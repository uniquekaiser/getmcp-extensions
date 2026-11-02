<?php
/** Public REST upstreams retain only explicitly granted Gateway access. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $old_user = get_current_user_id(); wp_set_current_user( 1 );
$suffix = substr( wp_generate_uuid4(), 0, 8 ); $server = null; $tool = null; $gateway = null; $second_user = 0; $checks = 0; $calls = 0;
$options = array(); foreach ( array( 'getmcp_gateway_enabled', 'getmcp_gateway_auth_type', 'getmcp_gateway_mode', 'getmcp_gateway_servers' ) as $key ) { $options[$key] = get_option( $key, null ); }
$rowprop = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $rowprop->getValue(); $old_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$check = static function( $ok, $message ) use ( &$checks ) { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$http = static function( $pre, $args, $url ) use ( &$calls ) { if ( 'https://example.org/qa-public' !== $url ) { return $pre; } ++$calls; foreach ( array_keys( $args['headers'] ?? array() ) as $key ) { if ( preg_match( '/authorization|cookie|api.?key/i', $key ) ) { throw new RuntimeException( 'Gateway credentials reached a public upstream.' ); } } return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"public":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ); };
add_filter( 'pre_http_request', $http, 1, 3 );
try {
 $server = $manager->create( array( 'name' => 'QA public upstream', 'slug' => 'qa-public-' . $suffix, 'status' => 'active', 'auth_type' => 'api-key', 'settings' => '{}' ) );
 $tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA public read', 'slug' => 'qa_public_read', 'status' => 'active', 'tool_type' => 'http', 'http_method' => 'GET', 'endpoint_url' => 'https://example.org/qa-public', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
 $g = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA public gateway ' . $suffix, 'server_ids' => array( $server->id ), 'allowed_user_ids' => array( 1 ) ) ); $gateway = $manager->get( $g['id'] );
 \GetMCP\Gateway\McpGateway::set_enabled( true ); \GetMCP\Gateway\McpGateway::set_auth_type( 'oauth' ); \GetMCP\Gateway\McpGateway::set_mode( 'selected' ); \GetMCP\Gateway\McpGateway::set_selected_server_ids( array( $server->id ) );
 $rowprop->setValue( (object) array( 'id' => 97070, 'user_id' => 1, 'client_server_id' => 0, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) ); $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer gateway-secret';
 $account = \GetMCP\Gateway\McpGateway::server();
 $ids = static fn() => array_map( static fn( $item ) => (int) $item->id, \GetMCP\Gateway\ProjectGateway::members( $account ) );
 $check( ! in_array( $server->id, $ids(), true ), 'Public upstream requires an explicit opt-in.' );
 $revision = \GetMCPExtensions\PublicGateway180::revision( $server );
 \GetMCPExtensions\PublicGateway180::configure( $server->id, 'https://example.org', $revision );
 $check( in_array( $server->id, $ids(), true ), 'A user explicitly allowed by an active project gateway can use the public member.' );
 $handler = new \GetMCP\Gateway\FeatureHandler( $account, null ); $list = $handler->handle( 'tools/list', array() ); $name = '';
 foreach ( $list['tools'] ?? array() as $entry ) { if ( str_ends_with( $entry['name'], 'qa_public_read' ) ) { $name = $entry['name']; } }
 $result = $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) );
 $check( '' !== $name && empty( $result['isError'] ) && 1 === $calls, 'Account Gateway makes a credential-free public request.' );
 $rowprop->setValue( (object) array( 'id' => 97072, 'user_id' => 1, 'client_server_id' => $gateway->id, 'resource' => $gateway->get_endpoint_url() ) );
 $project_handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null ); $project_list = $project_handler->handle( 'tools/list', array() ); $project_name = '';
 foreach ( $project_list['tools'] ?? array() as $entry ) { if ( str_ends_with( $entry['name'], 'qa_public_read' ) ) { $project_name = $entry['name']; } }
 $project_result = $project_handler->handle( 'tools/call', array( 'name' => $project_name, 'arguments' => array() ) );
 $check( '' !== $project_name && empty( $project_result['isError'] ) && 2 === $calls, 'The existing project Gateway also routes the public request without its credential.' );
 $rowprop->setValue( (object) array( 'id' => 97070, 'user_id' => 1, 'client_server_id' => 0, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) );
 $stale_refused = false; try { \GetMCPExtensions\PublicGateway180::configure( $server->id, 'https://example.org', $revision ); } catch ( \Throwable $e ) { $stale_refused = true; }
 $check( $stale_refused, 'Stale configuration saves are refused.' );
 $second_user = wp_insert_user( array( 'user_login' => 'qa-public-' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
 if ( is_wp_error( $second_user ) ) { throw new RuntimeException( 'Cannot create fixture user.' ); }
 $rowprop->setValue( (object) array( 'id' => 97071, 'user_id' => $second_user, 'client_server_id' => 0, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) ); wp_set_current_user( $second_user );
 $check( ! in_array( $server->id, $ids(), true ), 'An ungranted user cannot use another user’s project permission.' );
 $request = new \WP_REST_Request( 'PUT', '/getmcp-extensions/v1/servers/' . $server->id . '/public-upstream' ); $request->set_body_params( array( 'origin' => '', 'revision' => \GetMCPExtensions\PublicGateway180::revision( $manager->get( $server->id ) ) ) );
 $denied = rest_do_request( $request ); $check( 403 === $denied->get_status(), 'Users without management capabilities cannot change the policy.' );
 $rowprop->setValue( (object) array( 'id' => 97070, 'user_id' => 1, 'client_server_id' => 0, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) ); wp_set_current_user( 1 );
 $wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'endpoint_url' => 'https://other.example/steal' ), array( 'id' => $tool->id ) );
 $check( ! in_array( $server->id, $ids(), true ), 'A changed origin fails closed.' );
 $wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'endpoint_url' => 'https://example.org/qa-public', 'http_method' => 'POST' ), array( 'id' => $tool->id ) );
 $check( ! in_array( $server->id, $ids(), true ), 'A write tool fails closed.' );
 $current = $manager->get( $server->id ); \GetMCPExtensions\PublicGateway180::configure( $server->id, '', \GetMCPExtensions\PublicGateway180::revision( $current ) );
 $check( ! in_array( $server->id, $ids(), true ), 'An administrator can disable the policy even after a tool changes.' );
 echo wp_json_encode( array( 'passed' => $checks ) ) . PHP_EOL;
} finally {
 remove_filter( 'pre_http_request', $http, 1 ); $rowprop->setValue( $old_row ); wp_set_current_user( $old_user );
 if ( null === $old_auth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $old_auth; }
 foreach ( $options as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
 if ( $tool ) { $tools->delete( $tool->id ); } if ( $gateway ) { $manager->delete( $gateway->id ); } if ( $server ) { $manager->delete( $server->id ); }
 if ( $second_user && ! is_wp_error( $second_user ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $second_user ); }
}
