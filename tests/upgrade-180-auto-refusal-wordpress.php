<?php
/** Verify automatic sign-in conversion refuses unsafe synthetic batches atomically. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$tokens = $wpdb->prefix . 'getmcp_oauth_tokens';
$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", (int) $state['remote'] ), ARRAY_A );
$gateway_source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic gateway'", (int) $state['gateway'] ), ARRAY_A );
if ( ! $source || ! $gateway_source || 'wordpress' !== $source['auth_type'] || 'wordpress' !== $gateway_source['auth_type'] ) { throw new RuntimeException( 'Converted synthetic source records required.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$ids = array(); $token_id = 0; $token_hash = ''; $scope = array(); $tool_id = 0; $tool_uuid = '';
$scope_filter = static function () use ( &$scope ): array { return $scope; };
add_filter( 'getmcp_extensions_180_migration_scope', $scope_filter, 1000 );
$create = static function ( array $row, string $slug, string $name ) use ( &$ids, $wpdb, $table ): int {
	unset( $row['id'] );
	$row['uuid'] = wp_generate_uuid4(); $row['server_id'] = bin2hex( random_bytes( 8 ) ); $row['slug'] = $slug; $row['name'] = $name;
	if ( 1 !== $wpdb->insert( $table, $row ) ) { throw new RuntimeException( 'Could not create refusal fixture.' ); }
	$id = (int) $wpdb->insert_id; $ids[] = array( 'id' => $id, 'slug' => $slug ); return $id;
};
try {
	$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER );
	$source['auth_type'] = 'oauth';
	$bad_auth = json_decode( $source['auth_config'], true ); $bad_auth['allowed_user_ids'] = array( '1' );
	$source['auth_config'] = wp_json_encode( $bad_auth );
	$remote = $create( $source, 'qa-180-refusal-remote', 'QA 180 refusal remote' );
	$gateway_source['auth_type'] = 'oauth';
	$auth = json_decode( $gateway_source['auth_config'], true ); $auth['allowed_user_ids'] = array( 1 ); $gateway_source['auth_config'] = wp_json_encode( $auth );
	$settings = json_decode( $gateway_source['settings'], true ); $settings['gateway']['server_ids'] = array( $remote ); $gateway_source['settings'] = wp_json_encode( $settings );
	$gateway = $create( $gateway_source, 'qa-180-refusal-gateway', 'QA 180 refusal gateway' );
	$scope = array( $remote, $gateway );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( is_wp_error( $result ), 'Malformed allowlist refuses automatic conversion.' );
	$check( 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway ) ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $remote ) ), 'Invalid batch leaves both server types unchanged.' );
	$legacy_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $gateway ) );
	$check( ! \GetMCPExtensions\Runtime::server_enabled( $legacy_row ), 'An unconverted gateway remains guarded even when the compatibility runtime is active.' );
	$check( $old_ledger === get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ), 'Rejected batch leaves the migration ledger unchanged.' );
	$bad_auth['allowed_user_ids'] = array( 1 );
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $bad_auth ) ), array( 'id' => $remote ) );
	$server = ( new \GetMCP\Core\ServerManager() )->get( $remote );
	$resource = \GetMCP\Auth\FirstPartyOAuth::canonical_resource( $server );
	$client = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $state['client'] ) );
	$token_hash = hash( 'sha256', 'synthetic-pending-' . wp_generate_uuid4() );
	$check( 1 === $wpdb->insert( $tokens, array( 'client_id' => $client, 'user_id' => 1, 'access_token_hash' => '', 'authorization_code_hash' => $token_hash, 'resource' => $resource, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ) ) ), 'Synthetic code without redirect created.' );
	$token_id = (int) $wpdb->insert_id;
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'Pending code without its original redirect blocks conversion.' );
	$check( 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $remote ) ), 'Pending code refusal leaves the server unchanged.' );
	$wpdb->delete( $tokens, array( 'id' => $token_id, 'authorization_code_hash' => $token_hash ) ); $token_id = 0;
	$member = $source; $member['server_kind'] = 'native'; $member['auth_type'] = 'oauth'; $member['outbound_auth_type'] = null; $member['outbound_auth_credentials'] = null; $member['settings'] = '{}';
	$member_id = $create( $member, 'qa-180-refusal-native', 'QA 180 refusal native' );
	$settings['gateway']['server_ids'] = array( $member_id );
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'], 'An unrelated safe remote still migrates.' );
	$check( 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway ) ) && 'wordpress' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $remote ) ), 'Unsupported member defers its gateway without blocking another server.' );
	$test_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER );
	$member_auth = json_decode( $member['auth_config'], true ); $member_auth['allowed_user_ids'] = array( 1 );
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $member_auth ) ), array( 'id' => $member_id ) );
	$tool_uuid = wp_generate_uuid4();
	$check( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_tools', array( 'uuid' => $tool_uuid, 'server_id' => $member_id, 'name' => 'QA unsupported feature', 'slug' => 'google_health_qa_refusal', 'description' => '', 'endpoint_url' => 'https://example.org/api', 'tool_type' => 'http', 'status' => 'active' ) ), 'Synthetic add-on-specific tool created.' );
	$tool_id = (int) $wpdb->insert_id; $scope = array( $member_id );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $member_id ) ), 'Standard server with unsupported add-on behavior remains guarded.' );
	$scope = array( 'invalid-id' );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ) && $test_ledger === get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ), 'Malformed migration scope is refused without ledger changes.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic automatic migration refusals' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $scope_filter, 1000 );
	if ( $tool_id ) { $wpdb->delete( $wpdb->prefix . 'getmcp_tools', array( 'id' => $tool_id, 'uuid' => $tool_uuid ) ); }
	if ( $token_id ) { $wpdb->delete( $tokens, array( 'id' => $token_id, 'authorization_code_hash' => $token_hash ) ); }
	foreach ( $ids as $item ) { $wpdb->delete( $table, $item ); }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); }
}
