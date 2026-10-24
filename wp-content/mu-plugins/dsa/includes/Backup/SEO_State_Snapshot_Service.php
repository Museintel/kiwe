<?php
namespace DSA\Backup;

use DSA\Onboarding\Search_Defaults_Model as Defaults;
use DSA\Onboarding\Search_Settings_Writer as Handover;
use DSA\Access\Backup_Workspace_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Same-site native defaults through the existing encrypted, reviewed snapshot owner. */
final class SEO_State_Snapshot_Service {
	public const NAME = 'kiwe.seo.defaults';
	public const GROUP = 'SEO defaults';
	public static function allowed(): bool { return Backup_Workspace_Service::allowed() && current_user_can( 'manage_options' ); }
	public static function handles( string $name ): bool { return self::NAME === $name; }
	public static function groups(): array { return self::allowed() ? [ self::GROUP=>[ self::NAME ] ] : []; }
	public static function labels(): array { return [ self::NAME=>'Website SEO defaults' ]; }
	public static function read(): array {
		if ( ! self::allowed() ) throw new Archive_Error( 'Website-manager backup access is required.' );
		$missing = new \stdClass(); $stored = get_option( Defaults::OPTION, $missing );
		$value = [ 'schema'=>1, 'defaults'=>$stored === $missing ? null : $stored ];
		self::validate_options( [ self::NAME=>[ 'exists'=>true, 'value'=>$value ] ] );
		return $value;
	}
	public static function validate_options( array $options ): void {
		if ( ! array_key_exists( self::NAME, $options ) ) return;
		$setting = $options[self::NAME]; $value = $setting['value'] ?? null;
		if ( ! is_array( $setting ) || array_diff( array_keys( $setting ), [ 'exists','value' ] ) || ( $setting['exists'] ?? null ) !== true || ! is_array( $value ) || array_diff( array_keys( $value ), [ 'schema','defaults' ] ) || ( $value['schema'] ?? null ) !== 1 || ! array_key_exists( 'defaults', $value ) ) throw new Archive_Error( 'Invalid website SEO defaults were refused.' );
		// Absence is a real choice; the before-restore copy must be able to undo a newly created option.
		if ( $value['defaults'] !== null && false === Defaults::validate( $value['defaults'] ) ) throw new Archive_Error( 'Unsupported website SEO defaults need their own archive adapter.' );
	}
	public static function write( array $setting, ?array $expected = null ): void {
		if ( ! self::allowed() ) throw new Archive_Error( 'Website-manager backup access is required.' );
		self::validate_options( [ self::NAME=>$setting ] );
		if ( $expected !== null ) self::validate_options( [ self::NAME=>$expected ] );
		$before = $expected['value']['defaults'] ?? self::read()['defaults'];
		// Null denotes an absent native option, including an explicitly absent before-image.
		if ( $expected !== null && $expected['value']['defaults'] === null ) $before = null;
		$value = $setting['value']['defaults'];
		if ( ! Handover::compare_defaults( $before, $value ) ) throw new Archive_Error( 'Website SEO defaults changed during restore. The newer choice is protected.' );
		if ( self::read() !== $setting['value'] ) throw new Archive_Error( 'Website SEO defaults could not be confirmed.' );
		// Never touch SEO/AI choices, active plugins, provider credentials, source journals or delivery jobs.
		do_action( 'kiwe_seo_defaults_changed' );
		do_action( 'litespeed_purge_all' );
	}
}
