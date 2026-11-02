<?php
/** Reconstruct only members unambiguously inside the old 500-server limit. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb; $prior_prefix = $wpdb->prefix; $prefix = 'qa180_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 ) . '_'; $table = $prefix . 'getmcp_servers'; $checks = 0;
$names = array( 'getmcp_gateway_enabled', 'getmcp_gateway_auth_type', 'getmcp_gateway_mode', 'getmcp_gateway_servers', 'getmcp_db_version', \GetMCPExtensions\LegacyGateway180::OPTION );
$prior = array(); foreach ( $names as $name ) { $prior[$name] = get_option( $name, null ); }
if ( null !== $prior[\GetMCPExtensions\LegacyGateway180::OPTION] ) {
	$saved = json_decode( (string) $prior[\GetMCPExtensions\LegacyGateway180::OPTION], true ); $manager = new \GetMCP\Core\ServerManager();
	if ( ! is_array( $saved['members'] ?? null ) ) { throw new RuntimeException( 'Unexpected existing snapshot.' ); }
	foreach ( array_keys( $saved['members'] ) as $id ) { $server = $manager->get( (int) $id ); if ( ! $server || ! str_starts_with( $server->name, 'QA 180 ' ) ) { throw new RuntimeException( 'Only a synthetic snapshot may be temporarily replaced.' ); } }
	delete_option( \GetMCPExtensions\LegacyGateway180::OPTION );
}
$scope = static fn() => null; add_filter( 'getmcp_extensions_180_migration_scope', $scope, 1000 );
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
	$wpdb->prefix = $prefix;
	$check( false !== $wpdb->query( "CREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL,uuid VARCHAR(80) NOT NULL,name VARCHAR(80) NOT NULL,status VARCHAR(20) NOT NULL,auth_type VARCHAR(20) NOT NULL,auth_config TEXT NOT NULL,server_kind VARCHAR(20) NOT NULL,settings TEXT NOT NULL,outbound_auth_credentials TEXT NULL,PRIMARY KEY(id)) ENGINE=InnoDB" ), 'Synthetic server table created.' );
	$values = array(); for ( $id = 1; $id <= 501; ++$id ) { $values[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%s,%s)', $id, 'synthetic-' . $id, sprintf( 'Synthetic %03d', $id ), 'active', 'oauth', '{"provider":"getmcp","allowed_user_ids":[]}', 'native', '{}' ); }
	$check( 501 === $wpdb->query( "INSERT INTO `{$table}` (id,uuid,name,status,auth_type,auth_config,server_kind,settings) VALUES " . implode( ',', $values ) ), 'Synthetic 501-server inventory created.' );
	update_option( 'getmcp_db_version', '1.26' ); update_option( 'getmcp_gateway_enabled', 1 ); update_option( 'getmcp_gateway_auth_type', 'oauth' ); update_option( 'getmcp_gateway_mode', 'all' ); update_option( 'getmcp_gateway_servers', array() );
	$result = \GetMCPExtensions\LegacyGateway180::prepare(); $snapshot = json_decode( (string) get_option( \GetMCPExtensions\LegacyGateway180::OPTION ), true );
	$check( ! is_wp_error( $result ) && 500 === count( $snapshot['members'] ?? array() ) && isset( $snapshot['members']['500'] ) && ! isset( $snapshot['members']['501'] ), 'Unambiguous first 500 members are captured without including the 501st.' );
	delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); $wpdb->update( $table, array( 'name' => 'Synthetic 500' ), array( 'id' => 501 ) );
	$result = \GetMCPExtensions\LegacyGateway180::prepare(); $snapshot = json_decode( (string) get_option( \GetMCPExtensions\LegacyGateway180::OPTION ), true );
	$check( ! is_wp_error( $result ) && 499 === count( $snapshot['members'] ?? array() ) && ! isset( $snapshot['members']['500'] ) && ! isset( $snapshot['members']['501'] ) && true === ( $snapshot['boundary_ambiguous'] ?? false ), 'A tie crossing the boundary is flagged and does not guess which server was included.' );
	$check( \GetMCPExtensions\LegacyGateway180::review_required(), 'The signed boundary ambiguity is visible to the Gateway review warning.' );
	$tampered = $snapshot; unset( $tampered['boundary_ambiguous'] ); update_option( \GetMCPExtensions\LegacyGateway180::OPTION, wp_json_encode( $tampered ) );
	$check( is_wp_error( \GetMCPExtensions\LegacyGateway180::prepare() ), 'The boundary warning is protected by the snapshot signature.' );
	delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); $wpdb->update( $table, array( 'name' => 'Synthetic 501' ), array( 'id' => 501 ) ); update_option( 'getmcp_gateway_mode', 'selected' ); update_option( 'getmcp_gateway_servers', array( 1, 500, 501 ) );
	$result = \GetMCPExtensions\LegacyGateway180::prepare(); $snapshot = json_decode( (string) get_option( \GetMCPExtensions\LegacyGateway180::OPTION ), true );
	$check( ! is_wp_error( $result ) && 2 === count( $snapshot['members'] ?? array() ) && isset( $snapshot['members']['1'], $snapshot['members']['500'] ) && ! isset( $snapshot['members']['501'] ), 'Selected mode preserves only selected members inside the old limit.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic 501-server Gateway boundary' ) ) . PHP_EOL;
} finally {
	$wpdb->prefix = $prior_prefix; remove_filter( 'getmcp_extensions_180_migration_scope', $scope, 1000 );
	if ( ! preg_match( '/\Aqa180_[a-f0-9]{10}_\z/D', $prefix ) ) { throw new RuntimeException( 'Unexpected fixture table prefix.' ); }
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	foreach ( $prior as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
}
