<?php
namespace DSA\PWA;

use DSA\Access\App_Workspace_Service;
use DSA\Access\Site_Owner_Service;
use DSA\Access\Team_Workspace_Service;
use DSA\Notifications\Notification_Preference_Service;

if ( ! defined('ABSPATH') ) exit;

/** Read adapters over native app observations and WordPress account metadata. No new store or worker. */
final class App_Activity_Service {
	public const FIRST_USE_META = '_kiwe_app_first_used_at';
	public const LAST_USE_META = '_kiwe_app_last_used_at';
	public static function meta_key(string $name): string { return $name.'_b'.get_current_blog_id(); }
	public static function ready(): bool { return '2' === get_option('dsa_notification_preferences_db_version'); }
	public static function notification_visible_for( int $user, int $device ): bool {
		if ( $device < 1 || ! self::ready() || ! user_can( $user, App_Workspace_Service::CAPABILITY ) || ! user_can( $user, 'list_users' ) || ! user_can( $user, 'edit_users' ) || Team_Workspace_Service::suspended( $user ) || ( is_multisite() && ! is_user_member_of_blog( $user ) ) ) return false;
		global $wpdb; $table = Notification_Preference_Service::audience_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id=%d AND is_app=1", $device ), ARRAY_A );
		return is_array( $row ) && ( ! Site_Owner_Service::owner_id() || (int) $row['user_id'] !== Site_Owner_Service::owner_id() || Site_Owner_Service::is_owner( $user ) );
	}
	/** Called only after an authenticated lifecycle event; supplied user IDs never establish identity. */
	public static function record_account_use(int $id,string $utc): void {
		if(!$id||$id!==get_current_user_id()||!is_user_logged_in()||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$utc))return;
		if(!get_user_meta($id,self::meta_key(self::FIRST_USE_META),true))add_user_meta($id,self::meta_key(self::FIRST_USE_META),$utc,true);
		$last=(string)get_user_meta($id,self::meta_key(self::LAST_USE_META),true);
		if($last&&strtotime($last.' UTC')>strtotime($utc.' UTC')-300)return;
		update_user_meta($id,self::meta_key(self::LAST_USE_META),$utc,$last);
	}
	public static function user_summary(int $id): array {
		if(!App_Workspace_Service::audience_allowed()||!current_user_can('edit_user',$id))return [];
		if(Site_Owner_Service::is_owner($id)&&!Site_Owner_Service::is_owner())return [];
		$first=(string)get_user_meta($id,self::meta_key(self::FIRST_USE_META),true);$last=(string)get_user_meta($id,self::meta_key(self::LAST_USE_META),true);
		return ['isAppUser'=>''!==$first,'firstUsedAt'=>$first?get_date_from_gmt($first):'','lastUsedAt'=>$last?get_date_from_gmt($last):''];
	}
	/** Readiness is discovered from existing consent and active subscription owners, never a copied flag. */
	private static function alert_sql(): string {
		global $wpdb;
		if('4'!==get_option('dsa_push_db_version'))return '0';
		return "(p.browser_permission='granted' AND p.channels LIKE '%\"app\"%' AND EXISTS (SELECT 1 FROM {$wpdb->prefix}dsa_push_subscriptions s WHERE s.visitor_hash=p.visitor_hash AND s.status='active' AND s.is_app=1 AND s.user_id=p.user_id))";
	}
	private static function visible_sql(): string {
		global $wpdb;
		$owner=Site_Owner_Service::owner_id();
		return $owner&&!Site_Owner_Service::is_owner()?$wpdb->prepare(' AND p.user_id<>%d',$owner):'';
	}
	public static function listing(array $input=[]): array {
		if(!App_Workspace_Service::audience_allowed())return ['available'=>false,'people'=>[]];
		$page=is_scalar($input['page']??null)?max(1,min(1000,(int)$input['page'])):1;
		$filter=in_array($input['filter']??'',['all','known','unknown','alerts'],true)?$input['filter']:'all';
		$base=['available'=>self::ready(),'people'=>[],'page'=>$page,'filter'=>$filter,'hasNext'=>false,'summary'=>['devices'=>0,'known'=>0,'unknown'=>0,'alerts'=>0],
			'note'=>'Observed app activity, not a complete download or uninstall count. A browser install report and an app opening are separate signals.',
			'campaignsUrl'=>current_user_can('kiwe_manage_notification_policy')?admin_url('admin.php?page=kiwe-notifications&view=campaigns'):'','usersUrl'=>Team_Workspace_Service::url(['team_view'=>'accounts','team_app'=>'app_users'])];
		if(!self::ready())return $base;
		global $wpdb;$table=Notification_Preference_Service::audience_table();$alerts=self::alert_sql();
		$where="p.is_app=1 AND (p.app_installed_at IS NOT NULL OR p.app_first_opened_at IS NOT NULL)".self::visible_sql();
		$summary=$wpdb->get_row("SELECT COUNT(*) AS devices,SUM(u.ID IS NOT NULL) AS known,SUM(u.ID IS NULL) AS unknown,SUM({$alerts}) AS alerts FROM {$table} p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE {$where}",ARRAY_A);
		foreach($base['summary']as$key=>$value)$base['summary'][$key]=max(0,(int)($summary[$key]??0));
		if('known'===$filter)$where.=' AND u.ID IS NOT NULL';
		if('unknown'===$filter)$where.=' AND u.ID IS NULL';
		if('alerts'===$filter)$where.=' AND '.$alerts;
		$rows=$wpdb->get_results($wpdb->prepare("SELECT p.id,p.user_id,p.app_installed_at,p.app_first_opened_at,p.app_last_opened_at,p.app_platform,u.ID AS known_id,u.display_name,{$alerts} AS alerts_ready FROM {$table} p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE {$where} ORDER BY COALESCE(p.app_last_opened_at,p.app_installed_at) DESC,p.id DESC LIMIT 21 OFFSET %d",($page-1)*20),ARRAY_A);
		if(!is_array($rows)){$base['available']=false;return $base;}
		$base['hasNext']=count($rows)>20&&$page<1000;
		foreach(array_slice($rows,0,20)as$row){
			$known=!empty($row['known_id']);$id=$known?(int)$row['user_id']:0;$can=$id&&current_user_can('edit_user',$id)&&(!Site_Owner_Service::is_owner($id)||Site_Owner_Service::is_owner());
			$base['people'][]=['id'=>(int)$row['id'],'userId'=>$id,'name'=>$known?sanitize_text_field($row['display_name']):__('Unknown app visitor','dsa'),'known'=>$known,'notificationsReady'=>!empty($row['alerts_ready']),
				'installedAt'=>$row['app_installed_at']?get_date_from_gmt($row['app_installed_at']):'','firstOpenedAt'=>$row['app_first_opened_at']?get_date_from_gmt($row['app_first_opened_at']):'','lastUsedAt'=>$row['app_last_opened_at']?get_date_from_gmt($row['app_last_opened_at']):'',
				'platform'=>in_array($row['app_platform'],['ios','android','desktop','unknown'],true)?$row['app_platform']:'unknown','userUrl'=>$can?Team_Workspace_Service::url(['team_view'=>'accounts','team_account'=>$id]):''];
		}
		return $base;
	}
	/** Small native-period aggregate for Analytics; never a visitor-path query. Times are UTC. */
	public static function period(string $from_utc,string $to_utc): ?array {
		if(!App_Workspace_Service::audience_allowed()||!self::ready())return null;
		global $wpdb;$table=Notification_Preference_Service::audience_table();$visible=self::visible_sql();
		$row=$wpdb->get_row($wpdb->prepare("SELECT SUM(p.app_installed_at>=%s AND p.app_installed_at<%s) AS installs,SUM(p.app_first_opened_at>=%s AND p.app_first_opened_at<%s) AS first_opens,COUNT(DISTINCT CASE WHEN p.app_last_opened_at>=%s AND p.app_last_opened_at<%s AND u.ID IS NOT NULL THEN p.user_id END) AS known_users FROM {$table} p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE p.is_app=1 {$visible}",$from_utc,$to_utc,$from_utc,$to_utc,$from_utc,$to_utc),ARRAY_A);
		return is_array($row)?array_map(static fn($v)=>max(0,(int)$v),$row):null;
	}
}
