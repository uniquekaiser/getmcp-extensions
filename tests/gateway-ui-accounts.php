<?php
/** Disposable browser fixtures only: two independent, fictitious upstream grants. */
wp_set_current_user( 1 );
$server = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'remote-mcp', 'name' => 'UI OAuth accounts', 'slug' => 'ui-oauth-accounts', 'allowed_user_ids' => array( 1, 2 ), 'remote' => array( 'endpoint' => 'https://example.org/getmcp-qa/oauth', 'auth_mode' => 'oauth', 'client_id' => 'qa-client' ) ) );
$object = ( new \GetMCP\Core\ServerManager() )->get( $server['id'] );
foreach ( array( 1, 2 ) as $user ) {
    \GetMCP\Remote\UpstreamConnections::save( $object->id, $user, array( 'access_token' => $user === 1 ? 'fixture-account-a' : 'fixture-account-b', 'expires_at' => time() + 3600, 'config_hash' => \GetMCP\Remote\UpstreamConnections::config_hash( $object ) ) );
}
echo wp_json_encode( array( 'server_id' => $object->id, 'fixture' => 'Disposable account portal browser checks only' ) );
