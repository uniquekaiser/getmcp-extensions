<?php
/** Native feature registration, protected management routes, and account portal. @package GetMCP */
namespace GetMCP\Gateway;

use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Remote\UpstreamConnections;
use GetMCP\Remote\UpstreamOAuth;

class FeatureModule {

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ), 30 );
		add_action( 'template_redirect', array( self::class, 'portal' ), -5 );
		add_action( 'deleted_user', array( UpstreamConnections::class, 'delete_user' ) );
		add_action( 'getmcp_server_deleted', array( self::class, 'delete_legacy_server_grants' ) );
		add_action( 'getmcp_daily_cleanup', array( self::class, 'cleanup' ) );
		add_filter( 'getmcp_server_capabilities', array( self::class, 'capabilities' ), PHP_INT_MAX, 2 );
		add_filter( 'getmcp_gateway_servers', array( self::class, 'gateway_members' ), PHP_INT_MAX );
		add_filter( 'rest_pre_dispatch', array( self::class, 'legacy_metadata_dispatch' ), -50, 3 );
	}

	/** Core 1.8 cleans its own table; the add-on still owns its separate grants. */
	public static function delete_legacy_server_grants( int $server_id ): void {
		if ( \GetMCPExtensions\LegacyBridge::is_180() ) { UpstreamConnections::delete_server( $server_id ); }
	}

	/** Retain the old explicit-publication rule without weakening native caller checks. */
	public static function gateway_members( array $servers ): array {
		if ( ! \GetMCPExtensions\LegacyBridge::is_180() ) { return $servers; }
		return array_values( array_filter( $servers, static function ( $server ): bool {
			if ( ! $server instanceof Server ) { return false; }
			$kind = \GetMCPExtensions\LegacyBridge::kind( $server );
			if ( 'gateway' === $kind || 'unknown' === $kind ) { return false; }
			if ( 'remote-mcp' !== $kind ) { return true; }
			$settings = json_decode( $server->settings ?? '{}', true ) ?: array();
			return ! empty( $settings['remote_mcp']['publish_original'] ) && \GetMCPExtensions\Runtime::server_enabled( $server );
		} ) );
	}

	public static function cleanup(): void {
		global $wpdb;
		$table = UpstreamConnections::table();
		$wpdb->query( "DELETE c FROM {$table} c LEFT JOIN {$wpdb->prefix}getmcp_servers s ON s.id=c.server_id LEFT JOIN {$wpdb->users} u ON u.ID=c.user_id WHERE s.id IS NULL OR u.ID IS NULL" );
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000", $wpdb->esc_like( 'getmcp_upstream_state_' ) . '%' ) );
		foreach ( $names as $name ) {
			try { $state = json_decode( \GetMCP\Utils\Encryption::decrypt_strict( get_option( $name ) ), true ); if ( (int) ( $state['expires_at'] ?? 0 ) >= time() ) { continue; } }
			catch ( \Throwable $e ) { /* An unreadable expired state cannot be used. */ }
			delete_option( $name );
		}
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 1000", $wpdb->esc_like( 'getmcp_upstream_refresh_' ) . '%', time() ) );
		foreach ( $names as $name ) { delete_option( $name ); }
	}

	public static function capabilities( array $caps, Server $server ): array {
		return in_array( \GetMCPExtensions\LegacyBridge::kind( $server ), array( 'gateway', 'remote-mcp' ), true ) ? array( 'tools' => new \stdClass(), 'resources' => new \stdClass(), 'prompts' => new \stdClass() ) : $caps;
	}

	public static function routes(): void {
		$manage = fn() => current_user_can( 'getmcp_manage_servers' );
		register_rest_route( 'getmcp/v1', '/connections', array(
			array( 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => fn() => self::response( fn() => FeatureManager::run( 'list', array() ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => FeatureManager::run( 'save', $r->get_json_params() ?: array() ) ) ),
		) );
		register_rest_route( 'getmcp/v1', '/connections/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => FeatureManager::run( 'get', array( 'id' => (int) $r['id'] ) ) ) ),
			array( 'methods' => 'PUT', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => FeatureManager::run( 'save', array_merge( $r->get_json_params() ?: array(), array( 'id' => (int) $r['id'] ) ) ) ) ),
			array( 'methods' => 'DELETE', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => FeatureManager::run( 'delete', array( 'id' => (int) $r['id'] ) ) ) ),
		) );
		register_rest_route( 'getmcp/v1', '/connections/(?P<id>\d+)/preview', array( 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => FeatureManager::run( 'preview', array( 'id' => (int) $r['id'] ) ) ) ) );
		register_rest_route( 'getmcp/v1', '/my-connections', array( 'methods' => 'GET', 'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'read' ), 'callback' => fn() => self::response( fn() => self::my_connections() ) ) );
		register_rest_route( 'getmcp/v1', '/my-connections/(?P<id>\d+)', array( 'methods' => 'POST, DELETE', 'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'read' ), 'callback' => fn( $r ) => self::response( function() use ( $r ) {
			$server = ( new ServerManager() )->get( (int) $r['id'] ); $user = get_current_user_id();
			if ( ! $server || ! UpstreamConnections::can_connect( $server, $user ) ) { throw new \GetMCP\Remote\RemoteException( 'This upstream connection is not available to you.', -32003 ); }
			if ( 'DELETE' === $r->get_method() ) { UpstreamConnections::disconnect( $server->id, $user ); return array( 'disconnected' => true ); }
			$return_to = $r->get_param( 'return_to' ); $return_to = is_string( $return_to ) ? $return_to : '';
			return 'native' === \GetMCPExtensions\LegacyBridge::kind( $server ) ? \GetMCPExtensions\ProviderConnections::begin( $server, $user, $return_to ) : UpstreamOAuth::begin( $server, $user, $return_to );
		} ) ) );
		if ( ! \GetMCPExtensions\LegacyBridge::is_180() ) {
			register_rest_route( 'getmcp/v1', '/upstreams/(?P<uuid>[a-f0-9-]{36})/client-metadata', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn( $r ) => self::response( function() use ( $r ) {
				foreach ( FeatureManager::all() as $s ) { if ( $s->uuid === $r['uuid'] && 'remote-mcp' === \GetMCPExtensions\LegacyBridge::kind( $s ) && 'active' === $s->status && 'oauth' === ( UpstreamConnections::config( $s )['auth_mode'] ?? '' ) ) { return UpstreamOAuth::client_metadata( $s ); } }
				throw new \GetMCP\Remote\RemoteException( 'Connection not found.', -32602 );
			} ) ) );
		}
	}

	/** Keep legacy OAuth client URLs while allowing native 1.8 metadata to route normally. */
	/** @wp-test feature=upgrade.180 scenario=legacy-metadata-routing */
	public static function legacy_metadata_dispatch( $result, $rest, $request ) {
		if ( null !== $result || ! \GetMCPExtensions\LegacyBridge::is_180() || ! \GetMCPExtensions\Runtime::ready() || 'GET' !== $request->get_method() || ! preg_match( '#\A/getmcp/v1/upstreams/([a-f0-9-]{36})/client-metadata\z#D', $request->get_route(), $match ) ) { return $result; }
		$server = ( new ServerManager() )->get_by_uuid( $match[1] );
		if ( ! $server || 'remote-mcp' !== \GetMCPExtensions\LegacyBridge::kind( $server ) ) { return $result; }
		if ( 'active' !== $server->status || 'wordpress' !== $server->auth_type || ! \GetMCPExtensions\Runtime::server_enabled( $server ) || 'oauth' !== ( UpstreamConnections::config( $server )['auth_mode'] ?? '' ) ) {
			return new \WP_Error( 'getmcp_connection_not_found', 'Connection not found.', array( 'status' => 404 ) );
		}
		return self::response( fn() => UpstreamOAuth::client_metadata( $server ) );
	}

	private static function response( callable $callback ) {
		try { return rest_ensure_response( $callback() ); }
		catch ( \GetMCPExtensions\SettingsConflict $e ) { return new \WP_Error( 'getmcp_connection_conflict', $e->getMessage(), array( 'status' => 409 ) ); }
		catch ( \GetMCP\Remote\RemoteException $e ) { return new \WP_Error( 'getmcp_connection_error', $e->getMessage(), array( 'status' => -32003 === $e->get_rpc_code() ? 403 : 400 ) ); }
		catch ( \InvalidArgumentException $e ) { return new \WP_Error( 'getmcp_invalid_connection', $e->getMessage(), array( 'status' => 400 ) ); }
		catch ( \Throwable $e ) { return new \WP_Error( 'getmcp_connection_error', 'The connection operation could not be completed.', array( 'status' => 500 ) ); }
	}

	private static function my_connections(): array {
		$items = \GetMCPExtensions\ProviderConnections::listing( get_current_user_id() ); $user = get_current_user_id();
		foreach ( FeatureManager::all() as $server ) {
			if ( 'oauth' === ( UpstreamConnections::config( $server )['auth_mode'] ?? '' ) && UpstreamConnections::can_connect( $server, $user ) ) {
				try { $grant = UpstreamConnections::get( $server->id, $user ); $connected = null !== $grant; } catch ( \Throwable $e ) { $connected = false; }
				$items[] = array( 'id' => $server->id, 'name' => $server->name, 'connected' => $connected );
			}
		}
		$endpoints = array();
		foreach ( FeatureManager::all() as $server ) {
			if ( in_array( \GetMCPExtensions\LegacyBridge::kind( $server ), array( 'gateway', 'remote-mcp' ), true ) && 'active' === $server->status && \GetMCPExtensions\Runtime::server_enabled( $server ) && \GetMCPExtensions\LegacyBridge::can_user_access( $user, $server ) ) {
				$endpoints[] = array_intersect_key( FeatureManager::present( $server ), array_flip( array( 'id', 'kind', 'name', 'slug', 'status', 'url' ) ) );
			}
		}
		return array( 'connections' => $items, 'endpoints' => $endpoints, 'can_manage_connections' => current_user_can( 'getmcp_manage_servers' ) );
	}

	public static function menu(): void {
		$admin = new \GetMCP\Admin\Admin();
		$is_180 = 'vendor-original-1.8.0' === \GetMCPExtensions\Runtime::profile();
		$hook = add_submenu_page( 'getmcp', 'Gateways & Remote MCP', 'Gateways & Remote MCP', 'getmcp_manage_servers', $is_180 ? 'getmcp-extensions-connections' : 'getmcp-connections', $is_180 ? static fn() => \GetMCPExtensions\Standalone180::render( 'connections' ) : array( $admin, 'render_app' ) );
		if ( $hook ) { add_action( 'load-' . $hook, $is_180 ? static fn() => \GetMCPExtensions\Standalone180::enqueue( 'connections' ) : array( $admin, 'on_page_load' ) ); }
		$hook = add_submenu_page( 'getmcp', 'My MCP Connections', 'My MCP Connections', 'getmcp_manage_servers', $is_180 ? 'getmcp-extensions-my-connections' : 'getmcp-my-connections', $is_180 ? static fn() => \GetMCPExtensions\Standalone180::render( 'portal' ) : array( $admin, 'render_app' ) );
		if ( $hook ) { add_action( 'load-' . $hook, $is_180 ? static fn() => \GetMCPExtensions\Standalone180::enqueue( 'portal' ) : array( $admin, 'on_page_load' ) ); }
		$hook = add_users_page( 'My MCP Connections', 'My MCP Connections', 'read', 'getmcp-account-connections', fn() => self::render( true ) );
		if ( $hook ) { add_action( 'load-' . $hook, $is_180 ? static fn() => \GetMCPExtensions\Standalone180::enqueue( 'portal' ) : array( $admin, 'on_page_load' ) ); }
		if ( $is_180 ) {
			$hook = add_submenu_page( 'getmcp', 'Personal provider access', 'Provider access', 'getmcp_manage_servers', 'getmcp-extensions-providers', static fn() => \GetMCPExtensions\Standalone180::render( 'providers' ) );
			if ( $hook ) { add_action( 'load-' . $hook, static fn() => \GetMCPExtensions\Standalone180::enqueue( 'providers' ) ); }
		}
	}

	public static function portal(): void {
		if ( isset( $_GET['getmcp_upstream_callback'] ) ) {
			self::require_login();
			try { $return_url = UpstreamOAuth::complete( wp_unslash( $_GET ), get_current_user_id() ); wp_safe_redirect( $return_url ); exit; }
			catch ( \Throwable $e ) { wp_die( esc_html( $e instanceof \GetMCP\Remote\RemoteException ? $e->getMessage() : 'The upstream login failed. Start again from My MCP Connections.' ), 'MCP Connection', array( 'response' => 400 ) ); }
		}
		if ( ! isset( $_GET['getmcp_connections'] ) ) { return; }
		self::require_login();
		if ( ! current_user_can( 'read' ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
		nocache_headers();
		if ( 'vendor-original-1.8.0' === \GetMCPExtensions\Runtime::profile() ) { \GetMCPExtensions\Standalone180::enqueue( 'portal' ); }
		else { ( new \GetMCP\Admin\Admin() )->enqueue_assets(); }
		echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>My MCP Connections</title>';
		wp_head(); echo '</head><body class="bg-gray-50">'; self::render( true ); wp_footer(); echo '</body></html>'; exit;
	}

	private static function require_login(): void {
		// Front-end pages use the logged-in cookie; auth_redirect expects the wp-admin auth cookie.
		if ( is_user_logged_in() ) { return; }
		$return = add_query_arg( wp_unslash( $_GET ), home_url( '/' ) );
		wp_safe_redirect( wp_login_url( $return ) ); exit;
	}

	public static function enqueue_components(): void {
		$version = static fn( $file ) => substr( hash_file( 'sha256', GETMCP_EXTENSIONS_PATH . 'admin/' . $file ), 0, 12 );
		wp_register_script( 'getmcp-oauth-user-picker', GETMCP_EXTENSIONS_URL . 'admin/oauth-user-picker.js', array( 'wp-element' ), $version( 'oauth-user-picker.js' ), true );
		wp_enqueue_script( 'getmcp-native-connections', GETMCP_EXTENSIONS_URL . 'admin/native-connections.js', array( 'wp-element', 'getmcp-oauth-user-picker' ), $version( 'native-connections.js' ), true );
		wp_enqueue_script( 'getmcp-app-extension', GETMCP_EXTENSIONS_URL . 'admin/getmcp-app-extension.js', array( 'getmcp-native-connections' ), $version( 'getmcp-app-extension.js' ), true );
	}

	public static function render( bool $portal ): void {
		if ( 'vendor-original-1.8.0' === \GetMCPExtensions\Runtime::profile() ) { \GetMCPExtensions\Standalone180::render( $portal ? 'portal' : 'connections' ); return; }
		if ( ! wp_script_is( 'getmcp-admin', 'enqueued' ) ) { ( new \GetMCP\Admin\Admin() )->enqueue_assets(); }
		wp_add_inline_script( 'getmcp-admin', 'window.mountGetMCPConnectionPortal();', 'after' );
		echo '<div class="getmcp-wrap"><div id="getmcp-native-connections"><h1>My MCP Connections</h1><p>Loading…</p></div></div>';
	}
}
