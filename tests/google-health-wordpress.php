<?php
/** Shared owned fixture only. Synthetic Google HTTP, real WordPress storage/auth/execution. */
use GetMCPExtensions\GoogleHealth as Health;
use GetMCPExtensions\ProviderConnections as Connections;
use GetMCP\Core\ServerManager;
use GetMCP\Core\ToolManager;
use GetMCP\Remote\UpstreamConnections as Store;

if ( 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! get_option( 'getmcp_qa_paid_fixture' ) ) { throw new RuntimeException( 'Owned shared fixture required.' ); }
global $wpdb;
$checks = array(); $server = null; $gateway = null; $user_ids = array(); $requests = array(); $mode = 'valid';
$assert = static function( $ok, string $name ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $name ); } $checks[] = $name; };
$reject = static function( callable $fn, string $name ) use ( $assert ) { try { $fn(); } catch ( Throwable $e ) { $assert( true, $name ); return; } $assert( false, $name ); };
$prior_settings = get_option( 'getmcp_settings', false );
$previous_user = get_current_user_id(); $marketing = get_option( 'getmcp_extensions_marketing_module', false );
$row = new ReflectionProperty( GetMCP\Auth\McpAuth::class, 'current_token_row' ); $prior_row = $row->getValue();
$baseline = hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT id,auth_config,auth_credentials,settings,status FROM {$wpdb->prefix}getmcp_servers ORDER BY id", ARRAY_A ) ) );
$accounts = hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_upstream_connections ORDER BY server_id,user_id", ARRAY_A ) ) );
$http = static function( $pre, $args, $url ) use ( &$requests, &$mode ) {
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( 'health.googleapis.com' !== $host && 'oauth2.googleapis.com' !== $host ) { return $pre; }
    $requests[] = array( 'url' => $url, 'method' => $args['method'] ?? 'GET', 'headers' => $args['headers'] ?? array(), 'body' => $args['body'] ?? null );
    $status = 200; $body = array();
    if ( 'oauth2.googleapis.com' === $host ) {
        $body = array( 'access_token' => 'fixture-health-refreshed', 'token_type' => 'Bearer', 'expires_in' => 3600 );
    } else {
        $path = wp_parse_url( $url, PHP_URL_PATH ); $query = array(); wp_parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
        if ( in_array( $mode, array( '401', '403', '429', '500' ), true ) ) { $status = (int) $mode; $body = array( 'error' => array( 'message' => 'fixture-secret-account-do-not-return' ) ); }
        elseif ( 'malformed' === $mode ) { return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => 'not-json' ); }
        elseif ( preg_match( '#/(identity|profile|settings)$#', $path ) ) { $body = array( 'email' => 'fixture-health-private@example.invalid', 'displayName' => 'Private Account', 'height' => 172, 'nested' => array( 'location' => array( 'latitude' => 4.7, 'city' => 'Private city' ), 'access_token' => 'fixture-upstream-secret' ) ); }
        else {
            $rollup = str_contains( $path, ':dailyRollUp' ) || str_contains( $path, ':rollUp' ); $key = $rollup ? 'rollupDataPoints' : 'dataPoints';
            $b = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : array();
            if ( str_contains( $path, ':dailyRollUp' ) && ( ! isset( $b['range']['start']['date']['year'], $b['range']['end']['date']['day'] ) || isset( $b['range']['startTime'] ) ) ) { return new WP_Error( 'health_contract_mismatch', 'Expected CivilTimeInterval.start/end with CivilDateTime.date.' ); }
            $cursor = $query['pageToken'] ?? ( $b['pageToken'] ?? '' );
            $marker = str_contains( $args['headers']['Authorization'] ?? '', '-b' ) ? 987654 : 123456;
            $body = array( $key => 'empty' === $mode ? array() : array( array( 'steps' => array( 'countSum' => $marker ), 'nutritionLog' => array( 'energy' => 350 ), 'nested' => array( 'email' => 'fixture-health-private@example.invalid', 'route' => array( 'lat' => 4.7 ) ) ) ) );
            if ( 'omitted' === $mode ) { $body = new stdClass(); }
            if ( 'invalid-list' === $mode ) { $body[$key] = 'invalid'; }
            if ( in_array( $mode, array( 'pages', 'repeat', 'bounded' ), true ) ) {
                if ( 'repeat' === $mode || 'bounded' === $mode || '' === $cursor ) { $body['nextPageToken'] = 'repeat' === $mode ? 'same' : ( 'bounded' === $mode ? 'page-' . count( $requests ) : 'second' ); }
            }
        }
    }
    return array( 'response' => array( 'code' => $status, 'message' => 'Synthetic provider' ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $body ) );
};
add_filter( 'pre_http_request', $http, 1, 3 );
$sm = new ServerManager(); $tm = new ToolManager();
try {
    wp_set_current_user( 1 );
    $server = $sm->create( array( 'name' => 'Google Health Disposable ' . wp_generate_uuid4(), 'slug' => 'health-test-' . wp_generate_password( 8, false, false ), 'status' => 'draft', 'auth_type' => 'none' ) );
    $assert( $server instanceof GetMCP\Core\Server, 'disposable_native_server_created' );
    $request = new WP_REST_Request( 'POST', '/getmcp/v1/templates/synergetic-google-health/install' ); $request->set_param( 'server_id', $server->uuid );
    $installed = rest_do_request( $request );
    $assert( 201 === $installed->get_status(), 'template_installs_through_native_rest' );
    $server = $sm->get( $server->id ); $assert( 'draft' === $server->status && ! $server->auth_credentials, 'template_stays_draft_without_credentials' );
    $assert( ! Connections::can_connect( $server, 1 ), 'draft_cannot_connect' );
    $reinstall = rest_do_request( $request ); $assert( $reinstall->get_status() >= 400, 'nonempty_target_rejected_without_duplicate_tools' );
    $tools = array(); foreach ( $tm->get_by_server( $server->id, array( 'per_page' => 100 ) )['items'] as $t ) { $tools[str_replace( 'google_health_', '', $t->slug )] = $t; }
    $assert( 12 === count( $tools ), 'twelve_tools_installed' );
    foreach ( $tools as $t ) { $assert( ! empty( $t->get_effective_annotations()['readOnlyHint'] ), 'readonly_' . $t->slug ); }
    $config = Health::preset()['auth_config']; $config['client_id'] = 'fixture-health-client';
    foreach ( array( 'a', 'b' ) as $label ) { $id = wp_create_user( 'health-' . $label . '-' . wp_generate_password( 8, false, false ), wp_generate_password( 32 ), 'health-' . wp_generate_uuid4() . '@example.invalid' ); if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture user creation failed.' ); } $user_ids[] = (int) $id; }
    $settings = GetMCPExtensions\AuthenticationSettings::object( $server->settings ); $settings['personal_provider']['allowed_user_ids'] = $user_ids;
    $sm->update( $server->id, array( 'status' => 'active', 'auth_config' => $config, 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'fixture-health-secret' ) ), 'settings' => wp_json_encode( $settings ) ) ); $server = $sm->get( $server->id );
    $assert( ! Connections::can_connect( $server, 1 ), 'administrator_has_no_personal_bypass' );
    $native_save = new WP_REST_Request( 'PATCH', '/getmcp/v1/servers/' . $server->uuid );
    $edited = $config; $edited['client_id'] = 'fixture-health-edited'; unset( $edited['extra_authorize_params'] );
    $native_save->set_header( 'content-type', 'application/json' ); $native_save->set_body( wp_json_encode( array( 'auth_type' => 'oauth', 'auth_config' => $edited, 'auth_credentials' => array( 'client_secret' => 'fixture-health-edited-secret' ) ) ) );
    $saved = rest_do_request( $native_save ); $server = $sm->get( $server->id );
    $assert( 200 === $saved->get_status() && 'offline' === ( GetMCPExtensions\ProviderTokens::config( $server )['extra_authorize_params']['access_type'] ?? '' ), 'native_editor_client_change_retains_offline_consent' );
    $full_scope = $config['scope'];
    $save = static function( $user, $scope, $expiry = null ) use ( &$server ) {
        Store::save( $server->id, $user, array( 'kind' => 'rest-provider', 'config_hash' => Connections::fingerprint( $server ), 'access_token' => 'fixture-health-' . ( $user % 2 ? 'a' : 'b' ), 'refresh_token' => 'fixture-health-refresh', 'expires_at' => $expiry ?? time() + 3600, 'scope' => $scope ), Store::version( $server->id, $user ) );
    };
    foreach ( $user_ids as $id ) { $save( $id, $full_scope ); }
    $select = static function( $id ) use ( $row, &$server ) { wp_set_current_user( $id ); $row->setValue( null, (object) array( 'id' => 97000 + $id, 'user_id' => $id, 'server_id' => $server->id, 'resource' => $server->get_endpoint_url() ) ); };
    $select( $user_ids[0] ); $executor = new GetMCP\Execution\ToolExecutor();
    $call = static function( $name, $args = array() ) use ( &$tools, &$server, $executor ) { return $executor->execute( $tools[$name], $args, false, $server ); };
    $payload = static fn( $result ) => json_decode( $result['content'][0]['text'], true );
    $ok = static function( $name, $args = array() ) use ( $assert, $call ) { $r = $call( $name, $args ); $assert( empty( $r['isError'] ), 'exec_' . $name ); return $r; };
    $bad = static function( $name, $args, $label ) use ( $assert, $call, &$requests ) { $before = count( $requests ); $r = $call( $name, $args ); $assert( ! empty( $r['isError'] ) && count( $requests ) === $before, $label ); };
    $url = Connections::begin( $server, $user_ids[0] )['authorization_url']; parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q );
    $assert( 'offline' === $q['access_type'] && 'consent' === $q['prompt'] && 'S256' === $q['code_challenge_method'], 'offline_consent_pkce' );
    delete_option( 'getmcp_provider_state_' . hash( 'sha256', $q['state'] ) );
    foreach ( array( 'get_identity', 'get_profile', 'get_settings' ) as $name ) { $r = $ok( $name ); $assert( ! str_contains( wp_json_encode( $r ), 'fixture-health-private' ) && ! str_contains( wp_json_encode( $r ), 'fixture-upstream-secret' ) && ! str_contains( wp_json_encode( $r ), 'Private city' ), 'privacy_' . $name ); }
    $ok( 'data_inventory' ); $status = $payload( $ok( 'connection_status' ) ); $assert( false === $status['live_read_verified'], 'status_does_not_claim_live_proof' );
    $ok( 'list_data_points', array( 'data_type' => 'steps', 'page_size' => 3, 'page_token' => 'cursor', 'filter' => 'steps.interval.civil_start_time >= "2026-10-01"' ) );
    $last = end( $requests ); $assert( str_contains( $last['url'], 'pageSize=3' ) && str_contains( $last['url'], 'pageToken=cursor' ) && 'GET' === $last['method'], 'native_query_mapping' );
    $ok( 'reconcile_data_points', array( 'data_type' => 'sleep', 'data_source_family' => 'users/me/dataSourceFamilies/google-wearables' ) );
    $ok( 'daily_rollup', array( 'data_type' => 'steps', 'start_date' => '2026-10-01', 'end_date' => '2026-10-02' ) ); $last = end( $requests ); $body = json_decode( $last['body'], true );
    $assert( 'POST' === $last['method'] && $body['range']['start'] === array( 'date' => array( 'year' => 2026, 'month' => 10, 'day' => 1 ) ) && ! isset( $body['range']['startTime'] ) && ! isset( $body['start_date'] ), 'daily_civil_body_mapping' );
    $ok( 'rollup', array( 'data_type' => 'steps', 'start_time' => '2026-10-01T00:00:00-05:00', 'end_time' => '2026-10-02T00:00:00-05:00', 'window_size' => '86400s' ) );
    $ok( 'nutrition' ); $assert( str_contains( end( $requests )['url'], '/nutrition-log/dataPoints' ), 'nutrition_real_read_endpoint' );
    $daily = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02', 'timezone' => 'America/Bogota' ) ) );
    $assert( 10 === count( $daily['domains'] ) && 'observed' === $daily['domains']['nutrition-log']['status'] && $daily['complete'], 'daily_includes_observed_nutrition' );
    $weekly = $payload( $ok( 'weekly_summary', array( 'date' => '2026-10-02' ) ) ); $assert( '2026-09-26' === $weekly['start_date'] && '2026-10-03' === $weekly['end_date_exclusive'], 'weekly_seven_civil_dates' );
    $mode = 'empty'; $empty = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02' ) ) ); $assert( 'no_data' === $empty['domains']['steps']['status'] && array() === $empty['domains']['steps']['observations'], 'empty_not_zero' );
    $mode = 'omitted'; $omitted = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02' ) ) ); $assert( 'no_data' === $omitted['domains']['steps']['status'], 'protobuf_omitted_empty_list_is_no_data' );
    $mode = 'invalid-list'; $invalid = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02' ) ) ); $assert( 'unavailable' === $invalid['domains']['steps']['status'], 'invalid_list_is_not_no_data' );
    $mode = 'pages'; $paged = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02' ) ) ); $assert( 2 === $paged['domains']['steps']['point_count'] && $paged['complete'], 'summary_pagination_preserves_cursor' );
    $mode = 'repeat'; $repeated = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02' ) ) ); $assert( 'unavailable' === $repeated['domains']['steps']['status'] && ! $repeated['complete'], 'repeated_cursor_is_gap' );
    $mode = 'bounded'; $before = count( $requests ); $bounded = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02' ) ) ); $assert( 'partial' === $bounded['domains']['steps']['status'] && count( $requests ) - $before === 30, 'pagination_bounded_three_pages_per_domain' );
    $mode = 'valid'; $save( $user_ids[0], Health::SCOPE . 'activity_and_fitness.readonly' );
    $partial = $payload( $ok( 'daily_summary', array( 'date' => '2026-10-02' ) ) ); $assert( 'observed' === $partial['domains']['steps']['status'] && 'unavailable' === $partial['domains']['nutrition-log']['status'] && ! $partial['complete'], 'partial_consent_keeps_permitted_domains' );
    $bad( 'nutrition', array(), 'missing_nutrition_scope_refused_before_http' );
    $save( $user_ids[0], $full_scope, time() - 1 ); $ok( 'nutrition' ); $assert( 'fixture-health-refreshed' === Connections::grant( $server, $user_ids[0] )['data']['access_token'], 'expired_grant_refreshes_and_preserves_scope' );
    $save( $user_ids[0], $full_scope );
    foreach ( array( array( 'daily_rollup', array( 'data_type' => 'steps', 'start_date' => '2026-02-30', 'end_date' => '2026-03-01' ) ), array( 'daily_rollup', array( 'data_type' => 'total-calories', 'start_date' => '2026-10-01', 'end_date' => '2026-10-20' ) ), array( 'daily_rollup', array( 'data_type' => 'sleep', 'start_date' => '2026-10-01', 'end_date' => '2026-10-02' ) ), array( 'list_data_points', array( 'data_type' => '../../profile' ) ), array( 'nutrition', array( 'access_token' => 'injected' ) ), array( 'list_data_points', array( 'data_type' => 'steps', 'page_size' => '25' ) ), array( 'weekly_summary', array( 'timezone' => 'invalid-zone' ) ) ) as $i => $case ) { $bad( $case[0], $case[1], 'invalid_query_before_http_' . $i ); }
    $original = $tools['nutrition']->endpoint_url; $tools['nutrition']->endpoint_url = 'https://example.org/steal'; $bad( 'nutrition', array(), 'tampered_endpoint_refused' ); $tools['nutrition']->endpoint_url = $original;
    foreach ( array( '401', '403', '429', '500', 'malformed' ) as $error ) { $mode = $error; $r = $call( 'nutrition' ); $assert( ! empty( $r['isError'] ) && ! str_contains( wp_json_encode( $r ), 'fixture-secret-account' ), 'safe_error_' . $error ); }
    $mode = 'valid';
    $a = $payload( $ok( 'nutrition' ) ); $select( $user_ids[1] ); $b = $payload( $ok( 'nutrition' ) ); $assert( $a !== $b, 'users_receive_distinct_personal_data' );
    $select( $user_ids[0] ); $r = $call( 'nutrition' ); $assert( ! isset( $r['_replay_data'] ), 'no_replay_payload' );
    $cache_key = 'getmcp_resp_' . md5( $tools['nutrition']->id . '|' . wp_json_encode( array() ) . '|' . GetMCPExtensions\ProviderExecution::identity( $server ) ); $assert( false === get_transient( $cache_key ), 'health_bypasses_response_cache' );
    $logging_settings = GetMCPExtensions\AuthenticationSettings::object( $prior_settings ); $logging_settings['log_response_data'] = true; $logging_settings['default_cache_ttl'] = 3600; update_option( 'getmcp_settings', $logging_settings );
    $handler = new GetMCP\Protocol\ToolsCallHandler( $server, null ); $r = $handler->handle( 'tools/call', array( 'name' => 'google_health_nutrition', 'arguments' => array() ) ); $assert( empty( $r['isError'] ), 'real_protocol_handler_read' );
    $logs = wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT arguments,response_data,replay_data FROM {$wpdb->prefix}getmcp_call_logs WHERE server_id=%d", $server->id ), ARRAY_A ) );
    $assert( '[]' !== $logs && str_contains( $logs, 'Health payload omitted' ) && ! str_contains( $logs, 'countSum' ) && ! str_contains( $logs, 'fixture-health-' ) && ! str_contains( $logs, 'nutritionLog' ), 'database_logs_do_not_retain_health_payload' );
    wp_set_current_user( 1 );
    $g = GetMCP\Gateway\FeatureManager::run( 'save', array( 'kind' => 'gateway', 'name' => 'Google Health Disposable Gateway', 'server_ids' => array( $server->id ), 'allowed_user_ids' => array( $user_ids[0] ) ) ); $gateway = $sm->get( $g['id'] );
    $settings['personal_provider']['allowed_user_ids'] = array(); $sm->update( $server->id, array( 'settings' => wp_json_encode( $settings ) ) );
    $row->setValue( null, (object) array( 'id' => 99001, 'user_id' => $user_ids[0], 'server_id' => $gateway->id, 'resource' => $gateway->get_endpoint_url() ) ); wp_set_current_user( $user_ids[0] );
    $assert( Connections::can_connect( $sm->get( $server->id ), $user_ids[0] ) && ! Connections::can_connect( $sm->get( $server->id ), $user_ids[1] ), 'gateway_only_personal_access_is_explicit' );
    $gateway_handler = new GetMCP\Gateway\FeatureHandler( $gateway, null ); $catalogue = $gateway_handler->handle( 'tools/list', array() ); $nutrition_name = '';
    foreach ( $catalogue['tools'] as $entry ) { if ( str_ends_with( $entry['name'], 'google_health_nutrition' ) ) { $nutrition_name = $entry['name']; } }
    $assert( '' !== $nutrition_name && empty( $gateway_handler->handle( 'tools/call', array( 'name' => $nutrition_name, 'arguments' => array() ) )['isError'] ), 'project_gateway_routes_native_health_with_personal_grant' );
    wp_set_current_user( 1 ); GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $gateway->id, 'server_ids' => array() ) ); wp_set_current_user( $user_ids[0] );
    $before = count( $requests ); $reject( fn() => $gateway_handler->handle( 'tools/call', array( 'name' => $nutrition_name, 'arguments' => array() ) ), 'removed_gateway_member_refused' ); $assert( $before === count( $requests ), 'removed_gateway_member_never_contacts_google' );
    wp_set_current_user( 1 ); $sm->delete( $gateway->id ); $gateway = null; $settings['personal_provider']['allowed_user_ids'] = $user_ids; $sm->update( $server->id, array( 'settings' => wp_json_encode( $settings ) ) ); $select( $user_ids[0] );
    $debug = $executor->execute_test( $tools['nutrition'], array(), $server ); $assert( null === $debug['raw_response'] && ! str_contains( wp_json_encode( $debug ), 'fixture-health-' ), 'test_debug_omits_raw_payload_and_credentials' );
    update_option( 'getmcp_extensions_marketing_module', array( 'enabled' => false ) ); $assert( Connections::can_connect( $server, $user_ids[0] ) && GetMCPExtensions\Runtime::server_enabled( $server ) && ! getmcp_extensions_guard_row( (object) $server->to_array() ), 'health_independent_of_marketing_switch' );
    $ok( 'nutrition' );
    $settings['personal_provider']['allowed_user_ids'] = array( $user_ids[1] ); $sm->update( $server->id, array( 'settings' => wp_json_encode( $settings ) ) ); $bad( 'nutrition', array(), 'revoked_user_denied_before_http' );
    $settings['personal_provider']['allowed_user_ids'] = $user_ids; $sm->update( $server->id, array( 'settings' => wp_json_encode( $settings ) ) );
    Store::disconnect( $server->id, $user_ids[0] ); $bad( 'nutrition', array(), 'disconnected_user_has_no_shared_fallback' );
    $scrub = Health::redact( array( 'latitude_e7' => 47, 'location' => array( 'unanticipatedCoordinate' => 42 ), 'nested' => array( 'Authorization' => 'secret', 'steps' => 10 ), 'nextPageToken' => 'cursor', 'altitude' => 123 ) );
    $assert( ! isset( $scrub['latitude_e7'], $scrub['location'] ) && $scrub['nested'] === array( 'steps' => 10 ) && 'cursor' === $scrub['nextPageToken'] && 123 === $scrub['altitude'], 'recursive_privacy_preserves_physiology_and_pagination' );
} finally {
    remove_filter( 'pre_http_request', $http, 1 ); $row->setValue( null, $prior_row ); wp_set_current_user( 1 );
    false === $prior_settings ? delete_option( 'getmcp_settings' ) : update_option( 'getmcp_settings', $prior_settings );
    false === $marketing ? delete_option( 'getmcp_extensions_marketing_module' ) : update_option( 'getmcp_extensions_marketing_module', $marketing );
    if ( $gateway ) { $sm->delete( $gateway->id ); }
    if ( $server ) { $wpdb->delete( $wpdb->prefix . 'getmcp_call_logs', array( 'server_id' => $server->id ) ); $sm->delete( $server->id ); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $user_ids as $id ) { wp_delete_user( $id ); }
    wp_set_current_user( $previous_user );
}
$assert( $baseline === hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT id,auth_config,auth_credentials,settings,status FROM {$wpdb->prefix}getmcp_servers ORDER BY id", ARRAY_A ) ) ), 'unrelated_servers_preserved' );
$assert( $accounts === hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_upstream_connections ORDER BY server_id,user_id", ARRAY_A ) ) ), 'unrelated_personal_connections_preserved' );
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks, 'provider' => 'synthetic', 'live_google_acceptance' => 'unavailable: real Google application and consent required' ), JSON_PRETTY_PRINT );
