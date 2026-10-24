<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==2)exit(2);
define('ABSPATH',__DIR__.'/');$base=__DIR__.'/../../../wp-content/mu-plugins/dsa/includes/Backup/';
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container']as$n)require$base.$n.'.php';
$phrase=rtrim((string)fgets(STDIN,1026),"\r\n");$s=null;$p=['schema'=>'kiwe.independent-previous-pair-proof.v1','passed'=>false,'checks'=>[]];
try{
 $root=realpath($argv[1]);if(!$root||is_link($argv[1]))throw new RuntimeException('Ordinary private directory required');
 $s=fopen($root.'/previous-kiwe.kiwefiles','rb');$r=DSA\Backup\Portable_Archive_Container::verify($s,$phrase);fclose($s);$s=null;
 $expected=json_decode(file_get_contents($root.'/previous-kiwe-manifest.json'),true,512,JSON_THROW_ON_ERROR);$actual=[];
 foreach($r['inventory']['files']as$f)$actual[$f['path']]=['bytes'=>$f['bytes'],'sha256'=>$f['sha256']];
 ksort($actual);ksort($expected);$p['checks']['saved previous code authenticates']=true;$p['checks']['all previous code members match independent manifest']=$expected===$actual;
 $p['checks']['complete previous loader nested and guard present']=isset($actual['dsa.php'],$actual['dsa/dsa.php'],$actual['kiwe-incident-guard.php']);$p['members']=count($actual);$p['archiveSha256']=hash_file('sha256',$root.'/previous-kiwe.kiwefiles');$p['passed']=!in_array(false,$p['checks'],true);
}catch(Throwable$e){$p['error']='Saved previous code authentication refused';}finally{if(is_resource($s))fclose($s);if(function_exists('sodium_memzero'))sodium_memzero($phrase);}
echo json_encode($p);exit($p['passed']?0:1);
