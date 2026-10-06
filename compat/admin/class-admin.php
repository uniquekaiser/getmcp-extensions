<?php
/**
 * WordPress admin integration.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Admin;

/**
 * Registers the admin menu, enqueues scripts/styles, and mounts
 * the React single-page application.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Redirect the activating admin into the plugin on first activation.
	 *
	 * Consumes the transient set by Activator::activate() so the redirect
	 * fires exactly once, on the page load right after activation. Lands on
	 * the onboarding wizard when it hasn't been completed yet (fresh
	 * installs), or the dashboard otherwise (re-activations). Skipped for
	 * bulk activations, AJAX requests, and users who can't manage servers —
	 * in those cases the transient simply expires.
	 *
	 * @since 1.4.0
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'getmcp_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'getmcp_activation_redirect' );

		if (
			wp_doing_ajax()
			|| is_network_admin()
			|| isset( $_GET['activate-multi'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ! current_user_can( 'getmcp_manage_servers' )
		) {
			return;
		}

		$target = admin_url( 'admin.php?page=getmcp' );
		if ( ! (bool) get_option( 'getmcp_onboarding_complete', false ) ) {
			$target .= '#/onboarding';
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Register the admin menu page.
	 *
	 * @since 1.0.0
	 */
	public function register_menu(): void {
		$hook = add_menu_page(
			__( 'GetMCP', 'getmcp' ),
			__( 'GetMCP', 'getmcp' ),
			'getmcp_manage_servers',
			'getmcp',
			array( $this, 'render_app' ),
			$this->get_menu_icon(),
			30
		);

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'on_page_load' ) );
		}

		// Register submenu pages — all render the same React SPA root.
		$submenus = array(
			array(
				'title'      => __( 'Dashboard', 'getmcp' ),
				'capability' => 'getmcp_manage_servers',
				'slug'       => 'getmcp',
			),
			array(
				'title'      => __( 'Servers', 'getmcp' ),
				'capability' => 'getmcp_manage_servers',
				'slug'       => 'getmcp-servers',
			),
			array(
				'title'      => __( 'Manage with AI', 'getmcp' ),
				'capability' => 'getmcp_manage_servers',
				'slug'       => 'getmcp-mcp-server',
			),
			array(
				'title'      => __( 'Gateway', 'getmcp' ),
				'capability' => 'getmcp_manage_servers',
				'slug'       => 'getmcp-gateway',
			),
			array(
				'title'      => __( 'Chats', 'getmcp' ),
				'capability' => \GetMCP\Api\ChatController::current_user_can_chat() ? 'getmcp_manage_servers' : 'do_not_allow',
				'slug'       => 'getmcp-chats',
			),
			array(
				'title'      => __( 'Templates', 'getmcp' ),
				'capability' => 'getmcp_manage_servers',
				'slug'       => 'getmcp-templates',
			),
			array(
				'title'      => __( 'Analytics', 'getmcp' ),
				'capability' => 'getmcp_view_analytics',
				'slug'       => 'getmcp-analytics',
			),
			array(
				'title'      => __( 'Logs', 'getmcp' ),
				'capability' => 'getmcp_view_analytics',
				'slug'       => 'getmcp-logs',
			),
			array(
				'title'      => __( 'Settings', 'getmcp' ),
				'capability' => 'getmcp_manage_settings',
				'slug'       => 'getmcp-settings',
			),
			array(
				'title'      => __( 'Help', 'getmcp' ),
				'capability' => 'getmcp_manage_servers',
				'slug'       => 'getmcp-help',
			),
			array(
				'title'      => __( 'System Status', 'getmcp' ),
				'capability' => 'getmcp_manage_settings',
				'slug'       => 'getmcp-system-status',
			),
			array(
				'title'      => __( 'License', 'getmcp' ),
				'capability' => 'manage_options',
				'slug'       => 'getmcp-license',
			),
		);

		foreach ( $submenus as $submenu ) {
			$sub_hook = add_submenu_page(
				'getmcp',
				$submenu['title'] . ' — GetMCP',
				$submenu['title'],
				$submenu['capability'],
				$submenu['slug'],
				array( $this, 'render_app' )
			);

			if ( false !== $sub_hook ) {
				add_action( 'load-' . $sub_hook, array( $this, 'on_page_load' ) );
			}
		}
	}

	/**
	 * Fires when the admin page loads.
	 *
	 * Enqueues scripts and styles only on our admin page.
	 *
	 * @since 1.0.0
	 */
	public function on_page_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_assets(): void {
		\GetMCP\Gateway\FeatureModule::enqueue_components();
		$asset_file = GETMCP_EXTENSIONS_PATH . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		// Make wp.media() available to the React app so the server-logo
		// picker can open the WordPress Media Library. Safe to call on
		// every admin page — WordPress dedupes internally.
		wp_enqueue_media();

		$asset = require $asset_file;

		// Vendor chunks produced by splitChunks must be loaded before the entry.
		$vendor_chunks  = array( 'vendor-react', 'vendor-libs' );
		$vendor_handles = array();

		foreach ( $vendor_chunks as $chunk ) {
			$chunk_asset_file = GETMCP_PATH . 'build/' . $chunk . '.asset.php';

			if ( ! file_exists( GETMCP_PATH . 'build/' . $chunk . '.js' ) ) {
				continue;
			}

			$chunk_deps    = array();
			$chunk_version = GETMCP_VERSION;

			if ( file_exists( $chunk_asset_file ) ) {
				$chunk_asset   = require $chunk_asset_file;
				$chunk_deps    = $chunk_asset['dependencies'] ?? array();
				$chunk_version = $chunk_asset['version'] ?? GETMCP_VERSION;
			}

			$handle = 'getmcp-' . $chunk;

			wp_enqueue_script(
				$handle,
				GETMCP_URL . 'build/' . $chunk . '.js',
				$chunk_deps,
				$chunk_version,
				true
			);

			$vendor_handles[] = $handle;
		}

		// Main React app script — depends on WP externals + vendor chunks.
		$main_deps = array_merge( $asset['dependencies'] ?? array(), $vendor_handles, array( 'getmcp-app-extension' ) );

		wp_enqueue_script(
			'getmcp-admin',
			GETMCP_EXTENSIONS_URL . 'build/index.js',
			$main_deps,
			$asset['version'] ?? GETMCP_VERSION,
			true
		);

		// Main stylesheet (Tailwind + custom).
		wp_enqueue_style(
			'getmcp-admin',
			GETMCP_URL . 'build/index.css',
			array(),
			$asset['version'] ?? GETMCP_VERSION
		);

		// Admin fonts: DM Sans + JetBrains Mono, bundled locally to
		// avoid contacting Google Fonts CDN (privacy / GDPR / WP.org
		// guideline 7 — no external services without user consent).
		wp_enqueue_style(
			'getmcp-fonts',
			GETMCP_URL . 'assets/css/fonts.css',
			array(),
			GETMCP_VERSION
		);

		// Pass configuration data to the React app.
		wp_localize_script(
			'getmcp-admin',
			'getmcpAdmin',
			$this->get_js_config()
		);

		// Enable wp.apiFetch nonce handling.
		wp_set_script_translations( 'getmcp-admin', 'getmcp' );
	}

	/**
	 * Render the React app mount point.
	 *
	 * @since 1.0.0
	 */
	public function render_app(): void {
		// Map WordPress admin page slugs to React hash routes.
		$page_hash_map = array(
			'getmcp'               => '/dashboard',
			'getmcp-servers'       => '/servers',
			'getmcp-mcp-server'    => '/mcp-server',
			'getmcp-gateway'       => '/gateway',
			'getmcp-connections'   => '/connections',
			'getmcp-my-connections' => '/my-connections',
			'getmcp-chats'         => '/chats',
			'getmcp-templates'     => '/templates',
			'getmcp-analytics'     => '/analytics',
			'getmcp-logs'          => '/logs',
			'getmcp-settings'      => '/settings',
			'getmcp-help'          => '/help',
			'getmcp-system-status' => '/system-status',
			'getmcp-license'       => '/license',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'getmcp';
		$target_hash  = $page_hash_map[ $current_page ] ?? '/dashboard';

		echo '<div class="getmcp-wrap">';
		echo '<div id="getmcp-admin-root"></div>';
		echo '</div>';

		// Sync the WordPress page parameter with React hash route on first load.
		if ( 'getmcp' !== $current_page ) {
			echo '<script>if(!window.location.hash)window.location.hash="' . esc_js( $target_hash ) . '";</script>';
		}
	}

	/**
	 * Return a valid IANA timezone name for the current WordPress timezone.
	 *
	 * WordPress lets admins set either a city-based IANA timezone ("Asia/Kolkata")
	 * or a raw UTC offset ("+05:30"). Only IANA names are accepted by the
	 * JavaScript Intl.DateTimeFormat API. When an offset is configured, this
	 * method converts it to the closest IANA equivalent.
	 *
	 * @since  1.0.0
	 * @return string IANA timezone identifier, e.g. "Asia/Kolkata" or "Etc/GMT-5".
	 */
	private function get_iana_timezone(): string {
		// When the admin has selected a city/region, timezone_string is set
		// to a proper IANA identifier like "America/New_York" — use it directly.
		$tz_string = get_option( 'timezone_string', '' );
		if ( ! empty( $tz_string ) ) {
			return $tz_string;
		}

		// UTC offset mode (e.g. "UTC+5:30" selected in WP settings).
		// gmt_offset holds the float offset, e.g. 5.5 for +05:30.
		$gmt_offset = (float) get_option( 'gmt_offset', 0 );
		$offset_sec = (int) round( $gmt_offset * HOUR_IN_SECONDS );

		// PHP can often resolve an offset to an IANA timezone name.
		$tz_name = timezone_name_from_abbr( '', $offset_sec, 0 );
		if ( ! empty( $tz_name ) && 'UTC' !== $tz_name ) {
			return $tz_name;
		}

		// Whole-hour offsets map cleanly to Etc/GMT (sign is inverted there).
		if ( 0 === $offset_sec % HOUR_IN_SECONDS ) {
			$hours = (int) ( $offset_sec / HOUR_IN_SECONDS );
			if ( 0 === $hours ) {
				return 'UTC';
			}
			return 'Etc/GMT' . ( $hours > 0 ? '-' . $hours : '+' . abs( $hours ) );
		}

		return 'UTC';
	}

	/**
	 * Get the JavaScript configuration object.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed> Configuration data.
	 */
	/**
	 * The newer GetMCP version WordPress already knows about, if any.
	 *
	 * Reads core's update_plugins transient rather than asking the update
	 * server: this runs on every admin page load, and core refreshes that
	 * transient on its own schedule anyway.
	 *
	 * @since  1.4.0
	 * @return string|null Version string, or null when current.
	 */
	private function newer_version_available(): ?string {
		$updates = get_site_transient( 'update_plugins' );

		if ( ! is_object( $updates ) || empty( $updates->response ) || ! defined( 'GETMCP_FILE' ) ) {
			return null;
		}

		$row = $updates->response[ plugin_basename( GETMCP_FILE ) ] ?? null;

		if ( ! is_object( $row ) || empty( $row->new_version ) ) {
			return null;
		}

		return version_compare( (string) $row->new_version, GETMCP_VERSION, '>' ) ? (string) $row->new_version : null;
	}

	private function get_js_config(): array {
		$settings = get_option( 'getmcp_settings', array() );

		// Initial tier snapshot. The SPA refreshes from /license/usage when
		// the License page Refresh button changes plan/expiry.
		$tier = \GetMCP\Licensing\LicenseTier::current()->to_array();

		// 14-day trial snapshot. is_active / is_expired already factor in
		// whether a license is attached — the SPA can rely on them directly
		// without rechecking the license state.
		$trial = array(
			'started_at' => \GetMCP\Licensing\Trial::started_at(),
			'days_left'  => \GetMCP\Licensing\Trial::days_left(),
			'is_active'  => \GetMCP\Licensing\Trial::is_active(),
			'is_expired' => \GetMCP\Licensing\Trial::is_expired(),
		);

		return array(
			'restUrl'      => esc_url_raw( rest_url( 'getmcp/v1/' ) ),
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
			'adminUrl'     => esc_url( admin_url() ),
			'pluginUrl'    => esc_url( GETMCP_URL ),
			'siteUrl'      => esc_url( home_url() ),
			// The endpoint we hand users to paste into an MCP client must be the
			// address *those clients* can reach, which is not always the address
			// WordPress thinks it lives at. `siteUrl` above stays home_url()
			// because it labels this WordPress install in the admin UI.
			'mcpEndpoint'  => esc_url( \GetMCP\Utils\PublicUrl::to( 'mcp' ) . '/' ),
			'version'      => GETMCP_VERSION,
			// From WordPress's own update data — zero extra requests at boot.
			// The sidebar shows a hint; System Status carries the detail.
			'updateAvailable' => $this->newer_version_available(),
			'isDebug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'timezone'     => $this->get_iana_timezone(),
			'onboarding'   => array(
				'completed' => (bool) get_option( 'getmcp_onboarding_complete', false ),
			),
			'host'         => array( 'kind' => 'wordpress' ),
			// WordPress has users and roles, so chat access can be delegated.
			'features'     => array(
				'chatAccessControl' => true,
				'mediaLibrary'      => true,
			),
			'capabilities' => array(
				'manage_servers'  => current_user_can( 'getmcp_manage_servers' ),
				'manage_tools'    => current_user_can( 'getmcp_manage_tools' ),
				'view_analytics'  => current_user_can( 'getmcp_view_analytics' ),
				'manage_settings' => current_user_can( 'getmcp_manage_settings' ),
				'use_chat'        => \GetMCP\Api\ChatController::current_user_can_chat(),
			),
			'tier'         => $tier,
			'trial'        => $trial,
			'systemInfo'   => array(
				'php_version'      => PHP_VERSION,
				'wp_version'       => get_bloginfo( 'version' ),
				'memory_limit'     => ini_get( 'memory_limit' ),
				'sodium_available' => extension_loaded( 'sodium' ),
			),
		);
	}

	/**
	 * Get the SVG icon for the admin menu.
	 *
	 * @since  1.0.0
	 * @return string Base64-encoded SVG data URI.
	 */
	private function get_menu_icon(): string {
		// All fills set to white (#ffffff) so WordPress can apply its own
		// admin colour scheme tinting via CSS, matching native WP menu icons.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="250" height="250" viewBox="0 0 250 250">'
			. '<defs>'
			. '<path d="M0,0 L191,0 L191,234.257519 L0,234.257519 L0,0 Z" id="p2"/>'
			. '<path d="M0,0 L45,0 L45,81 L0,81 L0,0 Z" id="p3"/>'
			. '<path d="M0,0 L75,0 L75,59 L0,59 L0,0 Z" id="p4"/>'
			. '<path d="M0,0 L76,0 L76,60 L0,60 L0,0 Z" id="p5"/>'
			. '<path d="M0,0 L45,0 L45,82 L0,82 L0,0 Z" id="p6"/>'
			. '</defs>'
			. '<g fill="none" fill-rule="evenodd">'
			. '<g transform="translate(30,8)">'
			. '<g transform="translate(1,0)">'
			. '<rect fill="#ffffff" x="132" y="109" width="59" height="15" rx="7.5"/>'
			. '<g transform="translate(81,146)" fill="#ffffff" fill-rule="evenodd">'
			. '<path d="M7.11780105,15.7356021 L38.117801,15.7356021 C41.9837943,15.7356021 45.117801,18.8696088 45.117801,22.7356021 C45.117801,26.6015953 41.9837943,29.7356021 38.117801,29.7356021 L7.11780105,29.7356021 C3.2518078,29.7356021 0.117801047,26.6015953 0.117801047,22.7356021 C0.117801047,18.8696088 3.2518078,15.7356021 7.11780105,15.7356021 Z" transform="translate(22.6178,22.7356) rotate(90) translate(-22.6178,-22.7356)"/>'
			. '<circle cx="22.617801" cy="58.4293194" r="22.617801"/>'
			. '</g>'
			. '<g transform="translate(5,47)" fill="#ffffff" fill-rule="evenodd">'
			. '<path d="M36.0967835,35.3997562 L70.0967835,35.3997562 C73.9627768,35.3997562 77.0967835,38.5337629 77.0967835,42.3997562 C77.0967835,46.2657494 73.9627768,49.3997562 70.0967835,49.3997562 L36.0967835,49.3997562 C32.2307903,49.3997562 29.0967835,46.2657494 29.0967835,42.3997562 C29.0967835,38.5337629 32.2307903,35.3997562 36.0967835,35.3997562 Z" transform="translate(53.0968,42.3998) rotate(30) translate(-53.0968,-42.3998)"/>'
			. '<circle cx="22.617801" cy="22.617801" r="22.617801"/>'
			. '</g>'
			. '<g transform="translate(5,126)" fill="#ffffff" fill-rule="evenodd">'
			. '<path d="M36.6238069,10.5970154 L71.4300896,10.5970154 C75.073434,10.5970154 78.0269482,13.5505297 78.0269482,17.1938741 C78.0269482,20.8372185 75.073434,23.7907327 71.4300896,23.7907327 L36.6238069,23.7907327 C32.9804624,23.7907327 30.0269482,20.8372185 30.0269482,17.1938741 C30.0269482,13.5505297 32.9804624,10.5970154 36.6238069,10.5970154 Z" transform="translate(54.0269,17.1939) rotate(149) translate(-54.0269,-17.1939)"/>'
			. '<circle cx="22.617801" cy="37.453647" r="22.617801"/>'
			. '</g>'
			. '<g transform="translate(81,7)" fill="#ffffff" fill-rule="evenodd">'
			. '<path d="M6.28196305,52.4573478 L39.0882458,52.4573478 C42.7315902,52.4573478 45.6851044,55.410862 45.6851044,59.0542064 C45.6851044,62.6975508 42.7315902,65.6510651 39.0882458,65.6510651 L6.28196305,65.6510651 C2.63861863,65.6510651 -0.314895587,62.6975508 -0.314895587,59.0542064 C-0.314895587,55.410862 2.63861863,52.4573478 6.28196305,52.4573478 Z" transform="translate(22.6851,59.0542) rotate(90) translate(-22.6851,-59.0542)"/>'
			. '<circle cx="22.617801" cy="22.617801" r="22.617801"/>'
			. '</g>'
			. '<circle stroke="#ffffff" stroke-width="14.6073298" cx="104" cy="117" r="29.6963351"/>'
			. '</g>'
			. '</g>'
			. '</g>'
			. '</svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
