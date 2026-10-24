<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Context_Selection_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Site_Graph/Data_Query_Service.php';
if(!defined('DSA_OPTION_SETTINGS'))define('DSA_OPTION_SETTINGS','dsa_settings');
use DSA\Onboarding\Context_Selection_Service as Selection;
use DSA\Access\Context_Workspace_Service as Library;
use DSA\Access\Navigation_Workspace_Service as Navigation;

// Synthetic I/O exercises the real services and real Kiwe capability/route policy.
$postmeta=[];$commentmeta=[];$comments=[];$menuitems=[];$events=[];$filters=[];$fail_write=false;
function get_post_type_object($type){return (object)['publicly_queryable'=>in_array($type,['post','page','product'],true)];}
function get_post_meta($id,$key,$single=true){return $GLOBALS['postmeta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){if($GLOBALS['fail_write'])return false;$GLOBALS['writes'][]=[$id,$key,$value];$GLOBALS['postmeta'][$id][$key]=$value;if($key==='_menu_item_menu_item_parent'&&isset($GLOBALS['posts'][$id]))$GLOBALS['posts'][$id]->menu_item_parent=$value;return true;}
function delete_post_meta($id,$key){if($GLOBALS['fail_write'])return false;unset($GLOBALS['postmeta'][$id][$key]);return true;}
function get_comment($id){return $GLOBALS['comments'][$id]??null;}
function get_comment_meta($id,$key,$single=true){return $GLOBALS['commentmeta'][$id][$key]??'';}
function update_comment_meta($id,$key,$value){if($GLOBALS['fail_write'])return false;$GLOBALS['commentmeta'][$id][$key]=$value;return true;}
function delete_comment_meta($id,$key){if($GLOBALS['fail_write'])return false;unset($GLOBALS['commentmeta'][$id][$key]);return true;}
function get_comments($args){$GLOBALS['comment_query']=$args;return array_slice(array_values(array_filter($GLOBALS['comments'],fn($c)=>empty($args['meta_key'])||get_comment_meta($c->comment_ID,$args['meta_key'])==='1')),0,$args['number']);}
function wp_strip_all_tags($s){return strip_tags($s);}
function esc_url_raw($s){return $s;} function get_comment_link($c){return 'https://example.test/story/#comment-'.$c->comment_ID;}
function do_action($hook,...$args){$GLOBALS['events'][]=[$hook,...$args];if(isset($GLOBALS['after_test_action']))($GLOBALS['after_test_action'])($hook,...$args);}
function wp_json_encode($value){return json_encode($value);}
function wp_slash($value){return $value;}
function wp_get_nav_menu_object($id){return in_array($id,[70,71],true)?(object)['term_id'=>$id,'taxonomy'=>'nav_menu','name'=>$id===70?'Primary menu':'Visitor menu']:($GLOBALS['created_menus'][$id]??false);}
function wp_get_nav_menus($args=[]){return array_values(array_filter([wp_get_nav_menu_object(70),wp_get_nav_menu_object(71),...array_values($GLOBALS['created_menus']??[])]));}
function wp_create_nav_menu($name){$GLOBALS['created_menus'][72]=(object)['term_id'=>72,'taxonomy'=>'nav_menu','name'=>$name];return 72;}
function wp_update_nav_menu_object($id,$args){if($GLOBALS['fail_write']||!wp_get_nav_menu_object($id))return new WP_Error('db');$GLOBALS['created_menus'][$id]->name=$args['menu-name'];return $id;}
function wp_delete_nav_menu($id){if($GLOBALS['fail_write']||!wp_get_nav_menu_object($id))return false;unset($GLOBALS['created_menus'][$id],$GLOBALS['menuitems'][$id]);return true;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function get_nav_menu_locations(){return ['header'=>70];}
function get_registered_nav_menus(){return ['header'=>'Header navigation'];}
function apply_filters($hook,$value,...$args){return $value;}
function wp_get_nav_menu_items($id,$args){$GLOBALS['menu_query']=$args;return array_values($GLOBALS['menuitems'][$id]??[]);}
function get_post_field($key,$id,$context=''){return get_post($id)->$key??'';}
function wp_update_post($args,$error=false){if($GLOBALS['fail_write'])return new WP_Error('db');$GLOBALS['last_post_write']=$args;foreach($args as $k=>$v)$GLOBALS['posts'][$args['ID']]->$k=$v;return $args['ID'];}
function wp_update_nav_menu_item($menu,$id,$args){$GLOBALS['last_menu_write']=$args;if($GLOBALS['fail_write'])return new WP_Error('db');$GLOBALS['menuitems'][$menu][900]=newitem(900,$args['menu-item-object'],$args['menu-item-object-id'],$args['menu-item-parent-id']);return 900;}
function wp_set_object_terms($id,$terms,$taxonomy,$append=false){if($GLOBALS['fail_write'])return new WP_Error('db');foreach($GLOBALS['menuitems'] as &$items)unset($items[$id]);$GLOBALS['menuitems'][$terms[0]][$id]=$GLOBALS['posts'][$id];return $terms;}
function is_object_in_term($id,$taxonomy,$menu){return isset($GLOBALS['menuitems'][$menu][$id]);}
function wp_delete_post($id,$force){if($GLOBALS['fail_write'])return false;$post=get_post($id);unset($GLOBALS['posts'][$id]);foreach($GLOBALS['menuitems'] as &$items)unset($items[$id]);return $post;}
function add_filter($hook,$callback,$priority=10,$args=1){$GLOBALS['filters'][$hook][]=$callback;}
function remove_filter($hook,$callback,$priority=10){$GLOBALS['filters'][$hook]=array_values(array_filter($GLOBALS['filters'][$hook]??[],fn($f)=>$f!==$callback));}
function wp_reset_postdata(){}
function newpost($id,$type='attachment',$status='inherit',$parent=0,$password=''){
 return $GLOBALS['posts'][$id]=(object)['ID'=>$id,'post_type'=>$type,'post_status'=>$status,'post_parent'=>$parent,'post_password'=>$password,'post_title'=>'Native '.$id,'post_name'=>'native-'.$id];
}
function newitem($id,$object='page',$source=301,$parent=0,$label=''){
 $post=newpost($id,'nav_menu_item','publish');$post->post_title=$label;
 foreach(['menu_order'=>3,'menu_item_parent'=>$parent,'type'=>$object==='custom'?'custom':($object==='category'?'taxonomy':'post_type'),'object'=>$object,'object_id'=>$source,'url'=>'https://example.test/native/','title'=>$label?:'Native source','target'=>'_blank','classes'=>['preserve'],'xfn'=>'nofollow','description'=>'Preserve description','attr_title'=>'Preserve attribute'] as $k=>$v)$post->$k=$v;
 return $post;
}

$options[\DSA\Onboarding\Context_Media_Service::INDEX_OPTION]=['done'=>true];
$selection=new Selection();$library=new Library();$nav=new Navigation();
newpost(301,'page','publish');newpost(302,'page','draft');newpost(303,'page','publish',0,'password');newpost(304,'internal','publish');
foreach([201,202,203,204,205,206] as $id)newpost($id);
$posts[202]->post_parent=302;$posts[203]->post_parent=303;$posts[204]->post_status='private';$posts[205]->post_parent=304;
$posts[201]->post_mime_type='application/pdf';$posts[206]->post_mime_type='audio/mpeg';
check($selection->select('media',201,true)===true&&$selection->select('media',206,true)===true,'PDF and audio are eligible without image-only restriction');
check(array_keys($postmeta[201])===[Selection::MEDIA_META],'curation writes only selection metadata');
$before=count($writes);check($selection->select('media',201,true)===true&&count($writes)===$before,'repeating selection is a successful no-op');
foreach([202,203,204,205,301,999] as $id)check(is_wp_error($selection->select('media',$id,true)),'private draft protected internal nonmedia and missing objects are excluded: '.$id);
$denied=['edit_post:201'];check(is_wp_error($selection->select('media',201,false)),'media object edit denial is enforced');$denied=[];
$actor=3;check(is_wp_error($selection->select('media',201,true))&&is_wp_error($nav->items(70)),'author cannot curate context or edit navigation');$actor=1;
$fail_write=true;check(is_wp_error($selection->select('media',201,false)),'failed metadata removal is reported');$fail_write=false;
$posts[201]->post_parent=302;check(!Selection::public_media($posts[201])&&$selection->select('media',201,false)===true,'revoked public media is excluded and can still be deselected');$posts[201]->post_parent=0;
foreach([401,402,403,404,405] as $id)$comments[$id]=(object)['comment_ID'=>$id,'comment_post_ID'=>301,'comment_approved'=>'1','comment_type'=>'comment','comment_content'=>'<p>Helpful studio.</p>','comment_author'=>'Public name','comment_author_email'=>'private@example.test','comment_author_IP'=>'192.0.2.1','user_id'=>91];
$comments[402]->comment_approved='0';$comments[403]->comment_type='order_note';$comments[404]->comment_post_ID=302;
check($selection->select('testimonial',401,true)===true,'approved public comment can become a testimonial');
foreach([402,403,404] as $id)check(is_wp_error($selection->select('testimonial',$id,true)),'unapproved operational and private comments cannot be selected: '.$id);
$projected=Selection::testimonials();check(count($projected)===1&&$projected[0]['quote']==='Helpful studio.','only selected public comments enter live projection');
check(!str_contains(json_encode($projected),'private@example')&&!str_contains(json_encode($projected),'192.0.2')&&!isset($projected[0]['user_id']),'testimonials exclude email IP and account identifiers');
$comments[401]->comment_content=str_repeat('word ',600);$excerpt=Selection::testimonials()[0];check(strlen($excerpt['quote'])===2000&&$excerpt['isExcerpt']===true,'long quotes are bounded and disclosed as excerpts');$comments[401]->comment_content='Helpful studio.';
$comments[401]->comment_approved='spam';check(Selection::testimonials()===[],'moderation revocation removes selected quote on next projection');$comments[401]->comment_approved='1';
$posts[301]->post_password='private';check(Selection::testimonials()===[],'password protecting parent removes selected quote');$posts[301]->post_password='';
newpost(310,'product','publish');$comments[405]->comment_post_ID=310;$comments[405]->comment_type='review';
check(is_wp_error($selection->select('testimonial',405,true)),'Woo-disabled product reviews are excluded');
eval('function WC(){return true;}');$commentmeta[405]['rating']='5';
check($selection->select('testimonial',405,true)===true,'Woo review can be explicitly selected when Woo is present');
$quote=array_values(array_filter(Selection::testimonials(),fn($item)=>$item['id']===405))[0];check($quote['rating']===5&&$quote['source']==='woocommerce-review','review keeps genuine native rating and provenance');
$denied=['moderate_comments'];check(is_wp_error($selection->select('testimonial',405,false))&&is_wp_error($library->listing('testimonial')),'curation requires comment moderation authority');$denied=[];
$query_posts=array_fill(0,17,$posts[206]);$list=$library->listing('media',['view'=>'all','result_page'=>2,'number'=>10000,'mime'=>'image']);
check(count($list['items'])===16&&$list['has_more']&&$post_query['posts_per_page']===17&&$post_query['offset']===16&&!isset($post_query['post_mime_type']),'large libraries are bounded and accept all native file types');
$library->listing('media');check(!isset($post_query['meta_key']),'Media opens all native files by default');
$library->listing('media',['view'=>'selected']);check(!empty($post_query['kiwe_context_media'])&&!isset($post_query['meta_key']),'selected media view uses shared explicit and inherited membership predicate');
$library->listing('testimonial');check($comment_query['number']===17&&$comment_query['meta_key']===Selection::TESTIMONIAL_META,'testimonial library is bounded and selected by default');
$query_posts=[];$reader=new DSA\Site_Graph\Data_Query_Service();
$reader->query(['resource'=>'media','mimeType'=>'','contextOnly'=>true,'include'=>[201,206]],false);
check(!empty($post_query['kiwe_context_media'])&&$post_query['post__in']===[201,206]&&$post_query['kiwe_public_media']===true,'context selection intersects requested IDs and public visibility');
$reader->query(['resource'=>'media','mimeType'=>'','contextOnly'=>false],false);check(!isset($post_query['meta_query'])&&$post_query['post_mime_type']==='','ordinary public catalogue remains independently accessible');
$query_throw=true;try{$reader->query(['resource'=>'media','search'=>'studio'],false);}catch(RuntimeException $e){}$query_throw=false;
check(empty($filters['posts_where'])&&empty($filters['posts_search']),'failed media queries clean up both scoped SQL filters');

$menuitems[70]=[501=>newitem(501),502=>newitem(502,'custom',0,0,'Contact')];$menuitems[71]=[503=>newitem(503)];
$options[DSA_OPTION_SETTINGS]=['dock'=>['menu_nav_ids'=>[71]]];$menus=$nav->menu_list();check($menus[0]['id']===71&&$menus[0]['usedByDsa']===true&&$menus[1]['themeLocations']===['Header navigation'],'DSA-connected menu is selected first while theme locations remain read-only context');
$group_fresh=$nav->group_expected();
check(is_wp_error($nav->configure_groups([71,70],[71=>12,70=>1],$group_fresh)),'existing links cannot be silently hidden by lowering group limit');
check($nav->configure_groups([71,70],[71=>12,70=>12],$group_fresh)===true,'two named WordPress menus can be visible in deliberate order');
check($nav->menu_list()[1]['id']===70&&$options[DSA_OPTION_SETTINGS]['dock']['menu_nav_ids']===[71,70],'ordered DSA groups read back from native settings');
check(is_wp_error($nav->configure_groups([70,71],[], $group_fresh)),'stale group configuration is refused');
$created=$nav->create_group('More to explore',$nav->group_expected());
check($created===72&&in_array(72,$options[DSA_OPTION_SETTINGS]['dock']['menu_nav_ids'],true),'new WordPress menu joins DSA groups through native settings');
check(is_wp_error($nav->create_group('More to explore',$nav->group_expected())),'duplicate group name is refused');
$author_menu_expected=Navigation::fingerprint($nav->items(72));$actor=3;check(is_wp_error($nav->rename_group(72,'Unauthorized',$nav->group_expected()))&&is_wp_error($nav->delete_group(72,$nav->group_expected(),$author_menu_expected)),'author cannot rename or delete visitor groups');$actor=1;
check(is_wp_error($nav->rename_group(72,'Explore',$group_fresh)),'stale group rename is refused');
check(is_wp_error($nav->rename_group(72,'Visitor menu',$nav->group_expected())),'duplicate group rename is refused');
check($nav->rename_group(72,'Explore',$nav->group_expected())===true&&wp_get_nav_menu_object(72)->name==='Explore','group rename updates the same WordPress menu');
check(is_wp_error($nav->delete_group(70,$nav->group_expected(),Navigation::fingerprint($nav->items(70)))),'theme-assigned menu cannot be deleted in client workspace');
$old_items=Navigation::fingerprint($nav->items(72));$menuitems[72]=[newitem(933,'page',301)];
check(is_wp_error($nav->delete_group(72,$nav->group_expected(),$old_items)),'new link invalidates stale group deletion');
check($nav->delete_group(72,$nav->group_expected(),Navigation::fingerprint($nav->items(72)))===true&&!wp_get_nav_menu_object(72)&&!in_array(72,$options[DSA_OPTION_SETTINGS]['dock']['menu_nav_ids'],true)&&!isset($options[DSA_OPTION_SETTINGS]['dock']['menu_group_limits'][72]),'confirmed group deletion removes native menu and its DSA selection');
$options[DSA_OPTION_SETTINGS]=['dock'=>['menu_nav_ids'=>[71]]];unset($created_menus);
$source_before=Navigation::fingerprint($nav->items(70));$target_before=Navigation::fingerprint($nav->items(71));
check(is_wp_error($nav->move_items(70,71,[501],$source_before,$target_before)),'move refuses a source already represented in the destination');
check(is_wp_error($nav->move_items(70,71,[502],'stale',$target_before)),'move refuses stale source snapshot');
$actor=3;check(is_wp_error($nav->move_items(70,71,[502],$source_before,$target_before)),'author cannot move visitor links');$actor=1;
$native_custom=clone $posts[502];check($nav->move_items(70,71,[502],$source_before,$target_before)===true,'menu category move retains native item identity');
check(!isset($menuitems[70][502])&&isset($menuitems[71][502])&&$posts[502]->url===$native_custom->url&&$posts[502]->classes===$native_custom->classes&&$posts[502]->menu_item_parent===0,'menu move preserves native URL and metadata while placing link at destination top level');
$menuitems[70][502]=$menuitems[71][502];unset($menuitems[71][502]);$posts[502]->menu_order=$native_custom->menu_order;
$fresh=function()use(&$menuitems){return Navigation::fingerprint(array_values($menuitems[70]));};
$before=clone $posts[501];
check($nav->change(70,['operation'=>'label','item_id'=>501,'expected'=>$fresh(),'label'=>'<b>Our story</b>','url'=>'https://invalid.test/','parent_id'=>503])===true,'menu label changes through dedicated authority');
check(array_keys($last_post_write)===['ID','post_title','post_name']&&$posts[501]->url===$before->url&&$posts[501]->classes===$before->classes&&$posts[501]->menu_item_parent===$before->menu_item_parent,'label edit preserves permalink hierarchy and metadata despite injected fields');
check($nav->change(70,['operation'=>'label','item_id'=>501,'expected'=>$fresh(),'label'=>''])===true&&$posts[501]->post_title==='','blank native label restores following source name');
check(is_wp_error($nav->change(70,['operation'=>'label','item_id'=>502,'expected'=>$fresh(),'label'=>''])),'custom links cannot lose their required label');
check(is_wp_error($nav->change(70,['operation'=>'remove','item_id'=>503,'expected'=>$fresh()])),'cross-menu deletion is rejected');
check(is_wp_error($nav->change(70,['operation'=>'remove','item_id'=>501,'expected'=>'stale'])),'stale menu form is rejected');
$menuitems[70][504]=newitem(504,'page',303,501);
check(is_wp_error($nav->change(70,['operation'=>'remove','item_id'=>501,'expected'=>$fresh()])),'parent with children cannot be removed');
check($nav->change(70,['operation'=>'remove','item_id'=>504,'expected'=>$fresh()])===true&&isset($posts[303]),'removing a leaf deletes only the menu entry');
check(is_wp_error($nav->change(70,['operation'=>'add','source_kind'=>'page','source_id'=>302,'expected'=>$fresh()])),'draft page cannot be added');
check(is_wp_error($nav->change(70,['operation'=>'add','source_kind'=>'page','source_id'=>301,'expected'=>$fresh()])),'duplicate native entry is rejected');
check(is_wp_error($nav->change(70,['operation'=>'add','source_kind'=>'category','source_id'=>10,'parent_id'=>503,'expected'=>$fresh()])),'new link cannot attach to another menus parent');
check($nav->change(70,['operation'=>'add','source_kind'=>'category','source_id'=>10,'parent_id'=>501,'expected'=>$fresh()])===true,'native category can be appended under selected menu parent');
check($last_menu_write['menu-item-position']===4&&$last_menu_write['menu-item-status']==='publish'&&$last_menu_write['menu-item-parent-id']===501,'native insertion is explicit and preserves existing hierarchy');
$fail_write=true;check(is_wp_error($nav->change(70,['operation'=>'label','item_id'=>501,'expected'=>$fresh(),'label'=>'Failed'])),'failed menu write never reports success');$fail_write=false;
check($menu_query['output']==='OBJECT'&&$menu_query['posts_per_page']===201&&!$menu_query['nopaging'],'menu query is bounded and preserves original positions');
$saved=$menuitems[70];$menuitems[70]=array_fill(0,201,$posts[501]);check(is_wp_error($nav->items(70)),'oversized menu refuses partial editing');$menuitems[70]=$saved;
check($access->content_boundary(['edit_posts'],'edit_post',1,[501])===['do_not_allow'],'dedicated navigation does not enable generic menu-item writes');
foreach([[Library::PAGE,Library::ACTION,$library],[Navigation::PAGE,Navigation::ACTION,$nav]] as [$page,$action,$service]){
 $pagenow='admin.php';$_GET=['page'=>$page];$_REQUEST=[];$actor=1;$access->guard_editorial_admin_route();check(true,'client route opens '.$page);
 $actor=3;check(denied(fn()=>$access->guard_editorial_admin_route()),'author route denied '.$page);$actor=1;
 $_POST=['action'=>$action,'kind'=>'media','record_id'=>'206','selected'=>'1','menu_id'=>'70'];$_SERVER['REQUEST_METHOD']='POST';check(denied(fn()=>$service->handle()),'mutation requires bound nonce '.$action);
 $_POST['_wpnonce']='wrong-object';check(denied(fn()=>$service->handle()),'foreign nonce rejected '.$action);
 $_SERVER['REQUEST_METHOD']='GET';check(denied(fn()=>$service->handle()),'GET mutation denied '.$action);
}
echo "$count context and navigation behavior checks passed.\n";
