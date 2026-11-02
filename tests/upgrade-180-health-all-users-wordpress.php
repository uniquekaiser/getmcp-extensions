<?php
/** Disposable Health migration policy checks. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager(); $data = \GetMCPExtensions\GoogleHealth::asset()['server'];
$data['name'] = 'QA 180 health policy'; $data['slug'] = 'qa-health-policy-' . substr( wp_generate_uuid4(), 0, 8 ); $data['status'] = 'active'; $data['auth_config']['client_id'] = 'synthetic-health-client'; $data['auth_config'] = wp_json_encode( $data['auth_config'] );
$data['settings']['personal_provider'] = array( 'enabled' => true, 'preset' => 'google-health', 'allowed_user_ids' => array(), 'allow_all_users' => true ); $data['settings'] = wp_json_encode( $data['settings'] );
$server = $manager->create( $data ); if ( ! $server ) { throw new RuntimeException( 'Fixture creation failed.' ); }
try {
 $method = new ReflectionMethod( \GetMCPExtensions\Upgrade180Auto::class, 'safe_health_member' );
 $row = array( 'id' => $server->id, 'kind' => 'standard', 'auth_type' => 'oauth', 'settings' => $server->settings );
 if ( ! $method->invoke( null, $row ) ) { throw new RuntimeException( 'Boolean all-users health policy must migrate.' ); }
 $bad = json_decode( $server->settings, true ); $bad['personal_provider']['allow_all_users'] = 'yes'; $row['settings'] = wp_json_encode( $bad );
 if ( $method->invoke( null, $row ) ) { throw new RuntimeException( 'Malformed health policy must be refused.' ); }
 echo wp_json_encode( array( 'passed' => 2 ) ) . PHP_EOL;
} finally { $current = $manager->get( $server->id ); if ( $current && $current->slug === $data['slug'] ) { $manager->delete( $server->id ); } }
