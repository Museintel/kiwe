<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native font records share the media job's encrypted bytes, journal and attachment mapping. */
final class Bricks_Font_Archive_Service {
	public const SCOPE = 'bricks_fonts';
	public const MAX_FONTS = 100;
	public const MARKER = '_kiwe_bricks_font_recovery';
	public static function available(): bool { return Bricks_Design_Snapshot_Service::available() && class_exists( '\Bricks\Custom_Fonts' ) && defined( 'BRICKS_DB_CUSTOM_FONTS' ) && defined( 'BRICKS_DB_CUSTOM_FONT_FACES' ) && defined( 'BRICKS_DB_CUSTOM_FONT_FACE_RULES' ); }
	public static function allowed(): bool { return self::available() && Bricks_Design_Snapshot_Service::allowed() && Media_Archive_Service::can_use() && \Bricks\Builder_Permissions::user_has_permission( 'access_font_manager' ) && current_user_can( 'create_custom_fonts' ) && current_user_can( 'publish_custom_fonts' ); }
	public static function fingerprint( int $id ): string {
		$post = get_post( $id );
		return $post ? hash( 'sha256', (string) wp_json_encode( [ (array) $post, get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES ), get_post_meta( $id, self::MARKER ) ] ) ) : '';
	}
	/** Preserve legacy faces and the native list-of-subsets shape exactly. No arbitrary CSS. */
	public static function faces( array $faces, array $map = [] ): array {
		if ( ! $faces || count( $faces ) > 100 ) throw new Archive_Error( 'A font has no supported registered faces.' );
		foreach ( $faces as $variant=>&$face ) {
			if ( ! preg_match( '/^(?:[1-9]00)?(?:normal|italic|oblique)?$/D', (string) $variant ) || '' === (string) $variant || ! is_array( $face ) || ! $face ) throw new Archive_Error( 'A font variant is unsupported.' );
			$subsets = array_is_list( $face ) ? $face : [ $face ];
			if ( count( $subsets ) > 100 ) throw new Archive_Error( 'A font has too many subsets.' );
			foreach ( $subsets as &$subset ) {
				if ( ! is_array( $subset ) || ! $subset || array_diff( array_keys( $subset ), [ 'woff2','woff','ttf','otf','eot','unicode-range' ] ) ) throw new Archive_Error( 'A font face contains unsupported fields.' );
				$files = 0;
				foreach ( $subset as $format=>&$value ) {
					if ( 'unicode-range' === $format ) { if ( ! is_string( $value ) || strlen( $value ) > 2000 || ! preg_match( '/^U\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?(?:\s*,\s*U\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?)*$/iD', $value ) ) throw new Archive_Error( 'A font unicode range is unsupported.' ); continue; }
					if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) throw new Archive_Error( 'A font attachment ID is invalid.' );
					$id = (int) $value; if ( $id < 1 || ( $map && ! isset( $map[$id] ) ) ) throw new Archive_Error( 'A font file is missing from its recovery map.' );
					if ( $map ) $value = is_string( $value ) ? (string) $map[$id]['id'] : $map[$id]['id']; ++$files;
				} unset( $value );
				if ( ! $files ) throw new Archive_Error( 'A font subset has no files.' );
			} unset( $subset );
			$face = array_is_list( $face ) ? $subsets : $subsets[0];
		} unset( $face );
		return $faces;
	}
	public static function file_ids( array $faces ): array {
		$ids = []; $walk = static function( array $items ) use ( &$walk,&$ids ): void { foreach ( $items as $key=>$value ) { if ( is_array( $value ) ) $walk( $value ); elseif ( 'unicode-range' !== $key ) $ids[(int) $value] = true; } }; $walk( self::faces( $faces ) ); return array_keys( $ids );
	}
	private static function title( string $title ): void { if ( '' === trim( $title ) || strlen( $title ) > 240 || preg_match( '/[<>"\'{};\\\\\x00-\x1f]/', $title ) ) throw new Archive_Error( 'This font family needs a safe native name before backup.' ); }
	public static function plan( ?array $selected = null ): array {
		if ( ! self::allowed() ) throw new Archive_Error( 'Native Bricks font backup is unavailable for your account.' );
		if ( null !== $selected && ( ! $selected || ! array_is_list( $selected ) || count( $selected ) > self::MAX_FONTS || count( array_unique( $selected, SORT_REGULAR ) ) !== count( $selected ) || array_filter( $selected, static fn($id)=>! is_int($id) || $id < 1 ) ) ) throw new Archive_Error( 'The native font selection is invalid.' );
		$posts = get_posts( [ 'post_type'=>BRICKS_DB_CUSTOM_FONTS,'post_status'=>'publish','posts_per_page'=>self::MAX_FONTS+1,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,...( null === $selected ? [] : [ 'post__in'=>$selected ] ) ] );
		if ( null !== $selected && count($posts) !== count($selected) ) throw new Archive_Error( 'A selected native font is missing.' );
		if ( ! $posts || count( $posts ) > self::MAX_FONTS ) throw new Archive_Error( 'Choose a website with one to 100 published custom fonts for this bounded backup.' );
		$fonts = []; $records = []; $raw = 0; $files = 0;
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) throw new Archive_Error( 'A native font is unavailable for your account.' ); self::title( $post->post_title );
			$faces = get_post_meta( $post->ID, BRICKS_DB_CUSTOM_FONT_FACES, true ); if ( ! is_array( $faces ) ) throw new Archive_Error( 'A native font has unsupported faces.' ); $faces = self::faces( $faces );
			foreach ( self::file_ids( $faces ) as $id ) if ( ! isset( $records[$id] ) ) { $record = Media_Archive_Service::capture( $id ); self::font_file( $record ); $records[$id] = $record; $raw += array_sum( array_column( $record['files'],'bytes' ) ); $files += count( $record['files'] ); }
			$fonts[] = [ 'id'=>(int) $post->ID,'title'=>$post->post_title,'faces'=>$faces,'fingerprint'=>self::fingerprint( (int) $post->ID ) ];
		}
		if ( count( $records ) > Media_Archive_Service::MAX_ATTACHMENTS || $raw > Media_Archive_Service::MAX_RAW_BYTES || $files > Media_Archive_Service::MAX_FILES ) throw new Archive_Error( 'Custom font files exceed the bounded media backup limit.' );
		return [ 'scope'=>self::SCOPE,'sourceId'=>0,'label'=>'Bricks custom fonts','selection'=>[ 'from'=>'','to'=>'','timezone'=>wp_timezone_string() ],'records'=>array_values( $records ),'rawBytes'=>$raw,'fileCount'=>$files,'uploadsUrl'=>Media_Archive_Service::uploads()['url'],'fonts'=>$fonts ];
	}
	/** One exact dependency set reuses native media chunks and font copy journals. */
	public static function design_plan( array $media, array $fonts ): array {
		if ( ! Bricks_Design_Snapshot_Service::allowed() || ! Media_Archive_Service::can_use() || ! array_is_list($media) || count($media)>Media_Archive_Service::MAX_ATTACHMENTS || count(array_unique($media,SORT_REGULAR))!==count($media) || array_filter($media,static fn($id)=>!is_int($id)||$id<1) ) throw new Archive_Error('The native design asset selection is invalid.');
		$plan = $fonts ? self::plan($fonts) : [ 'fonts'=>[],'records'=>[] ]; $records = [];
		foreach ($plan['records'] as $record) $records[$record['id']]=$record;
		foreach ($media as $id) { $record=Media_Archive_Service::capture($id); if (!str_starts_with($record['mime'],'image/')) throw new Archive_Error('A design image dependency is not a native image.'); $records[$id]=$record; }
		ksort($records,SORT_NUMERIC);sort($media,SORT_NUMERIC);
		$raw=0;$files=0;foreach($records as $record){$raw+=array_sum(array_column($record['files'],'bytes'));$files+=count($record['files']);}
		if (!$records || count($records)>Media_Archive_Service::MAX_ATTACHMENTS || $raw>Media_Archive_Service::MAX_RAW_BYTES || $files>Media_Archive_Service::MAX_FILES) throw new Archive_Error('Design assets exceed the bounded media set.');
		return ['scope'=>'bricks_design_assets','sourceId'=>0,'label'=>'Bricks design assets','selection'=>['from'=>'','to'=>'','timezone'=>wp_timezone_string()],'records'=>array_values($records),'rawBytes'=>$raw,'fileCount'=>$files,'uploadsUrl'=>Media_Archive_Service::uploads()['url'],'fonts'=>$plan['fonts'],'designMedia'=>$media];
	}
	private static function font_file( array $record ): void { if ( ! in_array( strtolower( pathinfo( $record['attached'],PATHINFO_EXTENSION ) ),[ 'woff2','woff','ttf','otf','eot' ],true ) || ! preg_match( '~^(?:font/|application/(?:font-|x-font-|vnd\.ms-fontobject))~', $record['mime'] ) ) throw new Archive_Error( 'A font references a non-font attachment.' ); }
	public static function validate_plan( array $plan ): void {
		if ( ( ! empty($plan['fonts']) ? ! self::allowed() : ! Bricks_Design_Snapshot_Service::allowed() ) || ! is_array( $plan['fonts'] ?? null ) || ! array_is_list( $plan['fonts'] ) || ( ! $plan['fonts'] && 'bricks_design_assets' !== ($plan['scope']??'') ) || count( $plan['fonts'] ) > self::MAX_FONTS ) throw new Archive_Error( 'The native font archive is unavailable or invalid.' );
		$files = []; foreach ( $plan['records'] as $record ) { if(!is_array($record)) throw new Archive_Error('A font attachment is invalid.'); Media_Archive_Service::validate_record($record); $files[$record['id']] = $record; } $used = []; $seen = [];
		foreach ( $plan['fonts'] as $font ) {
			if ( ! is_array( $font ) || array_keys( $font ) !== [ 'id','title','faces','fingerprint' ] || ! is_int( $font['id'] ) || $font['id'] < 1 || isset( $seen[$font['id']] ) || ! is_string( $font['title'] ) || ! is_array( $font['faces'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$font['fingerprint'] ?? '' ) ) throw new Archive_Error( 'A font record is invalid or repeated.' );
			self::title( $font['title'] ); $seen[$font['id']] = true;
			foreach ( self::file_ids( $font['faces'] ) as $id ) { if ( ! isset( $files[$id] ) ) throw new Archive_Error( 'A font attachment is missing from the encrypted archive.' ); self::font_file($files[$id]); $used[$id] = true; }
		}
		if ('bricks_design_assets' === ($plan['scope']??'')) {
			$media=$plan['designMedia']??null;if(!is_array($media)||!array_is_list($media)||count($media)>Media_Archive_Service::MAX_ATTACHMENTS||count(array_unique($media,SORT_REGULAR))!==count($media))throw new Archive_Error('Design media inventory is invalid.');
			foreach($media as $id){if(!is_int($id)||$id<1||!isset($files[$id])||!str_starts_with($files[$id]['mime'],'image/'))throw new Archive_Error('A design image is missing from its media set.');$used[$id]=true;}
		} elseif(isset($plan['designMedia']))throw new Archive_Error('Ordinary font archives cannot declare design images.');
		if ( array_diff_key( $files,$used ) ) throw new Archive_Error( 'The font archive contains unrelated attachments.' );
	}
	public static function assert_sources( array $fonts ): void { if ( ! self::allowed() ) throw new Archive_Error( 'Font backup permission changed.' ); foreach ( $fonts as $font ) if ( ! current_user_can( 'read_post',$font['id'] ) || ! hash_equals( $font['fingerprint'],self::fingerprint( $font['id'] ) ) ) throw new Archive_Error( 'A native font changed during capture. Start a new backup.' ); }
	public static function refresh(): void {
		\Bricks\Custom_Fonts::$fonts = false; \Bricks\Custom_Fonts::$font_face_rules = ''; \Bricks\Custom_Fonts::get_custom_fonts();
		$rules = \Bricks\Custom_Fonts::$font_face_rules;
		if ( $rules ) update_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES,$rules,false ); else delete_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES );
		if ( (string) get_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES,'' ) !== $rules ) throw new Archive_Error( 'Bricks could not confirm its native font CSS refresh.' );
	}
	/** Resume a native copy by its journal identity. A changed copy is always preserved. */
	public static function copy( array $font, array $map, string $job, callable $journal, int $pending_id = 0 ): int {
		if ( ! self::allowed() ) throw new Archive_Error( 'Font recovery permission changed.' );
		$faces = self::faces( $font['faces'],$map ); $marker = $job . ':' . $font['id']; $guid = home_url( '/?kiwe-font-recovery=' . $marker );
		global $wpdb; $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND guid = %s LIMIT 2",BRICKS_DB_CUSTOM_FONTS,$guid ) );
		if ( count( $ids ) > 1 ) throw new Archive_Error( 'An interrupted font identity is ambiguous. Copies are preserved.' );
		if ( $pending_id && ( ! $ids || (int) $ids[0] !== $pending_id ) ) throw new Archive_Error( 'An interrupted font identity changed. The copy is preserved.' );
		$id = $pending_id ?: ( $ids ? (int) $ids[0] : 0 );
		if ( ! $id ) {
			$track = static function( $id,$post ) use ( $guid,$journal ): void { if ( $post->post_type === BRICKS_DB_CUSTOM_FONTS && $post->guid === $guid ) $journal( (int) $id ); };
			add_action( 'save_post',$track,PHP_INT_MIN,2 );
			$guard = static function($data,$args,$raw,$update) use ($guid) { if ( ! $update && ($raw['guid'] ?? '') === $guid && ($data['guid'] ?? '') !== $guid ) throw new Archive_Error('A native filter changed the font journal identity.'); return $data; };
			add_filter('wp_insert_post_data',$guard,PHP_INT_MAX,4);
			try { $id = wp_insert_post( wp_slash( [ 'post_type'=>BRICKS_DB_CUSTOM_FONTS,'post_title'=>$font['title'],'post_status'=>'publish','post_author'=>get_current_user_id(),'post_content'=>'','post_excerpt'=>'','guid'=>$guid,'meta_input'=>[ self::MARKER=>$marker,BRICKS_DB_CUSTOM_FONT_FACES=>$faces ] ] ),true ); } finally { remove_action( 'save_post',$track,PHP_INT_MIN ); remove_filter('wp_insert_post_data',$guard,PHP_INT_MAX); }
			if ( is_wp_error( $id ) || ! $id ) throw new Archive_Error( 'The native font copy could not be saved.' );
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_title !== $font['title'] || 'publish' !== $post->post_status || (int) $post->post_author !== get_current_user_id() || '' !== $post->post_content || '' !== $post->post_excerpt || $post->guid !== $guid || get_post_meta( $id,self::MARKER,true ) !== $marker || get_post_meta( $id,BRICKS_DB_CUSTOM_FONT_FACES,true ) !== $faces ) throw new Archive_Error( 'An imported font differs from the reviewed copy. It is preserved for review.' );
		$journal( (int) $id ); self::refresh(); return (int) $id;
	}
	public static function verify_owned( array $info ): void {
		$requires_font = 'bricks_design_assets' !== ( $info['scope'] ?? '' ) || ! empty( $info['fontCount'] );
		if ( $requires_font ? ! self::allowed() : ! Bricks_Design_Snapshot_Service::allowed() ) throw new Archive_Error( 'Font recovery access changed.' );
		foreach ( $info['fontOwned'] ?? [] as $font ) if ( ! hash_equals( $font['fingerprint'],self::fingerprint( $font['id'] ) ) || ! current_user_can( 'read_post',$font['id'] ) || get_post_meta( $font['id'],self::MARKER,true ) !== $info['id'].':'.$font['source'] ) throw new Archive_Error( 'A recovered font changed. Review a new recovery map.' );
	}
	/** A reviewed recovery rewrites only typed fontFamily values, never text or arbitrary numbers. */
	public static function remap_design( array $options, array $map ): array {
		if ( ! $map ) throw new Archive_Error( 'Choose a completed native font recovery.' );
		$nodes = 0;
		$walk = static function($value,int $depth=0) use (&$walk,&$nodes,$map) {
			if ( ++$nodes > 250000 || $depth > 20 ) throw new Archive_Error('The design font map exceeds its bounded limit.');
			if ( ! is_array($value) ) return $value;
			if ( is_string($value['fontFamily'] ?? null) && preg_match('/^custom_font_([1-9][0-9]*)$/D',$value['fontFamily'],$match) && isset($map[(int)$match[1]]) ) $value['fontFamily']='custom_font_'.$map[(int)$match[1]];
			foreach($value as &$child) $child=$walk($child,$depth+1); unset($child); return $value;
		};
		foreach($options as $name=>&$setting) if(Bricks_Design_Snapshot_Service::handles($name) && (!defined('BRICKS_DB_GLOBAL_CLASSES_TRASH') || $name!==BRICKS_DB_GLOBAL_CLASSES_TRASH) && $setting['exists']) $setting['value']=$walk($setting['value']); unset($setting);
		return $options;
	}
}
