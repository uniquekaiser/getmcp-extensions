<?php
/** Credential-free before/after evidence for the owned marketing browser server. */
global $wpdb;
$uuid = get_option( 'getmcp_marketing_browser_fixtures' )['google']['google-ads']['uuid'] ?? '';
$s = ( new GetMCP\Core\ServerManager() )->get_by_uuid( $uuid );
if ( ! $s || ! str_starts_with( $s->name, 'Marketing Fixture ' ) ) { throw new RuntimeException( 'Owned fixture missing.' ); }
$state = array();
foreach ( array( 'auth_config', 'auth_credentials', 'outbound_auth_credentials', 'settings', 'status' ) as $field ) { $state[$field] = hash( 'sha256', (string) $s->$field ); }
$file = '/evidence/connections-browser-state.json';
if ( 'before' === ( $args[0] ?? '' ) ) { file_put_contents( $file, wp_json_encode( $state ) ); echo 'Owned browser baseline captured (hashes only).'; }
else { $before = json_decode( file_get_contents( $file ), true ); if ( $before !== $state ) { throw new RuntimeException( 'Browser save changed protected fixture data.' ); } echo 'Browser save/reload preserves all protected fixture hashes.'; }
