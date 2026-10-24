<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Draft read-only native layout preflight. It grants no capture or restore authority. */
final class Portable_Source_Compatibility {
	public const SCHEMA = 'kiwe.portable-source-layout.v2';
	private const MAX_DEPENDENCIES = 20000;

	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function normalize( string $path ): string { return rtrim( 'Windows' === PHP_OS_FAMILY ? str_replace( '\\','/',$path ) : $path, '/' ); }
	private static function tick( ?callable $budget ): void { if ( $budget ) $budget(); }
	private static function version( $value, string $label ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 64 || ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:[-+.][a-zA-Z0-9.-]+)?$/D',$value ) ) self::fail( $label.' has no supported version declaration.' );
		return $value;
	}
	private static function ordinary( string $path, bool $directory ): string {
		if ( '' === $path || strlen( $path ) > 8192 || preg_match( '/[\x00-\x1f\x7f]/',$path ) ) self::fail( 'A native website dependency path is invalid.' );
		$normalized = self::normalize( $path ); $real = realpath( $path );
		if ( ! $real || self::normalize( $real ) !== $normalized ) self::fail( 'A native website dependency is missing, linked or uses an unsupported layout.' );
		$cursor = $normalized;
		while ( '' !== $cursor && '.' !== $cursor ) {
			if ( is_link( $cursor ) ) self::fail( 'A linked native dependency needs a separate recovery adapter.' );
			$parent = self::normalize( dirname( $cursor ) ); if ( $parent === $cursor ) break; $cursor = $parent;
		}
		clearstatcache( true,$path ); $stat = lstat( $path );
		if ( ! $stat || ( $stat['mode'] & 0170000 ) !== ( $directory ? 0040000 : 0100000 ) || ! is_readable( $path ) ) self::fail( 'A native website dependency is not an ordinary readable file or directory.' );
		return $normalized;
	}
	private static function relative( string $root, string $path, bool $directory, array $files, array $directories, string $policy ): string {
		$absolute = self::ordinary( $path,$directory );
		if ( ! str_starts_with( $absolute,$root.'/' ) ) self::fail( 'A native website dependency is outside this capture root.' );
		$relative = substr( $absolute,strlen( $root ) + 1 ); Portable_File_Inventory::relative( $relative,$policy );
		if ( ! isset( ( $directory ? $directories : $files )[$relative] ) ) self::fail( 'A required native dependency is absent from the reviewed file inventory.' );
		return $relative;
	}
	private static function inventory( array $inventory, ?callable $budget ): array {
		$policy = Portable_File_Inventory::validate_plan( $inventory,$budget );
		if ( 'Windows' === PHP_OS_FAMILY && 'posix' === $policy ) self::fail( 'POSIX source names cannot be inspected on this filesystem.' );
		return [ array_fill_keys( array_column( $inventory['files'],'path' ),true ),array_fill_keys( $inventory['directories'],true ),$policy ];
	}

	/** Requires loaded WordPress. The owner must still prove database/file consistency and destination support. */
	public static function inspect( string $root, array $inventory, ?callable $budget = null ): array {
		foreach ( [ 'is_multisite','get_option','get_template','get_stylesheet','get_template_directory','get_stylesheet_directory','get_theme_root','wp_get_theme','wp_upload_dir' ] as $function ) if ( ! function_exists( $function ) ) self::fail( 'Loaded native WordPress is required for portable compatibility review.' );
		foreach ( [ 'WP_CONTENT_DIR','WP_PLUGIN_DIR','WPMU_PLUGIN_DIR','WPINC','DSA_FILE','DSA_DIR','DSA_VERSION','KIWE_MU_LOADER_VERSION' ] as $constant ) if ( ! defined( $constant ) || ! is_string( constant( $constant ) ) ) self::fail( 'The native WordPress and Kiwe layout has not loaded completely.' );
		if ( is_multisite() ) self::fail( 'Network websites need a separate portable recovery adapter.' );
		if ( PHP_VERSION_ID < 80200 || ! extension_loaded( 'openssl' ) || ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'random_bytes' ) ) self::fail( 'This portable capture requires PHP 8.2 or newer with OpenSSL.' );
		global $wp_version, $wp_db_version, $wp_theme_directories;
		$wordpress = self::version( $wp_version ?? null,'WordPress' ); $kiwe = self::version( DSA_VERSION,'Kiwe' );
		if ( version_compare( $wordpress,'6.0','<' ) || ! is_int( $wp_db_version ) || $wp_db_version < 1 || $kiwe !== KIWE_MU_LOADER_VERSION ) self::fail( 'The native WordPress or Kiwe runtime is unsupported or mismatched.' );
		$root = self::ordinary( $root,true );
		if ( preg_match( '/^(?:[a-zA-Z]:)?\/?$/D',$root ) || self::ordinary( ABSPATH,true ) !== $root ) self::fail( 'The reviewed source must be the dedicated native WordPress root.' );
		[ $files,$directories,$policy ] = self::inventory( $inventory,$budget );
		Portable_File_Inventory::relative( WPINC );
		if ( str_contains( WPINC,'/' ) ) self::fail( 'The WordPress core include directory has an unsupported layout.' );
		$core = [];
		foreach ( [ 'index.php','wp-blog-header.php','wp-load.php','wp-settings.php','wp-config.php','wp-login.php','wp-cron.php',WPINC.'/version.php',WPINC.'/load.php',WPINC.'/functions.php','wp-admin/admin.php','wp-admin/includes/admin.php' ] as $required ) { self::tick( $budget ); $core[] = self::relative( $root,$root.'/'.$required,false,$files,$directories,$policy ); }
		$content = self::relative( $root,WP_CONTENT_DIR,true,$files,$directories,$policy );
		$plugins = self::relative( $root,WP_PLUGIN_DIR,true,$files,$directories,$policy ); $mu = self::relative( $root,WPMU_PLUGIN_DIR,true,$files,$directories,$policy );
		$package = self::relative( $root,DSA_DIR,true,$files,$directories,$policy ); $package_entry = self::relative( $root,DSA_FILE,false,$files,$directories,$policy );
		if ( ! str_starts_with( $package,$mu.'/' ) || ! str_starts_with( $package_entry,$package.'/' ) || ! function_exists( 'kiwe_mu_debug_log' ) ) self::fail( 'Kiwe must be loaded from its captured native MU package.' );
		$loader_path = ( new \ReflectionFunction( 'kiwe_mu_debug_log' ) )->getFileName();
		if ( ! is_string( $loader_path ) ) self::fail( 'The native Kiwe MU loader could not be identified.' );
		$loader = self::relative( $root,$loader_path,false,$files,$directories,$policy );
		if ( dirname( $loader ) !== $mu ) self::fail( 'The native Kiwe loader is not a captured MU entry.' );

		$active = get_option( 'active_plugins',[] );
		if ( ! is_array( $active ) || count( $active ) > 1000 ) self::fail( 'The native active plugin inventory is invalid or too large.' );
		// WordPress iterates plugin values; deactivation can leave valid numeric keys sparse.
		foreach ( array_keys( $active ) as $key ) if ( ! is_int( $key ) || $key < 0 ) self::fail( 'The native active plugin inventory has unsupported keys.' );
		$active_plugins = []; $seen = [];
		foreach ( $active as $plugin ) {
			self::tick( $budget ); if ( ! is_string( $plugin ) ) self::fail( 'A native active plugin entry is invalid.' ); Portable_File_Inventory::relative( $plugin );
			if ( substr_count( $plugin,'/' ) > 1 || ! str_ends_with( $plugin,'.php' ) || isset( $seen[$plugin] ) ) self::fail( 'A native active plugin entry uses an unsupported layout.' ); $seen[$plugin] = true;
			$active_plugins[] = self::relative( $root,WP_PLUGIN_DIR.'/'.$plugin,false,$files,$directories,$policy );
		}
		sort( $active_plugins,SORT_STRING );
		$mu_entries = [];
		try { $iterator = new \FilesystemIterator( WPMU_PLUGIN_DIR,\FilesystemIterator::SKIP_DOTS ); } catch ( \UnexpectedValueException $e ) { self::fail( 'The native MU directory could not be reviewed.' ); }
		foreach ( $iterator as $entry ) {
			self::tick( $budget ); if ( str_ends_with( $entry->getFilename(),'.php' ) ) $mu_entries[] = self::relative( $root,$entry->getPathname(),false,$files,$directories,$policy );
			if ( count( $mu_entries ) > 1000 ) self::fail( 'The native MU entry inventory is too large.' );
		}
		sort( $mu_entries,SORT_STRING );

		if ( ! is_array( $wp_theme_directories ) || count( $wp_theme_directories ) > 100 ) self::fail( 'The native theme directory registry is invalid.' );
		$theme_roots = [];
		foreach ( $wp_theme_directories as $theme_root ) { self::tick( $budget ); if ( ! is_string( $theme_root ) ) self::fail( 'A registered native theme directory is invalid.' ); $theme_roots[] = self::relative( $root,$theme_root,true,$files,$directories,$policy ); }
		$themes = [];
		foreach ( [ 'template'=>get_template(),'stylesheet'=>get_stylesheet() ] as $kind=>$slug ) {
			self::tick( $budget ); if ( ! is_string( $slug ) ) self::fail( 'The active native theme identifier is invalid.' ); Portable_File_Inventory::relative( $slug );
			if ( str_contains( $slug,'/' ) ) self::fail( 'The active native theme identifier uses an unsupported layout.' );
			$theme_root = get_theme_root( $slug ); $path = 'template' === $kind ? get_template_directory() : get_stylesheet_directory();
			if ( ! is_string( $theme_root ) || ! is_string( $path ) || self::normalize( $path ) !== self::normalize( $theme_root ).'/'.$slug ) self::fail( 'The active native theme root is inconsistent.' );
			$relative_root = self::relative( $root,$theme_root,true,$files,$directories,$policy ); $theme_roots[] = $relative_root;
			$relative = self::relative( $root,$path,true,$files,$directories,$policy ); $theme = wp_get_theme( $slug,$theme_root );
			if ( ! is_object( $theme ) || ! method_exists( $theme,'exists' ) || ! method_exists( $theme,'errors' ) || ! $theme->exists() || $theme->errors() ) self::fail( 'An active native theme is missing or invalid.' );
			self::relative( $root,$path.'/style.css',false,$files,$directories,$policy );
			$themes[$kind] = [ 'directory'=>$relative,'root'=>$relative_root ];
		}
		$theme_roots = array_values( array_unique( $theme_roots ) ); sort( $theme_roots,SORT_STRING );
		$uploads = wp_upload_dir( null,false,true );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || ! isset( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) self::fail( 'The native upload directory could not be reviewed without writing.' );
		$upload_root = self::relative( $root,$uploads['basedir'],true,$files,$directories,$policy );

		$included = get_included_files(); if ( count( $included ) > self::MAX_DEPENDENCIES ) self::fail( 'The loaded native dependency inventory is too large.' );
		$loaded = []; $entry = PHP_SAPI === 'cli' ? realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) : false;
		foreach ( $included as $path ) {
			self::tick( $budget ); $absolute = self::ordinary( $path,false );
			// A CLI operator helper is tooling; all other loaded PHP dependencies belong in the copy.
			if ( $entry && $absolute === self::normalize( $entry ) && ! str_starts_with( $absolute,$root.'/' ) ) continue;
			$loaded[] = self::relative( $root,$path,false,$files,$directories,$policy );
		}
		$loaded = array_values( array_unique( $loaded ) ); sort( $loaded,SORT_STRING );
		return [
			'schema'=>self::SCHEMA,
			'namePolicy'=>$policy,
			'scope'=>'ordinary-single-site-native-layout',
			'platform'=>[ 'php'=>PHP_VERSION,'phpMinimum'=>'8.2','wordpress'=>$wordpress,'wordpressDatabaseVersion'=>$wp_db_version,'kiwe'=>$kiwe,'operatingSystem'=>PHP_OS_FAMILY ],
			'layout'=>[ 'core'=>$core,'content'=>$content,'plugins'=>$plugins,'mustUsePlugins'=>$mu,'themeRoots'=>$theme_roots,'uploads'=>$upload_root ],
			'dependencies'=>[ 'activePlugins'=>$active_plugins,'mustUseEntries'=>$mu_entries,'themes'=>$themes,'kiwe'=>[ 'loader'=>$loader,'package'=>$package,'entry'=>$package_entry ],'loadedFiles'=>$loaded ],
			'exclusions'=>$inventory['exclusions'],
			'limits'=>[ 'databaseCaptureVerified'=>false,'databaseFileConsistencyVerified'=>false,'externalServicesCaptured'=>false,'destinationCompatibilityVerified'=>false ],
		];
	}
}
