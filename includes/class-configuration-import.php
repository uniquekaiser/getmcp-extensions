<?php
/** Preview-first configuration transcoder. It never executes a command or installs a runtime. */
namespace GetMCPExtensions;
use GetMCP\Remote\RemoteException;
use GetMCP\Remote\SafeHttp;
use GetMCP\Utils\Encryption;

final class ConfigurationImport {
    public static function parse( string $text ): array {
        if ( strlen( $text ) > 262144 ) { throw new \InvalidArgumentException( 'Configuration exceeds 256 KiB.' ); }
        $text = trim( $text );
        if ( str_starts_with( $text, 'https://' ) && ! preg_match( '/\s/', $text ) ) { return array( 'remote' => array( 'url' => $text, 'auth' => 'oauth' ) ); }
        if ( str_starts_with( $text, '{' ) ) {
            $json = json_decode( $text, true, 32, JSON_THROW_ON_ERROR );
            if ( ! is_array( $json ) ) { throw new \InvalidArgumentException( 'Configuration must be an object.' ); }
            return $json['mcpServers'] ?? $json['servers'] ?? $json['mcp_servers'] ?? ( isset( $json['url'] ) || isset( $json['command'] ) ? array( 'remote' => $json ) : $json );
        }
        if ( preg_match( '/^(?:claude\s+mcp\s+add|codex\s+mcp\s+add)\s+(.+)$/s', $text, $m ) ) {
            preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"|\x27([^\x27]*)\x27|([^\s]+)/', $m[1], $tokens, PREG_SET_ORDER );
            $parts = array_map( static fn( $v ) => '' !== $v[1] ? stripcslashes( $v[1] ) : ( '' !== ( $v[2] ?? '' ) ? $v[2] : $v[3] ), $tokens );
            $name = null; $url = null; $headers = array();
            for ( $i = 0; $i < count( $parts ); ++$i ) {
                $p = $parts[$i];
                if ( in_array( $p, array( '--transport', '-t', '--scope', '-s' ), true ) ) { ++$i; continue; }
                if ( in_array( $p, array( '--header', '-H' ), true ) ) { $h = explode( ':', $parts[++$i] ?? '', 2 ); if ( count( $h ) !== 2 ) { throw new \InvalidArgumentException( 'Invalid command header.' ); } $headers[trim( $h[0] )] = trim( $h[1] ); continue; }
                if ( str_starts_with( $p, 'https://' ) ) { $url = $p; }
                elseif ( ! $name && ! str_starts_with( $p, '-' ) ) { $name = $p; }
            }
            if ( ! $url ) { return array( $name ?: 'command' => array( 'command' => 'requires-reviewed-port' ) ); }
            return array( $name ?: 'remote' => array( 'url' => $url, 'headers' => $headers ) );
        }
        // Portable subset of Codex TOML and client YAML: maps, scalar strings and inline JSON header maps.
        // Reject advanced syntax rather than guess aliases, interpolation, array tables, or executable values.
        $entries = array(); $name = null; $section = ''; $indent = null;
        foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
            if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) { continue; }
            if ( preg_match( '/^\[mcp_servers\.(?:"([^"]+)"|([\w-]+))(?:\.(http_headers|headers|oauth))?\]$/', trim( $line ), $m ) ) { $name = $m[1] ?: $m[2]; $entries[$name] ??= array(); $section = $m[3] ?? ''; continue; }
            if ( preg_match( '/^\s*(mcpServers|mcp_servers|servers):\s*$/', $line ) ) { continue; }
            if ( preg_match( '/^(\s+)([\w.-]+):\s*$/', $line, $m ) ) {
                $level = strlen( $m[1] );
                if ( null === $indent || $level === $indent ) { $indent = $level; $name = $m[2]; $entries[$name] ??= array(); $section = ''; }
                elseif ( in_array( $m[2], array( 'headers', 'http_headers', 'oauth' ), true ) ) { $section = $m[2]; }
                else { throw new \InvalidArgumentException( 'Unsupported nested configuration field.' ); }
                continue;
            }
            if ( ! $name || ! preg_match( '/^\s*(?:"([^"]+)"|([\w.-]+))\s*[:=]\s*(.*?)\s*$/', $line, $m ) ) { throw new \InvalidArgumentException( 'Unsupported configuration syntax. Use client JSON, basic YAML, Codex TOML or a URL.' ); }
            $key = $m[1] ?: $m[2]; $raw = $m[3];
            if ( preg_match( '/^[&*!>|]/', $raw ) ) { throw new \InvalidArgumentException( 'YAML aliases, tags and multiline values are unsupported.' ); }
            if ( str_starts_with( $raw, '"' ) || str_starts_with( $raw, '{' ) || str_starts_with( $raw, '[' ) ) { $value = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR ); }
            elseif ( str_starts_with( $raw, "'" ) && str_ends_with( $raw, "'" ) ) { $value = substr( $raw, 1, -1 ); }
            else { $value = $raw; }
            if ( $section ) { $entries[$name][$section][$key] = $value; } else { $entries[$name][$key] = $value; }
        }
        if ( ! $entries ) { throw new \InvalidArgumentException( 'No server configuration was found.' ); }
        return $entries;
    }
    public static function map( string $name, array $entry ): array {
        $url = $entry['url'] ?? $entry['endpoint'] ?? $entry['serverUrl'] ?? null;
        if ( ! $url && isset( $entry['command'] ) && preg_match( '~(?:^|[\\\\/])(npx|uvx)(?:\.exe)?$~', (string) $entry['command'] ) && in_array( 'mcp-remote', $entry['args'] ?? array(), true ) ) {
            foreach ( $entry['args'] as $arg ) { if ( is_string( $arg ) && str_starts_with( $arg, 'https://' ) ) { $url = $arg; break; } }
        }
        if ( ! is_string( $url ) ) { return array( 'name' => $name, 'supported' => false, 'reason' => 'Command-only servers require a reviewed native API port; no process will be started.' ); }
        if ( str_contains( wp_json_encode( $entry ), '${' ) || str_contains( wp_json_encode( $entry ), '{{' ) ) { throw new \InvalidArgumentException( 'Resolve environment placeholders locally before importing.' ); }
        SafeHttp::validate_url( $url );
        if ( wp_parse_url( $url, PHP_URL_QUERY ) ) { throw new \InvalidArgumentException( 'Import endpoints without a query string; enter credentials through authentication or custom header fields.' ); }
        $headers = $entry['headers'] ?? $entry['http_headers'] ?? array(); SafeHttp::validate_headers( $headers );
        $oauth = $entry['oauth'] ?? array();
        if ( ! is_array( $oauth ) ) { throw new \InvalidArgumentException( 'OAuth configuration must be an object.' ); }
        $auth = $entry['auth'] ?? $entry['authMethod'] ?? ( $oauth ? 'oauth' : ( $headers ? 'shared' : 'oauth' ) );
        if ( is_array( $auth ) ) {
            $details = $auth; $auth = $details['type'] ?? 'oauth';
            if ( in_array( strtolower( (string) $auth ), array( 'oauth', 'oauth2', 'oauth2.0' ), true ) ) { $oauth = array_replace( $details, $oauth ); }
            elseif ( 'bearer' === strtolower( (string) $auth ) && isset( $details['token'] ) ) { $headers['Authorization'] = 'Bearer ' . $details['token']; }
            else { throw new \InvalidArgumentException( 'Unsupported authentication object. Use the editable authentication fields.' ); }
        }
        $auth = strtolower( (string) $auth ); if ( in_array( $auth, array( 'oauth2', 'oauth2.0' ), true ) ) { $auth = 'oauth'; }
        foreach ( array( 'clientId' => 'client_id', 'clientSecret' => 'client_secret', 'resourceMetadataUrl' => 'resource_metadata_url' ) as $alias => $field ) { if ( isset( $oauth[$alias] ) && ! isset( $oauth[$field] ) ) { $oauth[$field] = $oauth[$alias]; } }
        $scope = $oauth['scope'] ?? $oauth['scopes'] ?? null;
        if ( is_array( $scope ) ) { foreach ( $scope as $value ) { if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'OAuth scopes must be strings.' ); } } $scope = implode( ' ', $scope ); }
        if ( null !== $scope && ! is_string( $scope ) ) { throw new \InvalidArgumentException( 'OAuth scopes must be text or a list.' ); }
        $mode = in_array( $auth, array( 'none', 'oauth' ), true ) ? $auth : 'shared';
        if ( ! empty( $entry['bearer_token'] ) ) { $headers['Authorization'] = 'Bearer ' . $entry['bearer_token']; $mode = 'shared'; }
        $remote = array_filter( array( 'endpoint' => $url, 'auth_mode' => $mode, 'client_id' => $oauth['client_id'] ?? null, 'scope' => $scope, 'resource_metadata_url' => $oauth['resource_metadata_url'] ?? null, 'publish_original' => false ), static fn( $v ) => null !== $v );
        $credentials = array(); $custom = array();
        foreach ( $headers as $key => $value ) { if ( 'authorization' === strtolower( $key ) ) { if ( 'oauth' === $mode ) { throw new \InvalidArgumentException( 'An imported Authorization header cannot override personal OAuth.' ); } $credentials['headers']['Authorization'] = $value; } else { $custom[] = array( 'name' => $key, 'value' => $value ); } }
        if ( ! empty( $oauth['client_secret'] ) ) { $credentials['client_secret'] = $oauth['client_secret']; }
        return array( 'name' => $name, 'supported' => true, 'kind' => 'remote-mcp', 'remote' => $remote, 'credentials' => $credentials, 'connection_headers' => $custom, 'unmapped_fields' => array_merge( array_values( array_diff( array_keys( $entry ), array( 'url', 'endpoint', 'serverUrl', 'headers', 'http_headers', 'oauth', 'auth', 'authMethod', 'bearer_token', 'command', 'args', 'type', 'transport' ) ) ), array_map( static fn( $key ) => 'oauth.' . $key, array_values( array_diff( array_keys( $oauth ), array( 'type', 'client_id', 'clientId', 'client_secret', 'clientSecret', 'scope', 'scopes', 'resource_metadata_url', 'resourceMetadataUrl' ) ) ) ) ) );
    }
    public static function preview( string $text, int $actor ): array {
        if ( ! user_can( $actor, 'getmcp_manage_servers' ) ) { throw new RemoteException( 'You cannot import servers.', -32003 ); }
        $entries = self::parse( $text ); if ( count( $entries ) > 100 ) { throw new \InvalidArgumentException( 'Import up to 100 servers at once.' ); }
        $mapped = array(); $shown = array();
        foreach ( $entries as $name => $entry ) {
            if ( ! is_string( $name ) || in_array( $name, array( '__proto__', 'constructor', 'prototype' ), true ) || ! is_array( $entry ) ) { throw new \InvalidArgumentException( 'Invalid server entry.' ); }
            $row = self::map( $name, $entry ); $mapped[] = $row;
            $public = array_diff_key( $row, array( 'credentials' => true, 'connection_headers' => true ) );
            $public['header_names'] = array_column( $row['connection_headers'] ?? array(), 'name' ); $public['has_credentials'] = ! empty( $row['credentials'] );
            $public['index'] = count( $mapped ) - 1; $shown[] = $public;
        }
        $token = bin2hex( random_bytes( 32 ) );
        add_option( 'getmcp_import_preview_' . hash( 'sha256', $token ), Encryption::encrypt( wp_json_encode( array( 'actor' => $actor, 'expires_at' => time() + 900, 'entries' => $mapped ) ) ), '', false );
        return array( 'preview_token' => $token, 'entries' => $shown );
    }
    public static function commit( array $args, int $actor ): array {
        global $wpdb;
        if ( ! user_can( $actor, 'getmcp_manage_servers' ) ) { throw new RemoteException( 'You cannot import servers.', -32003 ); }
        $token = $args['preview_token'] ?? ''; if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { throw new \InvalidArgumentException( 'Invalid import preview.' ); }
        $name = 'getmcp_import_preview_' . hash( 'sha256', $token ); $enc = get_option( $name );
        if ( ! $enc ) { throw new \InvalidArgumentException( 'The preview expired or was already confirmed.' ); }
        $d = json_decode( Encryption::decrypt_strict( $enc ), true );
        if ( $d['actor'] !== $actor || $d['expires_at'] < time() ) { throw new RemoteException( 'This import belongs to another user or has expired.', -32003 ); }
        $entry = $d['entries'][(int) ( $args['index'] ?? -1 )] ?? null;
        if ( ! $entry || empty( $entry['supported'] ) ) { throw new \InvalidArgumentException( 'Select a supported server from the preview.' ); }
        if ( 1 !== $wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $enc ) ) ) { throw new \InvalidArgumentException( 'The import was already confirmed.' ); }
        wp_cache_delete( $name, 'options' );
        $input = $args['configuration'] ?? array(); unset( $input['id'], $input['kind'] );
        if ( isset( $input['connection_headers'] ) ) {
            $original = array_column( $entry['connection_headers'] ?? array(), 'value', 'name' );
            foreach ( $input['connection_headers'] as &$header ) { if ( '' === ( $header['value'] ?? null ) && isset( $original[$header['name']] ) ) { $header['value'] = $original[$header['name']]; } } unset( $header );
        }
        $entry = array_replace( $entry, $input, array( 'kind' => 'remote-mcp' ) );
        foreach ( \GetMCP\Gateway\FeatureManager::all() as $existing ) { if ( strtolower( $existing->name ) === strtolower( $entry['name'] ) ) { throw new \InvalidArgumentException( 'A server with that name exists. Rename the imported server; existing servers are preserved.' ); } }
        return \GetMCP\Gateway\FeatureManager::run( 'save', $entry, $actor );
    }
}
