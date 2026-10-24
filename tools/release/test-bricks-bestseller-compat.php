<?php
declare(strict_types=1);
namespace DSA {
 class Settings { public function get($key,$default=[]){return $key==='commerce'?$GLOBALS['config']:$default;} }
 class Element_Registry {}
}
namespace {
 define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
 $config=['bestseller_enabled'=>true,'bestseller_limit'=>3];$woo=true;$cache=[];$reads=0;$checks=0;
 $records=[11=>(object)['ID'=>11,'post_type'=>'product','post_status'=>'publish','post_password'=>''],12=>(object)['ID'=>12,'post_type'=>'product','post_status'=>'draft','post_password'=>''],13=>(object)['ID'=>13,'post_type'=>'product','post_status'=>'publish','post_password'=>''],14=>(object)['ID'=>14,'post_type'=>'page','post_status'=>'publish','post_password'=>''],15=>(object)['ID'=>15,'post_type'=>'product','post_status'=>'publish','post_password'=>'secret']];
 function __($s,$domain=''){return $s;}function absint($v){return abs((int)$v);}function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}
 function taxonomy_exists($tax){return $GLOBALS['woo'];}function get_transient($key){$GLOBALS['reads']++;return $GLOBALS['cache'][$key]??false;}
 function wc_get_product($id){return new class($id) {public function __construct(private $id){}public function is_visible(){return $this->id!==13;}};}
 function get_post($id){return $GLOBALS['records'][$id]??null;}function post_password_required($post){return $post->post_password!=='';}function get_queried_object_id(){return 11;}
 $wpdb=new class{public function __call($name,$args){throw new RuntimeException('Public adapter must not calculate rankings or query sales');}};
 $root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';require $root.'Commerce/Linked_Products_Service.php';require $root.'Bricks/Bricks_Integration.php';
 $settings=new DSA\Settings();$linked=new DSA\Commerce\Linked_Products_Service($settings);$bricks=new DSA\Bricks\Bricks_Integration(new DSA\Element_Registry(),$settings,$linked);
 function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
 $base=['post_type'=>'product','post__not_in'=>[19],'tax_query'=>[['taxonomy'=>'product_cat','terms'=>[7]]],'order'=>'ASC'];$settings=['bestSellers'=>true,'bestSellersPeriod'=>'year'];
 $query=$bricks->bestseller_query_vars($base,$settings,'fixture','block');check($query['post__in']===[0],'Cold cache is empty, never an unfiltered catalog');
 $cache['dsa_bestsellers_year']=['source'=>'lifetime-sales','limit'=>3,'ids'=>[11]];check($linked->cached_bestseller_ids('year')===[],'Unproven lifetime cache is not a period ranking');
 $cache['dsa_bestsellers_year']=['source'=>'woo-order-period-v1','limit'=>3,'ids'=>[13,11,12]];$cache['dsa_bestsellers_month']=['source'=>'woo-order-period-v1','limit'=>3,'ids'=>[12]];
 $query=$bricks->bestseller_query_vars($base,$settings,'fixture','woocommerce-products');check($query['post__in']===[13,11,12]&&$query['orderby']==='post__in'&&!isset($query['order']),'Native ranking order retained');
 check($query['tax_query']===$base['tax_query']&&$query['post__not_in']===$base['post__not_in']&&$query['post_status']==='publish','Native category exclusions and public status retained');
 check($bricks->bestseller_query_vars($base+['post__in'=>[11,19]],$settings,'fixture','block')['post__in']===[11],'Existing on-sale IDs intersect with rankings');
 check($bricks->bestseller_query_vars($base+['post__in'=>[19]],$settings,'fixture','block')['post__in']===[0],'Empty intersection is not unrestricted');
 foreach(['posts','container']as$type)check($bricks->bestseller_query_vars($base,$settings,'fixture',$type)===$base,'Other element queries untouched');
 $mixed=array_replace($base,['post_type'=>['post','product']]);check($bricks->bestseller_query_vars($mixed,$settings,'fixture','block')===$mixed,'Mixed post queries untouched');
 check($bricks->bestseller_query_vars($base,[],'fixture','block')===$base,'Disabled authoring control has no effect');
 $controls=$bricks->add_bestseller_controls(['native'=>['type'=>'text']]);check(isset($controls['native'],$controls['bestSellers'],$controls['bestSellersPeriod']['options']['year']),'Old authored IDs and native controls survive');
 check($bricks->render_dynamic_tag('{woo_product_bestseller}',$records[11])==='Bestseller','Legacy badge uses intended yearly native ranking');
 check($bricks->render_dynamic_tag('{kiwe_product_bestseller:year}',11)==='Bestseller'&&$bricks->render_dynamic_tag('{kiwe_product_bestseller}',11)==='','New period selector uses shared ranking');
 check($bricks->render_dynamic_content('Award {woo_product_bestseller}',$records[11])==='Award Bestseller','Badge resolves inside prose');
 foreach([12,13,14,15,999]as$id)check($bricks->render_dynamic_tag('{woo_product_bestseller}',$id)==='','Draft hidden non-product protected or missing product has no badge');
 foreach(['image','media','link']as$context)check($bricks->render_dynamic_tag('{woo_product_bestseller}',11,$context)==='','Badge not injected in image or link');
 check($bricks->render_dynamic_tag('{other_tag}',11)==='{other_tag}','Unowned dynamic data remains with Bricks');
 $prior=$reads;$config['bestseller_enabled']=false;check($linked->cached_bestseller_ids('year')===[]&&$reads===$prior,'Disabled ranking is cheap');$config['bestseller_enabled']=true;
 $woo=false;$prior=$reads;check($linked->cached_bestseller_ids('year')===[]&&$reads===$prior,'Non-Woo path avoids rankings');$woo=true;
 check($linked->cached_bestseller_ids('unsafe')===[],'Unknown period is not substituted');
 echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'Legacy Bricks adapters with native cached rankings, no ranking SQL or writes']).PHP_EOL;
}
