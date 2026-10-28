<?php
/** Actual WordPress/SQL/notification workers, confined to established temporary native tables. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||true!==KIWE_SEO_NATIVE_TEST||empty($GLOBALS['kiwe_seo_test_tables']))throw new RuntimeException('Owned native worker fixture required');
function kiwe_pt4_native_run():array{
 global$wpdb;
 $out=['passed'=>false,'cleanup'=>false,'checks'=>[]];$filters=[];$savedSuppress=$wpdb->suppress_errors;
 $check=static function($ok,string$why)use(&$out){$out['checks'][$why]=(bool)$ok;if(!$ok)throw new RuntimeException($why);};
 $key='dsa_notification_campaign_active';
 $expire=static function(string$option):void{$v=get_option($option);if(!is_array($v)||!isset($v['_kiwe_worker']))throw new RuntimeException('Current synthetic claim required');$v['_kiwe_worker']['until']=time()-1;update_option($option,$v,false);};
 $barrier=static function(callable$run,string$needle)use(&$filters,$check):array{
  $pause=true;$filter=static function($sql)use(&$pause,$needle){if($pause&&Fiber::getCurrent()&&str_starts_with($sql,'UPDATE ')&&str_contains($sql,$needle)){$pause=false;Fiber::suspend('pt4_native_compare');}return$sql;};
  add_filter('query',$filter,50);$filters[]=['query',$filter,50];$fiber=new Fiber($run);$check($fiber->start()==='pt4_native_compare','Native SQL conditional barrier reached '.count($filters));return[$fiber,$filter];
 };
 try{
  $check(DSA_VERSION==='8.0.0-rc.445'&&home_url()==='https://plum-giraffe-539524.hostingersite.com'&&class_exists(DSA\Notifications\Worker_Job::class),'Exact candidate and native worker owner loaded on lab');
  $settingsBefore=get_option(DSA_OPTION_SETTINGS);$contextBefore=get_option('kiwe_seam_design_context_v1');
  foreach(['dsa_notification_campaign_active','dsa_notification_campaign_history','dsa_new_post_notification_history','dsa_push_last_job','dsa_push_job_pt4fixture']as$option)delete_option($option);
  update_option($key,['id'=>'native-a','cursor'=>0],false);
  [$fiber,$filter]=$barrier(static fn()=>DSA\Notifications\Worker_Job::claim($key),$key);
  $b=DSA\Notifications\Worker_Job::claim($key);$check($b!==null,'Rival native SQL claim obtains current ownership');$fiber->resume();remove_filter('query',$filter,50);
  $check($fiber->getReturn()===null,'Paused native claim cannot replace a successful rival');$b->release();
  $a=DSA\Notifications\Worker_Job::claim($key);$old=$a->data();$expire($key);$b=DSA\Notifications\Worker_Job::claim($key);
  $check($b!==null&&!$a->save($old)&&!$a->remove(),'Native expired owner cannot update or delete a successor');$token=$b->data()['_kiwe_worker']['token'];$a->release();
  $check(get_option($key)['_kiwe_worker']['token']===$token,'Late native release preserves the successor claim');$b->release();
  $a=DSA\Notifications\Worker_Job::claim($key);
  [$fiber,$filter]=$barrier(static fn()=>$a->save(['id'=>'native-a','cursor'=>9]),$key);$expire($key);$b=DSA\Notifications\Worker_Job::claim($key);$fiber->resume();remove_filter('query',$filter,50);
  $check($fiber->getReturn()===false&&get_option($key)['cursor']===0,'Actual SQL rejects takeover between ownership read and progress write');$a->release();$b->release();
  $failed=static fn($sql)=>str_starts_with($sql,'UPDATE '.$GLOBALS['wpdb']->options.' ')&&str_contains($sql,'dsa_notification_campaign_active')?'UPDATE '.$GLOBALS['wpdb']->options." SET kiwe_pt4_nonexistent=1 WHERE option_name='synthetic-pt4'":$sql;
  add_filter('query',$failed,100);$filters[]=['query',$failed,100];$check(DSA\Notifications\Worker_Job::claim($key)===null,'Actual failed native SQL claim fails closed');remove_filter('query',$failed,100);
  $a=DSA\Notifications\Worker_Job::claim($key);$check($a!==null,'A later healthy claim succeeds after storage failure');
  $summary=['id'=>'native-a','status'=>'complete'];$check($a->remember('dsa_notification_campaign_history',$summary,'id')&&$a->remember('dsa_notification_campaign_history',$summary,'id')&&count(get_option('dsa_notification_campaign_history'))===1,'Native terminal history retries remain idempotent');$a->remove();

  // Enable only synthetic email in the disposable options table and intercept wp_mail before transport.
  $syntheticSettings=$settingsBefore;$syntheticSettings['email']['enabled']=true;$syntheticSettings['email']['transport']='wordpress';update_option(DSA_OPTION_SETTINGS,$syntheticSettings,false);
  $settings=new DSA\Settings();$prefs=new DSA\Notifications\Notification_Preference_Service($settings,new DSA\Trust\Trust_Service());$push=new DSA\Notifications\Push_Service();
  $channels=new DSA\Communications\Channel_Service($settings,new DSA\Communications\Email_Service($settings),new DSA\PhoneKey\PhoneKey_Bridge(static fn()=>null));
  $campaign=new DSA\Notifications\Notification_Campaign_Service($prefs,$channels,$push);
  $users=[];$contacts=[];
  for($i=1;$i<=4;++$i){$email='pt4-'.$i.'@example.invalid';$check((bool)$wpdb->insert($wpdb->users,['user_login'=>'pt4-'.$i,'user_pass'=>wp_hash_password('synthetic-only'),'user_email'=>$email,'display_name'=>'Synthetic worker recipient '.$i,'user_registered'=>current_time('mysql')]),'Synthetic native recipient created '.$i);$uid=(int)$wpdb->insert_id;$users[]=$uid;$contacts[]=$email;update_user_meta($uid,$wpdb->prefix.'capabilities',['customer'=>true]);update_user_meta($uid,'dsa_notification_preferences',['topics'=>['newsletter','new_offer','new_post'],'channels'=>['email']]);clean_user_cache($uid);}
  $mail=[];$hook=null;
  $mailFilter=static function($pre,$atts)use(&$mail,&$hook,$contacts){$to=(array)$atts['to'];if(count($to)!==1||!in_array($to[0],$contacts,true))throw new RuntimeException('Non-synthetic mail refused');$mail[]=$to[0];if($hook){$f=$hook;$hook=null;$f();}return true;};
  add_filter('pre_wp_mail',$mailFilter,PHP_INT_MAX,2);$filters[]=['pre_wp_mail',$mailFilter,PHP_INT_MAX];
  foreach([true,false]as$store){
   update_option('kiwe_store_app_enabled_v1',$store?'1':'0',false);$mail=[];delete_option('dsa_notification_campaign_history');
   update_option($key,['id'=>'native-mail','channel'=>'email','topic'=>$campaign->topic(),'subject'=>'Synthetic worker title','message'=>'Synthetic worker body','ids'=>$users,'cursor'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'unknown'=>0,'status'=>'queued','created'=>time()-100],false);
   $hook=static function()use($expire,$key,$campaign){$expire($key);$campaign->run_batch('native-mail');};$campaign->run_batch('native-mail');$h=get_option('dsa_notification_campaign_history');
   $out['campaignObservations'][(int)$store]=['recipientIndices'=>array_map(static fn($to)=>array_search($to,$contacts,true)+1,$mail),'channelReady'=>$channels->available_for_campaign('email'),'canRead'=>array_map(static fn($id)=>user_can($id,'read'),$users),'accepts'=>array_map(static fn($id)=>$prefs->user_accepts($id,'email',$campaign->topic()),$users),'active'=>$campaign->status()['active'],'history'=>$h[0]??null];
   $check($mail===$contacts&&!get_option($key,false),'Actual native campaign takeover has no duplicate or recreated job Store '.(int)$store);
   $check($h[0]['sent']===3&&$h[0]['unknown']===1,'Actual native campaign retains committed uncertainty Store '.(int)$store);
  }
  update_option('kiwe_store_app_enabled_v1','1',false);
  // An already terminal job finishes after a failed history write without resending.
  update_option($key,['id'=>'native-terminal','channel'=>'email','topic'=>$campaign->topic(),'subject'=>'Synthetic terminal','ids'=>[],'cursor'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'unknown'=>1,'status'=>'complete','created'=>time()-100],false);
  $badHistory=static fn($sql)=>(str_starts_with($sql,'UPDATE ')||str_starts_with($sql,'INSERT IGNORE'))&&str_contains($sql,'dsa_notification_campaign_history')?'UPDATE '.$GLOBALS['wpdb']->options." SET kiwe_pt4_nonexistent=1 WHERE option_name='synthetic-pt4'":$sql;
  add_filter('query',$badHistory,100);$filters[]=['query',$badHistory,100];$count=count($mail);$campaign->run_batch('native-terminal');$check(get_option($key)['status']==='complete'&&count($mail)===$count,'Native failed terminal history leaves the job recoverable');remove_filter('query',$badHistory,100);$campaign->run_batch('native-terminal');
  $check(!get_option($key,false)&&count($mail)===$count,'Native terminal retry finishes history without I/O');

  $postTable=DSA\Notifications\Notification_Preference_Service::audience_table();
  update_option('dsa_notification_preferences_db_version','2',false);
  foreach($users as$i=>$uid)$check((bool)$wpdb->insert($postTable,['id'=>$i+1,'visitor_hash'=>hash('sha256','pt4-'.$uid),'user_id'=>$uid,'topics'=>wp_json_encode(['new_post']),'channels'=>wp_json_encode(['email']),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]),'Synthetic native article recipient indexed '.($i+1));
  $check((bool)$wpdb->insert($wpdb->posts,['post_type'=>'post','post_status'=>'publish','post_title'=>'Synthetic worker article','post_content'=>'Synthetic only','post_password'=>'','post_date'=>current_time('mysql'),'post_date_gmt'=>current_time('mysql',true),'post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true)]),'Synthetic native article created without publish hooks');
  $postId=(int)$wpdb->insert_id;$postKey='dsa_new_post_alert_'.$postId;$postService=new DSA\Notifications\New_Post_Notification_Service($prefs,$channels,$push);
  $mail=[];update_option($postKey,['postId'=>$postId,'ceiling'=>4,'cursor'=>0,'categories'=>[],'status'=>'queued','scanned'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'unknown'=>0,'created'=>time()-100],false);
  $hook=static function()use($expire,$postKey,$postService,$postId){$expire($postKey);$postService->run_batch($postId);};$postService->run_batch($postId);$h=get_option('dsa_new_post_notification_history');
  $check($mail===$contacts&&!get_option($postKey,false),'Actual native article worker takeover avoids duplicate recipients and job resurrection');
  $check($h[0]['sent']===3&&$h[0]['unknown']===1,'Actual native article worker preserves unknown first attempt');

  // Exercise actual push encryption and the native HTTP boundary; return locally before any request.
  $ec=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_pkey_export($ec,$private);$details=openssl_pkey_get_details($ec);
  $b64=static fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
  $point=static fn($ec)=>"\x04".str_pad($ec['ec']['x'],32,"\x00",STR_PAD_LEFT).str_pad($ec['ec']['y'],32,"\x00",STR_PAD_LEFT);
  update_option('dsa_push_vapid_keys',['public'=>$b64($point($details)),'private'=>DSA\Security\Secret_Store::encrypt($private),'key_id'=>'pt4-synthetic'],false);update_option('dsa_push_db_version','4',false);
  $pushTable=$wpdb->prefix.'dsa_push_subscriptions';
  for($i=1;$i<=4;++$i){$r=openssl_pkey_get_details(openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']));$endpoint='https://fcm.googleapis.com/fcm/send/pt4-device-'.$i;$check((bool)$wpdb->insert($pushTable,['id'=>$i,'endpoint_hash'=>hash('sha256',$endpoint),'endpoint'=>DSA\Security\Secret_Store::encrypt($endpoint),'p256dh'=>DSA\Security\Secret_Store::encrypt($b64($point($r))),'auth_secret'=>DSA\Security\Secret_Store::encrypt($b64(random_bytes(16))),'user_id'=>$users[$i-1],'key_id'=>'pt4-synthetic','status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]),'Synthetic native encrypted push device created '.$i);}
  $pushKey='dsa_push_job_pt4fixture';update_option($pushKey,['title'=>'Synthetic push','message'=>'Synthetic push body','url'=>home_url('/'),'after_id'=>0,'sent'=>0,'failed'=>0,'created'=>time()],false);update_option('dsa_push_active_jobs',['pt4fixture'],false);
  $devices=[];$pause=true;
  $transport=static function($pre,$args,$url)use(&$devices,&$pause,$expire,$pushKey,$push){if(!preg_match('#^https://fcm.googleapis.com/fcm/send/pt4-device-([1-4])$#D',$url))return new WP_Error('pt4_no_delivery','No external fixture delivery');$devices[]=(int)substr($url,-1);if($pause){$pause=false;$expire($pushKey);$push->run_delivery_batch('pt4fixture');$push->run_delivery_batch('pt4fixture');}return['headers'=>[],'body'=>'','response'=>['code'=>201,'message'=>'Synthetic local acknowledgement'],'cookies'=>[]];};
  add_filter('pre_http_request',$transport,PHP_INT_MAX,3);$filters[]=['pre_http_request',$transport,PHP_INT_MAX];$push->run_delivery_batch('pt4fixture');
  $last=get_option('dsa_push_last_job');$check($devices===[1,2,3,4]&&!get_option($pushKey,false),'Actual native encrypted push takeover avoids duplicate devices or recreated job');
  $check($last['sent']===3&&$last['unknown']===1,'Actual native push preserves its committed uncertain device');
  update_option(DSA_OPTION_SETTINGS,$settingsBefore,false);update_option('kiwe_seam_design_context_v1',$contextBefore,false);
  $check(get_option(DSA_OPTION_SETTINGS)===$settingsBefore&&get_option('kiwe_seam_design_context_v1')===$contextBefore,'Core settings and complete Design Context remain exact in fixture');
  $out['runtime']=DSA_VERSION;$out['actualExternalDelivery']=false;$out['passed']=true;
 }catch(Throwable$error){$out['error']=$error->getMessage();$out['frames']=array_map(static fn($f)=>['file'=>basename($f['file']??''),'line'=>$f['line']??0,'function'=>$f['function']??''],array_slice($error->getTrace(),0,5));}
 finally{
  foreach($filters as[$tag,$callback,$priority])remove_filter($tag,$callback,$priority);$wpdb->suppress_errors($savedSuppress);
  try{wp_set_current_user(0);do_action('shutdown');remove_all_actions('shutdown');}catch(Throwable$e){$out['passed']=false;$out['shutdownError']=$e->getMessage();}
  kiwe_seo_test_cleanup($out);
 }
 $out['passed']=$out['passed']&&$out['cleanup']&&!in_array(false,$out['checks'],true);return$out;
}
