<?php
/** Native inbox authority and lifecycle. Synthetic fixtures are lab-only. */
if ( PHP_SAPI !== 'cli' || !defined('ABSPATH') || !defined('KIWE_WORKSPACE_TEST_SITE') || true !== KIWE_WORKSPACE_TEST_SITE || !defined('KIWE_WORKSPACE_EXPECTED_VERSION') ) exit(2);
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Notifications\Notification_Preference_Service as Preferences;
use DSA\Notifications\Admin_Event_Notification_Service as Inbox;
$proof=['schema'=>'kiwe.native-core-notifications.v1','version'=>DSA_VERSION,'checks'=>[],'cleanup'=>false];
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$users=[];$orders=[];$posts=[];$filters=[];$original=[];$inboxes=[];$mail=0;$http=0;$level=ob_get_level();
$table=$GLOBALS['wpdb']->prefix.'dsa_notification_preferences';
foreach([Owner::OPTION,DSA_OPTION_SETTINGS,'kiwe_seam_design_context_v1','pk_settings_v3','stp_settings']as$key)$original[$key]=get_option($key);
$activityOriginal=get_option(DSA\Access\Workspace_Activity_Service::OPTION,false);$activityMeta=[];
$bricksRaw=$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name=%s",'bricks_global_settings'));
$recent=get_transient('kiwe_notification_recent_orders');
foreach(get_users(['role__in'=>['administrator','kiwe_super_admin','shop_manager','editor'],'number'=>100,'fields'=>'ID'])as$id)$inboxes[$id]=get_user_meta($id,'dsa_admin_notification_inbox',true);
foreach(array_unique(array_merge([$actor],array_keys($inboxes)))as$id)foreach([DSA\Access\Workspace_Activity_Service::OWN,DSA\Access\Workspace_Activity_Service::SEEN]as$name){$key=DSA\Access\Workspace_Activity_Service::user_meta_key($name);$activityMeta[$id][$key]=get_user_meta($id,$key,true);}
$filter=static function($hook,$fn,$args=1)use(&$filters){add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
$filter('pre_wp_mail',static function()use(&$mail){$mail++;return false;},2);
$filter('pre_http_request',static function()use(&$http){$http++;return new WP_Error('kiwe_qa_block','No outbound lab delivery');},3);
$filter('pre_schedule_event','__return_false');
try{
 $package=DSA\Runtime\Package_Manifest::verify(true);
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($package['valid'])&&!empty($package['complete']),'complete matching signed runtime');
 require_once ABSPATH.'wp-admin/includes/user.php';
 $prefix='kiwe_inbox_'.bin2hex(random_bytes(5));
 foreach(['client'=>'administrator','editor'=>'editor','author'=>'author','guest'=>'contributor','shop'=>'shop_manager','designer'=>'kiwe_web_designer','subscriber'=>'subscriber','customer'=>'customer']as$kind=>$role){
  $id=wp_insert_user(['user_login'=>$prefix.$kind,'user_pass'=>wp_generate_password(48,true,true),'user_email'=>$prefix.$kind.'@example.invalid','role'=>$role]);
  if(is_wp_error($id))throw new RuntimeException('Synthetic account creation');$users[$kind]=(int)$id;
 }
 $settings=new DSA\Settings();$prefs=new Preferences($settings,new DSA\Trust\Trust_Service());
 $channels=(new ReflectionProperty(DSA\Plugin::class,'channels'))->getValue(DSA\Plugin::instance());
 $push=(new ReflectionProperty(DSA\Plugin::class,'push'))->getValue(DSA\Plugin::instance());
 $inbox=new Inbox($prefs,$push,null,$channels);
 $controller=new DSA\Rest\Admin_Notifications_Controller($inbox);
 $assert($prefs->enabled(),'native inbox enabled regardless of legacy flags');
 $resolved=$settings->all();
 foreach(['enabled','notifications_enabled','notification_preferences_enabled']as$key)$assert($resolved['permissions'][$key]===true,'core permission resolves on '.$key);
 $default=$settings->fresh_install_defaults();
 $assert(empty($default['metrics']['enabled'])&&empty($default['enabled'])&&empty($default['phonekey']['enabled']),'inbox defaults do not activate tracking or the visitor surface');
 $assert(!has_action('template_redirect',[$inbox,'queue_visitor_summary']),'an inbox instance does not activate visitor tracking');
 foreach($users as$kind=>$id){
  wp_set_current_user($id);delete_user_meta($id,'dsa_admin_notification_inbox');
  $beforeCaps=wp_get_current_user()->allcaps;
  $read=new WP_REST_Request('GET','/dsa/v1/admin-notifications');$read->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
  $assert(current_user_can('read')&&current_user_can('kiwe_use_notifications')&&$controller->can_read($read),'own inbox admission '.$kind);
  $assert($prefs->inbox_accepts($id,'account_update'),'native account topic default '.$kind);
  $snapshot=$prefs->workspace_snapshot();$topicIds=array_column($snapshot['config']['topics'],'id');
  $assert(in_array('account_update',$snapshot['preferences']['inboxTopics'],true)&&in_array('cart_reminder',$snapshot['preferences']['inboxTopics'],true),'account and saved cart inbox defaults '.$kind);
  if(in_array($kind,['author','guest','designer','subscriber','customer'],true))$assert(!array_filter($topicIds,static fn($topic)=>str_starts_with($topic,'admin_')||$topic==='securetrack_incident'),'ordinary role catalog excludes staff alerts '.$kind);
  $assert(!$prefs->user_accepts($id,'app','account_update')&&!$prefs->user_accepts($id,'email','account_update'),'no external enrollment '.$kind);
  $mailBefore=$mail;$httpBefore=$http;
  $event=['id'=>$prefix.'-'.$kind,'userIds'=>[$id],'title'=>'Account update','message'=>'Only this account can read this update.','url'=>home_url('/?kiwe-screen=profile')];
  $inbox->dispatch_event('account_update',$event);
  $items=$inbox->pull_current_user();
  $assert(in_array($event['id'],array_column($items,'id'),true),'native event without audience/channel choices '.$kind);
  $assert($mail===$mailBefore&&$http===$httpBefore,'native event makes no gateway call '.$kind);
  $meta=get_user_meta($id,'dsa_admin_notification_inbox',true);
  $inbox->pull_current_user();$assert($meta===get_user_meta($id,'dsa_admin_notification_inbox',true),'GET does not mark or rewrite account notices '.$kind);
  $assert($beforeCaps===wp_get_current_user()->allcaps,'notification read grants no other capabilities '.$kind);
  $write=new WP_REST_Request('POST','/dsa/v1/admin-notifications');$write->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$write->set_header('X-Kiwe-Mutation','1');
  $guard=(new DSA\Access\WordPress_Role_Access_Service())->guard_rest(null,null,$write);
  $assert(!is_wp_error($guard)&&true===$controller->can_write($write),'own acknowledgement crosses existing role guard '.$kind);
  $assert($inbox->acknowledge_current_user($event['id']),'own acknowledgement '.$kind);
  $ack=get_user_meta($id,'dsa_admin_notification_inbox',true);
  $inbox->dispatch_event('account_update',$event);
  $assert($ack===get_user_meta($id,'dsa_admin_notification_inbox',true),'replay preserves acknowledgement '.$kind);
 }
 wp_set_current_user($users['subscriber']);
 $assert(!$inbox->acknowledge_current_user($prefix.'-client'),'cannot acknowledge another account');
 $inbox->dispatch_event('account_update',['id'=>$prefix.'no-recipient','title'=>'Private','message'=>'Must not broadcast.']);
 $assert(!in_array($prefix.'no-recipient',array_column((array)get_user_meta($users['client'],'dsa_admin_notification_inbox',true),'id'),true),'personal topic never broadcasts without recipient');
 $wrong=new WP_REST_Request('GET','/dsa/v1/admin-notifications');$wrong->set_header('X-WP-Nonce','invalid');
 $assert(!$controller->can_read($wrong),'private GET needs current nonce');
 wp_set_current_user($users['client']);$nonce=wp_create_nonce('wp_rest');wp_set_current_user($users['subscriber']);$wrong->set_header('X-WP-Nonce',$nonce);
 $assert(!$controller->can_read($wrong),'another account nonce denied');
 $_SERVER['HTTP_ORIGIN']='https://outside.example.invalid';$wrong->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
 $assert(!$controller->can_read($wrong),'cross-origin inbox denied');unset($_SERVER['HTTP_ORIGIN']);
 update_user_meta($users['subscriber'],DSA\Access\Team_Workspace_Service::SUSPENDED_META,'1');
 $assert(!$prefs->topic_allowed_for_user($users['subscriber'],'account_update')&&!$controller->can_read($wrong),'banned account denied');delete_user_meta($users['subscriber'],DSA\Access\Team_Workspace_Service::SUSPENDED_META);
 wp_set_current_user(0);$assert(!$controller->can_read($wrong)&&[]===$inbox->pull_current_user(),'anonymous account inbox denied');
 wp_set_current_user($users['subscriber']);
 $state=$prefs->workspace_snapshot();
 $saved=$prefs->save_workspace(['expected'=>$state['expected'],'topics'=>[],'channels'=>[],'topicChannels'=>[],'inboxTopics'=>['account_update','new_post']]);
 $assert(!is_wp_error($saved)&&in_array('new_post',$saved['preferences']['inboxTopics'],true),'native-only preferences confirmed');
 $assert($prefs->inbox_accepts($users['subscriber'],'cart_reminder'),'legacy native cart choice cannot suppress the core inbox');
 $assert([]===$saved['preferences']['topics']&&[]===$saved['preferences']['channels'],'native choice does not enroll a gateway');
 $assert(in_array($users['subscriber'],$prefs->inbox_user_ids_for_topic('new_post'),true),'native-only publishing audience discoverable');
 $assert(!in_array($users['subscriber'],$prefs->audience_user_ids_for_topic('email','new_post'),true),'native-only publishing is not an email audience');
 $prefs->save(['visitorId'=>'admin:user:'.$users['subscriber'],'topics'=>[],'channels'=>[]]);
 $assert(in_array('new_post',$prefs->workspace_snapshot()['preferences']['inboxTopics'],true),'legacy DSA save preserves native choices');
 $assert($prefs->inbox_accepts($users['subscriber'],'cart_reminder'),'external-only legacy save leaves the core inbox available');
 $assert($prefs->personal_topic('cart_reminder')&&[]===$prefs->inbox_user_ids_for_topic('cart_reminder'),'saved carts require their explicit personal recipient');
 $injectTopics=static fn($topics)=>array_merge($topics,[['id'=>'admin_new_order','audience'=>'admin'],['id'=>'unowned_private_extension']]);
 add_filter('dsa_notification_topic_catalog',$injectTopics);
 try{$snapshot=$prefs->workspace_snapshot();$assert(!array_intersect(['admin_new_order','unowned_private_extension'],array_column($snapshot['config']['topics'],'id')),'native filtered catalog cannot broaden subscriber entitlement');}
 finally{remove_filter('dsa_notification_topic_catalog',$injectTopics);}
 $before=get_user_meta($users['subscriber'],'dsa_admin_notification_inbox',true);
 $inbox->account_verified($users['subscriber'],'email');
 $assert(count((array)get_user_meta($users['subscriber'],'dsa_admin_notification_inbox',true))>count((array)$before),'PhoneKey verification reuses account inbox');
 $inbox->account_password_changed(get_userdata($users['subscriber']));
 $assert(in_array('Your password changed',array_column((array)get_user_meta($users['subscriber'],'dsa_admin_notification_inbox',true),'title'),true),'password reset produces presentation-safe account update');
 $assert(class_exists('WooCommerce')&&function_exists('wc_create_order'),'native Woo available');
 $order=wc_create_order(['customer_id'=>$users['customer'],'status'=>'checkout-draft']);if(is_wp_error($order))throw new RuntimeException('Synthetic Woo order');$orders[]=$order->get_id();
 $before=get_user_meta($users['shop'],'dsa_admin_notification_inbox',true);$inbox->queue_order($order);
 $assert($before===get_user_meta($users['shop'],'dsa_admin_notification_inbox',true),'checkout draft is not a new order alert');
 $order->set_status('pending');$order->save();
 $event=['id'=>'order-'.$order->get_id(),'objectId'=>$order->get_id(),'userIds'=>[$users['client'],$users['shop'],$users['subscriber']],'title'=>'New order','message'=>'Review payment and fulfilment.','url'=>admin_url('admin.php?page=kiwe-store&area=orders&search='.$order->get_id())];
 $mailBefore=$mail;$httpBefore=$http;$inbox->dispatch_event('admin_new_order',$event);
 foreach(['client','shop']as$kind){wp_set_current_user($users[$kind]);$assert(in_array($event['id'],array_column($inbox->pull_current_user(),'id'),true),'order alert for authorized '.$kind);$assert($inbox->acknowledge_current_user($event['id']),'order acknowledgement '.$kind);$inbox->reconcile_recent_orders_for_current_user();$assert(in_array($event['id'],array_column($inbox->pull_current_user(),'id'),true)&&Inbox::unread_count()===0,'seen order remains available without a new badge '.$kind);}
 wp_set_current_user($users['subscriber']);$assert(!in_array($event['id'],array_column($inbox->pull_current_user(),'id'),true),'subscriber cannot read staff order alert');
 $assert($mailBefore===$mail&&$httpBefore===$http,'recent order recovery does not replay gateway sends');
 $status=new DSA\Notifications\Order_Notification_Service($prefs,$channels,$push);
 $status->notify_status_changed($order->get_id(),'pending','processing',$order);
 wp_set_current_user($users['customer']);$items=$inbox->pull_current_user();$assert(in_array('order_status',array_column($items,'type'),true),'customer status arrives without gateway choice');
 wp_set_current_user($users['subscriber']);$assert(!in_array('order_status',array_column($inbox->pull_current_user(),'type'),true),'another customer cannot read order status');
 wp_set_current_user($users['shop']);$roleUser=new WP_User($users['shop']);$roleUser->set_role('subscriber');wp_set_current_user(0);wp_set_current_user($users['shop']);
 $assert(!in_array('admin_new_order',array_column($inbox->pull_current_user(),'type'),true),'demotion removes old order alert access');
 $assert(!$inbox->acknowledge_current_user($event['id']),'demoted staff cannot acknowledge an unauthorized record');

 // A visit acknowledges its captured snapshot, not notifications that arrive afterwards.
 foreach($users as$kind=>$id){
  wp_set_current_user($id);$ids=$inbox->visit_ids();
  $assert($inbox->acknowledge_visit($ids)&&Inbox::unread_count()===0,'visit automatically clears own count '.$kind);
  $late=$prefix.'-late-'.$kind;$inbox->dispatch_event('account_update',['id'=>$late,'userIds'=>[$id],'title'=>'A later update','message'=>'Arrived after the captured visit.','inboxOnly'=>true]);
  $assert($inbox->acknowledge_visit($ids)&&Inbox::unread_count()===1,'later arrival remains new '.$kind);
  $write=new WP_REST_Request('POST','/dsa/v1/admin-notifications');$write->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$write->set_header('X-Kiwe-Mutation','1');$write->set_header('Content-Type','application/json');$write->set_body(wp_json_encode(['ids'=>$inbox->visit_ids()]));
  $ack=$controller->acknowledge($write)->get_data();
  $assert($controller->can_write($write)&&!empty($ack['seen'])&&$ack['unreadCount']===0,'DSA native visit receipt '.$kind);
  $assert(in_array($late,array_column($inbox->pull_current_user(),'id'),true),'seen updates remain available '.$kind);
 }
 wp_set_current_user($users['client']);
 $inbox->dispatch_event('account_update',['id'=>$prefix.'-race-old','userIds'=>[$users['client']],'title'=>'Before visit','message'=>'Snapshot race.','inboxOnly'=>true]);
 $ids=$inbox->visit_ids();$inject=true;
 $race=static function($check,$id,$key)use(&$inject,$inbox,$users,$prefix){if($inject&&$id===$users['client']&&$key==='dsa_admin_notification_inbox'){$inject=false;$inbox->dispatch_event('account_update',['id'=>$prefix.'-race-new','userIds'=>[$id],'title'=>'During visit','message'=>'Must remain new.','inboxOnly'=>true]);}return $check;};
 add_filter('update_user_metadata',$race,1,3);
 try{$assert($inbox->acknowledge_visit($ids),'visit retries a native metadata race');}finally{remove_filter('update_user_metadata',$race,1);}
 $assert(Inbox::unread_count()===1&&in_array($prefix.'-race-new',array_column($inbox->pull_current_user(),'id'),true),'concurrent notification survives visit compare-and-swap');
 $assert(!$inbox->acknowledge_visit(array_fill(0,41,'too-many'))&&!$inbox->acknowledge_visit([123]),'visit receipt validates its fixed payload bound');
 $activity=new DSA\Access\Workspace_Activity_Service();
 $request=static function(array$body){$r=new WP_REST_Request('POST','/dsa/v1/workspace/activity/seen');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('X-Kiwe-Mutation','1');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));return $r;};
 $snap=DSA\Access\Workspace_Activity_Service::snapshots();$assert(($snap['users']['count']??0)>=8,'native registration hook creates the Users count');$snap=$snap['users'];$total=$snap['ceiling'];
 $activity->user_created($users['client']);$assert(DSA\Access\Workspace_Activity_Service::snapshots()['users']['ceiling']===$total,'native user hook replay does not count twice');
 $activity->user_created(2147483000);
 $ack=$activity->visit($request($snap));$assert($ack->get_status()===200&&DSA\Access\Workspace_Activity_Service::snapshots()['users']['count']===1,'card visit preserves an arrival after the rendered ceiling');
 $latest=DSA\Access\Workspace_Activity_Service::snapshots()['users'];$assert($activity->visit($request($latest))->get_status()===200&&DSA\Access\Workspace_Activity_Service::snapshots()['users']['count']===0,'next Users visit resets its own badge');
 $forged=$latest;$forged['ceiling']++;$assert($activity->visit($request($forged))->get_status()!==200,'forged card ceiling denied');
 $expired=$latest;$expired['issued']=time()-700;$assert($activity->visit($request($expired))->get_status()!==200,'expired card receipt denied');
 $badNonce=$request($latest);$badNonce->set_header('X-WP-Nonce','invalid');$assert($activity->visit($badNonce)->get_status()===403,'card writes require current nonce');
 $ownerSnap=DSA\Access\Workspace_Activity_Service::snapshots()['posts'];
 $post=wp_insert_post(['post_type'=>'post','post_title'=>'Kiwe visit fixture','post_status'=>'draft','post_author'=>$users['author']],true);if(is_wp_error($post))throw new RuntimeException('Native draft fixture');$posts[]=(int)$post;
 wp_set_current_user($users['author']);$authorSnap=DSA\Access\Workspace_Activity_Service::snapshots();
 $assert(!isset($authorSnap['users'])&&($authorSnap['posts']['realm']??'')==='own'&&($authorSnap['posts']['count']??0)===1,'author badges contain only admitted own records');
 $assert($activity->visit($request($ownerSnap))->get_status()!==200,'another actor and realm cannot clear the author badge');
 $assert($activity->visit($request($authorSnap['posts']))->get_status()===200,'author can visit their own content');
 update_user_meta($users['author'],DSA\Access\Team_Workspace_Service::SUSPENDED_META,'1');$assert($activity->visit($request($authorSnap['posts']))->get_status()===403,'suspended account cannot write a visit');delete_user_meta($users['author'],DSA\Access\Team_Workspace_Service::SUSPENDED_META);
 $setup=new DSA\Access\Analytics_Reporting_Setup_Service($settings,(new ReflectionProperty(DSA\Plugin::class,'abandoned_carts'))->getValue(DSA\Plugin::instance()));
 $prepare=new WP_REST_Request('POST','/dsa/v1/workspace/analytics/setup');$prepare->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$prepare->set_header('X-Kiwe-Mutation','1');
 $assert(!$setup->permission($prepare),'author cannot enable site reporting');
 wp_set_current_user($users['client']);$prepare->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
 $assert($setup->permission($prepare),'client owner can prepare reporting');
 $beforeSetup=[get_option(DSA_OPTION_SETTINGS),get_option('stp_settings'),get_option('stp_db_version'),get_option('dsa_abandoned_cart_db_version')];$state=DSA\Access\Analytics_Reporting_Setup_Service::projection();$stateAgain=DSA\Access\Analytics_Reporting_Setup_Service::projection();
 $assert($state['expected']===$stateAgain['expected']&&$beforeSetup===[get_option(DSA_OPTION_SETTINGS),get_option('stp_settings'),get_option('stp_db_version'),get_option('dsa_abandoned_cart_db_version')],'reporting projection never writes schema or settings');
 $prepare->set_header('Content-Type','application/json');$prepare->set_body(wp_json_encode(['confirmed'=>true,'expected'=>'stale','recordVisits'=>true]));
 $assert($setup->prepare($prepare)->get_status()===409&&$beforeSetup===[get_option(DSA_OPTION_SETTINGS),get_option('stp_settings'),get_option('stp_db_version'),get_option('dsa_abandoned_cart_db_version')],'stale reporting review cannot alter recording');

 wp_set_current_user($users['subscriber']);
 for($index=0;$index<45;$index++)$inbox->dispatch_event('account_update',['id'=>$prefix.'limit-'.$index,'userIds'=>[$users['subscriber']],'title'=>'Account update','message'=>'Bounded history.','inboxOnly'=>true]);
 $assert(count(get_user_meta($users['subscriber'],'dsa_admin_notification_inbox',true))===40,'per-account history capped at 40');
 $assert(count($inbox->pull_current_user())===20&&$inbox->workspace_history(['account_update'],1)['hasNext'],'private inbox projection and history paged at 20');
 $assert(DSA\Bricks\Bricks_Integration::native_woocommerce_defaults(['unrelated'=>'retained','woocommerceEnableAjaxAddToCart'=>false])===['unrelated'=>'retained','woocommerceEnableAjaxAddToCart'=>true],'native Bricks AJAX enabled and unrelated setting retained');
 $assert(\Bricks\Woocommerce::enabled_ajax_add_to_cart(),'Bricks request cache uses automatic AJAX');
 $assert($http===0,'no remote delivery attempted');
}catch(Throwable$e){$proof['error']=['class'=>get_class($e),'line'=>$e->getLine(),'message'=>$e->getMessage()];}
finally{
 try{
  wp_set_current_user(0);
  foreach($orders as$id){$order=wc_get_order($id);if($order)$order->delete(true);wp_clear_scheduled_hook('dsa_admin_notification_event',['new_order',$id]);delete_transient('dsa_admin_alert_new_order_'.$id);$assert(!wc_get_order($id),'synthetic order retired');}
  foreach($posts as$id)wp_delete_post($id,true);
  foreach($users as$id){$GLOBALS['wpdb']->delete($table,['user_id'=>$id],['%d']);wp_delete_user($id);$assert(!get_userdata($id),'synthetic account retired');}
  foreach($inboxes as$id=>$value){if($value==='')delete_user_meta($id,'dsa_admin_notification_inbox');else update_user_meta($id,'dsa_admin_notification_inbox',$value);$assert(get_user_meta($id,'dsa_admin_notification_inbox',true)===$value,'pre-existing inbox restored');}
  foreach($activityMeta as$id=>$values)foreach($values as$key=>$value){if($value==='')delete_user_meta($id,$key);else update_user_meta($id,$key,$value);$assert(get_user_meta($id,$key,true)===$value,'pre-existing activity cursor restored');}
  if($activityOriginal===false)delete_option(DSA\Access\Workspace_Activity_Service::OPTION);else update_option(DSA\Access\Workspace_Activity_Service::OPTION,$activityOriginal,false);
  $assert(get_option(DSA\Access\Workspace_Activity_Service::OPTION,false)===$activityOriginal,'original global activity counters restored');
  if($recent===false)delete_transient('kiwe_notification_recent_orders');else set_transient('kiwe_notification_recent_orders',$recent,5*MINUTE_IN_SECONDS);
  foreach($original as$key=>$value)$assert(get_option($key)===$value,'original state retained '.$key);
  $assert($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name=%s",'bricks_global_settings'))===$bricksRaw,'native Bricks row unchanged by acceptance');
  $proof['cleanup']=true;
 }catch(Throwable$e){$proof['cleanupError']=$e->getMessage();}
 while(ob_get_level()>$level)ob_end_clean();foreach($filters as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);wp_set_current_user($actor);
}
$proof['blockedMailAttempts']=$mail;$proof['blockedHttpAttempts']=$http;
$proof['passed']=$http===0&&!isset($proof['error'])&&!isset($proof['cleanupError'])&&$proof['cleanup']&&!in_array(false,array_column($proof['checks'],'passed'),true);
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
