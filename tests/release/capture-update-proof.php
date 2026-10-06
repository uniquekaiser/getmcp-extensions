<?php
/**
 * Capture the real WordPress plugin-update and details-modal response as JSON.
 *
 * Run in a loaded WordPress runtime:
 *   wp eval-file capture-update-proof.php plugin-directory/plugin.php
 * Or set SYN_RELEASE_PLUGIN before evaluating this file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this script inside a loaded WordPress runtime.\n" );
	exit( 2 );
}

$syn_release_plugin = '';
if ( isset( $args[0] ) && is_string( $args[0] ) ) {
	$syn_release_plugin = trim( $args[0] );
} elseif ( isset( $argv[1] ) && is_string( $argv[1] ) ) {
	$syn_release_plugin = trim( $argv[1] );
}
if ( '' === $syn_release_plugin ) {
	$syn_release_plugin = trim( (string) getenv( 'SYN_RELEASE_PLUGIN' ) );
}
if ( '' === $syn_release_plugin ) {
	fwrite( STDERR, "Supply plugin-directory/plugin.php as the first argument or SYN_RELEASE_PLUGIN.\n" );
	exit( 2 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/update.php';

wp_clean_plugins_cache( true );
delete_site_transient( 'update_plugins' );
// Force a fresh, real public GitHub request instead of relying on PUC scheduling.
GetMCPExtensions\Updater::checker()->checkForUpdates();
wp_update_plugins();

$syn_release_transient = get_site_transient( 'update_plugins' );
$syn_release_update    = null;

if ( is_object( $syn_release_transient ) ) {
	if ( isset( $syn_release_transient->response[ $syn_release_plugin ] ) ) {
		$syn_release_update = $syn_release_transient->response[ $syn_release_plugin ];
	} elseif ( isset( $syn_release_transient->no_update[ $syn_release_plugin ] ) ) {
		$syn_release_update = $syn_release_transient->no_update[ $syn_release_plugin ];
	}
}

$syn_release_update_data = is_object( $syn_release_update )
	? get_object_vars( $syn_release_update )
	: ( is_array( $syn_release_update ) ? $syn_release_update : array() );

$syn_release_slug = isset( $syn_release_update_data['slug'] )
	? sanitize_key( (string) $syn_release_update_data['slug'] )
	: sanitize_key( dirname( $syn_release_plugin ) );

if ( '.' === $syn_release_slug || '' === $syn_release_slug ) {
	$syn_release_slug = sanitize_key( basename( $syn_release_plugin, '.php' ) );
}

$syn_release_details = plugins_api(
	'plugin_information',
	array(
		'slug'   => $syn_release_slug,
		'fields' => array(
			'sections' => true,
			'icons'    => true,
			'banners'  => true,
		),
	)
);

if ( is_wp_error( $syn_release_details ) ) {
	$syn_release_details_data = array(
		'error'         => $syn_release_details->get_error_code(),
		'error_message' => $syn_release_details->get_error_message(),
	);
} else {
	$syn_release_details_data = is_object( $syn_release_details )
		? get_object_vars( $syn_release_details )
		: (array) $syn_release_details;
}

$syn_release_pick = static function ( array $source, array $keys ) {
	$output = array();
	foreach ( $keys as $key ) {
		if ( array_key_exists( $key, $source ) ) {
			$output[ $key ] = $source[ $key ];
		}
	}
	return $output;
};

$syn_release_update_output = $syn_release_pick(
	$syn_release_update_data,
	array( 'slug', 'plugin', 'version', 'new_version', 'requires', 'tested', 'requires_php', 'icons' )
);
if ( ! isset( $syn_release_update_output['version'] ) && isset( $syn_release_update_output['new_version'] ) ) {
	$syn_release_update_output['version'] = $syn_release_update_output['new_version'];
}

$syn_release_details_output = $syn_release_pick(
	$syn_release_details_data,
	array( 'name', 'slug', 'version', 'requires', 'tested', 'requires_php', 'sections', 'icons', 'banners' )
);

$syn_release_icon_probes = array();
$syn_release_icon_sets   = array(
	'update'  => isset( $syn_release_update_output['icons'] ) ? (array) $syn_release_update_output['icons'] : array(),
	'details' => isset( $syn_release_details_output['icons'] ) ? (array) $syn_release_details_output['icons'] : array(),
);

foreach ( $syn_release_icon_sets as $syn_release_surface => $syn_release_icons ) {
	foreach ( $syn_release_icons as $syn_release_key => $syn_release_url ) {
		$syn_release_url = esc_url_raw( (string) $syn_release_url );
		if ( '' === $syn_release_url ) {
			continue;
		}

		$syn_release_response = wp_remote_get(
			$syn_release_url,
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => 2048,
			)
		);

		$syn_release_status       = is_wp_error( $syn_release_response ) ? 0 : wp_remote_retrieve_response_code( $syn_release_response );
		$syn_release_content_type = is_wp_error( $syn_release_response )
			? ''
			: (string) wp_remote_retrieve_header( $syn_release_response, 'content-type' );
		$syn_release_content_type = strtolower( trim( strtok( $syn_release_content_type, ';' ) ?: '' ) );

		$syn_release_icon_probes[] = array(
			'surface'      => $syn_release_surface,
			'key'          => (string) $syn_release_key,
			'url'          => $syn_release_url,
			'status'       => $syn_release_status,
			'content_type' => $syn_release_content_type,
			'reachable'    => $syn_release_status >= 200
				&& $syn_release_status < 400
				&& 0 === strpos( $syn_release_content_type, 'image/' ),
			'error'        => is_wp_error( $syn_release_response )
				? $syn_release_response->get_error_message()
				: '',
		);
	}
}

$syn_release_payload = array(
	'plugin'       => $syn_release_plugin,
	'captured_at'  => gmdate( 'c' ),
	'wordpress'    => get_bloginfo( 'version' ),
	'update'       => $syn_release_update_output,
	'details'      => $syn_release_details_output,
	'icon_probes'  => $syn_release_icon_probes,
);

echo wp_json_encode( $syn_release_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
