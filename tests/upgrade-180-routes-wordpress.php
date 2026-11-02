<?php
/** 1.8 local-only REST coexistence for legacy connection and metadata routes. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/auth/class-oauth-user-access.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-manager.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-module.php';
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$remote_id = (int) ( $state['remote'] ?? 0 );
$gateway_id = (int) ( $state['gateway'] ?? 0 );
$remote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", $remote_id ), ARRAY_A );
$gateway = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic gateway'", $gateway_id ), ARRAY_A );
if ( ! $remote || ! $gateway || 'wordpress' !== $remote['auth_type'] || 'wordpress' !== $gateway['auth_type'] ) { throw new RuntimeException( 'Converted synthetic servers required.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$native_id = 0; $test_users = array();
try {
	foreach ( array( 'allowed', 'denied' ) as $suffix ) {
		$login = 'qa180-' . $suffix . '-' . substr( wp_generate_uuid4(), 0, 8 );
		$user = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		if ( is_wp_error( $user ) ) { throw new RuntimeException( 'Could not create synthetic connection user.' ); }
		$test_users[$suffix] = array( 'id' => (int) $user, 'login' => $login );
	}
	$auth = json_decode( $gateway['auth_config'], true ); $auth['allowed_user_ids'] = array( 1, $test_users['allowed']['id'] );
	$settings = json_decode( $gateway['settings'], true ); $settings['gateway']['server_ids'] = array( $remote_id );
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $auth ), 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway_id ) );
	$native = $remote;
	unset( $native['id'] );
	$native['uuid'] = wp_generate_uuid4();
	$native['server_id'] = bin2hex( random_bytes( 8 ) );
	$native['slug'] = 'qa-180-native-metadata';
	$native['name'] = 'QA 180 native metadata';
	$native['server_kind'] = 'native';
	$native['kind'] = 'remote';
	$native['settings'] = wp_json_encode( array( 'remote' => array( 'url' => 'https://example.org/native', 'auth' => 'oauth' ) ) );
	$check( 1 === $wpdb->insert( $table, $native ), 'Synthetic native remote created.' );
	$native_id = (int) $wpdb->insert_id;
	$rest = rest_get_server();
	$unknown_path = '/getmcp/v1/upstreams/00000000-0000-0000-0000-000000000000/client-metadata';
	$native_unknown = rest_do_request( new WP_REST_Request( 'GET', $unknown_path ) )->get_status();
	$native_path = '/getmcp/v1/upstreams/' . $native['uuid'] . '/client-metadata';
	$native_before = rest_do_request( new WP_REST_Request( 'GET', $native_path ) );
	$check( 200 === $native_before->get_status() && 'GetMCP' === ( $native_before->get_data()['client_name'] ?? '' ), 'Native remote metadata works before extension registration.' );
	\GetMCP\Gateway\FeatureModule::register();
	\GetMCP\Gateway\FeatureModule::routes();
	\GetMCPExtensions\ConnectionScreens::routes();
	$metadata = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/upstreams/' . $remote['uuid'] . '/client-metadata' ) );
	$check( 200 === $metadata->get_status() && 'GetMCP Bridge' === ( $metadata->get_data()['client_name'] ?? '' ), 'Legacy client metadata remains available at its original URL.' );
	$check( $native_unknown === rest_do_request( new WP_REST_Request( 'GET', $unknown_path ) )->get_status(), 'Native metadata route retains its unknown-server behavior.' );
	$native_after = rest_do_request( new WP_REST_Request( 'GET', $native_path ) );
	$check( 200 === $native_after->get_status() && $native_before->get_data() === $native_after->get_data(), 'Native remote metadata remains unchanged.' );
	wp_set_current_user( 0 );
	$anonymous = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/my-connections' ) );
	$check( in_array( $anonymous->get_status(), array( 401, 403 ), true ), 'Anonymous connection listing is refused.' );
	wp_set_current_user( 1 );
	$connections = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/my-connections' ) );
	$data = $connections->get_data();
	$items = $data['connections'] ?? $data;
	$matches = array_values( array_filter( is_array( $items ) ? $items : array(), static fn( $item ) => is_array( $item ) && ( $item['id'] ?? 0 ) === $remote_id ) );
	$check( 200 === $connections->get_status() && count( $matches ) === 1 && ! empty( $matches[0]['connected'] ), 'Gateway user sees their existing personal connection.' );
	$management = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/connections/' . $remote_id ) );
	$check( 200 === $management->get_status() && 'remote-mcp' === ( $management->get_data()['kind'] ?? '' ), 'Authorized legacy management remains available.' );
	wp_set_current_user( $test_users['allowed']['id'] );
	$setup = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/connection-config/' . $gateway_id ) );
	$check( 200 === $setup->get_status() && 'wordpress' === ( $setup->get_data()['auth_type'] ?? '' ) && ! str_contains( wp_json_encode( $setup->get_data() ), 'synthetic-user-secret' ), 'Allowed subscriber receives secret-free connection setup.' );
	wp_set_current_user( $test_users['denied']['id'] );
	$setup = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/connection-config/' . $gateway_id ) );
	$check( 403 === $setup->get_status(), 'Unlisted subscriber cannot read connection setup.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic 1.8 REST route coexistence' ) ) . PHP_EOL;
} finally {
	if ( $native_id ) { $wpdb->delete( $table, array( 'id' => $native_id, 'slug' => 'qa-180-native-metadata' ) ); }
	$wpdb->update( $table, array( 'auth_config' => $gateway['auth_config'], 'settings' => $gateway['settings'], 'updated_at' => $gateway['updated_at'] ), array( 'id' => $gateway_id ) );
	if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
	foreach ( $test_users as $user ) { $live = get_userdata( $user['id'] ); if ( $live && $live->user_login === $user['login'] ) { wp_delete_user( $user['id'] ); } }
}
