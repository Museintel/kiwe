<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/SEO_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Search_Report_Snapshot.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php';
use DSA\Onboarding\Search_Report_Snapshot as Snapshot;
use DSA\Onboarding\Design_Context_Profile_Service as Profile;
$mode=$argv[1]??'off';$options=['kiwe_seo_workspace_enabled_v1'=>$mode==='on'?'1':'0'];$actions=[];$reads=[];$checks=0;
if($mode==='rankmath')define('RANK_MATH_VERSION','synthetic');
spl_autoload_register(static function($class){if(str_starts_with($class,'DSA\\SEO\\'))throw new RuntimeException('Core attempted to load SEO engine '.$class);});
function get_option($key,$default=false){$GLOBALS['reads'][]=$key;return array_key_exists($key,$GLOBALS['options'])?$GLOBALS['options'][$key]:$default;}
function absint($v){return abs((int)$v);}
function do_action($hook,...$args){$GLOBALS['actions'][]=$hook;}
$assert=static function($ok,$message)use(&$checks){++$checks;if(!$ok)throw new RuntimeException($message);};
$profile=new Profile();$assert(Snapshot::read()['report']===null&&Profile::saved_seo_strength()===null,'No report is invented');
$component=['label'=>'Synthetic evidence','score'=>83,'ready'=>5,'total'=>6,'weight'=>20,'active'=>true];
$report=['score'=>83,'calculatedAt'=>'2026-10-24T00:00:00Z','components'=>array_fill_keys(['onboarding','pages','posts','products','media'],$component)];
$state=['schema'=>1,'requested'=>str_repeat('a',32),'completed'=>str_repeat('a',32),'job'=>null,'report'=>$report,'lease'=>null,'error'=>'','nextAt'=>0,'updatedAt'=>0];
$options[Snapshot::OPTION]=json_encode($state);$options[Snapshot::LEGACY_OPTION]=['score'=>11];
$before=$options;$assert(Snapshot::read()['report']===$report,'Newest completed report wins over legacy');
$assert(Profile::saved_seo_strength()===83,'Core retains saved score with '.$mode);
$assert($profile->overall_seo_report(null,false)===$report&&$actions===[],'Passive compatibility read starts no work');
$profile->overall_seo_report();$assert(($mode==='on'?$actions===['kiwe_seo_score_dirty']:$actions===[]),'Only the active SEO owner can request a refresh');$actions=[];
$assert($options===$before,'Saved report reads never change native choices');
$pending=$state;$pending['requested']=str_repeat('b',32);$options[Snapshot::OPTION]=json_encode($pending);$assert(Snapshot::read()['pending']&&Snapshot::read()['report']===$report,'Pending work preserves the last complete result');
foreach(['json','oversize','schema','token','lease','job','component','score']as$bad){
 $v=$state;switch($bad){case'json':$raw='{';break;case'oversize':$raw=str_repeat('x',16385);break;case'schema':$v['schema']=2;break;case'token':$v['requested']='bad';break;case'lease':$v['lease']=['token'=>'bad','until'=>0];break;case'job':$v['job']=['upper'=>2,'cursor'=>3];break;case'component':$v['report']['components']['pages']['ready']=7;break;case'score':$v['report']['score']='83';break;}
 if(!in_array($bad,['json','oversize'],true))$raw=json_encode($v);$options[Snapshot::OPTION]=$raw;
 $assert(Snapshot::read()['report']===null&&Snapshot::read()['error']!=='','Malformed '.$bad.' cannot substitute a legacy success');
}
unset($options[Snapshot::OPTION]);$assert(Profile::saved_seo_strength()===11,'Earlier completed report remains readable');
unset($options[Snapshot::LEGACY_OPTION]);$options[Profile::OPTION_STATUS]=['completed'=>true,'scores'=>['seoStrength'=>74]];$assert(Profile::saved_seo_strength()===74,'Original onboarding score remains readable');
$assert(!class_exists('DSA\\SEO\\SEO_Score_Service',false),'No SEO worker class was loaded');
echo json_encode(['passed'=>true,'checks'=>$checks,'mode'=>$mode,'pureNativeReads'=>true,'engineLoaded'=>false]).PHP_EOL;
