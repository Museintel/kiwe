<?php
declare(strict_types=1);
require __DIR__ . '/workspace-test-bootstrap.php';
define('KIWE_REACT_WORKSPACE', !in_array('--disabled', $argv, true));

class WP_Post extends stdClass { public string $post_excerpt = ''; }
class WP_REST_Request {
	public function __construct(private string $method='GET', private array $url=[], private array $body=[], private array $query=[], private array $headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1']){}
	public function get_method(){return $this->method;}
	public function get_route(){$route='/dsa/v1/workspace/posts'.(isset($this->url['id'])?'/'.$this->url['id']:'');if(isset($this->url['terms']))return $route.'/terms';if(isset($this->url['autosave'])||isset($this->url['autosave_id']))return $route.'/autosave'.(isset($this->url['autosave_id'])?'/'.$this->url['autosave_id'].'/discard':'');return $route.(isset($this->url['revisions'])||isset($this->url['revision_id'])?'/revisions':'').(isset($this->url['revision_id'])?'/'.$this->url['revision_id'].'/restore':'');}
	public function get_url_params(){return $this->url;}
	public function get_json_params(){return $this->body;}
	public function get_query_params(){return $this->query;}
	public function get_param($key){return $this->url[$key]??$this->body[$key]??$this->query[$key]??null;}
	public function get_header($key){$headers=array_change_key_case($this->headers);return $headers[strtolower($key)]??'';}
}
class WP_REST_Response {
	public array $headers=[];
	public function __construct(public array $data, public int $status=200){}
	public function header($key,$value){$this->headers[$key]=$value;}
}
class WP_REST_Autosaves_Controller {
	public function __construct(private string $type){}
	public function create_post_autosave($data){$id=(int)$data['ID'];$old=wp_get_post_autosave($id,get_current_user_id());$rows=$GLOBALS['revisions']??[];$revision=$old?:new WP_Post;$revision->ID=$old?(int)$old->ID:7000+count($rows);$revision->post_parent=$id;$revision->post_type='revision';$revision->post_name=$id.'-autosave-v1';$revision->post_author=get_current_user_id();$revision->post_title=(string)$data['post_title'];$revision->post_content=(string)$data['post_content'];$revision->post_excerpt=(string)($data['post_excerpt']??'');$revision->post_modified_gmt='2026-09-11 00:30:'.count($rows);$GLOBALS['revisions'][$revision->ID]=$revision;return $revision->ID;}
}
function wp_json_encode($value){return json_encode($value);}
function get_post_meta($id,$key,$single=true){return $GLOBALS['post_meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['post_meta'][$id][$key]=$value;return true;}
function wp_autosave_post_revisioned_meta_fields($new_autosave){}
function wp_parse_url($value){return parse_url($value);}
function site_url($path=''){return home_url($path);}
function get_permalink($post){return home_url('/published-story/'.(int)(is_object($post)?$post->ID:$post));}
function esc_url_raw($url){return (string)$url;}
function wp_kses_post($value){return strip_tags((string)$value, '<p><strong><em><a><ul><ol><li><br>');}
function wp_slash($value){return is_array($value)?array_map('wp_slash',$value):(is_string($value)?addslashes($value):$value);}
function wp_timezone(){return new DateTimeZone('UTC');}
function wp_timezone_string(){return 'UTC';}
function current_time($type,$gmt=false){return gmdate('Y-m-d H:i:s');}
function wp_strip_all_tags($value){return strip_tags($value);}
function wp_get_post_revision($id){return $GLOBALS['revisions'][$id]??null;}
function wp_get_post_revisions($id,$args=[]){return array_values(array_filter($GLOBALS['revisions']??[],fn($revision)=>(int)$revision->post_parent===$id));}
function wp_is_post_autosave($revision){$revision=is_object($revision)?$revision:wp_get_post_revision((int)$revision);return $revision&&str_contains((string)($revision->post_name??''),'-autosave-v1')?(int)$revision->post_parent:false;}
function wp_get_post_autosave($id,$user=0){foreach($GLOBALS['revisions']??[]as$revision)if((int)$revision->post_parent===$id&&wp_is_post_autosave($revision)&&(!$user||(int)$revision->post_author===$user))return $revision;return false;}
function wp_delete_post_revision($id){if(!isset($GLOBALS['revisions'][$id]))return false;unset($GLOBALS['revisions'][$id]);return (object)['ID'=>$id];}
function wp_restore_post_revision($revision_id,$fields=null){$revision=wp_get_post_revision($revision_id);if(!$revision)return false;$post=$GLOBALS['posts'][$revision->post_parent]??null;if(!$post)return false;$data=[];foreach($fields as$field)$data[$field]=$revision->$field;$data['post_date']=gmdate('Y-m-d H:i:s');$data['post_date_gmt']='0000-00-00 00:00:00';$data=apply_filters('wp_insert_post_data',$data,['ID'=>$post->ID]);foreach($data as$field=>$value)$post->$field=$value;$post->post_modified_gmt=gmdate('Y-m-d H:i:s');return $post->ID;}
function add_action($name,$callback,...$args){$GLOBALS['post_hooks'][$name]=$callback;}
function add_filter($hook,$callback,$priority=10,$args=1){$GLOBALS['post_filters'][$hook][$priority][]=$callback;}
function remove_filter($hook,$callback,$priority=10){$GLOBALS['post_filters'][$hook][$priority]=array_values(array_filter($GLOBALS['post_filters'][$hook][$priority]??[],static fn($item)=>$item!==$callback));}
function apply_filters($hook,$value,...$args){$priorities=$GLOBALS['post_filters'][$hook]??[];ksort($priorities);foreach($priorities as$callbacks)foreach($callbacks as$callback)$value=$callback($value,...$args);return $value;}
function add_menu_page(...$args){$GLOBALS['post_menu']=$args;}
function register_rest_route($namespace,$path,$definition){$GLOBALS['post_routes'][$path]=$definition;}
function get_categories($args=[]){$GLOBALS['category_query']=$args;return array_values($GLOBALS['terms']);}
function get_tags($args=[]){$GLOBALS['tag_query']=$args;return array_values($GLOBALS['tag_terms']);}
function wp_get_post_categories($id,$args=[]){return $GLOBALS['relationships'][$id]??[];}
function wp_get_post_terms($id,$taxonomy,$args=[]){if($taxonomy!=='post_tag')return new WP_Error('taxonomy');return $GLOBALS['tag_relationships'][$id]??[];}
function wp_set_post_terms($id,$tags,$taxonomy,$append=false){if($taxonomy!=='post_tag'||!isset($GLOBALS['posts'][$id]))return new WP_Error('missing');if(($GLOBALS['fail_tag_writes']??0)>0){$GLOBALS['fail_tag_writes']--;return new WP_Error('db');}$GLOBALS['tag_relationships'][$id]=array_values(array_map('intval',(array)$tags));sort($GLOBALS['tag_relationships'][$id],SORT_NUMERIC);$GLOBALS['post_writes'][]=['tags',$id];return $GLOBALS['tag_relationships'][$id];}
function get_posts($args=[]){
	$GLOBALS['post_query']=$args;$items=array_values($GLOBALS['posts']);$statuses=(array)($args['post_status']??[]);
	$items=array_values(array_filter($items,static function($post)use($args,$statuses){
		if(($args['post_type']??'')!==$post->post_type||($statuses&&!in_array($post->post_status,$statuses,true)))return false;
		if(isset($args['author'])&&(int)$args['author']!==(int)$post->post_author)return false;
		$search=(string)($args['s']??'');return ''===$search||stripos($post->post_title.' '.$post->post_content,$search)!==false;
	}));usort($items,static fn($a,$b)=>strcmp((string)$b->post_modified_gmt,(string)$a->post_modified_gmt));
	$offset=(int)($args['offset']??0);$limit=(int)($args['posts_per_page']??12);return array_slice($items,$offset,$limit);
}
function get_post_thumbnail_id($id){return $GLOBALS['thumbnails'][$id]??0;}
function get_page_template_slug($id){return (string)get_post_meta($id,'_wp_page_template',true);}
function set_post_thumbnail($id,$image){$GLOBALS['thumbnails'][$id]=$image;}
function delete_post_thumbnail($id){unset($GLOBALS['thumbnails'][$id]);}
function wp_attachment_is_image($id){return $id===201;}
function wp_get_attachment_image_url($id,$size){return 'https://example.test/image.jpg';}
function wp_insert_post($data,$error=false){
	$id=500+count($GLOBALS['posts']);$post=new WP_Post;$post->ID=$id;$post->post_type=(string)($data['post_type']??'post');$post->post_status=(string)($data['post_status']??'draft');$post->post_title=wp_unslash((string)($data['post_title']??''));$post->post_content=wp_unslash((string)($data['post_content']??''));$post->post_author=(int)($data['post_author']??get_current_user_id());$post->post_date=(string)($data['post_date']??current_time('mysql'));$post->post_date_gmt=(string)($data['post_date_gmt']??current_time('mysql',true));$post->post_modified_gmt='2026-09-11 00:00:'.$id;$GLOBALS['posts'][$id]=$post;$GLOBALS['relationships'][$id]=array_values(array_map('intval',(array)($data['post_category']??[])));$GLOBALS['tag_relationships'][$id]=array_values(array_map('intval',(array)($data['tags_input']??[])));sort($GLOBALS['tag_relationships'][$id],SORT_NUMERIC);$GLOBALS['post_writes'][]=['insert',$id];return $id;
}
function wp_update_post($data,$error=false){
	$id=(int)($data['ID']??0);if(!$id||!isset($GLOBALS['posts'][$id]))return new WP_Error('missing','Missing post.');$post=$GLOBALS['posts'][$id];foreach(['post_title','post_content','post_status','post_date','post_date_gmt'] as $key)if(array_key_exists($key,$data))$post->$key=wp_unslash((string)$data[$key]);$post->post_modified_gmt='2026-09-11 00:01:'.count($GLOBALS['post_writes']);$GLOBALS['post_writes'][]=['update',$id];return $id;
}
function wp_set_post_categories($id,$categories,$append=false){if(!isset($GLOBALS['posts'][$id]))return new WP_Error('missing','Missing post.');$GLOBALS['relationships'][$id]=array_values(array_map('intval',(array)$categories));$GLOBALS['post_writes'][]=['categories',$id];return $GLOBALS['relationships'][$id];}

$kiwe_post_term_fixture=true;$tag_terms=[40=>(object)['term_id'=>40,'taxonomy'=>'post_tag','name'=>'Existing tag','slug'=>'existing-tag']];$tag_relationships=[101=>[40],102=>[],103=>[]];$fail_tag_writes=0;
foreach($posts as $id=>$post){$copy=new WP_Post;foreach((array)$post as $key=>$value)$copy->$key=$value;$copy->post_title=$id===101?'Published story':($id===102?'Draft story':'Product');$copy->post_content=$id===101?'<p>Published content</p>':'<p>Draft content</p>';$copy->post_author=$id===101?1:6;$copy->post_date='2026-09-10 00:02:00';$copy->post_date_gmt='2026-09-10 00:02:00';$copy->post_modified_gmt=$id===101?'2026-09-11 00:02:00':'2026-09-10 00:02:00';$posts[$id]=$copy;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Page_Publishing_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Media.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Guest_Contribution_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Post_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Post_App_Service.php';

use DSA\Access\Post_App_Service as App;
use DSA\Access\Post_Workspace_Service as Posts;

$app=new App();$app->register();$app->routes();
if(!KIWE_REACT_WORKSPACE){
	check(empty($post_hooks)&&empty($post_routes),'disabled app registers no document hook or REST route');
	check($app->listing(new WP_REST_Request())->status===403,'disabled listing refuses direct access');
	$pagenow='admin.php';$_GET=['page'=>Posts::PAGE];$_REQUEST=$_GET;try{$access->guard_editorial_admin_route();check(false,'disabled posts URL redirects back to the native editor');}catch(WorkspaceRedirect $redirect){check($redirect->getMessage()==='https://example.test/wp-admin/edit.php','disabled posts URL redirects back to the native editor');}
	exit;
}
check(count($post_routes)===8&&isset($post_hooks['load-toplevel_page_kiwe-posts']),'posts app registers bounded list, term and author search, item, revision and autosave routes');
$app->menu();check(isset($post_hooks['admin_menu'])&&$post_menu[2]==='edit_posts'&&$post_menu[3]===Posts::PAGE,'posts document has a capability-protected WordPress admin page');
$originalActor=$actor;$actor=1;$pagenow='edit.php';$_GET=['page'=>'kiwe-categories'];$_REQUEST=$_GET;$access->guard_editorial_admin_route();check(true,'category task is not redirected into the Posts app');$actor=$originalActor;
$list=$app->listing(new WP_REST_Request('GET',[],[],['page'=>1]));
check($list->status===200&&count($list->data['posts'])===2&&$list->data['has_next']===false,'list is private, bounded and native-author scoped');
check($list->data['posts'][0]['viewUrl']==='https://example.test/published-story/101'&&$list->data['posts'][1]['viewUrl']==='','only published WordPress posts expose their native public permalink');
check($app->listing(new WP_REST_Request('GET',[],[],['sort'=>'title_asc','author'=>6]))->data['posts'][0]['id']===102&&$post_query['author']===6&&$post_query['orderby']===['title'=>'ASC','ID'=>'ASC'],'reviewer can sort and filter by native author');
$query_users=[$users[6],$users[4]];$authors=$app->authors(new WP_REST_Request('GET',[],[],['search'=>'synthetic']));
check($authors->status===200&&array_column($authors->data['authors'],'id')===[6]&&$user_query['number']===20,'author lookup is capability-gated and bounded to editorial users');
unset($query_users);
$post_meta[102]['_kiwe_guest_submission']=['createdAt'=>'2026-09-25'];$guest=(new Posts())->post(102);$beforeGuestWrites=count($post_writes??[]);$guestBlocked=(new Posts())->save(102,[]);
check($guest['guestSubmission']===true&&is_wp_error($guestBlocked)&&$guestBlocked->get_error_code()==='kiwe_post_guest_review'&&count($post_writes??[])===$beforeGuestWrites,'Guest contribution is visible for routing but cannot bypass its own review write path');
unset($post_meta[102]['_kiwe_guest_submission']);
check($list->headers['Cache-Control']==='no-store, private'&&$category_query['number']===100&&$tag_query['number']===100&&$list->data['canCreateTags']===true,'list response and native category/tag queries are uncached and bounded');
$published=$list->data['posts'][0];check(array_keys($published)===['id','title','content','status','authorId','authorName','viewUrl','guestSubmission','canTrash','canExport','featuredImage','categories','tags','seo','modifiedGmt','scheduledLocal','expected','controls']&&$published['status']==='publish'&&$published['tags']===[40]&&$list->data['canCreateCategories']===true&&$list->data['seoOwner']==='kiwe','post projection includes native terms, public permalink and the active SEO authority');
$read=$app->read(new WP_REST_Request('GET',['id'=>101]));check($read->status===200&&$read->data['id']===101&&$read->data['authorId']===1,'read checks the native post object');
$termSearch=$app->terms(new WP_REST_Request('GET',['terms'=>true],[],['taxonomy'=>'category','search'=>'Category','post_id'=>101]));check($termSearch->status===200&&$termSearch->data['taxonomy']==='category'&&count($termSearch->data['terms'])===3&&$term_query['number']===20,'on-demand category search retains assigned native terms in a bounded response');
check($app->terms(new WP_REST_Request('GET',['terms'=>true],[],['taxonomy'=>'secret']))->status===400&&$app->terms(new WP_REST_Request('GET',['terms'=>true],[],['taxonomy'=>'category','post_id'=>103]))->status===403,'term search rejects unsupported taxonomy and inaccessible post');
$body=['title'=>'A new field note','content'=>'<p>Useful notes</p>','status'=>'draft','categories'=>[20],'newCategories'=>['Kitchen'],'tags'=>[40],'newTags'=>['Dispatch'],'seo'=>['title'=>'Field note SEO','description'=>'A useful field note.','focusKeyword'=>'field note']];$created=$app->create(new WP_REST_Request('POST',[],$body));check($created->status===200&&$created->data['title']==='A new field note'&&$created->data['categories']===[20,93]&&$created->data['tags']===[40,91],'new post writes native categories, selected tags and explicitly named new terms');
$id=$created->data['id'];check($created->data['seo']===$body['seo']&&($post_meta[$id]['_kiwe_seo_title']??'')==='Field note SEO'&&($post_meta[$id]['_kiwe_seo_description']??'')==='A useful field note.','post search details read back from the active native SEO fields');
check($posts[$id]->post_type==='post'&&$posts[$id]->post_author===1&&!isset($posts[$id]->password),'new post is owned by current WordPress user with no credential fields');
$updatedBody=['title'=>'A published field note','content'=>'<p>Published notes</p>','status'=>'publish','categories'=>[10,30],'tags'=>[40],'newTags'=>[],'expected'=>$created->data['expected']];$updated=$app->update(new WP_REST_Request('POST',['id'=>$id],$updatedBody));check($updated->status===200&&$updated->data['status']==='publish'&&$updated->data['categories']===[10,30]&&$updated->data['tags']===[40],'update publishes through native capability and confirms categories and tags');
check($app->update(new WP_REST_Request('POST',['id'=>$id],array_replace($updatedBody,['expected'=>'bad'])))->status===409,'stale post fingerprint cannot overwrite newer content');
$scheduleBody=array_replace($updatedBody,['status'=>'future','scheduledLocal'=>'2030-02-10T10:15','expected'=>$updated->data['expected']]);
$scheduled=$app->update(new WP_REST_Request('POST',['id'=>$id],$scheduleBody));
check($scheduled->status===200&&$scheduled->data['status']==='future'&&$scheduled->data['scheduledLocal']==='2030-02-10T10:15'&&$posts[$id]->post_date_gmt==='2030-02-10 10:15:00','site-local schedule reaches native post date and UTC date');
check($app->update(new WP_REST_Request('POST',['id'=>$id],$scheduleBody))->status===409,'stale schedule cannot overwrite a newer post');
foreach(['2030-02-30T10:15','2020-01-01T10:15','2030-02-10 10:15']as$date)check($app->create(new WP_REST_Request('POST',[],array_replace($body,['status'=>'future','scheduledLocal'=>$date])))->status===400,'invalid or past schedule is rejected before a write');
$denied=['publish_posts'];check($app->update(new WP_REST_Request('POST',['id'=>$id],array_replace($scheduleBody,['expected'=>$scheduled->data['expected']])))->status===403,'role without publishing capability cannot reschedule');$denied=[];
$nowBody=array_replace($scheduleBody,['status'=>'publish','scheduledLocal'=>'','expected'=>$scheduled->data['expected']]);
$now=$app->update(new WP_REST_Request('POST',['id'=>$id],$nowBody));
check($now->status===200&&$now->data['status']==='publish'&&$posts[$id]->post_date_gmt!=='2030-02-10 10:15:00','publish now clears the former future date');
$visibleBefore=[$posts[$id]->post_title,$posts[$id]->post_content,$posts[$id]->post_status,$posts[$id]->post_modified_gmt];
$autosaveBody=['title'=>'Quiet recovery title','content'=>'<p>Quiet recovery writing</p>','expected'=>$now->data['expected'],'autosaveExpected'=>''];
$automatic=$app->autosave(new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],$autosaveBody));
check($automatic->status===200&&$automatic->data['autosave']['title']==='Quiet recovery title'&&[$posts[$id]->post_title,$posts[$id]->post_content,$posts[$id]->post_status,$posts[$id]->post_modified_gmt]===$visibleBefore,'automatic recovery copy uses native autosave without changing the visible post');
check($app->autosave(new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],$autosaveBody))->status===409,'automatic recovery refuses to overwrite an unobserved autosave');
$autosaveBody['autosaveExpected']=$automatic->data['autosave']['expected'];$autosaveBody['content']='<p>Newer quiet recovery writing</p>';
$automaticUpdated=$app->autosave(new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],$autosaveBody));
check($automaticUpdated->status===200&&$automaticUpdated->data['autosave']['id']===$automatic->data['autosave']['id'],'observed current-writer autosave updates in place');
$discardBody=['expected'=>$automaticUpdated->data['expected'],'recoveryExpected'=>$automaticUpdated->data['autosave']['expected']];
check($app->discard_autosave(new WP_REST_Request('POST',['id'=>$id,'autosave_id'=>$automaticUpdated->data['autosave']['id']],array_replace($discardBody,['recoveryExpected'=>'bad'])))->status===409,'stale recovery proof cannot discard an autosave');
$discarded=$app->discard_autosave(new WP_REST_Request('POST',['id'=>$id,'autosave_id'=>$automaticUpdated->data['autosave']['id']],$discardBody));
check($discarded->status===200&&$discarded->data['autosave']===null&&!wp_get_post_autosave($id,1)&&[$posts[$id]->post_title,$posts[$id]->post_content,$posts[$id]->post_status,$posts[$id]->post_modified_gmt]===$visibleBefore,'reviewed recovery copy can be discarded without changing the visible post');
$excerptOnly=new WP_Post;$excerptOnly->ID=6000;$excerptOnly->post_type='revision';$excerptOnly->post_parent=$id;$excerptOnly->post_name=$id.'-autosave-v1';$excerptOnly->post_author=1;$excerptOnly->post_title=$posts[$id]->post_title;$excerptOnly->post_content=$posts[$id]->post_content;$excerptOnly->post_excerpt='An unsaved reader excerpt';$excerptOnly->post_modified_gmt='2026-09-11 00:31:00';$GLOBALS['revisions'][6000]=$excerptOnly;
$excerptVersions=$app->revisions(new WP_REST_Request('GET',['id'=>$id,'revisions'=>true]));
check(($excerptVersions->data['autosave']['id']??0)===6000,'excerpt-only native recovery is visible in the Post editor');
$unreviewedExcerpt=['expected'=>$excerptVersions->data['expected'],'title'=>$posts[$id]->post_title,'content'=>'<p>New title/body recovery</p>','autosaveExpected'=>''];
$unreviewed=$app->autosave(new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],$unreviewedExcerpt));
check($unreviewed->status===409&&wp_get_post_autosave($id,1)->post_excerpt==='An unsaved reader excerpt','automatic recovery cannot silently erase an unreviewed excerpt-only autosave');
$excerptRestore=$app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6000],['expected'=>$excerptVersions->data['expected'],'revisionExpected'=>$excerptVersions->data['autosave']['expected']]));
check($excerptRestore->status===200&&$posts[$id]->post_excerpt==='An unsaved reader excerpt','reviewed native text restoration includes the reader excerpt');
$afterExcerpt=$app->revisions(new WP_REST_Request('GET',['id'=>$id,'revisions'=>true]));
check($afterExcerpt->data['autosave']===null,'a fully restored identical native recovery copy becomes hidden');
$continuing=$app->autosave(new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],['expected'=>$afterExcerpt->data['expected'],'title'=>$posts[$id]->post_title,'content'=>'<p>Writing after excerpt recovery</p>','autosaveExpected'=>'']));
check($continuing->status===200&&wp_get_post_autosave($id,1)->post_excerpt==='An unsaved reader excerpt','new writing recovery preserves the restored current reader excerpt');
unset($GLOBALS['revisions'][6000]);
$revision=new WP_Post;$revision->ID=6001;$revision->post_type='revision';$revision->post_parent=$id;$revision->post_title='Earlier native title';$revision->post_content='<p>Earlier native writing</p>';$revision->post_modified_gmt='2026-09-10 00:00:00';$GLOBALS['revisions'][6001]=$revision;
$autosave=clone $revision;$autosave->ID=6003;$autosave->post_name=$id.'-autosave-v1';$autosave->post_author=1;$autosave->post_title='Recovered tab title';$autosave->post_content='<p>Recovered tab writing</p>';$autosave->post_modified_gmt='2026-09-10 01:00:00';$GLOBALS['revisions'][6003]=$autosave;
$otherAutosave=clone $autosave;$otherAutosave->ID=6004;$otherAutosave->post_author=6;$GLOBALS['revisions'][6004]=$otherAutosave;
$versions=$app->revisions(new WP_REST_Request('GET',['id'=>$id,'revisions'=>true]));
check($versions->status===200&&count($versions->data['revisions'])===1&&$versions->data['revisions'][0]['title']==='Earlier native title'&&!isset($versions->data['revisions'][0]['content']),'revisions list is bounded to safe summaries');
check($versions->data['autosave']['id']===6003&&$versions->data['autosave']['title']==='Recovered tab title'&&!isset($versions->data['autosave']['content']),'only the signed-in writer autosave is projected as a safe recovery summary');
$autosaveBody=['expected'=>$versions->data['expected'],'revisionExpected'=>$versions->data['autosave']['expected']];
$autosaveBefore=[$posts[$id]->post_status,$posts[$id]->post_date,$posts[$id]->post_date_gmt,$relationships[$id],$tag_relationships[$id],get_post_thumbnail_id($id)];
check($app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6004],$autosaveBody))->status===404,'another writer autosave cannot be restored');
$recovered=$app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6003],$autosaveBody));
check($recovered->status===200&&$recovered->data['title']==='Recovered tab title'&&$recovered->data['content']==='<p>Recovered tab writing</p>'&&[$posts[$id]->post_status,$posts[$id]->post_date,$posts[$id]->post_date_gmt,$relationships[$id],$tag_relationships[$id],get_post_thumbnail_id($id)]===$autosaveBefore,'native autosave recovery changes writing while preserving publication schedule categories tags and image');
$resumedBody=['title'=>'Writing resumed after recovery','content'=>'<p>New writing after recovery</p>','expected'=>$recovered->data['expected'],'autosaveExpected'=>''];
$resumed=$app->autosave(new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],$resumedBody));
check($resumed->status===200&&$resumed->data['autosave']['id']===6003&&$posts[$id]->post_title==='Recovered tab title','an identical hidden WordPress autosave does not block later automatic recovery');
$resumedDiscard=['expected'=>$resumed->data['expected'],'recoveryExpected'=>$resumed->data['autosave']['expected']];
check($app->discard_autosave(new WP_REST_Request('POST',['id'=>$id,'autosave_id'=>6003],$resumedDiscard))->status===200&&!wp_get_post_autosave($id,1),'later recovery remains reviewable and discardable');
$versions=$app->revisions(new WP_REST_Request('GET',['id'=>$id,'revisions'=>true]));
$beforeRestore=[$posts[$id]->post_status,$posts[$id]->post_date,$posts[$id]->post_date_gmt,$relationships[$id],$tag_relationships[$id],get_post_thumbnail_id($id)];
$restoreBody=['expected'=>$versions->data['expected'],'revisionExpected'=>$versions->data['revisions'][0]['expected']];
$originalExcerpt=$posts[$id]->post_excerpt;$posts[$id]->post_excerpt='Concurrent reader summary';
check($app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6001],$restoreBody))->status===409,'a changed reader excerpt invalidates the parent Post recovery proof');$posts[$id]->post_excerpt=$originalExcerpt;
check($app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6001],array_replace($restoreBody,['expected'=>'bad'])))->status===409,'stale current post cannot be restored over');
check($app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6001],$restoreBody,[],['X-WP-Nonce'=>'wp_rest']))->status===403,'revision restore requires mutation proof');
check($app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6001],$restoreBody+['role'=>'administrator']))->status===400,'revision restore rejects unrelated payload fields');
$otherRevision=clone $revision;$otherRevision->ID=6002;$otherRevision->post_parent=101;$GLOBALS['revisions'][6002]=$otherRevision;
check($app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6002],$restoreBody))->status===404,'revision from another post cannot be restored');
$restored=$app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6001],$restoreBody));
check($restored->status===200&&$restored->data['title']==='Earlier native title'&&$restored->data['content']==='<p>Earlier native writing</p>'&&[$posts[$id]->post_status,$posts[$id]->post_date,$posts[$id]->post_date_gmt,$relationships[$id],$tag_relationships[$id],get_post_thumbnail_id($id)]===$beforeRestore,'native restore changes title and writing while keeping publication schedule categories tags and image');
check(!array_filter($GLOBALS['post_filters']['wp_insert_post_data']??[],static fn($callbacks)=>!!$callbacks),'restore removes its date-preserving filter before another native write');
check($app->restore_revision(new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6001],$restoreBody))->status===409,'restoration replay is stale');
check($access->guard_rest(null,null,new WP_REST_Request('POST',['id'=>$id,'revision_id'=>6001],$restoreBody))===null,'client REST guard permits only the protected revision restore route');
check($access->guard_rest(null,null,new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],$autosaveBody))===null,'client REST guard permits the protected native autosave route');
check($access->guard_rest(null,null,new WP_REST_Request('POST',['id'=>$id,'autosave_id'=>6003],['expected'=>'x','recoveryExpected'=>'y']))===null,'client REST guard permits the protected autosave discard route');
$timelineRequest=new class('POST') extends WP_REST_Request { public function get_route(){return '/dsa/v1/workspace/continuity/timeline';} };
check($access->guard_rest(null,null,$timelineRequest)===null,'native write guard admits the protected Timeline adapter for a writer');
$unrelatedTimeline=new class('POST') extends WP_REST_Request { public function get_route(){return '/dsa/v1/workspace/continuity/timeline/delete-all';} };
check($access->guard_rest(null,null,$unrelatedTimeline) instanceof WP_Error,'Timeline write allowance does not admit extra route shapes');
$timelineActor=$actor;$actor=5;check($access->guard_rest(null,null,$timelineRequest) instanceof WP_Error,'subscriber cannot mutate Timeline');$actor=$timelineActor;
foreach([['title'=>'','content'=>'','status'=>'draft','categories'=>[]],['title'=>'Bad','content'=>'','status'=>'private','categories'=>[]],['title'=>'Bad','content'=>'','status'=>'draft','categories'=>['20',20]]] as $invalid)check($app->create(new WP_REST_Request('POST',[],$invalid))->status>=400,'invalid post payload is rejected');
check($app->create(new WP_REST_Request('POST',[],['title'=>'Too large','content'=>str_repeat('x',100001),'status'=>'draft','categories'=>[]]))->status===400,'oversized post content is rejected before native write');
$actor=6;check($app->listing(new WP_REST_Request())->status===200&&$app->create(new WP_REST_Request('POST',[],$body))->status===403,'Editor can manage existing posts but cannot create a new one');$actor=1;
$actor=4;check($app->listing(new WP_REST_Request())->status===403&&$app->read(new WP_REST_Request('GET',['id'=>101]))->status===403,'subscriber cannot enter the posts workspace');$actor=1;
foreach([[],['X-WP-Nonce'=>'bad','X-Kiwe-Mutation'=>'1'],['X-WP-Nonce'=>'wp_rest']] as $headers)check($app->create(new WP_REST_Request('POST',[],$body,[],$headers))->status===403,'missing nonce or mutation proof rejects post writes');
check($app->autosave(new WP_REST_Request('POST',['id'=>$id,'autosave'=>true],$autosaveBody,[],['X-WP-Nonce'=>'wp_rest']))->status===403,'automatic recovery requires mutation proof');
$_SERVER['HTTP_ORIGIN']='https://other.example.test';check($app->listing(new WP_REST_Request())->status===403&&$app->create(new WP_REST_Request('POST',[],$body))->status===403,'cross-origin list and write are denied');unset($_SERVER['HTTP_ORIGIN']);
check($access->guard_rest(null,null,new WP_REST_Request('POST',['id'=>101],$updatedBody))===null,'client REST guard permits only the protected posts route');
$actor=1;$posts[201]=(object)['ID'=>201,'post_type'=>'attachment'];
$imageBody=['title'=>'Illustrated post','content'=>'Article','status'=>'draft','categories'=>[20],'featuredImageId'=>201];
$imagePost=(new Posts())->save(0,$imageBody);check(is_array($imagePost)&&$imagePost['featuredImage']['id']===201,'featured image saves to native thumbnail metadata');
$beforeCount=count($post_writes);$invalid=(new Posts())->save(0,array_replace($imageBody,['featuredImageId'=>103]));check(is_wp_error($invalid)&&count($post_writes)===$beforeCount,'non-image media rejected before post creation');
$denied=['upload_files'];check(is_wp_error((new Posts())->save(0,$imageBody)),'role without upload permission cannot assign an image');$denied=[];
$old=$imagePost['expected'];set_post_thumbnail($imagePost['id'],0);check(is_wp_error((new Posts())->save($imagePost['id'],array_replace($imageBody,['expected'=>$old]))),'external thumbnail edits invalidate the post fingerprint');
$tagFailurePost=(new Posts())->save(0,array_replace($body,['title'=>'Tag rollback probe','newTags'=>[]]));$confirmedTags=$tag_relationships[$tagFailurePost['id']];$fail_tag_writes=1;$failedTag=(new Posts())->save($tagFailurePost['id'],array_replace($body,['title'=>'Tag rollback probe updated','tags'=>[91],'newTags'=>[],'expected'=>$tagFailurePost['expected']]));check(is_wp_error($failedTag)&&$failedTag->get_error_code()==='kiwe_post_tags_unconfirmed'&&$tag_relationships[$tagFailurePost['id']]===$confirmedTags,'unconfirmed tag assignment returns a bounded error and restores previously confirmed tags');
$taskLabels=array_column((new DSA\Access\Workspace_Admin_Service())->task_catalog(),"label");check(count(array_filter($taskLabels,fn($label)=>$label==="Posts"))===1&&!in_array("New post",$taskLabels,true)&&!in_array("Review submissions",$taskLabels,true),"React Home has one Posts destination; creation and review live inside it");
$beforeCount=count($post_writes);$denied=['manage_categories'];$deniedCategory=(new Posts())->save(0,array_replace($body,['newCategories'=>['Unapproved category'],'newTags'=>[]]));$denied=[];check(is_wp_error($deniedCategory)&&$deniedCategory->get_error_code()==='kiwe_post_category_create'&&count($post_writes)===$beforeCount,'category creation requires the native category-management capability before a post write');
$blockContent='<!-- wp:heading --><h3>Existing heading</h3><!-- /wp:heading -->';$posts[101]->post_content=$blockContent;$blockPost=(new Posts())->post(101);$blockSaved=(new Posts())->save(101,['title'=>'Renamed while keeping blocks','content'=>$blockContent,'status'=>'publish','categories'=>[10,30],'tags'=>[40],'newTags'=>[],'expected'=>$blockPost['expected'],'seo'=>$blockPost['seo']]);check(is_array($blockSaved)&&$blockSaved['content']===$blockContent&&$posts[101]->post_content===$blockContent,'taxonomy or title edits preserve unchanged Gutenberg block grammar exactly');
$createdTerm=(new Posts())->create_term(['taxonomy'=>'post_tag','name'=>'Fresh native tag']);check(is_array($createdTerm)&&$createdTerm['created']===true&&$createdTerm['term']['name']==='Fresh native tag'&&isset($tag_terms[$createdTerm['term']['id']]),'editor + New creates and reads back a native tag before post assignment');
$reusedTerm=(new Posts())->create_term(['taxonomy'=>'post_tag','name'=>'Fresh native tag']);check(is_array($reusedTerm)&&$reusedTerm['created']===false&&$reusedTerm['term']['id']===$createdTerm['term']['id'],'editor + New reuses an existing native tag');
$denied=['manage_categories'];$termDenied=(new Posts())->create_term(['taxonomy'=>'category','name'=>'Forbidden editor category']);$denied=[];check(is_wp_error($termDenied)&&$termDenied->get_error_code()==='kiwe_post_term_create','editor + New obeys taxonomy creation capability');
$page=clone $posts[$id];$page->ID=90123;$page->post_type='page';$page->post_parent=0;$page->menu_order=7;$page->post_excerpt='Saved Page excerpt';$posts[$page->ID]=$page;$post_meta[$page->ID]['_wp_page_template']='native-template.php';
$pageAuto=clone $page;$pageAuto->ID=90124;$pageAuto->post_parent=$page->ID;$pageAuto->post_type='revision';$pageAuto->post_name=$page->ID.'-autosave-v1';$pageAuto->post_author=$actor;$pageAuto->post_excerpt='Recovered Page excerpt';$GLOBALS['revisions'][$pageAuto->ID]=$pageAuto;
$pageService=new DSA\Access\Page_Publishing_Service();$pageVersions=$pageService->revisions($page->ID);check(is_array($pageVersions)&&$pageVersions['autosave']['readerExcerpt']==='Recovered Page excerpt','client sees a Page recovery differing only in its excerpt');
$pageBefore=[$page->post_status,$page->post_date,$page->post_date_gmt,$page->post_parent,$page->menu_order,get_page_template_slug($page->ID),get_post_thumbnail_id($page->ID),DSA\Onboarding\SEO_Context_Service::page_noindex($page->ID)];$pageInput=['expected'=>$pageVersions['expected'],'revisionExpected'=>$pageVersions['autosave']['expected']];
$pageResult=$pageService->restore($page->ID,$pageAuto->ID,$pageInput);check($pageResult===true&&$posts[$page->ID]->post_excerpt==='Recovered Page excerpt'&&[$page->post_status,$page->post_date,$page->post_date_gmt,$page->post_parent,$page->menu_order,get_page_template_slug($page->ID),get_post_thumbnail_id($page->ID),DSA\Onboarding\SEO_Context_Service::page_noindex($page->ID)]===$pageBefore,'client Page restoration confirms the recovered excerpt while preserving publication and layout');
check($pageService->revisions($page->ID)['autosave']===null,'restored identical Page recovery is hidden');check(is_wp_error($pageService->restore($page->ID,$pageAuto->ID,$pageInput)),'stale Page recovery cannot be restored again');
$actor=5;check(is_wp_error($pageService->revisions($page->ID))&&is_wp_error($pageService->restore($page->ID,$pageAuto->ID,$pageInput)),'Subscriber cannot inspect or restore client Page recovery');$actor=1;
define('RANK_MATH_VERSION','1.0');$rankMathBefore=(new Posts())->post(101);$rankMathSeo=['title'=>'Rank Math title','description'=>'Rank Math description','focusKeyword'=>'rank phrase'];$rankMathSaved=(new Posts())->save(101,['title'=>$rankMathBefore['title'],'content'=>$rankMathBefore['content'],'status'=>'publish','categories'=>[10,30],'tags'=>[40],'newTags'=>[],'expected'=>$rankMathBefore['expected'],'seo'=>$rankMathSeo]);check(is_array($rankMathSaved)&&$rankMathSaved['seo']===$rankMathSeo&&$post_meta[101]['rank_math_title']==='Rank Math title'&&$post_meta[101]['rank_math_focus_keyword']==='rank phrase','Rank Math sites write their original post SEO metadata, without a shadow Kiwe copy');
echo "$count post app boundary checks passed.\n";
