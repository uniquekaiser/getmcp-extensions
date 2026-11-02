<?php
/** Disposable synthetic provider for the isolated browser fixture. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_UI' ) ) { throw new RuntimeException( 'Isolated 1.8 UI fixture required.' ); }
$manager = new \GetMCP\Core\ServerManager();
if ( $manager->get_by_slug( 'qa-180-browser-provider' ) ) { throw new RuntimeException( 'Synthetic provider already exists.' ); }
$server = $manager->create( array( 'name' => 'QA 180 Browser Provider', 'slug' => 'qa-180-browser-provider', 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'client_id' => 'synthetic-client' ) ), 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'synthetic-client-secret' ) ), 'settings' => '{}' ) );
if ( ! $server || 'qa-180-browser-provider' !== $server->slug ) { throw new RuntimeException( 'Could not create synthetic provider.' ); }
echo wp_json_encode( array( 'created' => true, 'uuid' => $server->uuid ) ) . PHP_EOL;
