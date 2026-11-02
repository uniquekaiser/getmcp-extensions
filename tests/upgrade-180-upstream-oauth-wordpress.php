<?php
/** Local synthetic provider flow through the 1.8 legacy connection adapter. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/auth/class-oauth-user-access.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-manager.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-module.php';
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$remote_id = (int) ( $state['remote'] ?? 0 ); $gateway_id = (int) ( $state['gateway'] ?? 0 );
$gateway = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic gateway'", $gateway_id ), ARRAY_A );
$remote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", $remote_id ), ARRAY_A );
if ( ! $remote || ! $gateway || 'wordpress' !== $remote['auth_type'] || 'wordpress' !== $gateway['auth_type'] ) { throw new RuntimeException( 'Converted synthetic servers required.' ); }
$store = \GetMCP\Remote\UpstreamConnections::table();
$before = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$store} WHERE server_id=%d AND user_id=1", $remote_id ), ARRAY_A );
if ( ! $before ) { throw new RuntimeException( 'Synthetic personal grant required.' ); }
$checks = 0; $requests = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$mock = static function ( $pre, $args, $url ) use ( &$requests ) {
	if ( 'https://example.org/.well-known/oauth-protected-resource/mcp' === $url ) {
		$result = array( 'resource' => 'https://example.org/mcp', 'authorization_servers' => array( 'https://example.org/qa-issuer' ) );
	} elseif ( 'https://example.org/.well-known/oauth-authorization-server/qa-issuer' === $url ) {
		$result = array( 'issuer' => 'https://example.org/qa-issuer', 'authorization_endpoint' => 'https://example.org/authorize', 'token_endpoint' => 'https://example.org/token', 'code_challenge_methods_supported' => array( 'S256' ), 'token_endpoint_auth_methods_supported' => array( 'none' ) );
	} elseif ( 'https://example.org/token' === $url ) {
		++$requests;
		parse_str( (string) ( $args['body'] ?? '' ), $form );
		$result = array( 'access_token' => 'synthetic-reconnected-' . ( $form['grant_type'] ?? '' ), 'refresh_token' => 'synthetic-new-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600 );
	} else { return $pre; }
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $result ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 5, 3 );
try {
	$auth = json_decode( $gateway['auth_config'], true ); $auth['allowed_user_ids'] = array( 1 );
	$settings = json_decode( $gateway['settings'], true ); $settings['gateway']['server_ids'] = array( $remote_id );
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $auth ), 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway_id ) );
	wp_set_current_user( 1 );
	$check( \GetMCP\Remote\UpstreamConnections::portal_url( 'admin' ) === admin_url( 'admin.php?page=getmcp-extensions-my-connections' ), 'OAuth completion returns to the distinct 1.8 add-on page.' );
	$server = ( new \GetMCP\Core\ServerManager() )->get( $remote_id );
	$original = \GetMCP\Remote\UpstreamConnections::get( $remote_id, 1 );
	$original_refreshed = \GetMCP\Remote\UpstreamOAuth::refresh( $server, 1, $original );
	$check( 1 === $requests && 'synthetic-reconnected-refresh_token' === ( $original_refreshed['data']['access_token'] ?? '' ) && 'synthetic-user-client' === ( $original_refreshed['data']['client_id'] ?? '' ) && 'synthetic-user-secret' === ( $original_refreshed['data']['client_secret'] ?? '' ), 'The migrated personal grant refreshes using its original per-user client registration.' );
	\GetMCP\Gateway\FeatureModule::register();
	if ( did_action( 'rest_api_init' ) ) { \GetMCP\Gateway\FeatureModule::routes(); }
	$request = new WP_REST_Request( 'POST', '/getmcp/v1/my-connections/' . $remote_id );
	$begin = rest_do_request( $request );
	$data = $begin->get_data();
	$check( 200 === $begin->get_status() && ! empty( $data['authorization_url'] ), 'Gateway user can start their own upstream OAuth connection.' );
	parse_str( (string) wp_parse_url( $data['authorization_url'], PHP_URL_QUERY ), $query );
	$check( 'S256' === ( $query['code_challenge_method'] ?? '' ) && ! empty( $query['state'] ), 'Authorization uses a state and PKCE S256.' );
	$callback = array( 'state' => $query['state'], 'code' => 'synthetic-code', 'iss' => 'https://example.org/qa-issuer' );
	$refused = false;
	try { \GetMCP\Remote\UpstreamOAuth::complete( $callback, 999999 ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused && 1 === $requests, 'Another WordPress user cannot consume the consent state.' );
	\GetMCP\Remote\UpstreamOAuth::complete( $callback, 1 );
	$grant = \GetMCP\Remote\UpstreamConnections::get( $remote_id, 1 );
	$check( 2 === $requests && 'synthetic-reconnected-authorization_code' === ( $grant['data']['access_token'] ?? '' ), 'Consent exchanges and stores the user’s upstream grant.' );
	$refused = false;
	try { \GetMCP\Remote\UpstreamOAuth::complete( $callback, 1 ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused && 2 === $requests, 'Consent callback cannot be replayed.' );
	$server = ( new \GetMCP\Core\ServerManager() )->get( $remote_id );
	$refreshed = \GetMCP\Remote\UpstreamOAuth::refresh( $server, 1, $grant );
	$check( 3 === $requests && 'synthetic-reconnected-refresh_token' === ( $refreshed['data']['access_token'] ?? '' ), 'The same user’s personal upstream grant refreshes.' );
	$begin = rest_do_request( new WP_REST_Request( 'POST', '/getmcp/v1/my-connections/' . $remote_id ) );
	parse_str( (string) wp_parse_url( $begin->get_data()['authorization_url'] ?? '', PHP_URL_QUERY ), $next );
	$settings['gateway']['server_ids'] = array();
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway_id ) );
	$refused = false;
	try { \GetMCP\Remote\UpstreamOAuth::complete( array( 'state' => $next['state'] ?? '', 'code' => 'synthetic-code', 'iss' => 'https://example.org/qa-issuer' ), 1 ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused && 3 === $requests, 'Removing gateway membership stops a pending connection before token exchange.' );
	$check( $refreshed['version'] === ( \GetMCP\Remote\UpstreamConnections::get( $remote_id, 1 )['version'] ?? '' ), 'Rejected callback does not overwrite the current grant.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic upstream OAuth lifecycle' ) ) . PHP_EOL;
} finally {
	remove_filter( 'pre_http_request', $mock, 5 );
	$wpdb->update( $store, $before, array( 'server_id' => $remote_id, 'user_id' => 1 ) );
	$wpdb->update( $table, array( 'auth_config' => $gateway['auth_config'], 'settings' => $gateway['settings'], 'updated_at' => $gateway['updated_at'] ), array( 'id' => $gateway_id ) );
}
