<?php
/** Google Health read tools. Health payloads are never cached or sent to replay logging. */
namespace GetMCPExtensions;

use GetMCP\Core\ServerManager;
use GetMCP\Core\Tool;
use GetMCP\Core\ToolManager;
use GetMCP\Execution\ParameterResolver;
use GetMCP\Remote\RemoteException;
use GetMCP\Remote\SafeHttp;

final class GoogleHealth {
    public const BASE = 'https://health.googleapis.com';
    public const SCOPE = 'https://www.googleapis.com/auth/googlehealth.';

    public static function enabled(): bool { return Runtime::ready(); }
    public static function is_server( $server ): bool {
        if ( ! $server ) { return false; }
        $s = AuthenticationSettings::object( $server->settings );
        return ! empty( $s['google_health'] ) || 'google-health' === ( $s['personal_provider']['preset'] ?? '' ) || str_contains( ProviderTokens::config( $server )['scope'] ?? '', '/googlehealth.' );
    }
    public static function protects( $tool, $server = null ): bool {
        return self::is_server( $server ) || 'health.googleapis.com' === wp_parse_url( $tool->endpoint_url ?? '', PHP_URL_HOST ) || str_starts_with( $tool->slug ?? '', 'google_health_' );
    }
    public static function asset(): array {
        return json_decode( file_get_contents( GETMCP_EXTENSIONS_PATH . 'assets/templates/google-health.json' ), true, 32, JSON_THROW_ON_ERROR );
    }
    public static function preset(): array { return array( 'label' => 'Google Health', 'auth_config' => self::asset()['server']['auth_config'], 'requirements' => 'Enable Google Health API, configure a Google web OAuth application and its exact callback, then connect each permitted user. Nutrition reads require nutrition.readonly and available account data.' ); }

    /** Native auth editors omit extra params when a new client ID resets provider configuration. */
    public static function oauth_config( array $config ): array {
        if ( 'https://accounts.google.com/o/oauth2/v2/auth' !== ( $config['authorize_url'] ?? '' ) || 'https://oauth2.googleapis.com/token' !== ( $config['token_url'] ?? '' ) ) { throw new \InvalidArgumentException( 'Google Health requires the reviewed Google OAuth endpoints.' ); }
        $config['provider'] = 'external';
        $config['extra_authorize_params'] = array_replace( $config['extra_authorize_params'] ?? array(), array( 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true' ) );
        return $config;
    }

    public static function discover( $server, string $token, int $user ): array {
        $grant = ProviderConnections::grant( $server, $user );
        $scopes = preg_split( '/\s+/', trim( $grant['data']['scope'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
        foreach ( ( new ToolManager() )->get_by_server( $server->id, array( 'per_page' => 100 ) )['items'] as $tool ) {
            if ( 'google_health_get_profile' === $tool->slug && 'active' === $tool->status ) {
                self::read( $tool, array(), $token, $scopes );
                return array( 'read_verified' => true, 'complete' => true, 'reporting_verified' => false, 'measurements_returned' => false );
            }
        }
        throw new RemoteException( 'Enable the profile read tool and grant profile.readonly to verify this connection.' );
    }

    /** Curated read coverage. Unsupported combinations fail before sending HTTP. */
    public static function inventory(): array {
        $out = array();
        foreach ( array(
            'activity_and_fitness' => array( 'steps', 'distance', 'total-calories', 'active-zone-minutes', 'heart-rate' ),
            'health_metrics_and_measurements' => array( 'daily-resting-heart-rate', 'daily-heart-rate-variability', 'weight', 'body-fat' ),
            'sleep' => array( 'sleep' ),
            'nutrition' => array( 'nutrition-log', 'hydration-log', 'food', 'food-measurement-unit' ),
        ) as $scope => $types ) {
            foreach ( $types as $type ) {
                $ops = array( 'list', 'reconcile', 'daily_rollup', 'rollup' );
                if ( in_array( $type, array( 'sleep', 'daily-resting-heart-rate', 'daily-heart-rate-variability' ), true ) ) { $ops = array( 'list', 'reconcile' ); }
                if ( in_array( $type, array( 'food', 'food-measurement-unit' ), true ) ) { $ops = array( 'list' ); }
                $out[$type] = array( 'scope' => self::SCOPE . $scope . '.readonly', 'operations' => $ops );
            }
        }
        return $out;
    }

    // @wp-test feature=google.health scenario=health-read-summary
    // @wp-test feature=google.health scenario=health-access-refusal
    public static function execute( $tool, array $arguments, bool $test, $server = null ): array {
        try {
            $server = ( new ServerManager() )->get( $tool->server_id );
            if ( ! self::enabled() || ! self::is_server( $server ) || ! ProviderConnections::supported( $server ) || ! Runtime::server_enabled( $server ) || 'active' !== $tool->status ) { throw new RemoteException( 'Google Health requires an active personal Google OAuth server and an enabled tool.', -32003 ); }
            self::definition( $tool, $arguments );
            $context = ProviderExecution::context( $server, $test );
            $row = \GetMCP\Auth\McpAuth::current_token_row();
            $user = (int) ( $row->user_id ?? ( $test ? get_current_user_id() : 0 ) );
            // Broker grants have no authoritative returned Google scope set in the current core.
            if ( $user < 1 ) { throw new RemoteException( 'Connect Google Health in My MCP Connections and call it through a WordPress OAuth project gateway.', -32003 ); }
            $grant = ProviderConnections::grant( $server, $user );
            $scopes = preg_split( '/\s+/', trim( $grant['data']['scope'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
            $slug = $tool->slug;
            if ( 'google_health_data_inventory' === $slug ) {
                $payload = array( 'data_types' => self::inventory(), 'read_only' => true, 'nutrition' => 'Available only with nutrition.readonly and upstream account data.' );
            } elseif ( 'google_health_connection_status' === $slug ) {
                $payload = array( 'connected' => true, 'granted_scopes' => $scopes, 'scope_status' => isset( $grant['data']['scope'] ) ? 'reported' : 'unknown', 'live_read_verified' => false, 'storage' => 'Encrypted personal OAuth grant in WordPress; health response caching and replay disabled.' );
            } elseif ( in_array( $slug, array( 'google_health_daily_summary', 'google_health_weekly_summary' ), true ) ) {
                $payload = self::summary( $server, $context['token'], $scopes, $arguments, 'google_health_weekly_summary' === $slug );
            } else {
                $payload = self::read( $tool, $arguments, $context['token'], $scopes );
            }
            $result = array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) ) ) );
            if ( ! $test ) { \GetMCP\Licensing\CallCounter::increment(); }
            return $result;
        } catch ( \Throwable $e ) {
            // Never echo upstream response bodies, account IDs, tokens or exception traces.
            $message = $e instanceof RemoteException ? $e->getMessage() : 'Google Health could not complete this read. Check the connection and query.';
            return array( 'content' => array( array( 'type' => 'text', 'text' => $message ) ), 'isError' => true );
        }
    }

    public static function definition( $tool, array $args ): array {
        foreach ( self::asset()['tools'] as $d ) { if ( $d['slug'] === $tool->slug ) { $definition = $d; break; } }
        if ( ! isset( $definition ) || $tool->endpoint_url !== $definition['endpoint_url'] || $tool->http_method !== $definition['http_method'] || 'http' !== $tool->tool_type ) { throw new RemoteException( 'Restore the reviewed Google Health tool endpoint and method.', -32003 ); }
        $schema = $definition['input_schema'];
        if ( array_diff( array_keys( $args ), array_keys( $schema['properties'] ) ) || array_diff( $schema['required'], array_keys( $args ) ) ) { throw new RemoteException( 'Supply only the documented Google Health inputs, including required fields.' ); }
        foreach ( $args as $key => $value ) {
            $p = $schema['properties'][$key];
            if ( ( 'string' === $p['type'] && ( ! is_string( $value ) || strlen( $value ) > ( $p['maxLength'] ?? 4096 ) ) ) || ( 'integer' === $p['type'] && ( ! is_int( $value ) || $value < ( $p['minimum'] ?? 0 ) || $value > ( $p['maximum'] ?? PHP_INT_MAX ) ) ) || ( isset( $p['enum'] ) && ! in_array( $value, $p['enum'], true ) ) ) { throw new RemoteException( 'A Google Health input has an invalid type or value.' ); }
        }
        return $definition;
    }

    private static function need_scope( array $scopes, string $scope ): void {
        if ( ! in_array( $scope, $scopes, true ) ) { throw new RemoteException( 'MISSING_OAUTH_SCOPE: reconnect and grant ' . $scope . '.', -32003 ); }
    }
    private static function date( string $value ): \DateTimeImmutable {
        $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
        if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) { throw new RemoteException( 'Dates must be valid YYYY-MM-DD values.' ); }
        return $d;
    }
    private static function civil( \DateTimeImmutable $d ): array { return array( 'date' => array( 'year' => (int) $d->format( 'Y' ), 'month' => (int) $d->format( 'n' ), 'day' => (int) $d->format( 'j' ) ) ); }

    private static function read( $tool, array $args, string $token, array $scopes ): array {
        $d = self::definition( $tool, $args );
        $slug = str_replace( 'google_health_', '', $tool->slug );
        $operation = array( 'list_data_points' => 'list', 'reconcile_data_points' => 'reconcile', 'daily_rollup' => 'daily_rollup', 'rollup' => 'rollup', 'nutrition' => 'list' )[$slug] ?? '';
        if ( 'nutrition' === $slug ) { $args['data_type'] = 'nutrition-log'; }
        if ( '' !== $operation ) {
            $type = $args['data_type']; $info = self::inventory()[$type] ?? null;
            if ( ! $info || ! in_array( $operation, $info['operations'], true ) ) { throw new RemoteException( 'Unsupported data type or operation. Consult google_health_data_inventory.' ); }
            self::need_scope( $scopes, $info['scope'] );
            $args['page_size'] = $args['page_size'] ?? ( 'daily_rollup' === $operation ? 7 : 25 );
            if ( in_array( $type, array( 'sleep' ), true ) && $args['page_size'] > 25 ) { throw new RemoteException( 'Sleep page_size must not exceed 25.' ); }
            if ( 'list' === $operation && in_array( $type, array( 'sleep', 'food', 'food-measurement-unit' ), true ) && isset( $args['data_source_family'] ) ) { throw new RemoteException( 'This list operation does not support data_source_family; use reconciliation where available.' ); }
            if ( 'daily_rollup' === $operation ) {
                $start = self::date( $args['start_date'] ); $end = self::date( $args['end_date'] );
                $max = in_array( $type, array( 'total-calories', 'heart-rate' ), true ) ? 14 : 90;
                $days = (int) $start->diff( $end )->format( '%r%a' );
                if ( $days < 1 || $days > $max ) { throw new RemoteException( 'Daily rollup range is reversed, empty or exceeds this data type limit.' ); }
                $args['window_size_days'] = $args['window_size_days'] ?? 1;
                $args['range'] = array( 'start' => self::civil( $start ), 'end' => self::civil( $end ) );
                unset( $args['start_date'], $args['end_date'] );
            }
            if ( 'rollup' === $operation ) {
                foreach ( array( 'start_time', 'end_time' ) as $key ) {
                    self::date( substr( $args[$key], 0, 10 ) );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $args[$key] ) || false === strtotime( $args[$key] ) ) { throw new RemoteException( 'Rollup timestamps require valid RFC3339 values with a timezone.' ); }
                }
                if ( strtotime( $args['end_time'] ) <= strtotime( $args['start_time'] ) || strtotime( $args['end_time'] ) - strtotime( $args['start_time'] ) > 14 * DAY_IN_SECONDS || ! preg_match( '/^[1-9]\d{0,5}s$/D', $args['window_size'] ) ) { throw new RemoteException( 'Rollup requires an increasing range of at most 14 days and a positive duration in seconds.' ); }
                $args['range'] = array( 'startTime' => $args['start_time'], 'endTime' => $args['end_time'] ); unset( $args['start_time'], $args['end_time'] );
            }
        } else {
            self::need_scope( $scopes, self::SCOPE . ( 'get_settings' === $slug ? 'settings' : 'profile' ) . '.readonly' );
        }
        // The persisted native tool is the reviewed definition. Use its native parameter resolver;
        // keep credentials and all health response handling within this non-persistent path.
        $native_data = $d;
        foreach ( array( 'input_schema', 'parameter_mapping', 'headers', 'tags', 'annotations' ) as $field ) { $native_data[$field] = wp_json_encode( $d[$field] ); }
        $native = new Tool( $native_data );
        $mapping = $d['parameter_mapping'];
        if ( isset( $args['range'] ) ) { $mapping['range'] = array( 'target' => 'body', 'key' => 'range' ); }
        $native->parameter_mapping = wp_json_encode( $mapping );
        $schema = $d['input_schema'];
        if ( isset( $args['range'] ) ) { $schema['properties']['range'] = array( 'type' => 'object' ); }
        if ( 'nutrition' === $slug ) { $schema['properties']['data_type'] = array( 'type' => 'string' ); }
        $native->input_schema = wp_json_encode( $schema );
        $request = ( new ParameterResolver() )->resolve( $native, $args );
        if ( 'https' !== wp_parse_url( $request['url'], PHP_URL_SCHEME ) || 'health.googleapis.com' !== wp_parse_url( $request['url'], PHP_URL_HOST ) ) { throw new RemoteException( 'Invalid Google Health endpoint.' ); }
        $headers = array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json' );
        $r = SafeHttp::request( $request['url'], $request['method'], $headers, 'POST' === $request['method'] ? wp_json_encode( $request['body'] ) : null );
        if ( $r['status'] < 200 || $r['status'] >= 300 ) {
            $code = match ( $r['status'] ) { 401 => 'AUTHENTICATION_EXPIRED', 403 => 'PERMISSION_DENIED', 429 => 'RATE_LIMITED', 400, 404 => 'UNSUPPORTED_OR_INVALID_QUERY', default => 'UPSTREAM_UNAVAILABLE' };
            throw new RemoteException( $code . ': Google Health read failed. Check consent, account data and query; no measurements were inferred.' );
        }
        $data = json_decode( $r['body'], true );
        if ( ! is_array( $data ) || isset( $data['error'] ) ) { throw new RemoteException( 'Google Health returned a malformed read response.' ); }
        return self::redact( $data );
    }

    private static function summary( $server, string $token, array $scopes, array $args, bool $weekly ): array {
        try { $tz = new \DateTimeZone( $args['timezone'] ?? wp_timezone_string() ); } catch ( \Throwable $e ) { throw new RemoteException( 'Supply a valid IANA timezone.' ); }
        $date = self::date( $args['date'] ?? ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' ) );
        $start = $weekly ? $date->modify( '-6 days' ) : $date; $end = $date->modify( '+1 day' );
        $tools = ( new ToolManager() )->get_by_server( $server->id, array( 'per_page' => 100 ) )['items']; $by_slug = array();
        foreach ( $tools as $t ) { if ( 'active' === $t->status ) { $by_slug[$t->slug] = $t; } }
        $domains = array();
        foreach ( array( 'steps', 'distance', 'total-calories', 'active-zone-minutes', 'daily-resting-heart-rate', 'daily-heart-rate-variability', 'sleep', 'weight', 'nutrition-log', 'hydration-log' ) as $type ) {
            $reconcile = in_array( $type, array( 'sleep', 'daily-resting-heart-rate', 'daily-heart-rate-variability' ), true );
            $slug = 'google_health_' . ( $reconcile ? 'reconcile_data_points' : 'daily_rollup' );
            $query = array( 'data_type' => $type, 'page_size' => 7 );
            if ( $reconcile ) {
                $field = 'sleep' === $type ? 'sleep.interval.civil_end_time' : str_replace( '-', '_', $type ) . '.date';
                $query['filter'] = $field . ' >= "' . $start->format( 'Y-m-d' ) . '" AND ' . $field . ' < "' . $end->format( 'Y-m-d' ) . '"';
            } else { $query += array( 'start_date' => $start->format( 'Y-m-d' ), 'end_date' => $end->format( 'Y-m-d' ), 'window_size_days' => 1 ); }
            if ( ! isset( $by_slug[$slug] ) ) { $domains[$type] = array( 'status' => 'tool_disabled', 'complete' => false ); continue; }
            $points = array(); $seen = array();
            try {
                for ( $page = 0; $page < 3; $page++ ) {
                    $data = self::read( $by_slug[$slug], $query, $token, $scopes );
                    $key = $reconcile ? 'dataPoints' : 'rollupDataPoints';
                    // Protobuf JSON can omit an empty repeated field; that is an empty result.
                    if ( array_key_exists( $key, $data ) && ( ! is_array( $data[$key] ) || ! array_is_list( $data[$key] ) ) ) { throw new RemoteException( 'MALFORMED_RESPONSE: expected a data point list.' ); }
                    $points = array_merge( $points, $data[$key] ?? array() ); $next = $data['nextPageToken'] ?? '';
                    if ( ! is_string( $next ) || isset( $seen[$next] ) ) { throw new RemoteException( 'PAGINATION_ERROR: invalid or repeated cursor.' ); }
                    if ( '' === $next ) { break; } $seen[$next] = true; $query['page_token'] = $next;
                }
                $domains[$type] = array( 'status' => '' !== $next ? 'partial' : ( $points ? 'observed' : 'no_data' ), 'complete' => '' === $next, 'observations' => $points, 'point_count' => count( $points ) );
            } catch ( RemoteException $e ) { $domains[$type] = array( 'status' => 'unavailable', 'complete' => false, 'reason' => $e->getMessage() ); }
        }
        return array( 'start_date' => $start->format( 'Y-m-d' ), 'end_date_exclusive' => $end->format( 'Y-m-d' ), 'timezone' => $tz->getName(), 'date_basis' => 'Google Health civil dates; timezone selects the default date and does not rebucket upstream observations.', 'complete' => ! in_array( false, array_column( $domains, 'complete' ), true ), 'domains' => $domains, 'interpretation' => 'Observed daily data only. Missing data is unknown, never zero. No medical assessment or inferred measurements.' );
    }

    // @wp-test feature=google.health scenario=health-privacy
    public static function redact( array $data, int $depth = 0 ): array {
        if ( $depth > 32 ) { return array(); }
        $out = array();
        foreach ( $data as $key => $value ) {
            $normal = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $key ) );
            if ( 'nextPageToken' !== $key && preg_match( '/token|secret|password|authorization|cookie|apikey|authkey|email|latitude|longitude|latlng|coordinates|polyline/', $normal ) || in_array( $normal, array( 'name', 'displayname', 'firstname', 'lastname', 'fullname', 'givenname', 'familyname', 'birthdate', 'dateofbirth', 'phonenumber', 'picture', 'avatar', 'avatarurl', 'photo', 'photourl', 'userid', 'accountid', 'address', 'city', 'placeid', 'lat', 'lon', 'lng', 'late7', 'lnge7', 'lone7', 'startlate7', 'startlnge7', 'startlone7', 'endlate7', 'endlnge7', 'endlone7', 'gps', 'gpx', 'location', 'locations', 'geolocation', 'geo', 'geojson', 'route', 'routes', 'position', 'positions', 'waypoint', 'waypoints', 'trackpoint', 'trackpoints', 'placevisit' ), true ) ) { continue; }
            $out[$key] = is_array( $value ) ? self::redact( $value, $depth + 1 ) : $value;
        }
        return $out;
    }
    public static function log_arguments( $tool, $server, array $args ): array { return self::protects( $tool, $server ) ? array() : $args; }
    public static function log_result( $tool, $server, array $result ): array { return self::protects( $tool, $server ) ? array( 'content' => array(), 'isError' => ! empty( $result['isError'] ), 'privacy' => 'Health payload omitted from logs.' ) : $result; }
}
