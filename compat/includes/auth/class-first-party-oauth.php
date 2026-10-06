<?php
/**
 * First-party OAuth for GetMCP, its gateway and opted-in custom servers.
 *
 * Every other server in GetMCP brokers OAuth to somebody else's identity
 * provider — the operator supplies an authorize URL, a token URL and a client
 * secret, and this plugin sits in the middle. That model cannot work here:
 * the resource being protected is the installation's own configuration, and
 * the only identity that means anything is the dashboard account.
 *
 * So for this one server GetMCP is the authorization server. The resource
 * owner is whoever is signed in to wp-admin (or the standalone app), consent
 * is a screen they click, and the account id is written onto the token so
 * every tool call can re-check what that account may still do.
 *
 * OAuth 2.1 rules that are enforced and not negotiable: PKCE with S256, exact
 * redirect-URI matching (except native loopback ports), single-use authorization
 * codes with a short life, and RFC 8707 resource binding so a token minted for
 * this server is refused everywhere else.
 *
 * @package GetMCP
 * @since   1.4.0
 */

namespace GetMCP\Auth;

use GetMCP\Builtin\BuiltinServer;
use GetMCP\Core\Server;
use GetMCP\Gateway\McpGateway;

/**
 * Authorization-code flow with PKCE, owned by this installation.
 *
 * @since 1.4.0
 */
class FirstPartyOAuth {

	/**
	 * How long an authorization code stays usable, in seconds.
	 *
	 * Short by design: the code is handed through a browser redirect and is
	 * exchanged immediately by a client that already holds the verifier.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const CODE_TTL = 300;

	/**
	 * Access-token lifetime, in seconds.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const ACCESS_TTL = 3600;

	/**
	 * Refresh-token lifetime, in seconds.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const REFRESH_TTL = 2592000;

	/**
	 * Capability a user must hold to authorize a client at all.
	 *
	 * Checked here as well as on every tool call. Refusing at the consent
	 * screen means an unprivileged account never gets a token it could not
	 * use — a clearer failure than a token that 403s on first call.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private const CAP = 'getmcp_manage_servers';

	/**
	 * Whether this server's OAuth is handled first-party rather than brokered.
	 *
	 * @since  1.4.0
	 * @param  object $server Server row or object.
	 * @return bool
	 */
	public static function owns( $server ): bool {
		if ( ! isset( $server->slug ) ) {
			return false;
		}

		// The gateway is in the same position as the built-in server: it is
		// synthetic, has no `auth_config` and no upstream identity provider, so
		// brokering can only ever fail with "Upstream OAuth is not fully
		// configured". The identity that matters for it is the dashboard
		// account, which is exactly what this class authenticates.
		if ( McpGateway::slug_is_reserved( (string) $server->slug ) ) {
			return true;
		}

		return BuiltinServer::slug_is_reserved( (string) $server->slug ) || self::is_custom( $server );
	}

	/** Explicit opt-in; existing custom OAuth servers remain external providers. */
	public static function is_custom( $server ): bool {
		return 'oauth' === ( $server->auth_type ?? '' ) && self::config_is_native( $server->auth_config ?? null );
	}

	public static function config_is_native( mixed $config ): bool {
		$config = is_string( $config ) ? json_decode( $config, true ) : $config;
		return is_array( $config ) && 'getmcp' === ( $config['provider'] ?? '' );
	}

	/** Rechecked at consent, exchange, refresh and every protected request. */
	public static function can_user_access( int $user_id, Server $server ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( self::is_custom( $server ) ) {
			if ( ! \GetMCPExtensions\Runtime::server_enabled( $server ) ) { return false; }
			if ( 'active' !== $server->status || ! get_userdata( $user_id ) || ! user_can( $user_id, 'read' ) ) {
				return false;
			}
			$config = json_decode( $server->auth_config ?? '{}', true ) ?: array();
			// Explicit per-server permission, including admins. Missing/empty lists deny everyone.
			if ( ! isset( $config['allowed_user_ids'] ) || ! is_array( $config['allowed_user_ids'] ) || ! in_array( $user_id, $config['allowed_user_ids'], true ) ) {
				return false;
			}
			return true;
		}
		return user_can( $user_id, self::CAP );
	}

	public static function supported_scopes( $server ): array {
		return self::is_custom( $server ) ? array( 'mcp:read', 'mcp:write' ) : array( 'mcp:read', 'mcp:write', 'mcp:admin' );
	}

	/** Bind a registered client and token to the exact endpoint being used. */
	private static function client_matches_server( object $client, Server $server ): bool {
		return 0 === (int) $client->server_id || (int) $client->server_id === (int) $server->id;
	}

	private static function token_matches_server( object $row, Server $server ): bool {
		$resource = self::canonical_resource( $server );
		$requested = isset( $_POST['resource'] ) ? esc_url_raw( wp_unslash( $_POST['resource'] ) ) : '';
		return $resource === (string) ( $row->resource ?? '' ) &&
			( '' === $requested || $resource === self::canonicalize( $requested ) ) &&
			self::can_user_access( (int) ( $row->user_id ?? 0 ), $server );
	}

	/* ----------------------------------------------------------- Authorize */

	/**
	 * Handle GET/POST on the authorize endpoint.
	 *
	 * GET renders consent; POST records the decision. Both run through the same
	 * request validation, so an approval cannot carry parameters the initial
	 * request never passed.
	 *
	 * @since  1.4.0
	 * @param  Server $server The built-in server.
	 * @return void
	 */
	public static function handle_authorize( Server $server ): void {
		$request = self::read_authorize_request();

		if ( isset( $request['error'] ) ) {
			self::json_error( 400, $request['error'], $request['error_description'] );
			return;
		}

		$client = self::find_client( $request['client_id'] );

		if ( ! $client ) {
			self::json_error( 400, 'invalid_client', 'Unknown client_id. Register the client first.' );
			return;
		}
		if ( ! self::client_matches_server( $client, $server ) ) {
			self::json_error( 400, 'invalid_client', 'This client is registered for another MCP server.' );
			return;
		}
		if ( self::is_custom( $server ) ) {
			$scopes = preg_split( '/\s+/', trim( $request['scope'] ) ) ?: array();
			$scopes = array_values( array_unique( array_filter( $scopes, 'strlen' ) ) );
			if ( array_diff( $scopes, self::supported_scopes( $server ) ) ) {
				self::json_error( 400, 'invalid_scope', 'Supported scopes: mcp:read, mcp:write.' );
				return;
			}
			$request['scope'] = implode( ' ', $scopes ?: array( 'mcp:read' ) );
		}

		// Validated before anyone is redirected anywhere: an unchecked
		// redirect_uri here is an open redirect wearing an OAuth costume.
		$allowed = json_decode( (string) $client->redirect_uris, true );
		$allowed = is_array( $allowed ) ? $allowed : array();

		if ( ! self::redirect_uri_allowed( $request['redirect_uri'], $allowed ) ) {
			self::json_error( 400, 'invalid_request', 'redirect_uri is not registered for this client.' );
			return;
		}

		$expected_resource = self::canonical_resource( $server );

		if ( '' !== $request['resource'] && self::canonicalize( $request['resource'] ) !== $expected_resource ) {
			self::json_error( 400, 'invalid_target', 'The requested resource does not match this MCP server.' );
			return;
		}

		// Not signed in: send them to the dashboard login and come straight
		// back. This is the whole "authenticate once by logging in" story —
		// there is no separate credential for the MCP client to hold.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}

		if ( ! self::can_user_access( get_current_user_id(), $server ) ) {
			self::deny_page(
				__( 'Your account cannot access this MCP server', 'getmcp' ),
				self::is_custom( $server ) ? __( 'Ask an administrator to add your WordPress account to this server’s allowed users, then connect again.', 'getmcp' ) : __( 'Ask an administrator to grant your account the GetMCP server-management capability, then connect this client again.', 'getmcp' )
			);
			return;
		}

		$decision = isset( $_POST['getmcp_decision'] ) ? sanitize_text_field( wp_unslash( $_POST['getmcp_decision'] ) ) : '';

		if ( '' === $decision ) {
			self::consent_page( $client, $request, $server );
			return;
		}

		if ( ! isset( $_POST['getmcp_consent_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['getmcp_consent_nonce'] ) ), 'getmcp_oauth_consent' ) ) {
			self::json_error( 400, 'invalid_request', 'The consent form expired. Start the connection again.' );
			return;
		}

		// RFC 9207: the metadata advertises authorization_response_iss_parameter_supported,
		// so every authorization response — refusal included — must carry `iss`,
		// byte-identical to the issuer in that metadata. A client that honours the
		// flag (ChatGPT does) discards a response without it and starts over,
		// which looked like "problem connecting" after a successful consent.
		$issuer = OAuthProxy::server_base_url( $server );

		if ( 'approve' !== $decision ) {
			self::redirect_back( $request['redirect_uri'], array( 'error' => 'access_denied', 'state' => $request['state'] ), $issuer );
			return;
		}

		$code = self::issue_code( (int) $client->id, get_current_user_id(), $request, $expected_resource );

		self::redirect_back( $request['redirect_uri'], array( 'code' => $code, 'state' => $request['state'] ), $issuer );
	}

	/**
	 * Read and validate the authorize request parameters.
	 *
	 * @since  1.4.0
	 * @return array<string, string> Parsed request, or an `error` entry.
	 */
	private static function read_authorize_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$get = static function ( string $key, string $default = '' ): string {
			if ( isset( $_POST[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
		};
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// URL-valued parameters take esc_url_raw(), never sanitize_text_field(),
		// which would strip any percent-encoding they legitimately carry.
		$get_url = static function ( string $key ): string {
			if ( isset( $_POST[ $key ] ) ) {
				return esc_url_raw( wp_unslash( $_POST[ $key ] ) );
			}
			return isset( $_GET[ $key ] ) ? esc_url_raw( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$request = array(
			'client_id'             => $get( 'client_id' ),
			'redirect_uri'          => $get_url( 'redirect_uri' ),
			'response_type'         => $get( 'response_type' ),
			'scope'                 => $get( 'scope' ),
			'state'                 => $get( 'state' ),
			'code_challenge'        => $get( 'code_challenge' ),
			'code_challenge_method' => $get( 'code_challenge_method', 'S256' ),
			'resource'              => $get_url( 'resource' ),
		);

		if ( 'code' !== $request['response_type'] ) {
			return $request + array( 'error' => 'unsupported_response_type', 'error_description' => 'Only response_type=code is supported.' );
		}
		if ( '' === $request['client_id'] ) {
			return $request + array( 'error' => 'invalid_request', 'error_description' => 'client_id is required.' );
		}
		if ( '' === $request['redirect_uri'] ) {
			return $request + array( 'error' => 'invalid_request', 'error_description' => 'redirect_uri is required.' );
		}
		if ( '' === $request['code_challenge'] ) {
			return $request + array( 'error' => 'invalid_request', 'error_description' => 'PKCE code_challenge is required (OAuth 2.1).' );
		}
		if ( ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/D', $request['code_challenge'] ) ) {
			return $request + array( 'error' => 'invalid_request', 'error_description' => 'A valid S256 PKCE code_challenge is required.' );
		}
		// S256 only. `plain` — and any unrecognised value that might fall
		// through to it — lets anyone who can read the authorize request replay
		// the challenge as the verifier.
		if ( 'S256' !== $request['code_challenge_method'] ) {
			return $request + array( 'error' => 'invalid_request', 'error_description' => 'Only code_challenge_method=S256 is supported.' );
		}

		return $request;
	}

	/**
	 * Persist an authorization code bound to the consenting account.
	 *
	 * @since  1.4.0
	 * @param  int                   $client_id Internal client row id.
	 * @param  int                   $user_id   Consenting user.
	 * @param  array<string, string> $request   Validated authorize request.
	 * @param  string                $resource  Canonical resource URI.
	 * @return string The authorization code to hand back to the client.
	 */
	private static function issue_code( int $client_id, int $user_id, array $request, string $resource ): string {
		global $wpdb;

		$code = bin2hex( random_bytes( 32 ) );

		$wpdb->insert(
			$wpdb->prefix . 'getmcp_oauth_tokens',
			array(
				'client_id'               => $client_id,
				'user_id'                 => $user_id,
				'authorization_code_hash' => hash( 'sha256', $code ),
				'code_challenge'          => $request['code_challenge'],
				'code_challenge_method'   => 'S256',
				'scopes'                  => '' !== $request['scope'] ? $request['scope'] : '*',
				'resource'                => $resource,
				'access_token_hash'       => '',
				'expires_at'              => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Bind the token exchange to this exact callback, including its port.
		set_transient( 'getmcp_code_redirect_' . hash( 'sha256', $code ), $request['redirect_uri'], self::CODE_TTL );

		return $code;
	}

	/**
	 * Match registered callbacks, permitting only RFC 8252 loopback port changes.
	 * All characters outside the optional port must still match exactly.
	 */
	private static function redirect_uri_allowed( string $uri, array $allowed ): bool {
		if ( in_array( $uri, $allowed, true ) ) {
			return true;
		}

		$pattern = '~\Ahttp://(127\.0\.0\.1|\[::1\])(?::([0-9]{1,5}))?(/[^#]*)?\z~D';
		if ( ! preg_match( $pattern, $uri, $requested ) ) {
			return false;
		}
		if ( isset( $requested[2] ) && '' !== $requested[2] && ( (int) $requested[2] < 1 || (int) $requested[2] > 65535 ) ) {
			return false;
		}
		$without_port = preg_replace( '~\A(http://(?:127\.0\.0\.1|\[::1\])):[0-9]{1,5}(?=/|$)~', '$1', $uri );
		foreach ( $allowed as $registered ) {
			if ( ! is_string( $registered ) || ! preg_match( $pattern, $registered, $parts ) ) {
				continue;
			}
			if ( isset( $parts[2] ) && '' !== $parts[2] && ( (int) $parts[2] < 1 || (int) $parts[2] > 65535 ) ) {
				continue;
			}
			if ( $without_port === preg_replace( '~\A(http://(?:127\.0\.0\.1|\[::1\])):[0-9]{1,5}(?=/|$)~', '$1', $registered ) ) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------- Token */

	/**
	 * Handle the token endpoint.
	 *
	 * @since  1.4.0
	 * @param  Server $server The built-in server.
	 * @return void
	 */
	public static function handle_token( Server $server ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$grant_type = isset( $_POST['grant_type'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		switch ( $grant_type ) {
			case 'authorization_code':
				self::grant_authorization_code( $server );
				return;
			case 'refresh_token':
				self::grant_refresh_token( $server );
				return;
		}

		self::json_error( 400, 'unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.' );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @since  1.4.0
	 * @param  Server $server The built-in server.
	 * @return void
	 */
	private static function grant_authorization_code( Server $server ): void {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$code          = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$verifier      = isset( $_POST['code_verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['code_verifier'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $code || '' === $verifier ) {
			self::json_error( 400, 'invalid_request', 'code and code_verifier are required.' );
			return;
		}
		if ( ! preg_match( '/\A[A-Za-z0-9._~-]{43,128}\z/D', $verifier ) ) {
			self::json_error( 400, 'invalid_grant', 'Invalid PKCE verifier.' );
			return;
		}

		$tokens_table = $wpdb->prefix . 'getmcp_oauth_tokens';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tokens_table} WHERE authorization_code_hash = %s AND expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				hash( 'sha256', $code )
			)
		);

		if ( ! $row ) {
			self::json_error( 400, 'invalid_grant', 'The authorization code is invalid or has expired.' );
			return;
		}

		$client = self::find_client_by_row_id( (int) $row->client_id );

		if ( ! $client || '' === $client_id || $client->client_id !== $client_id || ! self::client_matches_server( $client, $server ) ) {
			self::json_error( 400, 'invalid_client', 'The code was not issued to this client.' );
			return;
		}
		if ( ! self::token_matches_server( $row, $server ) ) {
			self::json_error( 400, 'invalid_grant', 'The code is not authorized for this server or account.' );
			return;
		}

		// PKCE. Constant-time so a mismatch cannot be found by timing.
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		if ( ! hash_equals( (string) $row->code_challenge, $expected ) ) {
			self::json_error( 400, 'invalid_grant', 'PKCE verification failed.' );
			return;
		}

		$redirect_key = 'getmcp_code_redirect_' . hash( 'sha256', $code );
		$issued_redirect = get_transient( $redirect_key );
		if ( ! is_string( $issued_redirect ) || '' === $redirect_uri || ! hash_equals( $issued_redirect, $redirect_uri ) ) {
			self::json_error( 400, 'invalid_grant', 'redirect_uri does not match the one used to authorize.' );
			return;
		}

		$access  = bin2hex( random_bytes( 32 ) );
		$refresh = bin2hex( random_bytes( 32 ) );

		// The code row becomes the token row, and the code hash is cleared in
		// the same statement — that is what makes a code single-use. A second
		// exchange finds nothing to match.
		$consumed = $wpdb->update(
			$tokens_table,
			array(
				'access_token_hash'       => hash( 'sha256', $access ),
				'refresh_token_hash'      => hash( 'sha256', $refresh ),
				'authorization_code_hash' => null,
				'code_challenge'          => null,
				'expires_at'              => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TTL ),
				'refresh_expires_at'      => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TTL ),
			),
			array( 'id' => (int) $row->id, 'authorization_code_hash' => hash( 'sha256', $code ) ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $consumed ) {
			self::json_error( 400, 'invalid_grant', 'The authorization code was already used.' );
			return;
		}

		delete_transient( $redirect_key );
		self::send_tokens( $access, $refresh, (string) $row->scopes );
	}

	/**
	 * Exchange a refresh token for a new access token.
	 *
	 * @since  1.4.0
	 * @param  Server $server The built-in server.
	 * @return void
	 */
	private static function grant_refresh_token( Server $server ): void {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$refresh = isset( $_POST['refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['refresh_token'] ) ) : '';

		if ( '' === $refresh ) {
			self::json_error( 400, 'invalid_request', 'refresh_token is required.' );
			return;
		}

		$tokens_table = $wpdb->prefix . 'getmcp_oauth_tokens';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tokens_table} WHERE refresh_token_hash = %s AND refresh_expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				hash( 'sha256', $refresh )
			)
		);

		if ( ! $row ) {
			self::json_error( 400, 'invalid_grant', 'The refresh token is invalid or has expired.' );
			return;
		}

		// The account may have lost the capability since the token was minted.
		// Refusing to refresh is how that revocation actually takes effect.
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client = self::find_client_by_row_id( (int) $row->client_id );
		if ( ! $client || '' === $client_id || $client->client_id !== $client_id || ! self::client_matches_server( $client, $server ) || ! self::token_matches_server( $row, $server ) ) {
			self::json_error( 400, 'invalid_grant', 'The authorizing account can no longer manage MCP servers.' );
			return;
		}

		$access      = bin2hex( random_bytes( 32 ) );
		$new_refresh = bin2hex( random_bytes( 32 ) );

		// Rotated, not reused: a leaked refresh token is only good until the
		// legitimate client refreshes once.
		$rotated = $wpdb->update(
			$tokens_table,
			array(
				'access_token_hash'  => hash( 'sha256', $access ),
				'refresh_token_hash' => hash( 'sha256', $new_refresh ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TTL ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TTL ),
			),
			array( 'id' => (int) $row->id, 'refresh_token_hash' => hash( 'sha256', $refresh ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $rotated ) {
			self::json_error( 400, 'invalid_grant', 'The refresh token was already used.' );
			return;
		}

		self::send_tokens( $access, $new_refresh, (string) $row->scopes );
	}

	/**
	 * Emit a token response.
	 *
	 * @since  1.4.0
	 * @param  string $access  Access token.
	 * @param  string $refresh Refresh token.
	 * @param  string $scopes  Granted scopes.
	 * @return void
	 */
	private static function send_tokens( string $access, string $refresh, string $scopes ): void {
		status_header( 200 );
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );

		echo wp_json_encode(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => '*' === $scopes ? '' : $scopes,
			)
		);
	}

	/* ------------------------------------------------------------- Screens */

	/**
	 * Render the consent screen.
	 *
	 * Deliberately plain HTML rather than an admin page: this URL is opened by
	 * an MCP client, often in a bare browser window, and it has to render
	 * correctly without the admin shell around it.
	 *
	 * @since  1.4.0
	 * @param  object               $client  Client row.
	 * @param  array<string,string> $request Validated authorize request.
	 * @return void
	 */
	private static function consent_page( object $client, array $request, Server $server ): void {
		$custom = self::is_custom( $server );
		$user  = wp_get_current_user();
		$hidden = array(
			'client_id'             => $request['client_id'],
			'redirect_uri'          => $request['redirect_uri'],
			'response_type'         => 'code',
			'scope'                 => $request['scope'],
			'state'                 => $request['state'],
			'code_challenge'        => $request['code_challenge'],
			'code_challenge_method' => 'S256',
			'resource'              => $request['resource'],
		);

		$client_name = $client->client_name ? $client->client_name : __( 'An MCP client', 'getmcp' );

		// Where the browser goes after approval — shown so what happens next is
		// never a surprise, and a consent link opened out of context names the
		// place it would hand the code to. Native clients register private-use
		// schemes (cursor://…) with no host, so the scheme stands in then.
		$return_to = wp_parse_url( $request['redirect_uri'], PHP_URL_HOST );
		if ( ! is_string( $return_to ) || '' === $return_to ) {
			$return_to = (string) wp_parse_url( $request['redirect_uri'], PHP_URL_SCHEME );
		}

		$initial = function_exists( 'mb_substr' ) ? mb_substr( (string) $user->display_name, 0, 1 ) : substr( (string) $user->display_name, 0, 1 );
		$initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial ) : strtoupper( $initial );

		// Judge the client by the one redirect URI actually in play, not by every
		// URI it ever registered — the code lands on this one, so this one is the
		// only claim about identity that the flow itself can back up.
		$client_slug = ClientBrand::detect( (string) $client->client_name, array( $request['redirect_uri'] ) );

		self::page_open( __( 'Connect to GetMCP', 'getmcp' ) );
		?>
		<main class="card" role="main" aria-labelledby="consent-title">

			<div class="handshake" aria-hidden="true">
				<div class="app-badge app-badge--client" title="<?php echo esc_attr( $client_name ); ?>">
					<?php if ( '' !== $client_slug ) : ?>
						<img src="<?php echo esc_url( ClientBrand::url( $client_slug ) ); ?>" alt="" width="32" height="32">
					<?php else : ?>
						<span class="app-badge__initial"><?php echo esc_html( ClientBrand::initial( $client_name ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="beam"></div>
				<div class="app-badge app-badge--getmcp" title="GetMCP">
					<?php echo self::getmcp_mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static brand SVG shipped with the plugin. ?>
				</div>
			</div>

			<div class="titleblock">
				<h1 id="consent-title">
				<?php
				printf(
					/* translators: 1: MCP client name (bold), 2: the product name "GetMCP". */
					esc_html__( '%1$s wants to connect to %2$s', 'getmcp' ),
					'<strong>' . esc_html( $client_name ) . '</strong>',
						'<span class="brand">' . esc_html( $custom ? $server->name : 'GetMCP' ) . '</span>'
				);
				?>
				</h1>
				<div class="account">
					<span class="avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
					<span>
					<?php
					printf(
						/* translators: %s: user display name. */
						esc_html__( 'Signed in as %s', 'getmcp' ),
						'<b>' . esc_html( $user->display_name ) . '</b>'
					);
					?>
					</span>
				</div>
			</div>

			<section class="scopes" aria-label="<?php esc_attr_e( 'Requested permissions', 'getmcp' ); ?>">
				<div class="scopes-label">
				<?php
				printf(
					/* translators: %s: MCP client name. */
					esc_html__( 'This will allow %s to', 'getmcp' ),
					esc_html( $client_name )
				);
				?>
				</div>

				<?php if ( $custom ) : ?>
				<div class="scope"><div class="scope-body">
					<div class="scope-title"><?php echo esc_html( $server->name ); ?></div>
					<div class="scope-sub"><?php echo esc_html( $server->get_endpoint_url() ); ?></div>
					<p><?php echo esc_html( str_contains( $request['scope'], 'mcp:write' ) ? __( 'Use the read and write tools on this server.', 'getmcp' ) : __( 'Use the read-only tools on this server.', 'getmcp' ) ); ?></p>
					<?php if ( 'gateway' === $server->server_kind ) : ?>
					<p><?php esc_html_e( 'This gateway grants access to its selected member servers. Direct-server user restrictions apply only to direct connections.', 'getmcp' ); ?></p>
					<?php else : ?>
					<p><?php esc_html_e( 'This connection does not grant access to other MCP servers or the gateway.', 'getmcp' ); ?></p>
					<?php endif; ?>
					<p><a href="<?php echo esc_url( \GetMCP\Remote\UpstreamConnections::portal_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Manage your upstream accounts in My MCP Connections', 'getmcp' ); ?></a></p>
				</div></div>
				<?php else : ?>
				<div class="scope">
					<div class="scope-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
					</div>
					<div class="scope-body">
						<div class="scope-title"><?php esc_html_e( 'View your MCP servers and tools', 'getmcp' ); ?></div>
						<div class="scope-sub"><?php esc_html_e( 'See server settings, tool schemas and endpoint details.', 'getmcp' ); ?></div>
					</div>
				</div>

				<div class="scope">
					<div class="scope-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
					</div>
					<div class="scope-body">
						<div class="scope-title"><?php esc_html_e( 'Create and edit servers and tools', 'getmcp' ); ?></div>
						<div class="scope-sub"><?php esc_html_e( 'Build new MCP tools from your API docs and update existing ones.', 'getmcp' ); ?></div>
					</div>
				</div>

				<div class="scope danger">
					<div class="scope-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
					</div>
					<div class="scope-body">
						<div class="scope-title"><?php esc_html_e( 'Delete tools', 'getmcp' ); ?></div>
						<div class="scope-sub"><?php esc_html_e( 'Permanently removes a tool. This can’t be undone.', 'getmcp' ); ?></div>
					</div>
				</div>
				<?php endif; ?>
			</section>

			<section class="guarantees" aria-label="<?php esc_attr_e( 'Protections', 'getmcp' ); ?>">
				<div class="guarantee">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					<span>
					<?php
					printf(
						/* translators: %s: "can’t read your stored credentials", kept bold. */
						esc_html__( 'It %s, and it can’t delete a server.', 'getmcp' ),
						'<b>' . esc_html__( 'can’t read your stored credentials', 'getmcp' ) . '</b>'
					);
					?>
					</span>
				</div>
				<div class="guarantee">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 2l-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/><circle cx="7.5" cy="15.5" r="5.5"/></svg>
					<span>
					<?php
					printf(
						/* translators: 1: "revocable token", kept bold. 2: MCP client name. */
						esc_html__( 'Approving issues a %1$s — %2$s never sees your password.', 'getmcp' ),
						'<b>' . esc_html__( 'revocable token', 'getmcp' ) . '</b>',
						esc_html( $client_name )
					);
					?>
					</span>
				</div>
			</section>

			<form method="post">
				<?php
				foreach ( $hidden as $name => $value ) {
					printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) );
				}
				wp_nonce_field( 'getmcp_oauth_consent', 'getmcp_consent_nonce' );
				?>
				<div class="actions">
					<button type="submit" name="getmcp_decision" value="deny" class="btn btn-ghost"><?php esc_html_e( 'Cancel', 'getmcp' ); ?></button>
					<button type="submit" name="getmcp_decision" value="approve" class="btn btn-primary"><?php esc_html_e( 'Approve access', 'getmcp' ); ?></button>
				</div>
			</form>

			<footer class="foot">
				<div class="redirect">
				<?php
				printf(
					/* translators: %s: hostname the browser returns to after approval. */
					esc_html__( 'After approving, you’ll return to %s', 'getmcp' ),
					'<code>' . esc_html( $return_to ) . '</code>'
				);
				?>
				</div>
				<div class="revoke"><?php esc_html_e( $custom ? 'An administrator can revoke this access from GetMCP settings.' : 'Turn this off anytime from the Manage with AI screen.', 'getmcp' ); ?></div>
			</footer>

		</main>
		<?php
		self::page_close();
	}

	/**
	 * Render a refusal screen.
	 *
	 * @since  1.4.0
	 * @param  string $title   Heading.
	 * @param  string $message Explanation.
	 * @return void
	 */
	private static function deny_page( string $title, string $message ): void {
		status_header( 403 );
		self::page_open( $title );
		echo '<div class="card"><div class="plain"><h1>' . esc_html( $title ) . '</h1><p class="lede">' . esc_html( $message ) . '</p></div></div>';
		self::page_close();
	}

	/**
	 * Open a standalone HTML document.
	 *
	 * @since  1.4.0
	 * @param  string $title Document title.
	 * @return void
	 */
	private static function page_open( string $title ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $title ); ?></title>
<style>
/* One stylesheet, two appearances: light is the default, and the dark values
   take over purely from the visitor's system preference. */
:root {
	color-scheme: light dark;
	--bg: #f4f7f6;
	--bg-texture: radial-gradient(circle at 20% 0%, rgba(13,148,136,.06), transparent 45%),
	              radial-gradient(circle at 90% 100%, rgba(13,148,136,.05), transparent 40%);
	--card: #ffffff;
	--card-border: #e4eae8;
	--ink: #10201d;
	--ink-soft: #5a6b67;
	--ink-faint: #8a9995;
	--accent: #0d9488;
	--accent-tint: #e6f4f2;
	--brand: #0b7f75;
	--danger: #d13f3f;
	--danger-tint: #fdeeee;
	--danger-border: #f3cfcf;
	--danger-sub: #a35555;
	--danger-icon-bg: #ffffff;
	--panel: #f6f9f8;
	--panel-border: #e7edeb;
	--shadow: 0 1px 2px rgba(16,32,29,.05), 0 12px 40px -12px rgba(16,32,29,.14);
	--badge-bg: #ffffff;
	--badge-shadow: 0 2px 8px rgba(16,32,29,.06);
	--btn-primary-fg: #ffffff;
	--btn-primary-hover: #0b7f75;
	--btn-primary-shadow: 0 6px 18px -6px rgba(13,148,136,.55);
	--btn-ghost-bg: #ffffff;
	--avatar-fg: #ffffff;
	--avatar-g2: #25b5a8;
	--beam-dot: #0d9488;
	--beam-glow: 0 0 0 4px rgba(13,148,136,.15);
	/* No webfont request: a self-hosted auth page must not report its visitors
	   to a font CDN. The named families apply when installed locally. */
	--font-display: "Space Grotesk", "Avenir Next", "Segoe UI", system-ui, sans-serif;
	--font-body: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	--font-mono: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace;
}
@media (prefers-color-scheme: dark) {
	:root {
		--bg: #0b0f0e;
		--bg-texture: radial-gradient(circle at 20% 0%, rgba(20,184,166,.08), transparent 45%),
		              radial-gradient(circle at 90% 100%, rgba(20,184,166,.05), transparent 40%);
		--card: #121817;
		--card-border: #24302e;
		--ink: #e8eeec;
		--ink-soft: #93a3a0;
		--ink-faint: #64726f;
		--accent: #14b8a6;
		--accent-tint: rgba(20,184,166,.12);
		--brand: #2dd4bf;
		--danger: #f07b7b;
		--danger-tint: rgba(209,63,63,.10);
		--danger-border: rgba(209,63,63,.28);
		--danger-sub: #b98a8a;
		--danger-icon-bg: rgba(209,63,63,.15);
		--panel: #171f1e;
		--panel-border: #23302d;
		--shadow: 0 1px 2px rgba(0,0,0,.4), 0 24px 60px -12px rgba(0,0,0,.6);
		--badge-bg: #181f1e;
		--badge-shadow: inset 0 1px 0 rgba(255,255,255,.03);
		--btn-primary-fg: #04201c;
		--btn-primary-hover: #2dd4bf;
		--btn-primary-shadow: 0 8px 24px -8px rgba(20,184,166,.55);
		--btn-ghost-bg: transparent;
		--avatar-fg: #06211d;
		--avatar-g2: #2dd4bf;
		--beam-dot: #2dd4bf;
		--beam-glow: 0 0 12px 2px rgba(45,212,191,.5);
	}
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
	/* vh, not a percentage: min-height 100% resolves against nothing when the
	   parent has no definite height, and the flex centering silently becomes
	   top-alignment. dvh, where supported, keeps mobile URL bars honest. */
	min-height: 100vh;
	min-height: 100dvh;
	font-family: var(--font-body);
	background: var(--bg);
	background-image: var(--bg-texture);
	color: var(--ink);
	display: flex; align-items: center; justify-content: center;
	padding: 32px 16px;
	-webkit-font-smoothing: antialiased;
}
.card {
	width: 100%; max-width: 460px;
	background: var(--card);
	border: 1px solid var(--card-border);
	border-radius: 20px;
	box-shadow: var(--shadow);
	overflow: hidden;
}
.plain { padding: 28px 32px; }
.plain .lede { margin-top: 10px; font-size: 14.5px; color: var(--ink-soft); line-height: 1.5; }

/* ---------- Handshake header ---------- */
.handshake { padding: 36px 32px 8px; display: flex; align-items: center; justify-content: center; }
.app-badge {
	width: 56px; height: 56px; border-radius: 16px; overflow: hidden;
	display: flex; align-items: center; justify-content: center; flex-shrink: 0;
	border: 1px solid var(--card-border);
	background: var(--badge-bg);
	box-shadow: var(--badge-shadow);
}
/* Vendor marks (ChatGPT, Claude, Cursor…) are drawn as dark ink for a light
   ground, so the client tile stays white in both colour schemes — the same
   white tile the GetMCP mark paints for itself. On the dark theme a
   theme-coloured tile left a black OpenAI mark on near-black. */
.app-badge--client { background: #ffffff; color: #10201d; }
.app-badge--client img { width: 32px; height: 32px; display: block; object-fit: contain; }
.app-badge__initial { font-size: 22px; font-weight: 600; line-height: 1; letter-spacing: .01em; color: #5a6b67; }
.app-badge--getmcp svg { width: 100%; height: 100%; display: block; }
.beam {
	width: 88px; height: 2px; position: relative; margin: 0 10px;
	background: repeating-linear-gradient(90deg, var(--accent) 0 5px, transparent 5px 11px);
	opacity: .5; border-radius: 2px;
}
.beam::after {
	content: ""; position: absolute; top: 50%; left: 0;
	width: 8px; height: 8px; border-radius: 50%;
	background: var(--beam-dot);
	transform: translateY(-50%);
	animation: travel 2.2s ease-in-out infinite;
	box-shadow: var(--beam-glow);
}
@keyframes travel { 0%, 100% { left: 0; } 50% { left: calc(100% - 8px); } }
@media (prefers-reduced-motion: reduce) { .beam::after { animation: none; left: calc(50% - 4px); } }

/* ---------- Title ---------- */
.titleblock { padding: 20px 32px 4px; text-align: center; }
h1 { font-family: var(--font-display); font-size: 22px; font-weight: 600; letter-spacing: -.01em; line-height: 1.3; }
h1 .brand { color: var(--brand); }
.account {
	margin-top: 12px; display: inline-flex; align-items: center; gap: 8px;
	padding: 5px 12px 5px 6px;
	border: 1px solid var(--card-border); border-radius: 999px;
	font-size: 13px; color: var(--ink-soft); background: var(--panel);
}
.avatar {
	width: 22px; height: 22px; border-radius: 50%;
	background: linear-gradient(135deg, var(--accent), var(--avatar-g2));
	color: var(--avatar-fg); font-size: 11px; font-weight: 700;
	display: flex; align-items: center; justify-content: center;
	font-family: var(--font-display);
}
.account b { color: var(--ink); font-weight: 600; }

/* ---------- Scopes ---------- */
.scopes { padding: 24px 24px 0; }
.scopes-label {
	font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase;
	color: var(--ink-faint); padding: 0 8px 10px;
}
.scope { display: flex; align-items: flex-start; gap: 12px; padding: 13px 12px; border-radius: 12px; border: 1px solid transparent; }
.scope + .scope { margin-top: 4px; }
.scope-icon {
	width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
	display: flex; align-items: center; justify-content: center;
	background: var(--accent-tint); color: var(--brand);
}
.scope-icon svg { width: 17px; height: 17px; }
.scope-body { flex: 1; min-width: 0; }
.scope-title { font-size: 14.5px; font-weight: 500; line-height: 1.35; color: var(--ink); }
.scope-sub { font-size: 12.5px; color: var(--ink-soft); margin-top: 2px; line-height: 1.45; }
.scope.danger { background: var(--danger-tint); border-color: var(--danger-border); }
.scope.danger .scope-icon { background: var(--danger-icon-bg); color: var(--danger); }
.scope.danger .scope-title { color: var(--danger); }
.scope.danger .scope-sub { color: var(--danger-sub); }

/* ---------- Guarantees ---------- */
.guarantees {
	margin: 20px 24px 0;
	background: var(--panel); border: 1px solid var(--panel-border);
	border-radius: 14px; padding: 14px 16px;
}
.guarantee { display: flex; align-items: flex-start; gap: 10px; font-size: 12.5px; color: var(--ink-soft); line-height: 1.5; }
.guarantee + .guarantee { margin-top: 9px; }
.guarantee svg { width: 15px; height: 15px; flex-shrink: 0; color: var(--brand); margin-top: 1.5px; }
.guarantee b { color: var(--ink); font-weight: 500; }

/* ---------- Actions ---------- */
.actions { display: flex; gap: 10px; padding: 24px 24px 20px; }
.btn {
	flex: 1; font-family: var(--font-body); font-size: 14.5px; font-weight: 600;
	padding: 13px 0; border-radius: 12px; border: 1px solid transparent; cursor: pointer;
	transition: background .15s ease, transform .06s ease, box-shadow .15s ease;
}
.btn:active { transform: translateY(1px); }
.btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
.btn-ghost { background: var(--btn-ghost-bg); border-color: var(--card-border); color: var(--ink); }
.btn-ghost:hover { background: var(--panel); }
.btn-primary { background: var(--accent); color: var(--btn-primary-fg); box-shadow: var(--btn-primary-shadow); }
.btn-primary:hover { background: var(--btn-primary-hover); }

/* ---------- Footer ---------- */
.foot { border-top: 1px solid var(--card-border); background: var(--panel); padding: 13px 24px 15px; text-align: center; }
.foot .redirect { font-size: 11.5px; color: var(--ink-faint); }
.foot .redirect code { font-family: var(--font-mono); font-size: 10.5px; color: var(--ink-soft); }
.foot .revoke { font-size: 11.5px; color: var(--ink-faint); margin-top: 4px; }

@media (max-width: 420px) {
	.handshake { padding-top: 28px; }
	.beam { width: 56px; }
	.titleblock, .scopes, .actions { padding-left: 18px; padding-right: 18px; }
	.guarantees { margin-left: 18px; margin-right: 18px; }
}
</style>
</head>
<body>
		<?php
	}

	/**
	 * Close the standalone HTML document.
	 *
	 * @since  1.4.0
	 * @return void
	 */
	private static function page_close(): void {
		echo '</body></html>';
	}

	/* ------------------------------------------------------------- Helpers */

	/**
	 * The GetMCP brand mark, inlined.
	 *
	 * The consent page is deliberately self-contained: it is often the first
	 * thing an operator sees from an unfamiliar browser window, and a page that
	 * fetches nothing is one that renders the same everywhere and reports its
	 * visitors to no one. The file also lives at assets/images/getmcp-icon.svg;
	 * this copy differs only in dropping the XML prolog and fixed pixel size.
	 *
	 * @since  1.4.0
	 * @return string SVG markup.
	 */
	private static function getmcp_mark(): string {
		return <<<'SVG'
<svg aria-hidden="true" viewBox="0 0 250 250" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
    <defs>
        <path d="M0,0 L250,0 L250,250 L0,250 L0,0 Z" id="path-1"></path>
        <path d="M0,0 L191,0 L191,234.257519 L0,234.257519 L0,0 Z" id="path-2"></path>
        <path d="M0,0 L45,0 L45,81 L0,81 L0,0 Z" id="path-3"></path>
        <path d="M0,0 L75,0 L75,59 L0,59 L0,0 Z" id="path-4"></path>
        <path d="M0,0 L76,0 L76,60 L0,60 L0,0 Z" id="path-5"></path>
        <path d="M0,0 L45,0 L45,82 L0,82 L0,0 Z" id="path-6"></path>
    </defs>
    <g id="getmcp-icon" stroke="none" fill="none" xlink:href="#path-1" fill-rule="evenodd">
        <use fill="#FFFFFF" xlink:href="#path-1"></use>
        <g id="icon" stroke-width="1" transform="translate(29, 8)">
            <g id="getmcp" transform="translate(1, -0)" xlink:href="#path-2">
                <rect id="Rectangle" fill="#19303F" fill-rule="evenodd" x="132" y="109" width="59" height="15" rx="7.5"></rect>
                <g id="Frame" transform="translate(81, 146)" xlink:href="#path-3" fill="#157D87" fill-rule="evenodd">
                    <path d="M7.11780105,15.7356021 L38.117801,15.7356021 C41.9837943,15.7356021 45.117801,18.8696088 45.117801,22.7356021 C45.117801,26.6015953 41.9837943,29.7356021 38.117801,29.7356021 L7.11780105,29.7356021 C3.2518078,29.7356021 0.117801047,26.6015953 0.117801047,22.7356021 C0.117801047,18.8696088 3.2518078,15.7356021 7.11780105,15.7356021 Z" id="Rectangle" transform="translate(22.6178, 22.7356) rotate(90) translate(-22.6178, -22.7356)"></path>
                    <circle id="Oval" cx="22.617801" cy="58.4293194" r="22.617801"></circle>
                </g>
                <g id="Frame" transform="translate(5, 47)" xlink:href="#path-4" fill="#157D87" fill-rule="evenodd">
                    <path d="M36.0967835,35.3997562 L70.0967835,35.3997562 C73.9627768,35.3997562 77.0967835,38.5337629 77.0967835,42.3997562 C77.0967835,46.2657494 73.9627768,49.3997562 70.0967835,49.3997562 L36.0967835,49.3997562 C32.2307903,49.3997562 29.0967835,46.2657494 29.0967835,42.3997562 C29.0967835,38.5337629 32.2307903,35.3997562 36.0967835,35.3997562 Z" id="Rectangle" transform="translate(53.0968, 42.3998) rotate(30) translate(-53.0968, -42.3998)"></path>
                    <circle id="Oval" cx="22.617801" cy="22.617801" r="22.617801"></circle>
                </g>
                <g id="Frame" transform="translate(5, 126)" xlink:href="#path-5" fill="#19303F" fill-rule="evenodd">
                    <path d="M36.6238069,10.5970154 L71.4300896,10.5970154 C75.073434,10.5970154 78.0269482,13.5505297 78.0269482,17.1938741 C78.0269482,20.8372185 75.073434,23.7907327 71.4300896,23.7907327 L36.6238069,23.7907327 C32.9804624,23.7907327 30.0269482,20.8372185 30.0269482,17.1938741 C30.0269482,13.5505297 32.9804624,10.5970154 36.6238069,10.5970154 Z" id="Rectangle" transform="translate(54.0269, 17.1939) rotate(149) translate(-54.0269, -17.1939)"></path>
                    <circle id="Oval" cx="22.617801" cy="37.453647" r="22.617801"></circle>
                </g>
                <g id="Frame" transform="translate(81, 7)" xlink:href="#path-6" fill="#19303F" fill-rule="evenodd">
                    <path d="M6.28196305,52.4573478 L39.0882458,52.4573478 C42.7315902,52.4573478 45.6851044,55.410862 45.6851044,59.0542064 C45.6851044,62.6975508 42.7315902,65.6510651 39.0882458,65.6510651 L6.28196305,65.6510651 C2.63861863,65.6510651 -0.314895587,62.6975508 -0.314895587,59.0542064 C-0.314895587,55.410862 2.63861863,52.4573478 6.28196305,52.4573478 Z" id="Rectangle" transform="translate(22.6851, 59.0542) rotate(90) translate(-22.6851, -59.0542)"></path>
                    <circle id="Oval" cx="22.617801" cy="22.617801" r="22.617801"></circle>
                </g>
                <circle id="Oval" stroke="#19303F" stroke-width="14.6073298" cx="104" cy="117" r="29.6963351"></circle>
            </g>
        </g>
    </g>
</svg>
SVG;
	}


	/**
	 * Find a registered client by its public client_id.
	 *
	 * @since  1.4.0
	 * @param  string $client_id Public client identifier.
	 * @return object|null
	 */
	private static function find_client( string $client_id ): ?object {
		// Deliberately the same resolver the brokered flow uses. A client_id is
		// not always a row: the metadata this server publishes advertises
		// `client_id_metadata_document_supported`, and Claude now offers
		// Anthropic's hosted client metadata as the recommended, auto-detected
		// option. Chosen, it sends the metadata document's URL as the client_id
		// instead of registering one.
		//
		// A plain SELECT missed every such caller and answered "Unknown
		// client_id. Register the client first." — advice that cannot be
		// followed, because that mode never registers. Resolving through
		// OAuthProvider means both flows accept exactly the same identifiers,
		// which is the only honest reading of one shared metadata document.
		return OAuthProvider::resolve_client( $client_id );
	}

	/**
	 * Find a registered client by its internal row id.
	 *
	 * @since  1.4.0
	 * @param  int $id Row id.
	 * @return object|null
	 */
	private static function find_client_by_row_id( int $id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'getmcp_oauth_clients';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * The canonical resource URI for the built-in server.
	 *
	 * @since  1.4.0
	 * @param  Server $server The built-in server.
	 * @return string
	 */
	private static function canonical_resource( Server $server ): string {
		return self::canonicalize( $server->get_endpoint_url() );
	}

	/**
	 * Normalise a resource URI for comparison (RFC 8707).
	 *
	 * @since  1.4.0
	 * @param  string $raw Candidate URI.
	 * @return string
	 */
	private static function canonicalize( string $raw ): string {
		return OAuthProvider::canonicalize_resource( $raw );
	}

	/**
	 * Rebuild the current request URL, for the post-login bounce.
	 *
	 * @since  1.4.0
	 * @return string
	 */
	private static function current_url(): string {
		// Not sanitize_text_field(): it strips every %XX octet, which turns the
		// percent-encoded redirect_uri and resource parameters in this very URL
		// into garbage — `http%3A%2F%2F127.0.0.1` came back as
		// `http127.0.0.1`. The host is pinned to this site rather than trusted
		// from the header, and esc_url_raw() does the escaping that matters.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		return esc_url_raw( home_url( (string) $uri ) );
	}

	/**
	 * Build the URL an authorization response sends the MCP client back to.
	 *
	 * Empty parameters are dropped (a client that sent no `state` gets none
	 * back), `iss` is appended last, and an existing query on the registered
	 * redirect URI is extended rather than replaced.
	 *
	 * @since  1.6.0
	 * @param  string                $redirect_uri Registered redirect URI.
	 * @param  array<string, string> $params       code/state or error/state.
	 * @param  string                $issuer       Issuer to advertise as `iss`; skipped when empty.
	 * @return string
	 */
	public static function authorization_response_url( string $redirect_uri, array $params, string $issuer = '' ): string {
		$params = array_filter( $params, static fn( $v ) => '' !== $v && null !== $v );
		if ( '' !== $issuer ) {
			$params['iss'] = $issuer;
		}

		$separator = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';

		return $redirect_uri . $separator . http_build_query( array_map( 'strval', $params ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Redirect back to the MCP client with a result.
	 *
	 * @since  1.4.0
	 * @param  string                $redirect_uri Registered redirect URI.
	 * @param  array<string, string> $params       Query parameters to append.
	 * @param  string                $issuer       Issuer for the RFC 9207 `iss` parameter.
	 * @return void
	 */
	private static function redirect_back( string $redirect_uri, array $params, string $issuer = '' ): void {
		$target = self::authorization_response_url( $redirect_uri, $params, $issuer );

		// wp_safe_redirect() would refuse a private-use scheme such as
		// cursor:// or vscode://, which is exactly what native MCP clients
		// register. The URI is already proven to be on the client's own
		// registered list, so the allowlist has done its job.
		header( 'Location: ' . $target, true, 302 );
		exit;
	}

	/**
	 * Emit an OAuth error response.
	 *
	 * @since  1.4.0
	 * @param  int    $status      HTTP status.
	 * @param  string $error       OAuth error code.
	 * @param  string $description Human-readable detail.
	 * @return void
	 */
	private static function json_error( int $status, string $error, string $description ): void {
		status_header( $status );
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store' );

		echo wp_json_encode(
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);
	}
}
