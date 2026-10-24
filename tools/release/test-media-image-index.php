<?php
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Context_Media_Service.php';
use DSA\Onboarding\Context_Media_Service as Media;
$posts=[1=>(object)['post_type'=>'post','post_content'=>'']];$meta=[];$writes=0;$checks=[];
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;$GLOBALS['writes']++;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);$GLOBALS['writes']++;}
function wp_attachment_is_image($id){return in_array((int)$id,[10,11,12,13,14,15,16,17],true);}
function absint($n){return abs((int)$n);}
function check($v,$name){if(!$v)throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
$posts[1]->post_content='<img class="aligncenter wp-image-10" src="a"><!-- wp:image {"id":11} /-->[gallery ids="12, 13"]<!-- wp:gallery {"ids":[14,15]} /-->';
Media::index_post(1);check($meta[1][Media::INLINE_META]==='10,11,12,13,14,15','native images blocks and both gallery formats are indexed');
$before=$writes;Media::index_post(1);check($writes===$before,'unchanged content never rewrites derived media hints');
$posts[1]->post_content='wp-image-16 &lt;img class="wp-image-17"&gt; <img class="wp-image-999" src="external">';Media::index_post(1);check(!isset($meta[1][Media::INLINE_META]),'plain text escaped examples and non-image IDs are not automatic references');
$meta[1]['_bricks_page_content_2']=[['id'=>'f00bad','settings'=>['image'=>['id'=>16,'url'=>'image.png'],'other'=>['id'=>17]]]];Media::builder_changed(1,1,'_bricks_page_content_2');check($meta[1][Media::INLINE_META]==='16','native Bricks image reference is indexed without treating arbitrary IDs as images');
unset($meta[1]['_bricks_page_content_2']);Media::builder_changed(1,1,'_bricks_page_content_2');check(!isset($meta[1][Media::INLINE_META]),'removing builder image removes only the rebuildable hint');
$before=$writes;Media::builder_changed(1,1,'unrelated_secret');check($writes===$before,'unrelated metadata never invokes an indexing write');
foreach(['revision','attachment','shop_order','shop_order_refund','product_variation']as$type){$posts[2]=(object)['post_type'=>$type,'post_content'=>'<img class="wp-image-10">'];Media::index_post(2);check(!isset($meta[2]),$type.' does not create content-reference state');}
echo json_encode(['passed'=>true,'checks'=>$checks]).PHP_EOL;
