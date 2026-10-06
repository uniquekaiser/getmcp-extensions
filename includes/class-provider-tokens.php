<?php
/** Provider token strategies. Facebook token extension is distinct from OAuth refresh. */
namespace GetMCPExtensions;
use GetMCP\Remote\SafeHttp;
use GetMCP\Remote\RemoteException;
use GetMCP\Utils\Encryption;

final class ProviderTokens {
    public static function config( $server ): array { return AuthenticationSettings::object( $server->auth_config ); }
    public static function is_meta( $server ): bool {
        $url = self::config( $server )['token_url'] ?? '';
        return 'graph.facebook.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) && preg_match( '#^/v\d+\.\d+/oauth/access_token$#D', (string) wp_parse_url( $url, PHP_URL_PATH ) );
    }
    public static function secret( $server ): string {
        if ( empty( $server->auth_credentials ) ) { return ''; }
        return (string) ( AuthenticationSettings::object( Encryption::decrypt_strict( $server->auth_credentials ) )['client_secret'] ?? '' );
    }
    public static function request( string $url, array $params ): array {
        $r = SafeHttp::request( $url, 'POST', array( 'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json' ), http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
        $data = json_decode( $r['body'], true );
        if ( $r['status'] < 200 || $r['status'] >= 300 || ! is_array( $data ) || isset( $data['error'] ) || ! is_string( $data['access_token'] ?? null ) || '' === $data['access_token'] || preg_match( '/[\r\n]/', $data['access_token'] ) ) { throw new RemoteException( 'Provider authentication failed. Reconnect your account; no shared identity was substituted.', -32001 ); }
        if ( isset( $data['token_type'] ) && 'bearer' !== strtolower( $data['token_type'] ) ) { throw new RemoteException( 'Unsupported provider token type.' ); }
        $expiry = (int) ( $data['expires_in'] ?? $data['expires'] ?? 0 );
        if ( $expiry <= 0 ) { throw new RemoteException( 'The provider did not supply a usable token lifetime. Reconnect.' ); }
        $data['expires_in'] = min( $expiry, 7776000 );
        return $data;
    }
    public static function after_exchange( $server, array $tokens ): array {
        if ( ! self::is_meta( $server ) ) { return $tokens; }
        $c = self::config( $server ); $secret = self::secret( $server );
        if ( empty( $c['client_id'] ) || '' === $secret ) { throw new RemoteException( 'Configure the Facebook OAuth application before connecting.' ); }
        $extended = array_merge( $tokens, self::request( $c['token_url'], array( 'grant_type' => 'fb_exchange_token', 'client_id' => $c['client_id'], 'client_secret' => $secret, 'fb_exchange_token' => $tokens['access_token'] ?? '' ) ) );
        unset( $extended['refresh_token'] );
        return $extended;
    }
    public static function refresh( $server, array $data ): array {
        // Facebook Login reauthentication obtains a new short-lived token which is then extended once.
        // An expired long-lived token is never sent to the generic refresh_token grant.
        if ( self::is_meta( $server ) || empty( $data['refresh_token'] ) ) { throw new RemoteException( 'This provider requires reconnection. Open My MCP Connections.', -32001 ); }
        $c = self::config( $server );
        $next = self::request( $c['token_url'], array( 'grant_type' => 'refresh_token', 'refresh_token' => $data['refresh_token'], 'client_id' => $c['client_id'], 'client_secret' => self::secret( $server ) ) );
        return array_merge( $data, $next, array( 'expires_at' => time() + $next['expires_in'] ) );
    }
    public static function graph_base( $server ): string {
        if ( ! self::is_meta( $server ) ) { throw new RemoteException( 'This connection uses a different login variant.' ); }
        return dirname( dirname( self::config( $server )['token_url'] ) );
    }
    public static function read( string $url, string $token, array $query = array() ): array {
        $r = SafeHttp::request( add_query_arg( $query, $url ), 'GET', array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) );
        $data = json_decode( $r['body'], true );
        if ( $r['status'] < 200 || $r['status'] >= 300 || ! is_array( $data ) || isset( $data['error'] ) ) { throw new RemoteException( 'Provider read failed. Check permissions, eligibility and connection expiry.', -32001 ); }
        return $data;
    }
}
