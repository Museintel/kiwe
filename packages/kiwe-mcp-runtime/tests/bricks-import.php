<?php
namespace DSA\Apps {
	final class Runtime_Registry {
		public static function installed( string $id ): bool { return $id === 'mcp'; }
		public static function owner( string $id ): string { return self::class; }
		public static function enabled(): bool { return true; }
	}
}
namespace DSA\Bricks {
	final class Compiler_Import_Plan { public static function allowed(): bool { return $GLOBALS['nativeBricks']; } }
	final class Compiler_Import_Service { public const MCP_IMPORT_API = 1; }
}
namespace DSA\MCP {
	final class Credentials { public static function actor_allowed( int $id ): bool { return $id === 7; } }
}
namespace {
	define( 'ABSPATH',__DIR__ ); $nativeBricks = true; $actor = 7;
	function get_current_user_id(): int { return $GLOBALS['actor']; }
	function wp_json_encode( $value ): string { return json_encode( $value,JSON_THROW_ON_ERROR ); }
	$root = dirname( __DIR__,3 ) . '/wp-content/mu-plugins/dsa/includes/';
	foreach ( [ 'Apps/Runtime_Contract','Apps/MCP_Provider_Contract','MCP/Schema','MCP/Operation_Registry','MCP/Bricks_Import_Provider' ] as $class ) require $root . $class . '.php';
	use DSA\MCP\{Bricks_Import_Provider as Provider,Operation_Registry as Registry,Schema};
	$checks = 0;
	$check = static function ( bool $ok,string $why ) use ( &$checks ): void { if ( ! $ok ) throw new \RuntimeException( $why ); $checks++; };
	$r = new Registry(); $ops = Provider::operations( $r );
	$r->register( 'mcp',1,static fn() => [ 'schema'=>DSA\Apps\MCP_Provider_Contract::SCHEMA,'api'=>1,'app'=>'mcp','setup'=>[ 'state'=>'ready' ],'operations'=>$ops ] );
	$scope = Provider::SCOPE; $base = 'mcp.bricks.import.';
	$check( $r->scopes() === [ $scope ] && count( $r->describe( [ $scope ] ) ) === 4,'The full provider packet is admitted through the real registry schema budget' );
	$pins = $r->freeze( [ $scope ] );
	$check( count( $pins ) === 4 && $r->describe( [ $scope ],[] ) === [],'Existing frozen pins cannot acquire these operations' );
	$check( $ops[$base.'begin']['approval'] === 'required' && $ops[$base.'undo']['approval'] === 'required' && $ops[$base.'next']['approval'] === 'none','Begin and Undo require native approval; bounded continuation consumes its original approval' );
	$input = $ops[$base.'begin']['inputSchema']; $job = [ 'job'=>str_repeat( 'a',32 ),'archiveSha256'=>str_repeat( 'b',64 ),'revision'=>1 ];
	$check( Schema::value( $job,$input ) && ! Schema::value( $job + [ 'approve'=>true ],$input ) && ! Schema::value( $job + [ 'url'=>'https://example.invalid/zip' ],$input ),'The typed job admits neither chat approval nor transport' );
	$check( ! Schema::value( $job,$ops[$base.'next']['inputSchema'] ) && Schema::value( $job + [ 'beginIntent'=>'i_' . str_repeat( 'c',32 ) ],$ops[$base.'next']['inputSchema'] ),'Continuation must name its exact original intent' );
	$nativeBricks = false;$check( $r->describe( [ $scope ],$pins ) === [],'Current native builder permission overrides an existing grant' );
	$nativeBricks = true;$actor = 8;$check( $r->describe( [ $scope ],$pins ) === [],'A user outside the MCP authoring role cannot use Bricks access' );
	echo wp_json_encode( [ 'passed'=>true,'checks'=>$checks,'nativeSql'=>false ] ) . PHP_EOL;
}
