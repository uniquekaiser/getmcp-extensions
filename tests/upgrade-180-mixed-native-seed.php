<?php
/** Run with Extensions skipped after upgrading a synthetic 1.7 database. */
if ( wp_get_environment_type() !== 'local' || GETMCP_VERSION !== '1.8.0' || ! getenv( 'GETMCP_AUTO_IDS' ) ) { throw new RuntimeException( 'Local already-upgraded fixture required.' ); }
global $wpdb;
$remote = (int) explode( ',', getenv( 'GETMCP_AUTO_IDS' ) )[0];
$name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $remote ) );
if ( 'QA 180 automatic remote' !== $name || '1.35' !== get_option( 'getmcp_db_version' ) ) { throw new RuntimeException( 'Unexpected synthetic fixture.' ); }
$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}getmcp_upstream_connections" );
if ( ! in_array( 'config_hash', $columns, true ) ) { throw new RuntimeException( 'Core did not update the shared table.' ); }
$result = \GetMCP\RemoteMcp\UpstreamConnections::save( $remote, 2, str_repeat( 'c', 64 ), array( 'access_token' => 'synthetic-native-token', 'refresh_token' => 'synthetic-native-refresh', 'expires_at' => time() + 3600, 'scope' => '' ) );
if ( null === $result ) { throw new RuntimeException( 'Could not seed the synthetic native grant.' ); }
echo wp_json_encode( array( 'seeded' => true, 'fixture' => 'already upgraded shared table' ) ) . PHP_EOL;
