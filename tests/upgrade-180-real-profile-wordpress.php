<?php
/** Verify the reviewed runtime loader in an isolated 1.8 fixture. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_REAL_PROFILE' ) ) { throw new RuntimeException( 'Isolated real-profile fixture required.' ); }
global $wpdb;
$checks = 0; $check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$check( \GetMCPExtensions\Runtime::ready() && 'vendor-original-1.8.0' === \GetMCPExtensions\Runtime::profile() && \GetMCPExtensions\Upgrade180::reviewed_core(), 'The reviewed 1.8 profile boots without the forced-ready shim.' );
$profile = \GetMCPExtensions\Runtime::inspect( GETMCP_PATH );
$check( \GetMCPExtensions\Upgrade180::reviewed_adapter_files( $profile['classes'] ), 'The real loader checks the expected adapter mapping and hashes.' );
$tampered = $profile['classes']; $tampered['GetMCP\\Core\\ServerManager'] = 'compat/includes/core/class-server-manager.php';
$check( ! \GetMCPExtensions\Upgrade180::reviewed_adapter_files( $tampered ), 'A mismatched adapter map is rejected before loading.' );
foreach ( array( 'GetMCP\\Core\\ServerManager', 'GetMCP\\Protocol\\JsonRpcRouter', 'GetMCP\\Protocol\\ToolsCallHandler', 'GetMCP\\Execution\\ToolExecutor', 'GetMCP\\Builtin\\BuiltinTools' ) as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	$check( str_starts_with( realpath( $file ), realpath( GETMCP_EXTENSIONS_PATH ) . DIRECTORY_SEPARATOR ), 'A reviewed extension adapter was not loaded.' );
}
$core_file = ( new ReflectionClass( \GetMCP\Core\Server::class ) )->getFileName();
$check( str_starts_with( realpath( $core_file ), realpath( GETMCP_PATH ) . DIRECTORY_SEPARATOR ), 'The native 1.8 Server class remains in use.' );
$check( false !== has_action( 'rest_api_init', array( \GetMCP\Gateway\FeatureModule::class, 'routes' ) ) && false !== has_action( 'rest_api_init', array( \GetMCPExtensions\MarketingModule::class, 'routes' ) ), 'Gateway and provider routes are registered by the real loader.' );
$check( \GetMCP\Builtin\BuiltinTools::has( 'manage_mcp_connections' ) && \GetMCP\Builtin\BuiltinTools::has( 'manage_provider_configuration' ) && \GetMCP\Builtin\BuiltinTools::has( 'get_allowed_users' ), 'Legacy and native management tools coexist.' );
wp_register_script( 'getmcp-admin', '/synthetic-native-180.js', array(), 'test', true ); wp_enqueue_script( 'getmcp-admin' );
$before = wp_scripts()->registered['getmcp-admin']->src; \GetMCPExtensions\Runtime::assets();
$check( $before === wp_scripts()->registered['getmcp-admin']->src, 'The extension does not replace the native 1.8 admin bundle.' );
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
foreach ( array( 'standard', 'remote', 'gateway' ) as $key ) {
	$id = (int) ( $state[$key] ?? 0 ); $server = ( new \GetMCP\Core\ServerManager() )->get( $id );
	$check( $server && 'wordpress' === $server->auth_type, 'A synthetic 1.7 server was not automatically converted: ' . $key );
}
$legacy = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX;
$grant = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$legacy} WHERE server_id=%d AND user_id=1", (int) $state['remote'] ), ARRAY_A );
$check( $grant && hash_equals( $state['grant_hash'], hash( 'sha256', wp_json_encode( $grant ) ) ), 'The original encrypted personal grant and metadata are unchanged.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'actual reviewed runtime loader' ) ) . PHP_EOL;
