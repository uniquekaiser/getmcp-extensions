<?php
/** Preserve reviewed legacy Gateway delegation captured before the upgrade. */
namespace GetMCPExtensions;

final class LegacyGateway180 {
	public const OPTION = 'getmcp_extensions_180_gateway_snapshot';
	private static function signature( array $members, array $keys, bool $boundary_ambiguous = false ): string {
		$auth = (string) get_option( 'getmcp_gateway_auth_type', 'oauth' );
		$ids = get_option( 'getmcp_gateway_servers', array() ); $namespace = get_option( 'getmcp_gateway_namespace_tools', null );
		$features = Runtime::settings()['features'];
		$settings = array( 'enabled' => (bool) get_option( 'getmcp_gateway_enabled', false ), 'auth' => in_array( $auth, array( 'none', 'api-key', 'oauth' ), true ) ? $auth : 'oauth', 'mode' => 'selected' === get_option( 'getmcp_gateway_mode', 'all' ) ? 'selected' : 'all', 'ids' => is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array(), 'namespace' => null === $namespace ? true : (bool) $namespace, 'native_oauth' => ! empty( $features['native_oauth'] ), 'remote_mcp' => ! empty( $features['remote_mcp'] ) );
		$payload = array( 'settings' => $settings, 'members' => $members, 'keys' => $keys );
		if ( $boundary_ambiguous ) { $payload['boundary_ambiguous'] = true; }
		$encoded = wp_json_encode( $payload );
		return is_string( $encoded ) ? hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ) : '';
	}

	/** Capture the legacy Gateway's eligible members before core upgrades.
	 * @wp-test feature=upgrade.180 scenario=builtin-gateway-boundary
	 */
	public static function prepare() {
		global $wpdb;
		if ( ! is_string( $wpdb->prefix ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/D', $wpdb->prefix ) ) { return self::error(); }
		$version = get_option( 'getmcp_db_version', '' );
		$mode = get_option( 'getmcp_gateway_mode', 'all' );
		$auth_type = get_option( 'getmcp_gateway_auth_type', 'oauth' );
		if ( ! is_string( $version ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+)*\z/D', $version ) || version_compare( $version, '1.35', '>=' ) || ! get_option( 'getmcp_gateway_enabled', false ) || ! in_array( $auth_type, array( 'oauth', 'api-key', 'none' ), true ) || ! in_array( $mode, array( 'all', 'selected' ), true ) ) { return true; }
		$existing = get_option( self::OPTION, false );
		if ( false !== $existing ) {
			$saved = is_string( $existing ) ? json_decode( $existing, true ) : null;
			return is_array( $saved ) && is_array( $saved['members'] ?? null ) && is_array( $saved['keys'] ?? null ) && is_bool( $saved['boundary_ambiguous'] ?? false ) && is_string( $saved['signature'] ?? null ) && preg_match( '/\A[a-f0-9]{64}\z/D', $saved['signature'] ) && hash_equals( self::signature( $saved['members'], $saved['keys'], $saved['boundary_ambiguous'] ?? false ), $saved['signature'] ) ? true : self::error();
		}
		$features = Runtime::settings()['features']; if ( empty( $features['native_oauth'] ) ) { return true; }
		$selected = get_option( 'getmcp_gateway_servers', array() ); $scope = apply_filters( 'getmcp_extensions_180_migration_scope', null );
		if ( ! is_array( $selected ) || ! array_is_list( $selected ) || ( null !== $scope && ( ! is_array( $scope ) || ! array_is_list( $scope ) ) ) ) { return self::error(); }
		$ids = array(); foreach ( $selected as $id ) { if ( ! ( is_int( $id ) && $id > 0 ) && ! ( is_string( $id ) && preg_match( '/\A[1-9][0-9]*\z/D', $id ) && (string) (int) $id === $id ) ) { return self::error(); } $ids[] = (int) $id; }
		if ( null !== $scope ) { foreach ( $scope as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return self::error(); } } }
		// In an open 1.7 Gateway, protected members were only included when
		// explicitly selected, even if its general mode was "all".
		$ids = ( 'selected' === $mode || 'none' === $auth_type ) ? ( null === $scope ? $ids : array_values( array_intersect( $ids, $scope ) ) ) : $scope;
		if ( array() === $ids ) { return true; }
		// Core 1.7 orders active servers by name and inspects only 500. Read
		// the boundary and candidates in one consistent SQL statement. A
		// collation-equal group crossing it has no stable order, so omit it.
		$table = $wpdb->prefix . 'getmcp_servers';
		$rows = $wpdb->get_results( "SELECT s.id,s.uuid,s.auth_type,s.auth_config,s.server_kind,s.settings,s.outbound_auth_credentials,(s.name = (SELECT b.name FROM `{$table}` b WHERE b.status='active' ORDER BY b.name ASC LIMIT 1 OFFSET 499)) AS boundary_equal FROM `{$table}` s WHERE s.status='active' ORDER BY s.name ASC LIMIT 501", ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { return self::error(); }
		$tied = false;
		if ( count( $rows ) > 500 ) {
			if ( null === $rows[500]['boundary_equal'] ) { return self::error(); }
			$tied = '1' === (string) $rows[500]['boundary_equal']; array_pop( $rows );
			if ( $tied ) { while ( $rows && '1' === (string) $rows[count( $rows ) - 1]['boundary_equal'] ) { array_pop( $rows ); } }
		}
		$members = array();
		foreach ( $rows as $row ) {
			if ( ( null !== $ids && ! in_array( (int) $row['id'], $ids, true ) ) || 'oauth' !== $row['auth_type'] || ! in_array( $row['server_kind'], array( 'native', 'remote-mcp' ), true ) || ! is_string( $row['uuid'] ) || '' === $row['uuid'] ) { continue; }
			$auth = json_decode( (string) $row['auth_config'], true ); $settings = json_decode( (string) $row['settings'], true );
			if ( ! is_array( $auth ) || 'getmcp' !== ( $auth['provider'] ?? null ) || ! is_array( $settings ) ) { continue; }
			$identity = $row['uuid'];
			if ( 'remote-mcp' === $row['server_kind'] ) {
				$remote = $settings['remote_mcp'] ?? null;
				if ( empty( $features['remote_mcp'] ) || ! is_array( $remote ) || empty( $remote['publish_original'] ) ) { continue; }
				if ( 'oauth' !== $auth_type ) {
					if ( ! in_array( $remote['auth_mode'] ?? null, array( 'none', 'shared' ), true ) ) { continue; }
					$identity .= '|' . self::remote_digest( (string) $row['settings'], (string) $row['outbound_auth_credentials'] );
				}
			}
			$members[(string) $row['id']] = $identity;
		}
		if ( ! $members && ! $tied ) { return true; }
		$keys = array();
		if ( 'api-key' === $auth_type ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,key_hash,expires_at FROM {$wpdb->prefix}getmcp_api_keys WHERE server_id=%d AND is_active=1", \GetMCP\Gateway\McpGateway::SERVER_ID ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { return self::error(); }
			foreach ( $rows as $row ) {
				$expiry = empty( $row['expires_at'] ) ? null : strtotime( $row['expires_at'] . ' UTC' );
				if ( ! preg_match( '/\A[a-f0-9]{64}\z/D', (string) $row['key_hash'] ) || (int) $row['id'] < 1 || ( null !== $expiry && ( false === $expiry || $expiry < time() ) ) ) { continue; }
				$keys[(string) $row['id']] = self::key_digest( (int) $row['id'], $row['key_hash'] );
			}
			if ( ! $keys ) { return true; }
		}
		$signature = self::signature( $members, $keys, $tied ); if ( '' === $signature ) { return self::error(); }
		$snapshot = array( 'signature' => $signature, 'members' => $members, 'keys' => $keys );
		if ( $tied ) { $snapshot['boundary_ambiguous'] = true; }
		$value = wp_json_encode( $snapshot );
		if ( ! is_string( $value ) ) { return self::error(); }
		if ( ! add_option( self::OPTION, $value, '', false ) ) { $stored = get_option( self::OPTION, false ); if ( ! is_string( $stored ) || ! hash_equals( $value, $stored ) ) { return self::error(); } }
		return true;
	}

	private static function key_digest( int $id, string $hash ): string { return hash_hmac( 'sha256', 'gateway-key|' . $id . '|' . $hash, wp_salt( 'auth' ) ); }
	private static function remote_digest( string $settings, string $credentials ): string { return hash_hmac( 'sha256', wp_json_encode( array( 'settings' => $settings, 'credentials' => $credentials ) ), wp_salt( 'auth' ) ); }

	/** Report uncertainty without recreating historical grants or changing access.
	 * @wp-test feature=upgrade.180 scenario=gateway-history-notice
	 */
	public static function review_required(): bool {
		return (bool) self::review_items();
	}

	/** Describe the review without exposing credentials or changing current access. */
	private static function review_items(): array {
		if ( ! Runtime::ready() || 'vendor-original-1.8.0' !== Runtime::profile() || ! \GetMCP\Gateway\McpGateway::is_enabled() ) { return array(); }
		$raw = get_option( self::OPTION, false ); $snapshot = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$valid = is_array( $snapshot ) && is_array( $snapshot['members'] ?? null ) && is_array( $snapshot['keys'] ?? null ) && is_bool( $snapshot['boundary_ambiguous'] ?? false ) && is_string( $snapshot['signature'] ?? null ) && preg_match( '/\A[a-f0-9]{64}\z/D', $snapshot['signature'] ) && hash_equals( self::signature( $snapshot['members'], $snapshot['keys'], $snapshot['boundary_ambiguous'] ?? false ), $snapshot['signature'] );
		$items = array();
		if ( $valid && ! empty( $snapshot['boundary_ambiguous'] ) ) { $items[] = 'Previous Gateway policy'; }
		$raw_ledger = get_option( Upgrade180Auto::LEDGER, false ); $ledger = is_string( $raw_ledger ) ? json_decode( $raw_ledger, true ) : null;
		if ( ! is_array( $ledger ) ) { $ledger = array(); }
		$gateway = \GetMCP\Gateway\McpGateway::class; $mode = $gateway::mode(); $auth_type = $gateway::auth_type(); $selected = $gateway::selected_server_ids();
		$list = ( new \GetMCP\Core\ServerManager() )->list( array( 'status' => 'active', 'per_page' => 500, 'page' => 1, 'orderby' => 'name', 'order' => 'ASC' ) );
		foreach ( $list['items'] ?? array() as $server ) {
			$id = (int) $server->id; $explicit = in_array( $id, $selected, true );
			if ( $id <= 0 || \GetMCP\Builtin\BuiltinServer::slug_is_reserved( $server->slug ) || ( 'selected' === $mode && ! $explicit ) || ( 'none' === $auth_type && 'none' !== $server->auth_type && ! $explicit ) ) { continue; }
			$kind = LegacyBridge::kind( $server ); $auth = json_decode( (string) $server->auth_config, true );
			// Project gateways were never nested in the account Gateway. Remote
			// servers were included only when explicitly published there.
			if ( ! in_array( $kind, array( 'native', 'remote-mcp' ), true ) || ! is_array( $auth ) || 'getmcp' !== ( $auth['provider'] ?? null ) ) { continue; }
			if ( 'remote-mcp' === $kind ) {
				$settings = json_decode( (string) $server->settings, true );
				if ( ! is_array( $settings ) || empty( $settings['remote_mcp']['publish_original'] ) || ! Runtime::server_enabled( $server ) ) { continue; }
			}
			if ( 'oauth' === $server->auth_type ) { $items[] = $server->name . ' (ID ' . $id . ')'; continue; }
			if ( 'wordpress' !== $server->auth_type || 'oauth' !== ( $ledger[(string) $id] ?? null ) ) { continue; }
			$identity = $server->uuid;
			if ( 'oauth' !== $auth_type && 'remote-mcp' === $kind ) { $identity .= '|' . self::remote_digest( (string) $server->settings, (string) $server->outbound_auth_credentials ); }
			$captured = $valid ? ( $snapshot['members'][(string) $id] ?? null ) : null;
			if ( ! is_string( $captured ) || ! hash_equals( $captured, $identity ) ) { $items[] = $server->name . ' (ID ' . $id . ')'; }
		}
		return $items;
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$items = self::review_items();
		if ( ! $items ) { return; }
		$url = esc_url( admin_url( 'admin.php?page=getmcp-gateway#/gateway' ) );
		echo '<div class="notice notice-warning"><p>GetMCP Extensions could not verify previous access for these built-in Gateway items: ' . implode( ', ', array_map( 'esc_html', $items ) ) . '. Current access rules have been kept. Please review the Gateway and compare member permissions with a pre-upgrade record before changing access. <a href="' . $url . '">Open Gateway</a>.</p></div>';
	}

	/** A current managed key must also be one captured before the upgrade. */
	private static function authorized_key( array $keys ): bool {
		global $wpdb;
		if ( \GetMCP\Auth\McpAuth::is_passthrough() || empty( $_SERVER['HTTP_X_API_KEY'] ) ) { return false; }
		$token = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_API_KEY'] ) ) ); if ( '' === $token ) { return false; }
		$hash = hash( 'sha256', $token );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,key_hash,expires_at FROM {$wpdb->prefix}getmcp_api_keys WHERE server_id=%d AND key_hash=%s AND is_active=1", \GetMCP\Gateway\McpGateway::SERVER_ID, $hash ), ARRAY_A );
		if ( ! is_array( $row ) || ! hash_equals( $hash, (string) ( $row['key_hash'] ?? '' ) ) ) { return false; }
		$expiry = empty( $row['expires_at'] ) ? null : strtotime( $row['expires_at'] . ' UTC' );
		if ( null !== $expiry && ( false === $expiry || $expiry < time() ) ) { return false; }
		$id = (int) ( $row['id'] ?? 0 ); $expected = $keys[(string) $id] ?? null;
		return $id > 0 && is_string( $expected ) && hash_equals( $expected, self::key_digest( $id, $hash ) );
	}

	/** Never extend direct access, another resource, or a later gateway edit. */
	public static function members( array $included ): array {
		$row = \GetMCP\Auth\McpAuth::current_token_row(); $user = (int) ( $row->user_id ?? 0 );
		$native = \GetMCP\Gateway\McpGateway::reachable_by( $included, $user );
		$raw = get_option( self::OPTION, false ); $snapshot = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$ledger_raw = get_option( Upgrade180Auto::LEDGER, false ); $ledger = is_string( $ledger_raw ) ? json_decode( $ledger_raw, true ) : null;
		if ( ! is_array( $snapshot ) || ! is_string( $snapshot['signature'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $snapshot['signature'] ) || ! is_array( $snapshot['members'] ?? null ) || ! is_array( $snapshot['keys'] ?? null ) || ! is_bool( $snapshot['boundary_ambiguous'] ?? false ) || ! hash_equals( self::signature( $snapshot['members'], $snapshot['keys'], $snapshot['boundary_ambiguous'] ?? false ), $snapshot['signature'] ) || ! is_array( $ledger ) ) { return $native; }
		$gateway_auth = \GetMCP\Gateway\McpGateway::auth_type();
		if ( 'oauth' === $gateway_auth ) {
			$resource = \GetMCP\Auth\OAuthProvider::canonicalize_resource( \GetMCP\Gateway\McpGateway::resource() ); $client_server = $row->client_server_id ?? null;
			if ( $user < 1 || ( ! is_int( $client_server ) && ( ! is_string( $client_server ) || ! ctype_digit( $client_server ) ) ) || ! in_array( (int) $client_server, array( 0, \GetMCP\Gateway\McpGateway::SERVER_ID ), true ) || ! is_string( $row->resource ?? null ) || '' === $resource || ! hash_equals( $resource, $row->resource ) ) { return $native; }
		} elseif ( 'api-key' === $gateway_auth ) {
			if ( ! self::authorized_key( $snapshot['keys'] ) ) { return $native; }
		} elseif ( 'none' !== $gateway_auth ) { return $native; }
		$allowed = array(); foreach ( $native as $server ) { $allowed[(int) $server->id] = true; }
		$result = array();
		foreach ( $included as $server ) {
			$id = (int) $server->id; $auth = json_decode( (string) $server->auth_config, true );
			$identity = $server->uuid;
			if ( 'oauth' !== $gateway_auth && 'remote-mcp' === LegacyBridge::kind( $server ) ) { $identity .= '|' . self::remote_digest( (string) $server->settings, (string) $server->outbound_auth_credentials ); }
			$legacy = isset( $snapshot['members'][(string) $id], $ledger[(string) $id] ) && 'oauth' === $ledger[(string) $id] && is_string( $snapshot['members'][(string) $id] ) && hash_equals( $snapshot['members'][(string) $id], $identity ) && 'wordpress' === $server->auth_type && is_array( $auth ) && 'getmcp' === ( $auth['provider'] ?? null );
			if ( isset( $allowed[$id] ) || $legacy ) { $result[] = $server; }
		}
		return $result;
	}

	private static function error(): \WP_Error { return new \WP_Error( 'extensions_gateway_snapshot_failed', 'The legacy gateway could not be safely inventoried; core migration is deferred.', array( 'status' => 503 ) ); }
}
