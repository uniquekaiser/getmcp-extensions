<?php
/** Guarded, deliberately narrow 1.8.0 sign-in migration. */
namespace GetMCPExtensions;

final class Upgrade180 {
	private const ADAPTERS_180 = array(
		'\\GetMCP\\Core\\ServerManager' => array( 'compat/versions/1.8.0/includes/core/class-server-manager.php', '297a8916c6e50dd11b7eb97fa5be92464d581e33dbdcea3148f42226514659eb' ),
		'\\GetMCP\\Protocol\\JsonRpcRouter' => array( 'compat/versions/1.8.0/includes/protocol/class-json-rpc-router.php', 'aad5de890f9fb2f6ed8a0ca7c707770fd55f5a02a8712860b2ff9be92c6d24cb' ),
		'\\GetMCP\\Execution\\ToolExecutor' => array( 'compat/versions/1.8.0/includes/execution/class-tool-executor.php', '3a425e30af7c824a77e86817c3625a902e6474c824c64c8e75588bdff9429be6' ),
		'\\GetMCP\\Protocol\\ToolsCallHandler' => array( 'compat/versions/1.8.0/includes/protocol/class-tools-call-handler.php', '59ab605f61b814690af27777575fa95b3a7fe3340de896be648cbf77c95c2124' ),
		'\\GetMCP\\Api\\ServersController' => array( 'compat/versions/1.8.0/includes/api/class-servers-controller.php', '0f79a5f1d234edb330bcc9cda6dc76522177c4089654685c1c9e3c7f6f87f7cf' ),
		'\\GetMCP\\Builtin\\BuiltinTools' => array( 'compat/versions/1.8.0/includes/builtin/class-builtin-tools.php', '1f2769370078257aa02fdbe505e942780f0557b6718413c66d04721568f9dfa8' ),
	);
    /** @wp-test feature=upgrade.180 scenario=preview-refusal */
    public static function preview( int $id ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'upgrade_forbidden', 'You cannot review this migration.', array( 'status' => 403 ) ); }
        return self::inspect( $id );
    }

    /**
     * Convert one eligible standalone sign-in, preserving the remaining row.
     * All dependent reads happen inside a serializable InnoDB transaction.
     * @wp-test feature=upgrade.180 scenario=standalone-signin
     */
    public static function migrate( int $id, string $expected ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'upgrade_forbidden', 'You cannot run this migration.', array( 'status' => 403 ) ); }
        if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $expected ) ) { return new \WP_Error( 'upgrade_revision_required', 'Review this server before migrating it.', array( 'status' => 400 ) ); }
        if ( ! self::transactional_tables() ) { return new \WP_Error( 'upgrade_storage_unsupported', 'Migration requires transactional database tables.', array( 'status' => 409 ) ); }
        if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new \WP_Error( 'upgrade_storage_error', 'Could not start a safe migration transaction.', array( 'status' => 500 ) );
        }
        try {
            $review = self::inspect( $id );
            if ( is_wp_error( $review ) ) { $wpdb->query( 'ROLLBACK' ); return $review; }
            if ( 'eligible' !== $review['state'] || ! hash_equals( $review['revision'], $expected ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'upgrade_conflict', 'The server is no longer eligible or changed since review.', array( 'status' => 409 ) );
            }
            // MariaDB's ON UPDATE timestamp must not rewrite unrelated metadata.
            $current = $wpdb->get_row( $wpdb->prepare( "SELECT updated_at FROM {$wpdb->prefix}getmcp_servers WHERE id = %d", $id ), ARRAY_A );
            if ( ! is_array( $current ) || ! array_key_exists( 'updated_at', $current ) ) { throw new \RuntimeException( 'The server could not be rechecked.' ); }
            $updated = $wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'wordpress', 'updated_at' => $current['updated_at'] ), array( 'id' => $id, 'server_kind' => 'native', 'kind' => 'standard', 'auth_type' => 'oauth' ) );
            if ( 1 !== $updated ) { throw new \RuntimeException( 'The server could not be updated.' ); }
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'The migration could not be committed.' ); }
            wp_cache_delete( 'getmcp_server_' . $id );
            return array( 'id' => $id, 'state' => 'migrated' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'upgrade_failed', 'Migration could not be confirmed. Review the server again.', array( 'status' => 500 ) );
        }
    }

    private static function inspect( int $id ) {
        global $wpdb;
        if ( $id <= 0 ) { return new \WP_Error( 'upgrade_invalid_id', 'A positive server ID is required.', array( 'status' => 400 ) ); }
        $server = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE id = %d", $id ), ARRAY_A );
        if ( ! is_array( $server ) ) { return new \WP_Error( 'upgrade_not_found', 'Server not found.', array( 'status' => 404 ) ); }
        $reviewed = ! is_multisite() && self::reviewed_core() && '1.35' === get_option( 'getmcp_db_version' );
        $enabled = self::option( 'getmcp_extensions_deactivated' );
        $switches = self::option( 'getmcp_extensions_settings' );
        $global_gateway = self::option( 'getmcp_gateway_enabled' );
        $store = self::option( Upgrade180Store::OPTION );
        $gateway_rows = self::exists( "SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_servers WHERE server_kind = 'gateway' OR kind = 'bundle') + (SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_bundle_members)" );
        $gateway_risk = false === $global_gateway || '0' === $global_gateway || 0 === $global_gateway ? $gateway_rows : true;
        $native_grants = self::exists( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_upstream_connections WHERE server_id = %d", $id ) );
        $legacy_grants = Upgrade180Store::SEPARATE === $store ? self::exists( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_extensions_upstream_connections WHERE server_id = %d", $id ) ) : ( false === $store ? false : null );
        $any_grants = true === $native_grants || true === $legacy_grants ? true : ( false === $native_grants && false === $legacy_grants ? false : null );
        $features = false === $switches ? array() : ( is_array( $switches ) ? ( $switches['features'] ?? null ) : null );
        $context = array(
            'reviewed_core' => $reviewed,
            'oauth_enabled' => false === $enabled && is_array( $features ) && ( ! array_key_exists( 'native_oauth', $features ) || true === $features['native_oauth'] ),
            // Any legacy gateway conservatively blocks conversion until delegation is compatible.
            'gateway_member' => $gateway_risk,
            'oauth_clients' => self::exists( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_oauth_clients WHERE server_id = %d", $id ) ),
            'upstream_grants' => $any_grants,
            'has_content' => self::exists( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_tools WHERE server_id = %d) + (SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_resources WHERE server_id = %d) + (SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_prompts WHERE server_id = %d)", $id, $id, $id ) ),
            'oauth_grants' => null,
            'eligible_users' => false,
        );
        if ( $reviewed ) {
            $native = ( new \GetMCP\Core\ServerManager() )->get( $id );
            if ( $native ) {
                $resource = \GetMCP\Auth\FirstPartyOAuth::canonical_resource( $native );
                if ( is_string( $resource ) && '' !== $resource ) {
                    $context['oauth_grants'] = self::exists( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_oauth_tokens WHERE resource = %s OR client_id IN (SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE server_id = %d)", $resource, $id ) );
                }
            }
        }
        $auth = json_decode( $server['auth_config'] ?? '', true );
        $ids = is_array( $auth ) ? ( $auth['allowed_user_ids'] ?? null ) : null;
        if ( is_array( $ids ) && array_is_list( $ids ) ) {
            $context['eligible_users'] = true;
            foreach ( $ids as $user ) {
                if ( ! is_int( $user ) || $user <= 0 || ! get_userdata( $user ) || ! user_can( $user, 'read' ) ) { $context['eligible_users'] = false; break; }
            }
        }
        $plan = Upgrade180Plan::classify( $server, $context );
        $plan['id'] = $id;
        $plan['revision'] = hash_hmac( 'sha256', wp_json_encode( array( $server, $context ) ), wp_salt( 'auth' ) );
        return $plan;
    }

    /** Returns null on a failed query, which the planner treats as unknown. */
    private static function exists( string $query ): ?bool {
        global $wpdb;
        $value = $wpdb->get_var( $query );
        return null === $value ? null : (int) $value > 0;
    }

    /** Read options directly so migration checks participate in the transaction. */
    private static function option( string $name ) {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( '' !== $wpdb->last_error ) { return null; }
        return null === $value ? false : maybe_unserialize( $value );
    }

    private static function transactional_tables(): bool {
        global $wpdb;
        $tables = array( $wpdb->options, $wpdb->prefix . 'getmcp_servers', $wpdb->prefix . 'getmcp_bundle_members', $wpdb->prefix . 'getmcp_tools', $wpdb->prefix . 'getmcp_resources', $wpdb->prefix . 'getmcp_prompts', $wpdb->prefix . 'getmcp_oauth_clients', $wpdb->prefix . 'getmcp_oauth_tokens', $wpdb->prefix . 'getmcp_upstream_connections' );
        if ( Upgrade180Store::SEPARATE === self::option( Upgrade180Store::OPTION ) ) { $tables[] = $wpdb->prefix . Upgrade180Store::LEGACY_SUFFIX; }
        foreach ( $tables as $table ) {
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
            if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) { return false; }
        }
        return true;
    }

    public static function reviewed_core_files(): bool {
        if ( ! defined( 'GETMCP_PATH' ) || ! defined( 'GETMCP_VERSION' ) || '1.8.0' !== GETMCP_VERSION ) { return false; }
        $manifest_file = GETMCP_EXTENSIONS_PATH . 'compatibility-1.8-migration.json';
        $manifest = is_file( $manifest_file ) ? json_decode( file_get_contents( $manifest_file ), true ) : null;
        if ( ! is_array( $manifest ) || count( $manifest ) !== 14 ) { return false; }
        foreach ( $manifest as $file => $expected ) {
            $path = GETMCP_PATH . $file;
            if ( ! is_file( $path ) || ! hash_equals( $expected, hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( $path ) ) ) ) ) { return false; }
        }
        return true;
    }

	public static function reviewed_core(): bool {
        if ( ! self::reviewed_core_files() ) { return false; }
        foreach ( array(
            '\\GetMCP\\Core\\ServerManager' => 'includes/core/class-server-manager.php',
            '\\GetMCP\\Core\\Server' => 'includes/core/class-server.php',
            '\\GetMCP\\Auth\\ServerUserAccess' => 'includes/auth/class-server-user-access.php',
            '\\GetMCP\\Auth\\FirstPartyOAuth' => 'includes/auth/class-first-party-oauth.php',
            '\\GetMCP\\Auth\\McpAuth' => 'includes/auth/class-mcp-auth.php',
            '\\GetMCP\\Auth\\OAuthProvider' => 'includes/auth/class-oauth-provider.php',
            '\\GetMCP\\Auth\\OAuthProxy' => 'includes/auth/class-oauth-proxy.php',
            '\\GetMCP\\Gateway\\McpGateway' => 'includes/gateway/class-mcp-gateway.php',
        ) as $class => $file ) {
            if ( ! class_exists( $class ) ) { return false; }
            $loaded = realpath( ( new \ReflectionClass( $class ) )->getFileName() );
            $expected = realpath( GETMCP_PATH . $file );
			if ( '\\GetMCP\\Core\\ServerManager' === $class && 'vendor-original-1.8.0' === Runtime::profile() ) {
				if ( ! self::reviewed_adapter( $class ) ) { return false; }
				continue;
			}
            if ( ! $expected || $loaded !== $expected ) { return false; }
        }
		if ( 'vendor-original-1.8.0' === Runtime::profile() && ! self::reviewed_adapter( '\\GetMCP\\Protocol\\JsonRpcRouter' ) ) { return false; }
		if ( 'vendor-original-1.8.0' === Runtime::profile() && ! self::reviewed_adapter( '\\GetMCP\\Execution\\ToolExecutor' ) ) { return false; }
		if ( 'vendor-original-1.8.0' === Runtime::profile() && ! self::reviewed_adapter( '\\GetMCP\\Protocol\\ToolsCallHandler' ) ) { return false; }
		if ( 'vendor-original-1.8.0' === Runtime::profile() && ! self::reviewed_adapter( '\\GetMCP\\Api\\ServersController' ) ) { return false; }
		if ( 'vendor-original-1.8.0' === Runtime::profile() && ! self::reviewed_adapter( '\\GetMCP\\Builtin\\BuiltinTools' ) ) { return false; }
        return true;
    }

	private static function reviewed_adapter( string $class ): bool {
		if ( ! isset( self::ADAPTERS_180[$class] ) || ! class_exists( $class ) ) { return false; }
		list( $relative, $expected_hash ) = self::ADAPTERS_180[$class];
		$path = GETMCP_EXTENSIONS_PATH . $relative;
		if ( ! is_file( $path ) || realpath( ( new \ReflectionClass( $class ) )->getFileName() ) !== realpath( $path ) ) { return false; }
		return hash_equals( $expected_hash, hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( $path ) ) ) );
	}

	/** Verify the mapped adapter bytes before any replacement autoloader is registered. */
	public static function reviewed_adapter_files( array $classes ): bool {
		foreach ( self::ADAPTERS_180 as $class => $adapter ) {
			list( $relative, $expected_hash ) = $adapter;
			if ( ( $classes[ltrim( $class, '\\' )] ?? null ) !== $relative ) { return false; }
			$path = GETMCP_EXTENSIONS_PATH . $relative;
			if ( ! is_file( $path ) || ! hash_equals( $expected_hash, hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( $path ) ) ) ) ) { return false; }
		}
		return true;
	}
}
