<?php
/** Remove only the synthetic conflict table created by upgrade-180-conflict-seed.php. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1.26' !== get_option( 'getmcp_db_version' ) ) { throw new RuntimeException( 'Local deferred fixture required.' ); }
global $wpdb;
$server = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}getmcp_servers WHERE name='QA 180 automatic remote'" );
$target = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX;
$only = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$target}" );
$matching = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$target} WHERE server_id=%d AND user_id=1 AND BINARY credentials=BINARY %s AND version=%s", $server, 'synthetic-conflicting-ciphertext', 'synthetic-conflict' ) );
if ( $server < 1 || 1 !== $only || 1 !== $matching || false === $wpdb->query( "DROP TABLE {$target}" ) ) { throw new RuntimeException( 'Synthetic conflict did not match; nothing removed.' ); }
echo wp_json_encode( array( 'synthetic_conflict_removed' => true ) ) . PHP_EOL;
