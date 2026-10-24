<?php
/** Read-only native lab acceptance; execute in separate Woo/non-Woo WordPress bootstraps. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION')||!defined('KIWE_WORKSPACE_WOO_EXPECTED'))exit(2);
$proof=['schema'=>'kiwe.native-conditional-runtime.v1','passed'=>false,'checks'=>[],'cleanup'=>false];$originalActor=get_current_user_id();$settingsBefore=get_option(DSA_OPTION_SETTINGS);$pluginsBefore=$wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='active_plugins'");
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['passed'=>(bool)$ok,'label'=>$label];if(!$ok)throw new RuntimeException($label);};
$count=static function($hook,$class,$method){$n=0;foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$entries)foreach($entries as$entry){$cb=$entry['function'];if(is_array($cb)&&is_object($cb[0])&&get_class($cb[0])===$class&&$cb[1]===$method)$n++;}return $n;};
try{
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'exact complete native runtime');
 $woo=function_exists('WC');$assert($woo===KIWE_WORKSPACE_WOO_EXPECTED,'real WordPress bootstrap has the expected Woo presence');
 if(!$woo){
  $assert(!class_exists('WooCommerce',false)&&!post_type_exists('product'),'Woo and product registration are absent, rather than hidden by a Kiwe flag');
  $metrics=!empty((new DSA\Settings())->get('metrics',[])['enabled']);
  foreach(['Linked_Products_Service','Abandoned_Cart_Service','Checkout_Service','COD_Gate_Service','Product_Context_Service']as$name)$assert(!class_exists('DSA\\Commerce\\'.$name,false),'unneeded '.$name.' is not parsed');
  $assert(class_exists(DSA\Commerce\Store_Analytics_Service::class,false)===$metrics,'generic analytics is parsed only when explicitly configured');
  $assert(!class_exists(DSA\Access\Commerce_App_Service::class,false),'store workspace is not parsed on the non-Woo public bootstrap');
  $callbacks=0;foreach($GLOBALS['wp_filter']as$hook=>$handler)if(str_starts_with($hook,'woocommerce_'))foreach($handler->callbacks as$entries)foreach($entries as$entry){$cb=$entry['function'];if(is_array($cb)&&is_object($cb[0])&&str_starts_with(get_class($cb[0]),'DSA\\'))$callbacks++;}
  $assert($callbacks===0,'no Kiwe Woo order, cart, product, checkout or login callbacks on non-Woo');
 }else{
  $assert($count('woocommerce_add_to_cart',DSA\Commerce\Abandoned_Cart_Service::class,'capture_after_cart_change')===1,'native saved-cart capture is registered once without an activation setting');
  $assert($count('woocommerce_before_calculate_totals',DSA\Commerce\Store_Analytics_Service::class,'sync_cart_upsell_coupons')===1,'native pair pricing is registered once');
  $assert($count('woocommerce_checkout_posted_data',DSA\Commerce\Checkout_Service::class,'merge_draft_into_posted_data')===1,'checkout draft handoff remains registered');
 }
 $server=rest_get_server();$routes=$server->get_routes();
 foreach(['/dsa/v1/search','/dsa/v1/saved-items','/dsa/v1/runtime/hydrate','/dsa/v1/notification-preferences','/dsa/v1/account/profile']as$route)$assert(isset($routes[$route]),'shared '.$route.' remains available');
 $assert(isset($routes['/dsa/v1/cart'])===$woo&&isset($routes['/dsa/v1/checkout'])===$woo,'Kiwe cart and checkout REST routes follow actual Woo availability');
 $owner=null;foreach(($GLOBALS['wp_filter']['wp_ajax_nopriv_dsa_runtime_hydrate']->callbacks??[])as$entries)foreach($entries as$entry){$cb=$entry['function'];if(is_array($cb)&&$cb[0] instanceof DSA\Rest\Runtime_Hydration_Controller)$owner=$cb[0];}
 $assert($owner instanceof DSA\Rest\Runtime_Hydration_Controller,'original guest/private hydration owner exists');
 $request=new WP_REST_Request('GET','/dsa/v1/runtime/hydrate');$assert($owner->can_read($request)===true,'same-site guest identity read remains allowed');
 $origin=$_SERVER['HTTP_ORIGIN']??null;$_SERVER['HTTP_ORIGIN']='https://unrelated.invalid';$assert(is_wp_error($owner->can_read($request)),'cross-site private identity read remains refused');if($origin===null)unset($_SERVER['HTTP_ORIGIN']);else $_SERVER['HTTP_ORIGIN']=$origin;
 wp_set_current_user(0);$calculated=did_action('woocommerce_after_calculate_totals');$response=$owner->hydrate();$guest=$response->get_data();
 $assert($response->get_status()===200&&$guest['phonekey']['user']['loggedIn']===false,'anonymous identity remains explicit and usable');
 $assert($guest['phonekey']['cart']['available']===$woo&&$guest['commerce']['available']===$woo,'guest cart and commerce projections agree with native availability');
 $assert(did_action('woocommerce_after_calculate_totals')===$calculated,'identity read forces no cart or shipping recalculation');
 $headers=$response->get_headers();$assert(str_contains($headers['Cache-Control']??'','private')&&str_contains($headers['Cache-Control']??'','no-store')&&($headers['Vary']??'')==='Cookie','private runtime remains uncacheable and scoped to its cookie');
 $plugin=DSA\Plugin::instance();$saved=(new ReflectionProperty($plugin,'saved_items'))->getValue($plugin);$controller=new DSA\Rest\Saved_Items_Controller($saved);
 $assert($saved->current_items()===[]&&is_wp_error($controller->can_mutate(new WP_REST_Request('POST','/dsa/v1/saved-items'))),'guest Saved read stays empty and a guest cannot mutate native saved records');
 $accounts=new DSA\Rest\Account_Controller();$assert($accounts->can_access(new WP_REST_Request('GET','/dsa/v1/account/orders'))===false,'guest cannot read another account data');
 $ids=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);$assert(count($ids)===1,'existing native actor is available without making a test account');wp_set_current_user((int)$ids[0]);
 $signed=$owner->hydrate()->get_data();$assert($signed['phonekey']['user']['loggedIn']===true,'signed-in identity remains available without Woo');
 $assert($controller->get_items()->get_data()['items']===$saved->current_items(),'signed-in Saved uses the same native user metadata');
 $assert(is_wp_error($controller->can_mutate(new WP_REST_Request('POST','/dsa/v1/saved-items'))),'signed-in Saved mutation still requires its native nonce and mutation proof');
 $assert($accounts->can_access(new WP_REST_Request('GET','/dsa/v1/account/orders'))===true,'native signed-in account read boundary is retained');
 if(!$woo&&!$metrics){
  // Exercise the real mutation owner without editing an existing visitor's saved records.
  $actor=get_current_user_id();$fixture=[];$analyticsLoaded=class_exists(DSA\Commerce\Store_Analytics_Service::class,false);
  $read=static function($value,$id,$key,$single)use($actor,&$fixture){return $id===$actor&&$key==='dsa_saved_items'?[$fixture]:$value;};
  $write=static function($value,$id,$key,$data)use($actor,&$fixture){if($id===$actor&&$key==='dsa_saved_items'){$fixture=$data;return true;}return $value;};
  add_filter('get_user_metadata',$read,PHP_INT_MAX,4);add_filter('update_user_metadata',$write,PHP_INT_MAX,4);
  try{
   $item=['type'=>'bookmark','title'=>'Temporary conditional-runtime fixture','url'=>home_url('/kiwe-app119-fixture/')];$added=$saved->mutate('add',$item);
   $assert(!empty($added['ok'])&&count($fixture)===1&&$fixture[0]['type']==='bookmark','non-Woo native Saved mutation still adds an article bookmark with request-local metadata');
   $removed=$saved->mutate('remove',$item);$assert(!empty($removed['ok'])&&$fixture===[],'the same native bookmark owner removes its item');
   $assert(class_exists(DSA\Commerce\Store_Analytics_Service::class,false)===$analyticsLoaded,'a non-Woo bookmark does not start disabled optional analytics');
  }finally{remove_filter('get_user_metadata',$read,PHP_INT_MAX);remove_filter('update_user_metadata',$write,PHP_INT_MAX);}
 }
 $assert($count('kiwe_notification_event',DSA\Notifications\Admin_Event_Notification_Service::class,'dispatch_event')===1&&$count('user_register',DSA\Notifications\Admin_Event_Notification_Service::class,'account_registered')===1,'generic notification delivery ingress and account events remain registered once');
 $proof['passed']=true;
}catch(Throwable$error){$proof['error']=['message'=>$error->getMessage(),'line'=>$error->getLine()];}
finally{wp_set_current_user($originalActor);$proof['cleanup']=get_option(DSA_OPTION_SETTINGS)===$settingsBefore&&$wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='active_plugins'")===$pluginsBefore;}
$proof['passed']=$proof['passed']&&$proof['cleanup'];echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
