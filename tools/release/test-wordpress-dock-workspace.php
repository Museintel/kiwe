<?php
/** Actual private Dock routes and client roles, isolated native options; no customer writes. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE || ! defined( 'KIWE_WORKSPACE_EXPECTED_VERSION' ) ) exit(2);
use DSA\Access\Dock_Workspace_Service as Dock;
use DSA\Access\Site_Owner_Service as Owner;
$proof=['schema'=>'kiwe.native-dock-workspace.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'checks'=>[],'cleanup'=>false];
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$optionsTable=$wpdb->options;$cache=$GLOBALS['wp_object_cache'];$table=$wpdb->prefix.'kiwe_dock_qa_'.bin2hex(random_bytes(5));$user=0;$menu=0;$isolated=false;$filters=[];$mail=[];$http=[];
$originalChoices=[];foreach([DSA_OPTION_SETTINGS,Owner::OPTION,'active_plugins','template','stylesheet','dsa_installed_themes']as$key)$originalChoices[$key]=get_option($key);
$filter=static function($hook,$callback,$args=1)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
$request=static function($method,$body=[], $nonce=true,$mutation=true){$r=new WP_REST_Request($method,'/dsa/v1/workspace/dock');if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('X-Kiwe-Mutation','1');if($method==='POST'){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_do_request($r);};
try {
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'complete matching package');
 $assert(!wp_using_ext_object_cache(),'native request-local cache available');
 $filter('pre_wp_mail',static function($return,$args)use(&$mail){$mail[]=true;return true;},2);$filter('pre_http_request',static function($return,$args,$url)use(&$http){$http[]=true;return new WP_Error('kiwe_qa_block','No outbound acceptance traffic');},3);
 $ownerOff=static fn()=>[];$filter('pre_option_'.Owner::OPTION,$ownerOff);
 $user=wp_insert_user(['user_login'=>'kiwedockqa_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(40,true,true),'user_email'=>'dock_'.bin2hex(random_bytes(6)).'@example.invalid','role'=>'administrator']);if(is_wp_error($user))throw new RuntimeException('Synthetic client unavailable');$user=(int)$user;
 $assert(false!==$wpdb->query("CREATE TABLE {$table} LIKE {$optionsTable}"),'native fixture options created');$isolated=true;
 $assert(false!==$wpdb->query("INSERT INTO {$table} SELECT * FROM {$optionsTable}"),'native options isolated before editing');$wpdb->options=$table;$GLOBALS['wp_object_cache']=new WP_Object_Cache();
 remove_filter('pre_option_'.Owner::OPTION,$ownerOff,PHP_INT_MAX);
 $filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$actor?:1,'rankMath'=>'inactive']);
 wp_set_current_user($user);$_SERVER['HTTP_ORIGIN']=home_url();$_SERVER['HTTP_REFERER']=admin_url('admin.php');$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
 $state=$request('GET');$assert($state->get_status()===200,'native client administrator reads Dock');$state=$state->get_data();
 $assert($state['schema']==='kiwe.dock-workspace.v1'&&!isset($state['choices']['phonekey'])&&!isset($state['choices']['ai']),'private projection excludes authentication and provider settings');
 $commerce=(new DSA\Settings())->get('commerce');$assert($state['choices']['commerce']['cart_quantity_controls']===$commerce['cart_quantity_controls'],'quantity projection reads the actual native commerce owner');
 $tasks=(new DSA\Access\Workspace_Admin_Service())->task_catalog();$dockTasks=array_values(array_filter($tasks,static fn($t)=>$t['group']==='setup'&&$t['label']==='Dock'));$appTasks=array_values(array_filter($tasks,static fn($t)=>$t['group']==='setup'&&$t['label']==='App'));$assert(!$dockTasks&&count($appTasks)===1,'client Home has one Setup App entrance instead of a duplicate Dock card');
 $menu=wp_create_nav_menu('Kiwe dock QA '.bin2hex(random_bytes(5)));if(is_wp_error($menu))throw new RuntimeException('Synthetic menu unavailable');$menu=(int)$menu;
 $raw=get_option(DSA_OPTION_SETTINGS);$save=static function($changes)use($request){$s=(new Dock())->snapshot();return $request('POST',['expected'=>$s['expected'],'changes'=>$changes]);};
 $changes=['dock'=>['menu_nav_ids'=>[$menu],'menu_label'=>"Writer's menu",'mobile_orientation'=>'horizontal','mobile_horizontal_position'=>'center','enabled_items'=>['ai'=>false]],'style'=>['mode'=>'sheet'],'dsa_theme'=>['active_color'=>'#552b1c']];if(function_exists('WC'))$changes['commerce']=['cart_quantity_controls'=>!$commerce['cart_quantity_controls']];$saved=$save($changes);
 $assert($saved->get_status()===200,'native client writes menu, buttons, placement and appearance');$after=get_option(DSA_OPTION_SETTINGS);
 $assert($after['dock']['menu_nav_ids']===[$menu]&&$after['dock']['menu_nav_id']===$menu&&$after['dock']['menu_label']==="Writer's menu",'same native menu storage and text readback');
 $assert($after['style']['mode']==='sheet'&&(new DSA\Settings())->get('style')['sheet_origin']==='bottom'&&$after['dsa_theme']['active_color']==='#552b1c','shared native appearance controls read back');
 foreach(['phonekey','secure','ai','pwa']as$key)$assert(($after[$key]??null)===($raw[$key]??null),'unrelated native settings preserved '.$key);
 if(function_exists('WC')){$assert((new DSA\Settings())->get('commerce')['cart_quantity_controls']===!$commerce['cart_quantity_controls'],'quantity control saves through the actual native commerce owner');$unchanged=$after['commerce'];if(array_key_exists('cart_quantity_controls',$raw['commerce']))$unchanged['cart_quantity_controls']=$raw['commerce']['cart_quantity_controls'];else unset($unchanged['cart_quantity_controls']);ksort($unchanged);$originalCommerce=$raw['commerce'];ksort($originalCommerce);$assert($unchanged===$originalCommerce,'other native commerce choices preserved');}
 $assert(!current_user_can('manage_options')&&current_user_can(Dock::CAPABILITY),'client theme presentation uses bounded Dock authority without technical options access');
 $assert(array_slice(array_column($state['themes'],'id'),0,2)===['legacy','kiwe2027'],'theme catalog comes from native installed-theme owner');
 $themeBefore=get_option(DSA_OPTION_SETTINGS);$themeSaved=$save(['style'=>['active_theme_id'=>'legacy','sheet_position'=>'right','sheet_animation'=>'soft','sheet_backdrop'=>'fade','sheet_spacing'=>'inset','sheet_duration_ms'=>450,'sheet_max_height'=>90,'sheet_width_percent'=>80]]);
 $themeAfter=(new DSA\Settings())->all();$assert($themeSaved->get_status()===200&&$themeAfter['style']['active_theme_id']==='legacy'&&$themeAfter['style']['visual_profile']==='legacy','native theme selection pairs legacy foundation');
 $assert($themeAfter['style']['sheet_position']==='right'&&$themeAfter['style']['sheet_animation']==='soft'&&$themeAfter['style']['sheet_backdrop']==='fade'&&$themeAfter['style']['sheet_spacing']==='inset'&&$themeAfter['style']['sheet_duration_ms']===450&&$themeAfter['style']['sheet_max_height']===90&&$themeAfter['style']['sheet_width_percent']===80,'complete native sheet behaviour readback');
 $themeRaw=get_option(DSA_OPTION_SETTINGS);$assert($themeRaw['dock']===$themeBefore['dock']&&$themeRaw['dsa_theme']===$themeBefore['dsa_theme'],'theme selection preserves visitor menu modules geometry and colours');
 $themeSaved=$save(['style'=>['active_theme_id'=>'kiwe2027']]);$assert($themeSaved->get_status()===200&&(new DSA\Settings())->get('style')['visual_profile']==='kiwe2027','native modern theme foundation selected');
 $themeOwner=new DSA\Theme\Theme_Package_Service();$installed=$themeOwner->install(['theme'=>['id'=>'qa-dock-skin','name'=>'QA Dock skin'],'css'=>'#dsa-surface { color: #123456; }','settings'=>['style'=>['mode'=>'classic'],'dock'=>['enabled_items'=>['menu'=>false]]]]);$assert(!empty($installed['ok']),'synthetic skin installed through existing native theme owner');
 $themeBefore=get_option(DSA_OPTION_SETTINGS);$themeSaved=$save(['style'=>['active_theme_id'=>'qa-dock-skin']]);$themeAfter=get_option(DSA_OPTION_SETTINGS);$assert($themeSaved->get_status()===200&&$themeAfter['style']['active_theme_id']==='qa-dock-skin'&&$themeAfter['style']['visual_profile']==='kiwe2027','installed custom skin uses native modern foundation');
 $assert($themeAfter['dock']===$themeBefore['dock']&&$themeAfter['style']['mode']===$themeBefore['style']['mode'],'skin selection retains website module and layout choices');
 $themeState=(new Dock())->snapshot();$assert(!isset($themeState['themes'][2]['css'])&&!isset($themeState['themes'][2]['settings']),'theme catalog excludes package internals');
 $themeOwner->delete('qa-dock-skin');$assert($request('POST',['expected'=>$themeState['expected'],'changes'=>['style'=>['active_theme_id'=>'qa-dock-skin']]])->get_status()===409,'native theme removal invalidates stale client choice');$save(['style'=>['active_theme_id'=>'kiwe2027']]);
 $assert($request('POST',['expected'=>$state['expected'],'changes'=>['enabled'=>false]])->get_status()===409,'stale save refused');
 $current=(new Dock())->snapshot();$body=['expected'=>$current['expected'],'changes'=>['enabled'=>false]];
 $assert($request('POST',$body,false)->get_status()===403,'missing nonce denied');$assert($request('POST',$body,true,false)->get_status()===403,'missing mutation proof denied');
 $_SERVER['HTTP_ORIGIN']='https://foreign.example.invalid';$assert($request('POST',$body)->get_status()===403,'cross-origin mutation denied');$_SERVER['HTTP_ORIGIN']=home_url();
 foreach([['dock'=>['menu_nav_ids'=>[2147483647]]],['phonekey'=>['enabled'=>true]],['dock'=>['enabled_items'=>['invented'=>true]]],['dsa_theme'=>['active_color'=>'red']],['style'=>['active_theme_id'=>'not-installed']],['style'=>['visual_profile'=>'legacy']],['style'=>['sheet_duration_ms'=>901]],['style'=>['sheet_max_height'=>44]],['style'=>['sheet_width_percent'=>91]],['style'=>['sheet_duration_ms'=>'450']]]as$bad){$unchanged=get_option(DSA_OPTION_SETTINGS);$assert($save($bad)->get_status()===400&&get_option(DSA_OPTION_SETTINGS)===$unchanged,'invalid or unowned field denied without mutation '.count($proof['checks']));}
 $beforeNative=(new Dock())->snapshot();$native=new DSA\Settings();$choices=$native->get('dock');$choices['menu_label']='Changed in native settings';$native->update_selected(['dock'=>$choices]);$assert((new Dock())->snapshot()['choices']['dock']['menu_label']==='Changed in native settings','native edit immediately appears in client projection');$assert($request('POST',['expected'=>$beforeNative['expected'],'changes'=>['enabled'=>false]])->get_status()===409,'native edit invalidates stale client snapshot');
 foreach(['author','editor','subscriber','contributor','shop_manager']as$role){if(!get_role($role))continue;wp_set_current_user($actor);$u=new WP_User($user);$u->set_role($role);wp_set_current_user(0);wp_set_current_user($user);$assert($request('GET')->get_status()===403,'native '.$role.' cannot manage Dock');$assert($request('POST',$body)->get_status()===403,'native '.$role.' cannot write Dock');}

 // Core journeys project historical off switches without rewriting their source.
 $legacy=get_option(DSA_OPTION_SETTINGS);$choices=$legacy;$choices['commerce']['checkout_surface_enabled']=false;$choices['dock']['admin_dashboard_link_enabled']=false;
 (new DSA\Settings())->update_selected(['commerce'=>$choices['commerce'],'dock'=>$choices['dock']]);$nativeSettings=new DSA\Settings();$resolved=$nativeSettings->all();
 $assert($resolved['commerce']['checkout_surface_enabled']===true&&$resolved['dock']['admin_dashboard_link_enabled']===true,'historical switches cannot disable core journeys');
 $assert(get_option(DSA_OPTION_SETTINGS)['commerce']['checkout_surface_enabled']===false&&get_option(DSA_OPTION_SETTINGS)['dock']['admin_dashboard_link_enabled']===false,'projection does not write historical choices');
 $linkMethod=new ReflectionMethod(DSA\Rest\Runtime_Hydration_Controller::class,'dashboard_link');$hydration=(new ReflectionClass(DSA\Rest\Runtime_Hydration_Controller::class))->newInstanceWithoutConstructor();
 foreach(['administrator','kiwe_web_designer','editor','author','contributor','shop_manager','subscriber','customer']as$role){if(!get_role($role))continue;$nativeUser=new WP_User($user);$nativeUser->set_role($role);wp_set_current_user(0);wp_set_current_user($user);$link=$linkMethod->invoke($hydration);$expected=in_array($role,['administrator','kiwe_web_designer','editor','author','contributor','shop_manager'],true);$assert($expected?($link['url']??'')===DSA\Access\Workspace_Admin_Service::url():$link===null,'private Dashboard uses existing workspace policy for '.$role);}
 wp_set_current_user(0);$assert($linkMethod->invoke($hydration)===null,'anonymous runtime never receives Dashboard access');$nativeUser=new WP_User($user);$nativeUser->set_role('administrator');wp_set_current_user(0);wp_set_current_user($user);
 // Links shortcuts use the existing Contact workspace, with no technical capability grant.
 $linksMethod=new ReflectionMethod(DSA\Rest\Runtime_Hydration_Controller::class,'links_admin_data');
 $contactRequest=static function($method,$body=[],$nonce=true){$r=new WP_REST_Request($method,'/dsa/v1/workspace/continuity/contact');if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('X-Kiwe-Mutation','1');if($method==='POST'){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_do_request($r);};
 $socialData=$linksMethod->invoke($hydration,['social_links'=>['facebook'=>'https://example.invalid/existing']]);
 $assert(!current_user_can('manage_options')&&current_user_can('kiwe_manage_context'),'client business access does not grant technical settings');
 $assert(!empty($socialData['canEdit'])&&count($socialData['editor']['socials'])===6&&$socialData['editor']['adminUrl']===admin_url('admin.php?page=kiwe-contact'),'client gets six social entries and existing Contact editor');
 $assert($socialData['editor']['socials'][4]['url']===''&&$socialData['editor']['socials'][4]['editUrl']===add_query_arg('link','pinterest',admin_url('admin.php?page=kiwe-contact')),'missing Pinterest links directly to its native field');
 $contact=$contactRequest('GET');$assert($contact->get_status()===200,'client can enter the existing social editor');$contact=$contact->get_data();
 $socialBody=['expected'=>$contact['expected'],'path'=>'contact.socialLinks.pinterest','value'=>'https://example.invalid/synthetic-pinterest'];
 $assert($contactRequest('POST',$socialBody,false)->get_status()===403,'social edit requires native nonce');
 $savedContact=$contactRequest('POST',$socialBody);$assert($savedContact->get_status()===200,'social edit uses existing native Contact save');
 $assert(((new DSA\Settings())->get('link_hub')['social_links']['pinterest']??'')===$socialBody['value'],'Contact save updates the native Links owner');
 $assert(($contactRequest('GET')->get_data()['values']['contact.socialLinks.pinterest']??'')===$socialBody['value'],'Contact editor reads the same saved native social link');
 foreach(['kiwe_web_designer','editor','author','contributor','shop_manager','subscriber','customer']as$role){if(!get_role($role))continue;$nativeUser=new WP_User($user);$nativeUser->set_role($role);wp_set_current_user(0);wp_set_current_user($user);$assert($linksMethod->invoke($hydration,[])===['canEdit'=>false,'editor'=>[]],'private social management omitted for '.$role);$assert($contactRequest('POST',$socialBody)->get_status()===403,'native social mutation denied for '.$role);}
 wp_set_current_user(0);$assert($linksMethod->invoke($hydration,[])===['canEdit'=>false,'editor'=>[]],'anonymous Links never receives editor shortcuts');$nativeUser=new WP_User($user);$nativeUser->set_role('administrator');wp_set_current_user(0);wp_set_current_user($user);
 if(function_exists('WC')&&WC()){
  $wc=WC();$originalWC=[$wc->session,$wc->customer,$wc->cart];$gate=static fn()=>['enabled'=>true,'allowed'=>false];$testSession=null;
  try{
   $wc->session=new WC_Session_Handler();$wc->session->init();$testSession=$wc->session;$wc->customer=new WC_Customer($user);$wc->cart=new WC_Cart();
   $checkout=new DSA\Commerce\Checkout_Service($nativeSettings);$assert($checkout->enabled(),'native checkout enabled despite historical off switch');
   add_filter('dsa_purchase_identity_gate_state',$gate,PHP_INT_MAX);$assert(!$checkout->available(),'required identity gate still denies checkout');remove_filter('dsa_purchase_identity_gate_state',$gate,PHP_INT_MAX);
   $gate=static fn()=>['enabled'=>true,'allowed'=>true];add_filter('dsa_purchase_identity_gate_state',$gate,PHP_INT_MAX);$assert($checkout->available(),'qualified synthetic identity can use native checkout');
   $result=$checkout->save_draft(['billing_first_name'=>'Synthetic','billing_last_name'=>'Acceptance','billing_email'=>'checkout@example.invalid','billing_phone'=>'2025550100','billing_country'=>'IN','billing_state'=>'MH','billing_city'=>'Pune','billing_postcode'=>'411030','billing_address_1'=>'Synthetic acceptance address','account_password'=>'never-persist-this'],false);
   $assert(!empty($result['ok'])&&($wc->session->get('dsa_checkout_draft')['billing_first_name']??'')==='Synthetic','DSA details persist in the native Woo draft owner');
   $assert(!isset($wc->session->get('dsa_checkout_draft')['account_password']),'unowned account field excluded for signed-in checkout');
   $assert($checkout->checkout_value('','billing_first_name')==='Synthetic','native checkout template hydrates the saved first name');
   $assert(apply_filters('woocommerce_checkout_get_value',null,'billing_first_name')==='Synthetic','registered Woo template hook reads the same saved draft');
   $merged=$checkout->merge_draft_into_posted_data(['billing_first_name'=>'New template value']);$assert($merged['billing_first_name']==='New template value','native final checkout values remain authoritative');
   $assert(($checkout->contract()['values']['billing_city']??'')==='Pune','returning DSA reads the same native draft');
  }finally{remove_filter('dsa_purchase_identity_gate_state',$gate,PHP_INT_MAX);if($testSession)$testSession->destroy_session();[$wc->session,$wc->customer,$wc->cart]=$originalWC;}
 }
 (new DSA\Settings())->update_selected(['commerce'=>$legacy['commerce'],'dock'=>$legacy['dock']]);
 wp_set_current_user(0);foreach(['GET','POST']as$method){$denied=$request($method,$body);$assert($denied->get_status()===401&&($denied->get_data()['code']??'')==='rest_forbidden','anonymous '.$method.' route denied by WordPress authentication');}
 $proof['passed']=true;
} catch(Throwable $e){$proof['passed']=false;$proof['error']=$e->getMessage();}
finally {
 try {
  if($menu){wp_delete_nav_menu($menu);$assert(!is_nav_menu($menu),'synthetic menu retired');}
  $wpdb->options=$optionsTable;$GLOBALS['wp_object_cache']=$cache;wp_set_current_user($actor);
  foreach($filters as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);
  if($user){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);$assert(!get_userdata($user),'synthetic client retired');}
  if($isolated)$assert(false!==$wpdb->query("DROP TABLE {$table}"),'isolated native options retired');
  foreach($originalChoices as$key=>$value)$assert(get_option($key)===$value,'original site choices unchanged '.$key);
  $proof['cleanup']=true;
 } catch(Throwable $e){$proof['passed']=false;$proof['cleanupError']=$e->getMessage();}
 $proof['mailAttempts']=count($mail);$proof['httpAttempts']=count($http);
}
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
