<?php
declare(strict_types=1);
namespace Bricks { class Query { public static $current=null;public static function is_looping(){return self::$current!==null;}public static function get_loop_object(){return self::$current;} } }
namespace DSA\Onboarding { class Context_Selection_Service {public const TESTIMONIAL_META='_kiwe_design_context_testimonial';public static int $reads=0;public static array $records=[];public static array $selected=[];public static function testimonials($limit){self::$reads++;return array_slice(array_values(array_filter(self::$records,static fn($r)=>!empty(self::$selected[$r['id']])&&!empty($r['public']))),0,$limit);}public static function selected($kind,$id){return !empty(self::$selected[$id]);}public static function public_testimonial($comment){return !empty(self::$records[$comment->comment_ID]['public']);}} }
namespace DSA\Discussion { class Discussion_Reactions {public const HEART='_kiwe_discussion_business_heart_v1';public const PIN='_kiwe_discussion_pinned_v1';} }
namespace {
define('ABSPATH',__DIR__.'/');class WP_Comment {public function __construct(public int $comment_ID){}}
function __($s,$domain=''){return$s;}function absint($v){return abs((int)$v);}function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}function esc_url_raw($v){return preg_match('#^https?://#',$v)?$v:'';}function get_comment($id){return new WP_Comment((int)$id);}
$actions=[];function do_action($hook,...$args){if($hook==='litespeed_tag_add'){$GLOBALS['page_tags'][]=$args[0];return;}$GLOBALS['actions'][]=$hook;}function get_comments($args){return[];}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Context_Collection_Service.php';
$owner='DSA\\Onboarding\\Context_Selection_Service';$service=new DSA\Bricks\Context_Collection_Service();$checks=[];$assert=static function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
$service->definitions();DSA\Bricks\Context_Collection_Service::fields();$assert($owner::$reads===0,'chooser definitions do not query comments');
$assert($service->render('kiwe_private_comment_email')===null&&$owner::$reads===0,'unknown private fields are never read');
$assert($service->run_query(['untouched'],(object)['object_type'=>'post'])===['untouched']&&$owner::$reads===0,'ordinary native queries remain exact');
$owner::$records=[1=>['id'=>1,'name'=>'Reader <script>','quote'=>'Native quote','url'=>'https://example.test/#comment-1','public'=>true],2=>['id'=>2,'name'=>'Not selected','public'=>true],3=>['id'=>3,'name'=>'Private parent','public'=>false]];$owner::$selected=[1=>true,3=>true];
$query=(object)['object_type'=>'kiwe_testimonials','settings'=>['kiweTestimonialLimit'=>1000]];
$assert(array_map(static fn($c)=>$c['id'],$service->run_query([],$query))===[1],'only selected approved public native comments enter the bounded loop');
$assert($service->render('kiwe_testimonial_name')==='','outside a native comment loop no random testimonial is substituted');
Bricks\Query::$current=['_kiweContextObject'=>'testimonial','id'=>1,'name'=>'Untrusted overwrite'];$assert($service->render('kiwe_testimonial_name')==='Reader &lt;script&gt;'&&$service->render('kiwe_testimonial_quote')==='Native quote','public name and quote stay escaped and share the current owner instead of caller values');
$assert($service->render('kiwe_testimonial_url','link')==='https://example.test/#comment-1'&&$service->render('kiwe_testimonial_quote','link')==='','only the native public URL can be used as a link');
$assert($service->render('kiwe_testimonial_rating')==='','ordinary comments never acquire an invented rating');
$assert($owner::$reads===1,'multiple fields share one bounded request-local native projection');
$owner::$selected[1]=false;DSA\Bricks\Context_Collection_Service::invalidate();$assert($service->render('kiwe_testimonial_quote')===''&&$service->run_query([],$query)===[],'revoking selection immediately removes saved public bindings');
$assert($service->render('kiwe_testimonials_available')==='','an empty native collection supplies its real visibility condition');
$assert(isset($service->query_options([])['queryTypes']['kiwe_testimonials']),'the actual Bricks chooser exposes the native collection type');
$service->comment_changed(2);$service->comment_meta_changed(1,2,'unrelated','x');$assert($actions===['kiwe_public_context_changed','litespeed_purge'],'native edits invalidate only pages that rendered the community collection, including eligible descendants');
$service->comment_meta_changed(1,1,DSA\Onboarding\Context_Selection_Service::TESTIMONIAL_META,'');$assert($actions===['kiwe_public_context_changed','litespeed_purge'],'curation removal reuses the existing native collection-tag cache owner');
$service->comment_meta_changed(2,1,DSA\Onboarding\Context_Selection_Service::TESTIMONIAL_META,'1');$assert(count($actions)===2,'multiple native mutations issue at most one page-cache purge per request');
$assert(in_array('kiwe_testimonials',$GLOBALS['page_tags']??[],true),'a rendered collection carries its native cache tag without another visitor request');
echo json_encode(['passed'=>true,'checks'=>$checks,'count'=>count($checks)]).PHP_EOL;
}
