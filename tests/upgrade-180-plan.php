<?php
/** Isolated classification tests; no WordPress, database or provider writes. */
require dirname( __DIR__ ) . '/includes/class-upgrade180-plan.php';

use GetMCPExtensions\Upgrade180Plan;

$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void {
    ++$checks;
    if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$row = array(
    'id' => 12,
    'server_kind' => 'native',
    'kind' => 'standard',
    'auth_type' => 'oauth',
    'auth_config' => '{"provider":"getmcp","allowed_user_ids":[1,2],"custom":"secret-fixture"}',
    'settings' => '{}',
    'outbound_auth_credentials' => 'ciphertext-fixture',
);
$context = array(
    'reviewed_core' => true,
    'oauth_enabled' => true,
    'gateway_member' => false,
    'oauth_clients' => false,
    'oauth_grants' => false,
    'upstream_grants' => false,
    'has_content' => false,
    'eligible_users' => true,
);
$classify = static fn( array $server, array $state ) => Upgrade180Plan::classify( $server, $state );
$plan = $classify( $row, $context );
$check( 'eligible' === $plan['state'], 'isolated legacy WordPress sign-in is eligible' );
$check( ! str_contains( json_encode( $plan ), 'secret-fixture' ) && ! str_contains( json_encode( $plan ), 'ciphertext-fixture' ), 'plan does not expose credentials or configuration' );
$empty = $row; $empty['auth_config'] = '{"provider":"getmcp","allowed_user_ids":[]}';
$check( 'eligible' === $classify( $empty, $context )['state'], 'empty allowlist can remain deny-all' );
foreach ( array( 'reviewed_core', 'oauth_enabled', 'eligible_users' ) as $flag ) {
    $bad = $context; $bad[$flag] = false;
    $check( 'blocked' === $classify( $row, $bad )['state'], "{$flag} must be true" );
    unset( $bad[$flag] );
    $check( 'blocked' === $classify( $row, $bad )['state'], "missing {$flag} is blocked" );
}
foreach ( array( 'gateway_member', 'oauth_clients', 'oauth_grants', 'upstream_grants', 'has_content' ) as $flag ) {
    $bad = $context; $bad[$flag] = true;
    $check( 'blocked' === $classify( $row, $bad )['state'], "{$flag} requires explicit compatibility" );
    unset( $bad[$flag] );
    $check( 'blocked' === $classify( $row, $bad )['state'], "unknown {$flag} is blocked" );
}
foreach ( array( 'gateway', 'remote-mcp', 'unexpected' ) as $kind ) {
    $bad = $row; $bad['server_kind'] = $kind;
    $check( 'blocked' === $classify( $bad, $context )['state'], "legacy {$kind} cannot be converted as a standard server" );
}
$bad = $row; $bad['kind'] = 'bundle';
$check( 'blocked' === $classify( $bad, $context )['state'], 'existing native changes must not be overwritten' );
foreach ( array( '{', '{"provider":"getmcp"}', '{"provider":"getmcp","allowed_user_ids":["1"]}', '{"provider":"getmcp","allowed_user_ids":[0]}' ) as $config ) {
    $bad = $row; $bad['auth_config'] = $config;
    $check( 'blocked' === $classify( $bad, $context )['state'], 'invalid allowlist is refused' );
}
foreach ( array( '{', '{"getmcp_extensions_required":true}', '{"personal_provider":{}}', '{"google_health":true}', '{"unreviewed":true}' ) as $settings ) {
    $bad = $row; $bad['settings'] = $settings;
    $check( 'blocked' === $classify( $bad, $context )['state'], 'invalid or add-on-owned settings cannot be detached' );
}
$other = $row; $other['auth_config'] = '{"provider":"external"}';
$check( 'not_managed' === $classify( $other, $context )['state'], 'external OAuth is outside this migration' );
echo wp_json_encode_for_test( array( 'passed' => $checks ) ) . PHP_EOL;
function wp_json_encode_for_test( $value ): string { return (string) json_encode( $value ); }
