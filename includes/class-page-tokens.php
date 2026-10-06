<?php
/** Encrypted Page vault keyed by server and exact grant principal, never by a Page alone. */
namespace GetMCPExtensions;
use GetMCP\Remote\RemoteException;
use GetMCP\Utils\Encryption;

final class PageTokens {
    public static function epoch( int $server, string $identity ): string { return hash( 'sha256', (string) get_option( self::key( $server, $identity ), '' ) ); }
    private static function key( int $server, string $identity ): string { return 'getmcp_page_assets_' . hash( 'sha256', $server . '|' . $identity ); }
    private static function fingerprint( $server, string $token ): string { return hash_hmac( 'sha256', ProviderConnections::fingerprint( $server ) . '|' . $token, wp_salt( 'auth' ) ); }
    private static function get( $server, string $identity, string $token ): ?array {
        $enc = get_option( self::key( $server->id, $identity ) );
        if ( ! $enc ) { return null; }
        $data = json_decode( Encryption::decrypt_strict( $enc ), true );
        return hash_equals( self::fingerprint( $server, $token ), (string) ( $data['fingerprint'] ?? '' ) ) ? $data : null;
    }
    private static function save( $server, string $identity, string $token, array $data, $expected ): void {
        global $wpdb; $name = self::key( $server->id, $identity );
        $data['fingerprint'] = self::fingerprint( $server, $token ); $data['server_id'] = $server->id; $data['identity'] = $identity;
        $encrypted = Encryption::encrypt( wp_json_encode( $data ) );
        $ok = false === $expected ? add_option( $name, $encrypted, '', false ) : 1 === $wpdb->update( $wpdb->options, array( 'option_value' => $encrypted ), array( 'option_name' => $name, 'option_value' => $expected ) );
        wp_cache_delete( $name, 'options' );
        if ( ! $ok ) { throw new RemoteException( 'The Page connection changed. Reload and try again.' ); }
    }
    public static function discover( $server, string $identity, string $token ): array {
        $expected = get_option( self::key( $server->id, $identity ), false );
        self::save( $server, $identity, $token, array( 'pages' => array(), 'verified' => false, 'discovery_complete' => false, 'discovered_at' => time() ), $expected );
        $expected = get_option( self::key( $server->id, $identity ), false );
        $base = ProviderTokens::graph_base( $server ); $pages = array(); $after = ''; $seen = array();
        do {
            $r = ProviderTokens::read( $base . '/me/accounts', $token, array_filter( array( 'fields' => 'id,name,access_token,tasks,instagram_business_account{id,username}', 'limit' => '100', 'after' => $after ), 'strlen' ) );
            if ( ! is_array( $r['data'] ?? null ) ) { throw new RemoteException( 'Malformed Page discovery response.' ); }
            foreach ( $r['data'] as $page ) {
                if ( ! preg_match( '/^\d+$/D', (string) ( $page['id'] ?? '' ) ) || ! is_string( $page['access_token'] ?? null ) || empty( $page['instagram_business_account']['id'] ) ) { continue; }
                $pages[(string) $page['id']] = array( 'id' => (string) $page['id'], 'name' => (string) ( $page['name'] ?? '' ), 'instagram_id' => (string) $page['instagram_business_account']['id'], 'instagram_username' => (string) ( $page['instagram_business_account']['username'] ?? '' ), 'token' => $page['access_token'], 'tasks' => $page['tasks'] ?? array() );
            }
            $after = ! empty( $r['paging']['next'] ) ? ( $r['paging']['cursors']['after'] ?? '' ) : '';
            if ( ! is_string( $after ) || ( '' !== $after && isset( $seen[$after] ) ) || count( $seen ) >= 100 || ( ! empty( $r['paging']['next'] ) && '' === $after ) ) { throw new RemoteException( 'Page pagination was invalid or exceeded its limit.' ); }
            $seen[$after] = true;
        } while ( '' !== $after );
        $permissions = ProviderTokens::read( $base . '/me/permissions', $token ); $scopes = array();
        foreach ( $permissions['data'] ?? array() as $p ) { if ( 'granted' === ( $p['status'] ?? '' ) ) { $scopes[] = $p['permission']; } }
        self::save( $server, $identity, $token, array( 'pages' => $pages, 'scopes' => $scopes, 'selected' => null, 'verified' => false, 'discovery_complete' => true, 'discovered_at' => time() ), $expected );
        return self::present( $server, $identity, $token );
    }
    public static function present( $server, string $identity, string $token ): array {
        $d = self::get( $server, $identity, $token ); $items = array();
        foreach ( $d['pages'] ?? array() as $p ) { $items[] = array_diff_key( $p, array( 'token' => true ) ); }
        return array( 'pages' => $items, 'selected_page_id' => $d['selected'] ?? null, 'messaging_verified' => ! empty( $d['verified'] ) && (int) ( $d['verified_at'] ?? 0 ) > time() - 300, 'verified_at' => $d['verified_at'] ?? null, 'scopes' => $d['scopes'] ?? array(), 'discovery_complete' => ! empty( $d['discovery_complete'] ) );
    }
    public static function select( $server, string $identity, string $token, string $page_id, string $instagram_id ): array {
        $expected = get_option( self::key( $server->id, $identity ), false ); $d = self::get( $server, $identity, $token );
        if ( ! $d || ! isset( $d['pages'][$page_id] ) || $instagram_id !== $d['pages'][$page_id]['instagram_id'] ) { throw new RemoteException( 'The Page and Instagram account must belong to this exact connection.', -32003 ); }
        $d['selected'] = $page_id; $d['verified'] = false; unset( $d['conversations'], $d['verified_at'] );
        self::save( $server, $identity, $token, $d, $expected );
        return self::present( $server, $identity, $token );
    }
    public static function verify( $server, string $identity, string $token ): array {
        $expected = get_option( self::key( $server->id, $identity ), false ); $d = self::get( $server, $identity, $token );
        $p = $d['pages'][$d['selected'] ?? ''] ?? null;
        if ( ! $p ) { throw new RemoteException( 'Select a Page before verification.', -32003 ); }
        $d['verified'] = false;
        self::save( $server, $identity, $token, $d, $expected );
        $expected = get_option( self::key( $server->id, $identity ), false );
        $permissions = ProviderTokens::read( ProviderTokens::graph_base( $server ) . '/me/permissions', $token );
        $d['scopes'] = array();
        foreach ( $permissions['data'] ?? array() as $permission ) { if ( 'granted' === ( $permission['status'] ?? '' ) ) { $d['scopes'][] = $permission['permission']; } }
        self::save( $server, $identity, $token, $d, $expected );
        $expected = get_option( self::key( $server->id, $identity ), false );
        $after = ''; $seen = array(); $owned = null;
        do {
            $accounts = ProviderTokens::read( ProviderTokens::graph_base( $server ) . '/me/accounts', $token, array_filter( array( 'fields' => 'id,tasks,instagram_business_account{id}', 'limit' => '100', 'after' => $after ), 'strlen' ) );
            foreach ( $accounts['data'] ?? array() as $account ) { if ( (string) ( $account['id'] ?? '' ) === $p['id'] ) { $owned = $account; break; } }
            if ( $owned ) { break; }
            $after = ! empty( $accounts['paging']['next'] ) ? ( $accounts['paging']['cursors']['after'] ?? '' ) : '';
            if ( ! is_string( $after ) || ( '' !== $after && isset( $seen[$after] ) ) || count( $seen ) >= 100 || ( ! empty( $accounts['paging']['next'] ) && '' === $after ) ) { throw new RemoteException( 'Could not verify current Page ownership.' ); }
            $seen[$after] = true;
        } while ( '' !== $after );
        if ( ! $owned || (string) ( $owned['instagram_business_account']['id'] ?? '' ) !== $p['instagram_id'] || array_diff( array( 'instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata' ), $d['scopes'] ) || ! in_array( 'MESSAGING', $owned['tasks'] ?? array(), true ) ) { throw new RemoteException( 'The selected Page no longer has the linked account, granted permissions or MESSAGING task.', -32003 ); }
        $r = ProviderTokens::read( ProviderTokens::graph_base( $server ) . '/' . $p['id'] . '/conversations', $p['token'], array( 'platform' => 'instagram', 'fields' => 'id', 'limit' => '100' ) );
        if ( ! is_array( $r['data'] ?? null ) ) { throw new RemoteException( 'Malformed conversation verification response.' ); }
        $d['conversations'] = array_column( $r['data'], 'id' ); $d['verified'] = true; $d['verified_at'] = time();
        self::save( $server, $identity, $token, $d, $expected );
        return self::present( $server, $identity, $token );
    }
    public static function token( $server, string $identity, string $token, array $arguments ): string {
        $d = self::get( $server, $identity, $token ); $p = $d['pages'][$d['selected'] ?? ''] ?? null;
        if ( $p && ! empty( $d['verified'] ) && (int) $d['verified_at'] < time() - 300 ) { self::verify( $server, $identity, $token ); $d = self::get( $server, $identity, $token ); }
        if ( ! $p || empty( $d['verified'] ) ) { throw new RemoteException( 'Verify your selected Page with a harmless conversation read on My MCP Connections.', -32001 ); }
        if ( isset( $arguments['page_id'] ) && (string) $arguments['page_id'] !== $p['id'] ) { throw new RemoteException( 'That Page is not selected for your connection.', -32003 ); }
        if ( isset( $arguments['ig_user_id'] ) && (string) $arguments['ig_user_id'] !== $p['instagram_id'] ) { throw new RemoteException( 'That Instagram account does not match the selected Page.', -32003 ); }
        if ( isset( $arguments['conversation_id'] ) && ! in_array( $arguments['conversation_id'], $d['conversations'] ?? array(), true ) ) { throw new RemoteException( 'Discover this conversation on the selected Page first.', -32003 ); }
        return $p['token'];
    }
    public static function record_conversations( $server, string $identity, string $token, string $page, array $ids ): void {
        $expected = get_option( self::key( $server->id, $identity ), false ); $d = self::get( $server, $identity, $token );
        if ( ! $d || $page !== ( $d['selected'] ?? '' ) || empty( $d['verified'] ) ) { return; }
        $d['conversations'] = array_values( array_unique( array_merge( $d['conversations'] ?? array(), array_filter( $ids, 'is_string' ) ) ) );
        if ( count( $d['conversations'] ) > 10000 ) { $d['conversations'] = array_slice( $d['conversations'], -10000 ); }
        self::save( $server, $identity, $token, $d, $expected );
    }
    public static function forget( int $server, string $identity ): void { delete_option( self::key( $server, $identity ) ); }
    public static function cleanup( ?int $server = null, ?int $user = null ): void {
        global $wpdb;
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'getmcp_page_assets_' ) . '%' ) );
        foreach ( $names as $name ) {
            try { $d = json_decode( Encryption::decrypt_strict( get_option( $name ) ), true ); } catch ( \Throwable $e ) { continue; }
            if ( ( null !== $server && (int) $d['server_id'] === $server ) || ( null !== $user && $d['identity'] === 'user:' . $user ) || ( null === $server && null === $user && (int) ( $d['discovered_at'] ?? 0 ) < time() - 86400 ) ) { delete_option( $name ); }
        }
    }
}
