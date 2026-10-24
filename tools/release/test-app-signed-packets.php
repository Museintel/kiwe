<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
$source=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/';
foreach(['Runtime_Contract','Package_Layout','Signed_Packet','Install_Plan','Package_Verifier']as$name)require$source.$name.'.php';
use DSA\Apps\Signed_Packet as Packet;
use DSA\Apps\Install_Plan as Plan;
use DSA\Apps\Package_Verifier as Verifier;
$wp_version='7.1.3';$checks=[];
function check(bool $ok,string $label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}
function refuses(callable $fn,string $label){try{$fn();}catch(Throwable){check(true,$label);return;}throw new RuntimeException($label);}
$catalogue=json_decode(file_get_contents(__DIR__.'/fixtures/apps-signed-candidate-v1.json'),true,32,JSON_THROW_ON_ERROR);
$now=strtotime($catalogue['publishedAt'])+60;
check(Packet::catalogue($catalogue,$now),'Original Ed25519 signed candidate authenticates');
check(Packet::canonical(['z'=>1,'a'=>['y'=>2,'b'=>1]])==='{"a":{"b":1,"y":2},"z":1}','Canonical ordering matches the independent signer');
foreach(['https://other.invalid/apps/v1/x.zip','http://app.kiwelaunch.com/apps/v1/x.zip','https://app.kiwelaunch.com:443/apps/v1/x.zip','https://user@app.kiwelaunch.com/apps/v1/x.zip','https://app.kiwelaunch.com/apps/v1/%2e%2e/x.zip','https://app.kiwelaunch.com/apps/v1/../x.zip','https://app.kiwelaunch.com/apps/v1//x.zip','https://app.kiwelaunch.com/apps/v1/x.zip?token=x','https://app.kiwelaunch.com/updates/kiwe/v1/x.zip']as$i=>$url)check(!Packet::url($url),'Foreign or ambiguous distribution URL denied '.$i);
foreach(['signature'=>'A'.substr($catalogue['signature'],1),'channel'=>'stable','coreApi'=>2,'extra'=>true,'expiresAt'=>'2026-02-30T00:00:00Z']as$key=>$value){$bad=$catalogue;$bad[$key]=$value;check(!Packet::catalogue($bad,$now),'Altered catalogue denied '.$key);}
check(!Packet::catalogue($catalogue,strtotime($catalogue['expiresAt'])),'Expired catalogue cannot authorize installation');
check(!Packet::catalogue($catalogue,strtotime($catalogue['publishedAt'])-301),'Future catalogue denied');
$release=$catalogue['apps']['store'];check(Packet::release($release),'Store has its own independent signed release');
foreach(['id'=>'seo','bytes'=>0,'bytes2'=>1,'requiresWoo'=>'','coreApi'=>2,'packageUrl'=>'https://app.kiwelaunch.com/apps/v1/releases/seo/1.0.0/kiwe-seo.zip','sha256'=>str_repeat('a',64)]as$key=>$value){$bad=$release;$bad[$key]=$value;check(!Packet::release($bad),'Altered release denied '.$key);}
$native=['apps'=>[],'woo'=>['installed'=>false,'active'=>false,'version'=>''],'seoOwner'=>''];
$previous=json_decode(file_get_contents(__DIR__.'/fixtures/apps-signed-previous-v1.json'),true,32,JSON_THROW_ON_ERROR);
refuses(fn()=>Plan::create('store',$previous['apps']['store'],$native,false),'Authentic older App below the physical Core floor is refused');
$plan=Plan::create('store',$release,$native,false);
check($plan['steps']===['install_woo','activate_woo','download','verify','place','activate','confirm'],'Official Woo dependency ordered before App activation');
check($plan['automatic']===false,'Update preference is explicit');
$native['woo']=['installed'=>true,'active'=>true,'version'=>'11.2.1'];
check(Plan::create('store',$release,$native,true)['steps']===['download','verify','place','activate','confirm'],'Compatible native Woo reused without upgrade');
$native['woo']['version']='1.0';refuses(fn()=>Plan::create('store',$release,$native,false),'Existing incompatible Woo requires reviewed update');
$native['seoOwner']='rank_math';refuses(fn()=>Plan::create('seo',$catalogue['apps']['seo'],$native,false),'External SEO owner never silently replaced');
$native['seoOwner']='';$native['apps']['seo']=['version'=>'9.0.0','active'=>true];refuses(fn()=>Plan::create('seo',$catalogue['apps']['seo'],$native,false),'Newer native App cannot be downgraded by catalogue');
if(isset($argv[1])){
 $root=rtrim(str_replace('\\','/',$argv[1]),'/');
 $current=json_decode(file_get_contents($root.'/channels/candidate.json'),true,32,JSON_THROW_ON_ERROR);
 foreach($current['apps']as$id=>$r){$base=$root.'/releases/'.$id.'/'.$r['version'];$files=Verifier::archive($base.'/kiwe-'.$id.'.zip',$r);check(count($files['files'])>2,'Every signed archive member verified '.$id);Verifier::directory($base.'/source/kiwe-'.$id,$r);check(true,'Installed readback matches authenticated bytes '.$id);}
}
echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>array_keys($checks),'privateKeysInFixture'=>false],JSON_UNESCAPED_SLASHES)."\n";
