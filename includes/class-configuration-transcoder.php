<?php
/** Portable client format conversion. No execution, discovery or stored credential export. */
namespace GetMCPExtensions;

final class ConfigurationTranscoder {
    private static function quote( string $s ): string { return json_encode( $s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); }
    public static function convert( string $text, string $format ): array {
        if ( ! in_array( $format, array( 'claude', 'vscode', 'codex' ), true ) ) { throw new \InvalidArgumentException( 'Select Claude/Cursor JSON, VS Code JSON or Codex TOML.' ); }
        $entries = ConfigurationImport::parse( $text );
        if ( count( $entries ) > 100 ) { throw new \InvalidArgumentException( 'Convert up to 100 servers.' ); }
        $out = array(); $toml = array(); $warnings = array();
        foreach ( $entries as $name => $e ) {
            if ( ! is_string( $name ) || ! is_array( $e ) || in_array( $name, array( '__proto__', 'constructor', 'prototype' ), true ) ) { throw new \InvalidArgumentException( 'Invalid server entry.' ); }
            $url = $e['url'] ?? $e['httpUrl'] ?? $e['endpoint'] ?? $e['serverUrl'] ?? null;
            $row = array();
            if ( is_string( $url ) ) {
                // Reject embedded credentials rather than emit them into a portable file.
                if ( ! preg_match( '~^https?://~i', $url ) || wp_parse_url( $url, PHP_URL_USER ) || wp_parse_url( $url, PHP_URL_PASS ) || wp_parse_url( $url, PHP_URL_QUERY ) || wp_parse_url( $url, PHP_URL_FRAGMENT ) || preg_match( '/[\x00-\x20]/', $url ) ) { throw new \InvalidArgumentException( 'Use a credential-free HTTP endpoint without query parameters.' ); }
                $row['url'] = $url;
                if ( 'vscode' === $format ) { $row['type'] = 'http'; }
            } elseif ( is_string( $e['command'] ?? null ) ) {
                $row['command'] = $e['command'];
                if ( isset( $e['args'] ) ) { $row['args'] = $e['args']; }
                $warnings[] = $name . ': command configuration is text only; GetMCP will not run it.';
                if ( 'vscode' === $format ) { $row['type'] = 'stdio'; }
                // Arguments can contain passwords or tokens. Never guess which ones are safe.
                if ( ! empty( $row['args'] ) ) { unset( $row['args'] ); $warnings[] = $name . ': command arguments omitted; review and enter them locally.'; }
            } else { throw new \InvalidArgumentException( 'Each server needs a URL or a command.' ); }
            $headers = $e['headers'] ?? $e['http_headers'] ?? array();
            if ( ! is_array( $headers ) ) { throw new \InvalidArgumentException( 'Headers must be a map.' ); }
            if ( $headers ) { $row['codex' === $format ? 'http_headers' : 'headers'] = array_fill_keys( array_keys( $headers ), 'REPLACE_LOCALLY' ); $warnings[] = $name . ': header values redacted.'; }
            if ( isset( $e['env'] ) ) {
                if ( ! is_array( $e['env'] ) ) { throw new \InvalidArgumentException( 'Environment must be a map.' ); }
                $row['env'] = array_fill_keys( array_keys( $e['env'] ), 'REPLACE_LOCALLY' ); $warnings[] = $name . ': environment values redacted.';
            }
            if ( isset( $e['bearer_token_env_var'] ) && is_string( $e['bearer_token_env_var'] ) ) {
                if ( 'codex' === $format ) { $row['bearer_token_env_var'] = $e['bearer_token_env_var']; }
                else { $warnings[] = $name . ': bearer-token environment setting is Codex-specific; configure authentication locally.'; }
            }
            if ( isset( $e['env_http_headers'] ) ) {
                if ( ! is_array( $e['env_http_headers'] ) || array_filter( $e['env_http_headers'], static fn( $v ) => ! is_string( $v ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $v ) ) ) { throw new \InvalidArgumentException( 'Environment header values must be variable names.' ); }
                if ( 'codex' === $format ) { $row['env_http_headers'] = $e['env_http_headers']; }
                else { $warnings[] = $name . ': environment header mapping is Codex-specific; configure it locally.'; }
            }
            $unknown = array_diff( array_keys( $e ), array( 'url', 'httpUrl', 'endpoint', 'serverUrl', 'type', 'transport', 'command', 'args', 'headers', 'http_headers', 'env', 'env_http_headers', 'bearer_token_env_var' ) );
            if ( $unknown ) { $warnings[] = $name . ': review omitted settings: ' . implode( ', ', $unknown ) . '.'; }
            $out[$name] = $row;
            if ( 'codex' === $format ) {
                $prefix = 'mcp_servers.' . self::quote( $name ); $toml[] = '[' . $prefix . ']';
                foreach ( $row as $key => $v ) {
                    if ( is_array( $v ) ) { continue; }
                    if ( 'type' !== $key ) { $toml[] = $key . ' = ' . self::quote( $v ); }
                }
                foreach ( array( 'http_headers', 'env_http_headers', 'env' ) as $section ) {
                    if ( empty( $row[$section] ) ) { continue; }
                    $toml[] = '[' . $prefix . '.' . $section . ']';
                    foreach ( $row[$section] as $key => $v ) { $toml[] = self::quote( $key ) . ' = ' . self::quote( $v ); }
                }
                $toml[] = '';
            }
        }
        return array( 'format' => $format, 'configuration' => 'codex' === $format ? implode( "\n", $toml ) : wp_json_encode( array( 'vscode' === $format ? 'servers' : 'mcpServers' => $out ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), 'warnings' => $warnings, 'secrets_redacted' => true );
    }
}
