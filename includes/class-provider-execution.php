<?php
/** Token selection at the existing HTTP execution seam, before cache lookup. */
namespace GetMCPExtensions;
use GetMCP\Remote\RemoteException;

final class ProviderExecution {
    public static function context( $server, bool $test = false ): array {
        $live = ( new \GetMCP\Core\ServerManager() )->get( $server->id );
        if ( ! $live || 'active' !== $live->status ) { throw new RemoteException( 'The provider server is unavailable.', -32003 ); }
        $row = \GetMCP\Auth\McpAuth::current_token_row();
        if ( ! $row && $test && get_current_user_id() > 0 ) { $grant = ProviderConnections::grant( $live, get_current_user_id() ); return array( 'identity' => 'user:' . get_current_user_id(), 'token' => $grant['data']['access_token'], 'server' => $live ); }
        if ( ! $row ) { throw new RemoteException( 'Authenticate this connection before using provider tools.', -32001 ); }
        $user = (int) ( $row->user_id ?? 0 );
        if ( $user > 0 ) {
            $grant = ProviderConnections::grant( $live, $user );
            return array( 'identity' => 'user:' . $user, 'token' => $grant['data']['access_token'], 'server' => $live );
        }
        if ( (int) ( $row->server_id ?? 0 ) !== $live->id || empty( $row->upstream_access_token ) ) { throw new RemoteException( 'This grant belongs to another provider server.', -32003 ); }
        $expiry = strtotime( ( $row->upstream_expires_at ?? '' ) . ' UTC' );
        $token = \GetMCP\Utils\Encryption::decrypt_strict( $row->upstream_access_token );
        if ( $expiry < time() + 30 ) {
            $token = \GetMCP\Auth\OAuthProvider::refresh_upstream_token( $row, $live );
            if ( '' === $token ) { throw new RemoteException( 'Provider authentication expired. Reconnect.', -32001 ); }
        }
        return array( 'identity' => 'broker:' . (int) $row->id, 'token' => $token, 'server' => $live );
    }
    public static function inject( array $request, $server, $tool, array $arguments, bool $test ): ?array {
        if ( ! $server ) { return null; }
        $settings = AuthenticationSettings::object( $server->settings );
        $slug = $tool->slug ?: $tool->name;
        $page = 'page' === ( $settings['provider_token_sources'][$slug] ?? '' ) || in_array( $slug, array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true );
        if ( $page && ( ! MarketingModule::enabled() || ! ProviderConnections::supported( $server ) ) ) { throw new RemoteException( 'Page-token tools require an enabled, supported provider connection.', -32003 ); }
        if ( ! ProviderConnections::supported( $server ) || ! MarketingModule::enabled() ) { return null; }
        $row = \GetMCP\Auth\McpAuth::current_token_row();
        // Existing broker User-token and operator/test paths remain unchanged for ordinary tools.
        if ( ! $page && ( $test || (int) ( $row->user_id ?? 0 ) < 1 ) ) { return null; }
        $c = self::context( $server, $test );
        if ( $page ) {
            $asset = PageTokens::present( $c['server'], $c['identity'], $c['token'] );
            $path = (string) wp_parse_url( $request['url'], PHP_URL_PATH );
            $base = (string) wp_parse_url( ProviderTokens::graph_base( $c['server'] ), PHP_URL_PATH );
            $expected = $base . '/' . rawurlencode( $slug === 'get_conversation_messages' ? (string) ( $arguments['conversation_id'] ?? '' ) : (string) ( $asset['selected_page_id'] ?? '' ) );
            if ( 'get_conversations' === $slug ) { $expected .= '/conversations'; } elseif ( 'send_dm' === $slug ) { $expected .= '/messages'; }
            if ( 'graph.facebook.com' !== wp_parse_url( $request['url'], PHP_URL_HOST ) || $path !== $expected || ( 'send_dm' === $slug ? 'POST' : 'GET' ) !== strtoupper( $request['method'] ) ) { throw new RemoteException( 'Page credentials may only be sent to the reviewed selected-Page Graph operation.', -32003 ); }
        }
        $token = $page ? PageTokens::token( $c['server'], $c['identity'], $c['token'], $arguments ) : $c['token'];
        foreach ( array_keys( $request['headers'] ?? array() ) as $key ) { if ( 0 === strcasecmp( $key, 'Authorization' ) ) { unset( $request['headers'][$key] ); } }
        $request['headers']['Authorization'] = 'Bearer ' . $token;
        return $request;
    }
    public static function identity( $server ): string {
        $row = \GetMCP\Auth\McpAuth::current_token_row();
        return hash_hmac( 'sha256', wp_json_encode( array( get_current_user_id(), $server?->id, $server?->auth_credentials, $server?->outbound_auth_credentials, $server?->settings, $row?->id, $row?->resource, $row?->user_id, $row?->upstream_access_token ?? null, $row?->user_id ? \GetMCP\Remote\UpstreamConnections::version( $server->id, (int) $row->user_id ) : null, PageTokens::epoch( (int) ( $server?->id ?? 0 ), (int) ( $row?->user_id ?? 0 ) > 0 ? 'user:' . $row->user_id : 'broker:' . (int) ( $row?->id ?? 0 ) ), $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) ), wp_salt( 'auth' ) );
    }
    public static function retries( $tool ): int {
        if ( 'graph.facebook.com' === wp_parse_url( $tool->endpoint_url, PHP_URL_HOST ) && 'GET' !== strtoupper( $tool->http_method ?? '' ) ) { return 0; }
        if ( ! in_array( wp_parse_url( $tool->endpoint_url, PHP_URL_HOST ), array( 'graph.facebook.com', 'mcp.facebook.com', 'googleads.googleapis.com', 'analyticsadmin.googleapis.com', 'analyticsdata.googleapis.com', 'www.googleapis.com' ), true ) ) { return (int) $tool->retry_count; }
        $annotations = AuthenticationSettings::object( $tool->annotations ?? null );
        return ( $annotations['readOnlyHint'] ?? false ) === true || 'GET' === strtoupper( $tool->http_method ?? '' ) ? (int) $tool->retry_count : 0;
    }
    public static function record( $server, $tool, array $arguments, array $response, bool $test = false ): void {
        if ( ! $server || ! ProviderConnections::supported( $server ) || 'get_conversations' !== $tool->slug || (int) ( $response['status_code'] ?? 0 ) >= 400 ) { return; }
        $data = json_decode( $response['body'] ?? '', true ); if ( ! is_array( $data['data'] ?? null ) ) { return; }
        $c = self::context( $server, $test ); PageTokens::record_conversations( $c['server'], $c['identity'], $c['token'], (string) ( $arguments['page_id'] ?? '' ), array_column( $data['data'], 'id' ) );
    }
}
