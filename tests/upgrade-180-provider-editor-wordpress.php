<?php
/** The native editor must retain add-on provider options and encrypted secrets. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
$previous_user = get_current_user_id(); wp_set_current_user( 1 ); $manager = new \GetMCP\Core\ServerManager();
$slug = 'qa-180-provider-editor-' . substr( wp_generate_uuid4(), 0, 8 );
$config = \GetMCPExtensions\ProviderDiscovery::presets()['google-analytics']['auth_config'] + array( 'client_id' => 'synthetic-client' );
$server = $manager->create( array( 'name' => 'QA 180 provider editor', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( $config ), 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'synthetic-editor-secret' ) ), 'settings' => wp_json_encode( array( 'personal_provider' => array( 'enabled' => true, 'preset' => 'google-analytics', 'allowed_user_ids' => array( 1 ) ) ) ) ) );
if ( ! $server || $slug !== $server->slug ) { throw new RuntimeException( 'Synthetic provider creation failed.' ); }
$checks = 0; $check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
try {
	$secret = $server->auth_credentials; $before = $server->auth_revision();
	$minimal = $config; unset( $minimal['extra_authorize_params'] );
	$request = new WP_REST_Request( 'PATCH', '/getmcp/v1/servers/' . $server->uuid ); $request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'auth_type' => 'oauth', 'auth_config' => $minimal, 'auth_credentials' => array( 'client_secret' => '' ), 'expected_auth_revision' => $before ) ) );
	$response = rest_do_request( $request ); $after = $manager->get( $server->id );
	$check( 200 === $response->get_status(), 'The provider editor accepts the unchanged connection.' );
	$check( $secret === $after->auth_credentials, 'A masked empty secret preserves encrypted credentials.' );
	$actual = \GetMCPExtensions\AuthenticationSettings::object( $after->auth_config );
	$check( ( $actual['extra_authorize_params'] ?? null ) === $config['extra_authorize_params'], 'Offline authorization options survive a partial native editor save.' );
	$check( ! str_contains( wp_json_encode( $response->get_data() ), 'synthetic-editor-secret' ), 'The editor response does not expose the saved secret.' );
	$stale = new WP_REST_Request( 'PATCH', '/getmcp/v1/servers/' . $server->uuid ); $stale->set_header( 'Content-Type', 'application/json' );
	$stale->set_body( wp_json_encode( array( 'auth_config' => array( 'scope' => 'stale' ), 'expected_auth_revision' => str_repeat( '0', 64 ) ) ) );
	$check( 409 === rest_do_request( $stale )->get_status() && $after->auth_config === $manager->get( $server->id )->auth_config, 'The native stale-edit guard remains effective.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic provider native editor' ) ) . PHP_EOL;
} finally {
	$current = $manager->get( $server->id ); if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); }
	wp_set_current_user( $previous_user );
}
