<?php
/** Independently encrypted upstream OAuth grants. @package GetMCP */
namespace GetMCP\Remote;

use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Auth\FirstPartyOAuth;
use GetMCP\Utils\Encryption;

class UpstreamConnections {

	public static function can_connect( Server $server, int $user ): bool {
		if ( 'native' === $server->server_kind ) { return \GetMCPExtensions\ProviderConnections::can_connect( $server, $user ); }
		if ( ! \GetMCPExtensions\Runtime::enabled( 'remote_mcp' ) ) { return false; }
		if ( $user < 1 || ! get_userdata( $user ) || ! user_can( $user, 'read' ) || 'active' !== $server->status || 'remote-mcp' !== $server->server_kind ) { return false; }
		if ( FirstPartyOAuth::can_user_access( $user, $server ) ) { return true; }
		$page = 1;
		do {
			$result = ( new ServerManager() )->list( array( 'per_page' => 100, 'page' => $page, 'status' => 'active' ) );
			foreach ( $result['items'] as $gateway ) {
				$settings = json_decode( $gateway->settings ?? '{}', true ) ?: array();
				if ( 'gateway' === $gateway->server_kind && FirstPartyOAuth::can_user_access( $user, $gateway ) && in_array( $server->id, $settings['gateway']['server_ids'] ?? array(), true ) ) { return true; }
			}
			++$page;
		} while ( ( $page - 1 ) * 100 < (int) $result['total'] );
		return false;
	}

	public static function get( int $server_id, int $user ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_upstream_connections WHERE server_id = %d AND user_id = %d", $server_id, $user ), ARRAY_A );
		if ( ! $row ) { return null; }
		try { $data = json_decode( Encryption::decrypt_strict( $row['credentials'] ), true, 512, JSON_THROW_ON_ERROR ); }
		catch ( \Throwable $e ) { throw new RemoteException( 'The upstream credential cannot be decrypted. Reconnect your account.' ); }
		if ( ! empty( $data['disconnected'] ) ) { return null; }
		return array( 'version' => $row['version'], 'data' => $data );
	}

	/** Includes disconnected tombstones so a callback cannot resurrect an account. */
	public static function version( int $server_id, int $user ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$wpdb->prefix}getmcp_upstream_connections WHERE server_id = %d AND user_id = %d", $server_id, $user ) );
		return null === $value ? null : (string) $value;
	}

	/** Compare-and-swap prevents concurrent refreshes from replacing rotated grants. */
	public static function save( int $server_id, int $user, array $data, ?string $expected = null ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'getmcp_upstream_connections';
		$version = wp_generate_uuid4();
		$fields = array( 'credentials' => Encryption::encrypt( wp_json_encode( $data ) ), 'version' => $version, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		if ( null !== $expected ) {
			$result = $wpdb->update( $table, $fields, array( 'server_id' => $server_id, 'user_id' => $user, 'version' => $expected ) );
		} else {
			// No expected version means an initial insert, never an implicit replacement.
			// A competing connection is expected; keep encrypted credential SQL out of error logs.
			$previous = $wpdb->suppress_errors( true );
			try { $result = $wpdb->insert( $table, array_merge( array( 'server_id' => $server_id, 'user_id' => $user ), $fields ) ); }
			finally { $wpdb->suppress_errors( $previous ); }
		}
		if ( 1 !== $result ) { throw new RemoteException( 'Your upstream connection changed. Retry the connection operation.' ); }
		do_action( 'getmcp_upstream_connected', $server_id, $user );
		return $version;
	}

	public static function disconnect( int $server_id, int $user ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'getmcp_upstream_connections';
		$credentials = Encryption::encrypt( wp_json_encode( array( 'disconnected' => true ) ) );
		$version = wp_generate_uuid4(); $now = gmdate( 'Y-m-d H:i:s' );
		// One atomic upsert removes secrets and changes the version even before the first connection.
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (server_id,user_id,credentials,version,updated_at) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE credentials=VALUES(credentials),version=VALUES(version),updated_at=VALUES(updated_at)", $server_id, $user, $credentials, $version, $now ) );
		if ( false === $result ) { throw new RemoteException( 'Could not disconnect your upstream account. Try again.' ); }
		update_user_meta( $user, 'getmcp_upstream_epoch_' . $server_id, wp_generate_uuid4() );
		do_action( 'getmcp_upstream_disconnected', $server_id, $user );
	}

	public static function delete_server( int $server_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'getmcp_upstream_connections', array( 'server_id' => $server_id ), array( '%d' ) );
	}

	public static function delete_user( int $user ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'getmcp_upstream_connections', array( 'user_id' => $user ), array( '%d' ) );
	}

	public static function headers( Server $server, int $user ): array {
		$config = self::config( $server );
		$custom = \GetMCPExtensions\ConnectionHeaders::read( $server );
		$mode = $config['auth_mode'] ?? 'none';
		if ( 'oauth' === $mode ) {
			if ( ! self::can_connect( $server, $user ) ) { throw new RemoteException( 'You cannot connect this upstream account.', -32003 ); }
			$grant = self::get( $server->id, $user );
			if ( ! $grant ) { throw new RemoteException( 'Connect your upstream account on My MCP Connections.', -32001, array( 'connect_url' => self::portal_url() ) ); }
			if ( ! hash_equals( self::config_hash( $server ), (string) ( $grant['data']['config_hash'] ?? '' ) ) ) { throw new RemoteException( 'The upstream configuration changed. Reconnect your account.', -32001 ); }
			if ( (int) ( $grant['data']['expires_at'] ?? 0 ) <= time() + 30 ) { $grant = UpstreamOAuth::refresh( $server, $user, $grant ); }
			return array_merge( $custom, array( 'Authorization' => 'Bearer ' . $grant['data']['access_token'] ) );
		}
		if ( 'shared' !== $mode ) { return $custom; }
		try { $credentials = json_decode( Encryption::decrypt_strict( $server->outbound_auth_credentials ?? '' ), true, 512, JSON_THROW_ON_ERROR ); }
		catch ( \Throwable $e ) { throw new RemoteException( 'Save the shared upstream credential before connecting.' ); }
		return array_merge( $custom, $credentials['headers'] ?? array() );
	}

	public static function config( Server $server ): array {
		$settings = json_decode( $server->settings ?? '{}', true ) ?: array();
		return $settings['remote_mcp'] ?? array();
	}

	public static function config_hash( Server $server ): string { return hash( 'sha256', wp_json_encode( self::config( $server ) ) . '|' . $server->outbound_auth_credentials ); }

	public static function identity( Server $server, int $user ): string {
		$grant = 'oauth' === ( self::config( $server )['auth_mode'] ?? '' ) ? self::get( $server->id, $user ) : null;
		return hash( 'sha256', $server->id . '|' . $user . '|' . $server->settings . '|' . $server->outbound_auth_credentials . '|' . ( $grant['version'] ?? '' ) );
	}

	/** Only fixed, capability-checked destinations; never accept a caller-supplied URL. */
	public static function portal_url( string $context = '' ): string {
		if ( 'portal' === $context ) { return add_query_arg( 'getmcp_connections', '1', home_url( '/' ) ); }
		if ( 'profile' !== $context && current_user_can( 'getmcp_manage_servers' ) ) { return admin_url( 'admin.php?page=getmcp-my-connections' ); }
		if ( current_user_can( 'read' ) ) { return admin_url( 'profile.php?page=getmcp-account-connections' ); }
		return add_query_arg( 'getmcp_connections', '1', home_url( '/' ) );
	}
}
