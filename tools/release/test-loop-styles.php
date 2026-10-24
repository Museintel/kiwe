<?php
namespace {
define('ABSPATH', __DIR__); define('BRICKS_VERSION',$argv[1]??'2.4.3');
$hooks=[];$admin=false;$builder=false;$veto=false;$checks=0;
function add_action($hook,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook][$priority][]=[$callback,$accepted];}
function do_action($hook,...$args){$groups=$GLOBALS['hooks'][$hook]??[];ksort($groups);foreach($groups as$entries)foreach($entries as[$callback,$accepted])$callback(...array_slice($args,0,$accepted));}
function is_admin(){return $GLOBALS['admin'];}
function bricks_is_builder_call(){return $GLOBALS['builder'];}
function apply_filters($hook,$value,...$args){return $GLOBALS['veto']?false:$value;}
function check($label,$passed){$GLOBALS['checks']++;if(!$passed)throw new \RuntimeException($label);}
class WP_Query {public array $posts;public int $post_count;public int $current_post=-1;public function __construct($count=5){$this->posts=array_map(static fn($n)=>(object)['ID'=>$n],range(1,$count));$this->post_count=$count;}}
}
namespace Bricks {
class Assets {public static array $elements=[];public static function generate_css_from_element($element,$type){return 'style';}}
class Database {public static array $global_data=['globalClasses'=>[]];}
class Query {
 public $query_result;public $count=5;public $element_id='root';
 public function __construct(){$this->query_result=new \WP_Query();}
 public function render($callback,$args){$result=$this->query_result;\do_action('bricks/query/before_loop',$this,array_values($args));$iterations=$result->post_count;for($n=0;$n<$iterations;$n++)$callback(...array_values($args));\do_action('bricks/query/after_loop',$this,array_values($args));return $iterations;}
}
}
namespace {
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Loop_Style_Service.php';
$service=new \DSA\Bricks\Loop_Style_Service();$service->register();
$root=['id'=>'root','name'=>'div','children'=>['picture','label','button'],'settings'=>['hasLoop'=>true,'query'=>['post_type'=>['product']],'_padding'=>['top'=>'var(--kiwe-space-sm)']]];
$children=['picture'=>['id'=>'picture','name'=>'image','settings'=>['image'=>['useDynamicData'=>'{woo_product_image}'],'_objectFit'=>'contain']], 'label'=>['id'=>'label','name'=>'text-basic','settings'=>['text'=>'{post_title}','_attributes'=>[['name'=>'data-product','value'=>'{post_id}']]]], 'button'=>['id'=>'button','name'=>'button','settings'=>['text'=>'+','link'=>['url'=>'{post_url}'],'_typography'=>['color'=>['raw'=>'var(--kiwe-color-text)']]]]];
\Bricks\Assets::$elements=$children;\Bricks\Assets::$elements['picture']['settings']['altText']='{kiwe_product_image_alt}';
check('Native dynamic content and source images may share static styling',$service->invariant_tree($root));
$query=new \Bricks\Query();$before=serialize($query);$result=$query->query_result;$posts=$result->posts;
$iterations=$query->render('Bricks\\Assets::generate_css_from_element',[$root,'content']);
$supported=version_compare(BRICKS_VERSION,'2.3.4','>=')&&version_compare(BRICKS_VERSION,'2.5','<');
check('Only qualified native CSS iterations reduce',$iterations===($supported?1:5));
check('Native query/history object identity remains',$query->query_result===$result);
check('Every product result remains',$result->posts===$posts&&count($result->posts)===5);
check('Exact query state restored before HTML',serialize($query)===$before);
check('Ordinary render keeps every item',$query->render(static fn()=>'',[$root,'content'])===5);
check('Array callbacks are left intact',$query->render([\Bricks\Assets::class,'generate_css_from_element'],[$root,'content'])===5);
foreach(['header','footer','popup']as$type)check('Known native area '.$type,$query->render('Bricks\\Assets::generate_css_from_element',[$root,$type])===($supported?1:5));
check('Unknown native area',$query->render('Bricks\\Assets::generate_css_from_element',[$root,'unknown'])===5);
foreach(['admin','builder','veto']as$flag){$GLOBALS[$flag]=true;check('Explicit fallback '.$flag,$query->render('Bricks\\Assets::generate_css_from_element',[$root,'content'])===5);$GLOBALS[$flag]=false;}
$GLOBALS['wp_query']=$result;check('Main query is never adjusted',$query->render('Bricks\\Assets::generate_css_from_element',[$root,'content'])===5);unset($GLOBALS['wp_query']);
$result->current_post=0;check('In-progress query is never adjusted',$query->render('Bricks\\Assets::generate_css_from_element',[$root,'content'])===5);$result->current_post=-1;
$result->post_count=4;check('Custom mismatching result untouched',$query->render('Bricks\\Assets::generate_css_from_element',[$root,'content'])===4);$result->post_count=5;
foreach([
 'dynamic typography'=>['_typography'=>['color'=>['raw'=>'{cf_tone}']]],
 'dynamic background'=>['_background'=>['image'=>['useDynamicData'=>'{post_thumbnail}']]],
 'dynamic responsive style'=>['_padding:mobile_portrait'=>['top'=>'{cf_space}']],
 'dynamic identifier'=>['_cssId'=>'{post_id}'],
 'dynamic custom CSS'=>['_cssCustom'=>'%root% { color: {cf_tone}; }'],
 'dynamic unprefixed control'=>['mask'=>'{cf_mask}'],
 'dynamic tag argument'=>['_width'=>"{echo:width @fallback:'{post_id}'}"],
 'dynamic whitespace argument'=>['_width'=>"{cf_width @fallback:'12px'}"],
 'dynamic hyphen field'=>['_width'=>'{cf_product-width}'],
 'dynamic Unicode field'=>['_width'=>'{cf_幅}'],
 'dynamic quoted semicolon'=>['_cssCustom'=>"%root% { background-image:url({cf_image @fallback:'data:image/svg+xml;utf8,sample'}); }"],
]as$label=>$settings){$changed=$root;$changed['settings']=array_merge($root['settings'],$settings);check($label.' declines reuse',!$service->invariant_tree($changed));check($label.' keeps all native results',$query->render('Bricks\\Assets::generate_css_from_element',[$changed,'content'])===5);}
$staticCss=$root;$staticCss['settings']['_cssCustom']='%root% { background-image:none; display:flex; }';check('Ordinary CSS declaration blocks stay static',$service->invariant_tree($staticCss));
$longCss=$root;$longCss['settings']['_cssCustom']='{'.str_repeat('x',131073);check('Oversized ambiguous style uses native fallback',!$service->invariant_tree($longCss));
foreach(['template','post-content','slider-nested','third-party-card']as$type){\Bricks\Assets::$elements['button']['name']=$type;check('Unknown or context-dependent '.$type,!$service->invariant_tree($root));}\Bricks\Assets::$elements=$children;
foreach(['cid'=>'component','parentComponent'=>'component','instanceId'=>'instance','slotChildren'=>['slot'=>['label']]]as$key=>$value){$changed=$root;$changed[$key]=$value;check('Component/slot fallback '.$key,!$service->invariant_tree($changed));}
\Bricks\Assets::$elements['button']['settings']['hasLoop']=true;check('Nested query fallback',!$service->invariant_tree($root));\Bricks\Assets::$elements=$children;
$changed=$root;$changed['children'][]='missing';check('Unresolved descendant fallback',!$service->invariant_tree($changed));
$changed=$root;$changed['children']='invalid';check('Malformed descendant list declines without a warning',!$service->invariant_tree($changed));
\Bricks\Assets::$elements['button']['children']=['button'];check('Cycle fallback',!$service->invariant_tree($root));\Bricks\Assets::$elements=$children;
$changed=$root;$changed['settings']['_cssGlobalClasses']=['class'];check('Missing native class fallback',!$service->invariant_tree($changed));
\Bricks\Database::$global_data['globalClasses']=[['id'=>'class','settings'=>['_width'=>'40px']]];check('Known static native class',$service->invariant_tree($changed));
\Bricks\Database::$global_data['globalClasses'][0]['settings']['_width']='{cf_width}';check('Native class edit immediately invalidates reuse',!$service->invariant_tree($changed));
\Bricks\Database::$global_data['globalClasses'][0]['settings']['_width']='50px';check('Native static class edit immediately resumes reuse',$service->invariant_tree($changed));
$classRestore=\Bricks\Database::$global_data['globalClasses'];\Bricks\Database::$global_data['globalClasses']=array_fill(0,5001,['id'=>'class','settings'=>[]]);check('Global class inspection remains bounded',!$service->invariant_tree($root));\Bricks\Database::$global_data['globalClasses']=$classRestore;
$large=$root;$large['children']=[];for($i=0;$i<256;$i++){ $id='node'.$i;$large['children'][]=$id;\Bricks\Assets::$elements[$id]=['id'=>$id,'name'=>'div','settings'=>[]]; }check('Traversal stays bounded',!$service->invariant_tree($large));
check('Registration has only two hooks',array_keys($hooks)===['bricks/query/before_loop','bricks/query/after_loop']);
echo json_encode(['passed'=>true,'checks'=>$checks,'bricks'=>BRICKS_VERSION,'layer'=>'source-behavior-stubs'])."\n";
}
