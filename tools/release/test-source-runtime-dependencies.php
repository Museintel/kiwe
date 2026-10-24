<?php
/** Import metadata is a dependency check, never executable-code approval. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Bricks/Source_Runtime_Bridge.php';
require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php';
use DSA\Bricks\Source_Runtime_Bridge as Bridge;
$checks = [];
$assert = static function( $ok, $label ) use ( &$checks ): void { $checks[] = [ 'passed' => (bool) $ok, 'label' => $label ]; if ( ! $ok ) throw new RuntimeException( $label ); };
$make = static function( bool $cached = true, string $area = 'content' ): array {
 return [ 'title' => 'Runtime dependency fixture', 'type' => 'content', $area => [ [ 'id' => 'bridge', 'name' => 'html', 'settings' => [ 'html' => '<script data-brx-source-runtime-bundle="fixture"' . ( $cached ? ' data-seam-runtime="1"' : '' ) . '>fixture</script>' ] ] ], 'generator' => [ 'runtimeDependencies' => [ 'sourceBridge' => [ 'version' => '1', 'delivery' => $cached ? 'kiwe-cached' : 'embedded', 'requiresKiwe' => $cached ] ] ] ];
};
foreach ( [ 'content', 'header', 'footer' ] as $area ) foreach ( [ true, false ] as $cached ) $assert( null === Bridge::dependency_error( $make( $cached, $area ) ), 'matching ' . $area . ' ' . ( $cached ? 'cached' : 'embedded' ) . ' declaration admitted' );
$legacy = $make( false ); unset( $legacy['generator'] ); $assert( null === Bridge::dependency_error( $legacy ), 'old portable runtime needs no new metadata' );
$missing = $make(); unset( $missing['generator'] ); $assert( null !== Bridge::dependency_error( $missing ), 'cached runtime cannot conceal its dependency' );
foreach ( [
 'version' => static function( &$a ) { $a['generator']['runtimeDependencies']['sourceBridge']['version'] = '2'; },
 'false portability' => static function( &$a ) { $a['generator']['runtimeDependencies']['sourceBridge']['requiresKiwe'] = false; },
 'delivery' => static function( &$a ) { $a['generator']['runtimeDependencies']['sourceBridge']['delivery'] = 'unknown'; },
 'extra keys' => static function( &$a ) { $a['generator']['runtimeDependencies']['sourceBridge']['extra'] = true; },
 'null declaration' => static function( &$a ) { $a['generator']['runtimeDependencies']['sourceBridge'] = null; },
 'missing bridge' => static function( &$a ) { $a['content'] = []; },
 'duplicate bridge' => static function( &$a ) { $a['content'][] = $a['content'][0]; },
 'wrong markup version' => static function( &$a ) { $a['content'][0]['settings']['html'] = str_replace( 'data-seam-runtime="1"', 'data-seam-runtime="2"', $a['content'][0]['settings']['html'] ); },
 'data-only script' => static function( &$a ) { $a['content'][0]['settings']['html'] = str_replace( '<script ', '<script type="application/json" ', $a['content'][0]['settings']['html'] ); },
 'multiple scripts' => static function( &$a ) { $a['content'][0]['settings']['html'] .= '<script>second</script>'; },
] as $label => $mutate ) { $artifact = $make(); $mutate( $artifact ); $assert( null !== Bridge::dependency_error( $artifact ), 'reject ' . $label ); }
$validator = new DSA\AI\Bricks_Conversion_Validator();
$result = $validator->validate( $missing );
$assert( ! $result['ok'] && in_array( 'bricks_template_source_runtime_dependency', array_column( $result['findings'], 'code' ), true ), 'actual native template validation rejects hidden runtime dependency' );
echo json_encode( [ 'passed' => true, 'checks' => $checks ], JSON_UNESCAPED_SLASHES ) . PHP_EOL;
