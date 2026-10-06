<?php
/** Request-scoped delegation marker; gateway credentials never become API credentials. @package GetMCP */
namespace GetMCP\Gateway;

class Delegation {
	private static bool $active = false;
	public static function active(): bool { return self::$active; }
	public static function run( callable $callback ): array {
		$previous = self::$active; self::$active = true;
		$saved = array();
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW' ) as $key ) { if ( isset( $_SERVER[ $key ] ) ) { $saved[ $key ] = $_SERVER[ $key ]; unset( $_SERVER[ $key ] ); } }
		try { return $callback(); }
		finally { self::$active = $previous; foreach ( $saved as $key => $value ) { $_SERVER[ $key ] = $value; } }
	}
}
