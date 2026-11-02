<?php
/** Narrow helpers for retaining add-on server identity on reviewed core versions. */
namespace GetMCPExtensions;

final class LegacyBridge {
	private static ?bool $reviewed_180 = null;

	private static function reviewed_180(): bool {
		if ( null === self::$reviewed_180 ) { self::$reviewed_180 = Upgrade180::reviewed_core(); }
		return self::$reviewed_180;
	}

	public static function is_180(): bool { return self::reviewed_180(); }

	/** Resolve the persisted add-on kind when native 1.8 does not hydrate it. */
	public static function kind( $server ): string {
		if ( ! is_object( $server ) ) { return 'unknown'; }
		if ( isset( $server->server_kind ) && is_string( $server->server_kind ) ) {
			return in_array( $server->server_kind, array( 'native', 'remote-mcp', 'gateway' ), true ) ? $server->server_kind : 'unknown';
		}
		if ( ! self::reviewed_180() || empty( $server->id ) ) { return 'unknown'; }
		global $wpdb;
		$kind = $wpdb->get_var( $wpdb->prepare( "SELECT server_kind FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", (int) $server->id ) );
		return in_array( $kind, array( 'native', 'remote-mcp', 'gateway' ), true ) ? $kind : 'unknown';
	}

	/** Preserve the same explicit allowlist check without introducing an admin bypass. */
	public static function can_user_access( int $user, $server ): bool {
		if ( ! $server instanceof \GetMCP\Core\Server ) { return false; }
		if ( Runtime::ready() && method_exists( '\\GetMCP\\Auth\\FirstPartyOAuth', 'can_user_access' ) ) {
			return \GetMCP\Auth\FirstPartyOAuth::can_user_access( $user, $server );
		}
		return self::reviewed_180() && \GetMCP\Auth\ServerUserAccess::can_user_access( $user, $server );
	}

	/** Tell 1.8's native tool executor when a call came through a gateway. */
	public static function native_handler( string $class, \GetMCP\Core\Server $server, ?string $session, bool $delegated ): object {
		if ( '\\GetMCP\\Protocol\\ToolsCallHandler' === $class && self::reviewed_180() ) {
			return new $class( $server, $session, $delegated );
		}
		return new $class( $server, $session );
	}
}
