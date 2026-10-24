<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Small, same-site, encrypted snapshots of client-managed website choices. */
final class Configuration_Snapshot_Service {
	public const SCHEMA = 'kiwe.website-snapshot.v1';
	public const MAX_FILE = 16777216;
	private const NORMAL_MAX_FILE = 2097152;
	private const MAX_COPIES = 30;
	private const ITERATIONS = 310000;
	private const REVIEW_SECONDS = 900;
	private const MAX_GROUPS = 12;

	/** Native options plus the theme's one logo adapter. Secrets/identity policy are excluded. */
	public static function option_groups(): array {
		$groups = [
			'WordPress' => [ 'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'show_on_front', 'page_on_front', 'page_for_posts', 'site_icon', 'blog_public' ],
			'Kiwe' => [ 'kiwe_seam_design_context_v1', 'kiwe_site_logo_id', 'kiwe_site_logo_inverse_id', 'theme.custom_logo', 'kiwe_store_phone', 'kiwe_store_email', 'kiwe_store_app_enabled_v1', 'kiwe_seo_workspace_enabled_v1', 'kiwe_ai_opt_in_global_v1' ],
		];
		if ( function_exists( 'WC' ) ) $groups['WooCommerce'] = [ 'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_thousand_sep', 'woocommerce_price_decimal_sep', 'woocommerce_price_num_decimals', 'woocommerce_weight_unit', 'woocommerce_dimension_unit', 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_default_country' ];
		if ( class_exists( Bricks_Design_Snapshot_Service::class ) && ( $names = Bricks_Design_Snapshot_Service::names() ) ) $groups[Bricks_Design_Snapshot_Service::GROUP] = $names;
		if ( class_exists( Organization_Snapshot_Service::class ) ) $groups += Organization_Snapshot_Service::groups();
		if ( class_exists( Account_Profile_Snapshot_Service::class ) ) $groups += Account_Profile_Snapshot_Service::groups();
		if ( class_exists( Kiwe_Configuration_Snapshot_Service::class ) ) $groups += Kiwe_Configuration_Snapshot_Service::groups();
		if ( class_exists( Search_Performance_Snapshot_Service::class ) ) $groups += Search_Performance_Snapshot_Service::groups();
		if ( class_exists( SEO_State_Snapshot_Service::class ) ) $groups += SEO_State_Snapshot_Service::groups();
		if ( class_exists( Product_Attribute_Snapshot_Service::class ) ) $groups += Product_Attribute_Snapshot_Service::groups();
		return $groups;
	}

	private static function allowed_names(): array { return array_merge( ...array_values( self::option_groups() ) ); }
	private static function selected_names( array $selected ): array {
		$groups = self::option_groups();
		if ( ! $selected || count( $selected ) > count( $groups ) || array_diff( $selected, array_keys( $groups ) ) ) throw new \RuntimeException( 'Choose one or more available settings groups.' );
		$names = [];
		foreach ( array_unique( $selected ) as $group ) $names = array_merge( $names, $groups[ $group ] );
		return $names;
	}
	private static function groups_for_options( array $options ): array {
		$out = [];
		foreach ( self::option_groups() as $group=>$names ) if ( array_intersect( array_keys( $options ), $names ) ) $out[] = $group;
		return $out;
	}
	private static function require_crypto(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) throw new \RuntimeException( 'This server needs the OpenSSL AES-256-GCM extension for encrypted snapshots.' );
	}
	private static function site_hash(): string { return hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) . '|' . get_current_blog_id() ); }
	private static function valid_passphrase( string $passphrase ): bool { return strlen( $passphrase ) >= 16 && strlen( $passphrase ) <= 1024; }

	private static function directory( bool $create = false ): string {
		// Recovery data readers may run before the normal Core autoloader.
		require_once dirname( __DIR__ ) . '/Runtime/Private_Storage.php';
		return \DSA\Runtime\Private_Storage::directory( 'backups', $create );
	}

	/** Shared private vault; archive controllers still enforce their own actor permissions. */
	public static function private_directory( bool $create = false ): string { return self::directory( $create ); }

	private static function path( string $id, bool $stage = false ): string {
		if ( ! preg_match( '/^[a-f0-9]{24}$/D', $id ) ) throw new \RuntimeException( 'Invalid backup reference.' );
		$directory = self::directory();
		if ( ! $directory ) throw new \RuntimeException( 'No private backups are stored yet.' );
		return $directory . DIRECTORY_SEPARATOR . ( $stage ? 'stage-' : 'website-' ) . $id . '.kiwe';
	}

	private static function safe_value( $value, int $depth = 0, int $max_items = 2000 ): bool {
		if ( $depth > 20 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_array( $value ) ) {
			if ( count( $value ) > $max_items ) return false;
			foreach ( $value as $item ) if ( ! self::safe_value( $item, $depth + 1, $max_items ) ) return false;
		}
		return true;
	}

	private static function capture( array $names ): array {
		$sentinel = new \stdClass(); $out = [];
		foreach ( $names as $name ) {
			if ( class_exists( SEO_State_Snapshot_Service::class ) && SEO_State_Snapshot_Service::handles( $name ) ) { $out[$name] = [ 'exists'=>true,'value'=>SEO_State_Snapshot_Service::read() ]; continue; }
			if ( class_exists( Search_Performance_Snapshot_Service::class ) && Search_Performance_Snapshot_Service::handles( $name ) ) { $out[$name] = [ 'exists'=>true,'value'=>Search_Performance_Snapshot_Service::read() ]; continue; }
			if ( class_exists( Kiwe_Configuration_Snapshot_Service::class ) && Kiwe_Configuration_Snapshot_Service::handles( $name ) ) { $out[$name] = [ 'exists'=>true,'value'=>Kiwe_Configuration_Snapshot_Service::read() ]; continue; }
			if ( class_exists( Account_Profile_Snapshot_Service::class ) && Account_Profile_Snapshot_Service::handles( $name ) ) { $out[$name] = [ 'exists'=>true,'value'=>Account_Profile_Snapshot_Service::read() ]; continue; }
			if ( class_exists( Product_Attribute_Snapshot_Service::class ) && Product_Attribute_Snapshot_Service::handles( $name ) ) { $out[$name] = [ 'exists'=>true,'value'=>Product_Attribute_Snapshot_Service::read() ]; continue; }
			if ( class_exists( Organization_Snapshot_Service::class ) && Organization_Snapshot_Service::handles( $name ) ) { $out[$name] = [ 'exists'=>true,'value'=>Organization_Snapshot_Service::read( $name ) ]; continue; }
			$value = 'theme.custom_logo' === $name ? get_theme_mod( 'custom_logo', $sentinel ) : get_option( $name, $sentinel );
			if ( $value === $sentinel ) { $out[ $name ] = [ 'exists'=>false ]; continue; }
			$design = class_exists( Bricks_Design_Snapshot_Service::class ) && Bricks_Design_Snapshot_Service::handles( $name );
			if ( ! self::safe_value( $value, 0, $design ? 10000 : 2000 ) ) throw new \RuntimeException( $design ? 'This Bricks design exceeds the supported settings structure. Keep a hosting backup for complete recovery.' : 'A selected setting cannot be represented safely: ' . $name );
			// WP stores non-array scalars as text. Normalize before fingerprints so
			// a fresh database read and an in-request option cache compare equally.
			if ( ! is_array( $value ) ) $value = null === $value ? '' : (string) $value;
			$out[ $name ] = [ 'exists'=>true, 'value'=>$value ];
		}
		return $out;
	}
	private static function write_setting( string $name, array $setting, ?array $expected = null ): void {
		if ( class_exists( SEO_State_Snapshot_Service::class ) && SEO_State_Snapshot_Service::handles( $name ) ) { SEO_State_Snapshot_Service::write( $setting, $expected ); return; }
		if ( class_exists( Search_Performance_Snapshot_Service::class ) && Search_Performance_Snapshot_Service::handles( $name ) ) { Search_Performance_Snapshot_Service::write( $setting ); return; }
		if ( class_exists( Kiwe_Configuration_Snapshot_Service::class ) && Kiwe_Configuration_Snapshot_Service::handles( $name ) ) { Kiwe_Configuration_Snapshot_Service::write( $setting ); return; }
		if ( class_exists( Account_Profile_Snapshot_Service::class ) && Account_Profile_Snapshot_Service::handles( $name ) ) { Account_Profile_Snapshot_Service::write( $setting ); return; }
		if ( class_exists( Product_Attribute_Snapshot_Service::class ) && Product_Attribute_Snapshot_Service::handles( $name ) ) { Product_Attribute_Snapshot_Service::write( $setting ); return; }
		if ( class_exists( Organization_Snapshot_Service::class ) && Organization_Snapshot_Service::handles( $name ) ) { Organization_Snapshot_Service::write( $name,$setting ); return; }
		if ( class_exists( Bricks_Design_Snapshot_Service::class ) && Bricks_Design_Snapshot_Service::handles( $name ) ) { Bricks_Design_Snapshot_Service::write( $name, $setting ); return; }
		if ( 'theme.custom_logo' === $name ) { if ( $setting['exists'] ) set_theme_mod( 'custom_logo', $setting['value'] ); else remove_theme_mod( 'custom_logo' ); return; }
		// A content restore must not change an existing option's loading policy.
		// Let the native API preserve its autoload flag instead of forcing it off.
		if ( $setting['exists'] ) update_option( $name, $setting['value'] );
		else delete_option( $name );
	}
	/** Undo only values attributable to this restore; preserve newer fields in a partial adapter. */
	private static function rollback_value( $before, $planned, $current, bool &$conflict ) {
		$same = static fn( $a, $b ) => wp_json_encode( $a ) === wp_json_encode( $b );
		if ( $same( $before, $planned ) || $same( $current, $before ) ) return $current;
		if ( $same( $current, $planned ) ) return $before;
		if ( ! is_array( $before ) || ! is_array( $planned ) || ! is_array( $current ) ) { $conflict = true; return $current; }
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $planned ) ) ) as $key ) {
			$old = array_key_exists( $key, $before ); $target = array_key_exists( $key, $planned ); $present = array_key_exists( $key, $current );
			if ( ! $old ) { if ( ! $present ) continue; if ( $same( $current[$key], $planned[$key] ) ) unset( $current[$key] ); else $conflict = true; }
			elseif ( ! $target ) { if ( ! $present ) $current[$key] = $before[$key]; elseif ( ! $same( $current[$key], $before[$key] ) ) $conflict = true; }
			elseif ( $present ) $current[$key] = self::rollback_value( $before[$key], $planned[$key], $current[$key], $conflict );
			elseif ( ! $same( $before[$key], $planned[$key] ) ) $conflict = true;
		}
		return $current;
	}
	private static function validate_references( array $options ): void {
		$profile = $options['kiwe_seam_design_context_v1']['value'] ?? [];
		foreach ( ['squareLogoId','roundLogoId'] as $asset ) {
			$id = is_array($profile) ? ($profile['identity'][$asset] ?? 0) : 0;
			if ( ! is_int($id) || $id < 0 || ($id && ! wp_attachment_is_image($id)) ) throw new \RuntimeException('Restore the saved business logo image before these settings.');
		}
		if ( class_exists( SEO_State_Snapshot_Service::class ) ) SEO_State_Snapshot_Service::validate_options( $options );
		if ( class_exists( Kiwe_Configuration_Snapshot_Service::class ) ) Kiwe_Configuration_Snapshot_Service::validate_options( $options );
		if ( class_exists( Search_Performance_Snapshot_Service::class ) ) Search_Performance_Snapshot_Service::validate_options( $options );
		if ( class_exists( Account_Profile_Snapshot_Service::class ) ) Account_Profile_Snapshot_Service::validate_options( $options );
		if ( class_exists( Product_Attribute_Snapshot_Service::class ) ) Product_Attribute_Snapshot_Service::validate_options( $options );
		if ( class_exists( Organization_Snapshot_Service::class ) ) Organization_Snapshot_Service::validate_options( $options );
		if ( class_exists(\DSA\Onboarding\Native_Service_Conversion_Service::class) ) {
			$profileKey=\DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE;
			if ( isset($options[$profileKey]) ) \DSA\Onboarding\Native_Service_Conversion_Service::assert_settings_profile($options[$profileKey]);
		}
		foreach ( [ 'page_on_front','page_for_posts','site_icon','kiwe_site_logo_id','kiwe_site_logo_inverse_id','theme.custom_logo' ] as $name ) {
			$setting = $options[$name] ?? null;
			if ( ! $setting || ! $setting['exists'] ) continue;
			$value = $setting['value'];
			if ( ! is_scalar( $value ) || ! preg_match( '/^[0-9]*$/D', (string) $value ) ) throw new \RuntimeException( 'An image or page reference is invalid: ' . $name );
			$id = (int) $value;
			if ( ! $id ) continue;
			if ( in_array( $name, [ 'page_on_front','page_for_posts' ], true ) ) {
				$post = get_post( $id );
				if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) throw new \RuntimeException( 'A saved page is no longer available. Restore that page before these settings.' );
			} elseif ( ! wp_attachment_is_image( $id ) ) throw new \RuntimeException( 'A saved logo or icon is no longer available. Restore that image before these settings.' );
		}
	}

	private static function design_proof( array $options, array $templates = [] ): array { return class_exists( Bricks_Design_Snapshot_Service::class ) ? Bricks_Design_Snapshot_Service::validate( $options, $templates ) : []; }
	private static function refresh_design( array $names ): void { if ( class_exists( Bricks_Design_Snapshot_Service::class ) ) Bricks_Design_Snapshot_Service::refresh( $names ); }
	private static function fingerprint( array $names ): string { return hash( 'sha256', (string) wp_json_encode( self::capture( $names ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
	private static function file_limit( array $options ): int { return class_exists( Bricks_Design_Snapshot_Service::class ) && array_filter( array_keys( $options ), [ Bricks_Design_Snapshot_Service::class,'handles' ] ) ? self::MAX_FILE : self::NORMAL_MAX_FILE; }

	private static function envelope( array $options, string $passphrase, string $label ): string {
		self::require_crypto();
		if ( ! self::valid_passphrase( $passphrase ) ) throw new \RuntimeException( 'Use a recovery passphrase of at least 16 characters.' );
		$payload = wp_json_encode( [ 'schema'=>self::SCHEMA, 'siteHash'=>self::site_hash(), 'theme'=>get_stylesheet(), 'options'=>$options ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$limit = self::file_limit( $options );
		if ( ! is_string( $payload ) || strlen( $payload ) > $limit / 2 ) throw new \RuntimeException( 'These settings exceed the supported snapshot size. Keep a hosting backup for complete recovery.' );
		return Archive_Cipher::seal( [ 'theme'=>get_stylesheet(), 'options'=>$options ], self::SCHEMA, $passphrase, [ 'createdAt'=>gmdate( 'c' ), 'label'=>sanitize_text_field( $label ), 'groups'=>self::groups_for_options( $options ), 'count'=>count( $options ) ], $limit );
	}

	private static function read_envelope( string $bytes ): array {
		if ( strlen( $bytes ) > self::MAX_FILE ) throw new \RuntimeException( 'This snapshot is too large for the settings restore.' );
		$envelope = json_decode( $bytes, true, 20 );
		if ( ! is_array( $envelope ) || self::SCHEMA !== ( $envelope['schema'] ?? '' ) || ! is_string( $envelope['siteHash'] ?? null ) || ! hash_equals( self::site_hash(), $envelope['siteHash'] ) ) throw new \RuntimeException( 'This is not a settings snapshot for this website.' );
		if ( ! is_string( $envelope['createdAt'] ?? null ) || strlen( $envelope['createdAt'] ) > 40 || ! is_string( $envelope['label'] ?? null ) || strlen( $envelope['label'] ) > 320 || ! is_int( $envelope['count'] ?? null ) || $envelope['count'] < 0 || ! is_array( $envelope['groups'] ?? null ) || count( $envelope['groups'] ) > self::MAX_GROUPS || array_filter( $envelope['groups'], static fn( $name ) => ! is_string( $name ) ) || ! is_string( $envelope['data'] ?? null ) ) throw new \RuntimeException( 'The snapshot header is damaged.' );
		$crypto = $envelope['crypto'] ?? null;
		if ( ! is_array( $crypto ) || ( $crypto['method'] ?? '' ) !== 'AES-256-GCM' || ( $crypto['kdf'] ?? '' ) !== 'PBKDF2-SHA256' || (int) ( $crypto['iterations'] ?? 0 ) !== self::ITERATIONS ) throw new \RuntimeException( 'This snapshot uses an unsupported encryption format.' );
		return $envelope;
	}

	private static function decrypt( string $bytes, string $passphrase ): array {
		self::require_crypto();
		$envelope = self::read_envelope( $bytes ); $crypto = $envelope['crypto'];
		if ( ! self::valid_passphrase( $passphrase ) ) throw new \RuntimeException( 'Enter the recovery passphrase.' );
		$payload = Archive_Cipher::open( $bytes, self::SCHEMA, $passphrase, self::MAX_FILE );
		if ( ! is_array( $payload ) || self::SCHEMA !== ( $payload['schema'] ?? '' ) || ! hash_equals( self::site_hash(), (string) ( $payload['siteHash'] ?? '' ) ) || ! is_array( $payload['options'] ?? null ) ) throw new \RuntimeException( 'The passphrase is wrong or the snapshot is damaged.' );
		$allowed = self::allowed_names();
		if ( strlen( $bytes ) > self::file_limit( $payload['options'] ) || strlen( (string) wp_json_encode( $payload ) ) > self::file_limit( $payload['options'] ) / 2 ) throw new \RuntimeException( 'These settings exceed the supported snapshot size.' );
		if ( array_diff( array_keys( $payload['options'] ), $allowed ) ) throw new \RuntimeException( 'The snapshot contains an unsupported setting.' );
		if ( isset( $payload['options']['theme.custom_logo'] ) && get_stylesheet() !== ( $payload['theme'] ?? null ) ) throw new \RuntimeException( 'The active theme changed. Restore a WordPress-only snapshot or use a copy made for this theme.' );
		foreach ( $payload['options'] as $name=>$setting ) {
			$design = class_exists( Bricks_Design_Snapshot_Service::class ) && Bricks_Design_Snapshot_Service::handles( $name );
			if ( ! is_array( $setting ) || ! is_bool( $setting['exists'] ?? null ) || array_diff( array_keys( $setting ), [ 'exists','value' ] ) || ( $setting['exists'] && ( ! array_key_exists( 'value', $setting ) || ! self::safe_value( $setting['value'], 0, $design ? 10000 : 2000 ) ) ) || ( ! $setting['exists'] && array_key_exists( 'value', $setting ) ) ) throw new \RuntimeException( 'The snapshot contains an unsupported setting value.' );
		}
		return $payload['options'];
	}

	private static function write_copy( array $options, string $passphrase, string $label ): array {
		$directory = self::directory( true ); $id = bin2hex( random_bytes( 12 ) ); $path = $directory . DIRECTORY_SEPARATOR . 'website-' . $id . '.kiwe';
		$bytes = self::envelope( $options, $passphrase, $label ); $temporary = $path . '.partial';
		if ( file_put_contents( $temporary, $bytes, LOCK_EX ) !== strlen( $bytes ) || ! rename( $temporary, $path ) ) { @unlink( $temporary ); throw new \RuntimeException( 'The private snapshot could not be saved.' ); }
		@chmod( $path, 0600 );
		return [ 'id'=>$id, 'createdAt'=>gmdate( 'c' ), 'label'=>$label, 'bytes'=>strlen( $bytes ), 'count'=>count( $options ) ];
	}

	public function create( string $passphrase, string $label = '', ?array $groups = null ): array {
		if ( count( $this->inventory() ) >= self::MAX_COPIES ) throw new \RuntimeException( 'Keep at most 30 private copies. Download and remove an older copy first.' );
		$label = sanitize_text_field( $label );
		$names = self::selected_names( $groups ?? array_keys( self::option_groups() ) );
		$options = self::capture( $names );
		if ( class_exists( Organization_Snapshot_Service::class ) ) Organization_Snapshot_Service::validate_options( $options );
		self::design_proof( $options );
		if ( class_exists( Account_Profile_Snapshot_Service::class ) ) Account_Profile_Snapshot_Service::validate_options( $options );
		if ( class_exists( Product_Attribute_Snapshot_Service::class ) ) Product_Attribute_Snapshot_Service::validate_options( $options );
		if ( ! $options ) throw new \RuntimeException( 'No saved choices were found in the selected groups.' );
		return self::write_copy( $options, $passphrase, function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 80 ) : substr( $label, 0, 80 ) );
	}

	/** Composite design capture still uses the original settings envelope and native whitelist. */
	public static function capture_design( string $phrase ): array {
		$capture = self::read_design();
		return [ ...$capture,'bytes'=>self::envelope( $capture['options'],$phrase,'Bricks design settings' ) ];
	}
	public static function read_design(): array {
		if ( ! Bricks_Design_Snapshot_Service::allowed() ) throw new Archive_Error( 'Native Bricks design access is required.' );
		$options = self::capture( Bricks_Design_Snapshot_Service::names() ); self::design_proof( $options );
		return [ 'options'=>$options,'hash'=>hash( 'sha256', (string) wp_json_encode( $options ) ) ];
	}
	public static function inspect_design( string $bytes, string $phrase ): array {
		if ( ! Bricks_Design_Snapshot_Service::allowed() ) throw new Archive_Error( 'Native Bricks design access is required.' );
		$options = self::decrypt( $bytes,$phrase ); $names = Bricks_Design_Snapshot_Service::names();
		if ( count( $options ) !== count( $names ) || array_diff( array_keys( $options ),$names ) ) throw new Archive_Error( 'A design package must contain only the complete native Bricks settings group.' );
		return $options;
	}
	public function inventory(): array {
		$directory = self::directory(); if ( ! $directory ) return [];
		foreach ( glob( $directory . DIRECTORY_SEPARATOR . 'stage-*.kiwe' ) ?: [] as $staged ) if ( is_file( $staged ) && ! is_link( $staged ) && filemtime( $staged ) < time() - DAY_IN_SECONDS ) @unlink( $staged );
		$items = [];
		foreach ( glob( $directory . DIRECTORY_SEPARATOR . 'website-*.kiwe' ) ?: [] as $path ) {
			if ( count( $items ) >= 100 ) break;
			if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > self::MAX_FILE || ! preg_match( '/website-([a-f0-9]{24})\.kiwe$/D', basename( $path ), $match ) ) continue;
			$body = file_get_contents( $path );
			try { $envelope = is_string( $body ) ? self::read_envelope( $body ) : null; } catch ( \Throwable $error ) { continue; }
			$items[] = [ 'id'=>$match[1], 'createdAt'=>sanitize_text_field( (string) ( $envelope['createdAt'] ?? '' ) ), 'label'=>sanitize_text_field( (string) ( $envelope['label'] ?? '' ) ), 'groups'=>array_values( array_intersect( (array) ( $envelope['groups'] ?? [] ), array_keys( self::option_groups() ) ) ), 'count'=>absint( $envelope['count'] ?? 0 ), 'bytes'=>filesize( $path ) ];
		}
		usort( $items, static fn( $a, $b ) => strcmp( $b['createdAt'], $a['createdAt'] ) );
		return array_slice( $items, 0, self::MAX_COPIES );
	}

	public function download_path( string $id ): string {
		$path = self::path( $id );
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > self::MAX_FILE ) throw new \RuntimeException( 'This private snapshot is unavailable.' );
		self::read_envelope( (string) file_get_contents( $path ) );
		return $path;
	}

	public function remove( string $id ): void { $path = $this->download_path( $id ); if ( ! @unlink( $path ) ) throw new \RuntimeException( 'This private snapshot could not be removed.' ); }

	/** Revalidate selected native jobs at every existing restore boundary. */
	private static function recoveries( array $selection, bool $reviewed = false ): array {
		$out = []; $media_jobs = [];
		foreach ( [ 'fontRecovery','mediaRecovery','templateRecovery' ] as $key ) if ( ! empty( $selection[$key] ) ) {
			if ( ! is_string( $selection[$key] ) ) throw new Archive_Error( 'Choose a completed recovery from this account.' );
			$id = $selection[$key];
			if ( 'templateRecovery' === $key ) $out[$key] = ( new Content_Archive_Job_Service() )->recovery_map( $id );
			else { if ( ! isset( $media_jobs[$id] ) ) $media_jobs[$id] = ( new Media_Archive_Job_Service() )->recovery_map( $id, true ); $out[$key] = $media_jobs[$id]; }
			if ( $reviewed && ! hash_equals( (string) ( $selection[$key . 'Proof'] ?? '' ), $out[$key]['proof'] ) ) throw new Archive_Error( 'The reviewed recovery changed. Review the settings restore again.' );
			if ( 'mediaRecovery' === $key && ! in_array( $out[$key]['scope'] ?? '', [ 'media','attachment','bricks_design_assets' ], true ) ) throw new Archive_Error( 'Choose a completed media recovery for design images.' );
		}
		return $out;
	}
	private static function recovered_design( array $options, array $recoveries ): array {
		if ( ! empty( $recoveries['fontRecovery']['fontMap'] ) ) $options = Bricks_Font_Archive_Service::remap_design( $options, $recoveries['fontRecovery']['fontMap'] );
		return $recoveries ? Bricks_Design_Snapshot_Service::remap_design( $options, $recoveries['mediaRecovery']['map'] ?? [], $recoveries['templateRecovery']['map'] ?? [] ) : $options;
	}
	public function preview_upload( string $bytes, string $passphrase, string $font_recovery = '', string $media_recovery = '', string $template_recovery = '' ): array {
		$options = self::decrypt( $bytes, $passphrase );
		if ( class_exists( Account_Profile_Snapshot_Service::class ) ) $options = Account_Profile_Snapshot_Service::merge_options( $options );
		if ( class_exists( Product_Attribute_Snapshot_Service::class ) ) $options = Product_Attribute_Snapshot_Service::merge_options( $options );
		if ( class_exists( Organization_Snapshot_Service::class ) ) $options = Organization_Snapshot_Service::merge_options( $options );
		$selection = [ 'fontRecovery'=>$font_recovery,'mediaRecovery'=>$media_recovery,'templateRecovery'=>$template_recovery ]; $recoveries = self::recoveries( $selection );
		$options = self::recovered_design( $options, $recoveries );
		self::validate_references( $options ); $dependencies = self::design_proof( $options, $recoveries['templateRecovery']['map'] ?? [] );
		$directory = self::directory( true ); $id = bin2hex( random_bytes( 12 ) ); $path = $directory . DIRECTORY_SEPARATOR . 'stage-' . $id . '.kiwe';
		if ( file_put_contents( $path, $bytes, LOCK_EX ) !== strlen( $bytes ) ) { @unlink( $path ); throw new \RuntimeException( 'The review copy could not be staged.' ); }
		@chmod( $path, 0600 );
		try {
			$names = array_keys( $options ); $current = self::capture( $names ); $changed = [];
			foreach ( $options as $name=>$value ) if ( ! array_key_exists( $name, $current ) || wp_json_encode( $current[$name] ) !== wp_json_encode( $value ) ) $changed[] = $name;
			$token = bin2hex( random_bytes( 16 ) ); $review = [ 'id'=>$id, 'fileHash'=>hash( 'sha256', $bytes ), 'currentHash'=>self::fingerprint( $names ), 'dependencyHash'=>$dependencies['hash'] ?? '', 'actor'=>get_current_user_id(), ...$selection ];
			foreach ( $recoveries as $key=>$recovery ) $review[$key . 'Proof'] = $recovery['proof'];
			if ( ! set_transient( 'kiwe_backup_review_' . $token, $review, self::REVIEW_SECONDS ) ) throw new \RuntimeException( 'The restore review could not be saved.' );
			return [ 'token'=>$token, 'changed'=>$changed, 'count'=>count( $options ), 'changedCount'=>count( $changed ), 'groups'=>self::groups_for_options( $options ), 'organization'=>[ ...( class_exists( Organization_Snapshot_Service::class ) ? Organization_Snapshot_Service::review( $current,$options ) : [] ),...( class_exists( Account_Profile_Snapshot_Service::class ) ? Account_Profile_Snapshot_Service::review( $current,$options ) : [] ),...( class_exists( Product_Attribute_Snapshot_Service::class ) ? Product_Attribute_Snapshot_Service::review( $current,$options ) : [] ),...( class_exists( Kiwe_Configuration_Snapshot_Service::class ) ? Kiwe_Configuration_Snapshot_Service::review( $current,$options ) : [] ) ], 'recoveredFonts'=>count( $recoveries['fontRecovery']['fontMap'] ?? [] ),'recoveredMedia'=>count( $recoveries['mediaRecovery']['map'] ?? [] ),'recoveredTemplates'=>count( $recoveries['templateRecovery']['map'] ?? [] ), 'designDependencies'=>$dependencies ? array_diff_key( $dependencies, [ 'hash'=>true ] ) : null ];
		} catch ( \Throwable $error ) { @unlink( $path ); throw $error; }
	}

	/** Retire only the current actor's native review; no key or setting write is involved. */
	public function retire_review( string $token ): void {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D',$token ) || ! is_user_logged_in() ) return;
		$review = get_transient( 'kiwe_backup_review_'.$token );
		if ( ! is_array( $review ) || ( $review['actor'] ?? 0 ) !== get_current_user_id() ) return;
		$path = self::path( $review['id'],true ); if ( is_file( $path ) && ! @unlink( $path ) ) throw new Archive_Error( 'The old design review could not be retired.' );
		delete_transient( 'kiwe_backup_review_'.$token );
	}
	public function apply( string $token, string $passphrase, ?callable $observer = null ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) throw new \RuntimeException( 'The restore review expired.' );
		$review = get_transient( 'kiwe_backup_review_' . $token );
		if ( ! is_array( $review ) || (int) ( $review['actor'] ?? 0 ) !== get_current_user_id() ) throw new \RuntimeException( 'The restore review expired.' );
		$path = self::path( (string) ( $review['id'] ?? '' ), true );
		$bytes = is_file( $path ) && ! is_link( $path ) && filesize( $path ) <= self::MAX_FILE ? file_get_contents( $path ) : false;
		if ( ! is_string( $bytes ) || ! hash_equals( (string) ( $review['fileHash'] ?? '' ), hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( 'The reviewed snapshot changed or disappeared.' );
		$options = self::decrypt( $bytes, $passphrase );
		if ( class_exists( Organization_Snapshot_Service::class ) ) $options = Organization_Snapshot_Service::merge_options( $options );
		if ( class_exists( Account_Profile_Snapshot_Service::class ) ) $options = Account_Profile_Snapshot_Service::merge_options( $options );
		if ( class_exists( Product_Attribute_Snapshot_Service::class ) ) $options = Product_Attribute_Snapshot_Service::merge_options( $options );
		$recoveries = self::recoveries( $review, true ); $options = self::recovered_design( $options, $recoveries );
		$names = array_keys( $options );
		self::validate_references( $options );
		if ( ! hash_equals( (string) ( $review['dependencyHash'] ?? '' ), (string) ( self::design_proof( $options, $recoveries['templateRecovery']['map'] ?? [] )['hash'] ?? '' ) ) ) throw new \RuntimeException( 'Bricks design dependencies changed since review. Review the snapshot again.' );
		if ( ! hash_equals( (string) ( $review['currentHash'] ?? '' ), self::fingerprint( $names ) ) ) throw new \RuntimeException( 'Website settings changed since review. Review the snapshot again.' );
		if ( count( $this->inventory() ) >= self::MAX_COPIES ) throw new \RuntimeException( 'Download and remove an older private copy before restoring.' );
		$old_lock = get_option( 'kiwe_backup_restore_lock_v1', null );
		if ( is_array( $old_lock ) && (int) ( $old_lock['at'] ?? 0 ) < time() - 600 ) delete_option( 'kiwe_backup_restore_lock_v1' );
		$lock = bin2hex( random_bytes( 12 ) );
		if ( ! add_option( 'kiwe_backup_restore_lock_v1', [ 'token'=>$lock, 'at'=>time() ], '', false ) ) throw new \RuntimeException( 'Another restore is already running.' );
		$before = []; $rollback = null; $written = []; $committed = false;
		try {
			$before = self::capture( $names );
			if ( ! hash_equals( (string) $review['currentHash'], self::fingerprint( $names ) ) ) throw new \RuntimeException( 'Website settings changed during restore preparation.' );
			$rollback = self::write_copy( $before, $passphrase, 'Before settings restore' );
			if ( $observer ) $observer( 'prepared', [ 'rollbackId'=>$rollback['id'] ] );
			self::validate_references( $options );
			self::recoveries( $review, true );
			if ( ! hash_equals( (string) ( $review['dependencyHash'] ?? '' ), (string) ( self::design_proof( $options, $recoveries['templateRecovery']['map'] ?? [] )['hash'] ?? '' ) ) ) throw new \RuntimeException( 'Bricks design dependencies changed during restore preparation.' );
			if ( ! hash_equals( (string) $review['currentHash'], self::fingerprint( $names ) ) ) throw new \RuntimeException( 'Website settings changed during restore preparation.' );
			$changed = 0;
			foreach ( $options as $name=>$setting ) {
				if ( wp_json_encode( $before[$name] ) === wp_json_encode( $setting ) ) continue;
				$written[] = $name;
				self::write_setting( $name, $setting, $before[$name] );
				if ( wp_json_encode( self::capture( [ $name ] )[$name] ) !== wp_json_encode( $setting ) ) throw new \RuntimeException( 'WordPress could not confirm the setting: ' . $name );
				++$changed;
			}
			if ( ! hash_equals( (string) ( $review['dependencyHash'] ?? '' ), (string) ( self::design_proof( $options, $recoveries['templateRecovery']['map'] ?? [] )['hash'] ?? '' ) ) ) throw new \RuntimeException( 'Bricks design dependencies changed during restore.' );
			self::recoveries( $review, true );
			self::refresh_design( $names );
			if ( $observer ) { $observer( 'complete', [ 'restored'=>$changed,'rollbackId'=>$rollback['id'] ] ); $committed = true; }
			delete_transient( 'kiwe_backup_review_' . $token ); @unlink( $path );
			return [ 'restored'=>$changed, 'rollbackId'=>$rollback['id'] ];
		} catch ( \Throwable $error ) {
			// A durable composite completion follows native readback and CSS refresh.
			// Review-token cleanup cannot turn that confirmed restore into a rollback.
			if ( $committed ) return [ 'restored'=>$changed,'rollbackId'=>$rollback['id'] ];
			// Preparation failure has not written any setting. Never overwrite a
			// concurrent editor's newer choice while handling a stale review.
			if ( ! $written ) throw $error;
			$rollback_ok = true;
			foreach ( array_reverse( $written ) as $name ) {
				try {
					$current = self::capture( [ $name ] )[$name];
					if ( wp_json_encode( $current ) === wp_json_encode( $before[$name] ) ) continue;
					// SEO defaults are one atomic option. A newer option is protected as a whole.
					if ( class_exists( SEO_State_Snapshot_Service::class ) && SEO_State_Snapshot_Service::handles( $name ) ) {
						if ( wp_json_encode( $current ) !== wp_json_encode( $options[$name] ) ) { $rollback_ok = false; continue; }
						$restore = $before[$name];
					} else {
						$conflict = false; $restore = self::rollback_value( $before[$name], $options[$name], $current, $conflict );
						if ( $conflict ) $rollback_ok = false;
					}
					if ( wp_json_encode( $restore ) !== wp_json_encode( $current ) ) self::write_setting( $name, $restore, $current );
					if ( wp_json_encode( self::capture( [ $name ] )[$name] ) !== wp_json_encode( $restore ) ) $rollback_ok = false;
				} catch ( \Throwable $ignored ) { $rollback_ok = false; }
			}
			try { self::refresh_design( $written ); } catch ( \Throwable $ignored ) { $rollback_ok = false; }
			throw new \RuntimeException( $rollback_ok ? $error->getMessage() . ' The before-restore choices were put back.' : $error->getMessage() . ' Automatic rollback could not be confirmed; use the retained before-restore copy.', 0, $error );
		} finally {
			$current_lock = get_option( 'kiwe_backup_restore_lock_v1', [] );
			if ( is_array( $current_lock ) && ( $current_lock['token'] ?? '' ) === $lock ) delete_option( 'kiwe_backup_restore_lock_v1' );
		}
	}
}
