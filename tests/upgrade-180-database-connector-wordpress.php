<?php
/** A synthetic read-only SQL connector keeps its encrypted configuration on upgrade. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! extension_loaded( 'pdo_mysql' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture with PDO MySQL required.' ); }
global $wpdb;
$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $fixture_table = $wpdb->prefix . 'qa180_' . $suffix;
if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/D', $fixture_table ) ) { throw new RuntimeException( 'Unsafe synthetic table name.' ); }
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $server = null; $gateway = null; $tool = null; $scope = array(); $checks = 0;
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ); $previous_user = get_current_user_id();
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
try {
	if ( false === $wpdb->query( "CREATE TABLE `{$fixture_table}` (id INT NOT NULL PRIMARY KEY, value VARCHAR(50) NOT NULL) ENGINE=InnoDB" ) || 1 !== $wpdb->insert( $fixture_table, array( 'id' => 1, 'value' => 'synthetic-row' ) ) ) { throw new RuntimeException( 'Could not create synthetic SQL data.' ); }
	wp_set_current_user( 1 );
	$config = array( 'engine' => 'mysql', 'host' => DB_HOST, 'port' => 3306, 'database' => DB_NAME, 'username' => DB_USER, 'password' => \GetMCP\Utils\Encryption::encrypt( DB_PASSWORD ), 'ssl_mode' => 'disable', 'read_only' => true, 'max_rows' => 20, 'timeout' => 10 );
	$settings = wp_json_encode( array( 'database' => $config ) ); $slug = 'qa-180-sql-' . $suffix;
	$server = $manager->create( array( 'name' => 'QA 180 SQL connector', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => $settings ) );
	if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic SQL server.' ); }
	$tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA SQL read', 'slug' => 'qa_sql_read', 'tool_type' => 'database', 'handler' => 'get_rows', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array( 'table' => array( 'type' => 'string' ) ), 'required' => array( 'table' ) ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic SQL tool.' ); }
	$read = ( new \GetMCP\Sql\SqlExecutor() )->execute( $tool, array( 'table' => $fixture_table ), $server );
	$check( 200 === $read['status_code'] && str_contains( $read['body'], 'synthetic-row' ), 'The isolated native SQL connector can read its synthetic row.' );
	$gateway_data = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 SQL gateway', 'slug' => 'qa-180-sql-gateway-' . $suffix, 'allowed_user_ids' => array( 1 ), 'server_ids' => array( $server->id ) ), 1 );
	$gateway = $manager->get( (int) $gateway_data['id'] ); if ( ! $gateway ) { throw new RuntimeException( 'Could not create synthetic SQL gateway.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $gateway->id ) ); $scope = array( $server->id, $gateway->id );
	$invalid = $config; $invalid['read_only'] = 'false';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'database' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'Malformed read-only settings defer migration.' );
	$invalid = $config; $invalid['password'] = 'enc:v1:invalid';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'database' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreadable encrypted connector secret defers migration.' );
	$invalid = $config; $invalid['engine'] = 'unreviewed-engine';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'database' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreviewed database engine defers migration.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => $settings ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'handler' => 'unreviewed_action' ), array( 'id' => $tool->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreviewed database action defers migration.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'handler' => 'get_rows' ), array( 'id' => $tool->id ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $live = $manager->get( $server->id );
	$check( ! is_wp_error( $result ) && 2 === $result['migrated'] && $settings === $live->settings, 'The SQL server and dependent gateway migrate while preserving encrypted settings.' );
	$read = ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array( 'table' => $fixture_table ), false, $live, true );
	$check( empty( $read['isError'] ) && str_contains( wp_json_encode( $read ), 'synthetic-row' ), 'The migrated server executes the original SQL tool.' );
	$live_gateway = $manager->get( $gateway->id ); $token_row->setValue( (object) array( 'id' => 97004, 'user_id' => 1, 'server_id' => $gateway->id, 'resource' => $live_gateway->get_endpoint_url() ) );
	$handler = new \GetMCP\Gateway\FeatureHandler( $live_gateway, null ); $listed = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $listed['tools'] as $entry ) { if ( str_ends_with( $entry['name'], 'qa_sql_read' ) ) { $name = $entry['name']; } }
	$gateway_read = $name ? $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array( 'table' => $fixture_table ) ) ) : array();
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $live ) && $name && empty( $gateway_read['isError'] ) && str_contains( wp_json_encode( $gateway_read ), 'synthetic-row' ), 'Gateway users retain delegated SQL access without gaining direct access.' );
	$write = clone $tool; $write->handler = 'insert_row';
	$refusal = ( new \GetMCP\Sql\SqlExecutor() )->execute( $write, array( 'table' => $fixture_table, 'data' => array( 'id' => 2, 'value' => 'forbidden' ) ), $live );
	$check( 200 !== $refusal['status_code'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$fixture_table}`" ), 'The saved read-only policy still refuses writes.' );
	$config['read_only'] = false; $write_settings = wp_json_encode( array( 'database' => $config ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => $write_settings ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	$write_migration = \GetMCPExtensions\Upgrade180Auto::convert(); $write_server = $manager->get( $server->id );
	$inserted = ( new \GetMCP\Sql\SqlExecutor() )->execute( $write, array( 'table' => $fixture_table, 'data' => array( 'id' => 2, 'value' => 'synthetic-write' ) ), $write_server );
	$check( ! is_wp_error( $write_migration ) && $write_settings === $write_server->settings && 200 === $inserted['status_code'] && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$fixture_table}`" ), 'An explicitly write-enabled connector preserves its setting and can write its synthetic table.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic native SQL connector' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row );
	if ( $tool ) { $tools->delete( $tool->id ); } if ( $server ) { $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); } }
	if ( $gateway ) { $current = $manager->get( $gateway->id ); if ( $current && $current->slug === 'qa-180-sql-gateway-' . $suffix ) { $manager->delete( $gateway->id ); } }
	$wpdb->query( "DROP TABLE IF EXISTS `{$fixture_table}`" );
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); } wp_set_current_user( $previous_user );
}
