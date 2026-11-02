<?php
/** Local-only existing legacy remote management contract on 1.8. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/auth/class-oauth-user-access.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-manager.php';
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$id = (int) ( $state['remote'] ?? 0 );
$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", $id ), ARRAY_A );
if ( ! $before || 'remote-mcp' !== $before['server_kind'] || 'wordpress' !== $before['auth_type'] ) { throw new RuntimeException( 'Converted synthetic remote required.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
wp_set_current_user( 1 );
try {
	$extra_auth = json_decode( $before['auth_config'], true );
	$extra_auth['qa_preserve'] = 'synthetic-auth-metadata';
	$extra_settings = json_decode( $before['settings'], true );
	$extra_settings['remote_mcp']['qa_private_future_field'] = 'synthetic-private-metadata';
	$extra_settings['qa_preserve'] = array( 'enabled' => true );
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $extra_auth ), 'settings' => wp_json_encode( $extra_settings ) ), array( 'id' => $id ) );
	$expected_settings = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id=%d", $id ) );
	$read = \GetMCP\Gateway\FeatureManager::run( 'get', array( 'id' => $id ), 1 );
	$check( 'remote-mcp' === $read['kind'] && $read['name'] === $before['name'], 'Existing legacy connection remains visible.' );
	$check( ! isset( $read['remote']['qa_private_future_field'] ), 'Unknown stored metadata is not exposed by management output.' );
	$saved = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $id, 'name' => 'QA 180 managed remote updated', 'allowed_user_ids' => array( 1 ) ), 1 );
	$after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
	$auth = json_decode( $after['auth_config'], true );
	$check( 'wordpress' === $after['auth_type'] && 'remote-mcp' === $after['server_kind'], 'Saving preserves native sign-in and legacy kind.' );
	$check( array( 1 ) === $auth['allowed_user_ids'] && 'getmcp' === $auth['provider'] && 'synthetic-auth-metadata' === $auth['qa_preserve'], 'Only the requested allowlist change is applied.' );
	$check( $after['settings'] === $expected_settings && $after['outbound_auth_credentials'] === $before['outbound_auth_credentials'], 'Remote settings and stored credentials survive the edit.' );
	$check( $saved['id'] === $id && $saved['name'] === 'QA 180 managed remote updated', 'Saved connection is returned.' );
	$refused = false;
	try { \GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $id, 'name' => 'unauthorized' ), 0 ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused, 'Unauthorized management is refused.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic 1.8 management adapter' ) ) . PHP_EOL;
} finally {
	$wpdb->update( $table, array( 'name' => $before['name'], 'server_kind' => $before['server_kind'], 'auth_type' => $before['auth_type'], 'auth_config' => $before['auth_config'], 'settings' => $before['settings'], 'outbound_auth_credentials' => $before['outbound_auth_credentials'], 'updated_at' => $before['updated_at'] ), array( 'id' => $id ) );
}
