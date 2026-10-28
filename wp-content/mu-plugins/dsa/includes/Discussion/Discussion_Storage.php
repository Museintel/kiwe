<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Short native transactions owned by one discussion mutation, never an ambient caller. */
final class Discussion_Storage {
	private static ?int $lock_timeout = null;
	private static bool $cache_addition_suspended = false;
	public static function begin( bool $reactions = false ): bool {
		global $wpdb;
		$session = $wpdb->get_row( 'SELECT @@session.in_transaction AS active,@@session.innodb_lock_wait_timeout AS lock_timeout' );
		if ( $wpdb->last_error || ! $session || (int) $session->active !== 0 || null !== self::$lock_timeout ) return false;
		$tables = [ $wpdb->comments,$wpdb->commentmeta,$wpdb->posts,$wpdb->postmeta,$wpdb->options ];
		if ( $reactions ) $tables[]=Discussion_Reaction_Store::table();
		$sql='SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'%s')).')';
		$rows=$wpdb->get_results($wpdb->prepare($sql,$tables));
		if($wpdb->last_error||count((array)$rows)!==count($tables))return false;
		foreach($rows as$row)if(strtoupper((string)$row->ENGINE)!=='INNODB')return false;
		self::$lock_timeout=(int)$session->lock_timeout;
		self::$cache_addition_suspended=wp_suspend_cache_addition();
		if(false===$wpdb->query('SET SESSION innodb_lock_wait_timeout=1')||false===$wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')||false===$wpdb->query('START TRANSACTION')){self::restore();return false;}
		wp_suspend_cache_addition(true);
		return true;
	}
	public static function commit(): bool { global $wpdb; return false !== $wpdb->query('COMMIT'); }
	public static function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }
	public static function restore(): void {
		if(null===self::$lock_timeout)return;
		global $wpdb;$wpdb->query($wpdb->prepare('SET SESSION innodb_lock_wait_timeout=%d',self::$lock_timeout));
		self::$lock_timeout=null;wp_suspend_cache_addition(self::$cache_addition_suspended);
	}
}
