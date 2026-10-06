<?php
/**
 * MCP inbound authentication handler.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Auth;

use GetMCP\Core\Server;

/**
 * Authenticates incoming MCP requests using the server's configured auth type.
 *
 * Supports:
 * - none: No authentication required.
 * - api-key: Bearer token or X-API-Key validated against getmcp_api_keys table.
 * - oauth: OAuth 2.1 Bearer token validated against getmcp_oauth_tokens table.
 *
 * @since 1.0.0
 */
class McpAuth {

	/**
	 * Scopes granted by the current request's credential (null = unrestricted).
	 *
	 * Populated by the validators (`validate_api_key`, OAuth validators) on
	 * successful authentication. Read by `has_scope()` to gate JSON-RPC
	 * methods and tools.
	 *
	 * @since 1.1.0
	 * @var string[]|null
	 */
	private static ?array $current_scopes = null;

	/**
	 * The scope a recently-rejected request was missing, for step-up auth.
	 *
	 * When `has_scope()` returns false at a JSON-RPC dispatch site, the caller
	 * records the required scope here so the HTTP transport can convert the
	 * normal 200/JSON-RPC-error reply into a 403 + RFC 6750
	 * `WWW-Authenticate: Bearer error="insufficient_scope"` challenge — that's
	 * the protocol signal that asks an MCP client to re-authorize with the
	 * higher scope (MCP spec §Step-Up Authorization).
	 *
	 * @since 1.4.0
	 * @var string|null
	 */
	private static ?string $insufficient_scope = null;

	/**
	 * Authentication mode used for the current request.
	 *
	 * `api-key` means an admin-issued key with operator-defined coarse scopes
	 * (`tools` / `resources` / `prompts`). `oauth` means any OAuth flavour
	 * (self-hosted / external / proxy) where scope semantics are defined by
	 * the OAuth grant — tool-level filters, not method-level enforcement,
	 * are the correct gate. `null` = unauthenticated or auth_type=none.
	 *
	 * @since 1.4.0
	 * @var string|null
	 */
	private static ?string $auth_mode = null;

	/**
	 * Whether the current request authenticated via an OAuth flavour.
	 *
	 * Used by the JSON-RPC router to skip API-key-shaped method-level scope
	 * checks. OAuth principals only get gated at the tool level, where the
	 * scope vocabulary matches what the AS advertised.
	 *
	 * @since  1.4.0
	 * @return bool
	 */
	public static function is_oauth_request(): bool {
		return 'oauth' === self::$auth_mode;
	}

	/** A custom native login uses GetMCP's read/write scope vocabulary. */
	public static function is_native_server_request(): bool {
		return 'oauth' === self::$auth_mode && self::$native_server;
	}

	private static bool $native_server = false;

	/**
	 * Check whether the current authenticated principal has a required scope.
	 *
	 * Returns true when:
	 *  - No scope-bearing auth was used — unrestricted.
	 *  - The key has no scopes configured — unrestricted (backward compatible).
	 *  - The key's scope list contains the required scope.
	 *
	 * Does NOT mutate `$insufficient_scope` — callers that need to trigger a
	 * step-up challenge call `record_insufficient_scope()` separately so a
	 * speculative `has_scope()` (e.g. for capability advertisement) doesn't
	 * accidentally upgrade the response status.
	 *
	 * @since  1.1.0
	 * @param  string $scope Required scope (e.g. 'tools', 'resources', 'prompts', 'mcp:write').
	 * @return bool
	 */
	public static function has_scope( string $scope ): bool {
		if ( null === self::$current_scopes ) {
			return true;
		}
		return in_array( $scope, self::$current_scopes, true );
	}

	/**
	 * Set the scopes attached to the current request.
	 *
	 * Called by the validators after a successful authentication. Pass `null`
	 * to mark the request as unrestricted (the default for non-scope-bearing
	 * auth modes).
	 *
	 * @since 1.4.0
	 * @param string[]|null $scopes Granted scopes, or null for unrestricted.
	 */
	public static function set_current_scopes( ?array $scopes ): void {
		self::$current_scopes = $scopes;
	}

	/**
	 * Mark the request as failing for insufficient scope.
	 *
	 * Records the missing scope so the transport layer can emit a 403
	 * `insufficient_scope` challenge.
	 *
	 * @since 1.4.0
	 * @param string $scope The scope the request was missing.
	 */
	public static function record_insufficient_scope( string $scope ): void {
		self::$insufficient_scope = $scope;
	}

	/**
	 * Read the recorded insufficient-scope value, if any.
	 *
	 * @since  1.4.0
	 * @return string|null Missing scope name, or null when no step-up is needed.
	 */
	public static function get_insufficient_scope(): ?string {
		return self::$insufficient_scope;
	}

	/**
	 * Reset scopes / step-up state at the start of each request.
	 *
	 * @since 1.1.0
	 */
	public static function reset(): void {
		self::$current_scopes      = null;
		self::$insufficient_scope = null;
		self::$auth_mode          = null;
		self::$current_token_row  = null;
		self::$native_server      = false;
	}

	/**
	 * Authenticate an incoming MCP request for a server.
	 *
	 * Returns true if the request is authenticated, or a string error message
	 * if authentication fails.
	 *
	 * @since  1.0.0
	 * @param  Server $server The MCP server being accessed.
	 * @return true|string True if authenticated, error message string on failure.
	 */
	public static function authenticate( Server $server ): true|string {
		$auth_type = $server->auth_type ?? 'none';

		if ( 'none' === $auth_type ) {
			return true;
		}

		// Each auth flavour has its own credential location and validation rule.
		// Doing this per-type keeps us from forcing a Bearer-shaped header onto
		// (e.g.) Basic auth or an api-key in a custom header / query parameter.
		$result = match ( $auth_type ) {
			'bearer'  => self::authenticate_bearer( $server ),
			'basic'   => self::authenticate_basic( $server ),
			'api-key' => self::authenticate_api_key( $server ),
			'oauth'   => self::authenticate_oauth( $server ),
			// Fail closed: an unrecognized auth_type means misconfiguration,
			// not "no auth". The OAuth fallback below still lets a valid
			// getMCP OAuth token through.
			default   => __( 'Unsupported authentication type configured for this server.', 'getmcp' ),
		};

		// MCP clients such as VS Code auto-discover OAuth via
		// .well-known/oauth-authorization-server and attach an OAuth Bearer token
		// alongside any custom headers (e.g. xi-api-key). If the primary auth
		// method failed but a valid getMCP OAuth token is present in the
		// Authorization header, accept it — the client legitimately completed
		// the OAuth flow and the custom header will still be forwarded outbound.
		if ( true !== $result && 'oauth' !== $auth_type && 'bearer' !== $auth_type ) {
			$bearer = self::find_bearer_token();
			if ( '' !== $bearer ) {
				$oauth_result = self::authenticate_oauth( $server );
				if ( true === $oauth_result ) {
					return true;
				}
			}
		}

		return $result;
	}

	/**
	 * Is the inbound credential actually forwarded to the upstream API?
	 *
	 * Presence-only auth modes (`bearer`, `basic`, and `api-key` on a server
	 * that has never had a managed key) are only sound because the credential
	 * the client sends is passed straight through to the upstream API, which
	 * performs the real validation — a bogus token simply earns an upstream
	 * 401. That premise fails the moment the operator configures an outbound
	 * override: `AuthInjector::inject()` then discards the client's credential
	 * and substitutes the operator's own. Presence-only auth would let any
	 * caller spend the operator's credentials with an arbitrary string, so
	 * these modes must fail closed instead.
	 *
	 * @since 1.4.0
	 */
	private static function upstream_validates_inbound( Server $server ): bool {
		return empty( $server->outbound_auth_type ) || 'none' === $server->outbound_auth_type;
	}

	/**
	 * Error returned when a presence-only mode cannot be trusted.
	 */
	private static function passthrough_unavailable(): string {
		return __( 'This server does not accept passthrough credentials. Authenticate with a managed API key or OAuth token.', 'getmcp' );
	}

	/**
	 * Bearer-token presence check (passthrough — upstream re-validates).
	 */
	private static function authenticate_bearer( Server $server ): true|string {
		$token = self::find_bearer_token();
		if ( '' === $token ) {
			return __( 'Authentication required: send "Authorization: Bearer <your-token>" with each request.', 'getmcp' );
		}
		if ( ! self::upstream_validates_inbound( $server ) ) {
			return self::passthrough_unavailable();
		}
		return true;
	}

	/**
	 * Basic-credentials presence check (passthrough — upstream re-validates).
	 */
	private static function authenticate_basic( Server $server ): true|string {
		$auth = self::auth_header();
		if ( '' === $auth || 0 !== stripos( $auth, 'Basic ' ) ) {
			return __( 'Authentication required: send "Authorization: Basic <base64-username:password>" with each request.', 'getmcp' );
		}
		if ( ! self::upstream_validates_inbound( $server ) ) {
			return self::passthrough_unavailable();
		}
		return true;
	}

	/**
	 * OAuth (broker mode) — validate the bearer issued by our AS and stash
	 * the matched token row so AuthInjector can read its upstream tokens.
	 *
	 * The bearer is opaque to the upstream API (Google / GitHub / …); the
	 * upstream tokens we forward live on the row we matched here.
	 */
	private static function authenticate_oauth( Server $server ): true|string {
		$token = self::find_bearer_token();
		if ( '' === $token ) {
			return __( 'Missing OAuth access token. Authorize through the MCP client to obtain one.', 'getmcp' );
		}
		$row = OAuthProvider::validate_and_extract_row( $token, $server );
		if ( null === $row ) {
			return __( 'Invalid or expired OAuth access token.', 'getmcp' );
		}
		self::$current_token_row = $row;
		self::$native_server = FirstPartyOAuth::is_custom( $server );
		// Pull scopes from the row in the same shape validate_and_extract_scopes used.
		$raw_scopes = isset( $row->scopes ) ? trim( (string) $row->scopes ) : '';
		if ( '' === $raw_scopes || '*' === $raw_scopes ) {
			self::set_current_scopes( null );
		} else {
			$parts = preg_split( '/\s+/', $raw_scopes ) ?: array();
			self::set_current_scopes( array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) ) );
		}
		self::$auth_mode = 'oauth';
		return true;
	}

	/**
	 * Token row matched by the most recent OAuth authentication.
	 *
	 * `null` when this request was not OAuth-authenticated. AuthInjector
	 * reads `upstream_access_token` / `upstream_refresh_token` /
	 * `upstream_expires_at` off the row to attach the per-user upstream
	 * credential to outbound calls.
	 *
	 * @since 1.4.0
	 * @var object|null
	 */
	private static ?object $current_token_row = null;

	/**
	 * Read the token row matched by the current OAuth authentication.
	 *
	 * @since  1.4.0
	 * @return object|null
	 */
	public static function current_token_row(): ?object {
		return self::$current_token_row;
	}

	/**
	 * API-key auth — find the credential at the configured location, then validate
	 * it against the getmcp_api_keys table. Mirrors how AuthInjector finds the
	 * credential for outbound forwarding so both halves of the request use the
	 * same convention.
	 */
	private static function authenticate_api_key( Server $server ): true|string {
		$config   = self::parse_auth_config( $server->auth_config );
		$name     = ! empty( $config['name'] ) ? (string) $config['name'] : 'X-API-Key';
		$location = ! empty( $config['location'] ) ? (string) $config['location'] : 'header';

		$token = self::read_named_credential( $name, $location );

		if ( '' === $token ) {
			$where = 'query' === $location
				? sprintf( 'as the "%s" query parameter', $name )
				: sprintf( 'in the "%s" header', $name );
			return sprintf(
				/* translators: %s: where the API key should be sent */
				__( 'Authentication required: send your API key %s.', 'getmcp' ),
				$where
			);
		}

		return self::validate_api_key( $token, $server );
	}

	/**
	 * Read the Authorization (or first-X-Authorization) header sent by the client.
	 */
	private static function auth_header(): string {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) );
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) );
		}
		return '';
	}

	/**
	 * Pull a Bearer token from `Authorization`, falling back to common locations.
	 */
	private static function find_bearer_token(): string {
		$auth = self::auth_header();
		if ( '' !== $auth && 0 === stripos( $auth, 'Bearer ' ) ) {
			$token = trim( substr( $auth, 7 ) );
			if ( '' !== $token ) {
				return $token;
			}
		}
		return '';
	}

	/**
	 * Read a named credential from a header or query parameter.
	 *
	 * @param string $name     Header or query-param name (case-insensitive for headers).
	 * @param string $location "header" | "query".
	 */
	private static function read_named_credential( string $name, string $location ): string {
		if ( 'query' === $location ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $name ] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) );
			}
			return '';
		}

		// Header — convert "X-Api-Key" → HTTP_X_API_KEY for $_SERVER.
		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		$value      = ! empty( $_SERVER[ $server_key ] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) )
			: '';

		// Authorization header may carry "Bearer <key>" or "Token <key>" — strip the scheme.
		if ( '' !== $value && 0 === strcasecmp( $name, 'Authorization' ) ) {
			if ( preg_match( '/^(?:Bearer|Token)\s+(.+)$/i', $value, $m ) ) {
				$value = trim( $m[1] );
			}
		}
		return $value;
	}

	/**
	 * Decode the JSON-encoded auth_config column into an assoc array.
	 *
	 * @param  string|null $raw Raw column value.
	 * @return array<string, mixed>
	 */
	private static function parse_auth_config( ?string $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Validate an API key against the getmcp_api_keys table.
	 *
	 * @since  1.0.0
	 * @param  string $token  The raw Bearer token.
	 * @param  Server $server The MCP server.
	 * @return true|string True if valid, error message on failure.
	 */
	private static function validate_api_key( string $token, Server $server ): true|string {
		global $wpdb;

		$table     = $wpdb->prefix . 'getmcp_api_keys';
		$key_hash  = hash( 'sha256', $token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE server_id = %d AND key_hash = %s AND is_active = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$server->id,
				$key_hash
			)
		);

		if ( ! $row ) {
			// No matching key — check whether any keys have been created for this
			// server at all. When none ever have, the server is operating in
			// passthrough mode: the credential the MCP client sends is forwarded
			// directly to the upstream API, which performs the actual validation.
			// Requiring a getMCP-managed key here would block every request until
			// the operator explicitly creates one, which is wrong for that use case.
			//
			// Count every row, not just active ones. An operator who created keys
			// and then revoked them all has explicitly closed the server; revoke is
			// a soft delete (is_active = 0), so counting only active rows would
			// read "never configured" and silently re-open the server to any
			// arbitrary string.
			$key_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE server_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$server->id
				)
			);
			if ( 0 === $key_count && self::upstream_validates_inbound( $server ) ) {
				// Passthrough mode — credential presence already checked by the caller.
				self::$current_scopes = null;
				self::$auth_mode      = 'api-key';
				return true;
			}
			return __( 'Invalid API key.', 'getmcp' );
		}

		// Check expiration.
		if ( ! empty( $row->expires_at ) && strtotime( $row->expires_at ) < time() ) {
			return __( 'API key has expired.', 'getmcp' );
		}

		// Update last used timestamp.
		$wpdb->update(
			$table,
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => $row->id ),
			array( '%s' ),
			array( '%d' )
		);

		// Parse and store scopes for this request.
		$raw_scopes = ! empty( $row->scopes ) ? trim( $row->scopes ) : '';
		if ( '' === $raw_scopes || '*' === $raw_scopes ) {
			self::$current_scopes = null; // Unrestricted.
		} else {
			$decoded = json_decode( $raw_scopes, true );
			self::$current_scopes = is_array( $decoded )
				? array_map( 'trim', $decoded )
				: array_map( 'trim', explode( ',', $raw_scopes ) );
		}
		self::$auth_mode = 'api-key';

		return true;
	}

}
