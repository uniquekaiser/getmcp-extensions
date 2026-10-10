<?php
/** Test doubles only. Install solely in the disposable getmcp-native-wp fixture. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'plugins_loaded', function() {
	if ( ! get_option( 'getmcp_qa_paid_fixture', false ) || ! class_exists( 'GetMCP\\Licensing\\LicenseTier' ) ) { return; }
	$GLOBALS['getmcp_license'] = new class {
		public function get_status() { return 'active'; }
		public function get_license_data() { return array( 'plan' => 'Pro' ); }
	};
	\GetMCP\Licensing\LicenseTier::clear_cache();
}, PHP_INT_MAX );
add_filter( 'pre_http_request', function( $response, $args, $url ) {
	if ( false !== $response ) { return $response; }
	if ( ! str_starts_with( $url, 'https://example.org/getmcp-qa' ) && ! str_starts_with( $url, 'https://example.org/.well-known/' ) ) { return $response; }
	// The production HTTPS URL/SSRF guard still runs. Only the HTTP seam maps to an owned fixture.
	$args['redirection'] = 0; $args['reject_unsafe_urls'] = false;
	return wp_remote_request( 'http://127.0.0.1/qa-upstream.php?path=' . rawurlencode( wp_parse_url( $url, PHP_URL_PATH ) ), $args );
}, 10, 3 );
