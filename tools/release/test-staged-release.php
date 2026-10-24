<?php
declare(strict_types=1);
// Verifies historical signed public metadata and a disposable copy of today's package.
// Does not sign a release, read a private key, download, install or contact WordPress.
define('ABSPATH',__DIR__.'/');
function wp_parse_url($v){return parse_url($v);}function trailingslashit($v){return rtrim($v,'/\\').'/';}
$root=dirname(__DIR__,2);$stage=$argv[1]??'';
if(!is_dir($stage)||str_starts_with(str_replace('\\','/',realpath($stage)).'/',str_replace('\\','/',realpath($root)).'/'))throw new RuntimeException('External copied package required.');
require $root.'/wp-content/mu-plugins/dsa/includes/Runtime/Update_Service.php';
$service=new DSA\Runtime\Update_Service();$verify=new ReflectionMethod($service,'verify_release');$package=new ReflectionMethod($service,'verify_package_root');$checks=[];
function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][]=$label;}
$signed=json_decode(file_get_contents($root.'/public/app.kiwelaunch.com/updates/kiwe/v1/releases/8.0.0-rc.55/manifest.json'),true);
check($verify->invoke($service,$signed,'candidate'),'Existing public release signature verifies with pinned key');
foreach(['version'=>'9.9.9','channel'=>'stable','sha256'=>str_repeat('f',64),'packageUrl'=>'https://attacker.test/kiwe.zip','signature'=>base64_encode(str_repeat('x',64))]as$key=>$value){
	$bad=array_replace($signed,[$key=>$value]);check(!$verify->invoke($service,$bad,'candidate'),'Tampered signed '.$key.' is rejected');
}
$manifest=$stage.'/dsa/package-manifest.json';$value=json_decode(file_get_contents($manifest),true);$release=['version'=>$value['version'],'packageManifestSha256'=>hash_file('sha256',$manifest)];
$package->invoke($service,$stage,$release);check(true,'Current copied package passes native staged verification');
$asset=$stage.'/dsa/assets/js/guest-app.js';$body=file_get_contents($asset);
try{
	file_put_contents($asset,$body."\ninvalid fixture change");$failed=false;
	try{$package->invoke($service,$stage,$release);}catch(RuntimeException $e){$failed=true;}
	check($failed,'Tampered staged runtime file is rejected');
}finally{file_put_contents($asset,$body);}
$loader=$stage.'/dsa.php';$body=file_get_contents($loader);
try{
	file_put_contents($loader,str_replace($value['version'],'0.0.0-fixture',$body));$failed=false;
	try{$package->invoke($service,$stage,$release);}catch(RuntimeException $e){$failed=true;}
	check($failed,'Mismatched loader is rejected');
}finally{file_put_contents($loader,$body);}
$file=$stage.'/dsa/assets/js/guest-app.js';$body=file_get_contents($file);
try{
	unlink($file);$failed=false;try{$package->invoke($service,$stage,$release);}catch(RuntimeException $e){$failed=true;}
	check($failed,'Missing staged runtime file is rejected');
}finally{file_put_contents($file,$body);}
$package->invoke($service,$stage,$release);check(true,'Exact restored stage verifies again');
echo json_encode(['passed'=>true,'checks'=>$checks,'currentPackageSigned'=>false,'historicalSignatureOnly'=>true])."\n";
