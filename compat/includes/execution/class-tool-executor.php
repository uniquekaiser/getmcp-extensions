<?php
/**
 * Tool execution orchestrator.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Execution;

use GetMCP\Core\Server;
use GetMCP\Core\Tool;
use GetMCP\Licensing\CallCounter;
use GetMCP\Licensing\LicenseTier;
use GetMCP\Licensing\QuotaExceededException;

/**
 * Orchestrates the full tool execution lifecycle.
 *
 * Takes a Tool model and arguments, resolves parameters, injects auth,
 * makes the HTTP request, transforms the response, and returns MCP
 * content blocks.
 *
 * @since 1.0.0
 */
class ToolExecutor {

	/**
	 * Execute a tool with the given arguments.
	 *
	 * @since  1.0.0
	 * @param  Tool                 $tool      Tool to execute.
	 * @param  array<string, mixed> $arguments MCP tool arguments.
	 * @param  bool                 $use_test  Whether to use test credentials.
	 * @return array<string, mixed> MCP result with content blocks.
	 * @throws \RuntimeException When execution fails.
	 */
	public function execute( Tool $tool, array $arguments, bool $use_test = false, ?Server $server = null ): array {
		// Tier-quota gate. Test runs from the ToolEditor are exempt — they
		// don't represent live MCP traffic and shouldn't burn a customer's
		// monthly budget while iterating on a tool.
		if ( ! $use_test ) {
			self::enforce_call_quota();
		}

		/*
		 * Connector tools (mailbox, database) reach something that is not a
		 * REST API, so steps 1, 2 and 4 — parameter resolution into
		 * a URL, HTTP auth injection, and the HTTP call — are replaced by a
		 * connector executor. Everything else in this method is deliberately
		 * shared: the quota gate above, the response cache, the transform and
		 * PII redaction, byte accounting and call counting all behave
		 * identically for every kind of tool, and the caller's rate limiting,
		 * scope checks and logging never learn there is a difference.
		 */
		$is_connector = 'http' !== $tool->tool_type;

		if ( $is_connector ) {
			$request = array();
		} else {
			// Step 1: Resolve parameters into a request object. Server connection
			// variables (account subdomains, data-center codes — fixed or read
			// from the caller's request headers) fill any {{name}} placeholders
			// the arguments didn't.
			self::require_header_variables( $tool, $server );
			$resolver = new ParameterResolver();
			$request  = $resolver->resolve( $tool, $arguments, null !== $server ? $server->resolve_request_variables() : array() );

			// Step 2: Inject authentication credentials.
			$auth_injector = new AuthInjector();
			$personal = \GetMCPExtensions\ProviderExecution::inject( $request, $server, $tool, $arguments, $use_test );
			$request = null === $personal ? $auth_injector->inject( $request, $use_test, $server ) : $personal;
		}

		// Step 3: Check response cache (production calls only).
		$cache_key        = null;
		$effective_cache  = $tool->get_effective_cache_ttl();
		if ( ! $use_test && $effective_cache > 0 ) {
			$cache_key = 'getmcp_resp_' . md5( $tool->id . '|' . wp_json_encode( $arguments ) . '|' . \GetMCPExtensions\ProviderExecution::identity( $server ) );
			$cached    = get_transient( $cache_key );

			if ( false !== $cached ) {
				// Per the tier policy, cache hits count as successful tool calls.
				CallCounter::increment();
				return $cached;
			}
		}

		// Step 4: Talk to the upstream — an HTTP API, or a connector.
		if ( $is_connector ) {
			$response = self::run_connector( $tool, $arguments, $server );
		} else {
			$http_client = new HttpClient();
			$response    = $http_client->request( $request, $tool->get_effective_timeout(), \GetMCPExtensions\ProviderExecution::retries( $tool ), $tool->retry_backoff, $tool->ssl_verify );
		}

		// Step 5: Transform the response into MCP content blocks.
		// Pass the server so per-server PII redaction can scrub PII from
		// the response before it ever reaches the AI client.
		$transformer = new ResponseTransformer();
		$content     = $transformer->transform( $tool, $response, $server );

		$status_code = (int) ( $response['status_code'] ?? 0 );
		$is_error    = $status_code >= 400;

		$result = array( 'content' => $content );
		if ( $is_error ) {
			// MCP clients (and our analytics) need to know the call didn't semantically succeed
			// even though HTTP completed — per MCP spec, `isError` signals a tool-level error.
			$result['isError'] = true;
		}
		$result = \GetMCPExtensions\ProtocolEnvelope::for_tool( $tool, $result );
		$is_error = ! empty( $result['isError'] );
		if ( ! $is_error ) { \GetMCPExtensions\ProviderExecution::record( $server, $tool, $arguments, $response, $use_test ); }
		// Sideband: caller (ToolsCallHandler) strips these before returning
		// to the MCP client. Used so the analytics hook receives the real
		// upstream status / byte counts / replay payload instead of
		// hardcoded values.
		$result['_http_status']     = $status_code;
		$result['_upstream_bytes']  = isset( $response['body'] ) ? strlen( (string) $response['body'] ) : 0;
		$result['_delivered_bytes'] = self::sum_content_bytes( $content );
		$result['_replay_data']     = self::build_replay_data( $request, $response );

		// Step 6: Store in cache if enabled. Only cache successful responses — caching a 4xx/5xx
		// would pin a transient upstream failure to every caller for the full TTL.
		if ( $cache_key && ! $is_error ) {
			set_transient( $cache_key, $result, $effective_cache );
		}

		// Tier policy A3: count successful tool calls (cache hits already
		// counted above). Failures are not counted, so a flaky upstream
		// doesn't burn the customer's quota.
		if ( ! $use_test && ! $is_error ) {
			CallCounter::increment();
		}

		return $result;
	}

	/**
	 * Run a connector tool and return an HTTP-shaped response.
	 *
	 * Connectors are resolved here rather than through a registry because
	 * there are few of them and each is a distinct product feature; the
	 * resources layer resolves its own non-HTTP sources the same way, with a
	 * switch over a stored discriminator.
	 *
	 * The server is reloaded when the caller did not supply one (the tool
	 * test panel does not), because a connector's connection settings live on
	 * the server and without them there is nothing to connect to.
	 *
	 * @since 1.5.0
	 *
	 * @param Tool                 $tool      Tool being executed.
	 * @param array<string, mixed> $arguments Validated arguments.
	 * @param Server|null          $server    Owning server, if the caller had it.
	 * @return array{status_code:int, headers:array<string,string>, body:string}
	 */
	private static function run_connector( Tool $tool, array $arguments, ?Server $server ): array {
		if ( null === $server && $tool->server_id > 0 ) {
			$manager = new \GetMCP\Core\ServerManager();
			$loaded  = $manager->get( $tool->server_id );
			$server  = ( $loaded instanceof Server ) ? $loaded : null;
		}

		switch ( $tool->tool_type ) {
			case 'mailbox':
				$executor = new \GetMCP\Mail\MailboxExecutor();
				return $executor->execute( $tool, $arguments, $server );

			case 'database':
				$executor = new \GetMCP\Sql\SqlExecutor();
				return $executor->execute( $tool, $arguments, $server );

			default:
				return array(
					'status_code' => 502,
					'headers'     => array( 'content-type' => 'application/json' ),
					'body'        => (string) wp_json_encode(
						array(
							'error' => sprintf( 'This tool needs the "%s" connector, which this version of GetMCP does not provide.', $tool->tool_type ),
						)
					),
				);
		}
	}

	/**
	 * Throw if the site has consumed its monthly call quota for the
	 * current period.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws QuotaExceededException When the quota is exhausted.
	 */
	public static function enforce_call_quota(): void {
		$tier = LicenseTier::current();

		if ( $tier->is_unlimited() ) {
			return;
		}

		$period = CallCounter::current_period();
		if ( $period['count'] < $tier->call_quota() ) {
			return;
		}

		$retry_after_ts = strtotime( $period['period_end'] );
		$retry_after    = false !== $retry_after_ts ? max( 0, $retry_after_ts - time() ) : 0;

		throw new QuotaExceededException(
			sprintf(
				/* translators: %d: monthly quota count. */
				__( 'Monthly call quota exceeded (%d). Upgrade your GetMCP plan to continue.', 'getmcp' ),
				$tier->call_quota()
			),
			$retry_after,
			$tier->upgrade_url()
		);
	}

	/**
	 * Execute a tool for testing (returns full debug data).
	 *
	 * @since  1.0.0
	 * @param  Tool                 $tool      Tool to test.
	 * @param  array<string, mixed> $arguments MCP tool arguments.
	 * @return array<string, mixed> Full debug response.
	 */
	public function execute_test( Tool $tool, array $arguments, ?Server $server = null ): array {
		$start_time = microtime( true );

		// A connector tool has no URL to resolve and no HTTP auth to inject;
		// the Test panel runs it through the same executor production does,
		// and the "outbound request" it shows is the action and its arguments.
		if ( 'http' !== $tool->tool_type ) {
			$outbound_request = array(
				'url'         => '',
				'method'      => strtoupper( $tool->tool_type ) . ' ' . $tool->handler,
				'headers'     => array(),
				'raw_headers' => array(),
				'body'        => $arguments,
			);

			$response = self::run_connector( $tool, $arguments, $server );

			return $this->test_result( $tool, $server, $outbound_request, $response, $start_time );
		}

		// Step 1: Resolve parameters (connection variables included, so the
		// admin Test panel exercises the same URL production will use;
		// header-sourced variables read the admin's own request headers).
		self::require_header_variables( $tool, $server );
		$resolver = new ParameterResolver();
		$request  = $resolver->resolve( $tool, $arguments, null !== $server ? $server->resolve_request_variables() : array() );

		// Step 2: Inject test credentials from server (falls back to production creds if test creds not set).
		$auth_injector = new AuthInjector();
		$request = \GetMCPExtensions\ProviderExecution::inject( $request, $server, $tool, $arguments, true ) ?? $auth_injector->inject( $request, true, $server );

		// Step 3: Build debug output of outbound request (mask sensitive headers by default).
		$outbound_request = array(
			'url'         => $request['url'],
			'method'      => $request['method'],
			'headers'     => self::mask_sensitive_headers( $request['headers'] ),
			'raw_headers' => self::mask_sensitive_headers( $request['headers'] ),
			'body'        => $request['body'],
		);

		// Step 4: Make the HTTP request (with retry logic).
		$http_client = new HttpClient();

		try {
			$response = $http_client->request( $request, $tool->get_effective_timeout(), \GetMCPExtensions\ProviderExecution::retries( $tool ), $tool->retry_backoff, $tool->ssl_verify );
		} catch ( \Throwable $e ) {
			$total_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );

			return array(
				'outbound_request' => $outbound_request,
				'raw_response'     => null,
				'mcp_output'       => array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $e->getMessage(),
						),
					),
					'isError' => true,
				),
				'timing'           => array(
					'total_ms' => $total_ms,
				),
				'error'            => $e->getMessage(),
			);
		}

		return $this->test_result( $tool, $server, $outbound_request, $response, $start_time );
	}

	/**
	 * Shape a raw upstream response into the Test panel's debug envelope.
	 *
	 * Shared by the HTTP and connector test paths so both preview exactly
	 * what a real MCP client would see, per-server PII redaction included.
	 *
	 * @since 1.6.0
	 *
	 * @param Tool                 $tool             Tool under test.
	 * @param Server|null          $server           Owning server.
	 * @param array<string, mixed> $outbound_request What was sent, for display.
	 * @param array<string, mixed> $response         Upstream response.
	 * @param float                $start_time       microtime(true) at the start of the test.
	 * @return array<string, mixed>
	 */
	private function test_result( Tool $tool, ?Server $server, array $outbound_request, array $response, float $start_time ): array {
		// Step 5: Transform response.
		// Same per-server PII redaction as the production path so admin
		// "Test tool" runs preview exactly what real MCP clients would see.
		$transformer = new ResponseTransformer();
		$content     = $transformer->transform( $tool, $response, $server );

		$total_ms   = (int) ( ( microtime( true ) - $start_time ) * 1000 );
		$status     = (int) ( $response['status_code'] ?? 0 );
		$is_error   = $status >= 400;
		$diagnostic = null;

		if ( $is_error ) {
			// A connector's error body already carries the diagnosis; the
			// HTTP status table would only add "Server Error (502)" noise.
			$diagnostic = 'http' === $tool->tool_type
				? $this->diagnostic_for_status( $status, $outbound_request )
				: self::connector_diagnostic( $response );
		}

		$mcp_output = array( 'content' => $content );
		if ( $is_error ) {
			$mcp_output['isError'] = true;
		}

		return array(
			'outbound_request' => $outbound_request,
			'raw_response'     => array(
				'status_code' => $response['status_code'],
				'headers'     => $response['headers'],
				'body'        => $response['body'],
			),
			'mcp_output'       => $mcp_output,
			'diagnostic'       => $diagnostic,
			'timing'           => array(
				'total_ms'    => $total_ms,
				'response_ms' => $response['timing'] ?? 0,
			),
		);
	}

	/**
	 * The error sentence a connector put in its response body.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $response Connector response.
	 * @return string|null
	 */
	private static function connector_diagnostic( array $response ): ?string {
		$decoded = json_decode( (string) ( $response['body'] ?? '' ), true );

		return is_array( $decoded ) && is_string( $decoded['error'] ?? null ) ? $decoded['error'] : null;
	}

	/**
	 * Return a human-readable diagnostic message for a failed HTTP status code.
	 *
	 * @since  1.0.0
	 * @param  int                  $status          HTTP status code.
	 * @param  array<string, mixed> $outbound_request Resolved outbound request (for context).
	 * @return string
	 */
	private function diagnostic_for_status( int $status, array $outbound_request ): string {
		$url = $outbound_request['url'] ?? '';

		return match ( true ) {
			$status === 400 => 'Bad Request — the API rejected the request. Check your parameter names and values match what the API expects.',
			$status === 401 => 'Unauthorized — authentication failed. Go to Server Settings → Authentication and verify your credentials are correct.',
			$status === 403 => 'Forbidden — your credentials are valid but do not have permission for this endpoint.',
			$status === 404 => "Not Found — the endpoint URL was not found: $url. Check the URL for typos and verify any path parameters are correct.",
			$status === 405 => 'Method Not Allowed — the API does not support this HTTP method for this endpoint. Check the HTTP method selector.',
			$status === 409 => 'Conflict — the request conflicts with existing data (e.g., duplicate resource).',
			$status === 422 => 'Unprocessable Entity — the API rejected the request data. Check your parameter names and types match the API specification.',
			$status === 429 => 'Rate Limited — too many requests. Wait a moment and try again.',
			$status >= 500  => "Server Error ($status) — the external API returned a server error. This is likely an issue on the API provider's end.",
			default         => "HTTP $status — the request was not successful.",
		};
	}

	/**
	 * Build the per-call session-replay payload.
	 *
	 * Captures the outbound HTTP request (with auth headers masked) and
	 * the raw upstream response (status / headers / body) so the Logs
	 * page detail drawer can replay the full data flow after the fact.
	 *
	 * Bodies are truncated at MAX_BODY_BYTES so an unexpectedly large
	 * response (megabyte-scale binary, malformed JSON, etc.) can't bloat
	 * the call_logs table. Truncation is signaled with a sentinel field
	 * so the UI can show "…truncated" rather than silently lying.
	 *
	 * @since  1.14.0
	 * @param  array<string, mixed> $request  Outbound request shape from ParameterResolver / AuthInjector.
	 * @param  array<string, mixed> $response Upstream HTTP response shape from HttpClient.
	 * @return array{outbound:array<string,mixed>, upstream:array<string,mixed>}
	 */
	private static function build_replay_data( array $request, array $response ): array {
		$max_body = 65536; // 64 KB per side

		// Replay is captured before HttpClient encodes, so the body is still the
		// PHP structure ParameterResolver built — a bare (string) cast on it
		// printed the literal "Array" in the admin replay panel. Mirror
		// HttpClient::request(): JSON content types are wp_json_encode()d,
		// anything else is form-encoded the way wp_remote_request() would.
		$outbound_body = $request['body'] ?? '';
		if ( is_array( $outbound_body ) || is_object( $outbound_body ) ) {
			$content_type  = (string) ( $request['headers']['Content-Type'] ?? '' );
			$outbound_body = false !== strpos( $content_type, 'application/json' )
				? (string) wp_json_encode( $outbound_body )
				: http_build_query( (array) $outbound_body );
		}

		$outbound_body  = (string) $outbound_body;
		$outbound_trunc = strlen( $outbound_body ) > $max_body;
		if ( $outbound_trunc ) {
			$outbound_body = substr( $outbound_body, 0, $max_body );
		}

		$upstream_body = (string) ( $response['body'] ?? '' );
		$upstream_trunc = strlen( $upstream_body ) > $max_body;
		if ( $upstream_trunc ) {
			$upstream_body = substr( $upstream_body, 0, $max_body );
		}

		return array(
			'outbound' => array(
				'url'          => self::mask_sensitive_url( (string) ( $request['url'] ?? '' ) ),
				'method'       => strtoupper( (string) ( $request['method'] ?? 'GET' ) ),
				'headers'      => self::mask_sensitive_headers( (array) ( $request['headers'] ?? array() ) ),
				'body'         => $outbound_body,
				'body_truncated' => $outbound_trunc,
			),
			'upstream' => array(
				'status_code'  => isset( $response['status_code'] ) ? (int) $response['status_code'] : 0,
				// Masked too: Set-Cookie (and any echoed token) from the
				// upstream is a credential the same way a request header is.
				'headers'      => self::mask_sensitive_headers( (array) ( $response['headers'] ?? array() ) ),
				'body'         => $upstream_body,
				'body_truncated' => $upstream_trunc,
			),
		);
	}

	/**
	 * Mask credential-bearing query parameters in a URL before it is stored.
	 *
	 * An api-key auth configured with `location: query` writes the live key
	 * into the request URL; without this, replay_data persists it verbatim.
	 * The query string is rewritten pair-by-pair (never parse_str) so key
	 * order, duplicates and encoding of untouched parameters survive.
	 *
	 * @since  1.4.0
	 * @param  string $url Full request URL.
	 * @return string URL with sensitive query values replaced by `***`.
	 */
	private static function mask_sensitive_url( string $url ): string {
		$query_pos = strpos( $url, '?' );
		if ( false === $query_pos ) {
			return $url;
		}

		$base  = substr( $url, 0, $query_pos );
		$query = substr( $url, $query_pos + 1 );

		$fragment = '';
		$frag_pos = strpos( $query, '#' );
		if ( false !== $frag_pos ) {
			$fragment = substr( $query, $frag_pos );
			$query    = substr( $query, 0, $frag_pos );
		}

		$pairs = explode( '&', $query );
		foreach ( $pairs as $i => $pair ) {
			$eq   = strpos( $pair, '=' );
			$name = false === $eq ? $pair : substr( $pair, 0, $eq );
			if ( '' !== $name && self::is_sensitive_param( rawurldecode( $name ) ) ) {
				$pairs[ $i ] = $name . '=***';
			}
		}

		return $base . '?' . implode( '&', $pairs ) . $fragment;
	}

	/**
	 * Decide whether a query-parameter name carries a credential.
	 *
	 * @since  1.4.0
	 * @param  string $name Raw parameter name.
	 * @return bool True when the value must be masked.
	 */
	private static function is_sensitive_param( string $name ): bool {
		$normalized = str_replace( '-', '_', strtolower( $name ) );

		$exact = array( 'key', 'auth', 'sig', 'sas', 'password', 'passwd', 'pwd', 'secret', 'bearer', 'session', 'credential', 'credentials' );
		if ( in_array( $normalized, $exact, true ) ) {
			return true;
		}

		foreach ( array( 'api_key', 'apikey', 'token', 'secret', 'password', 'signature' ) as $needle ) {
			if ( str_contains( $normalized, $needle ) ) {
				return true;
			}
		}

		// `appkey`, `authkey`, `functions_key`… — but not `keyword`.
		return str_ends_with( $normalized, 'key' );
	}

	/**
	 * Mask sensitive HTTP header values for the replay payload.
	 *
	 * Auth credentials never belong in logs. We match a small allowlist
	 * of well-known header names case-insensitively (HTTP headers are
	 * case-insensitive per RFC 9110) and replace the value with `***`.
	 *
	 * @since  1.14.0
	 * @param  array<string, mixed> $headers Raw outbound headers.
	 * @return array<string, string> Headers with sensitive values masked.
	 */
	private static function mask_sensitive_headers( array $headers ): array {
		$out = array();
		foreach ( $headers as $name => $value ) {
			if ( self::is_sensitive_header( (string) $name ) ) {
				$out[ (string) $name ] = '***';
				continue;
			}
			// Some values arrive as arrays from wp_remote_request. Flatten
			// to a single string for consistent UI rendering.
			$out[ (string) $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		return $out;
	}

	/**
	 * Decide whether a header name carries a credential.
	 *
	 * A fixed allowlist misses vendor-specific auth headers
	 * (X-Shopify-Access-Token, xi-api-key, x-functions-key, …), and every
	 * miss is a plaintext credential stored in replay_data. Names are
	 * normalized and matched by pattern; over-masking a benign header only
	 * dims a debug view, under-masking persists a secret.
	 *
	 * Public because ServerTransfer applies the same rule when a tool's
	 * headers are written into an export manifest — one definition of "this
	 * header carries a credential", not two that drift.
	 *
	 * @since  1.4.0
	 * @param  string $name Raw header name.
	 * @return bool True when the value must be masked.
	 */
	public static function is_sensitive_header( string $name ): bool {
		$normalized = str_replace( '-', '_', strtolower( $name ) );

		$exact = array( 'authorization', 'proxy_authorization', 'cookie', 'set_cookie', 'token', 'auth', 'x_auth' );
		if ( in_array( $normalized, $exact, true ) ) {
			return true;
		}

		foreach ( array( 'api_key', 'apikey', 'token', 'secret', 'session', 'signature', 'credential', 'password' ) as $needle ) {
			if ( str_contains( $normalized, $needle ) ) {
				return true;
			}
		}

		return str_ends_with( $normalized, '_key' );
	}

	/**
	 * Sum the byte size of an MCP content-block list as delivered to the
	 * AI client. Counts only the user-visible payload (text / data / uri),
	 * not the wrapping JSON-RPC envelope — that overhead is fixed and
	 * not interesting for the savings calculation.
	 *
	 * @since  1.13.0
	 * @param  array<int, array<string, mixed>> $content MCP content blocks.
	 * @return int Total bytes.
	 */
	private static function sum_content_bytes( array $content ): int {
		$total = 0;
		foreach ( $content as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			foreach ( array( 'text', 'data', 'uri' ) as $key ) {
				if ( isset( $block[ $key ] ) && is_string( $block[ $key ] ) ) {
					$total += strlen( $block[ $key ] );
				}
			}
		}
		return $total;
	}

	/**
	 * Fail early — and helpfully — when a header-sourced variable is missing.
	 *
	 * When the tool references a variable configured as header-sourced and
	 * the request carries no usable value (header absent, or its value failed
	 * the URL-structure-safe charset) and no fixed fallback exists, the
	 * generic "was not resolved" error would leave the caller guessing.
	 * Naming the exact header lets an MCP client — or the model reading the
	 * error — fix its own configuration.
	 *
	 * @since  1.22.0
	 * @param  \GetMCP\Core\Tool $tool   Tool being executed.
	 * @param  Server|null       $server Owning server, when known.
	 * @return void
	 * @throws \RuntimeException When a required header-sourced variable has no value.
	 */
	private static function require_header_variables( $tool, ?Server $server ): void {
		if ( null === $server ) {
			return;
		}

		$header_vars = $server->get_header_variables();
		if ( empty( $header_vars ) ) {
			return;
		}

		$resolved = $server->resolve_request_variables();
		$haystack = $tool->endpoint_url . '|' . (string) $tool->headers;

		foreach ( $header_vars as $name => $header ) {
			if ( isset( $resolved[ $name ] ) ) {
				continue;
			}
			if ( false === strpos( $haystack, '{{' . $name . '}}' ) && false === strpos( $haystack, '{' . $name . '}' ) ) {
				continue;
			}
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: variable name, 2: HTTP header name. */
					__( 'Tool execution failed: this server requires your account value for "%1$s". Send it in the "%2$s" request header (letters, numbers, dots, dashes and underscores only), configured in your MCP client\'s connection settings.', 'getmcp' ),
					$name,
					$header
				)
			);
		}
	}
}
