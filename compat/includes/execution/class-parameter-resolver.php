<?php
/**
 * Parameter resolver.
 *
 * @package GetMCP
 * @since   1.0.0
 */

namespace GetMCP\Execution;

use GetMCP\Core\Tool;

/**
 * Resolves MCP tool arguments into a structured HTTP request.
 *
 * Reads the parameter_mapping from the tool and routes each argument
 * to the correct location: URL path, query string, JSON body, or
 * HTTP header.
 *
 * @since 1.0.0
 */
class ParameterResolver {

	/**
	 * Resolve tool arguments into a request object.
	 *
	 * @since  1.0.0
	 * @param  Tool                 $tool      Tool definition.
	 * @param  array<string, mixed> $arguments MCP arguments.
	 * @param  array<string, string> $variables Server connection variables ({{name}} => value),
	 *                                          substituted into the URL and headers after tool
	 *                                          arguments — an argument always wins over a variable
	 *                                          of the same name.
	 * @return array<string, mixed> Structured request: url, method, headers, body.
	 */
	public function resolve( Tool $tool, array $arguments, array $variables = array() ): array {
		$url     = $tool->endpoint_url;
		if ( 'graph.facebook.com' === wp_parse_url( $url, PHP_URL_HOST ) && str_contains( $url, '?' ) ) { $parts = explode( '?', $url, 2 ); $parts[1] = preg_replace_callback( '/(?<!\{)\{(?!\{)|(?<!\})\}(?!\})/', static fn( $m ) => rawurlencode( $m[0] ), $parts[1] ); $url = implode( '?', $parts ); }
		$method  = strtoupper( $tool->http_method );
		$headers = $this->parse_tool_headers( $tool );
		$query   = array();

		// Start the body from the template captured when the tool was imported,
		// not from an empty array. Only the fields the model actually fills
		// become parameters, so an empty start sends a partial object while the
		// cURL this tool was built from always sent the whole thing — and strict
		// APIs reject the partial. Resolved arguments are written on top below,
		// so a field the model does fill always wins over the sample value.
		// A raw-string template (XML, plain text) cannot take per-field writes,
		// so it is carried separately and used only if nothing maps to the body.
		$body     = array();
		$raw_body = null;

		$template = $this->decode_body_template( $tool->body_template );

		if ( is_array( $template ) ) {
			$body = $template;
		} elseif ( is_string( $template ) ) {
			$raw_body = $template;
		}

		// Parse parameter mapping.
		$mapping = $this->parse_mapping( $tool->parameter_mapping );

		// Fill in schema-declared defaults for arguments the caller omitted. An
		// importer routinely emits `"default": true` for the flag that switches
		// on the block the response selector reads; without this the upstream
		// call omits the flag entirely and every selected field comes back null.
		$declared  = $this->schema_properties( $tool );
		$arguments = $this->apply_schema_defaults( $declared, $arguments );

		// Loose bucket for arguments a tool receives without declaring them —
		// only reachable when the tool declares NO properties at all, which is
		// the one case assign_default_target() still lets through (otherwise it
		// has no way to accept arguments). They are kept apart from $query so
		// they can never shadow a query key the operator pinned in the endpoint
		// URL — see the merge below.
		//
		// A tool that DOES declare its properties has stated its contract, and
		// anything outside it is dropped rather than forwarded. That is what
		// makes the schema a real boundary: delete a parameter from a tool and
		// the model cannot reach the upstream field behind it, whatever it puts
		// in the arguments object.
		$loose_query = array();

		foreach ( $arguments as $param_name => $param_value ) {
			$param_config = $mapping[ $param_name ] ?? null;

			if ( null === $param_config || ! is_array( $param_config ) ) {
				$this->assign_default_target( $url, $method, $param_name, $param_value, $body, $query, $loose_query, $declared );
				continue;
			}

			$target = $param_config['target'] ?? $param_config['location'] ?? 'body';
			$key    = $param_config['key'] ?? $param_name;

			switch ( $target ) {
				case 'path':
					$url = $this->replace_path_param( $url, $key, $param_value );
					break;
				case 'query':
					$query[ $key ] = $param_value;
					break;
				case 'body':
					$this->set_nested_body_value( $body, $key, $this->maybe_decode_json( $param_value ) );
					break;
				case 'header':
					$headers[ $this->validate_header_name( $key ) ] = $this->sanitize_header_value( $key, $param_value );
					break;
				default:
					// An unrecognised target used to fall through to the body,
					// which put a JSON body (and a Content-Type) on GET requests
					// that never asked for one. Treat it as "unmapped" instead.
					$this->assign_default_target( $url, $method, $param_name, $param_value, $body, $query, $loose_query, $declared );
					break;
			}
		}

		// Server connection variables fill whatever placeholders the tool
		// arguments left behind — account subdomains and data-center codes
		// (Freshdesk `{{subdomain}}`, Mailchimp `{{dc}}`) live anywhere in the
		// URL, including the hostname. Values are operator config validated by
		// Server::get_variables() to a URL-structure-safe charset; re-checked
		// here because this method is also callable with an arbitrary map.
		foreach ( $variables as $var_name => $var_value ) {
			if ( preg_match( '/[^A-Za-z0-9_]/', (string) $var_name ) || preg_match( '/[^A-Za-z0-9._-]/', (string) $var_value ) ) {
				continue;
			}
			$url = str_replace( array( '{{' . $var_name . '}}', '{' . $var_name . '}' ), (string) $var_value, $url );
			foreach ( $headers as $header_name => $header_value ) {
				if ( is_string( $header_value ) && false !== strpos( $header_value, '{' ) ) {
					$headers[ $header_name ] = str_replace( array( '{{' . $var_name . '}}', '{' . $var_name . '}' ), (string) $var_value, $header_value );
				}
			}
		}

		/**
		 * Filters the tool endpoint URL after parameter resolution.
		 *
		 * @since 1.0.0
		 * @param string               $url       Resolved URL.
		 * @param Tool                 $tool      Tool instance.
		 * @param array<string, mixed> $arguments Original arguments.
		 */
		$url = apply_filters( 'getmcp_tool_endpoint_url', $url, $tool, $arguments );

		// Fail fast if any placeholders were not replaced — an unreplaced
		// {{param}} in the URL would be percent-encoded and reach the upstream API
		// as literal text, causing a confusing 404 instead of a clear error here.
		if ( preg_match( '/\{\{(\w+)\}\}|\{(\w+)\}/', $url, $unresolved ) ) {
			$missing = $unresolved[1] ?: $unresolved[2];
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: placeholder name */
					__( 'Tool execution failed: "%s" was not resolved. Provide it as a tool argument, or define it as a connection variable in the server settings.', 'getmcp' ),
					$missing
				)
			);
		}

		// Merge undeclared passthrough arguments last. A key the operator fixed in
		// the endpoint URL (an API key, a pinned mode) or bound through the
		// parameter mapping wins: otherwise a caller could send an argument the
		// tool never declared and append a second copy of that key, and most
		// upstreams honour the last occurrence.
		if ( ! empty( $loose_query ) ) {
			$fixed    = array();
			$existing = wp_parse_url( $url, PHP_URL_QUERY );
			if ( is_string( $existing ) && '' !== $existing ) {
				parse_str( $existing, $fixed );
			}

			foreach ( $loose_query as $loose_key => $loose_value ) {
				if ( array_key_exists( $loose_key, $fixed ) || array_key_exists( $loose_key, $query ) ) {
					continue;
				}
				$query[ $loose_key ] = $loose_value;
			}
		}

		// Append query parameters to URL.
		// Cast boolean values to strings so http_build_query never sees PHP false
		// (which it converts to an empty string, producing bare `param=` fragments).
		// Only null and empty-string values are dropped — false becomes "false" and
		// integer 0 passes through as-is, both of which are valid API values.
		$query = array_filter(
			array_map(
				function ( $v ) {
					if ( is_bool( $v ) ) {
						return $v ? 'true' : 'false';
					}
					return $v;
				},
				$query
			),
			function ( $v ) {
				return $v !== null && $v !== '';
			}
		);

		if ( ! empty( $query ) ) {
			// A declared parameter is the tool's contract: its value (or schema
			// default) replaces a same-named key baked into the endpoint URL.
			// The importers keep the example query string verbatim while also
			// mapping those keys, so appending without stripping sends every
			// key twice — and most upstreams honour the first occurrence, which
			// silently discards the caller's arguments. Undeclared arguments
			// still can never displace a pinned key (see $loose_query above).
			$url = $this->remove_query_keys( $url, array_keys( $query ) );

			$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';
			$url      .= $separator . $this->build_query( $query );
		}

		// Set Content-Type for body requests.
		if ( ! empty( $body ) && ! isset( $headers['Content-Type'] ) ) {
			$headers['Content-Type'] = 'application/json';
		}

		/**
		 * Filters the outbound request headers.
		 *
		 * @since 1.0.0
		 * @param array<string, string> $headers Outbound headers.
		 * @param Tool                  $tool    Tool instance.
		 */
		$headers = apply_filters( 'getmcp_outbound_request_headers', $headers, $tool );

		// A raw-string template only ships when nothing built an array body.
		// Per-field writes cannot be merged into an opaque string, so if any
		// argument mapped to the body the structured array is the truthful
		// request and the string is dropped.
		$outbound_body = null;

		if ( ! empty( $body ) ) {
			$outbound_body = $body;
		} elseif ( null !== $raw_body && '' !== $raw_body ) {
			$outbound_body = $raw_body;
		}

		return array(
			'url'     => $url,
			'method'  => $method,
			'headers' => $headers,
			'body'    => $outbound_body,
		);
	}

	/**
	 * Decode a stored body template into the shape the resolver can use.
	 *
	 * The column holds whatever the source request sent: a JSON object or list
	 * (stored as its JSON text), or a raw body an API takes verbatim — XML,
	 * plain text, an already-encoded form string. Returning the decoded array
	 * for the first case and the original string for the second lets the caller
	 * keep those two paths apart, because only an array can accept per-field
	 * writes from resolved arguments.
	 *
	 * @since  1.23.0
	 * @param  string|null $template Stored template, or null when the tool has none.
	 * @return array<string, mixed>|list<mixed>|string|null Decoded array, raw string, or null.
	 */
	private function decode_body_template( ?string $template ): array|string|null {
		if ( null === $template || '' === trim( $template ) ) {
			return null;
		}

		$decoded = json_decode( $template, true );

		// json_decode() turns the literal string "null" into null and a bare
		// number into an int, neither of which is a usable request body, so
		// only an array counts as a structured template. Everything else falls
		// through to the raw string, which is what the API was sent originally.
		if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}

		return $template;
	}

	/**
	 * Build a query string, spelling a list value as repeated bracketed keys.
	 *
	 * http_build_query() encodes array( 'tag' => array( 'mcp', 'api' ) ) as
	 * "tag%5B0%5D=mcp&tag%5B1%5D=api". Most REST APIs — Airtable's batch delete
	 * included — read a list as "tag[]=mcp&tag[]=api" instead: repeated keys with
	 * an empty bracket suffix, no index. PHP's own $_GET parsing agrees: without
	 * the brackets a repeated bare key just overwrites itself, so an unbracketed
	 * spelling would silently drop every item but the last on the other end.
	 *
	 * Scalar values keep http_build_query()'s exact encoding, including a space
	 * as "+", so nothing about an existing tool's requests changes.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $query Query parameters.
	 * @return string Encoded query string, without the leading separator.
	 */
	private function build_query( array $query ): string {
		$pairs = array();

		foreach ( $query as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$pairs[] = urlencode( (string) $key ) . '=' . urlencode( $this->query_scalar( $value ) );
				continue;
			}

			// A keyed or nested structure has no repeated-key spelling, so it keeps
			// the bracket encoding http_build_query() would have produced.
			if ( ! $this->is_list( $value ) ) {
				$encoded = http_build_query( array( $key => $value ) );
				if ( '' !== $encoded ) {
					$pairs[] = $encoded;
				}
				continue;
			}

			foreach ( $value as $index => $item ) {
				if ( is_array( $item ) ) {
					// Keep $item's own position — wrapping it in a fresh array()
					// here would reset every item to index 0 and collide.
					$pairs[] = http_build_query( array( $key => array( $index => $item ) ) );
					continue;
				}

				// Same rule as the scalar filter above: null and "" carry nothing,
				// so they would only add a bare "tag[]=" to the wire.
				if ( null === $item || '' === $item ) {
					continue;
				}

				$pairs[] = urlencode( (string) $key . '[]' ) . '=' . urlencode( $this->query_scalar( $item ) );
			}
		}

		return implode( '&', $pairs );
	}

	/**
	 * Whether an array is a plain list (keys 0..n-1, in order).
	 *
	 * array_is_list() is PHP 8.1; the plugin still supports 8.0.
	 *
	 * @since  1.4.0
	 * @param  array<mixed> $value Array to test.
	 * @return bool True when the array is a list.
	 */
	private function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Render one scalar query value the way http_build_query() would, except that
	 * a boolean becomes "true"/"false" instead of "1"/"".
	 *
	 * @since  1.4.0
	 * @param  mixed $value Scalar value.
	 * @return string Query-ready string.
	 */
	private function query_scalar( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return (string) $value;
	}

	/**
	 * Remove the given keys from a URL's query string, preserving the rest.
	 *
	 * Works textually pair-by-pair rather than through parse_str() +
	 * http_build_query() so the encoding of untouched pairs the operator
	 * pinned in the URL survives byte-for-byte. `key[...]` forms match their
	 * base key.
	 *
	 * @since  1.22.0
	 * @param  string   $url  URL possibly carrying a query string.
	 * @param  string[] $keys Query keys to remove.
	 * @return string URL without the given query keys.
	 */
	private function remove_query_keys( string $url, array $keys ): string {
		$q_pos = strpos( $url, '?' );
		if ( false === $q_pos ) {
			return $url;
		}

		$base     = substr( $url, 0, $q_pos );
		$rest     = substr( $url, $q_pos + 1 );
		$fragment = '';
		$f_pos    = strpos( $rest, '#' );
		if ( false !== $f_pos ) {
			$fragment = substr( $rest, $f_pos );
			$rest     = substr( $rest, 0, $f_pos );
		}

		$kept = array();
		foreach ( explode( '&', $rest ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$key      = rawurldecode( explode( '=', $pair, 2 )[0] );
			$base_key = (string) preg_replace( '/\[.*$/', '', $key );
			if ( in_array( $base_key, $keys, true ) ) {
				continue;
			}
			$kept[] = $pair;
		}

		return $base . ( empty( $kept ) ? '' : '?' . implode( '&', $kept ) ) . $fragment;
	}

	/**
	 * Route an argument that carries no usable parameter mapping.
	 *
	 * A declared argument whose name appears as a `{{placeholder}}` in the
	 * endpoint URL fills that placeholder. Nothing else can: the resolver
	 * refuses to call a URL with an unreplaced placeholder, so routing such an
	 * argument to the query string does not merely put it in an odd place, it
	 * fails the call outright with a message telling the operator to supply an
	 * argument they already supplied. Only tools built in the editor carried an
	 * explicit `path` mapping; ones created over the REST API or by an AI
	 * through the built-in server send a plain JSON Schema, which has nowhere to
	 * record a mapping, so every templated URL they produced was dead on
	 * arrival.
	 *
	 * Undeclared arguments are excluded deliberately. Placeholders are also
	 * filled from server connection variables — operator config such as a
	 * Mailchimp `{{dc}}` or a Freshdesk `{{subdomain}}`, which sit in the
	 * hostname — and those must not become writable by whoever is calling the
	 * tool.
	 *
	 * Body for POST/PUT/PATCH, query otherwise. Query arguments the tool's
	 * input schema never declared are held in $loose_query so the caller
	 * cannot use them to shadow an operator-pinned key.
	 *
	 * @since  1.0.0
	 * @param  string               &$url        URL being resolved.
	 * @param  string               $method      Upper-case HTTP method.
	 * @param  string               $param_name  Argument name.
	 * @param  mixed                $param_value Argument value.
	 * @param  array<string, mixed> &$body       Body accumulator.
	 * @param  array<string, mixed> &$query      Declared-query accumulator.
	 * @param  array<string, mixed> &$loose_query Undeclared-query accumulator.
	 * @param  array<string, mixed> $declared    Schema properties keyed by name.
	 * @return void
	 */
	private function assign_default_target( string &$url, string $method, string $param_name, mixed $param_value, array &$body, array &$query, array &$loose_query, array $declared ): void {
		// A tool that declares its properties has stated its contract; anything
		// outside it is either a client bug or someone probing for an upstream
		// parameter the author never exposed. Drop it rather than forward it.
		// Tools with no declared properties keep the passthrough behaviour —
		// that is the only way they can receive arguments at all.
		if ( ! empty( $declared ) && ! array_key_exists( $param_name, $declared ) ) {
			return;
		}

		if ( array_key_exists( $param_name, $declared ) && $this->url_has_placeholder( $url, $param_name ) ) {
			$url = $this->replace_path_param( $url, $param_name, $param_value );
			return;
		}

		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$body[ $param_name ] = $this->maybe_decode_json( $param_value );
			return;
		}

		if ( array_key_exists( $param_name, $declared ) ) {
			$query[ $param_name ] = $param_value;
			return;
		}

		$loose_query[ $param_name ] = $param_value;
	}

	/**
	 * Read the tool's input-schema properties.
	 *
	 * @since  1.0.0
	 * @param  Tool $tool Tool instance.
	 * @return array<string, mixed> Properties keyed by name, empty when absent.
	 */
	private function schema_properties( Tool $tool ): array {
		$schema = $tool->get_input_schema_as_object();

		if ( ! is_array( $schema ) || empty( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return array();
		}

		return $schema['properties'];
	}

	/**
	 * Apply schema-declared defaults for arguments the caller omitted.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $declared  Schema properties keyed by name.
	 * @param  array<string, mixed> $arguments Caller-supplied arguments.
	 * @return array<string, mixed> Arguments with defaults filled in.
	 */
	private function apply_schema_defaults( array $declared, array $arguments ): array {
		foreach ( $declared as $name => $property ) {
			if ( ! is_array( $property ) || ! array_key_exists( 'default', $property ) ) {
				continue;
			}
			if ( array_key_exists( $name, $arguments ) ) {
				continue;
			}
			$arguments[ $name ] = $property['default'];
		}

		return $arguments;
	}

	/**
	 * Validate an outbound header name.
	 *
	 * Header names come from the operator's parameter mapping, but a malformed
	 * one would corrupt the request just as badly as a malformed value.
	 *
	 * @since  1.0.0
	 * @param  string $name Header name from the mapping.
	 * @return string The validated name.
	 * @throws \RuntimeException When the name contains characters illegal in a header field name.
	 */
	private function validate_header_name( string $name ): string {
		if ( '' === $name || preg_match( '/[^!#$%&\'*+\-.^_`|~0-9A-Za-z]/', $name ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: header name */
					__( 'Tool execution failed: "%s" is not a valid HTTP header name.', 'getmcp' ),
					$name
				)
			);
		}

		return $name;
	}

	/**
	 * Convert an argument into a safe outbound header value.
	 *
	 * A value carrying CR or LF terminates the header early, letting a caller
	 * append arbitrary headers to the upstream request — including an
	 * `Authorization` line that overrides the operator's own credential. Those
	 * values are rejected outright rather than silently trimmed so the caller
	 * gets told, and remaining control characters are stripped.
	 *
	 * @since  1.0.0
	 * @param  string $name  Header name (for the error message).
	 * @param  mixed  $value Argument value.
	 * @return string Safe header value.
	 * @throws \RuntimeException When the value is non-scalar or contains CR/LF/NUL.
	 */
	private function sanitize_header_value( string $name, mixed $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		}

		if ( null !== $value && ! is_scalar( $value ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: parameter name */
					__( 'Tool execution failed: the "%s" header parameter must be a text or numeric value.', 'getmcp' ),
					$name
				)
			);
		}

		$value = (string) $value;

		if ( preg_match( '/[\r\n\x00]/', $value ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: parameter name */
					__( 'Tool execution failed: the "%s" header parameter contains line breaks, which are not allowed in an HTTP header.', 'getmcp' ),
					$name
				)
			);
		}

		// Remaining C0/DEL controls are not injection vectors but are still
		// illegal in a field value; drop them rather than fail the call.
		return (string) preg_replace( '/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
	}

	/**
	 * Parse the parameter mapping JSON.
	 *
	 * @since  1.0.0
	 * @param  string|null $mapping_json JSON string.
	 * @return array<string, array<string, string>> Parsed mapping.
	 */
	private function parse_mapping( ?string $mapping_json ): array {
		if ( empty( $mapping_json ) ) {
			return array();
		}

		$mapping = json_decode( $mapping_json, true );

		return is_array( $mapping ) ? $mapping : array();
	}

	/**
	 * Parse custom headers from the tool.
	 *
	 * @since  1.0.0
	 * @param  Tool $tool Tool instance.
	 * @return array<string, string> Headers.
	 */
	private function parse_tool_headers( Tool $tool ): array {
		if ( empty( $tool->headers ) ) {
			return array();
		}

		$headers = json_decode( $tool->headers, true );

		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Set a value in a nested body array using a dotted-path key.
	 *
	 * A key of "product.dimensions.length" produces:
	 *   {"product": {"dimensions": {"length": <value>}}}
	 *
	 * Plain keys (no dot) behave identically to direct array assignment.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> &$body  Body array to write into.
	 * @param  string               $key    Flat or dotted-path key.
	 * @param  mixed                $value  Value to assign.
	 * @return void
	 */
	private function set_nested_body_value( array &$body, string $key, mixed $value ): void {
		if ( false === strpos( $key, '.' ) ) {
			$body[ $key ] = $value;
			return;
		}

		$parts   = explode( '.', $key );
		$current = &$body;

		foreach ( $parts as $i => $part ) {
			if ( $i === count( $parts ) - 1 ) {
				$current[ $part ] = $value;
			} else {
				if ( ! isset( $current[ $part ] ) || ! is_array( $current[ $part ] ) ) {
					$current[ $part ] = array();
				}
				$current = &$current[ $part ];
			}
		}
	}

	/**
	 * Decode a value if it is a JSON-encoded string representing an object or array.
	 *
	 * When a tool parameter is typed as a JSON literal (e.g. a messages array), the
	 * value arrives as a plain PHP string. If it were placed directly into the body
	 * array it would be double-encoded — serialised as a JSON string rather than an
	 * inline object/array. This method detects that case and returns the decoded PHP
	 * value so it merges correctly into the request body.
	 *
	 * Scalars, booleans, and anything that does not parse as a JSON object or array
	 * are returned unchanged.
	 *
	 * @since  1.0.0
	 * @param  mixed $value The parameter value to inspect.
	 * @return mixed Decoded value if it was a JSON object/array string, original otherwise.
	 */
	private function maybe_decode_json( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$trimmed = ltrim( $value );

		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return $value;
		}

		$decoded = json_decode( $value, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		return $value;
	}

	/**
	 * Whether the URL still carries a placeholder of the given name.
	 *
	 * Matches both spellings the resolver substitutes, `{{name}}` and `{name}`.
	 *
	 * @since  1.4.0
	 * @param  string $url  URL being resolved.
	 * @param  string $name Parameter name.
	 * @return bool True when the placeholder is present.
	 */
	private function url_has_placeholder( string $url, string $name ): bool {
		return false !== strpos( $url, '{{' . $name . '}}' )
			|| false !== strpos( $url, '{' . $name . '}' );
	}

	/**
	 * Replace a path parameter in the URL.
	 *
	 * Replaces {{param}} or {param} syntax.
	 *
	 * @since  1.0.0
	 * @param  string $url   URL with template placeholders.
	 * @param  string $key   Parameter key.
	 * @param  mixed  $value Parameter value.
	 * @return string URL with replaced parameter.
	 */
	private function replace_path_param( string $url, string $key, mixed $value ): string {
		$encoded = rawurlencode( (string) $value );

		// Replace both {{key}} and {key} syntax.
		$url = str_replace( '{{' . $key . '}}', $encoded, $url );
		$url = str_replace( '{' . $key . '}', $encoded, $url );

		return $url;
	}
}
