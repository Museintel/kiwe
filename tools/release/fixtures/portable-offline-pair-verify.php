<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==3)exit(2);
define('ABSPATH',__DIR__.'/');
$base=__DIR__.'/../../../wp-content/mu-plugins/dsa/includes/Backup/';
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture','Portable_Source_Compatibility','Portable_Source_Observation','Portable_Recovery_Set']as$name)require$base.$name.'.php';
$phrase=rtrim((string)fgets(STDIN,1026),"\r\n");$s=null;$proof=['schema'=>'kiwe.independent-offline-pair-proof.v1','passed'=>false,'checks'=>[]];
try{
 $s=fopen($argv[1],'rb');$r=DSA\Backup\Portable_Recovery_Set::verify($s,$phrase);fclose($s);$s=null;
 $o=DSA\Backup\Portable_Source_Observation::open(file_get_contents($argv[2]),$phrase);
 $sha=hash_file('sha256',$argv[1]);
 $proof['checks']['complete saved encrypted archive authenticates']=true;
 $proof['checks']['saved observation belongs to this exact archive']=$o['recoverySha256']===$sha&&$o['recoveryArchiveId']===$r['archiveId'];
 $proof['checks']['captured files were observed stable']=$o['observedStable']===true;
 $proof['passed']=!in_array(false,$proof['checks'],true);$proof['archiveBytes']=filesize($argv[1]);$proof['archiveSha256']=$sha;$proof['observationSha256']=hash_file('sha256',$argv[2]);
}catch(Throwable$e){$proof['error']='Saved pair authentication refused';}finally{if(is_resource($s))fclose($s);if(function_exists('sodium_memzero'))sodium_memzero($phrase);}
echo json_encode($proof,JSON_UNESCAPED_SLASHES);exit($proof['passed']?0:1);
