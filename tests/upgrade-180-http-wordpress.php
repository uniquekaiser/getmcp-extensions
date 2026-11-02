<?php
/** Real local HTTP bridge rehearsal, using the explicitly test-only MU bootstrap. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 HTTP fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$remote_id = (int) ( $state['remote'] ?? 0 );
$gateway_id = (int) ( $state['gateway'] ?? 0 );
$remote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", $remote_id ), ARRAY_A );
$gateway = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic gateway'", $gateway_id ), ARRAY_A );
$standard = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic standard'", (int) ( $state['standard'] ?? 0 ) ), ARRAY_A );
if ( ! $remote || ! $gateway || ! $standard || 'remote-mcp' !== $remote['server_kind'] || 'gateway' !== $gateway['server_kind'] || 'native' !== $standard['server_kind'] ) { throw new RuntimeException( 'Synthetic legacy servers required.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$check( '1.35' === get_option( 'getmcp_db_version' ) && \GetMCPExtensions\Upgrade180Store::SEPARATE === get_option( \GetMCPExtensions\Upgrade180Store::OPTION ), 'Core upgrade and grant separation completed.' );
$legacy = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX;
$grant = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$legacy} WHERE server_id=%d AND user_id=1", $remote_id ), ARRAY_A );
$check( $grant && hash_equals( $state['grant_hash'], hash( 'sha256', wp_json_encode( $grant ) ) ), 'Pre-upgrade personal grant is byte-preserved.' );
$check( 'wordpress' === $remote['auth_type'] && 'wordpress' === $gateway['auth_type'] && 'wordpress' === $standard['auth_type'], 'Legacy gateway, remote and standard sign-in migrated automatically.' );
$fields = array( 'id', 'uuid', 'user_id', 'name', 'slug', 'server_id', 'status', 'transport_type', 'auth_config', 'auth_credentials', 'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials', 'test_auth_type', 'test_auth_credentials', 'cors_origins', 'rate_limit_per_min', 'settings', 'server_kind', 'created_at', 'updated_at' );
$fingerprint = static fn( array $row ): string => hash( 'sha256', wp_json_encode( array_intersect_key( $row, array_flip( $fields ) ) ) );
$check( hash_equals( $state['remote_hash'], $fingerprint( $remote ) ) && hash_equals( $state['gateway_hash'], $fingerprint( $gateway ) ) && hash_equals( $state['standard_hash'], $fingerprint( $standard ) ), 'All other server settings and credentials remain unchanged.' );
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function ( string $url, array $body, string $bearer = '', bool $form = false, string $method = '' ) use ( $host ): array {
	$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json', 'MCP-Protocol-Version' => '2026-07-28' );
	if ( $bearer ) { $headers['Authorization'] = 'Bearer ' . $bearer; }
	if ( $method ) { $headers['Mcp-Method'] = $method; $body['params']['_meta'] = array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass() ); }
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 30 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic gateway HTTP request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$rpc = static fn( string $method, array $params = array() ): array => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params );
$standard_list = $http( $state['standard_url'], $rpc( 'tools/list' ), $state['standard_access'], false, 'tools/list' );
$check( 200 === $standard_list['status'] && 'qa_180_standard_tool' === ( $standard_list['body']['result']['tools'][0]['name'] ?? '' ), 'Existing standard server and its tool remain available.' );
$standard_call = $http( $state['standard_url'], $rpc( 'tools/call', array( 'name' => 'qa_180_standard_tool' ) ), $state['standard_access'], false, 'tools/call' );
$check( 200 === $standard_call['status'] && ! empty( $standard_call['body']['result']['content'] ), 'Existing standard tool executes with its pre-upgrade grant.' );
$standard_refresh = $http( $state['standard_url'] . '/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $state['standard_refresh'], 'client_id' => $state['standard_client'], 'resource' => $state['standard_url'] ), '', true );
$check( 200 === $standard_refresh['status'] && ! empty( $standard_refresh['body']['access_token'] ), 'Existing standard server refresh grant remains usable.' );
$list = $http( $state['base'], $rpc( 'tools/list' ), $state['access'], false, 'tools/list' );
$name = $remote['slug'] . '__synthetic_read';
$check( 200 === $list['status'] && $name === ( $list['body']['result']['tools'][0]['name'] ?? '' ), 'Existing gateway token lists the remote tool over HTTP.' );
$call = $http( $state['base'], $rpc( 'tools/call', array( 'name' => $name ) ), $state['access'], false, 'tools/call' );
$check( 200 === $call['status'] && 'synthetic upstream result' === ( $call['body']['result']['content'][0]['text'] ?? '' ), 'Gateway invokes the remote tool using the original personal grant.' );
$write = $http( $state['base'], $rpc( 'tools/call', array( 'name' => $remote['slug'] . '__synthetic_write' ) ), $state['read_access'], false, 'tools/call' );
$check( isset( $write['body']['error'] ), 'A pre-upgrade read-only grant cannot invoke a remote write.' );
$resources = $http( $state['base'], $rpc( 'resources/list' ), $state['access'], false, 'resources/list' );
$uri = $resources['body']['result']['resources'][0]['uri'] ?? '';
$read = $http( $state['base'], $rpc( 'resources/read', array( 'uri' => $uri ) ), $state['access'], false, 'resources/read' );
$check( 200 === $read['status'] && $uri && $uri === ( $read['body']['result']['contents'][0]['uri'] ?? '' ), 'Gateway reads its namespaced remote resource.' );
$prompts = $http( $state['base'], $rpc( 'prompts/list' ), $state['access'], false, 'prompts/list' );
$prompt_name = $prompts['body']['result']['prompts'][0]['name'] ?? '';
$prompt = $http( $state['base'], $rpc( 'prompts/get', array( 'name' => $prompt_name ) ), $state['access'], false, 'prompts/get' );
$check( 200 === $prompt['status'] && 'synthetic prompt' === ( $prompt['body']['result']['messages'][0]['content']['text'] ?? '' ), 'Gateway retrieves the remote prompt.' );
$check( 401 === $http( $state['remote_url'], $rpc( 'tools/list' ), $state['access'], false, 'tools/list' )['status'], 'Gateway token cannot access the remote server directly.' );
$params = array( 'grant_type' => 'refresh_token', 'refresh_token' => $state['refresh'], 'client_id' => $state['client'], 'resource' => $state['base'] );
$refresh = $http( $state['base'] . '/oauth/token', $params, '', true );
$check( 200 === $refresh['status'] && ! empty( $refresh['body']['access_token'] ) && ! empty( $refresh['body']['refresh_token'] ), 'Pre-upgrade gateway refresh token remains usable.' );
$access = $refresh['body']['access_token'];
$check( 200 === $http( $state['base'], $rpc( 'tools/list' ), $access, false, 'tools/list' )['status'], 'Refreshed gateway token remains usable.' );
$settings = json_decode( $gateway['settings'], true );
$settings['gateway']['server_ids'] = array();
$wpdb->update( $table, array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway_id, 'name' => 'QA 180 automatic gateway' ) );
$removed = $http( $state['base'], $rpc( 'tools/call', array( 'name' => $name ) ), $access, false, 'tools/call' );
$check( isset( $removed['body']['error'] ), 'Removed gateway membership prevents further tool calls.' );
$auth = json_decode( $gateway['auth_config'], true );
$auth['allowed_user_ids'] = array();
$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $auth ) ), array( 'id' => $gateway_id, 'name' => 'QA 180 automatic gateway' ) );
$check( 401 === $http( $state['base'], $rpc( 'tools/list' ), $access, false, 'tools/list' )['status'], 'Revoked user cannot use the gateway token.' );
$params['refresh_token'] = $refresh['body']['refresh_token'];
$check( 400 === $http( $state['base'] . '/oauth/token', $params, '', true )['status'], 'Revoked user cannot refresh the gateway grant.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic end-to-end gateway bridge with test-only bootstrap' ) ) . PHP_EOL;
