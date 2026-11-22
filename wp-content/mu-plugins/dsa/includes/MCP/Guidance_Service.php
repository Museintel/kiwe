<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

use DSA\Onboarding\Design_Context_Profile_Service as Profile;
use DSA\Onboarding\Design_Context_Journey_Service as Journey;
use DSA\Bricks\Design_Context_Binding_Service as Bindings;
use DSA\Apps\Store_App_Service;

/** Native setup facts on explicit authorized reads. No scans, secrets or shadow progress. */
final class Guidance_Service {
 public const SECTIONS=['identity','business','about','contact','brand','audience','contentPlan','services','resources','commercePlan','regulatory','seo','localization'];
 private ?array $context=null;
 public static function allowed(): bool { return Credentials::actor_allowed(get_current_user_id())&&current_user_can('kiwe_manage_context'); }
 public static function text($value,int $limit=2000): string {
  if(!is_scalar($value))return'';
  $text=html_entity_decode(wp_strip_all_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8');
  return function_exists('mb_strcut')?mb_strcut($text,0,$limit,'UTF-8'):(string)preg_replace('/[\x80-\xFF]+$/','',substr($text,0,$limit));
 }
 private function context(): array {
  if($this->context!==null)return$this->context;
  $profile=(new Profile())->public_context(false,true);
  $profile['localization']=['language'=>(string)get_bloginfo('language'),'timezone'=>wp_timezone_string()];
  return$this->context=$profile;
 }
 /** The existing binding catalogue is the allowlist. Added native fields become discoverable here. */
 private function fields(): array {
  $fields=Bindings::fields();
  if(!Store_App_Service::enabled())foreach(array_keys($fields)as$path)if(str_starts_with($path,'commercePlan.')||str_starts_with($path,'regulatory.')||$path==='contentPlan.highlightBestsellers')unset($fields[$path]);
  return$fields;
 }
 private function field(string $path,int $offset=0,int $chunk=2000): array {
  $definition=$this->fields()[$path]??null;if(!$definition)throw new \RuntimeException('Unknown context field');
  $value=$this->context();$present=true;
  if(str_starts_with($path,'brand.colors.')){
   $role=substr($path,13);$value='';$present=false;
   foreach($this->context()['brand']['colors']??[] as$color)if(($color['role']??'')===$role){$value=$color['hex']??'';$present=true;break;}
  }else foreach(explode('.',$path)as$part){if(!is_array($value)||!array_key_exists($part,$value)){$present=false;$value=null;break;}$value=$value[$part];}
  $out=['path'=>$path,'tag'=>self::text($definition['tag'],200),'kind'=>self::text($definition['kind'],20),'present'=>$present,'valueType'=>'missing','truncated'=>false,'sourceTruncated'=>false,'nextOffset'=>0];
  if(!$present)return$out;
  if(is_bool($value)){$out['valueType']='boolean';$out['boolean']=$value;return$out;}
  if(is_int($value)||is_float($value)){$out['valueType']='number';$out['number']=$value;return$out;}
  if(is_array($value)){
   if(!array_is_list($value)){$out['present']=false;return$out;}
   $out['valueType']='list';$out['list']=array_map(static fn($v)=>self::text($v,200),array_slice($value,0,256));$out['truncated']=count($value)>256;return$out;
  }
  $out['sourceTruncated']=is_string($value)&&strlen(wp_strip_all_tags($value))>65536;
  $text=self::text($value,65536);$piece=function_exists('mb_strcut')?mb_strcut($text,$offset,$chunk,'UTF-8'):substr($text,$offset,$chunk);
  $out['valueType']='string';$out['text']=$piece;$out['truncated']=$offset+strlen($piece)<strlen($text);$out['nextOffset']=$out['truncated']?$offset+strlen($piece):0;return$out;
 }
 public function context_read(array $args): array {
  $section=$args['section'];$fields=[];
  foreach($this->fields()as$path=>$definition)if(str_starts_with($path,$section.'.'))$fields[]=$path;
  $page=$args['page']??1;$limit=8;$slice=array_slice($fields,($page-1)*$limit,$limit);
  return['schema'=>'kiwe.mcp-context-fields.v1','section'=>$section,'page'=>$page,'hasMore'=>$page*$limit<count($fields),'fields'=>array_map(fn($path)=>$this->typed_field($path),$slice),'collectionReads'=>'Use the paged public catalogue for content and explicitly selected media. This projection excludes private identities, operational street addresses, drafts and customer records. Computed links may have no stored value; ask only for missing native journey checks.','readOnly'=>true];
 }
 private function typed_field(string $path,int $offset=0,int $chunk=2000): array {
  return$this->field($path,$offset,$chunk);
 }
 public function field_read(array $args): array { return['field'=>$this->typed_field($args['path'],$args['offset']??0,8000),'readOnly'=>true]; }
 /** Public team facts, planned briefs and published services keep their native owners. */
 public function collection_read(array $args): array {
  $collection=$args['collection'];$page=$args['page']??1;$context=$this->context();$items=[];$limit=8;$hasMore=false;
  if($collection==='services'){
   $native=(new Profile())->service_catalog(['page'=>$page,'status'=>'publish']);$rows=$native['items']??[];
   $planned=empty($native['sourcePostType']);
   if(empty($native['enabled']))$rows=[];
   elseif($planned){$profile=(new Profile())->current(true);$all=array_values(array_filter($profile['services']['items']??[],static fn($item)=>is_array($item)&&empty($item['recordId'])));$rows=array_slice($all,($page-1)*20,21);}
   // Native service pages use 20 records. Keep that pagination and cap text
   // per record instead of silently losing twelve records between pages.
   $limit=20;$hasMore=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
   foreach($rows as$row){$id=(int)($row['recordId']??0);$post=$id?get_post($id):null;
    if(!$planned&&(!$post instanceof \WP_Post||!current_user_can('read_post',$id)||!\DSA\Utilities\Content_Visibility::is_public($post)))continue;
    $image=(int)($row['imageId']??0);if($image&&!\DSA\Utilities\Content_Visibility::is_public(get_post($image)))$image=0;
    $items[]=['id'=>$id,'name'=>self::text($row['title']??'',200),'summary'=>self::text($row['summary']??'',500),'text'=>self::text($row['description']??'',800),'url'=>$post?self::text(get_permalink($post),1024):'','imageId'=>$image];
   }
  }else{
   $rows=$collection==='team'?($context['about']['team']['members']??[]):($context['contentPlan']['plannedPages']??[]);
   $hasMore=$page*$limit<count($rows);
   foreach(array_slice($rows,($page-1)*$limit,$limit)as$row){if(!is_array($row))continue;
    $items[]=['id'=>0,'name'=>self::text($row['name']??'',300),'summary'=>self::text($collection==='team'?($row['title']??''):($row['visibility']??''),800),'text'=>$collection==='team'?self::text($row['bio']??'',1200):'','url'=>$collection==='team'?self::text($row['linkedin']??'',2048):'','imageId'=>$collection==='team'?(int)($row['imageId']??0):0];
   }
  }
  return['collection'=>$collection,'page'=>$page,'hasMore'=>$hasMore,'items'=>$items,'readOnly'=>true,'coverage'=>'Native public team selections, saved page/service plans or published configured services. Text is a bounded guidance summary; arbitrary custom fields, native drafts and private user identifiers are excluded.'];
 }
 public static function field_allowed(array $args): bool {
  if(!self::allowed()||!is_string($args['path']??null))return false;
  return isset((new self())->fields()[$args['path']]);
 }
 /** WordPress installs these three records directly, before any authored content. */
 private static function install_sample(int $id): bool {
  if ( $id < 1 || $id > 3 ) return false;
  $post = get_post( $id );
  if ( ! $post instanceof \WP_Post || $post->post_modified !== $post->post_date || $post->post_modified_gmt !== $post->post_date_gmt || $post->post_excerpt !== '' || $post->post_password !== '' || (int) $post->post_parent !== 0 ) return false;

  $guid = get_option( 'home' ) . ( $id === 1 ? '/?p=1' : '/?page_id=' . $id );
  if ( $post->guid !== $guid || $post->post_type !== ( $id === 1 ? 'post' : 'page' ) || $post->post_status !== ( $id === 3 ? 'draft' : 'publish' ) ) return false;
  if ( $id === 1 ) {
   $paragraph = __( 'Welcome to WordPress. This is your first post. Edit or delete it, then start writing!' );
   return $post->post_title === __( 'Hello world!' ) && $post->post_name === sanitize_title( _x( 'hello-world','Default post slug' ) ) && $post->post_content === "<!-- wp:paragraph -->\n<p>{$paragraph}</p>\n<!-- /wp:paragraph -->";
  }
  if ( $id === 2 ) {
   // WordPress clears fresh_site when a published page is saved. The stock
   // introduction also excludes same-ID content supplied by a host installer.
   $paragraph = __( "This is an example page. It's different from a blog post because it will stay in one place and will show up in your site navigation (in most themes). Most people start with an About page that introduces them to potential site visitors. It might say something like this:" );
   return $post->post_title === __( 'Sample Page' ) && $post->post_name === __( 'sample-page' ) && str_starts_with( $post->post_content,"<!-- wp:paragraph -->\n<p>{$paragraph}</p>\n<!-- /wp:paragraph -->" );
  }
  if ( (int) get_option( 'wp_page_for_privacy_policy' ) !== $id || $post->post_title !== __( 'Privacy Policy' ) || $post->post_name !== __( 'privacy-policy' ) ) return false;
  require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
  return $post->post_content === \WP_Privacy_Policy_Content::get_default_content();
 }
 public function orientation(array $args=[]): array {
  global $wpdb;
  $complete=true;
  $fresh=(int)get_option('fresh_site',0)===1;
  $exists=static function(string $sql)use($wpdb,&$complete):int{ $result=$wpdb->get_var($sql);if($wpdb->last_error)$complete=false;return$result!==null?1:0; };
  $postPresence=static function(array $types)use($wpdb,$exists):int{
   if(!$types)return 0;$holders=implode(',',array_fill(0,count($types),'%s'));
   return$exists($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$holders}) AND post_status NOT IN ('auto-draft','inherit') LIMIT 1",...$types));
  };
  $known=['post','page','product','product_variation','attachment','nav_menu_item','shop_order','shop_order_refund','bricks_template','wp_template','wp_template_part','wp_global_styles','wp_navigation','revision','custom_css','customize_changeset','oembed_cache','user_request','wp_font_family','wp_font_face'];
  $types=array_values(array_diff(get_post_types([], 'names'),$known));
  if(count($types)>80){$complete=false;$types=array_slice($types,0,80);}
  if ( $fresh && ! is_multisite() ) {
   $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('auto-draft','inherit') ORDER BY ID LIMIT 4" );
   if ( $wpdb->last_error ) $complete = false;
   $authored = (int) ( count( $ids ) > 3 || (bool) array_filter( $ids,static fn( $id ) => ! self::install_sample( (int) $id ) ) );
  } else {
   $authored = $postPresence( ['post','page'] );
  }
  $inventory=['authoredContent'=>$authored,'products'=>$postPresence(['product','product_variation']),'nativeOrders'=>$postPresence(['shop_order','shop_order_refund']),'customRecords'=>$postPresence($types),'builderDocuments'=>$postPresence(['bricks_template','wp_template','wp_template_part','wp_global_styles']),'media'=>$exists("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' LIMIT 1"),'navigation'=>$postPresence(['nav_menu_item','wp_navigation'])];
  $orders=$wpdb->prefix.'wc_orders';$found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($orders)));
  if($wpdb->last_error)$complete=false;
  elseif($found===$orders)$inventory['nativeOrders']=max($inventory['nativeOrders'],$exists("SELECT id FROM {$orders} LIMIT 1"));
  $stored=get_option(Profile::OPTION_PROFILE,[]);$business=false;
  foreach(['identity','audience','about','brand','services']as$section)if(!empty($stored[$section])){$business=true;break;}
  $native=Journey::projection();$view=Orientation::compose(['inventory'=>$inventory,'inventoryComplete'=>$complete,'freshInstallConfirmed'=>$fresh,'businessContextPresent'=>$business,'storeAvailable'=>Store_App_Service::enabled()],$native);
  $view['nextStep']=$view['nextStep']??'';$view['presence']=[];
  foreach($inventory as$key=>$present)$view['presence'][]=['kind'=>$key,'present'=>$present>0];
  $view['inventoryMeaning']='Presence checks look for existing work. The standard untouched WordPress samples are recognised on a native fresh installation.';
  $view['journeyPercent']=(int)($native['percent']??0);$view['journeyUrl']=self::text($native['resumeUrl']??'',2048);$view['nextQuestions']=[];
  foreach($native['steps']??[] as$step)if(($step['id']??'')===$view['nextStep']){
   foreach($step['checks']??[] as$label=>$ready)if(!$ready&&count($view['nextQuestions'])<3)$view['nextQuestions'][]=self::text($label,200);
  }
  $view['storeAvailable']=Store_App_Service::enabled();$view['bricksAvailable']=defined('BRICKS_VERSION');
  $view['guidance'][]='Discover the current approved operation list before claiming any action. An App recommendation does not prove installation, configuration, removal or an external account link. Native permissions and exact consequential-action reviews remain authoritative.';
  return$view;
 }
}
