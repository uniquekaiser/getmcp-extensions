<?php
/**
 * OAuth 2.0 proxy — forwards the authorize/token dance to an upstream IDP.
 *
 * Distinct from {@see OAuthProvider}, which is a *self-hosted* OAuth server
 * issuing getMCP-native tokens. The proxy never issues its own tokens — every
 * authorize redirect and token exchange is forwarded transparently to the
 * upstream URLs configured on the server (auth_type = 'oauth2').
 *
 * Endpoints (per-server):
 *  - GET  /mcp/{slug}/.well-known/oauth-authorization-server  Metadata pointing at our proxy URLs.
 *  - GET  /mcp/{slug}/.well-known/oauth-protected-resource     Resource metadata for MCP discovery.
 *  - GET  /mcp/{slug}/oauth/authorize                          Redirect to upstream authorize.
 *  - POST /mcp/{slug}/oauth/token                              Forward token exchange to upstream.
 *
 * @package GetMCP
 * @since   1.2.0
 */

namespace GetMCP\Auth;

use GetMCP\Core\ServerManager;
use GetMCP\Gateway\McpGateway;
use GetMCP\Utils\PublicUrl;

/**
 * Stateless proxy in front of an admin-configured upstream OAuth 2.0 IDP.
 *
 * @since 1.2.0
 */
class OAuthProxy {

	/**
	 * Dispatch a per-server OAuth request — discovery + broker routes.
	 *
	 * Discovery (`metadata`, `protected-resource`) runs for any OAuth-enabled
	 * server. Authorize / token / register / callback are dispatched to
	 * OAuthProvider's broker handlers when the server is configured for
	 * OAuth (`auth_type='oauth'`).
	 *
	 * @since  1.2.0
	 * @param  string $slug   Server slug from the URL.
	 * @param  string $action authorize | token | callback | register | metadata | protected-resource.
	 */
	public static function handle_request( string $slug, string $action ): void {
		self::emit_cors_headers();

		if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			status_header( 204 );
			return;
		}

		// Global no-slug discovery — resolve to the only OAuth server when
		// exactly one exists. Otherwise return a CORS-friendly 404 so the MCP
		// client can fall back to the per-server URL form.
		if ( '' === $slug ) {
			$server = self::resolve_default_oauth_server();

			// Nothing unambiguous in the servers table — but if the gateway is
			// on, the site *does* have a single front door and this document is
			// exactly how a client discovers it. Checked second so a one-server
			// site keeps answering for that server, as it always has.
			if ( ! $server && McpGateway::is_available() && 'oauth' === McpGateway::auth_type() ) {
				$server = McpGateway::server();
			}

			if ( ! $server ) {
				self::send_json_error(
					404,
					'no_default_server',
					'No OAuth-enabled server is configured. Use the per-server metadata URL: /mcp/{slug}/.well-known/...'
				);
				return;
			}
			if ( 'global-metadata' === $action ) {
				self::handle_metadata( $server );
				return;
			}
			if ( 'global-protected-resource' === $action ) {
				self::handle_protected_resource( $server );
				return;
			}
			self::send_json_error( 404, 'not_found', 'Unknown global OAuth action.' );
			return;
		}

		$server = ( new ServerManager() )->get_by_slug( sanitize_title( $slug ) );
		if ( ! $server ) {
			self::send_json_error( 404, 'not_found', 'Server not found.' );
			return;
		}

		$is_oauth = 'oauth' === $server->auth_type;
		$broker_actions = array( 'register', 'authorize', 'token', 'callback' );

		if ( ! $is_oauth && in_array( $action, array( 'metadata', 'protected-resource' ), true ) ) {
			// Discovery on a non-OAuth server is a 404, not 5xx — clients
			// commonly probe these URLs before realising the server isn't
			// gated by OAuth at all.
			self::send_json_error( 404, 'not_found', 'OAuth is not enabled on this server.' );
			return;
		}
		if ( in_array( $action, $broker_actions, true ) && ! $is_oauth ) {
			self::send_json_error( 404, 'not_found', 'This action requires auth_type=oauth.' );
			return;
		}

		switch ( $action ) {
			case 'metadata':
				self::handle_metadata( $server );
				return;
			case 'protected-resource':
				self::handle_protected_resource( $server );
				return;
			case 'register':
				OAuthProvider::handle_per_server_register( $server );
				return;
			case 'authorize':
				// The built-in server is its own authorization server — there
				// is no upstream identity provider to bounce through, and the
				// resource owner is whoever is signed in to the dashboard.
				if ( FirstPartyOAuth::owns( $server ) ) {
					FirstPartyOAuth::handle_authorize( $server );
					return;
				}
				OAuthProvider::handle_per_server_authorize( $server );
				return;
			case 'token':
				if ( FirstPartyOAuth::owns( $server ) ) {
					FirstPartyOAuth::handle_token( $server );
					return;
				}
				OAuthProvider::handle_per_server_token( $server );
				return;
			case 'callback':
				// Only the broker flow has an upstream to come back from.
				if ( FirstPartyOAuth::owns( $server ) ) {
					self::send_json_error( 404, 'not_found', 'This server does not use an upstream identity provider.' );
					return;
				}
				OAuthProvider::handle_per_server_callback( $server );
				return;
		}

		self::send_json_error( 404, 'not_found', 'Unknown OAuth action.' );
	}

	/**
	 * Serve `/.well-known/oauth-authorization-server` for this server.
	 *
	 * @since 1.2.0
	 * @param object $server Server row.
	 */
	private static function handle_metadata( object $server ): void {
		$base = self::server_base_url( $server );

		header( 'Content-Type: application/json' );
		status_header( 200 );

		// We are the AS for the MCP client — `OAuthProvider::metadata_for_issuer`
		// emits per-server endpoints under the issuer URL so authorize/token/
		// register/callback all route back to this server's broker handlers.
		$metadata = OAuthProvider::metadata_for_issuer( $base );
		if ( FirstPartyOAuth::is_custom( $server ) ) {
			$metadata['scopes_supported'] = FirstPartyOAuth::supported_scopes( $server );
		}
		echo wp_json_encode( $metadata );
	}

	/**
	 * Serve `/.well-known/oauth-protected-resource` (MCP discovery hint).
	 *
	 * @since 1.2.0
	 * @param object $server Server row.
	 */
	private static function handle_protected_resource( object $server ): void {
		$base = self::server_base_url( $server );

		/*
		 * No trailing slash on the authorization server URL — it must be
		 * byte-identical to the `issuer` in the AS metadata (RFC 8414 §3.3),
		 * and the issuer is emitted without one. Strict clients (Hyperagent)
		 * compare exactly and refused to connect over the mismatch:
		 * "the server's identity didn't match the URL you provided".
		 *
		 * History: the slash was added for ancient MCP TypeScript SDKs that
		 * resolved `new URL(".well-known/...", base)` relatively and lost the
		 * slug without it. Modern SDKs strip the slash and path-insert the
		 * well-known segment instead, and truly legacy clients still land on
		 * handle_root_fallback() below — so exact identity wins.
		 */
		header( 'Content-Type: application/json' );
		status_header( 200 );
		echo wp_json_encode(
			array(
				'resource'                 => $base,
				'authorization_servers'    => array( $base ),
				'bearer_methods_supported' => array( 'header' ),
				'scopes_supported'         => FirstPartyOAuth::supported_scopes( $server ),
				'resource_documentation'   => 'https://docs.getmcp.com/',
			)
		);
	}

	/**
	 * Serve the legacy origin-root OAuth paths: `/authorize`, `/token`, `/register`.
	 *
	 * RFC 8414 §5 and the MCP authorization spec both tell a client that fails
	 * metadata discovery to fall back to these fixed paths at the issuer origin.
	 * getMCP publishes per-server endpoints, so before this handler existed those
	 * requests landed on WordPress's HTML 404 page — an MCP client parsing it as
	 * JSON reports "fetch failed" or "unexpected token <", which points nowhere
	 * near the real cause.
	 *
	 * Runs on `template_redirect` and only when WordPress found nothing at the
	 * path. A membership site with a real page at `/register` keeps it: hijacking
	 * a customer's published page to serve OAuth would be a far worse failure
	 * than the one this fixes.
	 *
	 * Since 1.6.0 the plugin reaches this through GetMCP::serve_root_oauth_fallback(),
	 * which runs last on `template_redirect` and honours RootOAuthPaths::claims(),
	 * so any other plugin that answers these shared paths wins and a site can
	 * switch the fallback off. This wrapper keeps the old entry point working.
	 *
	 * @since 1.22.0
	 */
	public static function handle_root_fallback(): void {
		if ( ! is_404() ) {
			return;
		}

		$action = RootOAuthPaths::action_for_request();
		if ( null === $action || RootOAuthPaths::is_discovery( $action ) ) {
			return;
		}

		self::serve_root_action( $action );
		exit;
	}

	/**
	 * Answer one legacy root action for the server that owns the site's front door.
	 *
	 * @since  1.6.0
	 * @param  string $action One of authorize, token, register.
	 */
	public static function serve_root_action( string $action ): void {
		if ( ! in_array( $action, RootOAuthPaths::LEGACY, true ) ) {
			self::send_json_error( 404, 'not_found', 'Unknown OAuth action.' );
			return;
		}

		self::emit_cors_headers();

		if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			status_header( 204 );
			return;
		}

		$server = self::resolve_root_fallback_server();

		if ( ! $server ) {
			self::send_json_error( 404, 'invalid_request', self::describe_root_fallback_miss( $action ) );
			return;
		}

		$first_party = FirstPartyOAuth::owns( $server );

		switch ( $action ) {
			case 'authorize':
				if ( $first_party ) {
					FirstPartyOAuth::handle_authorize( $server );
					break;
				}
				OAuthProvider::handle_per_server_authorize( $server );
				break;
			case 'token':
				if ( $first_party ) {
					FirstPartyOAuth::handle_token( $server );
					break;
				}
				OAuthProvider::handle_per_server_token( $server );
				break;
			case 'register':
				OAuthProvider::handle_per_server_register( $server );
				break;
		}
	}

	/**
	 * Which server the legacy origin-root paths belong to.
	 *
	 * The gateway owns the site's front door whenever it is switched on: it is
	 * the endpoint an operator hands to a client, so a client that lost its way
	 * and fell back to `/authorize` is asking for the gateway's sign-in, not for
	 * some other server's.
	 *
	 * Without this the fallback dropped through to
	 * {@see resolve_sole_oauth_server()}, which answers "the one server on this
	 * site that brokers OAuth" — and cheerfully bounced the browser to that
	 * vendor's consent screen. Connecting the gateway showed a Calendly login.
	 *
	 * @since  1.5.0
	 * @return object|null
	 */
	private static function resolve_root_fallback_server(): ?object {
		if ( McpGateway::is_available() && 'oauth' === McpGateway::auth_type() ) {
			return McpGateway::server();
		}

		return self::resolve_sole_oauth_server();
	}

	/* ----------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------*/

	/**
	 * The one OAuth-broker server on this site, or null when the answer is ambiguous.
	 *
	 * Looser than {@see resolve_default_oauth_server()} on purpose. That method
	 * backs the origin-level *discovery documents*, which state a resource
	 * identity, so it refuses to answer while any non-OAuth server exists.
	 * `/authorize` and `/token` state nothing — a client only reaches them
	 * because it is doing OAuth, and non-OAuth servers cannot be what it means.
	 * Two OAuth servers still leaves us guessing, so that stays a hard stop.
	 *
	 * @since  1.22.0
	 * @return object|null
	 */
	private static function resolve_sole_oauth_server(): ?object {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE auth_type = 'oauth' LIMIT 2" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( empty( $rows ) || count( $rows ) > 1 ) {
			return null;
		}

		return $rows[0];
	}

	/**
	 * Explain a root-path OAuth request we cannot route, and name the real URLs.
	 *
	 * @since  1.22.0
	 * @param  string $action authorize | token | register.
	 * @return string
	 */
	private static function describe_root_fallback_miss( string $action ): string {
		global $wpdb;

		$slugs = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT slug FROM {$wpdb->prefix}getmcp_servers WHERE auth_type = 'oauth' ORDER BY slug ASC LIMIT 20" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( empty( $slugs ) ) {
			return sprintf(
				/* translators: %s: requested OAuth path, e.g. "authorize". */
				__( 'No server on this site uses OAuth, so there is no /%s endpoint to serve. Set the server\'s authentication type to OAuth in the getMCP admin, or point your MCP client at an API-key server instead.', 'getmcp' ),
				$action
			);
		}

		$urls = array();
		foreach ( $slugs as $slug ) {
			$urls[] = PublicUrl::server( (string) $slug ) . '/oauth/' . $action;
		}

		return sprintf(
			/* translators: 1: requested OAuth path, e.g. "authorize", 2: comma-separated list of per-server endpoint URLs. */
			__( 'getMCP serves OAuth per server, not at the site root, so /%1$s does not exist here. Your MCP client reached it because discovery failed and it fell back to the legacy default paths. Point the client at one MCP server URL and let it discover from there, or use the endpoint directly: %2$s', 'getmcp' ),
			$action,
			implode( ', ', $urls )
		);
	}

	/**
	 * Pick the only OAuth-enabled server to back the origin-level `.well-known/*`
	 * probes. Returns null when:
	 *  - There are zero or more than one OAuth servers, OR
	 *  - Any non-OAuth servers exist alongside the single OAuth server.
	 *
	 * Mixed-auth setups must use per-server discovery URLs. Serving the OAuth
	 * server's data at the origin level misleads MCP clients connecting to
	 * non-OAuth servers: they discover the OAuth server's resource URL and then
	 * fail the resource-match validation against their actual server URL.
	 *
	 * @since  1.2.0
	 * @return object|null
	 */
	private static function resolve_default_oauth_server(): ?object {
		global $wpdb;
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_servers" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE auth_type = 'oauth' LIMIT 2" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		// Only serve global discovery when every server on the site uses OAuth
		// and there is exactly one of them.
		if ( empty( $rows ) || count( $rows ) > 1 || $total !== 1 ) {
			return null;
		}
		return $rows[0];
	}

	/**
	 * Build the canonical base URL for a server, e.g. `https://site/mcp/wp-api`.
	 *
	 * Takes the server, not its slug, because the gateway does not live at
	 * `/mcp/{slug}` — it answers on the bare `/mcp`. The `issuer` and `resource`
	 * emitted here must be byte-identical to the URL the client was given, so
	 * this has to ask the server where it actually is rather than assume.
	 *
	 * @since  1.2.0
	 * @param  object $server Server row or model.
	 * @return string
	 */
	public static function server_base_url( object $server ): string {
		if ( isset( $server->id ) && McpGateway::SERVER_ID === (int) $server->id ) {
			return McpGateway::endpoint();
		}

		return PublicUrl::server( (string) $server->slug );
	}

	/**
	 * Emit a permissive CORS policy for OAuth proxy responses.
	 *
	 * Discovery (`.well-known/*`) and the token endpoint are called from browser
	 * fetch contexts (MCP Inspector, future web MCP clients). They need an
	 * explicit `Access-Control-Allow-Origin` to be readable.
	 *
	 * No `Access-Control-Allow-Credentials`. Nothing on these routes is
	 * authenticated by cookie: discovery returns public metadata, and the token
	 * endpoint authenticates the client with PKCE out of the request body.
	 * Reflecting an arbitrary Origin *and* granting credentials meant any page
	 * on the internet could drive a logged-in browser through the authorize /
	 * token dance and read the response — the credentialed exception exists for
	 * readability of public documents, and readability needs no credentials.
	 *
	 * A literal `*` rather than reflecting the request Origin: without
	 * credentials the two are equivalent in what a browser will let a page
	 * read, and `*` never echoes attacker-controlled input into a response
	 * header or depends on Vary handling in intermediary caches.
	 *
	 * @since 1.2.0
	 */
	private static function emit_cors_headers(): void {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, X-MCP-Protocol-Version' );
		header( 'Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id' );
		header( 'Access-Control-Max-Age: 86400' );
	}

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
}
