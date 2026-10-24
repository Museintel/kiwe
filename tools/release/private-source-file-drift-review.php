<?php
declare(strict_types=1);
/** Bounded metadata diagnostic after a refused source member capture; filenames remain private. */
if(PHP_SAPI!=='cli')return null;
return static function(array $c):array {
    $p=['schema'=>'kiwe.private-source-drift-diagnostic.v1','passed'=>false,'sourceWrites'=>0,'wordpressBooted'=>false,'databaseConnected'=>false];$lease=null;$started=hrtime(true);
    $budget=static function()use($started):void{if(hrtime(true)-$started>300000000000)throw new RuntimeException('Bounded source drift review');};
    try {
        if(realpath($c['private'])!==$c['private']||is_link($c['private'])||hash_file('sha256',$c['private'].'/receipt.json')!==$c['receiptSha256'])throw new RuntimeException('Exact failed capture required');
        $lease=fopen($c['private'].'/lease.lock','r+b');if(!$lease||!flock($lease,LOCK_EX|LOCK_NB))throw new RuntimeException('Failed capture must be idle');
        $plan=json_decode(file_get_contents($c['private'].'/source-inventory.private.json'),true,512,JSON_THROW_ON_ERROR);$old=array_column($plan['files'],null,'path');$excluded=array_fill_keys(array_column($plan['exclusions'],'path'),true);$stack=[''];$new=[];$changes=[];$directories=0;
        while($stack){$budget();$parent=array_pop($stack);if(++$directories>20000)throw new RuntimeException('Directory bound');foreach(new FilesystemIterator($c['root'].($parent===''?'':'/'.$parent),FilesystemIterator::SKIP_DOTS)as$e){$budget();$rel=($parent===''?'':$parent.'/').$e->getFilename();if(isset($excluded[$rel]))continue;if($e->isLink())throw new RuntimeException('Linked source path');if($e->isDir()){$stack[]=$rel;continue;}if(!$e->isFile())throw new RuntimeException('Ordinary source files required');if(count($new)>=150000)throw new RuntimeException('File bound');$new[$rel]=true;if(!isset($old[$rel]))$changes[]=['kind'=>'added','path'=>$rel,'bytes'=>$e->getSize()];elseif($e->getSize()!==$old[$rel]['bytes'])$changes[]=['kind'=>'size-changed','path'=>$rel,'before'=>$old[$rel]['bytes'],'after'=>$e->getSize()];}}
        foreach($old as$rel=>$e)if(!isset($new[$rel]))$changes[]=['kind'=>'removed','path'=>$rel];
        $details=json_encode($changes,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($c['private'].'/drift-details.private.json',$details,LOCK_EX)!==strlen($details)||!chmod($c['private'].'/drift-details.private.json',0600))throw new RuntimeException('Private drift storage required');
        $groups=[];foreach($changes as$change){$parts=explode('/',$change['path']);$bucket=count($parts)>2?implode('/',array_slice($parts,0,2)):(count($parts)>1?$parts[0]:'root');$key=$change['kind'].':'.$bucket;$groups[$key]=($groups[$key]??0)+1;}
        $p['initialFiles']=count($old);$p['currentFiles']=count($new);$p['metadataChangeCount']=count($changes);$p['changeGroups']=$groups;$p['privateDetailsSha256']=hash('sha256',$details);$p['capturedMembers']=[];
        foreach(scandir($c['private'])as$n){$budget();if($n==='.'||$n==='..')continue;$path=$c['private'].'/'.$n;if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600)throw new RuntimeException('Private failed capture member required');$p['capturedMembers'][$n]=['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];}
        $p['operatorKeySealed']=file_get_contents($c['private'].'/operator-key.sealed');$p['passed']=true;
    }catch(Throwable $e){$p['errorClass']=get_class($e);$p['error']='Bounded source drift review did not finish';}
    finally{if(is_resource($lease)){flock($lease,LOCK_UN);fclose($lease);}}
    $p['milliseconds']=(hrtime(true)-$started)/1000000;return $p;
};
