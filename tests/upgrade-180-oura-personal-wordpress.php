<?php
/** Disposable account Gateway and personal-provider authorization regression. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager();
$user = get_userdata( 1 ); $slug = 'qa-personal-' . substr( wp_generate_uuid4(), 0, 8 );
$server = $manager->create( array( 'name' => 'QA Oura personal account gateway', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://cloud.ouraring.com/oauth/authorize', 'token_url' => 'https://api.ouraring.com/oauth/token', 'client_id' => 'synthetic-client' ) ), 'settings' => wp_json_encode( array( 'personal_provider' => array( 'allow_all_users' => true, 'allowed_user_ids' => array() ) ) ) ) );
if ( ! $server || ! $user ) { throw new RuntimeException( 'Fixture creation failed.' ); }
$options = array(); foreach ( array( 'getmcp_gateway_enabled', 'getmcp_gateway_mode', 'getmcp_gateway_auth_type', 'getmcp_gateway_servers' ) as $key ) { $options[$key] = get_option( $key, false ); }
$rowprop = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $oldrow = $rowprop->getValue(); $olduser = get_current_user_id(); $oldauth = $_SERVER['HTTP_AUTHORIZATION'] ?? null; $tool_id = 0; $checks = 0; $calls = 0; $expected_token = 'synthetic-personal'; $second_user = 0;
$check = static function( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$http = static function( $pre, $args, $url ) use ( &$calls, &$expected_token ) { if ( 'https://api.ouraring.com/qa-personal' !== $url ) { return $pre; } ++$calls; if ( 'Bearer ' . $expected_token !== ( $args['headers']['Authorization'] ?? '' ) ) { throw new RuntimeException( 'Personal credential was not isolated.' ); } return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"health_marker":"private-oura-payload"}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ); };
add_filter( 'pre_http_request', $http, 1, 3 );
$observed = array(); $response_filter_called = false;
$after = static function( $tool, $args, $result, $ms, $status, $extra ) use ( &$observed ) { $observed = array( $args, $result, $extra ); };
$response_filter = static function( $result ) use ( &$response_filter_called ) { $response_filter_called = true; return $result; };
add_action( 'getmcp_after_tool_call', $after, 100, 6 ); add_filter( 'getmcp_tool_response', $response_filter, 100 );
try {
 $tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA personal read', 'slug' => 'qa_personal_read', 'endpoint_url' => 'https://api.ouraring.com/qa-personal', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'cache_ttl' => 3600, 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) ); if ( ! $tool ) { throw new RuntimeException( 'Fixture tool creation failed.' ); } $tool_id = $tool->id;
 $configuration = \GetMCPExtensions\MarketingModule::configuration( $server->uuid );
 $saved = \GetMCPExtensions\MarketingModule::configure( $server->uuid, array( 'configuration_revision' => $configuration['configuration_revision'], 'personal_provider' => array( 'allowed_user_ids' => array(), 'allow_all_users' => true ) ) );
 $check( true === ( $saved['personal_provider']['allow_all_users'] ?? null ), 'The all-users policy must survive a revision-checked save.' );
 $refused = false; try { \GetMCPExtensions\MarketingModule::configure( $server->uuid, array( 'configuration_revision' => $saved['configuration_revision'], 'personal_provider' => array( 'allowed_user_ids' => array(), 'allow_all_users' => 'yes' ) ) ); } catch ( \InvalidArgumentException $e ) { $refused = true; } $check( $refused, 'Malformed all-users policies must be refused.' );
 $server = $manager->get( $server->id );
 \GetMCP\Remote\UpstreamConnections::save( $server->id, $user->ID, array( 'kind' => 'rest-provider', 'config_hash' => \GetMCPExtensions\ProviderConnections::fingerprint( $server ), 'access_token' => 'synthetic-personal', 'refresh_token' => 'synthetic-refresh', 'expires_at' => time() + 3600 ) );
 $listing = array_values( array_filter( \GetMCPExtensions\ProviderConnections::listing( $user->ID ), static fn( $item ) => (int) $item['id'] === (int) $server->id ) );
 $check( 1 === count( $listing ) && false === $listing[0]['supports_discovery'], 'The personal connection avoids an unsupported provider-discovery action.' );
 \GetMCP\Gateway\McpGateway::set_enabled( true ); \GetMCP\Gateway\McpGateway::set_auth_type( 'oauth' ); \GetMCP\Gateway\McpGateway::set_mode( 'selected' ); \GetMCP\Gateway\McpGateway::set_selected_server_ids( array( $server->id ) );
 $rowprop->setValue( (object) array( 'id' => 97009, 'user_id' => $user->ID, 'client_server_id' => 0, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) ); wp_set_current_user( $user->ID ); $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-downstream';
 $check( \GetMCPExtensions\ProviderConnections::can_connect( $server, $user->ID ), 'All-users policy should permit an eligible user to connect.' );
 $gateway = \GetMCP\Gateway\McpGateway::server(); $handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null ); $list = $handler->handle( 'tools/list', array() ); $name = ''; foreach ( $list['tools'] ?? array() as $item ) { if ( str_ends_with( $item['name'], 'qa_personal_read' ) ) { $name = $item['name']; } }
 $check( '' !== $name, 'The account Gateway must publish the eligible personal grant.' );
 $response = $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) ); $check( empty( $response['isError'] ) && 1 === $calls, 'Calls must use the personal grant exactly once.' );
 $check( str_contains( wp_json_encode( $response ), 'private-oura-payload' ), 'Authorized caller receives health data.' );
 $check( ! str_contains( wp_json_encode( $observed ), 'private-oura-payload' ) && empty( $observed[0] ) && empty( $observed[2]['replay_data'] ), 'Hooks and replay omit health data.' );
 $check( ! $response_filter_called, 'Third-party response filter does not receive health data.' );
 $cached_health = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_getmcp_resp_%' AND option_value LIKE '%private-oura-payload%'" );
 $check( 0 === (int) $cached_health, 'Health responses are never persisted in response transients.' );
 $refused = false; try { \GetMCPExtensions\ProviderExecution::inject( array( 'url' => 'https://example.org/steal', 'headers' => array() ), $server, $tool, array(), false ); } catch ( \Throwable $e ) { $refused = true; }
 $check( $refused, 'Direct calls never send Oura credentials to another host.' );
 $misconfigured = clone $server; $bad_settings = json_decode( $server->settings, true ); $bad_settings['personal_provider']['preset'] = 'google-analytics'; $misconfigured->settings = wp_json_encode( $bad_settings );
 $discovery_calls = 0; $discovery_http = static function( $pre, $args, $url ) use ( &$discovery_calls ) { if ( str_contains( $url, 'googleapis.com' ) ) { ++$discovery_calls; return new \WP_Error( 'blocked_fixture_request', 'Synthetic discovery blocked.' ); } return $pre; };
 add_filter( 'pre_http_request', $discovery_http, -1, 3 );
 $refused = false; try { \GetMCPExtensions\ProviderDiscovery::discover( $misconfigured, 'synthetic-personal', $user->ID ); } catch ( \Throwable $e ) { $refused = true; } finally { remove_filter( 'pre_http_request', $discovery_http, -1 ); }
 $check( $refused && 0 === $discovery_calls, 'A misleading preset cannot send the Oura token to a different provider.' );
 $second_user = wp_insert_user( array( 'user_login' => 'qa-personal-' . substr( wp_generate_uuid4(), 0, 8 ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ); if ( is_wp_error( $second_user ) ) { throw new RuntimeException( 'Could not create disposable user.' ); }
 $check( \GetMCPExtensions\ProviderConnections::can_connect( $server, $second_user ), 'New eligible users may connect their own account.' );
 $rowprop->setValue( (object) array( 'id' => 97010, 'user_id' => $second_user, 'client_server_id' => 0, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) ); wp_set_current_user( $second_user );
 $check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'A different user cannot borrow an existing grant.' );
 \GetMCP\Remote\UpstreamConnections::save( $server->id, $second_user, array( 'kind' => 'rest-provider', 'config_hash' => \GetMCPExtensions\ProviderConnections::fingerprint( $server ), 'access_token' => 'synthetic-second', 'refresh_token' => 'synthetic-refresh', 'expires_at' => time() + 3600 ) ); $expected_token = 'synthetic-second';
 $second_handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null ); $response = $second_handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) ); $check( empty( $response['isError'] ) && 2 === $calls, 'Second user uses their own grant.' );
 $table = $wpdb->prefix . 'getmcp_servers'; $settings = $server->settings; $revoked = json_decode( $settings, true ); $revoked['personal_provider']['allow_all_users'] = false; $wpdb->update( $table, array( 'settings' => wp_json_encode( $revoked ) ), array( 'id' => $server->id ) );
 $check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'Revoked policy takes effect immediately.' ); $wpdb->update( $table, array( 'settings' => $settings ), array( 'id' => $server->id ) );
 $wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'endpoint_url' => 'https://example.org/steal' ), array( 'id' => $tool_id ) ); $check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'Unreviewed endpoints cannot receive the grant.' ); $wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'endpoint_url' => 'https://api.ouraring.com/qa-personal' ), array( 'id' => $tool_id ) );
 $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => $second_user ) ); $check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'Removed grants stop access.' );
 $rowprop->setValue( (object) array( 'id' => 97009, 'user_id' => $user->ID, 'client_server_id' => 0, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) ); wp_set_current_user( $user->ID );
 \GetMCP\Gateway\McpGateway::set_selected_server_ids( array() ); $check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'Unselected providers stay unavailable.' );
 \GetMCP\Gateway\McpGateway::set_selected_server_ids( array( $server->id ) ); $rowprop->setValue( (object) array( 'user_id' => $user->ID, 'client_server_id' => 999, 'resource' => \GetMCP\Gateway\McpGateway::resource() ) ); $check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'Wrong client resource must be refused.' );
 $rowprop->setValue( (object) array( 'user_id' => $user->ID, 'client_server_id' => 0, 'resource' => 'https://example.org/mcp' ) ); $check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'Wrong resource must be refused.' );
 // Reserve an Oura refresh token before dispatch because it is single-use.
 $token_calls = 0; $mode = 'failure';
 $token_http = static function( $pre, $args, $url ) use ( &$token_calls, &$mode ) {
  if ( 'https://api.ouraring.com/oauth/token' !== $url ) { return $pre; }
  ++$token_calls;
  if ( 'failure' === $mode ) { return new \WP_Error( 'http_request_failed', 'synthetic timeout' ); }
  return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( array( 'access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600, 'token_type' => 'bearer' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
 };
 add_filter( 'pre_http_request', $token_http, 0, 3 );
 $prior = \GetMCP\Remote\UpstreamConnections::get( $server->id, $user->ID ); $data = $prior['data']; $data['expires_at'] = time() - 1;
 \GetMCP\Remote\UpstreamConnections::save( $server->id, $user->ID, $data, $prior['version'] );
 $refused = false; try { \GetMCPExtensions\ProviderConnections::grant( $server, $user->ID ); } catch ( \Throwable $e ) { $refused = true; }
 $reserved = \GetMCP\Remote\UpstreamConnections::get( $server->id, $user->ID );
 $check( $refused && 1 === $token_calls && ! empty( $reserved['data']['refresh_attempted'] ), 'Uncertain refresh reserves the one-use token.' );
 try { \GetMCPExtensions\ProviderConnections::grant( $server, $user->ID ); } catch ( \Throwable $e ) {}
 $check( 1 === $token_calls && ! \GetMCPExtensions\ProviderConnections::refresh_available( $server, $reserved['data'] ), 'An uncertain refresh is never retried.' );
 $data = $reserved['data']; unset( $data['refresh_attempted'] ); $data['refresh_token'] = 'newly-reconnected';
 \GetMCP\Remote\UpstreamConnections::save( $server->id, $user->ID, $data, $reserved['version'] ); $mode = 'success';
 $rotated = \GetMCPExtensions\ProviderConnections::grant( $server, $user->ID );
 $check( 2 === $token_calls && 'rotated-refresh' === $rotated['data']['refresh_token'] && empty( $rotated['data']['refresh_attempted'] ), 'Successful refresh stores the new single-use token.' );
 remove_filter( 'pre_http_request', $token_http, 0 );
 echo wp_json_encode( array( 'passed' => $checks ) ) . PHP_EOL;
} finally {
 remove_action( 'getmcp_after_tool_call', $after, 100 ); remove_filter( 'getmcp_tool_response', $response_filter, 100 ); remove_filter( 'pre_http_request', $http, 1 ); $rowprop->setValue( $oldrow ); wp_set_current_user( $olduser ); if ( null === $oldauth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $oldauth; }
 foreach ( $options as $key => $value ) { if ( false === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
 $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => $user->ID ) ); if ( $second_user && ! is_wp_error( $second_user ) ) { $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => $second_user ) ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $second_user ); } if ( $tool_id ) { $tools->delete( $tool_id ); } $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); }
}
