<?php
/**
 * Tools exposed by the built-in GetMCP server.
 *
 * These are PHP, not stored HTTP tool definitions: they call this
 * installation's own managers directly. Routing them back out through the REST
 * API would mean an MCP request re-authenticating against a second surface as
 * a cookie-less caller, which is both slower and a second place for the
 * permission rules to disagree with themselves.
 *
 * Every handler re-checks the capability of the user the access token was
 * issued to. A valid token proves which account authorized the client; it does
 * not prove that account may still edit servers, and roles change after tokens
 * are minted.
 *
 * @package GetMCP
 * @since   1.4.0
 */

namespace GetMCP\Builtin;

use GetMCP\Auth\McpAuth;
use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Utils\Encryption;
use GetMCP\Core\Tool;
use GetMCP\Core\ToolManager;
use GetMCP\Protocol\JsonRpcException;
use GetMCP\Utils\PublicUrl;

/**
 * Catalogue and dispatcher for the built-in server's tools.
 *
 * @since 1.4.0
 */
class BuiltinTools {

	/**
	 * Capability required to read configuration.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private const CAP_READ = 'getmcp_manage_servers';

	/**
	 * Capability required to change configuration.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private const CAP_WRITE = 'getmcp_manage_servers';

	/**
	 * The tool catalogue, in the order clients should see it.
	 *
	 * Read tools come first on purpose: the list is what a model scans before
	 * choosing, and the safe way to work here is to look something up before
	 * editing it.
	 *
	 * `annotations` are the MCP safety hints. They are stated explicitly rather
	 * than derived from an HTTP method, because these tools have none — the
	 * derivation that serves stored tools has nothing to read.
	 *
	 * @since  1.4.0
	 * @return array<string, array<string, mixed>>
	 */
	public static function catalogue(): array {
		return array(

			/* ---------------------------------------------------------- Read */
			'search_oauth_users' => array(
				'title' => __( 'Search WordPress OAuth users', 'getmcp' ),
				'description' => 'Search by name or username (at least two characters); or look up up to 100 selected user IDs. Requires server-management permission. Returns minimal user details, never credentials.',
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema' => array( 'type' => 'object', 'properties' => array(
					'search' => array( 'type' => 'string', 'maxLength' => 100 ),
					'include' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'page' => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
				) ),
			),
			'get_oauth_allowed_users' => array(
				'title' => __( 'Get server OAuth access', 'getmcp' ),
				'description' => 'Read the saved allowlist for a custom server using GetMCP / WordPress login. An empty list denies everyone. Fetch this before changing access.',
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema' => array( 'type' => 'object', 'required' => array( 'server_id' ), 'properties' => array( 'server_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			),
			'set_oauth_allowed_users' => array(
				'title' => __( 'Set server OAuth access', 'getmcp' ),
				'description' => 'Replace the user allowlist for an existing native OAuth custom server. This grants or revokes access: require explicit user authorisation. Does not switch authentication mode or change API credentials. Pass expected_user_ids from the prior read to reject stale updates.',
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema' => array( 'type' => 'object', 'required' => array( 'server_id', 'allowed_user_ids' ), 'properties' => array(
					'server_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'allowed_user_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'expected_user_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				) ),
			),

			'list_servers' => array(
				'title'       => __( 'List MCP servers', 'getmcp' ),
				'description' => 'List the MCP servers on this GetMCP installation, with their slug, status, tool count and public endpoint URL. Call this first when you need a server id.',
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive', 'any' ),
							'description' => 'Filter by status. Defaults to any.',
						),
						'search' => array(
							'type'        => 'string',
							'description' => 'Match against the server name.',
						),
					),
				),
			),

			'get_server' => array(
				'title'       => __( 'Get an MCP server', 'getmcp' ),
				'description' => 'Fetch one MCP server in full, including its auth type, CORS origins, rate limit and instructions. Credentials are never returned.',
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'server_id' ),
					'properties' => array(
						'server_id' => array(
							'type'        => 'integer',
							'description' => 'Numeric server id, as returned by list_servers.',
						),
					),
				),
			),

			'list_tools' => array(
				'title'       => __( 'List tools on a server', 'getmcp' ),
				'description' => 'List the tools belonging to one MCP server, with their id, slug, HTTP method, endpoint and status.',
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'server_id' ),
					'properties' => array(
						'server_id' => array( 'type' => 'integer', 'description' => 'Numeric server id.' ),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive', 'draft', 'any' ),
							'description' => 'Filter by status. Defaults to any.',
						),
					),
				),
			),

			'get_tool' => array(
				'title'       => __( 'Get a tool', 'getmcp' ),
				'description' => 'Fetch one tool in full, including its input schema, parameter mapping, headers and body template. Fetch before updating so you can preserve the fields you are not changing.',
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'tool_id' ),
					'properties' => array(
						'tool_id' => array( 'type' => 'integer', 'description' => 'Numeric tool id.' ),
					),
				),
			),

			/* --------------------------------------------------------- Write */

			'create_server' => array(
				'title'       => __( 'Create an MCP server', 'getmcp' ),
				'description' => 'Create a new MCP server. Returns the new server id and its public endpoint URL. The slug is derived from the name and made unique; auth defaults to none, so set auth_type if the endpoint should be protected.',
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name'         => array( 'type' => 'string', 'description' => 'Human-readable server name.' ),
						'slug'         => array( 'type' => 'string', 'description' => 'Optional URL slug. Derived from the name when omitted.' ),
						'auth_type'    => array(
							'type'        => 'string',
							'enum'        => array( 'none', 'api-key', 'oauth' ),
							'description' => 'How clients authenticate to this server. Defaults to none.',
						),
						'instructions' => array( 'type' => 'string', 'description' => 'Guidance handed to connecting clients.' ),
						'status'       => array( 'type' => 'string', 'enum' => array( 'active', 'inactive' ) ),
						'api_auth'     => array(
							'type'        => 'string',
							'enum'        => array( 'none', 'bearer', 'api-key', 'basic' ),
							'description' => 'How this server authenticates to the API its tools call. Set this whenever the API needs a credential: it is configured with a placeholder the user replaces, and every tool then inherits it. Never put the credential in a tool header.',
						),
						'api_key_name'     => array(
							'type'        => 'string',
							'description' => 'For api-key only: the header or query parameter name, e.g. X-API-Key. Defaults to X-API-Key.',
						),
						'api_key_location' => array(
							'type'        => 'string',
							'enum'        => array( 'header', 'query' ),
							'description' => 'For api-key only: where the key is sent. Defaults to header.',
						),
						'connection_variables' => array(
							'type'                 => 'object',
							'description'          => 'Account-specific parts of the API endpoint, as name => value — a Mailchimp data centre, a Freshdesk subdomain, an ActiveCampaign account host. Write the endpoint as https://{{subdomain}}.freshdesk.com/api/v2/tickets and declare {"subdomain": ""} here; every tool on the server then shares one setting instead of asking the model for it on each call. Pass a value only if the user told you theirs — otherwise leave it empty and it is stored as a placeholder for them to fill in.',
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
				),
			),

			'update_server' => array(
				'title'       => __( 'Update an MCP server', 'getmcp' ),
				'description' => 'Change settings on an existing MCP server. Only the fields you pass are modified. Fetch the server first if you need to know its current values.',
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'server_id' ),
					'properties' => array(
						'server_id'          => array( 'type' => 'integer', 'description' => 'Numeric server id.' ),
						'name'               => array( 'type' => 'string' ),
						'status'             => array( 'type' => 'string', 'enum' => array( 'active', 'inactive' ) ),
						'auth_type'          => array( 'type' => 'string', 'enum' => array( 'none', 'api-key', 'oauth' ) ),
						'instructions'       => array( 'type' => 'string' ),
						'rate_limit_per_min' => array( 'type' => 'integer', 'description' => 'Requests per minute. 0 disables the limit.' ),
						'api_auth'           => array(
							'type'        => 'string',
							'enum'        => array( 'none', 'bearer', 'api-key', 'basic' ),
							'description' => 'How this server authenticates to the API its tools call. Configured with a placeholder the user replaces.',
						),
						'api_key_name'       => array( 'type' => 'string', 'description' => 'For api-key only: header or query parameter name.' ),
						'api_key_location'   => array( 'type' => 'string', 'enum' => array( 'header', 'query' ), 'description' => 'For api-key only: where the key is sent.' ),
						'connection_variables' => array(
							'type'                 => 'object',
							'description'          => 'Account-specific parts of the API endpoint, as name => value — a Mailchimp data centre, a Freshdesk subdomain, an ActiveCampaign account host. Write the endpoint as https://{{subdomain}}.freshdesk.com/api/v2/tickets and declare {"subdomain": ""} here; every tool on the server then shares one setting instead of asking the model for it on each call. Pass a value only if the user told you theirs — otherwise leave it empty and it is stored as a placeholder for them to fill in.',
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
				),
			),

			'create_tool' => array(
				'title'       => __( 'Create a tool', 'getmcp' ),
				'description' => 'Add a tool to a server: an HTTP endpoint the model can call. Declare parameters in input_schema as JSON Schema; each property becomes a tool argument, and one whose name matches a {{placeholder}} in endpoint_url fills that placeholder. Account-level parts of the address — a data centre or subdomain in the hostname — belong in the server\'s connection_variables instead, so they are set once rather than guessed on every call. Do not put credentials in headers: authentication belongs on the server, via create_server or update_server.',
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'server_id', 'name', 'endpoint_url' ),
					'properties' => array(
						'server_id'    => array( 'type' => 'integer', 'description' => 'Server the tool belongs to.' ),
						'name'         => array( 'type' => 'string', 'description' => 'Tool name shown to clients.' ),
						'description'  => array( 'type' => 'string', 'description' => 'What the tool does. Models rely on this to choose it, so be specific.' ),
						'endpoint_url' => array( 'type' => 'string', 'description' => 'Absolute URL to call. {{placeholders}} are substituted from arguments.' ),
						'http_method'  => array(
							'type'        => 'string',
							'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
							'description' => 'Defaults to GET. Also decides the safety hints clients see unless annotations are pinned.',
						),
						'input_schema' => array( 'type' => 'object', 'description' => 'JSON Schema object describing the tool arguments.' ),
						'parameter_mapping' => array(
							'type'        => 'object',
							'description' => 'Where each argument travels in the HTTP request, as {"arg_name": {"target": "path|query|body|header", "key": "name in the request"}}. Usually unnecessary — path placeholders bind themselves, and other arguments default to the query string on GET/DELETE and the body on POST/PUT/PATCH. Declare a mapping only when the API breaks that convention, e.g. a DELETE that reads a JSON body: {"row_ids": {"target": "body"}}. Never map an argument to an authentication header.',
							'additionalProperties' => array(
								'type'       => 'object',
								'properties' => array(
									'target' => array( 'type' => 'string', 'enum' => array( 'path', 'query', 'body', 'header' ) ),
									'key'    => array( 'type' => 'string', 'description' => 'Name in the request. Defaults to the argument name.' ),
								),
								'required'   => array( 'target' ),
							),
						),
						'headers'      => array( 'type' => 'object', 'description' => 'Static request headers, as a name/value object.' ),
						'status'       => array( 'type' => 'string', 'enum' => array( 'active', 'inactive', 'draft' ) ),
					),
				),
			),

			'update_tool' => array(
				'title'       => __( 'Update a tool', 'getmcp' ),
				'description' => 'Change an existing tool. Only the fields you pass are modified. Fetch the tool first so you do not drop its input schema or parameter mapping.',
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'tool_id' ),
					'properties' => array(
						'tool_id'      => array( 'type' => 'integer', 'description' => 'Numeric tool id.' ),
						'name'         => array( 'type' => 'string' ),
						'description'  => array( 'type' => 'string' ),
						'endpoint_url' => array( 'type' => 'string' ),
						'http_method'  => array( 'type' => 'string', 'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) ),
						'input_schema' => array( 'type' => 'object' ),
						'parameter_mapping' => array(
							'type'        => 'object',
							'description' => 'Where each argument travels in the HTTP request, as {"arg_name": {"target": "path|query|body|header", "key": "name in the request"}}. Usually unnecessary — path placeholders bind themselves, and other arguments default to the query string on GET/DELETE and the body on POST/PUT/PATCH. Declare a mapping only when the API breaks that convention, e.g. a DELETE that reads a JSON body: {"row_ids": {"target": "body"}}. Never map an argument to an authentication header.',
							'additionalProperties' => array(
								'type'       => 'object',
								'properties' => array(
									'target' => array( 'type' => 'string', 'enum' => array( 'path', 'query', 'body', 'header' ) ),
									'key'    => array( 'type' => 'string' ),
								),
								'required'   => array( 'target' ),
							),
						),
						'headers'      => array( 'type' => 'object' ),
						'status'       => array( 'type' => 'string', 'enum' => array( 'active', 'inactive', 'draft' ) ),
					),
				),
			),

			/* ---------------------------------------------------- Destructive */

			'delete_tool' => array(
				'title'       => __( 'Delete a tool', 'getmcp' ),
				'description' => 'Permanently delete a tool. This cannot be undone and any client calling that tool will start failing. Confirm with the user before calling it, and pass confirm: true.',
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
				'schema'      => array(
					'type'       => 'object',
					'required'   => array( 'tool_id', 'confirm' ),
					'properties' => array(
						'tool_id' => array( 'type' => 'integer', 'description' => 'Numeric tool id.' ),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true. A deliberate second step, because deletion is irreversible.',
						),
					),
				),
			),
		);
	}

	/**
	 * The catalogue in MCP tools/list shape.
	 *
	 * @since  1.4.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function as_mcp_list(): array {
		$tools = array( \GetMCP\Gateway\ManagementTool::definition(), \GetMCPExtensions\MarketingManagementTool::definition() );

		foreach ( self::catalogue() as $name => $spec ) {
			$schema = $spec['schema'];

			// An empty `properties` must serialise as {} — PHP would otherwise
			// emit [] for the empty array and clients reject that as a schema.
			if ( empty( $schema['properties'] ) ) {
				$schema['properties'] = new \stdClass();
			}

			$tools[] = array(
				'name'        => $name,
				'title'       => $spec['title'],
				'description' => $spec['description'],
				'inputSchema' => $schema,
				'annotations' => array_merge( array( 'title' => $spec['title'] ), $spec['annotations'] ),
			);
		}

		return $tools;
	}

	/**
	 * Whether a name is one of the built-in tools.
	 *
	 * @since  1.4.0
	 * @param  string $name Tool name.
	 * @return bool
	 */
	public static function has( string $name ): bool {
		if ( in_array( $name, array( 'manage_mcp_connections', 'manage_provider_configuration' ), true ) ) { return true; }
		return isset( self::catalogue()[ $name ] );
	}

	/**
	 * Run a built-in tool and return an MCP tools/call result.
	 *
	 * @since  1.4.0
	 * @param  string               $name      Tool name.
	 * @param  array<string, mixed> $arguments Client-supplied arguments.
	 * @return array<string, mixed> MCP result payload.
	 * @throws JsonRpcException When the tool is unknown or arguments are invalid.
	 */
	public static function call( string $name, array $arguments ): array {
		if ( 'manage_mcp_connections' === $name ) { return \GetMCP\Gateway\ManagementTool::call( $arguments ); }
		if ( 'manage_provider_configuration' === $name ) { return \GetMCPExtensions\MarketingManagementTool::call( $arguments ); }
		if ( ! self::has( $name ) ) {
			throw new JsonRpcException(
				-32602,
				/* translators: %s: tool name. */
				sprintf( __( 'Unknown tool: %s', 'getmcp' ), $name )
			);
		}

		self::assert_required( $name, $arguments );

		try {
			$payload = self::dispatch( $name, $arguments );
		} catch ( JsonRpcException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// Surfaced as an MCP tool error rather than a JSON-RPC fault: the
			// model can read it, explain it and try something else, whereas a
			// protocol error just ends the call.
			return self::error( $e->getMessage() );
		}

		return self::ok( $payload );
	}

	/**
	 * Dispatch to the handler for a tool.
	 *
	 * @since  1.4.0
	 * @param  string               $name      Tool name.
	 * @param  array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>|string Result payload.
	 * @throws JsonRpcException When the caller lacks permission.
	 */
	private static function dispatch( string $name, array $arguments ) {
		switch ( $name ) {
			case 'search_oauth_users':
			case 'get_oauth_allowed_users':
			case 'set_oauth_allowed_users':
				self::require_cap( self::CAP_WRITE );
				$scope = 'set_oauth_allowed_users' === $name ? 'mcp:write' : 'mcp:read';
				if ( ! McpAuth::has_scope( $scope ) && ! McpAuth::has_scope( 'mcp:admin' ) ) {
					throw new JsonRpcException( -32003, __( 'Insufficient OAuth scope.', 'getmcp' ) );
				}
				$actor = (int) McpAuth::current_token_row()->user_id;
				$result = match ( $name ) {
					'search_oauth_users' => \GetMCP\Auth\OAuthUserAccess::search( $arguments, $actor ),
					'get_oauth_allowed_users' => \GetMCP\Auth\OAuthUserAccess::get( (int) $arguments['server_id'], $actor ),
					default => \GetMCP\Auth\OAuthUserAccess::set( (int) $arguments['server_id'], $arguments['allowed_user_ids'], $arguments['expected_user_ids'] ?? null, $actor ),
				};
				if ( is_wp_error( $result ) ) { throw new JsonRpcException( -32602, $result->get_error_message() ); }
				return $result;
			case 'list_servers':
				self::require_cap( self::CAP_READ );
				return self::list_servers( $arguments );

			case 'get_server':
				self::require_cap( self::CAP_READ );
				return self::get_server( $arguments );

			case 'list_tools':
				self::require_cap( self::CAP_READ );
				return self::list_tools( $arguments );

			case 'get_tool':
				self::require_cap( self::CAP_READ );
				return self::get_tool( $arguments );

			case 'create_server':
				self::require_cap( self::CAP_WRITE );
				return self::create_server( $arguments );

			case 'update_server':
				self::require_cap( self::CAP_WRITE );
				return self::update_server( $arguments );

			case 'create_tool':
				self::require_cap( self::CAP_WRITE );
				return self::create_tool( $arguments );

			case 'update_tool':
				self::require_cap( self::CAP_WRITE );
				return self::update_tool( $arguments );

			case 'delete_tool':
				self::require_cap( self::CAP_WRITE );
				return self::delete_tool( $arguments );
		}

		throw new JsonRpcException( -32603, __( 'Tool has no handler.', 'getmcp' ) );
	}

	/* ------------------------------------------------------------- Handlers */

	/**
	 * List servers.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private static function list_servers( array $args ): array {
		$manager = new ServerManager();
		$query   = array( 'per_page' => 100, 'page' => 1 );

		$status = isset( $args['status'] ) ? (string) $args['status'] : 'any';
		if ( 'any' !== $status && '' !== $status ) {
			$query['status'] = $status;
		}
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = (string) $args['search'];
		}

		$result       = $manager->list( $query );
		$tool_manager = new ToolManager();
		$servers      = array();

		foreach ( ( $result['items'] ?? array() ) as $server ) {
			$tools = $tool_manager->get_by_server( (int) $server->id, array( 'per_page' => 1, 'status' => 'active' ) );

			$servers[] = array(
				'server_id'   => (int) $server->id,
				'name'        => $server->name,
				'slug'        => $server->slug,
				'status'      => $server->status,
				'auth_type'   => $server->auth_type,
				'tool_count'  => (int) ( $tools['total'] ?? 0 ),
				'endpoint'    => PublicUrl::server( $server->slug ),
			);
		}

		return array(
			'count'   => count( $servers ),
			'servers' => $servers,
		);
	}

	/**
	 * Fetch one server.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When the server does not exist.
	 */
	private static function get_server( array $args ): array {
		$server = self::find_server( (int) $args['server_id'] );

		$tool_manager = new ToolManager();
		$tools        = $tool_manager->get_by_server( $server->id, array( 'per_page' => 1 ) );

		return array(
			'server_id'          => (int) $server->id,
			'name'               => $server->name,
			'slug'               => $server->slug,
			'status'             => $server->status,
			'auth_type'          => $server->auth_type,
			'transport_type'     => $server->transport_type,
			'rate_limit_per_min' => (int) $server->rate_limit_per_min,
			'cors_origins'       => $server->cors_origins,
			'instructions'       => $server->get_instructions(),
			'api_auth'           => $server->outbound_auth_type ?: 'none',
			// Never the credential itself — only whether it still needs a human.
			'api_auth_pending'   => self::is_placeholder( self::decrypt_quietly( $server->outbound_auth_credentials ) ),
			// Configured values, then the names still waiting on a human. A
			// tool whose URL needs one of the latter cannot run yet, and that
			// is worth knowing before writing more tools against it.
			'connection_variables'         => self::as_object( $server->get_variables() ),
			'connection_variables_pending' => self::pending_variables( self::stored_variables( $server ) ),
			'tool_count'         => (int) ( $tools['total'] ?? 0 ),
			'endpoint'           => PublicUrl::server( $server->slug ),
			'created_at'         => $server->created_at,
			'updated_at'         => $server->updated_at,
		);
	}

	/**
	 * List a server's tools.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When the server does not exist.
	 */
	private static function list_tools( array $args ): array {
		$server = self::find_server( (int) $args['server_id'] );

		$query  = array( 'per_page' => 200, 'orderby' => 'sort_order', 'order' => 'ASC' );
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'any';
		if ( 'any' !== $status && '' !== $status ) {
			$query['status'] = $status;
		}

		$result = ( new ToolManager() )->get_by_server( $server->id, $query );
		$tools  = array();

		foreach ( ( $result['items'] ?? array() ) as $tool ) {
			$tools[] = array(
				'tool_id'      => (int) $tool->id,
				'name'         => $tool->name,
				'slug'         => $tool->slug,
				'description'  => $tool->description,
				'http_method'  => $tool->http_method,
				'endpoint_url' => $tool->endpoint_url,
				'status'       => $tool->status,
			);
		}

		return array(
			'server_id' => (int) $server->id,
			'count'     => count( $tools ),
			'tools'     => $tools,
		);
	}

	/**
	 * Fetch one tool.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When the tool does not exist.
	 */
	private static function get_tool( array $args ): array {
		$tool = self::find_tool( (int) $args['tool_id'] );

		return array(
			'tool_id'           => (int) $tool->id,
			'server_id'         => (int) $tool->server_id,
			'name'              => $tool->name,
			'slug'              => $tool->slug,
			'description'       => $tool->description,
			'endpoint_url'      => $tool->endpoint_url,
			'http_method'       => $tool->http_method,
			'input_schema'      => self::decode( $tool->input_schema ),
			'parameter_mapping' => self::decode( $tool->parameter_mapping ),
			'body_template'     => self::decode( $tool->body_template ),
			'headers'           => self::decode( $tool->headers ),
			'annotations'       => $tool->get_effective_annotations(),
			'timeout'           => (int) $tool->timeout,
			'status'            => $tool->status,
		);
	}

	/**
	 * Create a server.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When the slug is reserved or creation fails.
	 */
	private static function create_server( array $args ): array {
		if ( ! empty( $args['slug'] ) && BuiltinServer::slug_is_reserved( (string) $args['slug'] ) ) {
			throw new JsonRpcException(
				-32602,
				/* translators: %s: reserved slug. */
				sprintf( __( 'The slug "%s" is reserved for the built-in GetMCP server. Choose another.', 'getmcp' ), BuiltinServer::SLUG )
			);
		}

		$data = array(
			'name'      => sanitize_text_field( (string) $args['name'] ),
			'status'    => self::one_of( $args['status'] ?? 'active', array( 'active', 'inactive' ), 'active' ),
			'auth_type' => self::one_of( $args['auth_type'] ?? 'none', array( 'none', 'api-key', 'oauth' ), 'none' ),
		);

		if ( ! empty( $args['slug'] ) ) {
			$data['slug'] = sanitize_title( (string) $args['slug'] );
		}
		$settings = array();

		if ( isset( $args['instructions'] ) ) {
			$settings['instructions'] = (string) $args['instructions'];
		}

		$variables = self::connection_variables( $args );

		if ( ! empty( $variables ) ) {
			$settings['variables'] = $variables;
		}
		if ( ! empty( $settings ) ) {
			$data['settings'] = wp_json_encode( $settings );
		}

		$api_auth = self::one_of( $args['api_auth'] ?? 'none', array( 'none', 'bearer', 'api-key', 'basic' ), 'none' );
		$data     = array_merge( $data, self::placeholder_outbound_auth( $api_auth, $args ) );

		$server = ( new ServerManager() )->create( $data );

		if ( ! $server ) {
			throw new JsonRpcException( -32603, __( 'The server could not be created.', 'getmcp' ) );
		}

		$result = array(
			'created'   => true,
			'server_id' => (int) $server->id,
			'name'      => $server->name,
			'slug'      => $server->slug,
			'auth_type' => $server->auth_type,
			'api_auth'  => $api_auth,
			'endpoint'  => PublicUrl::server( $server->slug ),
			'next_step' => 'Add tools with create_tool, then give the endpoint URL to the user.',
		);

		$actions = array();

		if ( 'none' !== $api_auth ) {
			$actions[] = self::placeholder_notice( $server->name );
		}

		if ( ! empty( $variables ) ) {
			$result['connection_variables'] = self::as_object( $variables );
			$pending                        = self::pending_variables( $variables );
			if ( ! empty( $pending ) ) {
				$actions[] = self::variables_notice( $server->name, $pending );
			}
		}

		if ( ! empty( $actions ) ) {
			$result['action_required'] = implode( ' ', $actions );
		}

		return $result;
	}

	/**
	 * Update a server.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When the server is missing or the update fails.
	 */
	private static function update_server( array $args ): array {
		$server = self::find_server( (int) $args['server_id'] );
		$data   = array();

		if ( isset( $args['name'] ) ) {
			$data['name'] = sanitize_text_field( (string) $args['name'] );
		}
		if ( isset( $args['status'] ) ) {
			$data['status'] = self::one_of( $args['status'], array( 'active', 'inactive' ), $server->status );
		}
		if ( isset( $args['auth_type'] ) ) {
			$data['auth_type'] = self::one_of( $args['auth_type'], array( 'none', 'api-key', 'oauth' ), $server->auth_type );
		}
		if ( isset( $args['rate_limit_per_min'] ) ) {
			$data['rate_limit_per_min'] = max( 0, (int) $args['rate_limit_per_min'] );
		}
		if ( isset( $args['api_auth'] ) ) {
			$api_auth = self::one_of( $args['api_auth'], array( 'none', 'bearer', 'api-key', 'basic' ), 'none' );
			$data     = array_merge( $data, self::placeholder_outbound_auth( $api_auth, $args ) );
		}
		$variables = self::connection_variables( $args );

		if ( isset( $args['instructions'] ) || ! empty( $variables ) ) {
			// Merge rather than replace: settings also carries state this tool
			// does not model, and writing a fresh object would drop it.
			$settings = self::decode( $server->settings );
			$settings = is_array( $settings ) ? $settings : array();

			if ( isset( $args['instructions'] ) ) {
				$settings['instructions'] = (string) $args['instructions'];
			}

			if ( ! empty( $variables ) ) {
				// Merged by name so naming one variable does not silently drop
				// the rest, and so a value the operator has already filled in is
				// not pushed back to a placeholder by a later edit.
				$existing              = isset( $settings['variables'] ) && is_array( $settings['variables'] ) ? $settings['variables'] : array();
				$settings['variables'] = self::merge_variables( $existing, $variables );
			}

			$data['settings'] = wp_json_encode( $settings );
		}

		if ( empty( $data ) ) {
			throw new JsonRpcException( -32602, __( 'Nothing to update — pass at least one field to change.', 'getmcp' ) );
		}

		$updated = ( new ServerManager() )->update( $server->id, $data );

		if ( ! $updated ) {
			throw new JsonRpcException( -32603, __( 'The server could not be updated.', 'getmcp' ) );
		}

		$result = array(
			'updated'   => true,
			'server_id' => (int) $updated->id,
			'name'      => $updated->name,
			'slug'      => $updated->slug,
			'status'    => $updated->status,
			'auth_type' => $updated->auth_type,
			'changed'   => array_keys( $data ),
		);

		$actions = array();

		if ( isset( $args['api_auth'] ) && 'none' !== $args['api_auth'] ) {
			$actions[] = self::placeholder_notice( $updated->name );
		}

		if ( ! empty( $variables ) ) {
			$stored                         = $updated->get_variables();
			$result['connection_variables'] = self::as_object( $stored );
			$pending                        = array_values( array_diff( array_keys( $variables ), array_keys( $stored ) ) );
			if ( ! empty( $pending ) ) {
				$actions[] = self::variables_notice( $updated->name, $pending );
			}
		}

		if ( ! empty( $actions ) ) {
			$result['action_required'] = implode( ' ', $actions );
		}

		return $result;
	}

	/**
	 * Create a tool.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When the server is missing or creation fails.
	 */
	private static function create_tool( array $args ): array {
		$server = self::find_server( (int) $args['server_id'] );

		$data = array(
			'server_id'    => $server->id,
			'name'         => sanitize_text_field( (string) $args['name'] ),
			'description'  => isset( $args['description'] ) ? sanitize_textarea_field( (string) $args['description'] ) : '',
			// Deliberately NOT esc_url_raw() here. That eats `{`, so a templated
			// endpoint such as
			// https://api.airtable.com/v0/{{baseId}}/{{tableIdOrName}}
			// was stored as .../v0/baseId/tableIdOrName — a URL that looks
			// plausible, saves without complaint and calls the wrong address.
			// ToolManager::sanitize_endpoint_url() masks the placeholders,
			// sanitises the skeleton and puts them back, so the raw value is
			// what it needs to receive.
			'endpoint_url' => trim( (string) $args['endpoint_url'] ),
			'http_method'  => self::one_of( strtoupper( (string) ( $args['http_method'] ?? 'GET' ) ), array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), 'GET' ),
			'status'       => self::one_of( $args['status'] ?? 'active', array( 'active', 'inactive', 'draft' ), 'active' ),
		);

		if ( ! self::url_is_usable( $data['endpoint_url'] ) ) {
			throw new JsonRpcException( -32602, __( 'endpoint_url must be a valid absolute http(s) URL. {{placeholders}} are allowed anywhere in it.', 'getmcp' ) );
		}
		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			$data['input_schema'] = wp_json_encode( $args['input_schema'] );
		}
		if ( isset( $args['parameter_mapping'] ) && is_array( $args['parameter_mapping'] ) ) {
			$data['parameter_mapping'] = wp_json_encode( self::sanitize_parameter_mapping( $args['parameter_mapping'] ) );
		}
		$rejected_headers = array();

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$split            = self::strip_auth_headers( $args['headers'] );
			$rejected_headers = $split['rejected'];
			$data['headers']  = wp_json_encode( $split['headers'] );
		}

		$tool = ( new ToolManager() )->create( $data );

		if ( ! $tool ) {
			throw new JsonRpcException( -32603, __( 'The tool could not be created.', 'getmcp' ) );
		}

		$result = array(
			'created'     => true,
			'tool_id'     => (int) $tool->id,
			'server_id'   => (int) $tool->server_id,
			'name'        => $tool->name,
			'slug'        => $tool->slug,
			'http_method' => $tool->http_method,
			'annotations' => $tool->get_effective_annotations(),
		);

		if ( $rejected_headers ) {
			$result['warnings'] = array( self::auth_header_warning( $rejected_headers, $server->name ) );
		}

		return $result;
	}

	/**
	 * Update a tool.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When the tool is missing or the update fails.
	 */
	private static function update_tool( array $args ): array {
		$tool = self::find_tool( (int) $args['tool_id'] );
		$data = array();

		if ( isset( $args['name'] ) ) {
			$data['name'] = sanitize_text_field( (string) $args['name'] );
		}
		if ( isset( $args['description'] ) ) {
			$data['description'] = sanitize_textarea_field( (string) $args['description'] );
		}
		if ( isset( $args['endpoint_url'] ) ) {
			$url = trim( (string) $args['endpoint_url'] );
			if ( ! self::url_is_usable( $url ) ) {
				throw new JsonRpcException( -32602, __( 'endpoint_url must be a valid absolute http(s) URL. {{placeholders}} are allowed anywhere in it.', 'getmcp' ) );
			}
			$data['endpoint_url'] = $url;
		}
		if ( isset( $args['http_method'] ) ) {
			$data['http_method'] = self::one_of( strtoupper( (string) $args['http_method'] ), array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), $tool->http_method );
		}
		if ( isset( $args['status'] ) ) {
			$data['status'] = self::one_of( $args['status'], array( 'active', 'inactive', 'draft' ), $tool->status );
		}
		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			$data['input_schema'] = wp_json_encode( $args['input_schema'] );
		}
		if ( isset( $args['parameter_mapping'] ) && is_array( $args['parameter_mapping'] ) ) {
			$data['parameter_mapping'] = wp_json_encode( self::sanitize_parameter_mapping( $args['parameter_mapping'] ) );
		}
		$rejected_headers = array();

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$split            = self::strip_auth_headers( $args['headers'] );
			$rejected_headers = $split['rejected'];
			$data['headers']  = wp_json_encode( $split['headers'] );
		}

		if ( empty( $data ) ) {
			throw new JsonRpcException( -32602, __( 'Nothing to update — pass at least one field to change.', 'getmcp' ) );
		}

		$updated = ( new ToolManager() )->update( $tool->id, $data );

		if ( ! $updated ) {
			throw new JsonRpcException( -32603, __( 'The tool could not be updated.', 'getmcp' ) );
		}

		$result = array(
			'updated'     => true,
			'tool_id'     => (int) $updated->id,
			'name'        => $updated->name,
			'slug'        => $updated->slug,
			'http_method' => $updated->http_method,
			'status'      => $updated->status,
			'annotations' => $updated->get_effective_annotations(),
			'changed'     => array_keys( $data ),
		);

		if ( $rejected_headers ) {
			$result['warnings'] = array( self::auth_header_warning( $rejected_headers, '' ) );
		}

		return $result;
	}

	/**
	 * Delete a tool.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws JsonRpcException When unconfirmed, missing, or the delete fails.
	 */
	private static function delete_tool( array $args ): array {
		if ( true !== ( $args['confirm'] ?? false ) ) {
			throw new JsonRpcException(
				-32602,
				__( 'Deleting a tool is irreversible. Confirm with the user, then call again with confirm: true.', 'getmcp' )
			);
		}

		$tool = self::find_tool( (int) $args['tool_id'] );

		// Captured before the row goes away — the result has to be able to say
		// what was deleted, and afterwards there is nothing left to read.
		$deleted = array(
			'tool_id'   => (int) $tool->id,
			'name'      => $tool->name,
			'slug'      => $tool->slug,
			'server_id' => (int) $tool->server_id,
		);

		if ( ! ( new ToolManager() )->delete( $tool->id ) ) {
			throw new JsonRpcException( -32603, __( 'The tool could not be deleted.', 'getmcp' ) );
		}

		return array_merge( $deleted, array( 'deleted' => true ) );
	}

	/* ----------------------------------------------------------------- Auth */

	/**
	 * Marker every generated credential carries.
	 *
	 * Deliberately shouty and greppable: it has to be obvious in the admin UI
	 * that the value is not a real key, and {@see self::is_placeholder()} uses
	 * it to tell the operator the server is still unconfigured.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private const PLACEHOLDER = Server::PLACEHOLDER;

	/**
	 * Build outbound auth fields carrying a placeholder credential.
	 *
	 * The tools never accept a real secret. An assistant that could set one
	 * would need it typed into a chat first, which puts the customer's API key
	 * in a conversation transcript and, usually, in a model provider's logs.
	 * Writing a placeholder gets the shape right — every tool on the server
	 * inherits it — and leaves the secret itself to a human at the keyboard.
	 *
	 * @since  1.4.0
	 * @param  string               $type Outbound auth type.
	 * @param  array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed> Fields to merge into the create/update payload.
	 */
	private static function placeholder_outbound_auth( string $type, array $args ): array {
		if ( 'none' === $type ) {
			return array(
				'outbound_auth_type'        => 'none',
				'outbound_auth_config'      => null,
				'outbound_auth_credentials' => null,
			);
		}

		$name     = isset( $args['api_key_name'] ) && '' !== trim( (string) $args['api_key_name'] )
			? sanitize_text_field( (string) $args['api_key_name'] )
			: 'X-API-Key';
		$location = self::one_of( $args['api_key_location'] ?? 'header', array( 'header', 'query' ), 'header' );

		$credentials = match ( $type ) {
			'bearer'  => array( 'token' => self::PLACEHOLDER . 'TOKEN' ),
			'basic'   => array(
				'username' => self::PLACEHOLDER . 'USERNAME',
				'password' => self::PLACEHOLDER . 'PASSWORD',
			),
			'api-key' => array(
				'value'    => self::PLACEHOLDER . 'API_KEY',
				'name'     => $name,
				'location' => $location,
			),
			default   => array(),
		};

		$fields = array(
			'outbound_auth_type'        => $type,
			'outbound_auth_credentials' => wp_json_encode( $credentials ),
			'outbound_auth_config'      => null,
		);

		if ( 'api-key' === $type ) {
			$fields['outbound_auth_config'] = wp_json_encode(
				array(
					'name'     => $name,
					'location' => $location,
				)
			);
		}

		return $fields;
	}

	/**
	 * The message handed back when credential headers are stripped from a tool.
	 *
	 * @since  1.4.0
	 * @param  array<int, string> $names       Header names that were removed.
	 * @param  string             $server_name Server the tool belongs to, when known.
	 * @return string
	 */
	private static function auth_header_warning( array $names, string $server_name ): string {
		return sprintf(
			/* translators: 1: comma-separated header names, 2: sentence naming the server, or empty. */
			__( 'Removed the header(s) %1$s: credentials do not belong on a tool. Tool headers are stored unencrypted and would have to be repeated on every tool. Set authentication on the server instead%2$s — call update_server with api_auth, and every tool inherits it.', 'getmcp' ),
			implode( ', ', $names ),
			'' !== $server_name ? sprintf( ' (%s)', $server_name ) : ''
		);
	}

	/**
	 * Encode a name => value map so an empty one is still an object.
	 *
	 * PHP cannot tell an empty map from an empty list, so an empty array
	 * re-encodes as `[]` where the caller was promised an object.
	 *
	 * @since  1.4.0
	 * @param  array<string, string> $map Map to encode.
	 * @return array<string, string>|\stdClass
	 */
	private static function as_object( array $map ): array|\stdClass {
		return empty( $map ) ? new \stdClass() : $map;
	}

	/**
	 * Every connection variable recorded on a server, placeholders included.
	 *
	 * {@see Server::get_variables()} deliberately hides an unset one so it
	 * cannot reach a request; reporting which names are still waiting needs the
	 * raw map.
	 *
	 * @since  1.4.0
	 * @param  Server $server Server to read.
	 * @return array<string, string>
	 */
	private static function stored_variables( Server $server ): array {
		$settings = self::decode( $server->settings );

		if ( ! is_array( $settings ) || empty( $settings['variables'] ) || ! is_array( $settings['variables'] ) ) {
			return array();
		}

		$variables = array();

		foreach ( $settings['variables'] as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$variables[ $name ] = (string) $value;
			}
		}

		return $variables;
	}

	/**
	 * Validate a parameter_mapping argument into the stored shape.
	 *
	 * This field exists because JSON Schema alone cannot say where an argument
	 * travels, and the defaults are only a convention: Retable, for one,
	 * deletes rows with a JSON body on a DELETE request, which the default
	 * routing would put in the query string where the API never looks.
	 *
	 * The same auth rule as static headers applies, but harder: a mapping with
	 * target "header" turns a caller-supplied value into a request header on
	 * every call, so an auth-carrying header name here would let whoever holds
	 * the MCP session override the operator's stored credential. Static
	 * headers are stripped with a warning; this is refused outright, because
	 * unlike a pasted cURL command it cannot be there by accident.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $mapping Raw argument.
	 * @return array<string, array{target: string, key: string}>|\stdClass
	 * @throws JsonRpcException When an entry is malformed or targets an auth header.
	 */
	private static function sanitize_parameter_mapping( array $mapping ): array|\stdClass {
		$clean = array();

		foreach ( $mapping as $param => $entry ) {
			if ( ! is_string( $param ) || '' === trim( $param ) || ! is_array( $entry ) ) {
				continue;
			}

			$param  = trim( $param );
			$target = self::one_of( $entry['target'] ?? '', array( 'path', 'query', 'body', 'header' ), '' );

			if ( '' === $target ) {
				throw new JsonRpcException(
					-32602,
					/* translators: %s: parameter name. */
					sprintf( __( 'parameter_mapping.%s.target must be one of path, query, body or header.', 'getmcp' ), $param )
				);
			}

			$key = isset( $entry['key'] ) && is_string( $entry['key'] ) && '' !== trim( $entry['key'] ) ? trim( $entry['key'] ) : $param;

			if ( 'header' === $target && self::is_auth_header( $key ) ) {
				throw new JsonRpcException(
					-32602,
					/* translators: 1: parameter name, 2: header name. */
					sprintf( __( 'parameter_mapping.%1$s targets the authentication header "%2$s". Credentials belong on the server — configure them with update_server instead.', 'getmcp' ), $param, $key )
				);
			}

			$clean[ $param ] = array(
				'target' => $target,
				'key'    => $key,
			);
		}

		return empty( $clean ) ? new \stdClass() : $clean;
	}

	/**
	 * Read the connection_variables argument into a storable map.
	 *
	 * A name the caller supplied no value for is stored carrying the
	 * placeholder rather than dropped: the row has to appear in the server's
	 * settings screen for the operator to fill in, and an empty value is
	 * discarded on the way to the database.
	 *
	 * Names are held to the same charset the settings screen enforces, so a
	 * variable an assistant invents cannot be one the resolver would refuse to
	 * substitute later.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $args Tool arguments.
	 * @return array<string, string> name => value, empty when none were passed.
	 */
	private static function connection_variables( array $args ): array {
		if ( empty( $args['connection_variables'] ) || ! is_array( $args['connection_variables'] ) ) {
			return array();
		}

		$variables = array();

		foreach ( $args['connection_variables'] as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$name = trim( $name );

			if ( '' === $name || strlen( $name ) > 40 || preg_match( '/[^A-Za-z0-9_]/', $name ) ) {
				continue;
			}

			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			// A value the assistant did invent but that could never be stored —
			// a full host, a path, anything carrying URL structure — is treated
			// as absent rather than silently corrected, so the operator is told
			// to supply it instead of the server calling the wrong address.
			if ( '' === $value || strlen( $value ) > 100 || preg_match( '/[^A-Za-z0-9._-]/', $value ) ) {
				$value = self::PLACEHOLDER . strtoupper( $name );
			}

			$variables[ $name ] = $value;
		}

		return $variables;
	}

	/**
	 * Merge newly declared variables over the stored ones.
	 *
	 * A placeholder never overwrites a value the operator has already filled
	 * in — re-running create/update after the fact would otherwise undo their
	 * configuration and break a working server.
	 *
	 * @since  1.4.0
	 * @param  array<string, string> $existing Stored variables.
	 * @param  array<string, string> $incoming Variables from this call.
	 * @return array<string, string>
	 */
	private static function merge_variables( array $existing, array $incoming ): array {
		foreach ( $incoming as $name => $value ) {
			$already_set = isset( $existing[ $name ] )
				&& is_string( $existing[ $name ] )
				&& '' !== $existing[ $name ]
				&& ! str_contains( $existing[ $name ], self::PLACEHOLDER );

			if ( $already_set && str_contains( $value, self::PLACEHOLDER ) ) {
				continue;
			}

			$existing[ $name ] = $value;
		}

		return $existing;
	}

	/**
	 * Names still carrying a placeholder.
	 *
	 * @since  1.4.0
	 * @param  array<string, string> $variables name => value.
	 * @return array<int, string>
	 */
	private static function pending_variables( array $variables ): array {
		$pending = array();

		foreach ( $variables as $name => $value ) {
			if ( str_contains( $value, self::PLACEHOLDER ) ) {
				$pending[] = $name;
			}
		}

		return $pending;
	}

	/**
	 * The message handed back whenever a connection variable is left unset.
	 *
	 * @since  1.4.0
	 * @param  string            $server_name Server the variables belong to.
	 * @param  array<int, string> $pending    Variable names still unset.
	 * @return string
	 */
	private static function variables_notice( string $server_name, array $pending ): string {
		$names = implode( '", "', $pending );

		if ( 1 === count( $pending ) ) {
			return sprintf(
				/* translators: 1: variable name, 2: server name. */
				__( 'The connection variable "%1$s" has no value yet, so any tool whose URL uses it will fail until it does. Tell the user to open Servers → %2$s → Connection variables and set it — a Mailchimp data centre reads us21, a Freshdesk subdomain is the first part of their helpdesk address.', 'getmcp' ),
				$names,
				$server_name
			);
		}

		return sprintf(
			/* translators: 1: quoted, comma-separated variable names, 2: server name. */
			__( 'The connection variables "%1$s" have no value yet, so any tool whose URL uses them will fail until they do. Tell the user to open Servers → %2$s → Connection variables and set them — a Mailchimp data centre reads us21, a Freshdesk subdomain is the first part of their helpdesk address.', 'getmcp' ),
			$names,
			$server_name
		);
	}

	/**
	 * The message handed back whenever a placeholder credential is written.
	 *
	 * @since  1.4.0
	 * @param  string $server_name Server the credential belongs to.
	 * @return string
	 */
	private static function placeholder_notice( string $server_name ): string {
		return sprintf(
			/* translators: %s: server name. */
			__( 'Authentication is configured with a placeholder, so calls will fail until it is replaced. Tell the user to open Servers → %s → Authentication and enter the real credential. Do not ask them for it here — it would end up in this conversation.', 'getmcp' ),
			$server_name
		);
	}

	/**
	 * Decrypt a stored credential, returning null rather than throwing.
	 *
	 * Used only to answer "is this still a placeholder". A credential that
	 * cannot be decrypted is not a placeholder, so a failure here must read as
	 * "no" rather than take the whole call down.
	 *
	 * @since  1.4.0
	 * @param  string|null $raw Stored credential column.
	 * @return string|null Decrypted JSON, or null.
	 */
	private static function decrypt_quietly( ?string $raw ): ?string {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		try {
			return Encryption::decrypt( $raw );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether a stored credential blob is still one of ours.
	 *
	 * @since  1.4.0
	 * @param  string|null $raw Decrypted credential JSON.
	 * @return bool
	 */
	private static function is_placeholder( ?string $raw ): bool {
		return null !== $raw && '' !== $raw && str_contains( $raw, self::PLACEHOLDER );
	}

	/**
	 * Header names that carry a credential and must not sit on a tool.
	 *
	 * A per-tool header is stored unencrypted and has to be repeated on every
	 * tool, so a key placed there leaks and drifts. Server-level outbound auth
	 * is encrypted at rest and applies to every tool at once.
	 *
	 * @since  1.4.0
	 * @param  string $name Header name.
	 * @return bool
	 */
	private static function is_auth_header( string $name ): bool {
		$normalized = strtolower( trim( $name ) );

		if ( in_array( $normalized, array( 'authorization', 'proxy-authorization', 'cookie' ), true ) ) {
			return true;
		}

		return 1 === preg_match( '/(api[-_ ]?key|auth[-_ ]?token|access[-_ ]?token|secret)/', $normalized );
	}

	/**
	 * Split submitted headers into ones a tool may keep and ones it may not.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $headers Submitted headers.
	 * @return array{headers: array<string, mixed>, rejected: array<int, string>}
	 */
	private static function strip_auth_headers( array $headers ): array {
		$kept     = array();
		$rejected = array();

		foreach ( $headers as $name => $value ) {
			if ( self::is_auth_header( (string) $name ) ) {
				$rejected[] = (string) $name;
				continue;
			}

			$kept[ (string) $name ] = $value;
		}

		return array(
			'headers'  => $kept,
			'rejected' => $rejected,
		);
	}

	/* -------------------------------------------------------------- Helpers */

	/**
	 * Look up a server or fail with a message naming what to do next.
	 *
	 * @since  1.4.0
	 * @param  int $server_id Server id.
	 * @return \GetMCP\Core\Server
	 * @throws JsonRpcException When no such server exists.
	 */
	private static function find_server( int $server_id ) {
		$server = ( new ServerManager() )->get( $server_id );

		if ( ! $server ) {
			throw new JsonRpcException(
				-32602,
				/* translators: %d: server id. */
				sprintf( __( 'No server with id %d. Call list_servers to see the ids that exist.', 'getmcp' ), $server_id )
			);
		}

		return $server;
	}

	/**
	 * Look up a tool or fail with a message naming what to do next.
	 *
	 * @since  1.4.0
	 * @param  int $tool_id Tool id.
	 * @return Tool
	 * @throws JsonRpcException When no such tool exists.
	 */
	private static function find_tool( int $tool_id ): Tool {
		$tool = ( new ToolManager() )->get( $tool_id );

		if ( ! $tool ) {
			throw new JsonRpcException(
				-32602,
				/* translators: %d: tool id. */
				sprintf( __( 'No tool with id %d. Call list_tools for a server to see the ids that exist.', 'getmcp' ), $tool_id )
			);
		}

		return $tool;
	}

	/**
	 * Assert that the schema's required arguments are present.
	 *
	 * @since  1.4.0
	 * @param  string               $name      Tool name.
	 * @param  array<string, mixed> $arguments Arguments.
	 * @return void
	 * @throws JsonRpcException When a required argument is missing.
	 */
	private static function assert_required( string $name, array $arguments ): void {
		$required = self::catalogue()[ $name ]['schema']['required'] ?? array();

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $arguments ) ) {
				throw new JsonRpcException(
					-32602,
					/* translators: 1: argument name, 2: tool name. */
					sprintf( __( 'Missing required argument "%1$s" for %2$s.', 'getmcp' ), $key, $name )
				);
			}
		}
	}

	/**
	 * Require a capability of the account the access token belongs to.
	 *
	 * The token proves who authorized the client, not what they may still do.
	 * Roles change, and a token outlives the role it was minted under.
	 *
	 * @since  1.4.0
	 * @param  string $capability Capability to require.
	 * @return void
	 * @throws JsonRpcException When the caller is unidentified or unauthorised.
	 */
	private static function require_cap( string $capability ): void {
		$row     = McpAuth::current_token_row();
		$user_id = $row && isset( $row->user_id ) ? (int) $row->user_id : 0;

		if ( $user_id <= 0 ) {
			throw new JsonRpcException(
				-32001,
				__( 'This access token is not tied to a user account. Reconnect the client and authorize from the GetMCP dashboard.', 'getmcp' )
			);
		}

		if ( ! user_can( $user_id, $capability ) ) {
			throw new JsonRpcException(
				-32001,
				__( 'The account this client was authorized with cannot manage MCP servers.', 'getmcp' )
			);
		}
	}

	/**
	 * Wrap a payload as a successful MCP tool result.
	 *
	 * The JSON is also returned as `structuredContent` so a client that
	 * understands it does not have to re-parse the text block.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed>|string $payload Result payload.
	 * @return array<string, mixed>
	 */
	private static function ok( $payload ): array {
		$text = is_string( $payload ) ? $payload : wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => (string) $text,
				),
			),
			'isError' => false,
		);

		if ( is_array( $payload ) ) {
			$result['structuredContent'] = $payload;
		}

		return $result;
	}

	/**
	 * Wrap a message as a failed MCP tool result.
	 *
	 * @since  1.4.0
	 * @param  string $message Error message.
	 * @return array<string, mixed>
	 */
	private static function error( string $message ): array {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * Whether an endpoint URL is usable once its placeholders are set aside.
	 *
	 * Validation has to look past `{{tokens}}`: they are legal anywhere in the
	 * URL — path, query, even the host, which is how a per-account domain like
	 * {{subdomain}}.freshdesk.com or a data-centre host like
	 * {{dc}}.api.mailchimp.com is expressed. Judging the raw string would
	 * reject those, and sanitising it first would silently eat the braces.
	 *
	 * @since  1.4.0
	 * @param  string $url Candidate URL, placeholders intact.
	 * @return bool
	 */
	private static function url_is_usable( string $url ): bool {
		if ( '' === trim( $url ) ) {
			return false;
		}

		// Stand every placeholder down to a plain token so the rest can be
		// judged as an ordinary URL.
		$skeleton = preg_replace( '/\{\{[^{}]+\}\}|\{[^{}]+\}/', 'x', $url );

		if ( ! is_string( $skeleton ) ) {
			return false;
		}

		$scheme = wp_parse_url( $skeleton, PHP_URL_SCHEME );
		$host   = wp_parse_url( $skeleton, PHP_URL_HOST );

		return in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) && '' !== (string) $host;
	}

	/**
	 * Constrain a value to an allowed set.
	 *
	 * @since  1.4.0
	 * @param  mixed         $value    Candidate value.
	 * @param  array<string> $allowed  Allowed values.
	 * @param  string        $fallback Value to use when the candidate is not allowed.
	 * @return string
	 */
	private static function one_of( $value, array $allowed, string $fallback ): string {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Decode a stored JSON column.
	 *
	 * @since  1.4.0
	 * @param  string|null $raw Stored value.
	 * @return mixed Decoded value, or null.
	 */
	private static function decode( ?string $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return null === $decoded ? $raw : $decoded;
	}

}
