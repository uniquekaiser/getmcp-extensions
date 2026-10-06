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
		add_action( 'getmcp_daily_cleanup', array( self::class, 'cleanup' ) );
		add_filter( 'getmcp_server_capabilities', array( self::class, 'capabilities' ), PHP_INT_MAX, 2 );
	}

	public static function cleanup(): void {
		global $wpdb;
		$wpdb->query( "DELETE c FROM {$wpdb->prefix}getmcp_upstream_connections c LEFT JOIN {$wpdb->prefix}getmcp_servers s ON s.id=c.server_id LEFT JOIN {$wpdb->users} u ON u.ID=c.user_id WHERE s.id IS NULL OR u.ID IS NULL" );
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
		return 'native' === $server->server_kind ? $caps : array( 'tools' => new \stdClass(), 'resources' => new \stdClass(), 'prompts' => new \stdClass() );
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
			return 'native' === $server->server_kind ? \GetMCPExtensions\ProviderConnections::begin( $server, $user ) : UpstreamOAuth::begin( $server, $user );
		} ) ) );
		register_rest_route( 'getmcp/v1', '/upstreams/(?P<uuid>[a-f0-9-]{36})/client-metadata', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn( $r ) => self::response( function() use ( $r ) {
			foreach ( FeatureManager::all() as $s ) { if ( $s->uuid === $r['uuid'] && 'remote-mcp' === $s->server_kind && 'active' === $s->status && 'oauth' === ( UpstreamConnections::config( $s )['auth_mode'] ?? '' ) ) { return UpstreamOAuth::client_metadata( $s ); } }
			throw new \GetMCP\Remote\RemoteException( 'Connection not found.', -32602 );
		} ) ) );
	}

	private static function response( callable $callback ) {
		try { return rest_ensure_response( $callback() ); }
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
			if ( 'native' !== $server->server_kind && 'active' === $server->status && \GetMCPExtensions\Runtime::server_enabled( $server ) && \GetMCP\Auth\FirstPartyOAuth::can_user_access( $user, $server ) ) {
				$endpoints[] = array_intersect_key( FeatureManager::present( $server ), array_flip( array( 'id', 'kind', 'name', 'slug', 'status', 'url' ) ) );
			}
		}
		return array( 'connections' => $items, 'endpoints' => $endpoints, 'can_manage_connections' => current_user_can( 'getmcp_manage_servers' ) );
	}

	public static function menu(): void {
		$admin = new \GetMCP\Admin\Admin();
		$hook = add_submenu_page( 'getmcp', 'Gateways & Remote MCP', 'Gateways & Remote MCP', 'getmcp_manage_servers', 'getmcp-connections', array( $admin, 'render_app' ) );
		if ( $hook ) { add_action( 'load-' . $hook, array( $admin, 'on_page_load' ) ); }
		$hook = add_submenu_page( 'getmcp', 'My MCP Connections', 'My MCP Connections', 'getmcp_manage_servers', 'getmcp-my-connections', array( $admin, 'render_app' ) );
		if ( $hook ) { add_action( 'load-' . $hook, array( $admin, 'on_page_load' ) ); }
		$hook = add_users_page( 'My MCP Connections', 'My MCP Connections', 'read', 'getmcp-account-connections', fn() => self::render( true ) );
		if ( $hook ) { add_action( 'load-' . $hook, array( $admin, 'on_page_load' ) ); }
	}

	public static function portal(): void {
		if ( isset( $_GET['getmcp_upstream_callback'] ) ) {
			self::require_login();
			try { UpstreamOAuth::complete( wp_unslash( $_GET ), get_current_user_id() ); wp_safe_redirect( UpstreamConnections::portal_url() ); exit; }
			catch ( \Throwable $e ) { wp_die( esc_html( $e instanceof \GetMCP\Remote\RemoteException ? $e->getMessage() : 'The upstream login failed. Start again from My MCP Connections.' ), 'MCP Connection', array( 'response' => 400 ) ); }
		}
		if ( ! isset( $_GET['getmcp_connections'] ) ) { return; }
		self::require_login();
		if ( ! current_user_can( 'read' ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
		nocache_headers();
		( new \GetMCP\Admin\Admin() )->enqueue_assets();
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
		if ( ! wp_script_is( 'getmcp-admin', 'enqueued' ) ) { ( new \GetMCP\Admin\Admin() )->enqueue_assets(); }
		wp_add_inline_script( 'getmcp-admin', 'window.mountGetMCPConnectionPortal();', 'after' );
		echo '<div class="getmcp-wrap"><div id="getmcp-native-connections"><h1>My MCP Connections</h1><p>Loading…</p></div></div>';
	}
}
