<?php
namespace DSA\Bricks;
if ( ! defined( 'ABSPATH' ) ) exit;

/** One merge/write owner for manual Framework pushes and reviewed compiler imports. */
final class Framework_Style_Service {
	private const OPTIONS = [ 'variables'=>'BRICKS_DB_GLOBAL_VARIABLES','categories'=>'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES','colorPalette'=>'BRICKS_DB_COLOR_PALETTE','classes'=>'BRICKS_DB_GLOBAL_CLASSES','classCategories'=>'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES','themeStyles'=>'BRICKS_DB_THEME_STYLES' ];
	public static function allowed(): bool {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( '\Bricks\Builder_Permissions' ) || ! is_callable( [ '\Bricks\Helpers','save_global_classes_in_db' ] ) || ! is_callable( [ '\Bricks\Helpers','save_global_variables_in_db' ] ) ) return false;
		foreach ( self::OPTIONS as $constant ) if ( ! defined( $constant ) ) return false;
		foreach ( [ 'edit_global_classes','access_variable_manager','access_theme_styles','edit_color_palettes' ] as $permission ) if ( ! \Bricks\Builder_Permissions::user_has_permission( $permission ) ) return false;
		return true;
	}
	public static function snapshot(): array {
		if ( ! self::allowed() ) throw new Compiler_Package_Error( 'Your account needs native Bricks style editing access.' );
		$out = [];
		foreach ( self::OPTIONS as $kind=>$constant ) {
			$value = get_option( constant( $constant ),null );
			if ( null !== $value && ! is_array( $value ) ) throw new Compiler_Package_Error( 'A native Bricks style option has an unsupported format.' );
			$out[$kind] = [ 'exists'=>null !== $value,'value'=>$value ?? [] ];
		}
		return $out;
	}
	public static function fingerprint( array $snapshot ): string { return hash( 'sha256',serialize( $snapshot ) ); }
	/** Preserve all unrelated entries and stale owned styles; deletion is a separate native action. */
	public static function plan( array $before, array $universal, array $project, bool $include_theme = true, bool $allow_theme_update = false ): array {
		$after = $before; $changes = []; $reused = 0;
		foreach ( array_diff( array_keys( self::OPTIONS ),[ 'themeStyles' ] ) as $kind ) {
			$current = $before[$kind]['value'] ?? [];
			if ( ! is_array( $current ) || ! array_is_list( $current ) ) throw new Compiler_Package_Error( 'A native Bricks style list is not supported.' );
			$incoming = array_merge( $universal[$kind] ?? [],$project[$kind] ?? [] ); $seen_ids = []; $seen_names = [];
			foreach ( $incoming as $item ) {
				if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || '' === $item['id'] || ! is_string( $item['name'] ?? null ) ) throw new Compiler_Package_Error( 'An incoming native style identity is invalid.' );
				if ( isset( $seen_ids[$item['id']] ) || ( in_array( $kind,[ 'variables','classes' ],true ) && isset( $seen_names[$item['name']] ) ) ) throw new Compiler_Package_Error( 'The supplied profile repeats a native style identity.' );
				$seen_ids[$item['id']] = $seen_names[$item['name']] = true;
				$matches = []; $names = [];
				foreach ( $current as $index=>$old ) if ( is_array( $old ) ) {
					if ( ( $old['id'] ?? '' ) === $item['id'] ) $matches[] = $index;
					if ( in_array( $kind,[ 'variables','classes' ],true ) && ( $old['name'] ?? '' ) === $item['name'] ) $names[] = $index;
				}
				if ( count( $matches ) > 1 || count( $names ) > 1 ) {
					// Older Kiwe exports repeated a shared vocabulary class in two
					// categories. Reuse identical canonical definitions without
					// deleting/reassigning either existing entry. Any edited or
					// ambiguous identity still needs its native owner's review.
					$equivalent = $kind === 'classes' && ( $item['source'] ?? '' ) === 'kiwe-seam';
					foreach ( array_unique( array_merge( $matches,$names ) ) as $old_index ) {
						$old = $current[$old_index];
						$equivalent = $equivalent && ( $old['id'] ?? '' ) === $item['id'] && ( $old['name'] ?? '' ) === $item['name'] && ( $old['source'] ?? '' ) === 'kiwe-seam' && ( $old['settings'] ?? [] ) === ( $item['settings'] ?? [] );
					}
					if ( ! $equivalent ) throw new Compiler_Package_Error( 'A native ' . $kind . ' identity is ambiguous: ' . $item['name'] . '. Resolve the collision in Bricks before importing.' );
					$reused++; continue;
				}
				$index = $matches[0] ?? $names[0] ?? null;
				if ( null === $index ) { $current[] = $item; $changes[] = [ 'kind'=>$kind,'name'=>$item['name'],'action'=>'add' ]; continue; }
				$old = $current[$index];
				if ( ( $old['name'] ?? '' ) !== $item['name'] || ( $kind === 'classes' && ( $old['id'] ?? '' ) !== $item['id'] ) ) throw new Compiler_Package_Error( 'An incoming style ID or class name belongs to a different native style: ' . $item['name'] );
				$equal = $kind === 'variables' ? ( $old['value'] ?? null ) === ( $item['value'] ?? null ) : ( $kind === 'classes' ? ( $old['settings'] ?? [] ) === ( $item['settings'] ?? [] ) : $old === $item );
				if ( $equal ) { $reused++; continue; }
				$managed = in_array( $old['source'] ?? '',[ 'kiwe-universal','kiwe-project','kiwe-framework','kiwe-seam' ],true ) && ( $old['source'] ?? '' ) === ( $item['source'] ?? '' ) && ( $old['project'] ?? '' ) === ( $item['project'] ?? '' ) && ( $old['id'] ?? '' ) === $item['id'];
				if ( ! $managed ) throw new Compiler_Package_Error( 'This package conflicts with an existing native style: ' . $item['name'] . '. Existing styles have been preserved.' );
				$current[$index] = $item; $changes[] = [ 'kind'=>$kind,'name'=>$item['name'],'action'=>'update-shared' ];
			}
			$after[$kind] = $current === ( $before[$kind]['value'] ?? [] ) ? $before[$kind] : [ 'exists'=>true,'value'=>$current ];
		}
		$theme = $universal['themeStyle'] ?? [];
		if ( $include_theme && ! empty( $theme['id'] ) && ! empty( $theme['settings'] ) ) {
			$styles = $before['themeStyles']['value']; $id = (string) $theme['id'];
			$next = [ 'label'=>(string) ( $theme['label'] ?? 'Kiwe Framework' ),'settings'=>$theme['settings'] ];
			if ( isset( $styles[$id] ) && $styles[$id] !== $next && ! $allow_theme_update ) throw new Compiler_Package_Error( 'The incoming Theme Style differs from an existing style. Give the new design its own Theme Style before importing.' );
			if ( ! isset( $styles[$id] ) || $styles[$id] !== $next ) { $action = isset( $styles[$id] ) ? 'update-shared' : 'add'; $styles[$id] = $next; $changes[] = [ 'kind'=>'themeStyles','name'=>$next['label'],'action'=>$action ]; $after['themeStyles'] = [ 'exists'=>true,'value'=>$styles ]; } else $reused++;
		}
		return [ 'before'=>$before,'after'=>$after,'expected'=>self::fingerprint( $before ),'changes'=>$changes,'reused'=>$reused ];
	}
	/** Every write is journalled first. Native readback, not update_option's bool, is authoritative. */
	public static function apply( array $plan, callable $journal ): void {
		if ( ! self::allowed() || self::fingerprint( self::snapshot() ) !== $plan['expected'] ) throw new Compiler_Package_Error( 'Bricks styles changed. Review the package again before importing.' );
		$changed = false;
		foreach ( self::OPTIONS as $kind=>$constant ) {
			$before = $plan['before'][$kind]; $after = $plan['after'][$kind];
			if ( $before === $after ) continue;
			if ( self::snapshot()[$kind] !== $before ) throw new Compiler_Package_Error( 'A native style changed while importing. Resume after reviewing the retained recovery.' );
			$journal( $kind,$before,$after ); self::write( $kind,$after ); $changed = true;
			if ( self::snapshot()[$kind] !== $after ) throw new Compiler_Package_Error( 'Bricks did not retain the reviewed styles. Recovery is available.' );
		}
		if ( $changed ) self::refresh();
	}
	public static function restore( string $kind,array $before,array $after ): bool {
		if ( ! self::allowed() || ! isset( self::OPTIONS[$kind] ) ) throw new Compiler_Package_Error( 'Native style recovery permission is unavailable.' );
		$current = self::snapshot()[$kind];
		if ( $current === $before ) return true;
		if ( $current !== $after ) return false; // A later editor owns this value now.
		self::write( $kind,$before ); return self::snapshot()[$kind] === $before;
	}
	private static function write( string $kind,array $setting ): void {
		$name = constant( self::OPTIONS[$kind] );
		if ( ! $setting['exists'] ) { delete_option( $name ); return; }
		if ( $kind === 'classes' ) \Bricks\Helpers::save_global_classes_in_db( $setting['value'] );
		elseif ( $kind === 'variables' ) \Bricks\Helpers::save_global_variables_in_db( $setting['value'] );
		update_option( $name,$setting['value'],false );
	}
	public static function refresh(): void {
		if ( is_callable( [ '\Bricks\Database','get_global_data' ] ) ) \Bricks\Database::get_global_data();
		if ( ! class_exists( '\Bricks\Assets' ) || empty( \Bricks\Assets::$css_dir ) ) return;
		foreach ( [ 'variables'=>'\Bricks\Assets_Global_Variables','colorPalette'=>'\Bricks\Assets_Color_Palettes','themeStyles'=>'\Bricks\Assets_Theme_Styles' ] as $kind=>$class ) if ( is_callable( [ $class,'generate_css_file' ] ) ) $class::generate_css_file( get_option( constant( self::OPTIONS[$kind] ),[] ) );
		if ( is_callable( [ '\Bricks\Assets_Files','schedule_css_file_regeneration' ] ) ) \Bricks\Assets_Files::schedule_css_file_regeneration();
	}
}
