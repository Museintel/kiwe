<?php
namespace DSA\Backup;

use DSA\Access\Backup_Workspace_Service;
use DSA\Settings;
use DSA\Settings_Choice_Schema;

if ( ! defined( 'ABSPATH' ) ) exit;

/** A typed projection of existing Settings choices; never a copy of dsa_settings. */
final class Kiwe_Configuration_Snapshot_Service {
	public const NAME = 'kiwe.configuration.choices';
	public const GROUP = 'Kiwe app choices';
	public const SCHEMA = 'kiwe.configuration-choices.v1';
	private const FIELDS = [
		'app'=>[ 'welcome_message','pwa_pitch','ios_url','playstore_url','android_url','pc_url','idle_enabled','idle_delay_ms','home_timing','home_enabled','show_install_actions','button_labels' ],
		'permissions'=>[ 'enabled','retention_days','cooldown_hours','max_asks_per_session','pwa_min_home_views','pwa_min_dock_opens','pwa_min_transition_completes','pwa_min_game_completes','pwa_title','pwa_message','notifications_enabled','notifications_title','notifications_message','notification_preferences_enabled','notification_order_prompt_enabled','notification_cta_label','notification_cta_color','ai_popup_duration_ms' ],
		'metrics'=>[ 'enabled','retention_days' ],
		'schema_geo'=>[ 'enabled','breadcrumb','webpage','registry_hints' ],
	];
	private const LABELS = [
		'app'=>'App presentation','permissions'=>'PWA and notification prompts','metrics'=>'Local analytics','schema_geo'=>'Page schema',
		'welcome_message'=>'Welcome message','pwa_pitch'=>'App introduction','ios_url'=>'iOS download URL','playstore_url'=>'Play Store URL','android_url'=>'Android download URL','pc_url'=>'Desktop download URL','idle_enabled'=>'Legacy idle introduction','idle_delay_ms'=>'Idle delay (milliseconds)','home_timing'=>'Legacy Home timing','home_enabled'=>'Show Home DSA','show_install_actions'=>'Show app install buttons',
		'enabled'=>'Enabled','retention_days'=>'Retention (days)','cooldown_hours'=>'Prompt cooldown (hours)','max_asks_per_session'=>'Prompts per session','pwa_min_home_views'=>'Home views before offering installation','pwa_min_dock_opens'=>'Dock opens before offering installation','pwa_min_transition_completes'=>'Completed transitions before offering installation','pwa_min_game_completes'=>'Completed games before offering installation','pwa_title'=>'Installation title','pwa_message'=>'Installation message',
		'notifications_enabled'=>'Offer browser notifications','notifications_title'=>'Notification title','notifications_message'=>'Notification message','notification_preferences_enabled'=>'Notification preference controls','notification_order_prompt_enabled'=>'Order notification prompt','notification_cta_label'=>'Notification action label','notification_cta_color'=>'Notification action color','ai_popup_duration_ms'=>'Assistant prompt duration (milliseconds)',
		'button_labels'=>'Download button labels','breadcrumb'=>'Breadcrumb schema','webpage'=>'Webpage schema','registry_hints'=>'Registry hints',
	];
	public static function handles( string $name ): bool { return self::NAME === $name; }
	/** These controls belong to the existing technical Settings form. */
	public static function allowed(): bool { return Backup_Workspace_Service::allowed() && current_user_can( 'manage_options' ); }
	public static function available(): bool { return is_array( get_option( DSA_OPTION_SETTINGS,null ) ); }
	public static function groups(): array { return self::allowed() && self::available() ? [ self::GROUP=>[ self::NAME ] ] : []; }
	public static function descriptions(): array { return [ self::GROUP=>'App presentation, installation and notification prompts, local analytics and page schema. Existing Settings authority is required. Credentials, security, visitor consent and delivery jobs stay unchanged.' ]; }
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function access(): void { if ( ! self::allowed() ) self::fail( 'Your account cannot recover technical Kiwe app choices.' ); }
	private static function keys( $value,array $keys ): bool { return is_array( $value ) && array_keys( $value ) === $keys; }
	private static function projection( array $settings ): array {
		$out = [];
		foreach ( self::FIELDS as $section=>$keys ) {
			$fields = $settings[$section] ?? null;
			if ( ! is_array( $fields ) || array_diff( $keys,array_keys( $fields ) ) ) self::fail( 'A native Kiwe choice is unavailable. Review Settings before creating a snapshot.' );
			$out[$section] = [];
			foreach ( $keys as $key ) {
				$out[$section][$key] = $fields[$key];
				if ( 'button_labels' === $key && is_array( $fields[$key] ) ) {
					$out[$section][$key] = [];
					foreach ( [ 'playstore','android','pc' ] as $label ) {
						if ( ! array_key_exists( $label,$fields[$key] ) ) self::fail( 'A native app button label is unavailable.' );
						$out[$section][$key][$label] = $fields[$key][$label];
					}
				}
			}
		}
		return $out;
	}
	public static function read(): array {
		self::access();
		if ( ! self::available() ) self::fail( 'Native Kiwe Settings must exist before app-choice capture.' );
		$value = [ 'schema'=>self::SCHEMA,'choices'=>self::projection( ( new Settings() )->all() ) ]; self::validate( $value ); return $value;
	}
	/** Native form units and slash handling are preserved before exact validation. */
	private static function normalized( string $section,array $fields ): array {
		$input = $fields;
		if ( 'app' === $section ) $input['idle_delay_seconds'] = $fields['idle_delay_ms'] / 1000;
		if ( 'permissions' === $section ) $input['ai_popup_duration_seconds'] = $fields['ai_popup_duration_ms'] / 1000;
		return array_intersect_key( Settings_Choice_Schema::$section( wp_slash( $input ),$fields ),array_flip( self::FIELDS[$section] ) );
	}
	public static function validate( array $value ): void {
		self::access();
		if ( ! self::keys( $value,[ 'schema','choices' ] ) || self::SCHEMA !== $value['schema'] || ! self::keys( $value['choices'],array_keys( self::FIELDS ) ) ) self::fail( 'The Kiwe choices snapshot has an unsupported schema.' );
		$defaults = ( new Settings() )->defaults();
		foreach ( self::FIELDS as $section=>$keys ) {
			$fields = $value['choices'][$section];
			// A pre-Home-control v1 copy keeps newer current Home visibility unchanged.
			if ( 'app' === $section && is_array( $fields ) && ! array_key_exists( 'home_enabled', $fields ) ) $keys = array_values( array_diff( $keys, [ 'home_enabled' ] ) );
			if ( ! self::keys( $fields,$keys ) ) self::fail( 'The snapshot contains unsupported Kiwe choices.' );
			foreach ( $fields as $key=>$field ) {
				if ( 'button_labels' === $key ) {
					if ( ! self::keys( $field,[ 'playstore','android','pc' ] ) ) self::fail( 'The app button labels are invalid.' );
					foreach ( $field as $label ) if ( ! is_string( $label ) || strlen( $label ) > 100 || sanitize_text_field( $label ) !== $label ) self::fail( 'An app button label needs review in Settings.' );
					continue;
				}
				if ( gettype( $field ) !== gettype( $defaults[$section][$key] ) || ( is_string( $field ) && strlen( $field ) > 1200 ) ) self::fail( 'A Kiwe choice has an invalid type or exceeds its supported limit.' );
				if ( str_ends_with( $key,'_url' ) && '' !== $field && ( ! preg_match( '~^https?://~i',$field ) || false === wp_parse_url( $field ) || null !== wp_parse_url( $field,PHP_URL_USER ) || null !== wp_parse_url( $field,PHP_URL_PASS ) ) ) self::fail( 'An app download requires an HTTP or HTTPS URL without login credentials.' );
			}
			$clean = self::normalized( $section,$fields );
			foreach ( $fields as $key=>$field ) if ( ! array_key_exists( $key,$clean ) || $clean[$key] !== $field ) self::fail( 'A Kiwe choice needs review in its native Settings form before exact recovery.' );
		}
	}
	public static function validate_options( array $options ): void {
		if ( ! isset( $options[self::NAME] ) ) return;
		$setting = $options[self::NAME];
		if ( ! self::keys( $setting,[ 'exists','value' ] ) || true !== $setting['exists'] || ! is_array( $setting['value'] ) ) self::fail( 'The Kiwe app choices projection cannot be deleted.' );
		self::validate( $setting['value'] );
	}
	private static function display( $value ): string {
		if ( is_bool( $value ) ) return $value ? 'On' : 'Off';
		if ( is_array( $value ) ) return implode( ' · ',array_map( static fn( $key,$label )=>ucfirst( $key ).': '.$label,array_keys( $value ),$value ) );
		return '' === $value ? 'Empty' : (string) $value;
	}
	public static function review( array $current,array $target ): array {
		if ( ! isset( $target[self::NAME] ) ) return [];
		self::validate_options( $target ); $before = $current[self::NAME]['value']['choices']; $after = $target[self::NAME]['value']['choices']; $changes = [];
		foreach ( self::FIELDS as $section=>$keys ) foreach ( $keys as $key ) if ( array_key_exists( $key,$after[$section] ) && $before[$section][$key] !== $after[$section][$key] ) $changes[] = [ 'label'=>self::LABELS[$section].' / '.self::LABELS[$key],'before'=>self::display( $before[$section][$key] ),'after'=>self::display( $after[$section][$key] ) ];
		return [ [ 'label'=>self::GROUP,'changes'=>$changes ] ];
	}
	public static function write( array $setting ): void {
		self::validate_options( [ self::NAME=>$setting ] );
		// Settings merges only these reviewed fields into a fresh native store.
		// Every other section and newer field keeps its current native value.
		( new Settings() )->update_selected( $setting['value']['choices'] );
	}
}
