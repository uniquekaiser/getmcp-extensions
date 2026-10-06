<?php
/** Management-only MCP interface, excluded from every project gateway. */
namespace GetMCPExtensions;
final class MarketingManagementTool {
    private static function configuration_schema(): array {
        $text = array( 'type' => 'string' );
        $object = array( 'type' => 'object' );
        return array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'configuration_revision' => $text,
            'auth_type' => array( 'type' => 'string', 'enum' => array( 'none', 'api_key', 'bearer', 'basic', 'oauth' ) ),
            'auth_config' => $object + array( 'description' => 'Partial merge, including extra_authorize_params. Null removes a leaf; secrets are forbidden.' ),
            'auth_config_mode' => array( 'type' => 'string', 'enum' => array( 'merge', 'replace' ) ),
            'clear_credentials' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => AuthenticationSettings::CREDENTIALS ) ),
            'auth_credentials' => $object + array( 'description' => 'Write-only encrypted application credentials; prefer private user entry.' ),
            'outbound_auth_type' => $text, 'outbound_auth_config' => $object, 'outbound_auth_credentials' => $object,
            'personal_provider' => $object + array( 'additionalProperties' => false, 'properties' => array( 'allowed_user_ids' => array( 'type' => 'array', 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'preset' => $text ) ),
            'token_sources' => $object + array( 'additionalProperties' => array( 'type' => 'string', 'enum' => array( 'user', 'page' ) ) ),
            'revision' => $text, 'body_template' => array( 'type' => array( 'object', 'null' ) ),
            'annotations' => $object, 'headers' => $object + array( 'additionalProperties' => $text, 'description' => 'Static noncredential headers. Store credentials at connection level.' ),
            'retry_count' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 3 ),
            'retry_backoff' => array( 'type' => 'string', 'enum' => array( 'none', 'linear', 'exponential' ) ),
        ) );
    }
    public static function definition(): array {
        return array( 'name' => 'manage_provider_configuration', 'title' => 'Manage provider configuration', 'description' => 'Read or update advanced authentication, explicit personal-connection users, token sources, or native tool templates/annotations/retry fields. Read the configuration revision first. Credentials are write-only; never put secrets in auth_config. Existing provider grants, allowlists and Gateway routing are retained unless explicitly changed.', 'inputSchema' => array( 'type' => 'object', 'required' => array( 'operation' ), 'properties' => array( 'operation' => array( 'type' => 'string', 'enum' => array( 'read', 'update', 'read_tool', 'update_tool', 'presets' ) ), 'uuid' => array( 'type' => 'string' ), 'tool_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'configuration' => self::configuration_schema() ) ), 'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false ) );
    }
    public static function call( array $a ): array {
        $actor = (int) ( \GetMCP\Auth\McpAuth::current_token_row()->user_id ?? 0 );
        $op = $a['operation'] ?? ''; $read = in_array( $op, array( 'read', 'read_tool', 'presets' ), true );
        if ( ! $actor || ! user_can( $actor, 'getmcp_manage_servers' ) || ( ! \GetMCP\Auth\McpAuth::has_scope( $read ? 'mcp:read' : 'mcp:write' ) && ! \GetMCP\Auth\McpAuth::has_scope( 'mcp:admin' ) ) ) { throw new \GetMCP\Remote\RemoteException( 'Insufficient provider management permission or scope.', -32003 ); }
        if ( ! MarketingModule::enabled() ) { throw new \GetMCP\Remote\RemoteException( 'Marketing extensions are disabled.', -32003 ); }
        if ( in_array( $op, array( 'read_tool', 'update_tool' ), true ) ) {
            $r = new \WP_REST_Request( $read ? 'GET' : 'PUT', '/getmcp/v1/provider-tools/' . (int) ( $a['tool_id'] ?? 0 ) );
            if ( ! $read ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $a['configuration'] ?? array() ) ); }
            $old = get_current_user_id(); wp_set_current_user( $actor );
            try { $response = rest_do_request( $r ); if ( $response->get_status() >= 400 ) { throw new \GetMCP\Remote\RemoteException( 'The tool configuration update was rejected. Reload its revision and validate the fields.' ); } $result = $response->get_data(); }
            finally { wp_set_current_user( $old ); }
        } elseif ( 'presets' === $op ) { $result = ProviderDiscovery::presets(); }
        elseif ( 'read' === $op ) { $result = MarketingModule::configuration( (string) ( $a['uuid'] ?? '' ) ); }
        elseif ( 'update' === $op ) { $result = MarketingModule::configure( (string) ( $a['uuid'] ?? '' ), $a['configuration'] ?? array() ); }
        else { throw new \InvalidArgumentException( 'Unknown provider management operation.' ); }
        return array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $result ) ) ), 'structuredContent' => $result );
    }
}
