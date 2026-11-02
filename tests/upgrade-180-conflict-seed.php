<?php
/** Create an intentionally conflicting, synthetic destination in the local fixture. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Local 1.7 fixture required.' ); }
global $wpdb;
$server = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}getmcp_servers WHERE name='QA 180 automatic remote'" );
if ( ! $server ) { throw new RuntimeException( 'Synthetic remote required.' ); }
$source = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::NATIVE_SUFFIX;
$target = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX;
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) ) ) { throw new RuntimeException( 'Destination already exists.' ); }
$old = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$source} WHERE server_id=%d AND user_id=1", $server ), ARRAY_A );
if ( ! $old || false === $wpdb->query( "CREATE TABLE {$target} LIKE {$source}" ) || 1 !== $wpdb->insert( $target, array( 'server_id' => $server, 'user_id' => 1, 'credentials' => 'synthetic-conflicting-ciphertext', 'version' => 'synthetic-conflict', 'updated_at' => '2026-10-30 12:00:00' ) ) ) { throw new RuntimeException( 'Could not prepare synthetic conflict.' ); }
echo wp_json_encode( array( 'server_id' => $server, 'legacy_hash' => hash( 'sha256', wp_json_encode( $old ) ) ) ) . PHP_EOL;
