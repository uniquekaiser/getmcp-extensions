<?php
/** Verify genuine published update metadata can be read without another HTTP request. */
if ( ! defined( 'ABSPATH' ) || wp_parse_url( home_url(), PHP_URL_HOST ) !== 'localhost' || ! in_array( (int) wp_parse_url( home_url(), PHP_URL_PORT ), array( 8917, 8918 ), true ) || ! get_option( 'getmcp_qa_paid_fixture' ) ) { throw new RuntimeException( 'Owned release fixture required.' ); }
$block = static function() { throw new RuntimeException( 'Cached update read attempted HTTP.' ); };
add_filter( 'pre_http_request', $block, -100 );
try {
    $state = get_site_transient( 'update_plugins' );
    $update = $state->response['getmcp-extensions/getmcp-extensions.php'] ?? null;
    if ( ! $update || $update->new_version !== '1.2.0' || ! GetMCPExtensions\Updater::valid_package_url( $update->package, $update->new_version ) || $update->requires !== '6.2' || $update->tested !== '7.1.2' || $update->requires_php !== '8.2' || empty( $update->icons['1x'] ) || empty( $update->icons['2x'] ) ) { throw new RuntimeException( 'Published cached update metadata is incomplete.' ); }
    echo wp_json_encode( array( 'passed' => true, 'version' => $update->new_version, 'http_requests' => 0, 'package' => $update->package, 'compatibility_and_icons_complete' => true ) );
} finally { remove_filter( 'pre_http_request', $block, -100 ); }
