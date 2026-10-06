<?php
/** Independently read the new template created by the owned browser test. */
if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'localhost' || (int) wp_parse_url( home_url(), PHP_URL_PORT ) !== 8917 || ! get_option( 'getmcp_qa_paid_fixture' ) ) { throw new RuntimeException( 'Owned browser fixture required.' ); }
$items = ( new GetMCP\Core\ServerManager() )->list( array( 'per_page' => 100, 'orderby' => 'id', 'order' => 'DESC' ) )['items'];
$items = array_values( array_filter( $items, static fn( $s ) => 'Google Ads · Synergetic' === $s->name && 'draft' === $s->status ) );
if ( ! $items ) { throw new RuntimeException( 'Browser-created draft template missing.' ); }
$s = $items[0]; $settings = GetMCPExtensions\AuthenticationSettings::object( $s->settings );
$checks = array( 'browser_template_created_as_draft' => 'draft' === $s->status, 'browser_template_has_three_tools' => 3 === ( new GetMCP\Core\ToolManager() )->get_by_server( $s->id )['total'], 'browser_template_has_no_credentials' => ! $s->auth_credentials && ! $s->outbound_auth_credentials, 'browser_template_personal_allowlist_denies_everyone' => array() === $settings['personal_provider']['allowed_user_ids'] );
if ( in_array( false, $checks, true ) ) { throw new RuntimeException( wp_json_encode( $checks ) ); }
file_put_contents( '/evidence/connections-browser-template.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT ) ); echo wp_json_encode( $checks );
