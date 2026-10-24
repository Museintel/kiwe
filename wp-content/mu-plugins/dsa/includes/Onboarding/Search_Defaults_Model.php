<?php
namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One inert defaults validator for native editing, snapshots and pre-boot recovery. */
final class Search_Defaults_Model {
 public const OPTION='kiwe_seo_defaults_v1';
	public const ROBOTS = [ 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ];
	public const VARIABLES = [ 'title', 'sitename', 'sep', 'excerpt', 'category', 'term', 'term_description', 'name', 'date', 'currentyear', 'page', 'tagline' ];

	public static function defaults(): array {
		return [ 'schema'=>1, 'separator'=>'-', 'globalRobots'=>[], 'verification'=>[], 'home'=>[], 'blog'=>[], 'postTypes'=>[], 'taxonomies'=>[], 'archives'=>[], 'policies'=>[ 'redirectDateArchives'=>false, 'redirectAuthorArchives'=>false, 'redirectAttachments'=>false, 'attachmentFallback'=>'', 'noindexEmptyTerms'=>false ], 'social'=>[ 'image'=>'', 'twitterCard'=>'summary_large_image' ], 'sitemap'=>[ 'enabled'=>true, 'pageSize'=>500, 'excludePosts'=>[], 'excludeTerms'=>[], 'authors'=>true ], 'news'=>[ 'enabled'=>false, 'publication'=>'', 'language'=>'' ] ];
	}


	public static function validate( $input ) {
		$fail = static fn() => false;
		if ( ! is_array( $input ) || strlen( (string) json_encode( $input, JSON_INVALID_UTF8_SUBSTITUTE ) ) > 65536 || array_diff( array_keys( $input ), array_keys( self::defaults() ) ) || ( isset( $input['schema'] ) && 1 !== $input['schema'] ) ) return $fail();
		if ( in_array( null, $input, true ) ) return $fail();
		$out = self::defaults();
		if ( isset( $input['separator'] ) ) { if ( ! is_string( $input['separator'] ) || self::length( $input['separator'] ) > 5 || '' === trim( $input['separator'] ) || ! self::text( $input['separator'] ) ) return $fail(); $out['separator'] = $input['separator']; }
		if ( isset( $input['globalRobots'] ) ) { $r = self::validate_robots( $input['globalRobots'] ); if ( false === $r ) return $fail(); $out['globalRobots'] = $r; }
		foreach ( [ 'home', 'blog' ] as $scope ) if ( isset( $input[$scope] ) ) { $rule = self::validate_rule( $input[$scope] ); if ( false === $rule ) return $fail(); $out[$scope] = $rule; }
		foreach ( [ 'postTypes', 'taxonomies', 'archives' ] as $scope ) {
			if ( ! isset( $input[$scope] ) ) continue;
			if ( ! is_array( $input[$scope] ) || count( $input[$scope] ) > 32 ) return $fail();
			foreach ( $input[$scope] as $key=>$rule ) { if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $key ) || false === ( $rule = self::validate_rule( $rule ) ) ) return $fail(); $out[$scope][$key] = $rule; }
		}
		if ( isset( $input['policies'] ) ) {
			$p = $input['policies']; if ( ! is_array( $p ) || in_array( null, $p, true ) || array_diff( array_keys( $p ), array_keys( $out['policies'] ) ) ) return $fail();
			foreach ( [ 'redirectDateArchives', 'redirectAuthorArchives', 'redirectAttachments', 'noindexEmptyTerms' ] as $key ) if ( isset( $p[$key] ) && ! is_bool( $p[$key] ) ) return $fail();
			if ( isset( $p['attachmentFallback'] ) && ( ! is_string( $p['attachmentFallback'] ) || ( '' !== $p['attachmentFallback'] && '' === self::url( $p['attachmentFallback'] ) ) ) ) return $fail();
			$out['policies'] = array_replace( $out['policies'], $p );
		}
		if ( isset( $input['verification'] ) ) {
			$v=$input['verification']; if(!is_array($v)||array_diff(array_keys($v),['google','bing','pinterest']))return $fail();
			foreach($v as$key=>$code){if(!self::verification_code($key,$code))return $fail();$out['verification'][$key]=$code;}
		}
		if ( isset( $input['social'] ) ) {
			$s = $input['social']; if ( ! is_array( $s ) || in_array( null, $s, true ) || array_diff( array_keys( $s ), [ 'image', 'twitterCard' ] ) ) return $fail();
			if ( isset( $s['image'] ) && ( ! is_string( $s['image'] ) || ( '' !== $s['image'] && '' === self::url( $s['image'] ) ) ) ) return $fail();
			if ( isset( $s['twitterCard'] ) && ! in_array( $s['twitterCard'], [ 'summary', 'summary_large_image' ], true ) ) return $fail();
			$out['social'] = array_replace( $out['social'], $s );
		}
		if ( isset( $input['sitemap'] ) ) {
			$s = $input['sitemap']; if ( ! is_array( $s ) || in_array( null, $s, true ) || array_diff( array_keys( $s ), array_keys( $out['sitemap'] ) ) ) return $fail();
			foreach ( [ 'enabled', 'authors' ] as $k ) if ( isset( $s[$k] ) && ! is_bool( $s[$k] ) ) return $fail();
			if ( isset( $s['pageSize'] ) && ( ! is_int( $s['pageSize'] ) || $s['pageSize'] < 50 || $s['pageSize'] > 1000 ) ) return $fail();
			foreach ( [ 'excludePosts', 'excludeTerms' ] as $k ) if ( isset( $s[$k] ) && ( ! is_array( $s[$k] ) || count( $s[$k] ) > 1000 || array_filter( $s[$k], static fn( $id ) => ! is_int( $id ) || $id < 1 ) ) ) return $fail();
			$out['sitemap'] = array_replace( $out['sitemap'], $s );
		}
		if ( isset( $input['news'] ) ) {
			$n = $input['news']; if ( ! is_array( $n ) || in_array( null, $n, true ) || array_diff( array_keys( $n ), [ 'enabled', 'publication', 'language' ] ) || ( isset( $n['enabled'] ) && ! is_bool( $n['enabled'] ) ) ) return $fail();
			foreach ( [ 'publication', 'language' ] as $k ) if ( isset( $n[$k] ) && ( ! is_string( $n[$k] ) || strlen( $n[$k] ) > 200 || ! self::text( $n[$k] ) ) ) return $fail();
			$out['news'] = array_replace( $out['news'], $n );
			if ( $out['news']['enabled'] && ( '' === trim( $out['news']['publication'] ) || ! preg_match( '/^(?:[a-z]{2,3}|zh-cn|zh-tw)$/D', $out['news']['language'] ) ) ) return $fail();
		}
		return $out;
	}


	private static function validate_rule( $r ) {
		if ( ! is_array( $r ) || in_array( null, $r, true ) || array_diff( array_keys( $r ), [ 'title', 'description', 'robots', 'advancedRobots', 'sitemap', 'facebookTitle', 'facebookDescription', 'facebookImage', 'twitterTitle', 'twitterDescription', 'twitterImage' ] ) ) return false;
		foreach ( [ 'title', 'description', 'facebookTitle', 'facebookDescription', 'twitterTitle', 'twitterDescription' ] as $k ) if ( isset( $r[$k] ) && ( ! is_string( $r[$k] ) || strlen( $r[$k] ) > 1000 || ! self::valid_template( $r[$k] ) ) ) return false;
		foreach ( [ 'facebookImage', 'twitterImage' ] as $k ) if ( isset( $r[$k] ) && ( ! is_string( $r[$k] ) || ( '' !== $r[$k] && '' === self::url( $r[$k] ) ) ) ) return false;
		if ( isset( $r['sitemap'] ) && ! is_bool( $r['sitemap'] ) ) return false;
		if ( isset( $r['robots'] ) && false === ( $r['robots'] = self::validate_robots( $r['robots'] ) ) ) return false;
		if ( isset( $r['advancedRobots'] ) ) {
			if ( ! is_array( $r['advancedRobots'] ) || array_diff( array_keys( $r['advancedRobots'] ), [ 'max-snippet', 'max-video-preview', 'max-image-preview' ] ) ) return false;
			foreach ( $r['advancedRobots'] as $key=>$v ) if ( 'max-image-preview' === $key ? ! in_array( $v, [ 'none', 'standard', 'large' ], true ) : ( ! is_int( $v ) || $v < -1 || $v > 100000 ) ) return false;
		}
		return $r;
	}


	public static function validate_robots( $r ) {
		if ( ! is_array( $r ) || count( $r ) > 7 || array_filter( $r, static fn( $v ) => ! is_string( $v ) || ! in_array( $v, self::ROBOTS, true ) ) ) return false;
		if ( ( in_array( 'index', $r, true ) && in_array( 'noindex', $r, true ) ) || ( in_array( 'follow', $r, true ) && in_array( 'nofollow', $r, true ) ) ) return false;
		return array_values( array_unique( $r ) );
	}


	public static function verification_code(string $provider,$value):bool {
		if(!is_string($value))return false;if($value==='')return true;
		return (bool)preg_match($provider==='google'?'/^[A-Za-z0-9_-]{8,200}$/D':'/^[a-fA-F0-9]{32}$/D',$value);
	}
	public static function valid_template( string $value ): bool {
		if ( ! self::text( $value ) ) return false;
		preg_match_all( '/%([a-z0-9_:-]+)%/i', $value, $m );
		return ! array_diff( $m[1], self::VARIABLES );
	}


 private static function text(string $value): bool { return !str_contains($value,"\0")&&preg_match('//u',$value)&&strip_tags($value)===$value; }
 private static function length(string $value): int { $length=preg_match_all('/./us',$value);return false===$length?PHP_INT_MAX:$length; }
 public static function url(string $url): string {
  if(strlen($url)>2048||!preg_match('#^https?://#i',$url)||preg_match('/[\x00-\x20\x7f]/',$url)||!preg_match('//u',$url))return '';
  $parts=parse_url($url);return is_array($parts)&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])?$url:'';
 }
}
