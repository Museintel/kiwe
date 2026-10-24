<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_parse_url($url){return parse_url($url);}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Schema/Schema_Template_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Redirect_Service.php';
use DSA\Schema\Schema_Template_Service as Templates;
use DSA\SEO\SEO_Redirect_Service as Redirects;
$graph=['@context'=>'https://schema.org','@type'=>'NewsArticle','headline'=>'%title%','description'=>'%excerpt%','datePublished'=>'%date(Y-m-dTH:i:sP)%','author'=>['@type'=>'Person','name'=>'%post_author%'],'image'=>['@type'=>'ImageObject','url'=>'%post_thumbnail%']];
check(Templates::validate(json_encode($graph))===[ $graph ],'JSON news graph retains native live bindings');
check(Templates::validate(serialize($graph))===[ $graph ],'native serialized source remains declarative');
class SchemaUnserializeTrap {public function __wakeup(){throw new RuntimeException('object executed');}}
check(is_wp_error(Templates::validate(serialize(new SchemaUnserializeTrap()))),'serialized source never instantiates a PHP object');
foreach([
 ['@type'=>'UnknownType'],['@type'=>'Product'],['@type'=>'Article','script'=>'bad'],['@type'=>'Article','headline'=>'%unknown%'],['@context'=>'https://remote.test/schema','@type'=>'Article'],
 ['@type'=>'Article','headline'=>"bad\0text"],['@type'=>'Article','headline'=>"\xff"],['@type'=>'Article','headline'=>str_repeat('x',8193)],
 ['@type'=>'Article','image'=>new stdClass()],['@type'=>'Article','image'=>array_fill(0,65,'x')],
 ['@context'=>'https://schema.org','@graph'=>[['@type'=>'Article']],'headline'=>'must not disappear'],
 [['@type'=>'Article','@id'=>'#same'],['@type'=>'Person','@id'=>'#same']],
 ['@type'=>'Article','@id'=>['bad']],['@graph'=>[]],[],false,'{invalid',str_repeat('x',65537),
]as$i=>$bad)check(is_wp_error(Templates::validate($bad)),'unsafe or unsupported graph refuses '.$i);
$deep=['@type'=>'Article'];for($i=0;$i<12;$i++)$deep=['@type'=>'Article','about'=>$deep];
check(is_wp_error(Templates::validate($deep)),'nested graph exceeds bounded depth');
$node=$graph+['metadata'=>['title'=>'Article','type'=>'template','isPrimary'=>true,'shortcode'=>'s-synthetic','name'=>'%title%','description'=>'%excerpt%']];
check(Templates::source_node($node)===[ $graph ],'known source editor metadata leaves its public graph intact');
$node['metadata']['unpublish']='on';check(is_wp_error(Templates::source_node($node)),'conditional source metadata refuses');
$facts=['title'=>'Changed title </script><script>alert(1)</script>','excerpt'=>'','post_author'=>'Synthetic writer','post_thumbnail'=>'https://example.test/image.jpg','date(Y-m-dTH:i:sP)'=>'2026-10-07T10:00:00+05:30'];
$resolved=Templates::resolve([$graph],$facts);
check($resolved[0]['headline']===$facts['title']&&$resolved[0]['author']['name']==='Synthetic writer'&&!isset($resolved[0]['description']),'live bindings update and empty native facts disappear');
$json=wp_json_encode($resolved,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
check(!str_contains($json,'</script>')&&str_contains($json,'\\u003C'),'JSON-LD encoding prevents a closing-script injection');
check(is_wp_error(Templates::resolve([$graph],['title'=>str_repeat('x',8193)])),'oversized current facts refuse expansion');
$wide=[];for($i=0;$i<20;$i++)$wide[]=['@type'=>'Article','headline'=>'%title%'];
check(is_wp_error(Templates::resolve($wide,['title'=>str_repeat('x',8192)])),'many variable expansions cannot exceed output bytes');
$raw=$graph;Templates::source_node($raw);check($raw===$graph,'source parsing leaves input bytes and fields unchanged');
check(is_wp_error(Templates::source_node(['@type'=>'Article','url'=>'%permalink%'])),'native-only aliases never silently change source variable behavior');
check(Templates::resolve([['@type'=>'Article','url'=>'%url%']],['url'=>'https://example.test/current/'])[0]['url']==='https://example.test/current/','actual source URL variable binds the native current URL');
$rule=Redirects::rule('/old/','/new/',301);
check(!is_wp_error($rule)&&$rule['source_path']==='old'&&$rule['destination']==='https://example.test/new/','exact redirect normalizes slash boundaries and a local target');
check(Redirects::rule('Old','https://example.test/new',302,true)['source_hash']===Redirects::rule('old','https://example.test/new',302,true)['source_hash'],'declared case-insensitive paths share an exact indexed identity');
check(Redirects::rule('Old','https://example.test/new',301)['source_hash']!==Redirects::rule('old','https://example.test/new',301)['source_hash'],'case-sensitive source paths stay distinct');
foreach(['/','wp-admin/x','wp-login.php','wp-json/dsa','wp-cron.php','//other.test/path','https://other.test/path','a/../b','a/%2e%2e/b','a%2fb','a%5cb','a%0db','a?x=1','a#fragment','a%zz',"a\xff"]as$i=>$bad)check(is_wp_error(Redirects::source_path($bad)),'unsafe or ambiguous source path refuses '.$i);
foreach(['javascript:alert(1)','//other.test/path','https://user:password@example.test/path','https://example.test/%0d%0aLocation:evil','http://','https://example.test/%bad%']as$i=>$bad)check(is_wp_error(Redirects::destination($bad)),'unsafe destination refuses '.$i);
check(Redirects::rule('gone','ignored',410)['destination']===''&&Redirects::rule('legal','ignored',451)['code']===451,'gone and legal-removal status codes never emit a Location');
check(is_wp_error(Redirects::rule('old','/new',303)),'unsupported status is not silently changed');
class StructuredLookupFixture {
 public string $prefix='wp_',$last_error='';public array $queries=[],$rows=[];
 public function prepare($sql,...$args){foreach($args as$a)$sql=preg_replace('/%s/',"'".addslashes($a)."'",$sql,1);return$sql;}
 public function get_results($sql,$mode){$this->queries[]=$sql;preg_match_all("/'([a-f0-9]{64})'/",$sql,$m);return array_values(array_filter($this->rows,fn($r)=>in_array($r['source_hash'],$m[1],true)&&$r['state']==='active'));}
}
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
$wpdb=new StructuredLookupFixture();$redirect=new Redirects();
$options[Redirects::OPTION]=['schema'=>1,'enabled'=>true];
$wpdb->rows=[$rule+['state'=>'active']];
check($redirect->decision('/old/?tracking=yes')===['code'=>301,'url'=>'https://example.test/new/?tracking=yes'],'native exact visitor lookup forwards query parameters');
check(count($wpdb->queries)===2&&array_reduce($wpdb->queries,fn($ok,$q)=>$ok&&str_contains($q,'source_hash IN')&&str_contains($q,'LIMIT 2'),true),'redirect chains use bounded unique-hash indexed reads');
$wpdb->rows[0]['state']='pending';check($redirect->decision('/old')===null,'incomplete import is invisible to visitors');
$wpdb->rows=[Redirects::rule('old','/old',301)+['state'=>'active']];check(is_wp_error($redirect->decision('/old')),'self-redirect loop fails closed');
$wpdb->rows=[$rule+['state'=>'active'],Redirects::rule('new','/old',301)+['state'=>'active']];check(is_wp_error($redirect->decision('/old')),'multi-rule redirect loop fails closed');
$wpdb->queries=[];$options['kiwe_seo_workspace_enabled_v1']='0';
check($redirect->decision('/old')===null&&$wpdb->queries===[],'SEO-off adds zero optional redirect queries');
$options['kiwe_seo_workspace_enabled_v1']='1';$options[Redirects::OPTION]['enabled']=false;
check($redirect->decision('/old')===null&&$wpdb->queries===[],'redirect-off never reads its native table');
$options[Redirects::OPTION]['enabled']=true;
check($redirect->decision('/old','POST')===null&&$wpdb->queries===[],'visitor mutations never enter redirect lookup');
define('RANK_MATH_VERSION','synthetic-owner');
check($redirect->decision('/old')===null&&$wpdb->queries===[],'external SEO owner suppresses optional redirect work');
echo $count." structured SEO checks passed.\n";
