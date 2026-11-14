<?php
/** Run only after intentionally modifying the isolated beta fixture. */
if ( 'local' !== wp_get_environment_type() || '1.8.1-beta2' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_BETA_QA_TAMPERED' ) ) {
	throw new RuntimeException( 'An isolated modified beta fixture is required.' );
}
$checks = 0;
$check = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$status = \GetMCPExtensions\Runtime::status();
$check( ! $status['ready'] && '' === $status['profile'], 'The modified beta must not become ready.' );
$check( in_array( 'includes/execution/class-tool-executor.php', $status['compatibility_errors'], true ), 'The modified execution file must be reported.' );
$check( ! \GetMCPExtensions\Upgrade180::reviewed_core_files(), 'A modified beta must not run protected migration code.' );
$protected = (object) array( 'server_kind' => 'remote-mcp', 'auth_type' => 'wordpress', 'auth_config' => '{}', 'settings' => '{}' );
$ordinary = (object) array( 'server_kind' => 'native', 'auth_type' => 'none', 'auth_config' => '{}', 'settings' => '{}' );
$check( getmcp_extensions_guard_row( $protected ) && ! getmcp_extensions_guard_row( $ordinary ), 'The persistent guard must block affected servers while leaving an ordinary server alone.' );
wp_set_current_user( 1 );
$response = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/connections' ) );
$check( 503 === $response->get_status() && 'extensions_unavailable' === ( $response->get_data()['code'] ?? '' ), 'Protected connection endpoints must fail closed.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'intentionally modified beta' ) ) . PHP_EOL;
