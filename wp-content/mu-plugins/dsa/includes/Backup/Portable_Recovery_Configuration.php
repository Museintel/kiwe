<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Inert, bounded prepared configuration. Never include captured or prepared PHP here. */
final class Portable_Recovery_Configuration {
	private const REQUIRED = [ 'DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT' ];
	private const ALLOWED = [ 'DB_CHARSET','DB_COLLATE','WP_DEBUG','WP_DEBUG_DISPLAY','WP_DEBUG_LOG','WP_CACHE','WP_CACHE_KEY_SALT','WP_MEMORY_LIMIT','WP_MAX_MEMORY_LIMIT','WP_ENVIRONMENT_TYPE','DISABLE_WP_CRON','AUTOMATIC_UPDATER_DISABLED','WP_AUTO_UPDATE_CORE','DISALLOW_FILE_EDIT','DISALLOW_FILE_MODS','FORCE_SSL_ADMIN','WP_HOME','WP_SITEURL','ABSPATH' ];
	private static function fail(): never { throw new Archive_Error( 'An ordinary supported prepared configuration is required.' ); }
	private static function literal( $token ): mixed {
		if ( ! is_array( $token ) ) self::fail();
		if ( $token[0] === T_STRING && in_array( strtolower( $token[1] ),[ 'true','false' ],true ) ) return strtolower( $token[1] ) === 'true';
		if ( $token[0] !== T_CONSTANT_ENCAPSED_STRING ) self::fail();
		$text = $token[1]; $body = substr( $text,1,-1 );
		if ( $text[0] === "'" ) return strtr( $body,[ "\\\\"=>"\\","\\'"=>"'" ] );
		return preg_replace_callback( '/\\\\(?:[\\\\"$nrtvef]|[0-7]{1,3}|x[0-9a-fA-F]{1,2})/',static function( $m ) {
			$c = substr( $m[0],1 ); $fixed = [ '\\'=>'\\','"'=>'"','$'=>'$','n'=>"\n",'r'=>"\r",'t'=>"\t",'v'=>"\v",'e'=>chr( 27 ),'f'=>"\f" ];
			return $fixed[$c] ?? chr( $c[0] === 'x' ? hexdec( substr( $c,1 ) ) : octdec( $c ) );
		},$body );
	}
	/** Only ordinary define/prefix statements and the standard native bootstrap are executable. */
	private static function syntax( array $entries ): void {
		$t=array_column( $entries,0 ); $n=count( $t ); $i=0;
		if ( ! is_array( $t[$i] ?? null ) || $t[$i++][0]!==T_OPEN_TAG ) self::fail();
		$constant=static fn( $v )=>is_array( $v )&&$v[0]===T_STRING?$v[1]:null;
		$define=static function( bool $only_abspath = false ) use ( &$i,$t,$constant ): void {
			if ( strtolower( (string)$constant( $t[$i] ?? null ) )!=='define' || ( $t[$i+1]??null )!=='(' || ( $t[$i+3]??null )!==',' ) self::fail(); $key=self::literal( $t[$i+2]??null ); $at=$i+4;
			if ( $key==='ABSPATH' ) {
				if ( is_array( $t[$at]??null ) && $t[$at][0]===T_DIR ) ++$at;
				elseif ( $constant( $t[$at]??null )==='dirname' && ( $t[$at+1]??null )==='(' && is_array( $t[$at+2]??null ) && $t[$at+2][0]===T_FILE && ( $t[$at+3]??null )===')' ) $at+=4;
				else self::fail();
				if ( ( $t[$at]??null )!=='.' || self::literal( $t[$at+1]??null )!=='/' ) self::fail(); $at+=2;
			} else { if ( $only_abspath ) self::fail(); self::literal( $t[$at]??null ); ++$at; }
			if ( ( $t[$at]??null )!==')' || ( $t[$at+1]??null )!==';' ) self::fail(); $i=$at+2;
		};
		while ( $i<$n ) {
			$v=$t[$i];
			if ( strtolower( (string)$constant( $v ) )==='define' ) { $define(); continue; }
			if ( is_array( $v ) && $v[0]===T_VARIABLE && $v[1]==='$table_prefix' && ( $t[$i+1]??null )==='=' && ( $t[$i+3]??null )===';' ) { self::literal( $t[$i+2] ); $i+=4; continue; }
			if ( is_array( $v ) && $v[0]===T_IF ) {
				if ( ( $t[$i+1]??null )!=='(' || ( $t[$i+2]??null )!=='!' || $constant( $t[$i+3]??null )!=='defined' || ( $t[$i+4]??null )!=='(' || self::literal( $t[$i+5]??null )!=='ABSPATH' || ( $t[$i+6]??null )!==')' || ( $t[$i+7]??null )!==')' || ( $t[$i+8]??null )!=='{' ) self::fail(); $i+=9; $define( true ); if ( ( $t[$i++]??null )!=='}' ) self::fail(); continue;
			}
			if ( is_array( $v ) && in_array( $v[0],[ T_REQUIRE,T_REQUIRE_ONCE ],true ) && $constant( $t[$i+1]??null )==='ABSPATH' && ( $t[$i+2]??null )==='.' && self::literal( $t[$i+3]??null )==='wp-settings.php' && ( $t[$i+4]??null )===';' && $i+5===$n ) { $i+=5; continue; }
			self::fail();
		}
	}
	/** Preserve every prepared byte except the one prefix and explicit replay/cache guards. */
	public static function build( #[\SensitiveParameter] string $bytes, #[\SensitiveParameter] array $connection, string $old_prefix, string $new_prefix, array $urls ): string {
		if ( strlen( $bytes ) > 1048576 || ! str_starts_with( $bytes,'<?php' ) || ! preg_match( '/^[a-zA-Z0-9_]{1,48}$/D',$old_prefix ) || ! preg_match( '/^krd_[a-f0-9]{12,24}_$/D',$new_prefix ) || array_keys( $urls ) !== [ 'home','siteurl','root' ] ) self::fail();
		try { $raw = token_get_all( $bytes,TOKEN_PARSE ); } catch ( \Throwable $e ) { self::fail(); }
		$tokens = []; $offset = 0;
		foreach ( $raw as $token ) { $text = is_array( $token ) ? $token[1] : $token; if ( ! is_array( $token ) || ! in_array( $token[0],[ T_WHITESPACE,T_COMMENT,T_DOC_COMMENT ],true ) ) $tokens[] = [ $token,$offset,strlen( $text ) ]; $offset += strlen( $text ); }
		self::syntax( $tokens ); $defines = []; $replacements = []; $prefix = null; $depth = 0; $require = 0;
		foreach ( $tokens as $i=>$entry ) {
			$t = $entry[0]; if ( $t === '{' ) ++$depth; elseif ( $t === '}' ) --$depth;
			if ( is_array( $t ) && in_array( $t[0],[ T_EVAL,T_INCLUDE,T_INCLUDE_ONCE,T_FUNCTION,T_FN,T_CLASS,T_NEW,T_VARIABLE,T_REQUIRE,T_REQUIRE_ONCE,T_INLINE_HTML,T_EXIT,T_ECHO,T_PRINT,T_HALT_COMPILER ],true ) ) {
				if ( $t[0] === T_VARIABLE && $t[1] === '$table_prefix' && $depth === 0 && ( $tokens[$i+1][0] ?? null ) === '=' && ( $tokens[$i+3][0] ?? null ) === ';' && $prefix === null ) {
					$value = self::literal( $tokens[$i+2][0] ?? null ); if ( $value !== $old_prefix ) self::fail(); $prefix = $value; $replacements[] = [ $tokens[$i+2][1],$tokens[$i+2][2],var_export( $new_prefix,true ) ]; continue;
				}
				if ( in_array( $t[0],[ T_REQUIRE,T_REQUIRE_ONCE ],true ) && ++$require === 1 ) {
					$seq = array_column( array_slice( $tokens,$i+1,4 ),0 ); if ( ! is_array( $seq[0] ?? null ) || $seq[0][0] !== T_STRING || $seq[0][1] !== 'ABSPATH' || ( $seq[1] ?? null ) !== '.' || self::literal( $seq[2] ?? null ) !== 'wp-settings.php' || ( $seq[3] ?? null ) !== ';' ) self::fail(); continue;
				}
				self::fail();
			}
			if ( is_array( $t ) && $t[0] === T_STRING && ( $tokens[$i+1][0] ?? null ) === '(' ) {
				if ( ! in_array( strtolower( $t[1] ),[ 'define','defined','dirname' ],true ) ) self::fail();
				if ( strtolower( $t[1] ) === 'dirname' && ( ! is_array( $tokens[$i+2][0] ?? null ) || $tokens[$i+2][0][0] !== T_FILE || ( $tokens[$i+3][0] ?? null ) !== ')' ) ) self::fail();
				if ( strtolower( $t[1] ) !== 'define' ) continue;
				$key = self::literal( $tokens[$i+2][0] ?? null ); if ( ! is_string( $key ) || isset( $defines[$key] ) || ! in_array( $key,array_merge( self::REQUIRED,self::ALLOWED ),true ) || ( $tokens[$i+3][0] ?? null ) !== ',' ) self::fail();
				if ( $key === 'ABSPATH' ) { $defines[$key] = true; continue; }
				if ( $depth !== 0 || ( $tokens[$i+5][0] ?? null ) !== ')' || ( $tokens[$i+6][0] ?? null ) !== ';' ) self::fail();
				$value = self::literal( $tokens[$i+4][0] ?? null ); $defines[$key] = $value;
				if ( in_array( $key,[ 'WP_CACHE','DISABLE_WP_CRON','AUTOMATIC_UPDATER_DISABLED' ],true ) ) $replacements[] = [ $tokens[$i+4][1],$tokens[$i+4][2],$key === 'WP_CACHE' ? 'false' : 'true' ];
			}
		}
		if ( $depth !== 0 || $prefix === null || $require !== 1 || ! isset( $defines['ABSPATH'] ) || array_diff( self::REQUIRED,array_keys( $defines ) ) ) self::fail();
		foreach ( self::REQUIRED as $key ) if ( ! is_string( $defines[$key] ) || $defines[$key] === '' || str_contains( $defines[$key],"\0" ) ) self::fail();
		if ( $defines['DB_HOST'] !== $connection['host'] || $connection['port'] !== 3306 || $defines['DB_NAME'] !== $connection['database'] || $defines['DB_USER'] !== $connection['username'] || $defines['DB_PASSWORD'] !== $connection['password'] ) self::fail();
		$salts = array_intersect_key( $defines,array_fill_keys( array_slice( self::REQUIRED,4 ),true ) ); if ( count( array_unique( $salts ) ) !== 8 ) self::fail(); foreach ( $salts as $salt ) if ( strlen( $salt ) < 32 || strlen( $salt ) > 4096 ) self::fail();
		foreach ( [ 'WP_HOME'=>'home','WP_SITEURL'=>'siteurl' ] as $key=>$url ) if ( isset( $defines[$key] ) && $defines[$key] !== $urls[$url] ) self::fail();
		// Guard definitions must precede the trusted native bootstrap, even if absent in the original.
		$guards = ''; foreach ( [ 'WP_CACHE'=>false,'DISABLE_WP_CRON'=>true,'AUTOMATIC_UPDATER_DISABLED'=>true ] as $key=>$value ) if ( ! array_key_exists( $key,$defines ) ) $guards .= 'define( '.var_export( $key,true ).', '.( $value ? 'true' : 'false' )." );\n";
		$replacements[] = [ strlen( '<?php' ),0,"\n".$guards ]; usort( $replacements,static fn( $a,$b )=>$b[0]<=>$a[0] );
		foreach ( $replacements as [ $at,$length,$value ] ) $bytes = substr_replace( $bytes,$value,$at,$length );
		return $bytes;
	}
}
