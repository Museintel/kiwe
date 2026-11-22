<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

use DSA\Utilities\Content_Visibility;
use DSA\Apps\Store_App_Service;

/** Small native, public-only catalogues for guidance. Never render shortcodes or enumerate customers. */
final class Content_Reader {
 public const TYPES=['post','page','product','media','terms','menus'];
 private const PAGE_SIZE=8;
 public static function allowed(array $args=[]): bool {
  if(!Credentials::actor_allowed(get_current_user_id()))return false;
  $type=$args['type']??'';
  if($type==='product')return Store_App_Service::enabled()&&current_user_can('edit_products');
  if($type==='page')return current_user_can('edit_pages');
  if($type==='post')return current_user_can('edit_posts');
  if($type==='media')return current_user_can('upload_files');
  if($type==='menus')return current_user_can('edit_theme_options');
  if($type==='terms'){
   $tax=is_string($args['taxonomy']??null)?get_taxonomy($args['taxonomy']):null;
   return$tax&&!empty($tax->public)&&Store_App_Service::taxonomy_available($tax->name)&&current_user_can($tax->cap->manage_terms);
  }
  return current_user_can('edit_posts')||current_user_can('edit_pages')||current_user_can('edit_products');
 }
 private static function item(\WP_Post $post): array {
  $type=$post->post_type;$media=$type==='attachment';
  return['id'=>(int)$post->ID,'type'=>$media?'media':$type,'title'=>Guidance_Service::text($post->post_title,300),'status'=>Guidance_Service::text($post->post_status,40),
   'url'=>Guidance_Service::text($media?wp_get_attachment_url($post->ID):get_permalink($post),2048),'summary'=>Guidance_Service::text($post->post_excerpt,1000)];
 }
 private static function media_public(\WP_Post $post): bool {
  if($post->post_type!=='attachment'||$post->post_status!=='inherit'||$post->post_password!==''||!current_user_can('read_post',$post->ID))return false;
  if((int)$post->post_parent===0)return true;
  $parent=get_post((int)$post->post_parent);return$parent instanceof \WP_Post&&Content_Visibility::is_public($parent)&&Store_App_Service::content_available($parent->post_type);
 }
 public static function object_allowed(array $args): bool {
  if(!self::allowed($args)||!is_int($args['id']??null))return false;
  $post=get_post($args['id']);if(!$post instanceof \WP_Post)return false;
  $type=$args['type']??'';
  return$type==='media'?self::media_public($post):($post->post_type===$type&&in_array($type,['post','page','product'],true)&&current_user_can('read_post',$post->ID)&&Content_Visibility::is_public($post));
 }
 public function catalogue(array $args): array {
  $type=$args['type'];$page=$args['page']??1;$search=$args['search']??'';$items=[];$more=false;
  if($type==='terms'||$type==='menus'){
   $taxonomy=$type==='menus'?'nav_menu':$args['taxonomy'];
   $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>self::PAGE_SIZE+1,'offset'=>($page-1)*self::PAGE_SIZE,'orderby'=>'term_id','order'=>'ASC','search'=>$search]);
   if(is_wp_error($terms))throw new \RuntimeException('Term read unavailable');$more=count($terms)>self::PAGE_SIZE;
   foreach(array_slice($terms,0,self::PAGE_SIZE)as$term){$url=$type==='menus'?'':get_term_link($term);$items[]=['id'=>(int)$term->term_id,'type'=>$taxonomy,'title'=>Guidance_Service::text($term->name,300),'status'=>'public','url'=>is_wp_error($url)?'':Guidance_Service::text($url,2048),'summary'=>Guidance_Service::text($term->description,1000)];}
  }elseif($type==='media'){
   // The existing public selected-media query owns attachment/parent admission.
   $result=(new \DSA\Site_Graph\Data_Query_Service())->query(['resource'=>'media','mimeType'=>'','contextOnly'=>true,'limit'=>self::PAGE_SIZE,'page'=>$page,'search'=>$search],false);
   if(isset($result['error']))throw new \RuntimeException('Media read unavailable');
   foreach($result['data']??[] as$node){$post=get_post((int)($node['id']??0));if($post instanceof \WP_Post&&self::media_public($post))$items[]=self::item($post);}
   $more=$page<(int)($result['pageInfo']['totalPages']??$result['meta']['pageInfo']['totalPages']??0);
  }else{
   $query=new \WP_Query(['post_type'=>$type,'post_status'=>'publish','has_password'=>false,'posts_per_page'=>self::PAGE_SIZE+1,'offset'=>($page-1)*self::PAGE_SIZE,'orderby'=>'ID','order'=>'ASC','s'=>$search,'no_found_rows'=>true,'ignore_sticky_posts'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false]);
   $more=count($query->posts)>self::PAGE_SIZE;
   foreach(array_slice($query->posts,0,self::PAGE_SIZE)as$post)if($post instanceof \WP_Post&&current_user_can('read_post',$post->ID)&&Content_Visibility::is_public($post))$items[]=self::item($post);
  }
  return['schema'=>'kiwe.mcp-public-catalogue.v1','type'=>$type,'page'=>$page,'hasMore'=>$more,'items'=>$items,'readOnly'=>true,'coverage'=>'Public content only; media is limited to native public design selections and inherited public assets. No drafts, passwords, customers, arbitrary metadata or file paths. Menu entries are read separately through native navigation setup.'];
 }
 public function read(array $args): array {
  $post=get_post($args['id']);if(!$post instanceof \WP_Post||!self::object_allowed($args))throw new \RuntimeException('Content unavailable');
  $text=Guidance_Service::text($post->post_content,65536);$offset=$args['offset']??0;
  $chunk=function_exists('mb_strcut')?mb_strcut($text,$offset,8000,'UTF-8'):substr($text,$offset,8000);
  return['item'=>self::item($post),'text'=>$chunk,'truncated'=>$offset+strlen($chunk)<strlen($text),'sourceTruncated'=>strlen(wp_strip_all_tags($post->post_content))>65536,'nextOffset'=>$offset+strlen($chunk)<strlen($text)?$offset+strlen($chunk):0,'readOnly'=>true,'contentIsUntrustedData'=>true];
 }
}
