<?php
declare(strict_types=1);
/** CLI operator: authenticate retained original code; retire only explicitly pinned temporary custody. */
if (PHP_SAPI !== 'cli') return null;
return static function(array $c): array {
    $p=['schema'=>'kiwe.private-previous-code-custody-audit.v1','passed'=>false,'sourceWrites'=>0,'wordpressBooted'=>false,'databaseConnected'=>false,'productionUpgradeExecuted'=>false,'checks'=>[]];
    $leases=[];$stream=null;$phrase=null;$started=hrtime(true);
    $check=static function(bool $ok,string $name)use(&$p):void{$p['checks'][$name]=$ok;if(!$ok)throw new RuntimeException($name);};
    $budget=static function()use($started):void{if(hrtime(true)-$started>120000000000)throw new RuntimeException('Bounded custody review exceeded its budget');};
    $ordinary=static function(string $path,bool $directory,int $mode)use($check):void{
        clearstatcache(true,$path);$s=lstat($path);
        $check($s!==false&&!is_link($path)&&realpath($path)===$path&&($s['mode']&0170000)===($directory?0040000:0100000)&&($s['mode']&0777)===$mode&&$s['uid']===posix_geteuid()&&($directory||$s['nlink']===1),'ordinary owner-only custody '.$path);
    };
    $entries=static function(string $path):array{$a=array_values(array_diff(scandir($path),['.','..']));sort($a,SORT_STRING);return $a;};
    $inventory=static function(array $plan):array{$a=[];foreach($plan['files']as$r)$a[$r['path']]=['bytes'=>$r['bytes'],'sha256'=>$r['sha256']];ksort($a,SORT_STRING);return $a;};
    try {
        $pattern='#^/home/(u[0-9]+)/domains/([a-z0-9.-]+)/public_html$#D';
        $check(preg_match($pattern,$c['sourceRoot'],$s)===1&&preg_match($pattern,$c['executionRoot'],$x)===1&&$s[1]===$x[1]&&$s[2]!==$x[2],'distinct exact source and authorized operator roots in one account');
        $check(realpath($c['sourceRoot'])===$c['sourceRoot']&&realpath($c['executionRoot'])===$c['executionRoot']&&!is_link($c['sourceRoot'])&&!is_link($c['executionRoot']),'both reviewed website roots are ordinary');
        $parent=dirname($c['executionRoot']);
        $check($c['retainedDirectory']===$parent.'/kiwe-private-woo-previous-rc306-f'&&$c['failedDirectory']===$parent.'/kiwe-private-woo-previous-rc306-e','only exact turn-owned private namespaces can be changed');
        $check($c['localKeyCustodyPassed']===true&&preg_match('/^[a-f0-9]{64}$/D',$c['localRecoveryPhraseSha256'])===1,'independent local DPAPI key recovery has passed');
        $mu=$c['sourceRoot'].'/wp-content/mu-plugins';$original=DSA\Backup\Portable_File_Inventory::scan($mu,2000,16777216,$budget,'posix');
        $configSha=hash_file('sha256',$c['sourceRoot'].'/wp-config.php');
        $retained=$c['retainedDirectory'];$failed=$c['failedDirectory'];
        foreach([$retained,$failed]as$dir){$ordinary($dir,true,0700);$ordinary($dir.'/lease.lock',false,0600);$lease=fopen($dir.'/lease.lock','r+b');$check(is_resource($lease)&&flock($lease,LOCK_EX|LOCK_NB),'inactive exact custody lease '.$dir);$leases[]=$lease;}
        $top=['lease.lock','receipt.json','previous-kiwe-pair','previous-kiwe-manifest.json','previous-kiwe.kiwefiles','recovery-phrase.private','operator-key.sealed','crypto-native.stdout.private','crypto-native.stderr.private','crypto-pure.stdout.private','crypto-pure.stderr.private'];sort($top,SORT_STRING);
        $check($entries($retained)===$top&&$entries($failed)===['lease.lock','receipt.json'],'no unknown private custody member can be removed');
        foreach($top as$n)$ordinary($retained.'/'.$n,$n==='previous-kiwe-pair',$n==='previous-kiwe-pair'?0700:0600);
        $ordinary($failed.'/receipt.json',false,0600);
        $check(hash_file('sha256',$retained.'/receipt.json')===$c['retainedReceiptSha256']&&hash_file('sha256',$failed.'/receipt.json')===$c['failedReceiptSha256'],'both complete native receipts are exactly pinned');
        $f=json_decode(file_get_contents($retained.'/receipt.json'),true,512,JSON_THROW_ON_ERROR);$e=json_decode(file_get_contents($failed.'/receipt.json'),true,512,JSON_THROW_ON_ERROR);
        $check(($f['passed']??null)===true&&($e['passed']??null)===false&&$f['privateDirectory']===$retained&&$e['privateDirectory']===$failed,'successful custody and genuine failed attempt stay distinct');
        $check($f['sourceVersion']==='8.0.0-rc.10'&&$f['candidateVersion']==='8.0.0-rc.306'&&$f['previousCode']['previousCodeMembers']===349&&count($f['previousCode']['installedManifestDrift'])===18,'original installed layout and declared-manifest differences remain explicit');
        $check($configSha===$f['previousCode']['originalConfigurationSha256'],'original WordPress configuration remains pinned');
        foreach($f['previousCode']['privateMembers']as$n=>$pin)$check(in_array($n,['previous-kiwe.kiwefiles','previous-kiwe-manifest.json','operator-key.sealed'],true)&&filesize($retained.'/'.$n)===$pin['bytes']&&hash_file('sha256',$retained.'/'.$n)===$pin['sha256'],'retained ciphertext custody pin '.$n);
        foreach(['native','pure']as$mode){$sig=$f['signature'][$mode];$check($sig['passed']===true&&$sig['nativeExitCode']===0&&$sig['stderrBytes']===0&&$sig['suppressedOutputBytes']===0&&$sig['webFpmExecutionQualified']===false,'source CLI signature receipt remains scoped '.$mode);foreach($sig['sourceFiles']as$n=>$sha)$check(in_array($n,['compat.php','http.php','sodium_compat/autoload.php'],true)&&hash_file('sha256',$c['sourceRoot'].'/wp-includes/'.$n)===$sha,'source signature dependency still pinned '.$mode.'/'.$n);}
        $pair=json_decode(file_get_contents($retained.'/previous-kiwe-manifest.json'),true,512,JSON_THROW_ON_ERROR);ksort($pair,SORT_STRING);
        $plan=DSA\Backup\Portable_File_Inventory::scan($retained.'/previous-kiwe-pair',2000,16777216,$budget,'posix');
        foreach($plan['directories']as$n)$ordinary($retained.'/previous-kiwe-pair/'.$n,true,0700);
        foreach($plan['files']as$r)$ordinary($retained.'/previous-kiwe-pair/'.$r['path'],false,0600);
        $check(count($pair)===349&&$inventory($plan)===$pair,'all 349 retained ordinary original members match their independent manifest');
        $live=[];foreach($original['files']as$r)if(in_array($r['path'],['dsa.php','kiwe-incident-guard.php'],true)||str_starts_with($r['path'],'dsa/'))$live[$r['path']]=['bytes'=>$r['bytes'],'sha256'=>$r['sha256']];ksort($live,SORT_STRING);
        $check($live===$pair,'all 349 current production members still exactly match preserved original bytes');
        $phrase=file_get_contents($retained.'/recovery-phrase.private');$check(preg_match('/^[a-f0-9]{64}$/D',$phrase)===1&&hash('sha256',$phrase)===$c['localRecoveryPhraseSha256'],'local recovered key matches the retained private server key');
        $stream=fopen($retained.'/previous-kiwe.kiwefiles','rb');$check(is_resource($stream),'independent ciphertext stream opens');$verified=DSA\Backup\Portable_Archive_Container::verify($stream,$phrase,$budget);fclose($stream);$stream=null;
        $check($inventory($verified['inventory'])===$pair,'independent encrypted readback authenticates every preserved original member');
        foreach($c['helpers']as$path=>$sha)$check(dirname($path)===$c['executionRoot']&&in_array(basename($path),['kiwe-woo-previous-rc306-e.php','kiwe-woo-previous-rc306-f.php'],true)&&!is_link($path)&&realpath($path)===$path&&hash_file('sha256',$path)===$sha,'exact owned helper before retirement '.$path);
        // All source/custody/ownership checks precede the first mutation. No recursive delete is used.
        $check(unlink($retained.'/recovery-phrase.private'),'server plaintext recovery key retired after independent local custody');$p['serverPlaintextRecoveryKeyRemoved']=true;
        $check(hash_file('sha256',$failed.'/receipt.json')===$c['failedReceiptSha256']&&unlink($failed.'/receipt.json')&&unlink($failed.'/lease.lock')&&rmdir($failed),'only the failed empty custody namespace is retired');
        foreach($c['helpers']as$path=>$sha)$check(hash_file('sha256',$path)===$sha&&unlink($path),'exact owned helper retired '.$path);
        $top=array_values(array_diff($top,['recovery-phrase.private']));$check($entries($retained)===$top,'successful encrypted and original-byte custody intentionally remains');
        $check(DSA\Backup\Portable_File_Inventory::scan($mu,2000,16777216,$budget,'posix')===$original&&hash_file('sha256',$c['sourceRoot'].'/wp-config.php')===$configSha,'complete source MU inventory and configuration remain exact after retirement');
        $p['previousCodeMembers']=349;$p['preservedManifestDifferences']=18;$p['ciphertextSha256']=$f['previousCode']['privateMembers']['previous-kiwe.kiwefiles']['sha256'];$p['retainedPrivateDirectory']=$retained;$p['retainedTopMembers']=$top;$p['failedNamespaceRemoved']=true;$p['retiredHelpers']=array_keys($c['helpers']);$p['fullSiteBackup']=false;$p['kiweSettingsCaptured']=false;$p['offlineCopyCompleted']=false;$p['restoreExecuted']=false;$p['webFpmExecutionQualified']=false;$p['passed']=true;
    } catch(Throwable $e) {$p['errorClass']=get_class($e);$p['error']='Pinned original-code custody audit or temporary retirement did not finish';}
    finally {if(is_resource($stream))fclose($stream);foreach($leases as$l)if(is_resource($l)){flock($l,LOCK_UN);fclose($l);}if(is_string($phrase)&&function_exists('sodium_memzero'))sodium_memzero($phrase);$phrase=null;}
    $p['milliseconds']=(hrtime(true)-$started)/1000000;return $p;
};
