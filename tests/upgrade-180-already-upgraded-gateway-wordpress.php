<?php
/** Verify the user's chosen safe fallback for a Gateway with no pre-upgrade history. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed isolated 1.8 fixture required.' ); }
global $wpdb; $state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
if ( ! is_array( $state ) || 'api-key' !== ( $state['builtin_auth'] ?? null ) || empty( $state['builtin_remote_hash_none'] ) || empty( $state['builtin_remote_hash_shared'] ) ) { throw new RuntimeException( 'Synthetic Gateway state required.' ); }
$checks = 0; $check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$check( false === get_option( \GetMCPExtensions\LegacyGateway180::OPTION, false ) && \GetMCPExtensions\LegacyGateway180::review_required(), 'Missing historical delegation is flagged instead of reconstructed.' );
wp_set_current_user( 1 ); ob_start(); \GetMCPExtensions\LegacyGateway180::notice(); $notice = (string) ob_get_clean();
$check( str_contains( $notice, 'Gateway' ) && str_contains( $notice, 'review' ), 'The administrator sees the Gateway warning.' );
$selected = \GetMCP\Gateway\McpGateway::selected_server_ids();
foreach ( array( 'none', 'shared' ) as $kind ) {
	$id = (int) $state['builtin_remote_' . $kind]; $row = $wpdb->get_row( $wpdb->prepare( "SELECT auth_config,settings,outbound_auth_credentials FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $id ), ARRAY_A );
	$check( is_array( $row ) && hash_equals( $state['builtin_remote_hash_' . $kind], hash( 'sha256', wp_json_encode( $row ) ) ) && in_array( $id, $selected, true ), 'The original ' . $kind . ' remote settings and Gateway membership are retained.' );
}
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$response = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $state['builtin_base'] ), array( 'headers' => array( 'Host' => $host, 'X-API-Key' => $state['builtin_key'], 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list' ), 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass() ) ) ) ), 'redirection' => 0, 'timeout' => 30 ) );
if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Synthetic Gateway request failed.' ); }
$body = json_decode( wp_remote_retrieve_body( $response ), true ); $names = array_column( $body['result']['tools'] ?? array(), 'name' );
$check( 200 === wp_remote_retrieve_response_code( $response ) && ! in_array( 'qa-180-builtin-remote-none__synthetic_read', $names, true ) && ! in_array( 'qa-180-builtin-remote-shared__synthetic_read', $names, true ), 'The old key does not receive unverified member access.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic already-upgraded Gateway safe fallback' ) ) . PHP_EOL;
