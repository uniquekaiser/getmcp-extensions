<?php
/** The shared fixture's disposable PostgreSQL service contains synthetic data only. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! extension_loaded( 'pdo_pgsql' ) || 'getmcp180pg' !== getenv( 'GETMCP_180_QA_PGHOST' ) ) { throw new RuntimeException( 'Isolated PostgreSQL fixture required.' ); }
global $wpdb;
$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $table = 'qa180_' . $suffix;
if ( ! preg_match( '/\A[a-z0-9_]+\z/D', $table ) ) { throw new RuntimeException( 'Unsafe synthetic table name.' ); }
$host = getenv( 'GETMCP_180_QA_PGHOST' ); $user = getenv( 'GETMCP_180_QA_PGUSER' ); $password = getenv( 'GETMCP_180_QA_PGPASSWORD' ); $db = getenv( 'GETMCP_180_QA_PGDATABASE' );
$pdo = new PDO( 'pgsql:host=' . $host . ';port=5432;dbname=' . $db . ';sslmode=disable', $user, $password, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $server = null; $gateway = null; $tool = null; $scope = array(); $checks = 0;
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ); $previous_user = get_current_user_id();
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
try {
	$pdo->exec( "CREATE TABLE \"{$table}\" (id INTEGER PRIMARY KEY, value VARCHAR(50) NOT NULL)" );
	$pdo->exec( "INSERT INTO \"{$table}\" (id,value) VALUES (1,'synthetic-postgres-row')" ); wp_set_current_user( 1 );
	$config = array( 'engine' => 'pgsql', 'host' => $host, 'port' => 5432, 'database' => $db, 'username' => $user, 'password' => \GetMCP\Utils\Encryption::encrypt( $password ), 'ssl_mode' => 'disable', 'schema' => 'public', 'read_only' => true, 'max_rows' => 20, 'timeout' => 10 );
	$settings = wp_json_encode( array( 'database' => $config ) ); $slug = 'qa-180-pg-' . $suffix;
	$server = $manager->create( array( 'name' => 'QA 180 PostgreSQL connector', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => $settings ) );
	if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic PostgreSQL server.' ); }
	$tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA PostgreSQL read', 'slug' => 'qa_pg_read', 'tool_type' => 'database', 'handler' => 'get_rows', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array( 'table' => array( 'type' => 'string' ) ), 'required' => array( 'table' ) ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic PostgreSQL tool.' ); }
	$read = ( new \GetMCP\Sql\SqlExecutor() )->execute( $tool, array( 'table' => $table ), $server );
	$check( 200 === $read['status_code'] && str_contains( $read['body'], 'synthetic-postgres-row' ), 'The native PostgreSQL connector can read its synthetic row.' );
	$gateway_data = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 PostgreSQL gateway', 'slug' => 'qa-180-pg-gateway-' . $suffix, 'allowed_user_ids' => array( 1 ), 'server_ids' => array( $server->id ) ), 1 );
	$gateway = $manager->get( (int) $gateway_data['id'] ); if ( ! $gateway ) { throw new RuntimeException( 'Could not create synthetic PostgreSQL gateway.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $gateway->id ) ); $scope = array( $server->id, $gateway->id );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $live = $manager->get( $server->id );
	$check( ! is_wp_error( $result ) && 2 === $result['migrated'] && $settings === $live->settings, 'The PostgreSQL server and gateway migrate without changing encrypted settings.' );
	$live_gateway = $manager->get( $gateway->id ); $token_row->setValue( (object) array( 'id' => 97005, 'user_id' => 1, 'server_id' => $gateway->id, 'resource' => $live_gateway->get_endpoint_url() ) );
	$handler = new \GetMCP\Gateway\FeatureHandler( $live_gateway, null ); $listed = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $listed['tools'] as $entry ) { if ( str_ends_with( $entry['name'], $tool->slug ) ) { $name = $entry['name']; } }
	$gateway_read = $name ? $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array( 'table' => $table ) ) ) : array();
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $live ) && $name && empty( $gateway_read['isError'] ) && str_contains( wp_json_encode( $gateway_read ), 'synthetic-postgres-row' ), 'Delegated PostgreSQL access survives without granting direct access.' );
	$write = clone $tool; $write->handler = 'insert_row';
	$refusal = ( new \GetMCP\Sql\SqlExecutor() )->execute( $write, array( 'table' => $table, 'data' => array( 'id' => 2, 'value' => 'forbidden' ) ), $live );
	$check( 200 !== $refusal['status_code'] && 1 === (int) $pdo->query( "SELECT COUNT(*) FROM \"{$table}\"" )->fetchColumn(), 'PostgreSQL read-only mode still refuses writes.' );
	$config['read_only'] = false; $write_settings = wp_json_encode( array( 'database' => $config ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => $write_settings ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	$write_migration = \GetMCPExtensions\Upgrade180Auto::convert(); $write_server = $manager->get( $server->id );
	$inserted = ( new \GetMCP\Sql\SqlExecutor() )->execute( $write, array( 'table' => $table, 'data' => array( 'id' => 2, 'value' => 'synthetic-write' ) ), $write_server );
	$check( ! is_wp_error( $write_migration ) && $write_settings === $write_server->settings && 200 === $inserted['status_code'] && 2 === (int) $pdo->query( "SELECT COUNT(*) FROM \"{$table}\"" )->fetchColumn(), 'An explicitly write-enabled PostgreSQL connector retains its write setting.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic PostgreSQL connector' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row );
	if ( $tool ) { $tools->delete( $tool->id ); } if ( $server ) { $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); } }
	if ( $gateway ) { $current = $manager->get( $gateway->id ); if ( $current && $current->slug === 'qa-180-pg-gateway-' . $suffix ) { $manager->delete( $gateway->id ); } }
	$pdo->exec( "DROP TABLE IF EXISTS \"{$table}\"" );
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); } wp_set_current_user( $previous_user );
}
