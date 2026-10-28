<?php
namespace DSA\Saved;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Coalesced invalidations, never a second saved list or an append-only event log. */
final class Saved_Report_Journal {
	public const READY = 'dsa_saved_report_schema_v2';
	public const EPOCH = 'dsa_saved_report_epoch_v2';
	public const PREFIX = 'dsa_saved_report_change_v2_';

	public static function table(): string {
		global $wpdb;
		return function_exists( 'is_multisite' ) && is_multisite() ? $wpdb->get_blog_prefix( get_main_site_id() ) . 'options' : $wpdb->options;
	}
	public static function enabled(): bool { return self::value( self::READY ) === '1'; }
	public static function value( string $name ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . self::table() . ' WHERE option_name=%s', $name ) );
		return $wpdb->last_error ? null : (string) $value;
	}
	public static function user_key( int $id ): string { return self::PREFIX . 'u_' . $id; }
	public static function mark_user( int $id ): bool {
		return $id > 0 && self::mark( self::user_key( $id ) );
	}
	public static function mark_post( int $id ): bool {
		return $id > 0 && self::mark( self::PREFIX . 'p_' . get_current_blog_id() . '_' . $id );
	}
	private static function mark( string $key ): bool {
		$enabled = self::value( self::READY );
		if ( $enabled === null ) return false;
		if ( $enabled !== '1' ) return true;
		$token = time() . ':' . bin2hex( random_bytes( 16 ) );
		return self::put( $key, $token ) && self::put( self::EPOCH, $token );
	}
	public static function put( string $name, string $value ): bool {
		if ( $name !== self::READY && $name !== self::EPOCH && ! preg_match( '/^dsa_saved_report_change_v2_(u_[1-9][0-9]*|p_[1-9][0-9]*_[1-9][0-9]*)$/D', $name ) ) return false;
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . " (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'", $name, $value ) );
		if ( self::table() === $wpdb->options ) { wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
		return $result !== false;
	}
}
