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
use GetMCP\Core\ToolManager;
use GetMCP\Core\ClaudeConnector;
use GetMCP\Licensing\LicenseTier;
use GetMCP\Utils\Encryption;
use GetMCP\Utils\RemoteFetch;
use GetMCP\Protocol\JsonRpcRouter;
use GetMCP\Auth\McpAuth;
use GetMCP\Auth\BrokerConfig;
use GetMCP\Auth\BrokerConnections;
use GetMCP\Auth\FirstPartyOAuth;
use GetMCP\Auth\OAuthProvider;
use GetMCP\Auth\ServerUserAccess;
use GetMCP\Gateway\Bundle;
use GetMCP\RemoteMcp\RemoteConfig;
use GetMCP\RemoteMcp\UpstreamOAuth;
use GetMCP\Security\HealthData;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/slug-check',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_slug' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => array(
					'slug'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'exclude' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// UUID pattern: 8-4-4-4-12 hex characters, or numeric ID fallback.
		$uuid_pattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9]+';

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

		// Everything needed to add this server to an AI client — Claude by
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

		// Same import, but the server fetches the export from a URL: a browser
		// fetch would be blocked by CORS on most hosts.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import-url',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_from_url' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => array(
					'url'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
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

		// Who is signed in to a broker server, and whether each grant renews.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/connections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_connections' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/connections/(?P<connection_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_connection' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
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

		// The file server connector's twins.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/ftp-test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_ftp' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $uuid_pattern . ')/ftp-sync-tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_ftp_tools' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_ftp_tools' ),
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
				'per_page'     => $request->get_param( 'per_page' ) ?? 20,
				'page'         => $request->get_param( 'page' ) ?? 1,
				'status'       => $request->get_param( 'status' ) ?? '',
				'kind'         => $request->get_param( 'kind' ) ?? '',
				'exclude_kind' => $request->get_param( 'exclude_kind' ) ?? '',
				'search'       => $request->get_param( 'search' ) ?? '',
				'orderby'      => $request->get_param( 'orderby' ) ?? 'created_at',
				'order'        => $request->get_param( 'order' ) ?? 'DESC',
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
		// Tier-cap gate. Existing servers are grandfathered (Option A) — we
		// only block *new* creation when the user is at or over their cap.
		$tier    = LicenseTier::current();
		$limited = $this->server_limit_error( $tier );
		if ( $limited ) {
			return $limited;
		}

		// Auth-method gate (inbound). OAuth is only available on Pro/Agency.
		$desired_auth_type = (string) ( $request->get_param( 'auth_type' ) ?? self::default_auth_type( (string) $request->get_param( 'kind' ), $tier ) );
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

		// WHY: connector blocks carry secrets; without this a server created
		// with its connection in one call stores the password in plain text.
		$run_as_error = $this->validate_ability_run_as( $settings );
		if ( $run_as_error ) {
			return $run_as_error;
		}
		$submitted_remote = $settings['remote'] ?? null;
		$settings         = $this->sanitize_settings( $settings );

		$kind      = (string) $request->get_param( 'kind' );
		$is_bundle = 'bundle' === $kind;
		$is_remote = 'remote' === $kind;
		$auth_type = $desired_auth_type;

		if ( $is_bundle ) {
			$bundle_auth_error = self::bundle_auth_error( (string) $auth_type );
			if ( $bundle_auth_error ) {
				return $bundle_auth_error;
			}
		}

		if ( $is_remote && ! $tier->allows_remote() ) {
			return $this->tier_limit_error(
				'tier_limit_remote',
				sprintf(
					/* translators: %s: tier id. */
					__( 'Remote servers are not available on your %s plan.', 'getmcp' ),
					strtoupper( $tier->id() )
				),
				$tier,
				array()
			);
		}

		$settings = self::apply_remote_settings( $settings, $submitted_remote, $is_remote, array() );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		if ( $is_remote ) {
			$remote_auth_error = self::remote_auth_error( (string) $auth_type, (string) $settings['remote']['auth'] );
			if ( $remote_auth_error ) {
				return $remote_auth_error;
			}
		}

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

		$auth_config = self::checked_auth_config( $auth_config );
		if ( is_wp_error( $auth_config ) ) {
			return $auth_config;
		}

		// A bundle never calls an API itself (each member sends its own
		// credential), so an upstream credential stored on it would sit
		// unused and still be reported as configured. A remote server keeps
		// its upstream credential in settings.remote, where it is encrypted.
		if ( $is_bundle || $is_remote ) {
			$outbound_auth_type        = null;
			$outbound_auth_config      = null;
			$outbound_auth_credentials = null;
		}

		// test_auth_type always mirrors auth_type — they are the same concept.
		$test_auth_type = $auth_type;

		// OAuth (broker) uses `auth_credentials` to store the encrypted
		// upstream client_secret JSON, but does NOT use `test_auth_credentials`
		// (each MCP user authenticates upstream as themselves). Non-OAuth
		// modes use both. `none` wipes both unconditionally.
		$is_oauth_inbound = 'oauth' === $auth_type;

		// Broker OAuth cannot run without an upstream identity provider, and a
		// server saved in that state is stuck: the authorize endpoint refuses
		// every client and the admin form refuses to save. Refuse it here, with
		// the way out, rather than store it.
		if ( $is_oauth_inbound && ! BrokerConfig::is_complete( $auth_config, null !== $auth_credentials && '' !== $auth_credentials ) ) {
			return new WP_Error( 'oauth_config_incomplete', BrokerConfig::incomplete_message(), array( 'status' => 400 ) );
		}

		if ( 'none' === $auth_type ) {
			$auth_credentials      = null;
			$test_auth_credentials = null;
		} elseif ( ServerUserAccess::AUTH_TYPE === $auth_type ) {
			// WordPress sign-in has no upstream secret to store. The allowlist
			// starts empty (nobody gets in) and is only ever changed through
			// the allowed-users endpoint, never by a server update. A project
			// gateway is the one exception: it gets this mode without the
			// creator choosing it, so the creator starts on the list.
			$auth_credentials      = null;
			$test_auth_credentials = null;
			$allowed_user_ids      = array();
			if ( $is_bundle && ServerUserAccess::is_eligible( get_current_user_id() ) ) {
				$allowed_user_ids[] = get_current_user_id();
			}
			$auth_config = wp_json_encode( array( 'allowed_user_ids' => $allowed_user_ids ) );
		} elseif ( $is_oauth_inbound ) {
			// Test creds are meaningless in broker mode — wipe them so an
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

		// Outbound credentials are independent of inbound auth_type — they
		// govern how GetMCP authenticates to the upstream API. None / unset
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

		$conflict = McpAuth::outbound_conflict( (string) $auth_type, is_string( $outbound_auth_type ) ? $outbound_auth_type : null );
		if ( null !== $conflict ) {
			return new WP_Error( 'getmcp_passthrough_outbound_conflict', $conflict, array( 'status' => 422 ) );
		}

		try {
			$server = $this->manager->create(
				array(
					'name'                      => $request->get_param( 'name' ),
					'slug'                      => $request->get_param( 'slug' ) ?? '',
					'status'                    => $request->get_param( 'status' ) ?? 'active',
					'kind'                      => $is_bundle ? 'bundle' : ( $is_remote ? 'remote' : 'standard' ),
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

		$data = $this->prepare_server_response( $server );
		// Only the detail view reads it, so the list never pays the two queries.
		$data['connected'] = $this->has_connected( $server->id );

		return new WP_REST_Response( $data );
	}

	/**
	 * Say whether a slug is free for a server's endpoint.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function check_slug( $request ): WP_REST_Response {
		$exclude = (string) $request->get_param( 'exclude' );
		$server  = '' === $exclude ? null : $this->manager->get_by_uuid( $exclude );

		return new WP_REST_Response(
			$this->manager->slug_status( (string) $request->get_param( 'slug' ), $server ? $server->id : 0 )
		);
	}

	/**
	 * Whether any client has reached this server: a logged call or a used key.
	 *
	 * The editor locks the endpoint slug once this is true, because renaming
	 * it breaks every client already pointed at the old URL.
	 *
	 * @since  1.8.0
	 * @param  int $server_id Server ID.
	 * @return bool
	 */
	private function has_connected( int $server_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}getmcp_call_logs WHERE server_id = %d LIMIT 1", $server_id ) ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}getmcp_api_keys WHERE server_id = %d AND last_used_at IS NOT NULL LIMIT 1", $server_id ) );
	}

	/**
	 * The error for a slug the update cannot use, with a free one to offer.
	 *
	 * @since  1.8.0
	 * @param  array $slug Result of ServerManager::slug_status().
	 * @return WP_Error
	 */
	private static function slug_error( array $slug ): WP_Error {
		$errors = array(
			'empty'    => array( 'getmcp_slug_invalid', __( 'The endpoint slug needs at least one letter or number.', 'getmcp' ), 400 ),
			'reserved' => array( 'getmcp_slug_reserved', __( 'This endpoint slug is reserved by GetMCP. Choose another.', 'getmcp' ), 400 ),
			'taken'    => array( 'getmcp_slug_taken', __( 'Another server already uses this endpoint slug.', 'getmcp' ), 409 ),
		);
		list( $code, $message, $status ) = $errors[ $slug['reason'] ];

		return new WP_Error( $code, $message, array( 'status' => $status, 'suggestion' => $slug['suggestion'] ) );
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

		$touches_auth = self::touches_auth( $request );
		if ( ! $touches_auth && ! self::touches_settings( $request ) ) {
			return $this->save_update( $request, $server_obj );
		}

		// Sign-in and settings edits run under a per-server lock and re-read
		// the row inside it: two tabs cannot both pass the revision check and
		// the second save silently replace the first, and the settings merge
		// starts from the stored blob rather than the one loaded above.
		return ServerManager::with_auth_lock(
			$server_obj->id,
			function () use ( $request, $server_obj, $touches_auth ) {
				$current = $this->manager->get( $server_obj->id );
				if ( ! $current ) {
					return new WP_Error( 'getmcp_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
				}
				$expected = $request->get_param( 'expected_auth_revision' );
				if ( $touches_auth && null !== $expected && ! hash_equals( $current->auth_revision(), (string) $expected ) ) {
					return self::auth_revision_conflict( $current );
				}
				return $this->save_update( $request, $current );
			}
		);
	}

	/**
	 * Whether a PATCH rewrites the settings blob.
	 *
	 * Mirrors the triggers in save_update(): description and instructions are
	 * stored inside settings, so they rewrite it too.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Request object.
	 * @return bool
	 */
	private static function touches_settings( WP_REST_Request $request ): bool {
		return null !== $request->get_param( 'settings' )
			|| $request->has_param( 'description' )
			|| $request->has_param( 'instructions' );
	}

	/**
	 * Whether a PATCH changes sign-in settings.
	 *
	 * Covers the inbound and outbound auth columns plus the remote upstream
	 * block, which holds the upstream credentials of a remote server.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Request object.
	 * @return bool
	 */
	private static function touches_auth( WP_REST_Request $request ): bool {
		foreach ( array( 'auth_type', 'auth_config', 'auth_credentials', 'test_auth_credentials', 'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				return true;
			}
		}
		$settings = $request->get_param( 'settings' );
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}
		return is_array( $settings ) && array_key_exists( 'remote', $settings );
	}

	/**
	 * 409 for a sign-in save based on settings that changed since the editor loaded.
	 *
	 * Carries the current revision so a client can tell the user what happened
	 * without a second request.
	 *
	 * @since  1.8.0
	 * @param  Server $current Row read under the lock.
	 * @return WP_Error
	 */
	private static function auth_revision_conflict( Server $current ): WP_Error {
		return new WP_Error(
			'getmcp_auth_revision_conflict',
			__( 'The sign-in settings of this server changed after you opened this page. Reload the page to see the current settings, then make your change again.', 'getmcp' ),
			array(
				'status'        => 409,
				'auth_revision' => $current->auth_revision(),
			)
		);
	}

	/**
	 * Apply a validated PATCH to a server.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request    Request object.
	 * @param  Server          $server_obj Row the update is based on.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save_update( WP_REST_Request $request, Server $server_obj ): WP_REST_Response|WP_Error {
		// Block switching INTO a disallowed auth method on the current tier.
		// Switching out of OAuth is always allowed even on Solo/unlicensed —
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

		// test_auth_type is always derived from auth_type — not user-settable.
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

		// Preserve add-on-managed provider options and masked credentials while
		// core's per-server authentication lock and revision check are held.
		$extension_settings = json_decode( $server_obj->settings ?? '{}', true );
		$managed_provider = is_array( $extension_settings ) && array_key_exists( 'personal_provider', $extension_settings ) && \GetMCPExtensions\ProviderConnections::supported( $server_obj );
		if ( ( \GetMCPExtensions\GoogleHealth::is_server( $server_obj ) || $managed_provider ) && self::touches_auth( $request ) ) {
			try {
				$data = \GetMCPExtensions\AuthenticationSettings::prepare( $server_obj, $data );
			} catch ( \GetMCPExtensions\SettingsConflict $e ) {
				return new WP_Error( 'authentication_conflict', 'Authentication settings changed. Reload before saving.', array( 'status' => 409 ) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'authentication_invalid', 'Invalid authentication update.', array( 'status' => 400 ) );
			}
		}

		// The manager's update cleans a typed slug but checks neither that it
		// is free nor that it is reserved, so a used or reserved one must be
		// refused here. An unchanged slug is the server's own and passes,
		// which keeps every other edit of the form saving.
		if ( isset( $data['slug'] ) && sanitize_title( (string) $data['slug'] ) !== $server_obj->slug ) {
			$slug = $this->manager->slug_status( (string) $data['slug'], (int) $server_obj->id );
			if ( ! $slug['available'] ) {
				return self::slug_error( $slug );
			}
		}

		$bundle_members = null;
		if ( $server_obj->is_bundle() ) {
			unset( $data['outbound_auth_type'], $data['outbound_auth_config'], $data['outbound_auth_credentials'] );
			if ( isset( $data['auth_type'] ) ) {
				$bundle_auth_error = self::bundle_auth_error( (string) $data['auth_type'] );
				if ( $bundle_auth_error ) {
					return $bundle_auth_error;
				}
				// Lowering sign-in is allowed (members it no longer covers are
				// withheld), so the response names them instead of staying silent.
				if ( (string) $data['auth_type'] !== (string) $server_obj->auth_type ) {
					$bundle_members = Bundle::member_servers( $server_obj );
				}
			}
		}

		// The upstream credential of a remote server lives in settings.remote.
		if ( $server_obj->is_remote() ) {
			unset( $data['outbound_auth_type'], $data['outbound_auth_config'], $data['outbound_auth_credentials'] );
		}

		// Normalise credential fields: accept both JSON string and object.
		// The manager/encryption layer always works with JSON strings.
		foreach ( array( 'auth_credentials', 'test_auth_credentials', 'auth_config', 'outbound_auth_credentials', 'outbound_auth_config' ) as $json_field ) {
			if ( isset( $data[ $json_field ] ) && is_array( $data[ $json_field ] ) ) {
				$data[ $json_field ] = wp_json_encode( $data[ $json_field ] );
			}
		}

		if ( isset( $data['auth_config'] ) ) {
			$data['auth_config'] = self::checked_auth_config( $data['auth_config'] );
			if ( is_wp_error( $data['auth_config'] ) ) {
				return $data['auth_config'];
			}
		}

		// Process auth fields whenever any auth-related key is present in the update.
		$has_auth_update = isset( $data['auth_type'] ) || isset( $data['auth_credentials'] ) ||
			isset( $data['auth_config'] ) || isset( $data['test_auth_credentials'] ) ||
			isset( $data['outbound_auth_type'] ) || isset( $data['outbound_auth_config'] ) ||
			isset( $data['outbound_auth_credentials'] );

		// A status-only PATCH never enters the auth block below, which checks
		// switching on together with the auth fields it may also change.
		if ( ! $has_auth_update && 'active' === ( $data['status'] ?? null ) && 'active' !== $server_obj->status && ! BrokerConfig::may_activate( $server_obj ) ) {
			return new WP_Error( 'oauth_config_incomplete', BrokerConfig::incomplete_message(), array( 'status' => 400 ) );
		}

		if ( $has_auth_update ) {
			$server = $this->manager->get( $id );
			if ( ! $server ) {
				return new WP_Error( 'getmcp_not_found', __( 'Server not found.', 'getmcp' ), array( 'status' => 404 ) );
			}

			// Effective auth_type after this update — governs all credential logic.
			$effective_auth_type = $data['auth_type'] ?? $server->auth_type;

			// test_auth_type always mirrors auth_type — keep them in sync.
			$data['test_auth_type'] = $effective_auth_type;

			$is_oauth_inbound = 'oauth' === $effective_auth_type;

			// Same rule as create, but only when the request touches the
			// client-authentication fields or switches the server on: a save
			// of another section (or of the stored credential alone) must
			// still go through on a server that is already in the stuck
			// state, or it can never be repaired.
			$activating = 'active' === ( $data['status'] ?? null ) && 'active' !== $server->status && ! FirstPartyOAuth::owns( $server );
			if ( $is_oauth_inbound && ( $activating || isset( $data['auth_type'] ) || isset( $data['auth_config'] ) || isset( $data['auth_credentials'] ) ) ) {
				$config     = $data['auth_config'] ?? $server->auth_config;
				$has_secret = isset( $data['auth_credentials'] )
					? ( null !== $data['auth_credentials'] && '' !== $data['auth_credentials'] )
					: ! empty( $server->auth_credentials );
				if ( ! BrokerConfig::is_complete( $config, $has_secret ) ) {
					return new WP_Error( 'oauth_config_incomplete', BrokerConfig::incomplete_message(), array( 'status' => 400 ) );
				}
			}

			if ( $effective_auth_type === 'none' ) {
				// Switching to "none" wipes all credentials unconditionally.
				$data['auth_credentials']      = null;
				$data['test_auth_credentials'] = null;
			} elseif ( ServerUserAccess::AUTH_TYPE === $effective_auth_type ) {
				$data['auth_credentials']      = null;
				$data['test_auth_credentials'] = null;
				if ( ServerUserAccess::AUTH_TYPE === $server->auth_type ) {
					// The list belongs to the allowed-users endpoint and its
					// compare-and-swap save. Rewriting it here, even unchanged,
					// could undo a save another admin made a moment ago.
					unset( $data['auth_config'] );
				} else {
					// Switching in: start from an empty list, never from a list
					// the request carried or one left over from an earlier stint.
					$data['auth_config'] = wp_json_encode( array( 'allowed_user_ids' => array() ) );
				}
			} elseif ( $is_oauth_inbound ) {
				// OAuth (broker): `auth_credentials` stores the encrypted
				// upstream client_secret JSON. `test_auth_credentials` is
				// meaningless because each MCP user authenticates upstream as
				// themselves — wipe it so a stale value can't bleed into the
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

			// Outbound credentials are independent of inbound auth_type — they
			// govern how GetMCP authenticates to the upstream API. Treat empty
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

			// Only when this save touches one side of the pair, so a server
			// saved before the rule can still be renamed or repaired.
			if ( isset( $data['auth_type'] ) || isset( $data['outbound_auth_type'] ) ) {
				$conflict = McpAuth::outbound_conflict(
					(string) $effective_auth_type,
					is_string( $effective_outbound ) ? $effective_outbound : null
				);
				if ( null !== $conflict ) {
					return new WP_Error( 'getmcp_passthrough_outbound_conflict', $conflict, array( 'status' => 422 ) );
				}
			}
		}

		// Merge top-level description / instructions into settings JSON.
		// These are exposed as first-class API fields for ergonomics but
		// stored inside the freeform `settings` blob so we don't need a
		// dedicated DB column for every new structured field.
		$has_description  = $request->has_param( 'description' );
		$has_instructions = $request->has_param( 'instructions' );

		// Captured before the merge below copies the stored (encrypted) block in.
		$sent_settings    = isset( $data['settings'] ) && is_string( $data['settings'] ) ? json_decode( $data['settings'], true ) : ( $data['settings'] ?? null );
		$submitted_remote = is_array( $sent_settings ) ? ( $sent_settings['remote'] ?? null ) : null;
		$stored_remote    = RemoteConfig::stored_block( $server_obj->settings );

		if ( $has_description || $has_instructions || is_array( $sent_settings ) ) {
			// A PATCH changes only the settings keys it sends. Replacing the
			// whole blob let a form opened earlier put back values that
			// another screen or an API call had changed since.
			$existing_settings = is_string( $server_obj->settings ) ? json_decode( $server_obj->settings, true ) : array();
			$existing_settings = self::merge_settings(
				is_array( $existing_settings ) ? $existing_settings : array(),
				is_array( $sent_settings ) ? $sent_settings : array()
			);

			if ( $has_description ) {
				$existing_settings['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
			}

			if ( $has_instructions ) {
				// Instructions are sent to LLMs verbatim, so we don't HTML-escape
				// them — but we DO cap length to avoid blowing up the system
				// prompt and ban control chars so a copy-paste can't smuggle in
				// terminal escape sequences.
				$raw = (string) $request->get_param( 'instructions' );
				$existing_settings['instructions'] = self::sanitize_instructions( $raw );
			}

			$run_as_error = $this->validate_ability_run_as( $existing_settings, $id );
			if ( $run_as_error ) {
				return $run_as_error;
			}
			$existing_settings = $this->sanitize_settings( $existing_settings, $id );
			$data['settings']  = wp_json_encode( $existing_settings );
		} else {
			// Not an object: nothing to merge, and nothing unchecked may be stored.
			unset( $data['settings'] );
		}

		// Settled after the merge so it can never store an unchecked block.
		$final_remote = $stored_remote;
		if ( isset( $data['settings'] ) ) {
			$final = is_string( $data['settings'] ) ? json_decode( $data['settings'], true ) : $data['settings'];
			$final = self::apply_remote_settings( is_array( $final ) ? $final : array(), $submitted_remote, $server_obj->is_remote(), $stored_remote );
			if ( is_wp_error( $final ) ) {
				return $final;
			}
			$data['settings'] = wp_json_encode( $final );
			$final_remote     = $final['remote'] ?? array();
		}

		if ( $server_obj->is_remote() ) {
			$remote_auth_error = self::remote_auth_error(
				(string) ( $data['auth_type'] ?? $server_obj->auth_type ),
				(string) ( $final_remote['auth'] ?? 'none' )
			);
			if ( $remote_auth_error ) {
				return $remote_auth_error;
			}
		}

		try {
			$server = $this->manager->update( $id, $data );
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

		// Stored tokens already miss on the new config_hash; this drops the
		// orphaned client registration instead of leaving it in wp_options.
		// WHY: compared by config_hash, not the raw block. Forgetting the client
		// on a header edit would register a new one, and every user's refresh
		// token would then fail with invalid_grant.
		if ( $server_obj->is_remote() ) {
			$old_config = RemoteConfig::from_block( $stored_remote );
			$new_config = RemoteConfig::from_block( $final_remote );
			if ( null === $old_config || null === $new_config || ! hash_equals( $old_config->config_hash(), $new_config->config_hash() ) ) {
				UpstreamOAuth::forget_client( $id );
			}
		}

		$response = $this->prepare_server_response( $server );
		if ( null !== $bundle_members ) {
			$hidden = Bundle::newly_withheld( $server_obj, $server, $bundle_members );
			if ( $hidden ) {
				$response['hidden_members'] = $hidden;
			}
		}

		return new WP_REST_Response( $response );
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

		if ( 'active' === $new_status && ! BrokerConfig::may_activate( $server ) ) {
			return new WP_Error( 'oauth_config_incomplete', BrokerConfig::incomplete_message(), array( 'status' => 400 ) );
		}

		$updated = $this->manager->update( $id, array( 'status' => $new_status ) );

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

		// A copy is a new server, so it counts against the plan like one.
		$limited = $this->server_limit_error( LicenseTier::current() );
		if ( $limited ) {
			return $limited;
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
		// arm and fail closed — better than silently no-opping validation.
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

		$data = $server->to_array();

		$auth_credentials          = null;
		$test_auth_credentials     = null;
		$outbound_auth_credentials = null;

		if ( ! empty( $data['auth_credentials'] ) ) {
			$decrypted = Encryption::decrypt( $data['auth_credentials'] );
			if ( false !== $decrypted ) {
				$auth_credentials = json_decode( $decrypted, true );
			}
		}

		if ( ! empty( $data['test_auth_credentials'] ) ) {
			$decrypted = Encryption::decrypt( $data['test_auth_credentials'] );
			if ( false !== $decrypted ) {
				$test_auth_credentials = json_decode( $decrypted, true );
			}
		}

		if ( ! empty( $data['outbound_auth_credentials'] ) ) {
			$decrypted = Encryption::decrypt( $data['outbound_auth_credentials'] );
			if ( false !== $decrypted ) {
				$outbound_auth_credentials = json_decode( $decrypted, true );
			}
		}

		// Decode auth_config JSON so the frontend receives an object.
		$auth_config = null;
		if ( ! empty( $data['auth_config'] ) ) {
			$decoded = is_string( $data['auth_config'] ) ? json_decode( $data['auth_config'], true ) : $data['auth_config'];
			$auth_config = is_array( $decoded ) ? $decoded : null;
		}

		// Decode outbound_auth_config JSON for the same reason.
		$outbound_auth_config = null;
		if ( ! empty( $data['outbound_auth_config'] ) ) {
			$decoded = is_string( $data['outbound_auth_config'] ) ? json_decode( $data['outbound_auth_config'], true ) : $data['outbound_auth_config'];
			$outbound_auth_config = is_array( $decoded ) ? $decoded : null;
		}

		return new WP_REST_Response(
			array(
				'auth_type'                 => $data['auth_type'] ?? 'none',
				'auth_config'               => $auth_config,
				'auth_credentials'          => $auth_credentials,
				'test_auth_type'            => $data['test_auth_type'] ?? 'none',
				'test_auth_credentials'     => $test_auth_credentials,
				'outbound_auth_type'        => $data['outbound_auth_type'] ?? null,
				'outbound_auth_config'      => $outbound_auth_config,
				'outbound_auth_credentials' => $outbound_auth_credentials,
			)
		);
	}

	/**
	 * Live sign-ins on a broker server.
	 *
	 * Never returns token hashes or provider tokens: each grant says only
	 * when it was made, when it lapses and whether it renews by itself.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_connections( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		if ( ! BrokerConnections::is_broker( $server ) ) {
			return new WP_Error(
				'getmcp_not_broker',
				__( 'This server does not sign clients in through an OAuth provider, so it has no connections to list.', 'getmcp' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response( BrokerConnections::for_server( $server ), 200 );
	}

	/**
	 * Sign one client out of a broker server.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_connection( $request ): WP_REST_Response|WP_Error {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		if ( ! BrokerConnections::is_broker( $server ) ) {
			return new WP_Error(
				'getmcp_not_broker',
				__( 'This server does not sign clients in through an OAuth provider, so it has no connections to revoke.', 'getmcp' ),
				array( 'status' => 400 )
			);
		}

		// WHY: the route regex already limits this to digits; an id from
		// another server's list matches no row and lands here as a 404.
		if ( ! BrokerConnections::revoke( $server, (int) $request->get_param( 'connection_id' ) ) ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'That connection no longer exists. It may have expired or already been revoked.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'revoked' => true ), 200 );
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

		$capabilities_check = array(
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
		);

		// Legacy add-on gateways and remotes retain their own kind in the
		// server_kind column, which native 1.8 Server objects do not hydrate.
		// @wp-test feature=upgrade.180 scenario=legacy-gateway-readiness
		// @wp-test feature=upgrade.180 scenario=legacy-gateway-readiness-refusal
		$legacy_kind = \GetMCPExtensions\LegacyBridge::kind( $server );
		if ( ! $server->is_bundle() && ! $server->is_remote() && 'gateway' === $legacy_kind ) {
			$settings = json_decode( (string) $server->settings, true );
			$configured = is_array( $settings['gateway']['server_ids'] ?? null ) ? count( $settings['gateway']['server_ids'] ) : 0;
			$published = 0;
			if ( 'active' === $server->status && \GetMCPExtensions\Runtime::enabled( 'project_gateways' ) ) {
				try { $published = count( \GetMCP\Gateway\ProjectGateway::members( $server ) ); } catch ( \Throwable $e ) { $published = 0; }
			}
			$capabilities_check = array(
				'id' => 'capabilities', 'label' => __( 'Has servers', 'getmcp' ),
				'status' => $published > 0 ? 'pass' : 'fail',
				'message' => sprintf( __( '%1$d of %2$d servers in this project gateway are published.', 'getmcp' ), $published, $configured ),
			);
		} elseif ( ! $server->is_bundle() && ! $server->is_remote() && 'remote-mcp' === $legacy_kind ) {
			$settings = json_decode( (string) $server->settings, true );
			$remote = $settings['remote_mcp'] ?? null;
			$valid = \GetMCPExtensions\Runtime::enabled( 'remote_mcp' ) && is_array( $remote ) && is_string( $remote['endpoint'] ?? null ) && in_array( $remote['auth_mode'] ?? null, array( 'none', 'shared', 'oauth' ), true );
			if ( $valid ) {
				try { \GetMCP\Remote\SafeHttp::validate_url( $remote['endpoint'] ); } catch ( \Throwable $e ) { $valid = false; }
			}
			$capabilities_check = array(
				'id' => 'capabilities', 'label' => __( 'Has an upstream', 'getmcp' ),
				'status' => $valid ? 'pass' : 'fail',
				'message' => $valid ? __( 'Tools come from the connected MCP server.', 'getmcp' ) : __( 'The remote connection is unavailable or needs a valid URL.', 'getmcp' ),
			);
		}

		// A bundle owns no tools of its own; what it serves is its members.
		// Counting its own rows would mark every bundle "not ready".
		if ( $server->is_bundle() ) {
			$members   = Bundle::member_servers( $server );
			$published = count( Bundle::exposure( $server, $members )['published'] );

			$capabilities_check = array(
				'id'      => 'capabilities',
				'label'   => __( 'Has servers', 'getmcp' ),
				'status'  => $published > 0 ? 'pass' : 'fail',
				'message' => sprintf(
					/* translators: 1: published member count, 2: total member count */
					__( '%1$d of %2$d servers in this project gateway are published.', 'getmcp' ),
					$published,
					count( $members )
				),
			);
		}

		// WHY: config only. Readiness must never make a network call; the
		// "Test connection" button is where the upstream gets contacted.
		if ( $server->is_remote() ) {
			$remote             = RemoteConfig::from_server( $server );
			$capabilities_check = array(
				'id'      => 'capabilities',
				'label'   => __( 'Has an upstream', 'getmcp' ),
				'status'  => null !== $remote ? 'pass' : 'fail',
				'message' => null !== $remote
					? __( 'Tools come from the connected MCP server.', 'getmcp' )
					: __( 'Enter the URL of the MCP server to connect to.', 'getmcp' ),
			);
		}

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
						__( 'Server is "%s" — it will not respond to MCP clients until activated.', 'getmcp' ),
						$server->status
					),
			),
			$capabilities_check,
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
					: __( 'No inbound auth — anyone with the URL can call this server.', 'getmcp' ),
			),
			$this->oauth_check( $server ),
			array(
				'id'      => 'transport',
				'label'   => __( 'Secure transport', 'getmcp' ),
				'status'  => $is_https ? 'pass' : 'warn',
				'message' => $is_https
					? __( 'Endpoint is served over HTTPS.', 'getmcp' )
					: __( 'Endpoint is not HTTPS — fine for local development, not for production.', 'getmcp' ),
			),
			// CORS is deliberately not a readiness check: an open origin list is
			// surfaced inline next to the CORS Origins setting instead, so it
			// never drags the server badge down to Degraded.
			$this->rate_limit_check( $server ),
		);
		// A check that does not apply to this server returns null.
		$checks = array_values( array_filter( $checks ) );

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
	 * Readiness check for a broker server's sign-in provider.
	 *
	 * "Inbound authentication" passes as soon as auth_type is oauth, which
	 * says nothing about whether anyone can actually sign in. This names the
	 * missing provider fields, and warns when Google is set up without
	 * `access_type=offline`: Google then issues no refresh token, so every
	 * person has to sign in again each time the hour-long token ends.
	 * Reads only stored config, never the network.
	 *
	 * @since  1.8.0
	 * @param  Server $server Server instance.
	 * @return array{id: string, label: string, status: string, message: string}|null Null when the server is not a broker.
	 */
	private function oauth_check( Server $server ): ?array {
		if ( 'oauth' !== $server->auth_type || FirstPartyOAuth::owns( $server ) ) {
			return null;
		}

		$config = json_decode( (string) $server->auth_config, true );
		$config = is_array( $config ) ? $config : array();

		$labels  = array(
			'authorize_url' => __( 'Authorize URL', 'getmcp' ),
			'token_url'     => __( 'Token URL', 'getmcp' ),
			'client_id'     => __( 'Client ID', 'getmcp' ),
		);
		$missing = array();
		foreach ( BrokerConfig::REQUIRED as $key ) {
			if ( '' === trim( (string) ( $config[ $key ] ?? '' ) ) ) {
				$missing[] = $labels[ $key ];
			}
		}
		if ( empty( $server->auth_credentials ) ) {
			$missing[] = __( 'Client Secret', 'getmcp' );
		}

		$check = array(
			'id'    => 'oauth',
			'label' => __( 'Sign-in provider', 'getmcp' ),
		);

		if ( $missing ) {
			return $check + array(
				'status'  => 'fail',
				'message' => sprintf(
					/* translators: %s: comma-separated list of missing fields */
					__( 'No one can sign in yet. Missing: %s.', 'getmcp' ),
					implode( ', ', $missing )
				),
			);
		}

		$authorize_url = (string) $config['authorize_url'];
		$host          = rtrim( strtolower( (string) wp_parse_url( $authorize_url, PHP_URL_HOST ) ), '.' );
		if ( 'accounts.google.com' === $host ) {
			// The provider sends extras after any query already in the URL, so
			// offline counts wherever the admin put it.
			parse_str( (string) wp_parse_url( $authorize_url, PHP_URL_QUERY ), $query );
			$extras  = OAuthProvider::forwardable_extra_params( $config['extra_authorize_params'] ?? null );
			$offline = 'offline' === ( $extras['access_type'] ?? $query['access_type'] ?? '' );
			if ( ! $offline ) {
				return $check + array(
					'status'  => 'warn',
					'message' => __( 'Google gives no refresh token without access_type=offline, so people have to sign in again about every hour.', 'getmcp' ),
				);
			}
		}

		return $check + array(
			'status'  => 'pass',
			'message' => __( 'Provider settings are complete.', 'getmcp' ),
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
			$message = __( 'Rate limiting is disabled for this server — it accepts unlimited requests.', 'getmcp' );
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
				: __( 'No rate limit configured — the server accepts unlimited requests.', 'getmcp' );
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
	 * level (this is not an HTTP-level trace — no transport headers, session,
	 * or CORS handling). The endpoint is admin-gated by capability + nonce;
	 * McpAuth is reset first so the loopback runs unrestricted rather than
	 * inheriting any request-scoped API-key/OAuth scopes.
	 *
	 * Note: `tools/call` executes the real upstream request, same as the
	 * per-tool tester — the UI defaults to read-only methods for this reason.
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

		// Clean, unrestricted admin loopback — no client scope state in play.
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
	 * Unlike the loopback, this exercises the full transport stack — rewrite
	 * dispatch, inbound auth (McpAuth), rate limiting, CORS/protocol headers,
	 * and version negotiation — exactly as an external MCP client would hit it.
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
				'headers'     => $headers,
				'body'        => (string) wp_json_encode( $envelope ),
				'timeout'     => 30,
				// A followed redirect resends the credential header to the
				// Location host. Show the 3xx and its Location instead.
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', true ),
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
				array( 'status' => 424 )
			);
		}

		// Mask the credential everywhere it could appear in the trace.
		$masked_headers = $headers;
		foreach ( array( 'Authorization' ) as $sensitive ) {
			if ( isset( $masked_headers[ $sensitive ] ) ) {
				$masked_headers[ $sensitive ] = preg_replace( '/\S+$/', '••••••••', $masked_headers[ $sensitive ] );
			}
		}
		if ( '' !== $api_key ) {
			$masked_headers = array_map(
				static fn( string $value ): string => str_replace( $api_key, '••••••••', $value ),
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
		$data                  = $server->to_array();
		$data['endpoint_url']  = $server->get_endpoint_url();
		$data['auth_revision'] = $server->auth_revision();
		// The Connections tab asks this instead of re-deriving it from
		// auth_type: a stored server on a reserved slug is first-party even
		// with OAuth, and its connections route answers 400.
		$data['is_broker'] = BrokerConnections::is_broker( $server );

		// Whether the stored outbound credential is still the placeholder an
		// AI assistant wrote — the Authentication tab steers the operator to
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
		 * The mailbox password never leaves the server, not even encrypted —
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
		if ( is_array( $data['settings']['ftp'] ?? null ) ) {
			foreach ( array( 'password', 'private_key', 'passphrase' ) as $secret ) {
				$data['settings']['ftp'][ 'has_' . $secret ] = ! empty( $data['settings']['ftp'][ $secret ] );
				unset( $data['settings']['ftp'][ $secret ] );
			}
		}
		if ( is_array( $data['settings']['remote'] ?? null ) ) {
			$data['settings']['remote'] = RemoteConfig::redact( $data['settings']['remote'] );
		}

		// Always re-resolve logo_url server-side. Don't trust the
		// persisted value — the attachment might have been deleted or
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
				// Stored logo_id is stale (attachment deleted) — blank
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
				'validate_callback' => 'rest_validate_request_arg',
			),
			'kind'     => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'standard', 'bundle', 'remote' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'exclude_kind' => array(
				'description'       => __( 'Leave out servers of this kind.', 'getmcp' ),
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'standard', 'bundle', 'remote' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
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
				'validate_callback' => 'rest_validate_request_arg',
			),
			// No validate_callback on purpose: the enum is case-sensitive, callers
			// send 'desc' (Onboarding), and ServerManager upper-cases it anyway.
			'order'    => array(
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => array( 'ASC', 'DESC' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Refuse extra authorize params the broker would not forward and Google
	 * Health scopes that could write, and store the rest exactly as the
	 * authorize redirect will send them.
	 *
	 * @since  1.8.0
	 * @param  mixed $auth_config auth_config JSON string from the request.
	 * @return mixed|WP_Error
	 */
	private static function checked_auth_config( $auth_config ) {
		$config = is_string( $auth_config ) ? json_decode( $auth_config, true ) : null;
		if ( ! is_array( $config ) ) {
			return $auth_config;
		}

		$error = OAuthProvider::extra_params_error( $config['extra_authorize_params'] ?? null );
		if ( null !== $error ) {
			return new WP_Error( 'invalid_extra_authorize_params', $error, array( 'status' => 400 ) );
		}
		$error = HealthData::scope_error( $config['scope'] ?? null );
		if ( null !== $error ) {
			return new WP_Error( 'getmcp_health_scope_not_readonly', $error, array( 'status' => 400 ) );
		}
		$error = OAuthProvider::account_url_error( $config['account_url'] ?? null );
		if ( null !== $error ) {
			return new WP_Error( 'invalid_account_url', $error, array( 'status' => 400 ) );
		}
		$error = OAuthProvider::revocation_url_error( $config['revocation_url'] ?? null );
		if ( null !== $error ) {
			return new WP_Error( 'invalid_revocation_url', $error, array( 'status' => 400 ) );
		}
		return OAuthProvider::with_forwardable_extras( $auth_config );
	}

	/**
	 * The inbound auth a new server gets when the request names none.
	 *
	 * An open project gateway may only hold open servers, so starting one
	 * open made every protected server unpickable. A gateway starts on the
	 * strongest mode the plan allows that needs no setup: WordPress sign-in
	 * with its creator allowed, or else GetMCP API keys.
	 *
	 * @since  1.7.0
	 * @param  string      $kind Requested server kind.
	 * @param  LicenseTier $tier Current plan.
	 * @return string
	 */
	private static function default_auth_type( string $kind, LicenseTier $tier ): string {
		if ( 'bundle' !== $kind ) {
			return 'none';
		}

		return $tier->allows_auth_method( ServerUserAccess::AUTH_TYPE ) ? ServerUserAccess::AUTH_TYPE : 'api-key';
	}

	/**
	 * Refuse inbound modes a bundle cannot check.
	 *
	 * Bearer and Basic only test that a credential is present and leave the
	 * real check to the upstream API. A bundle has none, so either mode would
	 * let any string through to every member.
	 *
	 * @since  1.8.0
	 * @param  string $auth_type Requested inbound auth type.
	 * @return WP_Error|null
	 */
	private static function bundle_auth_error( string $auth_type ): ?WP_Error {
		if ( ! in_array( $auth_type, array( 'bearer', 'basic' ), true ) ) {
			return null;
		}

		return new WP_Error(
			'getmcp_bundle_auth_unsupported',
			__( 'A project gateway has no API of its own to check a passed-through token. Protect it with API keys, OAuth or WordPress sign-in instead.', 'getmcp' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Refuse inbound modes a remote server cannot honour.
	 *
	 * Bearer and Basic leave the check to an API the proxy does not have, the
	 * same gap as a bundle. Per-user upstream sign-in also needs to know which
	 * person is calling, and an API key always runs as one admin, so every
	 * key holder would share that admin's upstream account. OAuth here is the
	 * vendor broker, whose tokens name no WordPress user at all.
	 *
	 * @since  1.8.0
	 * @param  string $auth_type     Inbound auth type the server will have.
	 * @param  string $upstream_auth Upstream auth mode (none, header, oauth).
	 * @return WP_Error|null
	 */
	private static function remote_auth_error( string $auth_type, string $upstream_auth ): ?WP_Error {
		$conflict = RemoteConfig::auth_conflict( $auth_type, $upstream_auth );
		return null === $conflict ? null : new WP_Error( 'getmcp_remote_auth_unsupported', $conflict, array( 'status' => 422 ) );
	}

	/**
	 * Lay the sent settings keys over the stored ones.
	 *
	 * One level deep: a sent key replaces the stored value whole, so a form
	 * that owns a nested block (pii_redaction, mailbox) still sets all of it.
	 * A key sent as null is removed. A key not sent is left as stored.
	 *
	 * @since  1.8.0
	 * @param  array<string, mixed> $stored Settings currently stored.
	 * @param  array<string, mixed> $sent   Settings sent in the request.
	 * @return array<string, mixed>
	 */
	private static function merge_settings( array $stored, array $sent ): array {
		foreach ( $sent as $key => $value ) {
			if ( null === $value ) {
				unset( $stored[ $key ] );
				continue;
			}
			$stored[ $key ] = $value;
		}
		return $stored;
	}

	/**
	 * Settle `settings.remote` for a save.
	 *
	 * Only a remote server may hold the block, so a standard server cannot be
	 * used to park a plain-text secret. The block is validated only when the
	 * request carried one: the stored copy already holds encrypted secrets,
	 * which sanitize() refuses as input, and an absent block means "keep".
	 *
	 * @since  1.8.0
	 * @param  array<string, mixed>       $settings  Settings about to be stored.
	 * @param  mixed                      $submitted Raw `settings.remote`, or null when absent.
	 * @param  bool                       $is_remote Whether the server is remote.
	 * @param  array<string, mixed>       $stored    Block currently stored (empty on create).
	 * @return array<string, mixed>|WP_Error
	 */
	private static function apply_remote_settings( array $settings, $submitted, bool $is_remote, array $stored ) {
		if ( ! $is_remote ) {
			unset( $settings['remote'] );
			return $settings;
		}

		if ( null === $submitted ) {
			if ( array() === $stored ) {
				return new WP_Error(
					'getmcp_remote_invalid',
					__( 'A remote server needs the URL of the MCP server it proxies.', 'getmcp' ),
					array( 'status' => 400 )
				);
			}
			$settings['remote'] = $stored;
			return $settings;
		}

		$block = RemoteConfig::sanitize( $submitted, $stored );
		if ( is_wp_error( $block ) ) {
			return $block;
		}
		$settings['remote'] = $block;
		return $settings;
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
			// Fixed at creation: a server that already has tools cannot turn
			// into a bundle, and a bundle's members cannot turn into tools.
			'kind'        => array(
				'type'        => 'string',
				'default'     => 'standard',
				'enum'        => array( 'standard', 'bundle', 'remote' ),
				'description' => __( 'standard serves its own tools; bundle is a project gateway that serves the tools of the servers chosen as its members; remote proxies the tools of another MCP server.', 'getmcp' ),
			),
			'description' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'instructions' => array(
				'type'    => 'string',
				'default' => '',
				// Intentionally NOT routed through sanitize_textarea_field —
				// sanitize_instructions() runs the right stricter cleanup
				// (control-char strip + length cap) inside the controller.
			),
			'auth_type'             => array(
				'type'    => 'string',
				'default' => 'none',
				'enum'    => array( 'none', 'bearer', 'api-key', 'basic', 'oauth', 'wordpress' ),
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
				// See note on create-endpoint args — sanitize_instructions()
				// runs the stricter cleanup inside the controller.
			),
			'auth_type'             => array(
				'type' => 'string',
				'enum' => array( 'none', 'bearer', 'api-key', 'basic', 'oauth', 'wordpress' ),
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
			'expected_auth_revision' => array(
				'type'        => 'string',
				'pattern'     => '^[a-f0-9]{64}$',
				'description' => __( 'auth_revision the editor loaded. When the PATCH changes sign-in settings and the stored revision differs, the save is refused with 409.', 'getmcp' ),
			),
		);
	}

	/**
	 * Run every settings sanitiser, in the order the save paths rely on.
	 *
	 * Create and both update branches call this so a new sanitiser is added
	 * once; the create path previously drifted and stored pii_redaction,
	 * icon_data, logo and variables unchecked.
	 *
	 * @since  1.6.2
	 * @param  array<string, mixed> $settings  Decoded settings payload.
	 * @param  int                  $server_id Existing server id, 0 on create.
	 * @return array<string, mixed>
	 */
	private function sanitize_settings( array $settings, int $server_id = 0 ): array {
		$settings = $this->sanitize_pii_redaction( $settings );
		$settings = $this->sanitize_icon_data( $settings );
		$settings = $this->sanitize_logo( $settings );
		$settings = $this->sanitize_variables( $settings );
		// WHY: connector sanitisers take the id so a blank secret field keeps
		// the stored one on update; 0 means there is nothing stored to keep.
		$settings = $this->sanitize_mailbox( $settings, $server_id );
		$settings = $this->sanitize_database( $settings, $server_id );
		$settings = $this->sanitize_ftp( $settings, $server_id );
		if ( array_key_exists( 'ability_run_as_user_id', $settings ) ) {
			$settings['ability_run_as_user_id'] = absint( $settings['ability_run_as_user_id'] );
		}
		return $this->keep_launch_kit( $settings, $server_id );
	}

	/**
	 * Refuse a run-as user for ability tools that does not exist or is an
	 * administrator.
	 *
	 * Only a changed value is checked. A user promoted after the setting was
	 * saved is refused at call time by AbilityRunner, so an unrelated save
	 * (renaming the server) must not start failing because of it.
	 *
	 * @since  1.8.0
	 * @param  array<string, mixed> $settings  Settings about to be stored.
	 * @param  int                  $server_id Existing server id, 0 on create.
	 * @return WP_Error|null
	 */
	private function validate_ability_run_as( array $settings, int $server_id = 0 ): ?WP_Error {
		if ( ! array_key_exists( 'ability_run_as_user_id', $settings ) ) {
			return null;
		}

		$user_id = absint( $settings['ability_run_as_user_id'] );
		if ( 0 === $user_id ) {
			return null;
		}

		$server = $server_id > 0 ? $this->manager->get( $server_id ) : false;
		if ( $server && $server->get_ability_run_as_user_id() === $user_id ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'getmcp_invalid_run_as',
				__( 'The selected run-as user does not exist.', 'getmcp' ),
				array(
					'status' => 400,
					'field'  => 'ability_run_as_user_id',
				)
			);
		}

		if ( user_can( $user, 'manage_options' )
			&& ! apply_filters( 'getmcp_ability_allow_admin_run_as', false, $user, $server ?: null ) ) {
			return new WP_Error(
				'getmcp_invalid_run_as',
				__( 'Ability tools cannot run as an administrator: anyone holding this server\'s API key would act with full admin rights. Choose a user with a lower role.', 'getmcp' ),
				array(
					'status' => 400,
					'field'  => 'ability_run_as_user_id',
				)
			);
		}

		return null;
	}

	/**
	 * The Launch Kit has one writer: LaunchKitController. Whatever this
	 * payload says about it is replaced by the stored copy.
	 *
	 * The settings editor PUTs the snapshot it opened with, so without this a
	 * save from a tab opened before a Launch Kit change would silently revert
	 * it — or, when the page has just been made private, re-publish it.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $settings  Settings payload.
	 * @param  int                  $server_id Existing server id, 0 on create or import.
	 * @return array<string, mixed>
	 */
	private function keep_launch_kit( array $settings, int $server_id ): array {
		unset( $settings['launch_kit'] );

		// Create and import start without a page: the kit defaults to private.
		if ( $server_id <= 0 ) {
			return $settings;
		}

		$server = $this->manager->get( $server_id );
		if ( $server ) {
			$stored = \GetMCP\LaunchKit\LaunchKitSettings::stored( $server );
			if ( ! empty( $stored ) ) {
				$settings['launch_kit'] = $stored;
			}
		}

		return $settings;
	}

	/**
	 * Sanitize per-server logo identity in settings.
	 *
	 * Two storage shapes are accepted, both stripped of any client lies:
	 *  - `logo_id`  (preferred): WordPress Media Library attachment ID.
	 *                 Must be an int that resolves to an existing
	 *                 `attachment` post. Anything else → 0.
	 *  - `logo_url` (read-only): we *derive* this server-side from
	 *                 logo_id and overwrite whatever the client sent —
	 *                 the URL is purely a frontend convenience so the
	 *                 admin can preview without waiting for a re-fetch.
	 *
	 * @since  1.13.0
	 * @param  array<string, mixed> $settings Settings array.
	 * @return array<string, mixed> Same shape with logo_id / logo_url validated.
	 */
	private function sanitize_logo( array $settings ): array {
		// logo_id: trust nothing the client sent — verify it actually
		// points at an attachment we own.
		if ( array_key_exists( 'logo_id', $settings ) ) {
			$logo_id = absint( $settings['logo_id'] );
			if ( $logo_id > 0 && 'attachment' === get_post_type( $logo_id ) ) {
				$settings['logo_id'] = $logo_id;
				// Always re-resolve URL — never trust the client's value.
				$url = wp_get_attachment_image_url( $logo_id, 'thumbnail' );
				if ( ! $url ) {
					$url = wp_get_attachment_url( $logo_id );
				}
				$settings['logo_url'] = $url ? esc_url_raw( $url ) : '';
			} else {
				// 0 or invalid → clear both.
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

		// Empty string = explicit "clear icon" — keep it as ''.
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
		// magic bytes (\x89PNG\r\n\x1a\n) — guards against a sneaky
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
	 * clients. Plain-text only — no HTML escaping because MCP clients
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
	 * Sanitize the connection-variables map inside a settings payload.
	 *
	 * Names are placeholder identifiers ([A-Za-z0-9_], max 40 chars); values
	 * are endpoint fragments (subdomains, data-center codes) restricted to
	 * [A-Za-z0-9._-] (max 100) so a stored value can never introduce URL
	 * structure when substituted. Entries failing either rule are dropped,
	 * not mangled — a silently rewritten value would produce a confusing
	 * wrong-host call instead of an obvious missing-variable error.
	 *
	 * @since  1.22.0
	 * @param  array<string, mixed> $settings Decoded settings payload.
	 * @return array<string, mixed> Settings with a clean `variables` map.
	 */
	private function sanitize_variables( array $settings ): array {
		// WHY: a wrapping condition, not an early return — a payload can carry
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
	 * failure is a configuration problem — wrong port, app password required,
	 * outbound 993 blocked by the host — and each produces a distinguishable
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
	 * File server tools this server is missing.
	 *
	 * @since  1.7.0
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_ftp_tools( $request ) {
		return $this->check_connector_tools( $request, \GetMCP\Ftp\FtpTools::class );
	}

	/**
	 * Install any file server tools this server is missing.
	 *
	 * @since  1.7.0
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_ftp_tools( $request ) {
		return $this->sync_connector_tools(
			$request,
			\GetMCP\Ftp\FtpTools::class,
			static function ( int $added ): string {
				if ( 0 === $added ) {
					return __( 'This file server already has every tool.', 'getmcp' );
				}

				return sprintf(
					/* translators: %d: number of tools added. */
					_n( '%d tool added. Anything that uploads, edits, moves or deletes files is off until you enable it.', '%d tools added. Anything that uploads, edits, moves or deletes files is off until you enable it.', $added, 'getmcp' ),
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

		$kind_error = ToolManager::kind_refusal( $server );
		if ( $kind_error ) {
			return $kind_error;
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
	 * database failure is a configuration problem — wrong port, the site's IP
	 * not allowed in, TLS not offered — and each produces a distinguishable
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

	/**
	 * Test a server's file server connection.
	 *
	 * Builds the same drive a tool call would, logs in and lists the root.
	 * On SFTP the response carries the host key fingerprint the server
	 * presented, so the settings screen can offer to pin it.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_ftp( $request ) {
		$server = $this->manager->get_by_uuid( (string) $request->get_param( 'id' ) );

		if ( ! $server ) {
			return new WP_Error(
				'getmcp_not_found',
				__( 'Server not found.', 'getmcp' ),
				array( 'status' => 404 )
			);
		}

		try {
			$config = $server->get_ftp_config();
		} catch ( \RuntimeException $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The stored file server password or key could not be decrypted. Re-enter it and save.', 'getmcp' ),
				)
			);
		}

		if ( empty( $config['host'] ) || empty( $config['username'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Add the host, username and password or key first.', 'getmcp' ),
				)
			);
		}

		$drive   = \GetMCP\Ftp\FtpExecutor::drive_for( $config );
		$started = microtime( true );

		try {
			$drive->connect();

			$entries = $drive->list( $drive->root() );
			$names   = array_map( static fn( array $e ): string => (string) $e['name'], $entries );
			$info    = $drive->describe();

			return new WP_REST_Response(
				array_merge(
					$info,
					array(
						'success'     => true,
						'message'     => sprintf(
							/* translators: 1: protocol label, 2: entry count, 3: directory path. */
							__( 'Connected over %1$s. Found %2$d items in %3$s.', 'getmcp' ),
							strtoupper( str_replace( '_implicit', '', (string) $config['protocol'] ) ),
							count( $entries ),
							(string) $drive->root()
						),
						'entries'     => array_slice( $names, 0, 50 ),
						'entry_count' => count( $entries ),
						'read_only'   => (bool) $config['read_only'],
						'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
					)
				)
			);
		} catch ( \GetMCP\Ftp\FtpException $e ) {
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
			$drive->close();
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
	 * password (encrypt it), an unchanged one (keep what is stored — the API
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
	 * @param string $block     Settings key: 'mailbox', 'database' or 'ftp'.
	 * @param string $field     Secret inside the block: 'password', or for
	 *                          ftp also 'private_key' / 'passphrase'.
	 * @return string Encrypted blob, or '' when none is stored.
	 */
	private function stored_connector_password( int $server_id, string $block, string $field = 'password' ): string {
		if ( $server_id <= 0 ) {
			return '';
		}

		$server = $this->manager->get( $server_id );
		if ( ! $server || empty( $server->settings ) ) {
			return '';
		}

		$decoded = json_decode( (string) $server->settings, true );

		return is_array( $decoded ) ? (string) ( $decoded[ $block ][ $field ] ?? '' ) : '';
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
	 * Normalise and secure the `ftp` settings block.
	 *
	 * Three secrets instead of one — password, private key, key passphrase —
	 * and each follows the mailbox rules: new value encrypted, unchanged
	 * sentinel or blank keeps what is stored, already-encrypted left alone.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string, mixed> $settings  Settings array.
	 * @param int                  $server_id Server being updated, 0 on create.
	 * @return array<string, mixed>
	 */
	private function sanitize_ftp( array $settings, int $server_id = 0 ): array {
		if ( ! isset( $settings['ftp'] ) ) {
			return $settings;
		}

		if ( ! is_array( $settings['ftp'] ) ) {
			unset( $settings['ftp'] );
			return $settings;
		}

		$raw = $settings['ftp'];

		$protocol = strtolower( trim( (string) ( $raw['protocol'] ?? 'sftp' ) ) );
		$protocol = in_array( $protocol, array( 'ftp', 'ftps', 'ftps_implicit', 'sftp' ), true ) ? $protocol : 'sftp';
		$auth     = strtolower( trim( (string) ( $raw['auth_mode'] ?? 'password' ) ) );
		// WHY: only SSH has key login; FTP/FTPS always authenticate by password.
		$auth     = 'sftp' === $protocol && 'key' === $auth ? 'key' : 'password';
		$default  = 'sftp' === $protocol ? 22 : ( 'ftps_implicit' === $protocol ? 990 : 21 );

		$clean = array(
			'provider'             => sanitize_text_field( (string) ( $raw['provider'] ?? '' ) ),
			'protocol'             => $protocol,
			'host'                 => sanitize_text_field( (string) ( $raw['host'] ?? '' ) ),
			'port'                 => min( 65535, max( 1, absint( $raw['port'] ?? $default ) ) ),
			'username'             => sanitize_text_field( (string) ( $raw['username'] ?? '' ) ),
			'auth_mode'            => $auth,
			// "SHA256:" + base64; anything else cannot be part of a fingerprint.
			'host_key_fingerprint' => (string) preg_replace( '/[^A-Za-z0-9+\/=:]/', '', (string) ( $raw['host_key_fingerprint'] ?? '' ) ),
			'validate_cert'        => ! isset( $raw['validate_cert'] ) || rest_sanitize_boolean( $raw['validate_cert'] ),
			'root_path'            => sanitize_text_field( (string) ( $raw['root_path'] ?? '' ) ),
			'read_only'            => ! isset( $raw['read_only'] ) || rest_sanitize_boolean( $raw['read_only'] ),
			'max_file_size'        => min( 20, max( 1, absint( $raw['max_file_size'] ?? 5 ) ) ),
			'timeout'              => min( 300, max( 1, absint( $raw['timeout'] ?? 30 ) ) ),
		);

		foreach ( array( 'password', 'private_key', 'passphrase' ) as $field ) {
			$submitted = (string) ( $raw[ $field ] ?? '' );

			if ( self::MAILBOX_PASSWORD_UNCHANGED === $submitted || '' === $submitted ) {
				$clean[ $field ] = $this->stored_connector_password( $server_id, 'ftp', $field );
			} elseif ( str_starts_with( $submitted, 'enc:v1:' ) ) {
				$clean[ $field ] = $submitted;
			} else {
				$value = 'private_key' === $field ? self::sanitize_private_key( $submitted ) : $submitted;
				// WHY: a key rejected as oversized must not overwrite a working stored one.
				$clean[ $field ] = '' === $value
					? $this->stored_connector_password( $server_id, 'ftp', $field )
					: \GetMCP\Utils\Encryption::encrypt( $value );
			}
		}

		// WHY: blank keeps the stored secret, so after a switch of login method
		// the old one would sit on disk forever. Drop what this mode never sends.
		$unused = 'key' === $auth ? array( 'password' ) : array( 'private_key', 'passphrase' );
		foreach ( $unused as $field ) {
			$clean[ $field ] = '';
		}

		$settings['ftp'] = $clean;

		return $settings;
	}

	/**
	 * Keep only what an SSH private key file can contain.
	 *
	 * Not sanitize_pem(): PuTTY .ppk keys carry "Key: value" header lines, and
	 * that sanitiser strips the colon, which corrupts the key.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key Submitted key text.
	 * @return string
	 */
	private static function sanitize_private_key( string $key ): string {
		$key = trim( str_replace( array( "\r\n", "\r" ), "\n", $key ) );

		// Printable ASCII plus newline; the largest real keys (RSA 16384) are ~13 KB.
		$key = (string) preg_replace( '/[^\x20-\x7E\n]/', '', $key );

		return strlen( $key ) > 16384 ? '' : $key;
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

	/**
	 * Sanitize the `pii_redaction` block inside a settings array.
	 *
	 * Validation only — no errors thrown. Invalid custom rules (e.g.
	 * uncompilable regex) are dropped so the user sees what's saved
	 * after the round trip and can fix offending entries. Scope is
	 * clamped to the known enum, presets are kept only when they map
	 * to a real preset key.
	 *
	 * @since  1.13.0
	 * @param  array<string, mixed> $settings Settings array (may or may not contain `pii_redaction`).
	 * @return array<string, mixed> Same shape with `pii_redaction` normalized when present.
	 */
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

		$scope = $raw['scope'] ?? 'both';

		$settings['pii_redaction'] = array(
			'enabled' => ! empty( $raw['enabled'] ),
			'scope'   => in_array( $scope, $allowed_scope, true ) ? $scope : 'both',
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
		return $this->import_manifest( $request->get_json_params(), $request->get_param( 'name' ) );
	}

	/**
	 * Create a server from an exported manifest fetched from a URL.
	 *
	 * The body is never echoed back: private network addresses are allowed by
	 * default, so a response that repeated what the URL returned would let an
	 * admin page read internal services.
	 *
	 * @since  1.7.1
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_from_url( $request ): WP_REST_Response|WP_Error {
		// Both checked again in import_manifest(); here they save a pointless fetch.
		$limited = $this->import_limit_error( LicenseTier::current() );
		if ( $limited ) {
			return $limited;
		}
		$gated = $this->guard_import( 'getmcp' );
		if ( $gated ) {
			return $gated;
		}

		$body = RemoteFetch::get(
			(string) $request->get_param( 'url' ),
			10 * MB_IN_BYTES,
			__( 'Could not fetch the export from that URL. Download the file and import it instead.', 'getmcp' )
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$manifest = json_decode( $body, true );
		if ( ! is_array( $manifest ) ) {
			return new WP_Error(
				'invalid_manifest',
				__( 'That URL did not return a GetMCP server export.', 'getmcp' ),
				array( 'status' => 400 )
			);
		}

		return $this->import_manifest( $manifest, $request->get_param( 'name' ) );
	}

	/**
	 * The plan-limit refusal for adding one more server, or null if allowed.
	 *
	 * Create and duplicate share it, so a copy cannot get round the cap.
	 *
	 * @since  1.8.0
	 * @param  LicenseTier $tier Current tier.
	 * @return WP_Error|null
	 */
	private function server_limit_error( LicenseTier $tier ): ?WP_Error {
		$count = $this->manager->get_count();

		if ( $tier->can_create_server( $count ) ) {
			return null;
		}

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

	/**
	 * The plan-limit refusal for importing one more server, or null if allowed.
	 *
	 * Same cap as creating one by hand: importing must not be a way round
	 * the plan limit.
	 *
	 * @since  1.7.1
	 * @param  LicenseTier $tier Current tier.
	 * @return WP_Error|null
	 */
	private function import_limit_error( LicenseTier $tier ): ?WP_Error {
		$count = $this->manager->get_count();

		if ( $tier->can_create_server( $count ) ) {
			return null;
		}

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

	/**
	 * Reject the request when the current tier doesn't permit this import method.
	 *
	 * @since  1.7.1
	 * @param  string $type Import method, as listed in LicenseTier::TIERS.
	 * @return WP_Error|null Error to return immediately, or null when allowed.
	 */
	private function guard_import( string $type ): ?WP_Error {
		$tier = LicenseTier::current();
		if ( $tier->allows_import( $type ) ) {
			return null;
		}

		return $this->tier_limit_error(
			'tier_limit_import_method',
			sprintf(
				/* translators: %s: tier id. */
				__( 'Importing a server export is not available on your %s plan.', 'getmcp' ),
				strtoupper( $tier->id() )
			),
			$tier,
			array( 'import_method' => $type )
		);
	}

	/**
	 * Validate, sanitize and import a manifest, from either import route.
	 *
	 * @since  1.7.1
	 * @param  mixed       $manifest Decoded manifest.
	 * @param  string|null $name     Optional name override.
	 * @return WP_REST_Response|WP_Error
	 */
	private function import_manifest( $manifest, $name ): WP_REST_Response|WP_Error {
		$tier    = LicenseTier::current();
		$limited = $this->import_limit_error( $tier );
		if ( $limited ) {
			return $limited;
		}

		// The file route lands here directly, so the plan check cannot live
		// only in import_from_url().
		$gated = $this->guard_import( 'getmcp' );
		if ( $gated ) {
			return $gated;
		}

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
			// WHY: the manifest may come from a fetched URL, so an unknown value
			// is never echoed; only the methods GetMCP itself defines are named.
			if ( ! in_array( $auth_type, array( 'none', 'bearer', 'api-key', 'basic', 'oauth', 'wordpress' ), true ) ) {
				return $this->tier_limit_error(
					'tier_limit_auth_method',
					sprintf(
						/* translators: %s: tier id. */
						__( 'This export uses an authentication method that is not available on your %s plan.', 'getmcp' ),
						strtoupper( $tier->id() )
					),
					$tier
				);
			}

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

		// WHY: ServerTransfer allowlists settings keys but never checks their
		// values, so a hand-edited manifest could store a broken PII regex or
		// control characters in the instructions.
		if ( isset( $manifest['server']['settings'] ) && is_array( $manifest['server']['settings'] ) ) {
			$settings = $this->sanitize_settings( $manifest['server']['settings'] );

			if ( isset( $settings['instructions'] ) ) {
				$settings['instructions'] = self::sanitize_instructions( (string) $settings['instructions'] );
			}
			// logo_id is site-local and never exported, so sanitize_logo()
			// leaves the portable logo_url alone.
			if ( isset( $settings['logo_url'] ) ) {
				$settings['logo_url'] = esc_url_raw( (string) $settings['logo_url'] );
			}

			$manifest['server']['settings'] = $settings;
		}

		$result = ServerTransfer::import( $manifest, $name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}
}
