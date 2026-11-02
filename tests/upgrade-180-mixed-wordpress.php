<?php
/** Check automatic separation after core 1.8 previously touched the legacy table. */
if ( wp_get_environment_type() !== 'local' || GETMCP_VERSION !== '1.8.0' || ! getenv( 'GETMCP_AUTO_IDS' ) || ! getenv( 'GETMCP_AUTO_GRANT_HASH' ) ) { throw new RuntimeException( 'Local already-upgraded fixture required.' ); }
global $wpdb;
$remote = (int) explode( ',', getenv( 'GETMCP_AUTO_IDS' ) )[0];
if ( 'QA 180 automatic remote' !== $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $remote ) ) ) { throw new RuntimeException( 'Unexpected synthetic remote.' ); }
$native = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::NATIVE_SUFFIX;
$legacy = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX;
$checks = 0;
$check = static function( bool $ok, string $name ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $name ); } };
$check( \GetMCPExtensions\Upgrade180Store::SEPARATE === get_option( \GetMCPExtensions\Upgrade180Store::OPTION ), 'automatic split completed' );
$grant = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$legacy} WHERE server_id=%d AND user_id=1", $remote ), ARRAY_A );
$check( $grant && hash_equals( getenv( 'GETMCP_AUTO_GRANT_HASH' ), hash( 'sha256', wp_json_encode( $grant ) ) ), 'legacy ciphertext and version preserved' );
$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$native} WHERE server_id=%d AND user_id=1", $remote ) ), 'legacy row moved out of native storage' );
$check( null !== \GetMCP\RemoteMcp\UpstreamConnections::find( $remote, 2, str_repeat( 'c', 64 ) ), 'pre-existing native grant remains readable' );
$check( getmcp_extensions_guard_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $remote ) ) ), 'legacy endpoint remains guarded pending compatibility' );
\GetMCPExtensions\Upgrade180Store::maybe_prepare();
$check( $grant === $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$legacy} WHERE server_id=%d AND user_id=1", $remote ), ARRAY_A ), 'repeated preparation does not change the grant' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic already-upgraded mixed storage' ) ) . PHP_EOL;
