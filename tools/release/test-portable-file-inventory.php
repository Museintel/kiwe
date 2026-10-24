<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_File_Inventory.php';
use DSA\Backup\Portable_File_Inventory as Inventory;
$proof=['schema'=>'kiwe.portable-file-inventory-proof.v1','passed'=>false,'cleanup'=>false,'checks'=>[],'skips'=>[]];
$check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $fn,string $label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$root=sys_get_temp_dir().'/kiwe-portable-files-'.bin2hex(random_bytes(12));$owned=[];
try{
 mkdir($root,0700);$owned[]=$root;
 foreach(['wp-content','wp-content/cache','wp-content/cacheable','empty']as$dir){mkdir($root.'/'.$dir,0700);$owned[]=$root.'/'.$dir;}
 foreach(['wp-config.php'=>'synthetic private config','wp-content/asset.bin'=>"\0".chr(255).'binary','wp-content/cache/temporary'=>'excluded','wp-content/cacheable/keep.txt'=>'retained','.maintenance'=>'temporary']as$path=>$bytes){file_put_contents($root.'/'.$path,$bytes);$owned[]=$root.'/'.$path;}
 $plan=Inventory::scan($root);$paths=array_column($plan['files'],'path');
 $check($paths===['wp-config.php','wp-content/asset.bin','wp-content/cacheable/keep.txt'],'stable complete ordinary file inventory');
 $check($plan['directories']===['empty','wp-content','wp-content/cacheable'],'empty directories retained');
 $check(count($plan['exclusions'])===2&&$plan['exclusions'][0]['path']==='.maintenance','known temporary paths declared and excluded');
 $check($plan['bytes']===strlen('synthetic private config')+strlen("\0".chr(255).'binary')+strlen('retained'),'exact raw byte inventory');
 $check(!str_contains(json_encode($plan),str_replace('\\','/',$root))&&!str_contains(json_encode($plan),'synthetic private config'),'inventory omits absolute source roots and file content');
 $entry=$plan['files'][1];$check(hash_file('sha256',Inventory::unchanged($root,$entry))===$entry['sha256'],'reviewed binary file resolves only inside source');
 $mtime=filemtime($root.'/'.$entry['path']);file_put_contents($root.'/'.$entry['path'],"\0".chr(254).'binary');touch($root.'/'.$entry['path'],$mtime);
 $reject(static fn()=>Inventory::unchanged($root,$entry),'same-size same-time content change refused');
 $bad=$entry;$bad['path']='../escape';$reject(static fn()=>Inventory::unchanged($root,$bad),'review cannot escape source');
 $bad=$entry;$bad['extra']='injected';$reject(static fn()=>Inventory::unchanged($root,$bad),'unsupported review fields refused');
 $reject(static fn()=>Inventory::scan($root,1),'file-count overflow refused');$reject(static fn()=>Inventory::scan($root,50000,1),'byte-count overflow refused');
 $reject(static fn()=>Inventory::scan($root,0),'invalid count refused');$reject(static fn()=>Inventory::scan($root,Inventory::MAX_FILES+1),'unbounded count refused');$reject(static fn()=>Inventory::scan($root,10,Inventory::MAX_BYTES+1),'unbounded byte limit refused');
 $reject(static fn()=>Inventory::scan($root.'/missing'),'missing source refused');
 foreach(['','/absolute','../escape','a/../b','a//b','a\\b','C:/drive','a:stream','a/.','a/','nul.txt','COM1/file','a.','a ','bad'.chr(0),'bad'.chr(255),'a?b','a|b','<a>','a"b']as$i=>$path)$reject(static fn()=>Inventory::relative($path),'unsafe portable path '.$i.' refused');
 $calls=0;try{Inventory::scan($root,50000,Inventory::MAX_BYTES,static function()use(&$calls){if(++$calls===3)throw new RuntimeException('budget');});throw new RuntimeException('Budget ignored');}catch(RuntimeException $e){$check($e->getMessage()==='budget'&&$calls===3,'capture budget aborts bounded traversal');}
 $large=$root.'/large.bin';file_put_contents($large,str_repeat('x',2097152));$owned[]=$large;$largeEntry=array_values(array_filter(Inventory::scan($root)['files'],static fn($v)=>$v['path']==='large.bin'))[0];
 $readCalls=0;try{Inventory::unchanged($root,$largeEntry,static function()use(&$readCalls){if(++$readCalls===2)throw new RuntimeException('read budget');});throw new RuntimeException('File read budget ignored');}catch(RuntimeException $e){$check($e->getMessage()==='read budget'&&$readCalls===2,'budget interrupts a large file hash between read chunks');}
 $reject(static fn()=>Inventory::unchanged($root,$largeEntry,static function()use($large){file_put_contents($large,'grown',FILE_APPEND);}),'file growth during an active hash refused');
 foreach(['wp-content/debug.log','wp-content/uploads.zip']as$path){file_put_contents($root.'/'.$path,str_repeat('x',2097152));$owned[]=$root.'/'.$path;}
 unlink($large);$owned=array_values(array_diff($owned,[$large]));
 $withoutDiagnostics=Inventory::scan($root,50000,1024);
 $check(!in_array('wp-content/debug.log',array_column($withoutDiagnostics['files'],'path'),true)&&!in_array('wp-content/uploads.zip',array_column($withoutDiagnostics['files'],'path'),true),'named diagnostics and duplicate upload archive cannot exhaust website capture capacity');
 $check(in_array('wp-content/debug.log',array_column($withoutDiagnostics['exclusions'],'path'),true)&&in_array('wp-content/uploads.zip',array_column($withoutDiagnostics['exclusions'],'path'),true),'omitted diagnostics and duplicate archive remain explicit in recovery inventory');
 foreach(['wp-content/uploads','wp-content/uploads/rank-math','wp-content/compressx','wp-content/compressx/log','wp-content/compressx-nextgen']as$dir){mkdir($root.'/'.$dir,0700);$owned[]=$root.'/'.$dir;}
 $operational=['wp-content/uploads/rank-math/rank_math_'.md5('synthetic sitemap').'.xml'=>'synthetic generated XML','wp-content/compressx/log/compressx_20261006_log.txt'=>'synthetic diagnostic','wp-content/compressx-nextgen/preserved.avif'=>'synthetic public AVIF','wp-content/uploads/preserved.jpeg'=>'synthetic original JPEG'];
 foreach($operational as$path=>$bytes){file_put_contents($root.'/'.$path,$bytes);$owned[]=$root.'/'.$path;}
 $withOperational=Inventory::scan($root,50000,1024);$included=array_column($withOperational['files'],'path');$excluded=array_column($withOperational['exclusions'],'path');
 $check(in_array('wp-content/uploads/rank-math',$excluded,true)&&in_array('wp-content/compressx/log',$excluded,true),'native sitemap cache and diagnostic directories remain explicit exclusions');
 $check(in_array('wp-content/compressx-nextgen/preserved.avif',$included,true)&&in_array('wp-content/uploads/preserved.jpeg',$included,true),'CompressX public derivatives and original uploaded media remain recoverable');
 foreach(array_slice(array_keys($operational),0,2)as$path)file_put_contents($root.'/'.$path,str_repeat('x',2097152));
 Inventory::unchanged_tree($root,$withOperational);$check(true,'native generated sitemap and diagnostic churn does not invalidate the recoverable file tree');
 foreach(['wp-content/uploads/wc-logs','wp-content/uploads/wc-logs-public']as$dir){mkdir($root.'/'.$dir,0700);$owned[]=$root.'/'.$dir;}
 foreach(['wp-content/uploads/wc-logs/gateway.log'=>'synthetic payment diagnostic','wp-content/uploads/wc-logs-public/report.txt'=>'synthetic public report']as$path=>$bytes){file_put_contents($root.'/'.$path,$bytes);$owned[]=$root.'/'.$path;}
 $withLogs=Inventory::scan($root,50000,1024);$check(in_array('wp-content/uploads/wc-logs',array_column($withLogs['exclusions'],'path'),true)&&!in_array('wp-content/uploads/wc-logs/gateway.log',array_column($withLogs['files'],'path'),true),'Woo diagnostic logs are explicit exclusions from consistent recovery');
 file_put_contents($root.'/wp-content/uploads/wc-logs/gateway.log','synthetic live append',FILE_APPEND);Inventory::unchanged_tree($root,$withLogs);$check(true,'live gateway log append cannot invalidate the consistent website file member');
 $check(in_array('wp-content/uploads/wc-logs-public/report.txt',array_column($withLogs['files'],'path'),true)&&in_array('wp-content/uploads/preserved.jpeg',array_column($withLogs['files'],'path'),true),'adjacent public reports and original media remain captured');
 file_put_contents($root.'/wp-content/uploads/preserved.jpeg','changed original media',FILE_APPEND);$reject(static fn()=>Inventory::unchanged_tree($root,$withLogs),'real uploaded media drift still refuses despite excluded gateway logs');
 $originalBytes=file_get_contents($root.'/wp-content/uploads/wc-logs/gateway.log');$exhaustive=Inventory::scan($root,50000,Inventory::MAX_BYTES,null,null,true);$check(in_array('wp-content/uploads/wc-logs/gateway.log',array_column($exhaustive['files'],'path'),true)&&file_get_contents($root.'/wp-content/uploads/wc-logs/gateway.log')===$originalBytes,'explicit exhaustive diagnostic audit remains available without modifying logs');
 Inventory::unchanged_tree($root,$exhaustive);$check(true,'unchanged exhaustive file review retains its included diagnostics');
 file_put_contents($root.'/wp-content/uploads/wc-logs/gateway.log','new included log bytes',FILE_APPEND);$reject(static fn()=>Inventory::unchanged_tree($root,$exhaustive),'included diagnostic drift still refuses exhaustive capture');file_put_contents($root.'/wp-content/uploads/wc-logs/gateway.log',$originalBytes);
 $reject(static fn()=>Inventory::scan($root,50000,1024,null,null,true),'explicit exhaustive audit retains its ordinary file capacity refusal');
 $clean=$root.'/clean';mkdir($clean,0700);$owned[]=$clean;file_put_contents($clean.'/article.txt','reviewed public file');$owned[]=$clean.'/article.txt';$cleanPlan=Inventory::scan($clean);Inventory::unchanged_tree($clean,$cleanPlan);$check(true,'review without exclusions still verifies unchanged ordinary files');file_put_contents($clean.'/.maintenance','new excluded path');$owned[]=$clean.'/.maintenance';$reject(static fn()=>Inventory::unchanged_tree($clean,$cleanPlan),'new excluded path cannot silently expand a review that declared no exclusions');
 $catalog=['files'=>[],'directories'=>['assets'],'exclusions'=>[],'bytes'=>0,'namePolicy'=>'portable'];
 for($i=0;$i<60000;$i++)$catalog['files'][]=['path'=>'assets/file-'.str_pad((string)$i,6,'0',STR_PAD_LEFT).'.txt','bytes'=>0,'sha256'=>hash('sha256','')];
 $check(Inventory::validate_plan($catalog)==='portable','reviewed inventory beyond former file and metadata ceilings remains bounded and valid');unset($catalog);
 $link=$root.'/linked';if(function_exists('symlink')&&@symlink($root.'/empty',$link)){$owned[]=$link;$reject(static fn()=>Inventory::scan($root),'linked dependency refused');}else $proof['skips'][]='Runtime cannot create the optional symlink fixture; an actual linked-path native proof is still required';
 $proof['passed']=true;
}catch(Throwable $e){$proof['error']=$e->getMessage();}
foreach(array_reverse($owned)as$path){if(is_link($path)||is_file($path))unlink($path);elseif(is_dir($path))rmdir($path);}
$proof['cleanup']=!file_exists($root);$proof['passed']=$proof['passed']&&$proof['cleanup'];
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
