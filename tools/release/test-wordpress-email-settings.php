<?php
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
class WP_Error {public function __construct(public $code,public $message,public $data=[]){ }public function get_error_data(){return $this->data;}}
$actor=1;$caps=[1=>['kiwe_manage_notification_policy','moderate_comments'],2=>['kiwe_use_notifications'],3=>['kiwe_manage_notification_policy']];$opts=['comments_notify'=>'1','moderation_notify'=>'0'];$writes=0;$locked=false;$refuse=false;
function current_user_can($cap){return in_array($cap,$GLOBALS['caps'][$GLOBALS['actor']]??[],true);}function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['opts'])?$GLOBALS['opts'][$key]:$default;}function update_option($key,$value){$GLOBALS['writes']++;if(!empty($GLOBALS['refuse']))return false;$GLOBALS['opts'][$key]=$value;return true;}function wp_salt($scheme){return 'fixture-salt';}function is_wp_error($value){return $value instanceof WP_Error;}
$wpdb=new class {public $prefix='wp_';public function prepare($sql,...$args){return $sql;}public function get_var($sql){return str_contains($sql,'GET_LOCK')&&!empty($GLOBALS['locked'])?'0':'1';}};
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/WordPress_Email_Settings.php';
$service=new DSA\Access\WordPress_Email_Settings();$count=0;function check($ok,$name){if(!$ok)throw new RuntimeException('FAIL '.$name);$GLOBALS['count']++;echo "PASS $name\n";}
$state=$service->read();check($state['values']===['comments_notify'=>true,'moderation_notify'=>false]&&preg_match('/^[a-f0-9]{64}$/',$state['expected']),'Native comment email state is projected exactly');
$body=['key'=>'moderation_notify','value'=>true,'expected'=>$state['expected'],'acceptEffect'=>true];$saved=$service->save($body);check($saved['values']['moderation_notify']&&$opts['moderation_notify']==='1'&&$opts['comments_notify']==='1','One native comment email switch saves without changing the other');
check(is_wp_error($service->save($body)),'Stale comment email replay denied');$body['expected']=$saved['expected'];$before=$writes;check(!is_wp_error($service->save($body))&&$writes===$before,'Unchanged email switch skips write');
foreach([['key'=>'admin_email'],['value'=>'1'],['acceptEffect'=>false],['topics'=>[]],['expected'=>'old']]as$bad)check(is_wp_error($service->save(array_replace($body,$bad))),'Malformed or injected email setting denied');
$locked=true;check(is_wp_error($service->save(array_replace($body,['value'=>false]))),'Site email lock does not wait');$locked=false;$refuse=true;check(is_wp_error($service->save(array_replace($body,['value'=>false]))),'Refused native option write fails readback');$refuse=false;
$opts['comments_notify']='custom';check(is_wp_error($service->read()),'Custom filtered email value is protected');$opts['comments_notify']='1';
$actor=2;check(is_wp_error($service->read())&&is_wp_error($service->save($body)),'Personal notification user cannot change site email policy');$actor=3;check(is_wp_error($service->read()),'Policy capability alone cannot bypass comment moderation authority');
echo "$count WordPress email setting checks passed.\n";
