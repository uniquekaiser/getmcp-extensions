<?php
/** A connection editor must not overwrite a concurrent 1.8 save. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
$previous_user = get_current_user_id(); wp_set_current_user( 1 ); $checks = 0; $id = 0; $slug = '';
$check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
try {
	$created = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'remote-mcp', 'name' => 'QA 180 concurrent editor', 'allowed_user_ids' => array( 1 ), 'remote' => array( 'endpoint' => 'https://example.org/mcp', 'auth_mode' => 'none' ) ), 1 );
	$id = (int) $created['id']; $slug = $created['slug']; $revision = $created['revision'] ?? '';
	$check( 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $revision ), 'Connection management exposes a secret-free revision.' );
	$first = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $id, 'revision' => $revision, 'name' => 'QA 180 concurrent editor saved' ), 1 );
	$check( $first['revision'] !== $revision, 'A successful edit advances the revision.' );
	$conflict = false; try { \GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $id, 'revision' => $revision, 'name' => 'stale overwrite' ), 1 ); } catch ( \GetMCPExtensions\SettingsConflict $e ) { $conflict = true; }
	$live = \GetMCP\Gateway\FeatureManager::run( 'get', array( 'id' => $id ), 1 );
	$check( $conflict && 'QA 180 concurrent editor saved' === $live['name'], 'A stale browser tab cannot overwrite a later edit.' );
	$request = new WP_REST_Request( 'PUT', '/getmcp/v1/connections/' . $id );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'revision' => $revision, 'name' => 'stale REST overwrite' ) ) );
	$response = rest_do_request( $request );
	$check( 409 === $response->get_status() && 'QA 180 concurrent editor saved' === \GetMCP\Gateway\FeatureManager::run( 'get', array( 'id' => $id ), 1 )['name'], 'REST reports stale revisions as conflicts without writing.' );
	$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); $lock = 'getmcp_server_auth_' . $id;
	$acquired = $other->query( "SELECT GET_LOCK('{$lock}', 0)" )->fetch_row(); $check( '1' === (string) $acquired[0], 'An independent writer acquired the native lock.' );
	try {
		$busy = false; try { \GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $id, 'revision' => $live['revision'], 'name' => 'concurrent overwrite' ), 1 ); } catch ( \GetMCPExtensions\SettingsConflict $e ) { $busy = true; }
		$check( $busy && 'QA 180 concurrent editor saved' === \GetMCP\Gateway\FeatureManager::run( 'get', array( 'id' => $id ), 1 )['name'], 'A concurrent core authentication write prevents a connection update.' );
	} finally { $other->query( "SELECT RELEASE_LOCK('{$lock}')" ); $other->close(); }
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic connection concurrency' ) ) . PHP_EOL;
} finally {
	if ( $id ) { $manager = new \GetMCP\Core\ServerManager(); $server = $manager->get( $id ); if ( $server && $slug && $slug === $server->slug ) { $manager->delete( $id ); } }
	wp_set_current_user( $previous_user );
}
