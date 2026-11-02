<?php
/** Separate Extensions screens that use WordPress APIs instead of core's webpack internals. */
namespace GetMCPExtensions;

final class Standalone180 {
	public static function enqueue( string $page ): void {
		static $enqueued = false;
		if ( $enqueued || 'vendor-original-1.8.0' !== Runtime::profile() || ! Runtime::ready() || ! in_array( $page, array( 'connections', 'portal', 'catalogue', 'settings', 'providers' ), true ) ) { return; }
		$enqueued = true;
		$version = static fn( string $name ): string => substr( hash_file( 'sha256', GETMCP_EXTENSIONS_PATH . 'admin/' . $name ), 0, 16 );
		wp_enqueue_style( 'getmcp-extensions-180', GETMCP_EXTENSIONS_URL . 'admin/standalone-180.css', array(), $version( 'standalone-180.css' ) );
		wp_enqueue_style( 'getmcp-extensions-connections', GETMCP_EXTENSIONS_URL . 'admin/connections.css', array(), $version( 'connections.css' ) );
		wp_enqueue_script( 'getmcp-oauth-user-picker', GETMCP_EXTENSIONS_URL . 'admin/oauth-user-picker.js', array( 'wp-element' ), $version( 'oauth-user-picker.js' ), true );
		wp_enqueue_script( 'getmcp-connection-tools', GETMCP_EXTENSIONS_URL . 'admin/connection-tools.js', array( 'wp-element' ), $version( 'connection-tools.js' ), true );
		wp_enqueue_script( 'getmcp-remote-catalogue', GETMCP_EXTENSIONS_URL . 'admin/remote-catalogue.js', array( 'wp-element' ), $version( 'remote-catalogue.js' ), true );
		wp_enqueue_script( 'getmcp-native-connections', GETMCP_EXTENSIONS_URL . 'admin/native-connections.js', array( 'wp-element', 'getmcp-oauth-user-picker', 'getmcp-remote-catalogue' ), $version( 'native-connections.js' ), true );
		wp_enqueue_script( 'getmcp-extensions-settings', GETMCP_EXTENSIONS_URL . 'admin/extensions-settings.js', array( 'wp-element' ), $version( 'extensions-settings.js' ), true );
		wp_enqueue_script( 'getmcp-extensions-180', GETMCP_EXTENSIONS_URL . 'admin/standalone-180.js', array( 'wp-element', 'wp-api-fetch', 'getmcp-native-connections', 'getmcp-connection-tools', 'getmcp-extensions-settings' ), $version( 'standalone-180.js' ), true );
		$config = array(
			'page' => $page,
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'extensions' => Runtime::status() + array( 'connectionsUrl' => admin_url( 'admin.php?page=getmcp-extensions-connections' ), 'assetUrl' => GETMCP_EXTENSIONS_URL, 'settingsUrl' => rest_url( 'getmcp-extensions/v1/settings' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ),
			'catalogue' => array( 'catalogueUrl' => admin_url( 'admin.php?page=getmcp-extensions-remote-servers' ), 'connectionsUrl' => admin_url( 'admin.php?page=getmcp-extensions-connections' ), 'currentUserId' => get_current_user_id() ),
		);
		wp_add_inline_script( 'getmcp-extensions-180', 'window.getmcpExtensions180=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	public static function render( string $page ): void {
		self::enqueue( $page );
		echo '<div class="wrap"><div id="getmcp-extensions-180-notice" role="status" aria-live="polite"></div><div id="getmcp-extensions-180"><p>Loading…</p></div></div>';
	}
}
