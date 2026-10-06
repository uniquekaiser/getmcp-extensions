<?php
$baseline = json_decode( file_get_contents( '/evidence/browser-baseline.json' ), true ); $m = new GetMCP\Core\ServerManager(); $checks = array();
foreach ( $baseline as $preset => $before ) {
    $s = $m->get( $before['id'] ); $checks[$preset . '_encrypted_secret_unchanged'] = hash_equals( $before['secret_hash'], hash( 'sha256', $s->auth_credentials ) );
    $checks[$preset . '_advanced_settings_unchanged'] = $before['advanced'] === GetMCPExtensions\AuthenticationSettings::object( $s->auth_config )['extra_authorize_params'];
}
foreach ( $m->list( array( 'per_page' => 500 ) )['items'] as $server ) {
    if ( 'Marketing Fixture Browser Import' !== $server->name ) { continue; }
    $headers = GetMCPExtensions\ConnectionHeaders::read( $server );
    $checks['browser_import_custom_header_preserved'] = ( $headers['X-Project'] ?? '' ) === 'fixture-browser-header';
    $checks['browser_import_header_encrypted'] = ! str_contains( $server->outbound_auth_credentials, 'fixture-browser-header' );
    $public = GetMCP\Gateway\FeatureManager::present( $server );
    $checks['browser_saved_header_value_not_returned'] = ! str_contains( wp_json_encode( $public ), 'fixture-browser-header' );
    $checks['browser_scope_edit_persisted'] = $public['remote']['scope'] === 'fixture:read';
}
if ( in_array( false, $checks, true ) ) { throw new RuntimeException( 'Browser preservation failed.' ); }
file_put_contents( '/evidence/browser-preservation.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT ) );
echo wp_json_encode( $checks );
