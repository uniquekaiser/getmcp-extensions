<?php
/** Custom headers for every Remote MCP authentication mode, encrypted at rest. */
namespace GetMCPExtensions;
use GetMCP\Remote\SafeHttp;
use GetMCP\Utils\Encryption;

final class ConnectionHeaders {
    public static function read( $server ): array {
        if ( empty( $server->outbound_auth_credentials ) ) { return array(); }
        $plain = Encryption::decrypt_strict( $server->outbound_auth_credentials );
        // Older connections may carry an opaque encrypted credential without our optional header object.
        if ( ! str_starts_with( ltrim( $plain ), '{' ) ) { return array(); }
        $d = AuthenticationSettings::object( $plain );
        return $d['custom_headers'] ?? array();
    }
    public static function names( $server ): array { return array_keys( self::read( $server ) ); }
    public static function prepare( $server, array $data, array $args ): array {
        if ( 'remote-mcp' !== ( $server->server_kind ?? $data['server_kind'] ?? '' ) ) { return $data; }
        $stored = $server && ! empty( $server->outbound_auth_credentials ) ? AuthenticationSettings::object( Encryption::decrypt_strict( $server->outbound_auth_credentials ) ) : array();
        $original = $stored;
        $prior = $server ? \GetMCP\Remote\UpstreamConnections::config( $server ) : array();
        $remote = AuthenticationSettings::object( $data['settings'] )['remote_mcp'];
        if ( ( $remote['auth_mode'] ?? '' ) !== ( $prior['auth_mode'] ?? '' ) ) { unset( $stored['headers'], $stored['client_secret'] ); }
        if ( isset( $args['credentials'] ) ) { $stored = array_replace( $stored, $args['credentials'] ); }
        if ( isset( $args['connection_headers'] ) ) {
            if ( ! is_array( $args['connection_headers'] ) || count( $args['connection_headers'] ) > 40 ) { throw new \InvalidArgumentException( 'Supply up to 40 connection headers.' ); }
            $old = $stored['custom_headers'] ?? array(); $next = array();
            foreach ( $args['connection_headers'] as $header ) {
                if ( ! is_array( $header ) || ! is_string( $header['name'] ?? null ) || ! is_string( $header['value'] ?? null ) ) { throw new \InvalidArgumentException( 'Each header needs a name and value.' ); }
                $name = $header['name']; $value = $header['value'];
                if ( '' === $value ) { $value = $old[$name] ?? ''; }
                $next[$name] = $value;
            }
            SafeHttp::validate_headers( $next );
            $seen = array();
            foreach ( $next as $name => $value ) {
                $lower = strtolower( $name );
                if ( isset( $seen[$lower] ) || in_array( $lower, array( 'authorization', 'content-type', 'accept', 'mcp-session-id', 'mcp-protocol-version', 'mcp-method', 'origin' ), true ) ) { throw new \InvalidArgumentException( 'Authentication and MCP protocol headers are managed separately; duplicate headers are invalid.' ); }
                $seen[$lower] = true;
            }
            $stored['custom_headers'] = $next;
        }
        if ( isset( $args['clear_credentials'] ) ) {
            if ( true !== $args['clear_credentials'] ) { throw new \InvalidArgumentException( 'clear_credentials must be true.' ); }
            $stored = array();
        }
        if ( $server && $stored === $original ) { unset( $data['outbound_auth_credentials'] ); }
        elseif ( $stored || isset( $args['connection_headers'] ) || isset( $args['clear_credentials'] ) || ( $remote['auth_mode'] ?? '' ) !== ( $prior['auth_mode'] ?? '' ) ) { $data['outbound_auth_credentials'] = $stored ? wp_json_encode( $stored ) : null; $data['_clear_credentials'] = $stored ? array() : array( 'outbound_auth_credentials' ); }
        return $data;
    }
}
