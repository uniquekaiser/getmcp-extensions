<?php
/** Local-only creation of legacy connections alongside native 1.8 servers. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/auth/class-oauth-user-access.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-manager.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-module.php';
\GetMCP\Gateway\FeatureModule::register();
wp_set_current_user( 1 );
$checks = 0; $ids = array();
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
try {
	$remote = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'remote-mcp', 'name' => 'QA 180 created remote', 'allowed_user_ids' => array( 1 ), 'remote' => array( 'endpoint' => 'https://example.org/mcp', 'auth_mode' => 'none' ), 'connection_headers' => array( array( 'name' => 'X-Synthetic-Key', 'value' => 'synthetic-header-secret' ) ) ), 1 );
	$ids[] = array( 'id' => $remote['id'], 'name' => 'QA 180 created remote' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $remote['id'] ), ARRAY_A );
	$check( 'remote-mcp' === $row['server_kind'] && 'standard' === $row['kind'] && 'wordpress' === $row['auth_type'], 'New legacy remote has the correct native sign-in and legacy kind.' );
	$check( array( 1 ) === ( json_decode( $row['auth_config'], true )['allowed_user_ids'] ?? null ), 'New remote keeps its explicit user allowlist.' );
	$check( ! empty( $row['outbound_auth_credentials'] ) && ! str_contains( $row['outbound_auth_credentials'], 'synthetic-header-secret' ) && ! str_contains( wp_json_encode( $remote ), 'synthetic-header-secret' ), 'Custom header is encrypted and absent from management output.' );
	$unchanged = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $remote['id'], 'connection_headers' => array( array( 'name' => 'X-Synthetic-Key', 'value' => '' ) ) ), 1 );
	$after_header = $wpdb->get_row( $wpdb->prepare( "SELECT outbound_auth_credentials FROM {$table} WHERE id=%d", $remote['id'] ), ARRAY_A );
	$check( $row['outbound_auth_credentials'] === $after_header['outbound_auth_credentials'] && in_array( 'X-Synthetic-Key', $unchanged['header_names'], true ), 'Blank header edit keeps the existing encrypted value.' );
	\GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $remote['id'], 'connection_headers' => array() ), 1 );
	$cleared = $wpdb->get_row( $wpdb->prepare( "SELECT outbound_auth_credentials FROM {$table} WHERE id=%d", $remote['id'] ), ARRAY_A );
	$cleared_server = ( new \GetMCP\Core\ServerManager() )->get( $remote['id'] );
	$check( $row['outbound_auth_credentials'] !== $cleared['outbound_auth_credentials'] && array() === \GetMCPExtensions\ConnectionHeaders::read( $cleared_server ), 'Explicit header removal clears the requested header.' );
	$gateway = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 created gateway', 'allowed_user_ids' => array( 1 ), 'server_ids' => array( $remote['id'] ) ), 1 );
	$ids[] = array( 'id' => $gateway['id'], 'name' => 'QA 180 created gateway' );
	$gateway_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $gateway['id'] ), ARRAY_A );
	$check( 'gateway' === $gateway_row['server_kind'] && 'wordpress' === $gateway_row['auth_type'] && array( $remote['id'] ) === ( json_decode( $gateway_row['settings'], true )['gateway']['server_ids'] ?? null ), 'New legacy gateway retains its selected member.' );
	$refused = false;
	try { \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'remote-mcp', 'name' => 'unauthorized', 'remote' => array( 'endpoint' => 'https://example.org/mcp', 'auth_mode' => 'none' ) ), 0 ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused, 'Unauthorized creation is refused.' );
	$manager = new \GetMCP\Core\ServerManager();
	$native = $manager->create( array( 'name' => 'QA 180 native manager', 'slug' => 'qa-180-native-manager', 'status' => 'draft' ) );
	$check( (bool) $native, 'Native 1.8 server creation remains available.' );
	$ids[] = array( 'id' => $native->id, 'name' => 'QA 180 native manager' );
	$native_row = $wpdb->get_row( $wpdb->prepare( "SELECT server_kind,kind FROM {$table} WHERE id=%d", $native->id ), ARRAY_A );
	$check( 'native' === $native_row['server_kind'] && 'standard' === $native_row['kind'], 'Native servers retain their own kind.' );
	$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
	$store = \GetMCP\Remote\UpstreamConnections::table();
	$other = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$store} WHERE server_id=%d AND user_id=1", (int) $state['remote'] ), ARRAY_A );
	$check( is_array( $other ), 'Existing synthetic personal grant is present.' );
	\GetMCP\Remote\UpstreamConnections::save( (int) $remote['id'], 1, array( 'access_token' => 'synthetic-created-grant' ) );
	\GetMCP\Gateway\FeatureManager::run( 'delete', array( 'id' => $remote['id'] ), 1 );
	$check( null === \GetMCP\Remote\UpstreamConnections::get( (int) $remote['id'], 1 ), 'Deleting the new connection removes its personal grant.' );
	$other_after = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$store} WHERE server_id=%d AND user_id=1", (int) $state['remote'] ), ARRAY_A );
	$check( $other === $other_after, 'Deleting one connection preserves unrelated personal grants.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic 1.8 creation' ) ) . PHP_EOL;
} finally {
	$manager = new \GetMCP\Core\ServerManager();
	if ( isset( $remote['id'] ) ) { $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => (int) $remote['id'], 'user_id' => 1 ) ); }
	foreach ( array_reverse( $ids ) as $item ) { $server = $manager->get( $item['id'] ); if ( $server && $server->name === $item['name'] ) { $manager->delete( $item['id'] ); } }
}
