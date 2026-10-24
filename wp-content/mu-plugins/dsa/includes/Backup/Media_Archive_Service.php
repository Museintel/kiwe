<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** WordPress owns attachments, registered derivatives and metadata. No arbitrary filesystem export. */
final class Media_Archive_Service {
	public const MAX_ATTACHMENTS = 200;
	public const MAX_FILES = 2000;
	public const MAX_FILE_BYTES = 33554432;
	public const MAX_RAW_BYTES = 67108864;
	public const MARKER = '_kiwe_media_import_job';
	private const SEO = [ '_kiwe_seo_title','_kiwe_seo_description','_kiwe_search_phrases','_kiwe_seo_robots','_kiwe_search_visibility','rank_math_title','rank_math_description','rank_math_focus_keyword','rank_math_robots' ];
	public static function can_use(): bool { return is_user_logged_in() && current_user_can( 'upload_files' ); }
	public static function can_export( int $id ): bool {
		if ( ! self::can_use() ) return false;
		if ( 0 === $id ) return true;
		$post = get_post( $id );
		return $post && 'attachment' === $post->post_type && in_array( $post->post_status, [ 'inherit','private' ], true ) && current_user_can( 'edit_post', $id ) && ( (int) $post->post_author === get_current_user_id() || current_user_can( 'edit_others_posts' ) || current_user_can( 'manage_options' ) );
	}
	public static function filename( string $name ): string {
		if ( '' === $name || strlen( $name ) > 240 || $name !== sanitize_file_name( $name ) || preg_match( '#[/\\\\\x00-\x1f]#', $name ) || str_starts_with( $name, '.' ) || preg_match( '/\.(?:php[0-9]?|phtml|phar|html?|svg|xml|js|cgi|asp|aspx|sh|exe|com|bat|htaccess)(?:\.|$)/i', $name ) ) throw new Archive_Error( 'A media filename is unsafe or unsupported.' );
		return $name;
	}
	public static function relative( string $relative ): string {
		if ( '' === $relative || strlen( $relative ) > 600 || str_starts_with( $relative, '/' ) || preg_match( '#[\\\\\x00-\x1f:]#', $relative ) ) throw new Archive_Error( 'A media path is unsafe or unsupported.' );
		foreach ( explode( '/', $relative ) as $name ) self::filename( $name );
		return $relative;
	}
	public static function uploads(): array {
		$uploads = wp_upload_dir( null, false ); $root = realpath( $uploads['basedir'] ?? '' );
		if ( ! $root || ! empty( $uploads['error'] ) || is_link( $uploads['basedir'] ) ) throw new Archive_Error( 'Local WordPress uploads are unavailable.' );
		return [ 'root'=>wp_normalize_path( $root ),'url'=>untrailingslashit( $uploads['baseurl'] ) ];
	}
	public static function source_path( string $relative ): string {
		$relative = self::relative( $relative ); $root = self::uploads()['root']; $path = $root;
		foreach ( explode( '/', $relative ) as $component ) { $path .= '/' . $component; if ( is_link( $path ) ) throw new Archive_Error( 'Linked media files cannot be backed up.' ); }
		$real = realpath( $path );
		if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) || ! str_starts_with( wp_normalize_path( $real ), $root . '/' ) ) throw new Archive_Error( 'A registered media file is missing or outside local uploads.' );
		return $real;
	}
	public static function safe( $value, int $depth = 0 ): bool {
		if ( $depth > 12 ) return false;
		if ( is_array( $value ) ) { if ( count( $value ) > 300 ) return false; foreach ( $value as $key=>$item ) if ( ( ! is_int( $key ) && ! is_string( $key ) ) || ! self::safe( $item, $depth+1 ) ) return false; return true; }
		return is_null( $value ) || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) || ( is_string( $value ) && strlen( $value ) <= 262144 && false !== wp_json_encode( $value ) );
	}
	public static function file_type( string $path, string $name ): string {
		self::filename( $name );
		$type = wp_check_filetype_and_ext( $path, $name, get_allowed_mime_types() );
		if ( empty( $type['ext'] ) || empty( $type['type'] ) || ! empty( $type['proper_filename'] ) ) throw new Archive_Error( 'A media file does not match an allowed WordPress file type.' );
		return $type['type'];
	}
	private static function metadata( int $id ): array {
		$metadata = wp_get_attachment_metadata( $id, true ); if ( false === $metadata || '' === $metadata ) $metadata = [];
		$backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true ); if ( '' === $backups ) $backups = [];
		if ( ! is_array( $metadata ) || ! is_array( $backups ) || ! self::safe( $metadata ) || ! self::safe( $backups ) || strlen( (string) wp_json_encode( [ $metadata,$backups ] ) ) > 65536 || isset( $metadata['image'] ) ) throw new Archive_Error( 'This media metadata or embedded artwork needs a supported dependency adapter.' );
		return [ $metadata,$backups ];
	}
	public static function fingerprint( int $id ): string {
		$post = get_post( $id ); if ( ! $post || 'attachment' !== $post->post_type ) return '';
		$meta = [];
		foreach ( [ '_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wp_attachment_image_alt',...self::SEO,self::MARKER ] as $key ) $meta[$key] = get_post_meta( $id, $key );
		return hash( 'sha256', (string) wp_json_encode( [ (array) $post,$meta ] ) );
	}
	public static function capture( int $id ): array {
		if ( ! self::can_export( $id ) ) throw new Archive_Error( 'Your media export permission changed.' );
		$post = get_post( $id ); $attached = get_post_meta( $id, '_wp_attached_file', true );
		if ( ! is_string( $attached ) ) throw new Archive_Error( 'This attachment has no supported local file.' );
		$attached = self::relative( $attached ); [ $metadata,$backups ] = self::metadata( $id ); $directory = dirname( $attached );
		$names = [ basename( $attached ) ];
		if ( isset( $metadata['file'] ) && $metadata['file'] !== $attached ) throw new Archive_Error( 'The attachment and registered metadata paths disagree.' );
		if ( isset( $metadata['original_image'] ) ) $names[] = self::filename( $metadata['original_image'] );
		foreach ( [ $metadata['sizes'] ?? [],$backups ] as $sizes ) {
			if ( ! is_array( $sizes ) || count( $sizes ) > 100 ) throw new Archive_Error( 'The media derivative list is unsupported.' );
			foreach ( $sizes as $size ) { if ( ! is_array( $size ) || ! is_string( $size['file'] ?? null ) ) throw new Archive_Error( 'A registered media derivative is unsupported.' ); $names[] = self::filename( $size['file'] ); }
		}
		$files = []; $case_names = [];
		foreach ( array_unique( $names ) as $name ) {
			if ( isset( $case_names[strtolower( $name )] ) ) throw new Archive_Error( 'Media filenames conflict by letter case.' ); $case_names[strtolower( $name )] = true;
			$relative = ( '.' === $directory ? '' : $directory . '/' ) . $name; $path = self::source_path( $relative ); clearstatcache( true, $path ); $stat = stat( $path );
			if ( ! $stat || $stat['size'] < 1 || $stat['size'] > self::MAX_FILE_BYTES ) throw new Archive_Error( 'A media file is empty or exceeds 32 MB. Choose a supported selection.' );
			$mime = self::file_type( $path, $name ); $hash = hash_file( 'sha256', $path ); if ( ! is_string( $hash ) ) throw new Archive_Error( 'A media file could not be verified.' );
			$files[] = [ 'name'=>$name,'relative'=>$relative,'bytes'=>(int) $stat['size'],'modified'=>(int) $stat['mtime'],'hash'=>$hash,'mime'=>$mime ];
		}
		if ( $files[0]['mime'] !== $post->post_mime_type ) throw new Archive_Error( 'The attachment MIME type disagrees with its original file.' );
		$seo = []; foreach ( self::SEO as $key ) if ( metadata_exists( 'post', $id, $key ) ) { $seo[$key] = get_post_meta( $id, $key, true ); if ( ! self::safe( $seo[$key] ) ) throw new Archive_Error( 'Media SEO metadata is unsupported.' ); }
		$record = [ 'id'=>$id,'title'=>$post->post_title,'caption'=>$post->post_excerpt,'description'=>$post->post_content,'date'=>$post->post_date,'dateGmt'=>$post->post_date_gmt,'mime'=>$post->post_mime_type,'alt'=>(string) get_post_meta( $id, '_wp_attachment_image_alt', true ),'attached'=>$attached,'metadata'=>$metadata,'backups'=>$backups,'seo'=>$seo,'files'=>$files,'fingerprint'=>self::fingerprint( $id ) ];
		if ( ! self::safe( $record ) || strlen( (string) wp_json_encode( $record ) ) > 131072 ) throw new Archive_Error( 'This media record exceeds the metadata limit.' );
		return $record;
	}
	public static function plan( int $id, array $selection ): array {
		if ( $id < 0 || ! self::can_export( $id ) ) throw new Archive_Error( 'This media backup is unavailable for your account.' );
		$selection = Content_Archive_Service::selection( $selection );
		if ( $id && ( $selection['from'] || $selection['to'] ) ) throw new Archive_Error( 'A single media backup does not use date filters.' );
		$args = [ 'post_type'=>'attachment','post_status'=>[ 'inherit','private' ],'posts_per_page'=>self::MAX_ATTACHMENTS+1,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','no_found_rows'=>true ];
		if ( ! current_user_can( 'edit_others_posts' ) && ! current_user_can( 'manage_options' ) ) $args['author'] = get_current_user_id();
		if ( $selection['from'] || $selection['to'] ) { $range = [ 'column'=>'post_date','inclusive'=>true ]; if ( $selection['from'] ) $range['after'] = $selection['from'] . ' 00:00:00'; if ( $selection['to'] ) $range['before'] = $selection['to'] . ' 23:59:59'; $args['date_query'] = [ $range ]; }
		$ids = $id ? [ $id ] : array_map( 'intval', get_posts( $args ) );
		if ( ! $ids || count( $ids ) > self::MAX_ATTACHMENTS ) throw new Archive_Error( 'Choose a date range containing between 1 and 200 available attachments.' );
		$records = []; $total = 0; $files = 0;
		foreach ( $ids as $source ) { $record = self::capture( $source ); foreach ( $record['files'] as $file ) $total += $file['bytes']; $files += count( $record['files'] ); $records[] = $record; if ( $total > self::MAX_RAW_BYTES || $files > self::MAX_FILES ) throw new Archive_Error( 'Choose a smaller media selection: up to 64 MB of files and 2,000 registered files.' ); }
		return [ 'scope'=>$id ? 'attachment' : 'media','sourceId'=>$id,'label'=>$id ? $records[0]['title'] : 'Media files','selection'=>$selection,'records'=>$records,'rawBytes'=>$total,'fileCount'=>$files,'uploadsUrl'=>self::uploads()['url'] ];
	}
	public static function assert_source( array $record, bool $files = false ): void {
		if ( ! self::can_export( $record['id'] ) || ! hash_equals( $record['fingerprint'], self::fingerprint( $record['id'] ) ) ) throw new Archive_Error( 'An attachment or your permission changed. Start a fresh media backup.' );
		foreach ( $record['files'] as $file ) { $path = self::source_path( $file['relative'] ); clearstatcache( true, $path ); if ( filesize( $path ) !== $file['bytes'] || filemtime( $path ) !== $file['modified'] || ( $files && ! hash_equals( $file['hash'], hash_file( 'sha256', $path ) ) ) ) throw new Archive_Error( 'A registered media file changed. Start a fresh backup.' ); }
	}
	public static function validate_record( array $record ): void {
		$keys = [ 'id','title','caption','description','date','dateGmt','mime','alt','attached','metadata','backups','seo','files','fingerprint' ];
		if ( array_diff( array_keys( $record ), $keys ) || array_diff( $keys, array_keys( $record ) ) || ! is_int( $record['id'] ) || $record['id'] < 1 || ! self::safe( $record ) || strlen( (string) wp_json_encode( $record ) ) > 131072 || ! is_array( $record['files'] ) || ! $record['files'] || count( $record['files'] ) > 201 || ! is_array( $record['metadata'] ) || ! is_array( $record['backups'] ) || ! is_array( $record['seo'] ) || array_diff( array_keys( $record['seo'] ), self::SEO ) || isset( $record['metadata']['image'] ) ) throw new Archive_Error( 'The media record manifest is invalid.' );
		foreach ( [ 'title','caption','description','alt','date','dateGmt','mime','attached','fingerprint' ] as $key ) if ( ! is_string( $record[$key] ) ) throw new Archive_Error( 'A media record field is invalid.' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $record['fingerprint'] ) || ! preg_match( '/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $record['mime'] ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $record['date'] ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $record['dateGmt'] ) ) throw new Archive_Error( 'Media identity fields are invalid.' );
		self::relative( $record['attached'] ); $seen = []; $folder = dirname( $record['attached'] );
		foreach ( $record['files'] as $file ) {
			if ( ! is_array( $file ) || array_keys( $file ) !== [ 'name','relative','bytes','modified','hash','mime' ] || ! is_string( $file['name'] ) || ! is_string( $file['relative'] ) || ! is_int( $file['bytes'] ) || $file['bytes'] < 1 || $file['bytes'] > self::MAX_FILE_BYTES || ! is_int( $file['modified'] ) || ! is_string( $file['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $file['hash'] ) || ! is_string( $file['mime'] ) || isset( $seen[strtolower( $file['name'] )] ) ) throw new Archive_Error( 'A media file manifest is invalid.' );
			self::filename( $file['name'] ); self::relative( $file['relative'] ); if ( dirname( $file['relative'] ) !== $folder || basename( $file['relative'] ) !== $file['name'] ) throw new Archive_Error( 'The registered media paths disagree.' ); $seen[strtolower( $file['name'] )] = true;
		}
		if ( $record['files'][0]['relative'] !== $record['attached'] || $record['files'][0]['mime'] !== $record['mime'] || ( isset( $record['metadata']['file'] ) && $record['metadata']['file'] !== $record['attached'] ) ) throw new Archive_Error( 'The main attachment file disagrees with its metadata.' );
		$registered = [ basename( $record['attached'] ) ]; if ( isset( $record['metadata']['original_image'] ) ) $registered[] = self::filename( $record['metadata']['original_image'] );
		foreach ( [ $record['metadata']['sizes'] ?? [],$record['backups'] ] as $sizes ) { if ( ! is_array( $sizes ) || count( $sizes ) > 100 ) throw new Archive_Error( 'Media derivatives are invalid.' ); foreach ( $sizes as $size ) { if ( ! is_array( $size ) || ! is_string( $size['file'] ?? null ) ) throw new Archive_Error( 'A media derivative is invalid.' ); $registered[] = self::filename( $size['file'] ); } }
		if ( array_diff( array_unique( $registered ), array_column( $record['files'], 'name' ) ) || array_diff( array_column( $record['files'], 'name' ), array_unique( $registered ) ) ) throw new Archive_Error( 'The media manifest is missing or adds an unregistered file.' );
	}
	public static function restored_metadata( array $record, string $relative ): array {
		$metadata = $record['metadata']; if ( isset( $metadata['file'] ) ) $metadata['file'] = $relative;
		return $metadata;
	}
	public static function save_attachment( array $record, string $file, string $relative, string $job ): int {
		if ( ! self::can_use() || self::file_type( $file, basename( $file ) ) !== $record['mime'] ) throw new Archive_Error( 'The restored media type or upload permission changed.' );
		$post = [ 'post_title'=>$record['title'],'post_content'=>wp_kses_post( $record['description'] ),'post_excerpt'=>wp_kses_post( $record['caption'] ),'post_mime_type'=>$record['mime'],'post_status'=>'inherit','post_author'=>get_current_user_id(),'post_parent'=>0,'post_date'=>$record['date'],'post_date_gmt'=>$record['dateGmt'],'guid'=>self::uploads()['url'].'/'.$relative,'meta_input'=>[ self::MARKER=>$job ] ];
		$id = wp_insert_attachment( wp_slash( $post ), $file, 0, true ); if ( is_wp_error( $id ) || ! $id ) throw new Archive_Error( 'The media copy could not be saved.' );
		$metadata = self::restored_metadata( $record, $relative );
		if ( $metadata ) wp_update_attachment_metadata( $id, $metadata );
		if ( $record['backups'] ) update_post_meta( $id, '_wp_attachment_backup_sizes', wp_slash( $record['backups'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $record['alt'] ) );
		foreach ( $record['seo'] as $key=>$value ) update_post_meta( $id, $key, wp_slash( $value ) );
		$read = get_post( $id );
		if ( ! $read || 'attachment' !== $read->post_type || (int) $read->post_author !== get_current_user_id() || (int) $read->post_parent !== 0 || $read->post_mime_type !== $record['mime'] || $read->post_title !== $record['title'] || $read->post_content !== $post['post_content'] || $read->post_excerpt !== $post['post_excerpt'] || get_post_meta( $id, '_wp_attached_file', true ) !== $relative || ( $metadata && wp_get_attachment_metadata( $id, true ) !== $metadata ) || ( $record['backups'] && get_post_meta( $id, '_wp_attachment_backup_sizes', true ) !== $record['backups'] ) || get_post_meta( $id, '_wp_attachment_image_alt', true ) !== $record['alt'] || get_post_meta( $id, self::MARKER, true ) !== $job ) throw new Archive_Error( 'The saved media did not match the reviewed copy. Check saved progress.' );
		foreach ( $record['seo'] as $key=>$value ) if ( get_post_meta( $id, $key, true ) !== $value ) throw new Archive_Error( 'Media SEO readback failed. Check saved progress.' );
		return (int) $id;
	}
	/** Rewrite only a reviewed draft payload, using one completed actor-owned media recovery. */
	public static function remap_content( array $payload, array $recovery ): array {
		$map = $recovery['map']; $urls = [];
		foreach ( $map as $item ) foreach ( $item['urls'] as $from=>$to ) { if ( isset( $urls[$from] ) && $urls[$from] !== $to ) throw new Archive_Error( 'Two recovered attachments share a source URL. Choose a smaller media recovery for this content import.' ); $urls[$from] = $to; $encoded = str_replace( [ '%3A','%2F' ], [ ':','/' ], rawurlencode( $from ) ); $urls[$encoded] = str_replace( [ '%3A','%2F' ], [ ':','/' ], rawurlencode( $to ) ); }
		$text = static function( string $value ) use ( $urls ): string {
			if ( ! $urls || strlen( $value ) > 5242880 ) { if ( strlen( $value ) > 5242880 ) throw new Archive_Error( 'A content field exceeds the media mapping limit.' ); return $value; }
			// Match a whole URL before lookup; larger filenames and unrelated URLs stay intact.
			$result = preg_replace_callback( '~https?://[^\\s"\'<>\\\\\\)\\]]+~u', static function( $match ) use ( $urls ) { $url = $match[0]; $end = strcspn( $url, '?#' ); $path = substr( $url, 0, $end ); return isset( $urls[$path] ) ? $urls[$path].substr( $url, $end ) : $url; }, $value );
			if ( ! is_string( $result ) ) throw new Archive_Error( 'A media reference could not be mapped safely.' ); return $result;
		};
		$builder = static function( $value, string $key = '', int $depth = 0 ) use ( &$builder,$map,$text,$recovery ) {
			if ( $depth > 20 ) throw new Archive_Error( 'A builder media reference exceeds its mapping limit.' );
			if ( is_string( $value ) ) return $text( $value ); if ( ! is_array( $value ) ) return $value;
			if ( is_string($value['fontFamily'] ?? null) && preg_match('/^custom_font_([1-9][0-9]*)$/D',$value['fontFamily'],$font) && isset($recovery['fontMap'][(int)$font[1]]) ) $value['fontFamily']='custom_font_'.$recovery['fontMap'][(int)$font[1]];
			$id = $value['id'] ?? null; $source = is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? (int) $id : 0;
			if ( $source && isset( $map[$source] ) && ( ( is_string( $value['url'] ?? null ) && isset( $map[$source]['urls'][$value['url']] ) ) || in_array( $key, [ 'image','images','gallery','logo','avatar','backgroundImage','background-image' ], true ) ) ) $value['id'] = is_string( $id ) ? (string) $map[$source]['id'] : $map[$source]['id'];
			foreach ( $value as $child=>$item ) $value[$child] = $builder( $item, is_string( $child ) ? $child : $key, $depth+1 ); return $value;
		};
		foreach ( $payload['records'] as &$record ) {
			if ( isset( $map[$record['featuredImage']] ) ) $record['featuredImage'] = $map[$record['featuredImage']]['id'];
			if ( isset( $record['product'] ) ) { if ( isset( $map[$record['product']['image_id']] ) ) $record['product']['image_id'] = $map[$record['product']['image_id']]['id']; foreach ( $record['product']['gallery_image_ids'] ?? [] as $index=>$id ) if ( isset( $map[$id] ) ) $record['product']['gallery_image_ids'][$index] = $map[$id]['id']; }
			$record['content'] = preg_replace_callback( '/<!-- wp:(?:image|gallery|file|audio|video|cover|media-text)\s+(\{[^\r\n]*?\})(\s*\/?)\s*-->/', static function( $match ) use ( $map,$text ): string {
				$attrs = json_decode( $match[1], true ); if ( ! is_array( $attrs ) ) return $match[0];
				foreach ( [ 'id','mediaId' ] as $key ) if ( is_int( $attrs[$key] ?? null ) && isset( $map[$attrs[$key]] ) ) $attrs[$key] = $map[$attrs[$key]]['id'];
				if ( is_array( $attrs['ids'] ?? null ) ) foreach ( $attrs['ids'] as &$id ) if ( is_int( $id ) && isset( $map[$id] ) ) $id = $map[$id]['id']; unset( $id );
				foreach ( $attrs as &$value ) if ( is_string( $value ) ) $value = $text( $value ); unset( $value );
				$json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); return is_string( $json ) ? str_replace( $match[1], $json, $match[0] ) : $match[0];
			}, $record['content'] );
			if ( ! is_string( $record['content'] ) ) throw new Archive_Error( 'A WordPress media block could not be mapped.' );
			$record['content'] = preg_replace_callback( '/\bwp-image-([1-9][0-9]*)(?![0-9])/', static fn( $match )=>isset( $map[(int) $match[1]] ) ? 'wp-image-'.$map[(int) $match[1]]['id'] : $match[0], $text( $record['content'] ) );
			$record['excerpt'] = $text( $record['excerpt'] ); $record['builder'] = $builder( $record['builder'] );
			if ( isset( $record['template']['settings'] ) ) $record['template']['settings'] = $builder( $record['template']['settings'] );
			foreach ( $record['serviceMeta'] ?? [] as $key=>$value ) if ( is_string( $value ) ) $record['serviceMeta'][$key] = $text( $value );
		}
		unset( $record ); return $payload;
	}
}
