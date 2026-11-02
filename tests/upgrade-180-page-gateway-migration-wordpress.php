<?php
/** Synthetic Instagram Page grants remain usable through a converted gateway. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$source = $wpdb->get_row( "SELECT * FROM {$table} WHERE slug='qa-180-automatic-gateway'", ARRAY_A );
if ( ! $source ) { throw new RuntimeException( 'Synthetic gateway fixture required.' ); }
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager();
$suffix = substr( wp_generate_uuid4(), 0, 8 ); $slug = 'qa-180-page-member-' . $suffix;
$config = array( 'provider' => 'external', 'authorize_url' => 'https://www.facebook.com/v26.0/dialog/oauth', 'token_url' => 'https://graph.facebook.com/v26.0/oauth/access_token', 'client_id' => 'synthetic-meta-client' );
$settings = array( 'personal_provider' => array( 'enabled' => true, 'preset' => 'instagram-facebook-login', 'allowed_user_ids' => array( 1 ) ), 'provider_token_sources' => array( 'get_conversations' => 'page', 'get_conversation_messages' => 'page', 'send_dm' => 'page' ) );
$server = $manager->create( array( 'name' => 'QA 180 Page member', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( $config ), 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'synthetic-meta-secret' ) ), 'settings' => wp_json_encode( $settings ) ) );
if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Synthetic Page provider creation failed.' ); }
$gateway_id = 0; $ids = array(); $scope = array(); $checks = 0; $messages = 0; $wrong_credential = false;
$previous_user = get_current_user_id(); $old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false );
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$mock = static function( $pre, $args, $url ) use ( &$messages, &$wrong_credential ) {
	if ( 'graph.facebook.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) { return $pre; }
	$path = wp_parse_url( $url, PHP_URL_PATH ); $auth = $args['headers']['Authorization'] ?? '';
	if ( str_ends_with( $path, '/me/accounts' ) ) { $data = array( 'data' => array( array( 'id' => '101', 'name' => 'Synthetic Page', 'access_token' => 'synthetic-page-token', 'tasks' => array( 'MESSAGING' ), 'instagram_business_account' => array( 'id' => '1101', 'username' => 'synthetic' ) ) ) ); }
	elseif ( str_ends_with( $path, '/me/permissions' ) ) { $data = array( 'data' => array_map( static fn( $name ) => array( 'permission' => $name, 'status' => 'granted' ), array( 'instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata' ) ) ); }
	elseif ( str_ends_with( $path, '/101/conversations' ) ) { if ( 'Bearer synthetic-page-token' !== $auth ) { $wrong_credential = true; } $data = array( 'data' => array( array( 'id' => 'c101' ) ) ); }
	elseif ( str_ends_with( $path, '/101/messages' ) ) { if ( 'Bearer synthetic-page-token' !== $auth ) { $wrong_credential = true; } ++$messages; return new WP_Error( 'synthetic_ambiguous_write', 'Synthetic ambiguous response.' ); }
	else { $wrong_credential = true; return new WP_Error( 'synthetic_unknown_endpoint', 'Unexpected synthetic endpoint.' ); }
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 1, 3 );
try {
	wp_set_current_user( 1 );
	$asset = json_decode( file_get_contents( GETMCP_EXTENSIONS_PATH . 'assets/templates/instagram.json' ), true );
	foreach ( $asset['tools'] as $definition ) { if ( in_array( $definition['slug'], array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true ) ) { $definition['server_id'] = $server->id; $definition['status'] = 'active'; $definition['retry_count'] = 3; $tool = $tools->create( $definition ); if ( ! $tool ) { throw new RuntimeException( 'Synthetic Page tool creation failed.' ); } $ids[$definition['slug']] = $tool->id; } }
	$grant = array( 'kind' => 'rest-provider', 'config_hash' => \GetMCPExtensions\ProviderConnections::fingerprint( $server ), 'access_token' => 'synthetic-user-token', 'refresh_token' => 'synthetic-refresh', 'expires_at' => time() + 3600 );
	\GetMCP\Remote\UpstreamConnections::save( $server->id, 1, $grant );
	\GetMCPExtensions\PageTokens::discover( $server, 'user:1', 'synthetic-user-token' );
	\GetMCPExtensions\PageTokens::select( $server, 'user:1', 'synthetic-user-token', '101', '1101' );
	\GetMCPExtensions\PageTokens::verify( $server, 'user:1', 'synthetic-user-token' );
	$vault_key = 'getmcp_page_assets_' . hash( 'sha256', $server->id . '|user:1' ); $vault_before = get_option( $vault_key );
	unset( $source['id'] ); $source['uuid'] = wp_generate_uuid4(); $source['server_id'] = substr( hash( 'sha256', $source['uuid'] ), 0, 16 );
	$source['name'] = 'QA 180 Page gateway'; $source['slug'] = 'qa-180-page-gateway-' . $suffix; $source['auth_type'] = 'oauth';
	$auth = json_decode( $source['auth_config'], true ); $auth['allowed_user_ids'] = array( 1 ); $source['auth_config'] = wp_json_encode( $auth );
	$gateway_settings = json_decode( $source['settings'], true ); $gateway_settings['gateway']['server_ids'] = array( $server->id ); $source['settings'] = wp_json_encode( $gateway_settings );
	if ( 1 !== $wpdb->insert( $table, $source ) ) { throw new RuntimeException( 'Synthetic legacy gateway creation failed.' ); } $gateway_id = (int) $wpdb->insert_id; $scope = array( $gateway_id );
	$tool_table = $wpdb->prefix . 'getmcp_tools'; $original_tool = $wpdb->get_row( $wpdb->prepare( "SELECT endpoint_url,http_method FROM {$tool_table} WHERE id=%d", $ids['send_dm'] ), ARRAY_A );
	$wpdb->update( $tool_table, array( 'endpoint_url' => 'https://graph.facebook.com/v26.0/{{page_id}}/unsafe' ), array( 'id' => $ids['send_dm'] ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An altered Page messaging endpoint defers migration.' );
	$wpdb->update( $tool_table, $original_tool, array( 'id' => $ids['send_dm'] ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'], 'A reviewed Instagram Page gateway migrates automatically.' );
	$check( $vault_before === get_option( $vault_key ), 'Migration preserves the existing encrypted Page vault.' );
	$gateway = $manager->get( $gateway_id ); $token_row->setValue( (object) array( 'id' => 97003, 'user_id' => 1, 'server_id' => $gateway_id, 'resource' => $gateway->get_endpoint_url() ) ); $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-downstream';
	$handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null ); $list = $handler->handle( 'tools/list', array() ); $names = array();
	foreach ( $list['tools'] as $entry ) { foreach ( array_keys( $ids ) as $name ) { if ( str_ends_with( $entry['name'], $name ) ) { $names[$name] = $entry['name']; } } }
	$check( isset( $names['get_conversations'], $names['send_dm'] ), 'The migrated gateway retains Page tools.' );
	$response = $handler->handle( 'tools/call', array( 'name' => $names['get_conversations'], 'arguments' => array( 'page_id' => '101' ) ) );
	$check( empty( $response['isError'] ) && ! $wrong_credential, 'The original selected Page token is used for the permitted read.' );
	$denied = false; try { $denial = $handler->handle( 'tools/call', array( 'name' => $names['get_conversations'], 'arguments' => array( 'page_id' => '999' ) ) ); $denied = ! empty( $denial['isError'] ); } catch ( \Throwable $e ) { $denied = true; }
	$check( $denied && ! $wrong_credential, 'A different Page is refused before its credential is sent.' );
	try { $handler->handle( 'tools/call', array( 'name' => $names['send_dm'], 'arguments' => array( 'page_id' => '101', 'recipient' => array( 'id' => '123' ), 'message' => array( 'text' => 'synthetic' ) ) ) ); } catch ( \Throwable $e ) {}
	$check( 1 === $messages && ! $wrong_credential, 'An ambiguous synthetic message is dispatched at most once.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic Page gateway migration' ) ) . PHP_EOL;
} finally {
	remove_filter( 'pre_http_request', $mock, 1 ); remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row );
	if ( null === $saved_auth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth; }
	if ( $gateway_id ) { $wpdb->delete( $table, array( 'id' => $gateway_id, 'name' => 'QA 180 Page gateway' ) ); }
	\GetMCPExtensions\PageTokens::forget( $server->id, 'user:1' ); $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => 1 ) );
	foreach ( $ids as $id ) { $tools->delete( $id ); } $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); } wp_set_current_user( $previous_user );
}
