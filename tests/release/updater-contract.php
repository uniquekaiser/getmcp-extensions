<?php
/** Owned WordPress fixture: authentic PUC/WP paths, synthetic GitHub metadata, no provider writes. */
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
use GetMCPExtensions\Updater;
$checker = Updater::checker();
if ( ! $checker ) { throw new RuntimeException( 'Packaged updater did not initialize.' ); }
$checks = array(); $assert = static function( $ok, $name ) use ( &$checks ) { $checks[$name] = (bool) $ok; if ( ! $ok ) { throw new RuntimeException( $name ); } };
$mode = 'valid'; $requests = array();
$filter = static function( $pre, $args, $url ) use ( &$mode, &$requests ) {
    if ( ! str_starts_with( $url, 'https://api.github.com/repos/uniquekaiser/getmcp-extensions/' ) ) { return $pre; }
    if ( isset( $args['headers']['Authorization'] ) || isset( $args['headers']['authorization'] ) ) { throw new RuntimeException( 'Public checker sent authentication.' ); }
    $requests[] = $url;
    $root = WP_PLUGIN_DIR . '/getmcp-extensions';
    if ( str_contains( $url, '/releases/latest' ) ) {
        $data = array( 'tag_name' => 'v1.2.2', 'draft' => false, 'prerelease' => false, 'created_at' => '2026-10-06T00:00:00Z',
            'zipball_url' => 'https://api.github.com/repos/uniquekaiser/getmcp-extensions/zipball/v1.2.2',
            'body' => "## 1.2.2\n- [FIX] Synthetic updater fixture.",
            'assets' => $mode === 'missing' ? array() : array( array( 'name' => $mode === 'wrong-name' ? 'other-1.2.2.zip' : 'getmcp-extensions-1.2.2.zip',
                'browser_download_url' => $mode === 'foreign' ? 'https://example.org/unrelated.zip' : Updater::REPOSITORY . '/releases/download/v1.2.2/getmcp-extensions-1.2.2.zip', 'download_count' => 0 ) ) );
    } elseif ( str_contains( $url, '/contents/' ) ) {
        $name = basename( wp_parse_url( $url, PHP_URL_PATH ) );
        $content = file_get_contents( $root . '/' . $name );
        $content = str_replace( array( 'Version: ' . GETMCP_EXTENSIONS_VERSION, 'Stable tag: ' . GETMCP_EXTENSIONS_VERSION ), array( 'Version: 1.2.2', 'Stable tag: 1.2.2' ), $content );
        $data = array( 'encoding' => 'base64', 'content' => base64_encode( $content ) );
    } else { throw new RuntimeException( 'Unexpected source fallback: ' . $url ); }
    return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200 ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $filter, 10, 3 );
try {
    $info = $checker->requestInfo();
    $assert( $info && $info->version === '1.2.2' && Updater::valid_package_url( $info->download_url, $info->version ), 'latest_release_exact_asset_selected' );
    $assert( $info->requires === '6.2' && $info->tested === '7.1.2' && $info->requires_php === '8.2', 'details_compatibility_complete' );
    $assert( str_contains( $info->author, 'Synergetic Dev' ) && ! empty( $info->icons['1x'] ) && ! empty( $info->icons['2x'] ), 'publisher_and_icons_present' );
    $assert( ! str_contains( $info->sections['changelog'], '- [FIX]' ) && str_contains( $info->sections['changelog'], '<li>' ), 'details_changelog_is_html' );
    $checker->checkForUpdates();
    $fresh = get_site_transient( 'update_plugins' );
    $file = 'getmcp-extensions/getmcp-extensions.php';
    $assert( isset( $fresh->response[$file] ) && $fresh->response[$file]->new_version === '1.2.2', 'real_wordpress_update_transient' );
    $count = count( $requests ); $cached = get_site_transient( 'update_plugins' );
    $assert( count( $requests ) === $count && $cached->response[$file]->package === $info->download_url, 'cached_metadata_preserves_valid_package_without_request' );
    $details = plugins_api( 'plugin_information', (object) array( 'slug' => 'getmcp-extensions' ) );
    $assert( ! is_wp_error( $details ) && $details->name === 'GetMCP Extensions' && $details->slug === 'getmcp-extensions', 'real_plugins_api_details' );
    $assert( ! empty( $details->icons['1x'] ) && ! empty( $details->icons['2x'] ), 'actual_details_icons_survive_wp_conversion' );
    $assert( str_contains( $details->sections['changelog'], '<strong>[FIX]</strong>' ), 'actual_details_categories_are_html' );
    $assert( substr_count( $details->author, '<a ' ) === 1 && str_contains( $details->author, 'https://synergetic.dev/' ), 'actual_details_single_publisher_link' );
    $again = Updater::details( $details, 'plugin_information', (object) array( 'slug' => 'getmcp-extensions' ) );
    $assert( ! str_contains( $again->sections['changelog'], '<strong><strong>' ), 'category_formatting_is_idempotent' );
    $other = (object) array( 'name' => 'Unrelated' );
    $assert( Updater::details( $other, 'plugin_information', (object) array( 'slug' => 'unrelated' ) ) === $other, 'unrelated_plugin_details_preserved' );
    $state = $checker->getUpdateState(); $badUpdate = clone $checker->getUpdate();
    $badUpdate->download_url = 'https://example.org/unrelated.zip';
    $state->setUpdate( $badUpdate )->save();
    $injected = Updater::transient( $checker->injectUpdate( (object) array( 'response' => array() ) ) );
    $assert( empty( $injected->response[$file] ), 'real_puc_invalid_cache_injection_has_no_fatal_or_package' );
    $assert( $state->getUpdate() === null, 'invalid_puc_cache_is_cleared' );
    foreach ( array( 'missing', 'wrong-name', 'foreign' ) as $failure ) {
        $mode = $failure; $assert( $checker->requestInfo() === null, $failure . '_release_has_no_source_archive_fallback' );
    }
    $bad = clone $info; $bad->download_url .= '?token=unwanted'; $assert( Updater::normalize( $bad ) === null, 'query_or_credential_package_url_rejected' );
    $bad = clone $info; $bad->version = '1.0.0'; $bad->download_url = Updater::REPOSITORY . '/releases/download/v1.0.0/getmcp-extensions-1.0.0.zip';
    $assert( Updater::normalize( $bad ) === null, 'downgrade_rejected' );
    $safe = clone $info; $safe->icons['1x'] = 'https://example.org/existing.png'; unset( $safe->icons['2x'] );
    $safe->sections['changelog'] = '<script>evil()</script><ul><li>[FIX] Fixture</li></ul>';
    $safe = Updater::normalize( $safe );
    $assert( $safe->icons['1x'] === 'https://example.org/existing.png' && ! empty( $safe->icons['2x'] ), 'existing_artwork_preserved_missing_icon_filled' );
    $assert( ! str_contains( $safe->sections['changelog'], '<script' ), 'details_script_removed' );
    $tampered = (object) array( 'response' => array( $file => (object) array( 'new_version' => '1.2.2', 'package' => 'https://example.org/source.zip' ) ) );
    $assert( empty( Updater::transient( $tampered )->response[$file] ), 'cached_foreign_package_rejected' );
    $assert( ! array_filter( $requests, static fn( $url ) => str_contains( $url, '/tags' ) || str_contains( $url, '/branches' ) || str_contains( $url, '/zipball/' ) ), 'no_tag_branch_or_source_requests' );
} finally { remove_filter( 'pre_http_request', $filter, 10 ); $checker->resetUpdateState(); delete_site_transient( 'update_plugins' ); }
file_put_contents( '/evidence/updater-contract.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT ) );
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ) );
