<?php
/** Exercise a synthetic FTP connector on a disposable local service. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || 'getmcp180ftp' !== getenv( 'GETMCP_180_QA_FTPHOST' ) ) { throw new RuntimeException( 'Isolated FTP fixture required.' ); }
global $wpdb;
$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $slug = 'qa-180-ftp-' . $suffix;
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $server = null; $gateway = null; $tool = null; $scope = array(); $checks = 0;
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ); $previous_user = get_current_user_id();
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
try {
	wp_set_current_user( 1 );
	$config = array( 'protocol' => 'ftp', 'host' => getenv( 'GETMCP_180_QA_FTPHOST' ), 'port' => 2121, 'username' => getenv( 'GETMCP_180_QA_FTPUSER' ), 'auth_mode' => 'password', 'password' => \GetMCP\Utils\Encryption::encrypt( getenv( 'GETMCP_180_QA_FTPPASSWORD' ) ), 'private_key' => '', 'passphrase' => '', 'root_path' => '/', 'read_only' => true, 'max_file_size' => 5, 'timeout' => 10 );
	$settings = wp_json_encode( array( 'ftp' => $config ) );
	$server = $manager->create( array( 'name' => 'QA 180 FTP connector', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => $settings ) );
	if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic FTP server.' ); }
	$tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA FTP read', 'tool_type' => 'ftp', 'handler' => 'read_file', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'max_bytes' => array( 'type' => 'integer' ) ), 'required' => array( 'path' ) ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic FTP tool.' ); }
	$read = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $tool, array( 'path' => '/readme.txt', 'max_bytes' => 17 ), $server );
	if ( 200 !== $read['status_code'] ) { echo wp_json_encode( array( 'status' => $read['status_code'], 'diagnostic' => str_replace( getenv( 'GETMCP_180_QA_FTPPASSWORD' ), '[redacted]', $read['body'] ) ) ) . PHP_EOL; }
	$check( 200 === $read['status_code'] && str_contains( $read['body'], 'synthetic-ftp-row' ), 'The native FTP connector can read its synthetic file.' );
	$gateway_data = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 FTP gateway', 'slug' => 'qa-180-ftp-gateway-' . $suffix, 'allowed_user_ids' => array( 1 ), 'server_ids' => array( $server->id ) ), 1 );
	$gateway = $manager->get( (int) $gateway_data['id'] ); if ( ! $gateway ) { throw new RuntimeException( 'Could not create synthetic FTP gateway.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $gateway->id ) ); $scope = array( $server->id, $gateway->id );
	$invalid = $config; $invalid['protocol'] = 'unreviewed';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'ftp' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'Unreviewed file-server protocol settings defer the gateway unchanged.' );
	$invalid = $config; $invalid['password'] = 'enc:v1:invalid';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'ftp' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreadable FTP secret defers migration.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => $settings ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'handler' => 'unreviewed_action' ), array( 'id' => $tool->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreviewed FTP action defers migration.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'handler' => 'read_file' ), array( 'id' => $tool->id ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $live = $manager->get( $server->id );
	$check( ! is_wp_error( $result ) && 2 === $result['migrated'] && $settings === $live->settings, 'The FTP server and gateway migrate without changing encrypted settings.' );
	$live_gateway = $manager->get( $gateway->id ); $token_row->setValue( (object) array( 'id' => 97006, 'user_id' => 1, 'server_id' => $gateway->id, 'resource' => $live_gateway->get_endpoint_url() ) );
	$handler = new \GetMCP\Gateway\FeatureHandler( $live_gateway, null ); $listed = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $listed['tools'] as $entry ) { if ( str_ends_with( $entry['name'], $tool->slug ) ) { $name = $entry['name']; } }
	$gateway_read = $name ? $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array( 'path' => '/readme.txt', 'max_bytes' => 17 ) ) ) : array();
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $live ) && $name && empty( $gateway_read['isError'] ) && str_contains( wp_json_encode( $gateway_read ), 'synthetic-ftp-row' ), 'Delegated FTP access survives without granting direct access.' );
	$write = clone $tool; $write->handler = 'write_file'; $path = '/qa180-' . $suffix . '.txt';
	$refusal = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $write, array( 'path' => $path, 'content' => 'synthetic-write' ), $live );
	$check( 200 !== $refusal['status_code'], 'FTP read-only mode still refuses writes.' );
	$config['read_only'] = false; $write_settings = wp_json_encode( array( 'ftp' => $config ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => $write_settings ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	$write_migration = \GetMCPExtensions\Upgrade180Auto::convert(); $write_server = $manager->get( $server->id );
	$written = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $write, array( 'path' => $path, 'content' => 'synthetic-write' ), $write_server );
	$verify = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $tool, array( 'path' => $path, 'max_bytes' => 15 ), $write_server );
	$check( ! is_wp_error( $write_migration ) && $write_settings === $write_server->settings && 200 === $written['status_code'] && str_contains( $verify['body'], 'synthetic-write' ), 'An explicitly write-enabled FTP connector retains its write setting.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic FTP connector' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row );
	if ( $tool ) { $tools->delete( $tool->id ); } if ( $server ) { $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); } }
	if ( $gateway ) { $current = $manager->get( $gateway->id ); if ( $current && $current->slug === 'qa-180-ftp-gateway-' . $suffix ) { $manager->delete( $gateway->id ); } }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); } wp_set_current_user( $previous_user );
}
