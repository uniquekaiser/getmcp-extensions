<?php
/**
 * Server CRUD manager.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Core;

use GetMCP\Builtin\BuiltinServer;

/**
 * Handles CRUD operations for MCP servers.
 *
 * All database operations use $wpdb->prepare() for security.
 *
 * @since 1.0.0
 */
class ServerManager {

	/**
	 * Table name without prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TABLE = 'getmcp_servers';

	/**
	 * Get the full table name with prefix.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create a new server.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $data Server data.
	 * @return Server|false Server object on success, false on failure.
	 */
	/**
	 * Generate a UUID v4.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	public static function generate_uuid(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 ); // version 4
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 ); // variant RFC 4122
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	public function create( array $data ): Server|false {
		global $wpdb;
		if ( 'oauth' === ( $data['auth_type'] ?? '' ) && \GetMCP\Auth\FirstPartyOAuth::config_is_native( $data['auth_config'] ?? null ) ) {
			$data['auth_credentials'] = null;
			$data['test_auth_credentials'] = null;
		}

		$defaults = array(
			'server_kind'               => 'native',
			'uuid'                      => self::generate_uuid(),
			'user_id'                   => get_current_user_id(),
			'name'                      => '',
			'slug'                      => '',
			'server_id'                 => bin2hex( random_bytes( 8 ) ),
			'status'                    => 'active',
			'transport_type'            => 'streamable-http',
			'auth_type'                 => 'none',
			'auth_config'               => null,
			'auth_credentials'          => null,
			'outbound_auth_type'        => null,
			'outbound_auth_config'      => null,
			'outbound_auth_credentials' => null,
			'test_auth_type'            => 'none',
			'test_auth_credentials'     => null,
			'cors_origins'              => null,
			'rate_limit_per_min'        => 60,
			'settings'                  => null,
		);

		$data = wp_parse_args( $data, $defaults );
		$data = \GetMCP\Gateway\FeatureManager::validate_storage( $data );

		// Generate or deduplicate slug.
		if ( empty( $data['slug'] ) && ! empty( $data['name'] ) ) {
			// No slug provided — derive one from the name.
			$data['slug'] = $this->generate_unique_slug( $data['name'] );
		} elseif ( ! empty( $data['slug'] ) ) {
			// Slug explicitly provided — ensure it is unique.
			$data['slug'] = $this->generate_unique_slug( $data['slug'] );
		}

		// Encrypt sensitive credential fields before storage.
		$data['auth_credentials']          = self::maybe_encrypt( $data['auth_credentials'] );
		$data['test_auth_credentials']     = self::maybe_encrypt( $data['test_auth_credentials'] );
		$data['outbound_auth_credentials'] = self::maybe_encrypt( $data['outbound_auth_credentials'] );

		$result = $wpdb->insert( // @codingStandardsIgnoreLine
			$this->table(),
			array(
				'server_kind'               => sanitize_key( $data['server_kind'] ),
				'uuid'                      => sanitize_text_field( $data['uuid'] ),
				'user_id'                   => absint( $data['user_id'] ),
				'name'                      => sanitize_text_field( $data['name'] ),
				'slug'                      => sanitize_title( $data['slug'] ),
				'server_id'                 => sanitize_text_field( $data['server_id'] ),
				'status'                    => sanitize_text_field( $data['status'] ),
				'transport_type'            => sanitize_text_field( $data['transport_type'] ),
				'auth_type'                 => sanitize_text_field( $data['auth_type'] ),
				'auth_config'               => $data['auth_config'],
				'auth_credentials'          => $data['auth_credentials'],
				'outbound_auth_type'        => $data['outbound_auth_type'] ? sanitize_text_field( $data['outbound_auth_type'] ) : null,
				'outbound_auth_config'      => $data['outbound_auth_config'],
				'outbound_auth_credentials' => $data['outbound_auth_credentials'],
				'test_auth_type'            => sanitize_text_field( $data['test_auth_type'] ),
				'test_auth_credentials'     => $data['test_auth_credentials'],
				'cors_origins'              => $data['cors_origins'],
				'rate_limit_per_min'        => self::normalize_rate_limit( $data['rate_limit_per_min'] ),
				'settings'                  => $data['settings'],
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		$server = $this->get( (int) $wpdb->insert_id );

		if ( $server ) {
			/**
			 * Fires after a server is created.
			 *
			 * @since 1.0.0
			 * @param Server $server The created server.
			 */
			do_action( 'getmcp_server_created', $server );
		}

		return $server;
	}

	/**
	 * Get a server by ID.
	 *
	 * @since  1.0.0
	 * @param  int $id Server ID.
	 * @return Server|false Server object on success, false on failure.
	 */
	public function get( int $id ): Server|false {
		global $wpdb;

		// @codingStandardsIgnoreStart
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		// @codingStandardsIgnoreEnd

		if ( null === $row ) {
			return false;
		}

		return new Server( $row );
	}

	/**
	 * Get a server by slug.
	 *
	 * @since  1.0.0
	 * @param  string $slug Server slug.
	 * @return Server|false Server object on success, false on failure.
	 */
	public function get_by_slug( string $slug ): Server|false {
		// The built-in server has no row, so it is resolved before the query
		// rather than after it. A stored server that already holds the reserved
		// slug wins — see BuiltinServer::slug_is_taken() — which is why this
		// asks is_available() and not merely is_enabled().
		// The gateway is synthetic too, and answers on its reserved alias
		// unless a pre-existing stored server already holds that slug.
		if ( \GetMCP\Gateway\McpGateway::slug_is_reserved( $slug )
			&& \GetMCP\Gateway\McpGateway::is_available()
			&& false === $this->get_stored_by_slug( sanitize_title( $slug ) ) ) {
			return \GetMCP\Gateway\McpGateway::server();
		}

		if ( BuiltinServer::slug_is_reserved( $slug ) && BuiltinServer::is_available() ) {
			return BuiltinServer::server();
		}

		return $this->get_stored_by_slug( $slug );
	}

	/**
	 * Look a server up by slug, ignoring the built-in one.
	 *
	 * Kept separate so BuiltinServer can ask whether a stored row already holds
	 * the reserved slug without calling back into get_by_slug() and recursing.
	 *
	 * @since  1.4.0
	 * @param  string $slug Server slug.
	 * @return Server|false Server object on success, false on failure.
	 */
	public function get_stored_by_slug( string $slug ): Server|false {
		global $wpdb;

		// @codingStandardsIgnoreStart
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE slug = %s",
				$slug
			),
			ARRAY_A
		);
		// @codingStandardsIgnoreEnd

		if ( null === $row ) {
			return false;
		}

		return new Server( $row );
	}

	/**
	 * Get a server by UUID.
	 *
	 * @since  1.1.0
	 * @param  string $uuid Server UUID.
	 * @return Server|false Server object on success, false on failure.
	 */
	public function get_by_uuid( string $uuid ): Server|false {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE uuid = %s",
				$uuid
			),
			ARRAY_A
		);

		// Fallback: if not found by UUID and the value is numeric, try by ID.
		// This handles records whose UUID hasn't been backfilled yet.
		if ( null === $row && ctype_digit( $uuid ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table()} WHERE id = %d",
					(int) $uuid
				),
				ARRAY_A
			);
		}

		if ( null === $row ) {
			return false;
		}

		return new Server( $row );
	}

	/**
	 * Update a server.
	 *
	 * @since  1.0.0
	 * @param  int                  $id   Server ID.
	 * @param  array<string, mixed> $data Fields to update.
	 * @return Server|false Updated server on success, false on failure.
	 */
	public function update( int $id, array $data ): Server|false {
		global $wpdb;
		$current = $this->get( $id );
		if ( ! $current ) {
			return false;
		}
		$configuration_cas = isset( $data['_configuration_revision'] );
		if ( $configuration_cas && ! hash_equals( \GetMCPExtensions\MarketingModule::configuration_revision( $current ), $data['_configuration_revision'] ) ) { throw new \GetMCPExtensions\SettingsConflict( 'Provider configuration changed.' ); }
		unset( $data['_configuration_revision'] );
		$data = \GetMCPExtensions\AuthenticationSettings::prepare( $current, $data );
		$authentication_cas = isset( $data['_authentication_revision'] );
		unset( $data['_authentication_revision'], $data['_auth_config_mode'], $data['_clear_credentials'] );
		if ( 'oauth' === ( $data['auth_type'] ?? $current?->auth_type ) && \GetMCP\Auth\FirstPartyOAuth::config_is_native( $data['auth_config'] ?? $current?->auth_config ) ) {
			$data['auth_credentials'] = null;
			$data['test_auth_credentials'] = null;
		}

		$current = $this->get( $id );
		if ( $current && 'native' !== $current->server_kind ) {
			if ( isset( $data['server_kind'] ) && $data['server_kind'] !== $current->server_kind ) { throw new \InvalidArgumentException( 'The connection kind cannot be changed.' ); }
			$data = \GetMCP\Gateway\FeatureManager::validate_storage( array_merge( $current->to_array(), $data ) );
		}
		$allowed = array(
			'name',
			'slug',
			'status',
			'transport_type',
			'auth_type',
			'auth_config',
			'auth_credentials',
			'outbound_auth_type',
			'outbound_auth_config',
			'outbound_auth_credentials',
			'test_auth_type',
			'test_auth_credentials',
			'cors_origins',
			'rate_limit_per_min',
			'settings',
		);

		$update = array();
		$format = array();

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$value = $data[ $field ];

			match ( $field ) {
				'name'                  => $update['name']               = sanitize_text_field( $value ),
				'slug'                  => $update['slug']               = sanitize_title( $value ),
				'rate_limit_per_min'    => $update['rate_limit_per_min'] = self::normalize_rate_limit( $value ),
				'auth_config',
				'auth_credentials',
				'outbound_auth_config',
				'outbound_auth_credentials',
				'test_auth_credentials',
				'settings'              => $update[ $field ]             = $value,
				default                 => $update[ $field ]             = is_string( $value ) ? sanitize_text_field( $value ) : $value,
			};

			$format[] = match ( $field ) {
				'rate_limit_per_min' => '%d',
				default              => '%s',
			};
		}

		if ( empty( $update ) ) {
			return $this->get( $id );
		}

		// When name is updated but slug is not explicitly provided, re-derive the slug.
		if ( isset( $update['name'] ) && ! isset( $update['slug'] ) && 'native' === $this->get( $id )->server_kind ) {
			$update['slug'] = $this->generate_unique_slug( $update['name'], $id );
			$format[]       = '%s';
		}

		// Encrypt sensitive credential fields before storage.
		if ( isset( $update['auth_credentials'] ) ) {
			$update['auth_credentials'] = self::maybe_encrypt( $update['auth_credentials'] );
		}
		if ( isset( $update['test_auth_credentials'] ) ) {
			$update['test_auth_credentials'] = self::maybe_encrypt( $update['test_auth_credentials'] );
		}
		if ( isset( $update['outbound_auth_credentials'] ) ) {
			$update['outbound_auth_credentials'] = self::maybe_encrypt( $update['outbound_auth_credentials'] );
		}

		$old_server = $this->get( $id );

		$where = array( 'id' => $id );
		if ( $configuration_cas ) { $where['settings'] = $current->settings; }
		if ( $authentication_cas ) { foreach ( array( 'auth_type', 'auth_config', 'auth_credentials', 'test_auth_credentials', 'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials' ) as $field ) { $where[$field] = $current->$field; } }
		$result = $wpdb->update( // @codingStandardsIgnoreLine
			$this->table(),
			$update,
			$where,
			$format,
			array_merge( array( '%d' ), array_fill( 0, count( $where ) - 1, '%s' ) )
		);

		if ( 0 === $result && ( $authentication_cas || $configuration_cas ) ) { $fresh = $this->get( $id ); if ( ! $fresh || \GetMCPExtensions\AuthenticationSettings::revision( $fresh ) !== \GetMCPExtensions\AuthenticationSettings::revision( $current ) || ( $configuration_cas && $fresh->settings !== $current->settings ) ) { throw new \GetMCPExtensions\SettingsConflict( 'Configuration changed during save.' ); } }
		if ( false === $result ) {
			return false;
		}

		$server = $this->get( $id );

		if ( $server && $old_server ) {
			/**
			 * Fires after a server is updated.
			 *
			 * @since 1.0.0
			 * @param Server $server     The updated server.
			 * @param Server $old_server The server before update.
			 */
			do_action( 'getmcp_server_updated', $server, $old_server );
			if ( \GetMCP\Auth\FirstPartyOAuth::is_custom( $server ) ) {
				$new_config = json_decode( $server->auth_config, true );
				$old_config = json_decode( $old_server->auth_config ?? '', true );
				$new_ids = $new_config['allowed_user_ids'] ?? array();
				$old_ids = $old_config['allowed_user_ids'] ?? array();
				if ( $new_ids !== $old_ids ) {
					do_action( 'getmcp_oauth_allowed_users_updated', $id, $new_ids, $old_ids, get_current_user_id() );
				}
			}
		}

		return $server;
	}

	/**
	 * Delete a server and all its associated data.
	 *
	 * @since  1.0.0
	 * @param  int $id Server ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		// Delete associated data using dedicated managers for proper cache handling.
		$tool_manager     = new ToolManager();
		$resource_manager = new ResourceManager();
		$prompt_manager   = new PromptManager();

		// Get all associated items and delete them individually for proper hook firing.
		$tools = $tool_manager->get_by_server( $id, array( 'per_page' => 9999 ) );
		if ( ! empty( $tools['items'] ) ) {
			foreach ( $tools['items'] as $tool ) {
				$tool_manager->delete( $tool->id );
			}
		}

		$resources = $resource_manager->get_by_server( $id, array( 'per_page' => 9999 ) );
		if ( ! empty( $resources['items'] ) ) {
			foreach ( $resources['items'] as $resource ) {
				$resource_manager->delete( $resource->id );
			}
		}

		$prompts = $prompt_manager->get_by_server( $id, array( 'per_page' => 9999 ) );
		if ( ! empty( $prompts['items'] ) ) {
			foreach ( $prompts['items'] as $prompt ) {
				$prompt_manager->delete( $prompt->id );
			}
		}

		// Delete auth and session data using dedicated methods.
		\GetMCP\Remote\UpstreamConnections::delete_server( $id );
		$this->delete_api_keys( $id );
		$this->delete_sessions( $id );
		$this->delete_webhooks( $id );
		$this->delete_call_logs( $id );

		// Clear any cached data for this server.
		wp_cache_delete( 'getmcp_server_' . $id );

		// Delete the server itself — if this fails, roll back everything above.
		$result = $this->delete_server_row( $id );

		if ( ! $result ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$wpdb->query( 'COMMIT' );

		/**
		 * Fires after a server is deleted.
		 *
		 * @since 1.0.0
		 * @param int $id The deleted server ID.
		 */
		do_action( 'getmcp_server_deleted', $id );

		return true;
	}

	/**
	 * Delete API keys for a server.
	 *
	 * @since  1.0.0
	 * @param  int $id Server ID.
	 * @return bool True on success, false on failure.
	 */
	private function delete_api_keys( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( // @codingStandardsIgnoreLine
			$wpdb->prefix . 'getmcp_api_keys',
			array( 'server_id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete sessions for a server.
	 *
	 * @since  1.0.0
	 * @param  int $id Server ID.
	 * @return bool True on success, false on failure.
	 */
	private function delete_sessions( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( // @codingStandardsIgnoreLine
			$wpdb->prefix . 'getmcp_sessions',
			array( 'server_id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete webhooks for a server.
	 *
	 * @since  1.0.0
	 * @param  int $id Server ID.
	 * @return bool True on success, false on failure.
	 */
	private function delete_webhooks( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( // @codingStandardsIgnoreLine
			$wpdb->prefix . 'getmcp_webhooks',
			array( 'server_id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete call logs for a server.
	 *
	 * @since  1.0.0
	 * @param  int $id Server ID.
	 * @return bool True on success, false on failure.
	 */
	private function delete_call_logs( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( // @codingStandardsIgnoreLine
			$wpdb->prefix . 'getmcp_call_logs',
			array( 'server_id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete the server row from the database.
	 *
	 * @since  1.0.0
	 * @param  int $id Server ID.
	 * @return bool True on success, false on failure.
	 */
	private function delete_server_row( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( // @codingStandardsIgnoreLine
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * List servers with pagination and filtering.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $args Query arguments.
	 * @return array<string, mixed> Array with 'items' and 'total' keys.
	 */
	public function list( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'per_page' => 20,
			'page'     => 1,
			'status'   => '',
			'user_id'  => 0,
			'search'   => '',
			// `last_used_at` sorts by the most recent call_logs row per
			// server. Default to it so the list always surfaces the
			// servers currently in active use at the top. Servers with
			// zero traffic fall back to their `created_at` order via
			// COALESCE in the SQL below.
			'orderby'  => 'last_used_at',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		// Optional kind filtering keeps legacy native-server editors separate.
		if ( ! empty( $args['server_kind'] ) ) {
			$where[]  = 'server_kind = %s';
			$values[] = sanitize_key( $args['server_kind'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $args['status'] );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $args['user_id'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(name LIKE %s OR slug LIKE %s)';
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$values[] = $search;
			$values[] = $search;
		}

		$where_clause = implode( ' AND ', $where );

		$allowed_orderby = array( 'id', 'name', 'slug', 'status', 'created_at', 'updated_at', 'last_used_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// Get total count.
		$count_query = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where_clause}";
		if ( ! empty( $values ) ) {
			$count_query = $wpdb->prepare( $count_query, ...$values ); // @codingStandardsIgnoreLine
		}
		$total = (int) $wpdb->get_var( $count_query ); // @codingStandardsIgnoreLine

		// Get items. Both bounds are floored at 1: `page=0` would otherwise
		// compute a negative OFFSET, which MySQL rejects as a syntax error —
		// the query then returns nothing and the caller sees an empty list
		// instead of an error.
		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		if ( 'last_used_at' === $orderby ) {
			// COALESCE so a server that's never been called sinks behind
			// servers that have, but still slots in by its own creation
			// timestamp. The subquery is cheap because call_logs has an
			// index on (server_id, created_at) via the server_id key.
			$logs_table = $wpdb->prefix . 'getmcp_call_logs';
			$query = "SELECT s.*,
				COALESCE( (SELECT MAX(created_at) FROM {$logs_table} WHERE server_id = s.id), s.created_at ) AS last_used_at
				FROM {$this->table()} s
				WHERE {$where_clause}
				ORDER BY last_used_at {$order}
				LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$query = "SELECT * FROM {$this->table()} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		}

		$query_values   = $values;
		$query_values[] = $per_page;
		$query_values[] = $offset;

		$rows = $wpdb->get_results( // @codingStandardsIgnoreLine
			$wpdb->prepare( $query, ...$query_values ), // @codingStandardsIgnoreLine
			ARRAY_A
		);

		$items = array_map(
			fn( array $row ) => new Server( $row ),
			$rows ?: array() // @codingStandardsIgnoreLine
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Get server count with optional filtering.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $filters Optional filters.
	 * @return int
	 */
	public function get_count( array $filters = array() ): int {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $filters['status'] );
		}

		$where_clause = implode( ' AND ', $where );
		$query        = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where_clause}";

		if ( ! empty( $values ) ) {
			$query = $wpdb->prepare( $query, ...$values ); // @codingStandardsIgnoreLine
		}

		return (int) $wpdb->get_var( $query ); // @codingStandardsIgnoreLine
	}

	/**
	 * Generate a unique slug from a name.
	 *
	 * @since  1.0.0
	 * @param  string $name Server name.
	 * @return string Unique slug.
	 */
	/**
	 * Encrypt a credential value if not already encrypted.
	 *
	 * @since  1.0.0
	 * @param  string|null $value Raw credential string (JSON).
	 * @return string|null Encrypted string or null.
	 */
	private static function maybe_encrypt( ?string $value ): ?string {
		if ( null === $value || '' === $value ) {
			return $value;
		}

		// Already encrypted — don't double-encrypt.
		if ( \GetMCP\Utils\Encryption::is_encrypted( $value ) ) {
			return $value;
		}

		return \GetMCP\Utils\Encryption::encrypt( $value );
	}

	/**
	 * Clamp a rate limit to the values the limiter understands.
	 *
	 * absint() would turn the -1 "unlimited" sentinel into 1, which is the
	 * harshest limit the plugin can express rather than no limit at all.
	 *
	 * @since  1.0.0
	 * @param  mixed $value Raw input.
	 * @return int Positive limit, 0 to inherit the global default, or -1 for unlimited.
	 */
	private static function normalize_rate_limit( $value ): int {
		$value = (int) $value;

		return $value < 0 ? -1 : $value;
	}

	/**
	 * Duplicate a server and all its tools, resources, and prompts.
	 *
	 * The cloned server gets:
	 *  - A new UUID, new internal server_id, and a deduplicated slug.
	 *  - Name suffixed with " (Copy)".
	 *  - Status set to "draft" so the clone is not immediately live.
	 *  - Credentials are NOT copied — the clone starts with no stored credentials.
	 *
	 * @since  1.1.0
	 * @param  int $server_id Internal ID of the server to clone.
	 * @return Server|false The new server on success, false on failure.
	 */
	public function duplicate( int $server_id ): Server|false {
		$original = $this->get( $server_id );

		if ( ! $original ) {
			return false;
		}

		$data = $original->to_array();

		// Fresh identity for the clone.
		unset( $data['id'], $data['uuid'], $data['created_at'], $data['updated_at'] );
		$data['name']      = $data['name'] . ' (Copy)';
		$data['slug']      = ''; // Let generate_unique_slug derive one from the new name.
		$data['status']    = 'draft';
		$data['server_id'] = bin2hex( random_bytes( 8 ) );

		// Never copy stored credentials — clone starts clean. All three slots:
		// the outbound one is the key the server itself sends upstream, and it
		// was the one being carried across, three lines under this comment.
		// Its *type* travels so the clone asks for a credential rather than
		// silently running unauthenticated — same rule as export.
		$data['auth_credentials']          = null;
		$data['test_auth_credentials']     = null;
		$data['outbound_auth_credentials'] = null;

		$clone = $this->create( $data );

		if ( ! $clone ) {
			return false;
		}

		// Clone all tools.
		$tool_manager = new ToolManager();
		$tools        = $tool_manager->get_by_server( $original->id )['items'];
		foreach ( $tools as $tool ) {
			$tool_data = $tool->to_array();
			unset( $tool_data['id'], $tool_data['uuid'], $tool_data['created_at'], $tool_data['updated_at'] );
			$tool_data['server_id'] = $clone->id;
			$tool_data['uuid']      = self::generate_uuid();
			$tool_manager->create( $tool_data );
		}

		// Clone all resources.
		$resource_manager = new ResourceManager();
		$resources        = $resource_manager->get_by_server( $original->id )['items'];
		foreach ( $resources as $resource ) {
			$resource_data = $resource->to_array();
			unset( $resource_data['id'], $resource_data['created_at'], $resource_data['updated_at'] );
			$resource_data['server_id'] = $clone->id;
			$resource_manager->create( $resource_data );
		}

		// Clone all prompts.
		$prompt_manager = new PromptManager();
		$prompts        = $prompt_manager->get_by_server( $original->id )['items'];
		foreach ( $prompts as $prompt ) {
			$prompt_data = $prompt->to_array();
			unset( $prompt_data['id'], $prompt_data['created_at'], $prompt_data['updated_at'] );
			$prompt_data['server_id'] = $clone->id;
			$prompt_manager->create( $prompt_data );
		}

		return $clone;
	}

	/**
	 * Generate a unique slug from a name.
	 *
	 * @since  1.0.0
	 * @param  string $name Server name.
	 * @return string Unique slug.
	 */
	private function generate_unique_slug( string $name, int $exclude_id = 0 ): string {
		global $wpdb;

		$slug = sanitize_title( $name );

		// sanitize_title() returns "" for whitespace-only or non-ASCII names without
		// a transliteration plugin. Fall back to a short unique token so the server
		// always gets a valid, non-empty endpoint slug.
		if ( '' === $slug ) {
			$slug = 'server-' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}

		// The built-in server answers on the reserved slug and owns no row, so
		// the uniqueness loop below cannot see the collision. Step around it
		// here, which covers every caller — REST, CLI, import and the tools —
		// rather than in each of them.
		if ( BuiltinServer::slug_is_reserved( $slug ) || \GetMCP\Gateway\McpGateway::slug_is_reserved( $slug ) ) {
			$slug .= '-server';
		}

		$original = $slug;
		$counter  = 1;

		if ( $exclude_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE slug = %s AND id != %d", $slug, $exclude_id ) ) > 0 ) {
				$slug = $original . '-' . $counter;
				++$counter;
			}
		} else {
			while ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE slug = %s", $slug ) ) > 0 ) { // @codingStandardsIgnoreLine
				$slug = $original . '-' . $counter;
				++$counter;
			}
		}

		return $slug;
	}
}
