<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows')exit(2);
define('ABSPATH',__DIR__.'/');
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture','Portable_Source_Compatibility','Portable_Source_Observation','Portable_Recovery_Set','Portable_Recovery_Stage','Portable_Table_Definition','Portable_Recovery_Migration','Portable_Recovery_Ownership','Portable_Recovery_Database','Portable_Recovery_Configuration','Portable_Recovery_Activation']as$c)require __DIR__.'/../../../wp-content/mu-plugins/dsa/includes/Backup/'.$c.'.php';
use DSA\Backup\Portable_Recovery_Activation as Activation;
try{
 $p=json_decode(fgets(STDIN,65537),true,32,JSON_THROW_ON_ERROR);$base=str_replace('\\','/',sys_get_temp_dir()).'/kiwe-owner-qa-';
 if(!is_array($p)||!preg_match('~^'.preg_quote($base,'~').'[a-f0-9]{24}/public$~D',$p['public'])||!in_array($p['action'],['activate','reverse','retire'],true))throw new RuntimeException();
 $seen=0;$hook=static function($label,$directory)use($p,&$seen){
  if($label!==$p['stop'])return;
  if(($p['partialMedia']??false)===true){$path=$directory.'/next/wp-content/uploads/sample.bin';clearstatcache(true,$path);$s=@lstat($path);if(!$s||$s['size']<1||$s['size']>=1048576)return;}
  if(++$seen!==($p['stopNth']??1))return;
  echo json_encode(['checkpoint'=>$label,'directory'=>$directory],JSON_UNESCAPED_SLASHES)."\n";fflush(STDOUT);
  // The parent terminates this direct proc_open child. No public flag/environment activates this hook.
  fgets(STDIN);throw new RuntimeException('Synthetic checkpoint resumed without parent termination');
 };
 if($p['action']==='activate')Activation::activate($p['connection'],$p['database'],$p['stage'],$p['public'],$p['phrase'],$p['parent'],$p['policy'],null,$hook);
 elseif($p['action']==='reverse')Activation::reverse($p['connection'],$p['directory'],$p['public'],$p['phrase'],null,$hook);
 else Activation::retire($p['directory'],$p['public'],$p['phrase'],null,$hook);
 echo json_encode(['unexpectedCompletion'=>true])."\n";exit(1);
}catch(Throwable$e){echo json_encode(['workerRefused'=>true])."\n";exit(1);}
