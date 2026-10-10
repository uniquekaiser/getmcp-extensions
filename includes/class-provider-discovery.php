<?php
/** Paginated harmless discovery, with reporting coverage kept separate. */
namespace GetMCPExtensions;
use GetMCP\Remote\RemoteException;

final class ProviderDiscovery {
    public static function presets(): array {
        $google = array( 'provider' => 'external', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'extra_authorize_params' => array( 'access_type' => 'offline', 'prompt' => 'consent' ) );
        return array(
            'google-ads' => array( 'label' => 'Google Ads', 'auth_config' => $google + array( 'scope' => 'https://www.googleapis.com/auth/adwords' ), 'requirements' => 'OAuth application and owning Cloud project Ads access; no developer-token header.' ),
            'google-analytics' => array( 'label' => 'Google Analytics', 'auth_config' => $google + array( 'scope' => 'https://www.googleapis.com/auth/analytics.readonly' ), 'requirements' => 'Analytics Admin and Data APIs, plus property access.' ),
            'google-search-console' => array( 'label' => 'Google Search Console', 'auth_config' => $google + array( 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly' ), 'requirements' => 'Use webmasters only for explicitly enabled property/sitemap writes.' ),
            'instagram-facebook-login' => array( 'label' => 'Instagram with Facebook Login', 'requirements' => 'Configure a currently supported Graph version and your Facebook Login application. Select a linked professional account; verify messaging separately.' ),
            'meta-ads' => array( 'label' => 'Meta Ads', 'requirements' => 'Retain the working REST bridge. A separate Remote MCP connection can expose the live catalog through discovery when provider OAuth is supported; explicitly choose which catalog to publish.' ),
        );
    }
    private static function pages( string $url, string $token, string $field, array $query = array() ): array {
        $items = array(); $seen = array(); $next = '';
        do {
            $r = ProviderTokens::read( $url, $token, $query + ( '' !== $next ? array( 'pageToken' => $next ) : array() ) );
            if ( ! is_array( $r[$field] ?? null ) ) { throw new RemoteException( 'Malformed provider discovery list.' ); }
            $items = array_merge( $items, $r[$field] ); $next = $r['nextPageToken'] ?? '';
            if ( ! is_string( $next ) || ( '' !== $next && isset( $seen[$next] ) ) || count( $seen ) >= 100 ) { throw new RemoteException( 'Provider discovery pagination exceeded its limit or repeated a cursor.' ); }
            $seen[$next] = true;
        } while ( '' !== $next );
        return $items;
    }
    public static function discover( $server, string $token, int $user ): array {
        $settings = AuthenticationSettings::object( $server->settings ); $preset = $settings['personal_provider']['preset'] ?? '';
        $scope = ProviderTokens::config( $server )['scope'] ?? '';
        if ( ! $preset ) { $preset = str_contains( $scope, '/adwords' ) ? 'google-ads' : ( str_contains( $scope, '/analytics' ) ? 'google-analytics' : ( str_contains( $scope, '/webmasters' ) ? 'google-search-console' : ( ProviderTokens::is_meta( $server ) ? 'instagram-facebook-login' : '' ) ) ); }
        if ( 'instagram-facebook-login' === $preset ) {
            return array( 'assets' => PageTokens::discover( $server, 'user:' . $user, $token ), 'complete' => true, 'reporting_verified' => false );
        }
        if ( 'google-analytics' === $preset ) {
            $items = self::pages( 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries', $token, 'accountSummaries', array( 'pageSize' => '200' ) );
            return array( 'accounts' => $items, 'complete' => true, 'reporting_verified' => false );
        }
        if ( 'google-search-console' === $preset ) {
            $r = ProviderTokens::read( 'https://www.googleapis.com/webmasters/v3/sites', $token );
            return array( 'properties' => $r['siteEntry'] ?? array(), 'complete' => true, 'reporting_verified' => false );
        }
        if ( 'google-ads' === $preset ) { return self::ads( $server, $token ); }
        throw new RemoteException( 'Use this provider’s verified Remote MCP or REST discovery tool. Asset discovery is not a reporting acceptance check.' );
    }
    private static function ads( $server, string $token ): array {
        $result = ( new \GetMCP\Core\ToolManager() )->get_by_server( $server->id, array( 'per_page' => 100 ) ); $base = '';
        foreach ( $result['items'] as $tool ) {
            if ( preg_match( '#^https://googleads\.googleapis\.com/(v\d+)/customers:listAccessibleCustomers$#D', $tool->endpoint_url, $m ) ) { $base = 'https://googleads.googleapis.com/' . $m[1]; break; }
        }
        if ( ! $base ) { throw new RemoteException( 'Configure the Google Ads list-accessible-customers tool with your supported API version first.' ); }
        $r = ProviderTokens::read( $base . '/customers:listAccessibleCustomers', $token );
        if ( ! is_array( $r['resourceNames'] ?? null ) ) { throw new RemoteException( 'Malformed accessible-customer response.' ); }
        $queue = array(); foreach ( $r['resourceNames'] as $resource ) { if ( preg_match( '#^customers/(\d+)$#D', $resource, $m ) ) { $queue[] = array( 'id' => $m[1], 'manager' => '' ); } }
        $seen = array(); $customers = array(); $unavailable = array();
        while ( $queue ) {
            $item = array_shift( $queue ); $key = $item['id'] . '|' . $item['manager']; if ( isset( $seen[$key] ) ) { continue; } $seen[$key] = true;
            if ( count( $seen ) > 1000 ) { throw new RemoteException( 'Manager traversal exceeded its bound; discovery is incomplete.' ); }
            $headers = array( 'Authorization' => 'Bearer ' . $token ); if ( '' !== $item['manager'] ) { $headers['login-customer-id'] = $item['manager']; }
            $next = ''; $cursors = array();
            do {
                $body = array( 'query' => 'SELECT customer_client.client_customer, customer_client.descriptive_name, customer_client.manager, customer_client.level, customer_client.status FROM customer_client WHERE customer_client.level <= 1' ); if ( '' !== $next ) { $body['pageToken'] = $next; }
                $response = \GetMCP\Remote\SafeHttp::request( $base . '/customers/' . $item['id'] . '/googleAds:search', 'POST', $headers + array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), wp_json_encode( $body ) );
                $data = json_decode( $response['body'], true );
                $disabled = false;
                if ( 403 === $response['status'] ) {
                    foreach ( $data['error']['details'] ?? array() as $detail ) {
                        foreach ( $detail['errors'] ?? array() as $error ) { if ( 'CUSTOMER_NOT_ENABLED' === ( $error['errorCode']['authorizationError'] ?? '' ) ) { $disabled = true; } }
                    }
                }
                // A disabled customer is an account restriction, not a failed OAuth connection.
                // Keep other account results, while making the incomplete hierarchy explicit.
                if ( $disabled ) { $unavailable[$key] = array( 'id' => $item['id'], 'login_customer_id' => $item['manager'], 'reason' => 'CUSTOMER_NOT_ENABLED', 'reporting_verified' => false ); break; }
                if ( $response['status'] < 200 || $response['status'] >= 300 || ! is_array( $data ) || isset( $data['error'] ) || ! is_array( $data['results'] ?? null ) ) { throw new RemoteException( 'Google Ads manager discovery failed. No reporting access was assumed.' ); }
                foreach ( $data['results'] as $row ) {
                    $c = $row['customerClient'] ?? array(); $resource = $c['clientCustomer'] ?? '';
                    if ( ! preg_match( '#^customers/(\d+)$#D', $resource, $m ) ) { continue; }
                    $customers[$m[1]] = $c + array( 'reporting_verified' => false, 'login_customer_id' => $item['manager'] ?: $item['id'] );
                    if ( ! empty( $c['manager'] ) && (int) ( $c['level'] ?? 0 ) > 0 ) { $queue[] = array( 'id' => $m[1], 'manager' => $item['manager'] ?: $item['id'] ); }
                }
                $next = $data['nextPageToken'] ?? '';
                if ( ! is_string( $next ) || ( '' !== $next && isset( $cursors[$next] ) ) || count( $cursors ) >= 100 ) { throw new RemoteException( 'Invalid Ads discovery cursor.' ); } $cursors[$next] = true;
            } while ( '' !== $next );
        }
        return array( 'customers' => array_values( $customers ), 'directly_accessible' => $r['resourceNames'], 'unavailable_customers' => array_values( $unavailable ), 'complete' => empty( $unavailable ), 'reporting_verified' => false );
    }
}
