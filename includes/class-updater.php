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
        foreach ( array( 'request_info_result', 'request_update_result', 'pre_inject_info', 'pre_inject_update' ) as $hook ) {
            self::$checker->addFilter( $hook, array( self::class, 'normalize' ) );
        }
        // Normalize both freshly written and cached WordPress update responses.
        add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'transient' ), 100 );
        add_filter( 'site_transient_update_plugins', array( self::class, 'transient' ), 100 );
        // plugins_api details are provided by the checker's validated info path.
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
            foreach ( $info->sections as $key => $html ) { $info->sections[$key] = wp_kses_post( (string) $html ); }
            $info->name = 'GetMCP Extensions';
            $info->author = '<a href="https://synergetic.dev/">Synergetic Dev</a>';
            $info->author_homepage = 'https://synergetic.dev/';
        }
        return $info;
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
