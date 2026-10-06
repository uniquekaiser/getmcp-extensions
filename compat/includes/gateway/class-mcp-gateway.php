<?php
/**
 * MCP Gateway — one endpoint that fronts every server on this install.
 *
 * @package GetMCP
 * @since   1.5.0
 */

declare(strict_types=1);

namespace GetMCP\Gateway;

use GetMCP\Builtin\BuiltinServer;
use GetMCP\Core\Server;
use GetMCP\Core\ServerManager;
use GetMCP\Core\ToolManager;
use GetMCP\Utils\PublicUrl;

/**
 * Class McpGateway
 *
 * A synthetic server, in the same shape as {@see BuiltinServer}, that answers
 * on the bare `/mcp` path and republishes the tools of every server the
 * operator has admitted to it.
 *
 * Because every "upstream" here lives in this same install, the gateway merges
 * catalogues in-process: there is no second HTTP hop, no upstream session to
 * keep alive, and no third-party token for the gateway to broker. A tool call
 * is handed to the owning server's normal execution path, so per-server
 * credentials, rate limits and logging keep working untouched.
 *
 * @since 1.5.0
 */
class McpGateway {

	/**
	 * Canonical slug. The gateway answers on the bare `/mcp` path; this slug
	 * is the addressable alias (`/mcp/gateway`) and the identity the rest of
	 * the codebase resolves it by.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const SLUG = 'gateway';

	/**
	 * Synthetic server id. Distinct from BuiltinServer's 0 so the two are never
	 * confused by an `(int)` comparison.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	/*
	 * Not -1. Every server_id column in the schema is BIGINT UNSIGNED, and
	 * MySQL stores -1 in one of those as 0 — the built-in server's id. The
	 * first cost was sessions: initialize wrote the row under 0, the next
	 * request looked it up under -1, and every gateway session on the
	 * WordPress build died after one call. The second was OAuth clients
	 * registering against the gateway landing in the built-in server's
	 * namespace. SQLite has no unsigned columns, so the standalone app never
	 * showed either. A value that fits the column, sits far above any real
	 * AUTO_INCREMENT id, and stays under 2^53 for JavaScript.
	 */
	public const SERVER_ID = 2147483647;

	/**
	 * Separator between the server slug and the tool slug in a published name.
	 *
	 * Two underscores, because a single one is legal inside both slugs. Names
	 * are still resolved by matching against the real server slugs rather than
	 * by splitting on it, so a slug containing `__` cannot break routing.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const SEPARATOR = '__';

	/**
	 * Ceiling on a published tool name. The spec allows more, but several
	 * clients reject or silently truncate long names, and a truncated name is
	 * a name that cannot be called back.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 128;

	/**
	 * Slugs no stored server may take, so nothing can shadow the gateway or
	 * read as if it were the gateway.
	 *
	 * @since 1.5.0
	 * @var array<int, string>
	 */
	public const RESERVED_SLUGS = array(
		'gateway',
		'hub',
		'all',
		'portal',
		'aggregate',
		'everything',
		'mcp',
	);

	public const ENABLED_OPTION   = 'getmcp_gateway_enabled';
	public const AUTH_OPTION      = 'getmcp_gateway_auth_type';
	public const MODE_OPTION      = 'getmcp_gateway_mode';
	public const SERVERS_OPTION   = 'getmcp_gateway_servers';
	public const NAMESPACE_OPTION = 'getmcp_gateway_namespace_tools';
	public const LOGGING_OPTION   = 'getmcp_gateway_logging';

	/**
	 * Whether the operator has switched the gateway on.
	 *
	 * Off by default: turning it on republishes tools under a single endpoint
	 * and a single credential, which is a decision to take deliberately.
	 *
	 * @since  1.5.0
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/**
	 * Turn the gateway on or off.
	 *
	 * @since 1.5.0
	 * @param bool $enabled Desired state.
	 */
	public static function set_enabled( bool $enabled ): void {
		update_option( self::ENABLED_OPTION, $enabled ? 1 : 0 );
		// The bare /mcp rule only exists while the gateway is on.
		update_option( 'getmcp_flush_rewrite_rules', 1 );
	}

	/**
	 * Inbound auth for the gateway itself.
	 *
	 * Defaults to `oauth` — the same first-party flow the built-in server uses.
	 * It is the only mode a client can complete on its own: paste the URL, the
	 * client discovers the metadata, registers itself and sends the operator
	 * through a browser sign-in. Header modes need a key handed over manually.
	 *
	 * @since  1.5.0
	 * @return string One of none|api-key|bearer|oauth.
	 */
	public static function auth_type(): string {
		$type = (string) get_option( self::AUTH_OPTION, 'oauth' );

		return in_array( $type, array( 'none', 'api-key', 'bearer', 'oauth' ), true ) ? $type : 'oauth';
	}

	/**
	 * Set the gateway's inbound auth mode.
	 *
	 * @since  1.5.0
	 * @param  string $type One of none|api-key|bearer|oauth.
	 * @return bool True when stored, false when the mode is unknown.
	 */
	public static function set_auth_type( string $type ): bool {
		if ( ! in_array( $type, array( 'none', 'api-key', 'bearer', 'oauth' ), true ) ) {
			return false;
		}
		update_option( self::AUTH_OPTION, $type );

		return true;
	}

	/**
	 * Selection mode: every eligible server, or an explicit list.
	 *
	 * @since  1.5.0
	 * @return string `all` or `selected`.
	 */
	public static function mode(): string {
		return 'selected' === get_option( self::MODE_OPTION, 'all' ) ? 'selected' : 'all';
	}

	/**
	 * Set the selection mode.
	 *
	 * @since  1.5.0
	 * @param  string $mode `all` or `selected`.
	 * @return bool True when stored.
	 */
	public static function set_mode( string $mode ): bool {
		if ( ! in_array( $mode, array( 'all', 'selected' ), true ) ) {
			return false;
		}
		update_option( self::MODE_OPTION, $mode );

		return true;
	}

	/**
	 * Server ids admitted to the gateway when the mode is `selected`.
	 *
	 * @since  1.5.0
	 * @return array<int, int>
	 */
	public static function selected_server_ids(): array {
		$ids = get_option( self::SERVERS_OPTION, array() );

		return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * Replace the admitted-server list.
	 *
	 * @since 1.5.0
	 * @param array<int, int|string> $ids Server ids.
	 */
	public static function set_selected_server_ids( array $ids ): void {
		$clean = array_values( array_unique( array_filter( array_map( 'intval', $ids ), fn( $id ) => $id > 0 ) ) );
		update_option( self::SERVERS_OPTION, $clean );
	}

	/**
	 * Whether published tool names carry their server prefix.
	 *
	 * On by default. Without it two servers exposing `search` collide, and the
	 * later one silently wins — turn it off only when you know the catalogues
	 * are disjoint and you want the original names.
	 *
	 * @since  1.5.0
	 * @return bool
	 */
	public static function namespacing_enabled(): bool {
		$value = get_option( self::NAMESPACE_OPTION, null );

		return null === $value ? true : (bool) $value;
	}

	/**
	 * Set tool-name namespacing.
	 *
	 * @since 1.5.0
	 * @param bool $enabled Desired state.
	 */
	public static function set_namespacing( bool $enabled ): void {
		update_option( self::NAMESPACE_OPTION, $enabled ? 1 : 0 );
	}

	/**
	 * Whether a slug is reserved by the gateway.
	 *
	 * @since  1.5.0
	 * @param  string $slug Slug to test.
	 * @return bool
	 */
	public static function slug_is_reserved( string $slug ): bool {
		return in_array( sanitize_title( $slug ), self::RESERVED_SLUGS, true );
	}

	/**
	 * Whether a stored server already sits on one of the reserved slugs.
	 *
	 * Installs that predate the reservation keep their slug — breaking a live
	 * endpoint to free a name is never the right trade — and the gateway
	 * stands down from that alias instead. The bare `/mcp` path is unaffected.
	 *
	 * @since  1.5.0
	 * @return bool
	 */
	public static function alias_is_taken(): bool {
		static $taken = null;

		if ( null === $taken ) {
			$taken   = false;
			$manager = new ServerManager();
			foreach ( self::RESERVED_SLUGS as $slug ) {
				if ( false !== $manager->get_stored_by_slug( $slug ) ) {
					$taken = true;
					break;
				}
			}
		}

		return $taken;
	}

	/**
	 * Whether the gateway should answer requests.
	 *
	 * @since  1.5.0
	 * @return bool
	 */
	public static function is_available(): bool {
		return self::is_enabled();
	}

	/**
	 * The synthetic server the transport, auth and router all operate on.
	 *
	 * @since  1.5.0
	 * @return Server
	 */
	public static function server(): Server {
		return new Server(
			array(
				'id'                 => self::SERVER_ID,
				'uuid'               => '',
				'user_id'            => 0,
				'name'               => __( 'MCP Gateway', 'getmcp' ),
				'slug'               => self::SLUG,
				'server_id'          => null,
				'status'             => self::is_available() ? 'active' : 'inactive',
				'transport_type'     => 'streamable-http',
				'auth_type'          => self::auth_type(),
				'auth_config'        => null,
				'auth_credentials'   => null,
				'cors_origins'       => null,
				'rate_limit_per_min' => 240,
				'settings'           => wp_json_encode(
					array(
						'instructions' => self::instructions(),
						'gateway'      => true,
					)
				),
			)
		);
	}

	/**
	 * Whether a server object is the gateway.
	 *
	 * @since  1.5.0
	 * @param  Server $server Server to test.
	 * @return bool
	 */
	public static function is_gateway( Server $server ): bool {
		return self::SERVER_ID === (int) $server->id && self::SLUG === $server->slug;
	}

	/**
	 * The canonical public endpoint — the bare `/mcp` path.
	 *
	 * @since  1.5.0
	 * @return string
	 */
	public static function endpoint(): string {
		return PublicUrl::to( 'mcp' );
	}

	/**
	 * Canonical resource URI OAuth tokens are bound to.
	 *
	 * @since  1.5.0
	 * @return string
	 */
	public static function resource(): string {
		return self::endpoint();
	}

	/**
	 * Servers whose tools the gateway republishes.
	 *
	 * Always excludes the built-in GetMCP server: it administers this install,
	 * and folding "create a server" into the same surface as an install's
	 * business tools is not a combination to hand out behind one credential.
	 *
	 * @since  1.5.0
	 * @return array<int, Server>
	 */
	public static function included_servers(): array {
		$manager = new ServerManager();
		$result  = $manager->list(
			array(
				'status'   => 'active',
				'per_page' => 500,
				'page'     => 1,
				'orderby'  => 'name',
				'order'    => 'ASC',
			)
		);

		$servers  = $result['items'] ?? array();
		$mode     = self::mode();
		$selected = self::selected_server_ids();

		$included = array();
		foreach ( $servers as $server ) {
			if ( ! $server instanceof Server ) {
				continue;
			}
			// The built-in server is never republished.
			if ( BuiltinServer::SERVER_ID === (int) $server->id || BuiltinServer::slug_is_reserved( $server->slug ) ) {
				continue;
			}
			if ( 'gateway' === $server->server_kind ) { continue; }
			$settings = json_decode( $server->settings ?? '{}', true ) ?: array();
			if ( 'remote-mcp' === $server->server_kind && empty( $settings['remote_mcp']['publish_original'] ) ) { continue; }
			$is_explicit = in_array( (int) $server->id, $selected, true );

			if ( 'selected' === $mode && ! $is_explicit ) {
				continue;
			}

			// Never quietly downgrade a server's protection. A server that
			// demands a credential of its own would become reachable without
			// one the moment it is republished behind an open gateway, so an
			// open gateway carries only servers that were already open. An
			// operator who genuinely wants that combination can still have it
			// by naming the server in `selected` mode — an explicit act.
			if ( 'none' === self::auth_type() && 'none' !== ( $server->auth_type ?? 'none' ) && ! $is_explicit ) {
				continue;
			}

			$included[] = $server;
		}

		/**
		 * Filters the servers the gateway republishes.
		 *
		 * @since 1.5.0
		 * @param array<int, Server> $included Servers admitted to the gateway.
		 */
		return array_values( array_filter( (array) apply_filters( 'getmcp_gateway_servers', $included ), static function( $server ) {
			if ( ! $server instanceof Server || $server->id <= 0 || 'active' !== $server->status || 'gateway' === $server->server_kind || BuiltinServer::slug_is_reserved( $server->slug ) ) { return false; }
			$settings = json_decode( $server->settings ?? '{}', true ) ?: array();
			return 'remote-mcp' !== $server->server_kind || ! empty( $settings['remote_mcp']['publish_original'] );
		} ) );
	}

	/**
	 * The published name for one tool.
	 *
	 * @since  1.5.0
	 * @param  Server $server Owning server.
	 * @param  string $tool   Bare tool name.
	 * @return string
	 */
	public static function published_name( Server $server, string $tool ): string {
		if ( ! self::namespacing_enabled() ) {
			return $tool;
		}

		$name = $server->slug . self::SEPARATOR . $tool;

		if ( strlen( $name ) <= self::MAX_NAME_LENGTH ) {
			return $name;
		}

		// Keep the tool name intact and shorten the prefix — the tail is what
		// tells the model what the tool does. A short hash keeps two truncated
		// prefixes from colliding.
		$budget = self::MAX_NAME_LENGTH - strlen( self::SEPARATOR ) - strlen( $tool ) - 5;
		if ( $budget < 1 ) {
			return substr( $tool, 0, self::MAX_NAME_LENGTH );
		}
		$short = substr( $server->slug, 0, $budget ) . '-' . substr( md5( $server->slug ), 0, 4 );

		return $short . self::SEPARATOR . $tool;
	}

	/**
	 * Every tool the gateway publishes, in MCP definition form.
	 *
	 * @since  1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function tools(): array {
		$tool_manager = new ToolManager();
		$tools        = array();
		$seen         = array();

		foreach ( self::included_servers() as $server ) {
			$page = 1;
			do {
				$result = $tool_manager->get_by_server(
					$server->id,
					array(
						'per_page' => 100,
						'page'     => $page,
						'status'   => 'active',
						'orderby'  => 'sort_order',
						'order'    => 'ASC',
					)
				);

				foreach ( $result['items'] ?? array() as $tool ) {
					$definition = $tool->to_mcp_definition();
					if ( empty( $definition['name'] ) ) {
						continue;
					}

					$published = self::published_name( $server, (string) $definition['name'] );

					// With namespacing off, first server to publish a name keeps
					// it. Dropping the duplicate is the honest outcome: two tools
					// answering to one name would route by accident.
					if ( isset( $seen[ $published ] ) ) {
						continue;
					}
					$seen[ $published ] = true;

					$definition['name'] = $published;

					// Say which server a tool came from. Models pick tools from
					// the description, and "on Server X" is often the deciding
					// detail once several servers offer similar verbs.
					$description = (string) ( $definition['description'] ?? '' );
					$definition['description'] = '' === $description
						? sprintf( /* translators: %s: server name. */ __( 'From the %s server.', 'getmcp' ), $server->name )
						: $description . ' ' . sprintf( /* translators: %s: server name. */ __( '(From the %s server.)', 'getmcp' ), $server->name );

					$tools[] = $definition;
				}

				$total_pages = (int) ceil( (int) ( $result['total'] ?? 0 ) / 100 );
				++$page;
			} while ( $page <= $total_pages );
		}

		/**
		 * Filters the merged tool list the gateway publishes.
		 *
		 * @since 1.5.0
		 * @param array<int, array<string, mixed>> $tools Merged definitions.
		 */
		return apply_filters( 'getmcp_gateway_tools', $tools );
	}

	/**
	 * Resolve a published tool name back to its owning server and bare name.
	 *
	 * Matching is done against the real server slugs, longest first, rather
	 * than by splitting on the separator — a slug is allowed to contain the
	 * separator, and splitting would route such a tool to the wrong server.
	 *
	 * @since  1.5.0
	 * @param  string $published Published tool name.
	 * @return array{server: Server, tool: string}|null Null when nothing owns the name.
	 */
	public static function resolve_tool( string $published ): ?array {
		$servers = self::included_servers();

		if ( self::namespacing_enabled() ) {
			// Longest slug first, so `orders-eu__x` cannot be claimed by `orders`.
			usort( $servers, fn( $a, $b ) => strlen( $b->slug ) <=> strlen( $a->slug ) );

			foreach ( $servers as $server ) {
				$prefix = $server->slug . self::SEPARATOR;
				if ( str_starts_with( $published, $prefix ) ) {
					return array(
						'server' => $server,
						'tool'   => substr( $published, strlen( $prefix ) ),
					);
				}
			}
		}

		// Either namespacing is off, or the name was shortened by
		// published_name(). Fall back to matching the published form
		// tool-by-tool, which always terminates on the same answer.
		$tool_manager = new ToolManager();
		foreach ( $servers as $server ) {
			$result = $tool_manager->get_by_server(
				$server->id,
				array(
					'per_page' => 500,
					'page'     => 1,
					'status'   => 'active',
				)
			);
			foreach ( $result['items'] ?? array() as $tool ) {
				$definition = $tool->to_mcp_definition();
				$name       = (string) ( $definition['name'] ?? '' );
				if ( '' !== $name && self::published_name( $server, $name ) === $published ) {
					return array(
						'server' => $server,
						'tool'   => $name,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Instructions surfaced to clients on initialize.
	 *
	 * @since  1.5.0
	 * @return string
	 */
	public static function instructions(): string {
		$count = count( self::included_servers() );

		return sprintf(
			/* translators: %d: number of servers behind the gateway. */
			_n(
				'This gateway exposes the tools of %d MCP server on this site through a single endpoint. Tool names are prefixed with the slug of the server that owns them.',
				'This gateway exposes the tools of %d MCP servers on this site through a single endpoint. Tool names are prefixed with the slug of the server that owns them.',
				$count,
				'getmcp'
			),
			$count
		);
	}

	/**
	 * Servers held back from an open gateway because they carry their own auth.
	 *
	 * Reported so the admin screen can explain an absence rather than leave the
	 * operator wondering where a server went.
	 *
	 * @since  1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function withheld_servers(): array {
		if ( 'none' !== self::auth_type() ) {
			return array();
		}

		$manager  = new ServerManager();
		$result   = $manager->list( array( 'status' => 'active', 'per_page' => 500, 'page' => 1 ) );
		$selected = self::selected_server_ids();
		$withheld = array();

		foreach ( $result['items'] ?? array() as $server ) {
			if ( ! $server instanceof Server ) {
				continue;
			}
			if ( BuiltinServer::SERVER_ID === (int) $server->id || BuiltinServer::slug_is_reserved( $server->slug ) ) {
				continue;
			}
			if ( 'none' === ( $server->auth_type ?? 'none' ) || in_array( (int) $server->id, $selected, true ) ) {
				continue;
			}
			$withheld[] = array(
				'id'        => (int) $server->id,
				'name'      => $server->name,
				'slug'      => $server->slug,
				'auth_type' => $server->auth_type,
				'reason'    => __( 'This server requires its own credential. Give the gateway an auth mode, or add the server explicitly, before it is republished.', 'getmcp' ),
			);
		}

		return $withheld;
	}

	/**
	 * Every server the operator could put behind the gateway.
	 *
	 * Distinct from {@see included_servers()}, which answers "what is published
	 * right now". This answers "what is on the menu" — the whole active list,
	 * regardless of mode or selection — because the picker has to render the
	 * unticked boxes too, and in `selected` mode with nothing chosen yet the
	 * published list is empty by definition.
	 *
	 * @since  1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function candidate_servers(): array {
		$manager = new ServerManager();
		$result  = $manager->list(
			array(
				'status'   => 'active',
				'per_page' => 500,
				'page'     => 1,
				'orderby'  => 'name',
				'order'    => 'ASC',
			)
		);

		$candidates = array();
		foreach ( $result['items'] ?? array() as $server ) {
			if ( ! $server instanceof Server ) {
				continue;
			}
			if ( BuiltinServer::SERVER_ID === (int) $server->id || BuiltinServer::slug_is_reserved( $server->slug ) ) {
				continue;
			}
			$candidates[] = array(
				'id'        => (int) $server->id,
				'name'      => $server->name,
				'slug'      => $server->slug,
				'auth_type' => $server->auth_type ?? 'none',
			);
		}

		return $candidates;
	}

	/**
	 * A summary for the admin screens and the REST API.
	 *
	 * @since  1.5.0
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$servers = self::included_servers();
		$withheld = self::withheld_servers();

		return array(
			'candidates'   => self::candidate_servers(),
			'selected'     => self::selected_server_ids(),
			'enabled'      => self::is_enabled(),
			'endpoint'     => self::endpoint(),
			'alias'        => self::alias_is_taken() ? null : PublicUrl::server( self::SLUG ),
			'auth_type'    => self::auth_type(),
			'mode'         => self::mode(),
			'namespaced'   => self::namespacing_enabled(),
			'server_count' => count( $servers ),
			'withheld'     => $withheld,
			'tool_count'   => count( self::tools() ),
			'servers'      => array_map(
				fn( Server $s ) => array(
					'id'   => (int) $s->id,
					'name' => $s->name,
					'slug' => $s->slug,
				),
				$servers
			),
		);
	}
}
