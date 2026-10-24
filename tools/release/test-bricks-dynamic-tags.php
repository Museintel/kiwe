<?php
/** Fast behavioral boundaries; actual Bricks/roles are separately exercised by the native lab proof. */
namespace DSA\Onboarding {
 class Design_Context_Profile_Service { public const OPTION_PROFILE='profile'; public function service_post_types(){return ['kiwe_service'=>[]];} }
 class Design_Context_Enhancement_Service { public function resolved_profile(){return $GLOBALS['profile'];} }
}
namespace DSA\Site { class Site_Identity_Service { public const OPTION_LOGO='logo',OPTION_LOGO_INVERSE='inverse'; public static function attachment_id($key){return $key==='logo'?12:0;}public static function logo_url($variant){return 'https://example.test/logo.png';}public static function store_phone(){return '+919000000000';}public static function store_email(){return 'public@example.test';} } }
namespace Bricks { class Query { public static $object=null;public static function is_looping(){return self::$object!==null;} public static function get_loop_object(){return self::$object;} } }
namespace {
 define('ABSPATH',__DIR__);
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
 class WP_User {public function __construct(public int $ID){}}
 $options=['kiwe_tag_groups_v1'=>[['id'=>7,'kind'=>'post','name'=>'Topics & ideas'],['id'=>8,'kind'=>'product','name'=>'Ingredients']],'profile'=>['services'=>['enabled'=>true,'sourcePostType'=>'kiwe_service'],'contact'=>['address'=>['line1'=>'Native non-Woo address','country'=>'IN','state'=>'MH']]]];
 $profile=['about'=>['team'=>['enabled'=>true,'members'=>[['id'=>'user-22','userId'=>22,'name'=>'Public Person','title'=>'Designer','bio'=>'Public biography','imageId'=>12]]]]];
 $reads=0;$termQueries=[];$menus=0;$preview=false;
 $records=[1=>(object)['ID'=>1,'post_type'=>'post','post_status'=>'publish','post_password'=>''],2=>(object)['ID'=>2,'post_type'=>'post','post_status'=>'draft','post_password'=>''],3=>(object)['ID'=>3,'post_type'=>'product','post_status'=>'publish','post_password'=>''],9=>(object)['ID'=>9,'post_type'=>'kiwe_service','post_status'=>'publish','post_password'=>'','post_title'=>'Native service','post_excerpt'=>'Native summary','post_content'=>'<p>Native description</p>']];
 function __($s,$d=''){return $s;} function absint($v){return abs((int)$v);} function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
 function sanitize_text_field($s){return strip_tags($s);}function sanitize_textarea_field($s){return strip_tags($s);}function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}
 function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_url_raw($s){return $s;}function wp_kses_post($s){return strip_tags($s,'<p><strong>');}
 function get_option($k,$default=false){$GLOBALS['reads']++;return $GLOBALS['options'][$k]??$default;}function taxonomy_exists($k){return in_array($k,['post_tag','product_tag']);}
 function wp_get_nav_menus(){$GLOBALS['menus']++;return [];}function get_post($id){return $GLOBALS['records'][$id]??null;}
 function post_password_required($p){return $p->post_password!=='';}function bricks_is_builder_call(){return $GLOBALS['preview'];}function current_user_can($cap,$id=0){return $GLOBALS['preview']&&$id===2;}
 function get_post_thumbnail_id($id){return 12;}function wp_get_attachment_url($id){return 'https://example.test/image-'.$id.'.png';}function get_permalink($id){return 'https://example.test/service/'.$id;}
 function get_terms($args){$GLOBALS['termQueries'][]=$args;return [(object)['name'=>'Public & safe','term_id'=>11]];}function is_wp_error($v){return false;}function get_term_link($t){return 'https://example.test/tag/'.$t->term_id;}
 $root=dirname(__DIR__,2);require_once $root."/wp-content/mu-plugins/dsa/includes/Bricks/Design_Context_Binding_Service.php";require $root.'/wp-content/mu-plugins/dsa/includes/Access/Tag_Group_Service.php';require $root.'/wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php';require $root.'/wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php';
 $class=new \ReflectionClass(\DSA\Bricks\Bricks_Integration::class);$bridge=$class->newInstanceWithoutConstructor();$checks=[];
 $assert=function($ok,$label)use(&$checks){$checks[]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new \RuntimeException($label);};
 $before=$reads;$assert($bridge->render_dynamic_content('Native copy {post_title}')==='Native copy {post_title}'&&$reads===$before,'ordinary content has zero owner reads');
 $assert($bridge->render_dynamic_tag('{kiwe_unknown_secret}')==='{kiwe_unknown_secret}'&&$reads===$before,'unknown tags remain native without data enumeration');
 $tags=$bridge->add_dynamic_tags([['name'=>'{kiwe_site_logo}','label'=>'Existing']]);$names=array_column($tags,'name');
 $assert(count(array_keys($names,'{kiwe_site_logo}'))===1,'catalog preserves existing registrations');
 $assert(in_array('{kiwe_service_title}',$names,true)&&in_array('{kiwe_team_title}',$names,true),'Services and Team are discoverable');
 $assert(in_array('{kiwe_tag_group_7}',$names,true)&&in_array('{kiwe_tag_group_7_name}',$names,true)&&in_array('{kiwe_tag_group_7_all}',$names,true),'each tag category has stable context/name/all choices');
 $options['kiwe_tag_groups_v1'][0]['name']='Renamed';$after=$bridge->add_dynamic_tags([]);$assert(count(array_filter($after,fn($t)=>$t['name']==='{kiwe_tag_group_7}'&&str_contains($t['label'],'Renamed')))===1,'rename updates labels without breaking saved tags');
 $assert($bridge->render_dynamic_tag('kiwe_site_logo',null,'image')===[12],'logo uses native image context');
 $assert($bridge->render_dynamic_tag('{kiwe_store_phone_url}')==='tel:+919000000000','public phone URL uses the PhoneKey adapter without account factor reads');
 $menusBefore=$menus;$assert(str_contains($bridge->render_dynamic_content('Topics: {kiwe_tag_group_7}',1),'Public &amp; safe')&&$menus===$menusBefore,'render resolves escaped term links without listing menus');
 $args=end($termQueries);$assert($args['object_ids']===[1]&&$args['number']===100&&$args['meta_query'][0]['value']==='7','current record selection is scoped and bounded');
 $assert($bridge->render_dynamic_tag('{kiwe_tag_group_7}',3)==='','post group cannot cross into product tags');
 $assert($bridge->render_dynamic_tag('{kiwe_tag_group_8}',3)!==''&&end($termQueries)['taxonomy']==='product_tag','product tag categories follow their native taxonomy');
 $assert($bridge->render_dynamic_tag('{kiwe_tag_group_7}',2)==='','draft assignments are private');
 $preview=true;$assert($bridge->render_dynamic_tag('{kiwe_tag_group_7}',2)!=='','permitted builder draft preview uses native object capability');$preview=false;
 $assert($bridge->render_dynamic_tag('{kiwe_tag_group_7_all}')!==''&&!isset(end($termQueries)['object_ids'])&&end($termQueries)['hide_empty']===true,'all group view excludes unused terms and stays bounded');
 $assert($bridge->render_dynamic_tag('{kiwe_tag_group_7}',1,'image')==='','taxonomy values cannot become images');
 $assert($bridge->render_dynamic_tag('{kiwe_service_title}',9)==='Native service'&&$bridge->render_dynamic_tag('{kiwe_service_summary}',9)==='Native summary','service values follow native records');
 $assert($bridge->render_dynamic_tag('{kiwe_service_image}',9,'image')===[12],'service image returns native attachment IDs');
 $assert($bridge->render_dynamic_tag('{kiwe_service_title}',1)==='','unrelated posts cannot be services');
 $records[9]->post_status='draft';$assert($bridge->render_dynamic_tag('{kiwe_service_description}',9)==='','unpublished service content stays private');$records[9]->post_status='publish';
 $options['profile']['services']['enabled']=false;$assert($bridge->render_dynamic_tag('{kiwe_service_title}',9)==='','disabled services cannot expose plans');
 $assert($bridge->render_dynamic_tag('{kiwe_store_address_1}')==='Native non-Woo address','non-Woo address uses Design Context authority');
 $assert($bridge->render_dynamic_tag('{kiwe_team_name:user-22}')==='Public Person','stable team selector resolves public member');
 \Bricks\Query::$object=new WP_User(999);$assert($bridge->render_dynamic_tag('{kiwe_team_name}')==='','non-team user loop never falls back to another person');\Bricks\Query::$object=null;
 $profile['about']['team']['enabled']=false;\DSA\Bricks\Design_Context_Binding_Service::invalidate();$assert($bridge->render_dynamic_tag('{kiwe_team_name:user-22}')==='','disabled team stays private');
 $options['kiwe_tag_groups_v1']=[];$assert($bridge->render_dynamic_tag('{kiwe_tag_group_7_name}')==='','removed group fails closed');
 echo json_encode(['passed'=>true,'checks'=>$checks]);
}
