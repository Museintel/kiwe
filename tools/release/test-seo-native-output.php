<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
class WP_REST_Request {
 public function __construct(private string $method='GET',private array $body=[],private array $headers=['X-WP-Nonce'=>'wp_rest','x-kiwe-mutation'=>'1']){}
 public function get_method(){return $this->method;}public function get_header($key){return $this->headers[$key]??'';}public function get_json_params(){return $this->body;}
}
class WP_REST_Response {public array $headers=[];public function __construct(public array $data,public int $status=200){}public function header($key,$value){$this->headers[$key]=$value;}}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_parse_url($url){return parse_url($url);}
function esc_url_raw($url){return filter_var($url,FILTER_VALIDATE_URL)?$url:'';}
function wp_strip_all_tags($value){return strip_tags($value);}
function site_url($path='/'){return home_url($path);}
function do_action($hook){$GLOBALS['events'][]=$hook;}
function update_option($key,$value,$autoload=null){$GLOBALS['option_writes'][]=[$key,$autoload];if($GLOBALS['fail_option']??false)return false;$GLOBALS['options'][$key]=$value;return true;}
require __DIR__.'/fixtures/seo-defaults-native-option-db.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Schema/Schema_Template_Service.php';
function get_post_types($args,$format='names'){return $format==='names'?['post'=>'post','page'=>'page']:['post'=>(object)['labels'=>(object)['name'=>'Posts']],'page'=>(object)['labels'=>(object)['name'=>'Pages']]];}
function get_taxonomies($args,$format='names'){return $format==='names'?['category'=>'category','post_tag'=>'post_tag']:['category'=>(object)['labels'=>(object)['name'=>'Categories']],'post_tag'=>(object)['labels'=>(object)['name'=>'Tags']]];}
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
use DSA\SEO\SEO_Defaults_Service as Defaults;
use DSA\SEO\SEO_Sitemap_Service as Sitemaps;
$service=new Defaults();$actor=2;$events=[];$option_writes=[];
check(Defaults::enabled(),'native SEO defaults are available without an external plugin');
foreach([
 ['unknown'=>true],['schema'=>2],['home'=>null],['home'=>['title'=>null]],['sitemap'=>['enabled'=>null]],['news'=>['enabled'=>null]],['social'=>['image'=>null]],
 ['postTypes'=>['post'=>['unknown'=>true]]],['sitemap'=>['pageSize'=>49]],['sitemap'=>['pageSize'=>1001]],['sitemap'=>['excludePosts'=>['4']]],
 ['home'=>['title'=>'%unknown_token%']],['home'=>['title'=>'<b>title</b>']],['globalRobots'=>['index','noindex']],['home'=>['robots'=>['follow','nofollow']]],
 ['home'=>['advancedRobots'=>['max-snippet'=>-2]]],['news'=>['enabled'=>true,'publication'=>'Paper','language'=>'english']],['social'=>['image'=>'https://name:secret@example.test/private.jpg']],['social'=>['image'=>'javascript:alert(1)']],
]as$input)check(is_wp_error(Defaults::validate($input)),'malformed defaults refuse: '.json_encode($input));
$supported=Defaults::validate(['home'=>['title'=>'%sitename% %sep% %tagline%'],'postTypes'=>['post'=>['title'=>'%title% %sep% %sitename%','robots'=>['index'],'advancedRobots'=>['max-snippet'=>-1,'max-image-preview'=>'standard']]],'taxonomies'=>['category'=>['title'=>'%term%','description'=>'%term_description%','robots'=>['noindex']]],'news'=>['enabled'=>true,'publication'=>'Sample paper','language'=>'en']]);
check(!is_wp_error($supported),'supported defaults cover native content taxonomy and news scopes');
check(Defaults::expand('%title% %sep% %sitename%',['title'=>'Edited title','sep'=>'|','sitename'=>'Paper'])==='Edited title | Paper','title expansion responds to edited native facts');
check(Defaults::expand('%title%',['title'=>'<script>x</script> Real story'])==='x Real story','variable expansion strips markup');
check(Defaults::expand('%unknown%',['unknown'=>'leak'])==='','unsupported template tokens never render');
check(Defaults::expand(str_repeat('अ',500),[])===str_repeat('अ',320),'output remains Unicode-safe and bounded');
$actor=6;check(403===$service->read(new WP_REST_Request())->status,'Editor cannot inspect website defaults');
check(403===$service->write(new WP_REST_Request('POST',['expected'=>Defaults::expected(),'settings'=>$supported]))->status,'Editor cannot change website defaults');
$actor=3;check(!$service->permission(new WP_REST_Request()),'Author record rights do not grant global SEO rights');
$actor=2;check(!$service->permission(new WP_REST_Request('POST',[],[])),'missing nonce and mutation proof refuse default writes');
check(!$service->permission(new WP_REST_Request('POST',[],['X-WP-Nonce'=>'wp_rest'])),'valid nonce without mutation proof refuses default writes');
check(409===$service->write(new WP_REST_Request('POST',['expected'=>str_repeat('0',64),'settings'=>$supported]))->status&&[]===$option_writes,'stale defaults refuse all option writes');
check(400===$service->write(new WP_REST_Request('POST',['expected'=>Defaults::expected(),'settings'=>['unknown'=>true]]))->status&&[]===$option_writes,'invalid defaults refuse all writes');
$response=$service->write(new WP_REST_Request('POST',['expected'=>Defaults::expected(),'settings'=>$supported]));
check(200===$response->status&&get_option(Defaults::OPTION)===$supported&&false===$option_writes[0][1],'defaults save through one non-autoload native option and readback');
check(count($response->data['postTypes'])===2&&count($response->data['taxonomies'])===2,'manager discovers registered types without a content inventory');
check(in_array('kiwe_seo_defaults_changed',$events,true)&&in_array('litespeed_purge_all',$events,true),'native invalidation follows a confirmed save');
check(Defaults::robots_for('taxonomies','category')===['noindex']&&Defaults::robots_for('taxonomies','category',['index'])===['index'],'individual indexing overrides inherited scope policy');
$maps=new Sitemaps();$args=$maps->posts_args(['meta_query'=>[['key'=>'existing','value'=>'retained']]],'post');
check(isset($args['meta_query'][0][0]['key'])&&$args['meta_query'][0][0]['key']==='existing'&&!$args['has_password'],'native sitemap constraints retain other query owners and exclude passwords');
check($maps->page_size(2000)===500,'native sitemap page size stays bounded');
check(Sitemaps::clean_entries([false,['loc'=>'https://example.test/excluded/','_kiwe_excluded'=>true],['loc'=>'https://example.test/kept/']])===[['loc'=>'https://example.test/kept/']],'renderer receives only complete safe URL entries');
$builds=0;$first=$maps->remember('scope:1',function()use(&$builds){++$builds;return ['value'=>1];});$maps->remember('scope:1',function()use(&$builds){++$builds;return ['value'=>2];});
check($builds===1,'warm sitemap output reuses its exact bounded cached projection');
for($i=0;$i<100;$i++)$maps->remember('scope:'.$i,fn()=>['value'=>$i]);
check(count(array_filter(array_keys($transients),fn($k)=>str_starts_with($k,'kiwe_seo_sitemap_cache_v1_')))<=32,'many sitemap routes cannot grow persistent cache keys beyond the fixed ring');
$before=count($option_writes);$maps->invalidate();$maps->invalidate();
check(count($option_writes)===$before+1,'many native write hooks coalesce one sitemap generation change');
$settings=get_option(Defaults::OPTION);$fail_option=true;$changed=$settings;$changed['separator']='|';
check(409===$service->write(new WP_REST_Request('POST',['expected'=>Defaults::expected(),'settings'=>$changed]))->status&&get_option(Defaults::OPTION)===$settings,'failed native readback preserves the previous defaults');$fail_option=false;
$newer=$settings;$newer['separator']='!';$seo_defaults_query_hook=static function($sql)use($newer){if(str_starts_with($sql,'UPDATE')){unset($GLOBALS['seo_defaults_query_hook']);$GLOBALS['options'][Defaults::OPTION]=$newer;}};
check(409===$service->write(new WP_REST_Request('POST',['expected'=>Defaults::expected(),'settings'=>$changed]))->status&&get_option(Defaults::OPTION)===$newer,'an edit interleaved after review defeats the native defaults conditional writer');
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Native_Indexing_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Output_Service.php';
$theme=(new ReflectionClass(DSA\SEO\SEO_Output_Service::class))->newInstanceWithoutConstructor();
check($theme->bricks_seo(false)===true,'Enabled Kiwe SEO suppresses competing Bricks metadata');
$options['kiwe_seo_workspace_enabled_v1']='0';$before=count($option_writes);$off=new Sitemaps();
check($theme->bricks_seo(false)===false&&$theme->bricks_seo(true)===true,'With both SEO owners absent the native Bricks choice passes through');
check(!Defaults::enabled()&&false===$off->enabled(false)&&$off->posts_args(['native'=>true],'post')===['native'=>true],'SEO-off yields to unchanged native discovery and queries');
$off->invalidate();check(count($option_writes)===$before,'SEO-off adds no invalidation writes');
$options['kiwe_seo_workspace_enabled_v1']='1';define('RANK_MATH_VERSION','synthetic');
check(!Defaults::enabled()&&Defaults::external_owner()==='rank_math','an active external plugin remains the frontend authority');
check($theme->bricks_seo(false)===true,'An active external SEO owner suppresses duplicate Bricks metadata');
check(409===$service->write(new WP_REST_Request('POST',['expected'=>Defaults::expected(),'settings'=>$supported]))->status,'website defaults cannot overwrite an active external provider');
echo "$count native SEO foundation local checks passed.\n";
