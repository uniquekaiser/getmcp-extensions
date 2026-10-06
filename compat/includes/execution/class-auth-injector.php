<?php
/**
 * Authentication injector for outbound requests.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Execution;

use GetMCP\Core\Server;

/**
 * Injects authentication credentials into outbound API requests.
 *
 * Reads outbound auth from the server model and adds the appropriate
 * API key, Bearer token, or Basic auth header to the upstream request.
 *
 * Production live calls forward the credential from the MCP client's own Authorization
 * header directly to the upstream API (passthrough model — each end-user supplies their
 * own key via the MCP client configuration).
 *
 * Test calls (admin "Test Tool") use stored credentials:
 *  - test_auth_credentials — sandbox/test credentials, used only when the admin
 *                            clicks "Test Tool" in the editor. Falls back to
 *                            auth_credentials when test creds are not configured.
 *
 * @since 1.0.0
 */
class AuthInjector {

	/**
	 * Inject authentication into a request.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $request  Request object (url, method, headers, body).
	 * @param  bool                 $use_test True when called from the admin "Test Tool" flow.
	 * @param  Server|null          $server   Parent server that holds the outbound credentials.
	 * @return array<string, mixed> Request with auth injected.
	 */
	public function inject( array $request, bool $use_test = false, ?Server $server = null ): array {
		if ( null === $server ) {
			return $request;
		}

		$auth_type = $server->auth_type;

		// Test mode is resolved before the inbound-auth check. auth_type describes how
		// MCP clients authenticate *with getMCP*; it says nothing about whether stored
		// test credentials exist for the *upstream* API. A server that accepts anonymous
		// MCP traffic can still call a token-protected upstream from "Test Tool".
		if ( $use_test ) {
			return $this->inject_test_credentials( $request, $server );
		}
		$delegated = \GetMCP\Gateway\Delegation::active();
		if ( $delegated ) {
			foreach ( array_keys( $request['headers'] ?? array() ) as $name ) { if ( 0 === strcasecmp( $name, 'Authorization' ) ) { unset( $request['headers'][ $name ] ); } }
		}

		// Operator-stored outbound override wins for every inbound mode, OAuth
		// included: an OAuth-protected server whose upstream authenticates with
		// one fixed operator key is a normal setup, and the admin API accepts
		// that combination. The override *replaces* the credential rather than
		// forwarding it, so the MCP-spec rule below still holds — every injector
		// either overwrites Authorization or strips it.
		//
		// Resolved BEFORE the inbound-auth check, for the same reason test mode
		// is: auth_type says how MCP clients authenticate with getMCP, and
		// nothing whatever about the upstream API. A server that answers
		// anonymous MCP traffic and calls a key-protected upstream is an
		// ordinary setup — and it is the one the built-in server produces, since
		// a tool built by an AI defaults to open inbound with a placeholder
		// upstream credential. Checked after the early return, that credential
		// was stored, encrypted, reported as configured, and never sent: the
		// upstream answered 401 while the Test Tool button — which takes the
		// branch above — kept working, so the one check an operator would run
		// said the credential was fine.
		$has_outbound_override = ! empty( $server->outbound_auth_type )
			&& 'none' !== $server->outbound_auth_type;

		// A half-configured override (type set, credentials never saved) would
		// send the upstream call out with no auth at all. For OAuth the per-user
		// token below is the better fallback, so ignore the override then.
		if ( $has_outbound_override && 'oauth' === $auth_type && empty( $server->outbound_auth_credentials ) ) {
			$has_outbound_override = false;
		}

		if ( $has_outbound_override ) {
			return $this->inject_outbound_credentials( $request, $server );
		}
		// A gateway authenticates access to a selected native server, never its upstream API.
		if ( $delegated ) { return $request; }

		// Nothing stored to send, and no inbound credential to forward.
		if ( 'none' === $auth_type ) {
			return $request;
		}

		// MCP-spec rule: for OAuth we MUST NOT forward the inbound (MCP-AS-issued)
		// bearer to the upstream API — the broker pattern stores per-user
		// upstream tokens on the request's matched token row and we attach
		// THAT to the outbound call. Each MCP user therefore hits the upstream
		// API as themselves.
		if ( 'oauth' === $auth_type ) {
			return $this->inject_per_user_oauth( $request, $server );
		}

		// Passthrough path for bearer / basic / api-key inbound — the MCP
		// client itself is supplying the upstream credential.
		return $this->forward_inbound_credentials( $request, $auth_type, $server );
	}

	/**
	 * Attach the per-user upstream OAuth access token to the request.
	 *
	 * Reads the token row matched by McpAuth during inbound auth, decrypts
	 * `upstream_access_token`, refreshes via the broker if it has expired or
	 * is within 30s of expiry, and attaches as `Authorization: Bearer …`.
	 *
	 * Returns the request unchanged when no row is available (defensive —
	 * shouldn't happen for `auth_type=oauth`) or refresh fails. The upstream
	 * call will then 401 and the MCP client surfaces a useful error rather
	 * than us silently substituting an operator key.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $request Outbound request.
	 * @param  Server               $server  Parent server.
	 * @return array<string, mixed>
	 */
	private function inject_per_user_oauth( array $request, Server $server ): array {
		if ( \GetMCP\Auth\FirstPartyOAuth::is_custom( $server ) ) {
			// Native login authenticates the MCP client, never the upstream API.
			foreach ( array_keys( $request['headers'] ?? array() ) as $name ) {
				if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
					unset( $request['headers'][ $name ] );
				}
			}
			return $request;
		}
		$row = \GetMCP\Auth\McpAuth::current_token_row();
		if ( ! $row ) {
			return $request;
		}

		$enc = isset( $row->upstream_access_token ) ? (string) $row->upstream_access_token : '';
		if ( '' === $enc ) {
			return $request;
		}
		// Strict: an undecryptable stored token must abort rather than let the
		// call go out unauthenticated (see decode_credentials()).
		try {
			$access = \GetMCP\Utils\Encryption::decrypt_strict( $enc );
		} catch ( \GetMCP\Utils\DecryptionFailedException $e ) {
			throw new \RuntimeException(
				__( 'Your stored upstream access token could not be decrypted — the WordPress AUTH_KEY may have changed. Reconnect your account for this server.', 'getmcp' )
			);
		}
		if ( '' === $access ) { throw new \RuntimeException( 'Reconnect your upstream account.' ); }

		// Refresh when the upstream token is expired or about to expire — 30s
		// padding stops us from racing the upstream's clock on a slow request.
		$expires_at = isset( $row->upstream_expires_at ) ? strtotime( (string) $row->upstream_expires_at . ' UTC' ) : 0;
		if ( $expires_at > 0 && $expires_at - time() < 30 ) {
			$refreshed = \GetMCP\Auth\OAuthProvider::refresh_upstream_token( $row, $server );
			if ( '' === $refreshed ) { throw new \RuntimeException( 'The upstream login expired. Reconnect your account.' ); }
			$access = $refreshed;
		}

		$request['headers']['Authorization'] = 'Bearer ' . $access;
		return $request;
	}

	/**
	 * Attach the operator's stored outbound credentials to the upstream request.
	 *
	 * Used whenever:
	 *  - the inbound auth type forbids passthrough (OAuth flavours per MCP spec),
	 *  - or the operator explicitly configured a separate outbound auth method.
	 *
	 * Falls back to a clean unauthenticated request when nothing is stored —
	 * which is the right answer for a public upstream API and the safe answer
	 * for an inbound mode that was misconfigured (don't accidentally forward
	 * the user's token).
	 *
	 * @since  1.3.0
	 * @param  array<string, mixed> $request Outbound request.
	 * @param  Server               $server  Parent server.
	 * @return array<string, mixed>          Request with outbound auth attached (or unmodified).
	 */
	private function inject_outbound_credentials( array $request, Server $server ): array {
		$type = $server->outbound_auth_type ?? '';
		if ( '' === $type || 'none' === $type ) {
			return $request;
		}

		$creds_raw = $server->outbound_auth_credentials ?? '';
		if ( empty( $creds_raw ) ) {
			return $request;
		}

		$credentials = $this->decode_credentials( $creds_raw );
		if ( empty( $credentials ) ) {
			return $request;
		}

		// api-key wants its location/name from outbound_auth_config when set,
		// falling back to whatever is on the credentials blob.
		if ( 'api-key' === $type && ! empty( $server->outbound_auth_config ) ) {
			$config = $this->parse_auth_config( $server->outbound_auth_config );
			if ( ! empty( $config['name'] ) ) {
				$credentials['name'] = $config['name'];
			}
			if ( ! empty( $config['location'] ) ) {
				$credentials['location'] = $config['location'];
			}
		}

		return match ( $type ) {
			'bearer'  => $this->inject_bearer( $request, $credentials ),
			'api-key' => $this->inject_api_key( $request, $credentials ),
			'basic'   => $this->inject_basic( $request, $credentials ),
			default   => $request,
		};
	}

	/**
	 * Inject the server's stored test credentials.
	 *
	 * Used only by the admin "Test Tool" action. Resolution order:
	 * test_auth_* when configured, then outbound_auth_* (the operator's stored
	 * upstream credential), then auth_credentials as a last resort. MCP clients
	 * never reach this path.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $request Request object.
	 * @param  Server               $server  Server instance.
	 * @return array<string, mixed> Request with test credentials applied.
	 */
	private function inject_test_credentials( array $request, Server $server ): array {
		$has_test_creds = ! empty( $server->test_auth_type )
			&& 'none' !== $server->test_auth_type
			&& ! empty( $server->test_auth_credentials );

		if ( ! $has_test_creds ) {
			// No test credentials: reuse the operator's stored upstream credential so
			// "Test Tool" exercises the same auth a production call would.
			$has_outbound = ! empty( $server->outbound_auth_type )
				&& 'none' !== $server->outbound_auth_type
				&& ! empty( $server->outbound_auth_credentials );

			if ( $has_outbound ) {
				return $this->inject_outbound_credentials( $request, $server );
			}
		}

		$cred_type = $has_test_creds ? $server->test_auth_type : $server->auth_type;
		$creds_raw = $has_test_creds ? $server->test_auth_credentials : $server->auth_credentials;

		if ( 'none' === $cred_type || empty( $creds_raw ) ) {
			return $request;
		}

		$credentials = $this->decode_credentials( $creds_raw );
		if ( empty( $credentials ) ) {
			return $request;
		}

		return match ( $cred_type ) {
			'bearer'  => $this->inject_bearer( $request, $credentials ),
			'api-key' => $this->inject_api_key( $request, $credentials ),
			'basic'   => $this->inject_basic( $request, $credentials ),
			default   => $request,
		};
	}

	/**
	 * Inject Bearer token authentication.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $request     Request object.
	 * @param  array<string, mixed> $credentials Auth credentials.
	 * @return array<string, mixed> Request with Bearer token.
	 */
	private function inject_bearer( array $request, array $credentials ): array {
		$token = $credentials['token'] ?? '';

		if ( ! empty( $token ) ) {
			$request['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return $request;
	}

	/**
	 * Inject API key authentication.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $request     Request object.
	 * @param  array<string, mixed> $credentials Auth credentials.
	 * @return array<string, mixed> Request with API key.
	 */
	private function inject_api_key( array $request, array $credentials ): array {
		$key      = $credentials['value'] ?? $credentials['key'] ?? '';
		$location = $credentials['location'] ?? 'header';
		$name     = $credentials['name'] ?? 'X-API-Key';

		if ( empty( $key ) ) {
			return $request;
		}

		if ( 'query' === $location ) {
			$separator       = ( false === strpos( $request['url'], '?' ) ) ? '?' : '&';
			$request['url'] .= $separator . rawurlencode( $name ) . '=' . rawurlencode( $key );
			// The key travels in the URL, so nothing should also ride along in an
			// Authorization header. Same strip as the header branch below.
			unset( $request['headers']['Authorization'], $request['headers']['authorization'] );
		} else {
			$request['headers'][ $name ] = $key;
			// Strip the inbound Authorization header so the getMCP OAuth/API-key
			// token that the MCP client sent doesn't leak to the upstream API.
			if ( 0 !== strcasecmp( $name, 'Authorization' ) ) {
				unset( $request['headers']['Authorization'], $request['headers']['authorization'] );
			}
		}

		return $request;
	}

	/**
	 * Inject Basic authentication.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $request     Request object.
	 * @param  array<string, mixed> $credentials Auth credentials.
	 * @return array<string, mixed> Request with Basic auth.
	 */
	private function inject_basic( array $request, array $credentials ): array {
		$username = $credentials['username'] ?? '';
		$password = $credentials['password'] ?? '';

		if ( ! empty( $username ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$encoded                              = base64_encode( $username . ':' . $password );
			$request['headers']['Authorization'] = 'Basic ' . $encoded;
		}

		return $request;
	}

	/**
	 * Forward the MCP client's inbound Authorization header to the upstream API.
	 *
	 * In the passthrough model each end-user configures their own API key/token in the
	 * MCP client (e.g. Claude Desktop). getMCP reads that value from the incoming HTTP
	 * request and injects it into the outbound upstream request using the format dictated
	 * by the server's auth_type.
	 *
	 * - bearer  : extracts the token from "Authorization: Bearer <token>" and re-injects it.
	 * - basic   : forwards the full "Authorization: Basic <base64>" header unchanged.
	 * - api-key : extracts the raw value, then injects it into the header name / query
	 *             parameter defined in auth_config (defaults to "Authorization" header).
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $request   Outbound request object.
	 * @param  string               $auth_type Server outbound auth type.
	 * @param  Server               $server    Server instance (used for api-key config).
	 * @return array<string, mixed> Request with forwarded auth.
	 * @throws \RuntimeException When the inbound Authorization header is absent or malformed.
	 */
	private function forward_inbound_credentials( array $request, string $auth_type, Server $server ): array {
		switch ( $auth_type ) {
			case 'bearer':
				$auth = $this->read_authorization_header();
				if ( '' === $auth || ! preg_match( '/^Bearer\s+(.+)$/i', $auth, $matches ) ) {
					throw new \RuntimeException(
						__( 'Authentication required: expected a Bearer token in the Authorization header (e.g. "Authorization: Bearer <your-token>").', 'getmcp' )
					);
				}
				$request['headers']['Authorization'] = 'Bearer ' . trim( $matches[1] );
				break;

			case 'basic':
				$auth = $this->read_authorization_header();
				if ( '' === $auth || ! preg_match( '/^Basic\s+(.+)$/i', $auth, $matches ) ) {
					throw new \RuntimeException(
						__( 'Authentication required: expected Basic credentials in the Authorization header (e.g. "Authorization: Basic <base64>").', 'getmcp' )
					);
				}
				$request['headers']['Authorization'] = 'Basic ' . trim( $matches[1] );
				break;

			case 'api-key':
				$config   = $this->parse_auth_config( $server->auth_config );
				$name     = ! empty( $config['name'] ) ? (string) $config['name'] : 'X-API-Key';
				$location = ! empty( $config['location'] ) ? (string) $config['location'] : 'header';

				// The MCP client sends the key at the same name + location the operator
				// configured for upstream forwarding — so getMCP can read from the same
				// place it forwards to. (Earlier versions only checked Authorization,
				// which silently broke any custom header / query-param config.)
				$value = $this->read_named_credential( $name, $location );

				if ( '' === $value ) {
					$where = 'query' === $location
						? sprintf( 'as the "%s" query parameter', $name )
						: sprintf( 'in the "%s" header', $name );
					throw new \RuntimeException(
						/* translators: %s: where the API key should be sent */
						sprintf( __( 'Authentication required: send your API key %s.', 'getmcp' ), $where )
					);
				}

				if ( 'query' === $location ) {
					$separator       = ( false === strpos( $request['url'], '?' ) ) ? '?' : '&';
					$request['url'] .= $separator . rawurlencode( $name ) . '=' . rawurlencode( $value );
					unset( $request['headers']['Authorization'], $request['headers']['authorization'] );
				} else {
					$request['headers'][ $name ] = $value;
					// Prevent the inbound getMCP Authorization header from leaking
					// to the upstream API when the key goes to a different header.
					if ( 0 !== strcasecmp( $name, 'Authorization' ) ) {
						unset( $request['headers']['Authorization'], $request['headers']['authorization'] );
					}
				}
				break;
		}

		return $request;
	}

	/**
	 * Read the inbound `Authorization` (or PHP-CGI fallback) header.
	 *
	 * @since 1.0.0
	 */
	private function read_authorization_header(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) );
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		return '';
	}

	/**
	 * Read a credential the MCP client sent at a configurable header / query slot.
	 *
	 * For headers, "X-Api-Key" → $_SERVER['HTTP_X_API_KEY']. For Authorization,
	 * the "Bearer "/"Token " scheme prefix is stripped so the upstream gets the
	 * raw value when forwarded.
	 *
	 * @since 1.0.0
	 * @param string $name     Configured header or query-param name.
	 * @param string $location "header" | "query".
	 */
	private function read_named_credential( string $name, string $location ): string {
		if ( 'query' === $location ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $name ] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) );
			}
			return '';
		}

		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		$value      = ! empty( $_SERVER[ $server_key ] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) )
			: '';

		if ( '' !== $value && 0 === strcasecmp( $name, 'Authorization' ) ) {
			if ( preg_match( '/^(?:Bearer|Token)\s+(.+)$/i', $value, $m ) ) {
				$value = trim( $m[1] );
			}
		}
		return $value;
	}

	/**
	 * Parse the server auth_config JSON (non-sensitive forwarding configuration).
	 *
	 * @since  1.0.0
	 * @param  string|null $raw Raw JSON string from server->auth_config.
	 * @return array<string, string> Decoded config or empty array.
	 */
	private function parse_auth_config( ?string $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decode credentials from stored format.
	 *
	 * Handles both encrypted (prefixed with "enc:v1:") and legacy
	 * plain-JSON credentials for backward compatibility.
	 *
	 * @since  1.0.0
	 * @param  string $raw Raw credentials string (encrypted or plain JSON).
	 * @return array<string, mixed> Decoded credentials.
	 */
	private function decode_credentials( string $raw ): array {
		// Strict on purpose: a lenient decrypt turns "AUTH_KEY rotated" into an
		// empty credential set, and every caller of this method responds to an
		// empty set by sending the upstream request UNAUTHENTICATED.
		try {
			$decrypted = \GetMCP\Utils\Encryption::decrypt_strict( $raw );
		} catch ( \GetMCP\Utils\DecryptionFailedException $e ) {
			throw new \RuntimeException(
				__( 'Stored credentials could not be decrypted — the WordPress AUTH_KEY may have changed since they were saved. Re-enter the credentials in the server settings.', 'getmcp' )
			);
		}

		$decoded = json_decode( $decrypted, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
