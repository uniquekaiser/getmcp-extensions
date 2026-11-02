<?php
/** Read the same synthetic grant after restoring the 1.7 core source mount. */
if ( wp_get_environment_type() !== 'local' || GETMCP_VERSION !== '1.7.0' || ! getenv( 'GETMCP_AUTO_IDS' ) ) { throw new RuntimeException( 'Local rolled-back 1.7 fixture required.' ); }
$remote = (int) explode( ',', getenv( 'GETMCP_AUTO_IDS' ) )[0];
$server = ( new \GetMCP\Core\ServerManager() )->get( $remote );
if ( ! $server || $server->name !== 'QA 180 automatic remote' ) { throw new RuntimeException( 'Unexpected synthetic server.' ); }
$grant = \GetMCP\Remote\UpstreamConnections::get( $remote, 1 );
if ( ! $grant || ( $grant['data']['client_id'] ?? '' ) !== 'synthetic-user-client' || ( $grant['data']['refresh_token'] ?? '' ) !== 'synthetic-refresh' || ( $grant['data']['config_hash'] ?? '' ) !== \GetMCP\Remote\UpstreamConnections::config_hash( $server ) ) { throw new RuntimeException( 'Legacy grant did not survive the core rollback.' ); }
$new_version = \GetMCP\Remote\UpstreamConnections::save( $remote, 1, $grant['data'], $grant['version'] );
if ( $new_version === $grant['version'] || ! \GetMCP\Remote\UpstreamConnections::get( $remote, 1 ) ) { throw new RuntimeException( 'Legacy compare-and-swap update failed.' ); }
echo wp_json_encode( array( 'passed' => 2, 'fixture' => 'synthetic 1.7 core rollback' ) ) . PHP_EOL;
