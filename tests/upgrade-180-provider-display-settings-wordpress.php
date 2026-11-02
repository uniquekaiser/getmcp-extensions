<?php
/** Migrate a paused legacy gateway with reviewed provider execution settings. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed local GetMCP 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager();
$previous_user = get_current_user_id(); $old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false );
$ids = array(); $tool_ids = array(); $scope = array(); $checks = 0;
$filter = static function() use ( &$scope ): array { return $scope; };
$check = static function( bool $value, string $message ) use ( &$checks ): void { ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } };
add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); wp_set_current_user( 1 );
try {
	$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$settings = array( 'instructions' => '', 'default_timeout' => 30, 'personal_provider' => array( 'allowed_user_ids' => array(), 'allow_all_users' => true ), 'pii_redaction' => array( 'enabled' => false, 'scope' => 'both', 'presets' => array(), 'custom' => array() ) );
	$provider = $manager->create( array( 'name' => 'QA 180 provider settings', 'slug' => 'qa-provider-settings-' . $suffix, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'client_id' => 'synthetic-client' ) ), 'settings' => wp_json_encode( $settings ) ) );
	if ( ! $provider ) { throw new RuntimeException( 'Could not create synthetic provider.' ); } $ids[] = array( $provider->id, $provider->slug );
	$tool = $tools->create( array( 'server_id' => $provider->id, 'name' => 'QA read', 'slug' => 'qa_read', 'endpoint_url' => 'https://googleads.googleapis.com/qa', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic tool.' ); } $tool_ids[] = $tool->id;
	$members = array( $provider->id );
	foreach ( array( 'analyticsdata.googleapis.com', 'searchconsole.googleapis.com' ) as $index => $host ) {
		$member = $manager->create( array( 'name' => 'QA 180 provider member ' . $index, 'slug' => 'qa-provider-member-' . $index . '-' . $suffix, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'client_id' => 'synthetic-client' ) ), 'settings' => wp_json_encode( array( 'instructions' => '' ) ) ) );
		if ( ! $member ) { throw new RuntimeException( 'Could not create synthetic member.' ); } $ids[] = array( $member->id, $member->slug ); $members[] = $member->id;
		$member_tool = $tools->create( array( 'server_id' => $member->id, 'name' => 'QA member read', 'slug' => 'qa_member_read', 'endpoint_url' => 'https://' . $host . '/qa', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
		if ( ! $member_tool ) { throw new RuntimeException( 'Could not create synthetic member tool.' ); } $tool_ids[] = $member_tool->id;
	}
	$auth = wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ) ) );
	$gateway = $manager->create( array( 'name' => 'QA 180 paused gateway', 'slug' => 'qa-paused-gateway-' . $suffix, 'status' => 'paused', 'auth_type' => 'wordpress', 'auth_config' => $auth, 'settings' => wp_json_encode( array( 'gateway' => array( 'server_ids' => $members ) ) ) ) );
	if ( ! $gateway ) { throw new RuntimeException( 'Could not create synthetic gateway.' ); } $ids[] = array( $gateway->id, $gateway->slug );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'server_kind' => 'gateway' ), array( 'id' => $gateway->id ) ); $scope = array( (int) $gateway->id );
	$bad = $settings; $bad['unknown_setting'] = true; $wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( $bad ) ), array( 'id' => $provider->id ) );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( is_wp_error( $result ) && 'oauth' === $manager->get( $gateway->id )->auth_type, 'An unreviewed provider setting must defer migration.' );
	$bad_cases = array();
	$bad = $settings; $bad['default_timeout'] = '30'; $bad_cases['string timeout'] = $bad;
	$bad = $settings; $bad['default_timeout'] = 301; $bad_cases['unbounded timeout'] = $bad;
$bad = $settings; $bad['personal_provider']['allow_all_users'] = 'yes'; $bad_cases['malformed all-users setting'] = $bad;
	$bad = $settings; $bad['pii_redaction']['enabled'] = true; $bad_cases['enabled privacy policy'] = $bad;
	$bad = $settings; $bad['pii_redaction']['custom'] = array( array( 'pattern' => 'unknown' ) ); $bad_cases['custom privacy policy'] = $bad;
	$bad = $settings; $bad['pii_redaction']['unexpected'] = true; $bad_cases['unknown privacy field'] = $bad;
	foreach ( $bad_cases as $label => $bad ) {
		$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( $bad ) ), array( 'id' => $provider->id ) );
		$result = \GetMCPExtensions\Upgrade180Auto::convert();
		$check( is_wp_error( $result ) && 'oauth' === $manager->get( $gateway->id )->auth_type, 'Migration must defer the ' . $label . ' case.' );
	}
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $provider->id ) );
	$before = $wpdb->get_row( $wpdb->prepare( "SELECT status,settings,auth_config FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $gateway->id ), ARRAY_A );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'], 'The reviewed timeout and disabled privacy settings must not defer migration.' );
	$after = $wpdb->get_row( $wpdb->prepare( "SELECT status,settings,auth_config FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $gateway->id ), ARRAY_A );
	$check( $before === $after && 'paused' === $after['status'] && 'wordpress' === $manager->get( $gateway->id )->auth_type, 'Migration preserves the paused state, member list and allowlist.' );
	$check( $settings === json_decode( (string) $manager->get( $provider->id )->settings, true ), 'Provider settings remain unchanged.' );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $check( ! is_wp_error( $result ) && 0 === $result['migrated'], 'Repeated migration is idempotent.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic paused provider gateway settings' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
	foreach ( $tool_ids as $tool_id ) { $tools->delete( $tool_id ); }
	foreach ( array_reverse( $ids ) as $item ) { $current = $manager->get( $item[0] ); if ( $current && $current->slug === $item[1] ) { $manager->delete( $item[0] ); } }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); }
	wp_set_current_user( $previous_user );
}
