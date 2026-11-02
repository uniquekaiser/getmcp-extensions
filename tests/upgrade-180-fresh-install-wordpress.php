<?php
/** Fresh 1.8 installation must never rewrite core's native grant store. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$previous_prefix = $wpdb->prefix; $previous_options = $wpdb->options;
$prefix = 'qa180fresh_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 ) . '_';
$source = $prefix . 'getmcp_upstream_connections'; $target = $prefix . 'getmcp_extensions_upstream_connections'; $options = $prefix . 'options';
$checks = 0; $check = static function( bool $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$reset_cache = static function(): void { foreach ( array( 'alloptions', 'notoptions', 'getmcp_extensions_schema_version', \GetMCPExtensions\Upgrade180Store::OPTION ) as $key ) { wp_cache_delete( $key, 'options' ); } };
try {
	$check( false !== $wpdb->query( "CREATE TABLE `{$options}` (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes', PRIMARY KEY(option_id), UNIQUE KEY option_name(option_name)) ENGINE=InnoDB" ), 'Synthetic options table created.' );
	$check( false !== $wpdb->query( "CREATE TABLE `{$source}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,server_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,credentials LONGTEXT NOT NULL,config_hash CHAR(64) NOT NULL,version CHAR(36) NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY server_user(server_id,user_id),KEY user_id(user_id)) ENGINE=InnoDB" ), 'Synthetic native grant table created.' );
	$check( 1 === $wpdb->insert( $source, array( 'server_id' => 91, 'user_id' => 92, 'credentials' => 'synthetic-native-ciphertext', 'config_hash' => str_repeat( 'a', 64 ), 'version' => 'synthetic-version' ) ), 'Synthetic native grant created.' );
	$before_schema = $wpdb->get_row( "SHOW CREATE TABLE `{$source}`", ARRAY_N )[1];
	$before_row = $wpdb->get_row( "SELECT * FROM `{$source}` WHERE server_id=91 AND user_id=92", ARRAY_A );
	$wpdb->prefix = $prefix; $wpdb->options = $options; $reset_cache();
	( new ReflectionMethod( \GetMCPExtensions\Lifecycle::class, 'prepare_180_store' ) )->invoke( null );
	$check( $before_schema === $wpdb->get_row( "SHOW CREATE TABLE `{$source}`", ARRAY_N )[1], 'Native grant schema is unchanged.' );
	$check( $before_row === $wpdb->get_row( "SELECT * FROM `{$source}` WHERE server_id=91 AND user_id=92", ARRAY_A ), 'Existing native grant is unchanged.' );
	$check( $target === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) ), 'Separate add-on grant table is created.' );
	$check( \GetMCPExtensions\Upgrade180Store::SEPARATE === get_option( \GetMCPExtensions\Upgrade180Store::OPTION ) && '1' === get_option( 'getmcp_extensions_schema_version' ), 'Separate store and schema markers are recorded.' );
	( new ReflectionMethod( \GetMCPExtensions\Lifecycle::class, 'prepare_180_store' ) )->invoke( null );
	$check( $before_row === $wpdb->get_row( "SELECT * FROM `{$source}` WHERE server_id=91 AND user_id=92", ARRAY_A ), 'Repeating fresh installation preserves the native grant.' );
	$wpdb->query( "DROP TABLE `{$target}`, `{$source}`" );
	delete_option( \GetMCPExtensions\Upgrade180Store::OPTION ); delete_option( 'getmcp_extensions_schema_version' ); $reset_cache();
	( new ReflectionMethod( \GetMCPExtensions\Lifecycle::class, 'prepare_180_store' ) )->invoke( null );
	$check( $target === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) ) && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $source ) ) ), 'Empty installation creates only the add-on-owned store.' );
	update_option( \GetMCPExtensions\Upgrade180Store::OPTION, 'unknown-marker' ); $before_owned = $wpdb->get_row( "SHOW CREATE TABLE `{$target}`", ARRAY_N )[1];
	$refused = false; try { ( new ReflectionMethod( \GetMCPExtensions\Lifecycle::class, 'prepare_180_store' ) )->invoke( null ); } catch ( \Throwable $e ) { $refused = true; }
	$check( $refused && 'unknown-marker' === get_option( \GetMCPExtensions\Upgrade180Store::OPTION ) && $before_owned === $wpdb->get_row( "SHOW CREATE TABLE `{$target}`", ARRAY_N )[1], 'An unexpected store marker is refused without modifying the table.' );
	$wpdb->query( "DROP TABLE `{$target}`" ); delete_option( \GetMCPExtensions\Upgrade180Store::OPTION ); delete_option( 'getmcp_extensions_schema_version' ); $reset_cache();
	$wpdb->query( "CREATE TABLE `{$source}` (server_id BIGINT UNSIGNED NOT NULL, unfamiliar_column TEXT NULL) ENGINE=InnoDB" );
	$bad_schema = $wpdb->get_row( "SHOW CREATE TABLE `{$source}`", ARRAY_N )[1];
	$refused = false; try { ( new ReflectionMethod( \GetMCPExtensions\Lifecycle::class, 'prepare_180_store' ) )->invoke( null ); } catch ( \Throwable $e ) { $refused = true; }
	$check( $refused && $bad_schema === $wpdb->get_row( "SHOW CREATE TABLE `{$source}`", ARRAY_N )[1] && false === get_option( \GetMCPExtensions\Upgrade180Store::OPTION, false ), 'An unknown native store shape is refused without rewriting or marking it.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic fresh installation' ) ) . PHP_EOL;
} finally {
	$wpdb->prefix = $previous_prefix; $wpdb->options = $previous_options; $reset_cache();
	if ( ! preg_match( '/\Aqa180fresh_[a-f0-9]{10}_\z/D', $prefix ) ) { throw new RuntimeException( 'Unexpected synthetic prefix.' ); }
	foreach ( array( $target, $source, $options ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); }
}
