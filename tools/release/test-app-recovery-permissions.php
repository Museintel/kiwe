<?php
declare(strict_types=1);
namespace DSA\Apps {final class Native_State { public static int $calls=0;public static function filesystem():void{self::$calls++;} }}
namespace {
 define('ABSPATH',__DIR__.'/fixture/');define('FS_CHMOD_DIR',0755);define('FS_CHMOD_FILE',0644);function wp_normalize_path($v){return str_replace('\\','/',$v);}
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Apps/Runtime_Contract.php';require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Apps/Package_Verifier.php';require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Apps/Code_Recovery.php';
 $base=rtrim((string)realpath(sys_get_temp_dir()),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'kiwe-permission-fixture-'.bin2hex(random_bytes(8));if(!mkdir($base,0700))throw new RuntimeException('Owned fixture directory required');$resolved=(string)realpath($base);$root=$base.'/kiwe-feedback';$private=$base.'/private';$checks=[];$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
 try{
  mkdir($root.'/assets/js/modules',0700,true);mkdir($private,0700);file_put_contents($root.'/assets/js/modules/comments-panel.js','export const fixture = true;');file_put_contents($private.'/secret','private fixture');chmod($root.'/assets/js/modules/comments-panel.js',0600);chmod($private.'/secret',0600);
  $fingerprint=new ReflectionMethod(\DSA\Apps\Code_Recovery::class,'fingerprint');$permissions=new ReflectionMethod(\DSA\Apps\Code_Recovery::class,'public_permissions');$expected=$fingerprint->invoke(null,$root);$changed=$expected;$changed['assets/js/modules/comments-panel.js']['sha256']=str_repeat('0',64);$refused=false;try{$permissions->invoke(null,$root,$changed);}catch(RuntimeException){$refused=true;}$check($refused&&\DSA\Apps\Native_State::$calls===0,'Changed code is refused before native permission work');
  $permissions->invoke(null,$root,$expected);$check($fingerprint->invoke(null,$root)===$expected,'Restoring modes preserves every original code byte');$check(\DSA\Apps\Native_State::$calls===1,'Native WordPress filesystem authority owns mode restoration');
  if(PHP_OS_FAMILY!=='Windows'){
   foreach([$root,$root.'/assets',$root.'/assets/js',$root.'/assets/js/modules']as$p){clearstatcache(true,$p);$check((fileperms($p)&0777)===0755,'Restored public directory is traversable');}clearstatcache(true,$root.'/assets/js/modules/comments-panel.js');$check((fileperms($root.'/assets/js/modules/comments-panel.js')&0777)===0644,'Restored static asset is publicly readable');clearstatcache(true,$private.'/secret');$check((fileperms($private.'/secret')&0777)===0600,'Private sibling permissions remain private');
  }
  echo json_encode(['passed'=>true,'checks'=>count($checks),'posixModeChecks'=>PHP_OS_FAMILY!=='Windows','scope'=>'Actual recovery permission method and byte-refusal boundary; POSIX modes need the Linux lab HTTP gate.']);
 }finally{
  if((string)realpath($base)!==$resolved||!str_starts_with($resolved,rtrim((string)realpath(sys_get_temp_dir()),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'kiwe-permission-fixture-'))throw new RuntimeException('Owned fixture cleanup path changed');foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f){if($f->isLink())unlink($f->getPathname());elseif($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);
 }
}
