<?php
/** Legacy access-management calls must preserve 1.8's native grant checks. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_UI' ) ) { throw new RuntimeException( 'Isolated 1.8 UI fixture required.' ); }
$actor = get_user_by( 'login', 'qa_extensions_180_browser' ); $viewer = get_user_by( 'login', 'qa_extensions_180_viewer' );
if ( ! $actor || ! $viewer ) { throw new RuntimeException( 'Disposable browser users are required.' ); }
$manager = new \GetMCP\Core\ServerManager(); $slug = 'qa-180-access-' . substr( wp_generate_uuid4(), 0, 8 );
$server = $manager->create( array( 'name' => 'QA 180 legacy access', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ), 'synthetic_metadata' => 'preserve' ) ), 'settings' => '{}' ) );
if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic server.' ); }
$checks = 0; $previous_user = get_current_user_id(); wp_set_current_user( $actor->ID );
$check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
try {
	$current = \GetMCP\Auth\OAuthUserAccess::get( $server->id, $actor->ID );
	$check( ! is_wp_error( $current ) && array( 1 ) === $current['allowed_user_ids'], 'Legacy management reads the native allowlist.' );
	$saved = \GetMCP\Auth\OAuthUserAccess::set( $server->id, array( (int) $actor->ID ), array( 1 ), $actor->ID );
	$after = $manager->get( $server->id ); $config = json_decode( $after->auth_config, true );
	$check( ! is_wp_error( $saved ) && array( (int) $actor->ID ) === $saved['allowed_user_ids'] && 'preserve' === ( $config['synthetic_metadata'] ?? null ), 'Legacy management saves only the requested allowlist.' );
	$check( \GetMCP\Auth\ServerUserAccess::can_user_access( $actor->ID, $after ) && ! \GetMCP\Auth\ServerUserAccess::can_user_access( 1, $after ), 'The native access check immediately reflects the change.' );
	$stale = \GetMCP\Auth\OAuthUserAccess::set( $server->id, array( 1 ), array( 1 ), $actor->ID );
	$check( is_wp_error( $stale ) && array( (int) $actor->ID ) === \GetMCP\Auth\ServerUserAccess::allowed_ids( $manager->get( $server->id ) ), 'Stale allowlist edits are refused.' );
	$invalid = \GetMCP\Auth\OAuthUserAccess::set( $server->id, array( '1' ), array( (int) $actor->ID ), $actor->ID );
	$check( is_wp_error( $invalid ), 'Non-integer access grants are refused.' );
	$denied = \GetMCP\Auth\OAuthUserAccess::get( $server->id, $viewer->ID );
	$check( is_wp_error( $denied ), 'A subscriber cannot manage another server’s allowlist.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic legacy allowlist' ) ) . PHP_EOL;
} finally {
	wp_set_current_user( 1 ); $current_server = $manager->get( $server->id ); if ( $current_server && $current_server->slug === $slug ) { $manager->delete( $server->id ); }
	wp_set_current_user( $previous_user );
}
