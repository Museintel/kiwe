<?php
/** Invoked only by the expiring, exact-lab qualification operator; not a public route. */
function kiwe_mcp_guidance_native_tests(callable $check,callable $ledger,string $run,string $mode): array {
 if(PHP_SAPI!=='cli'||!preg_match('/^[a-f0-9]{16}$/D',$run)||!in_array($mode,['normal','plain'],true))throw new RuntimeException('Exact native qualification context required.');
 global $wpdb;
 $initialActor=get_current_user_id();$owner=DSA\Access\Site_Owner_Service::owner_id();$ownedUsers=[];$ownedPosts=[];$ownedTables=[];$fixtures=[];$hooks=[];$nativePrefix=$wpdb->prefix;
 $properties=['posts','postmeta','term_relationships'];$originalTables=[];foreach($properties as$key)$originalTables[$key]=$wpdb->$key;
 $keys=['dsa_settings','pk_settings_v3','stp_settings','kiwe_seam_design_context_v1','active_plugins','template','stylesheet','permalink_structure','kiwe_store_app_enabled_v1','kiwe_seo_workspace_enabled_v1','kiwe_guest_app_enabled_v1','kiwe_ai_app_enabled_v1','kiwe_sitegraph_app_enabled_v1','kiwe_mcp_app_enabled_v1','kiwe_mcp_revocation_epoch_v1'];
 $snapshot=static function()use($wpdb,$keys):array{$out=[];foreach($keys as$key)$out[$key]=hash('sha256',(string)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));return$out;};
 $before=$snapshot();$engine=hash_file('sha256',DSA_DIR.'includes/PhoneKey/phonekey-core.php');
 $registry=static function():DSA\MCP\Operation_Registry{$r=new DSA\MCP\Operation_Registry();DSA\MCP\Native_Provider::register($r);return$r;};
 $runRead=static function($r,$id,$args=[])use($check){$scopes=$r->scopes();return$r->read($id,$args,$scopes,$r->freeze($scopes));};
 $filter=static function(string $name,$value)use(&$fixtures):void{$fn=static fn()=>$value;add_filter($name,$fn,PHP_INT_MAX);$fixtures[]=[$name,$fn];};
 // Fixtures use native writers with request-local delivery/activity hooks suppressed.
 foreach(['user_register','set_user_role','save_post','save_post_post','save_post_page','transition_post_status','wp_after_insert_post']as$hook){$hooks[$hook]=isset($GLOBALS['wp_filter'][$hook])?clone $GLOBALS['wp_filter'][$hook]:null;remove_all_actions($hook);}
 try {
  wp_set_current_user($owner);$check(current_user_can('kiwe_manage_context')&&DSA\MCP\Credentials::actor_allowed($owner),'Native technical actor can read current context');
  $r=$registry();$ops=$r->describe($r->scopes());$check(count($ops)===8,'Native installed provider exposes eight bounded reads and zero writes');
  $wire=json_decode(wp_json_encode($ops));$check($wire[0]->inputSchema->properties instanceof stdClass,'Actual native discovery exports an empty JSON Schema object');
  $old=$r->freeze(['mcp.read']);$check(count($r->describe(['mcp.read'],$old))===1&&is_wp_error($r->read('mcp.site.read',[],$r->scopes(),$old)),'Actual native old consent cannot inherit wider guidance');
  $site=$runRead($r,'mcp.site.read');$check(is_array($site)&&$site['state']==='existing'&&$site['mutatesSite']===false,'Existing lab orientation preserves native authored state');
  $nativeJourney=DSA\Onboarding\Design_Context_Journey_Service::projection();$check($site['journeyPercent']===$nativeJourney['percent']&&$site['nextStep']===($nativeJourney['next']??''),'Native setup progress and next step remain the authority');
  $apps=$runRead($r,'mcp.apps.read',['intent'=>'external-ai']);$check(is_array($apps)&&count($apps['apps'])===6&&$apps['mcpCanInstall']===false&&$apps['mcpCanRemove']===false,'Native App guidance does not claim remote installation/removal');
  $check(count(array_filter($apps['apps'],static fn($app)=>$app['id']==='ai'&&$app['recommendation']==='not-required'))===1,'External AI needs no built-in model credential');
  $check($apps['includedCoreApps']===['Auth','Security'],'Included security Apps remain distinct from optional installation');
  foreach(DSA\MCP\Guidance_Service::SECTIONS as$section){$view=$runRead($r,'mcp.context.read',['section'=>$section]);$check(is_array($view)&&count($view['fields'])<=8,'Actual native bounded context section '.$section);}
  foreach(['team','plannedPages','services']as$collection){$view=$runRead($r,'mcp.context.collection.read',['collection'=>$collection]);$check(is_array($view)&&count($view['items'])<=20,'Actual native collection projection '.$collection);}
  foreach(['seo.apiKey','about.founder.userId','pk_settings_v3','../../wp-config.php']as$path)$check(is_wp_error($runRead($r,'mcp.context.field.read',['path'=>$path])),'Native private/unknown field denied '.$path);
  foreach(['editor','subscriber','administrator']as$role){$login='kiwe_m03_'.$run.'_'.$mode.'_'.$role;$check(username_exists($login)===false,'Fresh native fixture identity '.$role);$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','display_name'=>'M03 synthetic '.$role,'user_pass'=>wp_generate_password(48,true,true),'role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Native fixture user refused.');$ownedUsers[$role]=(int)$id;$ledger('users',['ids'=>array_values($ownedUsers)]);}
  foreach(['publish','private','draft','password']as$status){$id=wp_insert_post(['post_type'=>'post','post_status'=>$status==='password'?'publish':$status,'post_title'=>'M03 synthetic '.$run.' '.$mode.' '.$status,'post_content'=>'Native guidance [m03-never-execute] content','post_author'=>$ownedUsers['editor'],'post_password'=>$status==='password'?'synthetic-password':''],true);if(is_wp_error($id))throw new RuntimeException('Native content fixture refused.');$ownedPosts[$status]=(int)$id;$ledger('posts',['ids'=>array_values($ownedPosts)]);}
  $count=0;add_shortcode('m03-never-execute',static function()use(&$count){$count++;return'Unexpected execution';});
  $post=$runRead($r,'mcp.content.read',['type'=>'post','id'=>$ownedPosts['publish']]);$check(is_array($post)&&str_contains($post['text'],'[m03-never-execute]')&&$count===0,'Native text read never executes embedded shortcodes');remove_shortcode('m03-never-execute');
  foreach(['private','draft','password']as$status)$check(is_wp_error($runRead($r,'mcp.content.read',['type'=>'post','id'=>$ownedPosts[$status]])),'Native protected object denied '.$status);
  wp_set_current_user($ownedUsers['editor']);$editorOps=$r->describe($r->scopes(),$r->freeze($r->scopes()));$check(!in_array('mcp.apps.read',array_column($editorOps,'id'),true)&&is_wp_error($runRead($r,'mcp.apps.read')),'Native editor lacks software-management reads');
  wp_set_current_user($ownedUsers['subscriber']);$check(!$r->describe($r->scopes())&&is_wp_error($runRead($r,'mcp.content.read',['type'=>'post','id'=>$ownedPosts['publish']])),'Native subscriber cannot turn a public content read into management access');
  wp_set_current_user($owner);$filter('pre_option_kiwe_seam_design_context_v1',['identity'=>['description'=>'M03 synthetic known business'],'seo'=>['primaryGoal'=>'leads','apiKey'=>'M03-NATIVE-PRIVATE-SENTINEL'],'about'=>['founder'=>['userId'=>0,'name'=>'M03 public founder','email'=>'M03-NATIVE-PRIVATE-SENTINEL']],'brand'=>['notes'=>'Keep existing design']]);
  $r=$registry();$known=$runRead($r,'mcp.context.field.read',['path'=>'identity.description']);$check(is_array($known)&&$known['field']['text']==='M03 synthetic known business','Native known context is prefilled rather than invented');
  $wire=wp_json_encode($runRead($r,'mcp.context.read',['section'=>'seo']));$check(!str_contains($wire,'M03-NATIVE-PRIVATE-SENTINEL'),'Native arbitrary private context keys are not serialized');
  // Three empty copies of the actual WordPress content tables prove presence
  // behavior without deleting or replacing the lab's authored content.
  $fixturePrefix=$nativePrefix.'kiwe_m03_'.substr($run,0,8).'_'.$mode.'_';
  foreach($properties as$key){$table=$fixturePrefix.$key;$check(preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)===1&&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===null,'Unused isolated native table '.$key);if($wpdb->query("CREATE TABLE `{$table}` LIKE `{$originalTables[$key]}`")===false)throw new RuntimeException('Isolated native table creation failed.');$ownedTables[]=$table;$ledger('tables',['names'=>$ownedTables]);}
  $freshActor=$ownedUsers['administrator'];update_user_meta($freshActor,$fixturePrefix.'capabilities',['administrator'=>true,'kiwe_manage_context'=>true]);
  foreach($properties as$key)$wpdb->$key=$fixturePrefix.$key;$wpdb->prefix=$fixturePrefix;
  $filter('pre_option_kiwe_seam_design_context_v1',[]);$filter('pre_option_fresh_site',1);wp_set_current_user(0);wp_set_current_user($freshActor);DSA\Onboarding\Design_Context_Journey_Service::invalidate();
  $r=$registry();$empty=$runRead($r,'mcp.site.read');$check(is_array($empty)&&$empty['state']==='fresh'&&$empty['inventoryComplete'],'Actual empty native SQL inventory and fresh marker permit a fresh welcome');
  $filter('pre_option_fresh_site',0);$empty=$runRead($r,'mcp.site.read');$check(is_array($empty)&&$empty['state']==='empty','Actual empty SQL inventory without fresh marker stays empty');
  foreach(['post','product','attachment','nav_menu_item','bricks_template']as$type){$ok=$wpdb->insert($wpdb->posts,['post_type'=>$type,'post_status'=>$type==='attachment'?'inherit':'publish','post_title'=>'M03 isolated '.$type,'post_content'=>'Synthetic native presence']);if(!$ok)throw new RuntimeException('Native presence fixture failed.');$id=(int)$wpdb->insert_id;DSA\Onboarding\Design_Context_Journey_Service::invalidate();$view=$runRead($r,'mcp.site.read');$check(is_array($view)&&$view['state']==='existing','Actual SQL presence takes priority '.$type);$wpdb->delete($wpdb->posts,['ID'=>$id],['%d']);}
  foreach($properties as$key)$wpdb->$key=$originalTables[$key];$wpdb->prefix=$nativePrefix;wp_set_current_user(0);wp_set_current_user($owner);
  foreach($fixtures as[$name,$fn])remove_filter($name,$fn,PHP_INT_MAX);$fixtures=[];DSA\Onboarding\Design_Context_Journey_Service::invalidate();
  if($mode==='plain'){
   $check(!function_exists('WC')&&!defined('BRICKS_VERSION')&&str_contains(DSA\MCP\Urls::resource(),'rest_route='),'Actual non-Woo/non-Bricks/default-permalink bootstrap');
   $check(!DSA\Apps\Store_App_Service::enabled(),'Actual Store is unavailable without Woo');
   $r=$registry();$reg=$runRead($r,'mcp.context.read',['section'=>'regulatory']);$check(is_array($reg)&&$reg['fields']===[]&&is_wp_error($runRead($r,'mcp.catalogue.read',['type'=>'product'])),'Actual non-Woo has no Store fields or product catalogue');
  }else $check(defined('BRICKS_VERSION')&&function_exists('WC'),'Actual existing Bricks and Woo bootstrap');
  return['passed'=>true,'nativeSql'=>true,'mode'=>$mode,'php'=>PHP_VERSION,'freshProof'=>'isolated native content tables and process-local context, not a new hosting installation','customerRecordsExported'=>false,'credentialsExported'=>false];
 }finally {
  foreach($properties as$key)$wpdb->$key=$originalTables[$key];$wpdb->prefix=$nativePrefix;
  foreach($fixtures as[$name,$fn])remove_filter($name,$fn,PHP_INT_MAX);
  wp_set_current_user(0);wp_set_current_user($owner);remove_shortcode('m03-never-execute');
  foreach($ownedPosts as$id)if(get_post($id)&&!wp_delete_post($id,true))throw new RuntimeException('Native fixture content cleanup failed.');
  require_once ABSPATH.'wp-admin/includes/user.php';foreach($ownedUsers as$id)if(get_userdata($id)&&!wp_delete_user($id))throw new RuntimeException('Native fixture account cleanup failed.');
  foreach($ownedTables as$table){if(!str_starts_with($table,$nativePrefix.'kiwe_m03_')||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table))throw new RuntimeException('Native table cleanup identity changed.');if($wpdb->query("DROP TABLE `{$table}`")===false)throw new RuntimeException('Native fixture table cleanup failed.');}
  foreach($hooks as$hook=>$original){if($original===null)unset($GLOBALS['wp_filter'][$hook]);else$GLOBALS['wp_filter'][$hook]=$original;}
  DSA\Onboarding\Design_Context_Journey_Service::invalidate();DSA\Onboarding\Design_Context_Journey_Service::projection();
  $check($snapshot()===$before,'Exact original native settings, Apps, dashboard and authentication choices survive');$check(hash_file('sha256',DSA_DIR.'includes/PhoneKey/phonekey-core.php')===$engine,'Exact frozen PhoneKey engine survives');
  foreach($ownedUsers as$id)$check(get_userdata($id)===false,'Owned native fixture account retired');foreach($ownedPosts as$id)$check(get_post($id)===null,'Owned native fixture content retired');
  foreach($ownedTables as$table)$check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===null,'Owned isolated native table retired');
  $check($wpdb->last_error==='','No final native SQL error');wp_set_current_user($initialActor);
 }
}
