<?php
/** Compatibility for the add-on's management MCP tools beside the 1.8 native catalogue. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
$actor = get_userdata( 1 ); $viewer_login = 'qa180-builtin-' . substr( wp_generate_uuid4(), 0, 8 );
$viewer_id = wp_insert_user( array( 'user_login' => $viewer_login, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
if ( ! $actor || is_wp_error( $viewer_id ) ) { throw new RuntimeException( 'Disposable fixture user could not be created.' ); } $viewer = get_userdata( $viewer_id );
$manager = new \GetMCP\Core\ServerManager(); $slug = 'qa-180-builtin-' . substr( wp_generate_uuid4(), 0, 8 );
$server = $manager->create( array( 'name' => 'QA 180 builtin access', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ), 'synthetic_metadata' => 'preserve' ) ), 'settings' => '{}' ) );
if ( ! $server || $server->slug !== $slug ) { throw new RuntimeException( 'Could not create synthetic server.' ); }
$row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $row->getValue();
$scopes = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_scopes' ); $old_scopes = $scopes->getValue();
$previous_user = get_current_user_id(); $checks = 0;
$check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
try {
	$row->setValue( (object) array( 'id' => 98001, 'user_id' => $actor->ID, 'server_id' => $server->id ) ); \GetMCP\Auth\McpAuth::set_current_scopes( array( 'mcp:read' ) ); wp_set_current_user( 0 );
	$names = array_column( \GetMCP\Builtin\BuiltinTools::as_mcp_list(), 'name' );
	foreach ( array( 'list_servers', 'get_allowed_users', 'search_users', 'manage_mcp_connections', 'manage_provider_configuration', 'search_oauth_users', 'get_oauth_allowed_users', 'set_oauth_allowed_users' ) as $name ) { $check( in_array( $name, $names, true ) && \GetMCP\Builtin\BuiltinTools::has( $name ), 'Expected native and legacy management tool missing: ' . $name ); }
	$result = \GetMCP\Builtin\BuiltinTools::call( 'get_oauth_allowed_users', array( 'server_id' => $server->id ) );
	$check( array( 1 ) === ( $result['structuredContent']['allowed_user_ids'] ?? null ), 'Legacy MCP management reads the native allowlist.' );
	$denied = false; try { \GetMCP\Builtin\BuiltinTools::call( 'set_oauth_allowed_users', array( 'server_id' => $server->id, 'allowed_user_ids' => array( (int) $viewer->ID ), 'expected_user_ids' => array( 1 ) ) ); } catch ( \Throwable $e ) { $denied = true; }
	$check( $denied && array( 1 ) === \GetMCP\Auth\ServerUserAccess::allowed_ids( $manager->get( $server->id ) ), 'Read-only OAuth scope cannot change access.' );
	\GetMCP\Auth\McpAuth::set_current_scopes( array( 'mcp:write' ) );
	$result = \GetMCP\Builtin\BuiltinTools::call( 'set_oauth_allowed_users', array( 'server_id' => $server->id, 'allowed_user_ids' => array( (int) $viewer->ID ), 'expected_user_ids' => array( 1 ) ) );
	$config = json_decode( $manager->get( $server->id )->auth_config, true );
	$check( array( (int) $viewer->ID ) === ( $result['structuredContent']['allowed_user_ids'] ?? null ) && 'preserve' === ( $config['synthetic_metadata'] ?? null ), 'Authorized legacy write preserves unrelated metadata.' );
	$list = \GetMCP\Builtin\BuiltinTools::call( 'manage_mcp_connections', array( 'operation' => 'list' ) );
	$check( isset( $list['structuredContent'] ), 'Authorized connection management remains available.' );
	$presets = \GetMCP\Builtin\BuiltinTools::call( 'manage_provider_configuration', array( 'operation' => 'presets' ) );
	$check( ! empty( $presets['structuredContent'] ), 'Authorized provider management remains available.' );
	$row->setValue( (object) array( 'id' => 98002, 'user_id' => $viewer->ID, 'server_id' => $server->id ) );
	$denied = false; try { \GetMCP\Builtin\BuiltinTools::call( 'get_oauth_allowed_users', array( 'server_id' => $server->id ) ); } catch ( \Throwable $e ) { $denied = true; }
	$check( $denied, 'An unprivileged user cannot read management data.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic builtin management' ) ) . PHP_EOL;
} finally {
	$row->setValue( $old_row ); $scopes->setValue( $old_scopes ); wp_set_current_user( 1 ); $current = $manager->get( $server->id );
	if ( $current && $current->slug === $slug ) { $manager->delete( $server->id ); }
	if ( $viewer && $viewer->user_login === $viewer_login ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $viewer->ID ); } wp_set_current_user( $previous_user );
}
