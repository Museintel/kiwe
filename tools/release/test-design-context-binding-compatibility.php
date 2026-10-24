<?php
declare(strict_types=1);
namespace DSA\Onboarding {
 final class Design_Context_Profile_Service {
  public function current(): array { return $GLOBALS['owner']; }
  public function public_context(bool $administrator=false): array { return $GLOBALS['context']; }
 }
}
namespace {
 define('ABSPATH',__DIR__.'/');
 function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
 function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
 function __($value,$domain=''){return $value;}
 class WP_Error {public function __construct(public $code,public $message){}}
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Design_Context_Binding_Service.php';
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Enhancement_Service.php';
 use DSA\Bricks\Design_Context_Binding_Service as Bindings;
 use DSA\Onboarding\Design_Context_Enhancement_Service as Enhancements;
 $owner=['identity'=>['siteType'=>'ecommerce','description'=>'Owner description'],'contentPlan'=>['showBlogRailOnHome'=>true],'about'=>['mission'=>'Owner mission'],'brand'=>['colors'=>[]],'seo'=>[]];
 $legacy=$owner;
 $context=$legacy;
 $context['identity']+=['squareLogo'=>'','roundLogo'=>''];
 $context['business']=['activities'=>Bindings::activities($owner)];
 $hash=static fn($value)=>hash('sha256',wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 $approved=['schema'=>Enhancements::SCHEMA,'sourceContextHash'=>$hash($legacy),'suggestions'=>['copy'=>['businessDescription'=>'Approved description','mission'=>'Must not replace owner mission'],'seo'=>[],'brand'=>['colors'=>[]],'design'=>[]],'authority'=>['approvedAt'=>'2026-10-14'],'assumptions'=>[],'requiresHumanReview'=>[]];
 $options=[Enhancements::OPTION=>$approved];$service=new Enhancements();$checks=[];
 $check=static function($ok,$label)use(&$checks){if(!$ok)throw new \RuntimeException($label);$checks[]=$label;};
 $resolved=$service->resolved_profile();
 $check($resolved['identity']['description']==='Approved description'&&$resolved['about']['mission']==='Owner mission','empty additive fields retain prior approved copy and factual owner evidence');
 $context['business']['activities']['publication']=true;
 $check(!isset($service->resolved_profile()['enhancement']),'a new independent activity invalidates an older approval');
 $context['business']['activities']['publication']=false;
 $context['identity']['roundLogo']='https://example.test/round.png';
 $check(!isset($service->resolved_profile()['enhancement']),'a selected new public asset invalidates an older approval');
 $context['identity']['roundLogo']='';$context['identity']['description']='Changed owner fact';
 $check(!isset($service->resolved_profile()['enhancement']),'a changed original business fact cannot pass the compatibility path');
 $context['identity']['description']=$legacy['identity']['description'];
 $check($service->import(['schema'=>Enhancements::SCHEMA,'sourceContextHash'=>$hash($legacy)],1) instanceof WP_Error,'fresh imports still require the complete current fingerprint');
 $options[Enhancements::OPTION]['sourceContextHash']=$hash($context);
 $check(isset($service->resolved_profile()['enhancement']),'an exact current approval remains valid');
 $context['business']['activities']['commerce']=false;
 $check(!isset($service->resolved_profile()['enhancement']),'a later native activity edit invalidates the current approval');
 echo json_encode(['passed'=>true,'checks'=>$checks,'count'=>count($checks)])."\n";
}
