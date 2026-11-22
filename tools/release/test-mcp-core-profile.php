<?php
define('ABSPATH',__DIR__.'/');$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/';require$base.'Runtime_Contract.php';require$base.'Core_Profile_Contract.php';
use DSA\Apps\Core_Profile_Contract as Profile;use DSA\Apps\Runtime_Contract as Runtime;
$checks=0;function check($ok,$label){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label);}function denied($m,$active=false){try{Profile::validate($m,$active);}catch(RuntimeException){return true;}return false;}
$minimum=array_fill_keys(Runtime::IDS,'1.0.0');$full=['apps'=>['profile'=>'core','coreApi'=>4,'minimum'=>$minimum],'files'=>[]];
check(Profile::validate($full,false)===$minimum&&Profile::validate($full,true)===$minimum,'Current complete profile retains all native App floors');
$old=$full;$old['apps']['coreApi']=3;unset($old['apps']['minimum']['mcp']);
check(count(Profile::validate($old,false))===5,'First bridge accepts the existing five-App inventory while MCP is Off');
check(denied($old,true),'An active MCP installation cannot silently downgrade to an undeclared compatibility profile');
$future=$full;$future['apps']['coreApi']=5;$future['apps']['minimum']['google']='1.0.0';$future['apps']['minimum']['meta']='1.0.0';
check(Profile::validate($future,true)===$minimum,'A signed future Core may add optional Apps while retaining every currently known floor');
check(Runtime::IDS===['seo','store','guest','ai','sitegraph','mcp']&&!array_key_exists('google',Profile::validate($future,true)),'Future metadata grants no runtime registration or App authority');
foreach(Runtime::IDS as$id){$missing=$future;unset($missing['apps']['minimum'][$id]);check(denied($missing,true),'Future profile cannot drop a known App floor '.$id);}
foreach([0,65,'4',true,1.2]as$api){$invalid=$full;$invalid['apps']['coreApi']=$api;check(denied($invalid),'Invalid compatibility API rejected');}
foreach(['../other','BadIdentity',str_repeat('a',49),'google app']as$id){$invalid=$future;$invalid['apps']['minimum'][$id]='1.0.0';check(denied($invalid),'Unsafe future App identity rejected');}
foreach(['1','latest','1.0.0;code',null,true]as$version){$invalid=$future;$invalid['apps']['minimum']['google']=$version;check(denied($invalid),'Invalid future floor rejected');}
$over=$future;for($n=0;$n<24;$n++)$over['apps']['minimum']['future-'.$n]='1.0.0';check(denied($over),'Future App metadata is bounded');
$wrong=$full;$wrong['apps']['extra']=true;check(denied($wrong),'Unknown authority fields rejected');
$legacy=['apps'=>['profile'=>'core','coreApi'=>1,'minimum'=>['seo'=>'1.0.0','store'=>'1.0.0']],'files'=>[]];check(denied($legacy),'Legacy bridge cannot drop its native retained workspaces');
foreach(['includes/Access/Guest_Engine_Service.php','includes/AI/AI_Provider_Service.php','includes/Access/SiteGraph_Workspace_Service.php']as$f)$legacy['files'][$f]=['sha256'=>str_repeat('a',64),'bytes'=>1];
check(count(Profile::validate($legacy,false))===2,'Original two-App bridge still describes its retained native workspaces');
echo json_encode(['passed'=>true,'checks'=>$checks,'nativePackageAuthentication'=>false,'scope'=>'Data-only Core upgrade/profile compatibility; native signature, exact bytes and execution remain separate gates']).PHP_EOL;
