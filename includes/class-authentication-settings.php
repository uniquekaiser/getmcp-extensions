<?php
/** Explicit partial authentication updates; no saved secret is returned to an editor. */
namespace GetMCPExtensions;

final class AuthenticationSettings {
    public const CREDENTIALS = array( 'auth_credentials', 'test_auth_credentials', 'outbound_auth_credentials' );

    public static function object( mixed $value ): array {
        if ( '' === $value ) { return array(); }
        if ( is_string( $value ) ) { $value = json_decode( $value, true, 32, JSON_THROW_ON_ERROR ); }
        if ( null === $value ) { return array(); }
        if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) ) { throw new \InvalidArgumentException( 'Configuration must be an object.' ); }
        return $value;
    }

    public static function revision( $server ): string {
        $parts = array( $server->id, $server->auth_type, $server->auth_config, $server->outbound_auth_type, $server->outbound_auth_config );
        foreach ( self::CREDENTIALS as $field ) { $parts[] = $server->$field; }
        return hash_hmac( 'sha256', wp_json_encode( $parts ), wp_salt( 'auth' ) );
    }
    private static function merge( array $old, array $new ): array {
        foreach ( $new as $key => $value ) {
            if ( null === $value ) { unset( $old[$key] ); }
            elseif ( is_array( $value ) && $value && ! array_is_list( $value ) && is_array( $old[$key] ?? null ) ) { $old[$key] = self::merge( $old[$key], $value ); }
            else { $old[$key] = $value; }
        }
        return $old;
    }

    public static function prepare( $server, array $data ): array {
        if ( isset( $data['_authentication_revision'] ) && ! hash_equals( self::revision( $server ), (string) $data['_authentication_revision'] ) ) {
            throw new SettingsConflict( 'Authentication changed. Reload before saving.' );
        }
        $replace = ( $data['_auth_config_mode'] ?? 'merge' ) === 'replace';
        if ( ! in_array( $data['_auth_config_mode'] ?? 'merge', array( 'merge', 'replace' ), true ) ) { throw new \InvalidArgumentException( 'Invalid configuration update mode.' ); }
        $clear = $data['_clear_credentials'] ?? array();
        if ( ! is_array( $clear ) || array_diff( $clear, self::CREDENTIALS ) ) { throw new \InvalidArgumentException( 'Invalid credential clear list.' ); }
        foreach ( array( 'auth', 'outbound_auth' ) as $prefix ) {
            $field = $prefix . '_config'; $old = self::object( $server->$field );
            $new = array_key_exists( $field, $data ) ? self::object( $data[$field] ) : array();
            $mode_changed = isset( $data[$prefix . '_type'] ) && $data[$prefix . '_type'] !== $server->{$prefix . '_type'};
            $provider_changed = ( isset( $new['provider'] ) && $new['provider'] !== ( $old['provider'] ?? 'external' ) ) || ( isset( $new['client_id'] ) && $new['client_id'] !== ( $old['client_id'] ?? '' ) );
            foreach ( array( 'token_url', 'authorize_url' ) as $endpoint ) { if ( isset( $new[$endpoint], $old[$endpoint] ) && strtolower( (string) wp_parse_url( $new[$endpoint], PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $old[$endpoint], PHP_URL_HOST ) ) ) { $provider_changed = true; } }
            if ( array_key_exists( $field, $data ) || $mode_changed ) {
                $config = $replace || $mode_changed || $provider_changed ? $new : self::merge( $old, $new );
                if ( 'none' === ( $data[$prefix . '_type'] ?? $server->{$prefix . '_type'} ) ) { $config = array(); }
                self::validate( $config );
                $data[$field] = wp_json_encode( $config );
            }
            $credentials = $prefix . '_credentials';
            if ( $mode_changed || $provider_changed ) {
                if ( empty( $data[$credentials] ) ) { $data[$credentials] = null; }
                if ( 'auth' === $prefix ) { $data['test_auth_credentials'] = null; }
            } elseif ( array_key_exists( $credentials, $data ) ) {
                $value = $data[$credentials];
                // The ordinary editor submits an empty masked field. Clearing is a separate intent.
                if ( null === $value || '' === $value ) { unset( $data[$credentials] ); }
                else {
                    $values = self::object( $value );
                    $values = array_filter( $values, static fn( $v ) => null !== $v && '' !== $v );
                    if ( ! $values ) { unset( $data[$credentials] ); }
                    else {
                        $stored = empty( $server->$credentials ) ? array() : self::object( \GetMCP\Utils\Encryption::decrypt_strict( $server->$credentials ) );
                        $data[$credentials] = wp_json_encode( array_replace( $stored, $values ) );
                    }
                }
            }
        }
        if ( array_key_exists( 'test_auth_credentials', $data ) && ! in_array( 'test_auth_credentials', $clear, true ) && empty( $data['test_auth_credentials'] ) && ( $data['auth_type'] ?? $server->auth_type ) === $server->auth_type ) { unset( $data['test_auth_credentials'] ); }
        foreach ( $clear as $field ) { $data[$field] = null; }
        return $data;
    }

    public static function validate( array $config ): void {
        $walk = static function( array $node ) use ( &$walk ) {
            foreach ( $node as $key => $value ) {
                if ( preg_match( '/secret|access_token|refresh_token|password|authorization|api_key/i', (string) $key ) ) { throw new \InvalidArgumentException( 'Secrets belong in encrypted credentials, not authentication configuration.' ); }
                if ( is_array( $value ) ) { $walk( $value ); }
            }
        };
        $walk( $config );
        foreach ( array( 'authorize_url', 'token_url' ) as $key ) { if ( ! empty( $config[$key] ) ) { \GetMCP\Remote\SafeHttp::validate_url( $config[$key] ); } }
        if ( isset( $config['extra_authorize_params'] ) ) {
            $extra = self::object( $config['extra_authorize_params'] );
            foreach ( $extra as $key => $value ) {
                if ( in_array( $key, array( 'state', 'redirect_uri', 'client_id', 'response_type', 'code_challenge', 'code_challenge_method', 'resource' ), true ) || ! is_scalar( $value ) || strlen( (string) $value ) > 2048 ) { throw new \InvalidArgumentException( 'Invalid extra authorization parameter.' ); }
            }
        }
    }

    public static function public_credentials( $server ): array {
        $result = array( 'auth_type' => $server->auth_type, 'auth_config' => self::public_config( $server->auth_config ), 'outbound_auth_type' => $server->outbound_auth_type, 'outbound_auth_config' => self::public_config( $server->outbound_auth_config ), 'test_auth_type' => $server->test_auth_type, 'authentication_revision' => self::revision( $server ) );
        foreach ( self::CREDENTIALS as $field ) { $result[$field] = null; $result['has_' . $field] = ! empty( $server->$field ); }
        return $result;
    }
    public static function public_config( mixed $value ): array {
        try { $config = self::object( $value ); } catch ( \Throwable $e ) { return array(); }
        $walk = static function( array $node ) use ( &$walk ): array {
            foreach ( $node as $key => $item ) {
                if ( preg_match( '/secret|access_token|refresh_token|password|authorization|api_key/i', (string) $key ) ) { unset( $node[$key] ); }
                elseif ( is_array( $item ) ) { $node[$key] = $walk( $item ); }
            }
            return $node;
        };
        return $walk( $config );
    }
}

final class SettingsConflict extends \RuntimeException {}
