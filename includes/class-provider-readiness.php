<?php
/** Per-identity evidence, with unknown permissions and provider eligibility kept explicit. */
namespace GetMCPExtensions;
final class ProviderReadiness {
    public static function connection( $server, array $data, bool $current, array $assets ): array {
        $config = ProviderTokens::config( $server );
        $required = preg_split( '/[\s,]+/', trim( (string) ( $config['scope'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
        $granted = ProviderTokens::is_meta( $server ) ? ( $assets['scopes'] ?? array() ) : preg_split( '/\s+/', trim( (string) ( $data['scope'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
        $known = ProviderTokens::is_meta( $server ) ? ! empty( $assets['discovery_complete'] ) : isset( $data['scope'] );
        return array(
            'application_configured' => ! empty( $config['client_id'] ) && ! empty( $server->auth_credentials ),
            'user_connected' => $current && ( (int) ( $data['expires_at'] ?? 0 ) > time() + 30 || ProviderConnections::refresh_available( $server, $data ) ),
            'required_scopes' => $required,
            'required_scopes_present' => $current && $known ? ! array_diff( $required, $granted ) : null,
            'read_verified' => $current && ( ! empty( $data['readiness']['read_verified'] ) || ! empty( $assets['messaging_verified'] ) ),
            'checked_at' => $current ? ( $data['readiness']['checked_at'] ?? null ) : null,
            'discovery_complete' => $current && ! empty( $data['readiness']['discovery_complete'] ),
            'reporting_verified' => false,
        );
    }
    public static function accounts( mixed $node, int $depth = 0 ): array {
        if ( $depth > 12 || ! is_array( $node ) ) { return array(); }
        $items = array();
        if ( array_key_exists( 'is_ads_mcp_enabled', $node ) || array_key_exists( 'is_queryable', $node ) ) {
            $items[] = array(
                'id' => (string) ( $node['id'] ?? $node['account_id'] ?? '' ),
                'is_ads_mcp_enabled' => is_bool( $node['is_ads_mcp_enabled'] ?? null ) ? $node['is_ads_mcp_enabled'] : null,
                'is_queryable' => is_bool( $node['is_queryable'] ?? null ) ? $node['is_queryable'] : null,
                'reasons' => array_intersect_key( $node, array_flip( array( 'reason', 'eligibility_reason', 'queryability_reason', 'not_queryable_reason', 'disabled_reason' ) ) ),
                'reporting_verified' => false,
            );
        }
        foreach ( $node as $value ) { if ( is_array( $value ) ) { $items = array_merge( $items, self::accounts( $value, $depth + 1 ) ); } }
        return $items;
    }
}
