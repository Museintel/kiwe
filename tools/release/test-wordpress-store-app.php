<?php
/** CLI-only native Store fixture; common owner isolates writes in connection-local tables. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||KIWE_SEO_NATIVE_TEST!==true)throw new RuntimeException('Owned native Store fixture required');
function kiwe_store_native_run(string $mode):array{
 global $wpdb;
 $proof=['passed'=>false,'cleanup'=>false,'checks'=>[],'observations'=>[]];
 $check=static function($ok,string$name)use(&$proof){$proof['checks'][$name]=(bool)$ok;if(!$ok)throw new RuntimeException($name);};
 $hash=static fn($v)=>hash('sha256',serialize($v));
 try{
  foreach($GLOBALS['kiwe_seo_test_original']as$name=>$source)if($name!=='options')$check(false!==$wpdb->query('INSERT INTO '.$wpdb->$name.' SELECT * FROM '.$source),'Exact temporary native '.$name.' copy');
  // Observe invalidation without purging the served website or its persistent cache tables.
  foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url']as$hook)remove_all_actions($hook);
  remove_all_filters('pre_option_kiwe_store_app_enabled_v1');
  update_option(DSA\Apps\Store_App_Service::OPTION,$mode==='on'?'1':'0',false);
  $actor=wp_insert_user(['user_login'=>'kiwe_store_native_fixture','user_email'=>'store-fixture@example.invalid','user_pass'=>wp_generate_password(32,true,true),'role'=>'administrator']);
  $check(!is_wp_error($actor)&&$actor>0,'Temporary native manager created only in owned tables');
  $user=new WP_User((int)$actor);foreach(['kiwe_manage_context','kiwe_manage_navigation','kiwe_manage_analytics','kiwe_manage_team']as$cap)$user->add_cap($cap);wp_set_current_user((int)$actor);
  $check(current_user_can('kiwe_manage_context'),'Native manager retains the exact app permission');
  $settingsBefore=get_option(DSA_OPTION_SETTINGS);$contextBefore=get_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE);
  $raw=(new DSA\Settings())->all();$state=DSA\Apps\Store_App_Service::card();$active=$mode==='on';
  $check($state['enabled']===$active&&$state['canChange']===true,'Native Store card follows actual dependency and app state');
  $check($mode!=='missing'||$state['blocked']===true,'Non-Woo dependency is truthfully blocked without installing software');
  $check($active?str_contains($state['url'],'area=settings'):$state['url']==='','Only active Store links into the existing settings home');
  $dock=new DSA\Access\Dock_Workspace_Service();$view=$dock->snapshot();
  $check(isset($view['labels']['cart'])===$active,'Cart Dock option appears only for active Store');
  $check(in_array('cart',$view['choices']['dock']['item_order'],true)===$active,'Cart Dock ordering follows applicability');
  $check($active?!empty($view['choices']['commerce']):$view['choices']['commerce']===[],'Cart DSA display choices follow applicability');
  $registry=new DSA\Modules\Module_Registry();$registry->register_defaults();$modules=$registry->public_modules();
  $manifest=$registry->manifest_contract($raw['dock']);
  $check(in_array('cart',array_column($manifest['items'],'id'),true)===$active&&isset($modules['saved'],$modules['search'],$modules['profile']),'Active visitor manifest omits Store cart while Core Saved Search Profile remain declared');
  $profile=new DSA\Onboarding\Design_Context_Profile_Service();$public=$profile->public_context(false);
  foreach(['identity','business','about','contact','brand','audience','contentPlan','services','testimonials','resources','regulatory','commercePlan','seo','scores']as$key)$check(array_key_exists($key,$public),'Complete authoring context family '.$key);
  $check($profile->commerce_available()===$active,'Core Design Context applicability follows Store');
  $urls=(new DSA\Access\Continuity_App_Service())->area_urls();
  $check(isset($urls['store'])===$active,'Store editing home disappears while inactive');
  $form=(new ReflectionMethod(DSA\Access\Continuity_App_Service::class,'business'))->invoke(new DSA\Access\Continuity_App_Service(),'store');
  $check($active?(is_array($form)&&in_array('regulatory.fssaiLicense',array_column($form['fields'],'path'),true)):is_wp_error($form),'Actual native Store form includes FSSAI only while active');
  $tasks=(new DSA\Access\Workspace_Admin_Service())->task_catalog();$storeCards=array_values(array_filter($tasks,static fn($t)=>($t['app']['id']??'')==='store'));
  $check(count($storeCards)===1&&$storeCards[0]['group']==='apps','Store lives in App instead of Setup');
  $saved=array_values(array_filter($tasks,static fn($t)=>($t['label']??'')==='Saved carts'));
  $check($active?count($saved)===1&&!in_array($saved[0]['group'],['apps','setup'],true):!$saved,'Saved carts stay in their Daily home and disappear while inactive');
  $graph=new DSA\AI\Site_Graph_Service(new DSA\Settings(),$registry);
  $packet=(new DSA\Site_Graph\Design_Context_Service($graph))->context(['productLimit'=>2,'mediaLimit'=>2,'contentLimit'=>2,'customContentLimit'=>2,'termLimit'=>2],false);
  $check(isset($packet['dynamicBindings'],$packet['ideationContract'],$packet['seamDesignContext']),'Complete Core SiteGraph and Start handoff remain available');
  $check(($packet['ideationContract']['autoComposeCommands']??[])===['/ideate'],'Start command contract is retained');
  $proof['observations']['coreContextHash']=$hash(array_intersect_key($public,array_flip(['identity','business','about','contact','brand','audience','services','resources','seo','scores'])));
  $reader=new DSA\Site_Graph\Data_Query_Service();
  if(!$active){
   foreach([['resource'=>'products'],['resource'=>'posts','postType'=>'product'],['resource'=>'terms','taxonomy'=>'product_cat']]as$i=>$query){$r=$reader->query($query,true);$check(($r['error']??'')==='store_app_off'&&($r['available']??null)===false,'Actual native Store query refuses before catalog scan '.$i);}
   $hidden=$raw['dock'];$visible=$view['choices']['dock']['enabled_items'];$visible['search']=!($visible['search']??false);
   $updated=$dock->update(['expected'=>$view['expected'],'changes'=>['dock'=>['enabled_items'=>$visible]]]);
   $next=(new DSA\Settings())->all();$check(!is_wp_error($updated)&&($next['dock']['enabled_items']['cart']??null)===($hidden['enabled_items']['cart']??null),'Native Core Dock save preserves the dormant Cart setting');
   update_option(DSA_OPTION_SETTINGS,$settingsBefore,false);
   $dormant=is_array($contextBefore)?$contextBefore:[];$dormant['regulatory']=['fssaiLicense'=>'synthetic-original','future'=>'keep'];$dormant['commerce']=['enabled'=>true,'future'=>'keep'];$dormant['contentPlan']['highlightBestsellers']=true;update_option($profile::OPTION_PROFILE,$dormant,false);
   $result=$profile->save_section('website-plan',['contentPlan'=>['showBlogRailOnHome'=>true,'highlightBestsellers'=>false]],(int)$actor);$after=get_option($profile::OPTION_PROFILE);
   $check(!is_wp_error($result)&&$after['regulatory']===$dormant['regulatory']&&$after['commerce']===$dormant['commerce']&&$after['contentPlan']['highlightBestsellers']===true,'Native Core context save retains dormant Store FSSAI and bestseller choices');
   update_option($profile::OPTION_PROFILE,$contextBefore,false);
  }else{
   // An ordinary guest cart in the actual native Woo owner; never place an order.
   wp_set_current_user(0);update_option('woocommerce_enable_guest_checkout','yes',false);
   $product=new WC_Product_Simple();$product->set_name('Synthetic Store App product');$product->set_status('publish');$product->set_regular_price('10');$product->set_virtual(true);$id=$product->save();
   $check($id>0,'Synthetic native product belongs only to isolated tables');
   wc_load_cart();$key=WC()->cart->add_to_cart($id,1);$check(is_string($key)&&$key!==''&&WC()->cart->get_cart_contents_count()===1,'Actual native guest add to cart remains available');
   WC()->cart->set_quantity($key,2);$check(WC()->cart->get_cart_contents_count()===2,'Actual guest quantity change uses native Woo state');
   $cart=new DSA\Commerce\Cart_Payload_Service(new DSA\Settings(),fn()=>new DSA\Commerce\Linked_Products_Service(new DSA\Settings()),fn()=>new DSA\Commerce\Store_Analytics_Service(new DSA\Settings()));$payload=$cart->cart_data();
   $check($payload['available']===true&&$payload['count']===2&&count($payload['items'])===1,'Kiwe cart reads the same native guest cart');
   $checkout=(new DSA\Commerce\Checkout_Service(new DSA\Settings(),$cart))->contract();
   $check(!empty($checkout['available'])&&empty($checkout['accountRequired']),'Native guest checkout remains available without an order');
   WC()->cart->empty_cart();wp_set_current_user((int)$actor);
  }
  // Actual App writer changes only its option, native cache invalidation and the three owned jobs.
  $beforeSettings=get_option(DSA_OPTION_SETTINGS);$beforeContext=get_option($profile::OPTION_PROFILE);$beforePlugins=get_option('active_plugins');
  $changed=DSA\Apps\Store_App_Service::change(false,DSA\Apps\Store_App_Service::expected());
  $check(!is_wp_error($changed)&&!DSA\Apps\Store_App_Service::enabled(),'Actual native app writer can pause Store');
  $check(get_option(DSA_OPTION_SETTINGS)===$beforeSettings&&get_option($profile::OPTION_PROFILE)===$beforeContext&&get_option('active_plugins')===$beforePlugins,'App switch preserves settings business facts and installed software exactly');
  $changed=DSA\Apps\Store_App_Service::change(true,DSA\Apps\Store_App_Service::expected());
  $check($mode==='missing'?is_wp_error($changed):!is_wp_error($changed)&&DSA\Apps\Store_App_Service::enabled(),'Actual native dependency admission and re-enabling follow Woo availability');
  $check(($GLOBALS['kiwe_seo_test_http_calls']??0)===0&&($GLOBALS['kiwe_seo_test_mail_calls']??0)===0,'Native inspection and cart tests send no provider mail or campaign');
  $proof['observations']['wordpress']=$GLOBALS['wp_version'];$proof['observations']['php']=PHP_VERSION;$proof['observations']['database']=$wpdb->db_version();$proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();$proof['frames']=array_map(static fn($f)=>['file'=>basename($f['file']??''),'line'=>$f['line']??0,'function'=>$f['function']??''],array_slice($e->getTrace(),0,6));}
 finally{try{do_action('shutdown');remove_all_actions('shutdown');}catch(Throwable$e){$proof['passed']=false;$proof['shutdownError']=$e->getMessage();}kiwe_seo_test_cleanup($proof);}
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);return$proof;
}
