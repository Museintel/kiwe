<?php
/** Fast adapter boundaries; native WordPress/Bricks sanitation has separate lab proof. */
namespace DSA\Access { class Template_Workspace_Service { public static function builder_actor(){return $GLOBALS['actor'];} } }
namespace {
define('ABSPATH',__DIR__);define('BRICKS_DB_PAGE_CONTENT','_bricks_page_content_2');define('BRICKS_DB_PAGE_HEADER','_bricks_page_header_2');define('BRICKS_DB_PAGE_FOOTER','_bricks_page_footer_2');
$actor=true;$can=true;$nonce=true;$ajax=true;$type='page';$reads=0;$old=[];
function wp_doing_ajax(){return $GLOBALS['ajax'];}function absint($v){return abs((int)$v);}function get_post_type($id){return $GLOBALS['type'];}function current_user_can($cap,$id){return $GLOBALS['can'];}function check_ajax_referer($a,$b,$c){return $GLOBALS['nonce'];}function get_post_meta($id,$key,$single){$GLOBALS['reads']++;return $GLOBALS['old'];}
function wp_slash($v){return addslashes($v);}function wp_unslash($v){return stripslashes($v);}function wp_filter_post_kses($v){return addslashes(strip_tags(stripslashes($v)));}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Source_Runtime_Bridge.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php';$bridge=(new \ReflectionClass(\DSA\Bricks\Bricks_Integration::class))->newInstanceWithoutConstructor();$checks=[];$assert=static function($ok,$label)use(&$checks){$checks[]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new \RuntimeException($label);};
$html='<script data-runtime="approved">window.qa="known";</script><svg><path d="M0 0"/></svg>';$old=[['id'=>'native1','name'=>'html','settings'=>['html'=>$html]],['id'=>'copy','name'=>'text-basic','settings'=>['text'=>'Original']]];
$sanitized=$old;$sanitized[0]['settings']['html']=wp_unslash(wp_filter_post_kses(wp_slash($html)));$sanitized[1]['settings']['text']='Legitimate edit';$_POST=['action'=>'bricks_save_post','postId'=>9];
$run=static fn($data)=>$bridge->preserve_authored_html($data,BRICKS_DB_PAGE_HEADER,'post');$saved=$run($sanitized);
$assert($saved[0]===$old[0]&&$saved[1]['settings']['text']==='Legitimate edit','approved script and SVG restored while legitimate copy survives');
$assert($run($saved)===$saved,'repeated unchanged save is idempotent');
$changed=$sanitized;$changed[0]['settings']['html']='window.qa="new";';$assert($run($changed)===$changed,'changed code is not approved or executed');
$changed=$sanitized;$changed[0]['id']='new';$assert($run($changed)===$changed,'new element cannot inherit code approval');
$changed=$sanitized;$changed[0]['name']='text-basic';$assert($run($changed)===$changed,'different element type cannot inherit approval');
$changed=$sanitized;$changed[0]['settings']['html']='';$assert($run($changed)===$changed,'intentional deletion survives');
foreach(['actor','can','nonce']as$flag){$GLOBALS[$flag]=false;$assert($run($sanitized)===$sanitized,'missing '.$flag.' cannot restore code');$GLOBALS[$flag]=true;}
$type='post';$assert($run($sanitized)===$sanitized,'ordinary article is outside builder scope');$type='page';
$assert($bridge->preserve_authored_html($sanitized,'other_meta','post')===$sanitized,'unrelated metadata untouched');
$assert($bridge->preserve_authored_html($sanitized,BRICKS_DB_PAGE_HEADER,'user')===$sanitized,'wrong metadata owner untouched');
$before=$reads;$ajax=false;$assert($run($sanitized)===$sanitized&&$reads===$before,'ordinary requests perform no owner reads');$ajax=true;
$_POST['action']='other';$assert($run($sanitized)===$sanitized&&$reads===$before,'other AJAX performs no owner reads');$_POST['action']='bricks_save_post';
$assert($run(array_fill(0,5001,$sanitized[0]))===array_fill(0,5001,$sanitized[0]),'oversized collection refused');
foreach(['&amp;&gt;div'=>'&>div','.card&amp;:focus'=>'.card&:focus','&amp;&amp;:hover'=>'&&:hover','[data-x="&gt;"] &gt; img'=>'[data-x="&gt;"] > img']as$input=>$expected)$assert(\DSA\Bricks\Source_Runtime_Bridge::normalize_selector($input)===$expected,'selector operator syntax '.$input);
$css='/* &gt; */ @import url("?x=1&amp;y=2"); @media (width &gt; 10px) { .items&gt;div[data-title="&gt;"] { content:"&amp; &gt; { }"; --data: { .keep&gt;x { value: 1 } }; background:url(data:image/svg+xml,%3Csvg%3E); &amp;:hover { color:red } } }';
$expected=str_replace(['.items&gt;div','&amp;:hover'],['.items>div','&:hover'],$css);
$assert(\DSA\Bricks\Source_Runtime_Bridge::normalize_custom_css($css)===$expected&&\DSA\Bricks\Source_Runtime_Bridge::normalize_custom_css($expected)===$expected,'CSS control repairs rule selectors only, preserving declaration and at-rule data');
echo json_encode(['passed'=>true,'checks'=>$checks],JSON_UNESCAPED_SLASHES).PHP_EOL;
}
