<?php
/** Native SQL/roles, connection-local empty cart tables; no real customer record or delivery. */
if ( PHP_SAPI !== 'cli' || !defined('KIWE_WORKSPACE_TEST_SITE') || KIWE_WORKSPACE_TEST_SITE !== true || !defined('KIWE_WORKSPACE_EXPECTED_VERSION') ) exit(2);
$out=['schema'=>'kiwe.native-saved-cart-inbox.v1','checks'=>[],'passed'=>false,'cleanup'=>false];
$nativeFinished=false;register_shutdown_function(static function()use(&$out,&$nativeFinished){if(!$nativeFinished&&empty($GLOBALS['kiwe_test_receipt_emitted'])){$out['passed']=false;$out['cleanup']=false;$out['unexpectedExit']=true;$out['error']=error_get_last()['message']??'Exited before final test receipt';echo json_encode($out)."\n";}});
$assert=static function($ok,$label)use(&$out){$out['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$originalPrefix=$wpdb->prefix;$originalSettings=get_option(DSA_OPTION_SETTINGS);$originalActivity=get_option(DSA\Access\Workspace_Activity_Service::OPTION,null);$filters=[];$borrowed=[];$tables=[];$user=0;$http=0;$mail=0;$arrivals=[];$scope='kq358_'.bin2hex(random_bytes(4)).'_';
$filter=static function($name,$cb,$args=1)use(&$filters){add_filter($name,$cb,PHP_INT_MAX,$args);$filters[]=[$name,$cb];};
try {
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'Exact signed native runtime');
 $filter('pre_http_request',static function()use(&$http){$http++;return new WP_Error('qa_closed','No provider');},3);$filter('pre_wp_mail',static function()use(&$mail){$mail++;return false;},2);
 // Borrow exact account-event callbacks while creating/deleting the one fixture.
 foreach(['user_register','set_user_role','dsa_saved_cart_abandoned']as$hook)foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$priority=>$entries)foreach($entries as$key=>$entry){$cb=$entry['function'];if(is_array($cb)&&$cb[0] instanceof DSA\Notifications\Admin_Event_Notification_Service){$borrowed[]=[$hook,$priority,$entry];remove_action($hook,$cb,$priority);}}
 // Retire this harness's interrupted identity before capturing the new baseline.
 $stale=get_users(['search'=>'kiwecartqa_*','search_columns'=>['user_login'],'number'=>2]);
 if(count($stale)>1)throw new RuntimeException('More than one interrupted fixture requires explicit review');
 if($stale){
  $old=$stale[0];if(!preg_match('/^kiwecartqa_[a-f0-9]{8}$/D',$old->user_login)||$old->user_email!==$old->user_login.'@example.test'||(int)$old->ID===DSA\Access\Site_Owner_Service::owner_id()||get_posts(['post_type'=>'any','post_status'=>'any','author'=>$old->ID,'posts_per_page'=>1,'fields'=>'ids']))throw new RuntimeException('Interrupted identity did not match the exact empty fixture');
  $custody=$GLOBALS['private']??'';if(!is_string($custody)||!str_contains($custody,'/plum-giraffe-539524.hostingersite.com/kiwe-private-rc358-'))throw new RuntimeException('Private lab repair custody required');
  $state=get_option(DSA\Access\Workspace_Activity_Service::OPTION,null);
  $repairFile=$custody.'/fixture-repair-before.json';if(file_exists($repairFile))throw new RuntimeException('Interrupted repair receipt already exists');
  file_put_contents($repairFile,wp_json_encode(['syntheticUserId'=>(int)$old->ID,'activity'=>$state]),LOCK_EX);chmod($repairFile,0600);
  require_once ABSPATH.'wp-admin/includes/user.php';if(!wp_delete_user($old->ID))throw new RuntimeException('Interrupted fixture removal failed');
  if(is_array($state)){
   $key=substr(hash('sha256','users|user:'.$old->ID),0,32);$keys=(array)($state['keys']['users']??[]);
   if(in_array($key,$keys,true)){$state['keys']['users']=array_values(array_diff($keys,[$key]));$state['totals']['users']=max(0,(int)($state['totals']['users']??0)-1);}
   // This new section did not exist on RC357. Rebuild first arrivals from
   // durable real cart rows since RC358 boot, excluding connection-local rows.
   $cutoff=wp_date('Y-m-d H:i:s',filemtime($custody.'/boot.json')-2,wp_timezone());
   $realTable=$originalPrefix.'dsa_abandoned_carts';
   $state['totals']['saved-carts']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$realTable} WHERE abandoned_at >= %s",$cutoff));
   $realIds=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$realTable} WHERE abandoned_at >= %s ORDER BY abandoned_at DESC,id DESC LIMIT 64",$cutoff));
   $state['keys']['saved-carts']=array_reverse(array_map(static fn($id)=>substr(hash('sha256','saved-carts|cart:'.$id),0,32),$realIds));
   update_option(DSA\Access\Workspace_Activity_Service::OPTION,$state,false);
   $seenKey=DSA\Access\Workspace_Activity_Service::user_meta_key(DSA\Access\Workspace_Activity_Service::SEEN);
   $seenRows=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%s LIMIT 100",$seenKey),ARRAY_A);
   foreach($seenRows as$r){$seen=maybe_unserialize($r['meta_value']);if(!is_array($seen))continue;$next=$seen;foreach(['users','saved-carts']as$section){$k=$section.':all';if(($next[$k]['epoch']??'')===($state['epoch']??''))$next[$k]['ceiling']=min((int)($next[$k]['ceiling']??0),(int)($state['totals'][$section]??0));}if($next!==$seen)update_user_meta((int)$r['user_id'],$seenKey,$next,$seen);}
  }
  $out['interruptedFixtureRetired']=1;$out['labDerivedActivityRebuilt']=true;$originalActivity=get_option(DSA\Access\Workspace_Activity_Service::OPTION,null);
 }
 $tag='kiwecartqa_'.bin2hex(random_bytes(4));$created=wp_insert_user(['user_login'=>$tag,'user_pass'=>wp_generate_password(40),'user_email'=>$tag.'@example.test','role'=>'administrator']);$assert(!is_wp_error($created),'One native role fixture');$user=(int)$created;wp_set_current_user($user);
 $filter('pre_option_'.DSA\Access\Site_Owner_Service::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$user,'rankMath'=>'inactive']);
 $filter('pre_get_users',static function($query)use($user){if(($query->query_vars['fields']??'')==='ID'&&(int)($query->query_vars['number']??0)===100)$query->set('include',[$user]);});
 $filter('pre_option_'.DSA_OPTION_SETTINGS,static function()use($originalSettings){$v=$originalSettings;$v['abandoned_cart']['enabled']=true;$v['abandoned_cart']['inactivity_minutes']=15;$v['abandoned_cart']['automatic_email_enabled']=false;$v['abandoned_cart']['automatic_whatsapp_enabled']=false;return$v;});
 $filter('pre_option_dsa_abandoned_cart_db_version',static fn()=> '4');
 foreach(['dsa_abandoned_carts','dsa_abandoned_cart_reminders','dsa_notification_preferences']as$suffix){$name=$originalPrefix.$scope.$suffix;$source=$originalPrefix.$suffix;if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name.$source))throw new RuntimeException('Bounded table identifiers');$assert($wpdb->query("CREATE TEMPORARY TABLE `{$name}` LIKE `{$source}`")!==false,'Connection-local native schema '.$suffix);$tables[]=$name;}
 $wpdb->prefix=$originalPrefix.$scope;
 $settings=new DSA\Settings();$analytics=new DSA\Commerce\Store_Analytics_Service($settings);$payload=new DSA\Commerce\Cart_Payload_Service($settings,new DSA\Commerce\Linked_Products_Service($settings),$analytics);$bridge=new DSA\PhoneKey\PhoneKey_Bridge($payload);$channels=new DSA\Communications\Channel_Service($settings,new DSA\Communications\Email_Service($settings),$bridge);$carts=new DSA\Commerce\Abandoned_Cart_Service($settings,$analytics,$channels);$prefs=new DSA\Notifications\Notification_Preference_Service($settings,new DSA\Trust\Trust_Service());$carts->set_preferences($prefs);
 $table=$wpdb->prefix.'dsa_abandoned_carts';$old=wp_date('Y-m-d H:i:s',time()-3600,wp_timezone());$now=current_time('mysql');
 $make=static function($uid,$status='active',$abandoned=null)use($wpdb,$table,$old,$now){$wpdb->insert($table,['visitor_hash'=>hash('sha256',wp_generate_uuid4()),'user_id'=>$uid,'cart_hash'=>hash('sha256','fixture'),'items'=>'[]','item_count'=>2,'cart_total'=>523.16,'currency'=>'INR','status'=>$status,'first_seen_at'=>$old,'last_activity_at'=>$old,'abandoned_at'=>$abandoned,'created_at'=>$now,'updated_at'=>$now]);return(int)$wpdb->insert_id;};
 $known=$make($user);$unknown=$make(0);$recovered=$make(0,'active',$old);$race=$make(0);
 $list=$carts->workspace_rows(['status'=>'active']);$byId=array_column($list['items'],null,'id');$assert($byId[$known]['customer']===$tag.'@example.test','Known account email is the private card title');$assert($byId[$unknown]['customer']==='Unknown','Anonymous cart has an Unknown title');$assert(str_contains($byId[$known]['total'],'₹')&&!str_contains($byId[$known]['total'],'&#'),'Native Woo money is plain readable currency');
 $detail=$carts->review_record($known);$assert(!is_wp_error($detail)&&$detail['customer']===$byId[$known]['customer'],'Card and private review share identity');
 $activity=new DSA\Access\Workspace_Activity_Service();$counterBefore=DSA\Access\Workspace_Activity_Service::snapshots()['saved-carts']['ceiling']??null;$assert(is_int($counterBefore),'Daily cart activity is admitted for order managers');
 $listener=static function($id)use(&$arrivals,$activity){$arrivals[]=$id;$activity->cart_abandoned($id);};add_action('dsa_saved_cart_abandoned',$listener,60,1);
 $raceHit=false;$raceGuard=static function($sql)use(&$raceHit,$race,$table,$wpdb,$now){if(!$raceHit&&str_starts_with($sql,'UPDATE '.$table)&&str_contains($sql,'WHERE id='.$race.' ')){$raceHit=true;$wpdb->update($table,['last_activity_at'=>$now],['id'=>$race]);}return$sql;};$filter('query',$raceGuard);
 $changed=$carts->refresh_abandoned_statuses();$assert($changed===3&&$raceHit,'Native conditional claims reject a concurrent cart edit');$assert($arrivals===[$known,$unknown],'Only first abandonments publish arrivals');$assert($carts->refresh_abandoned_statuses()===0&&count($arrivals)===2,'Worker replay publishes no duplicate arrival');$assert(($wpdb->get_row($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d",$race),ARRAY_A)['status']??'')==='active','Concurrent shopper cart remains active');
 $snapshot=DSA\Access\Workspace_Activity_Service::snapshots()['saved-carts'];$assert($snapshot['ceiling']===$counterBefore+2,'Saved-cart card badge uses the shared arrival counter');$activity->cart_abandoned($known);$assert(DSA\Access\Workspace_Activity_Service::snapshots()['saved-carts']['ceiling']===$snapshot['ceiling'],'Repeated event keeps the same counter identity');
 $body=$snapshot;$request=new WP_REST_Request('POST','/dsa/v1/workspace/activity/seen');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$request->set_header('X-Kiwe-Mutation','1');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($body));$assert($activity->visit($request)->get_status()===200&&DSA\Access\Workspace_Activity_Service::snapshots()['saved-carts']['count']===0,'Opening rendered saved carts clears its own badge');
 update_user_meta($user,'dsa_notification_preferences',['inboxTopics'=>[],'topics'=>[],'channels'=>[]]);$assert($prefs->inbox_accepts($user,'admin_abandoned_cart'),'Legacy inbox opt-out cannot disable role-eligible cart updates');$assert($prefs->inbox_user_ids_for_topic('admin_abandoned_cart')===[$user],'Notification recipient scope is only the bounded native fixture');
 $push=new DSA\Notifications\Push_Service();$notices=new DSA\Notifications\Admin_Event_Notification_Service($prefs,$push);$notices->dispatch('abandoned_cart',$known);$notices->dispatch('abandoned_cart',$known);$inbox=get_user_meta($user,'dsa_admin_notification_inbox',true);$assert(is_array($inbox)&&count($inbox)===1&&$inbox[0]['type']==='admin_abandoned_cart','One deduplicated native admin notification');$assert(str_contains($inbox[0]['actionUrl'],'area=saved-carts')&&str_contains($inbox[0]['actionUrl'],'cart='.$known),'Notification links to the selected Daily cart');$assert(!str_contains(wp_json_encode($inbox),$tag.'@example.test'),'Admin notification does not carry shopper contact');
 $tasks=(new DSA\Access\Workspace_Admin_Service())->task_catalog();$cartTasks=array_values(array_filter($tasks,static fn($t)=>$t['label']==='Saved carts'));$assert(count($cartTasks)===1&&$cartTasks[0]['group']==='daily','One Saved carts card belongs to Daily');
 foreach($filters as[$hook,$cb])if($hook==='pre_option_'.DSA\Access\Site_Owner_Service::OPTION)remove_filter($hook,$cb,PHP_INT_MAX);
 $nativeUser=new WP_User($user);$nativeUser->set_role('subscriber');wp_set_current_user(0);wp_set_current_user($user);$assert(!$prefs->inbox_accepts($user,'admin_abandoned_cart'),'Ordinary user cannot receive private cart operations');$assert(!isset(DSA\Access\Workspace_Activity_Service::snapshots()['saved-carts']),'Ordinary user has no cart badge scope');$assert($carts->workspace_rows()['items']===[]&&is_wp_error($carts->review_record($known)),'Ordinary user cannot read cart identity or details');
 $nativeUser->set_role('administrator');wp_set_current_user(0);wp_set_current_user($user);$beforeEvents=count($arrivals);$reads=$carts->workspace_rows();$carts->review_record($known);$assert(count($arrivals)===$beforeEvents,'List and detail GETs do not trigger abandonment');
 for($i=0;$i<27;$i++)$make(0);$beforeBatch=count($arrivals);$assert($carts->refresh_abandoned_statuses()===25&&count($arrivals)===$beforeBatch+25,'Native background batch claims at most twenty-five carts');$assert($carts->refresh_abandoned_statuses()===2,'Remaining carts continue in the next bounded worker pass');
 $guardCache=new ReflectionProperty(DSA\Commerce\Store_Recovery_Gate_Service::class,'paused');$guardOriginal=$guardCache->getValue();$guardCache->setValue(null,true);$beforePause=get_user_meta($user,'dsa_admin_notification_inbox',true);$notices->dispatch('abandoned_cart',$unknown);$assert($carts->refresh_abandoned_statuses()===0&&get_user_meta($user,'dsa_admin_notification_inbox',true)===$beforePause,'Restored Store pause blocks cart transitions and queued notices');$guardCache->setValue(null,$guardOriginal);
 $assert($http===0&&$mail===0,'No HTTP mail OTP or payment work');
 $out['passed']=true;
}catch(Throwable$e){$out['error']=$e->getMessage();}
finally{
 if(isset($guardCache))$guardCache->setValue(null,$guardOriginal);
 if(isset($listener))remove_action('dsa_saved_cart_abandoned',$listener,60);
 $wpdb->prefix=$originalPrefix;
 foreach(array_reverse($tables)as$name)$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `{$name}`");
 // Ownership overrides must be gone before native account deletion.
 foreach($filters as[$hook,$cb])if($hook==='pre_option_'.DSA\Access\Site_Owner_Service::OPTION)remove_filter($hook,$cb,PHP_INT_MAX);
 wp_set_current_user($actor);
 if($user){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);}
 wp_set_current_user($actor);
 foreach(array_reverse($filters)as[$hook,$cb])remove_filter($hook,$cb,PHP_INT_MAX);
 foreach($borrowed as[$hook,$priority,$entry])add_action($hook,$entry['function'],$priority,$entry['accepted_args']);
 if($originalActivity===null)delete_option(DSA\Access\Workspace_Activity_Service::OPTION);else update_option(DSA\Access\Workspace_Activity_Service::OPTION,$originalActivity,false);
 $out['cleanup']=$wpdb->prefix===$originalPrefix&&get_option(DSA_OPTION_SETTINGS)===$originalSettings&&get_option(DSA\Access\Workspace_Activity_Service::OPTION,null)===$originalActivity&&(!$user||!get_userdata($user));$out['httpAttempts']=$http;$out['mailAttempts']=$mail;
}
 $nativeFinished=true;echo wp_json_encode($out)."\n";if(!$out['passed']||!$out['cleanup'])exit(1);
