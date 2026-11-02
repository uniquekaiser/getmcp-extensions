<?php
/** Real-core regression for legacy native and mailbox execution preferences. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb; $manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $servers = array(); $tool_ids = array(); $scope = array(); $checks = 0; $old_user = get_current_user_id(); $old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false );
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); wp_set_current_user( 1 );
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
 $prefs = array( 'description' => 'Synthetic legacy server', 'default_timeout' => 30, 'pii_redaction' => array( 'enabled' => false, 'scope' => 'both', 'presets' => array(), 'custom' => array() ) );
 $method = new ReflectionMethod( \GetMCPExtensions\Upgrade180Auto::class, 'safe_native_settings' );
 $check( $method->invoke( null, $prefs ), 'Existing bounded native timeout must be accepted.' );
 foreach ( array( '30', 0, 301 ) as $bad ) { $modified = $prefs; $modified['default_timeout'] = $bad; $check( ! $method->invoke( null, $modified ), 'Malformed or unbounded timeout must be refused.' ); }
 $unknown = $prefs; $unknown['unreviewed_setting'] = true; $check( ! $method->invoke( null, $unknown ), 'Unreviewed native setting must remain deferred.' );
 foreach ( array( 'http', 'mailbox' ) as $kind ) {
  $settings = $prefs;
  if ( 'mailbox' === $kind ) { $settings['mailbox'] = array( 'provider' => 'other', 'host' => 'mail.example.invalid', 'port' => 993, 'encryption' => 'ssl', 'username' => 'synthetic-user', 'auth_mode' => 'password', 'validate_cert' => true, 'default_folder' => 'INBOX', 'password' => \GetMCP\Utils\Encryption::encrypt( 'synthetic-password' ) ); }
  $slug = 'qa-native-timeout-' . $kind . '-' . substr( wp_generate_uuid4(), 0, 8 );
  $server = $manager->create( array( 'name' => 'QA native timeout ' . $kind, 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ) ) ), 'settings' => wp_json_encode( $settings ) ) ); if ( ! $server ) { throw new RuntimeException( 'Could not create synthetic server.' ); } $servers[] = $server;
  $tool_data = array( 'server_id' => $server->id, 'name' => 'QA timeout read', 'slug' => 'qa_timeout_read', 'tool_type' => $kind, 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) );
  if ( 'http' === $kind ) { $tool_data += array( 'endpoint_url' => 'https://example.org/qa-read', 'http_method' => 'GET' ); } else { $tool_data['handler'] = 'list_emails'; }
  $tool = $tools->create( $tool_data ); if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic tool.' ); } $tool_ids[] = $tool->id;
  $wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) ); $scope[] = (int) $server->id;
 }
 $result = \GetMCPExtensions\Upgrade180Auto::convert(); $check( ! is_wp_error( $result ) && 2 === $result['migrated'], 'Both HTTP and mailbox servers should migrate automatically.' );
 foreach ( $servers as $server ) { $current = $manager->get( $server->id ); $check( 'wordpress' === $current->auth_type && $current->settings === $server->settings && $current->auth_config === $server->auth_config, 'Settings, encrypted credentials and access rules must remain unchanged.' ); }
 echo wp_json_encode( array( 'passed' => $checks ) ) . PHP_EOL;
} finally {
 remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); foreach ( $tool_ids as $id ) { $tools->delete( $id ); } foreach ( $servers as $server ) { $current = $manager->get( $server->id ); if ( $current && $current->slug === $server->slug ) { $manager->delete( $server->id ); } } if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); } wp_set_current_user( $old_user );
}
