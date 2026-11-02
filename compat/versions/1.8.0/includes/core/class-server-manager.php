<?php
/**
 * Server CRUD manager.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Core;

use GetMCP\Auth\ServerUserAccess;
use GetMCP\Builtin\BuiltinServer;

defined( 'ABSPATH' ) || exit;

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
	 * Seconds a save waits for another save of the same server.
	 *
	 * @since 1.8.0
	 * @var int
	 */
	private const AUTH_LOCK_TIMEOUT = 5;

	/**
	 * Run a server write while holding the server's named lock.
	 *
	 * Every writer of the auth columns or the `settings` blob goes through
	 * here, so the REST revision check and its write cannot interleave with
	 * another save of the same server, and a settings merge always starts
	 * from what is stored rather than from an earlier read.
	 *
	 * @since  1.8.0
	 * @param  int      $id   Server ID.
	 * @param  callable $work Read, check and write; its return value is passed through.
	 * @return mixed What $work returned, or a 409 WP_Error when the lock stayed busy.
	 */
	public static function with_auth_lock( int $id, callable $work ): mixed {
		global $wpdb;

		$lock = 'getmcp_server_auth_' . $id;
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::AUTH_LOCK_TIMEOUT ) ); // @codingStandardsIgnoreLine
		if ( '1' !== (string) $got ) {
			return new \WP_Error(
				'getmcp_server_busy',
				__( 'Another save of this server is still running. Try again in a moment.', 'getmcp' ),
				array( 'status' => 409 )
			);
		}

		try {
			return $work();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // @codingStandardsIgnoreLine
		}
	}

	/**
	 * Change a server's settings blob, starting from the stored copy.
	 *
	 * The row is re-read under the server lock, so a key another save wrote
	 * after the caller loaded the server is kept instead of being replaced
	 * by the caller's older copy.
	 *
	 * @since  1.8.0
	 * @param  int      $id     Server ID.
	 * @param  callable $change Receives (array $settings, Server $current) and returns the
	 *                          new settings array, or a WP_Error to write nothing.
	 * @return Server|false|\WP_Error Updated server; false when the server is gone or the
	 *                                write failed; the WP_Error from $change or a busy lock.
	 */
	public function update_settings( int $id, callable $change ): Server|false|\WP_Error {
		return self::with_auth_lock(
			$id,
			function () use ( $id, $change ) {
				$current = $this->get( $id );
				if ( ! $current ) {
					return false;
				}

				$settings = is_string( $current->settings ) ? json_decode( $current->settings, true ) : null;
				$settings = $change( is_array( $settings ) ? $settings : array(), $current );
				if ( $settings instanceof \WP_Error ) {
					return $settings;
				}

				return $this->update( $id, array( 'settings' => (string) wp_json_encode( $settings ) ) );
			}
		);
	}

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

	/**
	 * Create a new server.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $data Server data.
	 * @return Server|false Server object on success, false on failure.
	 */
	public function create( array $data ): Server|false {
		global $wpdb;

		$defaults = array(
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
			'kind'                      => 'standard',
			'server_kind'               => 'native',
		);

		$data = wp_parse_args( $data, $defaults );
		// Legacy add-on kinds live beside core's 1.8 kind, and must be stored
		// in the same insert so no request can observe a half-created server.
		if ( 'native' !== $data['server_kind'] ) {
			if ( 'standard' !== $data['kind'] ) { throw new \InvalidArgumentException( 'A legacy server cannot also be a native remote or bundle.' ); }
			$data = \GetMCP\Gateway\FeatureManager::validate_storage( $data );
		}

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
				// WHY: whitelisted, never sanitised-and-stored — any other
				// word would be a third kind no code path knows about.
				'kind'                      => in_array( $data['kind'], array( 'bundle', 'remote' ), true ) ? $data['kind'] : 'standard',
				'server_kind'               => sanitize_key( $data['server_kind'] ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
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

		// WHY: a rename keeps the slug. The slug is the public endpoint URL, so
		// moving it with the name would disconnect every client; only an explicit
		// `slug` field changes it.

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

		// Leaving WordPress sign-in drops its allowed-users list. Nothing reads
		// the list in other modes and no screen shows it, so keeping it would
		// leave access data behind that nobody can see or edit.
		if ( $old_server && ServerUserAccess::AUTH_TYPE === $old_server->auth_type
			&& isset( $update['auth_type'] ) && ServerUserAccess::AUTH_TYPE !== $update['auth_type'] ) {
			$raw    = array_key_exists( 'auth_config', $update ) ? $update['auth_config'] : $old_server->auth_config;
			$config = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $config ) && array_key_exists( 'allowed_user_ids', $config ) ) {
				unset( $config['allowed_user_ids'] );
				if ( ! array_key_exists( 'auth_config', $update ) ) {
					$format[] = '%s';
				}
				$update['auth_config'] = array() === $config ? null : wp_json_encode( $config );
			}
		}

		$result = $wpdb->update( // @codingStandardsIgnoreLine
			$this->table(),
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

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

		// WHY: the server's endpoint is the only link to tokens issued to hosted
		// clients, so read the server before the row it lives on is gone.
		$server = $this->get( $id );

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
		$this->delete_api_keys( $id );
		$this->delete_sessions( $id );
		$this->delete_webhooks( $id );
		$this->delete_call_logs( $id );
		$this->delete_bundle_links( $id );
		// WHY: users' encrypted upstream tokens outlive nothing they belong to;
		// a later server reusing this id must start with no connections.
		\GetMCP\RemoteMcp\UpstreamConnections::delete_for_server( $id );
		\GetMCP\RemoteMcp\UpstreamOAuth::forget_client( $id );
		// WHY: a server deleted while its tokens survive hands them to the next
		// server created with this slug, so a failed cleanup aborts the delete.
		$oauth_deleted = $this->delete_oauth_rows( $id, $server ?: null );

		// Clear any cached data for this server.
		wp_cache_delete( 'getmcp_server_' . $id );

		// Delete the server itself — if this fails, roll back everything above.
		$result = $oauth_deleted && $this->delete_server_row( $id );

		if ( ! $result ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$wpdb->query( 'COMMIT' );

		if ( $server ) {
			$this->sweep_tokens_by_slug( (string) $server->slug );
		}

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
	 * Delete bundle membership rows on both sides: the bundle's own member
	 * list, and this server's place in any bundle that includes it.
	 *
	 * @since  1.8.0
	 * @param  int $id Server ID.
	 * @return bool True on success, false on failure.
	 */
	private function delete_bundle_links( int $id ): bool {
		global $wpdb;

		$result = $wpdb->query( // @codingStandardsIgnoreLine
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}getmcp_bundle_members WHERE bundle_id = %d OR server_id = %d",
				$id,
				$id
			)
		);

		return false !== $result;
	}

	/**
	 * Remove tokens whose resource ends in this server's path, under any host.
	 *
	 * The exact match in delete_oauth_rows() misses a token bound under an
	 * earlier public host, or one kept while the base URL was unusable. Token
	 * checks refuse it today, but it would revive if that host came back and
	 * a new server took the slug. A suffix match can use no index, so this runs
	 * after the delete commits: its row locks last one statement, not the
	 * whole delete. Best effort: the server is already gone, and a token left
	 * behind on failure is still refused and expires with its refresh window.
	 *
	 * @since  1.8.0
	 * @param  string $slug Slug of the deleted server.
	 * @return void
	 */
	private function sweep_tokens_by_slug( string $slug ): void {
		global $wpdb;
		// WHY: '%/mcp/' alone would match every row ending in a bare /mcp/.
		if ( '' === $slug ) {
			return;
		}
		$tokens = $wpdb->prefix . 'getmcp_oauth_tokens';
		$wpdb->query( // @codingStandardsIgnoreLine
			$wpdb->prepare( "DELETE FROM {$tokens} WHERE resource LIKE %s", '%/mcp/' . $wpdb->esc_like( $slug ) )
		);
	}

	/**
	 * Delete the OAuth clients registered to this server and every token
	 * issued for it, including hosted-client tokens bound by resource URL.
	 *
	 * @since  1.8.0
	 * @param  int         $id     Server ID.
	 * @param  Server|null $server The server, read before its row is deleted.
	 * @return bool True on success, false on failure.
	 */
	private function delete_oauth_rows( int $id, ?Server $server ): bool {
		global $wpdb;

		// WHY: hosted clients (Claude, ChatGPT) are stored with server_id 0 and
		// shared by every server; a zero id here would sign them out everywhere.
		if ( $id <= 0 ) {
			return true;
		}

		$tokens  = $wpdb->prefix . 'getmcp_oauth_tokens';
		$clients = $wpdb->prefix . 'getmcp_oauth_clients';

		// WHY: a hosted client's token is bound only by its resource URL; left
		// behind, a new server given the same slug would accept it. The shared
		// binding matches that URL exactly, as token checks do, so each side of
		// its UNION uses an index: a LIKE '%/mcp/slug' scan here locked every
		// server's token rows until the delete transaction committed.
		if ( $server ) {
			list( $match, $args ) = \GetMCP\Auth\FirstPartyOAuth::token_binding( $server );
		} else {
			$match = "client_id IN ( SELECT id FROM {$clients} WHERE server_id = %d )";
			$args  = array( $id );
		}

		$tokens_deleted  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE {$match}", $args ) ); // @codingStandardsIgnoreLine
		$clients_deleted = $wpdb->query( // @codingStandardsIgnoreLine
			$wpdb->prepare( "DELETE FROM {$clients} WHERE server_id = %d", $id )
		);

		return false !== $tokens_deleted && false !== $clients_deleted;
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
	 * `kind` takes one kind or a list of kinds.
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

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $args['status'] );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $args['user_id'] );
		}

		// One kind or a list of kinds: the project gateway picker lists
		// standard and remote servers together, so a remote server shows
		// its reason instead of going missing.
		$kinds = array_values( array_intersect( array_filter( (array) ( $args['kind'] ?? array() ), 'is_string' ), array( 'standard', 'bundle', 'remote' ) ) );
		if ( 1 === count( $kinds ) ) {
			$where[]  = 'kind = %s';
			$values[] = $kinds[0];
		} elseif ( count( $kinds ) > 1 ) {
			$where[]  = 'kind IN (' . implode( ', ', array_fill( 0, count( $kinds ), '%s' ) ) . ')';
			$values   = array_merge( $values, $kinds );
		}

		// The Servers screen leaves project gateways out; the Gateway page
		// lists them. Filtering here keeps the total and the pages honest.
		if ( ! empty( $args['exclude_kind'] ) && in_array( $args['exclude_kind'], array( 'standard', 'bundle', 'remote' ), true ) ) {
			$where[]  = 'kind <> %s';
			$values[] = $args['exclude_kind'];
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
	 * List every server matching the filters, one page at a time.
	 *
	 * For callers that must see the whole set (the gateway, the endpoints
	 * page): a single list() call with a large per_page silently drops
	 * whatever sorts past it.
	 *
	 * @since  1.8.0
	 * @param  array<string, mixed> $args list() filters; per_page and page are ignored.
	 * @return array<int, Server>
	 */
	public function list_all( array $args = array() ): array {
		$per_page = 200;
		$page     = 1;
		$servers  = array();

		do {
			$result  = $this->list( array_merge( $args, array( 'per_page' => $per_page, 'page' => $page ) ) );
			$items   = $result['items'] ?? array();
			$servers = array_merge( $servers, $items );
			++$page;
			// The total check ends the loop even if a page keeps coming back full.
		} while ( count( $items ) === $per_page && count( $servers ) < (int) ( $result['total'] ?? 0 ) );

		return $servers;
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
	 * Count servers per kind in one query. A kind with no rows is reported as 0,
	 * so the result always has all three keys.
	 *
	 * @since  1.8.0
	 * @return array{standard: int, remote: int, bundle: int}
	 */
	public function count_by_kind(): array {
		global $wpdb;

		$counts = array(
			'standard' => 0,
			'remote'   => 0,
			'bundle'   => 0,
		);
		$rows   = $wpdb->get_results( "SELECT kind, COUNT(*) AS total FROM {$this->table()} GROUP BY kind", ARRAY_A ); // @codingStandardsIgnoreLine

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['kind'] ] ) ) {
				$counts[ $row['kind'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

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

		// The page content is worth keeping, but the copy is a new server:
		// it has not been published or launched yet.
		$settings = is_string( $data['settings'] ) ? json_decode( $data['settings'], true ) : null;
		if ( is_array( $settings ) && isset( $settings['launch_kit'] ) && is_array( $settings['launch_kit'] ) ) {
			$settings['launch_kit']['visibility']    = 'private';
			$settings['launch_kit']['celebrated_at'] = null;
			$data['settings']                        = wp_json_encode( $settings );
		}

		// The remote upstream's header value and client secret are credentials
		// too. The clone keeps the URL and modes and asks for them again.
		if ( is_array( $settings ) && is_array( $settings['remote'] ?? null ) ) {
			$settings['remote'] = \GetMCP\RemoteMcp\RemoteConfig::strip_secrets( $settings['remote'] );
			$data['settings']   = wp_json_encode( $settings );
		}

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

		// A bundle's content is its member list and the tools picked for each
		// member. The clone has none yet, so the expected revision is the
		// empty list's.
		if ( $original->is_bundle() ) {
			$members = \GetMCP\Gateway\Bundle::member_ids( $original );
			if ( array() !== $members ) {
				\GetMCP\Gateway\Bundle::set( $clone, $members, \GetMCP\Gateway\Bundle::revision( array() ), \GetMCP\Gateway\Bundle::selections( $original ) );
			}
		}

		return $clone;
	}

	/**
	 * Say whether a slug can be a server's endpoint, and offer a free one.
	 *
	 * The editor asks while the person types and the update path asks before
	 * it saves, so both refuse the same slugs. Create needs no check: it
	 * steps around a used or reserved slug on its own.
	 *
	 * @since  1.8.0
	 * @param  string $slug       Slug as typed.
	 * @param  int    $exclude_id Server whose own slug does not count as taken.
	 * @return array{slug: string, available: bool, reason: string|null, suggestion: string}
	 */
	public function slug_status( string $slug, int $exclude_id = 0 ): array {
		global $wpdb;

		$clean  = sanitize_title( $slug );
		$reason = null;

		if ( '' === $clean ) {
			$reason = 'empty';
		} elseif ( BuiltinServer::slug_is_reserved( $clean ) || \GetMCP\Gateway\McpGateway::slug_is_reserved( $clean ) ) {
			$reason = 'reserved';
		} elseif ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE slug = %s AND id != %d", $clean, $exclude_id ) ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$reason = 'taken';
		}

		return array(
			'slug'       => $clean,
			'available'  => null === $reason,
			'reason'     => $reason,
			'suggestion' => null === $reason ? $clean : $this->generate_unique_slug( '' === $clean ? 'server' : $clean, $exclude_id ),
		);
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
