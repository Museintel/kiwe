<?php
declare(strict_types=1);
namespace { $GLOBALS['worker_profile']=$argv[1]??'state'; }
namespace DSA\Apps { class Store_App_Service { public static function enabled():bool{return false;} } }
namespace DSA\Security { class Secret_Store {public static function decrypt($v){return$v;}public static function decrypt_with_status($v){return['status'=>'ok','value'=>$v];}public static function encrypt($v){return$v;}} }
namespace DSA\Notifications {
 class Notification_Preference_Service {
  public bool $on=true,$failRows=false;public array $consent=[],$rows=[],$channels=[];
  public function enabled():bool{return$this->on;}public function user_accepts($id,...$rest):bool{return$this->consent[$id]??true;}
  public function contact_for_user($id,$channel):string{return(string)$id;}
  public function post_alert_rows_after($cursor,$ceiling,$limit=25):array{if($this->failRows){$GLOBALS['wpdb']->last_error='Synthetic audience read failure';return[];}return array_slice(array_values(array_filter($this->rows,fn($r)=>$r['id']>$cursor&&$r['id']<=$ceiling)),0,$limit);}
  public function post_alert_channels_for_row($row,$categories=[]):array{return$this->user_accepts($row['user_id'])?($this->channels[$row['id']]??['sms']):[];}
  public function post_alert_inbox_user_for_row($row,$categories=[]):int{return(int)($row['inbox_user']??0);}
 }
 if($GLOBALS['worker_profile']!=='push'){class Push_Service {}}
}
namespace DSA\Communications {class Channel_Service {
 public function available_for_campaign($channel):bool{return true;}
 public function send($channel,$recipient,...$rest){return \worker_delivery($channel,$recipient);}
}}
namespace DSA\Access {
 class Team_Workspace_Service {public static function suspended($id){return false;}}
 class User_Group_Service {public function find($id){return['id'=>$id,'smart'=>false];}public static function memberships($id){return['g-fixture'];}}
 class Campaign_Workspace_Service {public function __construct(...$args){}public function render($draft,$target,$url,$expires){return['title'=>'Synthetic title','body'=>'Synthetic body','url'=>$url];}}
}
namespace DSA\PhoneKey {class PhoneKey_Bridge {public static function campaign_contact($id,$channel){return(string)$id;}}}
namespace {
 define('ABSPATH',__DIR__.'/');$options=[];$events=[];$worker_clock=100;$checks=0;
 class WP_Error {public function __construct(private string $code='',private string $message='',private array $data=[]){}public function get_error_code(){return$this->code;}public function get_error_message(){return$this->message;}}
 function get_option($k,$d=false){return$GLOBALS['options'][$k]??$d;}function add_option($k,$v,...$rest){if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}function update_option($k,$v,...$rest){$GLOBALS['options'][$k]=$v;return true;}function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
 function sanitize_key($v){return(string)$v;}function absint($v){return abs((int)$v);}function sanitize_text_field($v){return strip_tags((string)$v);}function sanitize_textarea_field($v){return strip_tags((string)$v);}function wp_strip_all_tags($v){return strip_tags((string)$v);}function __($v,$domain=''){return$v;}function wp_json_encode($v){return json_encode($v);}function wp_salt($k){return'synthetic-only';}
 function is_wp_error($v){return$v instanceof WP_Error;}function wp_schedule_single_event($at,$hook,$args=[],$error=false){if(!($GLOBALS['schedule_ok']??true))return false;$GLOBALS['events'][]=[$at,$hook,$args];return true;}function wp_next_scheduled($hook,$args=[]){foreach($GLOBALS['events']as$e)if($e[1]===$hook&&$e[2]===$args)return$e[0];return false;}
 function do_action($hook,...$args){$GLOBALS['actions'][]=[$hook,$args];if($hook==='kiwe_notification_event'&&isset($GLOBALS['inbox_hook'])){$f=$GLOBALS['inbox_hook'];unset($GLOBALS['inbox_hook']);$f();}}
 function get_post($id){return(object)['ID'=>$id,'post_type'=>'post','post_status'=>'publish','post_password'=>'','post_title'=>'Synthetic article'];}function get_permalink($p){return'https://example.test/article/';}function get_the_title($id){return'Synthetic article';}function wp_get_post_categories(...$args){return[7];}function get_bloginfo($key){return'Synthetic Press';}function update_post_meta(...$args){return true;}function get_userdata($id){return(object)['ID'=>$id];}function is_multisite(){return false;}function get_user_meta(...$args){return[];}function home_url($p){return'https://example.test'.$p;}
 function worker_delivery($channel,$recipient){$GLOBALS['delivered'][]=[$channel,(string)$recipient];if(isset($GLOBALS['delivery_hook'])){$f=$GLOBALS['delivery_hook'];unset($GLOBALS['delivery_hook']);$f();}return$GLOBALS['delivery_result']??true;}
 function check($ok,$label){++$GLOBALS['checks'];if(!$ok){if($GLOBALS['worker_profile']==='push')$label.=' '.json_encode(['delivered'=>$GLOBALS['delivered']??[],'last'=>$GLOBALS['options']['dsa_push_last_job']??null,'deviceResults'=>array_column($GLOBALS['wpdb']->deviceUpdates,'values')]);throw new RuntimeException($label);}}
 require __DIR__.'/fixtures/worker-options.php';
 $root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/';
 $mode=$GLOBALS['worker_profile'];
 $reset=static function(){global$options,$events,$wpdb;$options=[];$events=[];$GLOBALS['actions']=[];$GLOBALS['delivered']=[];$GLOBALS['worker_clock']=100;unset($GLOBALS['worker_elapsed'],$GLOBALS['delivery_hook'],$GLOBALS['delivery_result'],$GLOBALS['inbox_hook']);$GLOBALS['schedule_ok']=true;$wpdb->failRead=$wpdb->failWrite=$wpdb->conflict=false;$wpdb->beforeWrite=null;};
 if($mode==='state'){
  $key='dsa_notification_campaign_active';$reset();check(DSA\Notifications\Worker_Job::claim($key)===null&&DSA\Notifications\Worker_Job::claim('dsa_ai_access_keys')===null,'Missing and unrelated options cannot become worker jobs');
  $options[$key]=['id'=>'a','cursor'=>0];$a=DSA\Notifications\Worker_Job::claim($key);check($a&&DSA\Notifications\Worker_Job::claim($key)===null,'Only one current owner');
  $old=$a->data();$GLOBALS['worker_clock']=191;$b=DSA\Notifications\Worker_Job::claim($key);check($b!==null,'Expired lease can be taken over');
  check(!$a->save($old)&&!$a->remove(),'Expired owner cannot write or delete');$token=$b->data()['_kiwe_worker']['token'];$a->release();check($options[$key]['_kiwe_worker']['token']===$token,'Late release preserves the successor');$b->release();
  $wpdb->beforeWrite=static function()use($key){$GLOBALS['rival']=DSA\Notifications\Worker_Job::claim($key);};check(DSA\Notifications\Worker_Job::claim($key)===null&&$GLOBALS['rival']!==null,'Two claim attempts converge on one owner');$GLOBALS['rival']->release();
  $a=DSA\Notifications\Worker_Job::claim($key);$wpdb->beforeWrite=static function()use($key){$GLOBALS['worker_clock']+=91;$GLOBALS['rival']=DSA\Notifications\Worker_Job::claim($key);};check(!$a->save(['id'=>'a','cursor'=>2]),'Takeover between ownership check and SQL write rejects stale progress');$a->release();$GLOBALS['rival']->release();
  $GLOBALS['worker_elapsed']=100;$a=DSA\Notifications\Worker_Job::claim($key);$GLOBALS['worker_elapsed']=121;check(!$a->save($a->data()),'Runtime deadline stops work between external calls');unset($GLOBALS['worker_elapsed']);$a->release();
  $wpdb->failRead=true;check(DSA\Notifications\Worker_Job::claim($key)===null,'Storage read failure refuses ownership');$wpdb->failRead=false;$wpdb->failWrite=true;check(DSA\Notifications\Worker_Job::claim($key)===null,'Storage write failure refuses ownership');$wpdb->failWrite=false;
  $a=DSA\Notifications\Worker_Job::claim($key);check($a->save($a->data()),'Unchanged state confirms a current no-op');
  $summary=['id'=>'a','status'=>'complete'];check($a->remember('dsa_notification_campaign_history',$summary,'id')&&$a->remember('dsa_notification_campaign_history',$summary,'id')&&count($options['dsa_notification_campaign_history'])===1,'Terminal history retry is idempotent');
  $wpdb->conflict=true;check(!$a->remember('dsa_notification_campaign_history',['id'=>'b'],'id'),'Repeated history conflict cannot claim success');$wpdb->conflict=false;$a->release();
  $options[$key]=['id'=>'original'];$original=$options[$key];$a=DSA\Notifications\Worker_Job::claim($key);check(!DSA\Notifications\Worker_Job::discard($key,$original)&&isset($options[$key]),'Initial scheduling failure cannot erase a job another worker claimed');$a->release();
  check(DSA\Notifications\Worker_Job::discard($key,$original)&&!isset($options[$key]),'Unclaimed failed initial schedule can be retired exactly');
  $options[$key]=str_repeat('x',2097153);check(DSA\Notifications\Worker_Job::claim($key)===null,'Oversized or corrupt private state is preserved');
 }elseif(in_array($mode,['campaign','post'],true)){
  require$root.($mode==='campaign'?'Notification_Campaign_Service.php':'New_Post_Notification_Service.php');
  $prefs=new DSA\Notifications\Notification_Preference_Service();$channel=new DSA\Communications\Channel_Service();$push=new DSA\Notifications\Push_Service();
  $service=$mode==='campaign'?new DSA\Notifications\Notification_Campaign_Service($prefs,$channel,$push):new DSA\Notifications\New_Post_Notification_Service($prefs,$channel,$push);
  $key=$mode==='campaign'?'dsa_notification_campaign_active':'dsa_new_post_alert_101';$history=$mode==='campaign'?'dsa_notification_campaign_history':'dsa_new_post_notification_history';
  $make=static function()use($mode,$key,$prefs){$prefs->on=true;$prefs->consent=[];$prefs->channels=[];$prefs->rows=[];for($i=1;$i<=4;$i++)$prefs->rows[]=['id'=>$i,'user_id'=>$i];$GLOBALS['options'][$key]=$mode==='campaign'?['id'=>'job-a','channel'=>'sms','topic'=>'newsletter','ids'=>[1,2,3,4],'cursor'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'unknown'=>0,'subject'=>'Synthetic','message'=>'Synthetic','status'=>'queued','created'=>0]:['postId'=>101,'ceiling'=>4,'cursor'=>0,'categories'=>[7],'status'=>'queued','scanned'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'unknown'=>0,'created'=>0];};
  $run=static fn()=>$service->run_batch($mode==='campaign'?'job-a':101);
  $reset();$make();$GLOBALS['delivery_hook']=static function()use($run){$GLOBALS['worker_clock']+=100;$run();};$run();check(array_column($GLOBALS['delivered'],1)===['1','2','3','4']&&!isset($options[$key]),'A slow stale worker cannot duplicate delivery or recreate a completed job');check($options[$history][0]['sent']===3&&$options[$history][0]['unknown']===1,'Lost ownership leaves its committed attempt unknown');
  $reset();$make();$GLOBALS['delivery_hook']=static function()use($run){$run();};$run();check(count($GLOBALS['delivered'])===3,'Unexpired overlapping worker sends nothing');$run();check(count($GLOBALS['delivered'])===4&&!isset($options[$key]),'Fresh passes finish only remaining recipients');
  $reset();$make();$GLOBALS['delivery_hook']=static function()use($prefs){$prefs->consent[2]=false;};$run();$run();check(array_column($GLOBALS['delivered'],1)===['1','3','4']&&$options[$history][0]['skipped']===1,'Consent withdrawal remains authoritative');
  $reset();$make();$GLOBALS['delivery_result']=new WP_Error('http_request_failed','Synthetic timeout');$run();$run();check($options[$history][0]['unknown']===4&&$options[$history][0]['sent']===0,'Transport uncertainty is not a successful or replayable delivery');
  $reset();$make();$wpdb->failWrite=true;$run();check(!$GLOBALS['delivered']&&$options[$key]['cursor']===0,'Failed claim writes send nothing');$wpdb->failWrite=false;
  $reset();$make();$GLOBALS['schedule_ok']=false;$run();check(($options[$key]['status']??'')==='stalled','Failed continuation scheduling retains an explicit stalled job');
  $reset();$make();$options[$key]['status']='complete';$wpdb->failWrite=true;$run();check(isset($options[$key])&&!$GLOBALS['delivered'],'Failed terminal persistence cannot replay recipients');$wpdb->failWrite=false;$run();check(!isset($options[$key])&&count($options[$history])===1&&!$GLOBALS['delivered'],'A terminal job resumes history completion without delivery');
  if($mode==='post'){
   $reset();$make();$prefs->channels[1]=['sms','email'];$GLOBALS['delivery_hook']=static function()use($run){$GLOBALS['worker_clock']+=100;$run();};$run();check(count($GLOBALS['delivered'])===4&&$GLOBALS['delivered'][0][0]==='sms','Ownership loss inside one channel stops the remaining channels');
   $reset();$make();$prefs->rows[0]['inbox_user']=1;$GLOBALS['inbox_hook']=static function()use($run){$GLOBALS['worker_clock']+=100;$run();};$run();$inbox=array_values(array_filter($GLOBALS['actions'],fn($a)=>$a[0]==='kiwe_notification_event'));check(count($inbox)===1&&array_column($GLOBALS['delivered'],1)===['2','3','4'],'Inbox progress is committed before a takeover and external delivery stops');
   $reset();$make();$prefs->failRows=true;$run();check(isset($options[$key])&&$options[$key]['cursor']===0&&!isset($options[$history])&&!$GLOBALS['delivered'],'Audience SQL failure cannot pretend the article job is complete');$prefs->failRows=false;
  }else{
   $reset();$make();$targets=[];foreach([1,2,3,4]as$uid)$targets[]=['user_id'=>$uid,'channel'=>'sms','contactProof'=>hash_hmac('sha256','sms:'.$uid,wp_salt('auth'))];
   $options[$key]['plan']=['draft'=>['id'=>'c-fixture','topic'=>'newsletter','sourceId'=>0,'group'=>'g-fixture','channels'=>['sms']],'url'=>'https://example.test/','categories'=>[],'expires'=>10000];$options[$key]['ids']=$targets;
   $GLOBALS['delivery_hook']=static function()use($service){$GLOBALS['worker_clock']+=100;$proof=$service->status()['active']['expected'];$GLOBALS['cancelled']=$service->cancel('job-a',$proof);};$run();check(($GLOBALS['cancelled']['ok']??false)&&count($GLOBALS['delivered'])===1&&!isset($options[$key])&&$options[$history][0]['status']==='cancelled','Cancellation after takeover survives the old worker returning from I/O');
  }
  check(!str_contains(json_encode($service->status()),'_kiwe_worker'),'Public status omits ownership tokens');
 }elseif($mode==='push'){
  require$root.'Push_Service.php';
  function wp_http_validate_url($url){return true;}function wp_parse_url($url,$component=-1){return parse_url($url,$component);}function esc_url_raw($v){return$v;}function get_site_icon_url($size){return'https://example.test/icon.png';}function sanitize_email($v){return$v;}function current_time($kind){return'2026-10-26 12:00:00';}
  function wp_safe_remote_post($url,$args){preg_match('/device-([0-9]+)/',$url,$m);worker_delivery('push',$m[1]);return$GLOBALS['push_response']??['response'=>['code'=>201]];}function wp_remote_retrieve_response_code($r){return$r['response']['code']??0;}
  $service=new DSA\Notifications\Push_Service();$key='dsa_push_job_fixture';$b64=static fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
  $short=['ec'=>['x'=>hex2bin('827e80c1b1191a6f82fae65e83ad7f87efd5fc295f9cb04e971fa5587f079b59'),'y'=>hex2bin('e3db113cb7f2b895ee22c4731920763421dd218c6fb733b11f3827ada97336')]];
  $rawPoint=new ReflectionMethod($service,'raw_public_point');$encoded=$rawPoint->invoke($service,$short);
  check(strlen($encoded)===65&&openssl_pkey_get_public((new ReflectionMethod($service,'public_point_pem'))->invoke($service,$encoded))!==false,'A real P-256 public point with a short OpenSSL coordinate remains valid');
  check($rawPoint->invoke($service,['ec'=>['x'=>'','y'=>str_repeat('a',32)]])===''&&$rawPoint->invoke($service,['ec'=>['x'=>str_repeat('a',33),'y'=>str_repeat('a',32)]])==='','Empty or oversized coordinates are still rejected');
  $point=static fn($ec)=>"\x04".str_pad($ec['ec']['x'],32,"\x00",STR_PAD_LEFT).str_pad($ec['ec']['y'],32,"\x00",STR_PAD_LEFT);
  $vapid=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_pkey_export($vapid,$private);$d=openssl_pkey_get_details($vapid);$public=$b64($point($d));
  $make=static function()use($key,$private,$public,$b64,$point){$GLOBALS['options']['dsa_push_vapid_keys']=['public'=>$public,'private'=>$private,'key_id'=>'synthetic'];$GLOBALS['options'][$key]=['title'=>'Synthetic','message'=>'Synthetic','url'=>'https://example.test/','after_id'=>0,'sent'=>0,'failed'=>0,'created'=>0];$GLOBALS['options']['dsa_push_active_jobs']=['fixture'];$GLOBALS['push_rows']=[];for($i=1;$i<=4;$i++){$ec=openssl_pkey_get_details(openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']));$GLOBALS['push_rows'][]=['id'=>$i,'user_id'=>$i,'endpoint'=>'https://fcm.googleapis.com/fcm/send/device-'.$i,'p256dh'=>$b64($point($ec)),'auth_secret'=>$b64(random_bytes(16))];}};
  $run=static fn()=>$service->run_delivery_batch('fixture');
  $reset();$make();$GLOBALS['delivery_hook']=static function()use($run){$GLOBALS['worker_clock']+=100;$run();$run();};$run();check(array_column($GLOBALS['delivered'],1)===['1','2','3','4']&&!isset($options[$key]),'Slow broadcast worker cannot resend devices or recreate the job');check($options['dsa_push_last_job']['unknown']===1&&$options['dsa_push_last_job']['sent']===3,'Committed uncertain device remains unknown after takeover');
  $reset();$make();$GLOBALS['delivery_hook']=static function()use($run){$run();};$run();$run();check(count($GLOBALS['delivered'])===4&&$options['dsa_push_last_job']['sent']===4,'Unexpired broadcast overlap does not duplicate delivery: '.json_encode(['delivered'=>$GLOBALS['delivered'],'last'=>$options['dsa_push_last_job']??null,'deviceResults'=>array_column($wpdb->deviceUpdates,'values')]));
  $reset();$make();$wpdb->failWrite=true;$run();check(!$GLOBALS['delivered']&&$options[$key]['after_id']===0,'Failed broadcast claim cannot send');$wpdb->failWrite=false;
  $reset();$make();$GLOBALS['push_response']=new WP_Error('http_request_failed','Synthetic network uncertainty');$run();$run();check($options['dsa_push_last_job']['unknown']===4&&$options['dsa_push_last_job']['sent']===0,'Broadcast timeouts remain unknown without replay');unset($GLOBALS['push_response']);
 }else throw new RuntimeException('Unknown worker profile');
 echo json_encode(['passed'=>true,'profile'=>$mode,'checks'=>$checks,'externalDelivery'=>false]).PHP_EOL;
}
