<?php
/** Real executor and database with synthetic writes only. Run after wordpress.php. */
use GetMCPExtensions\ProviderConnections as PC;
use GetMCPExtensions\PageTokens as Pages;
use GetMCPExtensions\ProviderExecution as PX;
use GetMCPExtensions\ProtocolEnvelope as Envelope;
use GetMCPExtensions\MarketingModule as Module;
global $wpdb;
$f = get_option( 'getmcp_marketing_browser_fixtures' ); $sm = new GetMCP\Core\ServerManager(); $tm = new GetMCP\Core\ToolManager();
$s = $sm->get( $f['instagram']['id'] ); $a = $f['users'][0]; $b = $f['users'][1]; $checks = array();
$assert = function( $v, $name ) use ( &$checks ) { $checks[$name] = (bool) $v; if ( ! $v ) { throw new RuntimeException( 'FAIL: ' . $name ); } };
$reject = function( $fn, $name ) use ( $assert ) { try { $fn(); } catch ( Throwable $e ) { $assert( true, $name ); return; } $assert( false, $name ); };
update_option( 'getmcp_fixture_provider_mode', 'valid' ); wp_set_current_user( $a );
$g = PC::grant( $s, $a ); Pages::discover( $s, 'user:' . $a, $g['data']['access_token'] ); Pages::select( $s, 'user:' . $a, $g['data']['access_token'], '101', '1101' ); Pages::verify( $s, 'user:' . $a, $g['data']['access_token'] );
$row = new ReflectionProperty( GetMCP\Auth\McpAuth::class, 'current_token_row' );
$row->setValue( null, (object) array( 'id' => 91001, 'user_id' => $a, 'server_id' => $s->id, 'resource' => $s->get_endpoint_url() ) );
foreach ( $tm->get_by_server( $s->id, array( 'per_page' => 100 ) )['items'] as $old ) { if ( in_array( $old->name, array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true ) ) { $tm->delete( $old->id ); } }
$tools = array();
foreach ( array( 'get_conversations', 'get_conversation_messages', 'send_dm' ) as $slug ) {
    $path = $slug === 'get_conversation_messages' ? '/{{conversation_id}}' : '/{{page_id}}/' . ( $slug === 'send_dm' ? 'messages' : 'conversations' );
    $required = $slug === 'get_conversation_messages' ? 'conversation_id' : 'page_id';
    $tools[$slug] = $tm->create( array( 'server_id' => $s->id, 'name' => $slug, 'slug' => $slug, 'status' => 'draft', 'endpoint_url' => 'https://graph.facebook.com/v26.0' . $path, 'http_method' => $slug === 'send_dm' ? 'POST' : 'GET', 'input_schema' => array( 'type' => 'object', 'properties' => array( $required => array( 'type' => 'string' ) ), 'required' => array( $required ) ), 'parameter_mapping' => array( $required => array( 'target' => 'path', 'key' => $required ) ), 'retry_count' => 3 ) );
}
$tool = $tools['send_dm']; $request = ( new GetMCP\Execution\ParameterResolver() )->resolve( $tool, array( 'page_id' => '101' ) );
$injected = PX::inject( $request, $s, $tool, array( 'page_id' => '101' ), false );
$assert( $injected['headers']['Authorization'] === 'Bearer fixture-page-a-101', 'real_execution_seam_selects_private_page_token' );
$bad = $request; $bad['url'] = 'https://example.org/stolen'; $reject( fn() => PX::inject( $bad, $s, $tool, array( 'page_id' => '101' ), false ), 'page_token_never_sent_to_other_host' );
$identityA = PX::identity( $s ); $row->setValue( null, (object) array( 'id' => 91002, 'user_id' => $b, 'server_id' => $s->id, 'resource' => $s->get_endpoint_url() ) );
$assert( PX::identity( $s ) !== $identityA, 'provider_cache_separates_users' );
$row->setValue( null, (object) array( 'id' => 91001, 'user_id' => $a, 'server_id' => $s->id, 'resource' => 'http://localhost:8916/mcp/another-gateway' ) );
$assert( PX::identity( $s ) !== $identityA, 'provider_cache_separates_gateway_resources' );
$assert( PX::retries( $tool ) === 0, 'mutation_retries_forced_zero' );
$executor = new GetMCP\Execution\ToolExecutor(); update_option( 'getmcp_fixture_message_count', 0 );
$result = $executor->execute( $tool, array( 'page_id' => '101' ), false, $s );
$assert( empty( $result['isError'] ) && get_option( 'getmcp_fixture_message_count' ) === 1, 'synthetic_message_uses_real_executor_once' );
update_option( 'getmcp_fixture_provider_mode', 'ambiguous_write' ); $reject( fn() => $executor->execute( $tool, array( 'page_id' => '101' ), false, $s ), 'ambiguous_write_reports_error' );
$assert( get_option( 'getmcp_fixture_message_count' ) === 2, 'ambiguous_write_not_retried' );
update_option( 'getmcp_fixture_provider_mode', 'valid' ); $row->setValue( null, null );
$debug = $executor->execute_test( $tool, array( 'page_id' => '101' ), $s );
$assert( ! str_contains( wp_json_encode( $debug ), 'fixture-page-a-101' ), 'admin_test_debug_does_not_return_private_page_token' );
$row->setValue( null, (object) array( 'id' => 91001, 'user_id' => $a, 'server_id' => $s->id, 'resource' => $s->get_endpoint_url() ) );
update_option( 'getmcp_fixture_provider_mode', 'denied_scopes' );
$reject( fn() => Pages::verify( $s, 'user:' . $a, $g['data']['access_token'] ), 'scope_revocation_checked_again_at_verification' );
$reject( fn() => Pages::token( $s, 'user:' . $a, $g['data']['access_token'], array() ), 'failed_verification_invalidates_previous_readiness' );
update_option( 'getmcp_fixture_provider_mode', 'valid' ); Pages::verify( $s, 'user:' . $a, $g['data']['access_token'] );
$reject( fn() => Module::configure( $s->uuid, array( 'configuration_revision' => 'stale', 'personal_provider' => array( 'allowed_user_ids' => array() ) ) ), 'provider_configuration_stale_edit_rejected' );
$user = get_userdata( $a ); $user->add_cap( 'getmcp_manage_servers' ); $user->add_cap( 'getmcp_manage_tools' ); wp_set_current_user(0); wp_set_current_user($a);
$route = new WP_REST_Request( 'POST', '/getmcp/v1/servers/' . $s->uuid . '/enable-messaging' );
$enabled = rest_do_request( $route );
if ($enabled->get_status() !== 200) { echo wp_json_encode($enabled->get_data()); }
$assert( $enabled->get_status() === 200 && count( $enabled->get_data()['enabled'] ) === 3, 'existing_three_drafts_enabled_only_after_verified_connection' );
$assert( $tm->get( $tool->id )->status === 'active' && $tm->get( $tool->id )->retry_count === 0, 'messaging_activation_sets_safe_execution_policy' );
$saved = Module::configuration( $s->uuid ); $saved['personal_provider']['allowed_user_ids'] = array( $a ); Module::configure( $s->uuid, array( 'configuration_revision' => $saved['configuration_revision'], 'personal_provider' => $saved['personal_provider'] ) );
$assert( ! PC::can_connect( $sm->get( $s->id ), $b ), 'removed_personal_allowlist_user_immediately_denied' );
$saved = Module::configuration( $s->uuid ); $saved['personal_provider']['allowed_user_ids'] = array( $a, $b ); Module::configure( $s->uuid, array( 'configuration_revision' => $saved['configuration_revision'], 'personal_provider' => $saved['personal_provider'] ) );
$user->remove_cap( 'getmcp_manage_servers' ); $user->remove_cap( 'getmcp_manage_tools' );
$nested = array( 'content' => array( array( 'type' => 'image', 'data' => 'Zml4dHVyZQ==', 'mimeType' => 'image/png' ), array( 'type' => 'text', 'text' => '{"id":1,"jsonrpc":"2.0","result":{"structuredContent":{},"content":[{"type":"text","text":"read"}]}}' ) ) );
$decoded = Envelope::decode( wp_json_encode( $nested ) );
$assert( count( $decoded['content'] ) === 2 && $decoded['structuredContent'] instanceof stdClass, 'nested_blocks_and_empty_structured_objects_preserved' );
$reject( fn() => Envelope::decode( '{"content":[],"isError":"false"}' ), 'malformed_tool_error_type_rejected' );
$reject( fn() => Envelope::decode( "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"content\":[]}}\n\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{\"content\":[]}}\n\n" ), 'ambiguous_multiple_sse_results_rejected' );
$row->setValue( null, null ); wp_set_current_user( 1 );
foreach ( $tools as $t ) { $tm->delete( $t->id ); }
file_put_contents( '/evidence/execution.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks, 'live_provider_writes' => false ), JSON_PRETTY_PRINT ) );
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ) );
