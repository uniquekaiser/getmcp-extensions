<?php
/** An administrator is alerted when historic built-in Gateway access is unknowable. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb; $manager = new \GetMCP\Core\ServerManager(); $suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $server = null; $scope = array(); $checks = 0;
$names = array( 'getmcp_gateway_enabled', 'getmcp_gateway_auth_type', 'getmcp_gateway_mode', 'getmcp_gateway_servers', 'getmcp_extensions_180_gateway_snapshot', \GetMCPExtensions\Upgrade180Auto::LEDGER, 'getmcp_db_version' );
$prior = array(); foreach ( $names as $name ) { $prior[$name] = get_option( $name, null ); }
if ( null !== $prior[\GetMCPExtensions\LegacyGateway180::OPTION] ) { $saved = json_decode( (string) $prior[\GetMCPExtensions\LegacyGateway180::OPTION], true ); foreach ( array_keys( $saved['members'] ?? array() ) as $id ) { $s = $manager->get( (int) $id ); if ( ! $s || ! str_starts_with( $s->name, 'QA 180 ' ) ) { throw new RuntimeException( 'Only synthetic snapshots may be replaced.' ); } } delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); }
$user = get_current_user_id(); $filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
	wp_set_current_user( 1 ); $server = $manager->create( array( 'name' => 'QA 180 Gateway review', 'slug' => 'qa-180-review-' . $suffix, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => '{}' ) );
	if ( ! $server ) { throw new RuntimeException( 'Could not create synthetic member.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	update_option( 'getmcp_gateway_enabled', 1 ); update_option( 'getmcp_gateway_auth_type', 'oauth' ); update_option( 'getmcp_gateway_mode', 'selected' ); update_option( 'getmcp_gateway_servers', $scope );
	$check( \GetMCPExtensions\LegacyGateway180::review_required(), 'An unconverted legacy member is flagged before it can be mistaken for working access.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'server_kind' => 'gateway' ), array( 'id' => $server->id ) );
	$check( ! \GetMCPExtensions\LegacyGateway180::review_required(), 'Project gateways were never published by the account Gateway and must not cause a history warning.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'server_kind' => 'remote-mcp', 'settings' => wp_json_encode( array( 'remote_mcp' => array( 'publish_original' => false ) ) ) ), array( 'id' => $server->id ) );
	$check( ! \GetMCPExtensions\LegacyGateway180::review_required(), 'A remote server not published by the account Gateway must not cause a history warning.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'server_kind' => 'native', 'settings' => '{}' ), array( 'id' => $server->id ) );
	ob_start(); \GetMCPExtensions\Upgrade180Auto::notice(); $deferred = (string) ob_get_clean();
	$check( str_contains( $deferred, 'QA 180 Gateway review' ) && str_contains( $deferred, 'ID ' . $server->id ) && str_contains( $deferred, 'active' ) && ! str_contains( $deferred, 'allowed_user_ids' ), 'The deferred notice identifies the affected server without exposing its sign-in settings.' );
	wp_set_current_user( 0 ); ob_start(); \GetMCPExtensions\Upgrade180Auto::notice(); $anonymous_deferred = (string) ob_get_clean(); wp_set_current_user( 1 );
	$check( '' === $anonymous_deferred, 'The deferred notice is visible only to an administrator.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'name' => '<script>alert(1)</script>' ), array( 'id' => $server->id ) );
	ob_start(); \GetMCPExtensions\Upgrade180Auto::notice(); $escaped_deferred = (string) ob_get_clean();
	ob_start(); \GetMCPExtensions\LegacyGateway180::notice(); $escaped_gateway = (string) ob_get_clean();
	$check( str_contains( $escaped_deferred, '&lt;script&gt;' ) && str_contains( $escaped_gateway, '&lt;script&gt;' ) && ! str_contains( $escaped_deferred . $escaped_gateway, '<script>' ), 'Server names are escaped in both administrator notices.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'name' => 'QA 180 Gateway review' ), array( 'id' => $server->id ) );
	$check( false !== has_action( 'admin_notices', array( \GetMCPExtensions\LegacyGateway180::class, 'notice' ) ), 'The warning is registered on administrator screens.' );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $result ) && \GetMCPExtensions\LegacyGateway180::review_required(), 'An already-upgraded Gateway without historical evidence is flagged.' );
	$before = array( get_option( 'getmcp_gateway_servers' ), get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ), $manager->get( $server->id )->auth_config );
	ob_start(); \GetMCPExtensions\LegacyGateway180::notice(); $notice = (string) ob_get_clean();
	$check( str_contains( $notice, 'QA 180 Gateway review' ) && str_contains( $notice, 'ID ' . $server->id ) && str_contains( $notice, 'getmcp-gateway' ), 'The administrator sees the affected Gateway member and a direct review link.' );
	wp_set_current_user( 0 ); ob_start(); \GetMCPExtensions\LegacyGateway180::notice(); $anonymous = (string) ob_get_clean(); wp_set_current_user( 1 );
	$check( '' === $anonymous && $before === array( get_option( 'getmcp_gateway_servers' ), get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ), $manager->get( $server->id )->auth_config ), 'The warning is administrator-only and does not change access or settings.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) ); update_option( 'getmcp_db_version', '1.26' ); $prepared = \GetMCPExtensions\LegacyGateway180::prepare(); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] ); $result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $prepared ) && ! is_wp_error( $result ) && ! \GetMCPExtensions\LegacyGateway180::review_required(), 'A normal upgrade with a valid captured history has no warning.' );
	update_option( 'getmcp_gateway_mode', 'all' ); $check( \GetMCPExtensions\LegacyGateway180::review_required(), 'Changed Gateway policy invalidates the old historical exception and is flagged.' );
	update_option( 'getmcp_gateway_mode', 'selected' ); update_option( 'getmcp_gateway_servers', array() ); $check( ! \GetMCPExtensions\LegacyGateway180::review_required(), 'An empty Gateway has no affected member to review.' );
	update_option( 'getmcp_gateway_servers', $scope ); update_option( 'getmcp_gateway_enabled', 0 ); $check( ! \GetMCPExtensions\LegacyGateway180::review_required(), 'A disabled Gateway is not flagged.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic Gateway history review' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); if ( $server ) { $s = $manager->get( $server->id ); if ( $s && str_ends_with( $s->slug, $suffix ) ) { $manager->delete( $server->id ); } }
	foreach ( $prior as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } } wp_set_current_user( $user );
}
