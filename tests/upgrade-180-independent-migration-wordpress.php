<?php
/** Independent migrations must progress while unsafe dependency groups stay guarded. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$remote_source = $wpdb->get_row( "SELECT * FROM {$table} WHERE slug IN ('qa-180-browser-remote','qa-180-automatic-remote') ORDER BY id LIMIT 1", ARRAY_A );
$gateway_source = $wpdb->get_row( "SELECT * FROM {$table} WHERE slug IN ('qa-180-browser-gateway','qa-180-automatic-gateway') ORDER BY id LIMIT 1", ARRAY_A );
if ( ! $remote_source || ! $gateway_source ) { throw new RuntimeException( 'Disposable browser fixtures are required.' ); }
$ids = array(); $scope = array(); $checks = 0; $token_id = 0; $token_hash = ''; $tool_id = 0; $old_header = $_SERVER['HTTP_X_QA_REGION'] ?? null;
$old_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, false );
$filter = static function () use ( &$scope ): array { return $scope; };
add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$check = static function ( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$clone = static function ( array $row, string $name ) use ( &$ids, $wpdb, $table ): int {
	unset( $row['id'] ); $row['uuid'] = wp_generate_uuid4(); $row['server_id'] = substr( hash( 'sha256', $row['uuid'] ), 0, 16 );
	$row['slug'] = 'qa-180-independent-' . substr( $row['uuid'], 0, 8 ); $row['name'] = $name; $row['auth_type'] = 'oauth';
	if ( 1 !== $wpdb->insert( $table, $row ) ) { throw new RuntimeException( 'Could not create synthetic migration record.' ); }
	$ids[] = (int) $wpdb->insert_id; return end( $ids );
};
try {
	$good = $clone( $remote_source, 'QA independent safe remote' );
	$bad_auth = json_decode( $remote_source['auth_config'], true ); $bad_auth['allowed_user_ids'] = array( '34' );
	$bad = $clone( array_replace( $remote_source, array( 'auth_config' => wp_json_encode( $bad_auth ) ) ), 'QA independent unsafe remote' );
	$settings = json_decode( $gateway_source['settings'], true ); $settings['gateway']['server_ids'] = array( $bad );
	$gateway = $clone( array_replace( $gateway_source, array( 'settings' => wp_json_encode( $settings ) ) ), 'QA dependent unsafe gateway' );
	$scope = array( $good, $bad, $gateway );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'], 'An unrelated safe remote migrates despite an unsafe dependency group.' );
	$check( 'wordpress' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $good ) ), 'The safe server uses native WordPress sign-in.' );
	$check( 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $bad ) ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway ) ), 'Unsafe remote and dependent gateway stay unchanged.' );
	$bad_auth['allowed_user_ids'] = array( 34 ); $wpdb->update( $table, array( 'auth_config' => wp_json_encode( $bad_auth ) ), array( 'id' => $bad ) );
	$scope = array( $gateway );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( is_wp_error( $result ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway ) ), 'A gateway cannot convert while its legacy member is outside the migration scope.' );
	$member = ( new \GetMCP\Core\ServerManager() )->get( $bad );
	$token_hash = hash( 'sha256', 'synthetic-pending-' . wp_generate_uuid4() );
	$inserted = $wpdb->insert( $wpdb->prefix . 'getmcp_oauth_tokens', array( 'client_id' => 0, 'user_id' => 34, 'access_token_hash' => '', 'authorization_code_hash' => $token_hash, 'resource' => \GetMCP\Auth\FirstPartyOAuth::canonical_resource( $member ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ) ) );
	$check( 1 === $inserted, 'Synthetic pending authorization code created.' ); $token_id = (int) $wpdb->insert_id;
	$scope = array( $bad, $gateway ); $result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( is_wp_error( $result ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $bad ) ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway ) ), 'A pending code without redirect blocks its member and dependent gateway.' );
	$wpdb->delete( $wpdb->prefix . 'getmcp_oauth_tokens', array( 'id' => $token_id, 'authorization_code_hash' => $token_hash ) ); $token_id = 0;
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 2 === $result['migrated'] && 'wordpress' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $gateway ) ), 'Dependency group migrates together after the blocker is removed.' );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && 0 === $result['migrated'], 'Repeating a completed migration is idempotent.' );
	$native_settings = wp_json_encode( array( 'description' => 'Synthetic description', 'instructions' => 'Synthetic instructions' ) );
	$native = $clone( array_replace( $remote_source, array( 'server_kind' => 'native', 'settings' => $native_settings, 'outbound_auth_type' => null, 'outbound_auth_credentials' => null ) ), 'QA standard server with description' );
	$scope = array( $native ); $result = \GetMCPExtensions\Upgrade180Auto::convert();
	$native_after = ( new \GetMCP\Core\ServerManager() )->get( $native );
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'] && $native_settings === $native_after->settings && 'Synthetic instructions' === $native_after->get_instructions(), 'Reviewed presentation settings migrate unchanged on a standard server.' );
	$protected_settings = wp_json_encode( array( 'logo_id' => 0, 'logo_url' => '', 'icon_data' => '', 'variables' => array( 'region' => 'test-region' ), 'header_variables' => array( 'tenant' => 'X-QA-Region' ), 'pii_redaction' => array( 'enabled' => true, 'scope' => 'both', 'presets' => array( 'email' ), 'custom' => array() ), 'launch_kit' => array( 'visibility' => 'public', 'headline' => 'Synthetic page' ) ) );
	$protected = $clone( array_replace( $remote_source, array( 'server_kind' => 'native', 'settings' => $protected_settings, 'outbound_auth_type' => null, 'outbound_auth_credentials' => null ) ), 'QA standard server with privacy settings' );
	$scope = array( $protected ); $result = \GetMCPExtensions\Upgrade180Auto::convert(); $protected_after = ( new \GetMCP\Core\ServerManager() )->get( $protected );
	$privacy = \GetMCP\Security\PiiRedactor::config_from_settings( $protected_after->settings );
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'] && $protected_settings === $protected_after->settings && true === $privacy['enabled'] && 'both' === $privacy['scope'] && array( 'email' ) === $privacy['presets'], 'Branding and active privacy settings migrate without changing their native behavior.' );
	$check( '[REDACTED:EMAIL]' === \GetMCP\Security\PiiRedactor::redact( 'test@example.org', $privacy ), 'The native 1.8 privacy filter still redacts the configured data.' );
	$kit = \GetMCP\LaunchKit\LaunchKitSettings::from_server( $protected_after );
	$check( 'Synthetic page' === $kit['headline'] && \GetMCP\LaunchKit\LaunchKitSettings::is_published( $kit, $protected_after ), 'The existing published Launch Kit remains available.' );
	$variable_tool = new \GetMCP\Core\Tool( array( 'endpoint_url' => 'https://example.org/{{region}}/api', 'http_method' => 'GET', 'headers' => '{}', 'parameter_mapping' => '{}' ) );
	$resolved = ( new \GetMCP\Execution\ParameterResolver() )->resolve( $variable_tool, array(), $protected_after->get_variables() );
	$check( 'https://example.org/test-region/api' === $resolved['url'], 'Fixed connection variables retain their request substitution.' );
	$_SERVER['HTTP_X_QA_REGION'] = 'header-region'; $variable_tool->endpoint_url = 'https://example.org/{{tenant}}/api';
	$resolved = ( new \GetMCP\Execution\ParameterResolver() )->resolve( $variable_tool, array(), $protected_after->resolve_request_variables() );
	$check( 'https://example.org/header-region/api' === $resolved['url'], 'Header-derived path variables retain their request substitution.' );
	$unsafe_host = $clone( array_replace( $remote_source, array( 'server_kind' => 'native', 'settings' => $protected_settings ) ), 'QA server with caller-selected host' );
	$tool = ( new \GetMCP\Core\ToolManager() )->create( array( 'server_id' => $unsafe_host, 'name' => 'QA caller host', 'slug' => 'qa_caller_host', 'endpoint_url' => 'https://{{tenant}}.example.org/api', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active' ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic caller-host tool.' ); } $tool_id = $tool->id;
	$scope = array( $unsafe_host ); $check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'Caller-selected API hosts remain deferred under the stronger native security rule.' );
	$bad_privacy = wp_json_encode( array( 'pii_redaction' => array( 'enabled' => true, 'scope' => 'unknown' ) ) );
	$invalid_privacy = $clone( array_replace( $remote_source, array( 'server_kind' => 'native', 'settings' => $bad_privacy ) ), 'QA standard server with unreviewed privacy settings' );
	$scope = array( $invalid_privacy ); $check( is_wp_error( \GetMCPExtensions\Upgrade180Auto::convert() ), 'Unreviewed privacy settings defer migration.' );
	$unknown_settings = wp_json_encode( array( 'unknown_extension' => array( 'enabled' => true ) ) );
	$unknown = $clone( array_replace( $remote_source, array( 'server_kind' => 'native', 'settings' => $unknown_settings, 'outbound_auth_type' => null, 'outbound_auth_credentials' => null ) ), 'QA standard server with unknown settings' );
	$scope = array( $unknown ); $result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( is_wp_error( $result ) && 'oauth' === $wpdb->get_var( $wpdb->prepare( "SELECT auth_type FROM {$table} WHERE id=%d", $unknown ) ), 'Unknown standard-server behavior still defers migration.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic independent migration groups' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
	if ( $token_id ) { $wpdb->delete( $wpdb->prefix . 'getmcp_oauth_tokens', array( 'id' => $token_id, 'authorization_code_hash' => $token_hash ) ); }
	if ( $tool_id ) { ( new \GetMCP\Core\ToolManager() )->delete( $tool_id ); }
	foreach ( $ids as $id ) { $wpdb->delete( $table, array( 'id' => $id ) ); }
	if ( false === $old_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $old_ledger ); }
	if ( null === $old_header ) { unset( $_SERVER['HTTP_X_QA_REGION'] ); } else { $_SERVER['HTTP_X_QA_REGION'] = $old_header; }
}
