<?php
/** Optional marketing features and permission-checked configuration interfaces. */
namespace GetMCPExtensions;
use GetMCP\Core\ServerManager;
use GetMCP\Remote\RemoteException;

final class MarketingModule {
    public static function enabled(): bool { $s = get_option( 'getmcp_extensions_marketing_module', array( 'enabled' => true ) ); return Runtime::ready() && ! empty( $s['enabled'] ); }
    public static function boot(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ), 40 );
        add_action( 'template_redirect', array( self::class, 'callback' ), -20 );
        add_action( 'getmcp_upstream_disconnected', static fn( $server, $user ) => PageTokens::forget( $server, 'user:' . $user ), 10, 2 );
        add_action( 'getmcp_server_deleted', static fn( $server ) => PageTokens::cleanup( $server ) );
        add_action( 'deleted_user', static fn( $user ) => PageTokens::cleanup( null, $user ) );
        add_action( 'getmcp_daily_cleanup', array( self::class, 'cleanup' ) );
        add_filter( 'rest_post_dispatch', array( self::class, 'public_response' ), 10, 3 );
    }
    public static function response( callable $callback ) {
        try { if ( ! self::enabled() ) { throw new RemoteException( 'Marketing extensions are disabled.', -32003 ); } return rest_ensure_response( $callback() ); }
        catch ( SettingsConflict $e ) { return new \WP_Error( 'getmcp_settings_conflict', $e->getMessage(), array( 'status' => 409 ) ); }
        catch ( RemoteException $e ) { return new \WP_Error( 'getmcp_provider_error', $e->getMessage(), array( 'status' => -32003 === $e->get_rpc_code() ? 403 : 400 ) ); }
        catch ( \Throwable $e ) { return new \WP_Error( 'getmcp_provider_invalid', 'The configuration or connection operation could not be completed. Reload and check the supplied fields.', array( 'status' => 400 ) ); }
    }
    public static function routes(): void {
        $manage = static fn() => current_user_can( 'getmcp_manage_servers' );
        $personal = static fn() => is_user_logged_in() && current_user_can( 'read' );
        register_rest_route( 'getmcp-extensions/v1', '/marketing', array(
            array( 'methods' => 'GET', 'permission_callback' => fn() => current_user_can( 'manage_options' ), 'callback' => fn() => rest_ensure_response( get_option( 'getmcp_extensions_marketing_module', array( 'revision' => 0, 'enabled' => true ) ) ) ),
            array( 'methods' => 'PUT', 'permission_callback' => fn() => current_user_can( 'manage_options' ), 'callback' => function( $r ) {
                global $wpdb; $old = get_option( 'getmcp_extensions_marketing_module', false ); $current = $old ?: array( 'revision' => 0, 'enabled' => true ); $data = $r->get_json_params();
                if ( ! is_bool( $data['enabled'] ?? null ) || ! is_int( $data['revision'] ?? null ) ) { return new \WP_Error( 'invalid_module', 'Supply enabled and revision.', array( 'status' => 400 ) ); }
                if ( $current['revision'] !== $data['revision'] ) { return new \WP_Error( 'module_conflict', 'Reload module settings before saving.', array( 'status' => 409 ) ); }
                $next = array( 'revision' => $current['revision'] + 1, 'enabled' => $data['enabled'] );
                $ok = false === $old ? add_option( 'getmcp_extensions_marketing_module', $next, '', false ) : 1 === $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $next ) ), array( 'option_name' => 'getmcp_extensions_marketing_module', 'option_value' => maybe_serialize( $old ) ) ); wp_cache_delete( 'getmcp_extensions_marketing_module', 'options' );
                return $ok ? rest_ensure_response( $next ) : new \WP_Error( 'module_conflict', 'Reload before saving.', array( 'status' => 409 ) );
            } ),
        ) );
        register_rest_route( 'getmcp/v1', '/connections/import-preview', array( 'methods' => 'POST', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => ConfigurationImport::preview( (string) $r->get_param( 'document' ), get_current_user_id() ) ) ) );
        register_rest_route( 'getmcp/v1', '/connections/import-confirm', array( 'methods' => 'POST', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => ConfigurationImport::commit( $r->get_json_params() ?: array(), get_current_user_id() ) ) ) );
        register_rest_route( 'getmcp/v1', '/provider-presets', array( 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => fn() => self::response( fn() => ProviderDiscovery::presets() ) ) );
        register_rest_route( 'getmcp/v1', '/servers/(?P<uuid>[a-f0-9-]{36})/provider-configuration', array(
            array( 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => self::configuration( $r['uuid'] ) ) ),
            array( 'methods' => 'PUT', 'permission_callback' => $manage, 'callback' => fn( $r ) => self::response( fn() => self::configure( $r['uuid'], $r->get_json_params() ?: array() ) ) ),
        ) );
        register_rest_route( 'getmcp/v1', '/my-connections/(?P<id>\d+)/assets', array( 'methods' => 'GET, POST, PUT', 'permission_callback' => $personal, 'callback' => fn( $r ) => self::response( function() use ( $r ) {
            $server = ( new ServerManager() )->get( (int) $r['id'] ); $g = ProviderConnections::grant( $server, get_current_user_id() );
            return self::assets( $r, $server, 'user:' . get_current_user_id(), $g['data']['access_token'] );
        } ) ) );
        register_rest_route( 'getmcp/v1', '/my-connections/(?P<id>\d+)/discover', array( 'methods' => 'POST', 'permission_callback' => $personal, 'callback' => fn( $r ) => self::response( function() use ( $r ) {
            $server = ( new ServerManager() )->get( (int) $r['id'] ); $g = ProviderConnections::grant( $server, get_current_user_id() );
            $result = ProviderDiscovery::discover( $server, $g['data']['access_token'], get_current_user_id() );
            $live = ( new ServerManager() )->get( $server->id );
            if ( ! ProviderConnections::can_connect( $live, get_current_user_id() ) || ProviderConnections::fingerprint( $live ) !== $g['data']['config_hash'] ) { throw new RemoteException( 'Provider access changed during discovery.', -32003 ); }
            $d = $g['data']; $d['readiness'] = array( 'read_verified' => true, 'checked_at' => time(), 'discovery_complete' => $result['complete'], 'reporting_verified' => false );
            \GetMCP\Remote\UpstreamConnections::save( $server->id, get_current_user_id(), $d, $g['version'] );
            return $result;
        } ) ) );
        register_rest_route( 'getmcp/v1', '/servers/(?P<uuid>[a-f0-9-]{36})/enable-messaging', array( 'methods' => 'POST', 'permission_callback' => fn() => current_user_can( 'getmcp_manage_servers' ) && current_user_can( 'getmcp_manage_tools' ), 'callback' => fn( $r ) => self::response( function() use ( $r ) {
            $s = ( new ServerManager() )->get_by_uuid( $r['uuid'] ); $g = ProviderConnections::grant( $s, get_current_user_id() );
            if ( ! PageTokens::present( $s, 'user:' . get_current_user_id(), $g['data']['access_token'] )['messaging_verified'] ) { throw new RemoteException( 'Verify the selected Page with a harmless conversation read before enabling messaging.', -32003 ); }
            $tm = new \GetMCP\Core\ToolManager(); $tools = $tm->get_by_server( $s->id, array( 'per_page' => 100 ) ); $found = array();
            foreach ( $tools['items'] as $t ) { if ( in_array( $t->slug, array( 'get_conversations', 'get_conversation_messages', 'send_dm' ), true ) ) { $found[$t->slug] = $t; } }
            if ( count( $found ) !== 3 ) { throw new RemoteException( 'The three reviewed messaging definitions must exist; no replacement tools were created.' ); }
            $base = ProviderTokens::graph_base( $s );
            foreach ( $found as $slug => $t ) {
                $expected = $base . ( 'get_conversation_messages' === $slug ? '/{{conversation_id}}' : '/{{page_id}}/' . ( 'send_dm' === $slug ? 'messages' : 'conversations' ) );
                if ( explode( '?', $t->endpoint_url, 2 )[0] !== $expected || $t->http_method !== ( 'send_dm' === $slug ? 'POST' : 'GET' ) ) { throw new RemoteException( 'Review the existing messaging endpoint definitions before activation.' ); }
            }
            $settings = AuthenticationSettings::object( $s->settings ); $settings['getmcp_extensions_required'] = true;
            foreach ( $found as $slug => $tool ) { $settings['provider_token_sources'][$slug] = 'page'; }
            if ( ! ( new ServerManager() )->update( $s->id, array( 'settings' => wp_json_encode( $settings ) ) ) ) { throw new RemoteException( 'Could not preserve the endpoint guard.' ); }
            foreach ( $found as $tool ) { if ( ! $tm->update( $tool->id, array( 'status' => 'active', 'retry_count' => 0, 'cache_ttl' => 0 ) ) ) { throw new RemoteException( 'Messaging activation stopped. Review the remaining tool statuses.' ); } }
            return array( 'enabled' => array_keys( $found ), 'per_connection_verification_required' => true, 'live_message_sent' => false );
        } ) ) );
        register_rest_route( 'getmcp/v1', '/provider-tools/(?P<id>\d+)', array( 'methods' => 'GET, PUT', 'permission_callback' => fn() => current_user_can( 'getmcp_manage_servers' ) && current_user_can( 'getmcp_manage_tools' ), 'callback' => fn( $r ) => self::response( function() use ( $r ) {
            $m = new \GetMCP\Core\ToolManager(); $t = $m->get( (int) $r['id'] ); if ( ! $t ) { throw new \InvalidArgumentException( 'Tool not found.' ); }
            $revision = hash_hmac( 'sha256', wp_json_encode( $t->to_array() ), wp_salt( 'auth' ) );
            if ( 'PUT' === $r->get_method() ) {
                $a = $r->get_json_params() ?: array();
                if ( ! isset( $a['revision'] ) || ! hash_equals( $revision, $a['revision'] ) ) { throw new SettingsConflict( 'Tool changed. Reload before saving.' ); }
                $fields = array( 'body_template', 'annotations', 'retry_count', 'retry_backoff', 'headers' );
                if ( array_diff( array_keys( $a ), array_merge( $fields, array( 'revision' ) ) ) ) { throw new \InvalidArgumentException( 'Unsupported tool field.' ); }
                $data = array_intersect_key( $a, array_flip( $fields ) );
                foreach ( array( 'body_template', 'annotations', 'headers' ) as $key ) { if ( isset( $data[$key] ) ) { $data[$key] = AuthenticationSettings::object( $data[$key] ); } }
                if ( isset( $data['headers'] ) ) { \GetMCP\Remote\SafeHttp::validate_headers( $data['headers'] ); foreach ( array_keys( $data['headers'] ) as $name ) { if ( preg_match( '/authorization|cookie|token|api.key|secret/i', $name ) ) { throw new \InvalidArgumentException( 'Put credential headers in encrypted connection credentials.' ); } } }
                if ( isset( $data['retry_count'] ) && ( ! is_int( $data['retry_count'] ) || $data['retry_count'] < 0 || $data['retry_count'] > 3 ) ) { throw new \InvalidArgumentException( 'Retry count must be between zero and three.' ); }
                if ( isset( $data['retry_backoff'] ) && ! in_array( $data['retry_backoff'], array( 'none', 'linear', 'exponential' ), true ) ) { throw new \InvalidArgumentException( 'Unsupported retry policy.' ); }
                global $wpdb; $wpdb->query( 'START TRANSACTION' );
                try {
                    $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_tools WHERE id = %d FOR UPDATE", $t->id ) );
                    $fresh = $m->get( $t->id );
                    if ( ! $fresh || ! hash_equals( $revision, hash_hmac( 'sha256', wp_json_encode( $fresh->to_array() ), wp_salt( 'auth' ) ) ) ) { throw new SettingsConflict( 'Tool changed during save.' ); }
                    $t = $m->update( $t->id, $data ); if ( ! $t ) { throw new \RuntimeException( 'Could not save tool.' ); }
                    $wpdb->query( 'COMMIT' );
                } catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
            }
            return array( 'tool' => array_intersect_key( $t->to_array(), array_flip( array( 'id', 'slug', 'body_template', 'annotations', 'retry_count', 'retry_backoff' ) ) ) + array( 'header_names' => array_keys( AuthenticationSettings::object( $t->headers ) ) ), 'revision' => hash_hmac( 'sha256', wp_json_encode( $t->to_array() ), wp_salt( 'auth' ) ) );
        } ) ) );
        // Existing client-driven broker identities get their own vault. They are never mapped to a browser user.
        register_rest_route( 'getmcp/v1', '/provider-assets/(?P<id>\d+)', array( 'methods' => 'GET, POST, PUT', 'permission_callback' => static fn() => true, 'callback' => fn( $r ) => self::response( function() use ( $r ) {
            $server = ( new ServerManager() )->get( (int) $r['id'] );
            $header = (string) $r->get_header( 'authorization' );
            if ( ! $server || 'active' !== $server->status || ! preg_match( '/^Bearer ([^\s]+)$/D', $header, $m ) ) { throw new RemoteException( 'Authenticate this provider connection.', -32003 ); }
            $row = \GetMCP\Auth\OAuthProvider::validate_and_extract_row( $m[1], $server );
            if ( ! $row || (int) ( $row->user_id ?? 0 ) !== 0 || (int) $row->server_id !== $server->id || empty( $row->upstream_access_token ) || strtotime( $row->upstream_expires_at . ' UTC' ) < time() + 30 ) { throw new RemoteException( 'This broker identity is expired or belongs to another server.', -32003 ); }
            if ( ! \GetMCP\Auth\RateLimiter::allow_identifier( 'provider-assets-' . $row->id, 20 ) ) { throw new RemoteException( 'Connection operation rate limit reached.' ); }
            return self::assets( $r, $server, 'broker:' . $row->id, \GetMCP\Utils\Encryption::decrypt_strict( $row->upstream_access_token ) );
        } ) ) );
    }
    private static function assets( $r, $server, string $identity, string $token ): array {
        if ( 'GET' === $r->get_method() ) { return PageTokens::present( $server, $identity, $token ); }
        if ( 'PUT' === $r->get_method() ) { return PageTokens::select( $server, $identity, $token, (string) $r->get_param( 'page_id' ), (string) $r->get_param( 'instagram_id' ) ); }
        return 'verify' === $r->get_param( 'operation' ) ? PageTokens::verify( $server, $identity, $token ) : PageTokens::discover( $server, $identity, $token );
    }
    public static function configuration( string $uuid ): array {
        $s = ( new ServerManager() )->get_by_uuid( $uuid ); if ( ! $s ) { throw new \InvalidArgumentException( 'Server not found.' ); }
        $settings = AuthenticationSettings::object( $s->settings );
        return AuthenticationSettings::public_credentials( $s ) + array( 'personal_provider' => $settings['personal_provider'] ?? array( 'allowed_user_ids' => array() ), 'token_sources' => $settings['provider_token_sources'] ?? array(), 'configuration_revision' => self::configuration_revision( $s ) );
    }
    public static function configuration_revision( $s ): string { return hash_hmac( 'sha256', AuthenticationSettings::revision( $s ) . '|' . $s->settings, wp_salt( 'auth' ) ); }
    public static function configure( string $uuid, array $args ): array {
        $manager = new ServerManager(); $s = $manager->get_by_uuid( $uuid );
        if ( ! $s || ! isset( $args['configuration_revision'] ) || ! hash_equals( self::configuration_revision( $s ), (string) $args['configuration_revision'] ) ) { throw new SettingsConflict( 'Provider settings changed. Reload before saving.' ); }
        if ( 'native' !== $s->server_kind || array_diff( array_keys( $args ), array( 'configuration_revision', 'auth_config', 'auth_type', 'auth_credentials', 'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials', 'auth_config_mode', 'clear_credentials', 'personal_provider', 'token_sources' ) ) ) { throw new \InvalidArgumentException( 'Use the connection manager for remote servers and gateways; supply only supported provider fields.' ); }
        foreach ( array( 'auth_type', 'outbound_auth_type' ) as $field ) { if ( isset( $args[$field] ) && ! in_array( $args[$field], array( 'none', 'api_key', 'bearer', 'basic', 'oauth' ), true ) ) { throw new \InvalidArgumentException( 'Unsupported authentication type.' ); } }
        $settings = AuthenticationSettings::object( $s->settings ); $data = array();
        foreach ( array( 'auth_config', 'auth_type', 'auth_credentials', 'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials' ) as $field ) { if ( array_key_exists( $field, $args ) ) { $data[$field] = $args[$field]; } }
        foreach ( array( 'auth_config_mode' => '_auth_config_mode', 'clear_credentials' => '_clear_credentials' ) as $a => $b ) { if ( isset( $args[$a] ) ) { $data[$b] = $args[$a]; } }
        if ( isset( $args['personal_provider'] ) ) {
            $p = AuthenticationSettings::object( $args['personal_provider'] );
            $ids = \GetMCP\Auth\OAuthUserAccess::validate_ids( $p['allowed_user_ids'] ?? array() );
            if ( is_wp_error( $ids ) || array_diff( array_keys( $p ), array( 'allowed_user_ids', 'preset' ) ) ) { throw new \InvalidArgumentException( 'Invalid personal connection allowlist.' ); }
            if ( isset( $p['preset'] ) && ! array_key_exists( $p['preset'], ProviderDiscovery::presets() ) ) { throw new \InvalidArgumentException( 'Unknown provider preset.' ); }
            $settings['personal_provider'] = $p;
        }
        if ( isset( $args['token_sources'] ) ) {
            $sources = AuthenticationSettings::object( $args['token_sources'] );
            foreach ( $sources as $slug => $source ) { if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $slug ) || ! in_array( $source, array( 'user', 'page' ), true ) ) { throw new \InvalidArgumentException( 'Invalid token source.' ); } }
            $settings['provider_token_sources'] = $sources;
            if ( in_array( 'page', $sources, true ) ) { $settings['getmcp_extensions_required'] = true; }
        }
        $data['settings'] = wp_json_encode( $settings ); $data['_authentication_revision'] = AuthenticationSettings::revision( $s ); $data['_configuration_revision'] = $args['configuration_revision'];
        $saved = $manager->update( $s->id, $data ); if ( ! $saved ) { throw new \RuntimeException( 'Could not save settings.' ); }
        return self::configuration( $uuid );
    }
    public static function public_response( $response, $service, $request ) {
        if ( ! $response instanceof \WP_REST_Response || $response->get_status() >= 400 ) { return $response; }
        $redact = static function( array $item ): array {
            foreach ( array( 'auth_config', 'outbound_auth_config', 'test_auth_config' ) as $field ) { if ( isset( $item[$field] ) ) { $clean = AuthenticationSettings::public_config( $item[$field] ); $item[$field] = is_string( $item[$field] ) ? wp_json_encode( $clean ) : $clean; } }
            return $item;
        };
        if ( '/getmcp/v1/servers' === $request->get_route() && is_array( $response->get_data() ) ) { $data = $response->get_data(); $response->set_data( array_is_list( $data ) ? array_map( $redact, $data ) : $redact( $data ) ); }
        if ( preg_match( '#^/getmcp/v1/servers/([a-f0-9-]{36})(?:/credentials)?$#D', $request->get_route(), $m ) ) {
            $s = ( new ServerManager() )->get_by_uuid( $m[1] );
            if ( $s ) { $d = $redact( $response->get_data() ); $d['authentication_revision'] = AuthenticationSettings::revision( $s ); $response->set_data( $d ); }
        }
        return $response;
    }
    public static function callback(): void {
        $query = array(); wp_parse_str( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), $query );
        if ( ! is_string( $query['state'] ?? null ) || ! str_starts_with( $query['state'], 'gxp_' ) ) { return; }
        if ( ! is_user_logged_in() ) { wp_safe_redirect( wp_login_url( home_url( $_SERVER['REQUEST_URI'] ) ) ); exit; }
        try { if ( ! self::enabled() ) { throw new RemoteException( 'Provider connections are disabled.' ); } ProviderConnections::complete( $query, get_current_user_id(), (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ); wp_safe_redirect( \GetMCP\Remote\UpstreamConnections::portal_url() ); exit; }
        catch ( \Throwable $e ) { wp_die( 'The provider connection could not be completed. Start again from My MCP Connections.', 'MCP Connection', array( 'response' => 400 ) ); }
    }
    public static function cleanup(): void {
        global $wpdb; PageTokens::cleanup();
        foreach ( array( 'getmcp_provider_state_', 'getmcp_import_preview_' ) as $prefix ) {
            $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000", $wpdb->esc_like( $prefix ) . '%' ) );
            foreach ( $names as $name ) { try { $d = json_decode( \GetMCP\Utils\Encryption::decrypt_strict( get_option( $name ) ), true ); if ( (int) ( $d['expires_at'] ?? 0 ) >= time() ) { continue; } } catch ( \Throwable $e ) {} delete_option( $name ); }
        }
    }
}
