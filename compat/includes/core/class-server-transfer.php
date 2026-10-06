<?php
/**
 * Whole-server export and import.
 *
 * The tools-only export could move a catalogue but not a server: the target
 * still had to exist, and everything around the tools — resources, prompts,
 * connection variables, instructions, redaction rules, limits, CORS, the auth
 * setup — had to be rebuilt by hand. This carries the configuration and
 * recreates the server on arrival.
 *
 * Stored credentials are deliberately left out. They are encrypted with a key
 * unique to the site that stored them, so they could not be decrypted on the
 * target even if they travelled; shipping the ciphertext would only give a
 * false sense that the import was complete.
 *
 * @package GetMCP
 * @since   1.4.0
 */

namespace GetMCP\Core;

use WP_Error;

defined( 'ABSPATH' ) || defined( 'GETMCP_APP' ) || exit;

class ServerTransfer {

	/**
	 * Manifest format identifier. The minor number is informational; readers
	 * accept any `getmcp/server-v1*` so a manifest with extra keys still imports.
	 */
	public const FORMAT = 'getmcp/server-v1';

	/**
	 * Columns never exported.
	 *
	 * @var string[]
	 */
	public const EXCLUDED_FIELDS = array(
		'auth_credentials',
		'outbound_auth_credentials',
		'test_auth_credentials',
	);

	/** Import ceilings, matching the per-type caps the tools importer uses. */
	private const MAX_TOOLS     = 1000;
	private const MAX_RESOURCES = 1000;
	private const MAX_PROMPTS   = 1000;

	/**
	 * Per-server settings keys that travel.
	 *
	 * An allowlist rather than "everything": the settings blob also accumulates
	 * per-install state — logo attachment ids that mean nothing on another site,
	 * onboarding flags — and copying those across would be noise at best.
	 *
	 * @var string[]
	 */
	private const PORTABLE_SETTINGS = array(
		'description',
		'version',
		'instructions',
		'variables',
		'header_variables',
		'pii_redaction',
		'ip_allowlist',
		'ip_blocklist',
		'log_response_data',
		'logo_url',
		// Connector connection shapes travel; their passwords do not. Stripped
		// on the way out and again on the way in, alongside the same treatment
		// every other stored credential gets — see strip_connector_passwords().
		'mailbox',
		'database',
	);

	/**
	 * Settings blocks that carry a connector password.
	 *
	 * @var string[]
	 */
	private const CONNECTOR_BLOCKS = array( 'mailbox', 'database' );

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Build a manifest describing a server and everything it serves.
	 *
	 * @since  1.4.0
	 * @param  Server $server Server to export.
	 * @return array<string, mixed>
	 */
	public static function export( Server $server ): array {
		if ( 'native' !== $server->server_kind ) {
			$config = \GetMCP\Gateway\FeatureManager::present( $server );
			unset( $config['id'], $config['url'], $config['updated_at'], $config['connection_status'], $config['has_shared_credentials'] );
			$config['allowed_user_ids'] = array();
			$config['server_ids'] = array();
			return array( 'format' => 'getmcp/connections-v1', 'connection' => $config, 'excluded' => array( 'credentials', 'upstream_accounts', 'site_user_ids', 'site_member_ids' ), 'instructions' => 'Create using getmcp/v1/connections. Reselect eligible users and member servers on the destination site; re-enter shared secrets and reconnect user accounts.' );
		}
		$tools     = self::collect( new ToolManager(), $server->id );
		$resources = self::collect( new ResourceManager(), $server->id );
		$prompts   = self::collect( new PromptManager(), $server->id );

		return array(
			'format'      => self::FORMAT,
			'exported_at' => gmdate( 'c' ),
			'generator'   => array(
				'product' => 'GetMCP',
				'version' => defined( 'GETMCP_VERSION' ) ? GETMCP_VERSION : '',
			),
			'server'      => self::server_shape( $server ),
			'tools'       => array_map( array( self::class, 'tool_shape' ), $tools ),
			'resources'   => array_map( array( self::class, 'resource_shape' ), $resources ),
			'prompts'     => array_map( array( self::class, 'prompt_shape' ), $prompts ),
			/*
			 * Named in the file itself so someone reading it — or restoring from
			 * it months later — can see what still has to be re-entered, rather
			 * than discovering it when a tool 401s.
			 */
			'excluded'    => array(
				'fields' => self::EXCLUDED_FIELDS,
				'reason' => 'Stored credentials are encrypted with a key unique to the site that saved them, so they cannot be restored elsewhere. Re-enter them after importing.',
			),
		);
	}

	/**
	 * @since 1.4.0
	 * @param  object $manager Manager exposing get_by_server().
	 * @return array<int, object>
	 */
	private static function collect( object $manager, int $server_id ): array {
		$result = $manager->get_by_server(
			$server_id,
			array(
				'per_page' => 1000,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		return is_array( $result['items'] ?? null ) ? $result['items'] : array();
	}

	/**
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	private static function server_shape( Server $server ): array {
		return array(
			'name'                 => $server->name,
			'slug'                 => $server->slug,
			'status'               => $server->status,
			'transport_type'       => $server->transport_type,
			// Auth *shape* travels; the secret does not. On arrival the server
			// is created with its auth type intact but no credential, so the
			// operator is prompted rather than silently running unauthenticated.
			'auth_type'            => $server->auth_type,
			'auth_config'          => self::decode( $server->auth_config ),
			'outbound_auth_type'   => $server->outbound_auth_type,
			'outbound_auth_config' => self::decode( $server->outbound_auth_config ),
			'cors_origins'         => $server->cors_origins,
			'rate_limit_per_min'   => (int) $server->rate_limit_per_min,
			'settings'             => self::portable_settings( $server ),
		);
	}

	/**
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	private static function portable_settings( Server $server ): array {
		$settings = self::decode( $server->settings );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		$out = array();

		foreach ( self::PORTABLE_SETTINGS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}

		return self::strip_connector_passwords( $out );
	}

	/**
	 * Remove connector passwords from a portable settings block.
	 *
	 * Applied on export so a manifest never carries a credential, and again on
	 * import so a hand-edited manifest cannot inject one. The rest of each
	 * connector block travels, so importing gives a fully configured
	 * connection that needs only the password re-entered — the same contract
	 * as every other credential in a transfer.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, mixed> $settings Portable settings.
	 * @return array<string, mixed>
	 */
	private static function strip_connector_passwords( array $settings ): array {
		foreach ( self::CONNECTOR_BLOCKS as $block ) {
			if ( isset( $settings[ $block ] ) && is_array( $settings[ $block ] ) ) {
				unset( $settings[ $block ]['password'], $settings[ $block ]['has_password'] );
			}
		}

		return $settings;
	}

	/**
	 * Strip credential values from a tool's custom headers for the manifest.
	 *
	 * The name is kept and the value replaced with the same placeholder the
	 * rest of the product uses for "configured but not yet filled in", so on
	 * import the header is visibly present in the tool editor with an obvious
	 * value to replace — not silently absent, and not silently a secret. The
	 * rule for which headers count is ToolExecutor's, the one the replay log
	 * already applies, so the two can never disagree.
	 *
	 * @since  1.6.0
	 * @param  mixed $headers Decoded headers, or null.
	 * @return array<string, mixed>|null
	 */
	private static function portable_headers( $headers ): ?array {
		if ( ! is_array( $headers ) ) {
			return null;
		}

		$out = array();
		foreach ( $headers as $name => $value ) {
			$out[ $name ] = \GetMCP\Execution\ToolExecutor::is_sensitive_header( (string) $name )
				? Server::PLACEHOLDER . strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $name ) )
				: $value;
		}

		return $out;
	}

	/**
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	private static function tool_shape( Tool $tool ): array {
		return array(
			'name'               => $tool->name,
			'slug'               => $tool->slug,
			'description'        => $tool->description,
			'endpoint_url'       => $tool->endpoint_url,
			'http_method'        => $tool->http_method,
			// Without these a mailbox tool would import as a broken HTTP tool
			// pointing at nothing.
			'tool_type'          => $tool->tool_type,
			'handler'            => $tool->handler,
			'input_schema'       => self::decode( $tool->input_schema ),
			// A body template is legally a raw string (XML, plain text), so it
			// is only decoded when it really is JSON — decoding blindly would
			// drop a non-JSON template from the manifest.
			'body_template'      => self::decode( $tool->body_template ) ?? $tool->body_template,
			'parameter_mapping'  => self::decode( $tool->parameter_mapping ),
			'response_mapping'   => self::decode( $tool->response_mapping ),
			// Header NAMES travel; a credential VALUE does not. A hand-typed
			// `Authorization: Bearer …` used to go into the manifest verbatim —
			// the one place in this format a secret could leak, and the one
			// most likely to be pasted into a support ticket or a shared drive.
			'headers'            => self::portable_headers( self::decode( $tool->headers ) ),
			'timeout'            => (int) $tool->timeout,
			'cache_ttl'          => (int) $tool->cache_ttl,
			'retry_count'        => (int) $tool->retry_count,
			'retry_backoff'      => $tool->retry_backoff,
			'ssl_verify'         => (bool) $tool->ssl_verify,
			'rate_limit_per_min' => (int) $tool->rate_limit_per_min,
			'status'             => $tool->status,
			'tags'               => self::decode( $tool->tags ) ?? array(),
			// Only a deliberate override travels — see ToolsController.
			'annotations'        => $tool->has_annotation_override() ? $tool->get_effective_annotations() : null,
			'sort_order'         => (int) $tool->sort_order,
		);
	}

	/**
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	private static function resource_shape( Resource $resource ): array {
		return array(
			'uri'                => $resource->uri,
			'name'               => $resource->name,
			'description'        => $resource->description,
			'mime_type'          => $resource->mime_type,
			'data_source_type'   => $resource->data_source_type,
			'data_source_config' => self::decode( $resource->data_source_config ) ?? $resource->data_source_config,
			'template_uri'       => $resource->template_uri,
		);
	}

	/**
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	private static function prompt_shape( Prompt $prompt ): array {
		return array(
			'name'             => $prompt->name,
			'description'      => $prompt->description,
			'arguments'        => self::decode( $prompt->arguments ),
			'template_content' => $prompt->template_content,
		);
	}

	/**
	 * Decode a JSON column, or null when it is empty or not JSON.
	 *
	 * @since 1.4.0
	 * @param  mixed $raw Raw column value.
	 * @return array<mixed>|null
	 */
	private static function decode( $raw ): ?array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate a manifest before anything is written.
	 *
	 * @since  1.4.0
	 * @param  mixed $manifest Decoded manifest.
	 * @return WP_Error|null Error when unusable, null when it can be imported.
	 */
	public static function validate( $manifest ) {
		if ( ! is_array( $manifest ) ) {
			return new WP_Error(
				'invalid_manifest',
				'The import file could not be read as JSON.',
				array( 'status' => 400 )
			);
		}

		$format = isset( $manifest['format'] ) ? (string) $manifest['format'] : '';

		if ( '' !== $format && ! str_starts_with( $format, 'getmcp/server-v' ) ) {
			// A tools-only manifest is a common mistake — it looks similar and
			// the difference matters, so say which importer it belongs to.
			if ( str_starts_with( $format, 'getmcp/tools-v' ) ) {
				return new WP_Error(
					'wrong_manifest_type',
					'This is a tools export. Open a server and use Import tools, or export the whole server to move everything at once.',
					array( 'status' => 400 )
				);
			}

			return new WP_Error(
				'unsupported_format',
				sprintf( 'Unsupported manifest format "%s". Expected %s.', $format, self::FORMAT ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $manifest['server'] ) || ! is_array( $manifest['server'] ) ) {
			return new WP_Error(
				'invalid_manifest',
				'The manifest has no "server" section, so there is nothing to create.',
				array( 'status' => 400 )
			);
		}

		if ( empty( $manifest['server']['name'] ) ) {
			return new WP_Error(
				'invalid_manifest',
				'The manifest\'s server has no name.',
				array( 'status' => 400 )
			);
		}

		foreach ( array(
			'tools'     => self::MAX_TOOLS,
			'resources' => self::MAX_RESOURCES,
			'prompts'   => self::MAX_PROMPTS,
		) as $key => $max ) {
			if ( isset( $manifest[ $key ] ) && is_array( $manifest[ $key ] ) && count( $manifest[ $key ] ) > $max ) {
				return new WP_Error(
					'manifest_too_large',
					sprintf( 'Manifest contains %1$d %2$s — the limit is %3$d per import.', count( $manifest[ $key ] ), $key, $max ),
					array( 'status' => 413 )
				);
			}
		}

		return null;
	}

	/**
	 * Create a server from a manifest.
	 *
	 * The server is created first and its children added underneath, so a
	 * failure part-way leaves a visible, deletable server rather than orphaned
	 * rows. Individual children that fail are reported instead of aborting the
	 * whole import — one malformed tool in a hundred should not cost the other
	 * ninety-nine.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $manifest Validated manifest.
	 * @param  string|null          $name_override Name to use instead of the manifest's.
	 * @return array<string, mixed>|WP_Error Summary of what was created.
	 */
	public static function import( array $manifest, ?string $name_override = null ) {
		if ( 'getmcp/connections-v1' === ( $manifest['format'] ?? '' ) || 'native' !== ( $manifest['server']['server_kind'] ?? 'native' ) ) { return new WP_Error( 'getmcp_connection_import', 'Use the native connections API to recreate this connection. Reselect site users and server membership explicitly.', array( 'status' => 400 ) ); }
		$incoming = $manifest['server'];

		$name = $name_override && '' !== trim( $name_override )
			? trim( $name_override )
			: (string) $incoming['name'];

		$server_manager = new ServerManager();

		/*
		 * Slug is passed through rather than forced: ServerManager::create()
		 * already makes it unique, so importing the same manifest twice gives
		 * `weather-api` and `weather-api-2` instead of failing on a collision.
		 */
		$server = $server_manager->create(
			array(
				'name'                 => $name,
				'slug'                 => (string) ( $incoming['slug'] ?? '' ),
				'status'               => self::one_of( $incoming['status'] ?? 'active', array( 'active', 'inactive' ), 'active' ),
				'transport_type'       => (string) ( $incoming['transport_type'] ?? 'streamable-http' ),
				'auth_type'            => (string) ( $incoming['auth_type'] ?? 'none' ),
				'auth_config'          => self::encode( $incoming['auth_config'] ?? null ),
				'outbound_auth_type'   => $incoming['outbound_auth_type'] ?? null,
				'outbound_auth_config' => self::encode( $incoming['outbound_auth_config'] ?? null ),
				'cors_origins'         => $incoming['cors_origins'] ?? null,
				'rate_limit_per_min'   => isset( $incoming['rate_limit_per_min'] ) ? (int) $incoming['rate_limit_per_min'] : 60,
				'settings'             => self::encode( self::incoming_settings( $incoming ) ),
			)
		);

		if ( ! $server ) {
			return new WP_Error(
				'server_create_failed',
				'The server could not be created.',
				array( 'status' => 500 )
			);
		}

		$summary = array(
			'server'    => array(
				'uuid' => $server->uuid,
				'name' => $server->name,
				'slug' => $server->slug,
			),
			'created'   => array(
				'tools'     => 0,
				'resources' => 0,
				'prompts'   => 0,
			),
			'skipped'   => array(),
			'needs_credentials' => self::needs_credentials( $server ),
		);

		self::import_tools( $manifest['tools'] ?? array(), $server->id, $summary );
		self::import_resources( $manifest['resources'] ?? array(), $server->id, $summary );
		self::import_prompts( $manifest['prompts'] ?? array(), $server->id, $summary );

		return $summary;
	}

	/**
	 * @since 1.4.0
	 * @param mixed                $items
	 * @param array<string, mixed> $summary
	 */
	private static function import_tools( $items, int $server_id, array &$summary ): void {
		if ( ! is_array( $items ) ) {
			return;
		}

		$manager = new ToolManager();

		foreach ( $items as $index => $item ) {
			// A connector tool legitimately has no endpoint — its work is named
			// by `handler`, not a URL — so the endpoint requirement applies to
			// HTTP tools only. Without this split, importing a mailbox server
			// silently dropped every one of its tools.
			$item_type = ToolManager::sanitize_tool_type( $item['tool_type'] ?? 'http' );
			$needs_url = 'http' === $item_type;

			if ( ! is_array( $item ) || empty( $item['name'] ) || ( $needs_url && empty( $item['endpoint_url'] ) ) ) {
				$summary['skipped'][] = self::skip( 'tool', $index, $item['name'] ?? null, 'missing a name or endpoint URL' );
				continue;
			}

			if ( ! $needs_url && ! \GetMCP\Connectors\Connectors::has_handler( $item_type, (string) ( $item['handler'] ?? '' ) ) ) {
				$summary['skipped'][] = self::skip( 'tool', $index, $item['name'] ?? null, 'unknown connector action' );
				continue;
			}

			$created = $manager->create(
				array(
					'server_id'          => $server_id,
					'name'               => (string) $item['name'],
					'slug'               => (string) ( $item['slug'] ?? '' ),
					'description'        => (string) ( $item['description'] ?? '' ),
					'endpoint_url'       => (string) ( $item['endpoint_url'] ?? '' ),
					'http_method'        => (string) ( $item['http_method'] ?? 'GET' ),
					'tool_type'          => $item_type,
					'handler'            => (string) ( $item['handler'] ?? '' ),
					'input_schema'       => self::encode( $item['input_schema'] ?? null ),
					'body_template'      => self::encode_or_string( $item['body_template'] ?? null ),
					'parameter_mapping'  => self::encode( $item['parameter_mapping'] ?? null ),
					'response_mapping'   => self::encode( $item['response_mapping'] ?? null ),
					'headers'            => self::encode( $item['headers'] ?? null ),
					'timeout'            => isset( $item['timeout'] ) ? (int) $item['timeout'] : 30,
					'cache_ttl'          => isset( $item['cache_ttl'] ) ? (int) $item['cache_ttl'] : 0,
					'retry_count'        => isset( $item['retry_count'] ) ? (int) $item['retry_count'] : 0,
					'retry_backoff'      => (string) ( $item['retry_backoff'] ?? 'exponential' ),
					'ssl_verify'         => ! isset( $item['ssl_verify'] ) || (bool) $item['ssl_verify'],
					'rate_limit_per_min' => isset( $item['rate_limit_per_min'] ) ? (int) $item['rate_limit_per_min'] : 0,
					'status'             => self::one_of( $item['status'] ?? 'active', array( 'active', 'inactive' ), 'active' ),
					'tags'               => $item['tags'] ?? null,
					'annotations'        => $item['annotations'] ?? null,
					'sort_order'         => isset( $item['sort_order'] ) ? (int) $item['sort_order'] : 0,
				)
			);

			if ( $created ) {
				++$summary['created']['tools'];
			} else {
				$summary['skipped'][] = self::skip( 'tool', $index, $item['name'], 'could not be created' );
			}
		}
	}

	/**
	 * @since 1.4.0
	 * @param mixed                $items
	 * @param array<string, mixed> $summary
	 */
	private static function import_resources( $items, int $server_id, array &$summary ): void {
		if ( ! is_array( $items ) ) {
			return;
		}

		$manager = new ResourceManager();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['uri'] ) || empty( $item['name'] ) ) {
				$summary['skipped'][] = self::skip( 'resource', $index, $item['name'] ?? null, 'missing a URI or name' );
				continue;
			}

			$created = $manager->create(
				array(
					'server_id'          => $server_id,
					'uri'                => (string) $item['uri'],
					'name'               => (string) $item['name'],
					'description'        => $item['description'] ?? null,
					'mime_type'          => (string) ( $item['mime_type'] ?? 'text/plain' ),
					'data_source_type'   => (string) ( $item['data_source_type'] ?? 'static' ),
					'data_source_config' => self::encode_or_string( $item['data_source_config'] ?? null ),
					'template_uri'       => $item['template_uri'] ?? null,
				)
			);

			if ( $created ) {
				++$summary['created']['resources'];
			} else {
				$summary['skipped'][] = self::skip( 'resource', $index, $item['name'], 'could not be created' );
			}
		}
	}

	/**
	 * @since 1.4.0
	 * @param mixed                $items
	 * @param array<string, mixed> $summary
	 */
	private static function import_prompts( $items, int $server_id, array &$summary ): void {
		if ( ! is_array( $items ) ) {
			return;
		}

		$manager = new PromptManager();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				$summary['skipped'][] = self::skip( 'prompt', $index, null, 'missing a name' );
				continue;
			}

			$created = $manager->create(
				array(
					'server_id'        => $server_id,
					'name'             => (string) $item['name'],
					'description'      => $item['description'] ?? null,
					'arguments'        => self::encode( $item['arguments'] ?? null ),
					'template_content' => (string) ( $item['template_content'] ?? '' ),
				)
			);

			if ( $created ) {
				++$summary['created']['prompts'];
			} else {
				$summary['skipped'][] = self::skip( 'prompt', $index, $item['name'], 'could not be created' );
			}
		}
	}

	/**
	 * Which credentials the operator has to re-enter for this server to work.
	 *
	 * @since 1.4.0
	 * @return string[]
	 */
	private static function needs_credentials( Server $server ): array {
		$needs = array();

		if ( 'none' !== $server->auth_type && '' !== (string) $server->auth_type ) {
			$needs[] = 'inbound';
		}

		if ( $server->outbound_auth_type && 'none' !== $server->outbound_auth_type ) {
			$needs[] = 'outbound';
		}

		// The mailbox connection travels without its password, so an imported
		// mailbox server is fully configured but cannot connect until the
		// password is re-entered. Say so, rather than letting the first tool
		// call be how the user finds out.
		$config = $server->get_mailbox_config();
		if ( ! empty( $config['host'] ) ) {
			$needs[] = 'mailbox';
		}

		// Same contract for the database connector.
		$database = $server->get_database_config();
		if ( ! empty( $database['host'] ) ) {
			$needs[] = 'database';
		}

		return $needs;
	}

	/**
	 * @since 1.4.0
	 * @param  array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private static function incoming_settings( array $incoming ): array {
		$settings = isset( $incoming['settings'] ) && is_array( $incoming['settings'] )
			? $incoming['settings']
			: array();

		$out = array();

		// Same allowlist as export, applied again on the way in: a hand-edited
		// or hostile manifest must not be able to seed arbitrary settings keys.
		foreach ( self::PORTABLE_SETTINGS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}

		return self::strip_connector_passwords( $out );
	}

	/**
	 * @since 1.4.0
	 * @param  mixed    $value
	 * @param  string[] $allowed
	 */
	private static function one_of( $value, array $allowed, string $fallback ): string {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * @since 1.4.0
	 * @param  mixed $value
	 * @return string|null
	 */
	private static function encode( $value ): ?string {
		if ( null === $value || array() === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		$encoded = wp_json_encode( $value );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * Like encode(), but keeps a plain string as-is — for fields that legally
	 * hold raw text (an XML body template, a static resource payload).
	 *
	 * @since 1.4.0
	 * @param  mixed $value
	 * @return string|null
	 */
	private static function encode_or_string( $value ): ?string {
		if ( is_string( $value ) ) {
			return '' === $value ? null : $value;
		}

		return self::encode( $value );
	}

	/**
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	private static function skip( string $type, $index, ?string $name, string $reason ): array {
		return array(
			'type'   => $type,
			'index'  => is_int( $index ) ? $index : 0,
			'name'   => $name,
			'reason' => $reason,
		);
	}
}
