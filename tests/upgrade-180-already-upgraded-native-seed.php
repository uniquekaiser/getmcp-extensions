<?php
/** Seed a synthetic native grant after core upgraded while Extensions was inactive. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1.35' !== get_option( 'getmcp_db_version' ) ) { throw new RuntimeException( 'Isolated already-upgraded fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
if ( ! is_array( $state ) || empty( $state['remote'] ) ) { throw new RuntimeException( 'Synthetic state required.' ); }
global $wpdb; $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", (int) $state['remote'] ) );
if ( 'QA 180 automatic remote' !== $name ) { throw new RuntimeException( 'Unexpected synthetic remote.' ); }
$version = \GetMCP\RemoteMcp\UpstreamConnections::save( (int) $state['remote'], 2, str_repeat( 'c', 64 ), array( 'access_token' => 'synthetic-native-token', 'refresh_token' => 'synthetic-native-refresh', 'expires_at' => time() + 3600, 'scope' => '' ) );
if ( null === $version ) { throw new RuntimeException( 'Could not seed a native grant.' ); }
echo wp_json_encode( array( 'seeded' => true, 'fixture' => 'synthetic native grant on already-upgraded core' ) ) . PHP_EOL;
