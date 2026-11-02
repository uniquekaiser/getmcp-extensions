<?php
/** Split legacy personal grants from GetMCP 1.8's incompatible native store. */
namespace GetMCPExtensions;

final class Upgrade180Store {
    public const LEGACY_SUFFIX = 'getmcp_extensions_upstream_connections';
    public const NATIVE_SUFFIX = 'getmcp_upstream_connections';
    public const OPTION = 'getmcp_extensions_upstream_store';
    public const SEPARATE = 'separated-v1';
	private const NATIVE_SCHEMA_OPTION = 'getmcp_extensions_180_native_schema';

	/** Recover the owned table if a request stopped after moving rows but before recording the marker. */
	public static function legacy_table(): string {
		global $wpdb;
		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $wpdb->prefix ) ) { throw new \RuntimeException( 'Unsafe connection store prefix.' ); }
		$source = $wpdb->prefix . self::NATIVE_SUFFIX;
		$target = $wpdb->prefix . self::LEGACY_SUFFIX;
		if ( self::SEPARATE === get_option( self::OPTION ) ) { return $target; }
		if ( ! self::exists( $target ) ) { return $source; }
		if ( self::exists( $source ) ) {
			$columns = self::validate_table( $source, false );
			// A pre-upgrade table remains authoritative if a conflicting destination exists.
			if ( ! in_array( 'config_hash', $columns, true ) ) { return $source; }
			$remaining = $wpdb->get_var( "SELECT COUNT(*) FROM `{$source}` WHERE config_hash IS NULL OR config_hash = ''" );
			if ( null === $remaining || 0 !== (int) $remaining ) { throw new \RuntimeException( 'Connection storage separation is incomplete.' ); }
		}
		self::validate_table( $target, true );
		return $target;
	}

    /**
     * Run before core dbDelta. If the split cannot be proved, defer core's
     * schema migration on this request and keep the legacy endpoint guard.
     */
	/** @wp-test feature=upgrade.180 scenario=legacy-grant-preservation */
	public static function maybe_prepare(): void {
        if ( is_multisite() || get_option( 'getmcp_extensions_deactivated' ) || '1' !== get_option( 'getmcp_extensions_schema_version' ) || ! Upgrade180::reviewed_core_files() ) { return; }
        $result = LegacyGateway180::prepare();
		if ( ! is_wp_error( $result ) && self::SEPARATE === get_option( self::OPTION ) ) { return; }
        if ( ! is_wp_error( $result ) ) { $result = self::split_tables(); }
        if ( ! is_wp_error( $result ) && 'nothing' !== ( $result['state'] ?? '' ) ) {
            $prior = get_option( self::OPTION, false );
            if ( false === $prior ) { add_option( self::OPTION, self::SEPARATE, '', false ); }
            if ( self::SEPARATE === get_option( self::OPTION ) ) { return; }
        }
        if ( class_exists( '\\GetMCP\\GetMCP' ) ) { remove_action( 'init', array( \GetMCP\GetMCP::get_instance(), 'check_db_migration' ) ); }
        add_action( 'admin_notices', static function (): void {
			if ( current_user_can( 'manage_options' ) ) { echo '<div class="notice notice-error"><p>GetMCP Extensions could not safely prepare legacy Gateway access or connection storage. Core database migration was deferred for this request; existing credentials remain protected. Check the Gateway configuration and database permissions, then retry.</p></div>'; }
        } );
    }

	/** Repair only the exact 1.8-over-legacy primary-key shape after separation.
	 * @wp-test feature=upgrade.180 scenario=native-schema-recovery
	 * @wp-test feature=upgrade.180 scenario=native-schema-refusal
	 */
	public static function repair_native_schema() {
		global $wpdb;
		if ( ! is_string( $wpdb->prefix ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/D', $wpdb->prefix ) ) { return self::error(); }
		$table = $wpdb->prefix . self::NATIVE_SUFFIX;
		$lock = 'getmcp_ext_180_' . substr( hash( 'sha256', $wpdb->dbname . '|' . $wpdb->prefix ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return self::error(); }
		try {
			if ( ! self::exists( $table ) ) { return self::error(); }
			$columns = self::validate_table( $table, false );
			$required = array( 'server_id', 'user_id', 'credentials', 'version', 'updated_at', 'config_hash', 'created_at' );
			if ( array_diff( $required, $columns ) || array_diff( $columns, array_merge( $required, array( 'id' ) ) ) ) { return self::error(); }
			$details = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
			if ( ! is_array( $details ) || ! is_array( $indexes ) ) { return self::error(); }
			$by_name = array(); foreach ( $details as $column ) { $by_name[$column['Field']] = $column; }
			if ( 'char(64)' !== strtolower( $by_name['config_hash']['Type'] ?? '' ) || ! str_starts_with( strtolower( $by_name['created_at']['Type'] ?? '' ), 'datetime' ) ) { return self::error(); }
			foreach ( array( 'server_id', 'user_id', 'credentials', 'version', 'config_hash' ) as $required_column ) { if ( 'NO' !== ( $by_name[$required_column]['Null'] ?? null ) ) { return self::error(); } }
			foreach ( array( 'server_id', 'user_id' ) as $unsigned_column ) { if ( ! str_contains( strtolower( $by_name[$unsigned_column]['Type'] ), 'unsigned' ) ) { return self::error(); } }
			$keys = array(); $unique = array();
			foreach ( $indexes as $index ) { $name = $index['Key_name']; $keys[$name][(int) $index['Seq_in_index']] = $index['Column_name']; $unique[$name] = 0 === (int) $index['Non_unique']; }
			foreach ( $keys as &$parts ) { ksort( $parts ); $parts = array_values( $parts ); } unset( $parts );
			$legacy = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE config_hash IS NULL OR config_hash=''" );
			if ( null === $legacy || 0 !== (int) $legacy ) { return self::error(); }
			if ( in_array( 'id', $columns, true ) ) {
				$pair = false; foreach ( $keys as $name => $parts ) { if ( ! empty( $unique[$name] ) && array( 'server_id', 'user_id' ) === $parts ) { $pair = true; } }
				$id = $by_name['id'];
				return array( 'id' ) === ( $keys['PRIMARY'] ?? null ) && $pair && str_starts_with( strtolower( $id['Type'] ), 'bigint' ) && str_contains( strtolower( $id['Type'] ), 'unsigned' ) && 'NO' === $id['Null'] && str_contains( strtolower( $id['Extra'] ), 'auto_increment' ) ? true : self::error();
			}
			if ( array( 'server_id', 'user_id' ) !== ( $keys['PRIMARY'] ?? null ) || ( isset( $keys['server_user'] ) && ( array( 'server_id', 'user_id' ) !== $keys['server_user'] || empty( $unique['server_user'] ) ) ) ) { return self::error(); }
			$references = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=%s', $table ) );
			if ( null === $references || 0 !== (int) $references ) { return self::error(); }
			$pair = isset( $keys['server_user'] ) ? '' : ', ADD UNIQUE KEY server_user (server_id,user_id)';
			if ( false === $wpdb->query( "ALTER TABLE `{$table}` DROP PRIMARY KEY, ADD id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (id){$pair}" ) ) { return self::error(); }
			return true;
		} catch ( \Throwable $e ) { return self::error(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	/** Leave a failed or unknown schema untouched and retry later. */
	public static function maybe_repair_native_schema(): void {
		if ( is_multisite() || get_option( 'getmcp_extensions_deactivated' ) || ! Runtime::ready() ) { return; }
		if ( 'vendor-original-1.7.0' === Runtime::profile() ) { delete_option( self::NATIVE_SCHEMA_OPTION ); return; }
		if ( 'vendor-original-1.8.0' !== Runtime::profile() || '1.35' !== get_option( 'getmcp_db_version' ) || self::SEPARATE !== get_option( self::OPTION ) || 'ready-v1' === get_option( self::NATIVE_SCHEMA_OPTION ) || get_transient( 'getmcp_extensions_180_schema_retry' ) ) { return; }
		$result = self::repair_native_schema();
		if ( ! is_wp_error( $result ) ) { update_option( self::NATIVE_SCHEMA_OPTION, 'ready-v1', false ); return; }
		set_transient( 'getmcp_extensions_180_schema_retry', 1, 5 * MINUTE_IN_SECONDS );
		add_action( 'admin_notices', static function (): void { if ( current_user_can( 'manage_options' ) ) { echo '<div class="notice notice-warning"><p>GetMCP Extensions could not verify the native connection table layout. Existing connections have been retained; check the database before changing the table.</p></div>'; } } );
	}

    /**
     * Move ciphertext and versions without decrypting or overwriting either store.
     * The method derives both table names exclusively from the WordPress prefix.
     *
     * @return array|\WP_Error A secret-free result or a refusal.
     */
    public static function split_tables( bool $create_empty = false ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        if ( ! is_string( $prefix ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $prefix ) ) { return self::error(); }
        $source = $prefix . self::NATIVE_SUFFIX;
        $target = $prefix . self::LEGACY_SUFFIX;
        $lock = 'getmcp_ext_180_' . substr( hash( 'sha256', $wpdb->dbname . '|' . $prefix ), 0, 32 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 2 ) ) ) { return self::error(); }
        $in_transaction = false;
        try {
            $source_exists = self::exists( $source );
            $target_exists = self::exists( $target );
            if ( ! $source_exists && ! $target_exists ) {
				if ( ! $create_empty ) { return array( 'state' => 'nothing' ); }
				$charset = $wpdb->get_charset_collate();
				$created = $wpdb->query( "CREATE TABLE `{$target}` (server_id BIGINT(20) UNSIGNED NOT NULL,user_id BIGINT(20) UNSIGNED NOT NULL,credentials LONGTEXT NOT NULL,version VARCHAR(36) NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY (server_id,user_id),KEY user_id (user_id)) ENGINE=InnoDB {$charset}" );
				if ( false === $created ) { return self::error(); }
				self::validate_table( $target, true );
				return array( 'state' => 'created' );
			}
            if ( $target_exists ) { self::validate_table( $target, true ); }
            if ( ! $source_exists ) { return array( 'state' => 'already_separate' ); }
            $columns = self::validate_table( $source, false );
            if ( ! in_array( 'config_hash', $columns, true ) ) {
                // A second old-format table means an interrupted or conflicting
                // prior operation. Do not overwrite or guess which copy is newer.
                if ( $target_exists ) { return self::error(); }
                if ( false === $wpdb->query( "RENAME TABLE `{$source}` TO `{$target}`" ) ) { return self::error(); }
                return array( 'state' => 'renamed' );
            }
            if ( ! $target_exists ) {
                $charset = $wpdb->get_charset_collate();
                $created = $wpdb->query( "CREATE TABLE `{$target}` (server_id BIGINT(20) UNSIGNED NOT NULL,user_id BIGINT(20) UNSIGNED NOT NULL,credentials LONGTEXT NOT NULL,version VARCHAR(36) NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY (server_id,user_id),KEY user_id (user_id)) ENGINE=InnoDB {$charset}" );
                if ( false === $created ) { return self::error(); }
                self::validate_table( $target, true );
            }
            if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error(); }
            $in_transaction = true;
            $legacy = "(s.config_hash IS NULL OR s.config_hash = '')";
            $same = 'BINARY s.credentials = BINARY d.credentials AND BINARY s.version = BINARY d.version AND s.updated_at <=> d.updated_at';
            $different = $wpdb->get_var( "SELECT COUNT(*) FROM `{$source}` s INNER JOIN `{$target}` d ON d.server_id=s.server_id AND d.user_id=s.user_id WHERE {$legacy} AND NOT ({$same})" );
            if ( null === $different || (int) $different > 0 ) { throw new \RuntimeException( 'Conflicting legacy grant.' ); }
            $inserted = $wpdb->query( "INSERT INTO `{$target}` (server_id,user_id,credentials,version,updated_at) SELECT s.server_id,s.user_id,s.credentials,s.version,s.updated_at FROM `{$source}` s WHERE {$legacy} AND NOT EXISTS (SELECT 1 FROM `{$target}` d WHERE d.server_id=s.server_id AND d.user_id=s.user_id)" );
            if ( false === $inserted ) { throw new \RuntimeException( 'Could not copy legacy grants.' ); }
            $missing = $wpdb->get_var( "SELECT COUNT(*) FROM `{$source}` s LEFT JOIN `{$target}` d ON d.server_id=s.server_id AND d.user_id=s.user_id WHERE {$legacy} AND (d.server_id IS NULL OR NOT ({$same}))" );
            if ( null === $missing || (int) $missing > 0 ) { throw new \RuntimeException( 'Could not verify legacy grants.' ); }
            $deleted = $wpdb->query( "DELETE s FROM `{$source}` s INNER JOIN `{$target}` d ON d.server_id=s.server_id AND d.user_id=s.user_id WHERE {$legacy} AND {$same}" );
            if ( false === $deleted ) { throw new \RuntimeException( 'Could not complete the split.' ); }
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Could not confirm the split.' ); }
            $in_transaction = false;
            return array( 'state' => 'moved', 'moved' => (int) $deleted );
        } catch ( \Throwable $e ) {
            if ( $in_transaction ) { $wpdb->query( 'ROLLBACK' ); }
            return self::error();
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function exists( string $table ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Could not inspect the grant store.' ); }
        return $table === $found;
    }

    /** Check ownership assumptions before any rename or movement. */
    private static function validate_table( string $table, bool $owned ): array {
        global $wpdb;
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
        if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) || ! is_array( $columns ) || ! is_array( $indexes ) ) { throw new \RuntimeException( 'Unsupported grant store.' ); }
        $by_name = array(); foreach ( $columns as $column ) { $by_name[$column['Field']] = strtolower( $column['Type'] ); }
        foreach ( array( 'server_id', 'user_id', 'credentials', 'version', 'updated_at' ) as $name ) { if ( ! isset( $by_name[$name] ) ) { throw new \RuntimeException( 'Missing grant field.' ); } }
        if ( ! str_starts_with( $by_name['server_id'], 'bigint' ) || ! str_starts_with( $by_name['user_id'], 'bigint' ) || 'longtext' !== $by_name['credentials'] || ! in_array( $by_name['version'], array( 'varchar(36)', 'char(36)' ), true ) || ! str_starts_with( $by_name['updated_at'], 'datetime' ) ) { throw new \RuntimeException( 'Unexpected grant field type.' ); }
        if ( $owned && array_diff( array_keys( $by_name ), array( 'server_id', 'user_id', 'credentials', 'version', 'updated_at' ) ) ) { throw new \RuntimeException( 'Unexpected owned store fields.' ); }
        $keys = array();
        foreach ( $indexes as $index ) { if ( 0 === (int) $index['Non_unique'] ) { $keys[$index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name']; } }
        $unique_pair = false;
        foreach ( $keys as $parts ) { ksort( $parts ); if ( array_values( $parts ) === array( 'server_id', 'user_id' ) ) { $unique_pair = true; break; } }
        if ( ! $unique_pair ) { throw new \RuntimeException( 'Missing unique grant key.' ); }
        return array_keys( $by_name );
    }

    private static function error(): \WP_Error {
        return new \WP_Error( 'extensions_grant_split_failed', 'Legacy connection storage could not be separated safely. Existing data is retained; retry after checking the database.', array( 'status' => 503 ) );
    }
}
