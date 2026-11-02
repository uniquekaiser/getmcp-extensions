<?php
/** Transactional sign-in migration for reviewed legacy gateway and remote records. */
namespace GetMCPExtensions;

final class Upgrade180Auto {
	public const LEDGER = 'getmcp_extensions_180_auth_migrations';

	/** @wp-test feature=upgrade.180 scenario=automatic-legacy-migration */
	public static function maybe_migrate(): void {
		if ( is_multisite() || ! Runtime::ready() || get_option( 'getmcp_extensions_deactivated' ) ) { return; }
		if ( 'vendor-original-1.8.0' === Runtime::profile() && '1.35' === get_option( 'getmcp_db_version' ) && Upgrade180Store::SEPARATE === get_option( Upgrade180Store::OPTION ) && Upgrade180::reviewed_core() ) {
			global $wpdb;
			$scope = self::scope();
			if ( is_wp_error( $scope ) || array() === $scope ) { return; }
			$where = self::scope_sql( $scope );
			$needs = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}getmcp_servers WHERE auth_type='oauth' AND server_kind IN ('native','remote-mcp','gateway') AND auth_config LIKE %s {$where} LIMIT 1", '%getmcp%' ) );
			if ( null !== $needs ) { self::convert(); }
		} elseif ( 'vendor-original-1.7.0' === Runtime::profile() ) {
			$raw = get_option( self::LEDGER, false );
			if ( is_string( $raw ) && '' !== $raw && '[]' !== $raw ) { self::restore_17(); }
		}
	}

	/** Make any safely deferred legacy endpoint visible without mutating it.
	 * @wp-test feature=upgrade.180 scenario=deferred-migration-notice
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Runtime::ready() || 'vendor-original-1.8.0' !== Runtime::profile() ) { return; }
		$scope = self::scope(); if ( is_wp_error( $scope ) || array() === $scope ) { return; }
		global $wpdb; $where = self::scope_sql( $scope );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,status,auth_config FROM {$wpdb->prefix}getmcp_servers WHERE auth_type='oauth' AND server_kind IN ('native','remote-mcp','gateway') AND auth_config LIKE %s {$where} ORDER BY name,id", '%getmcp%' ), ARRAY_A );
		if ( ! is_array( $rows ) ) { return; }
		$affected = array();
		foreach ( $rows as $row ) {
			$config = json_decode( (string) $row['auth_config'], true );
			if ( is_array( $config ) && 'getmcp' !== ( $config['provider'] ?? null ) ) { continue; }
			$affected[] = esc_html( (string) $row['name'] ) . ' (ID ' . (int) $row['id'] . ', ' . esc_html( (string) $row['status'] ) . ')';
		}
		if ( ! $affected ) { return; }
		$url = esc_url( admin_url( 'admin.php?page=getmcp-extensions' ) );
		echo '<div class="notice notice-warning"><p>Some legacy servers still need a safe migration. Their existing settings and credentials have been kept and affected endpoints remain protected. Affected servers: ' . implode( ', ', $affected ) . '. Migration is retried automatically; review their configuration and any pending authorization before making changes. <a href="' . $url . '">Open Extensions</a>.</p></div>';
	}

	/** Convert only the currently reviewed legacy remote/gateway sign-in shape. */
	public static function convert() {
		global $wpdb;
		if ( is_multisite() || ! Runtime::ready() || get_option( 'getmcp_extensions_deactivated' ) || 'vendor-original-1.8.0' !== Runtime::profile() || '1.35' !== get_option( 'getmcp_db_version' ) || Upgrade180Store::SEPARATE !== get_option( Upgrade180Store::OPTION ) || ! Upgrade180::reviewed_core() ) { return self::error(); }
		if ( ! self::transactional() ) { return self::error(); }
		$lock = 'getmcp-ext-auth180-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) { return self::error(); }
		$transaction = false;
		try {
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'Could not start migration.' ); }
			$transaction = true;
			$scope = self::scope();
			if ( is_wp_error( $scope ) ) { throw new \RuntimeException( 'Invalid migration scope.' ); }
			if ( array() === $scope ) { $wpdb->query( 'COMMIT' ); $transaction = false; return array( 'migrated' => 0 ); }
			$where = self::scope_sql( $scope );
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE auth_type='oauth' AND server_kind IN ('native','remote-mcp','gateway') {$where} FOR UPDATE", ARRAY_A );
			if ( ! is_array( $rows ) ) { throw new \RuntimeException( 'Could not inspect servers.' ); }
			$convert = array(); $candidates = array();
			foreach ( $rows as $row ) {
				$auth = json_decode( (string) $row['auth_config'], true );
				if ( ! is_array( $auth ) || 'getmcp' !== ( $auth['provider'] ?? null ) ) { continue; }
				$candidates[(int) $row['id']] = $row;
			}
			foreach ( $candidates as $id => $row ) {
				$auth = json_decode( (string) $row['auth_config'], true );
				if ( ! self::safe_server( $row, $auth, $candidates ) ) { continue; }
				$server = ( new \GetMCP\Core\ServerManager() )->get( (int) $row['id'] );
				if ( ! $server ) { throw new \RuntimeException( 'A legacy server disappeared.' ); }
				$resource = \GetMCP\Auth\FirstPartyOAuth::canonical_resource( $server );
				$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_oauth_tokens WHERE resource=%s AND BINARY resource=BINARY %s AND authorization_code_hash IS NOT NULL AND authorization_code_hash<>'' AND redirect_uri IS NULL AND expires_at>UTC_TIMESTAMP()", $resource, $resource ) );
				if ( null === $pending ) { throw new \RuntimeException( 'Could not inspect pending codes.' ); }
				if ( 0 !== (int) $pending ) { continue; }
				$convert[$id] = $row;
			}
			// A gateway is safe only if every legacy member being converted in
			// this batch also passed its own checks, including pending codes.
			foreach ( $convert as $id => $row ) {
				if ( 'gateway' !== $row['server_kind'] ) { continue; }
				$settings = json_decode( (string) $row['settings'], true );
				foreach ( $settings['gateway']['server_ids'] as $member_id ) {
					if ( isset( $candidates[$member_id] ) && ! isset( $convert[$member_id] ) ) { unset( $convert[$id] ); break; }
				}
			}
			if ( ! $convert ) {
				if ( $candidates ) { throw new \RuntimeException( 'Legacy servers need review.' ); }
				$wpdb->query( 'COMMIT' ); $transaction = false; return array( 'migrated' => 0, 'deferred' => 0 );
			}
			$ledger = self::locked_ledger();
			foreach ( $convert as $row ) {
				$ledger[(string) $row['id']] = 'oauth';
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}getmcp_servers SET auth_type='wordpress',updated_at=updated_at WHERE id=%d AND auth_type='oauth' AND server_kind=%s AND kind='standard' AND BINARY auth_config=BINARY %s", (int) $row['id'], $row['server_kind'], $row['auth_config'] ) );
				if ( 1 !== $updated ) { throw new \RuntimeException( 'A legacy server changed.' ); }
			}
			self::write_ledger( $ledger );
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Could not commit migration.' ); }
			$transaction = false;
			self::clear_cache( $convert );
			return array( 'migrated' => count( $convert ), 'deferred' => count( $candidates ) - count( $convert ) );
		} catch ( \Throwable $e ) {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
			return self::error();
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	private static function safe_server( array $row, array $auth, array $candidates ): bool {
		if ( 'standard' !== ( $row['kind'] ?? null ) || ! isset( $auth['allowed_user_ids'] ) || ! is_array( $auth['allowed_user_ids'] ) || ! array_is_list( $auth['allowed_user_ids'] ) ) { return false; }
		foreach ( $auth['allowed_user_ids'] as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return false; } }
		$raw_settings = json_decode( (string) $row['settings'] );
		$settings = json_decode( (string) $row['settings'], true );
		if ( ! is_object( $raw_settings ) || ! is_array( $settings ) ) { return false; }
		foreach ( array( 'getmcp_extensions_required', 'personal_provider', 'google_health' ) as $key ) { if ( array_key_exists( $key, $settings ) ) { return false; } }
		if ( 'native' === $row['server_kind'] ) {
			return Runtime::enabled( 'native_oauth' ) && self::safe_native_settings( $settings ) && self::safe_native_tools( (int) $row['id'], $settings ) && self::safe_header_variable_tools( (int) $row['id'], $settings );
		}
		if ( 'remote-mcp' === $row['server_kind'] ) {
			$remote = $settings['remote_mcp'] ?? null;
			return Runtime::enabled( 'remote_mcp' ) && is_array( $remote ) && in_array( $remote['auth_mode'] ?? null, array( 'none', 'shared', 'oauth' ), true ) && is_string( $remote['endpoint'] ?? null ) && null === \GetMCP\RemoteMcp\SafeHttp::url_error( $remote['endpoint'] );
		}
		if ( ! Runtime::enabled( 'project_gateways' ) ) { return false; }
		$members = $settings['gateway']['server_ids'] ?? null;
		if ( ! is_array( $members ) || ! array_is_list( $members ) ) { return false; }
		global $wpdb;
		foreach ( $members as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) { return false; }
			$member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $id ), ARRAY_A );
			if ( ! $member ) { continue; } // Existing missing members were already omitted by the legacy gateway.
			if ( 'remote-mcp' === $member['server_kind'] ) {
				$member_auth = json_decode( (string) $member['auth_config'], true );
				if ( 'oauth' === $member['auth_type'] && ! isset( $candidates[(int) $member['id']] ) ) { return false; }
				if ( ! in_array( $member['auth_type'], array( 'oauth', 'wordpress' ), true ) || ! is_array( $member_auth ) || 'getmcp' !== ( $member_auth['provider'] ?? null ) || ! self::safe_server( $member, $member_auth, $candidates ) ) { return false; }
				continue;
			}
			if ( 'native' !== $member['server_kind'] ) { return false; }
			if ( self::safe_health_member( $member ) ) { continue; }
			if ( self::safe_personal_provider_member( $member ) ) { continue; }
			$member_raw_settings = json_decode( (string) $member['settings'] );
			$member_settings = json_decode( (string) $member['settings'], true );
			if ( ! is_object( $member_raw_settings ) || ! is_array( $member_settings ) || ! self::safe_native_settings( $member_settings ) || ! self::safe_native_tools( (int) $member['id'], $member_settings ) || ! self::safe_header_variable_tools( (int) $member['id'], $member_settings ) ) { return false; }
			if ( 'oauth' === $member['auth_type'] && isset( $candidates[(int) $member['id']] ) ) {
				$member_auth = json_decode( (string) $member['auth_config'], true );
				if ( ! is_array( $member_auth ) || 'getmcp' !== ( $member_auth['provider'] ?? null ) || ! self::safe_server( $member, $member_auth, $candidates ) ) { return false; }
				continue;
			}
			$override = ! empty( $member['outbound_auth_type'] ) && 'none' !== $member['outbound_auth_type'] && ! empty( $member['outbound_auth_credentials'] );
			if ( ! $override && ! in_array( $member['auth_type'], array( 'none', 'wordpress' ), true ) ) { return false; }
		}
		return true;
	}

	/** @wp-test feature=upgrade.180 scenario=native-timeout-migration */
	private static function safe_native_settings( array $settings ): bool {
		foreach ( $settings as $key => $value ) {
			if ( 'default_timeout' === $key && is_int( $value ) && $value >= 1 && $value <= 300 ) { continue; }
			if ( in_array( $key, array( 'description', 'instructions', 'logo_url', 'icon_data' ), true ) && is_string( $value ) ) { continue; }
			if ( 'logo_id' === $key && is_int( $value ) && $value >= 0 ) { continue; }
			if ( 'launch_kit' === $key && is_array( $value ) && ( ! $value || ! array_is_list( $value ) ) && ! array_diff( array_keys( $value ), array_keys( \GetMCP\LaunchKit\LaunchKitSettings::defaults() ) ) ) { continue; }
			if ( 'database' === $key && self::safe_database_settings( $value ) ) { continue; }
			if ( 'ftp' === $key && self::safe_ftp_settings( $value ) ) { continue; }
			if ( 'mailbox' === $key && self::safe_mailbox_settings( $value ) ) { continue; }
			if ( 'variables' === $key && is_array( $value ) ) {
				foreach ( $value as $name => $item ) {
					if ( ! is_string( $name ) || ! is_string( $item ) || '' === $name || '' === $item || strlen( $name ) > 40 || strlen( $item ) > 100 || preg_match( '/[^A-Za-z0-9_]/', $name ) || preg_match( '/[^A-Za-z0-9._-]/', $item ) ) { return false; }
				}
				continue;
			}
			if ( 'header_variables' === $key && is_array( $value ) ) {
				foreach ( $value as $name => $header ) {
					if ( ! is_string( $name ) || ! is_string( $header ) || '' === $name || strlen( $name ) > 40 || preg_match( '/[^A-Za-z0-9_]/', $name ) || ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9-]{0,63}\z/D', $header ) ) { return false; }
				}
				continue;
			}
			if ( 'pii_redaction' === $key && is_array( $value ) && ! array_diff( array_keys( $value ), array( 'enabled', 'scope', 'presets', 'custom' ) ) ) {
				if ( array_key_exists( 'enabled', $value ) && ! is_bool( $value['enabled'] ) && ! in_array( $value['enabled'], array( 0, 1 ), true ) ) { return false; }
				if ( isset( $value['scope'] ) && ! in_array( $value['scope'], array( 'both', 'response_only', 'log_only' ), true ) ) { return false; }
				if ( isset( $value['presets'] ) && ( ! is_array( $value['presets'] ) || ! array_is_list( $value['presets'] ) || array_filter( $value['presets'], static fn( $item ) => ! is_string( $item ) ) ) ) { return false; }
				if ( isset( $value['custom'] ) ) {
					if ( ! is_array( $value['custom'] ) || ! array_is_list( $value['custom'] ) ) { return false; }
					foreach ( $value['custom'] as $rule ) { if ( ! is_array( $rule ) || array_diff( array_keys( $rule ), array( 'label', 'pattern', 'replacement' ) ) || ! \GetMCP\Security\PiiRedactor::validate_custom_rule( $rule ) ) { return false; } }
				}
				continue;
			}
			return false;
		}
		return true;
	}

	/** Preserve the native connector's encrypted secret and read/write policy. */
	private static function safe_database_settings( $value ): bool {
		if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) || array_diff( array_keys( $value ), array( 'engine', 'host', 'port', 'database', 'username', 'password', 'ssl_mode', 'ssl_ca', 'schema', 'read_only', 'max_rows', 'timeout' ) ) ) { return false; }
		foreach ( array( 'host', 'database', 'username', 'password', 'ssl_ca', 'schema' ) as $key ) { if ( isset( $value[$key] ) && ! is_string( $value[$key] ) ) { return false; } }
		if ( isset( $value['engine'] ) && ! in_array( $value['engine'], array( 'mysql', 'pgsql' ), true ) ) { return false; }
		if ( isset( $value['ssl_mode'] ) && ! in_array( $value['ssl_mode'], array( 'disable', 'require', 'verify' ), true ) ) { return false; }
		if ( isset( $value['read_only'] ) && ! is_bool( $value['read_only'] ) ) { return false; }
		foreach ( array( 'port' => 65535, 'max_rows' => 1000, 'timeout' => 300 ) as $key => $limit ) { if ( isset( $value[$key] ) && ( ! is_int( $value[$key] ) || $value[$key] < 1 || $value[$key] > $limit ) ) { return false; } }
		if ( ! empty( $value['password'] ) ) {
			if ( ! str_starts_with( $value['password'], 'enc:v1:' ) ) { return false; }
			try { \GetMCP\Utils\Encryption::decrypt_strict( $value['password'] ); } catch ( \Throwable $e ) { return false; }
		}
		return true;
	}

	/** Preserve reviewed password and XOAUTH2 native mailbox configurations. */
	private static function safe_mailbox_settings( $value ): bool {
		if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) || array_diff( array_keys( $value ), array( 'provider', 'host', 'port', 'encryption', 'username', 'auth_mode', 'password', 'validate_cert', 'default_folder', 'trash_folder', 'sent_folder', 'smtp_host', 'smtp_port', 'smtp_encryption', 'from_name', 'from_email' ) ) || ( isset( $value['auth_mode'] ) && ! in_array( $value['auth_mode'], array( 'password', 'oauth' ), true ) ) ) { return false; }
		foreach ( array( 'provider', 'host', 'username', 'password', 'default_folder', 'trash_folder', 'sent_folder', 'smtp_host', 'from_name', 'from_email' ) as $key ) { if ( isset( $value[$key] ) && ! is_string( $value[$key] ) ) { return false; } }
		foreach ( array( 'encryption', 'smtp_encryption' ) as $key ) { if ( isset( $value[$key] ) && ! in_array( $value[$key], array( 'ssl', 'tls', 'none' ), true ) ) { return false; } }
		foreach ( array( 'port', 'smtp_port' ) as $key ) { if ( isset( $value[$key] ) && ( ! is_int( $value[$key] ) || $value[$key] < 1 || $value[$key] > 65535 ) ) { return false; } }
		if ( isset( $value['validate_cert'] ) && ! is_bool( $value['validate_cert'] ) ) { return false; }
		if ( ! empty( $value['password'] ) ) {
			if ( ! str_starts_with( $value['password'], 'enc:v1:' ) ) { return false; }
			try { \GetMCP\Utils\Encryption::decrypt_strict( $value['password'] ); } catch ( \Throwable $e ) { return false; }
		}
		return true;
	}

	/** Admit the locally exercised FTP, FTPS and SFTP forms. */
	private static function safe_ftp_settings( $value ): bool {
		if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) || array_diff( array_keys( $value ), array( 'provider', 'protocol', 'host', 'port', 'username', 'auth_mode', 'password', 'private_key', 'passphrase', 'host_key_fingerprint', 'validate_cert', 'root_path', 'read_only', 'max_file_size', 'timeout' ) ) || ! in_array( $value['protocol'] ?? null, array( 'ftp', 'ftps', 'ftps_implicit', 'sftp' ), true ) ) { return false; }
		foreach ( array( 'provider', 'host', 'username', 'password', 'private_key', 'passphrase', 'host_key_fingerprint', 'root_path' ) as $key ) { if ( isset( $value[$key] ) && ! is_string( $value[$key] ) ) { return false; } }
		$mode = $value['auth_mode'] ?? 'password';
		if ( ! in_array( $mode, array( 'password', 'key' ), true ) || ( 'sftp' !== $value['protocol'] && 'password' !== $mode ) ) { return false; }
		if ( 'password' === $mode && ( ! empty( $value['private_key'] ) || ! empty( $value['passphrase'] ) ) ) { return false; }
		if ( 'key' === $mode && ( ! empty( $value['password'] ) || empty( $value['private_key'] ) ) ) { return false; }
		foreach ( array( 'read_only', 'validate_cert' ) as $key ) { if ( isset( $value[$key] ) && ! is_bool( $value[$key] ) ) { return false; } }
		foreach ( array( 'port' => 65535, 'max_file_size' => 20, 'timeout' => 300 ) as $key => $limit ) { if ( isset( $value[$key] ) && ( ! is_int( $value[$key] ) || $value[$key] < 1 || $value[$key] > $limit ) ) { return false; } }
		if ( ! empty( $value['password'] ) ) {
			if ( ! str_starts_with( $value['password'], 'enc:v1:' ) ) { return false; }
			try { \GetMCP\Utils\Encryption::decrypt_strict( $value['password'] ); } catch ( \Throwable $e ) { return false; }
		}
		if ( ! empty( $value['private_key'] ) ) {
			if ( ! str_starts_with( $value['private_key'], 'enc:v1:' ) ) { return false; }
			try {
				$key = \GetMCP\Utils\Encryption::decrypt_strict( $value['private_key'] ); $passphrase = false;
				if ( ! empty( $value['passphrase'] ) ) { if ( ! str_starts_with( $value['passphrase'], 'enc:v1:' ) ) { return false; } $passphrase = \GetMCP\Utils\Encryption::decrypt_strict( $value['passphrase'] ); }
				if ( ! ( \phpseclib3\Crypt\PublicKeyLoader::load( $key, $passphrase ) instanceof \phpseclib3\Crypt\Common\PrivateKey ) ) { return false; }
			} catch ( \Throwable $e ) { return false; }
		}
		return true;
	}

	/** 1.8 protects caller-chosen hosts; retain header variables only in paths. */
	private static function safe_header_variable_tools( int $server_id, array $settings ): bool {
		$variables = $settings['header_variables'] ?? array();
		if ( ! $variables ) { return true; }
		global $wpdb;
		$urls = $wpdb->get_col( $wpdb->prepare( "SELECT endpoint_url FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d", $server_id ) );
		if ( ! is_array( $urls ) || '' !== $wpdb->last_error ) { return false; }
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || ! preg_match( '#\A[a-z][a-z0-9+.-]*://([^/?\#]*)#i', $url, $match ) ) { return false; }
			foreach ( array_keys( $variables ) as $name ) { if ( str_contains( $match[1], '{{' . $name . '}}' ) || str_contains( $match[1], '{' . $name . '}' ) ) { return false; } }
		}
		return true;
	}

	/** Allow only reviewed personal-provider hosts and user-token tools. */
public static function safe_personal_provider_member( array $row ): bool {
		if ( 'standard' !== ( $row['kind'] ?? null ) || 'oauth' !== ( $row['auth_type'] ?? null ) || ! MarketingModule::enabled() ) { return false; }
		$raw = json_decode( (string) $row['settings'] ); $settings = json_decode( (string) $row['settings'], true );
		if ( ! is_object( $raw ) || ! is_array( $settings ) || array_diff( array_keys( $settings ), array( 'getmcp_extensions_required', 'instructions', 'description', 'personal_provider', 'provider_token_sources', 'default_timeout', 'pii_redaction' ) ) ) { return false; }
		if ( isset( $settings['getmcp_extensions_required'] ) && ! is_bool( $settings['getmcp_extensions_required'] ) ) { return false; }
		foreach ( array( 'instructions', 'description' ) as $key ) { if ( isset( $settings[$key] ) && ! is_string( $settings[$key] ) ) { return false; } }
		// These existing execution preferences do not change provider access. Keep
		// the reviewed disabled-redaction shape intact; unknown policies defer.
		if ( array_key_exists( 'default_timeout', $settings ) && ( ! is_int( $settings['default_timeout'] ) || $settings['default_timeout'] < 1 || $settings['default_timeout'] > 300 ) ) { return false; }
		if ( array_key_exists( 'pii_redaction', $settings ) ) {
			$pii = $settings['pii_redaction'];
			if ( ! is_object( $raw->pii_redaction ?? null ) || ! is_array( $pii ) || array_diff( array_keys( $pii ), array( 'enabled', 'scope', 'presets', 'custom' ) ) || false !== ( $pii['enabled'] ?? null ) || 'both' !== ( $pii['scope'] ?? null ) || array() !== ( $pii['presets'] ?? null ) || array() !== ( $pii['custom'] ?? null ) ) { return false; }
		}
		if ( isset( $settings['personal_provider'] ) ) {
			$personal = $settings['personal_provider'];
if ( ! is_object( $raw->personal_provider ?? null ) || ! is_array( $personal ) || array_diff( array_keys( $personal ), array( 'allowed_user_ids', 'preset', 'enabled', 'allow_all_users' ) ) || ( isset( $personal['enabled'] ) && ! is_bool( $personal['enabled'] ) ) || ( isset( $personal['allow_all_users'] ) && ! is_bool( $personal['allow_all_users'] ) ) ) { return false; }
			if ( isset( $personal['preset'] ) && ( ! is_string( $personal['preset'] ) || ! array_key_exists( $personal['preset'], ProviderDiscovery::presets() ) ) ) { return false; }
			if ( isset( $personal['allowed_user_ids'] ) ) {
				if ( ! is_array( $personal['allowed_user_ids'] ) || ! array_is_list( $personal['allowed_user_ids'] ) ) { return false; }
				foreach ( $personal['allowed_user_ids'] as $user_id ) { if ( ! is_int( $user_id ) || $user_id < 1 ) { return false; } }
			}
		}
		$page_sources = array();
		if ( isset( $settings['provider_token_sources'] ) ) {
			if ( ! is_object( $raw->provider_token_sources ?? null ) || ! is_array( $settings['provider_token_sources'] ) ) { return false; }
			foreach ( $settings['provider_token_sources'] as $slug => $source ) {
				if ( ! is_string( $slug ) || ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $slug ) || ! in_array( $source, array( 'user', 'page' ), true ) ) { return false; }
				if ( 'page' === $source ) { if ( ! in_array( $slug, array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true ) ) { return false; } $page_sources[] = $slug; }
			}
		}
		try {
			$server = ( new \GetMCP\Core\ServerManager() )->get( (int) $row['id'] );
			if ( ! $server || GoogleHealth::is_server( $server ) || ! ProviderConnections::supported( $server ) ) { return false; }
			$config = ProviderTokens::config( $server );
			$google = 'https://accounts.google.com/o/oauth2/v2/auth' === ( $config['authorize_url'] ?? null ) && 'https://oauth2.googleapis.com/token' === ( $config['token_url'] ?? null );
			$meta = is_string( $config['authorize_url'] ?? null ) && is_string( $config['token_url'] ?? null ) && preg_match( '#\Ahttps://www\.facebook\.com/v[0-9]+\.[0-9]+/dialog/oauth\z#D', $config['authorize_url'] ) && preg_match( '#\Ahttps://graph\.facebook\.com/v[0-9]+\.[0-9]+/oauth/access_token\z#D', $config['token_url'] );
			if ( ( ! $google && ! $meta ) || ( $page_sources && ! $meta ) ) { return false; }
		} catch ( \Throwable $e ) { return false; }
		$hosts = $google ? array( 'www.googleapis.com', 'searchconsole.googleapis.com', 'googleads.googleapis.com', 'analyticsadmin.googleapis.com', 'analyticsdata.googleapis.com' ) : array( 'graph.facebook.com', 'mcp.facebook.com' );
		$page_tools = array();
		if ( $meta ) {
			$asset = json_decode( (string) file_get_contents( GETMCP_EXTENSIONS_PATH . 'assets/templates/instagram.json' ), true );
			foreach ( $asset['tools'] ?? array() as $definition ) { if ( in_array( $definition['slug'] ?? null, array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true ) ) { $page_tools[$definition['slug']] = $definition; } }
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT slug,endpoint_url,http_method,tool_type FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d", (int) $row['id'] ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { return false; }
		foreach ( $rows as $tool ) {
			$url = wp_parse_url( $tool['endpoint_url'] );
			if ( 'http' !== $tool['tool_type'] || str_starts_with( $tool['slug'], 'google_health_' ) || ! is_array( $url ) || 'https' !== strtolower( $url['scheme'] ?? '' ) || ! in_array( strtolower( $url['host'] ?? '' ), $hosts, true ) || isset( $url['user'] ) || isset( $url['pass'] ) || ( isset( $url['port'] ) && 443 !== (int) $url['port'] ) ) { return false; }
			if ( in_array( $tool['slug'], array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true ) ) {
				$definition = $page_tools[$tool['slug']] ?? null;
				if ( ! $definition || $tool['endpoint_url'] !== $definition['endpoint_url'] || $tool['http_method'] !== $definition['http_method'] ) { return false; }
			}
		}
		return true;
	}

	/** A health member keeps its own personal grant and the reviewed health executor. */
	private static function safe_health_member( array $row ): bool {
		if ( 'standard' !== ( $row['kind'] ?? null ) || 'oauth' !== ( $row['auth_type'] ?? null ) || ! GoogleHealth::enabled() ) { return false; }
		$raw = json_decode( (string) $row['settings'] ); $settings = json_decode( (string) $row['settings'], true );
		if ( ! is_object( $raw ) || ! is_array( $settings ) || array_diff( array_keys( $settings ), array( 'google_health', 'getmcp_extensions_required', 'instructions', 'description', 'personal_provider', 'provider_token_sources' ) ) ) { return false; }
		foreach ( array( 'google_health', 'getmcp_extensions_required' ) as $key ) { if ( isset( $settings[$key] ) && ! is_bool( $settings[$key] ) ) { return false; } }
		foreach ( array( 'instructions', 'description' ) as $key ) { if ( isset( $settings[$key] ) && ! is_string( $settings[$key] ) ) { return false; } }
		if ( isset( $settings['personal_provider'] ) ) {
			$personal = $settings['personal_provider'];
if ( ! is_object( $raw->personal_provider ?? null ) || ! is_array( $personal ) || array_diff( array_keys( $personal ), array( 'allowed_user_ids', 'preset', 'enabled', 'allow_all_users' ) ) || ( isset( $personal['enabled'] ) && ! is_bool( $personal['enabled'] ) ) || ( isset( $personal['allow_all_users'] ) && ! is_bool( $personal['allow_all_users'] ) ) || ( isset( $personal['preset'] ) && 'google-health' !== $personal['preset'] ) ) { return false; }
			if ( isset( $personal['allowed_user_ids'] ) ) {
				if ( ! is_array( $personal['allowed_user_ids'] ) || ! array_is_list( $personal['allowed_user_ids'] ) ) { return false; }
				foreach ( $personal['allowed_user_ids'] as $user_id ) { if ( ! is_int( $user_id ) || $user_id < 1 ) { return false; } }
			}
		}
		if ( isset( $settings['provider_token_sources'] ) ) {
			if ( ! is_object( $raw->provider_token_sources ?? null ) || ! is_array( $settings['provider_token_sources'] ) ) { return false; }
			foreach ( $settings['provider_token_sources'] as $slug => $source ) { if ( ! is_string( $slug ) || ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $slug ) || 'user' !== $source ) { return false; } }
		}
		try {
			$server = ( new \GetMCP\Core\ServerManager() )->get( (int) $row['id'] );
			if ( ! $server || ! GoogleHealth::is_server( $server ) || ! ProviderConnections::supported( $server ) ) { return false; }
			GoogleHealth::oauth_config( ProviderTokens::config( $server ) );
		} catch ( \Throwable $e ) { return false; }
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT slug,endpoint_url,http_method,tool_type FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d", (int) $row['id'] ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { return false; }
		$definitions = array(); foreach ( GoogleHealth::asset()['tools'] as $tool ) { $definitions[$tool['slug']] = $tool; }
		foreach ( $rows as $tool ) {
			$definition = $definitions[$tool['slug']] ?? null;
			if ( ! $definition || $definition['endpoint_url'] !== $tool['endpoint_url'] || $definition['http_method'] !== $tool['http_method'] || 'http' !== $tool['tool_type'] ) { return false; }
		}
		return true;
	}

	private static function safe_native_tools( int $server_id, array $settings ): bool {
		global $wpdb;
		$unsupported = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d AND (tool_type NOT IN ('http','database','ftp','mailbox') OR slug IN ('list_meta_ads_tools','call_meta_ads_tool') OR slug LIKE 'google\\_health\\_%%' OR LOWER(endpoint_url) LIKE 'https://health.googleapis.com/%%')", $server_id ) );
		if ( null === $unsupported || 0 !== (int) $unsupported ) { return false; }
		$handlers = $wpdb->get_col( $wpdb->prepare( "SELECT handler FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d AND tool_type='database'", $server_id ) );
		if ( ! is_array( $handlers ) || '' !== $wpdb->last_error || ( $handlers && ! isset( $settings['database'] ) ) ) { return false; }
		foreach ( $handlers as $handler ) { if ( ! is_string( $handler ) || ! \GetMCP\Sql\SqlTools::has( $handler ) ) { return false; } }
		$ftp_handlers = $wpdb->get_col( $wpdb->prepare( "SELECT handler FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d AND tool_type='ftp'", $server_id ) );
		if ( ! is_array( $ftp_handlers ) || '' !== $wpdb->last_error || ( $ftp_handlers && ! isset( $settings['ftp'] ) ) ) { return false; }
		foreach ( $ftp_handlers as $handler ) { if ( ! is_string( $handler ) || ! \GetMCP\Ftp\FtpTools::has( $handler ) ) { return false; } }
		$mail_handlers = $wpdb->get_col( $wpdb->prepare( "SELECT handler FROM {$wpdb->prefix}getmcp_tools WHERE server_id=%d AND tool_type='mailbox'", $server_id ) );
		if ( ! is_array( $mail_handlers ) || '' !== $wpdb->last_error || ( $mail_handlers && ! isset( $settings['mailbox'] ) ) ) { return false; }
		foreach ( $mail_handlers as $handler ) { if ( ! is_string( $handler ) || ! \GetMCP\Mail\MailboxTools::has( $handler ) ) { return false; } }
		return true;
	}

	/** Restore only records this migration changed when the reviewed 1.7 core returns. */
	private static function restore_17() {
		global $wpdb;
		if ( ! self::transactional() ) { return self::error(); }
		$lock = 'getmcp-ext-auth180-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) { return self::error(); }
		$transaction = false;
		try {
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'Could not start restore.' ); }
			$transaction = true;
			$ledger = self::locked_ledger();
			$restored = array();
			foreach ( $ledger as $id => $original ) {
				if ( ! ctype_digit( (string) $id ) || 'oauth' !== $original ) { throw new \RuntimeException( 'Invalid migration ledger.' ); }
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE id=%d FOR UPDATE", (int) $id ), ARRAY_A );
				if ( ! $row ) { unset( $ledger[$id] ); continue; }
				$auth = json_decode( (string) $row['auth_config'], true );
				if ( 'wordpress' !== $row['auth_type'] || ! is_array( $auth ) || 'getmcp' !== ( $auth['provider'] ?? null ) ) { unset( $ledger[$id] ); continue; }
				if ( 'standard' !== ( $row['kind'] ?? null ) ) { throw new \RuntimeException( 'A server now requires newer core features.' ); }
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}getmcp_servers SET auth_type='oauth',updated_at=updated_at WHERE id=%d AND auth_type='wordpress' AND BINARY auth_config=BINARY %s", (int) $id, $row['auth_config'] ) );
				if ( 1 !== $changed ) { throw new \RuntimeException( 'A restored server changed.' ); }
				$restored[] = $row; unset( $ledger[$id] );
			}
			self::write_ledger( $ledger );
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Could not commit restore.' ); }
			$transaction = false; self::clear_cache( $restored );
			return array( 'restored' => count( $restored ) );
		} catch ( \Throwable $e ) {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
			return self::error();
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	private static function transactional(): bool {
		global $wpdb;
		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $wpdb->prefix ) ) { return false; }
		foreach ( array( $wpdb->options, $wpdb->prefix . 'getmcp_servers', $wpdb->prefix . 'getmcp_oauth_tokens', $wpdb->prefix . 'getmcp_tools' ) as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $table ), ARRAY_A );
			if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) { return false; }
		}
		return true;
	}

	/** A filter may only narrow the set, never make an otherwise unsafe row eligible. */
	private static function scope() {
		$scope = apply_filters( 'getmcp_extensions_180_migration_scope', null );
		if ( null === $scope ) { return null; }
		if ( ! is_array( $scope ) || ! array_is_list( $scope ) ) { return self::error(); }
		foreach ( $scope as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return self::error(); } }
		return array_values( array_unique( $scope ) );
	}

	private static function scope_sql( ?array $scope ): string {
		if ( null === $scope ) { return ''; }
		// Values are validated positive PHP integers in scope(); no strings enter SQL.
		return 'AND id IN (' . implode( ',', $scope ) . ')';
	}

	private static function locked_ledger(): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", self::LEDGER ), ARRAY_A );
		if ( ! $row ) { if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'Could not inspect migration ledger.' ); } return array(); }
		$value = json_decode( (string) $row['option_value'], true );
		if ( ! is_array( $value ) ) { throw new \RuntimeException( 'Invalid migration ledger.' ); }
		foreach ( $value as $id => $original ) {
			if ( ! preg_match( '/\A[1-9][0-9]*\z/', (string) $id ) || (string) (int) $id !== (string) $id || 'oauth' !== $original ) { throw new \RuntimeException( 'Invalid migration ledger.' ); }
		}
		return $value;
	}

	private static function write_ledger( array $ledger ): void {
		global $wpdb;
		$value = wp_json_encode( $ledger );
		if ( ! is_string( $value ) ) { throw new \RuntimeException( 'Could not encode migration ledger.' ); }
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", self::LEDGER, $value ) );
		if ( false === $result ) { throw new \RuntimeException( 'Could not save migration ledger.' ); }
	}

	private static function clear_cache( array $rows ): void {
		wp_cache_delete( self::LEDGER, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( $rows as $row ) { wp_cache_delete( 'getmcp_server_' . (int) $row['id'] ); }
	}

	private static function error(): \WP_Error { return new \WP_Error( 'extensions_auto_migration_deferred', 'Legacy sign-in migration was deferred; existing data remains unchanged.' ); }
}
