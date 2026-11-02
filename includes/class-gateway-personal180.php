<?php
/** Restore per-user provider grants to the OAuth account Gateway. */
namespace GetMCPExtensions;

final class GatewayPersonal180 {
/** @wp-test feature=upgrade.180 scenario=builtin-personal-grant */
/** @wp-test feature=upgrade.180 scenario=builtin-personal-refusal */
public static function members( array $included ): array {
 $gateway = \GetMCP\Gateway\McpGateway::class;
 if ( ! Runtime::ready() || ! LegacyBridge::is_180() || ! $gateway::is_enabled() || 'oauth' !== $gateway::auth_type() ) { return $included; }
 $row = \GetMCP\Auth\McpAuth::current_token_row(); $user = (int) ( $row->user_id ?? 0 ); $client = $row->client_server_id ?? null;
 if ( $user < 1 || ! get_userdata( $user ) || ! user_can( $user, 'read' ) || ( ! is_int( $client ) && ! is_string( $client ) ) || ! ctype_digit( (string) $client ) || ! in_array( (int) $client, array( 0, $gateway::SERVER_ID ), true ) || ! is_string( $row->resource ?? null ) || \GetMCP\Auth\OAuthProvider::canonicalize_resource( $row->resource ) !== \GetMCP\Auth\OAuthProvider::canonicalize_resource( $gateway::resource() ) ) { return $included; }
 $selected = $gateway::selected_server_ids(); $candidates = array();
 foreach ( ( new \GetMCP\Core\ServerManager() )->list_all( array( 'status' => 'active', 'orderby' => 'name', 'order' => 'ASC' ) ) as $server ) {
			if ( ( 'selected' !== $gateway::mode() || in_array( (int) $server->id, $selected, true ) ) && PublicGateway180::permits( $server, $user ) ) { $candidates[(int) $server->id] = $server; continue; }
  if ( LegacyBridge::kind( $server ) !== 'native' || $server->is_bundle() || $server->is_remote() || \GetMCP\Builtin\BuiltinServer::slug_is_reserved( $server->slug ) || ! Runtime::server_enabled( $server ) || ( 'selected' === $gateway::mode() && ! in_array( (int) $server->id, $selected, true ) ) || ! ProviderConnections::can_connect( $server, $user ) ) { continue; }
  $spec = array( 'id' => $server->id, 'kind' => 'standard', 'auth_type' => $server->auth_type, 'settings' => $server->settings );
  if ( ! Upgrade180Auto::safe_personal_provider_member( $spec ) ) { continue; }
  try { $grant = \GetMCP\Remote\UpstreamConnections::get( $server->id, $user ); } catch ( \Throwable $e ) { continue; }
  $data = $grant['data'] ?? array();
  if ( ! is_array( $data ) || 'rest-provider' !== ( $data['kind'] ?? '' ) || ! is_string( $data['config_hash'] ?? null ) || ! hash_equals( ProviderConnections::fingerprint( $server ), $data['config_hash'] ) || empty( $data['access_token'] ) || ( (int) ( $data['expires_at'] ?? 0 ) < time() + 30 && ! ProviderConnections::refresh_available( $server, $data ) ) ) { continue; }
  $candidates[(int) $server->id] = $server;
 }
 // Respect existing publication filters without allowing a filter to inject an unchecked server.
 $filtered = apply_filters( 'getmcp_gateway_servers', array_values( $candidates ) );
 if ( ! is_array( $filtered ) ) { return $included; }
 $known = array_fill_keys( array_map( static fn( $s ) => (int) $s->id, $included ), true );
 foreach ( $filtered as $server ) { if ( $server instanceof \GetMCP\Core\Server && isset( $candidates[(int) $server->id] ) && ! isset( $known[(int) $server->id] ) ) { $included[] = $candidates[(int) $server->id]; $known[(int) $server->id] = true; } }
 return $included;
}
}
