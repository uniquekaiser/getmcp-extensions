<?php
/** Secret-free client setup metadata; all endpoints remain protected by existing access rules. */
namespace GetMCPExtensions;
use GetMCP\Core\ServerManager;
use GetMCP\Auth\FirstPartyOAuth;

final class ConnectionScreens {
    public static function boot(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ), 45 );
    }
    public static function routes(): void {
        register_rest_route( 'getmcp/v1', '/connection-config/(?P<id>[a-f0-9-]+)', array(
            'methods' => 'GET', 'permission_callback' => static fn() => is_user_logged_in() && current_user_can( 'read' ),
            'callback' => static function( $r ) {
                $m = new ServerManager(); $id = $r['id'];
                $s = ctype_digit( $id ) ? $m->get( (int) $id ) : $m->get_by_uuid( $id );
                if ( ! $s || ! Runtime::server_enabled( $s ) || ( ! current_user_can( 'getmcp_manage_servers' ) && ( 'active' !== $s->status || ! FirstPartyOAuth::can_user_access( get_current_user_id(), $s ) ) ) ) {
                    return new \WP_Error( 'connection_forbidden', 'This connection is unavailable.', array( 'status' => 403 ) );
                }
                // Never return upstream URLs, headers, secrets, tokens or user credentials.
                $config = AuthenticationSettings::object( $s->auth_config );
                return rest_ensure_response( array( 'name' => $s->name, 'slug' => $s->slug, 'url' => $s->get_endpoint_url(), 'auth_type' => $s->auth_type, 'auth_header' => 'api-key' === $s->auth_type ? ( $config['name'] ?? 'X-API-Key' ) : 'Authorization', 'auth_location' => 'api-key' === $s->auth_type ? ( $config['location'] ?? 'header' ) : 'header' ) );
            },
        ) );
        register_rest_route( 'getmcp/v1', '/connections/transcode', array(
            'methods' => 'POST', 'permission_callback' => static fn() => current_user_can( 'getmcp_manage_servers' ),
            'callback' => static fn( $r ) => MarketingModule::response( static fn() => ConfigurationTranscoder::convert( (string) $r->get_param( 'document' ), (string) $r->get_param( 'format' ) ) ),
        ) );
    }
}
