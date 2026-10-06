<?php
/** Equivalent management MCP interface; never included in project gateways. @package GetMCP */
namespace GetMCP\Gateway;

class ManagementTool {
	public static function definition(): array {
		return array( 'name' => 'manage_mcp_connections', 'title' => 'Manage remote MCP connections and project gateways', 'description' => 'List, get, save, delete, or preview a remote MCP connection or project gateway. save configures membership and allowed_user_ids; selecting servers delegates their capabilities to every allowed gateway user. Configuration changes require server-management permission and explicit user authorization. Credential fields are write-only. preview also requires endpoint membership.', 'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'operation' => array( 'type' => 'string', 'enum' => array( 'list', 'get', 'save', 'delete', 'preview' ) ),
			'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'kind' => array( 'type' => 'string', 'enum' => array( 'gateway', 'remote-mcp' ) ),
			'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string', 'enum' => array( 'active', 'paused', 'draft' ) ),
			'allowed_user_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'server_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'remote' => array( 'type' => 'object', 'properties' => array( 'endpoint' => array( 'type' => 'string' ), 'auth_mode' => array( 'type' => 'string', 'enum' => array( 'none', 'shared', 'oauth' ) ), 'publish_original' => array( 'type' => 'boolean' ), 'client_id' => array( 'type' => 'string' ), 'scope' => array( 'type' => 'string' ), 'resource_metadata_url' => array( 'type' => 'string' ) ) ),
			'connection_headers' => array( 'type' => 'array', 'maxItems' => 40, 'items' => array( 'type' => 'object', 'required' => array( 'name', 'value' ), 'properties' => array( 'name' => array( 'type' => 'string' ), 'value' => array( 'type' => 'string', 'description' => 'Write-only. Blank preserves an existing header.' ) ) ) ),
			'credentials' => array( 'type' => 'object', 'description' => 'Write-only headers map or client_secret.' ),
		), 'required' => array( 'operation' ) ), 'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true ) );
	}

	public static function call( array $arguments ): array {
		$operation = $arguments['operation'] ?? '';
		$scope = in_array( $operation, array( 'list', 'get', 'preview' ), true ) ? 'mcp:read' : 'mcp:write';
		if ( ! \GetMCP\Auth\McpAuth::has_scope( $scope ) && ! \GetMCP\Auth\McpAuth::has_scope( 'mcp:admin' ) ) { throw new \GetMCP\Remote\RemoteException( 'Insufficient management OAuth scope.', -32003 ); }
		$actor = (int) ( \GetMCP\Auth\McpAuth::current_token_row()->user_id ?? 0 );
		$result = FeatureManager::run( $operation, $arguments, $actor );
		return array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $result ) ) ), 'structuredContent' => $result );
	}
}
