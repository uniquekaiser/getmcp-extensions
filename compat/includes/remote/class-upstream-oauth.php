<?php
/** Per-WordPress-user OAuth client; independent from inbound GetMCP grants. @package GetMCP */
namespace GetMCP\Remote;

use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Utils\Encryption;

class UpstreamOAuth {

	public static function callback_url(): string { return add_query_arg( 'getmcp_upstream_callback', '1', home_url( '/' ) ); }

	private static function metadata( Server $server ): array {
		$config = UpstreamConnections::config( $server );
		$parts = wp_parse_url( $config['endpoint'] );
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$url = $config['resource_metadata_url'] ?? ( $origin . '/.well-known/oauth-protected-resource' . ( $parts['path'] ?? '' ) );
		try { $resource = SafeHttp::json( $url ); }
		catch ( RemoteException $e ) {
			if ( ! empty( $config['resource_metadata_url'] ) ) { throw $e; }
			// Discover the protected resource document from the upstream challenge when needed.
			$probe = SafeHttp::request( $config['endpoint'], 'POST', array( 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover' ), wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 'oauth-discovery', 'method' => 'server/discover', 'params' => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass() ) ) ) ) );
			$challenge = (string) ( $probe['headers']['www-authenticate'] ?? '' );
			if ( 401 !== $probe['status'] || ! preg_match( '/resource_metadata="([^"\r\n]+)"/', $challenge, $match ) ) { throw new RemoteException( 'Configure the upstream protected resource metadata URL.' ); }
			$resource = SafeHttp::json( $match[1] );
		}
		if ( ( $resource['resource'] ?? '' ) !== $config['endpoint'] || empty( $resource['authorization_servers'][0] ) ) { throw new RemoteException( 'The upstream OAuth resource metadata does not identify this MCP endpoint.' ); }
		$issuer = $resource['authorization_servers'][0];
		SafeHttp::validate_url( $issuer, true );
		$p = wp_parse_url( $issuer );
		$base = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		$metadata = null;
		foreach ( array( $base . '/.well-known/oauth-authorization-server' . ( $p['path'] ?? '' ), rtrim( $issuer, '/' ) . '/.well-known/openid-configuration' ) as $candidate ) {
			try { $metadata = SafeHttp::json( $candidate ); break; } catch ( RemoteException $e ) { /* Try the other standard discovery mechanism. */ }
		}
		if ( ! $metadata || ( $metadata['issuer'] ?? '' ) !== $issuer ) { throw new RemoteException( 'The upstream OAuth issuer metadata is invalid.' ); }
		foreach ( array( 'authorization_endpoint', 'token_endpoint' ) as $field ) {
			if ( empty( $metadata[ $field ] ) ) { throw new RemoteException( 'The upstream OAuth metadata is incomplete.' ); }
			SafeHttp::validate_url( $metadata[ $field ], true );
		}
		if ( ! in_array( 'S256', $metadata['code_challenge_methods_supported'] ?? array(), true ) ) { throw new RemoteException( 'The upstream authorization server must support PKCE S256.' ); }
		$metadata['resource'] = $resource;
		return $metadata;
	}

	public static function begin( Server $server, int $user ): array {
		if ( ! UpstreamConnections::can_connect( $server, $user ) || 'oauth' !== ( UpstreamConnections::config( $server )['auth_mode'] ?? '' ) ) { throw new RemoteException( 'You cannot connect this upstream account.', -32003 ); }
		$metadata = self::metadata( $server );
		$config = UpstreamConnections::config( $server );
		$client_id = trim( (string) ( $config['client_id'] ?? '' ) );
		$client_secret = '';
		if ( ! empty( $server->outbound_auth_credentials ) ) {
			$stored = json_decode( Encryption::decrypt_strict( $server->outbound_auth_credentials ), true ) ?: array();
			$client_secret = (string) ( $stored['client_secret'] ?? '' );
		}
		if ( '' === $client_id && ! empty( $metadata['client_id_metadata_document_supported'] ) ) {
			$client_id = rest_url( 'getmcp/v1/upstreams/' . $server->uuid . '/client-metadata' );
		} elseif ( '' === $client_id && ! empty( $metadata['registration_endpoint'] ) ) {
			$registered = SafeHttp::json( $metadata['registration_endpoint'], 'POST', self::client_metadata( $server ) );
			$client_id = (string) ( $registered['client_id'] ?? '' );
			$client_secret = (string) ( $registered['client_secret'] ?? '' );
		}
		if ( '' === $client_id ) { throw new RemoteException( 'Configure a preregistered upstream OAuth client ID.' ); }
		$state = bin2hex( random_bytes( 32 ) );
		$verifier = self::b64( random_bytes( 48 ) );
		$previous_version = UpstreamConnections::version( $server->id, $user );
		$record = array( 'epoch' => (string) get_user_meta( $user, 'getmcp_upstream_epoch_' . $server->id, true ), 'server_id' => $server->id, 'user_id' => $user, 'expires_at' => time() + 600, 'verifier' => $verifier, 'metadata' => $metadata, 'client_id' => $client_id, 'client_secret' => $client_secret, 'config_hash' => UpstreamConnections::config_hash( $server ), 'previous_version' => $previous_version );
		if ( ! add_option( 'getmcp_upstream_state_' . hash( 'sha256', $state ), Encryption::encrypt( wp_json_encode( $record ) ), '', false ) ) { throw new RemoteException( 'Could not start the upstream connection.' ); }
		$scope = trim( (string) ( $config['scope'] ?? implode( ' ', $metadata['resource']['scopes_supported'] ?? array() ) ) );
		$query = array( 'response_type' => 'code', 'client_id' => $client_id, 'redirect_uri' => self::callback_url(), 'state' => $state, 'code_challenge' => self::b64( hash( 'sha256', $verifier, true ) ), 'code_challenge_method' => 'S256', 'resource' => $config['endpoint'] );
		if ( '' !== $scope ) { $query['scope'] = $scope; }
		return array( 'authorization_url' => add_query_arg( $query, $metadata['authorization_endpoint'] ) );
	}

	/** Atomically consume encrypted state; bind it to the logged-in principal and issuer. */
	public static function complete( array $query, int $user ): void {
		global $wpdb;
		$state = $query['state'] ?? '';
		if ( ! is_string( $state ) || ! preg_match( '/^[a-f0-9]{64}$/D', $state ) ) { throw new RemoteException( 'Invalid upstream OAuth state.' ); }
		$name = 'getmcp_upstream_state_' . hash( 'sha256', $state );
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( ! $value ) { throw new RemoteException( 'The upstream consent expired or was already used.' ); }
		$record = json_decode( Encryption::decrypt_strict( $value ), true ) ?: array();
		if ( $user < 1 || $user !== ( $record['user_id'] ?? 0 ) ) { throw new RemoteException( 'Sign in with the WordPress account that started this connection.' ); }
		if ( 1 !== $wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value ) ) ) { throw new RemoteException( 'The upstream consent was already used.' ); }
		wp_cache_delete( $name, 'options' );
		$server = ( new ServerManager() )->get( (int) $record['server_id'] );
		if ( ! $server || ! UpstreamConnections::can_connect( $server, $user ) || $record['epoch'] !== (string) get_user_meta( $user, 'getmcp_upstream_epoch_' . $server->id, true ) || $record['expires_at'] < time() || UpstreamConnections::config_hash( $server ) !== $record['config_hash'] ) { throw new RemoteException( 'Access or connection configuration changed. Start again.' ); }
		$issuer = $query['iss'] ?? null;
		if ( ( null !== $issuer && $issuer !== $record['metadata']['issuer'] ) || ( ! empty( $record['metadata']['authorization_response_iss_parameter_supported'] ) && null === $issuer ) ) { throw new RemoteException( 'The upstream OAuth issuer did not match.' ); }
		if ( isset( $query['error'] ) || empty( $query['code'] ) || ! is_string( $query['code'] ) ) { throw new RemoteException( 'Upstream consent was declined or did not return a code.' ); }
		$tokens = self::token_request( $record['metadata']['token_endpoint'], array( 'grant_type' => 'authorization_code', 'code' => $query['code'], 'code_verifier' => $record['verifier'], 'redirect_uri' => self::callback_url(), 'client_id' => $record['client_id'], 'resource' => UpstreamConnections::config( $server )['endpoint'] ), $record['client_secret'], $record['metadata'] );
		$data = array_merge( $tokens, array( 'metadata' => $record['metadata'], 'client_id' => $record['client_id'], 'client_secret' => $record['client_secret'], 'config_hash' => $record['config_hash'] ) );
		// A parallel disconnect/reconnect must not be undone by a stale callback.
		$current_version = UpstreamConnections::version( $server->id, $user );
		$live = ( new ServerManager() )->get( $server->id );
		if ( $current_version !== $record['previous_version'] || $record['epoch'] !== (string) get_user_meta( $user, 'getmcp_upstream_epoch_' . $server->id, true ) || ! $live || ! UpstreamConnections::can_connect( $live, $user ) || $record['config_hash'] !== UpstreamConnections::config_hash( $live ) ) { throw new RemoteException( 'Your upstream connection changed while consent was open. Start again.' ); }
		UpstreamConnections::save( $server->id, $user, $data, $record['previous_version'] );
	}

	public static function refresh( Server $server, int $user, array $grant ): array {
		if ( empty( $grant['data']['refresh_token'] ) ) { throw new RemoteException( 'The upstream login expired. Reconnect on My MCP Connections.', -32001, array( 'connect_url' => UpstreamConnections::portal_url() ) ); }
		$lock = 'getmcp_upstream_refresh_' . hash( 'sha256', $server->id . '|' . $user );
		if ( ! add_option( $lock, time() + 60, '', false ) ) {
			$expires = get_option( $lock );
			if ( (int) $expires < time() ) { global $wpdb; $wpdb->delete( $wpdb->options, array( 'option_name' => $lock, 'option_value' => (string) $expires ) ); wp_cache_delete( $lock, 'options' ); }
			throw new RemoteException( 'An upstream token refresh is already running. Try again shortly.' );
		}
		try {
			$current = UpstreamConnections::get( $server->id, $user );
			if ( ! $current || $current['version'] !== $grant['version'] ) { throw new RemoteException( 'Your upstream connection changed. Try again.' ); }
			$data = $grant['data'];
			$tokens = self::token_request( $data['metadata']['token_endpoint'], array( 'grant_type' => 'refresh_token', 'refresh_token' => $data['refresh_token'], 'client_id' => $data['client_id'], 'resource' => UpstreamConnections::config( $server )['endpoint'] ), $data['client_secret'] ?? '', $data['metadata'] );
			$live = ( new ServerManager() )->get( $server->id );
			if ( ! $live || ! UpstreamConnections::can_connect( $live, $user ) || ( $data['config_hash'] ?? '' ) !== UpstreamConnections::config_hash( $live ) ) { throw new RemoteException( 'Upstream access or configuration changed during refresh.' ); }
			$data = array_merge( $data, $tokens );
			$version = UpstreamConnections::save( $server->id, $user, $data, $grant['version'] );
			return array( 'version' => $version, 'data' => $data );
		} finally { delete_option( $lock ); }
	}

	private static function token_request( string $url, array $params, string $secret, array $metadata ): array {
		$headers = array( 'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json' );
		$supported = $metadata['token_endpoint_auth_methods_supported'] ?? array( 'client_secret_basic' );
		if ( '' !== $secret ) {
			if ( in_array( 'client_secret_basic', $supported, true ) ) { $headers['Authorization'] = 'Basic ' . base64_encode( urlencode( $params['client_id'] ) . ':' . urlencode( $secret ) ); }
			elseif ( in_array( 'client_secret_post', $supported, true ) ) { $params['client_secret'] = $secret; }
			else { throw new RemoteException( 'The upstream requires an unsupported OAuth client authentication method.' ); }
		}
		$r = SafeHttp::request( $url, 'POST', $headers, http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
		$data = json_decode( $r['body'], true );
		if ( $r['status'] < 200 || $r['status'] >= 300 || ! is_array( $data ) || ! is_string( $data['access_token'] ?? null ) || '' === $data['access_token'] || 'bearer' !== strtolower( $data['token_type'] ?? '' ) || preg_match( '/[\r\n]/', $data['access_token'] ) ) { throw new RemoteException( 'Upstream OAuth failed. Reconnect your account; no shared credential was substituted.', -32001, array( 'connect_url' => UpstreamConnections::portal_url() ) ); }
		$result = array( 'access_token' => $data['access_token'], 'expires_at' => time() + max( 1, min( 604800, (int) ( $data['expires_in'] ?? 3600 ) ) ) );
		if ( isset( $data['refresh_token'] ) ) { if ( ! is_string( $data['refresh_token'] ) ) { throw new RemoteException( 'Invalid upstream refresh token.' ); } $result['refresh_token'] = $data['refresh_token']; }
		return $result;
	}

	public static function client_metadata( Server $server ): array {
		return array( 'client_id' => rest_url( 'getmcp/v1/upstreams/' . $server->uuid . '/client-metadata' ), 'client_name' => 'GetMCP Bridge', 'redirect_uris' => array( self::callback_url() ), 'grant_types' => array( 'authorization_code', 'refresh_token' ), 'response_types' => array( 'code' ), 'token_endpoint_auth_method' => 'none' );
	}

	private static function b64( string $value ): string { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
}
