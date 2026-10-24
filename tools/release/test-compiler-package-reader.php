<?php
/** Independent PHP consumer of a real compiler archive; no WordPress or test ZIP emulation. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Package_Error.php';
require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Package_Reader.php';
use DSA\Bricks\Compiler_Package_Reader as Reader;
$source = $argv[1] ?? ''; $checks = 0; $own_source = false;
if ( ! $source ) { require __DIR__ . '/fixtures/compiler-package.php'; $source = tempnam( sys_get_temp_dir(),'seam-source-' ); kiwe_test_compiler_package( $source ); $own_source = true; }
register_shutdown_function( static function() use ( $source,$own_source ): void { if ( $own_source && is_file( $source ) ) unlink( $source ); } );
$check = static function( bool $ok,string $name ) use ( &$checks ): void { if ( ! $ok ) throw new RuntimeException( $name ); $checks++; };
$proof = ( new Reader( $source ) )->inspect();
$check( 1 === count( $proof['assets'] ) && 2 === count( $proof['bindings'] ),'Actual compiler archive deduplication and both consumers' );
$check( [] === $proof['blockers'] && false === $proof['nativeImportReady'],'Native checks are independent and cannot certify import' );
$check( false === Reader::safe_path( '../outside.json' ) && false === Reader::safe_path( str_repeat( 'x',241 ) ),'Path and size bounds' );
$tmp = tempnam( sys_get_temp_dir(),'seam-reader-' );
try {
	$cases = [
		'tamper' => static function( ZipArchive $zip ): void { $zip->addFromString( 'home.json','{}' ); },
		'path' => static function( ZipArchive $zip ): void { $zip->addFromString( '../outside.json','{}' ); },
		'unlisted' => static function( ZipArchive $zip ): void { $zip->addFromString( 'extra.json','{}' ); },
		'forged-consumer' => static function( ZipArchive $zip ): void {
			$styles = json_decode( $zip->getFromName( 'style-dependencies.json' ),true ); $styles['assetBindings'] = [];
			$bytes = json_encode( $styles ); $zip->addFromString( 'style-dependencies.json',$bytes );
			$i = json_decode( $zip->getFromName( 'package-integrity.json' ),true );
			foreach ( $i['files'] as &$f ) if ( $f['path'] === 'style-dependencies.json' ) { $f['bytes'] = strlen( $bytes ); $f['sha256'] = 'sha256:' . hash( 'sha256',$bytes ); } unset( $f );
			$zip->addFromString( 'package-integrity.json',json_encode( $i ) );
		},
	];
	foreach ( $cases as $name=>$mutate ) {
		copy( $source,$tmp ); $zip = new ZipArchive(); $zip->open( $tmp ); $mutate( $zip ); $zip->close();
		$rejected = false;
		try { ( new Reader( $tmp ) )->inspect(); } catch ( DSA\Bricks\Compiler_Package_Error $error ) { $rejected = true; }
		$check( $rejected,'Reject actual ZIP ' . $name );
	}
} finally { if ( is_file( $tmp ) ) unlink( $tmp ); }
if ( $own_source && is_file( $source ) ) unlink( $source );
echo json_encode( [ 'schema'=>'kiwe.sc02.reader-tests.v1','passed'=>true,'checks'=>$checks,'cleanup'=>! is_file( $tmp ) && ( ! $own_source || ! is_file( $source ) ) ] ) . PHP_EOL;
