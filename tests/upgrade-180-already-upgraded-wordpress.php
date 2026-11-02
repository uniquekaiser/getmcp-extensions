<?php
/** Verify automatic recovery after core upgraded before the add-on was activated. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed isolated 1.8 fixture required.' ); }
global $wpdb; $state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
if ( ! is_array( $state ) || empty( $state['remote'] ) || empty( $state['gateway'] ) || empty( $state['grant_hash'] ) ) { throw new RuntimeException( 'Synthetic state required.' ); }
$remote = (int) $state['remote']; $gateway = (int) $state['gateway']; $native = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::NATIVE_SUFFIX; $legacy = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX; $checks = 0;
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$grant = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM `{$legacy}` WHERE server_id=%d AND user_id=1", $remote ), ARRAY_A );
$check( is_array( $grant ) && hash_equals( $state['grant_hash'], hash( 'sha256', wp_json_encode( $grant ) ) ), 'The original encrypted personal grant and metadata remain unchanged.' );
$check( null !== \GetMCP\RemoteMcp\UpstreamConnections::find( $remote, 2, str_repeat( 'c', 64 ) ), 'The separately created native grant remains usable.' );
$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$native}`", 0 ); $index = $wpdb->get_results( "SHOW INDEX FROM `{$native}` WHERE Key_name='PRIMARY'", ARRAY_A );
$check( in_array( 'id', $columns, true ) && count( $index ) === 1 && 'id' === $index[0]['Column_name'] && 'ready-v1' === get_option( 'getmcp_extensions_180_native_schema' ), 'The native primary-key layout was automatically repaired.' );
$manager = new \GetMCP\Core\ServerManager();
$check( 'wordpress' === $manager->get( $remote )->auth_type && 'wordpress' === $manager->get( $gateway )->auth_type, 'The original remote and project Gateway were automatically converted.' );
\GetMCPExtensions\Upgrade180Store::maybe_repair_native_schema();
$check( $grant === $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM `{$legacy}` WHERE server_id=%d AND user_id=1", $remote ), ARRAY_A ) && null !== \GetMCP\RemoteMcp\UpstreamConnections::find( $remote, 2, str_repeat( 'c', 64 ) ), 'Repeated preparation leaves both grants intact.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic already-upgraded automatic recovery' ) ) . PHP_EOL;
