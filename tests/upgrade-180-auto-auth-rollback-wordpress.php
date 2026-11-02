<?php
/** Verify 1.7 automatically restores only sign-in types recorded by 1.8. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Local rolled-back 1.7 fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$remote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", (int) $state['remote'] ), ARRAY_A );
$gateway = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic gateway'", (int) $state['gateway'] ), ARRAY_A );
$standard = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic standard'", (int) $state['standard'] ), ARRAY_A );
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$check( $remote && $gateway && $standard && 'oauth' === $remote['auth_type'] && 'oauth' === $gateway['auth_type'] && 'oauth' === $standard['auth_type'], 'All migrated sign-in types were restored automatically.' );
$check( 'remote-mcp' === $remote['server_kind'] && 'gateway' === $gateway['server_kind'], 'Legacy kinds remain unchanged.' );
$auth = json_decode( $gateway['auth_config'], true );
$settings = json_decode( $gateway['settings'], true );
$expected_users = getenv( 'GETMCP_EXPECT_GATEWAY_REVOKED' ) ? array() : array( 1 );
$expected_members = getenv( 'GETMCP_EXPECT_GATEWAY_REVOKED' ) ? array() : array( (int) $state['remote'] );
$check( $expected_users === $auth['allowed_user_ids'] && $expected_members === $settings['gateway']['server_ids'], 'Gateway access and membership settings are preserved.' );
$grant = \GetMCP\Remote\UpstreamConnections::get( (int) $state['remote'], 1 );
$check( 'synthetic-user-client' === ( $grant['data']['client_id'] ?? '' ) && 'synthetic-refresh' === ( $grant['data']['refresh_token'] ?? '' ), 'Original per-user upstream grant remains readable.' );
$check( array() === json_decode( (string) get_option( \GetMCPExtensions\Upgrade180Auto::LEDGER ), true ), 'Restored rows were removed from the migration ledger.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'automatic 1.7 sign-in rollback' ) ) . PHP_EOL;
