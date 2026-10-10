<?php
/** WordPress-personal REST provider grants; existing MCP broker rows are never reinterpreted. */
namespace GetMCPExtensions;
use GetMCP\Core\ServerManager;
use GetMCP\Remote\UpstreamConnections as Store;
use GetMCP\Remote\RemoteException;
use GetMCP\Utils\Encryption;

final class ProviderConnections {
    public static function supported( $server ): bool {
        if ( ! $server || 'native' !== $server->server_kind || 'oauth' !== $server->auth_type || \GetMCP\Auth\FirstPartyOAuth::is_custom( $server ) ) { return false; }
        $c = ProviderTokens::config( $server );
        return ProviderTokens::is_meta( $server ) || ( 'oauth2.googleapis.com' === wp_parse_url( $c['token_url'] ?? '', PHP_URL_HOST ) && 'accounts.google.com' === wp_parse_url( $c['authorize_url'] ?? '', PHP_URL_HOST ) );
    }
    public static function can_connect( $server, int $user ): bool {
        if ( ! MarketingModule::enabled() || ! self::supported( $server ) || $user < 1 || ! get_userdata( $user ) || ! user_can( $user, 'read' ) || 'active' !== $server->status ) { return false; }
        $settings = AuthenticationSettings::object( $server->settings );
        if ( in_array( $user, $settings['personal_provider']['allowed_user_ids'] ?? array(), true ) ) { return true; }
        foreach ( \GetMCP\Gateway\FeatureManager::all() as $gateway ) {
            if ( 'gateway' !== $gateway->server_kind || ! \GetMCP\Auth\FirstPartyOAuth::can_user_access( $user, $gateway ) ) { continue; }
            foreach ( \GetMCP\Gateway\ProjectGateway::members( $gateway ) as $member ) { if ( $server->id === $member->id ) { return true; } }
        }
        return false;
    }
    public static function fingerprint( $server ): string { return hash_hmac( 'sha256', $server->get_endpoint_url() . '|' . $server->auth_config . '|' . $server->auth_credentials, wp_salt( 'auth' ) ); }
    public static function callback( $server ): string { return $server->get_endpoint_url() . '/oauth/callback'; }
    public static function begin( $server, int $user, string $return_to = '' ): array {
        if ( ! self::can_connect( $server, $user ) ) { throw new RemoteException( 'This provider connection is not available to you.', -32003 ); }
        $c = ProviderTokens::config( $server );
        AuthenticationSettings::validate( $c );
        if ( empty( $c['client_id'] ) || empty( $c['authorize_url'] ) || '' === ProviderTokens::secret( $server ) ) { throw new RemoteException( 'The OAuth application is not configured.' ); }
        $state = 'gxp_' . bin2hex( random_bytes( 32 ) ); $verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
        $record = array( 'server_id' => $server->id, 'user_id' => $user, 'expires_at' => time() + 600, 'verifier' => $verifier, 'config_hash' => self::fingerprint( $server ), 'previous_version' => Store::version( $server->id, $user ), 'return_to' => in_array( $return_to, array( 'admin', 'profile', 'portal' ), true ) ? $return_to : '' );
        if ( ! add_option( 'getmcp_provider_state_' . hash( 'sha256', $state ), Encryption::encrypt( wp_json_encode( $record ) ), '', false ) ) { throw new RemoteException( 'Could not start provider consent.' ); }
        $query = array_merge( $c['extra_authorize_params'] ?? array(), array( 'response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => self::callback( $server ), 'state' => $state, 'scope' => $c['scope'] ?? '', 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), 'code_challenge_method' => 'S256' ) );
        return array( 'authorization_url' => add_query_arg( $query, $c['authorize_url'] ) );
    }
    public static function complete( array $query, int $user, ?string $callback_path = null ): string {
        global $wpdb;
        $state = $query['state'] ?? '';
        if ( ! is_string( $state ) || ! preg_match( '/^gxp_[a-f0-9]{64}$/D', $state ) ) { throw new RemoteException( 'Invalid provider consent state.' ); }
        $name = 'getmcp_provider_state_' . hash( 'sha256', $state );
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
        if ( ! $value ) { throw new RemoteException( 'Provider consent expired or was already used.' ); }
        $r = json_decode( Encryption::decrypt_strict( $value ), true );
        if ( $user < 1 || $user !== $r['user_id'] ) { throw new RemoteException( 'Sign in as the WordPress user who started this connection.' ); }
        if ( 1 !== $wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value ) ) ) { throw new RemoteException( 'Provider consent was already used.' ); }
        wp_cache_delete( $name, 'options' );
        $server = ( new ServerManager() )->get( (int) $r['server_id'] );
        if ( ! $server || ! self::can_connect( $server, $user ) || $r['expires_at'] < time() || self::fingerprint( $server ) !== $r['config_hash'] || Store::version( $server->id, $user ) !== $r['previous_version'] ) { throw new RemoteException( 'Access, credentials or connection changed during consent. Start again.' ); }
        if ( null !== $callback_path && wp_parse_url( self::callback( $server ), PHP_URL_PATH ) !== $callback_path ) { throw new RemoteException( 'This callback belongs to another server endpoint.' ); }
        if ( isset( $query['error'] ) || empty( $query['code'] ) || ! is_string( $query['code'] ) ) { throw new RemoteException( 'Provider consent was declined.' ); }
        $c = ProviderTokens::config( $server );
        $tokens = ProviderTokens::request( $c['token_url'], array( 'grant_type' => 'authorization_code', 'code' => $query['code'], 'code_verifier' => $r['verifier'], 'client_id' => $c['client_id'], 'client_secret' => ProviderTokens::secret( $server ), 'redirect_uri' => self::callback( $server ) ) );
        $tokens = ProviderTokens::after_exchange( $server, $tokens );
        $live = ( new ServerManager() )->get( $server->id );
        if ( ! $live || ! self::can_connect( $live, $user ) || self::fingerprint( $live ) !== $r['config_hash'] ) { throw new RemoteException( 'Provider configuration changed during exchange.' ); }
        $data = array_merge( $tokens, array( 'kind' => 'rest-provider', 'config_hash' => $r['config_hash'], 'expires_at' => time() + $tokens['expires_in'], 'scope_status' => isset( $tokens['scope'] ) ? 'reported' : 'not_reported', 'readiness' => array( 'read_verified' => false ) ) );
        Store::save( $server->id, $user, $data, $r['previous_version'] );
        PageTokens::forget( $server->id, 'user:' . $user );
        return Store::portal_url( $r['return_to'] ?? '' );
    }
    public static function refresh_available( $server, array $data ): bool {
        return ! ProviderTokens::is_meta( $server ) && ! empty( $data['refresh_token'] );
    }
    public static function grant( $server, int $user ): array {
        if ( ! self::can_connect( $server, $user ) ) { throw new RemoteException( 'Provider access has been removed.', -32003 ); }
        $grant = Store::get( $server->id, $user );
        if ( ! $grant || 'rest-provider' !== ( $grant['data']['kind'] ?? '' ) || self::fingerprint( $server ) !== ( $grant['data']['config_hash'] ?? '' ) ) { throw new RemoteException( 'Connect or reconnect your provider account on My MCP Connections.', -32001 ); }
        if ( (int) $grant['data']['expires_at'] < time() + 30 ) {
            $lock = 'getmcp_upstream_refresh_' . hash( 'sha256', $server->id . '|' . $user );
            if ( ! add_option( $lock, time() + 60, '', false ) ) { throw new RemoteException( 'A provider refresh is already running. Try again shortly.' ); }
            try {
                $next = ProviderTokens::refresh( $server, $grant['data'] );
                $live = ( new ServerManager() )->get( $server->id );
                if ( ! $live || ! self::can_connect( $live, $user ) || self::fingerprint( $live ) !== $grant['data']['config_hash'] ) { throw new RemoteException( 'Provider access changed during refresh.' ); }
                $grant = array( 'data' => $next, 'version' => Store::save( $server->id, $user, $next, $grant['version'] ) );
            } finally { delete_option( $lock ); }
        }
        return $grant;
    }
    public static function listing( int $user ): array {
        $items = array();
        foreach ( \GetMCP\Gateway\FeatureManager::all() as $server ) {
            if ( ! self::can_connect( $server, $user ) ) { continue; }
            $grant = Store::get( $server->id, $user ); $d = $grant['data'] ?? array();
            $current = ! empty( $d ) && ( $d['config_hash'] ?? '' ) === self::fingerprint( $server );
            $expired = (int) ( $d['expires_at'] ?? 0 ) < time() + 30;
            $refresh_pending = $current && $expired && self::refresh_available( $server, $d );
            $assets = PageTokens::present( $server, 'user:' . $user, $current ? $d['access_token'] : '' );
            $items[] = array( 'id' => $server->id, 'uuid' => $server->uuid, 'can_enable_messaging' => user_can( $user, 'getmcp_manage_servers' ) && user_can( $user, 'getmcp_manage_tools' ), 'name' => $server->name, 'kind' => 'rest-provider', 'connected' => $current, 'expires_at' => $current ? $d['expires_at'] : null, 'scope_status' => $d['scope_status'] ?? 'not_connected', 'scope' => $d['scope'] ?? '', 'readiness' => ProviderReadiness::connection( $server, $d, $current, $assets ), 'refresh_pending' => $refresh_pending, 'reconnect_required' => ! $current || ( $expired && ! $refresh_pending ), 'has_application_credentials' => ! empty( ProviderTokens::config( $server )['client_id'] ) && ! empty( $server->auth_credentials ), 'supports_pages' => ProviderTokens::is_meta( $server ), 'assets' => $assets );
        }
        return $items;
    }
}
