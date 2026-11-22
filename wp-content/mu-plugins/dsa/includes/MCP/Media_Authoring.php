<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** Native attachment metadata and bounded, checksum-pinned public media import. */
final class Media_Authoring {
 public const SOURCE_FIELDS=['url','filename','sha256','title','alt'];
 public const TEXT_FIELDS=['title','caption','description','alt'];
 public static function all_fields(): array{return array_values(array_unique([...self::SOURCE_FIELDS,...self::TEXT_FIELDS]));}
 private const MAX_BYTES=12582912;
 private const TYPES=['image/jpeg','image/png','image/gif','image/webp','image/avif','video/mp4','video/webm','audio/mpeg','application/pdf'];
 private function allowed(int $id=0): bool{
  if(!current_user_can('upload_files'))return false;if(!$id)return true;$p=get_post($id);
  return$p&&$p->post_type==='attachment'&&$p->post_status!=='trash'&&current_user_can('edit_post',$id)&&(!$p->post_parent||current_user_can('read_post',(int)$p->post_parent));
 }
 private function record(int $id): array{
  if(!$this->allowed($id))throw new \RuntimeException('Choose a media item your current role can edit.');$p=get_post($id);
  $data=['id'=>$id,'title'=>(string)$p->post_title,'caption'=>(string)$p->post_excerpt,'description'=>(string)$p->post_content,'alt'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),'mime'=>(string)$p->post_mime_type,'url'=>(string)wp_get_attachment_url($id),'parentId'=>(int)$p->post_parent,'modifiedGmt'=>(string)$p->post_modified_gmt,'importSha256'=>(string)get_post_meta($id,'_kiwe_mcp_asset_sha256',true)];$data['expected']=Authoring_Service::digest([$data,get_post_meta($id,'_wp_attached_file',true),get_post_meta($id,'_wp_attachment_metadata',true)]);return$data;
 }
 public function read(array $a): array{
  if(!$this->allowed())throw new \RuntimeException('Your native role cannot manage media.');$page=$a['page']??1;if($page<1||$page>1000)throw new \RuntimeException('Choose a bounded native media page.');$id=$a['id']??0;$hasMore=false;$expected='';
  if($a['area']==='media-record'){$data=$this->record($id);$expected=$data['expected'];}else{
   $q=['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>9,'offset'=>($page-1)*8,'s'=>$a['search']??'','orderby'=>'ID','order'=>'DESC','no_found_rows'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false];if(!current_user_can('edit_others_posts'))$q['author']=get_current_user_id();$rows=(new \WP_Query($q))->posts;$data=[];foreach(array_slice($rows,0,8)as$p)if($this->allowed((int)$p->ID))$data[]=array_intersect_key($this->record((int)$p->ID),array_flip(['id','title','mime','parentId','url','expected','importSha256']));$hasMore=count($rows)>8;
  }return['area'=>$a['area'],'page'=>$page,'hasMore'=>$hasMore,'expected'=>$expected,'dataJson'=>Authoring_Service::json($data,14000),'guidance'=>'Media remains in WordPress. Imports need the exact public HTTPS source, filename and SHA-256 of the bytes. No private paths, executable files or media deletion are admitted.'];
 }
 private function source(array $f): void{
  foreach(['title','alt']as$key)if(isset($f[$key])&&(!is_string($f[$key])||strlen($f[$key])>2000))throw new \RuntimeException('Provide bounded media title and alternative text.');
  if(array_diff(array_keys($f),self::SOURCE_FIELDS)||!is_string($f['url']??null)||strlen($f['url'])>2048||!is_string($f['filename']??null)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,120}\.(?:jpe?g|png|gif|webp|avif|mp4|webm|mp3|pdf)$/Di',$f['filename'])!==1||!is_string($f['sha256']??null)||preg_match('/^[a-f0-9]{64}$/D',$f['sha256'])!==1)throw new \RuntimeException('Provide a public media source, safe filename and exact SHA-256.');
  $parts=wp_parse_url($f['url']);if(!is_array($parts)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(isset($parts['port'])&&$parts['port']!==443)||!wp_http_validate_url($f['url']))throw new \RuntimeException('Only a public safe HTTPS media source is admitted.');
 }
 public function prepare(array $a): array{
  if(!$this->allowed($a['id']))throw new \RuntimeException('Your role cannot manage this media.');$f=$a['fields'];
  if($a['action']==='import'){if($a['id']!==0)throw new \RuntimeException('A new import cannot replace an attachment.');$this->source($f);return Authoring_Service::plan('Import the exact public media into WordPress',Authoring_Service::digest(['new-media',get_current_user_id(),$f]),[], $f);}
  if($a['action']!=='update'||!$a['id']||array_diff(array_keys($f),self::TEXT_FIELDS)||!$f)throw new \RuntimeException('Use only native media text fields.');foreach($f as$value)if(!is_string($value)||strlen($value)>2000)throw new \RuntimeException('Media text fields must be bounded text.');$before=$this->record($a['id']);return Authoring_Service::plan('Update native media text',$before['expected'],$before,$f,true);
 }
 public function apply(array $a,string $expected): array{
  if(!hash_equals($this->prepare($a)['nativeExpected'],$expected))throw new \RuntimeException('This media item changed. Read it before saving.');$f=$a['fields'];
  if($a['action']==='update'){
   $request=new \WP_REST_Request('POST');$request->set_param('id',$a['id']);foreach(['title','caption','description']as$key)if(isset($f[$key]))$request->set_param($key,sanitize_textarea_field($f[$key]));if(isset($f['alt']))$request->set_param('alt_text',sanitize_text_field($f['alt']));$c=new \WP_REST_Attachments_Controller('attachment');$permission=$c->update_item_permissions_check($request);if($permission!==true){Authoring_Service::native($permission);throw new \RuntimeException('Native media permission was refused.');}Authoring_Service::native($c->update_item($request));return$this->record($a['id']);
  }
  require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';$tmp=wp_tempnam($f['filename']);if(!$tmp)throw new \RuntimeException('Native temporary media storage is unavailable.');
  try{
   $max=min(self::MAX_BYTES,(int)wp_max_upload_size());if($max<1)throw new \RuntimeException('WordPress media upload capacity is unavailable.');$url=$f['url'];$response=null;$deadline=microtime(true)+20;
   for($hop=0;$hop<4;$hop++){
    $source=$f;$source['url']=$url;$this->source($source);
    $remaining=$deadline-microtime(true);if($remaining<1)throw new \RuntimeException('Media download exceeded its bounded time. Use a smaller direct source.');
    $response=wp_safe_remote_get($url,['timeout'=>$remaining,'redirection'=>0,'stream'=>true,'filename'=>$tmp,'limit_response_size'=>$max+1,'reject_unsafe_urls'=>true]);Authoring_Service::native($response);$code=wp_remote_retrieve_response_code($response);
    if(!in_array($code,[301,302,303,307,308],true))break;
    $location=wp_remote_retrieve_header($response,'location');if($hop===3||!is_string($location)||$location==='')throw new \RuntimeException('Use the final public HTTPS media source.');$url=\WP_Http::make_absolute_url($location,$url);
   }
   Authoring_Service::native($response);if(wp_remote_retrieve_response_code($response)!==200||!is_file($tmp)||filesize($tmp)<1||filesize($tmp)>$max||!hash_equals($f['sha256'],hash_file('sha256',$tmp)))throw new \RuntimeException('Media bytes, response or size do not match the exact import.');
   $type=wp_check_filetype_and_ext($tmp,$f['filename']);if(empty($type['ext'])||empty($type['type'])||!in_array($type['type'],self::TYPES,true)||!in_array($type['type'],get_allowed_mime_types(),true))throw new \RuntimeException('This file is not an admitted native media type.');
   if(str_starts_with($type['type'],'image/')){if(wp_get_image_mime($tmp)!==$type['type'])throw new \RuntimeException('The actual image format does not match its native file type.');$geometry=wp_getimagesize($tmp);if(!$geometry||$geometry[0]<1||$geometry[1]<1||$geometry[0]>8000||$geometry[1]>8000||$geometry[0]*$geometry[1]>16000000)throw new \RuntimeException('The media dimensions exceed the native import budget.');}
   else{$actual=class_exists('\finfo')?(new \finfo(FILEINFO_MIME_TYPE))->file($tmp):false;$accepted=match($type['type']){'video/mp4'=>['video/mp4','application/mp4'],'audio/mpeg'=>['audio/mpeg','audio/mp3'],default=>[$type['type']]};if(!in_array($actual,$accepted,true))throw new \RuntimeException('Native media bytes do not match their declared format.');}
   $id=Authoring_Service::native(media_handle_sideload(['name'=>$f['filename'],'tmp_name'=>$tmp,'error'=>0,'size'=>filesize($tmp)],0,null,['post_title'=>sanitize_text_field($f['title']??pathinfo($f['filename'],PATHINFO_FILENAME))]));
   if(!$id||!$this->allowed((int)$id))throw new \RuntimeException('The imported attachment needs native readback. Do not repeat the upload blindly.');if(isset($f['alt']))update_post_meta((int)$id,'_wp_attachment_image_alt',sanitize_text_field($f['alt']));update_post_meta((int)$id,'_kiwe_mcp_asset_sha256',$f['sha256']);return$this->record((int)$id);
  }finally{if(is_file($tmp))wp_delete_file($tmp);}
 }
}
