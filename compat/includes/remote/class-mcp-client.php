<?php
/** Request-scoped MCP client, independent of downstream protocol state. @package GetMCP */
namespace GetMCP\Remote;

use GetMCP\Core\Server;

class McpClient {

	private Server $server;
	private int $user;
	private int $gateway;
	private array $headers;
	private array $state;
	private string $key;

	public function __construct( Server $server, int $user, int $gateway = 0 ) {
		\GetMCPExtensions\Runtime::assert_enabled( 'remote_mcp' );
		$this->server = $server;
		$this->user = $user;
		$this->gateway = $gateway;
		$this->headers = UpstreamConnections::headers( $server, $user );
		$this->key = 'getmcp_remote_' . hash( 'sha256', UpstreamConnections::identity( $server, $user ) . '|' . $gateway );
		$this->state = get_transient( $this->key ) ?: array();
	}

	public function call( string $method, array $params = array() ): array {
		if ( ! $this->state ) { $this->negotiate(); }
		$result = $this->send( $method, $params );
		$field = array( 'tools/call' => 'content', 'resources/read' => 'contents', 'prompts/get' => 'messages' )[ $method ] ?? null;
		if ( $field && ( ! is_array( $result[ $field ] ?? null ) || array_values( $result[ $field ] ) !== $result[ $field ] ) ) { throw new RemoteException( 'The upstream returned a malformed capability result.' ); }
		if ( isset( $result['isError'] ) && ! is_bool( $result['isError'] ) ) { throw new RemoteException( 'The upstream returned an invalid tool error flag.' ); }
		return $result;
	}

	private function negotiate(): void {
		// A harmless modern discovery probe is the only operation eligible for fallback.
		$this->state = array( 'version' => '2026-07-28', 'session' => '', 'capabilities' => array() );
		try {
			$info = $this->send( 'server/discover', array(), true );
			$this->state['capabilities'] = $info['capabilities'] ?? array();
		} catch ( RemoteException $e ) {
			if ( -32601 !== $e->get_rpc_code() && -32098 !== $e->get_rpc_code() ) { $this->state = array(); throw $e; }
			$this->state = array( 'version' => '2025-11-25', 'session' => '', 'capabilities' => array() );
			$info = $this->send( 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => array( 'name' => 'GetMCP Bridge', 'version' => GETMCP_VERSION ) ) );
			$version = $info['protocolVersion'] ?? '';
			if ( ! in_array( $version, array( '2025-11-25', '2025-06-18', '2025-03-26' ), true ) ) { $this->state = array(); throw new RemoteException( 'This upstream requires an unsupported transport or protocol version.' ); }
			$this->state['version'] = $version;
			$this->state['capabilities'] = $info['capabilities'] ?? array();
			$this->send( 'notifications/initialized', array(), false, true );
		}
		set_transient( $this->key, $this->state, 300 );
	}

	private function send( string $method, array $params, bool $probe = false, bool $notification = false ): array {
		$id = bin2hex( random_bytes( 12 ) );
		$headers = $this->headers;
		$headers['Content-Type'] = 'application/json';
		$headers['Accept'] = 'application/json, text/event-stream';
		$headers['MCP-Protocol-Version'] = $this->state['version'];
		unset( $params['_meta']['progressToken'], $params['_meta']['io.modelcontextprotocol/protocolVersion'], $params['_meta']['io.modelcontextprotocol/clientCapabilities'], $params['_meta']['io.modelcontextprotocol/clientInfo'] );
		if ( $this->state['session'] ) { $headers['Mcp-Session-Id'] = $this->state['session']; }
		if ( '2026-07-28' === $this->state['version'] ) {
			$params['_meta'] = array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => array( 'name' => 'GetMCP Bridge', 'version' => GETMCP_VERSION ), 'io.modelcontextprotocol/clientCapabilities' => new \stdClass() );
			$headers['Mcp-Method'] = $method;
			if ( isset( $params['name'] ) || isset( $params['uri'] ) ) { $headers['Mcp-Name'] = self::header_value( $params['name'] ?? $params['uri'] ); }
			if ( 'tools/call' === $method ) {
				foreach ( $this->catalogue( 'tools/list' ) as $tool ) { if ( $tool['name'] === $params['name'] ) { $headers = array_merge( $headers, HeaderSchema::headers( $tool['inputSchema'], $params['arguments'] ?? array() ) ); break; } }
			}
		}
		$body = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: new \stdClass() );
		if ( ! $notification ) { $body['id'] = $id; }
		$r = SafeHttp::request( UpstreamConnections::config( $this->server )['endpoint'], 'POST', $headers, wp_json_encode( $body ) );
		if ( in_array( $r['status'], array( 401, 403 ), true ) ) { throw new RemoteException( 'The upstream requires connection or additional consent. Open My MCP Connections.', -32001, array( 'connect_url' => UpstreamConnections::portal_url() ) ); }
		if ( $probe && in_array( $r['status'], array( 400, 404, 405 ), true ) && ! is_array( json_decode( $r['body'], true ) ) ) { throw new RemoteException( 'Legacy handshake required.', -32098 ); }
		if ( $notification && $r['status'] >= 200 && $r['status'] < 300 && '' === trim( $r['body'] ) ) { return array(); }
		if ( $probe && in_array( $r['status'], array( 400, 404 ), true ) ) {
			$error = json_decode( $r['body'], true );
			if ( isset( $error['error'] ) && -32601 === ( $error['error']['code'] ?? 0 ) ) { throw new RemoteException( 'Legacy handshake required.', -32098 ); }
			if ( -32022 === ( $error['error']['code'] ?? 0 ) ) {
				$supported = $error['error']['data']['supported'] ?? array();
				if ( array_intersect( $supported, array( '2025-11-25', '2025-06-18', '2025-03-26' ) ) ) { throw new RemoteException( 'Legacy handshake required.', -32098 ); }
			}
		}
		if ( $r['status'] < 200 || $r['status'] >= 300 ) {
			if ( 404 === $r['status'] && ! $probe ) { delete_transient( $this->key ); $this->state = array(); }
			throw new RemoteException( 'The upstream rejected the request. No operation was retried.' );
		}
		$type = strtolower( (string) ( $r['headers']['content-type'] ?? '' ) );
		$message = self::decode( $r['body'], $type, $id );
		$session = (string) ( $r['headers']['mcp-session-id'] ?? '' );
		if ( 'initialize' === $method && $session && preg_match( '/^[\x21-\x7e]{1,512}$/D', $session ) ) { $this->state['session'] = $session; }
		if ( isset( $message['error'] ) ) {
			if ( ! is_array( $message['error'] ) || ! is_int( $message['error']['code'] ?? null ) || ! is_string( $message['error']['message'] ?? null ) ) { throw new RemoteException( 'The upstream returned an invalid MCP error.' ); }
			// Preserve the protocol code; upstream text/data may contain credentials or internals.
			throw new RemoteException( 'The upstream reported an MCP error.', (int) ( $message['error']['code'] ?? -32603 ) );
		}
		if ( ( $message['result'] ?? null ) instanceof \stdClass ) { $message['result'] = (array) $message['result']; }
		if ( ! is_array( $message['result'] ?? null ) ) { throw new RemoteException( 'The upstream returned an invalid MCP result.' ); }
		if ( isset( $message['result']['inputRequests'] ) ) { throw new RemoteException( 'This upstream requires an advanced MCP interaction that the bridge does not support.' ); }
		return $message['result'];
	}

	public static function decode( string $body, string $type, string $id ): array {
		$messages = array();
		if ( str_contains( $type, 'text/event-stream' ) ) {
			$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
			foreach ( preg_split( '/\r?\n\r?\n/', $body ) as $event ) {
				$data = array();
				foreach ( preg_split( '/\r?\n/', $event ) as $line ) { if ( str_starts_with( $line, 'data:' ) ) { $data[] = preg_replace( '/^data: ?/', '', $line ); } }
				if ( $data ) { $messages[] = self::json_message( implode( "\n", $data ) ); }
			}
		} elseif ( str_contains( $type, 'application/json' ) ) { $messages[] = self::json_message( $body ); }
		else { throw new RemoteException( 'The upstream returned an unsupported content type.' ); }
		$result = null;
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || '2.0' !== ( $message['jsonrpc'] ?? '' ) ) { throw new RemoteException( 'Malformed upstream MCP response.' ); }
			if ( isset( $message['method'], $message['id'] ) ) { throw new RemoteException( 'This server requires an advanced MCP client interaction that the bridge does not support.' ); }
			if ( array_key_exists( 'id', $message ) && $message['id'] === $id ) {
				if ( null !== $result || array_key_exists( 'result', $message ) === array_key_exists( 'error', $message ) ) { throw new RemoteException( 'Malformed or duplicate upstream MCP response.' ); }
				$result = $message;
			}
		}
		if ( null === $result ) { throw new RemoteException( 'No matching upstream MCP response was received.' ); }
		return $result;
	}

	private static function json_message( string $json ) {
		// Preserve empty JSON objects in schemas and structured results instead of serializing them as [].
		$walk = function( $value ) use ( &$walk ) {
			if ( $value instanceof \stdClass ) { $values = get_object_vars( $value ); return $values ? array_map( $walk, $values ) : $value; }
			return is_array( $value ) ? array_map( $walk, $value ) : $value;
		};
		return $walk( json_decode( $json ) );
	}

	public static function header_value( string $value ): string {
		return preg_match( '/^[\x09\x20-\x7e]*$/D', $value ) && trim( $value ) === $value && ! ( str_starts_with( $value, '=?base64?' ) && str_ends_with( $value, '?=' ) ) ? $value : '=?base64?' . base64_encode( $value ) . '?=';
	}

	/** Complete bounded catalogs; never quietly publish a partial pagination result. */
	public function catalogue( string $method ): array {
		$field = array( 'tools/list' => 'tools', 'resources/list' => 'resources', 'resources/templates/list' => 'resourceTemplates', 'prompts/list' => 'prompts' )[ $method ];
		$key = $this->key . '_' . str_replace( '/', '_', $method );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) { return $cached; }
		$items = array(); $cursor = null; $seen = array();
		for ( $page = 0; $page < 100; ++$page ) {
			try { $r = $this->call( $method, null === $cursor ? array() : array( 'cursor' => $cursor ) ); }
			catch ( RemoteException $e ) { if ( -32601 === $e->get_rpc_code() && 0 === $page ) { return array(); } throw $e; }
			if ( ! is_array( $r[ $field ] ?? null ) ) { throw new RemoteException( 'Invalid upstream catalogue.' ); }
			foreach ( $r[ $field ] as $item ) {
				if ( ! is_array( $item ) ) { throw new RemoteException( 'Invalid upstream catalogue entry.' ); }
				if ( 'tools/list' === $method ) {
					if ( ! is_array( $item['inputSchema'] ?? null ) || ( $item['inputSchema']['type'] ?? '' ) !== 'object' ) { throw new RemoteException( 'Invalid upstream tool schema.' ); }
					try { HeaderSchema::paths( $item['inputSchema'] ); } catch ( RemoteException $e ) { do_action( 'getmcp_remote_tool_schema_rejected', $this->server->id, $item['name'] ?? '' ); continue; }
				}
				$items[] = $item;
			}
			if ( count( $items ) > 10000 ) { throw new RemoteException( 'The upstream catalogue exceeds the 10,000-item limit.' ); }
			$cursor = $r['nextCursor'] ?? null;
			if ( null === $cursor || '' === $cursor ) { set_transient( $key, $items, 60 ); return $items; }
			if ( ! is_string( $cursor ) || isset( $seen[ $cursor ] ) ) { throw new RemoteException( 'Invalid or repeated upstream pagination cursor.' ); }
			$seen[ $cursor ] = true;
		}
		throw new RemoteException( 'The upstream catalogue exceeds the 100-page limit.' );
	}
}
