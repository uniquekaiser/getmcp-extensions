<?php
/** Loaded WordPress regression; all requests and records are synthetic. */
if ( 'local' !== wp_get_environment_type() || '1.8.1-beta2' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_BETA_QA_ENABLE' ) ) {
	throw new RuntimeException( 'An isolated GetMCP beta fixture is required.' );
}

$checks = 0;
$check = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$check( \GetMCPExtensions\Runtime::ready() && 'vendor-original-1.8.1-beta2' === \GetMCPExtensions\Runtime::profile(), 'The reviewed beta must boot without forcing readiness.' );
$check( \GetMCPExtensions\Upgrade180::reviewed_core(), 'The loaded beta and migration adapters must be reviewed.' );
$profile = \GetMCPExtensions\Runtime::inspect( GETMCP_PATH );
$check( \GetMCPExtensions\Upgrade180::reviewed_adapter_files( $profile['classes'] ), 'The beta adapter map and bytes must be reviewed.' );
$tampered = $profile['classes'];
$tampered['GetMCP\\Execution\\ToolExecutor'] = 'compat/versions/1.8.0/includes/execution/class-tool-executor.php';
$check( ! \GetMCPExtensions\Upgrade180::reviewed_adapter_files( $tampered ), 'A mapping to the older executor must be refused.' );
$check( str_contains( ( new ReflectionClass( \GetMCP\Execution\ToolExecutor::class ) )->getFileName(), '/compat/versions/1.8.1-beta2/' ), 'The beta executor must actually be loaded.' );
$check( str_contains( ( new ReflectionClass( \GetMCP\Api\ServersController::class ) )->getFileName(), '/compat/versions/1.8.1-beta2/' ), 'The beta controller must actually be loaded.' );

wp_register_script( 'getmcp-admin', '/synthetic-core-admin.js', array(), 'fixture', true );
wp_enqueue_script( 'getmcp-admin' );
$native_script = wp_scripts()->registered['getmcp-admin']->src;
\GetMCPExtensions\Runtime::assets();
$check( $native_script === wp_scripts()->registered['getmcp-admin']->src, 'The beta must retain its native admin bundle.' );

$secret_query = 'synthetic-query-value-181';
$secret_header = 'synthetic-header-value-181';
$secret_body = 'synthetic-body-value-181';
$secret_cookie = 'synthetic-cookie-value-181';
$seen = array();
$response_body = '{"ok":true}';
$response_type = 'application/json';
$mock = static function ( $pre, $args, $url ) use ( &$seen, &$response_body, &$response_type, $secret_cookie ) {
	$seen[] = array( 'url' => $url, 'args' => $args );
	return array( 'headers' => array( 'content-type' => $response_type, 'set-cookie' => 'session=' . $secret_cookie ), 'body' => $response_body, 'response' => array( 'code' => 200, 'message' => 'Synthetic' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	$tool = new \GetMCP\Core\Tool( array(
		'name' => 'Synthetic beta debug', 'slug' => 'synthetic_beta_debug', 'tool_type' => 'http',
		'endpoint_url' => 'https://8.8.8.8/qa?api_key=' . $secret_query, 'http_method' => 'POST',
		'headers' => wp_json_encode( array( 'Authorization' => 'Bearer ' . $secret_header, 'Content-Type' => 'application/json' ) ),
		'body_template' => wp_json_encode( array( 'password' => $secret_body, 'nested' => array( 'client_secret' => $secret_body ), 'visible' => 'synthetic-public-value' ) ),
		'input_schema' => '{"type":"object","properties":{}}', 'retry_count' => 0,
	) );
	$executor = new \GetMCP\Execution\ToolExecutor();
	$debug = $executor->execute_test( $tool, array() );
	$serialized = wp_json_encode( $debug );
	$check( count( $seen ) === 1 && str_contains( $seen[0]['url'], $secret_query ) && str_contains( (string) $seen[0]['args']['body'], $secret_body ), 'The synthetic upstream receives the unmodified request.' );
	$check( ! str_contains( $serialized, $secret_query ) && ! str_contains( $serialized, $secret_header ) && ! str_contains( $serialized, $secret_body ) && ! str_contains( $serialized, $secret_cookie ), 'The debug result must not disclose request credentials or response cookies.' );
	$check( ! array_key_exists( 'raw_headers', $debug['outbound_request'] ) && str_contains( $serialized, 'synthetic-public-value' ), 'Debug output omits raw headers while retaining ordinary fields.' );
	$response_body = "%PDF-1.4\nsynthetic-beta-document";
	$response_type = 'application/pdf';
	$file = $executor->execute_test( $tool, array() );
	$resource = $file['mcp_output']['content'][0]['resource'] ?? array();
	$check( 'application/pdf' === ( $resource['mimeType'] ?? '' ) && $response_body === base64_decode( $resource['blob'] ?? '', true ), 'The beta preserves binary resource bytes through the replacement executor.' );
	$executed = $executor->execute( $tool, array(), true );
	$replay = wp_json_encode( $executed['_replay_data'] ?? null );
	$check( ! str_contains( $replay, $secret_query ) && ! str_contains( $replay, $secret_header ) && ! str_contains( $replay, $secret_body ) && ! str_contains( $replay, $secret_cookie ), 'Replay data must not disclose the synthetic credentials or cookie.' );
	$check( strlen( base64_encode( $response_body ) ) <= $executed['_delivered_bytes'], 'Embedded binary bytes must be included in delivery accounting.' );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}

wp_set_current_user( 1 );
$manager = new \GetMCP\Core\ServerManager();
$server = $manager->create( array( 'name' => 'Synthetic beta connector', 'slug' => 'synthetic-beta-connector', 'auth_type' => 'none', 'settings' => '{}' ) );
if ( ! $server ) { throw new RuntimeException( 'Could not create the synthetic connector server.' ); }
try {
	$check( ! \GetMCP\Licensing\LicenseTier::current()->allows_templates(), 'The clean fixture must use the unlicensed tier.' );
	$route = '/getmcp/v1/servers/' . $server->uuid . '/database-sync-tools';
	$tools = new \GetMCP\Core\ToolManager();
	$count = static fn() => count( $tools->get_by_server( $server->id, array( 'per_page' => 100 ) )['items'] );
	$before = $count();
	$denied = rest_do_request( new WP_REST_Request( 'POST', $route ) );
	$check( 403 === $denied->get_status() && 'tier_limit_template' === ( $denied->get_data()['code'] ?? '' ) && $before === $count(), 'An unlicensed new connector must be refused without creating tools.' );
	wp_set_current_user( 0 );
	$anonymous = rest_do_request( new WP_REST_Request( 'POST', $route ) );
	$check( in_array( $anonymous->get_status(), array( 401, 403 ), true ) && $before === $count(), 'An anonymous direct request must be denied without changing tools.' );
	wp_set_current_user( 1 );
	$row = \GetMCP\Sql\SqlTools::tool_rows()[0];
	$row['server_id'] = $server->id;
	$seeded = $tools->create( $row );
	$check( false !== $seeded, 'One pre-existing synthetic database tool must be created.' );
	$available = rest_do_request( new WP_REST_Request( 'GET', $route ) );
	$check( 200 === $available->get_status() && true === ( $available->get_data()['installed'] ?? null ) && ( $available->get_data()['count'] ?? 0 ) > 0, 'An existing connector must show its missing tools.' );
	$updated = rest_do_request( new WP_REST_Request( 'POST', $route ) );
	$check( 200 === $updated->get_status() && $count() > $before + 1, 'An existing connector can be topped up on the unlicensed tier.' );
	foreach ( $tools->get_by_server( $server->id, array( 'per_page' => 100 ) )['items'] as $created ) {
		if ( \GetMCP\Sql\SqlTools::writes( $created->handler ) ) { $check( 'inactive' === $created->status, 'New database write tools must remain inactive.' ); }
	}
} finally {
	wp_set_current_user( 1 );
	$manager->delete( (int) $server->id );
}
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'reviewed beta with synthetic WordPress records and HTTP' ) ) . PHP_EOL;
