<?php
/** Bounded decoding of protocol envelopes; arbitrary business strings stay opaque. */
namespace GetMCPExtensions;
use GetMCP\Remote\RemoteException;

final class ProtocolEnvelope {
    private static function preserve( mixed $node ): mixed {
        if ( $node instanceof \stdClass ) { $vars = get_object_vars( $node ); return $vars ? array_map( array( self::class, 'preserve' ), $vars ) : $node; }
        return is_array( $node ) ? array_map( array( self::class, 'preserve' ), $node ) : $node;
    }
    public static function decode( string $body, int $depth = 0 ): array {
        if ( strlen( $body ) > 10485760 || $depth > 6 ) { throw new RemoteException( 'Provider protocol envelope exceeded its decoding limit.' ); }
        $body = trim( $body );
        if ( str_starts_with( $body, 'data:' ) || str_starts_with( $body, 'event:' ) || str_starts_with( $body, ':' ) ) {
            $messages = array();
            foreach ( preg_split( '/\r?\n\r?\n/', $body ) as $event ) {
                $lines = array(); foreach ( preg_split( '/\r?\n/', $event ) as $line ) { if ( str_starts_with( $line, 'data:' ) ) { $lines[] = ltrim( substr( $line, 5 ), ' ' ); } }
                if ( ! $lines ) { continue; }
                $node = self::preserve( json_decode( implode( "\n", $lines ), false, 64, JSON_THROW_ON_ERROR ) );
                if ( is_array( $node ) && ( isset( $node['result'] ) || isset( $node['error'] ) ) ) { $messages[] = $node; }
            }
            if ( count( $messages ) !== 1 ) { throw new RemoteException( 'Expected one request-scoped provider result.' ); }
            $node = $messages[0];
        } else { $node = self::preserve( json_decode( $body, false, 64, JSON_THROW_ON_ERROR ) ); }
        if ( is_string( $node ) ) { return self::decode( $node, $depth + 1 ); }
        if ( ! is_array( $node ) ) { throw new RemoteException( 'Malformed provider protocol response.' ); }
        if ( isset( $node['jsonrpc'] ) ) {
            if ( '2.0' !== $node['jsonrpc'] || ( array_key_exists( 'error', $node ) === array_key_exists( 'result', $node ) ) ) { throw new RemoteException( 'Malformed JSON-RPC response.' ); }
            if ( isset( $node['error'] ) ) { return array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => 'The upstream MCP operation failed.' ) ) ); }
            if ( ! is_array( $node['result'] ) ) { throw new RemoteException( 'Malformed MCP result.' ); }
            $node = $node['result'];
        }
        if ( isset( $node['isError'] ) && ! is_bool( $node['isError'] ) ) { throw new RemoteException( 'Invalid MCP error flag.' ); }
        foreach ( $node['content'] ?? array() as $index => $block ) {
            $text = trim( $block['text'] ?? '' ); $candidate = str_starts_with( $text, '{' ) ? json_decode( $text, true ) : null;
            if ( 'text' === ( $block['type'] ?? '' ) && is_string( $block['text'] ?? null ) && ( preg_match( '/^(?:data:|event:|:)/', $text ) || isset( $candidate['jsonrpc'] ) ) ) {
                $nested = self::decode( $block['text'], $depth + 1 );
                if ( ! empty( $node['isError'] ) ) { $nested['isError'] = true; }
                if ( isset( $nested['content'] ) ) { $nested['content'] = array_merge( array_slice( $node['content'], 0, $index ), $nested['content'], array_slice( $node['content'], $index + 1 ) ); }
                return $nested + array_diff_key( $node, array_flip( array( 'content', 'isError' ) ) );
            }
        }
        if ( is_array( $node['structuredContent'] ?? null ) && isset( $node['structuredContent']['ad_entities'] ) && is_string( $node['structuredContent']['ad_entities'] ) ) {
            $decoded = self::preserve( json_decode( $node['structuredContent']['ad_entities'], false, 32 ) );
            if ( is_array( $decoded ) ) { $node['structuredContent']['ad_entities'] = $decoded; }
        }
        return $node;
    }
    public static function for_tool( $tool, array $result ): array {
        if ( ! in_array( $tool->slug ?: $tool->name, array( 'list_meta_ads_tools', 'call_meta_ads_tool' ), true ) ) { return $result; }
        try {
            foreach ( $result['content'] ?? array() as $block ) {
                if ( 'text' === ( $block['type'] ?? '' ) ) {
                    $decoded = self::decode( $block['text'] );
                    $accounts = ProviderReadiness::accounts( $decoded['structuredContent'] ?? $decoded );
                    if ( $accounts ) { $decoded['_meta']['getmcp/provider-account-readiness'] = $accounts; }
                    if ( ! empty( $result['isError'] ) ) { $decoded['isError'] = true; }
                    return isset( $decoded['content'] ) ? $decoded : array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $decoded ) ) ), 'structuredContent' => $decoded );
                }
            }
        } catch ( \Throwable $e ) { return array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => 'The provider returned an invalid protocol response.' ) ) ); }
        return $result;
    }
}
