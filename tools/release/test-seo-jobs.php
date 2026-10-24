<?php
declare(strict_types=1);
namespace DSA\SEO { function time(){ return $GLOBALS['clock']; } }
namespace DSA\AI {
 class AI_Broker_Service {
  public bool $enabled=true; public array $calls=[]; public $callback=null; public ?array $response=null;
  public function status($service='', $refresh=false){return ['profile'=>['enabled'=>$this->enabled], 'provider'=>['enabled'=>true,'configured'=>true]];}
  public function request($args){$this->calls[]=$args;$records=json_decode($args['user'],true)['records'];if($this->callback)($this->callback)();return $this->response??['ok'=>true,'validation'=>['data'=>['schema'=>'kiwe.seo-refinement-batch.v1','proposals'=>array_map(fn($r)=>['id'=>$r['id'],'fields'=>['seoTitle'=>'Reviewed title','metaDescription'=>'Verified description','excerpt'=>"Reader's excerpt",'searchPhrases'=>['useful phrase']],'reason'=>'Synthetic evidence'], $records)]]];}
 }
}
namespace DSA\Onboarding {
 class Design_Context_Profile_Service {const OPTION_SEO_REPORT='score',OPTION_PROFILE='profile';public function overall_seo_report(...$args){throw new \RuntimeException('Unexpected full-library score query');}public function service_post_types(){return [];}}
 class Design_Context_Enhancement_Service {public function __construct($p){} public function resolved_public_context($private){if($GLOBALS['contextCallback'])($GLOBALS['contextCallback'])();return [];}}
}
namespace DSA\Access {
 class Task_Workspace_UI {
  public static function text($v,$limit=200){return is_string($v)?substr($v,0,$limit):'';}
  public static function error($e){$GLOBALS['uiError']=$e;}
  public static function finish($url,$message){$GLOBALS['receipt']=$message;}
  public static function receipt(){} public static function form($action,$nonce,$hidden=[]){echo '<form>';}
  public static function response($value,$status=200){return new \WP_REST_Response($value,$status);}
  public static function failure($error,$status=400){return new \WP_REST_Response(['code'=>$error->get_error_code(),'message'=>$error->get_error_message()],$status);}
 }
 class AI_Workspace_Service {public static function url(){return 'admin.php?page=kiwe-ai-features';}}
}
namespace DSA\Utilities {
 class Origin_Checker {
  public static function is_same_site_request(){return empty($GLOBALS['crossOrigin']);}
  public static function mutation_allowed($request){return $request->get_header('X-Kiwe-Mutation')==='1';}
 }
}
namespace {
define('ABSPATH',__DIR__.'/');define('DSA_URL','https://example.invalid/');define('DSA_VERSION','synthetic');define('KIWE_REACT_WORKSPACE',true);
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/SEO_App_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Native_Indexing_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Search_Report_Snapshot.php';
$checks=[];
class WP_Error {public function __construct(private string $code, private string $message){} public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}}
class WP_Term {public function __construct(public int $term_id,public string $taxonomy,public string $name,public string $description='',public int $parent=0,public int $count=0){}}
class WP_REST_Response {public function __construct(public array $data,public int $status=200){}}
class WP_REST_Request implements ArrayAccess {
 public function __construct(private string $method='GET',private array $params=[],private array $body=[],private array $headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1']){}
 public function get_method(){return $this->method;}public function get_header($key){$headers=array_change_key_case($this->headers);return $headers[strtolower($key)]??'';}public function get_param($key){return $this->params[$key]??$this->body[$key]??null;}public function get_json_params(){return $this->body;}
 public function offsetExists($offset):bool{return isset($this->params[$offset]);}public function offsetGet($offset):mixed{return $this->params[$offset]??null;}public function offsetSet($offset,$value):void{$this->params[$offset]=$value;}public function offsetUnset($offset):void{unset($this->params[$offset]);}
}
class WP_Query {
	public array $posts=[];public int $max_num_pages=0;public function __construct(array $args){$GLOBALS['objectQuery']=$args;$rows=array_filter($GLOBALS['posts'],function($post)use($args){if($post->post_type!==$args['post_type']||!in_array($post->post_status,(array)$args['post_status'],true))return false;if(isset($args['author'])&&(int)($post->post_author??0)!==(int)$args['author'])return false;if(isset($args['tax_query'])){$filter=$args['tax_query'][0];if(!in_array($post->ID,$GLOBALS['termPosts'][$filter['taxonomy']][$filter['terms'][0]]??[],true))return false;}return empty($args['s'])||stripos((string)$post->post_title,(string)$args['s'])!==false;});$ids=array_keys($rows);rsort($ids,SORT_NUMERIC);$size=(int)$args['posts_per_page'];$this->max_num_pages=(int)ceil(count($ids)/max(1,$size));$this->posts=array_slice($ids,((int)$args['paged']-1)*$size,$size);}
}
function __($s,$domain=''){return $s;} function esc_html__($s,$d=''){return esc_html($s);} function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);} function esc_url($s){return esc_html($s);}
function absint($v){return abs((int)$v);} function is_wp_error($v){return $v instanceof WP_Error;} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));} function sanitize_text_field($v){return sanitize_textarea_field($v);} function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_check_invalid_utf8($v,$strip=false){return iconv('UTF-8','UTF-8//IGNORE',$v);} function wp_strip_all_tags($v){return strip_tags($v);} function strip_shortcodes($v){return $v;}
function mysql_to_rfc3339($v){return str_replace(' ','T',(string)$v).'Z';}
function wp_slash($v){return is_array($v)?array_map('wp_slash',$v):(is_string($v)?addslashes($v):$v);} function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):(is_string($v)?stripslashes($v):$v);}
function wp_cache_delete($key,$group){} function clean_user_cache($id){}
function clean_post_cache($id){unset($GLOBALS['postCache'][$id]);}
function get_post($id){if(isset($GLOBALS['postCache'][$id]))return clone $GLOBALS['postCache'][$id];if(!isset($GLOBALS['posts'][$id]))return null;$GLOBALS['postCache'][$id]=clone $GLOBALS['posts'][$id];return clone $GLOBALS['postCache'][$id];}
function get_permalink($post){return 'https://example.test/'.$post->post_type.'/'.$post->ID.'/';}function esc_url_raw($url){return (string)$url;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function get_term($id,$taxonomy=''){return isset($GLOBALS['terms'][$id])&&$GLOBALS['terms'][$id]->taxonomy===$taxonomy?clone $GLOBALS['terms'][$id]:null;}
function get_term_link($term){return 'https://example.test/category/'.(int)$term->term_id.'/';}
function get_taxonomy($taxonomy){return in_array($taxonomy,['category','product_cat'],true)?(object)['hierarchical'=>true,'cap'=>(object)['edit_terms'=>'manage_categories']]:null;}
function get_terms($args){$terms=array_values(array_filter($GLOBALS['terms'],fn($term)=>in_array($term->taxonomy,(array)$args['taxonomy'],true)&&(!($args['search']??'')||stripos($term->name,(string)$args['search'])!==false)));usort($terms,fn($a,$b)=>strcmp($a->name,$b->name));if(($args['order']??'ASC')==='DESC')$terms=array_reverse($terms);return array_slice($terms,(int)($args['offset']??0),(int)($args['number']??150));}
function taxonomy_exists($taxonomy){return in_array($taxonomy,['category','product_cat'],true);}
function get_term_meta($id,$key,$single=true){return $GLOBALS['termMeta'][$id][$key]??'';}
function update_term_meta($id,$key,$value){$GLOBALS['termMeta'][$id][$key]=wp_unslash($value);return true;}
function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['termMeta'][$id]??[]);}
function update_post_meta($id,$key,$value){if($GLOBALS['failMeta'])return false;$GLOBALS['meta'][$id][$key]=wp_unslash($value);return true;}
function wp_update_post($values,$error=false){if($GLOBALS['failPost'])return new WP_Error('synthetic','Write denied');$values=wp_unslash($values);$id=$values['ID'];foreach($values as $k=>$v)if($k!=='ID')$GLOBALS['posts'][$id]->$k=$v;clean_post_cache($id);return $id;}
function get_post_type_object($type){return (object)['publicly_queryable'=>true];}function post_type_exists($t){return in_array($t,['post','page','product','attachment'],true);}
function get_attached_file($id){return 'synthetic/photo.jpg';}function get_post_mime_type($id){return 'image/jpeg';}function wp_attachment_is_image($id){return true;}
function get_option($key,$default=false){return $default;}function get_current_user_id(){return $GLOBALS['actor'];}
function is_user_logged_in(){return get_current_user_id()>0;}
function user_can($id,$cap,...$args){if($cap==='manage_options'&&isset($GLOBALS['technical'][$id]))return $GLOBALS['technical'][$id];if($cap==='kiwe_manage_ai_preferences'&&isset($GLOBALS['preferences'][$id]))return $GLOBALS['preferences'][$id];if($id===2&&($GLOBALS['pageSeoClient']??false))return in_array($cap,['read','edit_pages','kiwe_manage_page_indexing'],true);return ($GLOBALS['owner'][$id]??false)&&($cap!=='edit_post'||(isset($GLOBALS['posts'][$args[0]])&&!in_array($args[0],$GLOBALS['denied'],true)));}
function current_user_can($cap,...$args){return user_can(get_current_user_id(),$cap,...$args);}function check_admin_referer($nonce){if(!$GLOBALS['nonce'])throw new RuntimeException('Invalid nonce');}
function wp_die($s,...$args){throw new RuntimeException('Denied');}function admin_url($path){return $path;}
function wp_next_scheduled($hook,$args=[]){return $GLOBALS['events'][$hook.json_encode($args)]??false;}
function wp_schedule_single_event($when,$hook,$args=[]){$GLOBALS['events'][$hook.json_encode($args)]=$when;return true;}
function wp_clear_scheduled_hook($hook,$args=[]){unset($GLOBALS['events'][$hook.json_encode($args)]);}
function add_submenu_page(...$args){return 'kiwe_page_kiwe-seo';}function add_action(...$args){}function add_filter(...$args){}function remove_filter(...$args){}
function get_the_author_meta($key,$id){return 'Synthetic author';}function get_the_post_thumbnail_url($id,$size){return false;}function wp_get_attachment_image_url($id,$size){return 'https://example.invalid/photo.jpg';}function get_users($args){return [(object)['ID'=>1,'display_name'=>'Synthetic author']];}function wp_get_current_user(){return (object)['ID'=>1,'display_name'=>'Synthetic author'];}
function register_rest_route($namespace,$route,$args){$GLOBALS['objectRoutes'][$route]=$args;}
function wp_verify_nonce($nonce,$action){return $nonce===$action;}
function wp_generate_uuid4(){return '11111111-2222-4333-8444-555555555555';}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;return empty($GLOBALS['failTransient']);}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);return true;}
function wp_enqueue_style($handle,...$args){$GLOBALS['styles'][]=$handle;}
class JobDatabase {
 public string $options='wp_options';public string $posts='wp_posts';public string $last_error='';public ?string $raw=null;public bool $failRead=false;public bool $failWrite=false;public $contender=null;public array $queries=[];
 public function prepare($sql,...$args){return json_encode([$sql,$args]);}
 public function get_var($q){[$sql,$a]=json_decode($q,true);$this->queries[]=$sql;$this->last_error=$this->failRead?'Synthetic failure':'';
  if(str_starts_with($sql,'SELECT 1'))return null;
  if(str_contains($sql,'option_value')){if(!str_contains($sql,'OCTET_LENGTH'))throw new RuntimeException('Unbounded state read');return $this->raw===null?null:(strlen($this->raw)>$a[0]?'':$this->raw);}
  $ids=array_keys(array_filter($GLOBALS['posts'],fn($p)=>$p->post_type===$a[0]));return str_contains($sql,'MAX(ID)')?($ids?max($ids):null):count(array_filter($ids,fn($id)=>$id<=$a[1]));
 }
 public function get_col($q){[$sql,$a]=json_decode($q,true);$this->queries[]=$sql;$this->last_error=$this->failRead?'Synthetic failure':'';if(!str_contains($sql,'ID>%d AND ID<=%d ORDER BY ID ASC LIMIT %d')||$a[3]>5)throw new RuntimeException('Unbounded discovery');$ids=array_keys(array_filter($GLOBALS['posts'],fn($p)=>$p->post_type===$a[0]&&$p->ID>$a[1]&&$p->ID<=$a[2]));sort($ids);return array_slice($ids,0,$a[3]);}
 public function query($q){[$sql,$a]=json_decode($q,true);$this->queries[]=$sql;if($this->failWrite)return false;if($this->contender){$c=$this->contender;$this->contender=null;$c();}if(str_starts_with($sql,'INSERT IGNORE')){if($this->raw!==null)return 0;if(!str_contains($sql,"'no'"))throw new RuntimeException('Autoloaded job');$this->raw=$a[1];return 1;}if(!str_contains($sql,'AND BINARY option_value=BINARY %s'))throw new RuntimeException('Missing byte-exact CAS');if($this->raw!==$a[2])return 0;$this->raw=$a[0];return 1;}
}
$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
foreach(['Onboarding/SEO_Context_Service','Onboarding/Context_Selection_Service','AI/AI_Feature_Policy','SEO/SEO_Job_Store','SEO/SEO_Discovery_Service','SEO/SEO_Score_Service','SEO/SEO_Refinement_Service'] as $file)require $base.$file.'.php';
use DSA\SEO\SEO_Job_Store as Store;use DSA\SEO\SEO_Discovery_Service as Discovery;use DSA\SEO\SEO_Refinement_Service as Service;
function check($label,$pass){$GLOBALS['checks'][]=(bool)$pass;echo ($pass?'PASS ':'FAIL ').$label.PHP_EOL;}
function setup($count=10){$GLOBALS['clock']=1800000000;$GLOBALS['wpdb']=new JobDatabase();$GLOBALS['posts']=[];$GLOBALS['postCache']=[];$GLOBALS['meta']=[];$GLOBALS['terms']=[];$GLOBALS['termPosts']=[];$GLOBALS['termMeta']=[];$GLOBALS['owner']=[1=>true,2=>false];$GLOBALS['technical']=[];$GLOBALS['preferences']=[];$GLOBALS['actor']=1;$GLOBALS['denied']=[];$GLOBALS['events']=[];$GLOBALS['styles']=[];$GLOBALS['objectRoutes']=[];$GLOBALS['transients']=[];$GLOBALS['crossOrigin']=false;$GLOBALS['failTransient']=false;$GLOBALS['contextCallback']=null;$GLOBALS['nonce']=true;$GLOBALS['failPost']=false;$GLOBALS['failMeta']=false;$GLOBALS['uiError']=null;$GLOBALS['receipt']='';$_SERVER['REQUEST_METHOD']='POST';$_POST=[];for($i=1;$i<=$count;$i++)$GLOBALS['posts'][$i]=(object)['ID'=>$i,'post_type'=>'post','post_status'=>'publish','post_password'=>'','post_parent'=>0,'post_author'=>1,'post_modified_gmt'=>'2026-09-20 10:00:00','post_title'=>'Article '.$i,'post_excerpt'=>'Existing excerpt','post_content'=>'Verified synthetic content'];$broker=new DSA\AI\AI_Broker_Service();return [new Store(),$broker,new Service($broker,new DSA\Onboarding\Design_Context_Profile_Service())];}
function job(){return (new Store())->read()['job'];}function private_call($obj,$method,...$args){$r=new ReflectionMethod($obj,$method);return $r->invoke($obj,...$args);}
function review($service,$decision,$version=null){$j=job();$id=array_key_first($j['proposals']);$_POST=['job_id'=>$j['id'],'proposal_id'=>$id,'proposal_version'=>$version??Store::proposal_version($j['proposals'][$id]),'decision'=>$decision];$service->handle_review();}
[$s,$b,$v]=setup();check('fresh job reads do not write a row',$s->read()['job']===[]&&$wpdb->raw===null);$j=$s->start('all_posts',10,10,1);check('job persists cursor and counters without an ID inventory',$j['cursorId']===0&&!isset($j['ids'])&&strlen($wpdb->raw)<1500);
check('active job cannot be silently replaced',is_wp_error($s->start('all_posts',10,10,1)));$claimed=$s->claim($j['id']);check('one active operation wins and a competing claim is denied',isset($claimed['lease']['token'])&&is_wp_error($s->claim($j['id'])));
$s->control($j['id'],'pause');$claimed['cursorId']=5;$after=$s->finish($j['id'],$claimed['lease']['token'],$claimed);check('pause survives an in-flight completion and retains progress',$after['status']==='paused'&&$after['cursorId']===5&&!$after['lease']);$s->control($j['id'],'resume');$claimed=$s->claim($j['id']);$s->control($j['id'],'cancel');$claimed['cursorId']=10;$after=$s->finish($j['id'],$claimed['lease']['token'],$claimed);check('cancel discards worker result and retains earlier progress',$after['status']==='cancelled'&&$after['cursorId']===5);
[$s,$b,$v]=setup();$wpdb->contender=fn()=>(new Store())->start('all_posts',10,10,1);check('competing initial insertion preserves winning job',is_wp_error($s->start('all_posts',10,10,1))&&job()['createdBy']===1);
[$s,$b,$v]=setup();$j=$s->start('all_posts',10,10,1);$wpdb->contender=fn()=>(new Store())->claim($j['id']);check('CAS collision cannot create two leases',is_wp_error($s->claim($j['id'])));$old=job();$clock+=121;$v->run_batch($j['id']);check('expired worker pauses without automatically repeating provider work',job()['status']==='paused'&&!job()['lease']&&!$b->calls);$s->control($j['id'],'resume');$new=$s->claim($j['id']);check('expired completion cannot overwrite a new lease',is_wp_error($s->finish($j['id'],$old['lease']['token'],$old))&&job()['lease']['token']===$new['lease']['token']);
$raw=$wpdb->raw;$clock--;check('clock reversal fails closed',is_wp_error($s->read()));$clock++;$wpdb->raw='broken';check('malformed job is preserved and rejected',is_wp_error($s->read())&&$wpdb->raw==='broken');$wpdb->raw=str_repeat('x',262145);check('oversized old state cannot be loaded',is_wp_error($s->read()));$wpdb->raw=$raw;$wpdb->failRead=true;check('storage read errors do not create a fresh job',is_wp_error($s->start('all_posts',10,10,1)));$wpdb->failRead=false;$wpdb->failWrite=true;check('storage write errors preserve the previous state',is_wp_error($s->control($new['id'],'pause'))&&$wpdb->raw===$raw);
[$s,$b,$v]=setup(10000);$d=new Discovery();$boundary=$d->boundary('all_posts');$posts[10001]=clone $posts[10000];$posts[10001]->ID=10001;unset($posts[3]);check('large library discovery fixes an upper boundary and reads at most five IDs',$boundary===['upper'=>10000,'total'=>10000]&&$d->page('all_posts',0,10000,500) === [1,2,4,5,6]);check('new records are excluded from an existing scan',$d->page('all_posts',9998,10000,5)===[9999,10000]);check('Woo-off product discovery is unavailable',is_wp_error($d->boundary('all_products')));$wpdb->failRead=true;check('discovery errors are distinguishable from end of scan',is_wp_error($d->page('all_posts',0,10000,5)));
[$s,$b,$v]=setup();$j=$s->start('all_posts',10,10,1);$contextCallback=function(){$GLOBALS['clock']+=121;};$v->run_batch($j['id']);check('expired preparation lease cannot dispatch a provider request',!$b->calls&&job()['cursorId']===0);$contextCallback=null;
foreach(['pause','cancel'] as $control){[$s,$b,$v]=setup();$j=$s->start('all_posts',10,10,1);$contextCallback=fn()=>$s->control($j['id'],$control);$v->run_batch($j['id']);check($control.' during preparation prevents dispatch and releases the lease',!$b->calls&&!job()['lease']);}
[$s,$b,$v]=setup(1);$j=$s->start('all_posts',1,1,1);$v->run_batch($j['id']);$contextCallback=function(){$GLOBALS['clock']+=121;};review($v,'regenerate');check('expired review preparation cannot dispatch a second request',count($b->calls)===1&&count(job()['proposals'])===1);
[$s,$b,$v]=setup(25);$j=$s->start('all_posts',25,25,1);$actor=0;$v->run_batch($j['id']);check('cron uses the initiating owner permission and processes five records',job()['counts']['inspected']===5&&count($b->calls)===1&&count(job()['proposals'])===5);check('generation never applies native fields',$posts[1]->post_excerpt==='Existing excerpt'&&!$meta);$v->run_batch($j['id']);check('duplicate immediate work cannot spend another request',count($b->calls)===1);$events=[];$v->run_batch($j['id']);check('an early cron event reschedules the next eligible step',count($events)===1&&reset($events)===$clock+35);
for($i=0;$i<3;$i++){$clock+=35;$events=[];$v->run_batch($j['id']);}check('twenty review slots pause discovery before the full library is retained',job()['status']==='review'&&count(job()['proposals'])===20&&job()['cursorId']===20&&strlen($wpdb->raw)<262144);$clock+=35;$v->run_batch($j['id']);check('full buffer makes no further provider request',count($b->calls)===4&&is_wp_error($s->control($j['id'],'resume')));$actor=1;$key=array_key_first(job()['proposals']);$version=Store::proposal_version(job()['proposals'][$key]);review($v,'regenerate');check('stale review forms cannot accept a regenerated proposal',is_wp_error($s->claim($j['id'],$key,$version)));unset($posts[1]);review($v,'reject');check('owner can dismiss a proposal for a removed record',job()['counts']['dismissed']===1&&count(job()['proposals'])===19);$s->control($j['id'],'resume');$v->run_batch($j['id']);check('freed capacity bounds the next request to one record',job()['cursorId']===21&&count(job()['proposals'])===20);
[$s,$b,$v]=setup(1);$j=$s->start('all_posts',1,1,1);$v->run_batch($j['id']);review($v,'accept');check('accept writes native fields with slashing and verifies before counting',job()['status']==='complete'&&job()['counts']['applied']===1&&!job()['proposals']&&$posts[1]->post_excerpt==="Reader's excerpt"&&$meta[1]['_kiwe_seo_title']==='Reviewed title');
[$s,$b,$v]=setup(1);$j=$s->start('all_posts',1,1,1);$v->run_batch($j['id']);$failMeta=true;review($v,'accept');check('partial native failure retains proposal and does not claim acceptance',job()['counts']['applied']===0&&count(job()['proposals'])===1&&!isset($meta[1]['_kiwe_seo_review_status'])&&str_contains($receipt,'some fields may have saved'));
[$s,$b,$v]=setup(1);$j=$s->start('all_posts',1,1,1);$v->run_batch($j['id']);$failPost=true;review($v,'accept');check('native post write failure does not write SEO metadata',job()['counts']['applied']===0&&!$meta);
[$s,$b,$v]=setup(1);$posts[1]->post_content=str_repeat('x',5000);$j=$s->start('all_posts',1,1,1);$v->run_batch($j['id']);$posts[1]->post_content.='changed beyond prompt excerpt';review($v,'accept');check('full native source hash prevents applying a stale truncated-content proposal',job()['counts']['applied']===0&&!$meta);
[$s,$b,$v]=setup(4);$posts[1]->post_status='private';$posts[2]->post_password='synthetic';$denied=[3];$j=$s->start('all_posts',4,4,1);$v->run_batch($j['id']);$sent=json_decode($b->calls[0]['user'],true)['records'];check('private protected and uneditable posts are excluded before transport',array_column($sent,'id')===[4]&&job()['counts']['skipped']===3);
[$s,$b,$v]=setup(2);$posts[2]->post_type='attachment';$posts[2]->post_status='inherit';$posts[2]->post_parent=1;$posts[1]->post_status='private';$j=$s->start('all_media',2,1,1);$v->run_batch($j['id']);check('media belonging to a private parent never reaches AI',!$b->calls&&job()['status']==='complete');
[$s,$b,$v]=setup(1);$j=$s->start('all_posts',1,1,1);$b->callback=function(){$GLOBALS['posts'][1]->post_password='new protection';};$v->run_batch($j['id']);check('fresh cache read discards content protected during provider work',!job()['proposals']&&job()['counts']['skipped']===1);
foreach(['permission','feature','pause','cancel'] as $change){[$s,$b,$v]=setup(1);$j=$s->start('all_posts',1,1,1);$b->callback=function()use($change,$s,$j,$b){if($change==='permission')$GLOBALS['owner'][1]=false;elseif($change==='feature')$b->enabled=false;else $s->control($j['id'],$change);};$v->run_batch($j['id']);$state=job();check('in-flight '.$change.' remains authoritative',$change==='pause'?($state['status']==='paused'&&count($state['proposals'])===1):(!$state['proposals']&&$state['status']===($change==='cancel'?'cancelled':'paused')));}
[$s,$b,$v]=setup();$j=$s->start('all_posts',10,10,1);$owner[1]=false;$v->run_batch($j['id']);check('revoked creator cannot dispatch another request',!$b->calls&&job()['status']==='paused');
[$s,$b,$v]=setup();$j=$s->start('all_posts',10,10,1);$b->response=['ok'=>false,'error'=>['code'=>'site_ai_day_limit'],'retryAt'=>$clock+3600];$v->run_batch($j['id']);check('quota denial preserves cursor and records a manual resume time',job()['status']==='paused'&&job()['cursorId']===0&&job()['nextAt']===$clock+3600&&job()['batchSize']===2&&is_wp_error($s->control($j['id'],'resume')));$clock+=3600;$b->response=null;$s->control($j['id'],'resume');$v->run_batch($j['id']);check('resumption uses the reduced batch without losing records',job()['cursorId']===2&&count(job()['proposals'])===2);
[$s,$b,$v]=setup();$j=$s->start('all_posts',10,10,1);$b->response=['ok'=>true,'validation'=>['data'=>['schema'=>'wrong','proposals'=>[]]]];$v->run_batch($j['id']);check('malformed provider contract pauses without advancing cursor',job()['status']==='paused'&&job()['cursorId']===0);
[$s,$b,$v]=setup();$record=private_call($v,'record',1,'all_posts');$clean=private_call($v,'sanitize_proposals',[['id'=>[1],'fields'=>['seoTitle'=>'bad']]],[$record],'all_posts');check('malformed object IDs cannot select a real record',!$clean);$clean=private_call($v,'sanitize_proposals',[['id'=>1,'fields'=>['searchPhrases'=>[str_repeat('漢',80),['bad']],'seoTitle'=>'safe']]],[$record],'all_posts');check('proposal truncation retains valid UTF-8 and excludes nested phrase values',json_encode($clean)!==false&&count(reset($clean)['fields']['searchPhrases'])===1);
$posts[1]->post_type='attachment';$posts[1]->post_status='inherit';clean_post_cache(1);$record=private_call($v,'record',1,'all_media');$clean=private_call($v,'sanitize_proposals',[['id'=>1,'fields'=>['title'=>'','alt'=>'','caption'=>'','description'=>'']]],[$record],'all_media');check('missing visual evidence never authorizes clearing existing metadata',!$clean);
foreach(['GET','role','nonce'] as $denial){[$s,$b,$v]=setup();$before=$wpdb->raw;if($denial==='GET')$_SERVER['REQUEST_METHOD']='GET';if($denial==='role')$actor=2;if($denial==='nonce')$nonce=false;try{$v->handle_start();$blocked=false;}catch(RuntimeException $e){$blocked=true;}check($denial.' cannot start jobs or call a provider',$blocked&&$wpdb->raw===$before&&!$b->calls);}
[$s,$b,$v]=setup();ob_start();$v->render();$html=ob_get_clean();check('SEO screen does not recompute the full-library score',str_contains($html,'Start a library review'));$v->menu();$v->assets('other');$v->assets('kiwe_page_kiwe-seo');check('workspace stylesheet is scoped to its authorized screen',count($styles)===1);
[$s,$b,$v]=setup(2);$posts[2]->post_status='draft';$v->object_routes();check('SEO registers object, category and bounded history routes',count($objectRoutes)===12);
$list=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','page'=>1]));check('opening the per-object workspace lists editable drafts without spending an AI request',$list->status===200&&$list->data['schema']==='kiwe.seo-object-workspace.v1'&&count($list->data['items'])===2&&$list->data['items'][0]['status']==='draft'&&!$b->calls);
$focused=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','item_id'=>1]));$missing=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','item_id'=>999]));
check('exact post link returns one authorized record without scanning or spending AI',$focused->status===200&&array_column($focused->data['items'],'id')===[1]&&$focused->data['more']===false&&$missing->status===404&&!$b->calls);
[$s,$b,$v]=setup(3);$terms[10]=new WP_Term(10,'category','Blog','',0,2);$termPosts['category'][10]=[1,3];
$filtered=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','category'=>'category:10']));check('category context filters the native post query and keeps one selected filter option',$filtered->status===200&&array_column($filtered->data['items'],'id')===[3,1]&&$filtered->data['category']==='category:10'&&$objectQuery['tax_query'][0]['terms']===[10]);
$focusedCategory=$v->read_objects(new WP_REST_Request('GET',['scope'=>'category','taxonomy'=>'category','term_id'=>10]));check('SEO Categories context opens exactly the native category card',$focusedCategory->status===200&&array_column($focusedCategory->data['items'],'id')===[10]&&$focusedCategory->data['items'][0]['scope']==='term'&&$focusedCategory->data['items'][0]['viewUrl']==='https://example.test/category/10/');
$allCategories=$v->read_objects(new WP_REST_Request('GET',['scope'=>'category']));check('clearing category context returns the bounded Categories collection',$allCategories->status===200&&array_column($allCategories->data['items'],'id')===[10]&&$allCategories->data['more']===false);
$actor=2;$forbiddenCategory=$v->read_objects(new WP_REST_Request('GET',['scope'=>'category','taxonomy'=>'category','term_id'=>10]));check('role without native category authority cannot browse category SEO',$forbiddenCategory->status===403);$actor=1;
$invalidCategory=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','category'=>'product_cat:10']));check('post SEO refuses an unrelated taxonomy filter',$invalidCategory->status===403);
$list=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','page'=>1]));$item=$list->data['items'][0];
$item=$list->data['items'][0];$bad=$v->propose_object(new WP_REST_Request('POST',['id'=>$item['id']],['scope'=>'post','expected'=>str_repeat('0',64)]));check('stale object proof cannot spend an AI request',$bad->status===409&&!$b->calls);
$proposal=$v->propose_object(new WP_REST_Request('POST',['id'=>$item['id']],['scope'=>'post','expected'=>$item['expected']]));$token=$proposal->data['proposalToken']??'';check('explicit object review makes one bounded request and returns an opaque actor-bound proposal',$proposal->status===200&&preg_match('/^[a-f0-9]{32}$/D',$token)&&count($b->calls)===1&&($proposal->data['proposal']['id']??0)===$item['id']);
$owner[2]=true;$actor=2;$foreign=$v->apply_object_proposal(new WP_REST_Request('POST',['id'=>$item['id']],['proposalToken'=>$token,'expected'=>$item['expected']]));check('another signed-in actor cannot apply a held proposal',$foreign->status===409&&!isset($meta[$item['id']]['_kiwe_seo_title']));$actor=1;
$applied=$v->apply_object_proposal(new WP_REST_Request('POST',['id'=>$item['id']],['proposalToken'=>$token,'expected'=>$item['expected']]));check('approved object suggestions write native fields once and consume the held proposal',$applied->status===200&&$applied->data['saved']===true&&$meta[$item['id']]['_kiwe_seo_review_status']==='accepted'&&$posts[$item['id']]->post_excerpt==="Reader's excerpt"&&!$transients);
[$s,$b,$v]=setup(1);$list=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post']));$item=$list->data['items'][0];$proposal=$v->propose_object(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>$item['expected']]));$posts[1]->post_content.=' changed after review';clean_post_cache(1);$staleApply=$v->apply_object_proposal(new WP_REST_Request('POST',['id'=>1],['proposalToken'=>$proposal->data['proposalToken'],'expected'=>$item['expected']]));check('content changed after review cannot be marked accepted',$staleApply->status===409&&!isset($meta[1]['_kiwe_seo_review_status']));
[$s,$b,$v]=setup(1);$crossOrigin=true;$deniedList=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post']));$crossOrigin=false;$noMutation=$v->propose_object(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>str_repeat('0',64)],['X-WP-Nonce'=>'wp_rest']));check('same-site nonce and mutation proof protect every per-object SEO request',$deniedList->status===403&&$noMutation->status===403&&!$b->calls);
[$s,$b,$v]=setup(10);$technical[1]=false;$preferences[1]=true;
$history=$v->read_history(new WP_REST_Request('GET'));
check('client Administrator reads historical progress without technical manage_options or provider use',$history->status===200&&$history->data['job']===null&&!$b->calls);
$started=$v->start_history(new WP_REST_Request('POST',[],['scope'=>'all_posts']));$historyId=$started->data['job']['id']??'';
check('client starts one bounded native job and schedules work without spending in the request',$started->status===200&&preg_match('/^[a-f0-9]{32}$/D',$historyId)&&count($events)===1&&!$b->calls);
$repeat=$v->start_history(new WP_REST_Request('POST',[],['scope'=>'all_posts']));check('an active library job cannot be replaced',$repeat->status===409&&job()['id']===$historyId&&!$b->calls);
$actor=2;$preferences[2]=false;$owner[2]=true;$deniedHistory=$v->read_history(new WP_REST_Request('GET'));$deniedStart=$v->start_history(new WP_REST_Request('POST',[],['scope'=>'all_posts']));
check('editorial role cannot see or mutate the sitewide job',$deniedHistory->status===403&&$deniedStart->status===403&&!$b->calls);
$actor=1;$badControl=$v->control_history(new WP_REST_Request('POST',[],['jobId'=>str_repeat('0',32),'action'=>'cancel']));check('stale job control preserves current work',$badControl->status===409&&job()['status']==='queued');
$paused=$v->control_history(new WP_REST_Request('POST',[],['jobId'=>$historyId,'action'=>'pause']));check('client pause retires the scheduled worker',$paused->status===200&&job()['status']==='paused'&&!$events);
$resumed=$v->control_history(new WP_REST_Request('POST',[],['jobId'=>$historyId,'action'=>'resume']));check('client resume preserves the native cursor and schedules work',$resumed->status===200&&job()['status']==='queued'&&count($events)===1);
$v->run_batch($historyId);$history=$v->read_history(new WP_REST_Request('GET'));$candidate=$history->data['job']['proposals'][0]??[];
check('explicit worker step exposes separated progress and pending review without native application',$history->status===200&&$history->data['job']['counts']['inspected']===5&&$history->data['job']['percent']===50&&count($history->data['job']['proposals'])===5&&count($b->calls)===1&&!$meta);
$denied=[$candidate['objectId']];$hidden=$v->read_history(new WP_REST_Request('GET'));$forged=$v->review_history(new WP_REST_Request('POST',[],['jobId'=>$historyId,'proposalId'=>$candidate['id'],'version'=>$candidate['version'],'decision'=>'reject']));
check('record permission loss hides proposal and denies forged dismissal',$hidden->status===200&&count($hidden->data['job']['proposals'])===4&&$forged->status===403&&job()['counts']['dismissed']===0);
$denied=[];$wrongVersion=$v->review_history(new WP_REST_Request('POST',[],['jobId'=>$historyId,'proposalId'=>$candidate['id'],'version'=>str_repeat('0',64),'decision'=>'accept']));check('stale proposal version cannot apply native fields',$wrongVersion->status===409&&!$meta);
$accepted=$v->review_history(new WP_REST_Request('POST',[],['jobId'=>$historyId,'proposalId'=>$candidate['id'],'version'=>$candidate['version'],'decision'=>'accept']));
check('client review applies and confirms one native record',$accepted->status===200&&$accepted->data['job']['counts']['applied']===1&&$meta[$candidate['objectId']]['_kiwe_seo_title']==='Reviewed title');
[$s,$b,$v]=setup(1);$technical[1]=false;$preferences[1]=true;$j=$s->start('all_posts',1,1,1);$v->run_batch($j['id']);$candidate=$v->read_history(new WP_REST_Request('GET'))->data['job']['proposals'][0];$failMeta=true;$failed=$v->review_history(new WP_REST_Request('POST',[],['jobId'=>$j['id'],'proposalId'=>$candidate['id'],'version'=>$candidate['version'],'decision'=>'accept']));
check('unconfirmed native write reports failure and retains review',$failed->status===409&&job()['counts']['applied']===0&&count(job()['proposals'])===1);
[$s,$b,$v]=setup(2);$posts[2]->post_type='attachment';$posts[2]->post_status='inherit';$posts[2]->post_parent=1;
$focusedMedia=$v->read_objects(new WP_REST_Request('GET',['scope'=>'media','item_id'=>2]));$wrongMedia=$v->read_objects(new WP_REST_Request('GET',['scope'=>'media','item_id'=>1]));
check('exact media link targets its editable attachment and rejects another type',$focusedMedia->status===200&&array_column($focusedMedia->data['items'],'id')===[2]&&$wrongMedia->status===404&&!$b->calls);
// The active app edits Kiwe-owned metadata. External-provider admission is tested below.
[$s,$b,$v]=setup(1);$meta[1]['_kiwe_seo_title']='Existing Rank title';$meta[1]['_kiwe_seo_description']='Existing Rank description';$meta[1]['_kiwe_search_phrases']=['existing keyword'];
$focused=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','item_id'=>1]));$item=$focused->data['items'][0];
check('Kiwe SEO AI reads the current Kiwe fields before proposing',$item['current']['seoTitle']==='Existing Rank title'&&$item['current']['metaDescription']==='Existing Rank description'&&$item['current']['searchPhrases']===['existing keyword']);
$proposal=$v->propose_object(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>$item['expected']]));$token=$proposal->data['proposalToken']??'';
$applied=$v->apply_object_proposal(new WP_REST_Request('POST',['id'=>1],['proposalToken'=>$token,'expected'=>$item['expected']]));
check('reviewed AI fields update the native Kiwe owner without creating a Rank Math shadow',$applied->status===200&&$meta[1]['_kiwe_seo_title']==='Reviewed title'&&$meta[1]['_kiwe_seo_description']==='Verified description'&&$meta[1]['_kiwe_search_phrases']===['useful phrase']&&!isset($meta[1]['rank_math_title']));
[$s,$b,$v]=setup(1);$meta[1]['_kiwe_seo_title']='Before';$focused=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','item_id'=>1]));$item=$focused->data['items'][0];$proposal=$v->propose_object(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>$item['expected']]));$meta[1]['_kiwe_seo_title']='Edited elsewhere';$stale=$v->apply_object_proposal(new WP_REST_Request('POST',['id'=>1],['proposalToken'=>$proposal->data['proposalToken'],'expected'=>$item['expected']]));
check('a concurrent native SEO edit invalidates the held AI proposal',$stale->status===409&&$meta[1]['_kiwe_seo_title']==='Edited elsewhere'&&!isset($meta[1]['_kiwe_seo_review_status']));
[$s,$b,$v]=setup(1);$item=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','item_id'=>1]))->data['items'][0];
$manual=$v->save_object_fields(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>$item['expected'],'fields'=>['seoTitle'=>'Manual title','metaDescription'=>'Manual description','excerpt'=>'Human summary','searchPhrases'=>['one phrase']]]));
check('manual SEO edits write and verify active native fields without AI dispatch',$manual->status===200&&$meta[1]['_kiwe_seo_title']==='Manual title'&&$posts[1]->post_excerpt==='Human summary'&&!$b->calls);
$staleManual=$v->save_object_fields(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>$item['expected'],'fields'=>['seoTitle'=>'Overwrite']]));
check('manual SEO save rejects stale record fingerprint',$staleManual->status===409&&$meta[1]['_kiwe_seo_title']==='Manual title');
[$s,$b,$v]=setup(1);$item=$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','item_id'=>1]))->data['items'][0];
$retry=$v->propose_object(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>$item['expected'],'previous'=>['seoTitle'=>'Earlier suggestion','metaDescription'=>'Older wording','unsupported'=>'ignore'] ]));
$sent=json_decode($b->calls[0]['user']??'{}',true);
check('AI retry receives bounded previous wording but performs no native write',$retry->status===200&&($sent['previousSuggestion']['seoTitle']??'')==='Earlier suggestion'&&!isset($sent['previousSuggestion']['unsupported'])&&empty($meta));
$terms[7]=new WP_Term(7,'category','Synthetic category','Verified editorial topic');
$term=$v->read_term_seo(new WP_REST_Request('GET',['taxonomy'=>'category','id'=>7]))->data['items'][0];
$suggested=$v->propose_term_seo(new WP_REST_Request('POST',['taxonomy'=>'category','id'=>7],['expected'=>$term['expected']]));
check('category AI returns an editable proposal without writing term metadata',$suggested->status===200&&$suggested->data['proposal']['fields']['seoTitle']==='Reviewed title'&&empty($termMeta));
$savedTerm=$v->save_term_seo(new WP_REST_Request('POST',['taxonomy'=>'category','id'=>7],['expected'=>$term['expected'],'fields'=>['seoTitle'=>'Category search title','metaDescription'=>'Category description','searchPhrases'=>['topic']]]));
check('category SEO saves into the active Kiwe term owner and confirms readback',$savedTerm->status===200&&$termMeta[7]['_kiwe_seo_title']==='Category search title'&&$savedTerm->data['item']['current']['searchPhrases']===['topic']);
$oldTerm=$v->save_term_seo(new WP_REST_Request('POST',['taxonomy'=>'category','id'=>7],['expected'=>$term['expected'],'fields'=>['seoTitle'=>'Old','metaDescription'=>'Old','searchPhrases'=>[]]]));
check('category SEO rejects stale save without changing metadata',$oldTerm->status===409&&$termMeta[7]['_kiwe_seo_title']==='Category search title');
$actor=2;$forbiddenTerm=$v->read_term_seo(new WP_REST_Request('GET',['taxonomy'=>'category','id'=>7]));
check('another actor cannot inspect category SEO fields',$forbiddenTerm->status===403);$actor=1;
[$s,$b,$v]=setup(1);$posts[1]->post_type='page';$posts[1]->post_author=1;$pageSeoClient=true;$actor=2;
$pageFocus=$v->read_objects(new WP_REST_Request('GET',['scope'=>'page','item_id'=>1]));$pageList=$v->read_objects(new WP_REST_Request('GET',['scope'=>'page']));$pageItem=$pageFocus->data['items'][0]??[];
check('client page SEO opens another author page without generic page-content authority',$pageFocus->status===200&&count($pageList->data['items']??[])===1&&!isset($pageItem['current']['excerpt'])&&!current_user_can('edit_post',1));
$pageExcerpt=$v->save_object_fields(new WP_REST_Request('POST',['id'=>1],['scope'=>'page','expected'=>$pageItem['expected'],'fields'=>['excerpt'=>'Should not write']]));
check('client page SEO cannot replace native page excerpt',$pageExcerpt->status===400&&$posts[1]->post_excerpt==='Existing excerpt');
$pageSave=$v->save_object_fields(new WP_REST_Request('POST',['id'=>1],['scope'=>'page','expected'=>$pageItem['expected'],'fields'=>['seoTitle'=>'Page search title','metaDescription'=>'Page search description','searchPhrases'=>['page topic']]]));
check('client page SEO saves metadata with native readback while preserving page content',$pageSave->status===200&&$meta[1]['_kiwe_seo_title']==='Page search title'&&$posts[1]->post_excerpt==='Existing excerpt'&&!$b->calls);
$pageProposal=$v->propose_object(new WP_REST_Request('POST',['id'=>1],['scope'=>'page','expected'=>$pageSave->data['item']['expected']]));
check('client page AI proposal is review-only and omits page-content fields',$pageProposal->status===200&&!isset($pageProposal->data['proposal']['fields']['excerpt'])&&$posts[1]->post_excerpt==='Existing excerpt');
$pageApplied=$v->apply_object_proposal(new WP_REST_Request('POST',['id'=>1],['proposalToken'=>$pageProposal->data['proposalToken'],'expected'=>$pageSave->data['item']['expected']]));
check('reviewed client page SEO updates only search metadata',$pageApplied->status===200&&$meta[1]['_kiwe_seo_title']==='Reviewed title'&&$posts[1]->post_excerpt==='Existing excerpt');
$posts[1]->post_password='protected';clean_post_cache(1);$protected=$v->read_objects(new WP_REST_Request('GET',['scope'=>'page','item_id'=>1]));
check('protected pages do not enter client page SEO',$protected->status===404);$pageSeoClient=false;$actor=1;
// Rank Math now keeps the app off: its current native metadata and AI allowance remain untouched.
[$s,$b,$v]=setup(1);define('RANK_MATH_VERSION','synthetic-late');$meta[1]['rank_math_title']='Provider-owned title';$externalBefore=$meta;
check('external SEO provider disables the app instead of opening a second editor',!DSA\Apps\SEO_App_Service::enabled()&&$v->read_objects(new WP_REST_Request('GET',['scope'=>'post','item_id'=>1]))->status===403);
check('external ownership refuses manual Kiwe SEO changes',$v->save_object_fields(new WP_REST_Request('POST',['id'=>1],['scope'=>'post','expected'=>str_repeat('0',64),'fields'=>['seoTitle'=>'Override']]))->status===403&&$meta===$externalBefore&&!$b->calls);
check('external ownership refuses term SEO route permission',!$v->term_permission(new WP_REST_Request('POST',['taxonomy'=>'category','id'=>7])));
echo count(array_filter($checks)).'/'.count($checks).' SEO job behavior checks passed.'.PHP_EOL;exit(in_array(false,$checks,true)?1:0);
}
