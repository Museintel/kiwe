<?php
/** Focused native regression for the RC64 required form-argument repair. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
$proof=['schema'=>'kiwe-native-seo-render/v1','kiwe'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>true];
$actor=get_current_user_id();$level=ob_get_level();
try{
 $users=get_users(['role'=>'administrator','fields'=>'ID','number'=>1]);if(!$users)throw new RuntimeException('No native test administrator');wp_set_current_user((int)$users[0]);
 $proof['checks'][]=['label'=>'matching installed candidate','passed'=>DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION];
 $proof['checks'][]=['label'=>'installed package integrity','passed'=>!empty(DSA\Runtime\Package_Manifest::verify(true)['valid'])];
 ob_start();$service=new DSA\SEO\SEO_Refinement_Service(new DSA\AI\AI_Broker_Service(new DSA\Settings()),new DSA\Onboarding\Design_Context_Profile_Service());$service->render();$html=ob_get_clean();
 $proof['checks'][]=['label'=>'native full SEO screen renders through checklist and start controls','passed'=>str_contains($html,'kiwe_update_seo_checklist')&&str_contains($html,'name="_wpnonce"')&&str_contains($html,'Start a library review')];
 $proof['checks'][]=['label'=>'AI-off native screen preserves disabled generation controls','passed'=>substr_count($html,' disabled')>=3];
}catch(Throwable $e){$proof['error']=['class'=>get_class($e),'line'=>$e->getLine()];}
finally{while(ob_get_level()>$level)ob_end_clean();wp_set_current_user($actor);}
$proof['passed']=!isset($proof['error'])&&!in_array(false,array_column($proof['checks'],'passed'),true);
return $proof;
