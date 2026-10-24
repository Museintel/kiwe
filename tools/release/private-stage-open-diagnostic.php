<?php
declare(strict_types=1);
/** Failure-only operator diagnostic. Never included by the installed plugin. */
return static function(string $path, ?array $error, int $owned): void {
    $reason='unknown';$message=$error['message']??'';
    foreach(['Too many open files'=>'open-files','File exists'=>'exists','No space left'=>'space','Disk quota'=>'quota','Permission denied'=>'permission','File name too long'=>'name-length','No such file'=>'missing-parent']as$needle=>$label)if(stripos($message,$needle)!==false){$reason=$label;break;}
    $value=['schema'=>'kiwe.private-stage-open-diagnostic.v1','at'=>gmdate('c'),'reason'=>$reason,'pathSha256'=>hash('sha256',$path),'pathLength'=>strlen($path),'ownedFiles'=>$owned,'openFileDescriptors'=>count(glob('/proc/self/fd/*')?:[]),'freeBytes'=>disk_free_space(dirname($path)),'exists'=>file_exists($path),'linked'=>is_link($path),'parentExists'=>is_dir(dirname($path)),'parentWritable'=>is_writable(dirname($path)),'memoryBytes'=>memory_get_usage(true),'peakMemoryBytes'=>memory_get_peak_usage(true)];
    // Preserve the native failure in memory. File/inode quota can also prevent a new diagnostic file.
    // The operator writes this bounded value only after the stage owner's cleanup has run.
    $GLOBALS['kiwe_private_stage_open_diagnostic']=$value;
};
