<?php
/**
 * Tools call method handler.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Protocol;

use GetMCP\Builtin\BuiltinServer;
use GetMCP\Builtin\BuiltinTools;
use GetMCP\Auth\McpAuth;
use GetMCP\Auth\RateLimiter;
use GetMCP\Core\Server;
use GetMCP\Core\Tool;
use GetMCP\Execution\ToolExecutor;
use GetMCP\Licensing\QuotaExceededException;

/**
 * Handles the MCP "tools/call" method.
 *
 * The most important handler — validates arguments, executes the tool,
 * and returns MCP content blocks. This is the hot path.
 *
 * @since 1.0.0
 */
class ToolsCallHandler implements HandlerInterface {

	/**
	 * The MCP server instance.
	 *
	 * @since 1.0.0
	 * @var Server
	 */
	private Server $server;

	/**
	 * The MCP session ID.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private ?string $session_id;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Server      $server     The MCP server instance.
	 * @param string|null $session_id The MCP session ID.
	 */
	public function __construct( Server $server, ?string $session_id ) {
		$this->server     = $server;
		$this->session_id = $session_id;
	}

	/**
	 * Handle the tools/call method.
	 *
	 * @since  1.0.0
	 * @param  string               $method Method name.
	 * @param  array<string, mixed> $params Request parameters.
	 * @return array<string, mixed> Result with content blocks.
	 * @throws JsonRpcException When the tool is missing, unknown, inactive, or the arguments fail validation.
	 */
	public function handle( string $method, array $params ): array {
		// Both fields are client-supplied and were previously passed straight
		// into typed parameters: a non-string `name` hit find_tool()'s string
		// type and non-array `arguments` hit validate_arguments()'s array
		// type, each throwing a TypeError the router could only report as
		// -32603 "Internal error."
		if ( isset( $params['name'] ) && ! is_string( $params['name'] ) ) {
			throw new JsonRpcException(
				-32602,
				__( 'Invalid params: name must be a string', 'getmcp' )
			);
		}

		if ( isset( $params['arguments'] ) && ! is_array( $params['arguments'] ) ) {
			throw new JsonRpcException(
				-32602,
				__( 'Invalid params: arguments must be an object', 'getmcp' )
			);
		}

		$tool_name = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? array();

		// The built-in server dispatches to PHP handlers rather than to the
		// HTTP executor: there is no stored tool row to resolve, and no
		// outbound request to make. Placed before the progress/cancellation
		// plumbing below, which exists for long-running upstream calls that
		// these local operations do not make.
		if ( BuiltinServer::SERVER_ID === (int) $this->server->id && BuiltinServer::slug_is_reserved( $this->server->slug ) ) {
			if ( '' === (string) $tool_name ) {
				throw new JsonRpcException(
					-32602,
					__( 'Invalid params: a tool name is required', 'getmcp' )
				);
			}

			return BuiltinTools::call( (string) $tool_name, is_array( $arguments ) ? $arguments : array() );
		}

		// Gateway: the name carries its owner. Resolve it, then hand the call
		// to a handler bound to that server so execution, credentials, rate
		// limiting and logging all run exactly as a direct call would.
		if ( \GetMCP\Gateway\McpGateway::is_gateway( $this->server ) ) {
			if ( '' === (string) $tool_name ) {
				throw new JsonRpcException(
					-32602,
					__( 'Invalid params: a tool name is required', 'getmcp' )
				);
			}

			$resolved = \GetMCP\Gateway\McpGateway::resolve_tool( (string) $tool_name );
			if ( null === $resolved ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: %s: tool name. */
						__( 'Unknown tool: %s. It is not published by any server behind this gateway.', 'getmcp' ),
						(string) $tool_name
					)
				);
			}

			$delegate         = new self( $resolved['server'], $this->session_id );
			$params['name']   = $resolved['tool'];

			return $delegate->handle( $method, $params );
		}

		// MCP 2025-11-25: clients may opt into progress reporting by sending
		// `_meta.progressToken`. We surface it as `$tool->progress_token` /
		// the `getmcp_tool_progress_token` filter so tool execution code can
		// pick it up via {@see ProgressEmitter::emit_progress()}. Likewise
		// the request id is needed by long-running tools polling
		// {@see CancellationHandler::is_cancelled()}.
		$progress_token = ProgressEmitter::token_from_params( $params );

		/**
		 * Filter the resolved progress token before tool execution.
		 *
		 * Returning null disables progress for this tool call.
		 *
		 * @since 1.5.0
		 * @param string|int|null      $progress_token Token from `_meta.progressToken` or null.
		 * @param array<string, mixed> $params         Original tools/call params.
		 * @param string|null          $session_id     MCP session ID.
		 */
		$progress_token = apply_filters( 'getmcp_tool_progress_token', $progress_token, $params, $this->session_id );

		// Worded to distinguish it from validate_arguments()'s "Missing
		// required parameter: %s", which reports a missing *tool argument* and
		// reads identically when a tool happens to have an argument called
		// "name".
		if ( empty( $tool_name ) ) {
			throw new JsonRpcException(
				-32602,
				__( 'Invalid params: a tool name is required', 'getmcp' )
			);
		}

		/**
		 * Filters tool arguments before execution.
		 *
		 * @since 1.0.0
		 * @param array<string, mixed> $arguments Tool arguments.
		 * @param string               $tool_name Tool name.
		 */
		$arguments = apply_filters( 'getmcp_tool_arguments', $arguments, $tool_name );

		// Look up the tool by name within this server.
		$tool = $this->find_tool( $tool_name );

		// An unknown or disabled tool is a caller mistake, not a server fault:
		// MCP classifies it as a protocol error, so it gets -32602 rather than
		// the -32603 the generic RuntimeException path produced. Both cases
		// answer identically on purpose — a draft tool must not be
		// distinguishable from one that was never created.
		if ( null === $tool || 'active' !== $tool->status ) {
			throw new JsonRpcException(
				-32602,
				/* translators: %s: tool name */
				sprintf( __( 'Tool not found: %s', 'getmcp' ), $tool_name )
			);
		}
		if ( McpAuth::is_native_server_request() ) {
			$scope = ! empty( $tool->get_effective_annotations()['readOnlyHint'] ) ? 'mcp:read' : 'mcp:write';
			if ( ! McpAuth::has_scope( $scope ) ) {
				McpAuth::record_insufficient_scope( $scope );
				throw new JsonRpcException( -32003, __( 'Insufficient OAuth scope for this tool.', 'getmcp' ) );
			}
		}

		// A tool's own limit is an extra cap on top of the server's, checked per
		// JSON-RPC call so it still bites inside a batch. It sits after the
		// not-found guard on purpose: probing for tools that don't exist must
		// not burn another tool's budget. Raised as a JSON-RPC error rather
		// than an HTTP 429 because an HTTP-level refusal inside a batch fails
		// every unrelated call sharing the connection.
		if ( $tool->rate_limit_per_min > 0 && ! RateLimiter::allow_tool( (int) $tool->id, (int) $tool->rate_limit_per_min ) ) {
			throw new JsonRpcException(
				-32000,
				/* translators: %s: tool name */
				sprintf( __( 'Rate limit exceeded for tool: %s', 'getmcp' ), $tool_name ),
				array( 'retry_after' => RateLimiter::retry_after() )
			);
		}

		/**
		 * Filters the OAuth scope a tool requires (MCP step-up authorization).
		 *
		 * Returning a non-empty string declares the tool as scope-gated: when
		 * the current request's bearer doesn't carry the scope, the transport
		 * emits a 403 `insufficient_scope` challenge naming the missing scope
		 * and the MCP client re-runs the authorize flow. Returning '' (the
		 * default) means the tool is open to any authenticated principal.
		 *
		 * @since 1.4.0
		 * @param string $required_scope Required scope (default empty).
		 * @param Tool   $tool           Tool being invoked.
		 * @param Server $server         Parent server.
		 */
		$required_scope = (string) apply_filters( 'getmcp_tool_required_scope', '', $tool, $this->server );
		if ( '' !== $required_scope && ! McpAuth::has_scope( $required_scope ) ) {
			McpAuth::record_insufficient_scope( $required_scope );
			// Thrown as a JsonRpcException so the message reaches the client:
			// the generic RuntimeException path replaced it with "Internal
			// error.", leaving nothing to tell the client which scope to
			// re-authorize for. Matches the method-level scope error the
			// router emits.
			throw new JsonRpcException(
				-32603,
				/* translators: 1: required scope, 2: tool name */
				sprintf( __( 'Forbidden: missing the "%1$s" scope required to call %2$s.', 'getmcp' ), $required_scope, $tool_name )
			);
		}

		// Validate arguments against the tool's input schema.
		$this->validate_arguments( $tool, $arguments );

		/**
		 * Fires before a tool is executed.
		 *
		 * @since 1.0.0
		 * @param Tool                 $tool      Tool instance.
		 * @param array<string, mixed> $arguments Tool arguments.
		 * @param string|null          $session   Session ID.
		 */
		do_action( 'getmcp_before_tool_call', $tool, $arguments, $this->session_id );

		$start_time = microtime( true );

		try {
			$executor = new ToolExecutor();
			$result   = $executor->execute( $tool, $arguments, false, $this->server );

			$response_time_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );

			// Sideband: extract the upstream HTTP status + byte counts +
			// replay payload that ToolExecutor attached, then scrub them
			// from the result so they never leak to the MCP client —
			// these are for logging / analytics only, not part of the
			// MCP tools/call result shape.
			$status_code     = isset( $result['_http_status'] ) ? (int) $result['_http_status'] : 200;
			$upstream_bytes  = isset( $result['_upstream_bytes'] ) ? (int) $result['_upstream_bytes'] : null;
			$delivered_bytes = isset( $result['_delivered_bytes'] ) ? (int) $result['_delivered_bytes'] : null;
			$replay_data     = isset( $result['_replay_data'] ) && is_array( $result['_replay_data'] ) ? $result['_replay_data'] : null;
			unset( $result['_http_status'], $result['_upstream_bytes'], $result['_delivered_bytes'], $result['_replay_data'] );

			/**
			 * Fires after a successful tool execution.
			 *
			 * @since 1.0.0
			 * @param Tool                 $tool             Tool instance.
			 * @param array<string, mixed> $arguments        Tool arguments.
			 * @param array<string, mixed> $result           Execution result (may include isError=true for upstream 4xx/5xx).
			 * @param int                  $response_time_ms Response time in ms.
			 * @param int                  $status_code      Upstream HTTP status code (0 if no HTTP call was made).
			 * @param array<string, mixed> $extra            Sideband metrics: { upstream_bytes, delivered_bytes }.
			 *                                              Added in 1.13.0 — older listeners with 5 args still work because
			 *                                              PHP silently drops extra arguments to action callbacks.
			 */
			do_action(
				'getmcp_after_tool_call',
				$tool,
				$arguments,
				$result,
				$response_time_ms,
				$status_code,
				array(
					'upstream_bytes'  => $upstream_bytes,
					'delivered_bytes' => $delivered_bytes,
					// Replay payload (outbound request + raw upstream
					// response) for the Logs page session-replay drawer.
					// May be null when the executor didn't make an HTTP
					// call (e.g. cache hit, internal errors).
					'replay_data'     => $replay_data,
				)
			);

			/**
			 * Filters the tool response before returning to the client.
			 *
			 * @since 1.0.0
			 * @param array<string, mixed> $result    Tool result.
			 * @param Tool                 $tool      Tool instance.
			 * @param array<string, mixed> $arguments Tool arguments.
			 */
			return apply_filters( 'getmcp_tool_response', $result, $tool, $arguments );

		} catch ( QuotaExceededException $e ) {
			$response_time_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );

			/** This action is documented in includes/protocol/class-tools-call-handler.php */
			do_action( 'getmcp_tool_call_failed', $tool, $arguments, $e, $response_time_ms );

			// A quota refusal is not a tool result. Returned as isError content
			// it looked to the client like the upstream API had answered, and
			// the two things the caller can act on — how long to wait, where to
			// upgrade — were thrown away. -32000 is the implementation-defined
			// server-error code; the detail rides in `error.data`.
			throw new JsonRpcException(
				-32000,
				$e->getMessage(),
				array(
					'retry_after' => $e->retry_after(),
					'upgrade_url' => $e->upgrade_url(),
				)
			);

		} catch ( \Throwable $e ) {
			$response_time_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );

			/**
			 * Fires when a tool execution fails.
			 *
			 * @since 1.0.0
			 * @param Tool                 $tool             Tool instance.
			 * @param array<string, mixed> $arguments        Tool arguments.
			 * @param \Throwable           $error            The exception.
			 * @param int                  $response_time_ms Response time in ms.
			 */
			do_action( 'getmcp_tool_call_failed', $tool, $arguments, $e, $response_time_ms );

			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $e->getMessage(),
					),
				),
				'isError' => true,
			);
		}
	}

	/**
	 * Find a tool by name within the current server.
	 *
	 * @since  1.0.0
	 * @param  string $name Tool name.
	 * @return Tool|null Tool instance or null.
	 */
	private function find_tool( string $name ): ?Tool {
		global $wpdb;

		$table = $wpdb->prefix . 'getmcp_tools';

		// Try to find by slug first (for MCP client calls)
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE server_id = %d AND slug = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->server->id,
				$name
			),
			ARRAY_A
		);

		// Fallback to name (for legacy/display calls)
		if ( null === $row ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE server_id = %d AND name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$this->server->id,
					$name
				),
				ARRAY_A
			);
		}

		if ( null === $row ) {
			return null;
		}

		return new Tool( $row );
	}

	/**
	 * Validate tool arguments against the input schema.
	 *
	 * @since  1.0.0
	 * @param  Tool                 $tool      Tool instance.
	 * @param  array<string, mixed> $arguments Arguments to validate.
	 * @throws JsonRpcException When validation fails (emits -32602 Invalid Params).
	 */
	private function validate_arguments( Tool $tool, array $arguments ): void {
		$schema = $tool->get_input_schema_as_object();

		if ( null === $schema ) {
			return;
		}

		// Check required properties.
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $required_prop ) {
				if ( ! array_key_exists( $required_prop, $arguments ) ) {
					throw new JsonRpcException(
						-32602,
						/* translators: %s: parameter name */
						sprintf( __( 'Missing required parameter: %s', 'getmcp' ), $required_prop )
					);
				}
			}
		}

		// Validate property types.
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			// A tool that declares `additionalProperties: false` is stating that
			// its argument list is closed. Undeclared arguments used to be waved
			// through and then forwarded to the upstream API by the parameter
			// resolver, which let a caller append query keys the tool author
			// never sanctioned.
			if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
				$unknown = array_diff( array_keys( $arguments ), array_keys( $schema['properties'] ) );

				if ( ! empty( $unknown ) ) {
					throw new JsonRpcException(
						-32602,
						sprintf(
							/* translators: %s: comma-separated list of parameter names */
							__( 'Unknown parameter(s): %s', 'getmcp' ),
							implode( ', ', $unknown )
						)
					);
				}
			}

			foreach ( $arguments as $key => $value ) {
				if ( ! isset( $schema['properties'][ $key ] ) || ! is_array( $schema['properties'][ $key ] ) ) {
					continue;
				}

				$prop_schema = $schema['properties'][ $key ];
				$this->validate_type( $key, $value, $prop_schema );
			}
		}
	}

	/**
	 * Validate a single value against its schema type.
	 *
	 * @since  1.0.0
	 * @param  string               $name   Parameter name.
	 * @param  mixed                $value  Parameter value.
	 * @param  array<string, mixed> $schema Schema for this parameter.
	 * @throws JsonRpcException When type validation fails (emits -32602 Invalid Params).
	 */
	private function validate_type( string $name, mixed $value, array $schema ): void {
		// `type` is optional in JSON Schema — a property may constrain only its
		// enum, length, or pattern. The keyword checks below therefore fall back
		// to the value's own PHP type so they still run for an untyped property.
		$type = isset( $schema['type'] ) && is_string( $schema['type'] ) ? $schema['type'] : null;

		if ( null !== $type ) {
			$valid = match ( $schema['type'] ) {
				'string'  => is_string( $value ),
				'number'  => is_numeric( $value ),
				'integer' => is_int( $value ) || ( is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_INT ) ),
				'boolean' => is_bool( $value ),
				'array'   => is_array( $value ) && array_is_list( $value ),
				// `{}` decodes to an empty PHP array, which is also a list —
				// an empty object must not fail as "not an object".
				'object'  => is_array( $value ) && ( empty( $value ) || ! array_is_list( $value ) ),
				default   => true,
			};

			if ( ! $valid ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: expected type */
						__( 'Parameter "%1$s" must be of type %2$s', 'getmcp' ),
						$name,
						$schema['type']
					)
				);
			}
		}

		// Validate enum values.
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			if ( ! in_array( $value, $schema['enum'], true ) ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: comma-separated allowed values */
						__( 'Parameter "%1$s" must be one of: %2$s', 'getmcp' ),
						$name,
						implode( ', ', $schema['enum'] )
					)
				);
			}
		}

		// Validate numeric constraints.
		if ( ( in_array( $type, array( 'integer', 'number' ), true ) || null === $type ) && is_numeric( $value ) && ! is_bool( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: minimum value */
						__( 'Parameter "%1$s" must be at least %2$s.', 'getmcp' ),
						$name,
						$schema['minimum']
					)
				);
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: maximum value */
						__( 'Parameter "%1$s" must be at most %2$s.', 'getmcp' ),
						$name,
						$schema['maximum']
					)
				);
			}
		}

		// Validate string constraints.
		if ( ( 'string' === $type || null === $type ) && is_string( $value ) ) {
			if ( isset( $schema['minLength'] ) && strlen( $value ) < (int) $schema['minLength'] ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: minimum length */
						__( 'Parameter "%1$s" must be at least %2$d characters.', 'getmcp' ),
						$name,
						(int) $schema['minLength']
					)
				);
			}
			if ( isset( $schema['maxLength'] ) && strlen( $value ) > (int) $schema['maxLength'] ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: maximum length */
						__( 'Parameter "%1$s" must be at most %2$d characters.', 'getmcp' ),
						$name,
						(int) $schema['maxLength']
					)
				);
			}
			if ( isset( $schema['pattern'] ) ) {
				$this->validate_pattern( $name, $value, $schema['pattern'] );
			}
		}

		// Validate array constraints.
		if ( ( 'array' === $type || null === $type ) && is_array( $value ) ) {
			if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: minimum items */
						__( 'Parameter "%1$s" must have at least %2$d item(s).', 'getmcp' ),
						$name,
						(int) $schema['minItems']
					)
				);
			}
			if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) {
				throw new JsonRpcException(
					-32602,
					sprintf(
						/* translators: 1: parameter name, 2: maximum items */
						__( 'Parameter "%1$s" must have at most %2$d item(s).', 'getmcp' ),
						$name,
						(int) $schema['maxItems']
					)
				);
			}
		}
	}

	/**
	 * Check a string value against a JSON Schema `pattern`.
	 *
	 * The pattern is delimited with \x01 rather than "/" — a schema pattern is a
	 * bare ECMA-262 regex body, so any pattern containing a slash (a date format,
	 * a path, a MIME type) used to terminate the delimiter early and reject every
	 * value it should have matched.
	 *
	 * @param string $name    Parameter name.
	 * @param string $value   Value to test.
	 * @param mixed  $pattern Pattern from the schema.
	 * @throws JsonRpcException When the value does not match or the pattern is unusable.
	 */
	private function validate_pattern( string $name, string $value, mixed $pattern ): void {
		if ( ! is_string( $pattern ) || '' === $pattern ) {
			return;
		}

		$matched = @preg_match( "\x01" . $pattern . "\x01u", $value );

		if ( false === $matched ) {
			// Retry without /u: a pattern written for a byte-oriented engine can
			// be valid yet fail UTF-8 compilation.
			$matched = @preg_match( "\x01" . $pattern . "\x01", $value );
		}

		if ( false === $matched ) {
			// The tool's own schema is broken. Say so plainly instead of
			// blaming the caller's argument.
			throw new JsonRpcException(
				-32603,
				sprintf(
					/* translators: %s: parameter name */
					__( 'Tool schema error: the validation pattern for parameter "%s" is not a valid regular expression.', 'getmcp' ),
					$name
				)
			);
		}

		if ( 1 !== $matched ) {
			throw new JsonRpcException(
				-32602,
				sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" does not match the required pattern.', 'getmcp' ),
					$name
				)
			);
		}
	}
}
