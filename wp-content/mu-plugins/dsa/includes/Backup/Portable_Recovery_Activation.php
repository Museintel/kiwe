<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Isolated POSIX cutover. Keep original native tables and the complete destination root. */
final class Portable_Recovery_Activation {
	public const SCHEMA = 'kiwe.private-recovery-activation.v2';
	private const MAGIC = 'KIWEPRA2';
	private const MAX_RECEIPT = 20971520;
	private const HELD_FILES = [ 'wp-config.php','.htaccess','.user.ini','php.ini','wp-content/db.php','wp-content/object-cache.php','wp-content/advanced-cache.php','wp-content/sunrise.php','wp-content/maintenance.php' ];
	private string $directory;
	private array $state;
	private Portable_Archive_Cipher $cipher;
	private $budget;
	private $checkpoint;
	private bool $pending_receipt = false;
	private static function fail(): never { throw new Archive_Error( 'Isolated activation or its retained state could not be verified.' ); }
	private static function json( array $value ): string { return json_encode( $value,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); }
	private static function ordinary( string $path, bool $directory, bool $private = true ): array {
		if ( '' === $path || strlen( $path ) > 8192 || preg_match( '/[\x00-\x1f\x7f]/',$path ) || realpath( $path ) !== $path || $path === '/' ) self::fail();
		for ( $cursor = $path; ; $cursor = $next ) { if ( is_link( $cursor ) ) self::fail(); $next = dirname( $cursor ); if ( $next === $cursor ) break; }
		clearstatcache( true,$path ); $s = lstat( $path );
		if ( ! $s || ( $s['mode'] & 0170000 ) !== ( $directory ? 0040000 : 0100000 ) || ! is_readable( $path ) || ( ! $directory && $s['nlink'] !== 1 ) || ( function_exists( 'posix_geteuid' ) && $s['uid'] !== posix_geteuid() ) || ( $private && ( $s['mode'] & 0077 ) ) ) self::fail();
		return $s;
	}
	private static function local(): void { if ( PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80200 || PHP_OS_FAMILY === 'Windows' || ! function_exists( 'fsync' ) ) self::fail(); }
	private static function separate( string $a, string $b ): void { if ( $a === $b || str_starts_with( $a,$b.'/' ) || str_starts_with( $b,$a.'/' ) ) self::fail(); }
	private static function root_binding( string $path ): string { $s = self::ordinary( $path,true,false ); return hash( 'sha256',self::json( [ $path,$s['dev'],$s['ino'] ] ) ); }
	private static function sync( string $directory ): void { $s = @fopen( $directory,'r' ); if ( ! $s ) self::fail(); try { if ( ! fsync( $s ) ) self::fail(); } finally { fclose( $s ); } }
	private static function tree( string $root, ?callable $budget ): array {
		self::ordinary( $root,true,false ); $tree = Portable_File_Inventory::scan( $root,Portable_File_Inventory::MAX_FILES,Portable_File_Inventory::MAX_BYTES,$budget,'posix',true );
		foreach ( $tree['directories'] as $dir ) self::ordinary( $root.'/'.$dir,true,false ); foreach ( $tree['files'] as $file ) self::ordinary( $root.'/'.$file['path'],false,false ); return $tree;
	}
	private function tick(): void { if ( $this->budget ) ( $this->budget )(); }
	private static function held( string $path ): bool { return in_array( $path,self::HELD_FILES,true ) || $path === '.well-known' || str_starts_with( $path,'.well-known/' ); }
	private static function plan( array $database, array $review, array $before, string $database_hash, array $original_tables ): array {
		return [ 'schema'=>self::SCHEMA,'recoverySha256'=>$database['recoverySha256'],'databaseReceiptSha256'=>$database_hash,'sourceInventorySha256'=>hash( 'sha256',self::json( $review['inventory'] ) ),'destinationInventorySha256'=>hash( 'sha256',self::json( $before ) ),'destinationDatabaseSha256'=>hash( 'sha256',self::json( $original_tables ) ),'codeReview'=>null,'destinationIsolation'=>null,'quarantinedTables'=>$database['ownership']['quarantinedTables'],'withheldStateReview'=>null ];
	}
	/** Exact hash/disposition attestations; no code paths, secret values or preservation override. */
	public static function policy( array $plan, array $expected ): array {
		if ( array_keys( $plan ) !== array_keys( $expected ) || $plan['codeReview'] !== 'exact-captured-code-approved' || $plan['destinationIsolation'] !== 'requests-writers-host-cron-paused' || $plan['withheldStateReview'] !== 'original-private-review-complete' ) self::fail();
		foreach ( $expected as $key=>$value ) if ( ! in_array( $key,[ 'codeReview','destinationIsolation','withheldStateReview' ],true ) && $plan[$key] !== $value ) self::fail(); return $plan;
	}
	public static function reviewed_plan( #[\SensitiveParameter] array $connection, string $database, string $stage, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget = null ): array {
		self::local();
		return Portable_Recovery_Database::activation_guard( $connection,$database,$public_root,$phrase,static function( $state,$heartbeat,$original_tables ) use ( $database,$stage,$public_root,$phrase,$budget ) {
			$guard=static function() use ( $budget,$heartbeat ): void { if ( $budget ) $budget(); $heartbeat(); }; $budget=$guard;
			return Portable_Recovery_Stage::with_lease( $stage,static function() use ( $state,$database,$stage,$public_root,$phrase,$budget,$original_tables ) {
				$review = Portable_Recovery_Stage::inspect( $stage,$public_root,$phrase,$budget ); if ( $review['receipt']['recoverySha256'] !== $state['recoverySha256'] || $review['receipt']['records']['sha256'] !== $state['recordsSha256'] ) self::fail();
				return self::plan( $state,$review,self::tree( $public_root,$budget ),hash_file( 'sha256',$database.'/database.receipt' ),$original_tables );
			} );
		},$budget );
	}
	private static function lease( string $parent ) {
		self::ordinary( $parent,true ); $path = $parent.'/.kiwe-activation.lock'; $stream = @fopen( $path,'x+b' );
		if ( $stream ) { if ( ! chmod( $path,0600 ) ) { fclose( $stream ); self::fail(); } } else { self::ordinary( $path,false ); $stream = @fopen( $path,'r+b' ); }
		if ( ! $stream ) self::fail(); $s = self::ordinary( $path,false ); $opened = fstat( $stream );
		if ( $s['size'] !== 0 || $s['ino'] !== $opened['ino'] || $s['dev'] !== $opened['dev'] || ! flock( $stream,LOCK_EX | LOCK_NB ) ) { fclose( $stream ); self::fail(); } return $stream;
	}
	private static function write( string $path, string $bytes ): void {
		$s = @fopen( $path,'x+b' ); if ( ! $s ) self::fail();
		try { if ( ! chmod( $path,0600 ) ) self::fail(); for ( $at=0; $at<strlen( $bytes ); ) { $n=fwrite( $s,substr( $bytes,$at,65536 ) ); if ( ! is_int( $n ) || $n<1 ) self::fail(); $at+=$n; } if ( ! fflush( $s ) || ! fsync( $s ) ) self::fail(); } finally { fclose( $s ); }
	}

	private function mark( string $label ): void { if ( $this->checkpoint ) ( $this->checkpoint )( $label,$this->directory ); }
	private function save(): void {
		++$this->state['revision']; $plain=self::json( $this->state ); if ( strlen( $plain )>self::MAX_RECEIPT ) self::fail(); $header=self::json( $this->cipher->header() );
		$index=self::json( [ 'schema'=>self::SCHEMA,'bytes'=>strlen( $plain ),'sha256'=>hash( 'sha256',$plain ),'chunks'=>(int)ceil( strlen( $plain )/Portable_Archive_Cipher::CHUNK_BYTES ) ] ); $frames=[ $this->cipher->seal( $index,0,'index' ) ];
		for ( $at=0,$sequence=1; $at<strlen( $plain ); $at+=Portable_Archive_Cipher::CHUNK_BYTES,++$sequence ) $frames[]=$this->cipher->seal( substr( $plain,$at,Portable_Archive_Cipher::CHUNK_BYTES ),$sequence,'data' );
		$bytes=self::MAGIC.pack( 'N',strlen( $header ) ).$header; foreach ( $frames as $frame ) $bytes.=pack( 'N',strlen( $frame ) ).$frame;
		self::ordinary( $this->directory,true ); self::write( $this->directory.'/activation.next',$bytes ); $this->mark( 'receipt-written' );
		if ( ! rename( $this->directory.'/activation.next',$this->directory.'/activation.receipt' ) ) self::fail(); self::sync( $this->directory ); $this->mark( 'journal-'.$this->state['status'] );
	}
	private static function read_state( string $directory, string $file, #[\SensitiveParameter] string $phrase ): array {
		self::ordinary( $directory,true ); if ( ! preg_match( '/^kiwe-activation-[a-f0-9]{32}$/D',basename( $directory ) ) ) self::fail();
		$s=self::ordinary( $directory.'/'.$file,false ); if ( $s['size']>self::MAX_RECEIPT+8192 || $s['size']<20 ) self::fail(); $bytes=file_get_contents( $directory.'/'.$file ); if ( substr( $bytes,0,8 )!==self::MAGIC ) self::fail();
		$n=unpack( 'N',substr( $bytes,8,4 ) )[1]; if ( $n<2 || $n>2048 ) self::fail(); $cipher=new Portable_Archive_Cipher( json_decode( substr( $bytes,12,$n ),true,8,JSON_THROW_ON_ERROR ),$phrase ); $at=12+$n;
		$frame=static function( int $sequence,string $purpose ) use ( &$at,$bytes,$cipher ): string { if ( strlen( $bytes )-$at<4 ) self::fail(); $n=unpack( 'N',substr( $bytes,$at,4 ) )[1]; $at+=4; if ( $n<1 || $n>Portable_Archive_Cipher::CHUNK_BYTES+128 || strlen( $bytes )-$at<$n ) self::fail(); $body=$cipher->open( substr( $bytes,$at,$n ),$sequence,$purpose ); $at+=$n; return $body; };
		$index=json_decode( $frame( 0,'index' ),true,8,JSON_THROW_ON_ERROR ); if ( array_keys( $index )!==[ 'schema','bytes','sha256','chunks' ] || $index['schema']!==self::SCHEMA || ! is_int( $index['bytes'] ) || $index['bytes']<1 || $index['bytes']>self::MAX_RECEIPT || $index['chunks']!==(int)ceil( $index['bytes']/Portable_Archive_Cipher::CHUNK_BYTES ) ) self::fail();
		$plain=''; for ( $i=1;$i<=$index['chunks'];++$i ) { $plain.=$frame( $i,'data' ); if ( strlen( $plain )>$index['bytes'] ) self::fail(); } if ( $at!==strlen( $bytes ) || strlen( $plain )!==$index['bytes'] || hash( 'sha256',$plain )!==$index['sha256'] ) self::fail();
		$state=json_decode( $plain,true,64,JSON_THROW_ON_ERROR ); self::valid_state( $state,$directory ); return $state;
	}
	private static function valid_state( mixed $s, string $directory ): void {
		$keys=[ 'schema','id','revision','status','publicRoot','publicParentBinding','directoryBinding','beforeBinding','nextBinding','beforeMode','database','databaseReceiptSha256','originalTables','stage','stageReceiptSha256','policy','beforeTree','nextTree','assembled','activeConfigSha256','heldSourceFiles','activated','retirementTree' ];
		if ( ! is_array( $s ) || array_keys( $s )!==$keys || $s['schema']!==self::SCHEMA || $s['id']!==substr( basename( $directory ),16 ) || ! is_int( $s['revision'] ) || $s['revision']<1 || ! is_bool( $s['assembled'] ) || ! is_bool( $s['activated'] ) || ! is_int( $s['beforeMode'] ) || $s['beforeMode']<0700 || $s['beforeMode']>0777 ) self::fail();
		foreach ( [ 'publicParentBinding','directoryBinding','beforeBinding','nextBinding','databaseReceiptSha256','stageReceiptSha256','activeConfigSha256' ] as $key ) if ( ! is_string( $s[$key] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$s[$key] ) ) self::fail();
		foreach ( [ 'publicRoot','database','stage' ] as $key ) if ( ! is_string( $s[$key] ) || ''===$s[$key] || $s[$key][0]!=='/' || strlen( $s[$key] )>8192 || preg_match( '/[\x00-\x1f\x7f]/',$s[$key] ) ) self::fail();
		if ( ! in_array( $s['status'],[ 'building','prepared','parking','parked','publishing','active','reversing','reversed','cancelled','retiring' ],true ) || ! is_array( $s['originalTables'] ) || ! $s['originalTables'] || count( $s['originalTables'] )>128 || ! is_array( $s['policy'] ) || ! is_array( $s['heldSourceFiles'] ) ) self::fail();
		foreach ( [ 'beforeTree','nextTree' ] as $key ) if ( ! is_array( $s[$key] ) || Portable_File_Inventory::validate_plan( $s[$key] )!=='posix' || $s[$key]['exclusions']!==[] ) self::fail();
		if ( $s['retirementTree']!==null && ( ! is_array( $s['retirementTree'] ) || Portable_File_Inventory::validate_plan( $s['retirementTree'] )!=='posix' || $s['retirementTree']['exclusions']!==[] ) ) self::fail();
		if ( in_array( $s['status'],[ 'prepared','parking','parked','publishing','active','reversing','reversed' ],true ) && ! $s['assembled'] ) self::fail();
		if ( $s['status']==='active' && ! $s['activated'] || in_array( $s['status'],[ 'building','prepared','parking','parked','publishing','cancelled' ],true ) && $s['activated'] ) self::fail();
		if ( in_array( $s['status'],[ 'cancelled','reversed','retiring' ],true ) && ( $s['activated'] || $s['retirementTree']===null ) ) self::fail();
	}
	/** Only an authenticated immediate successor may replace an interrupted journal write. */
	public static function receipt_transition( array $old, array $next ): void {
		if ( array_keys( $old )!==array_keys( $next ) || ! is_int( $old['revision']??null ) || $next['revision']!==$old['revision']+1 ) self::fail();
		$steps=[ 'building'=>[ 'building','prepared','cancelled' ],'prepared'=>[ 'parking','reversing' ],'parking'=>[ 'parked','reversing' ],'parked'=>[ 'publishing','reversing' ],'publishing'=>[ 'active','reversing' ],'active'=>[ 'reversing' ],'reversing'=>[ 'reversed' ],'reversed'=>[ 'retiring' ],'cancelled'=>[ 'retiring' ],'retiring'=>[] ];
		if ( ! in_array( $next['status'],$steps[$old['status']]??[],true ) ) self::fail();
		foreach ( $old as $key=>$value ) if ( ! in_array( $key,[ 'revision','status','assembled','activated','retirementTree' ],true ) && $next[$key]!==$value ) self::fail();
		if ( $old['assembled'] && ! $next['assembled'] || $old['retirementTree']!==null && $next['retirementTree']!==$old['retirementTree'] ) self::fail();
	}
	private static function instance( string $directory, #[\SensitiveParameter] string $phrase, ?callable $budget, ?callable $checkpoint=null ): self {
		$owner=new self(); $owner->directory=$directory; $owner->budget=$budget; $owner->checkpoint=$checkpoint;
		$has=is_file( $directory.'/activation.receipt' ); $pending=file_exists( $directory.'/activation.next' ) || is_link( $directory.'/activation.next' );
		if ( ! $has && ! $pending ) self::fail(); $owner->state=self::read_state( $directory,$has?'activation.receipt':'activation.next',$phrase );
		if ( $pending ) { $next=self::read_state( $directory,'activation.next',$phrase ); if ( $has ) self::receipt_transition( $owner->state,$next ); elseif ( $next['revision']!==1 || $next['status']!=='building' ) self::fail(); $owner->state=$next; $owner->pending_receipt=true; }
		$owner->cipher=new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase ); return $owner;
	}
	private function promote(): void { if ( $this->pending_receipt ) { if ( ! rename( $this->directory.'/activation.next',$this->directory.'/activation.receipt' ) ) self::fail(); self::sync( $this->directory ); $this->pending_receipt=false; $this->mark( 'receipt-promoted' ); } }
	private static function binding_at( string $physical, string $logical ): string { $s=self::ordinary( $physical,true,false ); return hash( 'sha256',self::json( [ $logical,$s['dev'],$s['ino'] ] ) ); }
	/** Enumerate supported physical states; never infer a rename from a status alone. */
	public static function checkpoint_placement( string $status, array $layout ): void {
		$allowed=[ 'building'=>[ 'original/0/1' ],'prepared'=>[ 'original/0/1' ],'parking'=>[ 'original/0/1','missing/1/1' ],'parked'=>[ 'missing/1/1' ],'publishing'=>[ 'missing/1/1','recovered/1/0' ],'active'=>[ 'recovered/1/0' ],'reversing'=>[ 'recovered/1/0','missing/1/1','original/0/1' ],'reversed'=>[ 'original/0/1' ],'cancelled'=>[ 'original/0/1' ],'retiring'=>[ 'original/0/1','original/0/0' ] ];
		if ( array_keys( $layout )!==[ 'public','before','next' ] || ! is_bool( $layout['before'] ) || ! is_bool( $layout['next'] ) || ! in_array( $layout['public'],[ 'original','recovered','missing' ],true ) || ! in_array( $layout['public'].'/'.(int)$layout['before'].'/'.(int)$layout['next'],$allowed[$status]??[],true ) ) self::fail();
	}
	private function layout( string $public_root ): array {
		$s=$this->state; if ( $public_root!==$s['publicRoot'] || self::root_binding( dirname( $public_root ) )!==$s['publicParentBinding'] || self::root_binding( $this->directory )!==$s['directoryBinding'] ) self::fail();
		self::ordinary( dirname( $this->directory ),true ); $parent_entries=scandir( dirname( $this->directory ) ); $expected=[ '.','..','.kiwe-activation.lock',basename( $this->directory ) ]; sort( $expected,SORT_STRING ); if ( $parent_entries!==$expected ) self::fail();
		$entries=scandir( $this->directory ); foreach ( $entries as $entry ) if ( ! in_array( $entry,[ '.','..','activation.receipt','activation.next','before','next' ],true ) ) self::fail();
		$layout=[ 'public'=>'missing','before'=>false,'next'=>false ];
		foreach ( [ 'public'=>$public_root,'before'=>$this->directory.'/before','next'=>$this->directory.'/next' ] as $kind=>$path ) {
			clearstatcache( true,$path ); if ( ! @lstat( $path ) ) continue; $binding=self::binding_at( $path,$public_root );
			if ( $kind==='public' ) { if ( $binding===$s['beforeBinding'] ) $layout['public']='original'; elseif ( $binding===$s['nextBinding'] ) $layout['public']='recovered'; else self::fail(); }
			else { if ( $binding!==$s[$kind==='before'?'beforeBinding':'nextBinding'] ) self::fail(); $layout[$kind]=true; }
		}
		self::checkpoint_placement( $s['status'],$layout ); return $layout;
	}
	private static function retained( string $path ): bool { return in_array( $path,[ '.htaccess','.user.ini','php.ini' ],true ) || $path==='.well-known' || str_starts_with( $path,'.well-known/' ); }
	public static function assembly_plan( array $source, array $before, string $config ): array {
		Portable_File_Inventory::validate_plan( $source ); Portable_File_Inventory::validate_plan( $before ); $files=[]; $dirs=[];
		foreach ( $source['directories'] as $path ) if ( ! self::held( $path ) ) $dirs[$path]=true;
		foreach ( $source['files'] as $file ) if ( ! self::held( $file['path'] ) ) $files[$file['path']]=$file;
		foreach ( $before['directories'] as $path ) if ( self::retained( $path ) ) $dirs[$path]=true;
		foreach ( $before['files'] as $file ) if ( self::retained( $file['path'] ) ) $files[$file['path']]=$file;
		$files['wp-config.php']=[ 'path'=>'wp-config.php','bytes'=>strlen( $config ),'sha256'=>hash( 'sha256',$config ) ];
		foreach ( array_keys( $files ) as $path ) for ( $path=dirname( $path ); $path!=='.'; $path=dirname( $path ) ) $dirs[$path]=true;
		ksort( $files,SORT_STRING ); ksort( $dirs,SORT_STRING ); $plan=[ 'files'=>array_values( $files ),'directories'=>array_keys( $dirs ),'exclusions'=>[],'bytes'=>array_sum( array_column( $files,'bytes' ) ),'namePolicy'=>'posix' ]; Portable_File_Inventory::validate_plan( $plan ); return $plan;
	}
	/** Closed membership and complete hashes; building permits only shorter known files, verified separately against source prefixes. */
	public static function subset_plan( array $actual, array $expected, bool $partial=false ): array {
		Portable_File_Inventory::validate_plan( $actual ); Portable_File_Inventory::validate_plan( $expected ); if ( $actual['exclusions']!==[] || $expected['exclusions']!==[] || array_diff( $actual['directories'],$expected['directories'] ) ) self::fail(); $known=array_column( $expected['files'],null,'path' ); $short=[];
		foreach ( $actual['files'] as $file ) { $want=$known[$file['path']]??null; if ( $want===null ) self::fail(); if ( $file!==$want ) { if ( ! $partial || $file['bytes']>=$want['bytes'] ) self::fail(); $short[]=$file; } } return $short;
	}
	private function source_path( string $relative, string $original ): string { return self::retained( $relative )?$original.'/'.$relative:$this->state['stage'].'/site/'.$relative; }
	private function prefix_hash( string $relative, int $length, string $original, string $config ): string {
		if ( $relative==='wp-config.php' ) return hash( 'sha256',substr( $config,0,$length ) ); $path=$this->source_path( $relative,$original ); self::ordinary( $path,false,false ); $stream=fopen( $path,'rb' ); $hash=hash_init( 'sha256' );
		try { for ( $left=$length; $left>0; ) { $this->tick(); $bytes=fread( $stream,min( 65536,$left ) ); if ( ! is_string( $bytes ) || $bytes==='' ) self::fail(); hash_update( $hash,$bytes ); $left-=strlen( $bytes ); } return hash_final( $hash ); } finally { fclose( $stream ); }
	}
	private function verified_copies( string $root, string $original, string $config, bool $partial ): array {
		$actual=self::tree( $root,$this->budget ); foreach ( self::subset_plan( $actual,$this->state['nextTree'],$partial ) as $file ) if ( $file['sha256']!==$this->prefix_hash( $file['path'],$file['bytes'],$original,$config ) ) self::fail();
		if ( ! $partial && $actual!==$this->state['nextTree'] ) self::fail(); return $actual;
	}
	private function context( array $db, string $public_root, string $original, array $layout, #[\SensitiveParameter] array $connection, #[\SensitiveParameter] string $phrase ): string {
		$s=$this->state; if ( hash_file( 'sha256',$s['database'].'/database.receipt' )!==$s['databaseReceiptSha256'] || hash_file( 'sha256',$s['stage'].'/complete.receipt' )!==$s['stageReceiptSha256'] || self::tree( $original,$this->budget )!==$s['beforeTree'] ) self::fail();
		$review=Portable_Recovery_Stage::inspect( $s['stage'],$original,$phrase,$this->budget,$public_root ); if ( $review['receipt']['recoverySha256']!==$db['recoverySha256'] || $review['receipt']['records']['sha256']!==$db['recordsSha256'] ) self::fail();
		self::policy( $s['policy'],self::plan( $db,$review,$s['beforeTree'],$s['databaseReceiptSha256'],$s['originalTables'] ) );
		$config=Portable_Recovery_Configuration::build( file_get_contents( $original.'/wp-config.php' ),$connection,$db['livePrefix'],$db['prefix'],$db['migration']['policy']['destination'] );
		if ( hash( 'sha256',$config )!==$s['activeConfigSha256'] || self::assembly_plan( $review['inventory'],$s['beforeTree'],$config )!==$s['nextTree'] ) self::fail();
		if ( $layout['next'] ) $this->verified_copies( $this->directory.'/next',$original,$config,! $s['assembled'] );
		if ( $layout['public']==='recovered' ) $this->verified_copies( $public_root,$original,$config,false ); return $config;
	}
	private function copy( string $original, #[\SensitiveParameter] string $config, array $file ): void {
		$relative=$file['path']; $destination=$this->directory.'/next/'.$relative; self::ordinary( dirname( $destination ),true,false ); $exists=file_exists( $destination ) || is_link( $destination ); $size=0;
		if ( $exists ) { $s=self::ordinary( $destination,false,false ); $size=$s['size']; if ( $size>$file['bytes'] || hash_file( 'sha256',$destination )!==$this->prefix_hash( $relative,$size,$original,$config ) ) self::fail(); if ( $size===$file['bytes'] ) return; }
		$source=$relative==='wp-config.php'?null:$this->source_path( $relative,$original ); if ( $source!==null ) self::ordinary( $source,false,false ); $input=$source===null?null:fopen( $source,'rb' ); $output=@fopen( $destination,$exists?'ab':'x+b' ); if ( ! $output ) { if ( $input ) fclose( $input ); self::fail(); }
		try {
			if ( ! chmod( $destination,0600 ) || ( $input && fseek( $input,$size )!==0 ) ) self::fail(); $stat=fstat( $output ); $actual=self::ordinary( $destination,false,false ); if ( $actual['dev']!==$stat['dev'] || $actual['ino']!==$stat['ino'] || $actual['size']!==$size ) self::fail();
			for ( $at=$size; $at<$file['bytes']; ) { $this->tick(); $bytes=$input?fread( $input,min( 65536,$file['bytes']-$at ) ):substr( $config,$at,min( 65536,$file['bytes']-$at ) ); if ( ! is_string( $bytes ) || $bytes==='' ) self::fail(); for ( $written=0; $written<strlen( $bytes ); ) { $n=fwrite( $output,substr( $bytes,$written ) ); if ( ! is_int( $n ) || $n<1 ) self::fail(); $written+=$n; } $at+=strlen( $bytes ); if ( ! fflush( $output ) || ! fsync( $output ) ) self::fail(); $this->mark( 'copy-progress' ); }
			clearstatcache( true,$destination ); if ( filesize( $destination )!==$file['bytes'] || hash_file( 'sha256',$destination )!==$file['sha256'] ) self::fail();
		} finally { if ( $input ) fclose( $input ); fclose( $output ); }
		self::sync( dirname( $destination ) ); $this->mark( 'copy-complete' );
	}
	private function build( string $original, string $config ): void {
		foreach ( $this->state['nextTree']['directories'] as $relative ) { $path=$this->directory.'/next/'.$relative; if ( ! file_exists( $path ) && ! mkdir( $path,0700 ) ) self::fail(); self::ordinary( $path,true,false ); self::sync( dirname( $path ) ); }
		foreach ( $this->state['nextTree']['files'] as $file ) $this->copy( $original,$config,$file ); if ( self::tree( $this->directory.'/next',$this->budget )!==$this->state['nextTree'] ) self::fail(); $this->state['assembled']=true; $this->state['status']='prepared'; $this->save();
	}
	private function projection( array $db, array $layout, string $status ): array { return [ 'schema'=>self::SCHEMA,'status'=>$status,'directory'=>$this->directory,'checkpointStatus'=>$this->state['status'],'activated'=>$layout['public']==='recovered','fullSiteReady'=>false,'ownerId'=>$db['ownership']['ownerId'],'heldSourceFiles'=>count( $this->state['heldSourceFiles'] ),'retainedOriginalRoot'=>true,'retainedOriginalDatabase'=>true ]; }
	private function publish( array $db, string $public_root, string $original, string $config, callable $heartbeat ): array {
		if ( $this->state['status']==='active' ) return $this->projection( $db,$this->layout( $public_root ),'isolated-site-activated' );
		if ( $this->state['status']==='building' ) $this->build( $original,$config );
		$layout=$this->layout( $public_root ); $heartbeat( true );
		if ( $layout['public']==='original' ) { if ( $this->state['status']!=='parking' ) { $this->state['status']='parking'; $this->save(); } if ( ! rename( $public_root,$this->directory.'/before' ) ) self::fail(); self::sync( dirname( $public_root ) ); self::sync( $this->directory ); $this->mark( 'original-parked' ); }
		$layout=$this->layout( $public_root );
		if ( $layout['public']==='missing' ) {
			if ( ! chmod( $this->directory.'/before',0700 ) ) self::fail(); if ( $this->state['status']!=='publishing' ) { $this->state['status']='parked'; $this->save(); }
			foreach ( $this->state['nextTree']['directories'] as $dir ) if ( ! chmod( $this->directory.'/next/'.$dir,0755 ) ) self::fail(); foreach ( $this->state['nextTree']['files'] as $file ) if ( ! chmod( $this->directory.'/next/'.$file['path'],$file['path']==='wp-config.php'?0600:0644 ) ) self::fail();
			if ( ! chmod( $this->directory.'/next',$this->state['beforeMode'] ) ) self::fail(); if ( $this->state['status']!=='publishing' ) { $this->state['status']='publishing'; $this->save(); }
			$heartbeat( true ); if ( file_exists( $public_root ) || is_link( $public_root ) || ! rename( $this->directory.'/next',$public_root ) ) self::fail(); self::sync( dirname( $public_root ) ); self::sync( $this->directory ); $this->mark( 'recovered-published' );
		}
		if ( self::tree( $public_root,$this->budget )!==$this->state['nextTree'] || self::tree( $this->directory.'/before',$this->budget )!==$this->state['beforeTree'] ) self::fail(); $heartbeat( true ); $this->state['status']='active'; $this->state['activated']=true; $this->save(); return $this->projection( $db,$this->layout( $public_root ),'isolated-site-activated' );
	}
	public static function activate( #[\SensitiveParameter] array $connection, string $database, string $stage, string $public_root, #[\SensitiveParameter] string $phrase, string $parent, array $policy, ?callable $budget=null, ?callable $checkpoint=null ): array {
		self::local(); $p=self::ordinary( $parent,true ); $r=self::ordinary( $public_root,true,false ); $public_parent=self::ordinary( dirname( $public_root ),true,false ); foreach ( [ $public_root,$stage,dirname( $database ) ] as $other ) self::separate( $parent,$other ); if ( $p['dev']!==$r['dev'] || $r['dev']!==$public_parent['dev'] ) self::fail();
		$lease=self::lease( $parent ); try { return Portable_Recovery_Database::activation_guard( $connection,$database,$public_root,$phrase,static function( $db,$heartbeat,$original_tables ) use ( $connection,$database,$stage,$public_root,$phrase,$parent,$policy,$budget,$checkpoint,$r ) {
			$guard=static function() use ( $budget,$heartbeat ): void { if ( $budget ) $budget(); $heartbeat(); };
			return Portable_Recovery_Stage::with_lease( $stage,static function() use ( $db,$connection,$database,$stage,$public_root,$phrase,$parent,$policy,$guard,$checkpoint,$r,$heartbeat,$original_tables ) {
				$review=Portable_Recovery_Stage::inspect( $stage,$public_root,$phrase,$guard ); if ( $review['receipt']['recoverySha256']!==$db['recoverySha256'] || $review['receipt']['records']['sha256']!==$db['recordsSha256'] ) self::fail(); $before=self::tree( $public_root,$guard ); $expected=self::plan( $db,$review,$before,hash_file( 'sha256',$database.'/database.receipt' ),$original_tables ); self::policy( $policy,$expected );
				$config=Portable_Recovery_Configuration::build( file_get_contents( $public_root.'/wp-config.php' ),$connection,$db['livePrefix'],$db['prefix'],$db['migration']['policy']['destination'] ); $next=self::assembly_plan( $review['inventory'],$before,$config ); $free=disk_free_space( $parent ); if ( ! is_float( $free ) || $free<$next['bytes']+self::MAX_RECEIPT+67108864 || scandir( $parent )!==[ '.','..','.kiwe-activation.lock' ] ) self::fail();
				$owner=new self(); $id=bin2hex( random_bytes( 16 ) ); $owner->directory=$parent.'/kiwe-activation-'.$id; $owner->budget=$guard; $owner->checkpoint=$checkpoint; $owner->cipher=new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase ); if ( ! mkdir( $owner->directory,0700 ) || ! mkdir( $owner->directory.'/next',0700 ) ) self::fail(); self::sync( $owner->directory ); self::sync( $parent );
				$owner->state=[ 'schema'=>self::SCHEMA,'id'=>$id,'revision'=>0,'status'=>'building','publicRoot'=>$public_root,'publicParentBinding'=>self::root_binding( dirname( $public_root ) ),'directoryBinding'=>self::root_binding( $owner->directory ),'beforeBinding'=>self::root_binding( $public_root ),'nextBinding'=>self::binding_at( $owner->directory.'/next',$public_root ),'beforeMode'=>$r['mode']&0777,'database'=>$database,'databaseReceiptSha256'=>$expected['databaseReceiptSha256'],'originalTables'=>$original_tables,'stage'=>$stage,'stageReceiptSha256'=>hash_file( 'sha256',$stage.'/complete.receipt' ),'policy'=>$policy,'beforeTree'=>$before,'nextTree'=>$next,'assembled'=>false,'activeConfigSha256'=>hash( 'sha256',$config ),'heldSourceFiles'=>array_values( array_filter( $review['inventory']['files'],static fn( $file )=>self::held( $file['path'] ) ) ),'activated'=>false,'retirementTree'=>null ]; $owner->save(); return $owner->publish( $db,$public_root,$public_root,$config,$heartbeat );
			} );
		},$budget ); } finally { flock( $lease,LOCK_UN ); fclose( $lease ); }
	}
	private static function recover( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, string $action, ?callable $budget, ?callable $checkpoint ): array {
		self::local(); $lease=self::lease( dirname( $directory ) ); try {
			$owner=self::instance( $directory,$phrase,$budget,$checkpoint ); $layout=$owner->layout( $public_root ); if ( $owner->state['status']==='retiring' ) self::fail();
			$original=$layout['public']==='original'?$public_root:$directory.'/before'; $recovered=$layout['public']==='recovered'?$public_root:( $layout['next'] && $owner->state['assembled']?$directory.'/next':null );
			return Portable_Recovery_Database::activation_checkpoint_guard( $connection,$owner->state['database'],$public_root,$original,$recovered,$phrase,$owner->state['originalTables'],static function( $db,$heartbeat ) use ( $owner,$public_root,$original,$connection,$phrase,$action,$budget ) {
				$owner->budget=static function() use ( $budget,$heartbeat ): void { if ( $budget ) $budget(); $heartbeat(); };
				return Portable_Recovery_Stage::with_lease( $owner->state['stage'],static function() use ( $owner,$db,$heartbeat,$public_root,$original,$connection,$phrase,$action ) {
					$layout=$owner->layout( $public_root ); $config=$owner->context( $db,$public_root,$original,$layout,$connection,$phrase );
					if ( $action==='inspect' ) return $owner->projection( $db,$layout,'verified-activation-checkpoint' );
					if ( $action==='resume' && in_array( $owner->state['status'],[ 'reversing','reversed','cancelled' ],true ) ) self::fail(); $owner->promote();
					if ( $action==='resume' ) return $owner->publish( $db,$public_root,$original,$config,$heartbeat );
					if ( in_array( $owner->state['status'],[ 'reversed','cancelled' ],true ) ) return $owner->projection( $db,$layout,'isolated-activation-reversed');
					if ( $owner->state['status']==='building' ) { $owner->state['retirementTree']=self::tree( $owner->directory.'/next',$owner->budget ); $owner->state['status']='cancelled'; $owner->state['activated']=false; $owner->save(); return $owner->projection( $db,$owner->layout( $public_root ),'cancelled-original-preserved' ); }
					if ( $owner->state['status']!=='reversing' ) { $owner->state['status']='reversing'; $owner->save(); }
					$layout=$owner->layout( $public_root ); $heartbeat( true );
					if ( $layout['public']==='recovered' ) { if ( ! rename( $public_root,$owner->directory.'/next' ) ) self::fail(); self::sync( dirname( $public_root ) ); self::sync( $owner->directory ); $owner->mark( 'recovered-parked' ); }
					$layout=$owner->layout( $public_root ); if ( $layout['next'] && ! chmod( $owner->directory.'/next',0700 ) ) self::fail();
					if ( $layout['public']==='missing' ) { if ( ! chmod( $owner->directory.'/before',$owner->state['beforeMode'] ) || file_exists( $public_root ) || is_link( $public_root ) || ! rename( $owner->directory.'/before',$public_root ) ) self::fail(); self::sync( dirname( $public_root ) ); self::sync( $owner->directory ); $owner->mark( 'original-restored' ); }
					if ( self::root_binding( $public_root )!==$owner->state['beforeBinding'] || self::tree( $public_root,$owner->budget )!==$owner->state['beforeTree'] ) self::fail(); $owner->state['retirementTree']=self::tree( $owner->directory.'/next',$owner->budget ); if ( $owner->state['retirementTree']!==$owner->state['nextTree'] ) self::fail(); $heartbeat( true ); $owner->state['status']='reversed'; $owner->state['activated']=false; $owner->save(); return $owner->projection( $db,$owner->layout( $public_root ),'isolated-activation-reversed' );
				} );
			},$budget );
		} finally { flock( $lease,LOCK_UN ); fclose( $lease ); }
	}
	public static function inspect( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget=null ): array { return self::recover( $connection,$directory,$public_root,$phrase,'inspect',$budget,null ); }
	public static function resume( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget=null, ?callable $checkpoint=null ): array { return self::recover( $connection,$directory,$public_root,$phrase,'resume',$budget,$checkpoint ); }
	public static function reverse( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget=null, ?callable $checkpoint=null ): array { return self::recover( $connection,$directory,$public_root,$phrase,'reverse',$budget,$checkpoint ); }
	/** Retire only exact acknowledged copied bytes; interrupted deletion admits only a decreasing subset. */
	public static function retire( string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget=null, ?callable $checkpoint=null ): void {
		self::local(); $lease=self::lease( dirname( $directory ) ); try {
			$owner=self::instance( $directory,$phrase,$budget,$checkpoint ); $s=$owner->state; $layout=$owner->layout( $public_root ); if ( ! in_array( $s['status'],[ 'reversed','cancelled','retiring' ],true ) || $layout['public']!=='original' || self::tree( $public_root,$budget )!==$s['beforeTree'] ) self::fail();
			$actual=$layout['next']?self::tree( $directory.'/next',$budget ):null; if ( $actual!==null ) { self::subset_plan( $actual,$s['retirementTree'] ); if ( $s['status']!=='retiring' && $actual!==$s['retirementTree'] ) self::fail(); }
			$owner->promote(); if ( $s['status']!=='retiring' ) { $owner->state['status']='retiring'; $owner->save(); }
			if ( $actual!==null ) { foreach ( array_reverse( $actual['files'] ) as $file ) { $owner->tick(); $path=$directory.'/next/'.$file['path']; $stat=self::ordinary( $path,false,false ); if ( $stat['size']!==$file['bytes'] || hash_file( 'sha256',$path )!==$file['sha256'] || ! unlink( $path ) ) self::fail(); self::sync( dirname( $path ) ); $owner->mark( 'retire-file' ); } foreach ( array_reverse( $actual['directories'] ) as $dir ) { if ( ! rmdir( $directory.'/next/'.$dir ) ) self::fail(); self::sync( dirname( $directory.'/next/'.$dir ) ); } if ( ! rmdir( $directory.'/next' ) ) self::fail(); self::sync( $directory ); $owner->mark( 'retire-tree-removed' ); }
			if ( scandir( $directory )!==[ '.','..','activation.receipt' ] || ! unlink( $directory.'/activation.receipt' ) || ! rmdir( $directory ) ) self::fail(); self::sync( dirname( $directory ) );
		} finally { flock( $lease,LOCK_UN ); fclose( $lease ); }
	}
}
