<?php
/** Capability aggregation and live membership routing. @package GetMCP */
namespace GetMCP\Gateway;

use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Auth\McpAuth;
use GetMCP\Auth\RateLimiter;
use GetMCP\Remote\McpClient;
use GetMCP\Remote\RemoteException;

class FeatureHandler {

	private Server $server;
	private ?string $session;
	private int $user;
	private bool $preview;
	private const LISTS = array( 'tools/list' => 'tools', 'resources/list' => 'resources', 'resources/templates/list' => 'resourceTemplates', 'prompts/list' => 'prompts' );
	public function __construct( Server $server, ?string $session, ?int $user = null ) {
		$this->server = $server; $this->session = $session;
		$this->preview = null !== $user;
		$this->user = $user ?? (int) ( McpAuth::current_token_row()->user_id ?? 0 );
	}

	public static function handles( Server $server, string $method ): bool {
		return 'native' !== $server->server_kind || ( McpGateway::is_gateway( $server ) && ( isset( self::LISTS[ $method ] ) || in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) );
	}

	public function handle( string $method, array $params ): array {
		\GetMCPExtensions\Runtime::assert_server( $this->server );
		$current = McpGateway::is_gateway( $this->server ) ? $this->server : ( new ServerManager() )->get( $this->server->id );
		if ( ! $current || 'active' !== $current->status ) { throw new RemoteException( 'This MCP endpoint is unavailable.', -32003 ); }
		$this->server = $current;
		if ( 'gateway' === $current->server_kind ) { ProjectGateway::authorize( $current, $this->user ); }
		if ( isset( self::LISTS[ $method ] ) ) {
			$entries = $this->catalogue( $method );
			$items = array_column( $entries, 'definition' );
			return $this->page( $method, $items, $params['cursor'] ?? null );
		}
		if ( in_array( $method, array( 'initialize', 'notifications/initialized', 'server/discover', 'ping' ), true ) ) {
			$class = array( 'initialize' => '\\GetMCP\\Protocol\\InitializeHandler', 'notifications/initialized' => '\\GetMCP\\Protocol\\InitializeHandler', 'server/discover' => '\\GetMCP\\Protocol\\ServerDiscoverHandler', 'ping' => '\\GetMCP\\Protocol\\PingHandler' )[ $method ];
			return ( new $class( $current, $this->session ) )->handle( $method, $params );
		}
		if ( ! in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) { throw new RemoteException( 'This gateway does not implement that MCP capability.', -32601 ); }
		$key = 'resources/read' === $method ? 'uri' : 'name';
		if ( ! is_string( $params[ $key ] ?? null ) || '' === $params[ $key ] ) { throw new RemoteException( 'A string name or URI is required.', -32602 ); }
		if ( isset( $params['arguments'] ) && ! is_array( $params['arguments'] ) ) { throw new RemoteException( 'arguments must be an object.', -32602 ); }
		$member = $current; $definition = array();
		$aggregate = 'gateway' === $current->server_kind || McpGateway::is_gateway( $current );
		if ( 'resources/read' === $method && $aggregate ) {
			$resolved = ProjectGateway::resolve_uri( $current, $params['uri'] );
			if ( ! $resolved ) { throw new RemoteException( 'This resource is not available in the gateway.', -32602 ); }
			$member = $resolved['server']; $params['uri'] = $resolved['uri'];
		} else {
			$list = 'tools/call' === $method ? 'tools/list' : ( 'prompts/get' === $method ? 'prompts/list' : 'resources/list' );
			$found = false;
			foreach ( $this->catalogue( $list ) as $entry ) {
				if ( $entry['definition'][ $key ] === $params[ $key ] ) { $member = $entry['server']; $definition = $entry['definition']; $params[ $key ] = $entry['original']; $found = true; break; }
			}
			// Direct remote reads can target a URI expanded from a resource template.
			if ( ! $found && 'resources/read' !== $method ) { throw new RemoteException( 'This capability is not available in the gateway.', -32602 ); }
		}
		// Re-read membership immediately before invocation, independently of catalogue caches.
		if ( $aggregate ) {
			ProjectGateway::authorize( $current, $this->user );
			$allowed = array_column( array_map( fn( $s ) => array( 'id' => $s->id ), ProjectGateway::members( $current ) ), 'id' );
			if ( ! in_array( $member->id, $allowed, true ) ) { throw new RemoteException( 'Gateway membership changed. Refresh the capability list.', -32003 ); }
		}
		$member = ( new ServerManager() )->get( $member->id );
		if ( ! $member || 'active' !== $member->status || 'gateway' === $member->server_kind ) { throw new RemoteException( 'The selected server is unavailable.', -32003 ); }
		if ( $aggregate && ! RateLimiter::allow( $member ) ) { throw new RemoteException( 'The member server rate limit was reached.' ); }
		if ( 'remote-mcp' !== $member->server_kind ) {
			$class = array( 'tools/call' => '\\GetMCP\\Protocol\\ToolsCallHandler', 'resources/read' => '\\GetMCP\\Protocol\\ResourcesReadHandler', 'prompts/get' => '\\GetMCP\\Protocol\\PromptsGetHandler' )[ $method ];
			$delegate = fn() => ( new $class( $member, $this->session ) )->handle( $method, $params );
			$result = $aggregate ? Delegation::run( $delegate ) : $delegate();
		} else {
			if ( 'tools/call' === $method ) {
				$annotations = (array) ( $definition['annotations'] ?? array() );
				$scope = ! empty( $annotations['readOnlyHint'] ) ? 'mcp:read' : 'mcp:write';
				if ( McpAuth::is_native_server_request() && ! McpAuth::has_scope( $scope ) ) { McpAuth::record_insufficient_scope( $scope ); throw new RemoteException( 'Insufficient OAuth scope for this tool.', -32003 ); }
				\GetMCP\Execution\ToolExecutor::enforce_call_quota();
			}
			$start = microtime( true );
			try { $result = ( new McpClient( $member, $this->user, $aggregate ? $current->id : 0 ) )->call( $method, $params ); }
			catch ( \Throwable $e ) { \GetMCP\Analytics\CallLogger::log_call( $member->id, $method, array( 'name' => $params['name'] ?? '', 'gateway_id' => $current->id, 'user_id' => $this->user ), (int) ( ( microtime( true ) - $start ) * 1000 ), false, 'Upstream MCP connection failed.' ); do_action( 'getmcp_remote_call_failed', $member->id, $current->id, $this->user, $method ); throw $e; }
			\GetMCP\Analytics\CallLogger::log_call( $member->id, $method, array( 'name' => $params['name'] ?? '', 'gateway_id' => $current->id, 'user_id' => $this->user ), (int) ( ( microtime( true ) - $start ) * 1000 ), empty( $result['isError'] ), empty( $result['isError'] ) ? null : 'Upstream MCP tool error.' );
			if ( 'tools/call' === $method ) {
				\GetMCP\Licensing\CallCounter::increment();
				do_action( 'getmcp_remote_tool_called', $member->id, $current->id, $this->user, $params['name'], (int) ( ( microtime( true ) - $start ) * 1000 ), ! empty( $result['isError'] ) );
			}
		}
		return $aggregate ? ProjectGateway::rewrite_result( $current, $member, $result ) : $result;
	}

	private function catalogue( string $method ): array {
		$aggregate = 'gateway' === $this->server->server_kind || McpGateway::is_gateway( $this->server );
		$servers = $aggregate ? ProjectGateway::members( $this->server ) : array( $this->server );
		$entries = array(); $seen = array();
		foreach ( $servers as $server ) {
			$items = 'remote-mcp' === $server->server_kind ? ( new McpClient( $server, $this->user, $aggregate ? $this->server->id : 0 ) )->catalogue( $method ) : self::native_catalogue( $server, $method );
			foreach ( $items as $item ) {
				$key = 'resources/list' === $method ? 'uri' : ( 'resources/templates/list' === $method ? 'uriTemplate' : 'name' );
				if ( ! is_string( $item[ $key ] ?? null ) || '' === $item[ $key ] ) { throw new RemoteException( 'The upstream returned an invalid capability identifier.' ); }
				$original = $item[ $key ];
				if ( $aggregate ) {
					$item[ $key ] = 'uri' === $key || 'uriTemplate' === $key ? ProjectGateway::uri( $this->server, $server, $original ) : ( McpGateway::is_gateway( $this->server ) && 'tools/list' === $method && 'native' === $server->server_kind ? McpGateway::published_name( $server, $original ) : ProjectGateway::name( $server, $original ) );
				}
				if ( isset( $seen[ $item[ $key ] ] ) ) { throw new RemoteException( 'Duplicate capability identifiers were returned. Publication was refused.' ); }
				$seen[ $item[ $key ] ] = true;
				$entries[] = array( 'definition' => $item, 'server' => $server, 'original' => $original );
			}
		}
		return $entries;
	}

	private static function native_catalogue( Server $server, string $method ): array {
		$type = 'tools/list' === $method ? 'Tool' : ( 'prompts/list' === $method ? 'Prompt' : 'Resource' );
		$class = '\\GetMCP\\Core\\' . $type . 'Manager'; $manager = new $class();
		$items = array(); $page = 1;
		do {
			$r = $manager->get_by_server( $server->id, array( 'page' => $page++, 'per_page' => 100, 'status' => 'active' ) );
			foreach ( $r['items'] as $item ) {
				if ( 'Resource' === $type ) {
					$template = ! empty( $item->template_uri );
					if ( $template !== ( 'resources/templates/list' === $method ) ) { continue; }
					$def = $item->to_mcp_definition();
					if ( $template ) { unset( $def['uri'] ); $def['uriTemplate'] = $item->template_uri; }
				} else { $def = $item->to_mcp_definition(); }
				$items[] = $def;
			}
		} while ( ( $page - 1 ) * 100 < (int) $r['total'] && $r['items'] );
		return $items;
	}

	private function page( string $method, array $items, $cursor ): array {
		$fingerprint = hash( 'sha256', $this->server->id . '|' . $this->user . '|' . $method . '|' . wp_json_encode( $items ) );
		$offset = 0;
		if ( null !== $cursor ) {
			if ( ! is_string( $cursor ) || strlen( $cursor ) > 1024 || 2 !== count( explode( '.', $cursor ) ) ) { throw new RemoteException( 'Invalid pagination cursor.', -32602 ); }
			[ $body, $signature ] = explode( '.', $cursor );
			$data = json_decode( base64_decode( $body, true ) ?: '', true );
			if ( ! hash_equals( hash_hmac( 'sha256', $body, wp_salt( 'auth' ) ), $signature ) || ! is_array( $data ) || $fingerprint !== ( $data['fingerprint'] ?? '' ) || ! is_int( $data['offset'] ?? null ) || $data['offset'] < 0 || $data['offset'] > count( $items ) ) { throw new RemoteException( 'Invalid or stale pagination cursor. Restart listing.', -32602 ); }
			$offset = $data['offset'];
		}
		$result = array( self::LISTS[ $method ] => array_slice( $items, $offset, 100 ) );
		if ( $offset + 100 < count( $items ) ) { $body = base64_encode( wp_json_encode( array( 'fingerprint' => $fingerprint, 'offset' => $offset + 100 ) ) ); $result['nextCursor'] = $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) ); }
		return $result;
	}
}
