<?php
/** Narrow native WordPress proof of the first shared workspace task. Never included in the MU package. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Page_Workspace_Service as Pages;
use DSA\Access\Workspace_Admin_Service as Home;
$proof=['schema'=>'kiwe-native-workspace-app/v1','kiwe'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>false];
$actor=get_current_user_id();$level=ob_get_level();$prefix='kiwe65qa_'.strtolower(wp_generate_password(8,false,false));$users=[];$posts=[];$filters=[];$savedHooks=[];
$assert=static function($ok,$name)use(&$proof){$proof['checks'][]=['label'=>$name,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($name);};
$must=static function($v){if(!$v||is_wp_error($v))throw new RuntimeException('Fixture creation failed');return $v;};
$filter=static function($hook,$callback)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX);$filters[]=[$hook,$callback];};
$filter('pre_wp_mail',static fn()=>false);$filter('pre_http_request',static fn()=>new WP_Error('kiwe_qa_network','Synthetic test has no outbound requests.'));$filter('pre_schedule_event',static fn()=>false);
// Fixture creation must not inherit a site's existing Guest-approval policy.
// The real restricted policy below owns every behavior assertion and cleanup stays isolated.
$filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>false]);
foreach(['kiwe_notification_event','dsa_admin_notification_event','transition_post_status']as $hook){$savedHooks[$hook]=$GLOBALS['wp_filter'][$hook]??null;remove_all_actions($hook);}
$policy=static function()use(&$users){return ['enabled'=>true,'ownerId'=>$users['owner'],'rankMath'=>'inline'];};
try{
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty(DSA\Runtime\Package_Manifest::verify(true)['valid']),'matching installed package integrity');
 foreach(['client'=>'administrator','owner'=>'administrator','author'=>'author','guest'=>'contributor','visitor'=>'subscriber']as $name=>$role)$users[$name]=(int)$must(wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(40,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','display_name'=>'Synthetic '.$name,'role'=>$role]));
 add_filter('pre_option_'.Owner::OPTION,$policy,PHP_INT_MAX);
 wp_set_current_user($users['client']);
 $id=(int)$must(wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>$prefix.' Original','post_content'=>'Preserve native content','post_author'=>$users['client']],true));$posts[]=$id;
 update_post_meta($id,'_bricks_page_content_2',[['id'=>'fixture','name'=>'text','settings'=>['text'=>'Preserve layout']]]);$before=get_post($id);$design=get_post_meta($id,'_bricks_page_content_2',true);
 $request=static function($method,$params)use($id){$r=new WP_REST_Request($method,'/dsa/v1/workspace/pages/'.$id.'/name');$r->set_body_params($params);return rest_do_request($r);};
 $assert(current_user_can('kiwe_manage_page_titles')&&!current_user_can('edit_post',$id),'client has title authority without the native page editor');
 $r=$request('POST',['page_title'=>"Client's studio",'expected_title'=>$before->post_title,'post_content'=>'forged','post_name'=>'forged']);
 $assert($r->get_status()===200&&$r->get_data()['title']==="Client's studio"&&($r->get_headers()['Cache-Control']??'')==='no-store, private','native REST dispatch confirms a private canonical title readback');
 $after=get_post($id);$assert($after->post_name===$before->post_name&&$after->post_content===$before->post_content&&get_post_meta($id,'_bricks_page_content_2',true)===$design,'REST preserves native URL content and Bricks metadata');
 $assert($request('POST',['page_title'=>'Stale','expected_title'=>$before->post_title])->get_status()===409&&get_post($id)->post_title==="Client's studio",'native REST rejects a stale form');
 $assert($request('POST',['page_title'=>str_repeat('a',201),'expected_title'=>"Client's studio"])->get_status()===400,'WordPress validates the endpoint title schema');
 $assert($request('POST',['page_title'=>['array'],'expected_title'=>"Client's studio"])->get_status()===400,'WordPress rejects a nonstring title');
 foreach(['author','guest','visitor']as $role){wp_set_current_user($users[$role]);$assert($request('POST',['page_title'=>'Denied','expected_title'=>"Client's studio"])->get_status()===403,'native REST denies '.$role);}
 wp_set_current_user(0);$assert($request('POST',['page_title'=>'Anonymous','expected_title'=>"Client's studio"])->get_status()===401,'anonymous REST needs authentication');
 wp_set_current_user($users['client']);$home=new Home();ob_start();$home->render_dashboard();$home->navigation();$html=ob_get_clean();
 $assert(str_contains($html,'Your workspace')&&str_contains($html,'Design library')&&str_contains($html,'Workspace navigation'),'native client Home and navigation render fully');
 require_once ABSPATH.'wp-admin/includes/admin.php';require_once ABSPATH.'wp-admin/includes/dashboard.php';set_current_screen('dashboard');
 $home->dashboard();ob_start();do_meta_boxes('dashboard','normal',null);$nativeHome=ob_get_clean();
 $assert(str_contains($nativeHome,'data-kiwe-workspace')&&str_contains($nativeHome,'Your workspace'),'registered Home survives native dashboard meta-box rendering');
 foreach(['author','guest']as $role){wp_set_current_user($users[$role]);ob_start();$home->render_dashboard();$html=ob_get_clean();$assert(!str_contains($html,'Content at a glance')&&str_contains($html,'Your workspace'),'native '.$role.' Home omits global content counters');}
 wp_set_current_user($users['owner']);ob_start();$home->render_dashboard();$html=ob_get_clean();$assert($html==='','technical owner keeps native dashboard');
 wp_set_current_user($users['client']);$_GET=['page_id'=>$id];ob_start();(new Pages())->render();$html=ob_get_clean();
 $assert(str_contains($html,'data-kiwe-page-name')&&str_contains($html,'name="_wpnonce"')&&str_contains($html,'data-kiwe-save-state')&&str_contains($html,'kiwe_page_indexing'),'native full page task renders both enhancement and form fallback');
 // The existing lab administrator can inspect this named draft in a browser after this isolated proof.
 remove_filter('pre_option_'.Owner::OPTION,$policy,PHP_INT_MAX);
 $admins=get_users(['role'=>'administrator','exclude'=>array_values($users),'number'=>1,'fields'=>'ID']);
 $assert((bool)$admins,'existing lab administrator available for browser task');
 wp_set_current_user((int)$admins[0]);
 $browser=(int)$must(wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Kiwe RC65 browser fixture','post_content'=>'Synthetic page for workspace acceptance.','post_author'=>(int)$admins[0]],true));
 $proof['browserFixture']=['id'=>$browser,'originalTitle'=>'Kiwe RC65 browser fixture','slug'=>get_post($browser)->post_name,'deleteAfterBrowser'=>true];
}catch(Throwable $e){$proof['error']=['class'=>get_class($e),'line'=>$e->getLine(),'message'=>$e->getMessage()];}
finally{
 while(ob_get_level()>$level)ob_end_clean();
 remove_filter('pre_option_'.Owner::OPTION,$policy,PHP_INT_MAX);wp_set_current_user(0);
 foreach($posts as $id)wp_delete_post($id,true);
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $id)wp_delete_user($id);
 $proof['cleanup']=!array_filter($posts,'get_post')&&!array_filter($users,'get_userdata');
 foreach($filters as [$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);
 foreach($savedHooks as $hook=>$value){if($value)$GLOBALS['wp_filter'][$hook]=$value;else unset($GLOBALS['wp_filter'][$hook]);}
 wp_set_current_user($actor);
}
$proof['passed']=!isset($proof['error'])&&$proof['cleanup']&&!in_array(false,array_column($proof['checks'],'passed'),true);
return $proof;
