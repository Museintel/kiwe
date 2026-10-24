<?php
namespace DSA\Access { final class Backup_Workspace_Service { public static function allowed(): bool { return in_array($GLOBALS['kind'],['client','owner'],true); } } final class Template_Workspace_Service { public static bool $active=false; public static function allowed(): bool { return self::$active&&in_array($GLOBALS['kind'],['client','owner','designer'],true); } public static function available():bool{return self::$active;} public static function types():array{return self::$active?['header'=>'Header','footer'=>'Footer']:[];} } }
namespace Bricks { final class Helpers {} }
namespace DSA\Utilities { final class Origin_Checker { public static bool $mutation=true; public static function is_same_site_request():bool{return true;} public static function mutation_allowed($request){return self::$mutation?true:new \WP_Error();} } }
namespace {
class WP_Error {}
class WP_REST_Request { public function __construct(private bool $nonce=true){} public function get_method(){return 'POST';} public function get_header($name){return $this->nonce?'synthetic-native-nonce':'';} }
function wp_verify_nonce($nonce,$action){return $nonce==='synthetic-native-nonce';}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kiwe-content-qa-' . bin2hex( random_bytes( 6 ) ); mkdir( $root ); mkdir( $root . '/public_html' );
define( 'ABSPATH', $root . '/public_html/' ); define( 'DAY_IN_SECONDS', 86400 );
define('BRICKS_DB_TEMPLATE_SLUG','bricks_template');define('BRICKS_DB_TEMPLATE_TYPE','_bricks_template_type');define('BRICKS_DB_TEMPLATE_SETTINGS','_bricks_template_settings');
$kind='client'; $actor=7; $site='https://qa.example.test/'; $checks=[]; $options=[]; $transients=[]; $posts=[]; $terms=[]; $postmeta=[]; $termmeta=[]; $assignments=[]; $next=100; $fail=false;
#[\AllowDynamicProperties]
class WP_Post { public function __construct( array $input ) { foreach ( $input as $key=>$value ) $this->$key=$value; } }
class WP_Query { public array $posts; public int $found_posts; public function __construct( array $input ) { global $posts,$assignments; $this->posts=[];foreach($posts as $post){if($post->post_type!==$input['post_type']||!in_array($post->post_status,$input['post_status'],true))continue;if(isset($input['author'])&&$post->post_author!==$input['author'])continue;if(isset($input['tax_query'])){$tax=$input['tax_query'][0]['taxonomy'];$id=$input['tax_query'][0]['terms'][0];if(!in_array($id,$assignments[$post->ID][$tax]??[],true))continue;}if(isset($input['date_query'])){$date=$input['date_query'][0];if(isset($date['after'])&&strcmp($post->post_date,$date['after'])<0)continue;if(isset($date['before'])&&strcmp($post->post_date,$date['before'])>0)continue;}$this->posts[]=$post->ID;}$this->found_posts=count($this->posts);sort($this->posts);$this->posts=array_slice($this->posts,0,$input['posts_per_page']); } }
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_normalize_path($value){return str_replace('\\','/',$value);}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function home_url($path=''){return $GLOBALS['site'].ltrim($path,'/');}
function get_current_blog_id(){return 1;}
function get_current_user_id(){return $GLOBALS['actor'];}
function wp_timezone_string(){return 'Asia/Kolkata';}
function wp_timezone(){return new \DateTimeZone(wp_timezone_string());}
function is_user_logged_in(){return $GLOBALS['kind']!=='visitor';}
function untrailingslashit($value){return rtrim($value,'/\\');}
function trailingslashit($value){return rtrim($value,'/\\').'/';}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function sanitize_title($value){return strtolower(trim(preg_replace('/[^a-zA-Z0-9-]+/','-',$value),'-'));}
function get_objects_in_term($id,$tax){$out=[];foreach($GLOBALS['assignments']as$object=>$data)if(in_array($id,$data[$tax]??[],true))$out[]=$object;return $out;}
function absint($value){return abs((int)$value);}
function get_post_type_object($type){return in_array($type,['post','page','bricks_template',...($GLOBALS['archive_qa_service_types']??[])],true)?(object)['hierarchical'=>true,'cap'=>(object)['create_posts'=>'create_'.$type,'edit_posts'=>'edit_'.$type]]:null;}
function current_user_can($cap,$id=0,$key=''){if($cap==='edit_post_meta'&&in_array($key,$GLOBALS['archive_qa_denied_meta']??[],true))return false;if($GLOBALS['kind']==='visitor')return false;if($GLOBALS['kind']==='client')return true;if($GLOBALS['kind']==='service_staff'){if(in_array($cap,['edit_post','edit_post_meta'],true))return get_post($id)?->post_author===$GLOBALS['actor'];return in_array($cap,['read','create_qa_service','edit_qa_service','assign_terms'],true);}if($GLOBALS['kind']==='designer')return in_array($cap,['kiwe_manage_templates','create_kiwe_templates','edit_bricks_template','edit_post'],true);if($GLOBALS['kind']==='owner')return in_array($cap,['manage_options','edit_page','create_post','edit_post','assign_terms'],true);if($cap==='edit_post'&&$id)return get_post($id)?->post_author===$GLOBALS['actor'];return in_array($cap,['create_post','edit_post','assign_terms'],true);}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_userdata($id){return $id?(object)['ID'=>$id,'display_name'=>'Synthetic author']:false;}
function get_taxonomy($tax){return in_array($tax,['category','post_tag',...($GLOBALS['archive_qa_service_taxes']??[])],true)?(object)['cap'=>(object)['assign_terms'=>'assign_terms','manage_terms'=>'manage_terms'],'hierarchical'=>!str_ends_with($tax,'tag')]:null;}
function get_term($id,$tax){return $GLOBALS['terms'][$tax.':'.$id]??null;}
function get_term_by($field,$value,$tax){foreach($GLOBALS['terms']as$term)if($term->taxonomy===$tax&&$term->$field===$value)return $term;return false;}
function is_wp_error($value){return false;}
function metadata_exists($type,$id,$key){return isset($GLOBALS[$type==='post'?'postmeta':'termmeta'][$id][$key]);}
function get_post_meta($id,$key='',$single=true){return $key===''?($GLOBALS['postmeta'][$id]??[]):($GLOBALS['postmeta'][$id][$key]??'');}
function get_term_meta($id,$key='',$single=true){return $key===''?($GLOBALS['termmeta'][$id]??[]):($GLOBALS['termmeta'][$id][$key]??'');}
function get_object_taxonomies($type){return $type==='post'?['category','post_tag']:[];}
function wp_get_object_terms($id,$tax,$args){return $GLOBALS['assignments'][$id][$tax]??[];}
function get_post_thumbnail_id($id){return 0;}
function wp_attachment_is_image($id){return false;}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$expiry){$GLOBALS['transients'][$key]=$value;return true;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);return true;}
function add_option($key,$value,$deprecated='',$autoload=false){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function wp_slash($value){return is_array($value)?array_map('wp_slash',$value):(is_string($value)?addslashes($value):$value);}
function wp_unslash($value){return is_array($value)?array_map('wp_unslash',$value):(is_string($value)?stripslashes($value):$value);}
function wp_kses_post($value){return $value;}
function wp_insert_term($name,$tax,$args){$id=++$GLOBALS['next'];$GLOBALS['terms'][$tax.':'.$id]=(object)['term_id'=>$id,'taxonomy'=>$tax,'name'=>$name,'slug'=>$args['slug'],'description'=>$args['description'],'parent'=>$args['parent'],'count'=>0];return ['term_id'=>$id];}
function update_term_meta($id,$key,$value){$GLOBALS['termmeta'][$id][$key]=wp_unslash($value);return true;}
function update_post_meta($id,$key,$value){$GLOBALS['postmeta'][$id][$key]=wp_unslash($value);return true;}
function add_filter($hook,$callback,$priority=10,$args=1){}
function add_action($hook,$callback,$priority=10,$args=1){}
function remove_filter($hook,$callback,$priority=10){}
function remove_action($hook,$callback,$priority=10){}
function wp_update_post($input,$error=false){foreach($input as$key=>$value)if($key!=='ID')$GLOBALS['posts'][$input['ID']]->$key=$value;return $input['ID'];}
function wp_insert_post($input,$error=false){$input=wp_unslash($input);$id=++$GLOBALS['next'];$GLOBALS['posts'][$id]=new WP_Post(['ID'=>$id,'post_parent'=>0,'post_name'=>'imported','post_modified_gmt'=>'2026-10-02 00:00:00',...$input]);if($GLOBALS['fail'])$GLOBALS['posts'][$id]->post_content='Rejected field';return $id;}
function wp_set_object_terms($id,$terms,$tax){$GLOBALS['assignments'][$id][$tax]=$terms;return $terms;}
function wp_delete_post($id,$force=false){unset($GLOBALS['posts'][$id],$GLOBALS['postmeta'][$id],$GLOBALS['assignments'][$id]);return true;}
function wp_delete_term($id,$tax){unset($GLOBALS['terms'][$tax.':'.$id],$GLOBALS['termmeta'][$id]);return true;}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Cipher.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Configuration_Snapshot_Service.php';
require getenv('KIWE_ARCHIVE_BASELINE_FILE') ?: __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Content_Archive_Service.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Content_Archive_App_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Bricks_Design_Snapshot_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Job_Files.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Media_Archive_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Media_Archive_Job_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Media_Archive_App_Service.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Unit_Catalog_Service.php';
use DSA\Backup\Archive_Cipher as Cipher;use DSA\Backup\Content_Archive_Service as Archives;
function check($value,$label){$GLOBALS['checks'][$label]=(bool)$value;if(!$value)throw new \RuntimeException($label);}
function rejects($callback){try{$callback();return false;}catch(\RuntimeException $error){return true;}}
$phrase='synthetic-content-phrase-123';$service=new Archives();$error='';
if(defined('KIWE_CONTENT_JOB_TEST_BOOTSTRAP'))return;
try{
 $app=new DSA\Access\Content_Archive_App_Service();check(!$app->permission(new WP_REST_Request(false)),'archive route denies absent native nonce');DSA\Utilities\Origin_Checker::$mutation=false;check(!$app->permission(new WP_REST_Request()),'native mutation error is denied rather than coerced to approval');DSA\Utilities\Origin_Checker::$mutation=true;check($app->permission(new WP_REST_Request()),'archive route allows explicit native nonce and mutation proof');
 foreach([1=>7,2=>8]as$id=>$author){$posts[$id]=new WP_Post(['ID'=>$id,'post_type'=>'post','post_title'=>'Synthetic story '.$id,'post_content'=>'<p>Native story '.$id.'</p>','post_excerpt'=>'Synthetic excerpt','post_author'=>$author,'post_date'=>'2026-10-02 00:00:00','post_date_gmt'=>'2026-10-02 00:00:00','post_status'=>'publish','post_parent'=>0,'post_name'=>'story-'.$id,'menu_order'=>0]);$assignments[$id]=['category'=>[3],'post_tag'=>[]];}
 $terms['category:3']=(object)['term_id'=>3,'taxonomy'=>'category','name'=>'Synthetic category','slug'=>'synthetic-category','description'=>'Synthetic detail','parent'=>0,'count'=>2];
 $postmeta[1]=['_kiwe_seo_title'=>'Synthetic SEO','private_credential'=>'must stay private'];
 check(Archives::can_export('post',2)&&Archives::can_export('category',3),'client may export foreign record and whole category');
 check(rejects(fn()=>Archives::selection(['from'=>'2026-02-30']))&&rejects(fn()=>Archives::selection(['from'=>null]))&&rejects(fn()=>Archives::selection(['from'=>'2026-10-02','to'=>'2025-10-02']))&&rejects(fn()=>Archives::selection(['from'=>'2026-10-02','column'=>'post_modified'])),'invalid reversed and extra-field date selections refused');
 $bulk=$service->export('posts',0,$phrase,['from'=>'2026-10-02','to'=>'2026-10-02']);$bulkBytes=file_get_contents($service->download_path($bulk['id']));$bulkPayload=Cipher::open($bulkBytes,Archives::SCHEMA,$phrase,Archives::MAX_BYTES);check($bulk['count']===2&&$bulkPayload['selection']['timezone']==='Asia/Kolkata','bulk archive records inclusive creation range and site timezone');
 $kind='author';$own=$service->export('posts',0,$phrase,['from'=>'2026-10-02','to'=>'2026-10-02']);$ownPayload=Cipher::open(file_get_contents($service->download_path($own['id'])),Archives::SCHEMA,$phrase,Archives::MAX_BYTES);check($own['count']===1&&$ownPayload['records'][0]['author']===7,'author collection cannot bypass own-record export restriction');$kind='client';
 $posts[2]->post_author=9;$kind='author';check(rejects(fn()=>$service->download_path($bulk['id'])),'all collection source permissions rechecked at download');$kind='client';$posts[2]->post_author=8;
 $badRange=$bulkPayload;$badRange['records'][0]['date']='2026-10-01 23:59:59';check(rejects(fn()=>$service->preview(Cipher::seal($badRange,Archives::SCHEMA,$phrase,[],Archives::MAX_BYTES),$phrase,'post')),'outside-range record cannot enter a filtered manifest');
 $bulkReview=$service->preview($bulkBytes,$phrase,'post');check($bulkReview['scope']==='posts'&&$bulkReview['count']===2&&$bulkReview['selection']['from']==='2026-10-02','collection file reviewed through shared individual import area');
 $bulkResult=$service->apply($bulkReview['token'],$phrase);check($bulkResult['count']===2&&$posts[1]->post_status==='publish','date-selected collection restores new drafts without replacing sources');foreach($bulkResult['ids']as$id)wp_delete_post($id,true);
 check(rejects(fn()=>$service->export('posts',0,$phrase,['to'=>'2025-01-01']))&&rejects(fn()=>$service->export('post',1,$phrase,['from'=>'2026-01-01'])),'empty selection and date-filtered individual export refused');
 $kind='author';check(Archives::can_export('post',1)&&!Archives::can_export('post',2),'writer exports own record only');check(!Archives::can_export('category',3),'writer cannot export other authors through category');check(Archives::can_import('post')&&!Archives::can_import('page')&&!Archives::can_import('product'),'native create and optional Woo boundaries');
 $kind='owner';check(!current_user_can('create_page')&&Archives::can_import('page'),'technical owner uses existing Page draft authority without global create-pages grant');$kind='visitor';check(!Archives::can_export('post',1)&&!Archives::can_import('post'),'anonymous export and import denied');$kind='client';
 $archive=$service->export('category',3,$phrase);$bytes=file_get_contents($service->download_path($archive['id']));$payload=Cipher::open($bytes,Archives::SCHEMA,$phrase,Archives::MAX_BYTES);
 check($archive['count']===2&&count($payload['records'])===2&&count($payload['terms'])===1,'complete category includes all native posts and category');check(!str_contains($bytes,'Native story')&&!isset($payload['records'][0]['meta']['private_credential']),'encrypted content and secret metadata exclusion');
 check(rejects(fn()=>Cipher::open($bytes,Archives::SCHEMA,'wrong-synthetic-phrase',Archives::MAX_BYTES)),'wrong phrase refused');$damaged=json_decode($bytes,true);$damaged['data'][4]=$damaged['data'][4]==='a'?'b':'a';check(rejects(fn()=>Cipher::open(json_encode($damaged),Archives::SCHEMA,$phrase,Archives::MAX_BYTES)),'tamper refused');
 $site='https://other.example.test/';check(rejects(fn()=>Cipher::open($bytes,Archives::SCHEMA,$phrase,Archives::MAX_BYTES)),'other website refused');$site='https://qa.example.test/';
 $actor=8;check(rejects(fn()=>$service->download_path($archive['id'])),'download bound to original actor');$actor=7;
 check(rejects(fn()=>$service->preview($bytes,$phrase,'post')),'wrong collection import refused');
 $bad=$payload;$bad['records'][0]['terms']['category']=[];$badBytes=Cipher::seal($bad,Archives::SCHEMA,$phrase,[],Archives::MAX_BYTES);check(rejects(fn()=>$service->preview($badBytes,$phrase,'category')),'unrelated post cannot enter category manifest');
 $review=$service->preview($bytes,$phrase,'category');check($review['count']===2&&$review['mode']==='new-drafts'&&count($review['warnings'])>0,'review names complete draft collection and term reuse');
 $terms['category:3']->name='Concurrent rename';check(rejects(fn()=>$service->apply($review['token'],$phrase)),'stale category dependency refused');$terms['category:3']->name='Synthetic category';
 $result=$service->apply($review['token'],$phrase);check($result['count']===2&&$result['categoryId']===3,'category import preserves all archived posts');foreach($result['ids']as$id)check($posts[$id]->post_status==='draft'&&$assignments[$id]['category']===[3],'native imported draft and category '.$id);
 check($posts[1]->post_status==='publish'&&$posts[2]->post_status==='publish','published sources unchanged');check($service->apply($review['token'],$phrase)===$result,'completed import is idempotent');
 $single=$service->export('post',2,$phrase);$singleBytes=file_get_contents($service->download_path($single['id']));$kind='author';check(rejects(fn()=>$service->preview($singleBytes,$phrase,'post')),'writer cannot import another author archive');$kind='client';
 $single=$service->export('post',1,$phrase);$singleBytes=file_get_contents($service->download_path($single['id']));$review=$service->preview($singleBytes,$phrase,'post');$before=array_keys($posts);$fail=true;check(rejects(fn()=>$service->apply($review['token'],$phrase)),'failed native readback refused');$fail=false;check(array_keys($posts)===$before&&!get_transient('kiwe_content_review_'.$review['token'])&&!$options,'failed import removes own drafts review and lock');
 $review=$service->preview($singleBytes,$phrase,'post');$options['kiwe_content_import_'.$review['token']]=time();check(rejects(fn()=>$service->apply($review['token'],$phrase)),'simultaneous import refused');unset($options['kiwe_content_import_'.$review['token']]);
 DSA\Access\Template_Workspace_Service::$active=true;$posts[50]=clone$posts[1];$posts[50]->ID=50;$posts[50]->post_type='bricks_template';$posts[50]->post_title='Synthetic header';$postmeta[50]=[BRICKS_DB_TEMPLATE_TYPE=>'header',BRICKS_DB_TEMPLATE_SETTINGS=>['templateConditions'=>[['main'=>'entireWebsite']],'width'=>'1200px'],'_bricks_page_content_2'=>[['id'=>'abc123','name'=>'heading','settings'=>['text'=>'Synthetic header']]]];
 $kind='designer';check(Archives::can_export('template',50)&&Archives::can_import('template')&&Archives::can_export_collection('templates'),'native template designer archive authority');
 $template=$service->export('templates',0,$phrase);$templateBytes=file_get_contents($service->download_path($template['id']));$templatePayload=Cipher::open($templateBytes,Archives::SCHEMA,$phrase,Archives::MAX_BYTES);check($template['count']===1&&$templatePayload['records'][0]['template']['type']==='header'&&!isset($templatePayload['records'][0]['template']['settings']['templateConditions']),'template type and layout saved without live placement conditions');
 $templateReview=$service->preview($templateBytes,$phrase,'template');$templateResult=$service->apply($templateReview['token'],$phrase);$copy=$templateResult['ids'][0];check($posts[$copy]->post_status==='draft'&&$postmeta[$copy][BRICKS_DB_TEMPLATE_TYPE]==='header'&&$postmeta[$copy]['_bricks_page_content_2']===$postmeta[50]['_bricks_page_content_2']&&!isset($postmeta[$copy][BRICKS_DB_TEMPLATE_SETTINGS]['templateConditions'])&&isset($postmeta[50][BRICKS_DB_TEMPLATE_SETTINGS]['templateConditions']),'template import preserves native layout and source but never activates conditions');
 $bad=$templatePayload;$bad['records'][0]['template']['settings']['templateConditions']=[['main'=>'entireWebsite']];check(rejects(fn()=>$service->preview(Cipher::seal($bad,Archives::SCHEMA,$phrase,[],Archives::MAX_BYTES),$phrase,'template')),'template archive cannot reintroduce live display conditions');$kind='author';check(!Archives::can_import('template')&&!Archives::can_export('template',50)&&rejects(fn()=>$service->preview($templateBytes,$phrase,'template')),'writer denied template import and export');$kind='designer';DSA\Access\Template_Workspace_Service::$active=false;check(!Archives::can_import('template')&&!Archives::can_export_collection('templates'),'inactive Bricks denies template archive controls');$kind='client';
 // Do not silently truncate a whole category at the pagination boundary.
 for($id=1000;$id<1201;$id++){$posts[$id]=clone $posts[1];$posts[$id]->ID=$id;$assignments[$id]=['category'=>[3]];}
 check(rejects(fn()=>$service->export('category',3,$phrase)),'oversized complete category refused without partial file');
 $vault=$root.'/kiwe-private-backups';while(count(glob($vault.'/content-*.kiwe')?:[])<60)file_put_contents($vault.'/content-review-'.bin2hex(random_bytes(16)).'.kiwe','synthetic capacity');
 check(rejects(fn()=>$service->preview($singleBytes,$phrase,'post')),'preview cannot exceed bounded temporary archive capacity');
}catch(\Throwable $failure){$error=$failure->getMessage();}
finally{$vault=$root.'/kiwe-private-backups';foreach(glob($vault.'/*')?:[]as$path)if(is_file($path)&&!is_link($path))unlink($path);if(is_dir($vault))rmdir($vault);rmdir($root.'/public_html');rmdir($root);}
echo json_encode(['passed'=>$error==='','cleanup'=>!file_exists($root),'checks'=>$checks,'error'=>$error],JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($error===''?0:1);
}
