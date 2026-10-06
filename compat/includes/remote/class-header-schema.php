<?php
/** Validate and mirror 2026 MCP schema header annotations. @package GetMCP */
namespace GetMCP\Remote;

class HeaderSchema {
	public static function paths( $schema ): array {
		$paths = array(); $seen = array();
		$walk = function( $node, array $path, bool $reachable ) use ( &$walk, &$paths, &$seen ) {
			if ( $node instanceof \stdClass ) { $node = (array) $node; }
			if ( ! is_array( $node ) ) { return; }
			if ( isset( $node['x-mcp-header'] ) ) {
				$name = $node['x-mcp-header'];
				if ( ! $reachable || ! $path || ! is_string( $name ) || ! preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name ) || ! in_array( $node['type'] ?? '', array( 'string', 'integer', 'boolean' ), true ) || isset( $seen[ strtolower( $name ) ] ) ) { throw new RemoteException( 'Invalid MCP schema header annotation.' ); }
				$seen[ strtolower( $name ) ] = true; $paths[] = array( 'name' => $name, 'path' => $path, 'type' => $node['type'] );
			}
			foreach ( $node as $key => $child ) {
				if ( 'properties' === $key && ( is_array( $child ) || $child instanceof \stdClass ) ) { foreach ( $child as $property => $definition ) { $walk( $definition, array_merge( $path, array( $property ) ), $reachable ); } }
				elseif ( is_array( $child ) || $child instanceof \stdClass ) { $walk( $child, $path, false ); }
			}
		};
		$walk( $schema, array(), true ); return $paths;
	}

	public static function headers( $schema, array $arguments ): array {
		$headers = array();
		foreach ( self::paths( $schema ) as $entry ) {
			$value = $arguments;
			foreach ( $entry['path'] as $key ) { $value = is_array( $value ) && array_key_exists( $key, $value ) ? $value[ $key ] : null; }
			if ( null === $value ) { continue; }
			if ( ( 'string' === $entry['type'] && ! is_string( $value ) ) || ( 'boolean' === $entry['type'] && ! is_bool( $value ) ) || ( 'integer' === $entry['type'] && ( ! is_int( $value ) || abs( $value ) > 9007199254740991 ) ) ) { throw new RemoteException( 'Invalid value for an MCP header parameter.', -32602 ); }
			$headers[ 'Mcp-Param-' . $entry['name'] ] = McpClient::header_value( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
		}
		return $headers;
	}
}
