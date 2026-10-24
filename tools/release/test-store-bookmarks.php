<?php
declare(strict_types=1);
namespace DSA\Diagnostics { class Runtime_Profiler { public static function start(){return 0;}public static function finish(...$args):void{} } }
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Saved/Saved_Items_Service.php';
use DSA\Apps\Store_App_Service as App;
$mode=$argv[1]??'off';$options=[App::OPTION=>$mode==='on'?'1':'0'];$writes=0;$productReads=0;$checks=[];$meta=[];
if($mode!=='missing')eval('function WC(){return (object)[];}');
function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}function get_current_user_id(){return 4;}
function get_user_meta($id,$key,$single){return $GLOBALS['meta'][$id][$key]??[];}function update_user_meta($id,$key,$value){$GLOBALS['writes']++;$GLOBALS['meta'][$id][$key]=$value;return true;}
function absint($n){return abs((int)$n);}function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}function sanitize_text_field($s){return trim(strip_tags((string)$s));}function esc_url_raw($s){return (string)$s;}
function __($s,$d=''){return $s;}function _n($a,$b,$n,$d=''){return $n===1?$a:$b;}function get_bloginfo($s){return 'UTF-8';}
function get_post($id){return (object)['ID'=>$id,'post_type'=>get_post_type($id)];}function get_post_type($id){return $id===2?'product':'post';}function get_post_type_object($t){return (object)['labels'=>(object)['singular_name'=>$t]];}
function get_the_title($id){return 'Article '.$id;}function get_permalink($id){return 'https://site.test/story/'.$id;}function get_the_post_thumbnail_url($id,$size){return '';}function get_the_excerpt($id){return 'An article.';}function get_the_date($format,$id){return '2026-10-24';}function get_object_taxonomies($t,$output){return [];}function wp_trim_words($s,$n){return $s;}function wp_strip_all_tags($s,$r=false){return strip_tags($s);}function delete_transient($key){return true;}
function wc_get_product($id){$GLOBALS['productReads']++;if($id!==2)throw new RuntimeException('Article bookmark asked Woo for a product');return new ProductFixture();}
function wc_price($v){return '₹'.$v;}function wc_format_weight($v){return $v.' g';}function wp_get_post_terms($id,$t,$args){return ['Chikki'];}function is_wp_error($v){return false;}
class ProductFixture {function get_image_id(){return 0;}function get_name(){return 'Native product';}function get_permalink(){return 'https://site.test/product/2';}function get_price(){return '200';}function get_weight(){return '250';}function is_in_stock(){return true;}function managing_stock(){return false;}function get_short_description(){return 'Native product detail';}}
try {
 $wish=['id'=>2,'type'=>'wishlist','key'=>'wishlist:2','title'=>'Saved product','url'=>'https://site.test/product/2','savedAt'=>11,'untouched'=>['native'=>'kept']];
 $book=['id'=>1,'type'=>'bookmark','key'=>'bookmark:1','title'=>'Saved article','url'=>'https://site.test/story/1','savedAt'=>12];
 $meta[4]['dsa_saved_items']=[$wish,$book];$before=serialize($meta);$service=new \DSA\Saved\Saved_Items_Service();$on=App::enabled();
 $items=$service->current_items();check(count($items)===($on?2:1),'wishlist projection follows Store while article bookmarks remain');check(serialize($meta)===$before&&$writes===0,'reading saved lists never changes dormant native rows');
 $beforeReads=$productReads;$service->normalize_item($book);check($productReads===$beforeReads,'article bookmark normalization never calls Woo even when Store is on');
 if(!$on){check($productReads===0,'inactive saved projection does not query products');$reply=$service->mutate('add',$wish);check(!$reply['ok']&&serialize($meta)===$before&&$writes===0,'wishlist mutation is refused without rewriting it as a bookmark');}
 $reply=$service->mutate('remove',$book);check($reply['ok']&&$meta[4]['dsa_saved_items']===[$wish],'removing a bookmark preserves the exact dormant wishlist row');
 $reply=$service->mutate('add',['id'=>3,'type'=>'bookmark','title'=>'Article 3','url'=>'https://site.test/story/3','savedAt'=>13]);check($reply['ok']&&$meta[4]['dsa_saved_items'][1]===$wish,'adding an article preserves the exact dormant wishlist row');check(count($reply['items'])===($on?2:1),'mutation reply exposes only available Saved capabilities');
 if($mode==='off'){$options[App::OPTION]='1';check(count($service->current_items())===2,'turning Store back on restores existing wishlists without migration');$options[App::OPTION]='0';}
 if(!$on){$meta[4]['dsa_saved_items']=array_fill(0,100,$wish);$before=serialize($meta);$count=$writes;$reply=$service->mutate('add',['id'=>3,'type'=>'bookmark','title'=>'Article 3','url'=>'https://site.test/story/3']);check(!$reply['ok']&&serialize($meta)===$before&&$writes===$count,'a full inactive list refuses another bookmark instead of evicting dormant wishlists');}
 echo json_encode(['passed'=>true,'cleanup'=>true,'mode'=>$mode,'checks'=>$checks,'scope'=>'actual Saved service and Store state with synthetic native rows'])."\n";
}catch(Throwable $e){echo json_encode(['passed'=>false,'cleanup'=>true,'mode'=>$mode,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
}
