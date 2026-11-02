<?php
/** Exercise synthetic SFTP password/key credentials and pinned host identity. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || 'getmcp180sftp' !== getenv( 'GETMCP_180_QA_SFTPHOST' ) || ! is_file( '/tmp/qa180_id' ) ) { throw new RuntimeException( 'Isolated SFTP fixture required.' ); }
global $wpdb;
$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $slug = 'qa-180-sftp-' . $suffix;
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $server = null; $gateway = null; $tool = null; $scope = array(); $checks = 0;
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ); $previous_user = get_current_user_id();
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
try {
	wp_set_current_user( 1 );
	$config = array( 'protocol' => 'sftp', 'host' => getenv( 'GETMCP_180_QA_SFTPHOST' ), 'port' => 2222, 'username' => getenv( 'GETMCP_180_QA_SFTPUSER' ), 'auth_mode' => 'password', 'password' => \GetMCP\Utils\Encryption::encrypt( getenv( 'GETMCP_180_QA_SFTPPASSWORD' ) ), 'private_key' => '', 'passphrase' => '', 'root_path' => '', 'read_only' => true, 'max_file_size' => 5, 'timeout' => 10 );
	$settings = wp_json_encode( array( 'ftp' => $config ) );
	$server = $manager->create( array( 'name' => 'QA 180 SFTP connector', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => $settings ) );
	if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic SFTP server.' ); }
	$drive = new \GetMCP\Ftp\SftpDrive( $server->get_ftp_config() ); $drive->connect(); $description = $drive->describe(); $drive->close();
	$config['host_key_fingerprint'] = $description['host_key_fingerprint']; $settings = wp_json_encode( array( 'ftp' => $config ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => $settings ), array( 'id' => $server->id ) ); $server = $manager->get( $server->id );
	$tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA SFTP read', 'tool_type' => 'ftp', 'handler' => 'read_file', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ) ), 'required' => array( 'path' ) ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic SFTP tool.' ); }
	$read = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $tool, array( 'path' => '/readme.txt' ), $server );
	$check( 200 === $read['status_code'] && str_contains( $read['body'], 'synthetic-sftp-row' ), 'The native SFTP connector reads its synthetic file with a pinned host.' );
	$bad_config = $config; $bad_config['host_key_fingerprint'] = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; $bad_server = clone $server; $bad_server->settings = wp_json_encode( array( 'ftp' => $bad_config ) );
	$refusal = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $tool, array( 'path' => '/readme.txt' ), $bad_server );
	$check( 200 !== $refusal['status_code'], 'A changed SFTP host identity is refused.' );
	$gateway_data = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 SFTP gateway', 'slug' => 'qa-180-sftp-gateway-' . $suffix, 'allowed_user_ids' => array( 1 ), 'server_ids' => array( $server->id ) ), 1 );
	$gateway = $manager->get( (int) $gateway_data['id'] ); if ( ! $gateway ) { throw new RuntimeException( 'Could not create synthetic SFTP gateway.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $gateway->id ) ); $scope = array( $server->id, $gateway->id );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $live = $manager->get( $server->id );
	$check( ! is_wp_error( $result ) && 2 === $result['migrated'] && $settings === $live->settings, 'The SFTP server and gateway migrate without changing encrypted settings.' );
	$live_gateway = $manager->get( $gateway->id ); $token_row->setValue( (object) array( 'id' => 97007, 'user_id' => 1, 'server_id' => $gateway->id, 'resource' => $live_gateway->get_endpoint_url() ) );
	$handler = new \GetMCP\Gateway\FeatureHandler( $live_gateway, null ); $listed = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $listed['tools'] as $entry ) { if ( str_ends_with( $entry['name'], $tool->slug ) ) { $name = $entry['name']; } }
	$gateway_read = $name ? $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array( 'path' => '/readme.txt' ) ) ) : array();
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $live ) && $name && empty( $gateway_read['isError'] ) && str_contains( wp_json_encode( $gateway_read ), 'synthetic-sftp-row' ), 'Delegated SFTP access survives without granting direct access.' );
	$write = clone $tool; $write->handler = 'write_file'; $path = '/qa180-' . $suffix . '.txt';
	$refusal = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $write, array( 'path' => $path, 'content' => 'synthetic-write' ), $live );
	$check( 200 !== $refusal['status_code'], 'SFTP read-only mode still refuses writes.' );
	$config['read_only'] = false; $config['auth_mode'] = 'key'; $config['password'] = ''; $config['private_key'] = \GetMCP\Utils\Encryption::encrypt( (string) file_get_contents( '/tmp/qa180_id' ) );
	$key_settings = wp_json_encode( array( 'ftp' => $config ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => $key_settings ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	$key_migration = \GetMCPExtensions\Upgrade180Auto::convert(); $key_server = $manager->get( $server->id );
	$written = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $write, array( 'path' => $path, 'content' => 'synthetic-key-write' ), $key_server );
	$verify = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $tool, array( 'path' => $path ), $key_server );
	$check( ! is_wp_error( $key_migration ) && $key_settings === $key_server->settings && 200 === $written['status_code'] && str_contains( $verify['body'], 'synthetic-key-write' ), 'Encrypted private-key login and explicit write access survive migration.' );
	$key = \phpseclib3\Crypt\PublicKeyLoader::load( (string) file_get_contents( '/tmp/qa180_id' ) )->withPassword( 'synthetic-key-passphrase' )->toString( 'PKCS8' );
	$config['private_key'] = \GetMCP\Utils\Encryption::encrypt( $key ); $config['passphrase'] = \GetMCP\Utils\Encryption::encrypt( 'synthetic-key-passphrase' );
	$passphrase_settings = wp_json_encode( array( 'ftp' => $config ) );
	$wrong = $config; $wrong['passphrase'] = \GetMCP\Utils\Encryption::encrypt( 'synthetic-wrong-passphrase' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => wp_json_encode( array( 'ftp' => $wrong ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'A mismatched encrypted key passphrase defers migration.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => $passphrase_settings ), array( 'id' => $server->id ) );
	$passphrase_migration = \GetMCPExtensions\Upgrade180Auto::convert(); $passphrase_server = $manager->get( $server->id );
	$key_read = ( new \GetMCP\Ftp\FtpExecutor() )->execute( $tool, array( 'path' => '/readme.txt' ), $passphrase_server );
	$check( ! is_wp_error( $passphrase_migration ) && $passphrase_settings === $passphrase_server->settings && 200 === $key_read['status_code'] && str_contains( $key_read['body'], 'synthetic-sftp-row' ), 'Encrypted private-key passphrases survive migration and authentication.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic SFTP connector' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row );
	if ( $tool ) { $tools->delete( $tool->id ); } if ( $server ) { $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); } }
	if ( $gateway ) { $current = $manager->get( $gateway->id ); if ( $current && $current->slug === 'qa-180-sftp-gateway-' . $suffix ) { $manager->delete( $gateway->id ); } }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); } wp_set_current_user( $previous_user );
}
