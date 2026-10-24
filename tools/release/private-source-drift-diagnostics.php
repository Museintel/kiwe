<?php
declare(strict_types=1);
/** Failure-only operator instrumentation. Never included by the installed plugin. */
return static function(array $before, array $after): void {
    $fields=['dev','ino','mode','size','mtime','ctime'];
    $value=['schema'=>'kiwe.private-source-identity-drift.v1','at'=>gmdate('c'),'changedFiles'=>0,'changedFields'=>array_fill_keys($fields,0),'changes'=>[]];
    foreach ($before as $path=>$stamp) {
        if (($after[$path]??null)===$stamp) continue;
        ++$value['changedFiles']; $a=explode(':',$stamp); $b=explode(':',$after[$path]??''); $changed=[];
        foreach($fields as $i=>$field) if (($a[$i]??null)!==($b[$i]??null)) {
            ++$value['changedFields'][$field]; $changed[$field]=['before'=>$a[$i]??null,'after'=>$b[$i]??null];
        }
        if(count($value['changes'])<128) $value['changes'][]=['path'=>$path,'fields'=>$changed];
    }
    $value['detailsTruncated']=$value['changedFiles']>128;
    $path=__DIR__.'/source-identity-drift.private.json';
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $h=fopen($path,'x+b');
    if(!$h||!chmod($path,0600)) throw new \RuntimeException('Fresh private diagnostic required');
    try {
        $at=0; while($at<strlen($json)) { $n=fwrite($h,substr($json,$at,65536)); if(!$n) throw new \RuntimeException('Private diagnostic write incomplete'); $at+=$n; }
        if(!fflush($h)||!fsync($h)) throw new \RuntimeException('Private diagnostic sync refused');
    } finally { fclose($h); }
};
