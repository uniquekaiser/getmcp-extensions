<?php
/** Deferred legacy server migrations must be visible without changing access. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb; $manager = new \GetMCP\Core\ServerManager(); $suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $server = null; $scope = array(); $checks = 0;
$prior_ledger = get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, null ); $user = get_current_user_id();
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
	wp_set_current_user( 1 ); $server = $manager->create( array( 'name' => 'QA 180 deferred notice', 'slug' => 'qa-180-deferred-notice-' . $suffix, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ) ) ), 'settings' => wp_json_encode( array( 'unreviewed_setting' => 'synthetic' ) ) ) );
	if ( ! $server ) { throw new RuntimeException( 'Could not create synthetic server.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth', 'settings' => wp_json_encode( array( 'unreviewed_setting' => 'synthetic' ) ) ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	$before = $wpdb->get_row( $wpdb->prepare( "SELECT auth_type,auth_config,settings FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $server->id ), ARRAY_A );
	$result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ( is_wp_error( $result ) && 'extensions_auto_migration_deferred' === $result->get_error_code() ) || ( ! is_wp_error( $result ) && 1 === $result['deferred'] ), 'The unknown synthetic configuration is deferred.' );
	ob_start(); \GetMCPExtensions\Upgrade180Auto::notice(); $notice = (string) ob_get_clean();
	$check( str_contains( $notice, 'legacy server' ) && str_contains( $notice, 'review' ), 'Administrators see that a legacy server needs review.' );
	wp_set_current_user( 0 ); ob_start(); \GetMCPExtensions\Upgrade180Auto::notice(); $anonymous = (string) ob_get_clean(); wp_set_current_user( 1 );
	$check( '' === $anonymous && $before === $wpdb->get_row( $wpdb->prepare( "SELECT auth_type,auth_config,settings FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $server->id ), ARRAY_A ), 'The notice is administrator-only and does not mutate the server.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => '{}' ), array( 'id' => $server->id ) ); $result = \GetMCPExtensions\Upgrade180Auto::convert();
	ob_start(); \GetMCPExtensions\Upgrade180Auto::notice(); $cleared = (string) ob_get_clean();
	$check( ! is_wp_error( $result ) && '' === $cleared, 'The notice clears when the affected server has converted.' );
	$check( false !== has_action( 'admin_notices', array( \GetMCPExtensions\Upgrade180Auto::class, 'notice' ) ), 'The notice is registered on administrator screens.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic deferred migration notice' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); if ( $server ) { $s = $manager->get( $server->id ); if ( $s && str_ends_with( $s->slug, $suffix ) ) { $manager->delete( $server->id ); } }
	if ( null === $prior_ledger ) { delete_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ); } else { update_option( \GetMCPExtensions\Upgrade180Auto::LEDGER, $prior_ledger ); } wp_set_current_user( $user );
}
