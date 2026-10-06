<?php
/**
 * JSON-RPC 2.0 request router.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Protocol;

use GetMCP\Auth\McpAuth;
use GetMCP\Core\Server;

/**
 * Routes incoming JSON-RPC 2.0 requests to the appropriate handler.
 *
 * Validates JSON-RPC structure, supports batch requests, and returns
 * properly formatted JSON-RPC responses or errors.
 *
 * @since 1.0.0
 */
class JsonRpcRouter {

	/**
	 * Map of method names to handler classes.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	private array $handlers = array();

	/**
	 * Scope required for each MCP method.
	 *
	 * Methods not listed here (initialize, ping, notifications/*) are
	 * always permitted regardless of API key scopes.
	 *
	 * @since 1.1.0
	 * @var array<string, string>
	 */
	private const METHOD_SCOPES = array(
		'tools/list'               => 'tools',
		'tools/call'               => 'tools',
		'resources/list'           => 'resources',
		'resources/read'           => 'resources',
		'resources/templates/list' => 'resources',
		'resources/subscribe'      => 'resources',
		'prompts/list'             => 'prompts',
		'prompts/get'              => 'prompts',
		'completion/complete'      => 'prompts',
		'logging/setLevel'         => 'logging',
		'sampling/createMessage'   => 'sampling',
	);

	/**
	 * Methods whose result must carry SEP-2549 cache metadata.
	 *
	 * Revision 2026-07-28 promoted `ttlMs` and `cacheScope` from optional hints
	 * to required members of every list and read result. A client validating
	 * against that schema rejects a result missing either one, so this is a
	 * wire-format obligation rather than a caching feature.
	 *
	 * @since 1.21.0
	 * @var array<int, string>
	 */
	private const CACHEABLE_RESULT_METHODS = array(
		'tools/list',
		'prompts/list',
		'resources/list',
		'resources/templates/list',
		'resources/read',
	);

	/**
	 * The MCP server instance.
	 *
	 * @since 1.0.0
	 * @var Server
	 */
	private Server $server;

	/**
	 * MCP session ID.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private ?string $session_id = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Server $server The MCP server instance.
	 */
	public function __construct( Server $server ) {
		$this->server = $server;
		$this->register_default_handlers();
	}

	/**
	 * Set the MCP session ID.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 */
	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Register the default MCP method handlers.
	 *
	 * @since 1.0.0
	 */
	private function register_default_handlers(): void {
		$this->handlers = array(
			'initialize'                         => InitializeHandler::class,
			'notifications/initialized'          => InitializeHandler::class,
			'ping'                               => PingHandler::class,
			// MCP 2026-07-28 deleted the initialize handshake. server/discover
			// replaces it: one stateless read that reports the versions and
			// capabilities on offer. Clients that pin this revision probe with
			// it first and refuse to fall back, so its absence reads to them as
			// "this server does not speak my protocol" rather than as a missing
			// method. `initialize` stays registered for older clients.
			'server/discover'                    => ServerDiscoverHandler::class,
			'tools/list'                         => ToolsListHandler::class,
			'tools/call'                         => ToolsCallHandler::class,
			'resources/list'                     => ResourcesListHandler::class,
			'resources/read'                     => ResourcesReadHandler::class,
			'resources/templates/list'           => ResourcesTemplatesListHandler::class,
			'resources/subscribe'                => ResourcesSubscribeHandler::class,
			'prompts/list'                       => PromptsListHandler::class,
			'prompts/get'                        => PromptsGetHandler::class,
			'completion/complete'                => CompletionHandler::class,
			'logging/setLevel'                   => LoggingHandler::class,
			'sampling/createMessage'             => SamplingHandler::class,
			// MCP 2025-11-25 protocol additions: server-side handlers that
			// accept client traffic for roots, cancellation, and
			// elicitation. Outbound emission for these methods is done via
			// dedicated emitter/static helper classes
			// (RootsHandler::request_roots, ElicitationHandler::create,
			// ProgressEmitter::emit_progress, MessageEmitter::emit_log_message).
			'roots/list'                         => RootsHandler::class,
			'notifications/roots/list_changed'   => RootsHandler::class,
			'notifications/cancelled'            => CancellationHandler::class,
			'elicitation/create'                 => ElicitationHandler::class,
		);
	}

	/**
	 * Process a raw JSON-RPC request body.
	 *
	 * @since  1.0.0
	 * @param  string $raw_body Raw JSON request body.
	 * @return array<string, mixed>|array<int, array<string, mixed>>|null Response(s) or null for notifications.
	 */
	public function process( string $raw_body ): array|null {
		$decoded = json_decode( $raw_body, true );

		// Ask the decoder, not the result: a body of literal `null` is valid
		// JSON that decodes to null, and reporting it as a parse error is
		// wrong — it's a well-formed document that isn't a request.
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::error_response( null, -32700, __( 'Parse error: Invalid JSON', 'getmcp' ) );
		}

		// A batch is a JSON array. Detect it from the raw text rather than the
		// decoded value: with assoc decoding both `[x]` and `{"0":x}` become
		// the same PHP array, and only the first of those is a batch.
		if ( '[' === substr( ltrim( $raw_body ), 0, 1 ) ) {
			if ( is_array( $decoded ) && array() !== $decoded ) {
				/**
				 * Filters the maximum number of calls accepted in one batch.
				 *
				 * Batches are billed as a single request by the rate limiter,
				 * so an unbounded one turns one unit of quota into thousands
				 * of tool executions and outbound API calls. A 1 MB body fits
				 * roughly 15,000 tools/call elements. Set to 0 to disable the
				 * cap.
				 *
				 * @since 1.0.0
				 * @param int $max_batch_size Maximum calls per batch.
				 */
				$max_batch_size = (int) apply_filters( 'getmcp_max_batch_size', 50 );

				if ( $max_batch_size > 0 && count( $decoded ) > $max_batch_size ) {
					return self::error_response(
						null,
						-32600,
						sprintf(
							/* translators: %d: maximum number of calls per batch */
							__( 'Invalid request: a batch may contain at most %d calls', 'getmcp' ),
							$max_batch_size
						)
					);
				}

				$responses = $this->process_batch( $decoded );

				// A batch of nothing but notifications has no responses to
				// send. The spec is explicit that the server must not answer
				// with an empty array — return null so the transport emits the
				// same bodyless 202 a single notification gets.
				return array() === $responses ? null : $responses;
			}

			// An empty array carries no requests, so there is nothing to
			// answer per-element; the spec wants a single Invalid Request.
			return self::error_response( null, -32600, __( 'Invalid request: empty batch', 'getmcp' ) );
		}

		// Scalars and null are well-formed JSON but can never be a request.
		// Reject them here — handing one to process_single()'s `array` type
		// raised an uncaught TypeError, so a body as trivial as `5` or `true`
		// returned HTTP 500 on any server that allows anonymous access.
		if ( ! is_array( $decoded ) ) {
			return self::error_response( null, -32600, __( 'Invalid request: expected a JSON object', 'getmcp' ) );
		}

		// Single request.
		return $this->process_single( $decoded );
	}

	/**
	 * Process a batch of JSON-RPC requests.
	 *
	 * @since  1.0.0
	 * @param  array<int, array<string, mixed>> $requests Array of request objects.
	 * @return array<int, array<string, mixed>> Array of responses.
	 */
	private function process_batch( array $requests ): array {
		$responses = array();

		foreach ( $requests as $request ) {
			if ( ! is_array( $request ) ) {
				$responses[] = self::error_response( null, -32600, __( 'Invalid request', 'getmcp' ) );
				continue;
			}

			$response = $this->process_single( $request );

			// Notifications produce no response.
			if ( null !== $response ) {
				$responses[] = $response;
			}
		}

		return $responses;
	}

	/**
	 * Consume a JSON-RPC response the client sent for a server-initiated request.
	 *
	 * Only elicitation replies are acted on, recognised by the `srv-elicit-`
	 * id prefix this server mints in {@see ElicitationHandler::create()}.
	 * Anything else is dropped: an unrecognised id has no waiting caller, and
	 * answering a response would itself violate JSON-RPC.
	 *
	 * A JSON-RPC *error* response means the client refused or could not render
	 * the prompt. That is recorded as a `decline` so the waiting tool call
	 * resolves to a denial instead of polling a confirmation that will never
	 * arrive.
	 *
	 * @since  1.8.0
	 * @param  int|float|string|null $id      The response id.
	 * @param  array<string, mixed>  $message The full JSON-RPC response object.
	 * @return void
	 */
	private function absorb_client_response( int|float|string|null $id, array $message ): void {
		$request_id = is_string( $id ) ? $id : '';

		if ( 0 !== strpos( $request_id, 'srv-elicit-' ) ) {
			return;
		}

		if ( array_key_exists( 'error', $message ) ) {
			$payload = array( 'action' => 'decline' );
		} elseif ( is_array( $message['result'] ?? null ) ) {
			$payload = $message['result'];
		} else {
			return;
		}

		$payload['requestId'] = $request_id;

		$handler = new ElicitationHandler( $this->server, $this->session_id );

		try {
			$handler->handle( 'elicitation/create', $payload );
		} catch ( JsonRpcException $e ) {
			// A malformed elicitation body has no request to fail — the waiting
			// tool call simply keeps reporting "still pending" until it expires.
			unset( $e );
		}
	}

	/**
	 * Process a single JSON-RPC request.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $request The JSON-RPC request.
	 * @return array<string, mixed>|null Response or null for notifications.
	 */
	private function process_single( array $request ): ?array {
		// Notifications have no id — process but don't return a response.
		$is_notification = ! array_key_exists( 'id', $request );
		$id              = $request['id'] ?? null;

		// The id has to be checked before anything can answer with it. An
		// object or array id used to reach the response builders' scalar type
		// and raise an uncaught TypeError (HTTP 500). Respond with a null id:
		// the spec's rule for a request whose id could not be determined.
		if ( ! $is_notification ) {
			$id_error = self::validate_id( $id );

			if ( null !== $id_error ) {
				return self::error_response( null, -32600, $id_error );
			}
		}

		// Validate JSON-RPC 2.0 structure.
		if ( ! isset( $request['jsonrpc'] ) || '2.0' !== $request['jsonrpc'] ) {
			return self::error_response(
				$id,
				-32600,
				__( 'Invalid request: missing or wrong jsonrpc version', 'getmcp' )
			);
		}

		// A message carrying an id and a `result`/`error` but no `method` is the
		// client's *response* to a server-initiated request (elicitation,
		// roots), not a request of its own. It used to be rejected as "missing
		// method", which meant a client could never answer an elicitation the
		// server had sent. Absorb it and stay silent — JSON-RPC responses are
		// never themselves answered.
		if ( ! isset( $request['method'] ) && ! $is_notification
			&& ( array_key_exists( 'result', $request ) || array_key_exists( 'error', $request ) ) ) {
			$this->absorb_client_response( $id, $request );
			return null;
		}

		if ( ! isset( $request['method'] ) || ! is_string( $request['method'] ) ) {
			return self::error_response(
				$id,
				-32600,
				__( 'Invalid request: missing method', 'getmcp' )
			);
		}

		$method = $request['method'];
		$params = $request['params'] ?? array();

		// Route to handler.
		if ( ! isset( $this->handlers[ $method ] ) ) {
			if ( $is_notification ) {
				return null;
			}
			return self::error_response(
				$id,
				-32601,
				sprintf(
					/* translators: %s: method name */
					__( 'Method not found: %s', 'getmcp' ),
					// Truncated: the name is attacker-controlled and a body
					// may carry up to 1 MB of it. No reason to reflect more
					// than enough to identify the method.
					mb_substr( $method, 0, 128 )
				)
			);
		}

		// `params` is structured by definition. A scalar reached the handler's
		// `array $params` parameter and threw a TypeError, which the catch-all
		// below reported as -32603 "Internal error." — telling the client the
		// server is broken when the request was simply malformed.
		if ( isset( $request['params'] ) && ! is_array( $request['params'] ) ) {
			if ( $is_notification ) {
				return null;
			}
			return self::error_response(
				$id,
				-32602,
				__( 'Invalid params: expected an object or array', 'getmcp' )
			);
		}

		if ( McpAuth::is_native_server_request() && isset( self::METHOD_SCOPES[ $method ] ) && 'tools/call' !== $method && ! McpAuth::has_scope( 'mcp:read' ) ) {
			McpAuth::record_insufficient_scope( 'mcp:read' );
			return $is_notification ? null : self::error_response( $id, -32003, __( 'Insufficient OAuth scope: mcp:read is required.', 'getmcp' ) );
		}

		// Method-level scopes are an API-key feature: admins set coarse scopes
		// like `tools` / `resources` / `prompts` on a key. OAuth principals
		// use a different scope vocabulary (whatever the AS issued — often
		// `mcp:read` / `mcp:write` / custom), so checking `tools` against an
		// OAuth scope set always fails closed even when the token is valid.
		// Skip for OAuth — tool-level enforcement (`getmcp_tool_required_scope`)
		// is the right gate for OAuth scope semantics.
		if ( ! McpAuth::is_oauth_request()
			&& isset( self::METHOD_SCOPES[ $method ] )
			&& ! McpAuth::has_scope( self::METHOD_SCOPES[ $method ] )
		) {
			if ( $is_notification ) {
				return null;
			}
			McpAuth::record_insufficient_scope( self::METHOD_SCOPES[ $method ] );
			return self::error_response(
				$id,
				-32603,
				/* translators: 1: required scope, 2: MCP method name */
				sprintf( __( 'Forbidden: missing the "%1$s" scope required for %2$s.', 'getmcp' ), self::METHOD_SCOPES[ $method ], $method )
			);
		}

		$handler_class = $this->handlers[ $method ];

		/** @var HandlerInterface $handler */
		$handler = new $handler_class( $this->server, $this->session_id );

		try {
			if ( \GetMCP\Gateway\FeatureHandler::handles( $this->server, $method ) ) {
				$result = ( new \GetMCP\Gateway\FeatureHandler( $this->server, $this->session_id ) )->handle( $method, $params );
			} else { $result = $handler->handle( $method, $params ); }
		} catch ( JsonRpcException $e ) {
			if ( $is_notification ) {
				return null;
			}
			$data = $e->get_data();

			return self::error_response(
				$id,
				$e->get_rpc_code(),
				$e->getMessage(),
				empty( $data ) ? null : $data
			);
		} catch ( \Throwable $e ) {
			// Never leak internal exception details (paths, SQL fragments,
			// library messages) to MCP clients — log server-side, return generic.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'getMCP: unhandled %1$s in %2$s handler for server #%3$d: %4$s',
						get_class( $e ),
						$method,
						(int) ( $this->server->id ?? 0 ),
						$e->getMessage()
					)
				);
			}
			if ( $is_notification ) {
				return null;
			}
			return self::error_response(
				$id,
				-32603,
				__( 'Internal error.', 'getmcp' )
			);
		}

		if ( $is_notification ) {
			return null;
		}

		// Stamped centrally rather than inside each list handler: this is an
		// envelope rule of the protocol revision, not per-handler logic, and a
		// sixth list method added later would otherwise ship silently broken —
		// the failure surfaces only as a schema parse error on the client. `??=`
		// leaves a handler's own value alone if one ever computes a real TTL.

		// 2026-07-28 declares `resultType` on the base Result schema, so it binds
		// every result — lists, reads, tools/call, discover, ping — not just the
		// cacheable ones below. Stamped unconditionally because older revisions
		// define Result with an open index signature, which makes an unknown
		// member legal rather than a parse failure. `??=` reserves the slot for a
		// handler that returns "input_required" once multi-round-trip input lands.
		$result['resultType'] ??= 'complete';

		if ( in_array( $method, self::CACHEABLE_RESULT_METHODS, true ) ) {
			$result['ttlMs']      ??= 0;
			$result['cacheScope'] ??= 'private';
		}

		return self::success_response( $id, $result );
	}

	/**
	 * Check that a request id is one JSON-RPC allows and we can echo verbatim.
	 *
	 * The spec restricts `id` to a String, Number, or NULL, and requires the
	 * response to carry the same id the request did. Booleans, objects, and
	 * arrays have no valid representation here. Integers past IEEE-754's exact
	 * range are refused as well: json_decode() has already turned one into a
	 * lossy float by the time this runs, so echoing it would hand the client
	 * back a different id than it sent (`99999999999999999999` came back as
	 * `1.0E+20`) and break request correlation silently.
	 *
	 * @since  1.0.0
	 * @param  mixed $id Raw id from the request.
	 * @return string|null Error message, or null when the id is usable.
	 */
	private static function validate_id( mixed $id ): ?string {
		if ( null === $id || is_int( $id ) || is_string( $id ) ) {
			return null;
		}

		if ( is_float( $id ) ) {
			if ( is_finite( $id ) && abs( $id ) < 9007199254740992.0 ) {
				return null;
			}

			return __( 'Invalid request: id is outside the range of numbers that can be represented exactly', 'getmcp' );
		}

		return __( 'Invalid request: id must be a string, a number, or null', 'getmcp' );
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @since  1.0.0
	 * @param  int|float|string|null $id     Request ID.
	 * @param  array<string, mixed>  $result Result data.
	 * @return array<string, mixed>
	 */
	public static function success_response( int|float|string|null $id, array $result ): array {
		// PHP encodes an empty indexed array as JSON `[]`, but the MCP spec requires
		// `result` to always be a JSON object `{}`. Cast empty arrays to stdClass.
		$encoded_result = empty( $result ) ? new \stdClass() : $result;

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $encoded_result,
		);
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @since  1.0.0
	 * @param  int|float|string|null $id      Request ID.
	 * @param  int                   $code    Error code.
	 * @param  string                $message Error message.
	 * @param  mixed                 $data    Optional additional error data.
	 * @return array<string, mixed>
	 */
	public static function error_response( int|float|string|null $id, int $code, string $message, mixed $data = null ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}
}
