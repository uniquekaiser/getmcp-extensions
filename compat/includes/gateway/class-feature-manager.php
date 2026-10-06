<?php
/** Shared management service for REST, management MCP tools, and PHP callers. @package GetMCP */
namespace GetMCP\Gateway;

use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Auth\OAuthUserAccess;
use GetMCP\Licensing\LicenseTier;
use GetMCP\Remote\SafeHttp;
use GetMCP\Remote\RemoteException;

class FeatureManager {

	/** Validate persisted invariants, including writes through existing server interfaces. */
	public static function validate_storage( array $data ): array {
		$kind = $data['server_kind'] ?? 'native';
		if ( ! in_array( $kind, array( 'native', 'gateway', 'remote-mcp' ), true ) ) { throw new \InvalidArgumentException( 'Unknown server kind.' ); }
		if ( 'native' === $kind ) { return $data; }
		\GetMCPExtensions\Runtime::assert_enabled( 'gateway' === $kind ? 'project_gateways' : 'remote_mcp' );
		if ( ! empty( $data['slug'] ) && ( McpGateway::slug_is_reserved( $data['slug'] ) || \GetMCP\Builtin\BuiltinServer::slug_is_reserved( $data['slug'] ) ) ) { throw new \InvalidArgumentException( 'That slug is reserved for an existing GetMCP endpoint.' ); }
		if ( 'oauth' !== $data['auth_type'] || ! \GetMCP\Auth\FirstPartyOAuth::config_is_native( $data['auth_config'] ) ) { throw new \InvalidArgumentException( 'Project gateways and remote connections require WordPress login.' ); }
		$auth = json_decode( $data['auth_config'], true );
		$valid = OAuthUserAccess::validate_ids( $auth['allowed_user_ids'] ?? array() );
		if ( is_wp_error( $valid ) ) { throw new \InvalidArgumentException( $valid->get_error_message() ); }
		if ( ! LicenseTier::current()->allows_auth_method( 'oauth' ) ) { throw new \InvalidArgumentException( 'Your GetMCP plan does not include OAuth.' ); }
		if ( ! in_array( $data['status'], array( 'active', 'paused', 'draft' ), true ) ) { throw new \InvalidArgumentException( 'Invalid server status.' ); }
		$settings = json_decode( $data['settings'] ?? '{}', true );
		if ( ! is_array( $settings ) ) { throw new \InvalidArgumentException( 'Invalid server settings.' ); }
		if ( 'gateway' === $kind ) {
			$ids = $settings['gateway']['server_ids'] ?? array();
			if ( ! is_array( $ids ) || array_values( $ids ) !== $ids ) { throw new \InvalidArgumentException( 'server_ids must be a list.' ); }
			foreach ( $ids as $id ) {
				$member = is_int( $id ) && $id > 0 ? ( new ServerManager() )->get( $id ) : false;
				if ( ! $member || 'gateway' === $member->server_kind || \GetMCP\Builtin\BuiltinServer::slug_is_reserved( $member->slug ) ) { throw new \InvalidArgumentException( 'Gateway members must be stored native or remote servers. Management servers and nested gateways are excluded.' ); }
			}
			$settings['gateway']['server_ids'] = array_values( array_unique( $ids ) );
		} else {
			$remote = $settings['remote_mcp'] ?? array();
			SafeHttp::validate_url( (string) ( $remote['endpoint'] ?? '' ) );
			if ( ! in_array( $remote['auth_mode'] ?? '', array( 'none', 'shared', 'oauth' ), true ) ) { throw new \InvalidArgumentException( 'Invalid upstream authentication mode.' ); }
			if ( ! empty( $remote['resource_metadata_url'] ) ) { SafeHttp::validate_url( $remote['resource_metadata_url'] ); }
		}
		$data['settings'] = wp_json_encode( $settings );
		$data['transport_type'] = 'streamable-http';
		return $data;
	}

	public static function all(): array {
		$items = array(); $page = 1;
		do {
			$r = ( new ServerManager() )->list( array( 'per_page' => 100, 'page' => $page++, 'orderby' => 'id', 'order' => 'ASC' ) );
			$items = array_merge( $items, $r['items'] );
		} while ( count( $items ) < (int) $r['total'] && $r['items'] );
		return $items;
	}

	/** Secret-free response, suitable for management exports. */
	public static function present( Server $server ): array {
		$settings = json_decode( $server->settings ?? '{}', true ) ?: array();
		$auth = json_decode( $server->auth_config ?? '{}', true ) ?: array();
		return array( 'id' => $server->id, 'kind' => $server->server_kind, 'name' => $server->name, 'slug' => $server->slug, 'status' => $server->status, 'url' => $server->get_endpoint_url(), 'allowed_user_ids' => $auth['allowed_user_ids'] ?? array(), 'server_ids' => $settings['gateway']['server_ids'] ?? array(), 'remote' => $settings['remote_mcp'] ?? array(), 'connection_status' => get_transient( self::status_key( $server, get_current_user_id() ) ) ?: array( 'state' => 'not_tested' ), 'header_names' => \GetMCPExtensions\ConnectionHeaders::names( $server ), 'has_shared_credentials' => ! empty( $server->outbound_auth_credentials ), 'updated_at' => $server->updated_at );
	}

	private static function status_key( Server $server, int $user ): string {
		try { $identity = \GetMCP\Remote\UpstreamConnections::identity( $server, $user ); } catch ( \Throwable $e ) { $identity = 'unreadable'; }
		return 'getmcp_remote_status_' . hash( 'sha256', $server->id . '|' . $user . '|' . $server->settings . '|' . $identity );
	}

	public static function run( string $operation, array $args, ?int $actor = null ): array {
		$actor = $actor ?? get_current_user_id();
		if ( ! $actor || ! user_can( $actor, 'getmcp_manage_servers' ) ) { throw new RemoteException( 'You cannot manage MCP connections or gateways.', -32003 ); }
		$manager = new ServerManager();
		if ( 'list' === $operation ) { return array( 'servers' => array_map( array( self::class, 'present' ), self::all() ) ); }
		$id = (int) ( $args['id'] ?? 0 );
		$server = $id ? $manager->get( $id ) : false;
		if ( $id && ( ! $server || 'native' === $server->server_kind ) ) { throw new RemoteException( 'Connection or gateway not found.', -32602 ); }
		if ( 'get' === $operation ) { if ( ! $server ) { throw new RemoteException( 'An ID is required.', -32602 ); } return self::present( $server ); }
		if ( 'delete' === $operation ) { if ( ! $server || ! $manager->delete( $id ) ) { throw new RemoteException( 'Could not delete the connection or gateway.' ); } return array( 'deleted' => $id ); }
		if ( 'preview' === $operation ) {
			if ( ! $server ) { throw new RemoteException( 'An ID is required.', -32602 ); }
			// Preview grants no access: the actual actor must be explicitly permitted.
			if ( ! \GetMCP\Auth\FirstPartyOAuth::can_user_access( $actor, $server ) ) { throw new RemoteException( 'Add your user to this endpoint allowlist before previewing its capabilities.', -32003 ); }
			$handler = new FeatureHandler( $server, null, $actor ); $result = array();
			try {
				foreach ( array( 'tools/list', 'resources/list', 'resources/templates/list', 'prompts/list' ) as $method ) { $result[ $method ] = $handler->handle( $method, array() ); }
				set_transient( self::status_key( $server, $actor ), array( 'state' => 'connected', 'checked_at' => gmdate( 'c' ) ), 300 );
			} catch ( \Throwable $e ) { set_transient( self::status_key( $server, $actor ), array( 'state' => 'connection_failed', 'checked_at' => gmdate( 'c' ) ), 300 ); throw $e; }
			return $result;
		}
		if ( 'save' !== $operation ) { throw new RemoteException( 'Unknown management operation.', -32602 ); }
		if ( ! $server && ! LicenseTier::current()->can_create_server( $manager->get_count() ) ) { throw new RemoteException( 'Your GetMCP server limit has been reached.' ); }
		$kind = $server ? $server->server_kind : ( $args['kind'] ?? 'gateway' );
		if ( ! in_array( $kind, array( 'gateway', 'remote-mcp' ), true ) ) { throw new RemoteException( 'Invalid server kind.', -32602 ); }
		$prior = $server ? self::present( $server ) : array();
		$ids = $args['allowed_user_ids'] ?? ( $prior['allowed_user_ids'] ?? array() );
		$auth = array( 'provider' => 'getmcp', 'allowed_user_ids' => $ids );
		$settings = $server ? json_decode( $server->settings ?? '{}', true ) ?: array() : array();
		if ( 'gateway' === $kind ) { $settings['gateway'] = array( 'server_ids' => $args['server_ids'] ?? ( $prior['server_ids'] ?? array() ) ); }
		else {
			$remote = $args['remote'] ?? ( $prior['remote'] ?? array() );
			// Allow only documented public configuration keys; secrets have a separate field.
			$settings['remote_mcp'] = array_intersect_key( $remote, array_flip( array( 'endpoint', 'auth_mode', 'resource_metadata_url', 'client_id', 'scope', 'publish_original' ) ) );
		}
		$data = array( 'server_kind' => $kind, 'name' => $args['name'] ?? ( $prior['name'] ?? '' ), 'status' => $args['status'] ?? ( $prior['status'] ?? 'active' ), 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( $auth ), 'settings' => wp_json_encode( $settings ), 'transport_type' => 'streamable-http' );
		if ( ! $server || isset( $args['slug'] ) ) { $data['slug'] = $args['slug'] ?? ''; }
		if ( '' === trim( $data['name'] ) ) { throw new RemoteException( 'A name is required.', -32602 ); }
		if ( isset( $args['credentials'] ) ) {
			if ( 'remote-mcp' !== $kind || ! is_array( $args['credentials'] ) ) { throw new RemoteException( 'Invalid upstream credentials.', -32602 ); }
			$c = array_intersect_key( $args['credentials'], array_flip( array( 'headers', 'client_secret' ) ) );
			if ( isset( $c['headers'] ) ) { SafeHttp::validate_headers( $c['headers'] ); }
			$data['outbound_auth_credentials'] = wp_json_encode( $c );
		}
		$data = \GetMCPExtensions\ConnectionHeaders::prepare( $server, $data, $args );
		$data = self::validate_storage( $data );
		$saved = $server ? $manager->update( $id, $data ) : $manager->create( $data );
		if ( ! $saved ) { throw new RemoteException( 'Could not save the connection or gateway.' ); }
		do_action( 'getmcp_gateway_configuration_saved', $saved, $server, $actor );
		return self::present( $saved );
	}
}
