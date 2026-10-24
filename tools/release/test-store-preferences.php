<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Team_Workspace_Service.php';
function is_multisite(){return false;}
function WC(){throw new RuntimeException('Preference boundary must not load a cart');}
function get_option($key,$default=false){return $key==='kiwe_store_app_enabled_v1'?$GLOBALS['store']:$default;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function absint($v){return abs((int)$v);}
function get_userdata($id){return (object)['ID'=>$id,'roles'=>['administrator']];}function user_can($user,$cap){return true;}
function get_user_meta($id,$key,$single){return $key==='dsa_notification_preferences'?$GLOBALS['prior']:'';}
function check($value,$label){if(!$value)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}
$store='0';$checks=[];
$prior=['topics'=>['new_offer','order_status','new_post'],'channels'=>['email'],'topicChannels'=>['new_offer'=>[],'order_status'=>['email']],'productCategories'=>[8],'productIds'=>[2]];
$active=['topics'=>['new_post'],'channels'=>['email'],'topicChannels'=>['new_post'=>['email']],'productCategories'=>[],'productIds'=>[]];
try {
 $reflection=new ReflectionClass(DSA\Notifications\Notification_Preference_Service::class);$service=$reflection->newInstanceWithoutConstructor();$retain=$reflection->getMethod('retain_store_choices');
 $kept=$retain->invoke($service,$active,$prior);check(in_array('new_offer',$kept['topics'],true)&&in_array('order_status',$kept['topics'],true),'dormant Store topic choices survive a Core preference save');
 check($kept['topicChannels']['new_offer']===[]&&$kept['topicChannels']['order_status']===['email'],'explicit opt-out and existing per-topic delivery choices remain exact');
 check($kept['productCategories']===[8]&&$kept['productIds']===[2],'dormant product selections remain exact');
 check($kept['channels']===$active['channels']&&$kept['topicChannels']['new_post']===['email'],'active Core delivery choices remain the supplied native choices');
 foreach(['new_offer','order_status','new_product','cart_reminder','stock_update','price_change','admin_new_order','admin_abandoned_cart']as$topic)check(!$service->user_accepts(4,'email',$topic),'Store-off blocks delivery for '.$topic);
 check($service->user_accepts(4,'email','new_post'),'article delivery remains available with Store off');
 check($service->campaign_visitors('new_offer')===['hashes'=>[],'limited'=>false],'anonymous commerce audience is denied before any database scan');
 check(!$service->campaign_visitor_accepts(str_repeat('a',64),'new_offer'),'existing anonymous commerce consent cannot deliver with Store off');
 $store='1';check($retain->invoke($service,$active,$prior)===$active,'Store-on uses the existing native preference writer without dormant overrides');
 check($service->user_accepts(4,'email','order_status')&&!$service->user_accepts(4,'email','new_offer'),'re-enabling restores existing positive consent and keeps the explicit opt-out');
 echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$checks,'scope'=>'actual dormant-consent/delivery policy; reflective isolated preference model and synthetic native I/O'])."\n";
}catch(Throwable $e){echo json_encode(['passed'=>false,'cleanup'=>true,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
