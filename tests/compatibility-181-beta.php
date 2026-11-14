<?php
/** Verify exact reviewed packages and fail closed on altered beta code. */
declare( strict_types=1 );

define( 'GETMCP_EXTENSIONS_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
require_once GETMCP_EXTENSIONS_PATH . 'includes/class-runtime.php';

$beta = getenv( 'GETMCP_BETA_VENDOR_ROOT' );
$stable = getenv( 'GETMCP_STABLE_VENDOR_ROOT' );
if ( ! $beta || ! is_dir( $beta ) || ! $stable || ! is_dir( $stable ) ) {
	fwrite( STDERR, "Set GETMCP_BETA_VENDOR_ROOT and GETMCP_STABLE_VENDOR_ROOT to extracted GetMCP packages.\n" );
	exit( 2 );
}
$beta = rtrim( $beta, '/\\' ) . DIRECTORY_SEPARATOR;
$stable = rtrim( $stable, '/\\' ) . DIRECTORY_SEPARATOR;
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};

$profile = \GetMCPExtensions\Runtime::inspect( $beta );
$assert( 'vendor-original-1.8.1-beta2' === $profile['profile'], 'The reviewed 1.8.1 beta package must be recognized.' );
$assert( array() === $profile['errors'], 'The reviewed beta must not report compatibility errors.' );
$assert( 'compat/versions/1.8.1-beta2/includes/api/class-servers-controller.php' === ( $profile['classes']['GetMCP\\Api\\ServersController'] ?? null ), 'The beta must use its reviewed server controller.' );
$assert( 'compat/versions/1.8.1-beta2/includes/execution/class-tool-executor.php' === ( $profile['classes']['GetMCP\\Execution\\ToolExecutor'] ?? null ), 'The beta must use its reviewed tool executor.' );
$assert( 'vendor-original-1.8.0' === \GetMCPExtensions\Runtime::inspect( $stable )['profile'], 'The original 1.8.0 package must remain supported.' );

$manifest = json_decode( file_get_contents( GETMCP_EXTENSIONS_PATH . 'compatibility.json' ), true, 512, JSON_THROW_ON_ERROR );
$files = $manifest['profiles']['vendor-original-1.8.1-beta2'];
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'getmcp-compat-test-' . bin2hex( random_bytes( 8 ) );
mkdir( $temp, 0700, true );
try {
	foreach ( array_keys( $files ) as $file ) {
		$target = $temp . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file );
		if ( ! is_dir( dirname( $target ) ) ) { mkdir( dirname( $target ), 0700, true ); }
		if ( ! copy( $beta . $file, $target ) ) { throw new RuntimeException( 'Could not prepare isolated beta fixture.' ); }
	}
	$guarded = $temp . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'execution' . DIRECTORY_SEPARATOR . 'class-tool-executor.php';
	file_put_contents( $guarded, "\n// changed fixture\n", FILE_APPEND );
	$altered = \GetMCPExtensions\Runtime::inspect( $temp . DIRECTORY_SEPARATOR );
	$assert( '' === $altered['profile'] && in_array( 'includes/execution/class-tool-executor.php', $altered['errors'], true ), 'Changed beta execution code must be refused.' );
} finally {
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) { $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); }
	rmdir( $temp );
}
echo "Passed {$checks} compatibility assertions.\n";
