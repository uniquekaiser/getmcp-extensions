<?php
/** Explicit gateway delegation and reversible capability names. @package GetMCP */
namespace GetMCP\Gateway;

use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Auth\FirstPartyOAuth;
use GetMCP\Remote\RemoteException;

class ProjectGateway {

	public static function members( Server $gateway ): array {
		if ( McpGateway::is_gateway( $gateway ) ) { return McpGateway::included_servers(); }
		$live = ( new ServerManager() )->get( $gateway->id );
		if ( ! $live || 'gateway' !== $live->server_kind || 'active' !== $live->status ) { throw new RemoteException( 'This gateway is unavailable.', -32003 ); }
		$settings = json_decode( $live->settings ?? '{}', true ) ?: array();
		$result = array();
		foreach ( $settings['gateway']['server_ids'] ?? array() as $id ) {
			$server = ( new ServerManager() )->get( (int) $id );
			if ( $server && $server->id > 0 && $server->id !== McpGateway::SERVER_ID && 'gateway' !== $server->server_kind && 'active' === $server->status && \GetMCPExtensions\Runtime::server_enabled( $server ) && ! \GetMCP\Builtin\BuiltinServer::slug_is_reserved( $server->slug ) ) { $result[] = $server; }
		}
		return $result;
	}

	public static function authorize( Server $gateway, int $user ): void {
		if ( McpGateway::is_gateway( $gateway ) ) { return; }
		$live = ( new ServerManager() )->get( $gateway->id );
		if ( ! $live || ! FirstPartyOAuth::can_user_access( $user, $live ) ) { throw new RemoteException( 'You no longer have access to this gateway.', -32003 ); }
	}

	public static function name( Server $server, string $name ): string {
		$plain = $server->slug . '__' . $name;
		if ( strlen( $plain ) <= 128 && preg_match( '/^[a-zA-Z0-9_.-]+$/D', $plain ) ) { return $plain; }
		return substr( preg_replace( '/[^a-zA-Z0-9_.-]/', '-', $server->slug . '__' . $name ), 0, 95 ) . '__' . substr( hash( 'sha256', $server->uuid . '|' . $name ), 0, 30 );
	}

	public static function uri( Server $gateway, Server $server, string $uri ): string {
		// Literal prefix preserves all RFC 6570 expressions in resource templates.
		return 'getmcp://' . ( $gateway->uuid ?: 'legacy' ) . '/' . $server->uuid . '/' . $uri;
	}

	public static function resolve_uri( Server $gateway, string $uri ): array {
		foreach ( self::members( $gateway ) as $server ) {
			$prefix = self::uri( $gateway, $server, '' );
			if ( str_starts_with( $uri, $prefix ) && strlen( $uri ) > strlen( $prefix ) ) { return array( 'server' => $server, 'uri' => substr( $uri, strlen( $prefix ) ) ); }
		}
		throw new RemoteException( 'That resource is not available through this gateway.', -32602 );
	}

	public static function rewrite_result( Server $gateway, Server $server, array $result ): array {
		$rewrite = static function ( array $node ) use ( &$rewrite, $gateway, $server ): array {
			if ( isset( $node['uri'] ) && is_string( $node['uri'] ) && ( isset( $node['mimeType'] ) || isset( $node['text'] ) || isset( $node['blob'] ) || 'resource_link' === ( $node['type'] ?? '' ) ) ) { $node['uri'] = self::uri( $gateway, $server, $node['uri'] ); }
			foreach ( $node as $key => $value ) {
				// Structured tool data and arbitrary metadata are opaque, not resource blocks.
				if ( in_array( $key, array( 'structuredContent', '_meta' ), true ) ) { continue; }
				if ( is_array( $value ) ) { $node[ $key ] = $rewrite( $value ); }
			}
			return $node;
		};
		return $rewrite( $result );
	}
}
