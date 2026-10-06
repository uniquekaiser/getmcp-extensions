<?php
/** Run only in the owned disposable WordPress fixture after feature fixtures. */
wp_set_current_user( 1 );
$manager = new \GetMCP\Core\ServerManager();
$all = $manager->list( array( 'per_page' => 500 ) );
$native = array_values( array_filter( $all['items'], fn( $server ) => 'native' === $server->server_kind ) );
$request = new WP_REST_Request( 'GET', '/getmcp/v1/servers' );
$request->set_param( 'per_page', 1 );
$response = rest_do_request( $request );
$checks = array(
    'fixture_contains_both_kinds' => count( $all['items'] ) > count( $native ) && count( $native ) > 0,
    'legacy_list_native_only_with_correct_pagination' => 200 === $response->get_status() && count( $response->get_data() ) === 1 && (int) $response->get_headers()['X-WP-Total'] === count( $native ),
    'manager_unfiltered_list_preserves_feature_records' => $manager->list( array( 'server_kind' => 'native', 'per_page' => 500 ) )['total'] === count( $native ) && $all['total'] > count( $native ),
);
foreach ( $checks as $name => $passed ) { if ( ! $passed ) { throw new RuntimeException( $name ); } }
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT );
