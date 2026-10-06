<?php
/** Load feature-only dependencies for the original standalone OAuth fixtures. */
$fixture_root = getenv( 'GETMCP_TEST_ROOT' );
if ( $fixture_root ) {
	foreach ( array( 'class-delegation.php', 'class-feature-handler.php' ) as $file ) {
		$path = $fixture_root . '/includes/gateway/' . $file;
		if ( is_file( $path ) ) { require_once $path; }
	}
}
