<?php
declare(strict_types=1);
require __DIR__.'/test-client-workspace.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Defaults_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Output_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Page_Workspace_Service.php';
use DSA\Onboarding\SEO_Context_Service as SEO;
use DSA\Access\Page_Workspace_Service as Pages;

function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function get_post($id){return $GLOBALS['test_posts'][$id]??false;}
function get_post_status($id){return get_post($id)->post_status??'publish';}
function get_queried_object_id(){return $GLOBALS['query_page']??11;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['post_meta'][$id][$key]??'';}
function update_post_meta($id,$key,$v){$GLOBALS['post_meta'][$id][$key]=$v;return true;}
function wp_insert_post($data,$error=false){$GLOBALS['inserted']=$data;$id=100+count($GLOBALS['test_posts']);$GLOBALS['test_posts'][$id]=(object)(['ID'=>$id]+$data);return $id;}
function wp_slash($data){return is_array($data)?array_map('wp_slash',$data):(is_string($data)?addslashes($data):$data);}
function wp_update_post($data,$error=false){
 $GLOBALS['page_update_calls']=($GLOBALS['page_update_calls']??0)+1;
 $GLOBALS['page_updated']=$data;
 if(!empty($GLOBALS['page_write_fail']))return new WP_Error('write_failed','Synthetic write failure.');
 if(empty($GLOBALS['page_write_silent']))foreach($data as $key=>$value)$GLOBALS['test_posts'][$data['ID']]->$key=is_string($value)?stripslashes($value):$value;
 return $data['ID'];
}
function do_action($name,...$args){$GLOBALS['actions'][]=[$name,$args];}
function is_page(){return true;}
function is_singular(){return true;}
function add_query_arg($a,$b,$c=null){return $c??$b;}

$multisite=false;$actor=1; // Previous tests transferred ownership to 2: 1 is client Admin.
$post_meta=[];$test_posts=[11=>(object)['ID'=>11,'post_type'=>'page','post_status'=>'publish','post_title'=>'Original','post_name'=>'original-url','post_content'=>'Keep content','post_author'=>2],12=>(object)['ID'=>12,'post_type'=>'bricks_template','post_status'=>'publish']];
$pages=new Pages();$n=0;
check(current_user_can('edit_pages'),'native Pages list is accessible');
check($access->post_type_capabilities([],'page')['capabilities']['create_posts']==='create_pages'&&!current_user_can('create_pages'),'native REST XML-RPC page creation cannot use list capability');
check($access->content_boundary(['edit_pages'],'edit_post',1,[11])===['edit_pages'],'client Page design remains available through native Bricks authority');
$before=clone $test_posts[11];
$created=$pages->create_draft(['page_title'=>'<b>About us</b>','indexing'=>'noindex','ID'=>11,'post_type'=>'bricks_template','post_author'=>2,'post_status'=>'publish','post_content'=>'INJECTED','meta_input'=>['_bricks_page_content_2'=>'INJECTED']]);
check(is_int($created)&&$created!==11,'creation never edits submitted ID');
check($inserted===['post_type'=>'page','post_title'=>'About us','post_content'=>'','post_status'=>'draft','post_author'=>1],'draft ignores forged content type author status and metadata');
check(SEO::page_noindex($created),'draft indexing choice saved');
check($test_posts[11]==$before,'existing page remains unchanged');
check(is_wp_error($pages->create_draft(['page_title'=>str_repeat('a',201),'indexing'=>'index'])),'server enforces title length');
check(is_wp_error($pages->create_draft(['page_title'=>'Next','indexing'=>'invalid'])),'invalid indexing cannot create page');
check($pages->save_indexing(11,'noindex')===true&&SEO::page_noindex(11),'client can noindex without edit_post');
check($pages->save_indexing(11,'index')===true&&!SEO::page_noindex(11),'client can allow indexing');
check(is_wp_error($pages->save_indexing(12,'index')),'template indexing cannot be changed');
check($test_posts[11]==$before,'indexing changes no content title status or author');
$_POST=['page_id'=>11,'indexing'=>'noindex'];check(denies(fn()=>$pages->handle_indexing()),'indexing requires page-bound nonce');
$_POST=['page_title'=>'CSRF','indexing'=>'index'];check(denies(fn()=>$pages->handle_create()),'creation requires nonce');
$actor=3;
check(is_wp_error($pages->create_draft(['page_title'=>'Unauthorized','indexing'=>'index'])),'frontend user cannot create pages');
check(is_wp_error($pages->save_indexing(11,'noindex')),'frontend user cannot change indexing');
$actor=4;check(is_wp_error($pages->save_indexing(11,'noindex')),'author cannot change page indexing');
$actor=1;define('RANK_MATH_VERSION','test');
$test_posts[13]=(object)['ID'=>13,'post_type'=>'post','post_status'=>'publish','post_title'=>'Rank Math story','post_password'=>''];
$post_meta[13]['rank_math_robots']=['nofollow','nosnippet','index'];
check(SEO::set_object_indexing(13,true)===true&&$post_meta[13]['rank_math_robots']===['nofollow','nosnippet','noindex']&&SEO::object_noindex(13),'Post indexing changes only Rank Math index directive');
$seo_post=new DSA\SEO\SEO_Output_Service(new DSA\Onboarding\Design_Context_Profile_Service());
check($seo_post->rankmath_sitemap_entry(['loc'=>'story'],'post',$test_posts[13])===false,'Rank Math sitemap excludes a noindex post');
check(SEO::set_object_indexing(13,false)===true&&$post_meta[13]['rank_math_robots']===['nofollow','nosnippet','index'],'Post indexing restores Rank Math index while preserving other directives');
$post_meta[11]['rank_math_robots']=['index','nofollow','nosnippet'];
check($pages->save_indexing(11,'noindex')===true&&$post_meta[11]['rank_math_robots']===['nofollow','nosnippet','noindex'],'Rank Math updates only index directive');
check($pages->save_indexing(11,'index')===true&&$post_meta[11]['rank_math_robots']===['nofollow','nosnippet','index'],'Rank Math preserves other directives');
$seo=new DSA\SEO\SEO_Output_Service(new DSA\Onboarding\Design_Context_Profile_Service());
$post_meta[11]['rank_math_robots']=['noindex'];$seo->sync_rankmath_indexing(1,11,'rank_math_robots',[]);
check($post_meta[11][DSA\Onboarding\Design_Context_Profile_Service::PAGE_VISIBILITY_META]==='secondary','external Rank Math changes synchronize native sitemap mirror');
check($seo->rankmath_robots(['index'=>'index','follow'=>'nofollow'])===['index'=>'noindex','follow'=>'nofollow'],'Rank Math frontend honors noindex');
check($seo->rankmath_sitemap_entry(['loc'=>'test'],'post',$test_posts[11])===false,'Rank Math sitemap excludes noindex page');
check($seo->robots(['index'=>true])===['index'=>true],'native robots yield unchanged while Rank Math owns output');
unset($post_meta[11]['rank_math_robots']);$seo->sync_rankmath_indexing(1,11,'rank_math_robots',[]);
check(!SEO::page_noindex(11),'removing native SEO choice clears legacy mirror');

$actor=1;
check(current_user_can('kiwe_manage_page_titles')&&!current_user_can('edit_post',11),'page-title authority does not grant the native page editor');
$payload=['page_title'=>"<b>Client's story</b>",'expected_title'=>'Original','post_content'=>'forged','post_status'=>'draft','post_author'=>1,'post_name'=>'forged','meta_input'=>['_bricks_page_content_2'=>'forged']];
check($pages->rename_page(11,$payload)===true&&$test_posts[11]->post_title==="Client's story",'client renames an existing page and apostrophes survive');
check(array_keys($page_updated)===['ID','post_title','post_name']&&$page_updated['post_name']==='original-url','title mutation preserves slug and ignores forged fields');
$preserved=clone $test_posts[11];$preserved->post_title='Original';
check($preserved==$before,'title save preserves layout content status author and URL');
$calls=$page_update_calls;
check(is_wp_error($pages->rename_page(11,$payload))&&$page_update_calls===$calls,'stale title form does not overwrite a newer name');
check($pages->rename_page(11,['page_title'=>"Client's story",'expected_title'=>"Client's story"])===true&&$page_update_calls===$calls,'unchanged title does not write');
foreach([[],str_repeat('x',201),"\xFF",'   ']as $bad)check(is_wp_error($pages->rename_page(11,['page_title'=>$bad,'expected_title'=>"Client's story"])),'invalid title payload is rejected');
check(is_wp_error($pages->rename_page(12,['page_title'=>'Template','expected_title'=>'Original'])),'title authority cannot rename a Bricks template');
$page_write_fail=true;check(is_wp_error($pages->rename_page(11,['page_title'=>'Next','expected_title'=>"Client's story"])),'core write failure is reported');$page_write_fail=false;
$page_write_silent=true;check(is_wp_error($pages->rename_page(11,['page_title'=>'Next','expected_title'=>"Client's story"])),'unconfirmed write is reported');$page_write_silent=false;
$_SERVER['REQUEST_METHOD']='GET';check(denies(fn()=>$pages->handle_title()),'page title form requires POST');
$_SERVER['REQUEST_METHOD']='POST';$_POST=['page_id'=>11,'page_title'=>'Next','expected_title'=>"Client's story"];
check(denies(fn()=>$pages->handle_title()),'page title form requires a page-bound nonce');
$actor=3;check(is_wp_error($pages->rename_page(11,['page_title'=>'Next','expected_title'=>"Client's story"])),'visitor cannot rename pages');
$actor=4;check(is_wp_error($pages->rename_page(11,['page_title'=>'Next','expected_title'=>"Client's story"])),'author cannot rename pages');
$actor=1;
echo "$n native page and indexing tests passed.\n";

// Optional executable vendor proof, never shipped or copied into the MU plugin.
$vendor=$argv[1]??'';
if($vendor&&is_file($vendor.'/includes/capabilities.php')){
 define('BRICKS_DB_CAPABILITIES_PERMISSIONS','bricks_capabilities_permissions');
 define('BRICKS_DB_TEMPLATE_SLUG','bricks_template');
 require $vendor.'/includes/capabilities.php';
 require $vendor.'/includes/builder-permissions.php';
 $users[1]->allcaps=['administrator'=>true,'bricks_full_access'=>true,'bricks_execute_code'=>true];
 $users[1]->caps=['administrator'=>true,'bricks_full_access'=>true];
 Bricks\Capabilities::set_user_capabilities();
 check(Bricks\Capabilities::$full_access,'vendor fixture reproduces raw-role grant despite filtered WP caps');
 $access->enforce_bricks_boundary();
 check(!Bricks\Capabilities::$full_access&&Bricks\Capabilities::$no_access&&!Bricks\Capabilities::$execute_code,'Kiwe overrides vendor cached raw grants');
 check(!Bricks\Capabilities::current_user_can_use_builder(10),'actual Bricks denies client builder on editable post');
 check(!Bricks\Capabilities::current_user_can_use_builder(11),'actual Bricks denies client builder on page');
 $actor=2;check(Bricks\Capabilities::current_user_can_use_builder(),'actual Bricks permits Super Admin');
 echo "Bricks source integration proof passed.\n";
}
