<?php
/** Local synthetic regression for pending-code migration refusal and retry. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION ) { throw new RuntimeException( 'Local 1.8 fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-grant.json' ), true );
global $wpdb;
$servers = $wpdb->prefix . 'getmcp_servers';
$tokens = $wpdb->prefix . 'getmcp_oauth_tokens';
$id = (int) ( $state['id'] ?? 0 );
$stored = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$servers} WHERE id=%d AND slug='qa-180-existing-grant'", $id ), ARRAY_A );
if ( ! $stored ) { throw new RuntimeException( 'Synthetic server required.' ); }
$client = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $state['client'] ) );
if ( ! $client ) { throw new RuntimeException( 'Synthetic client required.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$cases = array();
$make = static function ( string $case, string $resource, ?string $redirect = null, string $expiry = '+10 minutes' ) use ( &$cases, $wpdb, $tokens, $client ): string {
	$hash = hash( 'sha256', 'qa-180-code-' . $case . '-' . wp_generate_uuid4() );
	$ok = $wpdb->insert( $tokens, array( 'client_id' => $client, 'user_id' => 1, 'access_token_hash' => '', 'authorization_code_hash' => $hash, 'resource' => $resource, 'redirect_uri' => $redirect, 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $expiry ) ) ) );
	if ( 1 !== $ok ) { throw new RuntimeException( 'Could not create synthetic code row.' ); }
	$cases[] = array( 'id' => (int) $wpdb->insert_id, 'hash' => $hash );
	return $hash;
};
try {
	$check( false !== $wpdb->update( $servers, array( 'auth_type' => 'oauth' ), array( 'id' => $id, 'slug' => 'qa-180-existing-grant' ) ), 'Synthetic legacy server prepared.' );
	$good = $make( 'bound', $state['base'] );
	$missing = $make( 'missing', $state['base'] );
	$existing = $make( 'existing', $state['base'], 'https://example.org/already-bound' );
	$other = $make( 'other-resource', 'https://example.org/other' );
	$expired = $make( 'expired', $state['base'], null, '-1 minute' );
	foreach ( array( $good, $existing, $other, $expired ) as $hash ) { set_transient( 'getmcp_code_redirect_' . $hash, 'https://example.org/exact-callback', 600 ); }
	\GetMCPExtensions\Upgrade180Codes::maybe_migrate();
	$redirect = static fn( string $hash ) => $wpdb->get_var( $wpdb->prepare( "SELECT redirect_uri FROM {$tokens} WHERE authorization_code_hash=%s", $hash ) );
	$check( 'https://example.org/exact-callback' === $redirect( $good ), 'Exact saved binding migrated.' );
	$check( null === $redirect( $missing ), 'Missing transient is not guessed.' );
	$check( 'https://example.org/already-bound' === $redirect( $existing ), 'Existing binding is not overwritten.' );
	$check( null === $redirect( $other ), 'Other resource is untouched.' );
	$check( null === $redirect( $expired ), 'Expired code is untouched.' );
	\GetMCPExtensions\Upgrade180Codes::maybe_migrate();
	$check( 'https://example.org/exact-callback' === $redirect( $good ), 'Retry is idempotent.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic pending authorization codes' ) ) . PHP_EOL;
} finally {
	foreach ( $cases as $case ) {
		$wpdb->delete( $tokens, array( 'id' => $case['id'], 'authorization_code_hash' => $case['hash'] ) );
		delete_transient( 'getmcp_code_redirect_' . $case['hash'] );
	}
	$wpdb->update( $servers, array( 'auth_type' => $stored['auth_type'], 'updated_at' => $stored['updated_at'] ), array( 'id' => $id, 'slug' => 'qa-180-existing-grant' ) );
}
