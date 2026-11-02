<?php
/** Explicit, credential-free read access to a reviewed public REST origin. */
namespace GetMCPExtensions;
use GetMCP\Core\ServerManager;
use GetMCP\Gateway\ProjectGateway;
use GetMCP\Remote\RemoteException;
final class PublicGateway180 {
	public static function routes(): void {
		if ( ! LegacyBridge::is_180() ) { return; }
		$manage = static fn() => current_user_can( 'getmcp_manage_servers' ) && current_user_can( 'getmcp_manage_tools' );
		register_rest_route( 'getmcp-extensions/v1', '/servers/(?P<id>\d+)/public-upstream', array(
			array( 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => static function( $request ) {
				$server = ( new ServerManager() )->get( (int) $request['id'] );
				if ( ! $server ) { return new \WP_Error( 'public_upstream_missing', 'Server not found.', array( 'status' => 404 ) ); }
				$settings = AuthenticationSettings::object( $server->settings );
				return rest_ensure_response( array( 'origin' => $settings['getmcp_extensions_public_upstream']['origin'] ?? '', 'revision' => self::revision( $server ) ) );
			} ),
			array( 'methods' => 'PUT', 'permission_callback' => $manage, 'callback' => static function( $request ) {
				try { return rest_ensure_response( self::configure( (int) $request['id'], $request->get_param( 'origin' ), $request->get_param( 'revision' ) ) ); }
				catch ( \Throwable $e ) { return new \WP_Error( 'public_upstream_invalid', 'Public upstream configuration could not be saved. Reload and review the server.', array( 'status' => 400 ) ); }
			} ),
		) );
	}
	public static function revision( $server ): string { return hash_hmac( 'sha256', wp_json_encode( array( $server->id, $server->auth_type, $server->auth_config, $server->outbound_auth_type, $server->outbound_auth_credentials, $server->settings ) ), wp_salt( 'auth' ) ); }
	private static function origin( $url ): string {
		if ( ! is_string( $url ) ) { return ''; }
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== strtolower( $p['scheme'] ?? '' ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) ) { return ''; }
		return 'https://' . strtolower( $p['host'] );
	}
	private static function acceptable( $server, string $origin ): bool {
		if ( ! $server || 'native' !== LegacyBridge::kind( $server ) || $server->is_bundle() || $server->is_remote() || \GetMCP\Builtin\BuiltinServer::slug_is_reserved( $server->slug ) || ! Runtime::server_enabled( $server ) || 'active' !== $server->status || 'api-key' !== $server->auth_type || ! in_array( $server->outbound_auth_type ?? '', array( '', 'none' ), true ) || ! empty( $server->outbound_auth_credentials ) || self::origin( $origin ) !== $origin ) { return false; }
		global $wpdb;
		$tools = $wpdb->get_results( $wpdb->prepare( "SELECT endpoint_url,http_method,tool_type,ssl_verify FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d", $server->id ), ARRAY_A );
		if ( ! is_array( $tools ) || ! $tools || '' !== $wpdb->last_error ) { return false; }
		foreach ( $tools as $tool ) {
			if ( 'http' !== $tool['tool_type'] || 'GET' !== strtoupper( $tool['http_method'] ) || '1' !== (string) $tool['ssl_verify'] || $origin !== self::origin( $tool['endpoint_url'] ) ) { return false; }
		}
		return true;
	}
	/** Revision-checked, administrator-selected origin; no credential is changed. */
	public static function configure( int $id, $origin, $revision ): array {
		if ( ! LegacyBridge::is_180() || ! is_string( $origin ) || ! is_string( $revision ) || ( '' !== $origin && ( self::origin( $origin ) !== $origin || false !== strpbrk( substr( $origin, 8 ), '/?#' ) ) ) ) { throw new \InvalidArgumentException( 'Invalid public upstream.' ); }
		$manager = new ServerManager();
		$saved = $manager->update_settings( $id, static function( $settings, $current ) use ( $origin, $revision ) {
			if ( ! hash_equals( self::revision( $current ), $revision ) || ( '' !== $origin && ! self::acceptable( $current, $origin ) ) ) { return new \WP_Error( 'public_upstream_changed', 'Server configuration changed or is not a public read-only origin.' ); }
			if ( '' === $origin ) { unset( $settings['getmcp_extensions_public_upstream'] ); return $settings; }
			$settings['getmcp_extensions_public_upstream'] = array( 'origin' => $origin );
			$settings['getmcp_extensions_required'] = true;
			return $settings;
		} );
		if ( ! $saved || is_wp_error( $saved ) ) { throw new SettingsConflict( 'Public upstream configuration was not saved.' ); }
		return array( 'origin' => $origin, 'revision' => self::revision( $saved ) );
	}
	private static function allowed_origin( $server ): string {
		$settings = AuthenticationSettings::object( $server->settings ?? null ); $policy = $settings['getmcp_extensions_public_upstream'] ?? null;
		if ( ! is_array( $policy ) || array_keys( $policy ) !== array( 'origin' ) || ! is_string( $policy['origin'] ) || ! self::acceptable( $server, $policy['origin'] ) ) { return ''; }
		return $policy['origin'];
	}
	/** The account Gateway uses only an active project grant for the same user. */
	/** @wp-test feature=upgrade.180 scenario=public-gateway-access */
	public static function permits( $server, int $user ): bool {
		if ( '' === self::allowed_origin( $server ) ) { return false; }
		foreach ( \GetMCP\Gateway\FeatureManager::all() as $gateway ) {
			if ( 'gateway' !== LegacyBridge::kind( $gateway ) || 'active' !== $gateway->status || ! LegacyBridge::can_user_access( $user, $gateway ) ) { continue; }
			foreach ( ProjectGateway::members( $gateway ) as $member ) { if ( (int) $member->id === (int) $server->id ) { return true; } }
		}
		return false;
	}
	/** Only an already-authorized gateway may make the public read request. */
	public static function inject( array $request, $server, $tool, bool $delegated ): ?array {
		if ( ! $delegated || ! \GetMCP\Gateway\Delegation::active() ) { return null; }
		$settings = AuthenticationSettings::object( $server->settings ?? null );
		if ( ! array_key_exists( 'getmcp_extensions_public_upstream', $settings ) ) { return null; }
		$origin = self::allowed_origin( $server );
		if ( '' === $origin || $origin !== self::origin( $request['url'] ?? '' ) || 'GET' !== strtoupper( $request['method'] ?? '' ) || (int) $tool->server_id !== (int) $server->id ) { throw new RemoteException( 'The public upstream changed and requires review.', -32003 ); }
		// Incoming and custom credential headers must never cross the gateway boundary.
		$request['headers'] = array( 'Accept' => 'application/json, */*' ); unset( $request['body'] );
		return $request;
	}
}
