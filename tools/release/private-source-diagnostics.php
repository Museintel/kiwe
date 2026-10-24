<?php
declare(strict_types=1);
/** Owner-only troubleshooting copy, separate from the consistent recovery file member. */
if(PHP_SAPI!=='cli')return null;
return static function(array $c,#[SensitiveParameter]string $phrase,Closure $budget,Closure $write):array {
    $source=$c['sourceRoot'].'/wp-content/uploads/wc-logs';$stage=$c['private'].'/diagnostics-stage';$owned=[];$directories=[];$total=0;$count=0;$stream=null;$created=false;
    $policy=PHP_OS_FAMILY==='Windows'?'portable':'posix';
    $same=static fn(string $path):bool=>realpath($path)!==false&&(PHP_OS_FAMILY==='Windows'?str_replace('\\','/',realpath($path)):realpath($path))===$path;
    $ordinary=static function(string $path)use($same):array {clearstatcache(true,$path);$s=lstat($path);if(!$s||is_link($path)||!$same($path))throw new RuntimeException('Ordinary diagnostic path required');return $s;};
    try {
        if(!mkdir($stage,0700))throw new RuntimeException('Fresh private diagnostic stage required');$created=true;if(!chmod($stage,0700))throw new RuntimeException('Private diagnostic stage mode required');
        if(file_exists($source)||is_link($source)){
            foreach(['wp-content','wp-content/uploads','wp-content/uploads/wc-logs']as$relative){$s=$ordinary($c['sourceRoot'].'/'.$relative);if(($s['mode']&0170000)!==0040000)throw new RuntimeException('Ordinary diagnostic directory required');}
            $stack=[''];$visited=0;
            while($stack){$budget();$parent=array_pop($stack);if(++$visited>100||substr_count($parent,'/')>8)throw new RuntimeException('Diagnostic directory bound exceeded');$dir=$source.($parent===''?'':'/'.$parent);$ordinary($dir);
                foreach(new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS)as$e){$budget();$relative=($parent===''?'':$parent.'/').$e->getFilename();DSA\Backup\Portable_File_Inventory::relative($relative,$policy);$path=$source.'/'.$relative;$before=$ordinary($path);$target=$stage.'/'.$relative;
                    if(($before['mode']&0170000)===0040000){if(!mkdir($target,0700))throw new RuntimeException('Private diagnostic directory creation refused');$directories[]=$target;if(!chmod($target,0700))throw new RuntimeException('Private diagnostic directory mode required');$stack[]=$relative;continue;}
                    if(($before['mode']&0170000)!==0100000||++$count>1000||$before['size']>67108864-$total)throw new RuntimeException('Ordinary bounded diagnostic files required');
                    $input=fopen($path,'rb');$output=null;if(!$input)throw new RuntimeException('Diagnostic input unavailable');
                    try{$opened=fstat($input);if(!$opened||$opened['ino']!==$before['ino']||$opened['dev']!==$before['dev'])throw new RuntimeException('Diagnostic path changed before opening');$output=fopen($target,'x+b');if(!$output)throw new RuntimeException('Fresh diagnostic copy required');$owned[]=$target;if(!chmod($target,0600))throw new RuntimeException('Private diagnostic mode required');$remaining=$before['size'];$digest=hash_init('sha256');
                        while($remaining>0){$budget();$bytes=fread($input,min($remaining,1048576));if($bytes===false||$bytes==='')throw new RuntimeException('Diagnostic initial extent was truncated');$remaining-=strlen($bytes);hash_update($digest,$bytes);$at=0;while($at<strlen($bytes)){$n=fwrite($output,substr($bytes,$at));if(!$n)throw new RuntimeException('Private diagnostic copy incomplete');$at+=$n;}}
                        if(!fflush($output)||!fsync($output)||fseek($input,0)!==0)throw new RuntimeException('Diagnostic initial extent readback refused');$expected=hash_final($digest);$digest=hash_init('sha256');$remaining=$before['size'];
                        while($remaining>0){$budget();$bytes=fread($input,min($remaining,1048576));if($bytes===false||$bytes==='')throw new RuntimeException('Diagnostic initial extent changed');$remaining-=strlen($bytes);hash_update($digest,$bytes);}
                        $after=$ordinary($path);if($after['ino']!==$before['ino']||$after['dev']!==$before['dev']||$after['size']<$before['size']||!hash_equals($expected,hash_final($digest))||!hash_equals($expected,hash_file('sha256',$target)))throw new RuntimeException('Diagnostic initial extent identity or content changed');$total+=$before['size'];
                    }finally{if(is_resource($output))fclose($output);fclose($input);}
                }
            }
        }
        $plan=DSA\Backup\Portable_File_Inventory::scan($stage,1000,67108864,$budget,$policy,true);if(count($plan['files'])!==$count||$plan['bytes']!==$total)throw new RuntimeException('Private diagnostic extent inventory differs');
        $archive=$c['private'].'/diagnostics.kiwe';$stream=fopen($archive,'x+b');if(!$stream||!chmod($archive,0600))throw new RuntimeException('Fresh private encrypted diagnostics required');$capture=DSA\Backup\Portable_Archive_Container::capture($stage,$plan,$stream,new DSA\Backup\Portable_Archive_Cipher(DSA\Backup\Portable_Archive_Cipher::new_header(),$phrase),$budget);if(!fflush($stream)||!fsync($stream))throw new RuntimeException('Encrypted diagnostics sync refused');fclose($stream);$stream=fopen($archive,'rb');$verified=DSA\Backup\Portable_Archive_Container::verify($stream,$phrase,$budget);fclose($stream);$stream=null;if($verified['inventory']!==$plan||$capture['archiveId']!==$verified['archiveId'])throw new RuntimeException('Encrypted diagnostic authentication refused');
        $metadata=['schema'=>'kiwe.private-source-diagnostics.v1','inventory'=>$plan,'archiveId'=>$verified['archiveId'],'sha256'=>hash_file('sha256',$archive),'bytes'=>filesize($archive),'fileCount'=>$count,'fileBytes'=>$total,'source'=>'wp-content/uploads/wc-logs','initialExtentsOnly'=>true,'atomicSourceSnapshot'=>false,'partOfRecoveryFileSet'=>false];$json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$write($c['private'].'/diagnostics.private.json',$json);
        unset($metadata['inventory']);$metadata['metadataSha256']=hash('sha256',$json);
    }finally{
        if(is_resource($stream))fclose($stream);
        foreach(array_reverse($owned)as$path){if(!is_file($path)||is_link($path)||!$same($path)||!str_starts_with($path,$stage.'/')||!unlink($path))throw new RuntimeException('Exact private diagnostic copy retirement refused');}
        foreach(array_reverse($directories)as$path){if(!is_dir($path)||is_link($path)||!$same($path)||!str_starts_with($path,$stage.'/')||!rmdir($path))throw new RuntimeException('Exact private diagnostic directory retirement refused');}
        if($created&&(!is_dir($stage)||is_link($stage)||!$same($stage)||!rmdir($stage)))throw new RuntimeException('Exact private diagnostic stage retirement refused');
    }
    $metadata['plaintextStageRetired']=true;return $metadata;
};
