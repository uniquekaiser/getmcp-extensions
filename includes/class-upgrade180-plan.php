<?php
/** Conservative classification for the 1.8.0 upgrade; never returns secrets. */
namespace GetMCPExtensions;

final class Upgrade180Plan {
    /**
     * Classify one stored server. Unknown facts always block conversion.
     *
     * This describes only the narrow standalone sign-in conversion. A blocked
     * record must remain under the existing endpoint guard until a separate
     * compatibility path has been proved.
     *
     * @param array $server Raw stored server row. Never included in the result.
     * @param array $context Independently verified boolean migration facts.
     * @return array{state:string,reasons:string[]}
     */
    public static function classify( array $server, array $context ): array {
        $block = static fn( string $reason ): array => array( 'state' => 'blocked', 'reasons' => array( $reason ) );
        $kind = $server['server_kind'] ?? null;
        if ( 'native' !== $kind ) { return $block( 'legacy_server_kind' ); }
        $auth = self::object( $server['auth_config'] ?? null );
        if ( null === $auth ) { return $block( 'invalid_auth_config' ); }
        if ( 'oauth' !== ( $server['auth_type'] ?? null ) || 'getmcp' !== ( $auth['provider'] ?? null ) ) {
            return array( 'state' => 'not_managed', 'reasons' => array() );
        }
        if ( 'standard' !== ( $server['kind'] ?? null ) ) { return $block( 'native_kind_conflict' ); }
        $settings = self::object( $server['settings'] ?? null );
        if ( null === $settings ) { return $block( 'invalid_settings' ); }
        foreach ( array( 'getmcp_extensions_required', 'personal_provider', 'google_health' ) as $key ) {
            if ( array_key_exists( $key, $settings ) ) { return $block( 'addon_dependency' ); }
        }
        // Until every extension-owned setting and execution hook is ported, only
        // an empty settings object has a proven equivalent in native core.
        if ( array() !== $settings ) { return $block( 'settings_require_review' ); }
        $ids = $auth['allowed_user_ids'] ?? null;
        if ( ! is_array( $ids ) || ! array_is_list( $ids ) || count( $ids ) > 500 ) { return $block( 'invalid_allowlist' ); }
        foreach ( $ids as $id ) {
            if ( ! is_int( $id ) || $id <= 0 ) { return $block( 'invalid_allowlist' ); }
        }
        foreach ( array( 'reviewed_core', 'oauth_enabled', 'eligible_users' ) as $flag ) {
            if ( true !== ( $context[$flag] ?? null ) ) { return $block( $flag ); }
        }
        foreach ( array( 'gateway_member', 'oauth_clients', 'oauth_grants', 'upstream_grants', 'has_content' ) as $flag ) {
            if ( false !== ( $context[$flag] ?? null ) ) { return $block( $flag ); }
        }
        return array( 'state' => 'eligible', 'reasons' => array() );
    }

    private static function object( $json ): ?array {
        if ( ! is_string( $json ) ) { return null; }
        $value = json_decode( $json );
        if ( ! is_object( $value ) || JSON_ERROR_NONE !== json_last_error() ) { return null; }
        return json_decode( $json, true );
    }
}
