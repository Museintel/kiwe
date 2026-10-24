<?php
/** Native in-memory Woo checkout plus one bounded role account; no order or provider transaction. */
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
$out=['schema'=>'kiwe.native-checkout-draft.v1','checks'=>[],'passed'=>false,'cleanup'=>false,'scope'=>'Native Woo fields, in-memory guest checkout and one bounded synthetic role account; no order or provider transaction'];
$assert=static function($ok,$label)use(&$out){$out['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$wc=WC();$before=[$wc->session,$wc->customer,$wc->cart];$actor=get_current_user_id();$raw=get_option(DSA_OPTION_SETTINGS);$filters=[];$delivery=false;$needs=true;$billingOnly=false;$http=0;$mail=0;$user=0;$ownerFilter=null;$borrowedCallbacks=[];
$filter=static function($hook,$cb,$argc=1)use(&$filters){add_filter($hook,$cb,PHP_INT_MAX,$argc);$filters[]=[$hook,$cb];};
try{
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'Exact complete private runtime');
 $filter('pre_http_request',static function()use(&$http){$http++;return new WP_Error('kiwe_test_closed','No transport');});$filter('pre_wp_mail',static function()use(&$mail){$mail++;return false;});
 $filter('pre_option_'.DSA_OPTION_SETTINGS,static function()use($raw,&$delivery){$v=is_array($raw)?$raw:[];$v['enabled']=true;$v['commerce']['cart_show_delivery']=$delivery;return $v;});
 $filter('dsa_purchase_identity_gate_state',static fn()=>['enabled'=>true,'allowed'=>true]);
 $filter('pre_option_woocommerce_enable_guest_checkout',static fn()=> 'yes');$filter('pre_option_woocommerce_enable_signup_from_checkout',static fn()=> 'no');
 // Native needs_shipping() exits before its cart filter when shipping is disabled
 // or no method exists. Supply only in-process configuration for this physical
 // fixture, as in the native cart-totals proof; create no real zone or method.
 $filter('pre_option_woocommerce_ship_to_countries',static fn()=> '');
 $filter('pre_transient_wc_shipping_method_count',static fn()=>['legacy'=>0,'enabled'=>1,'disabled'=>0,'version'=>WC_Cache_Helper::get_transient_version('shipping')]);
 $filter('pre_option_woocommerce_ship_to_destination',static function()use(&$billingOnly){return $billingOnly?'billing_only':'shipping';});
 $filter('woocommerce_cart_needs_shipping',static function()use(&$needs){return $needs;});
 wp_set_current_user(0);$wc->session=new class extends WC_Session {};$wc->customer=new WC_Customer();$wc->customer->set_billing_country('IN');$wc->customer->set_billing_state('MH');$wc->cart=new WC_Cart();
 $product=new WC_Product_Simple();$product->set_name('Synthetic checkout projection');$product->set_price('440');$product->set_tax_status('none');$wc->cart->set_cart_contents(['fixture'=>['data'=>$product,'quantity'=>1,'product_id'=>0,'variation_id'=>0]]);$wc->cart->set_totals(['subtotal'=>440,'cart_contents_total'=>440,'shipping_total'=>83.16,'total'=>523.16]);
 $factory=static function(){$s=new DSA\Settings();$p=new DSA\Commerce\Cart_Payload_Service($s,new DSA\Commerce\Linked_Products_Service($s),new DSA\Commerce\Store_Analytics_Service($s));(new ReflectionProperty($p,'totals_refreshed'))->setValue($p,true);return [new DSA\Commerce\Checkout_Service($s,$p),$p];};
 [$service,$payload]=$factory();
 $billing=['billing_first_name'=>'Synthetic','billing_last_name'=>'Checkout','billing_email'=>'checkout@example.invalid','billing_phone'=>'2025550100','billing_country'=>'IN','billing_state'=>'MH','billing_city'=>'Pune','billing_postcode'=>'411001','billing_address_1'=>'Synthetic fixture address','ship_to_different_address'=>'0'];
 $r=$service->save_draft($billing,true);$assert($r['valid']&&!$r['checkout']['shipToDifferent'],'Native required shipping fields do not invalidate unchecked billing-only draft');
 $assert($wc->customer->get_shipping_city()==='Pune'&&$wc->customer->get_shipping_postcode()==='411001','Native guest shipping destination follows billing while unchecked');
 $assert($r['checkout']['cartTotal']===$payload->product_total()&&$r['checkout']['cartShowDelivery']===false,'Checkout uses native product charge policy with delivery hidden');
 $assert($service->checkout_value('','billing_city')==='Pune'&&!$service->ship_to_different_address_checked(true),'Native template hooks hydrate saved billing and unchecked state');
 $out['nativePhysicalEligibility']=['shippingEnabled'=>wc_shipping_enabled(),'needsShipping'=>$wc->cart->needs_shipping(),'needsShippingAddress'=>$wc->cart->needs_shipping_address()];
 $assert($out['nativePhysicalEligibility']['needsShipping']===true&&$out['nativePhysicalEligibility']['needsShippingAddress']===true,'Native physical fixture requires a shipping address before negative validation');
 $blankShipping=['ship_to_different_address'=>'1'];$requiredShipping=[];
 foreach(WC()->checkout()->get_checkout_fields('shipping')as$key=>$definition){$blankShipping[$key]='';if(!empty($definition['required'])&&'hidden'!==($definition['type']??''))$requiredShipping[]=$key;}
 $r=$service->save_draft($blankShipping,true);
 $out['separateShippingFixture']=['requiredKeys'=>$requiredShipping,'submittedKeys'=>array_keys($blankShipping),'needsShipping'=>$r['checkout']['needsShipping']??null,'shipToDifferent'=>$r['checkout']['shipToDifferent']??null,'valid'=>$r['valid'],'errorKeys'=>array_keys($r['errors'])];
 $assert($requiredShipping!==[]&&!$r['valid']&&count(array_intersect($requiredShipping,array_keys($r['errors'])))===count($requiredShipping),'Explicit blanks validate the native required separate shipping definitions');
 $shipping=[];foreach($billing as$key=>$value)if(str_starts_with($key,'billing_'))$shipping['shipping_'.substr($key,8)]=$value;$shipping['shipping_city']='Mumbai';$shipping['shipping_postcode']='400001';$shipping['ship_to_different_address']='1';
 $r=$service->save_draft($shipping,true);$assert($r['valid']&&$wc->customer->get_shipping_city()==='Mumbai','Complete native separate address validates and primes active destination');
 $r=$service->save_draft(['ship_to_different_address'=>'0'],true);$assert($r['valid']&&$wc->customer->get_shipping_city()==='Pune','Switching off resets native delivery destination to billing');
 $assert(($wc->session->get('dsa_checkout_draft')['shipping_city']??'')==='Mumbai','Switching off retains separate address in the same Woo draft');
 $r=$service->save_draft(['ship_to_different_address'=>'1'],true);$assert($r['valid']&&$wc->customer->get_shipping_city()==='Mumbai','Switching back on restores the separate address without retyping');
 $native=$service->merge_draft_into_posted_data(['ship_to_different_address'=>false,'shipping_city'=>'','billing_city'=>'Edited natively']);$assert($native['shipping_city']===''&&$native['billing_city']==='Edited natively','Native final unchecked state and edited fields win over draft');
 $delivery=true;[$withDelivery,$nativePayload]=$factory();$c=$withDelivery->contract();$assert($c['cartShowDelivery']&&$c['cartTotal']===$nativePayload->money_text($wc->cart->get_total()),'Enabled delivery uses the same native full total policy');
 $delivery=false;$needs=false;[$virtual]=$factory();$r=$virtual->save_draft(['ship_to_different_address'=>'1'],true);$assert($r['valid']&&!$r['checkout']['needsShipping']&&!$r['checkout']['shipToDifferent'],'Native virtual cart ignores copied separate-address state');
 $needs=true;$billingOnly=true;[$restricted]=$factory();$r=$restricted->save_draft(['ship_to_different_address'=>'1'],true);$assert($r['valid']&&!$r['checkout']['needsShipping']&&!$r['checkout']['shipToDifferent'],'Native Woo billing-only destination hides separate-address choice');
 $assert($http===0&&$mail===0&&get_current_user_id()===0,'Guest fixture creates no provider request or account');
 // The same proof qualifies the private preference projection with actual native roles.
 $ownerPolicy=['enabled'=>false,'ownerId'=>0,'rankMath'=>'inactive'];$ownerFilter=static function()use(&$ownerPolicy){return $ownerPolicy;};$filter('pre_option_'.DSA\Access\Site_Owner_Service::OPTION,$ownerFilter);
 // These are catalog/capability fixtures, not account-event fixtures. Borrow only
 // this service's lifecycle callbacks so a synthetic user cannot seed staff inboxes.
 foreach(['user_register','set_user_role','profile_update','after_password_reset','password_reset','delete_user','deleted_user','wpmu_delete_user','kiwe_identity_factor_verified']as$hook){
  foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$priority=>$callbacks)foreach($callbacks as$id=>$entry){
   $callback=$entry['function']??null;$receiver=is_array($callback)?($callback[0]??null):null;
   if((is_object($receiver)||is_string($receiver))&&is_a($receiver,DSA\Notifications\Admin_Event_Notification_Service::class,true)){
    $borrowedCallbacks[]=[$hook,$priority,$id,$callback,(int)$entry['accepted_args']];remove_action($hook,$callback,$priority);
   }
  }
 }
 $paused=true;foreach($borrowedCallbacks as[$hook,$priority,$id,$callback,$accepted])$paused=$paused&&false===has_action($hook,$callback);$assert($paused,'Only account-event notification callbacks are paused in this process');
 $tag='kiwe_checkout_prefs_'.bin2hex(random_bytes(5));$created=wp_insert_user(['user_login'=>$tag,'user_pass'=>wp_generate_password(48,true,true),'user_email'=>$tag.'@example.invalid','role'=>'subscriber']);if(is_wp_error($created))throw new RuntimeException('Synthetic role account');$user=(int)$created;
 $prefs=new DSA\Notifications\Notification_Preference_Service(new DSA\Settings(),new DSA\Trust\Trust_Service());
 foreach(['subscriber'=>'subscriber','customer'=>'customer','guest'=>'contributor','client'=>'administrator','superAdmin'=>DSA\Access\Site_Owner_Service::ROLE]as$kind=>$role){
  $ownerPolicy['enabled']=false;$ownerPolicy['ownerId']=0;wp_set_current_user(0);$nativeUser=new WP_User($user);$nativeUser->set_role($role);$assert(in_array($role,(array)get_userdata($user)->roles,true),'Actual native role assignment '.$kind);
  $ownerPolicy['enabled']=true;$ownerPolicy['ownerId']=$kind==='superAdmin'?$user:$user+1000000;wp_set_current_user($user);delete_user_meta($user,'dsa_notification_preferences');
  $beforePreferences=get_user_meta($user,'dsa_notification_preferences',true);$private=$prefs->private_config();$snapshot=$prefs->workspace_snapshot();$ids=array_column($private['topics'],'id');
  $assert(in_array('account_update',$snapshot['preferences']['inboxTopics'],true)&&in_array('cart_reminder',$snapshot['preferences']['inboxTopics'],true),'Native account and saved-cart inbox defaults '.$kind);
  $assert($snapshot['preferences']['channels']===[]&&$snapshot['preferences']['topics']===[]&&!$prefs->user_accepts($user,'email','account_update')&&!$prefs->user_accepts($user,'app','cart_reminder'),'No external or campaign enrollment '.$kind);
  $staff=array_filter($ids,static fn($id)=>str_starts_with($id,'admin_')||$id==='securetrack_incident');
  $assert(in_array($kind,['client','superAdmin'],true)?in_array('admin_new_order',$ids,true):$staff===[],'Private catalog follows actual native role entitlement '.$kind);
  $publicIds=array_column($prefs->public_config()['topics'],'id');$assert(!array_filter($publicIds,static fn($id)=>str_starts_with($id,'admin_')||in_array($id,['securetrack_incident','account_update'],true)),'Cacheable public catalog is actor neutral '.$kind);
  $assert($beforePreferences===get_user_meta($user,'dsa_notification_preferences',true),'Reading private catalog does not mutate account preferences '.$kind);
  update_user_meta($user,'dsa_notification_preferences',['inboxTopics'=>[],'topics'=>[],'channels'=>[],'topicChannels'=>[]]);$empty=$prefs->workspace_snapshot();$assert($empty['preferences']['inboxTopics']===array_column($empty['config']['topics'],'id')&&$prefs->inbox_accepts($user,'account_update')&&$prefs->inbox_accepts($user,'cart_reminder'),'Legacy inbox choices cannot disable core updates '.$kind);
 }
 $assert($http===0&&$mail===0,'Neither checkout nor role preference checks send through a provider');$out['passed']=true;
}catch(Throwable$e){$out['error']=$e->getMessage();$out['line']=$e->getLine();}
finally{
 [$wc->session,$wc->customer,$wc->cart]=$before;
 // Retire the process-local ownership designation before native deletion.
 if($ownerFilter)remove_filter('pre_option_'.DSA\Access\Site_Owner_Service::OPTION,$ownerFilter,PHP_INT_MAX);wp_set_current_user($actor);$userClean=true;
 if($user){try{require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);$userClean=!get_userdata($user);}catch(Throwable$e){$userClean=false;$out['cleanupError']=$e->getMessage();}}
 // Keep event callbacks borrowed through deletion, then restore their exact
 // callback, priority and accepted-argument contract without changing any option.
 $hooksRestored=true;foreach($borrowedCallbacks as[$hook,$priority,$id,$callback,$accepted]){
  add_action($hook,$callback,$priority,$accepted);$entry=$GLOBALS['wp_filter'][$hook]->callbacks[$priority][$id]??null;
  $hooksRestored=$hooksRestored&&is_array($entry)&&$entry['function']===$callback&&(int)$entry['accepted_args']===$accepted&&has_action($hook,$callback)===$priority;
 }
 foreach($filters as[$hook,$cb])remove_filter($hook,$cb,PHP_INT_MAX);$out['borrowedNotificationCallbacks']=count($borrowedCallbacks);$out['borrowedHooksRestored']=$hooksRestored;$out['nativeHandlesRestored']=$wc->session===$before[0]&&$wc->customer===$before[1]&&$wc->cart===$before[2];$out['cleanup']=$userClean&&$hooksRestored&&$out['nativeHandlesRestored']&&$http===0&&$mail===0&&get_option(DSA_OPTION_SETTINGS)===$raw;$out['httpAttempts']=$http;$out['mailAttempts']=$mail;
}
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;
