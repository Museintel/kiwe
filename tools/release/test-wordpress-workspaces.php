<?php
/** Real WordPress fixture proof. CLI only; run in an explicitly authorized test installation. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Category_Workspace_Service as Categories;
use DSA\Access\Team_Workspace_Service as Teams;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Onboarding\User_Profile_Service as People;
use DSA\Onboarding\Design_Context_Profile_Service as Context;

$proof=['schema'=>'kiwe-wordpress-workspace-proof/v1','php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'kiwe'=>DSA_VERSION,'checks'=>[],'cleanup'=>false];
$fixtures=['users'=>[],'posts'=>[],'terms'=>[],'menus'=>[]];$policyFilter=null;
$assert=static function(bool $ok,string $label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>$ok];if(!$ok)throw new RuntimeException($label);};
$must=static function($value){if(is_wp_error($value)||!$value)throw new RuntimeException('Synthetic fixture creation failed');return $value;};
// No external message or HTTP delivery is part of fixture acceptance.
$mailFilter=static fn()=>false;$httpFilter=static fn()=>new WP_Error('kiwe_qa_no_network','External requests disabled during synthetic fixture proof.');
add_filter('pre_wp_mail',$mailFilter,PHP_INT_MAX);add_filter('pre_http_request',$httpFilter,PHP_INT_MAX);
$scheduleFilter=static fn()=>false;add_filter('pre_schedule_event',$scheduleFilter,PHP_INT_MAX);
$savedHooks=[];foreach(['kiwe_notification_event','dsa_admin_notification_event','transition_post_status']as $hook){$savedHooks[$hook]=$GLOBALS['wp_filter'][$hook]??null;remove_all_actions($hook);}
$originalActor=get_current_user_id();$prefix='kiwe_qa_'.strtolower(wp_generate_password(10,false,false));
try{
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION,'matching explicitly expected candidate runtime');
 $assert(!empty(DSA\Runtime\Package_Manifest::verify(true)['valid']),'installed package integrity');
 $assert((bool)has_action('admin_post_kiwe_category_move')&&(bool)has_action('admin_post_kiwe_team_save'),'workspace write handlers registered');
 foreach(['client'=>'administrator','owner'=>'administrator','staff'=>'author','visitor'=>'subscriber']as $name=>$role){$id=$must(wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','display_name'=>'Kiwe QA '.ucfirst($name),'role'=>$role]));$fixtures['users'][$name]=(int)$id;}
 $policyFilter=static fn()=>['enabled'=>true,'ownerId'=>$fixtures['users']['owner'],'rankMath'=>'inline'];
 add_filter('pre_option_'.Owner::OPTION,$policyFilter,PHP_INT_MAX);
 wp_set_current_user($fixtures['users']['client']);
 $assert(current_user_can('kiwe_manage_team')&&!current_user_can('manage_options'),'real client capabilities remain bounded');
 $categories=new Categories();$teams=new Teams();
 foreach(['source','destination','other']as $name){$id=$must($categories->create_category(['name'=>$prefix.' '.$name]));$fixtures['terms'][$name]=$id;}
 $source=$fixtures['terms']['source'];$target=$fixtures['terms']['destination'];$other=$fixtures['terms']['other'];
 $originalTerm=get_term($source,'category');$slug=$originalTerm->slug;
 $menu=$must(wp_create_nav_menu($prefix));$fixtures['menus'][]=(int)$menu;
 $defaultItem=$must(wp_update_nav_menu_item($menu,0,['menu-item-object-id'=>$source,'menu-item-object'=>'category','menu-item-type'=>'taxonomy','menu-item-status'=>'publish']));
 $customItem=$must(wp_update_nav_menu_item($menu,0,['menu-item-object-id'=>$source,'menu-item-object'=>'category','menu-item-type'=>'taxonomy','menu-item-title'=>'QA custom label','menu-item-status'=>'publish']));
 $expectedCategory=Categories::fingerprint($originalTerm);
 $assert($categories->rename_category($source,['name'=>$prefix.' renamed','slug'=>'injected','expected'=>$expectedCategory])===true,'native category rename succeeds with expected state');
 $stale=$categories->rename_category($source,['name'=>'Stale name','expected'=>$expectedCategory]);
 $assert(is_wp_error($stale)&&$stale->get_error_code()==='kiwe_category_stale'&&get_term($source,'category')->name===$prefix.' renamed','native category stale form cannot overwrite confirmed state');
 $assert(get_term($source,'category')->slug===$slug,'native permalink slug preserved');
 $menuItems=wp_get_nav_menu_items($menu);$labels=array_column($menuItems,'title','ID');
 $assert(($labels[$defaultItem]??'')===$prefix.' renamed','native default menu label follows category name');
 $assert(($labels[$customItem]??'')==='QA custom label','custom menu label preserved');
 $post=$must(wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>$prefix.' draft','post_author'=>$fixtures['users']['client'],'post_category'=>[$source,$other]],true));$fixtures['posts'][]=$post;
 $move=$categories->move_posts($source,$target,[$post]);$ids=array_map('intval',wp_get_object_terms($post,'category',['fields'=>'ids']));
 $assert(!is_wp_error($move)&&$move['moved']===1&&in_array($other,$ids,true)&&in_array($target,$ids,true)&&!in_array($source,$ids,true),'real post move preserves unrelated relationships');
 $expectedTeam=Teams::fingerprint($fixtures['users']['staff']);
 $input=['team_member'=>'1','team_title'=>'QA Studio lead','team_order'=>'3','expected'=>$expectedTeam];
 $assert($teams->save_member($fixtures['users']['staff'],$input)===true,'real staff team details save');
 $assert(People::is_team_member($fixtures['users']['staff']),'native team selection is visible immediately');
 $memberProjection=$teams->member($fixtures['users']['staff']);
 $assert(!is_wp_error($memberProjection)&&array_keys($memberProjection['profile']??[])===['wordpress','kiwe','woocommerce']&&array_keys($memberProjection['profile']['wordpress']??[])===['firstName','lastName','nickname','email','website','bio','locale','username','registered']&&array_keys($memberProjection['profile']['kiwe']??[])===['teamTitle','teamMember','teamOrder','founderTitle','linkedin','facebook','avatarUrl']&&array_keys($memberProjection['profile']['woocommerce']??[])===['billing','shipping'],'real profile projection separates WordPress, Kiwe and WooCommerce fields');
 $assert(!isset($memberProjection['password'])&&!isset($memberProjection['meta'])&&!isset($memberProjection['profile']['wordpress']['user_pass']),'real profile projection excludes credentials, arbitrary metadata and plugin fields');
 $stale=$teams->save_member($fixtures['users']['staff'],array_replace($input,['team_title'=>'Stale title']));
 $assert(is_wp_error($stale)&&$stale->get_error_code()==='kiwe_team_stale'&&get_user_meta($fixtures['users']['staff'],Context::USER_META_TEAM_TITLE,true)==='QA Studio lead','native team stale form cannot overwrite confirmed state');
 $input['expected']=Teams::fingerprint($fixtures['users']['staff']);
 $method=new ReflectionMethod(Context::class,'resolve_people');$context=$method->invoke(new Context(),['about'=>[]]);
 $person=array_values(array_filter($context['about']['team']['members'],static fn($p)=>(int)$p['userId']===$fixtures['users']['staff']))[0]??[];
 $assert(($person['title']??'')==='QA Studio lead'&&!isset($person['email'])&&!isset($person['phone']),'public context reads current native team metadata without contact credentials');
 $assert(is_wp_error($teams->save_member($fixtures['users']['owner'],$input)),'client cannot change owner public team fields');
 $assert(is_wp_error($teams->save_member($fixtures['users']['visitor'],$input)),'subscriber cannot be selected as staff');
 $listing=$teams->listing(['team_search'=>'Kiwe QA']);$assert(in_array($fixtures['users']['staff'],array_column($listing['users'],'ID'),true),'real bounded WP user query finds eligible staff');
 $assert($teams->save_member($fixtures['users']['staff'],array_replace($input,['team_member'=>'0']))===true&&!People::is_team_member($fixtures['users']['staff']),'removing a team member invalidates native metadata caches');
 wp_set_current_user($fixtures['users']['visitor']);
 $assert(is_wp_error($categories->rename_category($source,['name'=>'unauthorized']))&&is_wp_error($teams->save_member($fixtures['users']['staff'],$input)),'visitor mutations rejected on real WordPress');
}catch(Throwable $error){$proof['error']='Fixture proof failed; inspect named checks in private test evidence.';}
finally{
 if($policyFilter)remove_filter('pre_option_'.Owner::OPTION,$policyFilter,PHP_INT_MAX);
 wp_set_current_user($originalActor);
 require_once ABSPATH.'wp-admin/includes/user.php';
 foreach($fixtures['menus']as $id)wp_delete_nav_menu($id);
 foreach($fixtures['posts']as $id){$post=get_post($id);if($post&&str_starts_with($post->post_title,$prefix))wp_delete_post($id,true);}
 foreach($fixtures['terms']as $id){$term=get_term($id,'category');if($term&&!is_wp_error($term)&&str_starts_with($term->name,$prefix))wp_delete_term($id,'category');}
 foreach($fixtures['users']as $id){$user=get_userdata($id);if($user&&str_starts_with($user->user_login,$prefix))wp_delete_user($id);}
 $proof['cleanup']=!array_filter($fixtures['users'],'get_userdata')&&!array_filter($fixtures['posts'],'get_post')&&!array_filter($fixtures['terms'],static fn($id)=>get_term($id,'category'));
 remove_filter('pre_wp_mail',$mailFilter,PHP_INT_MAX);remove_filter('pre_http_request',$httpFilter,PHP_INT_MAX);
 remove_filter('pre_schedule_event',$scheduleFilter,PHP_INT_MAX);foreach($savedHooks as $hook=>$value){if($value)$GLOBALS['wp_filter'][$hook]=$value;else unset($GLOBALS['wp_filter'][$hook]);}
}
$proof['passed']=!isset($proof['error'])&&$proof['cleanup'];
return $proof;
