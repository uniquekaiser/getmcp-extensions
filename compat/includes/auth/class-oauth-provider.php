<?php
/**
 * OAuth 2.1 Authorization Server for MCP inbound authentication.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Auth;

use GetMCP\Core\Server;
use GetMCP\Utils\PublicUrl;

/**
 * Implements OAuth 2.1 with PKCE for MCP client authentication.
 *
 * Handles:
 * - Authorization endpoint (authorization code grant with PKCE)
 * - Token endpoint (code exchange and refresh)
 * - Token validation
 * - Well-known metadata endpoint
 *
 * @since 1.0.0
 */
class OAuthProvider {

	/**
	 * Register OAuth endpoints as WordPress rewrite rules.
	 *
	 * Called from GetMCP::register_rewrite_rules().
	 *
	 * @since 1.0.0
	 */
	public static function register_routes(): void {
		// The origin-level metadata document used to have a rewrite rule here.
		// It is served as a fallback by GetMCP::serve_root_oauth_fallback() now:
		// a rule at the site root shadowed every other MCP plugin's discovery
		// (see RootOAuthPaths). The tag stays registered so a stored copy of the
		// old rule still parses until the post-update flush removes it; the
		// `request` filter then turns that match into a plain 404.
		add_rewrite_tag( '%getmcp_oauth_action%', '([a-z]+)' );
	}

	/**
	 * Serve the origin-level metadata document for a `getmcp_oauth_action` query.
	 *
	 * Not hooked since 1.6.0: the site root is answered by
	 * GetMCP::serve_root_oauth_fallback() through OAuthProxy, which resolves
	 * the gateway or the sole OAuth server rather than the bare site issuer.
	 * Kept so code that called it directly keeps working.
	 *
	 * @since 1.0.0
	 */
	public static function handle_request(): void {
		$action = get_query_var( 'getmcp_oauth_action' );

		if ( empty( $action ) ) {
			return;
		}

		// Only the global metadata endpoint lives at the origin level now.
		// Authorize / token / register / callback are all per-server and routed
		// through OAuthProxy::handle_request so we have a Server in hand before
		// we touch upstream OAuth config.
		if ( 'metadata' === $action ) {
			self::handle_metadata();
			exit;
		}
	}

	/**
	 * Handle the authorize endpoint for an OAuth-broker server.
	 *
	 * Broker semantics: instead of authenticating the user against WordPress,
	 * we wrap the MCP client's flow context (PKCE, redirect_uri, resource,
	 * scope, state) into a signed envelope and redirect the user to the
	 * upstream Authorization Server's authorize URL. The user logs into THEIR
	 * upstream account (Google / GitHub / …) and grants consent. Upstream
	 * redirects back to our `/mcp/{slug}/oauth/callback` where we exchange
	 * the upstream code, store the upstream tokens, mint our own MCP code,
	 * and bounce the MCP client back to its own redirect_uri.
	 *
	 * @since  1.4.0
	 * @param  object $server Server row (with auth_type='oauth' and broker config).
	 */
	public static function handle_per_server_authorize( $server ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$client_id             = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect_uri          = isset( $_GET['redirect_uri'] ) ? self::sanitize_redirect_uri( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$response_type         = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '';
		$scope                 = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '';
		$state                 = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code_challenge        = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : 'S256';
		$resource              = isset( $_GET['resource'] ) ? sanitize_url( wp_unslash( $_GET['resource'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'code' !== $response_type ) {
			self::send_json_error( 400, 'unsupported_response_type', 'Only response_type=code is supported.' );
			return;
		}
		if ( empty( $code_challenge ) ) {
			self::send_json_error( 400, 'invalid_request', 'PKCE code_challenge is required (OAuth 2.1).' );
			return;
		}
		// S256 only. Accepting `plain` — or any unrecognised string, which used to
		// fall through to the plain branch in verify_pkce() — lets an attacker who
		// can read the authorize request replay the challenge as the verifier.
		// Our metadata has always advertised S256 exclusively.
		if ( 'S256' !== $code_challenge_method ) {
			self::send_json_error( 400, 'invalid_request', 'Only code_challenge_method=S256 is supported.' );
			return;
		}

		$client = self::resolve_client( $client_id );
		if ( ! $client ) {
			self::send_json_error( 400, 'invalid_client', self::describe_unknown_client( $client_id, $server ) );
			return;
		}

		// Validate the MCP client's redirect URI against the client's
		// registered list before sending anyone anywhere — this guards
		// against an open redirect via the post-callback bounce.
		if ( '' === $redirect_uri ) {
			// Split from the scheme error on purpose. The two shared one message,
			// so a client that omitted the parameter entirely was told its scheme
			// was wrong — the error pointed at the wrong field.
			self::send_json_error( 400, 'invalid_request', 'redirect_uri is required.' );
			return;
		}
		if ( ! self::redirect_scheme_allowed( $redirect_uri ) ) {
			self::send_json_error( 400, 'invalid_request', 'redirect_uri scheme is not allowed. Use http, https, or a private-use scheme (RFC 8252).' );
			return;
		}
		$allowed_uris = json_decode( $client->redirect_uris, true );
		$allowed_uris = is_array( $allowed_uris ) ? $allowed_uris : array();
		// No escape hatch for clients that registered an empty list. An empty
		// allowlist means "nowhere is allowed", not "anywhere is allowed" —
		// the latter turns this endpoint into an open redirect, and
		// redirect_to_mcp_client() whitelists the host before bouncing.
		if ( ! in_array( $redirect_uri, $allowed_uris, true ) ) {
			self::send_json_error( 400, 'invalid_request', 'redirect_uri not registered for this client.' );
			return;
		}

		// RFC 8707 binding. The tokens table has no server_id column, so `resource`
		// is the only thing tying an issued token to one server. Always stamp it —
		// a token minted without a resource is honoured nowhere (see
		// validate_and_extract_row()), and clients that omit the parameter would
		// otherwise get a token bound to nothing.
		//
		// Validated before the broker config is read: every check above this line
		// is about the *request*, every check below is about the *server's setup*.
		// A misconfigured server must not turn a client's bad `resource` into a
		// 500 — the client would retry forever against an error that is its own.
		$server_resource = self::canonicalize_resource( PublicUrl::server( $server->slug ) );
		if ( '' !== $resource && self::canonicalize_resource( $resource ) !== $server_resource ) {
			self::send_json_error( 400, 'invalid_target', 'The requested resource does not match this MCP server endpoint.' );
			return;
		}

		// Pull the broker config from the server row. URLs + client_id sit in
		// auth_config (plain JSON for visibility); client_secret lives in the
		// encrypted auth_credentials column.
		$config        = self::parse_broker_config( $server );
		$client_secret = self::read_broker_client_secret( $server );
		if ( empty( $config['authorize_url'] ) || empty( $config['token_url'] ) || empty( $config['client_id'] ) || '' === $client_secret ) {
			// The operator is the only one who can clear this, so it must not look
			// transient. A 5xx invites the client to retry a fault that will never
			// resolve on its own, and `invalid_configuration` is not a registered
			// OAuth error code — strict clients discard it. Past the redirect_uri
			// validation above, RFC 6749 §4.1.2.1 says to deliver the failure to the
			// client rather than render JSON into the browser the user is sitting in.
			self::log_debug(
				sprintf(
					'getMCP: broker authorize refused for server #%d — upstream authorize URL, token URL, client ID or client secret is missing.',
					(int) ( $server->id ?? 0 )
				)
			);
			self::redirect_to_mcp_client(
				$redirect_uri,
				array(
					'error'             => 'server_error',
					'error_description' => 'Upstream OAuth is not fully configured for this server. Set authorize URL, token URL, client ID, and client secret in the Auth tab.',
					'state'             => $state,
				),
				PublicUrl::server( $server->slug )
			);
			return;
		}

		// Wrap the MCP-side flow context. Upstream only ever sees an opaque
		// signed string; on callback we verify the HMAC and recover the
		// fields we need to mint our own auth code.
		$wrapped = self::wrap_broker_state( array(
			's'   => $state,
			'cid' => $client_id,
			'iid' => (int) $client->id,
			'svr' => (int) $server->id,
			'ru'  => $redirect_uri,
			'cc'  => $code_challenge,
			'ccm' => $code_challenge_method,
			'sc'  => $scope,
			'res' => $server_resource,
			'ts'  => time(),
		) );
		if ( '' === $wrapped ) {
			// Same reasoning as the broker-config branch above: operator-only fault,
			// so a registered `server_error` delivered to the client, not a 5xx. The
			// AUTH_KEY specifics stay in the log — the operator reads the log, the
			// client only needs to know the flow cannot proceed.
			self::log_debug(
				sprintf(
					'getMCP: broker authorize refused for server #%d — wp-config.php has no usable AUTH_KEY, so OAuth flow state cannot be signed.',
					(int) ( $server->id ?? 0 )
				)
			);
			self::redirect_to_mcp_client(
				$redirect_uri,
				array(
					'error'             => 'server_error',
					'error_description' => 'This site cannot sign OAuth flow state. Contact the site administrator.',
					'state'             => $state,
				),
				PublicUrl::server( $server->slug )
			);
			return;
		}

		// Tell upstream to come back to this server's per-server callback so
		// the operator only registers one redirect URI per server with the
		// upstream OAuth provider.
		$callback_url = PublicUrl::server( $server->slug ) . '/oauth/callback';

		$forward = array(
			'response_type' => 'code',
			'client_id'     => (string) $config['client_id'],
			'redirect_uri'  => $callback_url,
			'state'         => $wrapped,
		);

		// Default scope from server config when the MCP client didn't ask
		// for anything specific. Upstream-defined; we don't translate.
		if ( ! empty( $config['scope'] ) ) {
			$forward['scope'] = (string) $config['scope'];
		}

		// Some upstreams (Google) need `access_type=offline` + `prompt=consent`
		// to issue a refresh token. Operator can override via auth_config
		// (`extra_authorize_params`) when the upstream needs anything else.
		if ( ! empty( $config['extra_authorize_params'] ) && is_array( $config['extra_authorize_params'] ) ) {
			foreach ( $config['extra_authorize_params'] as $k => $v ) {
				$forward[ (string) $k ] = (string) $v;
			}
		}

		$separator = ( false === strpos( (string) $config['authorize_url'], '?' ) ) ? '?' : '&';
		$location  = $config['authorize_url'] . $separator . http_build_query( $forward );

		// The upstream host is operator-configured, so explicitly allow the
		// off-host redirect.
		$host = wp_parse_url( $location, PHP_URL_HOST );
		if ( $host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ) use ( $host ): array {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}

		wp_safe_redirect( $location, 302 );
	}

	/**
	 * Handle the upstream's redirect back to us after the user grants consent.
	 *
	 * Verifies the wrapped state, exchanges the upstream code for tokens
	 * using the operator-stored client_secret, persists the upstream tokens
	 * onto a new MCP authorization-code row (PKCE-bound to the original
	 * authorize call), and bounces the MCP client back to its redirect_uri
	 * with our own short-lived code.
	 *
	 * @since  1.4.0
	 * @param  object $server Server row.
	 */
	public static function handle_per_server_callback( $server ): void {
		// `error` is a WordPress public query var, and WP::parse_request() runs
		// `unset( $_GET['error'] )` on every request that matches a rewrite rule
		// (wp-includes/class-wp.php). This callback IS a rewrite rule, so the
		// upstream's error code is destroyed before we ever see it. Read the raw
		// query string instead — nothing rewrites that.
		$raw_query = array();
		wp_parse_str( wp_unslash( (string) ( $_SERVER['QUERY_STRING'] ?? '' ) ), $raw_query );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$upstream_code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$wrapped_state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$upstream_err = isset( $raw_query['error'] ) ? sanitize_text_field( (string) $raw_query['error'] ) : '';

		$envelope = self::unwrap_broker_state( $wrapped_state );
		if ( null === $envelope ) {
			self::send_json_error( 400, 'invalid_state', 'OAuth callback state is missing or invalid.' );
			return;
		}
		// State must belong to the server that received the callback.
		if ( (int) ( $envelope['svr'] ?? 0 ) !== (int) $server->id ) {
			self::send_json_error( 400, 'invalid_state', 'Callback state belongs to a different server.' );
			return;
		}
		// 10-minute envelope lifetime — same as our own auth-code expiry.
		if ( ( $envelope['ts'] ?? 0 ) + 600 < time() ) {
			self::send_json_error( 400, 'invalid_state', 'Authorization expired before the upstream responded. Try again.' );
			return;
		}

		// If the upstream surfaced an error, propagate it back to the MCP
		// client untouched so the user-agent can display the human reason.
		if ( '' !== $upstream_err ) {
			self::redirect_to_mcp_client(
				$envelope['ru'],
				array(
					'error'             => $upstream_err,
					'error_description' => isset( $raw_query['error_description'] ) ? sanitize_text_field( (string) $raw_query['error_description'] ) : '',
					'state'             => (string) $envelope['s'],
				),
				PublicUrl::server( $server->slug )
			);
			return;
		}
		if ( '' === $upstream_code ) {
			self::send_json_error( 400, 'invalid_grant', 'Upstream did not return an authorization code.' );
			return;
		}

		$config        = self::parse_broker_config( $server );
		$client_secret = self::read_broker_client_secret( $server );
		if ( empty( $config['token_url'] ) || empty( $config['client_id'] ) || '' === $client_secret ) {
			// The envelope's redirect_uri was validated during authorize and is
			// HMAC-sealed, so bouncing the error home is safe and matches how the
			// upstream-error branch above already behaves. Config was stripped
			// between authorize and callback — operator fault, non-transient.
			self::log_debug(
				sprintf(
					'getMCP: broker callback refused for server #%d — upstream token URL, client ID or client secret is missing.',
					(int) ( $server->id ?? 0 )
				)
			);
			self::redirect_to_mcp_client(
				(string) $envelope['ru'],
				array(
					'error'             => 'server_error',
					'error_description' => 'Upstream OAuth is not fully configured for this server. Set token URL, client ID, and client secret in the Auth tab.',
					'state'             => (string) $envelope['s'],
				),
				PublicUrl::server( $server->slug )
			);
			return;
		}
		$callback_url = PublicUrl::server( $server->slug ) . '/oauth/callback';

		// Exchange the upstream code for tokens. POST form-urlencoded — that's
		// what every commodity OAuth provider expects on the token endpoint.
		$response = wp_remote_post( (string) $config['token_url'], array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			'body'    => http_build_query( array(
				'grant_type'    => 'authorization_code',
				'code'          => $upstream_code,
				'client_id'     => (string) $config['client_id'],
				'client_secret' => $client_secret,
				'redirect_uri'  => $callback_url,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			// Transport errors name the upstream host and port ("cURL error 7:
			// Failed to connect to idp.internal:8443"), which maps the operator's
			// private network for anyone who can trigger the callback.
			self::log_debug(
				sprintf(
					'getMCP: upstream token endpoint unreachable for server #%1$d: %2$s',
					(int) ( $server->id ?? 0 ),
					$response->get_error_message()
				)
			);
			self::send_json_error( 502, 'upstream_unreachable', 'Could not reach the upstream token endpoint.' );
			return;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			// Never echo the upstream body. It is an IDP error document that can
			// carry the operator's client_id, internal hostnames, or a partially
			// reflected client_secret, and this endpoint is reachable by anyone
			// who can start the flow. The same risk applies to the debug log, so
			// only the registered OAuth error members survive into it.
			self::log_debug(
				sprintf(
					'getMCP: upstream token exchange failed for server #%1$d with status %2$d: %3$s',
					(int) ( $server->id ?? 0 ),
					$status,
					self::summarize_token_error( $body )
				)
			);
			self::send_json_error( 502, 'upstream_token_exchange_failed', 'Upstream token exchange returned ' . $status . '.' );
			return;
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['access_token'] ) ) {
			self::send_json_error( 502, 'upstream_invalid_response', 'Upstream token response did not include an access_token.' );
			return;
		}

		try { $payload = \GetMCPExtensions\ProviderTokens::after_exchange( $server, $payload ); }
		catch ( \Throwable $e ) { self::send_json_error( 502, 'upstream_token_extension_failed', 'The provider token could not be validated or extended. Reconnect.' ); return; }
		$upstream_access  = (string) $payload['access_token'];
		$upstream_refresh = isset( $payload['refresh_token'] ) ? (string) $payload['refresh_token'] : '';
		$expires_in       = isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : 3600;
		$upstream_expires = gmdate( 'Y-m-d H:i:s', time() + max( 60, $expires_in ) );

		// Mint our authorization code and persist it alongside the upstream
		// tokens. The upstream tokens stay encrypted at rest; the encryption
		// key is the same one auth_credentials uses (Encryption::encrypt).
		$code     = bin2hex( random_bytes( 32 ) );
		$code_hash = hash( 'sha256', $code );

		global $wpdb;
		$tokens_table = $wpdb->prefix . 'getmcp_oauth_tokens';

		$wpdb->insert(
			$tokens_table,
			array(
				'client_id'               => (int) $envelope['iid'],
				'user_id'                 => 0,
				'authorization_code_hash' => $code_hash,
				'code_challenge'          => (string) $envelope['cc'],
				'code_challenge_method'   => (string) $envelope['ccm'],
				'scopes'                  => (string) $envelope['sc'],
				'resource'                => '' !== (string) $envelope['res'] ? (string) $envelope['res'] : null,
				'access_token_hash'       => '',
				'upstream_access_token'   => \GetMCP\Utils\Encryption::encrypt( $upstream_access ),
				'upstream_refresh_token'  => '' !== $upstream_refresh ? \GetMCP\Utils\Encryption::encrypt( $upstream_refresh ) : null,
				'upstream_expires_at'     => $upstream_expires,
				'expires_at'              => gmdate( 'Y-m-d H:i:s', time() + 600 ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		self::redirect_to_mcp_client(
			(string) $envelope['ru'],
			array(
				'code'  => $code,
				'state' => (string) $envelope['s'],
			),
			PublicUrl::server( $server->slug )
		);
	}

	/**
	 * Bounce back to the MCP client's redirect_uri with the given query.
	 *
	 * Encapsulates the off-host redirect-allowlist dance so the broker
	 * authorize / callback paths stay free of the boilerplate.
	 *
	 * @since 1.4.0
	 * @param string                $redirect_uri The MCP client's redirect_uri.
	 * @param array<string, string> $params       Query params to append.
	 * @param string                $issuer       Issuer to advertise as RFC 9207 `iss`; skipped when empty.
	 */
	private static function redirect_to_mcp_client( string $redirect_uri, array $params, string $issuer = '' ): void {
		// RFC 9207 — our metadata sets authorization_response_iss_parameter_supported,
		// so every authorization response (success AND error) has to carry `iss`.
		// Advertising it without emitting it leaves mix-up-aware clients unable to
		// tell which AS answered, which is the exact attack the parameter defends.
		if ( '' !== $issuer ) {
			$params['iss'] = $issuer;
		}

		$separator = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';
		$location  = $redirect_uri . $separator . http_build_query( array_map( 'strval', $params ) );

		$host = wp_parse_url( $location, PHP_URL_HOST );
		if ( $host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ) use ( $host ): array {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}

		// http/https can ride wp_safe_redirect(), which validates the host
		// against the allowlist we just extended. A private-use scheme cannot:
		// wp_validate_redirect() rejects every scheme outside http/https and
		// silently sends the user to wp-admin instead, so the bounce would
		// vanish for exactly the native clients RFC 8252 §7.1 describes. Those
		// already cleared the strongest check we have — an exact string match
		// against the client's registered redirect_uris — so hand them to
		// wp_redirect(), which still strips %0d/%0a before writing the header.
		$scheme = strtolower( (string) wp_parse_url( $location, PHP_URL_SCHEME ) );
		if ( 'http' === $scheme || 'https' === $scheme ) {
			wp_safe_redirect( $location, 302 );
			return;
		}
		wp_redirect( $location, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact-matched against the client's registered redirect_uris; wp_safe_redirect() would discard the private-use scheme.
	}

	/**
	 * Sanitize a redirect_uri without destroying its scheme.
	 *
	 * `sanitize_url()` is `esc_url_raw()`, which drops any scheme outside
	 * WordPress's allowed-protocol list and hands back an empty string. Native
	 * MCP clients register private-use URI schemes (`cursor://…`, `vscode://…`)
	 * exactly as RFC 8252 §7.1 recommends, so running one through it turned a
	 * legal value into `''` before any comparison happened — the authorize
	 * endpoint then reported a scheme error for a scheme it never saw.
	 *
	 * We strip only what must never reach a `Location:` header: surrounding
	 * space, and any embedded control byte or whitespace (a raw CR/LF is
	 * response splitting). Everything else survives byte-for-byte, because the
	 * exact-string allowlist match is only honest if both sides of the
	 * comparison went through the same transformation.
	 *
	 * @since 1.4.0
	 * @param mixed $raw Raw value from a request parameter or a fetched document.
	 * @return string Sanitized URI, or '' when the input cannot be used as one.
	 */
	private static function sanitize_redirect_uri( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$uri = trim( $raw );

		// The value ends up in a response header and in a signed envelope, so
		// bound it here rather than discovering the limit at the web server.
		if ( '' === $uri || strlen( $uri ) > 2048 ) {
			return '';
		}

		// Control bytes, spaces and DEL are illegal in a URI (RFC 3986) and are
		// the header-injection vector. Refuse rather than repair: a value we had
		// to edit is not the value the client will send back at the token step,
		// and those two have to match exactly.
		if ( preg_match( '/[\x00-\x20\x7F]/', $uri ) ) {
			return '';
		}

		return $uri;
	}

	/**
	 * Is this redirect_uri's scheme acceptable for an MCP client?
	 *
	 * Web clients use http/https. Native clients use a private-use scheme —
	 * `cursor://…`, `vscode://…`, `com.example.app:/cb` — which RFC 8252 §7.1
	 * explicitly recommends for this exact case. Refusing those is what broke
	 * desktop MCP clients against the broker.
	 *
	 * This is not the open-redirect defence. That is the exact-string match
	 * against the client's registered `redirect_uris`, which every caller runs.
	 * This only keeps schemes that execute code or read local files out of a
	 * `Location:` header, since registration is open to any client via DCR.
	 *
	 * @since 1.4.0
	 * @param string $uri Sanitized redirect URI.
	 * @return bool True when the scheme may be redirected to.
	 */
	private static function redirect_scheme_allowed( string $uri ): bool {
		$scheme = wp_parse_url( $uri, PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || '' === $scheme ) {
			return false;
		}

		$scheme = strtolower( $scheme );

		// RFC 3986 §3.1 scheme grammar. Anything else is malformed, not exotic.
		if ( ! preg_match( '/^[a-z][a-z0-9+.\-]*$/', $scheme ) ) {
			return false;
		}

		// Schemes that run script or reach into the local machine. Any client can
		// register any scheme it likes, so this list is the one that matters.
		$denied = array( 'javascript', 'data', 'vbscript', 'file', 'about', 'blob', 'filesystem', 'jar' );

		return ! in_array( $scheme, $denied, true );
	}

	/**
	 * Handle the token endpoint for an OAuth-broker server (per-server route).
	 *
	 * Server-context wrapper around handle_token(). The server IS threaded
	 * through: an auth code minted for server A must not be redeemable at
	 * server B's token endpoint. The client row alone does not settle it —
	 * legacy DCR and CIMD clients carry server_id = 0 — so the route's own
	 * server is the audience we check the code's `resource` against.
	 *
	 * @since 1.4.0
	 * @param object $server Server row.
	 */
	public static function handle_per_server_token( $server ): void {
		self::handle_token( $server );
	}

	/**
	 * Handle the token endpoint (code exchange or refresh).
	 *
	 * @since 1.0.0
	 * @param object|null $server Server row when reached through a per-server route, else null.
	 */
	private static function handle_token( $server = null ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::send_json_error( 405, 'invalid_request', 'Token endpoint requires POST.' );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$grant_type    = isset( $_POST['grant_type'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_type'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$code          = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? self::sanitize_redirect_uri( wp_unslash( $_POST['redirect_uri'] ) ) : '';
		$code_verifier = isset( $_POST['code_verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['code_verifier'] ) ) : '';
		$refresh_token = isset( $_POST['refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['refresh_token'] ) ) : '';
		// RFC 8707 — clients MAY repeat the resource at the token step. We accept
		// it and require it to match the value bound to the auth code (if one
		// was provided). When absent, we fall back to the value already
		// associated with the auth code.
		$resource      = isset( $_POST['resource'] ) ? sanitize_url( wp_unslash( $_POST['resource'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'authorization_code' === $grant_type ) {
			self::handle_code_exchange( $client_id, $code, $code_verifier, $redirect_uri, $resource, $server );
		} elseif ( 'refresh_token' === $grant_type ) {
			self::handle_refresh( $client_id, $refresh_token, $resource, $server );
		} else {
			self::send_json_error( 400, 'unsupported_grant_type', 'Only authorization_code and refresh_token grants are supported.' );
		}
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @since 1.0.0
	 * @param string $client_id     Client ID.
	 * @param string $code          Authorization code.
	 * @param string $code_verifier PKCE code verifier.
	 * @param string $redirect_uri  Redirect URI.
	 * @param string      $resource RFC 8707 resource the client wants the token issued for.
	 * @param object|null $server   Server row when reached through a per-server route, else null.
	 */
	private static function handle_code_exchange( string $client_id, string $code, string $code_verifier, string $redirect_uri, string $resource = '', $server = null ): void {
		global $wpdb;

		$client = self::resolve_client( $client_id );
		if ( ! $client ) {
			self::send_json_error( 401, 'invalid_client', self::describe_unknown_client( $client_id, $server ) );
			return;
		}

		// OAuth 2.1 §4.1.3 — redirect_uri is required on the token request when
		// it was present on the authorization request, and ours always is. The
		// authorize-time value is not persisted (the tokens table has no column
		// for it), so this is membership in the client's registered list rather
		// than an exact match against that one request. Equivalent for
		// single-URI clients; weaker for clients that registered several.
		$allowed_uris = json_decode( $client->redirect_uris, true );
		$allowed_uris = is_array( $allowed_uris ) ? $allowed_uris : array();
		if ( '' === $redirect_uri || ! in_array( $redirect_uri, $allowed_uris, true ) ) {
			self::send_json_error( 400, 'invalid_grant', 'redirect_uri is missing or does not match a registered redirect URI.' );
			return;
		}

		$tokens_table = $wpdb->prefix . 'getmcp_oauth_tokens';
		$code_hash    = hash( 'sha256', $code );

		// Look up the authorization code.
		$token_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tokens_table} WHERE client_id = %d AND authorization_code_hash = %s AND expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$client->id,
				$code_hash
			)
		);

		if ( ! $token_row ) {
			self::send_json_error( 400, 'invalid_grant', 'Authorization code is invalid or expired.' );
			return;
		}

		// A code minted for server A must not be redeemable at server B's token
		// endpoint. Authorize always stamps `resource` now, so on the per-server
		// route the code's resource has to name this route's server.
		if ( null !== $server && isset( $server->slug ) ) {
			$route_resource = self::canonicalize_resource( PublicUrl::server( $server->slug ) );
			$code_resource  = isset( $token_row->resource ) ? (string) $token_row->resource : '';
			if ( $code_resource !== $route_resource ) {
				self::send_json_error( 400, 'invalid_grant', 'This authorization code was not issued for this MCP server.' );
				return;
			}
		}

		// Verify PKCE code challenge. A failed verifier burns the code — RFC 6749
		// §4.1.2 says an authorization code MUST be invalidated once it is used,
		// and leaving it live lets an attacker who stole the code keep guessing
		// verifiers until the 10-minute window closes.
		if ( ! self::verify_pkce( $code_verifier, $token_row->code_challenge, $token_row->code_challenge_method ) ) {
			$wpdb->update(
				$tokens_table,
				array( 'authorization_code_hash' => null ),
				array( 'id' => $token_row->id ),
				array( '%s' ),
				array( '%d' )
			);
			self::send_json_error( 400, 'invalid_grant', 'PKCE code_verifier verification failed.' );
			return;
		}

		// RFC 8707 — when the auth code was issued with a resource, the token
		// request MUST present the same resource. The client MAY also send a
		// resource we previously had none for; in that case we accept it.
		$bound_resource     = isset( $token_row->resource ) ? (string) $token_row->resource : '';
		$requested_resource = '' !== $resource ? self::canonicalize_resource( $resource ) : '';
		if ( '' !== $bound_resource && '' !== $requested_resource && $bound_resource !== $requested_resource ) {
			self::send_json_error( 400, 'invalid_target', 'The resource on the token request does not match the resource bound to the authorization code.' );
			return;
		}
		$final_resource = $bound_resource !== '' ? $bound_resource : $requested_resource;

		// Generate access token and refresh token.
		$access_token  = bin2hex( random_bytes( 32 ) );
		$refresh       = bin2hex( random_bytes( 32 ) );
		$expires_in    = 3600; // 1 hour.

		$wpdb->update(
			$tokens_table,
			array(
				'access_token_hash'      => hash( 'sha256', $access_token ),
				'refresh_token_hash'     => hash( 'sha256', $refresh ),
				'authorization_code_hash' => null, // Invalidate the code.
				'resource'               => '' !== $final_resource ? $final_resource : null,
				'expires_at'             => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
				'refresh_expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 86400 * 30 ), // 30 days.
			),
			array( 'id' => $token_row->id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store' );
		status_header( 200 );
		echo wp_json_encode(
			array(
				'access_token'  => $access_token,
				'token_type'    => 'Bearer',
				'expires_in'    => $expires_in,
				'refresh_token' => $refresh,
				'scope'         => $token_row->scopes ?? '',
			)
		);
	}

	/**
	 * Exchange a refresh token for a new access token.
	 *
	 * @since 1.0.0
	 * @param string $client_id     Client ID.
	 * @param string $refresh_token Refresh token.
	 * @param string $resource      RFC 8707 resource — must match the token's bound resource (when one exists).
	 */
	private static function handle_refresh( string $client_id, string $refresh_token, string $resource = '', $server = null ): void {
		global $wpdb;

		$client = self::resolve_client( $client_id );
		if ( ! $client ) {
			self::send_json_error( 401, 'invalid_client', self::describe_unknown_client( $client_id, $server ) );
			return;
		}

		$tokens_table  = $wpdb->prefix . 'getmcp_oauth_tokens';
		$refresh_hash  = hash( 'sha256', $refresh_token );

		$token_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tokens_table} WHERE client_id = %d AND refresh_token_hash = %s AND refresh_expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$client->id,
				$refresh_hash
			)
		);

		if ( ! $token_row ) {
			self::send_json_error( 400, 'invalid_grant', 'Refresh token is invalid or expired.' );
			return;
		}

		// Reject scope-narrowing across resources: the refresh MUST keep the
		// originally bound resource, so a stolen refresh token can't be replayed
		// against a different MCP server.
		$bound_resource     = isset( $token_row->resource ) ? (string) $token_row->resource : '';
		$requested_resource = '' !== $resource ? self::canonicalize_resource( $resource ) : '';
		if ( '' !== $bound_resource && '' !== $requested_resource && $bound_resource !== $requested_resource ) {
			self::send_json_error( 400, 'invalid_target', 'Refresh resource does not match the token\'s bound resource.' );
			return;
		}

		// Same audience rule as the code exchange: a refresh token bound to
		// server A cannot be rotated at server B's token endpoint.
		if ( null !== $server && isset( $server->slug ) ) {
			$route_resource = self::canonicalize_resource( PublicUrl::server( $server->slug ) );
			if ( $bound_resource !== $route_resource ) {
				self::send_json_error( 400, 'invalid_grant', 'This refresh token was not issued for this MCP server.' );
				return;
			}
		}

		// Rotate tokens.
		$new_access  = bin2hex( random_bytes( 32 ) );
		$new_refresh = bin2hex( random_bytes( 32 ) );
		$expires_in  = 3600;

		$wpdb->update(
			$tokens_table,
			array(
				'access_token_hash'  => hash( 'sha256', $new_access ),
				'refresh_token_hash' => hash( 'sha256', $new_refresh ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 * 30 ),
			),
			array( 'id' => $token_row->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store' );
		status_header( 200 );
		echo wp_json_encode(
			array(
				'access_token'  => $new_access,
				'token_type'    => 'Bearer',
				'expires_in'    => $expires_in,
				'refresh_token' => $new_refresh,
				'scope'         => $token_row->scopes ?? '',
			)
		);
	}

	/**
	 * Handle dynamic client registration for a specific server (RFC 7591).
	 *
	 * Per-server DCR is what makes the broker work end-to-end — when ChatGPT
	 * registers at `/mcp/{slug}/oauth/register` we bind the new client_id to
	 * that server's row, so the subsequent authorize call knows which
	 * upstream OAuth provider's credentials to use.
	 *
	 * @since 1.4.0
	 * @param object $server Server row.
	 */
	public static function handle_per_server_register( $server ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::send_json_error( 405, 'invalid_request', 'Registration endpoint requires POST.' );
			return;
		}

		/**
		 * How many client registrations one IP may make per minute against one
		 * server. Open registration means anyone can write rows to
		 * getmcp_oauth_clients without ever presenting a credential; unmetered
		 * that is an invitation to grow the table without bound. Real clients
		 * register once and reuse the client_id, so a handful per minute is
		 * generous.
		 *
		 * @since 1.0.0
		 * @param int    $limit  Registrations per minute per IP. Default 5.
		 * @param object $server Server row the registration targets.
		 */
		$register_limit = (int) apply_filters( 'getmcp_dcr_rate_limit', 5, $server );

		if ( ! RateLimiter::allow_identifier( 'dcr_' . (int) $server->id . '_' . RateLimiter::get_client_ip(), $register_limit ) ) {
			header( 'Retry-After: ' . RateLimiter::retry_after() );
			self::send_json_error( 429, 'temporarily_unavailable', 'Too many registration attempts. Try again later.' );
			return;
		}

		// Registration is unauthenticated by design (RFC 7591 open registration),
		// so the body is attacker-controlled in both content and size. Read a
		// bounded prefix rather than whatever the client decides to send.
		$max_body = 16384;
		$raw      = (string) file_get_contents( 'php://input', false, null, 0, $max_body + 1 );

		if ( strlen( $raw ) > $max_body ) {
			self::send_json_error( 413, 'invalid_request', 'Registration request body is too large.' );
			return;
		}

		$body = json_decode( $raw, true );

		if ( empty( $body ) || ! is_array( $body ) ) {
			self::send_json_error( 400, 'invalid_request', 'Invalid JSON body.' );
			return;
		}

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'MCP Client';

		// sanitize_text_field() strips tags, so a name that was nothing but
		// markup — `<script>alert(1)</script>` — arrives here as an empty
		// string. Storing that produced a nameless client the operator cannot
		// identify in the admin UI, which is exactly what someone probing the
		// endpoint wants. Fall back to the default rather than persist blank.
		if ( '' === trim( $client_name ) ) {
			$client_name = 'MCP Client';
		}

		if ( mb_strlen( $client_name ) > 255 ) {
			$client_name = mb_substr( $client_name, 0, 255 );
		}

		$raw_redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] )
			? array_values( $body['redirect_uris'] )
			: array();

		// A client with no registered redirect_uri is a client the authorize
		// endpoint can never satisfy — the allowlist there is strict, so an empty
		// list means "nowhere allowed". Reject it here instead of writing a row
		// that only exists to fail later.
		if ( empty( $raw_redirect_uris ) ) {
			self::send_json_error( 400, 'invalid_redirect_uri', 'At least one redirect_uri is required.' );
			return;
		}

		if ( count( $raw_redirect_uris ) > 20 ) {
			self::send_json_error( 400, 'invalid_redirect_uri', 'Too many redirect_uris.' );
			return;
		}

		// Sanitize and validate in one pass, and never drop a bad entry. The old
		// code mapped sanitize_url() through array_filter(), so a private-use URI
		// was blanked and then silently deleted: a native client whose URIs were
		// all rejected got told it had sent none, and a mixed list was quietly
		// truncated into a registration guaranteed to fail at authorize.
		$redirect_uris = array();
		foreach ( $raw_redirect_uris as $raw_uri ) {
			$uri = self::sanitize_redirect_uri( $raw_uri );
			if ( '' === $uri ) {
				self::send_json_error( 400, 'invalid_redirect_uri', 'Each redirect_uri must be a string under 2048 characters with no spaces or control characters.' );
				return;
			}
			if ( ! self::redirect_scheme_allowed( $uri ) ) {
				self::send_json_error( 400, 'invalid_redirect_uri', 'Every redirect_uri must use http, https, or a private-use scheme (RFC 8252).' );
				return;
			}
			$redirect_uris[] = $uri;
		}

		$grant_types = isset( $body['grant_types'] ) && is_array( $body['grant_types'] )
			? array_map( 'sanitize_text_field', $body['grant_types'] )
			: array( 'authorization_code' );
		$scope = isset( $body['scope'] ) ? sanitize_text_field( $body['scope'] ) : '';

		$new_client_id = 'getmcp_' . bin2hex( random_bytes( 16 ) );

		global $wpdb;
		$clients_table = $wpdb->prefix . 'getmcp_oauth_clients';

		// No client_secret. This is registered as a public client
		// (token_endpoint_auth_method = none) and the token endpoint
		// authenticates it with PKCE, never with a secret. Minting one anyway
		// handed every caller a long-lived credential that looks authoritative,
		// is echoed once in cleartext, and is checked by nothing.
		$wpdb->insert(
			$clients_table,
			array(
				'server_id'                  => (int) $server->id,
				'client_id'                  => $new_client_id,
				'client_secret_hash'         => null,
				'client_name'                => $client_name,
				'redirect_uris'              => wp_json_encode( $redirect_uris ),
				'scopes'                     => $scope,
				'grant_types'                => wp_json_encode( $grant_types ),
				'token_endpoint_auth_method' => 'none', // Public client (MCP uses PKCE).
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		header( 'Content-Type: application/json' );
		status_header( 201 );
		echo wp_json_encode(
			array(
				'client_id'                  => $new_client_id,
				'client_name'                => $client_name,
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => $grant_types,
				'token_endpoint_auth_method' => 'none',
			)
		);
	}

	/**
	 * Serve the OAuth 2.0 Authorization Server Metadata (RFC 8414).
	 *
	 * @since 1.0.0
	 */
	private static function handle_metadata(): void {
		$base = PublicUrl::base();

		header( 'Content-Type: application/json' );
		status_header( 200 );
		echo wp_json_encode( self::metadata_for_issuer( $base ) );
	}

	/**
	 * Build the OAuth 2.0 Authorization Server metadata document for a given issuer.
	 *
	 * Used both by the global discovery handler and by the per-server discovery
	 * dispatcher (so a `auth_type='oauth'` server publishes metadata whose issuer
	 * matches its own canonical URI — required for `aud` checks to line up).
	 *
	 * @since  1.3.0
	 * @param  string $issuer Issuer URL (no trailing slash).
	 * @return array<string,mixed>
	 */
	public static function metadata_for_issuer( string $issuer ): array {
		// Per-server endpoints — broker mode needs server context at every step
		// (authorize redirects to the upstream IDP using the server's
		// configured client_id; the callback persists the upstream tokens
		// against the server; DCR binds the issued client_id to the server).
		// All four endpoints live under the issuer URL so the wiring is
		// implicit in the path the MCP client follows.
		$base = rtrim( $issuer, '/' );
		return array(
			'issuer'                                => $issuer,
			'authorization_endpoint'                => $base . '/oauth/authorize',
			'token_endpoint'                        => $base . '/oauth/token',
			'registration_endpoint'                 => $base . '/oauth/register',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			// `none` only. The token endpoint authenticates the client with PKCE
			// and never reads a client_secret, so advertising client_secret_post
			// invited clients to send a secret we silently ignore — and made the
			// unauthenticated DCR endpoint look like it issued a real credential.
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			// Advertise audience binding so MCP-spec-aware clients know they can
			// (and per the spec MUST) send the `resource` parameter.
			'authorization_response_iss_parameter_supported' => true,
			// Client ID Metadata Documents (MCP spec §Client Identification) —
			// clients can pass an HTTPS URL as their `client_id`; we fetch the
			// document at that URL and use it in lieu of registration.
			'client_id_metadata_document_supported' => true,
			'scopes_supported'                      => array( 'mcp:read', 'mcp:write', 'mcp:admin' ),
			'service_documentation'                 => 'https://docs.getmcp.com/',
		);
	}

	/**
	 * Validate an incoming Bearer access token and return its granted scopes.
	 *
	 * Same checks as `validate_access_token()` (existence, expiry, audience
	 * binding, server-id match) but returns the parsed scope list on success
	 * so the caller can populate McpAuth's scope state for step-up auth. A
	 * return of `null` means the token is invalid.
	 *
	 * @since  1.4.0
	 * @param  string $access_token The raw Bearer token.
	 * @param  Server $server       The MCP server being accessed.
	 * @return string[]|null        Scopes (empty array means unrestricted) or null if invalid.
	 */
	public static function validate_and_extract_scopes( string $access_token, Server $server ): ?array {
		$row = self::validate_and_extract_row( $access_token, $server );
		if ( ! $row ) {
			return null;
		}

		// Parse the space-delimited `scope` string the way the OAuth 2.0 RFC
		// formats it. `*` is treated as an unrestricted wildcard for parity
		// with API-key scopes; an empty value means "no scopes granted",
		// which we surface as an empty array so the caller can decide whether
		// to enforce or treat as unrestricted.
		$raw   = isset( $row->scopes ) ? trim( (string) $row->scopes ) : '';
		if ( '' === $raw || '*' === $raw ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/\s+/', $raw ) ?: array() ), 'strlen' ) );
	}

	/**
	 * Validate an incoming Bearer access token for MCP requests.
	 *
	 * @since  1.0.0
	 * @param  string $access_token The raw Bearer token.
	 * @param  Server $server       The MCP server being accessed.
	 * @return bool True if the token is valid for this server.
	 */
	public static function validate_access_token( string $access_token, Server $server ): bool {
		// One source of truth: delegate to `validate_and_extract_scopes()` so
		// any future tightening of the audience / server / expiry checks lands
		// in a single place. `null` from the inner method means invalid.
		return null !== self::validate_and_extract_scopes( $access_token, $server );
	}

	/**
	 * Reduce a `resource` parameter to the canonical form RFC 8707 / RFC 9728 use.
	 *
	 * Lowercases scheme + host, drops fragments, removes a single trailing slash
	 * unless the path is just `/`. Two URIs that differ only in case or trailing
	 * slash collapse to the same canonical string so audience comparisons aren't
	 * fooled by client-side normalisation differences.
	 *
	 * @since  1.3.0
	 * @param  string $raw Raw resource URI from the wire.
	 * @return string      Canonical form, or empty string on parse failure.
	 */
	public static function canonicalize_resource( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		// RFC 8707 says the canonical form drops fragments. Keep query for now —
		// MCP server URIs typically don't carry queries; if they do, a discovery
		// step or a future spec revision will clarify how to handle them.
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		// Trim a single trailing slash unless the path is just `/`.
		if ( strlen( $path ) > 1 && '/' === substr( $path, -1 ) ) {
			$path = rtrim( $path, '/' );
		}
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Verify a PKCE code verifier against a code challenge.
	 *
	 * @since  1.0.0
	 * @param  string $verifier  The code_verifier from the token request.
	 * @param  string $challenge The code_challenge stored during authorization.
	 * @param  string $method    The code_challenge_method — S256 is the only one accepted.
	 * @return bool True if verification passes.
	 */
	private static function verify_pkce( string $verifier, string $challenge, string $method ): bool {
		if ( empty( $verifier ) || empty( $challenge ) ) {
			return false;
		}

		// S256 only. `plain` (and, previously, any unrecognised method string,
		// which fell through to the same branch) reduces PKCE to replaying the
		// challenge as the verifier. Our metadata has only ever advertised S256.
		if ( 'S256' !== $method ) {
			return false;
		}

		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return hash_equals( $challenge, $computed );
	}

	/**
	 * Look up an OAuth client by client_id string.
	 *
	 * @since  1.0.0
	 * @param  string $client_id The client_id string.
	 * @return object|null Client row or null.
	 */
	private static function get_client( string $client_id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'getmcp_oauth_clients';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE client_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$client_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Explain *why* a client_id failed to resolve, in terms the operator can act on.
	 *
	 * A bare "unknown client_id" is a dead end: four very different mistakes all
	 * land here, and only one of them is "the client genuinely isn't registered".
	 * The most common by far is layer confusion — the operator pastes the
	 * client_id from the *upstream* provider's developer console (Calendly,
	 * Google, GitHub) into the MCP client's OAuth panel. That credential
	 * authenticates getMCP *to the upstream IDP* and belongs in the server's
	 * settings; it is meaningless as an inbound client_id and always lands here.
	 *
	 * Reflecting the submitted value is safe: send_json_error() sets a JSON
	 * content type and runs the payload through wp_json_encode(), so there is no
	 * HTML context to escape into. It is also necessary — the operator needs to
	 * see which of the several IDs in front of them actually got sent.
	 *
	 * @since  1.22.0
	 * @param  string      $client_id The client_id that failed to resolve.
	 * @param  object|null $server    Server row, used to build the registration URL.
	 *                                Null on the global routes, where the origin-level
	 *                                fallback endpoint is the right address to name.
	 * @return string                 Human-readable `error_description`.
	 */
	private static function describe_unknown_client( string $client_id, $server = null ): string {
		$register_url = ( is_object( $server ) && ! empty( $server->slug ) )
			? PublicUrl::server( (string) $server->slug ) . '/oauth/register'
			: PublicUrl::to( 'register' );

		if ( '' === $client_id ) {
			return sprintf(
				/* translators: %s: dynamic client registration endpoint URL. */
				__( 'No client_id was sent. Register the client at %s (dynamic client registration) and retry with the client_id it returns.', 'getmcp' ),
				$register_url
			);
		}

		if ( self::looks_like_cimd_url( $client_id ) ) {
			return sprintf(
				/* translators: %s: the client_id URL that was submitted. */
				__( 'The Client ID Metadata Document at %s could not be retrieved or was not valid. It must be reachable over HTTPS from this server, return JSON with Content-Type application/json, and list a redirect_uri matching the one in this request.', 'getmcp' ),
				$client_id
			);
		}

		if ( str_starts_with( $client_id, 'getmcp_' ) ) {
			return sprintf(
				/* translators: 1: the client_id that was submitted, 2: dynamic client registration endpoint URL. */
				__( 'Client "%1$s" looks like a getMCP-issued client_id but is no longer registered on this server — it was most likely deleted, or issued by a different site. Clear the stored OAuth state in your MCP client so it registers again at %2$s.', 'getmcp' ),
				$client_id,
				$register_url
			);
		}

		return sprintf(
			/* translators: 1: the client_id that was submitted, 2: dynamic client registration endpoint URL. */
			__( 'Client "%1$s" was never issued by this server. getMCP client IDs start with "getmcp_" and are created automatically. If this value came from an upstream provider\'s developer console (Calendly, Google, GitHub), it is the credential getMCP uses to call *them* — it belongs in this server\'s OAuth settings in the getMCP admin, not in your MCP client. Leave the MCP client\'s OAuth Client ID and Client Secret blank so it registers itself at %2$s.', 'getmcp' ),
			$client_id,
			$register_url
		);
	}

	/**
	 * Look up a client by ID, transparently handling Client ID Metadata
	 * Documents (CIMD).
	 *
	 * For URL-shaped client_ids the document is fetched, validated, and
	 * upserted into `getmcp_oauth_clients` so the existing token-storage path
	 * (which uses an integer FK) keeps working unchanged.
	 *
	 * @since  1.4.0
	 * @param  string $client_id Either a registered client_id or an https:// URL.
	 * @return object|null       Client row, or null when the ID can't be resolved.
	 */
	public static function resolve_client( string $client_id ): ?object {
		if ( '' === $client_id ) {
			return null;
		}

		// DB-first: a pre-provisioned row (or a CIMD-upserted row from a
		// prior request) is the source of truth — the warm path is a single
		// SELECT. Falling through to CIMD means we never accidentally ignore
		// an admin-pre-provisioned URL-shaped client_id in favor of fetching
		// the document.
		$row = self::get_client( $client_id );
		if ( $row ) {
			return $row;
		}

		// Cold path: URL-shaped IDs fall through to CIMD. Non-URL strings
		// can't be CIMD references; nothing left to try.
		if ( ! self::looks_like_cimd_url( $client_id ) ) {
			return null;
		}

		$metadata = self::fetch_cimd( $client_id );
		if ( null === $metadata ) {
			return null;
		}

		return self::upsert_cimd_client( $client_id, $metadata );
	}

	/**
	 * Decide whether a client_id is a CIMD URL.
	 *
	 * Per the MCP spec / CIMD draft, the URL must be `https://` (with a single
	 * narrow exception for `http://localhost` / `http://127.0.0.1` to support
	 * local-development MCP clients). Anything else is treated as a registered
	 * client_id string and routed to the classic lookup.
	 *
	 * @since  1.4.0
	 * @param  string $client_id Candidate.
	 * @return bool
	 */
	private static function looks_like_cimd_url( string $client_id ): bool {
		// Cap length to fit the `client_id VARCHAR(255)` column. Anything
		// longer would silently truncate on insert and collide on the unique
		// index with the prefix-sharing entry. 255 is a hard cap; the spec
		// suggests practical limits well below this.
		if ( strlen( $client_id ) > 255 ) {
			return false;
		}

		$parts = wp_parse_url( $client_id );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		// Reject userinfo outright — `https://attacker.com@internal/` is a
		// classic confused-deputy SSRF vector. CIMD documents have no
		// legitimate use for credentials in the URL.
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		// Only allow http://localhost forms when the operator opts in. Local
		// MCP-client development is the legitimate use; the off-by-default
		// flag stops a remote attacker from forcing internal HTTP fetches.
		if ( 'http' === $scheme
			&& in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true )
			&& (bool) apply_filters( 'getmcp_cimd_allow_loopback_http', false ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Reject hostnames that resolve to private / loopback / link-local IPs.
	 *
	 * The CIMD fetcher takes attacker-controlled URLs from an unauthenticated
	 * endpoint, which is a textbook SSRF gadget. We resolve the host on each
	 * fetch (and on every redirect hop) and refuse to talk to anything in:
	 *  - 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16  (RFC 1918)
	 *  - 127.0.0.0/8, ::1/128                        (loopback)
	 *  - 169.254.0.0/16, fe80::/10                   (link-local; AWS/GCP IMDS)
	 *  - fc00::/7                                    (IPv6 ULA)
	 *  - 0.0.0.0/8                                   (this-network)
	 *  - 100.64.0.0/10                               (CGNAT / cloud overlay)
	 *
	 * Loopback is allowed only when `getmcp_cimd_allow_loopback_http` is on,
	 * matching the URL-shape gate.
	 *
	 * @since  1.4.0
	 * @param  string $host Hostname or numeric IP literal.
	 * @return bool         True when the host is safe to fetch.
	 */
	private static function host_is_public( string $host ): bool {
		$host = strtolower( trim( $host, "[]\t\r\n " ) );
		if ( '' === $host ) {
			return false;
		}

		// Resolve to one or more IPs. Both A and AAAA — an attacker that
		// publishes only an AAAA pointing at fe80:: would slip past an
		// IPv4-only check.
		$ips = array();
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$v4 = @gethostbynamel( $host );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $v4 ) ) {
				$ips = array_merge( $ips, $v4 );
			}
			$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $v6 ) ) {
				foreach ( $v6 as $rec ) {
					if ( ! empty( $rec['ipv6'] ) ) {
						$ips[] = $rec['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return false;
		}

		$loopback_allowed = (bool) apply_filters( 'getmcp_cimd_allow_loopback_http', false );

		foreach ( $ips as $ip ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $bin ) {
				return false;
			}
			// Use FILTER_VALIDATE_IP with the no-private/no-res flag — it
			// covers the bulk of the dangerous ranges in one call.
			$is_public = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( false === $is_public ) {
				// Fail closed unless this is a loopback the operator opted in.
				if ( $loopback_allowed && self::is_loopback_ip( $ip ) ) {
					continue;
				}
				return false;
			}
		}
		return true;
	}

	/**
	 * Return true when the IP is in the loopback range.
	 *
	 * @since  1.4.0
	 * @param  string $ip Numeric IP literal (v4 or v6).
	 * @return bool
	 */
	private static function is_loopback_ip( string $ip ): bool {
		if ( '::1' === $ip ) {
			return true;
		}
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 0 === strpos( $ip, '127.' );
		}
		return false;
	}

	/**
	 * Fetch and validate a Client ID Metadata Document.
	 *
	 * Returns the parsed document on success, or null when the document is
	 * unreachable, malformed, or doesn't satisfy the CIMD shape requirements
	 * (must echo back its own `client_id`, must declare at least one
	 * `redirect_uris` entry).
	 *
	 * Cached briefly so the authorize/token round-trip pair doesn't fetch
	 * twice.
	 *
	 * @since  1.4.0
	 * @param  string $url HTTPS URL of the metadata document.
	 * @return array<string,mixed>|null
	 */
	private static function fetch_cimd( string $url ): ?array {
		$cache_key   = 'getmcp_cimd_' . md5( $url );
		$failure_key = $cache_key . '_failed';

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// Negative cache: don't hammer a flaky CIMD host on every authorize.
		if ( false !== get_transient( $failure_key ) ) {
			return null;
		}

		// Resolve the host and refuse to talk to private / link-local /
		// loopback IPs — see host_is_public() for the threat model. This
		// must run before *every* HTTP hop, so we disable wp_remote_get's
		// follow-redirects and resolve ourselves.
		$response = self::http_get_with_ssrf_guard( $url, 3 );
		if ( null === $response ) {
			set_transient( $failure_key, 1, 60 );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			set_transient( $failure_key, 1, 60 );
			return null;
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			set_transient( $failure_key, 1, 60 );
			return null;
		}

		// Document MUST echo back its own URL — protects against a stale
		// document being served from a cache, and against the operator
		// accidentally configuring the wrong URL.
		$declared_id = isset( $decoded['client_id'] ) ? (string) $decoded['client_id'] : '';
		if ( $declared_id !== $url ) {
			set_transient( $failure_key, 1, 60 );
			return null;
		}

		// At least one redirect_uri is required — a CIMD client without one
		// can't complete the authorization-code grant.
		if ( empty( $decoded['redirect_uris'] ) || ! is_array( $decoded['redirect_uris'] ) ) {
			set_transient( $failure_key, 1, 60 );
			return null;
		}

		// Validate every declared redirect_uri. Mirrors the DCR check at
		// handle_register so a CIMD doc carrying a `javascript:`/`data:` URI,
		// or one with whitespace smuggled into it, is rejected at the document
		// level rather than stored as junk handle_authorize would later block.
		// Private-use schemes are legal here for the same reason they are there.
		foreach ( $decoded['redirect_uris'] as $candidate ) {
			$uri = self::sanitize_redirect_uri( $candidate );
			if ( '' === $uri || ! self::redirect_scheme_allowed( $uri ) ) {
				set_transient( $failure_key, 1, 60 );
				return null;
			}
		}

		// Cache for 5 minutes — short enough that a published change to
		// redirect_uris reflects within a CI cycle, long enough to amortise
		// the fetch across the authorize + token round-trip.
		set_transient( $cache_key, $decoded, 300 );
		return $decoded;
	}

	/**
	 * Fetch a URL with SSRF protection and manual redirect handling.
	 *
	 * `wp_remote_get` follows redirects opaquely, so a host that initially
	 * resolves public can `Location:`-redirect to an internal target before
	 * we get the chance to inspect the destination. We disable its redirect
	 * follower and re-run `host_is_public()` on each hop ourselves.
	 *
	 * @since  1.4.0
	 * @param  string $url       Initial URL.
	 * @param  int    $max_hops  Maximum redirect hops to follow.
	 * @return array<string,mixed>|null  WP HTTP response array, or null on guard rejection / wp_error.
	 */
	private static function http_get_with_ssrf_guard( string $url, int $max_hops ): ?array {
		$hop = 0;
		while ( $hop <= $max_hops ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' === $host || ! self::host_is_public( $host ) ) {
				return null;
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return null;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 300 || $code >= 400 ) {
				// Not a redirect — return the final response.
				return is_array( $response ) ? $response : null;
			}

			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( '' === $location ) {
				return null;
			}
			// Resolve the Location relative to the previous URL when needed.
			if ( false === strpos( $location, '://' ) ) {
				$base = wp_parse_url( $url );
				if ( ! is_array( $base ) ) {
					return null;
				}
				$origin   = ( $base['scheme'] ?? 'https' ) . '://' . ( $base['host'] ?? '' );
				$location = ( '/' === substr( $location, 0, 1 ) ) ? $origin . $location : $origin . '/' . $location;
			}
			$url = $location;
			++$hop;
		}
		return null;
	}

	/**
	 * Upsert a synthesised oauth_clients row for a CIMD client_id.
	 *
	 * The downstream code path expects a row with an integer `id` — that's
	 * the FK on every issued token. Rather than change every query, we keep
	 * the URL as the `client_id` column value and lazily ensure the row
	 * exists, refreshing `redirect_uris` / `scopes` on each authorize so a
	 * change in the document propagates immediately.
	 *
	 * @since  1.4.0
	 * @param  string               $url      CIMD URL (the canonical client_id).
	 * @param  array<string, mixed> $metadata Parsed CIMD document.
	 * @return object|null                    Client row or null on insert failure.
	 */
	private static function upsert_cimd_client( string $url, array $metadata ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'getmcp_oauth_clients';

		$client_name = isset( $metadata['client_name'] ) ? sanitize_text_field( (string) $metadata['client_name'] ) : 'CIMD Client';

		// The document is fetched from a URL the client chose, so `redirect_uris`
		// can be any JSON type. `(array) "https://evil"` would have produced a
		// one-element list from a bare string; require a real array instead.
		$raw_uris      = isset( $metadata['redirect_uris'] ) && is_array( $metadata['redirect_uris'] )
			? $metadata['redirect_uris']
			: array();
		$redirect_uris = array_values(
			array_filter(
				array_map(
					static function ( $uri ): string {
						// Same sanitizer as DCR, so a private-use scheme survives
						// storage. resolve_client_metadata_document() has already
						// refused any document holding a URI that blanks here, so
						// the array_filter below only covers a caller that skipped
						// that step.
						return self::sanitize_redirect_uri( $uri );
					},
					$raw_uris
				),
				'strlen'
			)
		);
		$grant_types   = isset( $metadata['grant_types'] ) && is_array( $metadata['grant_types'] )
			? array_map( 'sanitize_text_field', $metadata['grant_types'] )
			: array( 'authorization_code', 'refresh_token' );
		$scope         = isset( $metadata['scope'] ) ? sanitize_text_field( (string) $metadata['scope'] ) : '';

		$existing = self::get_client( $url );
		if ( $existing ) {
			// Refresh just the document-derived fields so a published change
			// to redirect_uris / scopes / grants takes effect within one cache TTL.
			$wpdb->update(
				$table,
				array(
					'client_name'   => $client_name,
					'redirect_uris' => wp_json_encode( $redirect_uris ),
					'scopes'        => $scope,
					'grant_types'   => wp_json_encode( $grant_types ),
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return self::get_client( $url );
		}

		// CIMD clients are public (PKCE-only, no secret) and global to the
		// install — same shape as the DCR-issued rows produced by handle_register.
		$wpdb->insert(
			$table,
			array(
				'server_id'                  => 0,
				'client_id'                  => $url,
				'client_secret_hash'         => null,
				'client_name'                => $client_name,
				'redirect_uris'              => wp_json_encode( $redirect_uris ),
				'scopes'                     => $scope,
				'grant_types'                => wp_json_encode( $grant_types ),
				'token_endpoint_auth_method' => 'none',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return self::get_client( $url );
	}

	/**
	 * Send a JSON error response.
	 *
	 * Does not terminate. Every call site returns immediately afterwards, and
	 * the two entry points that reach these handlers — GetMCP::handle_mcp_request()
	 * for the per-server routes and GetMCP::serve_root_oauth_fallback() for the
	 * site root — exit once the handler returns. Keeping the exit here would swallow
	 * shutdown hooks and breaks under test.
	 *
	 * @since 1.0.0
	 * @param int    $status      HTTP status code.
	 * @param string $error       OAuth error code.
	 * @param string $description Human-readable description.
	 */
	private static function send_json_error( int $status, string $error, string $description ): void {
		header( 'Content-Type: application/json' );
		status_header( $status );
		echo wp_json_encode(
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);
	}

	/* ----------------------------------------------------------------
	 * Broker helpers
	 * --------------------------------------------------------------*/

	/**
	 * Decode the server's broker config from the auth_config column.
	 *
	 * Shape:
	 *   {
	 *     "authorize_url": "...",
	 *     "token_url":     "...",
	 *     "client_id":     "...",
	 *     "scope":         "...",
	 *     "extra_authorize_params": { ... }  (optional)
	 *   }
	 *
	 * @since  1.4.0
	 * @param  object $server Server row.
	 * @return array<string, mixed>
	 */
	private static function parse_broker_config( $server ): array {
		$raw = isset( $server->auth_config ) ? (string) $server->auth_config : '';
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decrypt and return the broker's client_secret from auth_credentials.
	 *
	 * Stored as encrypted JSON `{ "client_secret": "..." }` so it goes through
	 * the same key-management path as every other secret on the server row.
	 *
	 * @since  1.4.0
	 * @param  object $server Server row.
	 * @return string Empty string when not configured / decryption fails.
	 */
	private static function read_broker_client_secret( $server ): string {
		$raw = isset( $server->auth_credentials ) ? (string) $server->auth_credentials : '';
		if ( '' === $raw ) {
			return '';
		}
		$decrypted = \GetMCP\Utils\Encryption::decrypt( $raw );
		if ( ! is_string( $decrypted ) || '' === $decrypted ) {
			return '';
		}
		$decoded = json_decode( $decrypted, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		return isset( $decoded['client_secret'] ) ? (string) $decoded['client_secret'] : '';
	}

	/**
	 * HMAC-sign-and-pack the broker flow envelope so we can recover the MCP
	 * flow context after the upstream redirect.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $envelope Flow context.
	 * @return string                          base64url(json).base64url(hmac)
	 */
	private static function wrap_broker_state( array $envelope ): string {
		$secret = self::broker_state_secret();
		if ( null === $secret ) {
			return '';
		}
		$json = (string) wp_json_encode( $envelope );
		$body = self::b64url( $json );
		$sig  = self::b64url( hash_hmac( 'sha256', $body, $secret, true ) );
		return $body . '.' . $sig;
	}

	/**
	 * Verify and unpack a broker state envelope. Returns null when the
	 * signature doesn't verify or the payload is malformed.
	 *
	 * @since  1.4.0
	 * @param  string $wrapped Wrapped envelope.
	 * @return array<string, mixed>|null
	 */
	private static function unwrap_broker_state( string $wrapped ): ?array {
		$parts = explode( '.', $wrapped, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$secret = self::broker_state_secret();
		if ( null === $secret ) {
			return null;
		}
		[ $body, $sig ] = $parts;
		$expected = self::b64url( hash_hmac( 'sha256', $body, $secret, true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}
		$json    = self::b64url_decode( $body );
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Refresh the upstream access_token for a given token row.
	 *
	 * Returns the freshly minted upstream access_token on success, or empty
	 * string when refresh isn't possible (no refresh_token, upstream returned
	 * an error, etc.). On success the row is updated in place — callers don't
	 * need to do additional bookkeeping.
	 *
	 * @since  1.4.0
	 * @param  object $token_row Decrypted token row from getmcp_oauth_tokens.
	 * @param  object $server    Parent server (broker config lives here).
	 * @return string
	 */
	public static function refresh_upstream_token( $token_row, $server ): string {
		if ( \GetMCPExtensions\ProviderTokens::is_meta( $server ) ) { throw new \RuntimeException( 'Facebook Login does not issue OAuth refresh tokens. Reconnect your account before it expires.' ); }
		$config        = self::parse_broker_config( $server );
		$client_secret = self::read_broker_client_secret( $server );
		if ( empty( $config['token_url'] ) || empty( $config['client_id'] ) || '' === $client_secret ) {
			return '';
		}

		$refresh_enc = isset( $token_row->upstream_refresh_token ) ? (string) $token_row->upstream_refresh_token : '';
		if ( '' === $refresh_enc ) {
			return '';
		}
		$refresh = \GetMCP\Utils\Encryption::decrypt( $refresh_enc );
		if ( ! is_string( $refresh ) || '' === $refresh ) {
			return '';
		}

		$response = wp_remote_post( (string) $config['token_url'], array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			'body'    => http_build_query( array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => (string) $config['client_id'],
				'client_secret' => $client_secret,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return '';
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $payload ) || empty( $payload['access_token'] ) ) {
			return '';
		}

		$new_access  = (string) $payload['access_token'];
		// Some IDPs (Google) rotate refresh tokens — when one comes back,
		// persist it. Otherwise keep the existing one.
		$new_refresh = isset( $payload['refresh_token'] ) ? (string) $payload['refresh_token'] : '';
		$expires_in  = isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : 3600;

		global $wpdb;
		$update = array(
			'upstream_access_token' => \GetMCP\Utils\Encryption::encrypt( $new_access ),
			'upstream_expires_at'   => gmdate( 'Y-m-d H:i:s', time() + max( 60, $expires_in ) ),
		);
		$format = array( '%s', '%s' );
		if ( '' !== $new_refresh ) {
			$update['upstream_refresh_token'] = \GetMCP\Utils\Encryption::encrypt( $new_refresh );
			$format[]                         = '%s';
		}
		$wpdb->update(
			$wpdb->prefix . 'getmcp_oauth_tokens',
			$update,
			array( 'id' => (int) $token_row->id ),
			$format,
			array( '%d' )
		);

		return $new_access;
	}

	/**
	 * Validate the bearer + return the token row (with upstream tokens) for
	 * downstream consumers (AuthInjector reads upstream_access_token from it).
	 *
	 * Same semantics as `validate_and_extract_scopes` — returns null when the
	 * token is invalid. On success the caller gets a clone of the DB row
	 * (including upstream tokens, which the caller is responsible for
	 * decrypting via Encryption::decrypt).
	 *
	 * @since  1.4.0
	 * @param  string $access_token Raw bearer.
	 * @param  Server $server       MCP server being accessed.
	 * @return object|null
	 */
	public static function validate_and_extract_row( string $access_token, Server $server ): ?object {
		global $wpdb;

		$tokens_table  = $wpdb->prefix . 'getmcp_oauth_tokens';
		$clients_table = $wpdb->prefix . 'getmcp_oauth_clients';
		$token_hash    = hash( 'sha256', $access_token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.*, c.server_id AS client_server_id
				FROM {$tokens_table} t
				JOIN {$clients_table} c ON t.client_id = c.id
				WHERE t.access_token_hash = %s AND t.expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token_hash
			)
		);
		if ( ! $row ) {
			return null;
		}
		if ( (int) $row->client_server_id !== 0 && (int) $row->client_server_id !== $server->id ) {
			return null;
		}
		// Same fail-closed rule as validate_and_extract_scopes(): a client bound to
		// no server plus a token bound to no resource is a token with no audience.
		$bound_resource = isset( $row->resource ) ? trim( (string) $row->resource ) : '';
		if ( '' === $bound_resource ) {
			if ( 0 === (int) $row->client_server_id ) {
				return null;
			}
		} else {
			$expected = self::canonicalize_resource( $server->get_endpoint_url() );
			if ( $bound_resource !== $expected ) {
				return null;
			}
		}
		if ( FirstPartyOAuth::owns( $server ) ) {
			if ( ! FirstPartyOAuth::can_user_access( (int) ( $row->user_id ?? 0 ), $server ) || '' === $bound_resource ) {
				return null;
			}
			if ( FirstPartyOAuth::is_custom( $server ) ) {
				$scopes = preg_split( '/\s+/', trim( (string) ( $row->scopes ?? '' ) ) ) ?: array();
				if ( empty( array_filter( $scopes, 'strlen' ) ) || array_diff( $scopes, FirstPartyOAuth::supported_scopes( $server ) ) ) {
					return null;
				}
			}
		} elseif ( (int) ( $row->user_id ?? 0 ) > 0 ) {
			// A mode change must not reinterpret a native login as an external grant.
			return null;
		}
		return $row;
	}

	/**
	 * HMAC secret used for broker state-envelope signing.
	 */
	private static function broker_state_secret(): ?string {
		// Fail closed. The old hardcoded fallback made the envelope signature
		// forgeable by anyone with the source, which is public — an attacker
		// could mint their own state and steer the callback.
		if ( ! defined( 'AUTH_KEY' ) || ! is_string( AUTH_KEY ) || strlen( AUTH_KEY ) < 32 ) {
			return null;
		}
		return AUTH_KEY . '|getmcp-oauth-broker-state';
	}

	/**
	 * URL-safe base64 encode (RFC 4648 §5, no padding).
	 */
	private static function b64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * URL-safe base64 decode.
	 */
	private static function b64url_decode( string $b64 ): string {
		$padded = $b64 . str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 );
		return (string) base64_decode( strtr( $padded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Write an operator diagnostic to the PHP error log when WP_DEBUG is on.
	 *
	 * Every failure branch that calls this also delivers an actionable (but
	 * detail-free) error to the OAuth client, so nothing is lost on production
	 * sites running with WP_DEBUG off.
	 *
	 * @since 1.22.0
	 * @param string $message Full log line, including the getMCP prefix.
	 */
	private static function log_debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
		}
	}

	/**
	 * Reduce an upstream token-endpoint error body to a log-safe summary.
	 *
	 * The raw body is an IDP error document that can carry the operator's
	 * client_id, internal hostnames, or a partially reflected client_secret,
	 * so only the registered OAuth error members (RFC 6749 §5.2) survive,
	 * with the free-text description capped.
	 *
	 * @since  1.22.0
	 * @param  string $body Raw HTTP response body from the upstream token endpoint.
	 * @return string Log-safe summary of the error document.
	 */
	private static function summarize_token_error( string $body ): string {
		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) ) {
			$error       = isset( $decoded['error'] ) && is_string( $decoded['error'] ) ? $decoded['error'] : '';
			$description = isset( $decoded['error_description'] ) && is_string( $decoded['error_description'] ) ? substr( $decoded['error_description'], 0, 200 ) : '';

			if ( '' !== $error || '' !== $description ) {
				return trim( $error . ( '' !== $description ? ': ' . $description : '' ) );
			}

			return 'JSON body with keys: ' . implode( ', ', array_map( 'strval', array_slice( array_keys( $decoded ), 0, 10 ) ) );
		}

		return sprintf( 'non-JSON body (%d bytes)', strlen( $body ) );
	}
}
