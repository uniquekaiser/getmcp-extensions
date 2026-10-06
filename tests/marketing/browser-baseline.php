<?php
/** Prepare public fixture forms and record hashes only; never export credentials. */
$fixtures = get_option( 'getmcp_marketing_browser_fixtures' ); $m = new GetMCP\Core\ServerManager(); $result = array();
foreach ( $fixtures['google'] as $preset => $item ) {
    $s = $m->get( $item['id'] ); $config = GetMCPExtensions\ProviderDiscovery::presets()[$preset]['auth_config'];
    $m->update( $s->id, array( 'auth_config' => $config ) ); $s = $m->get( $s->id );
    $result[$preset] = array( 'id' => $s->id, 'uuid' => $s->uuid, 'secret_hash' => hash( 'sha256', $s->auth_credentials ), 'advanced' => GetMCPExtensions\AuthenticationSettings::object( $s->auth_config )['extra_authorize_params'] );
}
file_put_contents( '/evidence/browser-baseline.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
echo wp_json_encode( $result );
