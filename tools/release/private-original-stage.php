<?php
declare(strict_types=1);
/** Operator-only qualification of a complete original-site recovery. No WordPress or SQL execution. */
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows') return null;
return static function(array $c): array {
    $proof = ['schema'=>'kiwe.private-original-stage-proof.v1','passed'=>false,'cleanup'=>false,'wordpressBooted'=>false,'databaseConnected'=>false,'sourceWrites'=>0,'activated'=>false,'fullSiteReady'=>false,'checks'=>[]];
    $check = static function(bool $ok, string $label) use (&$proof): void {
        $proof['checks'][$label] = $ok;
        if (!$ok) throw new RuntimeException($label);
    };
    $ordinary = static function(string $path, bool $directory=false): array {
        $s = lstat($path);
        if (!$s || is_link($path) || realpath($path) !== $path || ($s['mode'] & 0170000) !== ($directory ? 0040000 : 0100000) || ($s['mode'] & 0077) || $s['uid'] !== posix_geteuid() || (!$directory && $s['nlink'] !== 1)) throw new RuntimeException('Ordinary private owned input required');
        return $s;
    };
    $write = static function(string $path, string $bytes): void {
        $h = fopen($path, 'x+b');
        if (!$h || !chmod($path,0600)) throw new RuntimeException('Fresh private qualification output required');
        try { $at=0; while ($at < strlen($bytes)) { $n=fwrite($h,substr($bytes,$at,65536)); if (!$n) throw new RuntimeException('Private qualification write incomplete'); $at += $n; } if (!fflush($h) || !fsync($h)) throw new RuntimeException('Private qualification synchronization refused'); }
        finally { fclose($h); }
    };
    $started=hrtime(true); $last=-10; $phrase=null; $input=null; $phase='accepted-capture';
    $budget = static function() use ($c,$started,&$last,&$phase): void {
        $elapsed=(hrtime(true)-$started)/1000000000;
        if ($elapsed-$last>=10) {
            $h=fopen($c['private'].'/progress.private.json','c+b');
            $json=json_encode(['phase'=>$phase,'seconds'=>$elapsed,'memoryBytes'=>memory_get_usage(true),'peakMemoryBytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);
            if (!$h || !chmod($c['private'].'/progress.private.json',0600) || !ftruncate($h,0) || fwrite($h,$json)!==strlen($json) || !fflush($h) || !fsync($h)) throw new RuntimeException('Private qualification progress refused');
            fclose($h); $last=$elapsed;
        }
        if ($elapsed>$c['captureSeconds']) throw new DSA\Backup\Archive_Error('Original recovery stage exceeded its operation budget');
    };
    try {
        $ordinary($c['capture'],true); $ordinary($c['private'],true);
        $check(dirname($c['capture'])===dirname($c['private']) && $c['capture']!==$c['private'],'captured source and qualification have separate private owners');
        $final=$c['capture'].'/capture-final.json'; $ordinary($final);
        $accepted=json_decode(file_get_contents($final),true,512,JSON_THROW_ON_ERROR);
        $check(($accepted['passed']??false)===true && ($accepted['cleanup']??false)===true && ($accepted['nativeExitCode']??null)===0 && ($accepted['stderrBytes']??null)===0 && ($accepted['suppressedOutputBytes']??null)===0 && isset($accepted['capture']['members']['recovery.kiwebackup']),'checked complete original recovery receipt required');
        $check($accepted['capture']['members']['recovery.kiwebackup']['sha256']===$c['captureSha256'],'complete original archive matches independent operator receipt');
        foreach (['recovery.kiwebackup','observation.kiwesource'] as $name) {
            $stat=$ordinary($c['capture'].'/'.$name); $member=$accepted['capture']['members'][$name];
            $check($stat['size']===$member['bytes'] && hash_file('sha256',$c['capture'].'/'.$name)===$member['sha256'],'exact accepted encrypted member: '.$name);
        }
        $ordinary($c['capture'].'/recovery-phrase.private'); $phrase=file_get_contents($c['capture'].'/recovery-phrase.private');
        $check(preg_match('/^[a-f0-9]{64}$/D',$phrase)===1,'exact private recovery key format');
        $qa='/tmp/kiwe-roundtrip-qa-'.bin2hex(random_bytes(12));
        foreach ([$qa,$qa.'/destination',$qa.'/destination/public',$qa.'/stages'] as $path) if (!mkdir($path,0700) || !chmod($path,0700)) throw new RuntimeException('Fresh unserved qualification directory required');
        $write($c['private'].'/stage-attempt.private.json',json_encode(['schema'=>'kiwe.private-original-stage-attempt.v1','root'=>$qa,'identity'=>array_intersect_key(lstat($qa),array_flip(['dev','ino','uid'])),'sourceRoot'=>$c['root']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $public=$qa.'/destination/public'; $check($public!==$c['root'] && !str_starts_with($public,$c['root'].'/'),'qualification destination is outside the served original root');
        $configBefore=hash_file('sha256',$c['root'].'/wp-config.php'); $loaderBefore=hash_file('sha256',$c['root'].'/wp-content/mu-plugins/dsa.php');
        $phase='shared-recovery-stage'; $input=fopen($c['capture'].'/recovery.kiwebackup','rb');
        $staged=DSA\Backup\Portable_Recovery_Stage::prepare($input,file_get_contents($c['capture'].'/observation.kiwesource'),$phrase,$qa.'/stages',$public,$budget);
        fclose($input); $input=null;
        $check($staged['activated']===false && $staged['fullSiteReady']===false && $staged['files']===$accepted['capture']['files'] && $staged['tables']===$accepted['capture']['tables'] && $staged['rows']===$accepted['capture']['rows'],'all original files and database records stage through the existing owner');
        $check(hash_file('sha256',$c['root'].'/wp-config.php')===$configBefore && hash_file('sha256',$c['root'].'/wp-content/mu-plugins/dsa.php')===$loaderBefore,'original connection and Kiwe loader remain exact');
        $state=['schema'=>'kiwe.private-original-stage-state.v1','originalRoot'=>$c['root'],'root'=>$qa,'private'=>$c['private'],'capture'=>$c['capture'],'public'=>$public,'stage'=>$staged['directory'],'captureFinalSha256'=>hash_file('sha256',$final),'configurationSha256'=>$configBefore,'loaderSha256'=>$loaderBefore,'staged'=>$staged];
        $write($c['private'].'/stage-state.private.json',json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $proof['stateSha256']=hash_file('sha256',$c['private'].'/stage-state.private.json');
        $proof['files']=$staged['files']; $proof['fileBytes']=$staged['fileBytes']; $proof['tables']=$staged['tables']; $proof['rows']=$staged['rows'];
        $proof['passed']=true; $proof['cleanup']=true; $proof['retainedPrivateStage']=true; $proof['milliseconds']=(hrtime(true)-$started)/1000000;
    } catch (Throwable $error) {
        $proof['phase']=$phase; $proof['errorClass']=get_class($error); $proof['error']=$error instanceof DSA\Backup\Archive_Error ? $error->getMessage() : 'Original recovery qualification stage refused';
        if(isset($GLOBALS['kiwe_private_stage_open_diagnostic'])){
            $diagnostic=$GLOBALS['kiwe_private_stage_open_diagnostic'];unset($GLOBALS['kiwe_private_stage_open_diagnostic']);
            $proof['stageOpenDiagnostic']=$diagnostic;
            try{$write($c['private'].'/stage-open-diagnostic.private.json',json_encode($diagnostic,JSON_THROW_ON_ERROR));$proof['stageOpenDiagnosticSaved']=true;}
            catch(Throwable $ignored){$proof['stageOpenDiagnosticSaved']=false;}
        }
    } finally { if (is_resource($input)) fclose($input); $phrase=null; }
    return $proof;
};
