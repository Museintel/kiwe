<?php
declare(strict_types=1);
require __DIR__ . '/private-operator-receipt.php';

$fixture = sys_get_temp_dir() . '/kiwe-private-receipt-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture, 0700 );
mkdir( $fixture . '/private', 0700 );
mkdir( $fixture . '/public_html', 0700 );
define( 'KIWE_RECEIPT_TEST_DIRECTORY', str_replace( '\\', '/', realpath( $fixture . '/private' ) ) );
$checks = 0;
$check  = static function ( bool $ok ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'Private receipt contract failed.' );
	}
	++$checks;
};
try {
	// Reproduce a bootstrap include overwriting the operator's generic global.
	$file = $fixture . '/private/target.json';
	file_put_contents( $fixture . '/bootstrap.php', '<?php $file = __DIR__ . "/public_html/system.php";' );
	file_put_contents( $fixture . '/public_html/system.php', '<?php /* exact original */' );
	require $fixture . '/bootstrap.php';
	$bytes = '{"passed":true}';
	$path  = Kiwe_Private_Operator_Receipt::write( KIWE_RECEIPT_TEST_DIRECTORY, 'target.json', $bytes );
	$check( file_get_contents( $path ) === $bytes );
	$check( file_get_contents( $file ) === '<?php /* exact original */' );
	$check( Kiwe_Private_Operator_Receipt::write( KIWE_RECEIPT_TEST_DIRECTORY, 'target.json', $bytes ) === $path );
	foreach ( [
		[ KIWE_RECEIPT_TEST_DIRECTORY, '../system.json', $bytes ],
		[ str_replace( '\\', '/', realpath( $fixture . '/public_html' ) ), 'target.json', $bytes ],
		[ KIWE_RECEIPT_TEST_DIRECTORY, 'target.json', '{"changed":true}' ],
		[ KIWE_RECEIPT_TEST_DIRECTORY . '/..', 'target.json', $bytes ],
	] as $args ) {
		$refused = false;
		try {
			Kiwe_Private_Operator_Receipt::write( ...$args );
		} catch ( RuntimeException $error ) {
			$refused = true;
		}
		$check( $refused );
	}
	$check( file_get_contents( $path ) === $bytes );
	$check( file_get_contents( $file ) === '<?php /* exact original */' );
	echo json_encode( [ 'passed' => true, 'checks' => $checks, 'bootstrapCollisionContained' => true ] ) . PHP_EOL;
} finally {
	// Exact, freshly owned fixture files; never a recursive deletion.
	foreach ( [ '/private/target.json', '/public_html/system.php', '/bootstrap.php' ] as $suffix ) {
		if ( is_file( $fixture . $suffix ) ) {
			unlink( $fixture . $suffix );
		}
	}
	rmdir( $fixture . '/private' );
	rmdir( $fixture . '/public_html' );
	rmdir( $fixture );
}
