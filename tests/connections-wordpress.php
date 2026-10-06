<?php
/** Owned WordPress/database fixture. Synthetic endpoints only; cleanup on success/failure. */
use GetMCPExtensions\ConfigurationImport as Importer;
use GetMCPExtensions\ConfigurationTranscoder as Converter;
use GetMCP\Core\ServerManager as SM;
use GetMCP\Gateway\FeatureManager as FM;
global $wpdb;
$checks = array(); $ids = array(); $tokens = array(); $sm = new SM();
$assert = function( $ok, $name ) use ( &$checks ) { $checks[$name] = (bool) $ok; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $name ); } };
$reject = function( $fn, $name ) use ( $assert ) { try { $fn(); } catch ( Throwable $e ) { $assert( true, $name ); return; } $assert( false, $name ); };
$rest = function( $method, $path, $data = null ) { $r = new WP_REST_Request( $method, '/getmcp/v1/' . $path ); if ( null !== $data ) { $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $data ) ); } return rest_do_request( $r ); };
$moduleBefore = get_option( 'getmcp_extensions_marketing_module', false );
$user = (int) ( username_exists( 'connections-qa' ) ?: wp_create_user( 'connections-qa', 'disposable-fixture-only', 'connections-qa@example.invalid' ) );
wp_set_current_user( 1 );
try {
    $p = Importer::preview( wp_json_encode( array( 'mcpServers' => array(
        'Connections QA A' => array( 'url' => 'https://example.org/getmcp-qa/json', 'headers' => array( 'X-QA-Key' => 'synthetic-header-secret' ) ),
        'Connections QA B' => array( 'url' => 'https://example.org/getmcp-qa/sse' ),
        'Connections QA Command' => array( 'command' => 'node', 'args' => array( 'fixture.js' ) ),
    ) ) ), 1 ); $tokens[] = $p['preview_token'];
    $assert( count( $p['entries'] ) === 3 && ! $p['entries'][2]['supported'], 'bulk_preview_includes_supported_and_command_only_entries' );
    $assert( ! str_contains( wp_json_encode( $p ), 'synthetic-header-secret' ), 'preview_redacts_header_values' );
    $args = array( 'preview_token' => $p['preview_token'], 'entries' => array(
        array( 'index' => 0, 'configuration' => array( 'status' => 'active', 'allowed_user_ids' => array( $user ) ) ),
        array( 'index' => 1, 'configuration' => array( 'name' => 'Connections QA A', 'status' => 'draft' ) ),
    ) );
    $r = Importer::commit_batch( $args, 1 ); $ids[] = $r['results'][0]['server']['id'];
    $assert( $r['created'] === 1 && 'failed' === $r['results'][1]['status'], 'batch_reports_success_and_duplicate_failure_independently' );
    $a = $sm->get( $ids[0] );
    $assert( ! str_contains( wp_json_encode( $r ), 'synthetic-header-secret' ) && ! GetMCP\Remote\UpstreamConnections::config( $a )['publish_original'], 'batch_response_secret_free_and_original_gateway_unpublished' );
    $headers = GetMCPExtensions\ConnectionHeaders::read( $a );
    $assert( ( $headers['X-QA-Key'] ?? '' ) === 'synthetic-header-secret', 'encrypted_import_header_retained' );
    $r = Importer::commit_batch( array( 'preview_token' => $p['preview_token'], 'entries' => array( array( 'index' => 1, 'configuration' => array( 'name' => 'Connections QA B corrected', 'status' => 'draft' ) ) ) ), 1 ); $ids[] = $r['results'][0]['server']['id'];
    $assert( 1 === $r['created'], 'failed_entry_can_be_corrected_and_retried' );
    $assert( 0 === Importer::commit_batch( $args, 1 )['created'], 'consumed_entries_cannot_be_replayed' );
    $reject( fn() => Importer::commit_batch( array( 'entries' => array( array( 'index' => 0 ), array( 'index' => 0 ) ) ), 1 ), 'duplicate_batch_indexes_rejected' );
    $reject( fn() => Importer::commit_batch( $args, $user ), 'subscriber_cannot_import' );
    $pending = Importer::preview( '{"mcpServers":{"Connections QA rollback":{"url":"https://example.org/getmcp-qa/json","headers":{"X-Key":"synthetic-rollback-secret"}}}}', 1 ); $tokens[] = $pending['preview_token'];
    $key = 'getmcp_import_preview_' . hash( 'sha256', $pending['preview_token'] ); $cipher = get_option( $key );
    $failed = Importer::commit_batch( array( 'preview_token' => $pending['preview_token'], 'entries' => array( array( 'index' => 0, 'configuration' => array( 'remote' => array( 'endpoint' => 'https://127.0.0.1/mcp' ) ) ) ) ), 1 );
    $assert( 0 === $failed['created'] && get_option( $key ) === $cipher, 'failed_transaction_preserves_encrypted_preview_for_retry' );
    $saved = Importer::commit( array( 'preview_token' => $pending['preview_token'], 'index' => 0 ), 1 ); $ids[] = $saved['id'];
    $assert( GetMCPExtensions\ConnectionHeaders::read( $sm->get( $saved['id'] ) )['X-Key'] === 'synthetic-rollback-secret', 'retry_preserves_secret_after_transaction_rollback' );
    $assert( 200 === $rest( 'GET', 'connection-config/' . $a->id )->get_status(), 'connection_metadata_route_registered' );
    $g = FM::run( 'save', array( 'kind' => 'gateway', 'name' => 'Connections QA Gateway', 'status' => 'active', 'allowed_user_ids' => array( $user ), 'server_ids' => array( $a->id ) ), 1 ); $ids[] = $g['id'];
    $list = $rest( 'GET', 'connections' )->get_data()['servers'];
    $assert( in_array( $g['id'], array_column( $list, 'id' ), true ), 'created_gateway_is_present_in_listing' );
    wp_set_current_user( $user ); $portal = $rest( 'GET', 'my-connections' )->get_data();
    $assert( ! $portal['can_manage_connections'] && in_array( $g['id'], array_column( $portal['endpoints'], 'id' ), true ), 'personal_portal_lists_allowed_gateway_without_create_permission' );
    $config = $rest( 'GET', 'connection-config/' . $g['id'] );
    $assert( 200 === $config->get_status() && $config->get_data()['url'] === $g['url'] && ! str_contains( wp_json_encode( $config->get_data() ), 'example.org' ), 'codex_config_uses_inbound_gateway_and_no_upstream_metadata' );
    wp_set_current_user( 0 ); $assert( 401 === $rest( 'GET', 'connection-config/' . $g['id'] )->get_status(), 'anonymous_cannot_get_connection_metadata' );
    wp_set_current_user( 1 ); $portal = $rest( 'GET', 'my-connections' )->get_data();
    $assert( $portal['can_manage_connections'] && ! in_array( $g['id'], array_column( $portal['endpoints'], 'id' ), true ), 'administrator_create_shortcut_does_not_bypass_endpoint_allowlist' );
    $f = $g; $f['allowed_user_ids'] = array(); FM::run( 'save', $f, 1 );
    wp_set_current_user( $user ); $assert( 403 === $rest( 'GET', 'connection-config/' . $g['id'] )->get_status(), 'removed_gateway_user_immediately_loses_config_access' );
    $assert( 403 === $rest( 'POST', 'connections/transcode', array( 'document' => '{}', 'format' => 'codex' ) )->get_status(), 'converter_requires_management_permission' );
    wp_set_current_user( 1 );
    $source = '{"mcpServers":{"one":{"url":"https://example.org/mcp","headers":{"X-Test":"synthetic-secret"}},"two":{"httpUrl":"https://example.org/two"}}}';
    $converted = Converter::convert( $source, 'codex' );
    $round = Importer::parse( $converted['configuration'] );
    $assert( 2 === count( $round ) && $round['one']['http_headers']['X-Test'] === 'REPLACE_LOCALLY' && ! str_contains( wp_json_encode( $converted ), 'synthetic-secret' ), 'codex_conversion_roundtrip_and_redaction' );
    $assert( ! str_contains( $converted['configuration'], 'mcp-remote' ) && str_contains( $converted['configuration'], 'url = ' ), 'codex_remote_config_uses_native_http' );
    $envText = Converter::convert( '{"mcpServers":{"env":{"url":"https://example.org/mcp","env_http_headers":{"X-Key":"GETMCP_AUTH_HEADER"}}}}', 'codex' )['configuration'];
    $assert( Importer::parse( $envText )['env']['env_http_headers']['X-Key'] === 'GETMCP_AUTH_HEADER', 'codex_environment_header_mapping_roundtrips_without_credentials' );
    $json = json_decode( Converter::convert( $source, 'vscode' )['configuration'], true );
    $assert( 'http' === $json['servers']['one']['type'] && 2 === count( $json['servers'] ), 'vscode_multi_server_output' );
    $assert( isset( json_decode( Converter::convert( $source, 'claude' )['configuration'], true )['mcpServers']['two'] ), 'claude_multi_server_output' );
    $reject( fn() => Converter::convert( '{"mcpServers":{"secret":{"url":"https://example.org/mcp?token=x"}}}', 'codex' ), 'converter_rejects_query_secrets' );
    $command = Converter::convert( '{"mcpServers":{"text":{"command":"node","args":["--token","synthetic-secret"],"env":{"KEY":"synthetic-env-secret"}}}}', 'codex' );
    $assert( ! str_contains( wp_json_encode( $command ), 'synthetic-secret' ) && ! str_contains( wp_json_encode( $command ), 'synthetic-env-secret' ) && $command['warnings'], 'command_conversion_is_redacted_text_only' );
    $templates = $rest( 'GET', 'templates' )->get_data();
    $assert( 5 === count( array_filter( $templates, fn( $t ) => str_starts_with( $t['slug'], 'synergetic-' ) ) ), 'five_marketing_templates_visible_in_native_index' );
    $created = $rest( 'POST', 'servers', array( 'name' => 'Connections QA UI Template', 'description' => 'Synthetic presentation description', 'auth_type' => 'none' ) );
    $assert( 201 === $created->get_status(), 'native_ui_server_creation_route_accepted' );
    $s = $sm->get_by_uuid( $created->get_data()['id'] ); $ids[] = $s->id;
    $installed = $rest( 'POST', 'templates/synergetic-google-ads/install', array( 'server_id' => $s->uuid ) );
    $assert( 201 === $installed->get_status() && GetMCPExtensions\AuthenticationSettings::object( $sm->get( $s->id )->settings )['description'] === 'Synthetic presentation description', 'native_ui_description_preserved_during_template_install' );
    foreach ( array( 'google-ads', 'google-analytics', 'google-search-console', 'meta-ads', 'instagram' ) as $slug ) {
        $s = $sm->create( array( 'name' => 'Connections QA Template ' . $slug, 'slug' => 'connections-qa-template-' . $slug ) ); $ids[] = $s->id;
        $response = $rest( 'POST', 'templates/synergetic-' . $slug . '/install', array( 'server_id' => $s->uuid ) );
        $assert( 201 === $response->get_status(), $slug . '_template_installs_into_empty_server' );
        $saved = $sm->get( $s->id ); $settings = GetMCPExtensions\AuthenticationSettings::object( $saved->settings );
        $assert( 'draft' === $saved->status && array() === $settings['personal_provider']['allowed_user_ids'] && ! $saved->auth_credentials, $slug . '_template_stays_draft_without_credentials_or_user_access' );
        $before = hash( 'sha256', wp_json_encode( $saved->to_array() ) );
        $assert( 400 === $rest( 'POST', 'templates/synergetic-' . $slug . '/install', array( 'server_id' => $s->uuid ) )->get_status() && hash( 'sha256', wp_json_encode( $sm->get( $s->id )->to_array() ) ) === $before, $slug . '_template_reinstall_does_not_overwrite_configuration' );
        if ( 'instagram' === $slug ) { $tools = ( new GetMCP\Core\ToolManager() )->get_by_server( $s->id, array( 'per_page' => 100 ) )['items']; $drafts = array_filter( $tools, fn( $t ) => in_array( $t->slug, array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true ) ); $assert( 3 === count( $drafts ) && ! array_filter( $drafts, fn( $t ) => 'inactive' !== $t->status ), 'template_preserves_three_inactive_messaging_drafts' ); }
    }
    wp_set_current_user( $user ); $assert( 403 === $rest( 'GET', 'templates' )->get_status(), 'marketing_templates_preserve_vendor_permission_check' ); wp_set_current_user( 1 );
    $protected = $sm->create( array( 'name' => 'Connections QA Protected', 'auth_type' => 'bearer', 'auth_credentials' => 'synthetic-protected-secret' ) ); $ids[] = $protected->id;
    $before = hash( 'sha256', wp_json_encode( $protected->to_array() ) );
    $assert( 400 === $rest( 'POST', 'templates/synergetic-google-ads/install', array( 'server_id' => $protected->uuid ) )->get_status() && hash( 'sha256', wp_json_encode( $sm->get( $protected->id )->to_array() ) ) === $before, 'template_rejects_credentialed_target_with_exact_preservation' );
    $config = $rest( 'GET', 'connection-config/' . $protected->uuid )->get_data();
    $assert( 'bearer' === $config['auth_type'] && ! str_contains( wp_json_encode( $config ), 'synthetic-protected-secret' ), 'native_quick_connect_metadata_preserves_auth_mode_without_secret' );
    $license = $GLOBALS['getmcp_license'];
    try {
        $GLOBALS['getmcp_license'] = new class { public function get_status() { return 'not_connected'; } public function get_license_data() { return array(); } }; GetMCP\Licensing\LicenseTier::clear_cache();
        $assert( 403 === $rest( 'POST', 'templates/synergetic-google-ads/install', array( 'server_id' => $protected->uuid ) )->get_status(), 'marketing_template_cannot_bypass_vendor_license_gate' );
    } finally { $GLOBALS['getmcp_license'] = $license; GetMCP\Licensing\LicenseTier::clear_cache(); }
    update_option( 'getmcp_extensions_marketing_module', array( 'enabled' => false ) );
    $assert( ! array_filter( $rest( 'GET', 'templates' )->get_data(), fn( $t ) => str_starts_with( $t['slug'], 'synergetic-' ) ), 'disabled_module_removes_owned_templates' );
    update_option( 'getmcp_extensions_marketing_module', array( 'enabled' => true ) );
    echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ) );
    file_put_contents( '/evidence/connections.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks, 'live_provider_writes' => false ), JSON_PRETTY_PRINT ) );
} finally {
    false === $moduleBefore ? delete_option( 'getmcp_extensions_marketing_module' ) : update_option( 'getmcp_extensions_marketing_module', $moduleBefore );
    wp_set_current_user( 1 ); foreach ( array_reverse( $ids ) as $id ) { $s = $sm->get( $id ); if ( $s && str_starts_with( $s->name, 'Connections QA ' ) ) { $sm->delete( $id ); } }
    foreach ( $tokens as $token ) { delete_option( 'getmcp_import_preview_' . hash( 'sha256', $token ) ); }
}
