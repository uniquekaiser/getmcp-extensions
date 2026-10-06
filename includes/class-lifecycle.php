<?php
/** Additive schema and non-destructive activation/deactivation. */
namespace GetMCPExtensions;
final class Lifecycle {
    public static function activate(): void {
        if ( is_multisite() ) { wp_die( 'GetMCP Extensions currently supports single-site WordPress only.' ); }
        if ( ! defined( 'GETMCP_PATH' ) || ! Runtime::inspect( GETMCP_PATH )['profile'] ) { wp_die( 'A reviewed GetMCP build and PHP 8.2+ are required. No GetMCP files were changed.' ); }
        $dir = WPMU_PLUGIN_DIR;
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { wp_die( 'Could not create the endpoint safety guard directory.' ); }
        $target = $dir . '/getmcp-extensions-guard.php';
        $source = file_get_contents( GETMCP_EXTENSIONS_PATH . 'includes/endpoint-guard.php' );
        if ( is_file( $target ) && ! str_contains( file_get_contents( $target ), 'getmcp-extensions-owned-guard:v1' ) ) { wp_die( 'An unrelated file occupies the endpoint guard path. It has not been overwritten.' ); }
        $temporary = $target . '.' . wp_generate_uuid4() . '.tmp';
        if ( file_put_contents( $temporary, $source, LOCK_EX ) !== strlen( $source ) || ! rename( $temporary, $target ) ) {
            if ( is_file( $temporary ) ) { unlink( $temporary ); }
            wp_die( 'Could not install the endpoint safety guard; activation stopped. The previous guard was preserved.' );
        }
        update_option( 'getmcp_extensions_guard_installed', 1, false );
        self::migrate();
        delete_option( 'getmcp_extensions_deactivated' );
    }
    public static function migrate(): void {
        global $wpdb;
        // Refresh only our own guard on an add-on update, before the schema early return.
        $target = WPMU_PLUGIN_DIR . '/getmcp-extensions-guard.php';
        $source = file_get_contents( GETMCP_EXTENSIONS_PATH . 'includes/endpoint-guard.php' );
        if ( is_file( $target ) && str_contains( file_get_contents( $target ), 'getmcp-extensions-owned-guard:v1' ) && hash_file( 'sha256', $target ) !== hash( 'sha256', $source ) ) {
            $temporary = $target . '.' . wp_generate_uuid4() . '.tmp';
            if ( file_put_contents( $temporary, $source, LOCK_EX ) !== strlen( $source ) || ! rename( $temporary, $target ) ) {
                if ( is_file( $temporary ) ) { unlink( $temporary ); }
                throw new \RuntimeException( 'Could not refresh the owned endpoint guard. Resolve filesystem permissions before enabling messaging.' );
            }
        }
        if ( get_option( 'getmcp_extensions_schema_version' ) === '1' ) { return; }
        $table = $wpdb->prefix . 'getmcp_servers';
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        if ( ! $columns ) { return; } // GetMCP's own activation/migration must create its tables first.
        if ( ! in_array( 'server_kind', $columns, true ) && false === $wpdb->query( "ALTER TABLE {$table} ADD server_kind VARCHAR(20) NOT NULL DEFAULT 'native'" ) ) { throw new \RuntimeException( 'Could not add the server kind column.' ); }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}getmcp_upstream_connections (
server_id BIGINT(20) UNSIGNED NOT NULL,
user_id BIGINT(20) UNSIGNED NOT NULL,
credentials LONGTEXT NOT NULL,
version VARCHAR(36) NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (server_id, user_id),
KEY user_id (user_id)
) {$charset};" );
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'getmcp_upstream_connections' ) ) ) ) { throw new \RuntimeException( 'Upstream account schema could not be created.' ); }
        update_option( 'getmcp_extensions_schema_version', '1', false );
    }
    public static function deactivate(): void {
        // No deletion or credential migration. Persistent MU guard refuses affected endpoints.
        update_option( 'getmcp_extensions_deactivated', 1, false );
    }
}
