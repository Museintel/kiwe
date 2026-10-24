<?php
namespace Bricks {
	class Elements { public static array $elements = [ 'div'=>[],'image'=>[],'html'=>[],'text-basic'=>[] ]; }
	class Capabilities { public static function current_user_can_execute_code(): bool { return ! empty( $GLOBALS['code_allowed'] ); } }
}
namespace {
	define( 'ABSPATH',__DIR__ . '/' ); $GLOBALS['html_allowed'] = true; $GLOBALS['code_allowed'] = true;
	function current_user_can( $cap ): bool { return $cap === 'unfiltered_html' && ! empty( $GLOBALS['html_allowed'] ); }
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Package_Error.php';
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Package_Reader.php';
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Bricks/Source_Runtime_Bridge.php';
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Import_Plan.php';
	$checks = 0;
	$check = static function( bool $ok,string $name ) use ( &$checks ): void { if ( ! $ok ) throw new \RuntimeException( $name ); $checks++; };
	$reject = static function( callable $call,string $name ) use ( $check ): void { $failed = false; try { $call(); } catch ( \DSA\Bricks\Compiler_Package_Error $error ) { $failed = true; } $check( $failed,$name ); };
	$validate = [ \DSA\Bricks\Compiler_Import_Plan::class,'validate_document' ];
	$base = [ 'title'=>'Home','templateType'=>'content','version'=>'2.3.10','content'=>[ [ 'id'=>'root1','name'=>'div','parent'=>0,'children'=>[ 'image1' ],'settings'=>[] ],[ 'id'=>'image1','name'=>'image','parent'=>'root1','children'=>[],'settings'=>[ 'image'=>[ 'url'=>'{featured_image}' ] ] ] ] ];
	$check( $validate( $base )['elements'] === 2,'Valid native tree and dynamic image remain accepted' );
	$cycle = $base; $cycle['content'][0]['parent'] = 'image1'; $cycle['content'][1]['children'] = [ 'root1' ];
	$reject( static fn()=>$validate( $cycle ),'Circular native tree cannot hang the builder' );
	$missing = $base; $missing['content'][0]['children'] = [];
	$reject( static fn()=>$validate( $missing ),'Native parent/child ownership is reciprocal' );
	$duplicate = $base; $duplicate['content'][1]['id'] = 'root1';
	$reject( static fn()=>$validate( $duplicate ),'Duplicate native IDs are denied' );
	$php = $base; $php['content'][0]['settings']['query'] = [ 'useQueryEditor'=>true,'queryEditor'=>'return []; ' ];
	$reject( static fn()=>$validate( $php ),'Imported PHP query execution never acquires authority' );
	$php = $base; $php['content'][0]['settings']['_attributes'] = [ [ 'name'=>'title','value'=>'{echo:unsafe}' ] ];
	$reject( static fn()=>$validate( $php ),'Echo tags require a separate native code review' );
	$js = $base; $js['pageSettings'] = [ 'customJs'=>'console.log("test");' ];
	$GLOBALS['html_allowed'] = false;
	$reject( static fn()=>$validate( $js ),'Source JavaScript cannot bypass WordPress HTML authority' );
	$GLOBALS['html_allowed'] = true; $GLOBALS['code_allowed'] = false;
	$check( $validate( $js )['sourceCode'] === true && ! \Bricks\Capabilities::current_user_can_execute_code(),'Native HTML authority admits source JavaScript without enabling PHP execution' );
	$GLOBALS['code_allowed'] = true;
	$check( $validate( $js )['sourceCode'] === true,'Authorized source JavaScript is retained explicitly' );
	$unsupported = $base; $unsupported['version'] = '2.4.2';
	$reject( static fn()=>$validate( $unsupported ),'Unqualified target family does not become accepted by metadata' );
	$image = 'data:image/png;base64,AA==';
	$inspection = [ 'documents'=>[ 'home.json'=>[ 'content'=>[ [ 'settings'=>[ 'image'=>[ 'url'=>$image ] ] ] ] ] ],'assets'=>[ 'a'=>[ 'id'=>'a' ] ],'bindings'=>[ [ 'artifact'=>'home.json','pointer'=>'/content/0/settings/image/url','referenceHash'=>'sha256:' . hash( 'sha256',$image ),'assetId'=>'a' ],[ 'artifact'=>'other.json','pointer'=>'/content/0/settings/image/url','referenceHash'=>'sha256:' . hash( 'sha256',$image ),'assetId'=>'a' ] ] ];
	$remapped = \DSA\Bricks\Compiler_Import_Plan::remap( $inspection,[ 'a'=>[ 'id'=>71,'url'=>'https://site.test/native.png' ] ] );
	$check( $remapped['home.json']['content'][0]['settings']['image'] === [ 'url'=>'https://site.test/native.png','id'=>71 ],'Guarded media remap uses the target native ID and supports one-file continuation' );
	$check( $inspection['documents']['home.json']['content'][0]['settings']['image']['url'] === $image,'Remapping does not mutate the source package' );
	if ( isset( $argv[1] ) ) {
		define( 'BRICKS_PATH',rtrim( $argv[1],'/\\' ) . '/' );
		function apply_filters( $tag,$value,...$args ) { return $value; }
		require BRICKS_PATH . 'includes/svg.php'; \Bricks\Svg::load_libraries();
		require BRICKS_PATH . 'includes/integrations/svg-sanitizer/allowed-tags.php';
		require BRICKS_PATH . 'includes/integrations/svg-sanitizer/allowed-attributes.php';
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z" fill="#fff"/></svg>';
		\DSA\Bricks\Compiler_Import_Plan::validate_embedded_svg( $svg ); $check( true,'Actual Bricks sanitizer retains a safe SVG without changing its DOM' );
		$reject( static fn()=>\DSA\Bricks\Compiler_Import_Plan::validate_embedded_svg( str_replace( '<path','<script>alert(1)</script><path',$svg ) ),'Actual Bricks sanitizer cannot silently change executable SVG' );
		$reject( static fn()=>\DSA\Bricks\Compiler_Import_Plan::validate_embedded_svg( str_replace( '<path','<image href="https://unverified.invalid/a.png"/><path',$svg ) ),'Actual Bricks sanitizer denies unverified SVG remote dependencies' );
	}
	echo json_encode( [ 'schema'=>'kiwe.sc02.import-boundary-tests.v1','passed'=>true,'checks'=>$checks,'nativeWordPress'=>false ] ) . PHP_EOL;
}
