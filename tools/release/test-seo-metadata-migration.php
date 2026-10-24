<?php
declare(strict_types=1);
require __DIR__ . '/workspace-test-bootstrap.php';
define('ARRAY_A','ARRAY_A');
final class WP_Post {
 public function __construct(public int $ID,public string $post_type,public string $post_status,public string $post_title,public string $post_excerpt='',public string $post_content='',public string $post_date='2026-10-07 00:00:00'){}
}
final class WP_Term {
 public function __construct(public int $term_id,public string $taxonomy,public string $name){}
}
final class WP_REST_Request {
 public function __construct(private string $method='GET',private array $body=[],private array $headers=['X-WP-Nonce'=>'wp_rest','x-kiwe-mutation'=>'1']){}
 public function get_method():string{return $this->method;}
 public function get_header(string $key):string{return (string)($this->headers[$key]??'');}
 public function get_json_params():array{return $this->body;}
}
final class WP_REST_Response {
 public array $headers=[];
 public function __construct(public array $data,public int $status=200){}
 public function header(string $name,string $value):void{$this->headers[$name]=$value;}
}
$source_keys=['rank_math_title','rank_math_description','rank_math_focus_keyword','rank_math_robots','rank_math_canonical_url','rank_math_facebook_title','rank_math_facebook_description','rank_math_facebook_image','rank_math_twitter_title','rank_math_twitter_description','rank_math_twitter_image'];
$posts=[201=>new WP_Post(201,'page','publish','About Kiwe','A short excerpt.','Page body.'),202=>new WP_Post(202,'post','publish','Launch story','','A longer story body.'),203=>new WP_Post(203,'post','draft','Unresolved template','','Draft body.')];
$terms=[41=>new WP_Term(41,'category','Updates'),42=>new WP_Term(42,'post_tag','News')];
$relationships=[201=>[41]];
$postmeta=[201=>['rank_math_title'=>'%title% %sep% %sitename%','rank_math_description'=>'Imported description','rank_math_focus_keyword'=>'WordPress, client dashboard, WordPress','rank_math_robots'=>['noindex','nofollow','invalid'],'rank_math_canonical_url'=>'https://example.test/about-canonical/','rank_math_facebook_title'=>'Social %title%','rank_math_facebook_image'=>'https://example.test/social.jpg','rank_math_schema_BlogPosting'=>'unsupported-source-remains','_kiwe_seo_description'=>'Client-written description'],202=>['rank_math_title'=>'Imported story title','rank_math_description'=>'Imported story description','rank_math_twitter_title'=>'Tweet %title%','_kiwe_seo_title'=>'Existing Kiwe title'],203=>['rank_math_title'=>'%unsupported_variable%']];
$termmeta=[41=>['rank_math_title'=>'%term% %sep% %sitename%','rank_math_description'=>'%term_description%','rank_math_robots'=>['noindex']],42=>['rank_math_title'=>'%unknown_variable%']];
$schedules=[];$rest_routes=[];$cache_cleans=[];
function add_action(...$args):void{}
function do_action(...$args):void{if(isset($GLOBALS['metadata_action']))($GLOBALS['metadata_action'])(...$args);}
function register_rest_route(string $namespace,string $route,array $args):void{$GLOBALS['rest_routes'][$route]=$args;}
function wp_json_encode($value,int $flags=0){return json_encode($value,$flags);}
function esc_sql($value):string{return str_replace("'","''",(string)$value);}
function esc_url_raw($value):string{return filter_var((string)$value,FILTER_VALIDATE_URL)?(string)$value:'';}
function wp_slash($value){return is_array($value)?array_map('wp_slash',$value):(is_string($value)?addslashes($value):$value);}
function stripslashes_deep_for_test($value){return is_array($value)?array_map('stripslashes_deep_for_test',$value):(is_string($value)?stripslashes($value):$value);}
function wp_strip_all_tags($value):string{return strip_tags((string)$value);}
function wp_trim_words($text,$words=55,$more=null):string{return implode(' ',array_slice(preg_split('/\s+/',trim((string)$text))?:[],0,(int)$words));}
function get_bloginfo($field=''):string{return 'Kiwe Studio';}
function is_singular():bool{return true;}
function is_page():bool{return 'page'===get_post_type(get_queried_object_id());}
function get_queried_object_id():int{return 201;}
function is_front_page():bool{return false;} function is_home():bool{return false;} function is_preview():bool{return false;} function is_search():bool{return false;} function is_404():bool{return false;} function is_category():bool{return false;} function is_tag():bool{return false;} function is_tax():bool{return false;}
function is_date():bool{return false;}
function post_password_required():bool{return false;}
function get_post_status($id):string{return get_post($id)->post_status;}
function get_query_var($key){return 0;}
function mysql2date($format,$date):string{return $date;}
function wp_date($format):string{return '2026';}
function strip_shortcodes($value):string{return $value;}
function get_permalink($post):string{return 'https://example.test/about/';}
function get_post_types($args):array{return ['post'=>'post','page'=>'page','product'=>'product'];}
function get_taxonomies($args):array{return ['category'=>'category','post_tag'=>'post_tag'];}
function get_the_terms($post,$taxonomy){$id=$post instanceof WP_Post?$post->ID:(int)$post;return array_values(array_filter(array_map(fn($term_id)=>$GLOBALS['terms'][$term_id]??null,$GLOBALS['relationships'][$id]??[])));}
function metadata_exists(string $type,int $id,string $key):bool{return array_key_exists($key,$GLOBALS[$type.'meta'][$id]??[]);}
function get_post_meta(int $id,string $key='',bool $single=false){return $key===''?($GLOBALS['postmeta'][$id]??[]):($GLOBALS['postmeta'][$id][$key]??($single?'':[]));}
function get_term_meta(int $id,string $key='',bool $single=false){return $key===''?($GLOBALS['termmeta'][$id]??[]):($GLOBALS['termmeta'][$id][$key]??($single?'':[]));}
function update_post_meta(int $id,string $key,$value){$GLOBALS['postmeta'][$id][$key]=stripslashes_deep_for_test($value);return true;}
function update_term_meta(int $id,string $key,$value){$GLOBALS['termmeta'][$id][$key]=stripslashes_deep_for_test($value);return true;}
function delete_post_meta(int $id,string $key,$value=''):bool{if($value!==''&&($GLOBALS['postmeta'][$id][$key]??null)!==$value)return false;unset($GLOBALS['postmeta'][$id][$key]);return true;}
function delete_term_meta(int $id,string $key,$value=''):bool{if($value!==''&&($GLOBALS['termmeta'][$id][$key]??null)!==$value)return false;unset($GLOBALS['termmeta'][$id][$key]);return true;}
function add_post_meta(int $id,string $key,$value,bool $unique=false){if($unique&&metadata_exists('post',$id,$key))return false;return update_post_meta($id,$key,$value);}
function add_term_meta(int $id,string $key,$value,bool $unique=false){if($unique&&metadata_exists('term',$id,$key))return false;return update_term_meta($id,$key,$value);}
function maybe_serialize($value){return is_array($value)?serialize($value):$value;}
function wp_cache_delete($key,$group=''):bool{return true;}
function clean_post_cache(int $id):void{$GLOBALS['cache_cleans'][]=$id;}
function clean_term_cache(int $id):void{$GLOBALS['cache_cleans'][]=$id;}
function update_option($key,$value,$autoload=null):bool{$GLOBALS['options'][$key]=$value;return true;}
function wp_schedule_single_event(int $timestamp,string $hook,array $args=[]):bool{$GLOBALS['schedules'][]=[$timestamp,$hook,$args];return true;}
function wp_next_scheduled(string $hook,array $args=[]){foreach($GLOBALS['schedules'] as $event)if($event[1]===$hook&&$event[2]===$args)return $event[0];return false;}
function wp_parse_url($url){return parse_url((string)$url);}
function site_url($path='/'):string{return home_url($path);}
final class SeoMigrationDB {
 public string $posts='wp_posts',$postmeta='wp_postmeta',$terms='wp_terms',$termmeta='wp_termmeta',$term_taxonomy='wp_term_taxonomy',$last_error='',$dbname='synthetic',$prefix='wp_';
 public function prepare(string $sql,...$args):array{return ['sql'=>$sql,'args'=>$args];}
 public function get_row($sql,$output):array{
  $type=str_contains($sql,'wp_terms')?'term':'post';$records=0;$fields=0;$upper=0;$marker=0;
  foreach($GLOBALS[$type==='term'?'terms':'posts'] as $id=>$record){$count=0;foreach($GLOBALS[$type.'meta'][$id]??[] as $key=>$value)if(in_array($key,$GLOBALS['source_keys'],true)){++$count;++$fields;++$marker;}if($count){++$records;$upper=max($upper,$id);}}
  return compact('records','fields','upper','marker');
 }
 public function get_col($prepared):array{
  if($GLOBALS['fail_source_read']??false){$this->last_error='Synthetic source read failure';return[];}$this->last_error='';
  $args=$prepared['args'];$type=str_contains($prepared['sql'],'wp_terms')||str_contains($prepared['sql'],'wp_termmeta')?'term':'post';$rollback=str_contains($prepared['sql'],'meta_value=%s');$ids=[];
  foreach($GLOBALS[$type==='term'?'terms':'posts'] as $id=>$record){if($id<=(int)$args[0])continue;if($rollback){if(($GLOBALS[$type.'meta'][$id][$args[1]]??'')===$args[2])$ids[]=$id;}elseif($id<=(int)$args[1])foreach(array_keys($GLOBALS[$type.'meta'][$id]??[]) as $key)if(in_array($key,$GLOBALS['source_keys'],true)){$ids[]=$id;break;}}
  sort($ids);return array_slice($ids,0,(int)end($args));
 }
 public function get_var($prepared):int{if(str_contains($prepared['sql'],'IS_USED_LOCK'))return !empty($GLOBALS['metadata_lease_lost'])?0:1;if(str_contains($prepared['sql'],'GET_LOCK'))return !empty($GLOBALS['migration_busy'])?0:1;if(str_contains($prepared['sql'],'RELEASE_LOCK'))return 1;[$key,$job]=$prepared['args'];$type=str_contains($prepared['sql'],'wp_termmeta')?'term':'post';$count=0;foreach($GLOBALS[$type.'meta'] as $meta)if(($meta[$key]??'')===$job)++$count;return $count;}
 public function get_results($prepared,$output):array{[$id,$key]=$prepared['args'];$type=str_contains($prepared['sql'],'wp_termmeta')?'term':'post';if(!metadata_exists($type,$id,$key))return[];$row=['id'=>(string)abs(crc32($type.'|'.$id.'|'.$key)),'value'=>(string)maybe_serialize($GLOBALS[$type.'meta'][$id][$key])];return[$row];}
 public function query($prepared){
  $type=str_contains($prepared['sql'],'wp_termmeta')?'term':'post';$update=str_starts_with($prepared['sql'],'UPDATE');
  if(str_starts_with($prepared['sql'],'DELETE j FROM')){
   [$bid,$bk,$bv,$mid,$mk,$mv,$id,$lock]=$prepared['args'];
   if(!empty($GLOBALS['metadata_retire_failure'])){$this->last_error='synthetic joint retirement failed';return false;}
   if(!empty($GLOBALS['metadata_lease_lost'])||!metadata_exists($type,$id,$bk)||!metadata_exists($type,$id,$mk)||(string)maybe_serialize($GLOBALS[$type.'meta'][$id][$bk])!==(string)$bv||$GLOBALS[$type.'meta'][$id][$mk]!==$mv)return 0;
   unset($GLOBALS[$type.'meta'][$id][$bk],$GLOBALS[$type.'meta'][$id][$mk]);return 2;
  }
  if($update)[$next,$mid,$id,$key,$expected]=$prepared['args'];else[$mid,$id,$key,$expected]=$prepared['args'];
  if(isset($GLOBALS['metadata_race'][$key])){[$race,$value]=$GLOBALS['metadata_race'][$key];unset($GLOBALS['metadata_race'][$key]);if($race==='delete')unset($GLOBALS[$type.'meta'][$id][$key]);else$GLOBALS[$type.'meta'][$id][$key]=$value;}
  if(!empty($GLOBALS['metadata_sql_failure'])){$this->last_error='synthetic conditional write failed';return false;}
  if(!metadata_exists($type,$id,$key)||(string)maybe_serialize($GLOBALS[$type.'meta'][$id][$key])!==(string)$expected)return 0;
  if($update)$GLOBALS[$type.'meta'][$id][$key]=str_starts_with((string)$next,'a:')?unserialize($next):$next;else unset($GLOBALS[$type.'meta'][$id][$key]);return 1;
 }
}
$wpdb=new SeoMigrationDB();
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Settings_Handover_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Metadata_Migration_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Native_Indexing_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Output_Service.php';
use DSA\SEO\SEO_Metadata_Migration_Service as Migration;
use DSA\SEO\SEO_Sitemap_Service as Sitemaps;
$service=new Migration();$service->routes();
check(isset($rest_routes['/workspace/ai/seo-migration']),'private SEO migration route is registered');
$actor=6;$read=new WP_REST_Request();
check(!$service->permission($read)&&403===$service->read($read)->status,'Editor cannot read website-level migration inventory');
$actor=2;$snapshot=$service->read($read);
check(200===$snapshot->status&&3===$snapshot->data['source']['records']&&!$snapshot->data['source']['pluginActive'],'owner sees native metadata inventory without requiring Rank Math active');
check(2===$snapshot->data['termSource']['records'],'taxonomy metadata has its own reviewed scope');
check([]===$schedules&&!metadata_exists('post',201,'_kiwe_seo_title'),'reading inventory does not schedule work or write metadata');
$body=['operation'=>'start','confirmed'=>true,'expected'=>$snapshot->data['expected']];
check(400===$service->mutate(new WP_REST_Request('POST',array_replace($body,['confirmed'=>false])))->status,'import requires explicit confirmation before scheduling');
check(409===$service->mutate(new WP_REST_Request('POST',array_replace($body,['expected'=>str_repeat('0',64)])))->status,'stale inventory cannot schedule an import');
check(403===$service->mutate(new WP_REST_Request('POST',$body,[]))->status,'missing nonce and mutation proof refuse scheduling');
$source_before=hash('sha256',json_encode(array_map(fn($m)=>array_filter($m,fn($k)=>str_starts_with($k,'rank_math_'),ARRAY_FILTER_USE_KEY),$postmeta)));
$queued=$service->mutate(new WP_REST_Request('POST',$body));$job=$queued->data['active']['id'];
check(200===$queued->status&&'post'===$queued->data['active']['scope']&&count($schedules)===1,'confirmed post import stores one bounded queued job');
$post_before=$postmeta;$fail_source_read=true;$service->run($job);$paused=$service->status();
check($paused['active']['status']==='stalled'&&$paused['active']['reason']==='source_read_failed'&&$paused['active']['cursor']===0,'a failed source read stalls without completing or advancing the import');
check($postmeta===$post_before,'a failed source read preserves source and target metadata');
$fail_source_read=false;$wpdb->last_error='';
check(!metadata_exists('post',201,'_kiwe_seo_title'),'REST request never performs metadata work inline');
$postmeta[202][Migration::BACKUP_META]=['schema'=>2,'job'=>$job,'values'=>['_kiwe_seo_description'=>['exists'=>false,'value'=>'']],'imported'=>['_kiwe_seo_description'=>'partial worker value']];$postmeta[202]['_kiwe_seo_description']='partial worker value';
$service->run($job);
check(get_post_meta(201,'_kiwe_seo_title',true)==='%title% %sep% %sitename%','supported title variables remain live after import');
check(get_post_meta(201,'_kiwe_seo_description',true)==='Client-written description','fill-empty policy preserves client-authored metadata');
check(get_post_meta(201,'_kiwe_search_phrases',true)===['WordPress','client dashboard'],'focus phrases remain bounded and deduplicated');
check(get_post_meta(201,'_kiwe_seo_robots',true)===['noindex','nofollow']&&get_post_meta(201,'_kiwe_search_visibility',true)==='secondary','supported robots values align native Page visibility');
check(get_post_meta(201,'_kiwe_seo_canonical_url',true)==='https://example.test/about-canonical/','supported canonical metadata imports');
check(get_post_meta(202,'_kiwe_seo_title',true)==='Existing Kiwe title'&&get_post_meta(202,'_kiwe_seo_description',true)==='Imported story description','interrupted journal retry retains fill-empty precedence');
check(!metadata_exists('post',203,'_kiwe_seo_title'),'unknown title variables refuse import instead of leaking tokens');
$seo_context=new DSA\SEO\SEO_Output_Service(new DSA\Onboarding\Design_Context_Profile_Service());
check($seo_context->canonical_url('https://example.test/about/',$posts[201])==='https://example.test/about-canonical/','imported canonical controls Kiwe output');
check([]===Sitemaps::clean_entries([$seo_context->sitemap_entry(['loc'=>'https://example.test/about/'],$posts[201],'page')]),'noindex filters the URL list without false entries');
$robots=$seo_context->robots(['index'=>true,'follow'=>true]);
check(!empty($robots['noindex'])&&!empty($robots['nofollow'])&&!isset($robots['index'],$robots['follow']),'imported directives drive native robots output');
$complete=$service->status();
check(null===$complete['active']&&'complete'===$complete['lastImport']['status']&&2===$complete['rollbackRecords']&&$complete['canRollback'],'completed post import exposes aggregate results and rollback availability');
check($complete['lastImport']['unsupported']===2,'unknown source directives and variables remain visible as unsupported');
check(!str_contains(json_encode($complete),'Client-written description')&&!str_contains(json_encode($complete),'About Kiwe'),'job status contains no record content');
check($source_before===hash('sha256',json_encode(array_map(fn($m)=>array_filter($m,fn($k)=>str_starts_with($k,'rank_math_'),ARRAY_FILTER_USE_KEY),$postmeta))),'all Rank Math source fields remain exact');
check(409===$service->mutate(new WP_REST_Request('POST',array_replace($body,['expected'=>$complete['expected']])))->status,'retained journals prevent overlapping imports');
$rollback=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','confirmed'=>true,'expected'=>$complete['expected']]));
check(200===$rollback->status&&'rollback'===$rollback->data['active']['operation'],'explicit rollback queues the same bounded worker');
$service->run($rollback->data['active']['id']);
check(!metadata_exists('post',201,'_kiwe_seo_title')&&!metadata_exists('post',201,'_kiwe_search_phrases')&&!metadata_exists('post',201,'_kiwe_search_visibility'),'rollback removes imported fields absent before migration');
check($seo_context->canonical_url('https://example.test/about/',$posts[201])==='https://example.test/about/'&&count(Sitemaps::clean_entries([$seo_context->sitemap_entry(['loc'=>'https://example.test/about/'],$posts[201],'page')]))===1,'rollback restores canonical and sitemap behavior');
check(get_post_meta(201,'_kiwe_seo_description',true)==='Client-written description'&&get_post_meta(202,'_kiwe_seo_title',true)==='Existing Kiwe title','rollback preserves fields never replaced');
check(!metadata_exists('post',201,Migration::BACKUP_META)&&!metadata_exists('post',202,Migration::MARKER_META),'rollback retires completed journals');
$restored=$service->status();
check(null===$restored['lastImport']&&$restored['canStart']&&count($restored['history'])===2,'successful rollback restores clean bounded job state');
$term_before=$termmeta;
$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'term','confirmed'=>true,'expected'=>$restored['expected']]));$job=$queued->data['active']['id'];
check(200===$queued->status&&'term'===$queued->data['active']['scope'],'taxonomy import uses an explicit separate queue');
$service->run($job);
check(get_term_meta(41,'_kiwe_seo_title',true)==='%term% %sep% %sitename%'&&get_term_meta(41,'_kiwe_seo_robots',true)===['noindex'],'taxonomy title and robots import without post-meta writes');
check(!metadata_exists('term',42,'_kiwe_seo_title')&&$service->status()['lastImport']['unsupported']===1,'unsupported taxonomy variables refuse import');
$done=$service->status();$rollback=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','confirmed'=>true,'expected'=>$done['expected']]));$service->run($rollback->data['active']['id']);
check($term_before===$termmeta,'taxonomy rollback restores exact pre-import metadata');
require __DIR__ . '/fixtures/seo-metadata-recovery-cases.php';
define('RANK_MATH_VERSION','synthetic');$postmeta[201]['rank_math_robots']=['index'];$postmeta[201]['_kiwe_seo_robots']=['noindex'];$postmeta[201]['_kiwe_seo_canonical_url']='https://example.test/kiwe-fallback/';
check($seo_context->canonical_url('https://example.test/rank-math/',$posts[201])==='https://example.test/rank-math/'&&!isset($seo_context->sitemap_entry(['loc'=>'rank-math'],$posts[201],'page')['_kiwe_excluded']),'active Rank Math retains canonical and sitemap authority');
check(!DSA\Onboarding\SEO_Context_Service::page_noindex(201),'explicit Rank Math index wins over dormant Kiwe noindex');
$postmeta[201]['rank_math_robots']=[];
check(!DSA\Onboarding\SEO_Context_Service::page_noindex(201),'missing external directive cannot activate dormant Kiwe metadata');
echo "$count SEO metadata migration checks passed.\n";
