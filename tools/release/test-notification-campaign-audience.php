<?php
declare(strict_types=1);
namespace DSA { class Settings {public bool $on=true;public function get($key,$default=[]){return $key==='permissions'?['notification_preferences_enabled'=>$this->on]:$default;}} }
namespace DSA\Trust { class Trust_Service {} }
namespace DSA\Access {class Team_Workspace_Service {public static function suspended(int $id):bool{return false;}}}
namespace {
	define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';define('ARRAY_A','ARRAY_A');define('DAY_IN_SECONDS',86400);$checks=0;$meta=[];$users=[];$rows=[];$transients=[];
	class WP_Error {}
	$wpdb=new class {public string $prefix='test_';public string $sql='';public function esc_like($value){return addcslashes($value,'_%\\');}public function prepare($sql,...$args){foreach($args as $arg){$sql=preg_replace_callback('/%[ds]/',static fn($match)=>$match[0]==='%d'?(string)(int)$arg:"'".(string)$arg."'",$sql,1);}return $sql;}public function get_results($sql,$format){$this->sql=$sql;return $GLOBALS['rows'];}};
	function ok($condition,$label){$GLOBALS['checks']++;if(!$condition)throw new \RuntimeException($label);}
	function get_option($key,$default=false){return $key==='dsa_notification_preferences_db_version'?'2':$default;}
	function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}function sanitize_text_field($v){return (string)$v;}function sanitize_email($v){return filter_var((string)$v,FILTER_SANITIZE_EMAIL);}
	function absint($v){return abs((int)$v);}function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
	function get_transient($key){return $GLOBALS['transients'][$key]??false;}
	function get_userdata($id){return $GLOBALS['users'][$id]??false;}function user_can($user,$cap){$id=is_object($user)?$user->ID:$user;return $cap==='read'&&isset($GLOBALS['users'][$id]);}function is_multisite(){return false;}function pk_normalize_phone($phone){return $phone;}
	require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php';
	$settings=new \DSA\Settings();$service=new \DSA\Notifications\Notification_Preference_Service($settings,new \DSA\Trust\Trust_Service());
	foreach(range(1,5) as $id){$users[$id]=(object)['ID'=>$id,'user_email'=>"member$id@example.test"];$meta[$id]['dsa_notification_preferences']=['topics'=>['newsletter'],'channels'=>['email'],'topicChannels'=>[],'browserPermission'=>'denied'];$rows[]=['user_id'=>$id,'browser_permission'=>'denied'];}
	$meta[2]['dsa_notification_preferences']['topics']=[]; // Table discovery row is stale; user opted out.
	$meta[3]['dsa_notification_preferences']['channels']=[];$meta[3]['dsa_notification_preferences']['topicChannels']=['newsletter'=>['email']]; // Per-topic override still opts in.
	$users[4]->user_email=''; // No usable contact.
	$meta[5]['dsa_notification_preferences']['channels']=[];
	$result=$service->campaign_audience('email','newsletter');
	ok($result['ids']===[1,3],'Current user meta, topic overrides and contact govern selection');
	ok(str_contains($wpdb->sql,'LIMIT 201')&&str_contains($wpdb->sql,'topics LIKE'),'Single bounded discovery query');
	ok(!str_contains($wpdb->sql,'channels LIKE'),'Per-topic overrides are not lost to default-channel SQL filter');
	$meta[1]['dsa_notification_preferences']['channels']=['app'];$meta[1]['dsa_notification_preferences']['browserPermission']='granted';
	$meta[3]['dsa_notification_preferences']['topicChannels']=['newsletter'=>['app']];$meta[3]['dsa_notification_preferences']['browserPermission']='denied';
	ok($service->campaign_audience('app','newsletter')['ids']===[1],'Browser permission gates app campaigns');
	$settings->on=false;ok($service->campaign_audience('email','newsletter')['ids']===[],'Current channel opt-outs still block sends despite a retired global pause');$settings->on=true;
	$hash=str_repeat('b',64);
	$meta[1]['dsa_notification_preferences']=['topics'=>['new_post'],'channels'=>['email','app'],'topicChannels'=>[],'postCategories'=>[7],'browserPermission'=>'granted','updatedAt'=>time()];
	$meta[2]['dsa_notification_preferences']=['topics'=>['new_post'],'channels'=>['email'],'topicChannels'=>[],'postCategories'=>[9],'browserPermission'=>'denied','updatedAt'=>time()];
	$transients['dsa_notification_preferences_'.substr($hash,0,32)]=['topics'=>['new_post'],'channels'=>['email','app'],'topicChannels'=>[],'postCategories'=>[7],'browserPermission'=>'granted','updatedAt'=>time()];
	ok($service->post_alert_channels_for_row(['user_id'=>1,'visitor_hash'=>''],[7])===['app','email'],'Registered publisher alerts honor topic, category, channel and browser permission.');
	ok($service->post_alert_channels_for_row(['user_id'=>2,'visitor_hash'=>''],[7])===[],'A selected category that does not match the post suppresses delivery.');
	ok($service->post_alert_channels_for_row(['user_id'=>0,'visitor_hash'=>$hash],[7])===['app'],'Anonymous visitors can receive only their exact consented PWA channel.');
	$rows=[['user_id'=>1,'visitor_hash'=>''],['user_id'=>2,'visitor_hash'=>''],['user_id'=>0,'visitor_hash'=>$hash]];
	$summary=$service->post_alert_summary();
	ok($summary['counts']===['app'=>2,'email'=>2,'whatsapp'=>0,'sms'=>0]&&!isset($summary['rows']),'Client projection exposes aggregate enrolments without identities or contacts.');
	$service->post_alert_rows_after(0,3,25);
	ok(str_contains($wpdb->sql,'p.topics LIKE')&&substr_count($wpdb->sql,'LIMIT 25')===1,'Publication discovery is topic-filtered and capped to 25 rows per query.');
	$transients['dsa_notification_preferences_'.substr($hash,0,32)]['updatedAt']=time()-31*86400;
	ok($service->post_alert_channels_for_row(['user_id'=>0,'visitor_hash'=>$hash],[7])===[],'Expired anonymous preference state cannot authorize a send.');
	echo json_encode(['passed'=>true,'checks'=>$checks])."\n";
}
