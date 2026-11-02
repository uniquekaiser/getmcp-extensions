<?php
/** Local synthetic per-user REST provider execution on the 1.8 adapter. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
wp_set_current_user( 1 );
$manager = new \GetMCP\Core\ServerManager();
$server = $manager->create( array( 'name' => 'QA 180 provider executor', 'slug' => 'qa-180-provider-executor', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'client_id' => 'synthetic-provider-client' ) ), 'settings' => wp_json_encode( array( 'personal_provider' => array( 'allowed_user_ids' => array( 1 ) ) ) ) ) );
if ( ! $server || 'qa-180-provider-executor' !== $server->slug ) { throw new RuntimeException( 'Synthetic provider server required.' ); }
$id = (int) $server->id; $checks = 0; $calls = 0; $authorization = ''; $test_user_id = 0; $test_user_login = ''; $ordinary_id = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$row_property = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' );
$old_row = $row_property->getValue();
$saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$token_suffix = substr( wp_generate_uuid4(), 0, 8 );
$personal_token = 'synthetic-personal-provider-' . $token_suffix;
$other_token = 'synthetic-other-provider-' . $token_suffix;
$failure_mode = false; $response_body = '{"ok":true}';
$mock = static function ( $pre, $args, $url ) use ( &$calls, &$authorization, &$failure_mode, &$response_body ) {
	if ( 'https://www.googleapis.com/qa-180-provider' !== $url ) { return $pre; }
	++$calls; $authorization = $args['headers']['Authorization'] ?? '';
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => $response_body, 'response' => array( 'code' => $failure_mode ? 503 : 200, 'message' => 'Synthetic' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	$grant = array( 'kind' => 'rest-provider', 'access_token' => $personal_token, 'refresh_token' => 'synthetic-provider-refresh', 'expires_at' => time() + 3600, 'config_hash' => \GetMCPExtensions\ProviderConnections::fingerprint( $server ) );
	\GetMCP\Remote\UpstreamConnections::save( $id, 1, $grant );
	$tool = new \GetMCP\Core\Tool( array( 'id' => 0, 'server_id' => $id, 'name' => 'QA provider read', 'slug' => 'qa_180_provider_read', 'description' => '', 'endpoint_url' => 'https://www.googleapis.com/qa-180-provider', 'http_method' => 'GET', 'tool_type' => 'http', 'input_schema' => '{"type":"object","properties":{}}', 'status' => 'active', 'cache_duration' => 0 ) );
	$row_property->setValue( (object) array( 'id' => 91000, 'user_id' => 1, 'resource' => 'https://example.org/different-gateway' ) );
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-downstream-credential';
	$result = ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true );
	$check( 1 === $calls && 'Bearer ' . $personal_token === $authorization && empty( $result['isError'] ), 'Delegated provider execution uses the user’s member-bound personal grant.' );
	$row_property->setValue( (object) array( 'id' => 91000, 'user_id' => 999999, 'resource' => 'https://example.org/different-gateway' ) );
	$refused = false;
	try { ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true ); } catch ( \Throwable $e ) { $refused = true; }
	$check( $refused && 1 === $calls, 'An unauthorized user cannot spend another user’s provider grant.' );
	$row_property->setValue( (object) array( 'id' => 91000, 'user_id' => 1, 'resource' => 'https://example.org/different-gateway' ) );
	$wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $id, 'user_id' => 1 ) );
	$refused = false;
	try { ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true ); } catch ( \Throwable $e ) { $refused = true; }
	$check( $refused && 1 === $calls, 'A missing personal grant does not fall back to another credential.' );
	$test_user_login = 'qa180-provider-' . substr( wp_generate_uuid4(), 0, 8 );
	$test_user_id = wp_insert_user( array( 'user_login' => $test_user_login, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	if ( is_wp_error( $test_user_id ) ) { throw new RuntimeException( 'Could not create synthetic provider user.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'personal_provider' => array( 'allowed_user_ids' => array( 1, (int) $test_user_id ) ) ) ) ), array( 'id' => $id ) );
	$server = $manager->get( $id );
	\GetMCP\Remote\UpstreamConnections::save( $id, 1, $grant );
	$other_grant = $grant; $other_grant['access_token'] = $other_token;
	\GetMCP\Remote\UpstreamConnections::save( $id, (int) $test_user_id, $other_grant, \GetMCP\Remote\UpstreamConnections::version( $id, (int) $test_user_id ) );
	$tool->cache_ttl = 60;
	$row_property->setValue( (object) array( 'id' => 91000, 'user_id' => 1, 'resource' => 'https://example.org/different-gateway' ) );
	( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true );
	$row_property->setValue( (object) array( 'id' => 91000, 'user_id' => (int) $test_user_id, 'resource' => 'https://example.org/different-gateway' ) );
	( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true );
	$check( 3 === $calls && 'Bearer ' . $other_token === $authorization, 'Different personal grants do not share cached responses.' );
	$other_grant['access_token'] = $personal_token;
	\GetMCP\Remote\UpstreamConnections::save( $id, (int) $test_user_id, $other_grant, \GetMCP\Remote\UpstreamConnections::version( $id, (int) $test_user_id ) );
	( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true );
	$check( 4 === $calls && 'Bearer ' . $personal_token === $authorization, 'Even identical upstream tokens retain per-user cache isolation.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'personal_provider' => array( 'allowed_user_ids' => array( 1 ) ) ) ) ), array( 'id' => $id ) );
	$refused = false;
	try { ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true ); } catch ( \Throwable $e ) { $refused = true; }
	$check( $refused && 4 === $calls, 'Revoked user access is checked before a cached result can be returned.' );
	$ordinary = $manager->create( array( 'name' => 'QA 180 ordinary executor', 'slug' => 'qa-180-ordinary-executor', 'auth_type' => 'none', 'settings' => '{}' ) );
	if ( ! $ordinary || 'qa-180-ordinary-executor' !== $ordinary->slug ) { throw new RuntimeException( 'Synthetic ordinary server required.' ); }
	$ordinary_id = (int) $ordinary->id;
	$ordinary_tool = clone $tool; $ordinary_tool->server_id = $ordinary_id; $ordinary_tool->cache_ttl = 0;
	( new \GetMCP\Execution\ToolExecutor() )->execute( $ordinary_tool, array(), false, $ordinary, true );
	$check( 5 === $calls && '' === $authorization, 'Ordinary native execution retains core credential handling.' );
	$ordinary->auth_type = 'oauth';
	$ordinary->auth_config = wp_json_encode( array( 'authorize_url' => 'https://example.org/authorize', 'token_url' => 'https://example.org/token' ) );
	$refused = false;
	try { ( new \GetMCP\Execution\ToolExecutor() )->execute( $ordinary_tool, array(), false, $ordinary, true ); } catch ( \Throwable $e ) { $refused = true; }
	$check( $refused && 5 === $calls, 'Core still refuses an unrelated delegated OAuth token.' );
	$row_property->setValue( (object) array( 'id' => 91000, 'user_id' => 1, 'resource' => 'https://example.org/different-gateway' ) );
	$server = $manager->get( $id );
	$tool->http_method = 'POST'; $tool->retry_count = 1; $tool->cache_ttl = 0;
	$failure_mode = true; $before = $calls;
	try { ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true ); } catch ( \Throwable $e ) {}
	$check( $calls === $before + 1, 'Ambiguous provider writes are never retried automatically.' );
	$failure_mode = false; $tool->http_method = 'GET'; $tool->slug = 'call_meta_ads_tool';
	$response_body = '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"synthetic nested result"}]}}';
	$result = ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true );
	$check( 'synthetic nested result' === ( $result['content'][0]['text'] ?? '' ) && 200 === $result['_http_status'], 'Provider MCP envelope is decoded while retaining native execution metadata.' );
	$response_body = '{"jsonrpc":"2.0","id":1,"result":{"content":[],"isError":"false"}}';
	$result = ( new \GetMCP\Execution\ToolExecutor() )->execute( $tool, array(), false, $server, true );
	$check( ! empty( $result['isError'] ) && 200 === $result['_http_status'], 'Malformed provider envelope fails closed despite successful HTTP status.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic provider execution' ) ) . PHP_EOL;
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	$row_property->setValue( $old_row );
	if ( null === $saved_auth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth; }
	$wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $id, 'user_id' => 1 ) );
	if ( $test_user_id ) {
		$wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $id, 'user_id' => (int) $test_user_id ) );
		if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
		$user = get_userdata( (int) $test_user_id ); if ( $user && $user->user_login === $test_user_login ) { wp_delete_user( (int) $test_user_id ); }
	}
	$live = $manager->get( $id ); if ( $live && 'QA 180 provider executor' === $live->name ) { $manager->delete( $id ); }
	if ( $ordinary_id ) { $live = $manager->get( $ordinary_id ); if ( $live && 'QA 180 ordinary executor' === $live->name ) { $manager->delete( $ordinary_id ); } }
}
