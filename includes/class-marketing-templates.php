<?php
/** Adds owned templates after vendor permission/licensing checks, without replacing vendor routes. */
namespace GetMCPExtensions;
use GetMCP\Core\ServerManager;
use GetMCP\Core\ToolManager;
use GetMCP\Licensing\LicenseTier;

final class MarketingTemplates {
    private const SLUGS = array( 'google-ads', 'google-analytics', 'google-search-console', 'meta-ads', 'instagram' );
    public static function boot(): void { add_filter( 'rest_request_after_callbacks', array( self::class, 'response' ), 20, 3 ); }
    /** Metadata for the vendor's static template UI, without credentials or tool execution data. */
    public static function client_catalogue(): array {
        if ( ! MarketingModule::enabled() ) { return array(); }
        $out = array();
        foreach ( self::SLUGS as $slug ) {
            $t = self::template( $slug );
            $out[] = array( 'slug' => $t['slug'], 'name' => $t['name'], 'description' => $t['description'], 'category' => $t['category'], 'icon' => $t['icon'], 'authLabel' => 'Configure OAuth application after draft installation', 'authType' => 'none', 'website' => $t['website'], 'tools' => array_map( static fn( $tool ) => array_intersect_key( $tool, array_flip( array( 'name', 'description' ) ) ), $t['tools'] ) );
        }
        return $out;
    }
    public static function template( string $slug ): array {
        if ( ! in_array( $slug, self::SLUGS, true ) ) { throw new \InvalidArgumentException( 'Unknown marketing template.' ); }
        $d = json_decode( file_get_contents( GETMCP_EXTENSIONS_PATH . 'assets/templates/' . $slug . '.json' ), true, 32, JSON_THROW_ON_ERROR );
        return array( 'slug' => 'synergetic-' . $slug, 'name' => $d['server']['name'] . ' · Synergetic', 'description' => 'Marketing integration. Install into an empty server; configure application credentials, personal access and provider verification before publishing.', 'category' => 'Marketing', 'icon' => 'Megaphone', 'auth_type' => 'oauth', 'website' => 'https://synergetic.dev/', 'tags' => array( 'marketing', 'synergetic' ), 'tool_count' => count( $d['tools'] ), 'tools' => $d['tools'] );
    }
    public static function response( $response, $handler, $request ) {
        if ( ! MarketingModule::enabled() || ! current_user_can( 'manage_options' ) ) { return $response; }
        $route = $request->get_route();
        if ( '/getmcp/v1/templates' === $route && 'GET' === $request->get_method() && $response instanceof \WP_REST_Response && 200 === $response->get_status() ) {
            $items = $response->get_data(); $known = array_column( $items, 'slug' );
            foreach ( self::SLUGS as $slug ) { $t = self::template( $slug ); unset( $t['tools'] ); if ( ! in_array( $t['slug'], $known, true ) ) { $items[] = $t; } }
            $response->set_data( $items ); return $response;
        }
        // Only fill a missing owned template. Preserve all vendor permission/tier failures.
        if ( ! is_wp_error( $response ) || 'template_not_found' !== $response->get_error_code() || ! preg_match( '~^/getmcp/v1/templates/synergetic-([a-z-]+)(/install)?$~D', $route, $match ) || ! in_array( $match[1], self::SLUGS, true ) ) { return $response; }
        if ( 'GET' === $request->get_method() && empty( $match[2] ) ) { return rest_ensure_response( self::template( $match[1] ) ); }
        if ( 'POST' === $request->get_method() && ! empty( $match[2] ) ) {
            try { return self::install( $match[1], (string) $request->get_param( 'server_id' ), $request ); }
            catch ( \Throwable $e ) { return new \WP_Error( 'marketing_template_rejected', 'Use an empty native server without credentials, tools or custom settings. Configure and verify the integration before activation.', array( 'status' => 400 ) ); }
        }
        return $response;
    }
    public static function install( string $slug, string $uuid, $request ): \WP_REST_Response {
        global $wpdb;
        if ( ! MarketingModule::enabled() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'getmcp_manage_servers' ) || ! current_user_can( 'getmcp_manage_tools' ) || ! LicenseTier::current()->allows_templates() ) { throw new \InvalidArgumentException( 'Template installation is unavailable.' ); }
        self::template( $slug ); // Validate the fixed asset path before loading it.
        $sm = new ServerManager(); $tm = new ToolManager();
        $wpdb->query( 'START TRANSACTION' );
        try {
            // Serialize concurrent installers before checking the target is still empty.
            $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_servers WHERE uuid=%s FOR UPDATE", $uuid ) );
            $s = $id ? $sm->get( (int) $id ) : false;
            $prior_settings = $s ? AuthenticationSettings::object( $s->settings ) : array();
            // Native UI creates a presentation-only description inside settings.
            // Preserve it; all other existing settings and capabilities block installation.
            $configuration = array_diff_key( $prior_settings, array( 'description' => true ) );
            if ( ! $s || 'native' !== ( $s->server_kind ?? 'native' ) || 'none' !== $s->auth_type || $s->auth_credentials || $s->test_auth_credentials || $s->outbound_auth_credentials || ! in_array( $s->outbound_auth_type, array( null, '', 'none' ), true ) || array_filter( AuthenticationSettings::object( $s->auth_config ) ) || array_filter( AuthenticationSettings::object( $s->outbound_auth_config ) ) || $configuration || ( isset( $prior_settings['description'] ) && ! is_string( $prior_settings['description'] ) ) || $tm->get_by_server( $s->id )['total'] || ( new \GetMCP\Core\ResourceManager() )->get_by_server( $s->id )['total'] || ( new \GetMCP\Core\PromptManager() )->get_by_server( $s->id )['total'] || $request->get_param( 'credential' ) || $request->get_param( 'variables' ) ) { throw new \InvalidArgumentException( 'Target is not empty.' ); }
            $d = json_decode( file_get_contents( GETMCP_EXTENSIONS_PATH . 'assets/templates/' . $slug . '.json' ), true, 32, JSON_THROW_ON_ERROR );
            $settings = array_replace( $prior_settings, $d['server']['settings'] );
            $settings['personal_provider'] = array( 'enabled' => true, 'preset' => 'instagram' === $slug ? 'instagram-facebook-login' : $slug, 'allowed_user_ids' => array() );
            $settings['getmcp_extensions_required'] = true;
            $settings['instructions'] .= "\nInstalled by GetMCP Extensions. Configure application credentials and permitted personal users; verify harmless reads before activation. Instagram Page tokens are selected per user in My MCP Connections. Messaging stays inactive until verified.";
            $created = array();
            foreach ( $d['tools'] as $data ) {
                if ( ! LicenseTier::current()->can_create_tool( count( $created ) ) ) { throw new \InvalidArgumentException( 'Tool quota exceeded.' ); }
                $data['server_id'] = $s->id; unset( $data['id'], $data['uuid'] );
                $t = $tm->create( $data ); if ( ! $t ) { throw new \RuntimeException( 'Tool creation failed.' ); } $created[] = $t->id;
            }
            if ( ! $sm->update( $s->id, array( 'status' => 'draft', 'auth_type' => 'oauth', 'auth_config' => $d['server']['auth_config'], 'settings' => wp_json_encode( $settings ) ) ) ) { throw new \RuntimeException( 'Configuration failed.' ); }
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
        do_action( 'getmcp_template_installed', 'synergetic-' . $slug, $s->id, $created );
        return new \WP_REST_Response( array( 'success' => true, 'template' => 'synergetic-' . $slug, 'server_id' => $uuid, 'tools_created' => count( $created ), 'failed_tools' => array(), 'pending_variables' => array(), '_notice' => 'Installed as draft. Configure OAuth application credentials and personal user access, then verify harmless reads before publishing. Messaging tools remain inactive.' ), 201 );
    }
}
