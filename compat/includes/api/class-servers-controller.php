<?php
/**
 * Servers REST API controller.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Api;

use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Core\ServerTransfer;
use GetMCP\Core\ClaudeConnector;
use GetMCP\Licensing\LicenseTier;
use GetMCP\Utils\Encryption;
use GetMCP\Protocol\JsonRpcRouter;
use GetMCP\Auth\McpAuth;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST controller for MCP server CRUD operations.
 *
 * @since 1.0.0
 */
class ServersController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $namespace = 'getmcp/v1';

	/**
	 * Resource base.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $rest_base = 'servers';

	/**
	 * Server manager instance.
	 *
	 * @since 1.0.0
	 * @var ServerManager
	 */
	private ServerManager $manager;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->manager = new ServerManager();
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/oauth-users', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_oauth_users' ),
			'permission_callback' => array( $this, 'get_items_permissions_check' ),
			'args' => array(
				'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
				'search' => array( 'type' => 'string', 'default' => '', 'maxLength' => 100 ),
				'include' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'maxItems' => 100, 'default' => array() ),
			),
		) );
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_create_params(),
				),
			)
		);

		// UUID pattern: 8-4-4-4-12 hex characters, or numeric ID fallback.
		$uuid_pattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9]+';
		register_rest_route( $this->namespace, '/servers/(?P<id>' . $uuid_pattern . ')/oauth-users', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_allowed_oauth_users' ), 'permission_callback' => array( $this, 'get_item_permissions_check' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'set_allowed_oauth_users' ), 'permission_callback' => array( $this, 'update_item_permissions_check' ), 'args' => array(
				'allowed_user_ids' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'expected_user_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			) ),
		) );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_update_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/toggle',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'toggle_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		// Admin-only endpoint: fetch outbound credentials for the settings UI.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/credentials',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_credentials' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		// Everything needed to add this server to an AI client â€” Claude by
		// default, or Cursor via ?client=cursor. The route keeps its original
		// name so existing callers are unaffected.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/claude-connector',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'claude_connector' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'client' => array(
						'type'    => 'string',
						'enum'    => ClaudeConnector::CLIENTS,
						'default' => 'claude',
					),
				),
			)
		);

		// Whole-server export: configuration, tools, resources and prompts.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		// Whole-server import. Not nested under a server id: this one creates
		// the server rather than adding to an existing one.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => array(
					'name' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Admin-only endpoint: composite readiness snapshot for the server card badge.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/readiness',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_readiness' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		// Admin-only endpoint: run a JSON-RPC method server-side and return the
		// raw request/response envelopes for the protocol inspector.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/mailbox-test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_mailbox' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/mailbox-sync-tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_mailbox_tools' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_mailbox_tools' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'dismiss' => array(
							'type'        => 'boolean',
							'description' => __( 'Record these tools as skipped instead of installing them.', 'getmcp' ),
						),
					),
				),
			)
		);

		// The database connector's twins of the two mailbox endpoints above.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/database-test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_database' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/database-sync-tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_database_tools' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_database_tools' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'dismiss' => array(
							'type'        => 'boolean',
							'description' => __( 'Record these tools as skipped instead of installing them.', 'getmcp' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/inspect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'inspect_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'method'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'params'  => array(
						'required' => false,
					),
					'mode'    => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'loopback',
						'enum'              => array( 'loopback', 'http' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'api_key' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Check permissions for listing servers.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'getmcp_manage_servers' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view servers.', 'getmcp' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * List servers with pagination.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$result = $this->manager->list(
			array(
				// New kinds use /connections and its dedicated editor.
				'server_kind' => 'native',
				'per_page' => $request->get_param( 'per_page' ) ?? 20,
				'page'     => $request->get_param( 'page' ) ?? 1,
				'status'   => $request->get_param( 'status' ) ?? '',
				'search'   => $request->get_param( 'search' ) ?? '',
				'orderby'  => $request->get_param( 'orderby' ) ?? 'created_at',
				'order'    => $request->get_param( 'order' ) ?? 'DESC',
			)
		);

		// Batch-fetch counts to avoid N+1 queries.
		$tool_counts     = $this->batch_tool_counts( $result['items'] );
		$prompt_counts   = $this->batch_type_counts( $result['items'], 'getmcp_prompts' );
		$resource_counts = $this->batch_type_counts( $result['items'], 'getmcp_resources' );

		$items = array_map(
			fn( $server ) => $this->prepare_server_response( $server, $tool_counts, $prompt_counts, $resource_counts ),
			$result['items']
		);

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header(
			'X-WP-TotalPages',
			(string) ceil( $result['total'] / max( 1, (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) )
		);

		return $response;
	}

	/**
	 * Check permissions for creating a server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'getmcp_manage_servers' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to create servers.', 'getmcp' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Create a server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		// Tier-cap gate. Existing servers are grandfathered (Option A) â€” we
		// only block *new* creation when the user is at or over their cap.
		$tier  = LicenseTier::current();
		$count = $this->manager->get_count();
		if ( ! $tier->can_create_server( $count ) ) {
			return $this->tier_limit_error(
				'tier_limit_servers',
				sprintf(
					/* translators: 1: current count, 2: tier maximum, 3: tier id. */
					__( 'Your %3$s plan allows %2$d server(s); you have %1$d. Upgrade to add more.', 'getmcp' ),
					$count,
					$tier->max_servers(),
					strtoupper( $tier->id() )
				),
				$tier,
				array(
					'limit'   => $tier->max_servers(),
					'current' => $count,
				)
			);
		}

		// Auth-method gate (inbound). OAuth is only available on Pro/Agency.
		$desired_auth_type = (string) ( $request->get_param( 'auth_type' ) ?? 'none' );
		if ( ! $tier->allows_auth_method( $desired_auth_type ) ) {
			return $this->tier_limit_error(
				'tier_limit_auth_method',
				sprintf(
					/* translators: 1: requested auth method, 2: tier id. */
					__( 'The %1$s authentication method is not available on your %2$s plan.', 'getmcp' ),
					$desired_auth_type,
					strtoupper( $tier->id() )
				),
				$tier,
				array( 'auth_method' => $desired_auth_type )
			);
		}

		// Build the settings JSON string.
		$settings    = $request->get_param( 'settings' );
		$description = $request->get_param( 'description' );

		// Ensure settings is an array we can merge into.
		if ( is_string( $settings ) && ! empty( $settings ) ) {
			$settings = json_decode( $settings, true );
		}
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! empty( $description ) ) {
			$settings['description'] = sanitize_textarea_field( $description );
		}

		$instructions = $request->get_param( 'instructions' );
		if ( null !== $instructions && '' !== $instructions ) {
			$settings['instructions'] = self::sanitize_instructions( (string) $instructions );
		}

		$auth_type = $request->get_param( 'auth_type' ) ?? 'none';

		// Normalise credential fields: accept both JSON string and plain object.
		$auth_config               = $request->get_param( 'auth_config' );
		$auth_credentials          = $request->get_param( 'auth_credentials' );
		$test_auth_credentials     = $request->get_param( 'test_auth_credentials' );
		$outbound_auth_type        = $request->get_param( 'outbound_auth_type' );
		$outbound_auth_config      = $request->get_param( 'outbound_auth_config' );
		$outbound_auth_credentials = $request->get_param( 'outbound_auth_credentials' );
		if ( is_array( $auth_config ) )               { $auth_config               = wp_json_encode( $auth_config ); }
		if ( is_array( $auth_credentials ) )          { $auth_credentials          = wp_json_encode( $auth_credentials ); }
		if ( is_array( $test_auth_credentials ) )     { $test_auth_credentials     = wp_json_encode( $test_auth_credentials ); }
		if ( is_array( $outbound_auth_config ) )      { $outbound_auth_config      = wp_json_encode( $outbound_auth_config ); }
		if ( is_array( $outbound_auth_credentials ) ) { $outbound_auth_credentials = wp_json_encode( $outbound_auth_credentials ); }

		// test_auth_type always mirrors auth_type â€” they are the same concept.
		$test_auth_type = $auth_type;

		// OAuth (broker) uses `auth_credentials` to store the encrypted
		// upstream client_secret JSON, but does NOT use `test_auth_credentials`
		// (each MCP user authenticates upstream as themselves). Non-OAuth
		// modes use both. `none` wipes both unconditionally.
		$is_oauth_inbound = 'oauth' === $auth_type;
		$provider_validation = $this->validate_oauth_provider( $auth_type, $auth_config );
		if ( is_wp_error( $provider_validation ) ) {
			return $provider_validation;
		}
		if ( $is_oauth_inbound && \GetMCP\Auth\FirstPartyOAuth::config_is_native( $auth_config ) ) {
			$auth_credentials = null;
		}

		if ( 'none' === $auth_type ) {
			$auth_credentials      = null;
			$test_auth_credentials = null;
		} elseif ( $is_oauth_inbound ) {
			// Test creds are meaningless in broker mode â€” wipe them so an
			// older value can't accidentally route through the test path.
			$test_auth_credentials = null;
			if ( '' === $auth_credentials ) { $auth_credentials = null; }
			// auth_credentials format for broker: `{"client_secret":"..."}`.
			// validate_auth_credentials accepts an empty `required` list for
			// `oauth`, so this just guards JSON validity.
			if ( null !== $auth_credentials ) {
				$validation = $this->validate_auth_credentials( $auth_type, $auth_credentials );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
		} else {
			// Treat empty string as null (explicit clear signal from clients).
			if ( '' === $auth_credentials )      { $auth_credentials      = null; }
			if ( '' === $test_auth_credentials ) { $test_auth_credentials = null; }

			// Validate shape only when a value is actually provided.
			if ( null !== $auth_credentials ) {
				$validation = $this->validate_auth_credentials( $auth_type, $auth_credentials );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
			if ( null !== $test_auth_credentials ) {
				$validation = $this->validate_auth_credentials( $auth_type, $test_auth_credentials );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
		}

		// Outbound credentials are independent of inbound auth_type â€” they
		// govern how getMCP authenticates to the upstream API. None / unset
		// means "no upstream auth needed" (public API) or "passthrough" for
		// non-OAuth inbound modes.
		if ( null === $outbound_auth_type || 'none' === $outbound_auth_type ) {
			$outbound_auth_credentials = null;
		} else {
			if ( '' === $outbound_auth_credentials ) { $outbound_auth_credentials = null; }
			if ( null !== $outbound_auth_credentials ) {
				$validation = $this->validate_auth_credentials( $outbound_auth_type, $outbound_auth_credentials );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
		}

		try {
			$server = $this->manager->create(
				array(
					'name'                      => $request->get_param( 'name' ),
					'slug'                      => $request->get_param( 'slug' ) ?? '',
					'status'                    => $request->get_param( 'status' ) ?? 'active',
					'auth_type'                 => $auth_type,
					'auth_config'               => $auth_config,
					'auth_credentials'          => $auth_credentials,
					'test_auth_type'            => $test_auth_type,
					'test_auth_credentials'     => $test_auth_credentials,
					'outbound_auth_type'        => $outbound_auth_type,
					'outbound_auth_config'      => $outbound_auth_config,
					'outbound_auth_credentials' => $outbound_auth_credentials,
					'cors_origins'              => $request->get_param( 'cors_origins' ),
					'rate_limit_per_min'        => $request->get_param( 'rate_limit_per_min' ) ?? 60,
					'settings'                  => ! empty( $settings ) ? wp_json_encode( $settings ) : null,
				)
			);
		} catch ( \RuntimeException $e ) {
			return $this->encryption_error( $e );
		}

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_create_failed',
				__( 'Failed to create server.', 'getmcp' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			$this->prepare_server_response( $server ),
			201
		);
	}

	/**
	 * Check permissions for getting a server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'getmcp_manage_servers' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view this server.', 'getmcp' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Get a single server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->prepare_server_response( $server ) );
	}

	/**
	 * Check permissions for updating a server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'getmcp_manage_servers' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update this server.', 'getmcp' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Update a server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$server_obj = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );
		if ( ! $server_obj ) {
			return new WP_Error( 'getmcp_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
		}

		// Block switching INTO a disallowed auth method on the current tier.
		// Switching out of OAuth is always allowed even on Solo/unlicensed â€”
		// the grandfather rule lets users downshift their existing config.
		if ( $request->has_param( 'auth_type' ) ) {
			$tier              = LicenseTier::current();
			$desired_auth_type = (string) $request->get_param( 'auth_type' );
			if (
				$desired_auth_type !== $server_obj->auth_type
				&& ! $tier->allows_auth_method( $desired_auth_type )
			) {
				return $this->tier_limit_error(
					'tier_limit_auth_method',
					sprintf(
						/* translators: 1: requested auth method, 2: tier id. */
						__( 'The %1$s authentication method is not available on your %2$s plan.', 'getmcp' ),
						$desired_auth_type,
						strtoupper( $tier->id() )
					),
					$tier,
					array( 'auth_method' => $desired_auth_type )
				);
			}
		}

		$id   = $server_obj->id;
		$data = array();
		foreach ( array( 'authentication_revision' => '_authentication_revision', 'auth_config_mode' => '_auth_config_mode', 'clear_credentials' => '_clear_credentials' ) as $public => $internal ) { if ( $request->has_param( $public ) ) { $data[$internal] = $request->get_param( $public ); } }

		// test_auth_type is always derived from auth_type â€” not user-settable.
		$fields = array(
			'name', 'slug', 'status',
			'auth_type', 'auth_config', 'auth_credentials', 'test_auth_credentials',
			'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials',
			'cors_origins', 'rate_limit_per_min', 'settings',
		);

		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		try { $data = \GetMCPExtensions\AuthenticationSettings::prepare( $server_obj, $data ); $data['_auth_config_mode'] = 'replace'; }
		catch ( \GetMCPExtensions\SettingsConflict $e ) { return new WP_Error( 'authentication_conflict', $e->getMessage(), array( 'status' => 409 ) ); }
		catch ( \Throwable $e ) { return new WP_Error( 'authentication_invalid', 'Invalid authentication update.', array( 'status' => 400 ) ); }

		// Normalise credential fields: accept both JSON string and object.
		// The manager/encryption layer always works with JSON strings.
		foreach ( array( 'auth_credentials', 'test_auth_credentials', 'auth_config', 'outbound_auth_credentials', 'outbound_auth_config' ) as $json_field ) {
			if ( isset( $data[ $json_field ] ) && is_array( $data[ $json_field ] ) ) {
				$data[ $json_field ] = wp_json_encode( $data[ $json_field ] );
			}
		}

		// Process auth fields whenever any auth-related key is present in the update.
		$has_auth_update = isset( $data['auth_type'] ) || isset( $data['auth_credentials'] ) ||
			isset( $data['auth_config'] ) || isset( $data['test_auth_credentials'] ) ||
			isset( $data['outbound_auth_type'] ) || isset( $data['outbound_auth_config'] ) ||
			isset( $data['outbound_auth_credentials'] );

		if ( $has_auth_update ) {
			$server = $this->manager->get( $id );
			if ( ! $server ) {
				return new WP_Error( 'getmcp_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
			}

			// Effective auth_type after this update â€” governs all credential logic.
			$effective_auth_type = $data['auth_type'] ?? $server->auth_type;

			// test_auth_type always mirrors auth_type â€” keep them in sync.
			$data['test_auth_type'] = $effective_auth_type;

			$is_oauth_inbound = 'oauth' === $effective_auth_type;
			$effective_config = $data['auth_config'] ?? $server->auth_config;
			$provider_validation = $this->validate_oauth_provider( $effective_auth_type, $effective_config );
			if ( is_wp_error( $provider_validation ) ) {
				return $provider_validation;
			}
			if ( $is_oauth_inbound && \GetMCP\Auth\FirstPartyOAuth::config_is_native( $effective_config ) ) {
				$data['auth_credentials'] = null;
			}

			if ( $effective_auth_type === 'none' ) {
				// Switching to "none" wipes all credentials unconditionally.
				$data['auth_credentials']      = null;
				$data['test_auth_credentials'] = null;
			} elseif ( $is_oauth_inbound ) {
				// OAuth (broker): `auth_credentials` stores the encrypted
				// upstream client_secret JSON. `test_auth_credentials` is
				// meaningless because each MCP user authenticates upstream as
				// themselves â€” wipe it so a stale value can't bleed into the
				// outbound path.
				$data['test_auth_credentials'] = null;
				if ( isset( $data['auth_credentials'] ) && '' === $data['auth_credentials'] ) {
					$data['auth_credentials'] = null;
				}
				if ( isset( $data['auth_credentials'] ) && null !== $data['auth_credentials'] ) {
					$validation = $this->validate_auth_credentials( $effective_auth_type, $data['auth_credentials'] );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
				}
			} else {
				// Treat empty string as null (explicit clear signal from clients).
				if ( isset( $data['auth_credentials'] ) && '' === $data['auth_credentials'] ) {
					$data['auth_credentials'] = null;
				}
				if ( isset( $data['test_auth_credentials'] ) && '' === $data['test_auth_credentials'] ) {
					$data['test_auth_credentials'] = null;
				}

				// Validate shape only when a non-null value is being written.
				if ( isset( $data['auth_credentials'] ) && null !== $data['auth_credentials'] ) {
					$validation = $this->validate_auth_credentials( $effective_auth_type, $data['auth_credentials'] );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
				}
				if ( isset( $data['test_auth_credentials'] ) && null !== $data['test_auth_credentials'] ) {
					$validation = $this->validate_auth_credentials( $effective_auth_type, $data['test_auth_credentials'] );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
				}
			}

			// Outbound credentials are independent of inbound auth_type â€” they
			// govern how getMCP authenticates to the upstream API. Treat empty
			// string / "none" as a clear signal so the operator can wipe them.
			$effective_outbound = $data['outbound_auth_type'] ?? $server->outbound_auth_type;

			if ( null === $effective_outbound || 'none' === $effective_outbound ) {
				$data['outbound_auth_credentials'] = null;
			} else {
				if ( isset( $data['outbound_auth_credentials'] ) && '' === $data['outbound_auth_credentials'] ) {
					$data['outbound_auth_credentials'] = null;
				}
				if ( isset( $data['outbound_auth_credentials'] ) && null !== $data['outbound_auth_credentials'] ) {
					$validation = $this->validate_auth_credentials( $effective_outbound, $data['outbound_auth_credentials'] );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
				}
			}
		}

		// Merge top-level description / instructions into settings JSON.
		// These are exposed as first-class API fields for ergonomics but
		// stored inside the freeform `settings` blob so we don't need a
		// dedicated DB column for every new structured field.
		$has_description  = $request->has_param( 'description' );
		$has_instructions = $request->has_param( 'instructions' );

		if ( $has_description || $has_instructions ) {
			// Load existing settings from the server.
			$current  = $this->manager->get( $id );
			$existing = $current ? $current->settings : null;
			$existing_settings = is_string( $existing ) ? json_decode( $existing, true ) : array();
			if ( ! is_array( $existing_settings ) ) {
				$existing_settings = array();
			}

			// Overlay any settings sent in the request.
			if ( isset( $data['settings'] ) ) {
				$sent = is_string( $data['settings'] ) ? json_decode( $data['settings'], true ) : $data['settings'];
				if ( is_array( $sent ) ) {
					$existing_settings = array_merge( $existing_settings, $sent );
				}
			}

			if ( $has_description ) {
				$existing_settings['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
			}

			if ( $has_instructions ) {
				// Instructions are sent to LLMs verbatim, so we don't HTML-escape
				// them â€” but we DO cap length to avoid blowing up the system
				// prompt and ban control chars so a copy-paste can't smuggle in
				// terminal escape sequences.
				$raw = (string) $request->get_param( 'instructions' );
				$existing_settings['instructions'] = self::sanitize_instructions( $raw );
			}

			$existing_settings = $this->sanitize_pii_redaction( $existing_settings );
			$existing_settings = $this->sanitize_icon_data( $existing_settings );
			$existing_settings = $this->sanitize_logo( $existing_settings );
			$existing_settings = $this->sanitize_variables( $existing_settings );
			$existing_settings = $this->sanitize_mailbox( $existing_settings, $id );
			$existing_settings = $this->sanitize_database( $existing_settings, $id );
			$data['settings']  = wp_json_encode( $existing_settings );
		} elseif ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			// Settings sent without description/instructions â€” still needs JSON encoding.
			$sanitized        = $this->sanitize_pii_redaction( $data['settings'] );
			$sanitized        = $this->sanitize_icon_data( $sanitized );
			$sanitized        = $this->sanitize_logo( $sanitized );
			$sanitized        = $this->sanitize_variables( $sanitized );
			$sanitized        = $this->sanitize_mailbox( $sanitized, $id );
			$sanitized        = $this->sanitize_database( $sanitized, $id );
			$data['settings'] = wp_json_encode( $sanitized );
		}

		try {
			$server = $this->manager->update( $id, $data );
		} catch ( \GetMCPExtensions\SettingsConflict $e ) { return new WP_Error( 'authentication_conflict', $e->getMessage(), array( 'status' => 409 ) );
		} catch ( \RuntimeException $e ) {
			return $this->encryption_error( $e );
		}

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_update_failed',
				__( 'Failed to update server.', 'getmcp' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( $this->prepare_server_response( $server ) );
	}

	/**
	 * Check permissions for deleting a server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'getmcp_manage_servers' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to delete this server.', 'getmcp' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Delete a server.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$server_obj = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );
		if ( ! $server_obj ) {
			return new WP_Error( 'getmcp_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
		}
		$id     = $server_obj->id;
		$result = $this->manager->delete( $id );

		if ( ! $result ) {
			return new WP_Error(
				'getmcp_delete_failed',
				__( 'Failed to delete server.', 'getmcp' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Toggle a server's status between active and paused.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_item( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );
		$id     = $server ? $server->id : 0;

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		$new_status = 'active' === $server->status ? 'paused' : 'active';
		$updated    = $this->manager->update( $id, array( 'status' => $new_status ) );

		if ( ! $updated ) {
			return new WP_Error(
				'getmcp_toggle_failed',
				__( 'Failed to toggle server status.', 'getmcp' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( $this->prepare_server_response( $updated ) );
	}

	/**
	 * Map a RuntimeException from the encryption layer to a 503 WP_Error.
	 *
	 * @since  1.0.0
	 * @param  \RuntimeException $e Exception thrown by the encryption layer.
	 * @return WP_Error
	 */
	/**
	 * Build a 403 WP_Error for tier-limit rejections.
	 *
	 * @since  1.0.0
	 * @param  string                    $code  Error code (`tier_limit_*`).
	 * @param  string                    $msg   Human-readable message.
	 * @param  LicenseTier               $tier  Current tier (for surfacing upgrade_url + plan).
	 * @param  array<string, mixed>      $extra Extra fields to expose in the error data.
	 * @return WP_Error
	 */
	private function tier_limit_error( string $code, string $msg, LicenseTier $tier, array $extra = array() ): WP_Error {
		return new WP_Error(
			$code,
			$msg,
			array_merge(
				array(
					'status'       => 403,
					'tier'         => $tier->id(),
					'plan'         => $tier->plan_name(),
					'is_expired'   => $tier->is_expired(),
					'upgrade_url'  => $tier->upgrade_url(),
				),
				$extra
			)
		);
	}

	private function encryption_error( \RuntimeException $e ): WP_Error {
		return new WP_Error(
			'getmcp_encryption_unavailable',
			$e->getMessage(),
			array( 'status' => 503 )
		);
	}

	/**
	 * Duplicate a server with all its tools, resources, and prompts.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error( 'getmcp_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
		}

		try {
			$clone = $this->manager->duplicate( $server->id );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'getmcp_duplicate_failed',
				WP_DEBUG ? $e->getMessage() : __( 'Failed to duplicate server.', 'getmcp' ),
				array( 'status' => 500 )
			);
		}

		if ( ! $clone ) {
			$db_error = $wpdb->last_error;
			return new WP_Error(
				'getmcp_duplicate_failed',
				WP_DEBUG ? ( $db_error ?: __( 'Failed to duplicate server.', 'getmcp' ) ) : __( 'Failed to duplicate server.', 'getmcp' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( $this->prepare_server_response( $clone ), 201 );
	}

	/**
	 * Validate that auth_credentials matches the expected shape for the given auth_type.
	 *
	 * @since  1.0.0
	 * @param  string      $auth_type   One of none|bearer|api-key|basic.
	 * @param  string|null $credentials JSON string of credentials.
	 * @return true|WP_Error
	 */
	/** Bounded AJAX directory; blank searches never list the site users. */
	public function get_oauth_users( $request ): WP_REST_Response|WP_Error {
		$result = \GetMCP\Auth\OAuthUserAccess::search( $request->get_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	public function get_allowed_oauth_users( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request['id'] );
		if ( ! $server ) { return new WP_Error( 'getmcp_not_found', 'Server not found.', array( 'status' => 404 ) ); }
		$result = \GetMCP\Auth\OAuthUserAccess::get( $server->id );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	public function set_allowed_oauth_users( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request['id'] );
		if ( ! $server ) { return new WP_Error( 'getmcp_not_found', 'Server not found.', array( 'status' => 404 ) ); }
		$result = \GetMCP\Auth\OAuthUserAccess::set( $server->id, $request->get_param( 'allowed_user_ids' ), $request->get_param( 'expected_user_ids' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	private function validate_oauth_provider( string $auth_type, ?string $config ): true|WP_Error {
		if ( 'oauth' !== $auth_type || null === $config || '' === $config ) {
			return true;
		}
		$decoded = json_decode( $config, true );
		if ( ! is_array( $decoded ) || ( isset( $decoded['provider'] ) && ! in_array( $decoded['provider'], array( 'getmcp', 'external' ), true ) ) ) {
			return new WP_Error( 'getmcp_invalid_oauth_provider', __( 'OAuth provider must be getmcp or external.', 'getmcp' ), array( 'status' => 400 ) );
		}
		if ( 'getmcp' === ( $decoded['provider'] ?? '' ) ) {
			return \GetMCP\Auth\OAuthUserAccess::validate_ids( $decoded['allowed_user_ids'] ?? null );
		}
		return true;
	}

	private function validate_auth_credentials( string $auth_type, ?string $credentials ): true|WP_Error {
		if ( ! $credentials ) {
			return true;
		}

		$creds = json_decode( $credentials, true );

		if ( ! is_array( $creds ) ) {
			return new WP_Error(
				'getmcp_invalid_credentials',
				__( 'auth_credentials must be a valid JSON object.', 'getmcp' ),
				array( 'status' => 400 )
			);
		}

		// Keep this match in lock-step with the auth_type / outbound_auth_type
		// enums declared in get_create_params() and get_update_params(). Adding
		// a value to one without updating the other will land in the default
		// arm and fail closed â€” better than silently no-opping validation.
		$required = match ( $auth_type ) {
			'bearer'  => array( 'token' ),
			'api-key' => array( 'name', 'value', 'location' ),
			'basic'   => array( 'username', 'password' ),
			'oauth' => array(),
			default   => null,
		};

		if ( null === $required ) {
			return new WP_Error(
				'getmcp_invalid_credentials',
				/* translators: %s: auth_type value */
				sprintf( __( 'Cannot validate credentials for unknown auth_type "%s".', 'getmcp' ), $auth_type ),
				array( 'status' => 400 )
			);
		}

		$missing = array_filter( $required, fn( $key ) => empty( $creds[ $key ] ) );

		if ( ! empty( $missing ) ) {
			/* translators: 1: auth type, 2: comma-separated list of missing fields */
			$message = sprintf(
				__( 'auth_credentials for auth_type "%1$s" must include: %2$s.', 'getmcp' ),
				$auth_type,
				implode( ', ', $missing )
			);
			return new WP_Error( 'getmcp_invalid_credentials', $message, array( 'status' => 400 ) );
		}

		if ( $auth_type === 'api-key' && ! in_array( $creds['location'] ?? '', array( 'header', 'query' ), true ) ) {
			return new WP_Error(
				'getmcp_invalid_credentials',
				__( 'auth_credentials.location must be "header" or "query" for api-key auth.', 'getmcp' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Return outbound credentials (production + test) for the admin UI only.
	 * Requires WP cookie auth (capability check via update_item_permissions_check).
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_credentials( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( \GetMCPExtensions\AuthenticationSettings::public_credentials( $server ) );
	}

	/**
	 * Composite readiness snapshot for a single server.
	 *
	 * Runs a set of lightweight, side-effect-free checks (status, capability
	 * presence, inbound auth, transport security, CORS, rate limit) and rolls
	 * them up into a single traffic-light status the admin UI renders as a
	 * badge on the server card. Each check is `pass` / `warn` / `fail`; any
	 * `fail` makes the server `not_ready`, any `warn` makes it `degraded`,
	 * otherwise `ready`.
	 *
	 * @since  1.16.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_readiness( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		global $wpdb;

		$tool_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_tools WHERE server_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$server->id
			)
		);
		$resource_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_resources WHERE server_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$server->id
			)
		);
		$prompt_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_prompts WHERE server_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$server->id
			)
		);

		$endpoint_url = $server->get_endpoint_url();
		$is_https     = 0 === stripos( $endpoint_url, 'https://' );

		$checks = array(
			array(
				'id'      => 'status',
				'label'   => __( 'Server active', 'getmcp' ),
				'status'  => 'active' === $server->status ? 'pass' : 'fail',
				'message' => 'active' === $server->status
					? __( 'Server is active and accepting requests.', 'getmcp' )
					: sprintf(
						/* translators: %s: current server status */
						__( 'Server is "%s" â€” it will not respond to MCP clients until activated.', 'getmcp' ),
						$server->status
					),
			),
			array(
				'id'      => 'capabilities',
				'label'   => __( 'Has capabilities', 'getmcp' ),
				'status'  => ( $tool_count + $resource_count + $prompt_count ) > 0 ? 'pass' : 'fail',
				'message' => sprintf(
					/* translators: 1: tool count, 2: resource count, 3: prompt count */
					__( '%1$d tools, %2$d resources, %3$d prompts.', 'getmcp' ),
					$tool_count,
					$resource_count,
					$prompt_count
				),
			),
			array(
				'id'      => 'auth',
				'label'   => __( 'Inbound authentication', 'getmcp' ),
				'status'  => 'none' !== $server->auth_type ? 'pass' : 'warn',
				'message' => 'none' !== $server->auth_type
					? sprintf(
						/* translators: %s: auth type */
						__( 'Protected with "%s" authentication.', 'getmcp' ),
						$server->auth_type
					)
					: __( 'No inbound auth â€” anyone with the URL can call this server.', 'getmcp' ),
			),
			array(
				'id'      => 'transport',
				'label'   => __( 'Secure transport', 'getmcp' ),
				'status'  => $is_https ? 'pass' : 'warn',
				'message' => $is_https
					? __( 'Endpoint is served over HTTPS.', 'getmcp' )
					: __( 'Endpoint is not HTTPS â€” fine for local development, not for production.', 'getmcp' ),
			),
			// CORS is deliberately not a readiness check: an open origin list is
			// surfaced inline next to the CORS Origins setting instead, so it
			// never drags the server badge down to Degraded.
			$this->rate_limit_check( $server ),
		);

		$score = array( 'pass' => 0, 'warn' => 0, 'fail' => 0 );
		foreach ( $checks as $check ) {
			++$score[ $check['status'] ];
		}

		if ( $score['fail'] > 0 ) {
			$status = 'not_ready';
		} elseif ( $score['warn'] > 0 ) {
			$status = 'degraded';
		} else {
			$status = 'ready';
		}

		return new WP_REST_Response(
			array(
				'status'     => $status,
				'score'      => $score,
				'checks'     => $checks,
				'checked_at' => gmdate( 'c' ),
			),
			200
		);
	}

	/**
	 * Readiness check for the server's rate limit.
	 *
	 * The stored value has three meanings, and only one of them is a real
	 * warning: a positive number is the limit itself, 0 defers to the global
	 * `default_rate_limit`, and a negative number is the operator deliberately
	 * turning limiting off. Reporting all three the same way told operators
	 * their configured server was unlimited when it was not.
	 *
	 * @since  1.0.0
	 * @param  Server $server Server instance.
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function rate_limit_check( Server $server ): array {
		$limit = (int) $server->rate_limit_per_min;

		if ( $limit < 0 ) {
			$status  = 'warn';
			$message = __( 'Rate limiting is disabled for this server â€” it accepts unlimited requests.', 'getmcp' );
		} elseif ( $limit > 0 ) {
			$status  = 'pass';
			/* translators: %d: requests per minute */
			$message = sprintf( __( 'Limited to %d requests/minute.', 'getmcp' ), $limit );
		} else {
			$settings = get_option( 'getmcp_settings', array() );
			$default  = isset( $settings['default_rate_limit'] ) ? absint( $settings['default_rate_limit'] ) : 60;

			$status  = $default > 0 ? 'pass' : 'warn';
			$message = $default > 0
				/* translators: %d: requests per minute */
				? sprintf( __( 'Limited to %d requests/minute by the global default.', 'getmcp' ), $default )
				: __( 'No rate limit configured â€” the server accepts unlimited requests.', 'getmcp' );
		}

		return array(
			'id'      => 'rate_limit',
			'label'   => __( 'Rate limiting', 'getmcp' ),
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Run a JSON-RPC method against the server server-side and return the raw
	 * request/response envelopes for the protocol inspector.
	 *
	 * This is a loopback: it builds a JSON-RPC 2.0 request from the admin's
	 * `{method, params}` payload, feeds it straight through JsonRpcRouter, and
	 * hands back exactly what an MCP client would receive at the protocol
	 * level (this is not an HTTP-level trace â€” no transport headers, session,
	 * or CORS handling). The endpoint is admin-gated by capability + nonce;
	 * McpAuth is reset first so the loopback runs unrestricted rather than
	 * inheriting any request-scoped API-key/OAuth scopes.
	 *
	 * Note: `tools/call` executes the real upstream request, same as the
	 * per-tool tester â€” the UI defaults to read-only methods for this reason.
	 *
	 * @since  1.16.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function inspect_item( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		$method = trim( (string) $request->get_param( 'method' ) );
		if ( '' === $method ) {
			return new WP_Error(
				'getmcp_invalid_method',
				__( 'A JSON-RPC method name is required.', 'getmcp' ),
				array( 'status' => 400 )
			);
		}

		$params = $request->get_param( 'params' );
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$envelope = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
			'params'  => $params,
		);

		if ( 'http' === $request->get_param( 'mode' ) ) {
			return $this->inspect_over_http( $server, $envelope, (string) $request->get_param( 'api_key' ) );
		}

		// Clean, unrestricted admin loopback â€” no client scope state in play.
		McpAuth::reset();

		$router = new JsonRpcRouter( $server );

		$start       = microtime( true );
		$response    = $router->process( (string) wp_json_encode( $envelope ) );
		$duration_ms = round( ( microtime( true ) - $start ) * 1000, 2 );

		return new WP_REST_Response(
			array(
				'mode'             => 'loopback',
				'request'          => $envelope,
				'response'         => $response,
				'duration_ms'      => $duration_ms,
				'protocol_version' => GETMCP_MCP_PROTOCOL_VERSION,
			),
			200
		);
	}

	/**
	 * Run an inspector request as a real HTTP round-trip against the server's
	 * own public `/mcp/{slug}` endpoint.
	 *
	 * Unlike the loopback, this exercises the full transport stack â€” rewrite
	 * dispatch, inbound auth (McpAuth), rate limiting, CORS/protocol headers,
	 * and version negotiation â€” exactly as an external MCP client would hit it.
	 * The optional API key is injected per the server's configured auth type,
	 * used for this one request only, never stored, and masked in the returned
	 * request trace.
	 *
	 * @since  1.17.0
	 * @param  \GetMCP\Core\Server  $server   Server instance.
	 * @param  array<string, mixed> $envelope JSON-RPC request envelope.
	 * @param  string               $api_key  Optional credential for inbound auth.
	 * @return WP_REST_Response|WP_Error
	 */
	private function inspect_over_http( \GetMCP\Core\Server $server, array $envelope, string $api_key ): WP_REST_Response|WP_Error {
		$url     = $server->get_endpoint_url();
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json, text/event-stream',
		);

		// Spec: clients include the negotiated version header on requests
		// after initialize (initialize itself carries it in the body).
		if ( 'initialize' !== $envelope['method'] ) {
			$headers['Mcp-Protocol-Version'] = GETMCP_MCP_PROTOCOL_VERSION;
		}

		if ( '' !== $api_key ) {
			switch ( $server->auth_type ) {
				case 'api-key':
					$config   = is_string( $server->auth_config ) ? json_decode( $server->auth_config, true ) : null;
					$name     = is_array( $config ) && ! empty( $config['name'] ) ? (string) $config['name'] : 'X-API-Key';
					$location = is_array( $config ) && ! empty( $config['location'] ) ? (string) $config['location'] : 'header';

					if ( 'query' === $location ) {
						$url = add_query_arg( rawurlencode( $name ), rawurlencode( $api_key ), $url );
					} else {
						$headers[ $name ] = $api_key;
					}
					break;
				case 'basic':
					// Credential entered as "user:password".
					$headers['Authorization'] = 'Basic ' . base64_encode( $api_key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					break;
				default:
					$headers['Authorization'] = 'Bearer ' . $api_key;
					break;
			}
		}

		$start    = microtime( true );
		$response = wp_remote_post(
			$url,
			array(
				'headers'   => $headers,
				'body'      => (string) wp_json_encode( $envelope ),
				'timeout'   => 30,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
			)
		);
		$duration_ms = round( ( microtime( true ) - $start ) * 1000, 2 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'getmcp_inspect_http_failed',
				sprintf(
					/* translators: %s: HTTP error message. */
					__( 'HTTP request to the MCP endpoint failed: %s. If loopback requests are blocked on this host, use Loopback mode instead.', 'getmcp' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		// Mask the credential everywhere it could appear in the trace.
		$masked_headers = $headers;
		foreach ( array( 'Authorization' ) as $sensitive ) {
			if ( isset( $masked_headers[ $sensitive ] ) ) {
				$masked_headers[ $sensitive ] = preg_replace( '/\S+$/', 'â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢', $masked_headers[ $sensitive ] );
			}
		}
		if ( '' !== $api_key ) {
			$masked_headers = array_map(
				static fn( string $value ): string => str_replace( $api_key, 'â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢', $value ),
				$masked_headers
			);
			$url            = str_replace( rawurlencode( $api_key ), '********', $url );
		}

		$raw_headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			$raw_headers = $raw_headers->getAll();
		}
		$response_headers = array();
		foreach ( (array) $raw_headers as $key => $value ) {
			$response_headers[ (string) $key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		return new WP_REST_Response(
			array(
				'mode'             => 'http',
				'request'          => array(
					'url'     => $url,
					'method'  => 'POST',
					'headers' => $masked_headers,
					'body'    => $envelope,
				),
				'response'         => array(
					'status_code' => (int) wp_remote_retrieve_response_code( $response ),
					'headers'     => $response_headers,
					'body'        => null !== $decoded ? $decoded : $body,
				),
				'duration_ms'      => $duration_ms,
				'protocol_version' => GETMCP_MCP_PROTOCOL_VERSION,
			),
			200
		);
	}

	/**
	 * Batch-fetch tool counts for a list of servers in a single query.
	 *
	 * @since  1.0.0
	 * @param  \GetMCP\Core\Server[] $servers Server instances.
	 * @return array<int, int> Map of server_id => tool count.
	 */
	private function batch_tool_counts( array $servers ): array {
		if ( empty( $servers ) ) {
			return array();
		}

		global $wpdb;

		$ids          = array_map( fn( $s ) => $s->id, $servers );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT server_id, COUNT(*) AS cnt FROM {$wpdb->prefix}getmcp_tools WHERE server_id IN ({$placeholders}) GROUP BY server_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			),
			ARRAY_A
		);

		$map = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row['server_id'] ] = (int) $row['cnt'];
			}
		}

		return $map;
	}

	/**
	 * Batch-fetch counts for a given table (prompts, resources) keyed by server_id.
	 *
	 * @since  1.0.0
	 * @param  \GetMCP\Core\Server[] $servers    Server instances.
	 * @param  string                $table_name Unprefixed table name (e.g. 'getmcp_prompts').
	 * @return array<int, int> Map of server_id => count.
	 */
	private function batch_type_counts( array $servers, string $table_name ): array {
		if ( empty( $servers ) ) {
			return array();
		}

		global $wpdb;

		$ids          = array_map( fn( $s ) => $s->id, $servers );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $wpdb->prefix . $table_name;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT server_id, COUNT(*) AS cnt FROM {$table} WHERE server_id IN ({$placeholders}) GROUP BY server_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			),
			ARRAY_A
		);

		$map = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row['server_id'] ] = (int) $row['cnt'];
			}
		}

		return $map;
	}

	/**
	 * Prepare server data for REST response.
	 *
	 * @since  1.0.0
	 * @param  \GetMCP\Core\Server $server          Server instance.
	 * @param  array<int, int>     $tool_counts     Optional pre-fetched tool counts keyed by server ID.
	 * @param  array<int, int>     $prompt_counts   Optional pre-fetched prompt counts keyed by server ID.
	 * @param  array<int, int>     $resource_counts Optional pre-fetched resource counts keyed by server ID.
	 * @return array<string, mixed>
	 */
	private function prepare_server_response( \GetMCP\Core\Server $server, array $tool_counts = array(), array $prompt_counts = array(), array $resource_counts = array() ): array {
		$data                 = $server->to_array();
		$data['endpoint_url'] = $server->get_endpoint_url();

		// Whether the stored outbound credential is still the placeholder an
		// AI assistant wrote â€” the Authentication tab steers the operator to
		// replace it. Never the credential itself, and a blob that cannot be
		// decrypted reads as "not a placeholder" rather than failing the
		// request.
		$data['api_auth_pending'] = false;
		if ( ! empty( $server->outbound_auth_credentials ) ) {
			try {
				$decrypted                = \GetMCP\Utils\Encryption::decrypt( $server->outbound_auth_credentials );
				$data['api_auth_pending'] = is_string( $decrypted ) && str_contains( $decrypted, \GetMCP\Core\Server::PLACEHOLDER );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// Decode settings JSON so the frontend receives an object.
		if ( is_string( $data['settings'] ) && ! empty( $data['settings'] ) ) {
			$decoded = json_decode( $data['settings'], true );
			$data['settings'] = is_array( $decoded ) ? $decoded : null;
		}

		/*
		 * The mailbox password never leaves the server, not even encrypted â€”
		 * an encrypted blob in an API response is still a credential being
		 * handed to whatever is holding the admin session. The form gets a
		 * boolean instead and posts back the unchanged sentinel.
		 */
		foreach ( array( 'mailbox', 'database' ) as $connector_block ) {
			if ( is_array( $data['settings'] ?? null ) && isset( $data['settings'][ $connector_block ] ) && is_array( $data['settings'][ $connector_block ] ) ) {
				$data['settings'][ $connector_block ]['has_password'] = ! empty( $data['settings'][ $connector_block ]['password'] );
				unset( $data['settings'][ $connector_block ]['password'] );
			}
		}

		// Always re-resolve logo_url server-side. Don't trust the
		// persisted value â€” the attachment might have been deleted or
		// regenerated since save. This keeps frontend rendering correct
		// without requiring a settings re-save.
		if ( is_array( $data['settings'] ?? null ) ) {
			$logo_id = isset( $data['settings']['logo_id'] ) ? absint( $data['settings']['logo_id'] ) : 0;
			if ( $logo_id > 0 && 'attachment' === get_post_type( $logo_id ) ) {
				$url = wp_get_attachment_image_url( $logo_id, 'thumbnail' );
				if ( ! $url ) {
					$url = wp_get_attachment_url( $logo_id );
				}
				$data['settings']['logo_url'] = $url ? esc_url_raw( $url ) : '';
			} elseif ( isset( $data['settings']['logo_id'] ) || isset( $data['settings']['logo_url'] ) ) {
				// Stored logo_id is stale (attachment deleted) â€” blank
				// both so the UI falls back cleanly to the letter swatch.
				$data['settings']['logo_id']  = 0;
				$data['settings']['logo_url'] = '';
			}
		}

		// Use pre-fetched counts if available, otherwise query individually (single-item endpoints).
		global $wpdb;

		if ( isset( $tool_counts[ $server->id ] ) ) {
			$data['tool_count'] = $tool_counts[ $server->id ];
		} else {
			$data['tool_count'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_tools WHERE server_id = %d",
					$server->id
				)
			);
		}

		if ( isset( $prompt_counts[ $server->id ] ) ) {
			$data['prompt_count'] = $prompt_counts[ $server->id ];
		} else {
			$data['prompt_count'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_prompts WHERE server_id = %d",
					$server->id
				)
			);
		}

		if ( isset( $resource_counts[ $server->id ] ) ) {
			$data['resource_count'] = $resource_counts[ $server->id ];
		} else {
			$data['resource_count'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_resources WHERE server_id = %d",
					$server->id
				)
			);
		}

		// Normalise timestamps to ISO 8601 with explicit UTC marker.
		if ( ! empty( $data['created_at'] ) ) {
			$data['created_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $data['created_at'] ) );
		}
		if ( ! empty( $data['updated_at'] ) ) {
			$data['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $data['updated_at'] ) );
		}

		// Strip all credential / auth-detail fields from the main response.
		// The credentials endpoint (GET /servers/{id}/credentials) provides these when needed.
		unset(
			$data['auth_credentials'],
			$data['test_auth_type'],
			$data['test_auth_credentials'],
			$data['outbound_auth_credentials']
		);

		// Move created_at / updated_at to the very end of the response.
		$created_at = $data['created_at'] ?? null;
		$updated_at = $data['updated_at'] ?? null;
		unset( $data['created_at'], $data['updated_at'] );
		if ( null !== $created_at ) {
			$data['created_at'] = $created_at;
		}
		if ( null !== $updated_at ) {
			$data['updated_at'] = $updated_at;
		}

		return $data;
	}

	/**
	 * Get collection query parameters.
	 *
	 * @since  1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			// `minimum`/`maximum` do nothing on their own: WP only range-checks
			// an argument when it has a validate_callback, so without this the
			// declared bounds were documentation and `per_page=99999` fetched
			// every row.
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'   => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'active', 'paused', 'draft' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'type'              => 'string',
				// Default to last_used_at so the most-active servers
				// surface at the top of the list automatically. Servers
				// with zero traffic fall back to their created_at order
				// via COALESCE in the manager.
				'default'           => 'last_used_at',
				'enum'              => array( 'id', 'name', 'slug', 'status', 'created_at', 'updated_at', 'last_used_at' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order'    => array(
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => array( 'ASC', 'DESC' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get create endpoint parameters.
	 *
	 * @since  1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_params(): array {
		return array(
			'name'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'        => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'      => array(
				'type'    => 'string',
				'default' => 'active',
				'enum'    => array( 'active', 'paused', 'draft' ),
			),
			'description' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'instructions' => array(
				'type'    => 'string',
				'default' => '',
				// Intentionally NOT routed through sanitize_textarea_field â€”
				// sanitize_instructions() runs the right stricter cleanup
				// (control-char strip + length cap) inside the controller.
			),
			'auth_type'             => array(
				'type'    => 'string',
				'default' => 'none',
				'enum'    => array( 'none', 'bearer', 'api-key', 'basic', 'oauth' ),
			),
			'auth_config'           => array(
				'type' => array( 'string', 'object' ),
			),
			'auth_credentials'      => array(
				'type' => array( 'string', 'object' ),
			),
			'test_auth_credentials' => array(
				'type' => array( 'string', 'object' ),
			),
			'outbound_auth_type'        => array(
				'type' => 'string',
				'enum' => array( 'none', 'bearer', 'api-key', 'basic' ),
			),
			'outbound_auth_config'      => array(
				'type' => array( 'string', 'object' ),
			),
			'outbound_auth_credentials' => array(
				'type' => array( 'string', 'object' ),
			),
			'cors_origins'          => array(
				'type' => 'string',
			),
			'rate_limit_per_min'    => array(
				'type'    => 'integer',
				'default' => 60,
				// -1 disables limiting outright; 0 inherits the global default.
				'minimum' => -1,
			),
		);
	}

	/**
	 * Get update endpoint parameters.
	 *
	 * @since  1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return array(
			'name'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'      => array(
				'type' => 'string',
				'enum' => array( 'active', 'paused', 'draft' ),
			),
			'description' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'instructions' => array(
				'type' => 'string',
				// See note on create-endpoint args â€” sanitize_instructions()
				// runs the stricter cleanup inside the controller.
			),
			'auth_type'             => array(
				'type' => 'string',
				'enum' => array( 'none', 'bearer', 'api-key', 'basic', 'oauth' ),
			),
			'auth_config'           => array(
				'type' => array( 'string', 'object' ),
			),
			'auth_credentials'      => array(
				'type' => array( 'string', 'object' ),
			),
			'test_auth_credentials' => array(
				'type' => array( 'string', 'object' ),
			),
			'outbound_auth_type'        => array(
				'type' => 'string',
				'enum' => array( 'none', 'bearer', 'api-key', 'basic' ),
			),
			'outbound_auth_config'      => array(
				'type' => array( 'string', 'object' ),
			),
			'outbound_auth_credentials' => array(
				'type' => array( 'string', 'object' ),
			),
			'cors_origins'          => array(
				'type' => 'string',
			),
			'rate_limit_per_min'    => array(
				'type'    => 'integer',
				// -1 disables limiting outright; 0 inherits the global default.
				'minimum' => -1,
			),
		);
	}

	/**
	 * Sanitize per-server logo identity in settings.
	 *
	 * Two storage shapes are accepted, both stripped of any client lies:
	 *  - `logo_id`  (preferred): WordPress Media Library attachment ID.
	 *                 Must be an int that resolves to an existing
	 *                 `attachment` post. Anything else â†’ 0.
	 *  - `logo_url` (read-only): we *derive* this server-side from
	 *                 logo_id and overwrite whatever the client sent â€”
	 *                 the URL is purely a frontend convenience so the
	 *                 admin can preview without waiting for a re-fetch.
	 *
	 * @since  1.13.0
	 * @param  array<string, mixed> $settings Settings array.
	 * @return array<string, mixed> Same shape with logo_id / logo_url validated.
	 */
	private function sanitize_logo( array $settings ): array {
		// logo_id: trust nothing the client sent â€” verify it actually
		// points at an attachment we own.
		if ( array_key_exists( 'logo_id', $settings ) ) {
			$logo_id = absint( $settings['logo_id'] );
			if ( $logo_id > 0 && 'attachment' === get_post_type( $logo_id ) ) {
				$settings['logo_id'] = $logo_id;
				// Always re-resolve URL â€” never trust the client's value.
				$url = wp_get_attachment_image_url( $logo_id, 'thumbnail' );
				if ( ! $url ) {
					$url = wp_get_attachment_url( $logo_id );
				}
				$settings['logo_url'] = $url ? esc_url_raw( $url ) : '';
			} else {
				// 0 or invalid â†’ clear both.
				$settings['logo_id']  = 0;
				$settings['logo_url'] = '';
			}
		}

		return $settings;
	}

	/**
	 * Sanitize per-server icon (base64-encoded PNG data URL).
	 *
	 * LEGACY: superseded by sanitize_logo() in 1.13.0. Kept so existing
	 * rows that still hold `icon_data` continue to round-trip cleanly
	 * until a new logo is picked from the Media Library.
	 *
	 * Stored in settings.icon_data as a `data:image/png;base64,...`
	 * string. We strip anything that doesn't match that shape and cap
	 * the encoded length to keep a malicious / oversized blob out of
	 * the settings JSON. Frontend already validates dimensions, but the
	 * backend never trusts the client.
	 *
	 * @since  1.13.0
	 * @param  array<string, mixed> $settings Settings array.
	 * @return array<string, mixed> Same shape with icon_data validated.
	 */
	private function sanitize_icon_data( array $settings ): array {
		if ( ! array_key_exists( 'icon_data', $settings ) ) {
			return $settings;
		}

		$raw = $settings['icon_data'];

		// Empty string = explicit "clear icon" â€” keep it as ''.
		if ( '' === $raw || null === $raw ) {
			$settings['icon_data'] = '';
			return $settings;
		}

		if ( ! is_string( $raw ) ) {
			$settings['icon_data'] = '';
			return $settings;
		}

		// 64 KB ceiling on the encoded data URL. A 128x128 PNG is
		// typically 5-15 KB encoded; 64 KB is generous headroom.
		if ( strlen( $raw ) > 65536 ) {
			$settings['icon_data'] = '';
			return $settings;
		}

		// Strict shape match: data:image/png;base64,<payload>.
		if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $raw, $m ) ) {
			$settings['icon_data'] = '';
			return $settings;
		}

		// Verify the payload actually decodes and starts with the PNG
		// magic bytes (\x89PNG\r\n\x1a\n) â€” guards against a sneaky
		// non-PNG blob wrapped in a PNG-looking data URL.
		$decoded = base64_decode( $m[1], true );
		if ( false === $decoded || strncmp( $decoded, "\x89PNG\r\n\x1a\n", 8 ) !== 0 ) {
			$settings['icon_data'] = '';
		}

		return $settings;
	}

	/**
	 * Sanitize per-server LLM `instructions` text.
	 *
	 * Cap at 8 KB so a runaway instructions block doesn't eat the whole
	 * system-prompt budget on the client side. Strip control characters
	 * (except tab/newline/CR) so a paste from a terminal can't smuggle
	 * in escape sequences that could render unexpectedly in some
	 * clients. Plain-text only â€” no HTML escaping because MCP clients
	 * pass this verbatim into LLM prompts where HTML entities would
	 * look weird.
	 *
	 * @since  1.13.0
	 * @param  string $raw User-supplied instructions text.
	 * @return string
	 */
	private static function sanitize_instructions( string $raw ): string {
		$max = 8192;
		// Strip everything in 0x00-0x1F except 0x09 (tab), 0x0A (LF), 0x0D (CR).
		$cleaned = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $raw );
		if ( null === $cleaned ) {
			$cleaned = '';
		}
		$cleaned = trim( $cleaned );
		if ( strlen( $cleaned ) > $max ) {
			$cleaned = substr( $cleaned, 0, $max );
		}
		return $cleaned;
	}

	/**
	 * Sanitize the `pii_redaction` block inside a settings array.
	 *
	 * Validation only â€” no errors thrown. Invalid custom rules (e.g.
	 * uncompilable regex) are dropped so the user sees what's saved
	 * after the round trip and can fix offending entries. Scope is
	 * clamped to the known enum, presets are kept only when they map
	 * to a real preset key.
	 *
	 * @since  1.13.0
	 * @param  array<string, mixed> $settings Settings array (may or may not contain `pii_redaction`).
	 * @return array<string, mixed> Same shape with `pii_redaction` normalized when present.
	 */
	/**
	 * Sanitize the connection-variables map inside a settings payload.
	 *
	 * Names are placeholder identifiers ([A-Za-z0-9_], max 40 chars); values
	 * are endpoint fragments (subdomains, data-center codes) restricted to
	 * [A-Za-z0-9._-] (max 100) so a stored value can never introduce URL
	 * structure when substituted. Entries failing either rule are dropped,
	 * not mangled â€” a silently rewritten value would produce a confusing
	 * wrong-host call instead of an obvious missing-variable error.
	 *
	 * @since  1.22.0
	 * @param  array<string, mixed> $settings Decoded settings payload.
	 * @return array<string, mixed> Settings with a clean `variables` map.
	 */
	private function sanitize_variables( array $settings ): array {
		// WHY: a wrapping condition, not an early return â€” a payload can carry
		// header_variables with no 'variables' key at all, and returning here
		// skipped the header sanitizer below entirely.
		if ( isset( $settings['variables'] ) ) {
			$clean = array();
			if ( is_array( $settings['variables'] ) ) {
				foreach ( $settings['variables'] as $name => $value ) {
					if ( ! is_string( $name ) || ! is_scalar( $value ) ) {
						continue;
					}
					$name  = trim( $name );
					$value = trim( (string) $value );
					if ( '' === $name || '' === $value || strlen( $name ) > 40 || strlen( $value ) > 100 ) {
						continue;
					}
					if ( preg_match( '/[^A-Za-z0-9_]/', $name ) || preg_match( '/[^A-Za-z0-9._-]/', $value ) ) {
						continue;
					}
					$clean[ $name ] = $value;
				}
			}

			if ( empty( $clean ) ) {
				unset( $settings['variables'] );
			} else {
				$settings['variables'] = $clean;
			}
		}

		// Header-sourced variables: name => HTTP header the client sends the
		// value in. Header names follow RFC 9110 token rules (restricted to
		// the letter/digit/hyphen subset every real client uses).
		if ( isset( $settings['header_variables'] ) ) {
			$clean_headers = array();
			if ( is_array( $settings['header_variables'] ) ) {
				foreach ( $settings['header_variables'] as $name => $header ) {
					if ( ! is_string( $name ) || ! is_string( $header ) ) {
						continue;
					}
					$name   = trim( $name );
					$header = trim( $header );
					if ( '' === $name || '' === $header || strlen( $name ) > 40 ) {
						continue;
					}
					if ( preg_match( '/[^A-Za-z0-9_]/', $name ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $header ) ) {
						continue;
					}
					$clean_headers[ $name ] = $header;
				}
			}

			if ( empty( $clean_headers ) ) {
				unset( $settings['header_variables'] );
			} else {
				$settings['header_variables'] = $clean_headers;
			}
		}

		return $settings;
	}

	/**
	 * Test a server's mailbox connection.
	 *
	 * Connects, authenticates and lists folders, then reports what it found.
	 * This is the whole diagnostic story for the feature: nearly every mailbox
	 * failure is a configuration problem â€” wrong port, app password required,
	 * outbound 993 blocked by the host â€” and each produces a distinguishable
	 * message here rather than an opaque failure at tool-call time.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Which mailbox tools this server is missing, minus any already skipped.
	 *
	 * Read-only and cheap, so the server screen can ask on load rather than
	 * making the operator go looking for an update they cannot see.
	 *
	 * @since  1.5.0
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_mailbox_tools( $request ) {
		return $this->check_connector_tools( $request, \GetMCP\Mail\MailboxTools::class );
	}

	/**
	 * Install any mailbox tools this server is missing.
	 *
	 * A mailbox server's tools are rows created when it was built, so one made
	 * before a tool existed never gains it. This tops the server up in place
	 * rather than making the operator rebuild it and re-enter credentials.
	 *
	 * @since  1.5.0
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_mailbox_tools( $request ) {
		return $this->sync_connector_tools(
			$request,
			\GetMCP\Mail\MailboxTools::class,
			static function ( int $added ): string {
				if ( 0 === $added ) {
					return __( 'This mailbox already has every tool.', 'getmcp' );
				}

				return sprintf(
					/* translators: %d: number of tools added. */
					_n( '%d tool added. Anything that writes to the mailbox is off until you enable it.', '%d tools added. Anything that writes to the mailbox is off until you enable it.', $added, 'getmcp' ),
					$added
				);
			}
		);
	}

	/**
	 * Which database tools this server is missing, minus any already skipped.
	 *
	 * @since  1.6.0
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_database_tools( $request ) {
		return $this->check_connector_tools( $request, \GetMCP\Sql\SqlTools::class );
	}

	/**
	 * Install any database tools this server is missing.
	 *
	 * @since  1.6.0
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_database_tools( $request ) {
		return $this->sync_connector_tools(
			$request,
			\GetMCP\Sql\SqlTools::class,
			static function ( int $added ): string {
				if ( 0 === $added ) {
					return __( 'This database server already has every tool.', 'getmcp' );
				}

				return sprintf(
					/* translators: %d: number of tools added. */
					_n( '%d tool added. Anything that writes to the database is off until you enable it.', '%d tools added. Anything that writes to the database is off until you enable it.', $added, 'getmcp' ),
					$added
				);
			}
		);
	}

	/**
	 * Missing-tools check shared by every connector.
	 *
	 * @since  1.6.0
	 *
	 * @param  \WP_REST_Request                                 $request   Request.
	 * @param  class-string<\GetMCP\Connectors\ConnectorTools> $catalogue Catalogue class.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function check_connector_tools( $request, string $catalogue ) {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		$dismissed = $catalogue::dismissed_for_server( (int) $server->id );
		$missing   = array_values(
			array_filter(
				$catalogue::missing_for_server( (int) $server->id ),
				static fn( array $tool ): bool => ! in_array( $tool['handler'], $dismissed, true )
			)
		);

		return new WP_REST_Response(
			array(
				'missing'   => $missing,
				'count'     => count( $missing ),
				'dismissed' => $dismissed,
			),
			200
		);
	}

	/**
	 * Sync-or-dismiss shared by every connector.
	 *
	 * @since  1.6.0
	 *
	 * @param  \WP_REST_Request                                 $request   Request.
	 * @param  class-string<\GetMCP\Connectors\ConnectorTools> $catalogue Catalogue class.
	 * @param  callable(int): string                            $message   Builds the result message from the added count.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function sync_connector_tools( $request, string $catalogue, callable $message ) {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		if ( $request->get_param( 'dismiss' ) ) {
			$handlers = array_map(
				static fn( array $tool ): string => (string) $tool['handler'],
				$catalogue::missing_for_server( (int) $server->id )
			);

			$catalogue::dismiss_for_server( (int) $server->id, $handlers );

			return new \WP_REST_Response(
				array(
					'added'     => array(),
					'skipped'   => $handlers,
					'dismissed' => true,
					'message'   => __( 'Skipped. These tools will not be offered again, but anything added later still will.', 'getmcp' ),
				),
				200
			);
		}

		$result = $catalogue::sync_server( (int) $server->id );

		return new \WP_REST_Response(
			array(
				'added'   => $result['added'],
				'skipped' => $result['skipped'],
				'message' => $message( count( $result['added'] ) ),
			),
			200
		);
	}

	/**
	 * Test a server's database connection.
	 *
	 * Connects, reads the server version and counts the tables it can see.
	 * Like the mailbox test, this is the whole diagnostic story: nearly every
	 * database failure is a configuration problem â€” wrong port, the site's IP
	 * not allowed in, TLS not offered â€” and each produces a distinguishable
	 * message here rather than an opaque failure at tool-call time.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_database( $request ) {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		try {
			$config = $server->get_database_config();
		} catch ( \RuntimeException $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The stored database password could not be decrypted. Re-enter it and save.', 'getmcp' ),
				)
			);
		}

		if ( empty( $config['host'] ) || empty( $config['database'] ) || empty( $config['username'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Add the host, database name, username and password first.', 'getmcp' ),
				)
			);
		}

		$client  = new \GetMCP\Sql\SqlClient();
		$started = microtime( true );

		try {
			$client->connect( $config );

			$version = $client->server_version();
			$tables  = $client->list_tables( true );
			$names   = array_map( static fn( array $t ): string => $t['name'], $tables );

			return new WP_REST_Response(
				array(
					'success'     => true,
					'message'     => sprintf(
						/* translators: 1: server version, 2: table count, 3: database name. */
						__( 'Connected to %1$s. Found %2$d tables and views in %3$s.', 'getmcp' ),
						$version,
						count( $tables ),
						'pgsql' === $config['engine'] ? $config['database'] . '.' . ( $config['schema'] ?: 'public' ) : $config['database']
					),
					'version'     => $version,
					'tables'      => array_slice( $names, 0, 50 ),
					'table_count' => count( $tables ),
					'read_only'   => (bool) $config['read_only'],
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				)
			);
		} catch ( \GetMCP\Sql\SqlException $e ) {
			return new WP_REST_Response(
				array(
					'success'     => false,
					'message'     => $e->getMessage(),
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The connection test failed: ', 'getmcp' ) . $e->getMessage(),
				)
			);
		} finally {
			$client->close();
		}
	}

	public function test_mailbox( $request ) {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		try {
			$config = $server->get_mailbox_config();
		} catch ( \RuntimeException $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The stored mailbox password could not be decrypted. Re-enter it and save.', 'getmcp' ),
				)
			);
		}

		if ( empty( $config['host'] ) || empty( $config['username'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Add the mail server, username and password first.', 'getmcp' ),
				)
			);
		}

		$client  = new \GetMCP\Mail\ImapClient();
		$started = microtime( true );

		try {
			$client->connect(
				(string) $config['host'],
				(int) $config['port'],
				(string) $config['encryption'],
				15,
				(bool) $config['validate_cert']
			);

			if ( 'oauth' === $config['auth_mode'] ) {
				$client->authenticate_xoauth2( (string) $config['username'], (string) $config['password'] );
			} else {
				$client->login( (string) $config['username'], (string) $config['password'] );
			}

			$folders = $client->list_folders();
			$inbox   = $client->select_folder( (string) $config['default_folder'], true );

			return new WP_REST_Response(
				array(
					'success'     => true,
					'message'     => sprintf(
						/* translators: 1: folder count, 2: message count, 3: folder name. */
						__( 'Connected. Found %1$d folders, and %2$d messages in %3$s.', 'getmcp' ),
						count( $folders ),
						(int) ( $inbox['exists'] ?? 0 ),
						(string) $config['default_folder']
					),
					'folders'     => array_values( array_map( static fn( array $f ): string => $f['name'], $folders ) ),
					'message_count' => (int) ( $inbox['exists'] ?? 0 ),
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				)
			);
		} catch ( \GetMCP\Mail\MailException $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The connection test failed: ', 'getmcp' ) . $e->getMessage(),
				)
			);
		} finally {
			$client->close();
		}
	}

	/**
	 * Sentinel the UI sends back in place of a stored mailbox password.
	 *
	 * The password is never returned by the API, so the edit form has nothing
	 * real to submit for an unchanged field. It sends this instead, and the
	 * stored value is kept.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const MAILBOX_PASSWORD_UNCHANGED = '__getmcp_unchanged__';

	/**
	 * Normalise and secure the `mailbox` settings block.
	 *
	 * The password is encrypted here, at the boundary, so a plaintext one is
	 * never written to the settings blob. Three cases have to work: a new
	 * password (encrypt it), an unchanged one (keep what is stored â€” the API
	 * never handed the real value out, so the form cannot resubmit it), and a
	 * re-saved already-encrypted value (leave it alone, so a settings round
	 * trip is idempotent).
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, mixed> $settings  Settings array.
	 * @param int                  $server_id Server being updated, 0 on create.
	 * @return array<string, mixed>
	 */
	private function sanitize_mailbox( array $settings, int $server_id = 0 ): array {
		if ( ! isset( $settings['mailbox'] ) ) {
			return $settings;
		}

		if ( ! is_array( $settings['mailbox'] ) ) {
			unset( $settings['mailbox'] );
			return $settings;
		}

		$raw = $settings['mailbox'];

		$encryption      = strtolower( trim( (string) ( $raw['encryption'] ?? 'ssl' ) ) );
		$smtp_encryption = strtolower( trim( (string) ( $raw['smtp_encryption'] ?? 'ssl' ) ) );
		$auth_mode       = strtolower( trim( (string) ( $raw['auth_mode'] ?? 'password' ) ) );

		$clean = array(
			// The chosen preset, so the picker still shows it after a reload.
			'provider'        => sanitize_text_field( (string) ( $raw['provider'] ?? '' ) ),
			'host'            => sanitize_text_field( (string) ( $raw['host'] ?? '' ) ),
			'port'            => min( 65535, max( 1, absint( $raw['port'] ?? 993 ) ) ),
			'encryption'      => in_array( $encryption, array( 'ssl', 'tls', 'none' ), true ) ? $encryption : 'ssl',
			'username'        => sanitize_text_field( (string) ( $raw['username'] ?? '' ) ),
			'auth_mode'       => in_array( $auth_mode, array( 'password', 'oauth' ), true ) ? $auth_mode : 'password',
			'validate_cert'   => ! isset( $raw['validate_cert'] ) || rest_sanitize_boolean( $raw['validate_cert'] ),
			'default_folder'  => sanitize_text_field( (string) ( $raw['default_folder'] ?? 'INBOX' ) ),
			'trash_folder'    => sanitize_text_field( (string) ( $raw['trash_folder'] ?? '' ) ),
			'sent_folder'     => sanitize_text_field( (string) ( $raw['sent_folder'] ?? '' ) ),
			'smtp_host'       => sanitize_text_field( (string) ( $raw['smtp_host'] ?? '' ) ),
			'smtp_port'       => min( 65535, max( 1, absint( $raw['smtp_port'] ?? 465 ) ) ),
			'smtp_encryption' => in_array( $smtp_encryption, array( 'ssl', 'tls', 'none' ), true ) ? $smtp_encryption : 'ssl',
			'from_name'       => sanitize_text_field( (string) ( $raw['from_name'] ?? '' ) ),
			'from_email'      => sanitize_email( (string) ( $raw['from_email'] ?? '' ) ),
		);

		if ( '' === $clean['default_folder'] ) {
			$clean['default_folder'] = 'INBOX';
		}

		$submitted = (string) ( $raw['password'] ?? '' );

		if ( self::MAILBOX_PASSWORD_UNCHANGED === $submitted || '' === $submitted ) {
			$clean['password'] = $this->stored_mailbox_password( $server_id );
		} elseif ( str_starts_with( $submitted, 'enc:v1:' ) ) {
			$clean['password'] = $submitted;
		} else {
			$clean['password'] = \GetMCP\Utils\Encryption::encrypt( $submitted );
		}

		$settings['mailbox'] = $clean;

		return $settings;
	}

	/**
	 * Read the currently stored (still encrypted) mailbox password.
	 *
	 * @since 1.5.0
	 *
	 * @param int $server_id Server id.
	 * @return string Encrypted blob, or '' when none is stored.
	 */
	private function stored_mailbox_password( int $server_id ): string {
		return $this->stored_connector_password( $server_id, 'mailbox' );
	}

	/**
	 * Read the currently stored (still encrypted) password of a connector block.
	 *
	 * @since 1.6.0
	 *
	 * @param int    $server_id Server id.
	 * @param string $block     Settings key: 'mailbox' or 'database'.
	 * @return string Encrypted blob, or '' when none is stored.
	 */
	private function stored_connector_password( int $server_id, string $block ): string {
		if ( $server_id <= 0 ) {
			return '';
		}

		$server = $this->manager->get( $server_id );
		if ( ! $server || empty( $server->settings ) ) {
			return '';
		}

		$decoded = json_decode( (string) $server->settings, true );

		return is_array( $decoded ) ? (string) ( $decoded[ $block ]['password'] ?? '' ) : '';
	}

	/**
	 * Normalise and secure the `database` settings block.
	 *
	 * Same three password cases as the mailbox block: a new password is
	 * encrypted, the unchanged sentinel keeps what is stored, and an already
	 * encrypted value is left alone. Everything else is clamped to the values
	 * the connector accepts so a hand-crafted request cannot store a row cap
	 * of a million or a negative port.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $settings  Settings array.
	 * @param int                  $server_id Server being updated, 0 on create.
	 * @return array<string, mixed>
	 */
	private function sanitize_database( array $settings, int $server_id = 0 ): array {
		if ( ! isset( $settings['database'] ) ) {
			return $settings;
		}

		if ( ! is_array( $settings['database'] ) ) {
			unset( $settings['database'] );
			return $settings;
		}

		$raw = $settings['database'];

		$engine   = strtolower( trim( (string) ( $raw['engine'] ?? 'mysql' ) ) );
		$engine   = in_array( $engine, array( 'mysql', 'pgsql' ), true ) ? $engine : 'mysql';
		$ssl_mode = strtolower( trim( (string) ( $raw['ssl_mode'] ?? 'require' ) ) );

		$clean = array(
			'engine'    => $engine,
			'host'      => sanitize_text_field( (string) ( $raw['host'] ?? '' ) ),
			'port'      => min( 65535, max( 1, absint( $raw['port'] ?? ( 'pgsql' === $engine ? 5432 : 3306 ) ) ) ),
			'database'  => sanitize_text_field( (string) ( $raw['database'] ?? '' ) ),
			'username'  => sanitize_text_field( (string) ( $raw['username'] ?? '' ) ),
			'ssl_mode'  => in_array( $ssl_mode, array( 'disable', 'require', 'verify' ), true ) ? $ssl_mode : 'require',
			// A PEM block, not a text field: line breaks are the format.
			'ssl_ca'    => self::sanitize_pem( (string) ( $raw['ssl_ca'] ?? '' ) ),
			'schema'    => sanitize_text_field( (string) ( $raw['schema'] ?? '' ) ),
			'read_only' => ! isset( $raw['read_only'] ) || rest_sanitize_boolean( $raw['read_only'] ),
			'max_rows'  => min( \GetMCP\Sql\SqlExecutor::MAX_ROWS, max( 1, absint( $raw['max_rows'] ?? 200 ) ) ),
			'timeout'   => min( 300, max( 1, absint( $raw['timeout'] ?? 30 ) ) ),
		);

		$submitted = (string) ( $raw['password'] ?? '' );

		if ( self::MAILBOX_PASSWORD_UNCHANGED === $submitted || '' === $submitted ) {
			$clean['password'] = $this->stored_connector_password( $server_id, 'database' );
		} elseif ( str_starts_with( $submitted, 'enc:v1:' ) ) {
			$clean['password'] = $submitted;
		} else {
			$clean['password'] = \GetMCP\Utils\Encryption::encrypt( $submitted );
		}

		$settings['database'] = $clean;

		return $settings;
	}

	/**
	 * Keep only what a PEM certificate block can contain.
	 *
	 * @since 1.6.0
	 *
	 * @param string $pem Submitted text.
	 * @return string
	 */
	private static function sanitize_pem( string $pem ): string {
		$pem = trim( str_replace( "\r\n", "\n", $pem ) );

		if ( '' === $pem ) {
			return '';
		}

		// Base64, PEM armour, whitespace: nothing else has any business here.
		$pem = (string) preg_replace( '/[^A-Za-z0-9+\/=\s\-]/', '', $pem );

		return strlen( $pem ) > 65536 ? '' : $pem;
	}

	private function sanitize_pii_redaction( array $settings ): array {
		if ( ! isset( $settings['pii_redaction'] ) || ! is_array( $settings['pii_redaction'] ) ) {
			return $settings;
		}

		$raw           = $settings['pii_redaction'];
		$preset_keys   = array_keys( \GetMCP\Security\PiiRedactor::presets() );
		$allowed_scope = array( 'both', 'response_only', 'log_only' );

		$presets = array();
		if ( isset( $raw['presets'] ) && is_array( $raw['presets'] ) ) {
			foreach ( $raw['presets'] as $key ) {
				if ( is_string( $key ) && in_array( $key, $preset_keys, true ) ) {
					$presets[] = $key;
				}
			}
		}

		$custom = array();
		if ( isset( $raw['custom'] ) && is_array( $raw['custom'] ) ) {
			foreach ( $raw['custom'] as $rule ) {
				$validated = \GetMCP\Security\PiiRedactor::validate_custom_rule( $rule );
				if ( null !== $validated ) {
					$custom[] = $validated;
				}
			}
		}

		$settings['pii_redaction'] = array(
			'enabled' => ! empty( $raw['enabled'] ),
			'scope'   => in_array( $raw['scope'] ?? 'both', $allowed_scope, true ) ? $raw['scope'] : 'both',
			'presets' => array_values( array_unique( $presets ) ),
			'custom'  => $custom,
		);

		return $settings;
	}

	/* ------------------------------------------------------------------ */
	/* Whole-server transfer                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Describe this server as a Claude custom connector.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function claude_connector( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error( 'server_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( ClaudeConnector::describe( $server, (string) $request->get_param( 'client' ) ), 200 );
	}

	/**
	 * Export a server and everything it serves as a portable manifest.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_item( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error( 'server_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( ServerTransfer::export( $server ), 200 );
	}

	/**
	 * Create a server from an exported manifest.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_item( $request ): WP_REST_Response|WP_Error {
		// Same cap as creating one by hand â€” importing must not be a way round
		// the plan limit.
		$tier  = LicenseTier::current();
		$count = $this->manager->get_count();

		if ( ! $tier->can_create_server( $count ) ) {
			return $this->tier_limit_error(
				'tier_limit_servers',
				sprintf(
					/* translators: 1: current count, 2: tier maximum, 3: tier id. */
					__( 'Your %3$s plan allows %2$d server(s); you have %1$d. Upgrade to import another.', 'getmcp' ),
					$count,
					$tier->max_servers(),
					strtoupper( $tier->id() )
				),
				$tier,
				array(
					'limit'   => $tier->max_servers(),
					'current' => $count,
				)
			);
		}

		$manifest = $request->get_json_params();

		$invalid = ServerTransfer::validate( $manifest );
		if ( $invalid ) {
			return $invalid;
		}

		/*
		 * An import whose auth method the plan does not allow would otherwise
		 * create a server the operator cannot configure, so it is refused for
		 * the same reason create_item() refuses it.
		 */
		$auth_type = (string) ( $manifest['server']['auth_type'] ?? 'none' );

		if ( ! $tier->allows_auth_method( $auth_type ) ) {
			return $this->tier_limit_error(
				'tier_limit_auth_method',
				sprintf(
					/* translators: 1: requested auth method, 2: tier id. */
					__( 'This export uses the %1$s authentication method, which is not available on your %2$s plan.', 'getmcp' ),
					$auth_type,
					strtoupper( $tier->id() )
				),
				$tier,
				array( 'auth_method' => $auth_type )
			);
		}

		$result = ServerTransfer::import( $manifest, $request->get_param( 'name' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}
}
