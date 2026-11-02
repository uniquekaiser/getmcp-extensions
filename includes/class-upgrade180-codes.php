<?php
/** Preserve the exact 1.7 redirect binding of pending authorization codes. */
namespace GetMCPExtensions;

final class Upgrade180Codes {
	/** Runs after the reviewed 1.8 database upgrade; safe to retry each request. */
	public static function maybe_migrate(): void {
		if ( is_multisite() || get_option( 'getmcp_extensions_deactivated' ) || '1' !== get_option( 'getmcp_extensions_schema_version' ) || '1.35' !== get_option( 'getmcp_db_version' ) || ! Upgrade180::reviewed_core() ) { return; }
		global $wpdb;
		$servers = $wpdb->get_results( "SELECT id, auth_config FROM {$wpdb->prefix}getmcp_servers WHERE auth_type = 'oauth' AND server_kind IN ('native','gateway','remote-mcp')", ARRAY_A );
		if ( ! is_array( $servers ) ) { return; }
		$manager = new \GetMCP\Core\ServerManager();
		foreach ( $servers as $stored ) {
			$config = json_decode( (string) $stored['auth_config'], true );
			if ( ! is_array( $config ) || 'getmcp' !== ( $config['provider'] ?? null ) ) { continue; }
			$server = $manager->get( (int) $stored['id'] );
			if ( ! $server ) { continue; }
			$resource = \GetMCP\Auth\FirstPartyOAuth::canonical_resource( $server );
			if ( ! is_string( $resource ) || '' === $resource ) { continue; }
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, authorization_code_hash FROM {$wpdb->prefix}getmcp_oauth_tokens WHERE resource = %s AND BINARY resource = BINARY %s AND authorization_code_hash IS NOT NULL AND authorization_code_hash <> '' AND redirect_uri IS NULL AND expires_at > UTC_TIMESTAMP()", $resource, $resource ), ARRAY_A );
			if ( ! is_array( $rows ) ) { continue; }
			foreach ( $rows as $row ) {
				$hash = $row['authorization_code_hash'];
				if ( ! is_string( $hash ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) { continue; }
				// This is the exact transient written by 1.7 when it issued the code.
				$redirect = get_transient( 'getmcp_code_redirect_' . $hash );
				if ( ! is_string( $redirect ) || '' === $redirect || strlen( $redirect ) > 2048 ) { continue; }
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}getmcp_oauth_tokens SET redirect_uri = %s WHERE id = %d AND BINARY authorization_code_hash = BINARY %s AND BINARY resource = BINARY %s AND redirect_uri IS NULL AND expires_at > UTC_TIMESTAMP() AND EXISTS (SELECT 1 FROM {$wpdb->prefix}getmcp_servers WHERE id = %d AND auth_type = 'oauth' AND BINARY auth_config = BINARY %s)", $redirect, (int) $row['id'], $hash, $resource, (int) $stored['id'], $stored['auth_config'] ) );
			}
		}
	}
}
