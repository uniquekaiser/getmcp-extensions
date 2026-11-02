<?php
/** Preserve a gateway's personal Google Health member during automatic sign-in conversion. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$source = $wpdb->get_row( "SELECT * FROM {$table} WHERE slug IN ('qa-180-browser-gateway','qa-180-automatic-gateway') ORDER BY id LIMIT 1", ARRAY_A );
$user = get_user_by( 'login', 'qa_extensions_180_browser' ) ?: get_userdata( 1 );
if ( ! $source || ! $user ) { throw new RuntimeException( 'Disposable browser fixtures are required.' ); }
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager();
$asset = \GetMCPExtensions\GoogleHealth::asset(); $data = $asset['server'];
$data['name'] = 'QA 180 health migration member'; $data['slug'] = 'qa-180-health-member-' . substr( wp_generate_uuid4(), 0, 8 ); $data['status'] = 'active';
$data['auth_config']['client_id'] = 'synthetic-health-client'; $data['auth_config'] = wp_json_encode( $data['auth_config'] );
$data['auth_credentials'] = wp_json_encode( array( 'client_secret' => 'synthetic-health-secret' ) );
$data['settings']['personal_provider'] = array( 'enabled' => true, 'preset' => 'google-health', 'allowed_user_ids' => array() ); $data['settings'] = wp_json_encode( $data['settings'] );
$server = $manager->create( $data );
if ( ! $server || $server->slug !== $data['slug'] ) { throw new RuntimeException( 'Could not create synthetic health server.' ); }
$gateway_id = 0; $tool_id = 0; $scope = array(); $checks = 0; $previous_user = get_current_user_id();
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false );
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$filter = static function () use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
try {
	$definition = null; foreach ( $asset['tools'] as $entry ) { if ( 'google_health_data_inventory' === $entry['slug'] ) { $definition = $entry; break; } }
	if ( ! $definition ) { throw new RuntimeException( 'Reviewed health tool missing.' ); }
	$definition['server_id'] = $server->id; $tool = $tools->create( $definition );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic health tool.' ); } $tool_id = $tool->id;
	\GetMCP\Remote\UpstreamConnections::save( $server->id, $user->ID, array( 'kind' => 'rest-provider', 'config_hash' => \GetMCPExtensions\ProviderConnections::fingerprint( $server ), 'access_token' => 'synthetic-health-personal', 'refresh_token' => 'synthetic-health-refresh', 'expires_at' => time() + 3600, 'scope' => '' ) );
	unset( $source['id'] ); $source['uuid'] = wp_generate_uuid4(); $source['server_id'] = substr( hash( 'sha256', $source['uuid'] ), 0, 16 );
	$source['name'] = 'QA 180 health migration gateway'; $source['slug'] = 'qa-180-health-gateway-' . substr( $source['uuid'], 0, 8 ); $source['auth_type'] = 'oauth';
	$auth = json_decode( $source['auth_config'], true ); $auth['allowed_user_ids'] = array( (int) $user->ID ); $source['auth_config'] = wp_json_encode( $auth );
	$settings = json_decode( $source['settings'], true ); $settings['gateway']['server_ids'] = array( $server->id ); $source['settings'] = wp_json_encode( $settings );
	if ( 1 !== $wpdb->insert( $table, $source ) ) { throw new RuntimeException( 'Could not create synthetic legacy gateway.' ); } $gateway_id = (int) $wpdb->insert_id;
	$scope = array( $gateway_id );
	$must_refuse = static function( string $message ) use ( $check, $wpdb, $table, $gateway_id, $old_ledger ): void {
		$result = \GetMCPExtensions\Upgrade180Auto::convert();
		$check( is_wp_error( $result ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway_id ) ) && $old_ledger === get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ), $message );
	};
	$original_settings = $server->settings; $modified = json_decode( $original_settings, true ); $modified['unknown_extension'] = true;
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $modified ) ), array( 'id' => $server->id ) ); $must_refuse( 'Unknown health settings defer the gateway unchanged.' );
	$modified = json_decode( $original_settings, true ); $modified['personal_provider']['allowed_user_ids'] = array( '34' );
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $modified ) ), array( 'id' => $server->id ) ); $must_refuse( 'Malformed personal grants defer the gateway unchanged.' );
	$modified = json_decode( $original_settings, true ); $modified['provider_token_sources'] = array( 'google_health_data_inventory' => 'page' );
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $modified ) ), array( 'id' => $server->id ) ); $must_refuse( 'Unsupported health token sources defer the gateway unchanged.' );
	$wpdb->update( $table, array( 'settings' => $original_settings ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'endpoint_url' => 'https://example.org/not-health' ), array( 'id' => $tool_id ) ); $must_refuse( 'A changed health endpoint defers the gateway unchanged.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'endpoint_url' => $definition['endpoint_url'] ), array( 'id' => $tool_id ) );
	$bad_auth = json_decode( $server->auth_config, true ); $bad_auth['authorize_url'] = 'https://example.org/authorize';
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $bad_auth ) ), array( 'id' => $server->id ) ); $must_refuse( 'Unreviewed health OAuth endpoints defer the gateway unchanged.' );
	$wpdb->update( $table, array( 'auth_config' => $server->auth_config ), array( 'id' => $server->id ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'], 'A reviewed personal health gateway migrates automatically.' );
	$gateway = $manager->get( $gateway_id ); $token_row->setValue( (object) array( 'id' => 97001, 'user_id' => $user->ID, 'server_id' => $gateway_id, 'resource' => $gateway->get_endpoint_url() ) ); wp_set_current_user( $user->ID );
	$handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null ); $list = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $list['tools'] as $entry ) { if ( str_ends_with( $entry['name'], 'google_health_data_inventory' ) ) { $name = $entry['name']; } }
	$check( '' !== $name, 'The existing health tool remains available through the gateway.' );
	$response = $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) );
	$check( empty( $response['isError'] ) && str_contains( wp_json_encode( $response ), 'data_types' ), 'The authorized user retains the original personal health grant.' );
	wp_set_current_user( 1 ); $settings['gateway']['server_ids'] = array(); $wpdb->update( $table, array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway_id ) );
	$denied = false; try { $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) ); } catch ( \Throwable $e ) { $denied = true; }
	$check( $denied, 'Removing the member immediately removes delegated access.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic health gateway migration' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row ); wp_set_current_user( 1 );
	if ( $gateway_id ) { $wpdb->delete( $table, array( 'id' => $gateway_id, 'name' => 'QA 180 health migration gateway' ) ); }
	$wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => $user->ID ) );
	if ( $tool_id ) { $tools->delete( $tool_id ); } $current = $manager->get( $server->id ); if ( $current && $current->slug === $data['slug'] ) { $manager->delete( $server->id ); }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); }
	wp_set_current_user( $previous_user );
}
