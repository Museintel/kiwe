<?php
/** RC66 native acceptance; run only inside an explicitly authorized disposable CLI fixture wrapper. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Navigation_Workspace_Service as Navigation;
use DSA\Onboarding\Context_Selection_Service as Selection;
$proof=['schema'=>'kiwe-native-reactive-tasks/v1','kiwe'=>DSA_VERSION,'php'=>PHP_VERSION,'checks'=>[],'cleanup'=>false];
$fixtures=['users'=>[],'posts'=>[],'comments'=>[],'menus'=>[]];$filters=[];$actor=get_current_user_id();
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$must=static function($v){if(!$v||is_wp_error($v))throw new RuntimeException('Fixture creation failed');return $v;};
$filter=static function($hook,$callback)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX);$filters[]=[$hook,$callback];};
$filter('pre_wp_mail',static fn()=>false);$filter('pre_http_request',static fn()=>new WP_Error('kiwe_qa_network','No outbound fixture traffic'));$filter('pre_schedule_event',static fn()=>false);
$savedHooks=[];foreach(['kiwe_notification_event','dsa_admin_notification_event','comment_post','transition_post_status']as $hook){$savedHooks[$hook]=$GLOBALS['wp_filter'][$hook]??null;remove_all_actions($hook);}
$prefix='kiwe66qa_'.strtolower(wp_generate_password(8,false,false));
$policy=null;
try{
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty(DSA\Runtime\Package_Manifest::verify(true)['valid']),'matching explicitly expected native package');
 foreach(['client'=>'administrator','owner'=>'administrator','visitor'=>'subscriber']as $name=>$role)$fixtures['users'][$name]=(int)$must(wp_insert_user(['user_login'=>$prefix.$name,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$prefix.$name.'@example.invalid','role'=>$role]));
 // Resolve only this fixture’s owner; never change the stored ownership policy.
 $policy=static function()use(&$fixtures){return ['enabled'=>true,'ownerId'=>$fixtures['users']['owner'],'rankMath'=>'inline'];};
 $filter('pre_option_'.Owner::OPTION,$policy);wp_set_current_user($fixtures['users']['client']);
 $page=(int)$must(wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$prefix.' Page','post_author'=>$fixtures['users']['client']],true));$fixtures['posts'][]=$page;
 $menu=(int)$must(wp_create_nav_menu($prefix.' Menu'));$fixtures['menus'][]=$menu;
 $nav=new Navigation();$assert($nav->change($menu,['operation'=>'add','source_kind'=>'page','source_id'=>$page,'parent_id'=>0,'expected'=>Navigation::fingerprint($nav->items($menu))])===true,'native menu fixture created');
 $item=(int)$nav->items($menu)[0]->ID;$fingerprint=Navigation::fingerprint($nav->items($menu));$originalSlug=get_post_field('post_name',$item,'raw');
 $dispatch=static function($route,$body){$r=new WP_REST_Request('POST',$route);$r->set_body_params($body);return rest_do_request($r);};
 $route='/dsa/v1/workspace/menus/'.$menu.'/items/'.$item.'/label';
 $r=$dispatch($route,['label'=>'A new label','expected'=>$fingerprint,'id'=>999999,'item'=>999999,'operation'=>'remove']);
 $assert($r->get_status()===200&&$r->get_data()['itemId']===$item&&get_post_field('post_title',$item,'raw')==='A new label'&&get_post_field('post_name',$item,'raw')===$originalSlug,'native REST scope and label-only writer');
 $assert(($r->get_headers()['Cache-Control']??'')==='no-store, private','private native menu response');
 $assert($dispatch($route,['label'=>'Stale','expected'=>$fingerprint])->get_status()===409,'native menu stale conflict');
 $r=$dispatch($route,['label'=>'','expected'=>$r->get_data()['expected']]);$assert($r->get_status()===200&&$r->get_data()['title']===$prefix.' Page','native label follows source after clearing');
 $media=(int)$must(wp_insert_attachment(['post_title'=>$prefix.' Document','post_mime_type'=>'application/pdf','post_status'=>'inherit','post_author'=>$fixtures['users']['client']],false,0,true));$fixtures['posts'][]=$media;
 $mediaRoute='/dsa/v1/workspace/context/media/'.$media.'/selection';$r=$dispatch($mediaRoute,['selected'=>true,'expected'=>false]);
 $assert($r->get_status()===200&&Selection::selected('media',$media),'native attachment selection readback');
 $assert($dispatch($mediaRoute,['selected'=>false,'expected'=>false])->get_status()===409&&Selection::selected('media',$media),'native selection stale conflict');
 $assert($dispatch($mediaRoute,['selected'=>['bad'],'expected'=>true])->get_status()===400,'native REST schema rejects invalid selection');
 $comment=(int)$must(wp_insert_comment(['comment_post_ID'=>$page,'comment_content'=>'Synthetic customer words.','comment_author'=>'QA public name','comment_approved'=>1]));$fixtures['comments'][]=$comment;
 $r=$dispatch('/dsa/v1/workspace/context/testimonial/'.$comment.'/selection',['selected'=>true,'expected'=>false]);$assert($r->get_status()===200&&Selection::selected('testimonial',$comment),'native testimonial membership');
 wp_set_current_user($fixtures['users']['visitor']);$assert($dispatch($mediaRoute,['selected'=>false,'expected'=>true])->get_status()===403,'native visitor cannot change selection');
 wp_set_current_user(0);$assert(in_array($dispatch($mediaRoute,['selected'=>false,'expected'=>true])->get_status(),[401,403],true),'native anonymous cannot change selection');
}catch(Throwable $e){$proof['error']=['class'=>get_class($e),'message'=>$e->getMessage(),'line'=>$e->getLine()];}
finally{
 if($policy)remove_filter('pre_option_'.Owner::OPTION,$policy,PHP_INT_MAX);wp_set_current_user(0);
 foreach($fixtures['comments']as $id)wp_delete_comment($id,true);foreach($fixtures['menus']as $id)wp_delete_nav_menu($id);foreach($fixtures['posts']as $id)wp_delete_post($id,true);
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($fixtures['users']as $id)wp_delete_user($id);
 $proof['cleanup']=!array_filter($fixtures['posts'],'get_post')&&!array_filter($fixtures['comments'],'get_comment')&&!array_filter($fixtures['menus'],'wp_get_nav_menu_object')&&!array_filter($fixtures['users'],'get_userdata');
 foreach($filters as [$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);foreach($savedHooks as $hook=>$value){if($value)$GLOBALS['wp_filter'][$hook]=$value;else unset($GLOBALS['wp_filter'][$hook]);}wp_set_current_user($actor);
}
$proof['passed']=!isset($proof['error'])&&$proof['cleanup'];return $proof;
