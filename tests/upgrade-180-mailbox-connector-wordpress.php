<?php
/** Synthetic IMAP and non-relaying SMTP services in the isolated fixture. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || 'getmcp180mail' !== getenv( 'GETMCP_180_QA_MAILHOST' ) ) { throw new RuntimeException( 'Isolated mailbox fixture required.' ); }
global $wpdb;
$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $slug = 'qa-180-mail-' . $suffix;
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $server = null; $gateway = null; $tool = null; $scope = array(); $checks = 0;
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false ); $previous_user = get_current_user_id();
$token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue();
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$count = static function(): int { $value = file_get_contents( 'http://getmcp180mail:9080/count' ); if ( ! is_string( $value ) || ! ctype_digit( $value ) ) { throw new RuntimeException( 'Synthetic SMTP counter unavailable.' ); } return (int) $value; };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
try {
	wp_set_current_user( 1 ); $host = getenv( 'GETMCP_180_QA_MAILHOST' );
	$config = array( 'provider' => 'custom', 'host' => $host, 'port' => 2993, 'encryption' => 'ssl', 'username' => getenv( 'GETMCP_180_QA_MAILUSER' ), 'auth_mode' => 'password', 'password' => \GetMCP\Utils\Encryption::encrypt( getenv( 'GETMCP_180_QA_MAILPASSWORD' ) ), 'validate_cert' => true, 'default_folder' => 'INBOX', 'trash_folder' => '', 'sent_folder' => '', 'smtp_host' => $host, 'smtp_port' => 2025, 'smtp_encryption' => 'tls', 'from_name' => 'Synthetic fixture', 'from_email' => 'sender@fixture.invalid' );
	$settings = wp_json_encode( array( 'mailbox' => $config ) );
	$server = $manager->create( array( 'name' => 'QA 180 mailbox connector', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => $settings ) );
	if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic mailbox server.' ); }
	$tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA mailbox read', 'tool_type' => 'mailbox', 'handler' => 'list_emails', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic mailbox tool.' ); }
	$read = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $tool, array(), $server );
	if ( 200 !== $read['status_code'] ) { echo wp_json_encode( array( 'status' => $read['status_code'], 'diagnostic' => str_replace( getenv( 'GETMCP_180_QA_MAILPASSWORD' ), '[redacted]', $read['body'] ) ) ) . PHP_EOL; }
	$check( 200 === $read['status_code'] && str_contains( $read['body'], 'Synthetic mailbox fixture' ), 'The native IMAPS connector reads its synthetic message.' );
	foreach ( array( 'tls' => 2143, 'none' => 2143 ) as $mode => $port ) {
		$variant = $config; $variant['encryption'] = $mode; $variant['port'] = $port; $copy = clone $server; $copy->settings = wp_json_encode( array( 'mailbox' => $variant ) );
		$result = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $tool, array(), $copy ); $check( 200 === $result['status_code'] && str_contains( $result['body'], 'Synthetic mailbox fixture' ), 'The synthetic IMAP transport mode works: ' . $mode );
	}
	$bad = $config; $bad['host'] = gethostbyname( $host ); $copy = clone $server; $copy->settings = wp_json_encode( array( 'mailbox' => $bad ) );
	$bad_tls = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $tool, array(), $copy ); $check( 200 !== $bad_tls['status_code'], 'The IMAPS connector refuses a certificate for another hostname.' );
	$gateway_data = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 mailbox gateway', 'slug' => 'qa-180-mail-gateway-' . $suffix, 'allowed_user_ids' => array( 1 ), 'server_ids' => array( $server->id ) ), 1 );
	$gateway = $manager->get( (int) $gateway_data['id'] ); if ( ! $gateway ) { throw new RuntimeException( 'Could not create synthetic mailbox gateway.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $gateway->id ) ); $scope = array( $server->id, $gateway->id );
	$invalid = $config; $invalid['auth_mode'] = 'unreviewed';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'mailbox' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreviewed mailbox authentication mode defers migration.' );
	$invalid = $config; $invalid['password'] = 'enc:v1:invalid';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'mailbox' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreadable mailbox credential defers migration.' );
	$invalid = $config; $invalid['validate_cert'] = 'false';
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'mailbox' => $invalid ) ) ), array( 'id' => $server->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'Malformed certificate validation settings defer migration.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => $settings ), array( 'id' => $server->id ) );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'handler' => 'unreviewed_action' ), array( 'id' => $tool->id ) );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'An unreviewed mailbox action defers migration.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_tools', array( 'handler' => 'list_emails' ), array( 'id' => $tool->id ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $live = $manager->get( $server->id );
	$check( ! is_wp_error( $result ) && 2 === $result['migrated'] && $settings === $live->settings, 'The mailbox server and gateway migrate without changing encrypted settings.' );
	$live_gateway = $manager->get( $gateway->id ); $token_row->setValue( (object) array( 'id' => 97008, 'user_id' => 1, 'server_id' => $gateway->id, 'resource' => $live_gateway->get_endpoint_url() ) );
	$handler = new \GetMCP\Gateway\FeatureHandler( $live_gateway, null ); $listed = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $listed['tools'] as $entry ) { if ( str_ends_with( $entry['name'], $tool->slug ) ) { $name = $entry['name']; } }
	$gateway_read = $name ? $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) ) : array();
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $live ) && $name && empty( $gateway_read['isError'] ) && str_contains( wp_json_encode( $gateway_read ), 'Synthetic mailbox fixture' ), 'Delegated mailbox access survives without granting direct access.' );
	$send = clone $tool; $send->handler = 'send_email';
	foreach ( array( 'tls' => 2025, 'ssl' => 2465, 'none' => 2025 ) as $mode => $port ) {
		$variant = $config; $variant['smtp_encryption'] = $mode; $variant['smtp_port'] = $port; $copy = clone $live; $copy->settings = wp_json_encode( array( 'mailbox' => $variant ) ); $before = $count();
		$sent = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $send, array( 'to' => 'recipient@fixture.invalid', 'subject' => 'Synthetic fixture', 'body' => 'synthetic-only' ), $copy );
		$check( 200 === $sent['status_code'] && $count() === $before + 1, 'The non-relaying SMTP fixture receives one synthetic message using ' . $mode );
	}
	$before = $count(); $ambiguous = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $send, array( 'to' => 'ambiguous@fixture.invalid', 'subject' => 'Synthetic fixture', 'body' => 'synthetic-only' ), $live );
	$check( 200 !== $ambiguous['status_code'] && $count() === $before + 1, 'A synthetic SMTP failure after receipt is not retried.' );
	$oauth = $config; $oauth['auth_mode'] = 'oauth'; $oauth['port'] = 3993; $oauth['password'] = \GetMCP\Utils\Encryption::encrypt( 'synthetic-oauth-token' );
	$copy = clone $live; $copy->settings = wp_json_encode( array( 'mailbox' => $oauth ) );
	$oauth_read = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $tool, array(), $copy ); $before = $count();
	$oauth_send = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $send, array( 'to' => 'recipient@fixture.invalid', 'subject' => 'Synthetic OAuth fixture', 'body' => 'synthetic-only' ), $copy );
	if ( 200 !== $oauth_read['status_code'] || 200 !== $oauth_send['status_code'] ) { echo wp_json_encode( array( 'imap_status' => $oauth_read['status_code'], 'imap_error' => str_replace( 'synthetic-oauth-token', '[redacted]', $oauth_read['body'] ), 'smtp_status' => $oauth_send['status_code'], 'smtp_error' => str_replace( 'synthetic-oauth-token', '[redacted]', $oauth_send['body'] ) ) ) . PHP_EOL; }
	$check( 200 === $oauth_read['status_code'] && str_contains( $oauth_read['body'], 'Synthetic mailbox fixture' ) && 200 === $oauth_send['status_code'] && $count() === $before + 1, 'The local XOAUTH2 IMAP and non-relaying SMTP services accept the synthetic token.' );
	$wrong = $oauth; $wrong['password'] = \GetMCP\Utils\Encryption::encrypt( 'synthetic-wrong-oauth-token' ); $bad_oauth = clone $live; $bad_oauth->settings = wp_json_encode( array( 'mailbox' => $wrong ) );
	$oauth_denied = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $tool, array(), $bad_oauth );
	$check( 200 !== $oauth_denied['status_code'], 'An invalid XOAUTH2 token is refused without substituting another credential.' );
	$oauth_settings = wp_json_encode( array( 'mailbox' => $oauth ) ); $wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => $oauth_settings ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	$oauth_migration = \GetMCPExtensions\Upgrade180Auto::convert(); $oauth_server = $manager->get( $server->id );
	$oauth_read = ( new \GetMCP\Mail\MailboxExecutor() )->execute( $tool, array(), $oauth_server );
	$check( ! is_wp_error( $oauth_migration ) && $oauth_settings === $oauth_server->settings && 200 === $oauth_read['status_code'] && str_contains( $oauth_read['body'], 'Synthetic mailbox fixture' ), 'The original encrypted OAuth mailbox token survives automatic migration.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic mailbox connector and non-relaying SMTP' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row );
	if ( $tool ) { $tools->delete( $tool->id ); } if ( $server ) { $current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); } }
	if ( $gateway ) { $current = $manager->get( $gateway->id ); if ( $current && $current->slug === 'qa-180-mail-gateway-' . $suffix ) { $manager->delete( $gateway->id ); } }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); } wp_set_current_user( $previous_user );
}
