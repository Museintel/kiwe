<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Site_Graph/Data_Query_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/WP7/Bindings_Service.php';
$store='0';$checks=[];$bindings=[];$nativeChecks=0;
function WC(){throw new RuntimeException('No cart/session construction is permitted in this fixture');}
function get_option($k,$d=false){return $k==='kiwe_store_app_enabled_v1'?$GLOBALS['store']:$d;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_taxonomy($tax){return (object)['object_type'=>$tax==='flavour'?['product']:['post']];}
function post_type_exists($type){$GLOBALS['nativeChecks']++;return false;}
function register_block_bindings_source($key,$value){$GLOBALS['bindings'][$key]=$value;}
function __($s,$d=''){return $s;}function get_bloginfo($key){return 'Core identity';}function wp_strip_all_tags($s){return strip_tags($s);}
function check($v,$s){if(!$v)throw new RuntimeException($s);$GLOBALS['checks'][$s]=true;}
try {
 $reader=new DSA\Site_Graph\Data_Query_Service();
 $queries=[['resource'=>'products'],['resource'=>'product'],['resource'=>'posts','postType'=>'product'],['resource'=>'nodes','post_type'=>'product_variation'],['resource'=>'shop_coupon'],['resource'=>'terms','taxonomy'=>'product_cat'],['resource'=>'productcategories'],['resource'=>'terms','taxonomy'=>'pa_pack'],['resource'=>'terms','taxonomy'=>'flavour']];
 foreach([false,true]as$private)foreach($queries as$i=>$query){$v=$reader->query($query,$private);check(($v['error']??'')==='store_app_off'&&($v['available']??null)===false&&$v['data']===[],'inactive Store query is refused before native lookup '.($private?'private':'public').' '.$i);}
 check($nativeChecks===0,'commerce aliases cannot trigger native catalogs while inactive');
 $v=$reader->query(['resource'=>'pages']);check($nativeChecks===1&&($v['error']??'')==='unknown_post_type','Core pages follow their normal native lookup rather than the Store refusal');
 $b=new DSA\WP7\Bindings_Service();$b->register();check(isset($bindings['kiwe/site'],$bindings['kiwe/team'])&&!isset($bindings['kiwe/product']),'Core bindings remain registered while product binding is absent');
 check($b->site_value(['key'=>'title'])==='Core identity'&&$b->product_value(['key'=>'nutrition_image'])==='','Core identity renders and direct product binding is inert');
 check(!in_array('kiwe/product',array_column($b->summary()['sources'],'name'),true)&&!$reader->schema()['resources']['products']['available'],'discovery truthfully reports Store unavailable');
 $store='1';$bindings=[];$b->register();check(isset($bindings['kiwe/product'])&&$reader->schema()['resources']['products']['available'],'re-enabling exposes product binding and authoring resource');
 echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$checks,'scope'=>'actual Site Graph and block binding owners, synthetic native I/O'])."\n";
}catch(Throwable $e){echo json_encode(['passed'=>false,'cleanup'=>true,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
