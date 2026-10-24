<?php
declare(strict_types=1);
namespace DSA\Onboarding {
 class Design_Context_Profile_Service { public const OPTION_PROFILE='profile'; }
 class Design_Context_Enhancement_Service { public function resolved_profile(){ $GLOBALS['resolutions']=($GLOBALS['resolutions']??0)+1; return \get_option('profile',[]); } }
 class Context_Selection_Service { public static function public_media($post){return $post && $post->post_status==='inherit';} }
}
namespace DSA\Apps {class Store_App_Service {public static function enabled(){return false;}}}
namespace DSA\Bricks {class Commerce_Binding_Service {public function __construct(){throw new \RuntimeException('Inactive Store must not construct commerce bindings');}}}
namespace DSA\Access {class Tag_Group_Service {public function dynamic_groups(){return [17=>['label'=>'Independent article terms']];}}}
namespace {
 define('ABSPATH',__DIR__.'/'); $reads=0; $queries=0;
 $options=['profile'=>['identity'=>['siteType'=>'ecommerce','industry'=>'Retail','squareLogoId'=>12,'roundLogoId'=>13], 'seo'=>['legalName'=>'Public legal name','primaryGoal'=>'buy'], 'audience'=>['primary'=>'Families'], 'commerce'=>['currency'=>'XXX'], 'privateSecret'=>'NEVER EXPORT'],'site_icon'=>12,'blog_public'=>'1','woocommerce_currency'=>'INR'];
 function __($s,$d=''){return $s;} function get_option($k,$d=false){$GLOBALS['reads']++;return $GLOBALS['options'][$k]??$d;} function absint($v){return abs((int)$v);} function sanitize_text_field($v){return strip_tags($v);} function sanitize_textarea_field($v){return strip_tags($v);} function esc_url_raw($v){return $v;}
 function get_bloginfo($k){return $k==='language'?'en-IN':'';} function wp_timezone_string(){return 'Asia/Kolkata';}
 function wp_attachment_is_image($id){return in_array($id,[12,13],true);} function wp_get_attachment_url($id){return 'https://example.test/image-'.$id.'.png';} function get_post($id){return (object)['ID'=>$id,'post_status'=>$id===12?'inherit':'private'];}
 if(!in_array('--non-woo',$argv,true))eval('function WC(){return null;}');
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Design_Context_Binding_Service.php';
 use DSA\Bricks\Design_Context_Binding_Service as Bindings;
 $checks=[]; $check=static function($ok,$label)use(&$checks){if(!$ok)throw new \RuntimeException($label);$checks[]=$label;};
 $inactiveContract=Bindings::contract();
 $check(isset($inactiveContract['fields']['tagGroups.group_17.name'])&&!isset($inactiveContract['collections']['commerce.products']),'Inactive Store keeps Core term-group bindings without constructing optional pairing code');
 $before=$reads; $fields=Bindings::fields(); $definitions=Bindings::definitions();
 $check($reads===$before,'enumerating static bindings performs zero business reads');
 $check($fields['identity.siteName']['tag']==='{site_title}'&&$fields['identity.tagline']['tag']==='{site_tagline}','existing WordPress tags own native title and tagline');
 $check($fields['identity.logoInverse']['kind']==='image'&&$fields['contact.emailUrl']['kind']==='link','image and link semantics are explicit');
 $check(Bindings::render('kiwe_context_private_secret')===null&&$reads===$before,'an arbitrary profile key cannot become a public tag');
 $check(Bindings::render('kiwe_context_identity_industry')==='Retail','declared scalar follows its existing profile owner');
 $check(Bindings::render('kiwe_context_identity_industry','image')===[],'text cannot become image IDs');
 $check(Bindings::render('kiwe_context_identity_square_logo','image')===[12],'approved public asset returns actual native attachment IDs');
 $check(Bindings::render('kiwe_context_identity_round_logo','image')===[],'a private asset is never exposed by a public binding');
 $check(Bindings::render('kiwe_context_identity_square_logo')==='https://example.test/image-12.png','text context returns the same native public image URL');
 $check(Bindings::render('kiwe_context_localization_language')==='en-IN'&&Bindings::render('kiwe_context_localization_timezone')==='Asia/Kolkata','native locale and timezone override profile guesses');
 $check(Bindings::render('kiwe_context_seo_primary_goal')==='buy','the shared SEO goal is dynamically available');
 $check(Bindings::render('kiwe_context_commerce_plan_currency')===(function_exists('WC')?'INR':'XXX'),'native Woo currency is reused when Woo is present');
 $check($GLOBALS['resolutions']===1,'multiple declared tags share one request-local owner resolution');
 $options['profile']['identity']['industry']='New native industry';
 Bindings::invalidate();
 $check(Bindings::render('kiwe_context_identity_industry')==='New native industry'&&$GLOBALS['resolutions']===2,'native invalidation refreshes the shared request projection');
 $activity=Bindings::activities($options['profile']); $check($activity['commerce']&&!$activity['publication'],'legacy store classification retains its original meaning');
 $options['profile']['business']['activities']=['publication'=>true,'physical_store'=>false];
 $activity=Bindings::activities($options['profile']); $check($activity['commerce']&&$activity['publication']&&!$activity['physical_store'],'commerce and publication are independent without inventing a physical location');
 $options['profile']['identity']['siteType']='publication'; $options['profile']['business']['activities']['publication']=false;
 $check(!Bindings::activities($options['profile'])['publication'],'an explicit publication choice supersedes an older single-type label');
 $sample=['identity'=>['siteName'=>'Example','logo'=>'x'],'about'=>['founder'=>['website'=>'x','facebook'=>'x','linkedToWordPressUser'=>true]],'resources'=>['query'=>['page'=>1]],'privacy'=>['adminIdentityExcluded'=>true]];
 $check(Bindings::coverage($sample)['complete'],'public facts, native collections and provenance have declared coverage');
 $sample['identity']['futurePublicField']='new'; $coverage=Bindings::coverage($sample);
 $check(!$coverage['complete']&&$coverage['uncovered']===['identity.futurePublicField'],'a future public field cannot silently bypass the binding contract');
 $check(!isset($definitions['site_title'])&&!isset($definitions['kiwe_site_logo']),'new bindings do not register duplicate native or existing Kiwe tags');
 $options['profile']['audience']['primary']='<script>unsafe</script>Families';
	Bindings::invalidate();
 $check(!str_contains(Bindings::render('kiwe_context_audience_primary'),'<'),'public scalar output does not expose executable markup');
 if(isset($argv[1])&&!str_starts_with($argv[1],'--')){$snapshot=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$coverage=Bindings::coverage($snapshot['nativeProfile']);$check($coverage['complete'],'current native profile has no undeclared fields: '.implode(',',$coverage['uncovered']));}
 echo json_encode(['passed'=>true,'checks'=>$checks,'count'=>count($checks),'woo'=>function_exists('WC'),'newTags'=>count($definitions),'fields'=>count($fields)])."\n";
}
