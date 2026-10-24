<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Inert byte transformation. Never deserialize objects or execute captured code. */
final class Portable_Recovery_Migration {
	public const SCHEMA = 'kiwe.private-recovery-migration.v1';
	private array $spec;
	private array $map = [];
	private string $pattern;
	private string $source_prefix;
	private array $seen = [];
	private int $nodes = 0;
	private int $changed = 0;
	private static function fail(): never { throw new Archive_Error( 'The reviewed migration policy or a captured value is unsupported.' ); }
	private static function keys( array $v, array $keys ): bool { return count( $v ) === count( $keys ) && ! array_diff( $keys,array_keys( $v ) ); }
	public static function file( string $path ): array {
		if ( PHP_SAPI !== 'cli' || realpath( $path ) !== $path || strlen( $path ) > 8192 ) self::fail();
		for ( $p = $path; ; $p = dirname( $p ) ) { if ( is_link( $p ) ) self::fail(); if ( dirname( $p ) === $p ) break; }
		$s = lstat( $path );
		if ( ! $s || ( $s['mode'] & 0170000 ) !== 0100000 || $s['nlink'] !== 1 || $s['size'] > 65536 || ( 'Windows' !== PHP_OS_FAMILY && ( ( $s['mode'] & 0077 ) || ( function_exists( 'posix_geteuid' ) && $s['uid'] !== posix_geteuid() ) ) ) ) self::fail();
		try { $v = json_decode( file_get_contents( $path ),true,20,JSON_THROW_ON_ERROR ); } catch ( \Throwable $e ) { self::fail(); } if ( ! is_array( $v ) ) self::fail(); return $v;
	}
	private static function url( mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[^\x21-\x7e]|[\\\\<>\x22\x27]/',$value ) || str_ends_with( $value,'/' ) ) self::fail();
		$p = parse_url( $value ); if ( ! is_array( $p ) || ! in_array( $p['scheme'] ?? '',[ 'http','https' ],true ) || ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$p['host'] ?? '' ) || array_diff( array_keys( $p ),[ 'scheme','host','port','path' ] ) || ( isset( $p['port'] ) && ( $p['port'] < 1 || $p['port'] > 65535 ) ) ) self::fail();
		$path = $p['path'] ?? ''; if ( preg_match( '~(?:^|/)(?:\.|\.\.)(?:/|$)|//|%2[fF]|%5[cC]~',$path ) ) self::fail(); return $value;
	}
	private static function root( mixed $path ): string {
		if ( ! is_string( $path ) || strlen( $path ) > 4096 || ! preg_match( '~^(?:/|[A-Z]:/)[^\\\\\x00-\x1f\x7f]+$~D',$path ) || preg_match( '~(?:^|/)(?:\.|\.\.)(?:/|$)|//~',$path ) || str_ends_with( $path,'/' ) ) self::fail(); return $path;
	}
	/** All dependencies and table suffixes are explicitly reviewed, not silently certified. */
	public static function review( array $spec, array $review, string $public_root ): self {
		if ( ! self::keys( $spec,[ 'schema','recoverySha256','source','destination','versions','dependencies','tableSuffixes','consistencyAcceptance' ] ) || $spec['schema'] !== self::SCHEMA || $spec['recoverySha256'] !== $review['receipt']['recoverySha256'] || $spec['consistencyAcceptance'] !== 'observed-stable-not-atomic' ) self::fail();
		foreach ( [ 'source','destination' ] as $k ) { if ( ! is_array( $spec[$k] ) || ! self::keys( $spec[$k],[ 'home','siteurl','root' ] ) ) self::fail(); self::url( $spec[$k]['home'] ); self::url( $spec[$k]['siteurl'] ); self::root( $spec[$k]['root'] ); }
		if ( $spec['destination']['root'] !== str_replace( '\\','/',rtrim( $public_root,'/\\' ) ) ) self::fail();
		$source = $review['source']; $layout = $source['layout']; $deps = $source['dependencies'];
		if ( $source['scope'] !== 'ordinary-single-site-native-layout' || $layout['content'] !== 'wp-content' || $layout['plugins'] !== 'wp-content/plugins' || $layout['mustUsePlugins'] !== 'wp-content/mu-plugins' || $layout['uploads'] !== 'wp-content/uploads' || $layout['themeRoots'] !== [ 'wp-content/themes' ] || $source['platform']['operatingSystem'] !== PHP_OS_FAMILY ) self::fail();
		if ( $spec['versions'] !== [ 'wordpress'=>$source['platform']['wordpress'],'kiwe'=>$source['platform']['kiwe'] ] || $spec['dependencies'] !== [ 'activePlugins'=>$deps['activePlugins'],'mustUseEntries'=>$deps['mustUseEntries'],'themes'=>$deps['themes'] ] ) self::fail();
		$prefix = $review['database']['prefix']; $suffixes = array_map( static fn( $t )=>substr( $t['name'],strlen( $prefix ) ),$review['database']['tables'] );
		if ( $spec['tableSuffixes'] !== $suffixes ) self::fail();
		foreach ( $review['database']['tables'] as $table ) {
			$suffix = substr( $table['name'],strlen( $prefix ) ); $required = $suffix === 'options' ? [ 'option_name','option_value' ] : ( $suffix === 'usermeta' ? [ 'meta_key','meta_value' ] : [] ); $columns = array_column( $table['columns'],null,'name' );
			foreach ( $required as $name ) if ( ! isset( $columns[$name] ) || ! preg_match( '/^(?:char|varchar|tinytext|text|mediumtext|longtext)\b/i',$columns[$name]['type'] ) || in_array( $name,$table['primary'],true ) ) self::fail();
		}
		$owner = new self(); $owner->spec = $spec; $owner->source_prefix = $prefix;
		foreach ( [ 'home','siteurl','root' ] as $key ) {
			$from = $spec['source'][$key]; $to = $spec['destination'][$key]; $variants = $key === 'root' ? [ $from=>$to ] : [ $from=>$to,preg_replace( '~^https?://~','http://',$from )=>$to,preg_replace( '~^https?://~','https://',$from )=>$to,preg_replace( '~^https?://~','//',$from )=>preg_replace( '~^https?://~','//',$to ) ];
			foreach ( $variants as $old=>$new ) { if ( isset( $owner->map[$old] ) && $owner->map[$old]['to'] !== $new ) self::fail(); $owner->map[$old] = [ 'to'=>$new,'root'=>$key === 'root' ]; }
		}
		uksort( $owner->map,static fn( $a,$b )=>strlen( $b ) <=> strlen( $a ) ?: strcmp( $a,$b ) );
		$patterns = []; foreach ( $owner->map as $old=>$rule ) { $left = $rule['root'] ? '[a-zA-Z0-9_./\\\\-]' : ( str_starts_with( $old,'//' ) ? '[a-zA-Z0-9+.:/-]' : '[a-zA-Z0-9+.-]' ); $right = $rule['root'] ? '[/\s\x22\x27<>),;]' : '[/\?#\s\x22\x27<>),;]'; $patterns[] = '(?<!'.$left.')'.preg_quote( $old,'~' ).'(?=$|'.$right.')'; } $owner->pattern = '~'.implode( '|',$patterns ).'~'; return $owner;
	}
	public function policy(): array { return $this->spec; }
	public function sha256(): string { return hash( 'sha256',json_encode( $this->spec,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ); }
	public function changed(): int { return $this->changed; }
	public function complete(): void { if ( count( $this->seen ) !== 3 || ( $this->seen['home'] ?? 0 ) !== 1 || ( $this->seen['siteurl'] ?? 0 ) !== 1 || ( $this->seen['roles'] ?? 0 ) !== 1 ) self::fail(); }
	private function plain( string $value ): string {
		if ( PHP_OS_FAMILY === 'Windows' && str_contains( $value,str_replace( '/','\\',$this->spec['source']['root'] ) ) ) self::fail();
		$result = preg_replace_callback( $this->pattern,fn( $m )=>$this->map[$m[0]]['to'],$value );
		if ( ! is_string( $result ) || strlen( $result ) > Portable_Database_Capture::MAX_ROW_BYTES ) self::fail(); return $result;
	}
	private static function serialized( string $value ): bool { return (bool)preg_match( '/^(?:a:[0-9]+:\{|s:[0-9]+:"|[bidORrCE]:|N;)/',$value ); }
	public function value( string $value, int $depth = 0, bool $native_string = false ): string {
		if ( strlen( $value ) > Portable_Database_Capture::MAX_ROW_BYTES || $depth > 64 ) self::fail();
		$serialized = self::serialized( $value );
		// token() already counts the typed node, including quoted strings. Count
		// each logical node once; dispatching its string contents is not another node.
		if ( ! $native_string && ! $serialized && ++$this->nodes > 100000 ) self::fail();
		// A quoted PHP/JSON string is text. Scalar-looking text (for example i::before
		// CSS) cannot contain a serialized scalar that needs URL migration. Preserve its
		// type and map its literal URLs; top-level malformed scalar encodings still refuse.
		if ( $native_string && preg_match( '/^[bid]:/D',$value ) ) return $this->plain( $value );
		if ( $serialized ) { $at = 0; $result = $this->token( $value,$at,$depth ); if ( $at !== strlen( $value ) ) self::fail(); return $result; }
		if ( str_starts_with( $value,'{' ) || str_starts_with( $value,'[' ) ) {
			if ( substr_count( $value,',' )+substr_count( $value,'{' )+substr_count( $value,'[' ) > 100000 ) self::fail();
			// Validate JSON, then rewrite only strings; never round-trip large numbers through floats.
			try { json_decode( $value,true,64,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING ); } catch ( \Throwable $e ) { if ( $this->plain( $value ) !== $value ) self::fail(); return $value; }
			$result = preg_replace_callback( '/"(?:[^"\\\\\x00-\x1f]|\\\\.)*"\s*:|"(?:[^"\\\\\x00-\x1f]|\\\\.)*"/s',function( $m ) use ( $depth ): string { if ( str_ends_with( $m[0],':' ) ) return $m[0]; $decoded = json_decode( $m[0],true,4,JSON_THROW_ON_ERROR ); $new = $this->value( $decoded,$depth+1,true ); if ( $new === $decoded ) return $m[0]; return json_encode( $new,JSON_UNESCAPED_UNICODE | ( str_contains( $m[0],'\\/' ) ? 0 : JSON_UNESCAPED_SLASHES ) | JSON_THROW_ON_ERROR ); },$value );
			if ( ! is_string( $result ) || strlen( $result ) > Portable_Database_Capture::MAX_ROW_BYTES ) self::fail(); return $result;
		}
		return $this->plain( $value );
	}
	private static function take( string $s, int &$at, string $literal ): void { if ( substr( $s,$at,strlen( $literal ) ) !== $literal ) self::fail(); $at += strlen( $literal ); }
	private static function digits( string $s, int &$at, int $max ): int { $end = strpos( $s,':',$at ); if ( false === $end || $end-$at > 9 || ! preg_match( '/^(?:0|[1-9][0-9]*)$/D',substr( $s,$at,$end-$at ) ) ) self::fail(); $n = (int)substr( $s,$at,$end-$at ); if ( $n > $max ) self::fail(); $at = $end+1; return $n; }
	private function token( string $s, int &$at, int $depth, bool $key = false, bool $property = false ): string {
		if ( $depth > 64 || ++$this->nodes > 100000 ) self::fail(); $kind = $s[$at] ?? ''; $start = $at;
		if ( $key && ! in_array( $kind,[ 'i','s' ],true ) ) self::fail();
		if ( $kind === 'N' ) { self::take( $s,$at,'N;' ); return 'N;'; }
		if ( in_array( $kind,[ 'b','i','d' ],true ) ) {
			$end = strpos( $s,';',$at ); if ( false === $end || $end-$at > 64 ) self::fail(); $part = substr( $s,$at,$end-$at+1 );
			$pattern = match ( $kind ) { 'b'=>'/\Ab:[01];\z/','i'=>'/\Ai:-?(?:0|[1-9][0-9]{0,19});\z/',default=>'/\Ad:(?:NAN|-?INF|-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[Ee][+-]?[0-9]+)?);\z/' }; if ( ! preg_match( $pattern,$part ) ) self::fail(); $at = $end+1; return $part;
		}
		if ( $kind === 's' ) {
			self::take( $s,$at,'s:' ); $n = self::digits( $s,$at,Portable_Database_Capture::MAX_ROW_BYTES ); self::take( $s,$at,'"' ); $bytes = substr( $s,$at,$n ); if ( strlen( $bytes ) !== $n ) self::fail(); $at += $n; self::take( $s,$at,'";' );
			$new = $property ? $bytes : $this->value( $bytes,$depth+1,true ); return 's:'.strlen( $new ).':"'.$new.'";';
		}
		if ( $kind === 'a' || $kind === 'O' ) {
			self::take( $s,$at,$kind.':' );
			if ( $kind === 'O' ) { $n = self::digits( $s,$at,1024 ); self::take( $s,$at,'"' ); $class = substr( $s,$at,$n ); if ( ! preg_match( '/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/D',$class ) ) self::fail(); $at += $n; self::take( $s,$at,'":' ); }
			$n = self::digits( $s,$at,50000 ); self::take( $s,$at,'{' ); $out = substr( $s,$start,$at-$start );
			$keys = []; for ( $i = 0; $i < $n; ++$i ) { if ( $kind === 'O' && ( $s[$at] ?? '' ) !== 's' ) self::fail(); $key_bytes = $this->token( $s,$at,$depth+1,true,$kind === 'O' ); if ( isset( $keys[$key_bytes] ) ) self::fail(); $keys[$key_bytes] = true; $out .= $key_bytes.$this->token( $s,$at,$depth+1 ); if ( strlen( $out ) > Portable_Database_Capture::MAX_ROW_BYTES ) self::fail(); }
			self::take( $s,$at,'}' ); return $out.'}';
		}
		// Opaque custom objects, references and enums need a dedicated adapter; do not guess.
		self::fail();
	}
	public function row( array $table, array $cells, string $target_prefix ): array {
		$this->nodes = 0; $suffix = substr( $table['name'],strlen( $this->source_prefix ) ); $names = array_column( $table['columns'],'name' ); $positions = array_flip( $names ); $before = $cells;
		// Stored search labels, hashes and historical scopes describe their source website. Rewriting
		// labels without their composite hashes would fabricate provenance and break relationships.
		if(in_array($suffix,['kiwe_search_buckets_v1','kiwe_search_rows_v1','stp_report_days','stp_report_sessions','stp_report_activity'],true))return $cells;
		if ( $suffix === 'options' ) {
			if ( ! isset( $positions['option_name'],$positions['option_value'] ) ) self::fail(); $key = $cells[$positions['option_name']]; $value = $cells[$positions['option_value']];
			if ( in_array( $key,[ 'home','siteurl',$this->source_prefix.'user_roles' ],true ) ) {
				$label = $key === $this->source_prefix.'user_roles' ? 'roles' : $key; if ( isset( $this->seen[$label] ) || ( $label !== 'roles' && $value !== $this->spec['source'][$label] ) ) self::fail(); $this->seen[$label] = 1;
				if ( $label === 'roles' ) $cells[$positions['option_name']] = $target_prefix.'user_roles';
			}
		} elseif ( $suffix === 'usermeta' ) {
			if ( ! isset( $positions['meta_key'],$positions['meta_value'] ) ) self::fail(); $key = $cells[$positions['meta_key']]; if ( is_string( $key ) && str_starts_with( $key,$this->source_prefix ) ) $cells[$positions['meta_key']] = $target_prefix.substr( $key,strlen( $this->source_prefix ) );
		}
		foreach ( $table['columns'] as $i=>$column ) {
			if ( null === $cells[$i] || in_array( $column['name'],$table['primary'],true ) || ( $suffix === 'posts' && $column['name'] === 'guid' ) || ( $suffix === 'users' && $column['name'] !== 'user_url' ) || in_array( $column['name'],[ 'option_name','meta_key' ],true ) || ! preg_match( '/^(?:char|varchar|tinytext|text|mediumtext|longtext)\b/i',$column['type'] ) ) continue;
			$cells[$i] = $this->value( $cells[$i] );
		}
		foreach ( $cells as $i=>$cell ) if ( $cell !== $before[$i] ) ++$this->changed; return $cells;
	}
}
