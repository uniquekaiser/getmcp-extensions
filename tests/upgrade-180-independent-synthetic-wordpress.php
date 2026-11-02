<?php
/** Supply disposable source records for the independent migration checks. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
$manager = new \GetMCP\Core\ServerManager(); $previous_user = get_current_user_id(); $remote = null; $gateway = null;
if ( $manager->get_by_slug( 'qa-180-automatic-remote' ) || $manager->get_by_slug( 'qa-180-automatic-gateway' ) ) { throw new RuntimeException( 'Synthetic source slugs are already in use.' ); }
try {
	wp_set_current_user( 1 );
	$remote = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'remote-mcp', 'name' => 'QA 180 independent source remote', 'slug' => 'qa-180-automatic-remote', 'allowed_user_ids' => array( 1 ), 'remote' => array( 'endpoint' => 'https://example.org/mcp', 'auth_mode' => 'none' ) ), 1 );
	$gateway = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 independent source gateway', 'slug' => 'qa-180-automatic-gateway', 'allowed_user_ids' => array( 1 ), 'server_ids' => array( (int) $remote['id'] ) ), 1 );
	( static function(): void { require GETMCP_EXTENSIONS_PATH . 'tests/upgrade-180-independent-migration-wordpress.php'; } )();
} finally {
	foreach ( array( $gateway, $remote ) as $item ) { if ( is_array( $item ) ) { $current = $manager->get( (int) $item['id'] ); if ( $current && $current->slug === $item['slug'] ) { $manager->delete( $current->id ); } } }
	wp_set_current_user( $previous_user );
}
