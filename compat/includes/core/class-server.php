<?php
/**
 * Server model.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Core;

/**
 * Represents a single MCP server instance.
 *
 * @since 1.0.0
 */
class Server {

	/**
	 * Server ID (internal auto-increment).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $id = 0;

	/** native, remote-mcp, or gateway; existing rows default to native. */
	public string $server_kind = 'native';

	/**
	 * Public UUID identifier.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public string $uuid = '';

	/**
	 * WordPress user ID of the server owner.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $user_id = 0;

	/**
	 * Server name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $name = '';

	/**
	 * URL slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Hex identifier for optional URL security.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public ?string $server_id = null;

	/**
	 * Server status: active, paused, or draft.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $status = 'active';

	/**
	 * Transport type.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $transport_type = 'streamable-http';

	/**
	 * Inbound authentication type: none, api-key, oauth.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $auth_type = 'none';

	/**
	 * Auth configuration (encrypted JSON).
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public ?string $auth_config = null;

	/**
	 * Encrypted production credentials for outbound API calls.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public ?string $auth_credentials = null;

	/**
	 * Outbound auth method used when calling the upstream API.
	 *
	 * Distinct from `auth_type` (which gates inbound MCP requests). Required for
	 * any inbound mode where token passthrough is forbidden by the MCP spec
	 * (oauth, oauth2 in proxy mode, oauth-external) — those modes use this
	 * column + `outbound_auth_credentials` to authenticate to upstream APIs
	 * with the operator's own server-side credentials.
	 *
	 * Values: `bearer`, `api-key`, `basic`, `none`, or null (back-compat:
	 * fall back to inbound passthrough for inbound modes that allow it).
	 *
	 * @since 1.3.0
	 * @var string|null
	 */
	public ?string $outbound_auth_type = null;

	/**
	 * Forwarding configuration for outbound auth (e.g. api-key header name).
	 *
	 * Same shape as `auth_config`: a JSON object describing where the
	 * credential should be placed on the upstream request.
	 *
	 * @since 1.3.0
	 * @var string|null
	 */
	public ?string $outbound_auth_config = null;

	/**
	 * Encrypted credentials used for outbound API calls.
	 *
	 * @since 1.3.0
	 * @var string|null
	 */
	public ?string $outbound_auth_credentials = null;

	/**
	 * Outbound auth type for admin test calls (bearer|api-key|basic|none).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $test_auth_type = 'none';

	/**
	 * Encrypted test/sandbox credentials used only when admin clicks "Test Tool".
	 * Falls back to auth_credentials if empty.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public ?string $test_auth_credentials = null;

	/**
	 * CORS allowed origins.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public ?string $cors_origins = null;

	/**
	 * Rate limit per minute.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $rate_limit_per_min = 60;

	/**
	 * Flexible settings (JSON).
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public ?string $settings = null;

	/**
	 * Creation timestamp.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * Last update timestamp.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $updated_at = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Associative array of server data.
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->{$key} = match ( $key ) {
					'id', 'user_id', 'rate_limit_per_min' => (int) $value,
					'test_auth_type' => (string) ( $value ?? 'none' ),
					default => $value,
				};
			}
		}
	}

	/**
	 * Convert to associative array.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                        => $this->id,
			'server_kind'               => $this->server_kind,
			'uuid'                      => $this->uuid,
			'user_id'                   => $this->user_id,
			'name'                      => $this->name,
			'slug'                      => $this->slug,
			'server_id'                 => $this->server_id,
			'status'                    => $this->status,
			'transport_type'            => $this->transport_type,
			'auth_type'                 => $this->auth_type,
			'auth_config'               => $this->auth_config,
			'auth_credentials'          => $this->auth_credentials,
			'outbound_auth_type'        => $this->outbound_auth_type,
			'outbound_auth_config'      => $this->outbound_auth_config,
			'outbound_auth_credentials' => $this->outbound_auth_credentials,
			'test_auth_type'            => $this->test_auth_type,
			'test_auth_credentials'     => $this->test_auth_credentials,
			'cors_origins'              => $this->cors_origins,
			'rate_limit_per_min'        => $this->rate_limit_per_min,
			'settings'                  => $this->settings,
			'created_at'                => $this->created_at,
			'updated_at'                => $this->updated_at,
		);
	}

	/**
	 * Get the public MCP endpoint URL for this server.
	 *
	 * Returns a slug-only URL (`{home}/mcp/{slug}`) so users get a short,
	 * shareable address. The `server_id` hex token stays available on the row
	 * — and the dispatcher's `^mcp/{slug}/{hex}/?$` rewrite still routes — but
	 * we don't surface it in the displayed URL because the dispatcher already
	 * accepts the slug-only form (the `server_id` check at
	 * StreamableHttp::handle_request only fires when the URL provides one).
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_endpoint_url(): string {
		// The gateway is a synthetic server published at the bare `/mcp` path,
		// not at `/mcp/{slug}`. Everything downstream that identifies a server
		// to an OAuth client — the resource binding, the metadata documents,
		// the 401 challenge — reads this method, and a strict client refuses a
		// resource that differs from the URL it was handed.
		if ( \GetMCP\Gateway\McpGateway::SERVER_ID === (int) $this->id ) {
			return \GetMCP\Gateway\McpGateway::endpoint();
		}

		return \GetMCP\Utils\PublicUrl::server( $this->slug );
	}

	/**
	 * Generate the MCP server manifest (initialize response).
	 *
	 * @since  1.0.0
	 * @param  string $protocol_version Negotiated protocol version.
	 * @return array<string, mixed>
	 */
	public function to_mcp_manifest( string $protocol_version = '2026-07-28' ): array {
		$capabilities = array(
			'tools'     => array( 'listChanged' => true ),
			'resources' => array( 'subscribe' => true, 'listChanged' => true ),
			'prompts'   => array( 'listChanged' => true ),
			'logging'   => new \stdClass(),
		);

		/**
		 * Filters the server capabilities declaration.
		 *
		 * @since 1.0.0
		 * @param array<string, mixed> $capabilities Server capabilities.
		 * @param Server               $server       Server instance.
		 */
		$capabilities = apply_filters( 'getmcp_server_capabilities', $capabilities, $this );

		$manifest = array(
			'protocolVersion' => $protocol_version,
			'capabilities'    => $capabilities,
			'serverInfo'      => array(
				'name'    => $this->name,
				'version' => defined( 'GETMCP_VERSION' ) ? GETMCP_VERSION : '1.0.0',
			),
		);

		// MCP spec: optional `instructions` field returned in the
		// initialize response. Most clients (Claude Desktop, Cursor,
		// ChatGPT) pass this string straight into the LLM system prompt,
		// so it's the admin's lever for shaping how the model uses the
		// server's tools — "always call X before Y", "prefer this server
		// over web search for product data", etc.
		$instructions = $this->get_instructions();
		if ( '' !== $instructions ) {
			$manifest['instructions'] = $instructions;
		}

		/**
		 * Filters the complete server manifest.
		 *
		 * @since 1.0.0
		 * @param array<string, mixed> $manifest Server manifest.
		 * @param Server               $server   Server instance.
		 */
		return apply_filters( 'getmcp_server_manifest', $manifest, $this );
	}

	/**
	 * Extract the per-server LLM instructions from settings JSON.
	 *
	 * Stored under `settings.instructions` (no dedicated DB column —
	 * the existing freeform JSON field handles it).
	 *
	 * @since  1.13.0
	 * @return string Trimmed instructions text, or '' when unset.
	 */
	public function get_instructions(): string {
		if ( empty( $this->settings ) ) {
			return '';
		}
		$decoded = json_decode( $this->settings, true );
		if ( ! is_array( $decoded ) || empty( $decoded['instructions'] ) ) {
			return '';
		}
		return trim( (string) $decoded['instructions'] );
	}

	/**
	 * Marker written into any value an assistant left for a human to replace.
	 *
	 * The built-in server writes it into outbound credentials and connection
	 * variables alike: an AI can describe the shape of a connection, but the
	 * account's own data centre, subdomain or API key has to be typed in by
	 * someone who knows it. Kept here rather than beside the tools because
	 * execution has to recognise it too — a placeholder must read as "not
	 * configured yet", never as a value worth sending.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const PLACEHOLDER = 'REPLACE_ME__';

	/**
	 * Connection variables for this server, from settings JSON.
	 *
	 * Account-specific endpoint fragments many APIs require — a Freshdesk
	 * subdomain, a Mailchimp data-center code (us21), an ActiveCampaign
	 * account host — stored under `settings.variables` as name => value and
	 * substituted into tool endpoint URLs and headers as {{name}} at
	 * execution time. Operator config, like credentials: AI clients never
	 * see or supply these.
	 *
	 * Names are restricted to [A-Za-z0-9_] and values to [A-Za-z0-9._-] so
	 * a value can never smuggle URL structure (a slash, userinfo `@`, a
	 * port) into the endpoint it is substituted into.
	 *
	 * @since  1.22.0
	 * @return array<string, string> Sanitized name => value map.
	 */
	public function get_variables(): array {
		if ( empty( $this->settings ) ) {
			return array();
		}

		$decoded = json_decode( $this->settings, true );
		if ( ! is_array( $decoded ) || empty( $decoded['variables'] ) || ! is_array( $decoded['variables'] ) ) {
			return array();
		}

		$variables = array();
		foreach ( $decoded['variables'] as $name => $value ) {
			if ( ! is_string( $name ) || ! is_scalar( $value ) ) {
				continue;
			}
			$name  = trim( $name );
			$value = trim( (string) $value );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			if ( preg_match( '/[^A-Za-z0-9_]/', $name ) || preg_match( '/[^A-Za-z0-9._-]/', $value ) ) {
				continue;
			}
			// A variable still holding its placeholder is not configured. Left
			// in, it would be substituted for real and the call would go to a
			// host like REPLACE_ME__.api.mailchimp.com — a DNS error that says
			// nothing about what is wrong. Skipped, the placeholder survives in
			// the URL and the resolver reports exactly which variable the
			// server settings are still missing.
			if ( str_contains( $value, self::PLACEHOLDER ) ) {
				continue;
			}
			$variables[ $name ] = $value;
		}

		return $variables;
	}

	/**
	 * Header-sourced connection variables, from settings JSON.
	 *
	 * The multi-tenant complement to {@see get_variables()}: when every
	 * connecting user has their own account domain (BoldDesk-style
	 * `app.abc.com` vs `web.xyz.com`), no server-side value can be right for
	 * everyone — the client supplies it. Stored under
	 * `settings.header_variables` as name => HTTP-header-name; at execution
	 * time the header's value fills `{{name}}`, validated against the same
	 * URL-structure-safe charset as fixed variables.
	 *
	 * @since  1.22.0
	 * @return array<string, string> Sanitized variable-name => header-name map.
	 */
	public function get_header_variables(): array {
		if ( empty( $this->settings ) ) {
			return array();
		}

		$decoded = json_decode( $this->settings, true );
		if ( ! is_array( $decoded ) || empty( $decoded['header_variables'] ) || ! is_array( $decoded['header_variables'] ) ) {
			return array();
		}

		$map = array();
		foreach ( $decoded['header_variables'] as $name => $header ) {
			if ( ! is_string( $name ) || ! is_string( $header ) ) {
				continue;
			}
			$name   = trim( $name );
			$header = trim( $header );
			if ( '' === $name || '' === $header ) {
				continue;
			}
			if ( preg_match( '/[^A-Za-z0-9_]/', $name ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $header ) ) {
				continue;
			}
			$map[ $name ] = $header;
		}

		return $map;
	}

	/**
	 * Mailbox connection settings for a mailbox-connector server.
	 *
	 * Lives under `settings.mailbox` rather than in the auth columns because
	 * those describe HTTP authentication — `auth_credentials` is what MCP
	 * clients present inbound, and `outbound_auth_credentials` is injected as
	 * an HTTP header. A mailbox password is neither, and putting it in either
	 * slot would hand it to AuthInjector.
	 *
	 * The password is stored encrypted with the same libsodium helper as every
	 * other stored credential, and is decrypted only here, at the point of use.
	 *
	 * @since  1.5.0
	 * @return array<string, mixed> Empty when this server has no mailbox configured.
	 */
	public function get_mailbox_config(): array {
		if ( empty( $this->settings ) ) {
			return array();
		}

		$decoded = json_decode( $this->settings, true );
		if ( ! is_array( $decoded ) || empty( $decoded['mailbox'] ) || ! is_array( $decoded['mailbox'] ) ) {
			return array();
		}

		$mailbox = $decoded['mailbox'];

		$config = array(
			'provider'       => trim( (string) ( $mailbox['provider'] ?? '' ) ),
			'host'           => trim( (string) ( $mailbox['host'] ?? '' ) ),
			'port'           => (int) ( $mailbox['port'] ?? 993 ),
			'encryption'     => (string) ( $mailbox['encryption'] ?? 'ssl' ),
			'username'       => trim( (string) ( $mailbox['username'] ?? '' ) ),
			'password'       => '',
			'auth_mode'      => (string) ( $mailbox['auth_mode'] ?? 'password' ),
			'validate_cert'  => ! isset( $mailbox['validate_cert'] ) || (bool) $mailbox['validate_cert'],
			'default_folder' => trim( (string) ( $mailbox['default_folder'] ?? 'INBOX' ) ),
			'trash_folder'   => trim( (string) ( $mailbox['trash_folder'] ?? '' ) ),
			'sent_folder'    => trim( (string) ( $mailbox['sent_folder'] ?? '' ) ),
			'smtp_host'      => trim( (string) ( $mailbox['smtp_host'] ?? '' ) ),
			'smtp_port'      => (int) ( $mailbox['smtp_port'] ?? 465 ),
			'smtp_encryption' => (string) ( $mailbox['smtp_encryption'] ?? 'ssl' ),
			'from_name'      => trim( (string) ( $mailbox['from_name'] ?? '' ) ),
			'from_email'     => trim( (string) ( $mailbox['from_email'] ?? '' ) ),
		);

		if ( ! in_array( $config['encryption'], array( 'ssl', 'tls', 'none' ), true ) ) {
			$config['encryption'] = 'ssl';
		}
		if ( ! in_array( $config['smtp_encryption'], array( 'ssl', 'tls', 'none' ), true ) ) {
			$config['smtp_encryption'] = 'ssl';
		}
		if ( $config['port'] < 1 || $config['port'] > 65535 ) {
			$config['port'] = 993;
		}
		if ( '' === $config['default_folder'] ) {
			$config['default_folder'] = 'INBOX';
		}
		if ( '' === $config['from_email'] ) {
			$config['from_email'] = $config['username'];
		}

		$stored = (string) ( $mailbox['password'] ?? '' );
		if ( '' !== $stored ) {
			$config['password'] = \GetMCP\Utils\Encryption::decrypt_strict( $stored );
		}

		return $config;
	}

	/**
	 * Database connection settings for a database-connector server.
	 *
	 * Lives under `settings.database` for the same reason the mailbox block
	 * lives under `settings.mailbox`: the auth columns describe HTTP
	 * authentication, and a database password is neither an inbound
	 * credential nor an outbound header. The password is stored encrypted
	 * and decrypted only here, at the point of use.
	 *
	 * Defaults are re-applied on read so a block written by an older build,
	 * or by hand, still yields a complete configuration.
	 *
	 * @since  1.6.0
	 * @return array<string, mixed> Empty when this server has no database configured.
	 */
	public function get_database_config(): array {
		if ( empty( $this->settings ) ) {
			return array();
		}

		$decoded = json_decode( $this->settings, true );
		if ( ! is_array( $decoded ) || empty( $decoded['database'] ) || ! is_array( $decoded['database'] ) ) {
			return array();
		}

		$db = $decoded['database'];

		$engine = strtolower( trim( (string) ( $db['engine'] ?? 'mysql' ) ) );
		if ( ! in_array( $engine, array( 'mysql', 'pgsql' ), true ) ) {
			$engine = 'mysql';
		}

		$ssl_mode = strtolower( trim( (string) ( $db['ssl_mode'] ?? 'require' ) ) );
		if ( ! in_array( $ssl_mode, array( 'disable', 'require', 'verify' ), true ) ) {
			$ssl_mode = 'require';
		}

		$config = array(
			'engine'    => $engine,
			'host'      => trim( (string) ( $db['host'] ?? '' ) ),
			'port'      => (int) ( $db['port'] ?? 0 ),
			'database'  => trim( (string) ( $db['database'] ?? '' ) ),
			'username'  => trim( (string) ( $db['username'] ?? '' ) ),
			'password'  => '',
			'ssl_mode'  => $ssl_mode,
			'ssl_ca'    => trim( (string) ( $db['ssl_ca'] ?? '' ) ),
			'schema'    => trim( (string) ( $db['schema'] ?? '' ) ),
			'read_only' => ! isset( $db['read_only'] ) || (bool) $db['read_only'],
			'max_rows'  => (int) ( $db['max_rows'] ?? 200 ),
			'timeout'   => (int) ( $db['timeout'] ?? 30 ),
		);

		if ( $config['port'] < 1 || $config['port'] > 65535 ) {
			$config['port'] = 'pgsql' === $engine ? 5432 : 3306;
		}
		if ( $config['max_rows'] < 1 || $config['max_rows'] > 1000 ) {
			$config['max_rows'] = 200;
		}
		if ( $config['timeout'] < 1 || $config['timeout'] > 300 ) {
			$config['timeout'] = 30;
		}

		$stored = (string) ( $db['password'] ?? '' );
		if ( '' !== $stored ) {
			$config['password'] = \GetMCP\Utils\Encryption::decrypt_strict( $stored );
		}

		return $config;
	}

	/**
	 * Resolve every connection variable for the current request.
	 *
	 * Merges fixed variables with header-sourced values read from the
	 * incoming request. A header value overrides a fixed variable of the
	 * same name, so a fixed entry can act as the default for clients that
	 * send nothing. Header values failing the URL-structure-safe charset
	 * are ignored rather than substituted — the call then fails with the
	 * clear missing-placeholder error instead of a mangled request.
	 *
	 * @since  1.22.0
	 * @return array<string, string> Variable-name => value map for this request.
	 */
	public function resolve_request_variables(): array {
		$variables = $this->get_variables();

		foreach ( $this->get_header_variables() as $name => $header ) {
			$key = 'HTTP_' . str_replace( '-', '_', strtoupper( $header ) );
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			if ( '' === $value || strlen( $value ) > 200 || preg_match( '/[^A-Za-z0-9._-]/', $value ) ) {
				continue;
			}
			$variables[ $name ] = $value;
		}

		return $variables;
	}
}
