<?php
/** Native settings, Woo lifecycle and SQL coverage; fresh local synthetic schema only. */
if(PHP_SAPI!=='cli'||!defined('KIWE_COMMERCE_OWNED_DATABASE')||DB_NAME!==KIWE_COMMERCE_OWNED_DATABASE||!preg_match('/^kiwe_ce01_20261029_(editorial|store)$/D',DB_NAME))throw new RuntimeException('Owned commerce schema required');

function kiwe_commerce_completion_tests(bool $store):array {
 global $wpdb;
 $checks=[];$GLOBALS['commerceChecks']=&$checks;$check=static function($ok,$label)use(&$checks){$checks[$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
 if(!defined('DSA_OPTION_SETTINGS'))define('DSA_OPTION_SETTINGS','dsa_settings');
 if(!defined('DSA_VERSION'))define('DSA_VERSION','8.0.0-rc.455');
 wp_set_current_user(1);$owner=wp_get_current_user();foreach(['kiwe_manage_context','kiwe_manage_notification_policy','edit_others_shop_orders']as$cap)$owner->add_cap($cap);
 update_option(DSA\Access\Site_Owner_Service::OPTION,['enabled'=>true,'ownerId'=>1,'dashboardMandatory'=>true],false);
 $settings=new DSA\Settings();$base=$settings->all();$base['commerceSentinel']=['preserve'=>'unchanged'];$base['abandoned_cart']['automatic_email_enabled']=false;$base['abandoned_cart']['automatic_whatsapp_enabled']=false;
 unset($base['abandoned_cart']['reminder_schedule_hours']);update_option(DSA_OPTION_SETTINGS,$base,false);$raw=get_option(DSA_OPTION_SETTINGS);$settings=new DSA\Settings();
 $check($settings->all()['abandoned_cart']['reminder_schedule_hours']===[]&&get_option(DSA_OPTION_SETTINGS)===$raw,'Legacy reads keep cooldown timing and do not write native settings');
 $phonekey=new DSA\PhoneKey\PhoneKey_Bridge(static fn()=>[]);$channels=new DSA\Communications\Channel_Service($settings,new DSA\Communications\Email_Service($settings),$phonekey);
 $preferences=new DSA\Notifications\Notification_Preference_Service($settings,new DSA\Trust\Trust_Service());$push=new DSA\Notifications\Push_Service();
 $delivery=new DSA\Notifications\Notification_Campaign_Service($preferences,$channels,$push);
 $carts=$store?new DSA\Commerce\Abandoned_Cart_Service($settings,new DSA\Commerce\Store_Analytics_Service($settings),$channels):null;
 $workspace=new DSA\Access\Campaign_Workspace_Service($preferences,$delivery,null,$carts);
 if(!$store){$state=$workspace->automations();$check($state['cart']===null&&$state['orders']===null,'No-Woo automation exposes no Store controls');$service=new DSA\Notifications\Order_Notification_Service($preferences,$channels,$push);$service->register();$check(!has_action('woocommerce_order_status_changed',[$service,'notify_status_changed']),'No-Woo registers no order lifecycle hook');return ['passed'=>true,'checks'=>$checks];}
 $check(DSA\Apps\Store_App_Service::enabled(),'Actual Woo enables the bundled Store source');
 $carts->set_preferences($preferences);$carts->maybe_install();$check($carts->ready(),'Explicit manager setup creates native saved-cart tables');
 $state=$workspace->automations();$check(is_array($state['orders'])&&in_array('pending',$state['orders']['fields']['statuses'],true),'Native order statuses projected into Campaigns');
 $saveCart=new ReflectionMethod($workspace,'save_automation');$saveOrder=new ReflectionMethod($workspace,'save_order_automation');
 $fields=$state['cart']['fields'];$fields['reminder_schedule_hours']=[12,24];$fields['cooldown_hours']=12;$fields['max_reminders']=2;$fields['manual_reminders_enabled']=true;
 $request=['operation'=>'automation','fields'=>$fields,'expected'=>$state['cart']['expected'],'confirmed'=>true];
 $saved=$saveCart->invoke($workspace,$request);$check(!is_wp_error($saved)&&$saved['cart']['fields']===$fields,'12h/24h and manual reminder settings persist through the actual manager owner');
 $check(get_option(DSA_OPTION_SETTINGS)['commerceSentinel']===['preserve'=>'unchanged'],'Unrelated native settings survive reminder save');
 $check(is_wp_error($saveCart->invoke($workspace,$request)),'Stale reminder proof cannot save twice');
 $before=get_option(DSA_OPTION_SETTINGS);$bad=$fields;$bad['reminder_schedule_hours']=[24,12];$check(is_wp_error($saveCart->invoke($workspace,['operation'=>'automation','fields'=>$bad,'expected'=>$saved['cart']['expected'],'confirmed'=>true]))&&get_option(DSA_OPTION_SETTINGS)===$before,'Malformed reminder order fails without native writes');
 $bad=$fields;$bad['cooldown_hours']=24;$check(is_wp_error($saveCart->invoke($workspace,['operation'=>'automation','fields'=>$bad,'expected'=>$saved['cart']['expected'],'confirmed'=>true])),'Contradicting cooldown and scheduled gap are refused');
 $orders=['statuses'=>['pending','on-hold','cancelled'],'channels'=>[]];
 $savedOrder=$saveOrder->invoke($workspace,['operation'=>'order-automation','fields'=>$orders,'expected'=>$workspace->automations()['orders']['expected'],'confirmed'=>true]);
 $check(!is_wp_error($savedOrder)&&$savedOrder['orders']['fields']===$orders,'Order status/channel selection saves in canonical native order');
 $check(get_option(DSA_OPTION_SETTINGS)['commerceSentinel']===['preserve'=>'unchanged'],'Order policy preserves all unrelated settings');
 $badOrder=['statuses'=>['invented-status'],'channels'=>[]];$check(is_wp_error($saveOrder->invoke($workspace,['operation'=>'order-automation','fields'=>$badOrder,'expected'=>$workspace->automations()['orders']['expected'],'confirmed'=>true])),'Invented order statuses are refused');
 $state=$workspace->automations();$unready=['statuses'=>$state['orders']['fields']['statuses'],'channels'=>['email']];
 $check(empty($state['orders']['ready']['email'])&&is_wp_error($saveOrder->invoke($workspace,['operation'=>'order-automation','fields'=>$unready,'expected'=>$state['orders']['expected'],'confirmed'=>true])),'Unconfigured email cannot be newly enabled');
 $settings->update(['email'=>['enabled'=>true]]);$state=$workspace->automations();$auto=$state['cart']['fields'];$auto['automatic_email_enabled']=true;
 $enabled=$saveCart->invoke($workspace,['operation'=>'automation','fields'=>$auto,'expected'=>$state['cart']['expected'],'confirmed'=>true]);$stored=get_option(DSA_OPTION_SETTINGS)['abandoned_cart'];
 $check(!is_wp_error($enabled)&&$enabled['cart']['fields']['automatic_email_enabled']===true&&!$stored['automatic_email_enabled']&&!$stored['automatic_whatsapp_enabled']&&$stored['policy_automatic_email_enabled']===true,'Current policy enables the selected channel while both legacy sender flags remain off');
 $check($stored['automation_started_at']>=time()-5&&$stored['automation_policy_v1']===true,'New programme receives a server-owned prospective activation fence');
 $reader=wp_create_user('commerce-reader',wp_generate_password(40),'reader@example.test');$events=[];$capture=static function($topic,$event)use(&$events){$events[]=['topic'=>$topic,...$event];};add_action('kiwe_notification_event',$capture,1,2);
 $service=new DSA\Notifications\Order_Notification_Service($preferences,$channels,$push);$service->register();
 $order=wc_create_order(['customer_id'=>$reader,'status'=>'pending']);$check(!is_wp_error($order)&&count($events)===0,'Creating a native order alone neither invents checkout acceptance nor sends a receipt');
 do_action('woocommerce_checkout_order_processed',$order->get_id(),[],$order);do_action('woocommerce_checkout_order_processed',$order->get_id(),[],$order);
 $check(count($events)===0,'Checkout only queues the receipt and performs no inline customer delivery');
 $service->notify_received($order->get_id());$service->notify_received($order->get_id());
 $check(count($events)===1&&str_contains($events[0]['title'],'received your order'),'Queued received-order worker emits once even when invoked again');
 $check($events[0]['deliveryChannels']===[],'Disabled external policy keeps the native order event while narrowing delivery');
 $order->set_status('on-hold');$order->save();$check(count($events)===2&&str_contains($events[1]['message'],'On hold'),'On-hold native transition emits once');
 $order->set_status('cancelled');$order->save();$check(count($events)===3,'Cancelled native transition emits one event');
 $order->save();$check(count($events)===3,'Saving the same order does not resend a status');
 $draft=wc_create_order(['customer_id'=>$reader,'status'=>'checkout-draft']);$check(count($events)===3,'Checkout draft emits no order confirmation');$draft->set_status('pending');$draft->save();$check(count($events)===4,'Draft-to-pending emits exactly one native event');
 $guest=wc_create_order(['customer_id'=>0,'status'=>'pending']);$check(count($events)===4,'Guest checkout contact does not silently become a notification account');
 $order->delete(true);$draft->delete(true);$guest->delete(true);remove_action('kiwe_notification_event',$capture,1);
 wp_set_current_user($reader);$check(is_wp_error($saveOrder->invoke($workspace,['operation'=>'order-automation','fields'=>$orders,'expected'=>$workspace->automations()['orders']['expected']??'','confirmed'=>true])),'Subscriber cannot change order delivery policy');wp_set_current_user(1);
 if(defined('KIWE_COMMERCE_FORM_FIXTURES'))kiwe_commerce_actual_forms($check,$settings);
 return ['passed'=>true,'checks'=>$checks,'customerDeliveryAttempted'=>false,'schema'=>DB_NAME];
}

/** Loads the prior, independently built Store classes with the current Core. */
function kiwe_commerce_legacy_store_tests():array {
 $checks=[];$GLOBALS['commerceChecks']=&$checks;$check=static function($ok,$label)use(&$checks){$checks[$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
 if(!defined('DSA_OPTION_SETTINGS'))define('DSA_OPTION_SETTINGS','dsa_settings');
 wp_set_current_user(1);$before=get_option(DSA_OPTION_SETTINGS);$settings=new DSA\Settings();
 $check(!method_exists(DSA\Commerce\Abandoned_Cart_Service::class,'supports_automation_policy')&&!method_exists(DSA\Notifications\Order_Notification_Service::class,'delivery_policy'),'Actual prior Store classes are selected');
 $phonekey=new DSA\PhoneKey\PhoneKey_Bridge(static fn()=>[]);$channels=new DSA\Communications\Channel_Service($settings,new DSA\Communications\Email_Service($settings),$phonekey);
 $preferences=new DSA\Notifications\Notification_Preference_Service($settings,new DSA\Trust\Trust_Service());$delivery=new DSA\Notifications\Notification_Campaign_Service($preferences,$channels,new DSA\Notifications\Push_Service());
 $carts=new DSA\Commerce\Abandoned_Cart_Service($settings,new DSA\Commerce\Store_Analytics_Service($settings),$channels);$workspace=new DSA\Access\Campaign_Workspace_Service($preferences,$delivery,null,$carts);$state=$workspace->automations();
 $check($state['cart']['updateRequired']===true&&$state['cart']['canEdit']===false&&$state['orders']===null,'New Core with prior Store offers read-only carts and no unsupported order controls');
 $save=new ReflectionMethod($workspace,'save_automation');$saved=$save->invoke($workspace,['operation'=>'automation','fields'=>$state['cart']['fields'],'expected'=>$state['cart']['expected'],'confirmed'=>true]);
 $check(is_wp_error($saved)&&get_option(DSA_OPTION_SETTINGS)===$before,'Mixed-version settings save is refused without writes');
 $check(empty($carts->config()['automatic_email_enabled'])&&empty($carts->config()['automatic_whatsapp_enabled']),'Actual prior Store cannot replay the new reminder programme');
 return ['passed'=>true,'checks'=>$checks,'customerDeliveryAttempted'=>false];
}

function kiwe_commerce_actual_forms(callable $check,DSA\Settings $settings):void {
 if(!defined('KIWE_NATIVE_BRICKS_SOURCE')||!is_file(KIWE_NATIVE_BRICKS_SOURCE.'/functions.php'))throw new RuntimeException('Explicit native Bricks source required');
 $theme=static fn()=>KIWE_NATIVE_BRICKS_SOURCE;add_filter('template_directory',$theme);try{require_once KIWE_NATIVE_BRICKS_SOURCE.'/functions.php';}finally{remove_filter('template_directory',$theme);}
 Bricks\Integrations\Form\Submission_Database::maybe_create_table();$table=Bricks\Integrations\Form\Submission_Database::get_table_name();
 $native=new Bricks\Integrations\Form\Actions\Save_Submission('save-submission');add_filter('bricks/form/save-submission/form_data',[DSA\Bricks\Form_Identity_Service::class,'capture'],10,3);
 $notices=[];$capture=static function($id)use(&$notices){$notices[]=$id;};add_action('kiwe_form_submission_saved',$capture);
 update_option(DSA\Site\Site_Identity_Service::OPTION_STORE_EMAIL,'forms-owner@example.test',false);$integration=new DSA\Bricks\Bricks_Integration(new DSA\Element_Registry(),$settings);
 $fixtures=json_decode(file_get_contents(KIWE_COMMERCE_FORM_FIXTURES),true,512,JSON_THROW_ON_ERROR);if(count($fixtures)!==2)throw new RuntimeException('Two exact named source forms required');
 foreach($fixtures as$fixture){
  if(!in_array($fixture['site'],['national','dharwad'],true))throw new RuntimeException('Named native source required');$source=$fixture['settings'];
  $check(in_array('email',$source['actions'],true)&&in_array('save-submission',$source['actions'],true)&&$source['emailToCustom']==='{kiwe_store_email}',$fixture['site'].' retains native saved-submission and dynamic-recipient actions');
  $check($integration->render_dynamic_tag($source['emailToCustom'])==='forms-owner@example.test',$fixture['site'].' business email binding resolves through the actual Kiwe dynamic-tag owner');
  $post=wp_insert_post(['post_type'=>'page','post_status'=>'private','post_title'=>$fixture['site'].' Contact native fixture']);update_post_meta($post,BRICKS_DB_PAGE_CONTENT,[['id'=>$fixture['element'],'name'=>'form','parent'=>0,'children'=>[],'settings'=>$source]]);
  $form=new class($post,$fixture['element'],$source){public array $result=[];public function __construct(public int $post,public string $element,public array $settings){}public function get_settings(){return $this->settings;}public function get_fields(){return ['postId'=>$this->post,'formId'=>$this->element];}public function set_result($value){$this->result=$value;}public function get_field_value($id){foreach($this->settings['fields']as$field)if($field['id']===$id)return match($field['type']??'text'){'email'=>'enquirer@example.test','tel'=>'+12025550123','number'=>'1','textarea'=>'Synthetic native Contact enquiry; no external delivery.',default=>'Synthetic enquirer'};return '';}};
  $before=count($notices);$native->run($form);$saved=(int)$GLOBALS['wpdb']->insert_id;DSA\Bricks\Form_Identity_Service::confirmed();
  $check(($form->result['type']??'')==='success'&&$saved>0&&count($notices)===$before+1&&end($notices)===$saved,$fixture['site'].' actual native Contact fields save once and confirm the exact arrival');
  $record=DSA\Access\Form_Workspace_Service::notification_record($saved);$check(($record['title']??'')==='Contact enquiries'&&!str_contains(wp_json_encode($record),'enquirer@example.test'),$fixture['site'].' Daily identity uses the saved Contact form without leaking enquiry answers');
 }
 remove_action('kiwe_form_submission_saved',$capture);
}
