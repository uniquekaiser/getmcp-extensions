<?php
/** Bounded, public HTTPS transport; never inherits inbound headers. @package GetMCP */
namespace GetMCP\Remote;

use GetMCP\Utils\UrlGuard;

class SafeHttp {

	public const MAX_BYTES = 10485760;

	public static function validate_url( string $url, bool $fetch = false ): void {
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || wp_parse_url( $url, PHP_URL_FRAGMENT ) ) {
			throw new RemoteException( 'Remote MCP connections require a public HTTPS URL without a fragment.' );
		}
		$reason = $fetch ? UrlGuard::validate_fetch_url( $url ) : UrlGuard::validate_configured_url( $url );
		if ( null !== $reason ) { throw new RemoteException( 'The remote connection URL is not permitted.' ); }
		// Remote MCP endpoints are always public HTTPS services. GetMCP 1.7
		// permits private hosts for self-hosted native connectors, so apply the
		// add-on's stricter public-endpoint rule independently of that setting.
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		// host_is_public() itself resolves all A/AAAA records and rejects an
		// unresolved name. Use that stable public API across GetMCP 1.6/1.7;
		// resolve_host() is private in 1.6 and public only in 1.7.
		if ( ! UrlGuard::host_is_public( $host ) ) {
			throw new RemoteException( 'The remote connection URL is not permitted.' );
		}
	}

	/** Redirects are deliberately refused: token endpoints and MCP URLs must be exact. */
	public static function request( string $url, string $method = 'GET', array $headers = array(), ?string $body = null ): array {
		self::validate_url( $url, true );
		self::validate_headers( $headers );
		$args = array( 'method' => $method, 'headers' => $headers, 'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true, 'limit_response_size' => self::MAX_BYTES + 1, 'cookies' => array() );
		if ( null !== $body ) { $args['body'] = $body; }
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { throw new RemoteException( 'The upstream connection failed or timed out. No operation was retried.' ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES ) { throw new RemoteException( 'The upstream response exceeded the 10 MiB limit.' ); }
		if ( $status >= 300 && $status < 400 ) { throw new RemoteException( 'Upstream redirects are not followed. Configure the final HTTPS endpoint.' ); }
		return array( 'status' => $status, 'body' => $body, 'headers' => wp_remote_retrieve_headers( $response ) );
	}

	public static function validate_headers( array $headers ): void {
		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name ) || ! is_string( $value ) || preg_match( '/[\r\n]/', $value ) ) {
				throw new RemoteException( 'Invalid upstream header.' );
			}
			if ( in_array( strtolower( $name ), array( 'host', 'cookie', 'connection', 'content-length', 'transfer-encoding', 'proxy-authorization' ), true ) ) {
				throw new RemoteException( 'That upstream header is reserved.' );
			}
		}
	}

	public static function json( string $url, string $method = 'GET', array $body = array(), array $headers = array() ): array {
		$headers['Accept'] = 'application/json';
		if ( 'GET' !== $method ) { $headers['Content-Type'] = 'application/json'; }
		$r = self::request( $url, $method, $headers, 'GET' === $method ? null : wp_json_encode( $body ) );
		$data = json_decode( $r['body'], true );
		if ( $r['status'] < 200 || $r['status'] >= 300 || ! is_array( $data ) ) { throw new RemoteException( 'The upstream metadata request failed.' ); }
		return $data;
	}
}
