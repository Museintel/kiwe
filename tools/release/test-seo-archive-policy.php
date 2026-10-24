<?php
declare(strict_types=1);
namespace DSA\Access { final class Taxonomy_App_Service { public const VISIBILITY_META='_fixture_visibility'; } }
namespace {
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
$base=__DIR__.'/../../wp-content/mu-plugins/dsa/includes/';
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require $base.'Apps/SEO_App_Service.php';require $base.'Onboarding/Native_Indexing_Service.php';
require$base.'SEO/SEO_Defaults_Model.php';require$base.'SEO/SEO_Defaults_Service.php';require$base.'SEO/SEO_Redirect_Service.php';require$base.'SEO/SEO_Sitemap_Service.php';require$base.'Onboarding/SEO_Context_Service.php';
use DSA\SEO\SEO_Defaults_Model as Model;use DSA\SEO\SEO_Redirect_Service as Redirect;use DSA\SEO\SEO_Sitemap_Service as Sitemap;use DSA\Onboarding\SEO_Context_Service as Context;
class WP_Error { public function __construct(public string$code,public string$message){} }
function is_wp_error($v){return$v instanceof WP_Error;}function wp_json_encode($v){return json_encode($v);}function wp_parse_url($v){return parse_url($v);}function home_url($p=''){return rtrim($GLOBALS['home'],'/').($p!==''?'/'.ltrim($p,'/'):'');}
function get_option($n,$d=null){return$GLOBALS['options'][$n]??$d;}function is_date(){return$GLOBALS['kind']==='date';}function is_author(){return$GLOBALS['kind']==='author';}function is_attachment(){return$GLOBALS['kind']==='attachment';}function get_queried_object_id(){return 20;}
function get_post($id){return$GLOBALS['posts'][$id]??null;}function get_permalink($p){return$p->url??'';}function is_post_publicly_viewable($p){return$p->post_status==='publish';}function is_admin(){return$GLOBALS['admin'];}function wp_doing_ajax(){return$GLOBALS['ajax'];}function is_preview(){return$GLOBALS['preview'];}
function wp_redirect($url,$code,$owner){$GLOBALS['redirected']=compact('url','code','owner');return false;}function get_term($id){return(object)['term_id'=>$id,'taxonomy'=>'category','count'=>$GLOBALS['termCount']];}function get_term_meta($id,$key,$single=true){return$GLOBALS['termMeta'][$key]??'';}
function get_post_types($a=[]){return['post'=>'post','page'=>'page','product'=>'product','attachment'=>'attachment','collision'=>'collision'];}function get_taxonomies($a=[]){return['category'=>'category','product_cat'=>'product_cat','collision'=>'collision'];}function add_rewrite_rule($regex,$query,$priority){$GLOBALS['rules'][$regex]=$query;}
final class Fixture_DB { public string$last_error='';public int$queries=0;public array$rows=[];public string$prefix='wp_';public function prepare($sql,...$args){return[$sql,$args];}public function get_results($q,$mode){++$this->queries;return array_values(array_intersect_key($this->rows,array_flip($q[1])));} }
$GLOBALS['wpdb']=new Fixture_DB();$GLOBALS['home']='https://source.invalid';$GLOBALS['options']=[];$GLOBALS['kind']='date';$GLOBALS['posts']=[];$GLOBALS['admin']=$GLOBALS['ajax']=$GLOBALS['preview']=false;$GLOBALS['rules']=[];$GLOBALS['termCount']=0;$GLOBALS['termMeta']=[];
$proof=['schema'=>'kiwe.seo-archive-policy.v1','passed'=>false,'cleanup'=>true,'checks'=>[]];$check=static function($v,$label)use(&$proof){$proof['checks'][$label]=(bool)$v;if(!$v)throw new \RuntimeException($label);};
try{
 $stored=['policies'=>['redirectDateArchives'=>true,'redirectAuthorArchives'=>false,'redirectAttachments'=>true,'attachmentFallback'=>'https://source.invalid/help/','noindexEmptyTerms'=>true]];
 $clean=Model::validate($stored);$check(is_array($clean)&&$clean['policies']===$stored['policies'],'closed native policy configuration round trips');
 $GLOBALS['options']['kiwe_seo_defaults_v1']=$stored;$r=new Redirect();
 if(($argv[1]??'')==='external'){define('RANK_MATH_VERSION','fixture');$check($r->decision('/2025/02/')===null,'active external SEO owner keeps routing authority');$proof['passed']=true;echo json_encode($proof);exit;}
 $check($r->decision('/2025/02/')===['code'=>301,'url'=>'https://source.invalid'],'date archive redirects to the configured website home');
 $check($r->decision('/?m=202502','HEAD')===['code'=>301,'url'=>'https://source.invalid'],'query date archive and HEAD retain native identity');
 $check($GLOBALS['wpdb']->queries===0,'policy routing creates no table, lookup query or hit write');
 foreach(['POST','PUT','PATCH','DELETE','OPTIONS']as$method)$check($r->decision('/2025/02/',$method)===null,'mutating or unrelated method stays untouched: '.$method);
 foreach(['/wp-admin/','/wp-login.php','/wp-json/','https://evil.invalid/2025/','//evil.invalid/2025/',"/2025/%0a/x","/2025/\\x",'/../2025/']as$path)$check($r->decision($path)===null,'protected or unsafe route cannot use archive policy: '.$path);
 $GLOBALS['kind']='author';$check($r->decision('/author/person/')===null,'disabled author redirect policy stays inert');
 $GLOBALS['options']['kiwe_seo_defaults_v1']['policies']['redirectAuthorArchives']=true;$check($r->decision('/author/person/')['code']===301,'enabled author policy uses existing redirect owner');
 $GLOBALS['kind']='attachment';$GLOBALS['posts']=[20=>(object)['ID'=>20,'post_type'=>'attachment','post_parent'=>30],30=>(object)['ID'=>30,'post_type'=>'post','post_status'=>'publish','url'=>'https://source.invalid/story/']];
 $check($r->decision('/photo/')['url']==='https://source.invalid/story/','attachment routes to its public native parent');
 $GLOBALS['posts'][30]->post_status='private';$check($r->decision('/photo/')['url']==='https://source.invalid/help/','private parent is not disclosed and uses explicit fallback');
 $GLOBALS['posts'][20]->post_parent=0;$GLOBALS['options']['kiwe_seo_defaults_v1']['policies']['attachmentFallback']='';$check($r->decision('/?attachment_id=20')['url']==='https://source.invalid','orphan root-query attachment falls back to home');
 $GLOBALS['posts'][20]->post_parent=20;$check($r->decision('/photo/')['url']==='https://source.invalid','self parent does not create a redirect loop');
 $GLOBALS['posts'][20]->post_parent=30;$GLOBALS['posts'][30]->post_status='publish';$GLOBALS['posts'][30]->url='https://source.invalid/photo/';$check(is_wp_error($r->decision('/photo/')),'a self destination is refused');
 $GLOBALS['kind']='date';$GLOBALS['home']='https://source.invalid/shop';$check($r->decision('/shop/2025/02/')['url']==='https://source.invalid/shop','subdirectory home is not doubled');$check($r->decision('/shop/?m=202502')['code']===301,'subdirectory query archive works');$check($r->decision('/2025/02/')===null,'paths outside a subdirectory site stay untouched');$GLOBALS['home']='https://source.invalid';
 $GLOBALS['options']['kiwe_seo_redirect_store_v1']=['schema'=>1,'enabled'=>true];$rule=Redirect::rule('2025/02','https://source.invalid/specific/',302);$GLOBALS['wpdb']->rows[$rule['source_hash']]=$rule;
 $check($r->decision('/2025/02/')===['code'=>302,'url'=>'https://source.invalid/specific/'],'an explicit reviewed redirect precedes a broad archive policy');$GLOBALS['options']['kiwe_seo_redirect_store_v1']=[];
 $GLOBALS['kind']='attachment';$GLOBALS['posts'][20]->post_parent=0;$GLOBALS['options']['kiwe_seo_defaults_v1']['policies']['attachmentFallback']='https://source.invalid/help/';$GLOBALS['options']['kiwe_seo_redirect_store_v1']=['schema'=>1,'enabled'=>true];$loop=Redirect::rule('help','https://source.invalid/photo/',301);$GLOBALS['wpdb']->rows=[$loop['source_hash']=>$loop];$check(is_wp_error($r->decision('/photo/')),'policy and exact rules cannot form a cross-owner redirect loop');$GLOBALS['options']['kiwe_seo_redirect_store_v1']=[];$GLOBALS['kind']='date';
 $GLOBALS['options']['kiwe_seo_workspace_enabled_v1']='0';$check($r->decision('/2025/02/')===null,'paused Kiwe SEO does not take routing authority');unset($GLOBALS['options']['kiwe_seo_workspace_enabled_v1']);
 foreach(['admin','ajax','preview']as$flag){$GLOBALS[$flag]=true;unset($GLOBALS['redirected']);$_SERVER['REQUEST_URI']='/2025/02/';$_SERVER['REQUEST_METHOD']='GET';$r->handle();$check(!isset($GLOBALS['redirected']),'protected request bypasses redirect handling: '.$flag);$GLOBALS[$flag]=false;}
 $check(Context::term_noindex(8),'empty term inherits the saved noindex policy');$GLOBALS['termMeta']['_kiwe_seo_robots']=['index'];$check(!Context::term_noindex(8),'explicit per-term indexing remains the final client choice');$GLOBALS['termMeta']=[];$GLOBALS['termCount']=1;$check(!Context::term_noindex(8),'populated term does not inherit the empty-term restriction');
 (new Sitemap())->rewrites();$check(($GLOBALS['rules']['^product-sitemap\\.xml$']??'')==='index.php?sitemap=posts&sitemap-subtype=product&paged=1','legacy product XML uses the native WordPress renderer');$check(($GLOBALS['rules']['^category-sitemap([1-9][0-9]{0,3})\\.xml$']??'')==='index.php?sitemap=taxonomies&sitemap-subtype=category&paged=$matches[1]','legacy paginated taxonomy XML retains bounded page identity');
 $check(!isset($GLOBALS['rules']['^attachment-sitemap\\.xml$'])&&!isset($GLOBALS['rules']['^collision-sitemap\\.xml$']),'non-indexable attachments and ambiguous names get no compatibility alias');
 foreach([['redirectDateArchives','true'],['redirectAttachments',1],['noindexEmptyTerms',null],['attachmentFallback','https://user:secret@source.invalid/'],['unknown',true]]as[$key,$value]){$bad=['policies'=>[$key=>$value]];$check(Model::validate($bad)===false,'unsafe or unknown policy is refused: '.$key);}
 $check(Model::validate([])['policies']===Model::defaults()['policies'],'old snapshots retain inert policy defaults');
 $proof['passed']=true;
}catch(\Throwable$e){$proof['error']=$e->getMessage();}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($proof['passed']?0:1);
}
