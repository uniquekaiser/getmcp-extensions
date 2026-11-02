<?php
/** Test-only MU bootstrap. Mount only in the snapshotted, local QA fixture. */
if ( '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || 'local' !== wp_get_environment_type() ) { return; }

add_action( 'plugins_loaded', static function (): void {
	if ( '1' === getenv( 'GETMCP_180_QA_REAL_PROFILE' ) ) { return; }
	$root = WP_CONTENT_DIR . '/plugins/getmcp-extensions/';
	$map = array(
		'GetMCP\\Core\\ServerManager' => 'compat/versions/1.8.0/includes/core/class-server-manager.php',
		'GetMCP\\Execution\\ToolExecutor' => 'compat/versions/1.8.0/includes/execution/class-tool-executor.php',
		'GetMCP\\Api\\ServersController' => 'compat/versions/1.8.0/includes/api/class-servers-controller.php',
		'GetMCP\\Protocol\\ToolsCallHandler' => 'compat/versions/1.8.0/includes/protocol/class-tools-call-handler.php',
		'GetMCP\\Builtin\\BuiltinTools' => 'compat/versions/1.8.0/includes/builtin/class-builtin-tools.php',
		'GetMCP\\Auth\\OAuthUserAccess' => 'compat/includes/auth/class-oauth-user-access.php',
		'GetMCP\\Gateway\\FeatureManager' => 'compat/includes/gateway/class-feature-manager.php',
		'GetMCP\\Gateway\\FeatureModule' => 'compat/includes/gateway/class-feature-module.php',
		'GetMCP\\Gateway\\ManagementTool' => 'compat/includes/gateway/class-management-tool.php',
		'GetMCP\\Protocol\\JsonRpcRouter' => 'compat/versions/1.8.0/includes/protocol/class-json-rpc-router.php',
		'GetMCP\\Gateway\\Delegation' => 'compat/includes/gateway/class-delegation.php',
		'GetMCP\\Gateway\\FeatureHandler' => 'compat/includes/gateway/class-feature-handler.php',
		'GetMCP\\Gateway\\ProjectGateway' => 'compat/includes/gateway/class-project-gateway.php',
		'GetMCP\\Remote\\HeaderSchema' => 'compat/includes/remote/class-header-schema.php',
		'GetMCP\\Remote\\McpClient' => 'compat/includes/remote/class-mcp-client.php',
		'GetMCP\\Remote\\RemoteException' => 'compat/includes/remote/class-remote-exception.php',
		'GetMCP\\Remote\\SafeHttp' => 'compat/includes/remote/class-safe-http.php',
		'GetMCP\\Remote\\UpstreamConnections' => 'compat/includes/remote/class-upstream-connections.php',
		'GetMCP\\Remote\\UpstreamOAuth' => 'compat/includes/remote/class-upstream-oauth.php',
	);
	if ( class_exists( '\\GetMCP\\Protocol\\JsonRpcRouter', false ) ) { throw new RuntimeException( 'QA router initialized too early.' ); }
	spl_autoload_register( static function ( string $class ) use ( $root, $map ): void { if ( isset( $map[$class] ) ) { require_once $root . $map[$class]; } }, true, true );
}, -101 );

add_action( 'plugins_loaded', static function (): void {
	if ( '1' === getenv( 'GETMCP_180_QA_REAL_PROFILE' ) ) { return; }
	if ( ! defined( 'GETMCP_VERSION' ) || '1.8.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Upgrade180::reviewed_core_files() ) { throw new RuntimeException( 'Reviewed QA core required.' ); }
	$ready = new ReflectionProperty( \GetMCPExtensions\Runtime::class, 'ready' );
	$ready->setValue( true );
	$profile = new ReflectionProperty( \GetMCPExtensions\Runtime::class, 'profile' );
	$profile->setValue( 'vendor-original-1.8.0' );
	if ( ! defined( 'GETMCP_EXTENSIONS_READY' ) ) { define( 'GETMCP_EXTENSIONS_READY', true ); }
	if ( '1' === getenv( 'GETMCP_180_QA_UI' ) ) {
		\GetMCP\Gateway\FeatureModule::register();
		\GetMCPExtensions\MarketingModule::boot();
		\GetMCPExtensions\ConnectionScreens::boot();
		\GetMCPExtensions\MarketingTemplates::boot();
		\GetMCPExtensions\RemoteCatalogue::boot();
		add_action( 'admin_enqueue_scripts', array( \GetMCPExtensions\Runtime::class, 'assets' ), 100 );
	}
}, -99 );

add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( 'https://example.org/qa-native-api' === $url ) {
		if ( ! empty( $args['headers']['Authorization'] ) ) { throw new RuntimeException( 'Synthetic downstream credential was forwarded.' ); }
		foreach ( $args['headers'] ?? array() as $name => $value ) { if ( 0 === strcasecmp( (string) $name, 'X-API-Key' ) && '' !== (string) $value ) { throw new RuntimeException( 'Synthetic Gateway key was forwarded.' ); } }
		return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	$synthetic_remotes = array( 'https://example.org/mcp' => 'Bearer synthetic-access', 'https://example.org/qa-public-mcp' => '', 'https://example.org/qa-shared-mcp' => 'Bearer synthetic-shared' );
	if ( ! array_key_exists( $url, $synthetic_remotes ) ) { return $pre; }
	foreach ( $args['headers'] ?? array() as $name => $value ) { if ( 0 === strcasecmp( (string) $name, 'X-API-Key' ) && '' !== (string) $value ) { throw new RuntimeException( 'Synthetic Gateway key was forwarded to a remote server.' ); } }
	if ( $synthetic_remotes[$url] !== ( $args['headers']['Authorization'] ?? '' ) ) {
		return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{}', 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'cookies' => array(), 'filename' => null );
	}
	$request = json_decode( $args['body'] ?? '', true );
	switch ( $request['method'] ?? '' ) {
		case 'server/discover': $result = array( 'capabilities' => array( 'tools' => new stdClass(), 'resources' => new stdClass(), 'prompts' => new stdClass() ) ); break;
		case 'tools/list': $result = array( 'tools' => array( array( 'name' => 'synthetic_read', 'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ), 'annotations' => array( 'readOnlyHint' => true ) ), array( 'name' => 'synthetic_write', 'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ), 'annotations' => array( 'readOnlyHint' => false ) ) ) ); break;
		case 'tools/call': $result = array( 'content' => array( array( 'type' => 'text', 'text' => 'synthetic upstream result' ) ) ); break;
		case 'resources/list': $result = array( 'resources' => array( array( 'uri' => 'https://example.org/resource', 'name' => 'synthetic resource' ) ) ); break;
		case 'resources/read': $result = array( 'contents' => array( array( 'uri' => 'https://example.org/resource', 'text' => 'synthetic resource content' ) ) ); break;
		case 'prompts/list': $result = array( 'prompts' => array( array( 'name' => 'synthetic_prompt' ) ) ); break;
		case 'prompts/get': $result = array( 'messages' => array( array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => 'synthetic prompt' ) ) ) ); break;
		default: return $pre;
	}
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'result' => $result ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );

// Narrow the local rehearsal to its named synthetic servers; never touch existing fixture records.
add_filter( 'getmcp_extensions_180_migration_scope', static function () {
	$path = '/tmp/getmcp-180-synthetic-http.json';
	$state = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array();
	return array_values( array_filter( array_map( 'intval', array( $state['remote'] ?? 0, $state['gateway'] ?? 0, $state['standard'] ?? 0, $state['builtin_member'] ?? 0, $state['builtin_remote_none'] ?? 0, $state['builtin_remote_shared'] ?? 0 ) ) ) );
} );
