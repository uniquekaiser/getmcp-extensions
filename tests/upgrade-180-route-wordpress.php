<?php
/** Protected REST and admin rendering against a temporary 1.8 fixture record. */
if ( wp_get_environment_type() !== 'local' || ! getenv( 'GETMCP_180_TEMPLATE' ) ) { throw new RuntimeException( 'Local fixture and explicit template are required.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$template_id = (int) getenv( 'GETMCP_180_TEMPLATE' );
$template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ), ARRAY_A );
if ( ! $template || 'qa-180-migration-direct' !== $template['slug'] ) { throw new RuntimeException( 'Unexpected synthetic template.' ); }
unset( $template['id'] );
$uuid = wp_generate_uuid4();
$template['uuid'] = $uuid; $template['server_id'] = substr( hash( 'sha256', $uuid ), 0, 16 ); $template['name'] = 'QA 180 REST migration'; $template['slug'] = 'qa-180-rest-' . substr( $uuid, 0, 8 );
$template['server_kind'] = 'native'; $template['kind'] = 'standard'; $template['auth_type'] = 'oauth';
$template['auth_config'] = wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) );
$template['settings'] = '{}'; $template['outbound_auth_credentials'] = null; $template['auth_credentials'] = null;
if ( 1 !== $wpdb->insert( $table, $template ) ) { throw new RuntimeException( 'Could not create the disposable REST fixture.' ); }
$id = (int) $wpdb->insert_id; $checks = 0;
$check = static function( bool $ok, string $name ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $name ); } };
try {
    $path = '/getmcp-extensions/v1/upgrade-180/' . $id;
    wp_set_current_user( 0 );
    $response = rest_do_request( new WP_REST_Request( 'POST', $path ) );
    $check( in_array( $response->get_status(), array( 401, 403 ), true ), 'anonymous direct POST refused' );
    wp_set_current_user( 1 );
    $malformed = new WP_REST_Request( 'POST', $path );
    $malformed->set_header( 'Content-Type', 'application/json' );
    $malformed->set_body( wp_json_encode( array( 'revision' => array( 'invalid' ) ) ) );
    $check( 400 === rest_do_request( $malformed )->get_status(), 'malformed revision is refused' );
    $response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
    $plan = $response->get_data();
    $check( 200 === $response->get_status() && 'eligible' === $plan['state'], 'administrator receives eligible review' );
    $request = new WP_REST_Request( 'POST', $path );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array( 'revision' => $plan['revision'] ) ) );
    $response = rest_do_request( $request );
    $check( 200 === $response->get_status() && 'migrated' === $response->get_data()['state'], 'reviewed REST migration succeeds' );
    $server = ( new \GetMCP\Core\ServerManager() )->get( $id );
    $check( 'wordpress' === $server->auth_type && ! \GetMCP\Auth\ServerUserAccess::can_user_access( 1, $server ), 'empty allowlist still denies the administrator' );
    $check( 409 === rest_do_request( $request )->get_status(), 'repeated REST POST is rejected without another write' );
    ob_start(); \GetMCPExtensions\Runtime::render(); $html = ob_get_clean();
    $check( str_contains( $html, 'getmcp-upgrade-review' ) && str_contains( $html, 'empty standalone' ), 'compatibility screen explains the limited migration' );
    \GetMCPExtensions\Runtime::upgrade_assets( 'getmcp_page_getmcp-extensions' );
    $check( wp_script_is( 'getmcp-extensions-upgrade-180', 'enqueued' ), 'compatibility screen enqueues its migration controls' );
    echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic REST and admin rendering' ) ) . PHP_EOL;
} finally {
    $wpdb->delete( $table, array( 'id' => $id, 'uuid' => $uuid ) );
}
