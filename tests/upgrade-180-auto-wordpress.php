<?php
/** Test automatic split on the real upgraded fixture; all grant inputs are synthetic. */
if ( wp_get_environment_type() !== 'local' || GETMCP_VERSION !== '1.8.0' || ! getenv( 'GETMCP_AUTO_IDS' ) || ! getenv( 'GETMCP_AUTO_GRANT_HASH' ) ) { throw new RuntimeException( 'Local 1.8 fixture and explicit synthetic IDs/hash required.' ); }
global $wpdb;
$ids = array_map( 'intval', explode( ',', getenv( 'GETMCP_AUTO_IDS' ) ) );
if ( count( $ids ) !== 2 || min( $ids ) <= 0 ) { throw new RuntimeException( 'Invalid synthetic IDs.' ); }
list( $remote, $gateway ) = $ids;
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name FROM {$wpdb->prefix}getmcp_servers WHERE id IN (%d,%d)", $remote, $gateway ), OBJECT_K );
if ( ( $rows[$remote]->name ?? '' ) !== 'QA 180 automatic remote' || ( $rows[$gateway]->name ?? '' ) !== 'QA 180 automatic gateway' ) { throw new RuntimeException( 'Unexpected synthetic records.' ); }
$checks = 0;
$check = static function( bool $condition, string $name ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $name ); } };
$native = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::NATIVE_SUFFIX;
$legacy = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX;
$check( \GetMCPExtensions\Upgrade180Store::SEPARATE === get_option( \GetMCPExtensions\Upgrade180Store::OPTION ), 'automatic migration marker saved' );
$check( '1.35' === get_option( 'getmcp_db_version' ), 'native core schema migration completed' );
$native_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$native}" );
$check( in_array( 'id', $native_columns, true ) && in_array( 'config_hash', $native_columns, true ), 'core receives the correct native schema' );
$grant = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$legacy} WHERE server_id=%d AND user_id=1", $remote ), ARRAY_A );
$check( $grant && hash_equals( getenv( 'GETMCP_AUTO_GRANT_HASH' ), hash( 'sha256', wp_json_encode( $grant ) ) ), 'legacy ciphertext and metadata are unchanged' );
$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$native} WHERE server_id=%d AND user_id=1", $remote ) ), 'legacy grant was not placed in native storage' );
$check( getmcp_extensions_guard_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $remote ) ) ), 'unported legacy remote remains guarded' );
$check( getmcp_extensions_guard_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $gateway ) ) ), 'unported legacy gateway remains guarded' );
$hash = str_repeat( 'a', 64 );
$credentials = array( 'access_token' => 'synthetic-native-token', 'refresh_token' => 'synthetic-native-refresh', 'expires_at' => time() + 3600, 'scope' => '' );
$check( null !== \GetMCP\RemoteMcp\UpstreamConnections::save( $remote, 1, $hash, $credentials ), 'native store accepts a separate synthetic grant for the same pair' );
$check( null !== \GetMCP\RemoteMcp\UpstreamConnections::find( $remote, 1, $hash ), 'native grant is readable' );
\GetMCP\RemoteMcp\UpstreamConnections::find( $remote, 1, str_repeat( 'b', 64 ) );
$grant_after = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$legacy} WHERE server_id=%d AND user_id=1", $remote ), ARRAY_A );
$check( $grant_after === $grant, 'native mismatch deletion cannot erase the legacy grant' );
\GetMCPExtensions\Upgrade180Store::maybe_prepare();
$check( $grant_after === $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$legacy} WHERE server_id=%d AND user_id=1", $remote ), ARRAY_A ), 'automatic preparation is idempotent' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic automatic 1.7 to 1.8 upgrade' ) ) . PHP_EOL;
