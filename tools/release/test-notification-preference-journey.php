<?php
declare(strict_types=1);
/** Actual preference owner with synthetic native identity/settings I/O; no delivery adapter. */
namespace DSA { final class Settings { public array $values=[]; public function get($key,$default=[]){return $this->values[$key]??$default;} } }
namespace DSA\Trust { final class Trust_Service { public function health_data(array $config):array{return [];} } }
namespace DSA\Access {
	final class Guest_Contribution_Service { public static function application(int $id):array{return $GLOBALS['applications'][$id]??[];} }
	final class Team_Workspace_Service { public static function suspended(int $id):bool{return in_array($id,$GLOBALS['suspended'],true);} }
}
namespace {
	define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php'; define('DAY_IN_SECONDS',86400); define('ARRAY_A','ARRAY_A');
	$noWoo=in_array('--no-woo',$argv,true); if(!$noWoo){function WC(){return true;}}
	$actor=0; $users=[]; $meta=[]; $applications=[]; $suspended=[]; $checks=0; $injected=[]; $extensionAllowed=[]; $multisite=false; $foreign=[];
	class WP_REST_Request { public function __construct(public array $params=[]){} public function get_param($key){return $this->params[$key]??null;} public function get_json_params(){return $this->params;} }
	class WP_REST_Response { public array $headers=[]; public function __construct(public array $data,public int $status=200){} public function header($key,$value){$this->headers[$key]=$value;} public function get_data(){return $this->data;} }
	$wpdb=new class { public string $prefix='synthetic_'; public function prepare($sql,...$args){return $sql;} public function get_var($sql){return false;} public function get_row($sql,$format=null){return null;} public function insert(...$args){return 1;} };
	function get_current_user_id(){return $GLOBALS['actor'];}
	function get_userdata($id){return $GLOBALS['users'][$id]??false;}
	function user_can($user,$cap){$id=is_object($user)?$user->ID:(int)$user;return in_array($cap,$GLOBALS['users'][$id]->caps??[],true);}
	function current_user_can($cap){return user_can(get_current_user_id(),$cap);}
	function is_user_logged_in(){return get_current_user_id()>0;}
	function is_multisite(){return $GLOBALS['multisite'];}
	function is_user_member_of_blog($id){return !in_array($id,$GLOBALS['foreign'],true);}
	function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
	function update_user_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;return true;}
	function get_option($key,$default=false){return $key==='dsa_notification_preferences_db_version'?'2':$default;}
	function get_transient($key){return false;}
	function wp_salt($scheme){return 'synthetic-local-preference-fixture';}
	function current_time($type){return '2026-10-14 00:00:00';}
	function taxonomy_exists($taxonomy){return false;}
	function sanitize_text_field($value){return trim(strip_tags((string)$value));}
	function __($value,$domain=''){return $value;}
	function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
	function absint($value){return abs((int)$value);}
	function wp_json_encode($value){return json_encode($value);}
	function apply_filters($name,$value,...$args){
		if($name==='dsa_notification_topic_catalog')return array_merge($value,$GLOBALS['injected']);
		if($name==='dsa_notification_topic_allowed_for_user')return in_array($args[0],$GLOBALS['extensionAllowed'][$args[1]]??[],true);
		return $value;
	}
	function check($ok,$label){$GLOBALS['checks']++;if(!$ok)throw new \RuntimeException('FAIL '.$label);}
	$adminCaps=['read','edit_others_posts','edit_products','edit_others_products','edit_shop_orders','edit_others_shop_orders','moderate_comments','manage_options','kiwe_manage_guest_applications','kiwe_manage_notification_policy'];
	foreach(['subscriber','customer','contributor','author','administrator','kiwe_super_admin','client','shop_manager']as$index=>$role){
		$id=$index+1;$caps=['read'];
		if(in_array($role,['administrator','kiwe_super_admin','client'],true))$caps=$adminCaps;
		elseif($role==='shop_manager')$caps=['read','edit_products','edit_others_products','edit_shop_orders','edit_others_shop_orders'];
		elseif($role==='author')$caps[]='edit_posts';
		$users[$id]=(object)['ID'=>$id,'roles'=>[$role==='client'?'administrator':$role],'caps'=>$caps];
	}
	require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php';
	require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Rest/Notification_Preferences_Controller.php';
	$settings=new \DSA\Settings();$service=new \DSA\Notifications\Notification_Preference_Service($settings,new \DSA\Trust\Trust_Service());
	$catalog=new \ReflectionMethod($service,'topics');$sanitize=new \ReflectionMethod($service,'sanitize_preferences');$channels=new \ReflectionMethod($service,'channels');
	foreach($users as$id=>$user){
		$actor=$id;$snapshot=$service->workspace_snapshot();$ids=array_column($snapshot['config']['topics'],'id');$prefs=$snapshot['preferences'];
		check(in_array('account_update',$prefs['inboxTopics'],true),'own account inbox defaults on '.$user->roles[0]);
		check(in_array('cart_reminder',$prefs['inboxTopics'],true)===!$noWoo,'saved cart inbox default follows Woo '.$user->roles[0]);
		check($prefs['topics']===[]&&$prefs['channels']===[]&&$prefs['browserPermission']==='default','defaults enroll no external gate '.$user->roles[0]);
		check($service->inbox_accepts($id,'account_update')&&$service->inbox_accepts($id,'cart_reminder')===!$noWoo,'dispatch agrees with defaults '.$user->roles[0]);
		check(!in_array('admin_new_order',$ids,true)||(!$noWoo&&in_array('edit_shop_orders',$user->caps,true)),'new order catalog is capability scoped '.$user->roles[0]);
		$cartAdmin=!$noWoo&&in_array('edit_shop_orders',$user->caps,true)&&in_array('edit_others_shop_orders',$user->caps,true);
		check(in_array('admin_abandoned_cart',$ids,true)===$cartAdmin,'abandoned-cart delivery catalog follows order-management capabilities '.$user->roles[0]);
		check($service->inbox_accepts($id,'admin_abandoned_cart')===$cartAdmin,'abandoned-cart inbox recipient follows native role '.$user->roles[0]);
		if(in_array($user->roles[0],['subscriber','customer','contributor','author'],true))check(!array_filter($ids,static fn($topic)=>str_starts_with($topic,'admin_')||$topic==='securetrack_incident'),'ordinary account never sees staff choices '.$user->roles[0]);
		$meta[$id]['dsa_notification_preferences']=['inboxTopics'=>[],'topics'=>[],'channels'=>[]];
		check($service->workspace_snapshot()['preferences']['inboxTopics']===$ids&&$service->inbox_accepts($id,'account_update')&&$service->inbox_accepts($id,'cart_reminder')===!$noWoo,'legacy inbox choices cannot disable the core inbox '.$user->roles[0]);
		$meta[$id]['dsa_notification_preferences']=['topics'=>[],'channels'=>[]];
		check(in_array('account_update',$service->workspace_snapshot()['preferences']['inboxTopics'],true),'legacy external choices do not erase native defaults '.$user->roles[0]);
	}
	check($service->personal_topic('cart_reminder')&&$service->inbox_user_ids_for_topic('cart_reminder')===[],'default cart reminders never become a staff broadcast');
	$injected=[['id'=>'admin_new_order','audience'=>'admin'],['id'=>'securetrack_incident','audience'=>'admin'],['id'=>'unowned_extension'],['id'=>'bad id'],null];
	$actor=1;check(!array_intersect(['admin_new_order','securetrack_incident','unowned_extension','bad id'],array_column($catalog->invoke($service),'id')),'filtered catalog cannot broaden subscriber entitlement');
	$selected=$sanitize->invoke($service,['inboxTopics'=>['account_update','admin_new_order'],'topics'=>['account_update','admin_new_order'],'channels'=>['app','email','sms'],'topicChannels'=>['admin_new_order'=>['app'],'account_update'=>['email']]]);
	check(!in_array('admin_new_order',$selected['inboxTopics'],true)&&in_array('account_update',$selected['inboxTopics'],true)&&$selected['topics']===['account_update']&&!isset($selected['topicChannels']['admin_new_order']),'forged staff selections are removed by the same catalog owner');
	check($selected['channels']===['app']&&$selected['topicChannels']['account_update']===[],'unconfigured email and SMS cannot become a selected delivery');
	$extensionAllowed[1]=['unowned_extension'];check(in_array('unowned_extension',array_column($catalog->invoke($service),'id'),true),'an extension requires explicit recipient entitlement');
	$actor=0;$ids=array_column($catalog->invoke($service),'id');
	check(!array_intersect(['account_update','admin_new_order','securetrack_incident','unowned_extension'], $ids),'anonymous catalog excludes private accounts and staff');
	check(in_array('cart_reminder',$sanitize->invoke($service,[])['inboxTopics'],true)===!$noWoo,'anonymous current-device cart inbox default follows Woo');
	$actor=1;$suspended=[1];check($catalog->invoke($service)===[]&&!$service->inbox_accepts(1,'account_update'),'suspended account cannot retain notification entitlement');$suspended=[];
	$multisite=true;$foreign=[1];check($catalog->invoke($service)===[],'foreign-site account cannot see notification topics');$multisite=false;$foreign=[];
	$actor=5;$meta[1]['dsa_notification_preferences']=['topics'=>['admin_new_order','account_update'],'channels'=>['email'],'topicChannels'=>[]];
	check(!$service->user_accepts(1,'email','admin_new_order')&&$service->user_accepts(1,'email','account_update'),'delivery entitlement belongs to the recipient rather than privileged sender');
	$settings->values=['email'=>['enabled'=>true],'abandoned_cart'=>['channels'=>['sms'=>['enabled'=>true,'webhook_url'=>'https://example.invalid/sms'],'whatsapp'=>['enabled'=>true,'webhook_url'=>'https://example.invalid/whatsapp']]]];
	$ready=array_column($channels->invoke($service),'available','id');check($ready===['app'=>true,'email'=>true,'whatsapp'=>true,'sms'=>true],'configured provider choices are available through their existing readiness owner');
	$defaults=$sanitize->invoke($service,[]);check($defaults['channels']===[]&&$defaults['topics']===[]&&$defaults['browserPermission']==='default','provider configuration alone does not enroll a person or browser');
	$injected=[];$actor=5;$meta[5]['dsa_notification_preferences']=['topics'=>[],'channels'=>[],'inboxTopics'=>[]];$beforeMeta=$meta;
	$publicOwner=$service->public_config();$privateOwner=$service->private_config();$actor=1;$publicSubscriber=$service->public_config();
	check($publicOwner===$publicSubscriber,'public configuration is identical for owner and subscriber');
	check(!array_intersect(['account_update','admin_new_order','securetrack_incident'],array_column($publicOwner['topics'],'id')),'cacheable public catalog contains no account or staff choices');
	check(in_array('securetrack_incident',array_column($privateOwner['topics'],'id'),true),'private owner catalog includes its entitled security alerts');
	check(!in_array('securetrack_incident',array_column($service->private_config()['topics'],'id'),true),'private catalog refresh follows the current subscriber');
	check($beforeMeta===$meta,'configuration lookup neither writes account state nor enrolls channels');
	$actor=5;$controller=new \DSA\Rest\Notification_Preferences_Controller($service);$response=$controller->read(new WP_REST_Request());
	check($response->get_data()['config']===$service->private_config()&&$response->get_data()['preferences']['inboxTopics']===array_column($service->private_config()['topics'],'id'),'private GET derives the complete role-eligible inbox without changing saved external choices');
	check($beforeMeta===$meta,'private GET with saved native choices performs no identity or consent write');
	check(str_contains($response->headers['Cache-Control']??'','private, no-store')&&($response->headers['Vary']??'')==='Cookie','role catalog response cannot enter a shared cookie-blind cache');
	$saved=$controller->save(new WP_REST_Request(['visitorId'=>'synthetic-user-5','inboxTopics'=>[],'topics'=>[],'channels'=>[]]));
	check(str_contains($saved->headers['Cache-Control']??'','private, no-store')&&($saved->headers['Vary']??'')==='Cookie','preference write response is private and non-cacheable too');
	check($saved->get_data()['preferences']['inboxTopics']===array_column($service->private_config()['topics'],'id')&&$saved->get_data()['preferences']['topics']===[]&&$saved->get_data()['preferences']['channels']===[],'legacy empty inbox input is ignored and cannot opt in a delivery gate');
	echo json_encode(['passed'=>true,'checks'=>$checks,'woo'=>!$noWoo,'sends'=>0])."\n";
}
