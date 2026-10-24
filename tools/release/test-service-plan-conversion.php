<?php
declare(strict_types=1);
namespace DSA\Onboarding { final class Design_Context_Profile_Service { public const OPTION_PROFILE='kiwe_seam_design_context_v1'; } }
namespace {
 define('ABSPATH',__DIR__); define('ARRAY_A','ARRAY_A'); define('OBJECT','OBJECT');
 final class WP_Error { public function __construct(public string $code,public string $message,public array $data=[]) {} }
 function current_user_can(string $cap,...$args):bool { return !empty($GLOBALS['conversionCaps'][$cap]); }
 function wp_json_encode($value):string { return (string)json_encode($value,JSON_UNESCAPED_SLASHES); }
 function sanitize_key(string $value):string { return preg_replace('/[^a-z0-9_-]/','',strtolower($value)); }
 function sanitize_title(string $value):string { return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($value)),'-'); }
 function sanitize_text_field(string $value):string { return trim(preg_replace('/\s+/',' ',strip_tags($value))); }
 function sanitize_textarea_field(string $value):string { return trim(strip_tags($value)); }
 function wp_kses_post(string $value):string { return preg_replace('/<script\b[^>]*>.*?<\/script>/is','',$value); }
 function get_option(string $key,$default=false) { return $GLOBALS['conversionOptions'][$key]??$default; }
 function post_type_exists(string $type):bool { return !empty($GLOBALS['conversionRegistered']); }
 function taxonomy_exists(string $taxonomy):bool { return !empty($GLOBALS['conversionRegistered']); }
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Onboarding/Native_Service_Source_Service.php';
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Onboarding/Service_Conversion_Exception.php';
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Onboarding/Native_Service_Conversion_Service.php';
 use DSA\Onboarding\Native_Service_Conversion_Service as Conversion;
 $checks=0;
 $check=static function(bool $ok,string $label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);};
 $reject=static function(array $services)use($check){try{Conversion::prepare_plans($services);$check(false,'unsupported plans accepted');}catch(RuntimeException $error){$check($error->getMessage()!=='unsupported plans accepted','unsupported plans refused before native writes');}};
 $a=['stableId'=>'plan-advice','recordId'=>0,'title'=>'Design advice','summary'=>"A quote: \"hi\"\nA slash: \\",'description'=>'<p><strong>Details</strong> and <em>examples</em>.</p>','status'=>'publish','imageId'=>17,'parentId'=>0,'menuOrder'=>7,'categoryPaths'=>"Advice > Design\nAdvice > Design, Reviews",'taxonomyPaths'=>[],'meta'=>[]];
 $b=array_replace($a,['stableId'=>'plan-review','title'=>'Independent review','status'=>'pending','imageId'=>0,'menuOrder'=>2,'categoryPaths'=>'']);
 $services=['enabled'=>true,'sourcePostType'=>'','useForNavigation'=>true,'items'=>[$a,$b]]; $original=$services; $mapped=Conversion::prepare_plans($services);
 $check($services===$original,'review mapping does not alter the original plans');
 $check(count($mapped)===2&&$mapped[0]['originalStatus']==='publish'&&$mapped[1]['originalStatus']==='pending'&&$mapped[0]['plan']['status']==='draft'&&$mapped[1]['plan']['status']==='draft','published and pending plans convert only to drafts');
 $check($mapped[0]['plan']['summary']===$a['summary']&&$mapped[0]['plan']['description']===$a['description'],'multiline quotes slashes and formatting survive mapping');
 $check($mapped[0]['plan']['imageId']===17&&$mapped[0]['plan']['menuOrder']===7&&$mapped[1]['plan']['menuOrder']===2,'existing image reference and display order survive mapping');
 $check($mapped[0]['paths']===['Advice > Design','Reviews']&&$mapped[0]['plan']['taxonomyPaths']['kiwe_service_category']==="Advice > Design\nReviews",'hierarchy maps to the native category source with no repeated assignment');
 $check($mapped[0]['slug']==='design-advice','review uses the native suggested address sanitizer');
 foreach([
  [],array_replace($services,['sourcePostType'=>'external_service']),array_replace($services,['extension'=>'hidden']),
  array_replace($services,['items'=>[$a,$a]]),array_replace($services,['items'=>[$a,array_replace($b,['title'=>'Design Advice'])]]),
  array_replace($services,['items'=>[array_replace($a,['recordId'=>8])]]),array_replace($services,['items'=>[array_replace($a,['parentId'=>8])]]),
  array_replace($services,['items'=>[array_replace($a,['meta'=>['price'=>10]])]]),array_replace($services,['items'=>[array_replace($a,['taxonomyPaths'=>['external'=>'Advice']])]]),
  array_replace($services,['items'=>[array_replace($a,['private'=>'hidden'])]]),array_replace($services,['items'=>[array_replace($a,['title'=>''])]]),
  array_replace($services,['items'=>[array_replace($a,['stableId'=>'Plan Bad'])]]),array_replace($services,['items'=>[array_replace($a,['imageId'=>'17'])]]),
  array_replace($services,['items'=>[array_replace($a,['menuOrder'=>-1])]]),array_replace($services,['items'=>[array_replace($a,['status'=>'trash'])]]),
  array_replace($services,['items'=>[array_replace($a,['description'=>'<script>alert(1)</script>'])]]),array_replace($services,['items'=>[array_replace($a,['summary'=>str_repeat('x',2001)])]]),
  array_replace($services,['items'=>[array_replace($a,['categoryPaths'=>str_repeat('Level > ',11).'Leaf'])]]),array_replace($services,['items'=>array_fill(0,51,$a)]),
 ]as$invalid)$reject($invalid);
 $minimal=Conversion::prepare_plans(['items'=>[['stableId'=>'plan-minimal','title'=>'Minimal']]]);
 $check($minimal[0]['plan']['recordId']===0&&$minimal[0]['plan']['status']==='draft'&&$minimal[0]['plan']['imageId']===0,'older minimal plans receive explicit safe native defaults');
 $GLOBALS['conversionCaps']=[];$conversion=new Conversion();
 $check($conversion->preview() instanceof WP_Error&&$conversion->convert(str_repeat('0',64))->data['status']===403&&$conversion->undo(str_repeat('0',64))->data['status']===403,'role denial occurs before profile SQL encryption or writes');
 $GLOBALS['conversionCaps']=array_fill_keys(['kiwe_manage_context','edit_kiwe_services','assign_kiwe_service_category','manage_kiwe_service_category'],true);
 $check($conversion->convert('stale')->data['status']===400,'malformed review proof is rejected before SQL or writes');
 $check($conversion->undo(str_repeat('0',64))->data['status']===403,'conversion authority does not grant deletion authority');
 $check($conversion->undo_summary()===null,'a role without recovery authority has no private recovery projection');
 $key=DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE;
 $GLOBALS['conversionOptions']=[$key=>['services'=>$services]];$GLOBALS['conversionCaps']=['kiwe_manage_context'=>true];
 $check($conversion->available()&&$conversion->convert('stale')->data['status']===400,'unbound plan review uses context authority before native capability projection');
 $GLOBALS['conversionRegistered']=true;
 $check(!$conversion->available()&&$conversion->convert(str_repeat('0',64))->data['status']===403,'an independently registered source cannot be claimed with context authority');
 $GLOBALS['conversionOptions'][$key]['services']['sourcePostType']='kiwe_service';
 $check($conversion->convert(str_repeat('0',64))->data['status']===403,'selected native source rechecks its own record and taxonomy authority');
 $GLOBALS['conversionRegistered']=false;
 $GLOBALS['conversionOptions']=[Conversion::OPTION=>['schema'=>Conversion::SCHEMA],$key=>['services'=>['sourcePostType'=>'kiwe_service','items'=>[]],'brand'=>['notes'=>'Keep']]];
 try{Conversion::assert_settings_profile(['exists'=>true,'value'=>['services'=>$services]]);$check(false,'unsafe settings restore accepted');}catch(RuntimeException $error){$check($error->getMessage()!=='unsafe settings restore accepted','older settings cannot strand the separately protected native collection');}
 try{Conversion::assert_settings_profile(['exists'=>false]);$check(false,'unsafe profile deletion accepted');}catch(RuntimeException $error){$check($error->getMessage()!=='unsafe profile deletion accepted','settings deletion cannot disconnect retained conversion recovery');}
 Conversion::assert_settings_profile(['exists'=>true,'value'=>['services'=>$GLOBALS['conversionOptions'][$key]['services'],'brand'=>['notes'=>'Later']]]);$check(true,'unrelated context settings can be restored while the native Service source remains protected');
 echo "$checks Service plan conversion mapping and authority checks passed.\n";
}
