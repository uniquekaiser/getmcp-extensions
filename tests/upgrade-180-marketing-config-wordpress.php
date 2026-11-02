<?php
/** Disposable personal-provider editor compatibility under the reviewed 1.8 QA profile. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_UI' ) ) { throw new RuntimeException( 'Isolated 1.8 UI fixture required.' ); }
$previous_user = get_current_user_id(); wp_set_current_user( 1 );
$manager = new \GetMCP\Core\ServerManager();
$slug = 'qa-180-provider-config-' . substr( wp_generate_uuid4(), 0, 8 );
$server = $manager->create( array( 'name' => 'QA 180 provider configuration', 'slug' => $slug, 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'client_id' => 'synthetic-client' ) ), 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'synthetic-client-secret' ) ), 'settings' => wp_json_encode( array( 'description' => 'synthetic-retain-setting' ) ) ) );
if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic provider.' ); }
$checks = 0;
$check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$route = '/getmcp/v1/servers/' . $server->uuid . '/provider-configuration';
try {
	$get = rest_do_request( new WP_REST_Request( 'GET', $route ) );
	$check( 200 === $get->get_status() && ! str_contains( wp_json_encode( $get->get_data() ), 'synthetic-client-secret' ), 'Provider settings load without revealing credentials.' );
	$revision = $get->get_data()['configuration_revision'] ?? '';
	$request = new WP_REST_Request( 'PUT', $route ); $request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'configuration_revision' => $revision, 'personal_provider' => array( 'allowed_user_ids' => array( 1 ) ) ) ) );
	$saved = rest_do_request( $request ); $current = $manager->get( $server->id ); $settings = json_decode( $current->settings, true );
	$check( 200 === $saved->get_status() && array( 1 ) === ( $settings['personal_provider']['allowed_user_ids'] ?? null ) && 'synthetic-retain-setting' === ( $settings['description'] ?? '' ), 'Provider settings save without losing unrelated configuration.' );
	$check( $server->auth_credentials === $current->auth_credentials, 'Provider settings save preserves the encrypted client secret.' );
	$stale = rest_do_request( $request );
	$check( 409 === $stale->get_status() && $current->settings === $manager->get( $server->id )->settings, 'A stale provider revision is refused without overwriting settings.' );
	$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	$lock = 'getmcp_server_auth_' . (int) $server->id;
	$acquired = $other->query( "SELECT GET_LOCK('{$lock}', 0)" )->fetch_row();
	$check( '1' === (string) $acquired[0], 'Independent writer acquired the native server lock.' );
	try {
		$busy = new WP_REST_Request( 'PUT', $route ); $busy->set_header( 'Content-Type', 'application/json' );
		$busy->set_body( wp_json_encode( array( 'configuration_revision' => $saved->get_data()['configuration_revision'], 'personal_provider' => array( 'allowed_user_ids' => array() ) ) ) );
		$busy_response = rest_do_request( $busy );
		$check( 409 === $busy_response->get_status() && $current->settings === $manager->get( $server->id )->settings, 'A concurrent native authentication save prevents provider changes.' );
	} finally { $other->query( "SELECT RELEASE_LOCK('{$lock}')" ); $other->close(); }
	$invalid = new WP_REST_Request( 'PUT', $route ); $invalid->set_header( 'Content-Type', 'application/json' );
	$invalid->set_body( wp_json_encode( array( 'configuration_revision' => $saved->get_data()['configuration_revision'], 'personal_provider' => array( 'allowed_user_ids' => array( -1 ) ) ) ) );
	$denied = rest_do_request( $invalid );
	$check( $denied->get_status() >= 400 && $current->settings === $manager->get( $server->id )->settings, 'Invalid user grants do not alter provider settings.' );
	wp_set_current_user( 0 ); $unauthorized = rest_do_request( new WP_REST_Request( 'GET', $route ) );
	$check( $unauthorized->get_status() >= 400, 'Unauthenticated provider configuration access is refused.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic provider configuration' ) ) . PHP_EOL;
} finally {
	wp_set_current_user( 1 ); $current = $manager->get( $server->id );
	if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); }
	wp_set_current_user( $previous_user );
}
