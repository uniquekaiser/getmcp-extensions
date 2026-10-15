<?php
/** Reference metadata only. Connections use the existing gateway management service.
 * @wp-test feature=remote.catalogue scenario=catalogue-permission-refusal
 * @wp-test feature=remote.catalogue scenario=provider-oauth-readiness
 */
namespace GetMCPExtensions;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class RemoteCatalogue {
    public static function boot(): void {
        add_action( 'admin_menu', array( self::class, 'menu' ), 35 );
        add_action( 'rest_api_init', static function(): void {
            $permission = static fn() => Runtime::enabled( 'remote_mcp' ) && current_user_can( 'getmcp_manage_servers' );
            register_rest_route( 'getmcp/v1', '/remote-catalogue', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static fn( $r ) => rest_ensure_response( self::search( (string) $r->get_param( 'q' ), (string) $r->get_param( 'category' ), max( 1, (int) $r->get_param( 'page' ) ) ) ) ) );
            register_rest_route( 'getmcp/v1', '/remote-catalogue/(?P<slug>[a-z0-9-]+)', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function( $r ) { $entry = self::get( $r['slug'] ); return $entry ? rest_ensure_response( $entry + array( 'draft' => self::draft( $entry ) ) ) : new \WP_Error( 'catalogue_not_found', 'Remote catalogue entry not found.', array( 'status' => 404 ) ); } ) );
        } );
    }
    public static function menu(): void {
        if ( ! Runtime::enabled( 'remote_mcp' ) ) { return; }
        $admin = new \GetMCP\Admin\Admin();
        $hook = add_submenu_page( 'getmcp', 'Remote MCP Servers', 'Remote MCP Servers', 'getmcp_manage_servers', 'getmcp-remote-servers', static function(): void {
            echo '<div class="getmcp-wrap"><div id="getmcp-admin-root"></div></div><script>if(!location.hash||location.hash==="#/connections")location.hash="/remote-servers";</script>';
        } );
        if ( $hook ) { add_action( 'load-' . $hook, array( $admin, 'on_page_load' ) ); }
    }
    public static function data(): array {
        static $data;
        if ( null === $data ) { $data = json_decode( file_get_contents( GETMCP_EXTENSIONS_PATH . 'assets/remote-catalogue.json' ), true, 32, JSON_THROW_ON_ERROR ); }
        return $data;
    }
    public static function get( string $id ): ?array {
        foreach ( self::data()['items'] as $entry ) { if ( $entry['id'] === $id ) { return $entry; } }
        return null;
    }
    public static function search( string $query = '', string $category = '', int $page = 1 ): array {
        $data = self::data(); $query = strtolower( substr( trim( $query ), 0, 200 ) );
        $items = array_values( array_filter( $data['items'], static fn( $e ) => ( '' === $category || $category === $e['category'] ) && ( '' === $query || str_contains( strtolower( $e['name'] . ' ' . $e['description'] . ' ' . $e['category'] . ' ' . $e['auth'] ), $query ) ) ) );
        $page = max( 1, min( 1000, $page ) );
        return array( 'items' => array_slice( $items, ( $page - 1 ) * 12, 12 ), 'total' => count( $items ), 'page' => $page, 'has_more' => $page * 12 < count( $items ), 'categories' => array_values( array_unique( array_column( $data['items'], 'category' ) ) ), 'reference_checked' => $data['reference_checked'], 'notice' => $data['verification_notice'] );
    }
    /** Only public configuration; no server/tool writes, HTTP requests or access grants. */
    public static function draft( array $entry ): array {
        $user = get_current_user_id();
        return array( 'kind' => 'remote-mcp', 'name' => $entry['name'], 'slug' => '', 'status' => 'active', 'allowed_user_ids' => $user > 0 ? array( $user ) : array(), 'server_ids' => array(), 'remote' => array( 'endpoint' => $entry['endpoint'], 'auth_mode' => array( 'oauth' => 'oauth', 'bearer' => 'shared', 'none' => 'none' )[$entry['auth']], 'publish_original' => false ) );
    }
}
