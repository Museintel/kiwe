<?php
/** Native WordPress proof; explicit CLI test-site and candidate version are required. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Page_Workspace_Service as Pages;
use DSA\Access\Navigation_Workspace_Service as Navigation;
use DSA\Access\Context_Workspace_Service as Library;
use DSA\Onboarding\Context_Selection_Service as Selection;
use DSA\Onboarding\Design_Context_Profile_Service as Profile;

$proof=['schema'=>'kiwe-wordpress-context-navigation-proof/v1','php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'kiwe'=>DSA_VERSION,'checks'=>[],'cleanup'=>false];
$fixtures=['users'=>[],'posts'=>[],'comments'=>[],'terms'=>[],'menus'=>[]];$filters=[];
$originalActor=get_current_user_id();$prefix='kiwe58qa_'.strtolower(wp_generate_password(8,false,false));
$assert=static function(bool $ok,string $name)use(&$proof){$proof['checks'][]=['label'=>$name,'passed'=>$ok];if(!$ok)throw new RuntimeException($name);};
$must=static function($value){if(!$value||is_wp_error($value))throw new RuntimeException('Native fixture creation failed');return $value;};
$filter=static function(string $hook,$callback,int $args=1)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
// Restrict side effects to this process and these fixtures. No external deliveries or scheduled work.
$filter('pre_wp_mail',static fn()=>false);
$filter('pre_http_request',static fn()=>new WP_Error('kiwe_qa_network','No network during fixture proof.'));
$filter('pre_schedule_event',static fn()=>false);
$savedHooks=[];
foreach(['kiwe_notification_event','dsa_admin_notification_event','comment_post','transition_post_status']as $hook){$savedHooks[$hook]=$GLOBALS['wp_filter'][$hook]??null;remove_all_actions($hook);}
try{
 $assert(KIWE_WORKSPACE_EXPECTED_VERSION==='8.0.0-rc.58'&&DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION,'explicit matching candidate');
 $assert(!empty(DSA\Runtime\Package_Manifest::verify(true)['valid']),'installed package integrity');
 foreach(['client'=>'administrator','owner'=>'administrator','visitor'=>'subscriber']as $name=>$role)$fixtures['users'][$name]=(int)$must(wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','display_name'=>'Kiwe QA '.$name,'role'=>$role]));
 $filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$fixtures['users']['owner'],'rankMath'=>'inline']);
 wp_set_current_user($fixtures['users']['client']);
 $assert(current_user_can('kiwe_manage_navigation')&&current_user_can('kiwe_manage_context')&&!current_user_can('manage_options')&&!current_user_can('edit_theme_options'),'native client capabilities isolate technical settings');
 $page=(int)$must(wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$prefix.' Page','post_content'=>'Synthetic integration fixture.','post_author'=>$fixtures['users']['client']],true));$fixtures['posts'][]=$page;
 $original=get_post($page);update_post_meta($page,'_bricks_page_content_2',[['id'=>'fixture','name'=>'text','settings'=>['text'=>'Preserve fixture design']]]);$design=get_post_meta($page,'_bricks_page_content_2',true);
 $pages=new Pages();$nav=new Navigation();$selection=new Selection();$library=new Library();$reader=new DSA\Site_Graph\Data_Query_Service();
 $assert($pages->rename_page($page,['page_title'=>$prefix.' Renamed','expected_title'=>$original->post_title])===true,'Kiwe page name writes native title');
 $assert(get_post($page)->post_name===$original->post_name&&get_post_meta($page,'_bricks_page_content_2',true)===$design,'page URL and Bricks metadata remain intact');
 $assert(!current_user_can('edit_post',$page),'name authority does not grant generic page writes');
 $term=$must(wp_insert_term($prefix.' Category','category'));$category=(int)$term['term_id'];$fixtures['terms'][]=$category;
 $menu=(int)$must(wp_create_nav_menu($prefix.' Menu'));$fixtures['menus'][]=$menu;
 $fingerprint=static fn()=>Navigation::fingerprint($nav->items($menu));
 $assert($nav->change($menu,['operation'=>'add','source_kind'=>'page','source_id'=>$page,'parent_id'=>'0','expected'=>$fingerprint()])===true,'Kiwe adds native page menu entry');
 $items=$nav->items($menu);$item=(int)$items[0]->ID;
 $assert((int)$items[0]->object_id===$page&&$items[0]->type==='post_type','native menu points to original page');
 $assert(!current_user_can('edit_post',$item),'menu adapter does not grant generic menu-item writes');
 $must(wp_update_post(['ID'=>$page,'post_title'=>$prefix.' Native edit'],true));
 $assert($nav->items($menu)[0]->title===$prefix.' Native edit','native page edit appears in Kiwe navigation');
 $assert($nav->change($menu,['operation'=>'label','item_id'=>$item,'label'=>'QA custom label','expected'=>$fingerprint()])===true&&get_post_field('post_title',$item,'raw')==='QA custom label','custom label writes native menu post');
 $must(wp_update_post(['ID'=>$page,'post_title'=>$prefix.' Another native edit'],true));
 $assert($nav->items($menu)[0]->title==='QA custom label','native source rename respects deliberate custom label');
 $assert($nav->change($menu,['operation'=>'label','item_id'=>$item,'label'=>'','expected'=>$fingerprint()])===true&&$nav->items($menu)[0]->title===$prefix.' Another native edit','clearing override reconnects native source label');
 $assert($nav->change($menu,['operation'=>'add','source_kind'=>'category','source_id'=>$category,'parent_id'=>$item,'expected'=>$fingerprint()])===true,'native category can be placed under an existing link');
 $items=$nav->items($menu);$child=(int)$items[1]->ID;
 $assert(is_wp_error($nav->change($menu,['operation'=>'remove','item_id'=>$item,'expected'=>$fingerprint()])),'native parent link is protected while it has children');
 $stale=$fingerprint();$must(wp_update_post(['ID'=>$child,'post_title'=>'Native category label'],true));
 $assert(is_wp_error($nav->change($menu,['operation'=>'remove','item_id'=>$child,'expected'=>$stale])),'native menu edits invalidate stale Kiwe forms');
 $assert($nav->change($menu,['operation'=>'remove','item_id'=>$child,'expected'=>$fingerprint()])===true&&get_term($category,'category')&&!get_post($child),'removing link retains original category');
 $media=[];
 foreach(['pdf'=>'application/pdf','audio'=>'audio/mpeg']as $extension=>$mime){$id=(int)$must(wp_insert_attachment(['post_title'=>$prefix.' '.$extension,'post_status'=>'inherit','post_mime_type'=>$mime,'post_parent'=>$page,'guid'=>home_url('/'.$prefix.'.'.$extension)],false,$page,true));$fixtures['posts'][]=$id;$media[]=$id;}
 $query=static fn()=> $reader->query(['resource'=>'media','mimeType'=>'','contextOnly'=>true,'include'=>$media],false)['data'];
 $assert($query()===[],'ordinary native media is not automatically design evidence');
 foreach($media as $id)$assert($selection->select('media',$id,true)===true,'native nonimage attachment can be selected');
 $assert(count($query())===2&&get_post_meta($media[0],Selection::MEDIA_META,true)==='1','selection metadata controls bounded public media projection');
 $must(wp_update_post(['ID'=>$media[0],'post_title'=>$prefix.' Native file edit'],true));
 $nodes=$query();$node=array_values(array_filter($nodes,static fn($n)=>(int)$n['id']===$media[0]))[0]??[];
 $assert(($node['title']??'')===$prefix.' Native file edit','native media title edit is reflected without copying the record');
 $must(wp_update_post(['ID'=>$page,'post_password'=>'synthetic-fixture-password'],true));
 $assert($query()===[],'native parent password excludes previously selected media');
 $must(wp_update_post(['ID'=>$page,'post_password'=>''],true));
 $assert(count($query())===2,'public media reappears when native visibility is restored');
 $assert($selection->select('media',$media[0],false)===true&&get_post($media[0])&&count($query())===1,'deselection retains original attachment and removes only its context membership');
 $comment=(int)$must(wp_insert_comment(['comment_post_ID'=>$page,'comment_author'=>'QA Public Author','comment_author_email'=>$prefix.'@example.invalid','comment_content'=>'A helpful synthetic studio.','comment_approved'=>1,'comment_type'=>'comment']));$fixtures['comments'][]=$comment;
 $assert($selection->select('testimonial',$comment,true)===true,'approved native comment can be selected');
 $find=static fn()=>array_values(array_filter(Selection::testimonials(100),static fn($c)=>(int)$c['id']===$comment));
 $quote=$find()[0]??[];$assert(($quote['quote']??'')==='A helpful synthetic studio.'&&!isset($quote['email'])&&!isset($quote['user_id']),'testimonial projection includes original words without private account fields');
 $must(wp_update_comment(['comment_ID'=>$comment,'comment_content'=>'Updated in native WordPress.'],true));
 $assert(($find()[0]['quote']??'')==='Updated in native WordPress.','native comment edits immediately update selected evidence');
 $must(wp_set_comment_status($comment,'hold'));
 $assert($find()===[],'native moderation revocation removes testimonial evidence');
 $must(wp_set_comment_status($comment,'approve'));
 $assert(count($find())===1,'native reapproval restores selected quote');
 if(function_exists('WC')){
  $product=(int)$must(wp_insert_post(['post_type'=>'product','post_title'=>$prefix.' Product','post_status'=>'publish','post_author'=>$fixtures['users']['owner']],true));$fixtures['posts'][]=$product;
  $review=(int)$must(wp_insert_comment(['comment_post_ID'=>$product,'comment_author'=>'QA Reviewer','comment_content'=>'Useful synthetic product.','comment_approved'=>1,'comment_type'=>'review']));$fixtures['comments'][]=$review;update_comment_meta($review,'rating','4');
  $assert($selection->select('testimonial',$review,true)===true,'real Woo review uses native comment selection');
  $reviews=array_values(array_filter(Selection::testimonials(100),static fn($c)=>(int)$c['id']===$review));
  $assert(($reviews[0]['rating']??0)===4&&($reviews[0]['source']??'')==='woocommerce-review','Woo rating is read from original review metadata');
 }
 $assert($library->listing('media',['search'=>$prefix,'view'=>'all'])['items']!==[],'native attachment search feeds Design library');
 $filter('pre_option_blogdescription',static fn()=>'Synthetic native tagline');
 $assert((new Profile())->current()['identity']['tagline']==='Synthetic native tagline','native WordPress value wins over retained profile snapshot');
 if(function_exists('WC')){$filter('pre_option_woocommerce_weight_unit',static fn()=>'lbs');$assert((new Profile())->current()['commerce']['weightUnit']==='lbs','Woo native option wins over retained context snapshot');}
 wp_set_current_user($fixtures['users']['visitor']);
 $assert(is_wp_error($selection->select('media',$media[0],true))&&is_wp_error($nav->items($menu))&&is_wp_error($pages->rename_page($page,[])),'visitor cannot use client mutations');
}catch(Throwable $error){$proof['error']=['class'=>get_class($error),'line'=>$error->getLine(),'stage'=>'native fixture proof'];}
finally{
 foreach(array_reverse($filters)as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);
 // Keep delivery and schedules blocked until deletion finishes.
 $noMail=static fn()=>false;$noHttp=static fn()=>new WP_Error('kiwe_qa_network');$noSchedule=static fn()=>false;
 add_filter('pre_wp_mail',$noMail,PHP_INT_MAX);add_filter('pre_http_request',$noHttp,PHP_INT_MAX);add_filter('pre_schedule_event',$noSchedule,PHP_INT_MAX);
 wp_set_current_user($originalActor);require_once ABSPATH.'wp-admin/includes/user.php';
 foreach($fixtures['menus']as$id)wp_delete_nav_menu($id);
 foreach($fixtures['comments']as$id)wp_delete_comment($id,true);
 foreach(array_reverse($fixtures['posts'])as$id){$post=get_post($id);if($post&&str_starts_with($post->post_title,$prefix))'attachment'===$post->post_type?wp_delete_attachment($id,true):wp_delete_post($id,true);}
 foreach($fixtures['terms']as$id){$term=get_term($id,'category');if($term&&!is_wp_error($term)&&str_starts_with($term->name,$prefix))wp_delete_term($id,'category');}
 foreach($fixtures['users']as$id){$user=get_userdata($id);if($user&&str_starts_with($user->user_login,$prefix))wp_delete_user($id);}
 $proof['cleanup']=!array_filter($fixtures['users'],'get_userdata')&&!array_filter($fixtures['posts'],'get_post')&&!array_filter($fixtures['comments'],'get_comment')&&!array_filter($fixtures['menus'],'wp_get_nav_menu_object')&&!array_filter($fixtures['terms'],static fn($id)=>get_term($id,'category'));
 foreach($savedHooks as$hook=>$value){remove_all_actions($hook);if($value)$GLOBALS['wp_filter'][$hook]=$value;}
 remove_filter('pre_wp_mail',$noMail,PHP_INT_MAX);remove_filter('pre_http_request',$noHttp,PHP_INT_MAX);remove_filter('pre_schedule_event',$noSchedule,PHP_INT_MAX);
}
$proof['passed']=!isset($proof['error'])&&$proof['cleanup'];
$proof['limitations']=['Synthetic records and process-local ownership policy; no persistent policy change.','Notification provider delivery and browser interactions require separate acceptance.'];
return $proof;
