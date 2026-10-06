<?php
/** Independent settings and reviewed, early compatibility adapters. */
namespace GetMCPExtensions;

final class Runtime {
    private static bool $ready = false;
    private static string $profile = '';
    private static array $errors = array();
    private const DEFAULTS = array( 'native_oauth' => true, 'remote_mcp' => true, 'project_gateways' => true );

    public static function settings(): array {
        $saved = get_option( 'getmcp_extensions_settings', array() );
        return array( 'revision' => (int) ( $saved['revision'] ?? 0 ), 'features' => array_merge( self::DEFAULTS, (array) ( $saved['features'] ?? array() ) ) );
    }
    public static function enabled( string $feature ): bool {
        $f = self::settings()['features'];
        $native = self::native_features();
        return self::$ready && ( ! empty( $f[$feature] ) || ! empty( $native[$feature] ) ) && ( 'native_oauth' === $feature || ! empty( $f['native_oauth'] ) || $native['native_oauth'] );
    }
    public static function native_features(): array {
        return array( 'native_oauth' => in_array( self::$profile, array( 'oauth-prerequisite-1.6.0', 'integrated-handoff-1.6.0' ), true ), 'remote_mcp' => 'integrated-handoff-1.6.0' === self::$profile, 'project_gateways' => 'integrated-handoff-1.6.0' === self::$profile );
    }
    public static function ready(): bool { return self::$ready; }
    public static function server_enabled( $server ): bool {
        $settings = json_decode( $server->settings ?? '{}', true ) ?: array();
        if ( ! empty( $settings['getmcp_extensions_required'] ) && ! MarketingModule::enabled() ) { return false; }
        $kind = $server->server_kind ?? 'native';
        if ( 'gateway' === $kind ) { return self::enabled( 'project_gateways' ); }
        if ( 'remote-mcp' === $kind ) { return self::enabled( 'remote_mcp' ); }
        $config = json_decode( $server->auth_config ?? '{}', true ) ?: array();
        return 'getmcp' !== ( $config['provider'] ?? '' ) || self::enabled( 'native_oauth' );
    }
    public static function assert_server( $server ): void {
        if ( ! self::server_enabled( $server ) ) { throw new \GetMCP\Remote\RemoteException( 'This GetMCP Extensions feature is disabled. Ask an administrator to enable it.', -32003 ); }
    }
    public static function assert_enabled( string $feature ): void {
        if ( ! self::enabled( $feature ) ) { throw new \GetMCP\Remote\RemoteException( 'This GetMCP Extensions feature is disabled.', -32003 ); }
    }
    public static function inspect( string $path ): array {
        $manifest = json_decode( file_get_contents( GETMCP_EXTENSIONS_PATH . 'compatibility.json' ), true );
        $best = array(); $selected = '';
        foreach ( $manifest['profiles'] as $name => $files ) {
            $bad = array();
            foreach ( $files as $file => $hash ) {
                $actual = is_file( $path . $file ) ? hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( $path . $file ) ) ) : '';
                if ( $actual !== $hash ) { $bad[] = $file; }
            }
            if ( ! $bad ) { $selected = $name; break; }
            if ( ! $best || count( $bad ) < count( $best ) ) { $best = $bad; }
        }
        return array( 'profile' => $selected, 'errors' => $selected ? array() : array_slice( $best, 0, 20 ), 'classes' => $manifest['classes'] );
    }
    public static function boot(): void {
        add_action( 'admin_menu', array( self::class, 'menu' ), 40 );
        add_action( 'rest_api_init', array( self::class, 'routes' ), 30 );
        add_action( 'admin_notices', array( self::class, 'notice' ) );
        if ( is_multisite() ) { self::$errors = array( 'GetMCP Extensions currently supports single-site WordPress only.' ); return; }
        if ( ! defined( 'GETMCP_PATH' ) || version_compare( PHP_VERSION, '8.2', '<' ) ) { self::$errors = array( 'GetMCP and PHP 8.2 or newer are required.' ); return; }
        $check = self::inspect( GETMCP_PATH ); self::$profile = $check['profile']; self::$errors = $check['errors'];
        if ( ! self::$profile ) { return; }
        // Already integrated builds own their implementation; do not redeclare their classes.
        $native = 'integrated-handoff-1.6.0' === self::$profile;
        {
            foreach ( $check['classes'] as $class => $file ) {
                if ( class_exists( $class, false ) ) { self::$errors = array( 'GetMCP initialized before the add-on: ' . $class ); return; }
            }
            spl_autoload_register( static function( $class ) use ( $check ) {
                if ( isset( $check['classes'][$class] ) ) { require_once GETMCP_EXTENSIONS_PATH . $check['classes'][$class]; }
            }, true, true );
        }
        self::$ready = true;
        if ( ! defined( 'GETMCP_EXTENSIONS_READY' ) ) { define( 'GETMCP_EXTENSIONS_READY', true ); }
        if ( ! $native ) { \GetMCP\Gateway\FeatureModule::register(); }
        MarketingModule::boot();
        ConnectionScreens::boot();
        MarketingTemplates::boot();
        add_action( 'init', array( Lifecycle::class, 'migrate' ), 30 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 100 );
        add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 100 );
        add_filter( 'rest_pre_dispatch', array( self::class, 'rest_guard' ), -100, 3 );
        add_filter( 'getmcp_gateway_servers', static fn( $servers ) => array_values( array_filter( $servers, array( self::class, 'server_enabled' ) ) ), PHP_INT_MAX );
    }
    public static function status(): array {
        $effective = array(); foreach ( array_keys( self::DEFAULTS ) as $feature ) { $effective[$feature] = self::enabled( $feature ); }
        return self::settings() + array( 'effective_features' => $effective, 'native_features' => self::native_features(), 'ready' => self::$ready, 'profile' => self::$profile, 'compatibility_errors' => self::$errors, 'upstream_integrated' => 'integrated-handoff-1.6.0' === self::$profile, 'php_minimum' => '8.2' );
    }
    public static function save( array $data ) {
        global $wpdb;
        $prior = get_option( 'getmcp_extensions_settings', false );
        $current = self::settings();
        if ( ! isset( $data['revision'] ) || $data['revision'] !== $current['revision'] ) { return new \WP_Error( 'extensions_conflict', 'Feature switches changed. Reload before saving.', array( 'status' => 409 ) ); }
        $flags = $data['features'] ?? array();
        if ( array_diff( array_keys( $flags ), array_keys( self::DEFAULTS ) ) || count( $flags ) !== 3 || count( array_filter( $flags, 'is_bool' ) ) !== 3 ) { return new \WP_Error( 'extensions_invalid', 'Supply all three boolean feature switches.', array( 'status' => 400 ) ); }
        if ( ! $flags['native_oauth'] && ! self::native_features()['native_oauth'] && ( $flags['remote_mcp'] || $flags['project_gateways'] ) ) { return new \WP_Error( 'extensions_dependency', 'Remote MCP and project gateways require native OAuth and user restrictions.', array( 'status' => 400 ) ); }
        $next = array( 'revision' => $current['revision'] + 1, 'features' => $flags );
        if ( false === $prior ) { $ok = add_option( 'getmcp_extensions_settings', $next, '', false ); }
        else { $ok = 1 === $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $next ) ), array( 'option_name' => 'getmcp_extensions_settings', 'option_value' => maybe_serialize( $prior ) ), array( '%s' ), array( '%s', '%s' ) ); wp_cache_delete( 'getmcp_extensions_settings', 'options' ); }
        if ( ! $ok ) { return new \WP_Error( 'extensions_conflict', 'Feature switches changed. Reload before saving.', array( 'status' => 409 ) ); }
        do_action( 'getmcp_extensions_features_updated', $next, $current, get_current_user_id() );
        return self::status();
    }
    public static function routes(): void {
        register_rest_route( 'getmcp-extensions/v1', '/settings', array(
            array( 'methods' => 'GET', 'permission_callback' => fn() => current_user_can( 'manage_options' ), 'callback' => fn() => rest_ensure_response( self::status() ) ),
            array( 'methods' => 'PUT', 'permission_callback' => fn() => current_user_can( 'manage_options' ), 'callback' => fn( $r ) => self::save( $r->get_json_params() ?: array() ) ),
        ) );
    }
    public static function rest_guard( $result, $service, $request ) {
        $route = $request->get_route();
        if ( ! self::enabled( 'native_oauth' ) && ( str_contains( $route, '/oauth-users' ) || preg_match( '#^/getmcp/v1/my-connections#', $route ) ) ) { return new \WP_Error( 'extensions_disabled', 'Native OAuth/user access is disabled.', array( 'status' => 503 ) ); }
        if ( ! self::enabled( 'remote_mcp' ) && preg_match( '#^/getmcp/v1/upstreams#', $route ) ) { return new \WP_Error( 'extensions_disabled', 'Remote MCP is disabled.', array( 'status' => 503 ) ); }
        return $result;
    }
    public static function menu(): void {
        $hook = add_submenu_page( 'getmcp', 'GetMCP Extensions', 'Extensions', 'manage_options', 'getmcp-extensions', array( self::class, 'render' ) );
        if ( $hook && self::$ready ) { $admin = new \GetMCP\Admin\Admin(); add_action( 'load-' . $hook, array( $admin, 'on_page_load' ) ); }
    }
    public static function render(): void {
        if ( ! self::$ready ) { echo '<div class="wrap"><h1>GetMCP Extensions</h1><p>Compatibility review required. Affected MCP endpoints are blocked; configuration and credentials are preserved.</p><pre>' . esc_html( implode( "\n", self::$errors ) ) . '</pre></div>'; return; }
        echo '<div class="getmcp-wrap"><div id="getmcp-admin-root"></div></div><script>if(!location.hash)location.hash="/extensions";</script>';
    }
    public static function assets(): void {
        if ( ! wp_script_is( 'getmcp-admin', 'enqueued' ) ) { return; }
        wp_enqueue_script( 'getmcp-extensions-settings', GETMCP_EXTENSIONS_URL . 'admin/extensions-settings.js', array( 'wp-element' ), GETMCP_EXTENSIONS_VERSION, true );
        wp_enqueue_script( 'getmcp-authentication-settings', GETMCP_EXTENSIONS_URL . 'admin/authentication-settings.js', array( 'wp-element', 'wp-api-fetch', 'getmcp-oauth-user-picker' ), GETMCP_EXTENSIONS_VERSION, true );
        wp_enqueue_script( 'getmcp-connection-tools', GETMCP_EXTENSIONS_URL . 'admin/connection-tools.js', array( 'wp-element' ), GETMCP_EXTENSIONS_VERSION, true );
        wp_add_inline_script( 'getmcp-admin', 'window.getmcpMarketingTemplates=' . wp_json_encode( MarketingTemplates::client_catalogue() ) . ';', 'before' );
        // Add-on settings page also works alongside an already integrated snapshot.
        wp_add_inline_script( 'getmcp-admin', 'window.getmcpExtensions=' . wp_json_encode( self::status() + array( 'connectionsUrl' => admin_url( 'admin.php?page=getmcp-connections' ), 'assetUrl' => GETMCP_EXTENSIONS_URL, 'settingsUrl' => rest_url( 'getmcp-extensions/v1/settings' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ) . ';', 'before' );
        $scripts = wp_scripts();
        if ( isset( $scripts->registered['getmcp-admin'] ) ) { $scripts->registered['getmcp-admin']->deps[] = 'getmcp-extensions-settings'; $scripts->registered['getmcp-admin']->deps[] = 'getmcp-authentication-settings'; $scripts->registered['getmcp-admin']->deps[] = 'getmcp-connection-tools'; $scripts->registered['getmcp-admin']->src = GETMCP_EXTENSIONS_URL . 'build/index.js'; $scripts->registered['getmcp-admin']->ver = substr( hash_file( 'sha256', GETMCP_EXTENSIONS_PATH . 'build/index.js' ), 0, 12 );
            foreach ( array( 'getmcp-native-connections' => 'native-connections.js', 'getmcp-app-extension' => 'getmcp-app-extension.js', 'getmcp-oauth-user-picker' => 'oauth-user-picker.js' ) as $handle => $file ) { if ( isset( $scripts->registered[$handle] ) ) { $scripts->registered[$handle]->src = GETMCP_EXTENSIONS_URL . 'admin/' . $file; $scripts->registered[$handle]->ver = substr( hash_file( 'sha256', GETMCP_EXTENSIONS_PATH . 'admin/' . $file ), 0, 12 ); } } }
    }
    public static function notice(): void {
        if ( ! self::$ready && current_user_can( 'manage_options' ) ) { echo '<div class="notice notice-error"><p>GetMCP Extensions needs compatibility review. Its affected MCP endpoints are blocked. Open GetMCP â†’ Extensions for details.</p></div>'; }
    }
}
