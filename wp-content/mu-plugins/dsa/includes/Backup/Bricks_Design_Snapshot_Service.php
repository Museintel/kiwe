<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bricks owns these options. Reuse its writers; never call its terminating AJAX imports. */
final class Bricks_Design_Snapshot_Service {
	public const GROUP = 'Bricks design';
	private const CONSTANTS = [ 'BRICKS_DB_GLOBAL_CLASSES','BRICKS_DB_GLOBAL_CLASSES_CATEGORIES','BRICKS_DB_GLOBAL_CLASSES_LOCKED','BRICKS_DB_GLOBAL_CLASSES_TRASH','BRICKS_DB_GLOBAL_VARIABLES','BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES','BRICKS_DB_COLOR_PALETTE','BRICKS_DB_THEME_STYLES','BRICKS_DB_COMPONENTS','BRICKS_DB_GLOBAL_ELEMENTS','BRICKS_DB_BREAKPOINTS','BRICKS_DB_PSEUDO_CLASSES','BRICKS_DB_STYLE_MANAGER' ];

	public static function available(): bool {
		if ( is_multisite() || ! \DSA\Access\Template_Workspace_Service::available() || ! class_exists( '\Bricks\Builder_Permissions' ) || ! is_callable( [ '\Bricks\Helpers','save_global_classes_in_db' ] ) || ! is_callable( [ '\Bricks\Helpers','save_global_variables_in_db' ] ) || ! is_callable( [ '\Bricks\Components','upgrade_components' ] ) ) return false;
		foreach ( self::CONSTANTS as $name ) if ( ! defined( $name ) || ! is_string( constant( $name ) ) ) return false;
		return true;
	}
	public static function allowed(): bool {
		if ( ! self::available() || ! \DSA\Access\Backup_Workspace_Service::allowed() || ! \DSA\Access\Template_Workspace_Service::allowed() ) return false;
		// The deprecated global-elements permission disappears when its catalog is empty.
		// Full native builder access remains required, alongside the current design permissions.
		foreach ( [ 'edit_global_classes','access_variable_manager','access_theme_styles','edit_color_palettes','access_pseudo_selectors' ] as $permission ) if ( ! \Bricks\Builder_Permissions::user_has_permission( $permission ) ) return false;
		return current_user_can( 'bricks_full_access' );
	}
	public static function names(): array { return self::allowed() ? array_map( 'constant', self::CONSTANTS ) : []; }
	public static function handles( string $name ): bool {
		foreach ( self::CONSTANTS as $constant ) if ( defined( $constant ) && $name === constant( $constant ) ) return true;
		return false;
	}
	private static function value( array $options, string $constant ): array {
		$setting = $options[ constant( $constant ) ];
		return $setting['exists'] ? $setting['value'] : [];
	}
	private static function ids( array $items, bool $native_class_list = false ): array {
		$ids = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || '' === $item['id'] || strlen( $item['id'] ) > 100 || ( ! $native_class_list && isset( $ids[$item['id']] ) ) ) throw new Archive_Error( 'A Bricks design ID is missing or duplicated.' );
			$ids[$item['id']] = true;
		}
		return $ids;
	}
	/** Bricks' image descriptors and popup interactions, never arbitrary numbers/text. */
	private static function media_id( array $value ): int {
		if ( ! isset( $value['id'] ) || ( ! is_string( $value['full'] ?? null ) && ! is_string( $value['url'] ?? null ) ) ) return 0;
		if ( ! is_int( $value['id'] ) && ! is_string( $value['id'] ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value['id'] ) ) throw new Archive_Error( 'A Bricks image reference is unsupported.' );
		return (int) $value['id'];
	}
	private static function template_keys( array $value ): array {
		return array_values( array_filter( [ 'template', ( $value['target'] ?? '' ) === 'popup' ? 'templateId' : '' ], static fn( $key )=>'' !== $key && isset( $value[$key] ) && ( is_int( $value[$key] ) || is_string( $value[$key] ) ) && preg_match( '/^[1-9][0-9]*$/D', (string) $value[$key] ) ) );
	}
	/** Maps come only from completed private jobs. Preserve native scalar types and exact URLs. */
	public static function remap_value( $value, array $media = [], array $templates = [], bool $require_published = false ) {
		$nodes = 0;
		$walk = static function( $item, int $depth = 0 ) use ( &$walk,&$nodes,$media,$templates,$require_published ) {
			if ( ++$nodes > 250000 || $depth > 20 ) throw new Archive_Error( 'The Bricks recovery map exceeds its bounded limit.' );
			if ( ! is_array( $item ) ) return $item;
			$source = self::media_id( $item );
			if ( $source && isset( $media[$source] ) ) {
				$copy = $media[$source];
				if ( ! is_int( $copy['id'] ?? null ) || $copy['id'] < 1 || ! is_array( $copy['urls'] ?? null ) ) throw new Archive_Error( 'The completed media recovery is invalid.' );
				foreach ( [ 'url','full' ] as $key ) if ( isset( $item[$key] ) ) {
					if ( ! is_string( $item[$key] ) ) throw new Archive_Error( 'A Bricks image URL is unsupported.' );
					$end = strcspn( $item[$key], '?#' ); $url = substr( $item[$key], 0, $end );
					if ( ! is_string( $copy['urls'][$url] ?? null ) ) throw new Archive_Error( 'A design image URL does not match the selected media recovery. Review its registered file first.' );
					$item[$key] = $copy['urls'][$url] . substr( $item[$key], $end );
				}
				$item['id'] = is_string( $item['id'] ) ? (string) $copy['id'] : $copy['id'];
			}
			foreach ( self::template_keys( $item ) as $key ) if ( isset( $templates[(int) $item[$key]] ) ) {
				$copy = $templates[(int) $item[$key]];
				if ( ! is_int( $copy['id'] ?? null ) || $copy['id'] < 1 ) throw new Archive_Error( 'The completed template recovery is invalid.' );
				if ( $require_published && ( $copy['status'] ?? '' ) !== 'publish' ) throw new Archive_Error( 'Review and publish the referenced template copies in Bricks before reconnecting live design. Recovery never publishes templates automatically.' );
				$item[$key] = is_string( $item[$key] ) ? (string) $copy['id'] : $copy['id'];
			}
			foreach ( $item as &$child ) $child = $walk( $child, $depth + 1 ); unset( $child ); return $item;
		};
		return $walk( $value );
	}
	public static function remap_design( array $options, array $media = [], array $templates = [] ): array {
		foreach ( $options as $name=>&$setting ) if ( self::handles( $name ) && $name !== BRICKS_DB_GLOBAL_CLASSES_TRASH && $setting['exists'] ) $setting['value'] = self::remap_value( $setting['value'], $media, $templates, true ); unset( $setting );
		return $options;
	}
	/** Exact native IDs reconnect same-site designs. Fonts/files must exist before restore. */
	public static function validate( array $options, array $recovered_templates = [], array $included_templates = [], bool $include_ids = false ): array {
		$selected = array_filter( array_keys( $options ), [ self::class,'handles' ] );
		if ( ! $selected ) return [];
		if ( ! self::allowed() || array_diff( self::names(), $selected ) ) throw new Archive_Error( 'Restore Bricks design together with all its settings, using an authorized account and the active Bricks theme.' );
		foreach ( $selected as $name ) {
			$setting = $options[$name];
			if ( ! is_bool( $setting['exists'] ?? null ) || ( $setting['exists'] && ! is_array( $setting['value'] ?? null ) ) ) throw new Archive_Error( 'The Bricks design format is unsupported.' );
		}
		// Native class saves preserve duplicate IDs and lookup uses the last row.
		// Archive every row in order; membership checks must not rewrite existing design.
		$classes = self::ids( self::value( $options, 'BRICKS_DB_GLOBAL_CLASSES' ), true );
		$components = self::value( $options, 'BRICKS_DB_COMPONENTS' ); $component_ids = self::ids( $components );
		if ( wp_json_encode( $components ) !== wp_json_encode( \Bricks\Components::upgrade_components( $components, false ) ) ) throw new Archive_Error( 'Upgrade legacy components in Bricks before making this design snapshot.' );
		$globals = self::ids( self::value( $options, 'BRICKS_DB_GLOBAL_ELEMENTS' ) );
		$dependencies = [ 'media'=>[], 'fonts'=>[], 'templates'=>[] ]; $edges = []; $nodes = 0;
		if ( count( $included_templates ) > Content_Archive_Job_Service::MAX_PRIMARY || ! array_is_list( $included_templates ) || count( array_unique( $included_templates, SORT_REGULAR ) ) !== count( $included_templates ) ) throw new Archive_Error( 'The design template selection is invalid.' );
		foreach ( $included_templates as $id ) { if ( ! is_int( $id ) || $id < 1 ) throw new Archive_Error( 'The design template selection is invalid.' ); $dependencies['templates'][$id] = true; }
		$walk = static function( $value, ?string $component = null, int $depth = 0, bool $active = true ) use ( &$walk,&$dependencies,&$edges,&$nodes,$classes,$component_ids,$globals ): void {
			if ( ++$nodes > 250000 || $depth > 20 ) throw new Archive_Error( 'The Bricks dependency graph exceeds the settings snapshot limit.' );
			if ( is_string( $value ) && preg_match( '/<\?(?:php|=)|<script\b|javascript\s*:|\{echo:/i', $value ) ) throw new Archive_Error( 'Executable design content needs a separate code review; it is not included in design snapshots.' );
			if ( ! is_array( $value ) ) return;
			if ( ( $value['name'] ?? '' ) === 'code' || array_intersect( array_keys( $value ), [ 'code','codeExecute','signature','_signature','_jsCustom','_phpCode' ] ) ) throw new Archive_Error( 'Executable design content needs a separate code review; it is not included in design snapshots.' );
			foreach ( array_keys( $value ) as $key ) if ( is_string( $key ) && preg_match( '/signature|queryEditorCode|executeCode|codeExecution/i',$key ) ) throw new Archive_Error( 'Executable design content needs a separate code review; it is not included in design snapshots.' );
			foreach ( $active ? (array) ( $value['_cssGlobalClasses'] ?? [] ) : [] as $id ) if ( ! is_string( $id ) || ! isset( $classes[$id] ) ) throw new Archive_Error( 'A Bricks global class dependency is unavailable.' );
			if ( $active && isset( $value['cid'] ) ) {
				$id = $value['cid'];
				if ( ! is_string( $id ) || ! isset( $component_ids[$id] ) ) throw new Archive_Error( 'A Bricks component dependency is unavailable.' );
				if ( null !== $component ) $edges[$component]['c:' . $id] = true;
			}
			if ( $active && isset( $value['global'] ) && ( ! is_string( $value['global'] ) || ! isset( $globals[$value['global']] ) ) ) throw new Archive_Error( 'A Bricks global element dependency is unavailable.' );
			if ( $active && isset( $value['global'] ) && null !== $component ) $edges[$component]['g:' . $value['global']] = true;
			if ( $active && is_string( $value['fontFamily'] ?? null ) && preg_match( '/^custom_font_([1-9][0-9]*)$/D', $value['fontFamily'], $match ) ) $dependencies['fonts'][(int) $match[1]] = true;
			if ( $active && ( $id = self::media_id( $value ) ) ) $dependencies['media'][$id] = true;
			if ( $active ) foreach ( self::template_keys( $value ) as $key ) { $id = (int) $value[$key]; $dependencies['templates'][$id] = true; if ( null !== $component ) $edges[$component]['t:' . $id] = true; }
			foreach ( $value as $item ) $walk( $item, $component, $depth + 1, $active );
		};
		foreach ( $selected as $name ) if ( ! in_array( $name, [ BRICKS_DB_COMPONENTS,BRICKS_DB_GLOBAL_ELEMENTS ], true ) ) $walk( $options[$name]['exists'] ? $options[$name]['value'] : [], null, 0, $name !== BRICKS_DB_GLOBAL_CLASSES_TRASH );
		foreach ( $components as $component ) $walk( $component, 'c:' . $component['id'] );
		foreach ( self::value( $options, 'BRICKS_DB_GLOBAL_ELEMENTS' ) as $global ) $walk( $global, 'g:' . $global['id'] );
		// Follow every native template once, including media/fonts and template/component edges.
		$templates = [];
		while ( $pending = array_diff_key( $dependencies['templates'], $templates ) ) foreach ( array_keys( $pending ) as $id ) {
			if ( count( $templates ) >= Content_Archive_Job_Service::MAX_PRIMARY ) throw new Archive_Error( 'Bricks template dependencies exceed the bounded snapshot limit.' );
			$templates[$id] = Content_Archive_Service::template_data( $id );
			foreach ( $recovered_templates as $copy ) if ( $copy['id'] === $id && get_post( $id )->post_status !== 'publish' ) throw new Archive_Error( 'Review and publish all referenced template copies, including nested templates, before reconnecting live design.' );
			$walk( $templates[$id], 't:' . $id );
		}
		$visiting = []; $done = [];
		$visit = static function( string $id, int $depth = 0 ) use ( &$visit,&$visiting,&$done,$edges ): void {
			if ( $depth > 100 ) throw new Archive_Error( 'Bricks template/component nesting exceeds the snapshot limit.' );
			if ( isset( $done[$id] ) ) return;
			if ( isset( $visiting[$id] ) ) throw new Archive_Error( 'Circular Bricks template/component dependencies cannot be restored.' );
			$visiting[$id] = true;
			foreach ( array_keys( $edges[$id] ?? [] ) as $child ) $visit( $child, $depth + 1 );
			unset( $visiting[$id] ); $done[$id] = true;
		};
		foreach ( array_keys( $component_ids ) as $id ) $visit( 'c:' . $id );
		foreach ( array_keys( $globals ) as $id ) $visit( 'g:' . $id );
		foreach ( array_keys( $templates ) as $id ) $visit( 't:' . $id );
		$proof = self::dependency_proof( $dependencies );
		if ( $include_ids ) { $proof['ids'] = []; foreach ( $dependencies as $kind=>$values ) { $proof['ids'][$kind] = array_keys( $values ); sort( $proof['ids'][$kind], SORT_NUMERIC ); } }
		return $proof;
	}
	private static function dependency_proof( array $dependencies ): array {
		$proof = []; $raw_bytes = 0; $files = [];
		$file = static function( int $id ) use ( &$files,&$raw_bytes ): array {
			if ( isset( $files[$id] ) ) return $files[$id];
			$post = get_post( $id ); $relative = get_post_meta( $id, '_wp_attached_file', true );
			if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, [ 'inherit','private' ], true ) || ! is_string( $relative ) ) throw new Archive_Error( 'Restore the referenced native media before restoring Bricks design.' );
			$path = Media_Archive_Service::source_path( $relative ); $size = filesize( $path );
			if ( false === $size || $size > Media_Archive_Service::MAX_FILE_BYTES || ( $raw_bytes += $size ) > Media_Archive_Service::MAX_RAW_BYTES || count( $files ) >= Media_Archive_Service::MAX_ATTACHMENTS ) throw new Archive_Error( 'Bricks media dependencies exceed the bounded snapshot limit.' );
			return $files[$id] = [ 'id'=>$id, 'file'=>$relative, 'mime'=>$post->post_mime_type, 'hash'=>hash_file( 'sha256', $path ), 'metadata'=>get_post_meta( $id, '_wp_attachment_metadata', true ) ];
		};
		foreach ( array_keys( $dependencies['media'] ) as $id ) $proof['media'][$id] = $file( $id );
		foreach ( array_keys( $dependencies['fonts'] ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || BRICKS_DB_CUSTOM_FONTS !== $post->post_type || 'publish' !== $post->post_status ) throw new Archive_Error( 'Restore the referenced Bricks font before restoring design.' );
			$faces = get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, true );
			if ( ! is_array( $faces ) || ! $faces ) throw new Archive_Error( 'A Bricks font has no registered faces.' );
			$font_files = []; $font_nodes = 0;
			$walk = static function( array $items, int $depth = 0 ) use ( &$walk,&$font_files,&$font_nodes,$file ): void {
				if ( ++$font_nodes > 2000 || $depth > 10 ) throw new Archive_Error( 'A Bricks font registry is too large.' );
				foreach ( $items as $key=>$value ) {
					if ( in_array( $key, [ 'woff2','woff','ttf','otf','eot' ], true ) ) {
						if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) throw new Archive_Error( 'A Bricks font file reference is unsupported.' );
						$font_files[(int) $value] = $file( (int) $value );
					} elseif ( is_array( $value ) ) $walk( $value, $depth + 1 );
				}
			};
			$walk( $faces );
			if ( ! $font_files ) throw new Archive_Error( 'A Bricks font has no available local files.' );
			$proof['fonts'][$id] = [ 'title'=>$post->post_title, 'faces'=>$faces, 'files'=>$font_files ];
		}
		foreach ( array_keys( $dependencies['templates'] ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type || 'trash' === $post->post_status ) throw new Archive_Error( 'Restore the referenced Bricks template before restoring design.' );
			$proof['templates'][$id] = [ 'title'=>$post->post_title,'author'=>(int) $post->post_author,'status'=>$post->post_status,'data'=>Content_Archive_Service::template_data( $id ) ];
		}
		foreach ( $proof as &$items ) ksort( $items ); unset( $items );
		return [ 'hash'=>hash( 'sha256', (string) wp_json_encode( $proof ) ), 'media'=>count( $dependencies['media'] ), 'fonts'=>count( $dependencies['fonts'] ), 'templates'=>count( $dependencies['templates'] ) ];
	}
	public static function write( string $name, array $setting ): void {
		if ( ! self::allowed() || ! self::handles( $name ) ) throw new Archive_Error( 'Bricks design access changed. Review the snapshot again.' );
		if ( $name === BRICKS_DB_GLOBAL_CLASSES ) \Bricks\Helpers::save_global_classes_in_db( $setting['exists'] ? $setting['value'] : [] );
		elseif ( $name === BRICKS_DB_GLOBAL_VARIABLES && $setting['exists'] ) \Bricks\Helpers::save_global_variables_in_db( $setting['value'] );
		elseif ( $name === BRICKS_DB_COMPONENTS && $setting['exists'] ) $setting['value'] = \Bricks\Components::upgrade_components( $setting['value'], false );
		// Native helpers normalize empty lists. Preserve the reviewed option's exact existence too.
		if ( $setting['exists'] ) update_option( $name, $setting['value'], false ); else delete_option( $name );
	}
	public static function refresh( array $names ): void {
		if ( ! array_filter( $names, [ self::class,'handles' ] ) ) return;
		\Bricks\Database::get_global_data();
		if ( \Bricks\Database::get_setting( 'cssLoading' ) === 'file' ) {
			\Bricks\Assets_Files::schedule_css_file_regeneration();
			if ( ! wp_next_scheduled( 'bricks_regenerate_css_files' ) ) throw new Archive_Error( 'Bricks could not schedule its native CSS rebuild.' );
		}
	}
}
