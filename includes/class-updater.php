<?php
namespace GetMCPExtensions;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public release delivery is independent of connection credentials and feature switches. */
final class Updater {
    public const REPOSITORY = 'https://github.com/uniquekaiser/getmcp-extensions';
    private const SLUG = 'getmcp-extensions';
    private static $checker = null;

    public static function boot(): void {
        $root = dirname( __DIR__ );
        // DISTRIBUTION is a package-only marker; Git worktrees may have a .git file.
        if ( ! is_file( $root . '/DISTRIBUTION' ) || file_exists( $root . '/.git' ) || self::$checker ) { return; }
        if ( plugin_basename( $root . '/getmcp-extensions.php' ) !== self::SLUG . '/getmcp-extensions.php' ) { return; }
        $library = $root . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
        if ( ! is_file( $library ) ) { return; }
        require_once $library;
        self::$checker = PucFactory::buildUpdateChecker( self::REPOSITORY . '/', $root . '/getmcp-extensions.php', self::SLUG );
        self::$checker->setBranch( 'main' );
        self::$checker->getVcsApi()->enableReleaseAssets( '/^getmcp-extensions-\d+\.\d+\.\d+\.zip$/', 2 );
        self::$checker->addFilter( 'vcs_update_detection_strategies', static function( array $strategies ): array {
            return isset( $strategies['latest_release'] ) ? array( 'latest_release' => $strategies['latest_release'] ) : array();
        } );
        foreach ( array( 'request_info_result', 'request_update_result', 'pre_inject_info' ) as $hook ) {
            self::$checker->addFilter( $hook, array( self::class, 'normalize' ) );
        }
        self::$checker->addFilter( 'pre_inject_update', array( self::class, 'cached_update' ) );
        // Normalize both freshly written and cached WordPress update responses.
        add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'transient' ), 100 );
        add_filter( 'site_transient_update_plugins', array( self::class, 'transient' ), 100 );
        add_filter( 'plugins_api_result', array( self::class, 'details' ), 100, 3 );
    }

    public static function checker() { return self::$checker; }

    public static function valid_package_url( string $url, string $version ): bool {
        return preg_match( '/^\d+\.\d+\.\d+$/D', $version ) === 1
            && $url === self::REPOSITORY . '/releases/download/v' . $version . '/getmcp-extensions-' . $version . '.zip';
    }

    public static function normalize( $info ) {
        if ( ! is_object( $info ) || empty( $info->version ) || ! self::valid_package_url( (string) ( $info->download_url ?? '' ), (string) $info->version ) ) { return null; }
        if ( version_compare( $info->version, GETMCP_EXTENSIONS_VERSION, '<' ) ) { return null; }
        foreach ( array( 'requires' => '6.2', 'requires_php' => '8.2', 'tested' => '7.1.2', 'homepage' => self::REPOSITORY ) as $key => $fallback ) {
            if ( empty( $info->$key ) ) { $info->$key = $fallback; }
        }
        $info->icons = self::icons( is_array( $info->icons ?? null ) ? $info->icons : array() );
        if ( property_exists( $info, 'sections' ) ) {
            foreach ( $info->sections as $key => $html ) { $info->sections[$key] = self::section( (string) $html ); }
            $info->name = 'GetMCP Extensions';
            $info->author = 'Synergetic Dev';
            $info->author_homepage = 'https://synergetic.dev/';
        }
        return $info;
    }

    private static function section( string $html ): string {
        return preg_replace( '~(<li\b[^>]*>\s*)\[(NEW|IMPROVE|FIX|SECURITY|COMPAT|DEPRECATE|REMOVE|DEV|TRANSLATIONS)\]\s+~i', '$1<strong>[$2]</strong> ', wp_kses_post( $html ) );
    }

    public static function details( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== self::SLUG || is_wp_error( $result ) ) { return $result; }
        if ( ! is_object( $result ) || ! self::valid_package_url( (string) ( $result->download_link ?? '' ), (string) ( $result->version ?? '' ) ) ) {
            return new \WP_Error( 'getmcp_release_unavailable', 'Verified GetMCP Extensions release information is unavailable.' );
        }
        $result->icons = self::icons( is_array( $result->icons ?? null ) ? $result->icons : array() );
        $result->author = '<a href="https://synergetic.dev/">Synergetic Dev</a>';
        foreach ( (array) ( $result->sections ?? array() ) as $key => $html ) { $result->sections[$key] = self::section( (string) $html ); }
        return $result;
    }

    public static function cached_update( $update ) {
        $valid = self::normalize( $update );
        if ( $valid !== null || ! is_object( $update ) ) { return $valid; }
        // PUC converts the callback result without a null guard. Keep its typed object safe,
        // clear the stale state, and let our final transient validator remove the empty package.
        self::$checker->resetUpdateState();
        $update->download_url = ''; $update->version = GETMCP_EXTENSIONS_VERSION;
        return $update;
    }

    private static function icons( array $icons ): array {
        $base = 'https://raw.githubusercontent.com/uniquekaiser/getmcp-extensions/main/assets/';
        foreach ( array( '1x' => 'icon-128.png', '2x' => 'icon-256.png', 'default' => 'icon-256.png' ) as $key => $file ) {
            if ( empty( $icons[$key] ) ) { $icons[$key] = $base . $file; }
        }
        return $icons;
    }

    public static function transient( $transient ) {
        if ( ! is_object( $transient ) ) { return $transient; }
        $file = self::SLUG . '/getmcp-extensions.php';
        foreach ( array( 'response', 'no_update' ) as $group ) {
            if ( empty( $transient->$group[$file] ) ) { continue; }
            $row = $transient->$group[$file];
            if ( ! self::valid_package_url( (string) ( $row->package ?? '' ), (string) ( $row->new_version ?? '' ) ) ) {
                unset( $transient->$group[$file] ); continue;
            }
            $row->icons = self::icons( is_array( $row->icons ?? null ) ? $row->icons : array() );
            foreach ( array( 'requires' => '6.2', 'requires_php' => '8.2', 'tested' => '7.1.2' ) as $key => $fallback ) {
                if ( empty( $row->$key ) ) { $row->$key = $fallback; }
            }
        }
        return $transient;
    }
}
