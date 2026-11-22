<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

use DSA\Access\Post_Workspace_Service as Posts;
use DSA\Access\Page_Publishing_Service as Pages;
use DSA\Access\Page_Workspace_Service as Page_Drafts;
use DSA\Access\Template_Workspace_Service as Templates;
use DSA\Access\Taxonomy_App_Service as Taxonomies;
use DSA\Access\Trash_Workspace_Service as Trash;

/** Narrow content adapters. Native records, permissions and writers own every effect. */
final class Authoring_Service {
 public const AREAS=['posts','pages','record','taxonomies','terms','tag-groups','media','media-record'];
 public static function allowed(): bool{return Credentials::actor_allowed(get_current_user_id())&&(current_user_can('edit_posts')||current_user_can('edit_pages')||current_user_can('upload_files'));}
 public static function kind_allowed(string $kind): bool{
  if(!self::allowed())return false;
  return match($kind){
   'publish'=>current_user_can('publish_posts')||current_user_can('publish_pages'),
   'taxonomy'=>(bool)array_filter((new Taxonomies())->definitions()+(new Taxonomies())->tag_definitions(),static fn($d)=>($d['objectType']??'')!=='product'),
   'tag-group'=>(new \DSA\Access\Tag_Group_Service())->can_manage_kind('post'),
   'media'=>current_user_can('upload_files'),
   'trash'=>current_user_can('delete_posts')||current_user_can('delete_pages'),
   default=>current_user_can('edit_posts')||current_user_can('edit_pages'),
  };
 }
 public static function native($value){if(is_wp_error($value))throw new \RuntimeException($value->get_error_message());if($value instanceof \WP_REST_Response){if($value->get_status()>=300)throw new \RuntimeException('The native operation was refused. Read its current field rules.');return$value->get_data();}return$value;}
 public static function json($value,int $limit=6000): string{$json=wp_json_encode($value);if(!is_string($json)||strlen($json)>$limit)throw new \RuntimeException('Use a smaller page or the native editor for this large record.');return$json;}
 public static function digest($value): string{return hash('sha256',serialize($value));}
 /** Serialize delegated effects across grants, without waiting on public requests.
  * Native writers keep their own permissions, fingerprints and narrower locks.
  * This is not a lock on unrelated WordPress/editor or third-party writers.
  */
 public static function mutate(string $key,callable $write){
  global $wpdb;$lock='kiwe-mcp-author-'.substr(hash('sha256',$wpdb->prefix.'|'.$key),0,36);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new \RuntimeException('Another delegated edit is finishing. Read its receipt before trying again.');
  try{return$write();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 public static function plan(string $summary,string $expected,$before,$after,bool $review=false): array{
  $preview=static fn($v)=>\DSA\Access\Task_Workspace_UI::text(is_string($v)?$v:(string)wp_json_encode($v),1800);
  return['summary'=>$summary,'nativeExpected'=>$expected,'before'=>$preview($before),'after'=>$preview($after),'needsApproval'=>$review];
 }
 public static function object(string $json,array $keys): array{$v=json_decode($json,true,16,JSON_THROW_ON_ERROR);if(!is_array($v)||($v!==[]&&array_is_list($v))||array_diff(array_keys($v),$keys))throw new \RuntimeException('Use only the fields described for this native action.');return$v;}
 public static function safe_html(string $value): string{
  if(str_contains($value,'<!--')||str_contains($value,'<?')||preg_match('/<\s*(script|style|iframe|object|embed)\b/i',$value))throw new \RuntimeException('Use ordinary article HTML. Executable builder output belongs to the reviewed design workflow.');
  if(function_exists('get_shortcode_regex')&&preg_match('/'.get_shortcode_regex().'/s',$value))throw new \RuntimeException('This authoring action does not execute shortcodes. Use the native editor for embedded applications.');
  return wp_kses_post($value);
 }
 public static function can_record(string $type,int $id=0): bool{
  if(!in_array($type,['post','page'],true)||!Credentials::actor_allowed(get_current_user_id()))return false;
  $object=get_post_type_object($type);if(!$object)return false;
  if(!$id){
   // Page creation deliberately has no generic REST capability. Admit only
   // native Page-workspace creators who can also edit the resulting body.
   // The workspace and object controller recheck their own authority at write.
   if($type==='page')return Templates::builder_actor()&&current_user_can('edit_pages')&&(current_user_can('manage_options')||current_user_can('kiwe_manage_page_indexing'))&&(current_user_can('manage_options')||current_user_can('kiwe_create_page_drafts'));
   return current_user_can($object->cap->create_posts??$object->cap->edit_posts);
  }
  $post=get_post($id);return$post&&$post->post_type===$type&&current_user_can('edit_post',$id)&&current_user_can('read_post',$id);
 }
 private static function core_taxonomy(string $taxonomy): void{$object=get_taxonomy($taxonomy);if($object&&in_array('product',(array)$object->object_type,true))throw new \RuntimeException('Product groups belong to the separately approved Store authoring operations.');}
 private static function post(string $type,int $id): \WP_Post{
  if(!self::can_record($type,$id))throw new \RuntimeException('Choose a content record your current role can edit.');$post=get_post($id);
  if(!$post instanceof \WP_Post)throw new \RuntimeException('This content record is unavailable.');return$post;
 }
 private static function expected(string $type,int $id): string{
  if(!$id){if(!self::can_record($type))throw new \RuntimeException('Your role cannot create this content.');return self::digest([$type,0,get_current_user_id()]);}
  $p=self::post($type,$id);return self::digest([$type==='post'?Posts::fingerprint($p):Pages::fingerprint($p),get_post_meta($id,'_bricks_page_content_2',true),$p->post_password,(string)$p->comment_status]);
 }
 private static function record(string $type,int $id): array{
  $p=self::post($type,$id);$body=(string)$p->post_content;
  return['id'=>$id,'type'=>$type,'title'=>(string)$p->post_title,'status'=>(string)$p->post_status,'content'=>$body,'featuredImageId'=>(int)get_post_thumbnail_id($id),'commentsAllowed'=>$p->comment_status==='open','categories'=>$type==='post'?array_map('intval',wp_get_post_categories($id)):[],'tags'=>$type==='post'?array_map('intval',wp_get_post_terms($id,'post_tag',['fields'=>'ids'])):[],'expected'=>self::expected($type,$id),'nativeUrl'=>admin_url('post.php?post='.$id.'&action=edit'),'builderOwned'=>!empty(get_post_meta($id,'_bricks_page_content_2',true))];
 }
 public function read(array $a): array{
  $area=$a['area'];$page=$a['page']??1;if($page<1||$page>1000)throw new \RuntimeException('Choose a bounded native catalogue page.');$id=$a['id']??0;$type=$a['type']??'post';$search=$a['search']??'';$expected='';$hasMore=false;
  if($area==='record'){$data=self::record($type,$id);$expected=$data['expected'];$body=$data['content'];$offset=$a['offset']??0;if($offset>strlen($body)||preg_match('//u',substr($body,$offset))!==1)throw new \RuntimeException('Use the next byte offset supplied by this native read.');$chunk=substr($body,$offset,4000);while($chunk!==''&&preg_match('//u',$chunk)!==1)$chunk=substr($chunk,0,-1);$data['content']=$chunk;$data['contentBytes']=strlen($body);$data['nextOffset']=$offset+strlen($chunk);$hasMore=$data['nextOffset']<strlen($body);}
  elseif(in_array($area,['posts','pages'],true)){
   $type=$area==='pages'?'page':'post';$object=get_post_type_object($type);if(!$object||!current_user_can($object->cap->edit_posts))throw new \RuntimeException('This content type is unavailable to your role.');
   $query=['post_type'=>$type,'post_status'=>['publish','private','draft','pending','future','trash'],'posts_per_page'=>9,'offset'=>($page-1)*8,'orderby'=>'ID','order'=>'DESC','s'=>$search,'no_found_rows'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false];if(!current_user_can($object->cap->edit_others_posts))$query['author']=get_current_user_id();$rows=(new \WP_Query($query))->posts;$data=[];foreach(array_slice($rows,0,8)as$p)if(self::can_record($type,(int)$p->ID))$data[]=['id'=>(int)$p->ID,'title'=>(string)$p->post_title,'status'=>(string)$p->post_status,'modifiedGmt'=>(string)$p->post_modified_gmt];$hasMore=count($rows)>8;
  }elseif($area==='taxonomies'){$service=new Taxonomies();$data=array_values(array_filter($service->definitions()+$service->tag_definitions(),static fn($definition)=>($definition['objectType']??'')!=='product'));}
  elseif($area==='terms'){self::core_taxonomy($a['taxonomy']??'');$data=self::native((new Taxonomies())->authoring_terms($a['taxonomy']??'',$id,['page'=>$page,'search'=>$search]));$expected=$id?$data['expected']:self::digest($data);$hasMore=!empty($data['has_next']);}
  elseif($area==='tag-groups'){$data=Tag_Group_Authoring::read($a['kind']??'post');$expected=$data['expected'];$groups=$data['groups'];$data['groups']=array_slice($groups,($page-1)*8,8);$hasMore=$page*8<count($groups);}
  elseif(in_array($area,['media','media-record'],true))return(new Media_Authoring())->read($a);
  else throw new \RuntimeException('Choose a described authoring area.');
  return['area'=>$area,'page'=>$page,'hasMore'=>$hasMore,'expected'=>$expected,'dataJson'=>self::json($data,14000),'guidance'=>'Read native IDs and current values before editing. Drafts are private to your editing role. Publication and changes to published content need the separate publish operation and an exact native review. Bricks content stays in the design workflow.'];
 }
 public function prepare(string $kind,array $a): array{
  if(in_array($kind,['save','publish'],true)){
   $type=$a['type'];$id=$a['id'];$before=$id?self::record($type,$id):['id'=>0,'title'=>'','status'=>'draft','content'=>''];$fields=$a['fields'];if(!$fields)throw new \RuntimeException('Choose the exact content fields to save.');
   if($before['status']==='trash')throw new \RuntimeException('Restore this record before editing.');
   if($kind==='save'&&(!in_array($before['status'],['draft','pending'],true)||!in_array($fields['status']??$before['status'],['draft','pending'],true)))throw new \RuntimeException('Use the separate reviewed publish operation for published, private or scheduled content.');
   if(!$id&&$kind==='publish')throw new \RuntimeException('Save a native draft before requesting publication.');
   if($kind==='publish'){$object=get_post_type_object($type);if(!current_user_can($object->cap->publish_posts)||($type==='page'&&!(new Pages())->can_manage_publishing($id)))throw new \RuntimeException('Your native role cannot publish this content.');}
   if($type==='page'&&isset($fields['status'])&&$fields['status']!==$before['status']&&!($id?(new Pages())->can_manage_publishing($id):(new Pages())->can_publish()))throw new \RuntimeException('Your native Page role cannot change this publication state.');
   $status=$fields['status']??$before['status'];
   if($type==='page'&&$id&&Pages::critical_reason($id)&&$status!=='publish')throw new \RuntimeException(Pages::critical_reason($id));
   if($status==='future'){$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$fields['scheduledLocal']??'',wp_timezone());if(!$date||$date->format('Y-m-d\TH:i')!==($fields['scheduledLocal']??'')||$date->getTimestamp()<time()+300)throw new \RuntimeException('Choose a valid publication time in the website timezone, at least five minutes from now.');}elseif(!empty($fields['scheduledLocal']))throw new \RuntimeException('A schedule is available only for a future publication.');
   if(!empty($before['builderOwned'])&&array_key_exists('content',$fields))throw new \RuntimeException('This page is owned by Bricks. Use the reviewed native design workflow.');
   if($type==='page'&&(isset($fields['categories'])||isset($fields['tags'])))throw new \RuntimeException('Native Pages do not use post categories or tags.');
   if($type==='post'&&class_exists(\DSA\Access\Guest_Contribution_Service::class)&&\DSA\Access\Guest_Contribution_Service::is_submission($id))throw new \RuntimeException('Guest submissions keep their native moderation workflow.');
   foreach(['content']as$key)if(isset($fields[$key]))$fields[$key]=self::safe_html($fields[$key]);
   if(!$id&&empty($fields['title']))throw new \RuntimeException('A new draft needs a title.');
   return self::plan($kind==='publish'?'Review content publication':'Save an agreed content draft',self::expected($type,$id),array_intersect_key($before,array_flip(['id','title','status'])),['type'=>$type,'id'=>$id,'fields'=>$fields],$kind==='publish');
  }
  if($kind==='trash'){$p=self::post($a['type'],$a['id']);if(!current_user_can('delete_post',$p->ID))throw new \RuntimeException('Your role cannot move this content to Trash.');return self::plan($a['action']==='restore'?'Restore native content':'Move native content to Trash',Trash::fingerprint($p,$a['type']),['id'=>$p->ID,'title'=>$p->post_title,'status'=>$p->post_status],$a,true);}
  if($kind==='taxonomy'){self::core_taxonomy($a['taxonomy']);if($a['id']&&isset($a['fields']['parent']))throw new \RuntimeException('This native rename changes only the label.');$s=new Taxonomies();$before=self::native($s->authoring_terms($a['taxonomy'],$a['id']));return self::plan($a['id']?'Rename native category or tag':'Create native category or tag',$a['id']?$before['expected']:self::digest($before),$a['id']?$before:[], $a['fields'],(bool)$a['id']);}
  if($kind==='tag-group')return Tag_Group_Authoring::prepare($a);
  if($kind==='media')return(new Media_Authoring())->prepare($a);
  throw new \RuntimeException('This native authoring action is unavailable.');
 }
 public function apply(string $kind,array $a,string $expected): array{
  $key=in_array($kind,['save','publish','trash'],true)?'content:'.$a['type'].':'.($a['id']?:'new:'.get_current_user_id()):($kind==='media'?'media:'.($a['id']?:'new:'.get_current_user_id()):'groups');
  return self::mutate($key,function()use($kind,$a,$expected){if(!empty($a['id'])&&in_array($kind,['save','publish','trash','media'],true)&&function_exists('clean_post_cache'))clean_post_cache($a['id']);return$this->apply_native($kind,$a,$expected);});
 }
 private function apply_native(string $kind,array $a,string $expected): array{
  if(!hash_equals($this->prepare($kind,$a)['nativeExpected'],$expected))throw new \RuntimeException('Native content changed. Read it before preparing another proposal.');
  if(in_array($kind,['save','publish'],true)){
   $type=$a['type'];$id=$a['id'];$f=$a['fields'];if(isset($f['content']))$f['content']=self::safe_html($f['content']);
   if($type==='post'){
    $before=$id?self::native((new Posts())->post($id)):['title'=>'','content'=>'','status'=>'draft','categories'=>[],'tags'=>[]];$input=array_intersect_key($before,array_flip(['title','content','status','categories','tags']));$input=array_replace($input,$f);$input['expected']=$id?$before['expected']:'';$result=self::native((new Posts())->save($id,$input));$id=(int)$result['id'];
   }else{
    $p=$id?get_post($id):null;$status=$f['status']??($p?$p->post_status:'draft');$created=0;
    try{
     if(!$id)$created=$id=(int)self::native((new Page_Drafts())->create_draft(['page_title'=>$f['title'],'indexing'=>'index']));
     $request=new \WP_REST_Request('POST');$request->set_param('id',$id);
     foreach(['title'=>'title','content'=>'content','featuredImageId'=>'featured_media','commentsAllowed'=>'comment_status']as$key=>$native)if(array_key_exists($key,$f))$request->set_param($native,$key==='commentsAllowed'?($f[$key]?'open':'closed'):$f[$key]);
     // Creation belongs to the checked Page workspace; body editing belongs to
     // the actual object's controller, and publication to its native Page task.
     // No generic create_pages grant, arbitrary meta or executable data.
     $controller=new \WP_REST_Posts_Controller('page');$permission=$controller->update_item_permissions_check($request);if($permission!==true){self::native($permission);throw new \RuntimeException('Native page permission was refused.');}$saved=self::native($controller->update_item($request));$id=(int)$saved['id'];
     if($kind==='publish'||$status!=='draft')self::native((new Pages())->save($id,['status'=>$status,'scheduledLocal'=>$f['scheduledLocal']??'','expected'=>Pages::fingerprint(get_post($id))]));
    }catch(\Throwable $error){if($created)throw new Unconfirmed_Write('page',$created,$error);throw$error;}
   }
   $result=self::record($type,$id);unset($result['content']);return['resultJson'=>self::json($result)];
  }
  if($kind==='trash')return['resultJson'=>self::json(self::native((new Trash())->mutate(['operation'=>$a['action'],'type'=>$a['type'],'item'=>['id'=>$a['id'],'expected'=>$expected]])))];
  if($kind==='taxonomy'){$fields=$a['fields'];if($a['id'])$fields['expected']=$expected;return['resultJson'=>self::json(self::native((new Taxonomies())->authoring_change($a['taxonomy'],$a['id'],$fields)))];}
  if($kind==='tag-group')return['resultJson'=>self::json(Tag_Group_Authoring::apply($a,$expected))];
  if($kind==='media')return['resultJson'=>self::json((new Media_Authoring())->apply($a,$expected))];
  throw new \RuntimeException('This authoring action has no native writer.');
 }
}
