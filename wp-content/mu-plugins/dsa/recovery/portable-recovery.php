<?php
declare(strict_types=1);

// Independent owner tool: never bootstrap WordPress or execute uploaded website code.
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( PHP_VERSION_ID < 80200 ) { fwrite( STDERR,"PHP 8.2 or newer is required.\n" ); exit( 2 ); }
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH',__DIR__.'/' );
foreach ( [ 'Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture','Portable_Source_Compatibility','Portable_Source_Observation','Portable_Recovery_Set','Portable_Recovery_Stage','Portable_Table_Definition','Portable_Recovery_Migration','Portable_Recovery_Ownership','Portable_Recovery_Database','Portable_Recovery_Configuration','Portable_Recovery_Activation' ] as $class ) require dirname( __DIR__ ).'/includes/Backup/'.$class.'.php';

use DSA\Backup\Portable_Recovery_Stage as Stage;
use DSA\Backup\Portable_Recovery_Database as Database;
use DSA\Backup\Portable_Recovery_Activation as Activation;

$options = getopt( '',[ 'prepare','inspect','retire','database-prepare','database-inspect','database-retire','activation-review','activate','activation-inspect','activation-resume','activation-reverse','activation-retire','archive:','observation:','parent:','public-root:','stage:','database:','connection:','migration:','ownership:','activation:','review:','help' ] );
$usage = "Kiwe portable recovery preparation (CLI only)\n"
	."--prepare --archive=<local file> --observation=<local file> --parent=<private directory> --public-root=<website directory>\n"
	."--inspect|--retire --stage=<owned private stage> --public-root=<website directory>\n"
	."--database-prepare --stage=<owned private stage> --connection=<private JSON> --parent=<private directory> --public-root=<website directory> [--migration=<private reviewed JSON> [--ownership=<private reviewed JSON>]]\n"
	."--database-inspect|--database-retire --database=<owned private database receipt> --connection=<private JSON> --public-root=<website directory>\n"
	."--activation-review --database=<owned receipt> --stage=<owned stage> --connection=<private JSON> --public-root=<isolated website directory>\n"
	."--activate --database=<owned receipt> --stage=<owned stage> --connection=<private JSON> --public-root=<isolated website directory> --parent=<private same-volume directory> --review=<private reviewed JSON>\n"
	."--activation-inspect|--activation-resume|--activation-reverse --activation=<owned activation> --connection=<private JSON> --public-root=<isolated website directory>\n"
	."--activation-retire --activation=<reversed activation> --public-root=<isolated website directory>\n"
	."Pass the recovery phrase as one line on standard input, never in arguments or environment variables.\n"
	."With --ownership, supply the destination owner's native WordPress password as a second input line.\n"
	."Preparation stages inert private files and records. Activation requires exact review and isolated destination operation; no production readiness is inferred.\n";
if ( isset( $options['help'] ) ) { echo $usage; exit( 0 ); }
$phrase = ''; $owner_password = null; $input = null; $connection = null;
try {
	$actions = array_values( array_intersect( [ 'prepare','inspect','retire','database-prepare','database-inspect','database-retire','activation-review','activate','activation-inspect','activation-resume','activation-reverse','activation-retire' ],array_keys( $options ) ) );
	if ( count( $actions ) !== 1 || ! is_string( $options['public-root'] ?? null ) ) throw new RuntimeException( 'Select one task and a prepared website public root.' );
	$action = $actions[0];
	$required = match ( $action ) { 'prepare'=>[ 'archive','observation','parent','public-root' ],'database-prepare'=>[ 'stage','connection','parent','public-root' ],'database-inspect','database-retire'=>[ 'database','connection','public-root' ],'activation-review'=>[ 'database','stage','connection','public-root' ],'activate'=>[ 'database','stage','connection','public-root','parent','review' ],'activation-inspect','activation-resume','activation-reverse'=>[ 'activation','connection','public-root' ],'activation-retire'=>[ 'activation','public-root' ],default=>[ 'stage','public-root' ] };
	foreach ( $required as $key ) if ( ! is_string( $options[$key] ?? null ) || '' === $options[$key] ) throw new RuntimeException( 'A required local task path is missing.' );
	foreach ( $options as $key=>$value ) if ( ! in_array( $key,array_merge( $required,[ $action ],$action === 'database-prepare' ? [ 'migration','ownership' ] : [] ),true ) || ( in_array( $key,[ 'migration','ownership' ],true ) && ! is_string( $value ) ) ) throw new RuntimeException( 'An unsupported task argument was supplied.' );
	if ( isset( $options['ownership'] ) && ! isset( $options['migration'] ) ) throw new RuntimeException( 'Ownership requires reviewed migration.' );
	$line = fgets( STDIN,1027 ); if ( ! is_string( $line ) || ! str_ends_with( $line,"\n" ) ) throw new RuntimeException( 'Supply one bounded recovery phrase line on standard input.' );
	$phrase = rtrim( $line,"\r\n" ); unset( $line );
	if ( strlen( $phrase ) < 16 || strlen( $phrase ) > 1024 ) throw new RuntimeException( 'The recovery phrase length is invalid.' );
	if ( isset( $options['ownership'] ) ) { $line = fgets( STDIN,4099 ); if ( ! is_string( $line ) || ! str_ends_with( $line,"\n" ) ) throw new RuntimeException( 'Supply one bounded owner password line.' ); $owner_password = rtrim( $line,"\r\n" ); unset( $line ); if ( strlen( $owner_password ) < 1 || strlen( $owner_password ) > 4096 ) throw new RuntimeException( 'The owner password length is invalid.' ); }
	$started = hrtime( true );
	$budget = static function() use ( $started ): void { if ( hrtime( true )-$started > 1800000000000 ) throw new DSA\Backup\Archive_Error( 'Private preparation exceeded its operation budget.' ); };
	if ( $action === 'activate' || ( str_starts_with( $action,'activation-' ) && $action !== 'activation-retire' ) ) {
		$connection = Database::connection_file( $options['connection'] );
		if ( $action === 'activation-review' ) $result = [ 'schema'=>Activation::SCHEMA,'status'=>'review-required','plan'=>Activation::reviewed_plan( $connection,$options['database'],$options['stage'],$options['public-root'],$phrase,$budget ),'activated'=>false,'fullSiteReady'=>false ];
		elseif ( $action === 'activate' ) $result = Activation::activate( $connection,$options['database'],$options['stage'],$options['public-root'],$phrase,$options['parent'],DSA\Backup\Portable_Recovery_Migration::file( $options['review'] ),$budget );
		elseif ( $action === 'activation-inspect' ) $result = Activation::inspect( $connection,$options['activation'],$options['public-root'],$phrase,$budget );
		elseif ( $action === 'activation-resume' ) $result = Activation::resume( $connection,$options['activation'],$options['public-root'],$phrase,$budget );
		else $result = Activation::reverse( $connection,$options['activation'],$options['public-root'],$phrase,$budget );
	} elseif ( $action === 'activation-retire' ) {
		Activation::retire( $options['activation'],$options['public-root'],$phrase,$budget ); $result = [ 'schema'=>Activation::SCHEMA,'status'=>'retired-reversed-activation','activated'=>false,'fullSiteReady'=>false ];
	} elseif ( str_starts_with( $action,'database-' ) ) {
		$connection = Database::connection_file( $options['connection'] );
		if ( 'database-prepare' === $action ) $result = Database::prepare( $connection,$options['stage'],$options['public-root'],$phrase,$options['parent'],$budget,isset( $options['migration'] ) ? DSA\Backup\Portable_Recovery_Migration::file( $options['migration'] ) : null,isset( $options['ownership'] ) ? DSA\Backup\Portable_Recovery_Migration::file( $options['ownership'] ) : null,$owner_password );
		elseif ( 'database-inspect' === $action ) $result = Database::inspect( $connection,$options['database'],$options['public-root'],$phrase,$budget );
		else { Database::retire( $connection,$options['database'],$options['public-root'],$phrase,$budget ); $result = [ 'schema'=>Database::SCHEMA,'status'=>'retired-inactive-database','activated'=>false ]; }
	} elseif ( 'prepare' === $action ) {
		foreach ( [ 'archive','observation' ] as $key ) {
			$path = $options[$key]; $stat = @lstat( $path );
			if ( ! $stat || is_link( $path ) || ( $stat['mode'] & 0170000 ) !== 0100000 || ! is_readable( $path ) || ( 'observation' === $key && $stat['size'] > 1048576 ) ) throw new RuntimeException( 'Ordinary readable local recovery files are required.' );
		}
		$input = fopen( $options['archive'],'rb' );
		$result = Stage::prepare( $input,file_get_contents( $options['observation'] ),$phrase,$options['parent'],$options['public-root'],$budget );
	} elseif ( 'inspect' === $action ) {
		$review = Stage::inspect( $options['stage'],$options['public-root'],$phrase,$budget );
		$r = $review['receipt']; $result = [ 'schema'=>Stage::SCHEMA,'status'=>'verified-private-stage','files'=>$r['fileCount'],'tables'=>$r['tableCount'],'rows'=>$r['rowCount'],'activated'=>false,'fullSiteReady'=>false ];
	} else {
		Stage::retire( $options['stage'],$options['public-root'],$phrase,$budget ); $result = [ 'schema'=>Stage::SCHEMA,'status'=>'retired','activated'=>false ];
	}
	echo json_encode( $result,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR )."\n";
	$code = 0;
} catch ( Throwable $error ) {
	// Paths, table values, captured settings, credentials and traces never enter a failure response.
	echo json_encode( [ 'schema'=>Stage::SCHEMA,'status'=>'refused','activated'=>( ( $action ?? '' ) === 'activate' || str_starts_with( $action ?? '','activation-' ) ) ? null : false,'message'=>'Private recovery failed. Verify the matching files, phrase, private paths and capacity. Retain an incomplete activation receipt for owner inspection.' ],JSON_UNESCAPED_SLASHES )."\n";
	$code = 1;
} finally {
	if ( is_resource( $input ) ) fclose( $input );
	if ( is_array( $connection ) ) { if ( function_exists( 'sodium_memzero' ) ) sodium_memzero( $connection['password'] ); $connection = null; }
	if ( function_exists( 'sodium_memzero' ) ) sodium_memzero( $phrase ); else $phrase = '';
	if ( is_string( $owner_password ) ) { if ( function_exists( 'sodium_memzero' ) ) sodium_memzero( $owner_password ); else $owner_password = ''; }
}
exit( $code );
