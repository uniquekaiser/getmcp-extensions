<?php
/** Preserve personal REST-provider access through a converted legacy gateway. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$source = $wpdb->get_row( "SELECT * FROM {$table} WHERE slug IN ('qa-180-browser-gateway','qa-180-automatic-gateway') ORDER BY id LIMIT 1", ARRAY_A );
$user = get_user_by( 'login', 'qa_extensions_180_browser' ) ?: get_userdata( 1 );
if ( ! $source || ! $user ) { throw new RuntimeException( 'Disposable browser fixtures are required.' ); }
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager();
$slug = 'qa-180-provider-member-' . substr( wp_generate_uuid4(), 0, 8 );
$server = $manager->create( array( 'name' => 'QA 180 provider migration member', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'client_id' => 'synthetic-provider-client' ) ), 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'synthetic-provider-secret' ) ), 'settings' => wp_json_encode( array( 'personal_provider' => array( 'enabled' => true, 'allowed_user_ids' => array() ) ) ) ) );
if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic provider.' ); }
$gateway_id = 0; $tool_id = 0; $scope = array(); $checks = 0; $calls = 0; $previous_user = get_current_user_id();
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ); $saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$filter = static function () use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$http = static function( $pre, $args, $url ) use ( &$calls ) {
	if ( 'https://www.googleapis.com/qa-180-provider' !== $url ) { return $pre; }
	++$calls; if ( 'Bearer synthetic-provider-personal' !== ( $args['headers']['Authorization'] ?? '' ) ) { throw new RuntimeException( 'Wrong credential forwarded.' ); }
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"synthetic":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $http, 1, 3 );
try {
	$tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA provider read', 'slug' => 'qa_provider_read', 'endpoint_url' => 'https://www.googleapis.com/qa-180-provider', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic provider tool.' ); } $tool_id = $tool->id;
	\GetMCP\Remote\UpstreamConnections::save( $server->id, $user->ID, array( 'kind' => 'rest-provider', 'config_hash' => \GetMCPExtensions\ProviderConnections::fingerprint( $server ), 'access_token' => 'synthetic-provider-personal', 'refresh_token' => 'synthetic-provider-refresh', 'expires_at' => time() + 3600 ) );
	unset( $source['id'] ); $source['uuid'] = wp_generate_uuid4(); $source['server_id'] = substr( hash( 'sha256', $source['uuid'] ), 0, 16 );
	$source['name'] = 'QA 180 provider migration gateway'; $source['slug'] = 'qa-180-provider-gateway-' . substr( $source['uuid'], 0, 8 ); $source['auth_type'] = 'oauth';
	$auth = json_decode( $source['auth_config'], true ); $auth['allowed_user_ids'] = array( (int) $user->ID ); $source['auth_config'] = wp_json_encode( $auth );
	$settings = json_decode( $source['settings'], true ); $settings['gateway']['server_ids'] = array( $server->id ); $source['settings'] = wp_json_encode( $settings );
	if ( 1 !== $wpdb->insert( $table, $source ) ) { throw new RuntimeException( 'Could not create synthetic gateway.' ); } $gateway_id = (int) $wpdb->insert_id;
	$scope = array( $gateway_id );
	$must_refuse = static function( string $message ) use ( $check, $wpdb, $table, $gateway_id, $old_ledger ): void {
		$result = \GetMCPExtensions\Upgrade180Auto::convert();
		$check( is_wp_error( $result ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway_id ) ) && $old_ledger === get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ), $message );
	};
	$tool_table = $wpdb->prefix . 'getmcp_tools';
	$wpdb->update( $tool_table, array( 'endpoint_url' => 'https://example.org/steal' ), array( 'id' => $tool_id ) ); $must_refuse( 'An unreviewed provider host defers the gateway unchanged.' );
	$wpdb->update( $tool_table, array( 'endpoint_url' => 'http://www.googleapis.com/qa-180-provider' ), array( 'id' => $tool_id ) ); $must_refuse( 'An insecure provider endpoint defers the gateway unchanged.' );
	$wpdb->update( $tool_table, array( 'endpoint_url' => 'https://www.googleapis.com/qa-180-provider', 'slug' => 'send_dm' ), array( 'id' => $tool_id ) ); $must_refuse( 'Unreviewed messaging tools defer the gateway unchanged.' );
	$wpdb->update( $tool_table, array( 'slug' => 'qa_provider_read' ), array( 'id' => $tool_id ) );
	$original_settings = $server->settings; $modified = json_decode( $original_settings, true ); $modified['provider_token_sources'] = array( 'qa_provider_read' => 'page' );
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $modified ) ), array( 'id' => $server->id ) ); $must_refuse( 'Unreviewed Page-token settings defer the gateway unchanged.' );
	$modified = json_decode( $original_settings, true ); $modified['personal_provider']['allowed_user_ids'] = array( '34' );
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $modified ) ), array( 'id' => $server->id ) ); $must_refuse( 'Malformed personal grants defer the gateway unchanged.' );
	$modified = json_decode( $original_settings, true ); $modified['personal_provider']['enabled'] = 'yes';
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $modified ) ), array( 'id' => $server->id ) ); $must_refuse( 'A malformed provider enabled flag defers the gateway unchanged.' );
	$modified = json_decode( $original_settings, true ); $modified['unknown_extension'] = true;
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $modified ) ), array( 'id' => $server->id ) ); $must_refuse( 'Unknown provider settings defer the gateway unchanged.' );
	$wpdb->update( $table, array( 'settings' => $original_settings ), array( 'id' => $server->id ) );
	$bad_auth = json_decode( $server->auth_config, true ); $bad_auth['authorize_url'] = 'https://example.org/authorize';
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $bad_auth ) ), array( 'id' => $server->id ) ); $must_refuse( 'Unreviewed provider OAuth endpoints defer the gateway unchanged.' );
	$wpdb->update( $table, array( 'auth_config' => $server->auth_config ), array( 'id' => $server->id ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'], 'A reviewed personal provider gateway migrates automatically.' );
	$gateway = $manager->get( $gateway_id ); $token_row->setValue( (object) array( 'id' => 97002, 'user_id' => $user->ID, 'server_id' => $gateway_id, 'resource' => $gateway->get_endpoint_url() ) ); wp_set_current_user( $user->ID ); $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-downstream';
	$handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null ); $list = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $list['tools'] as $entry ) { if ( str_ends_with( $entry['name'], 'qa_provider_read' ) ) { $name = $entry['name']; } }
	$check( '' !== $name, 'The existing personal provider tool remains published.' );
	$response = $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) );
	$check( empty( $response['isError'] ) && 1 === $calls, 'The gateway uses the user’s original upstream credential once.' );
	wp_set_current_user( 1 ); $settings['gateway']['server_ids'] = array(); $wpdb->update( $table, array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway_id ) );
	$denied = false; try { $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) ); } catch ( \Throwable $e ) { $denied = true; }
	$check( $denied && 1 === $calls, 'Removed membership prevents additional upstream requests.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic provider gateway migration' ) ) . PHP_EOL;
} finally {
	remove_filter( 'pre_http_request', $http, 1 ); remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row ); wp_set_current_user( 1 );
	if ( null === $saved_auth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth; }
	if ( $gateway_id ) { $wpdb->delete( $table, array( 'id' => $gateway_id, 'name' => 'QA 180 provider migration gateway' ) ); }
	$wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => $user->ID ) );
	if ( $tool_id ) { $tools->delete( $tool_id ); } $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); }
	wp_set_current_user( $previous_user );
}
