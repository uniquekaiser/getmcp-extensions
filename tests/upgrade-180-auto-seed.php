<?php
/** Create disposable legacy records before upgrading the shared test fixture. */
if ( wp_get_environment_type() !== 'local' || GETMCP_VERSION !== '1.7.0' || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed local 1.7 fixture required.' ); }
wp_set_current_user( 1 );
$remote = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'remote-mcp', 'name' => 'QA 180 automatic remote', 'allowed_user_ids' => array( 1 ), 'remote' => array( 'endpoint' => 'https://example.org/mcp', 'auth_mode' => 'oauth', 'client_id' => 'synthetic-client' ) ) );
$gateway = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'QA 180 automatic gateway', 'allowed_user_ids' => array( 1 ), 'server_ids' => array( $remote['id'] ) ) );
$server = ( new \GetMCP\Core\ServerManager() )->get( $remote['id'] );
$data = array( 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_at' => time() + 3600, 'client_id' => 'synthetic-user-client', 'client_secret' => 'synthetic-user-secret', 'metadata' => array( 'issuer' => 'https://example.org/qa-issuer', 'authorization_endpoint' => 'https://example.org/authorize', 'token_endpoint' => 'https://example.org/token', 'token_endpoint_auth_methods_supported' => array( 'client_secret_basic', 'none' ) ), 'config_hash' => \GetMCP\Remote\UpstreamConnections::config_hash( $server ) );
\GetMCP\Remote\UpstreamConnections::save( $server->id, 1, $data );
global $wpdb;
$store = \GetMCP\Remote\UpstreamConnections::table();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$store} WHERE server_id=%d AND user_id=1", $server->id ), ARRAY_A );
if ( ! $row ) { throw new RuntimeException( 'Synthetic grant was not saved.' ); }
echo wp_json_encode( array( 'remote' => $server->id, 'gateway' => $gateway['id'], 'grant_hash' => hash( 'sha256', wp_json_encode( $row ) ) ) ) . PHP_EOL;
