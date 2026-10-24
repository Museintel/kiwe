<?php
declare(strict_types=1);
namespace DSA\Onboarding {
 class Design_Context_Profile_Service {const OPTION_PROFILE='context';}
 class Design_Context_Enhancement_Service {
  const OPTION='enhancement';
  public function __construct(Design_Context_Profile_Service $profiles){}
  public function resolved_public_context(bool $administrator):array {$GLOBALS['contextReads']++;return$GLOBALS['fullContext'];}
 }
}
namespace DSA\SEO {
 class SEO_Defaults_Service {public static function enabled():bool{return$GLOBALS['seoEnabled'];}}
 class SEO_Sitemap_Service {const GENERATION='sitemap_generation';}
}
namespace {
 define('ABSPATH',__DIR__.'/');$seoEnabled=true;$administrator=true;$contextReads=0;$writes=[];$options=[];$checks=[];
 $fullContext=['complete'=>true,'identity'=>['siteName'=>'Public business'],'contact'=>['email'=>'public@example.test'],'seo'=>['primaryGoal'=>'read'],'internalReview'=>['private'=>'not public output']];
 function get_option($k,$d=false){return$GLOBALS['options'][$k]??$d;}function get_theme_mod($k,$d=false){return$d;}function get_bloginfo($k){return$k==='name'?'Public business':'Public description';}function home_url($p='/'){return'https://example.test'.$p;}
 function wp_json_encode($v){return json_encode($v,JSON_THROW_ON_ERROR);}function is_admin(){return true;}function wp_doing_ajax(){return false;}function wp_doing_cron(){return false;}function current_user_can($c){return$GLOBALS['administrator'];}
 function update_option($k,$v,$autoload=null){$GLOBALS['writes'][]=[$k,$autoload];$GLOBALS['options'][$k]=$v;return true;}
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Site/Site_Identity_Service.php';
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Output_Service.php';
 $service=new DSA\SEO\SEO_Output_Service(new DSA\Onboarding\Design_Context_Profile_Service());
 $check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
 $service->prepare_public_context();$saved=get_option(DSA\SEO\SEO_Output_Service::PUBLIC_CACHE);
 $check($contextReads===1&&count($writes)===1,'Cold admin initialization resolves the Core enhancement owner');
 $check($writes[0][1]===false,'Public SEO cache remains non-autoload');
 $check(array_keys($saved['context'])===['complete','identity','contact','seo']&&!isset($saved['context']['internalReview']),'Only the declared public context enters SEO output');
 $service->prepare_public_context();$check($contextReads===1&&count($writes)===1,'Matching public context adds no reconstruction or write');
 $options['context']=['updated'=>'native edit'];$service->prepare_public_context();$check($contextReads===2&&count($writes)===2,'A native context edit invalidates the saved projection');
 $seoEnabled=false;$options['context']=['updated'=>'disabled edit'];$service->prepare_public_context();$check($contextReads===2&&count($writes)===2,'Disabled SEO performs no public-context work');
 $seoEnabled=true;$administrator=false;$service->prepare_public_context();$check($contextReads===2&&count($writes)===2,'Unprivileged initialization performs no public-context work');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>$checks,'scope'=>'Real extracted output owner with synthetic WordPress and Core enhancement I/O'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
