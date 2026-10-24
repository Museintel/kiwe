<?php
declare(strict_types=1);
/** Operator-only native child protocol. Builder replaces the config/factory markers. */
$job=json_decode(__CONFIG__,true,512,JSON_THROW_ON_ERROR);
$manifest=json_decode(__MANIFEST__,true,512,JSON_THROW_ON_ERROR);
$root=$job['root'];$private=$job['private'];
$proof=['schema'=>'kiwe.private-source-preflight.v1','passed'=>false,'cleanup'=>false,'nativeExitCode'=>null];
if(($argv[1]??'')==='--worker'){
    if(realpath(__DIR__)!==$private||basename(__FILE__)!=='preflight-tool.php')exit(2);
    ob_start();$returned=false;$reserve=str_repeat('x',1048576);
    $proof['unexpectedExit']=true;$proof['memoryLimit']=ini_get('memory_limit');
    $publish=static function()use(&$proof,$private){
        $error=error_get_last();
        if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true)){$proof['passed']=false;$proof['fatalType']=$error['type'];}
        $noise='';while(ob_get_level()>0)$noise=ob_get_clean().$noise;
        $proof['suppressedOutputBytes']=strlen($noise);
        if($proof['suppressedOutputBytes'])$proof['passed']=false;
        $json=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        if(file_put_contents($private.'/native-preflight.json',$json)!==strlen($json)||!chmod($private.'/native-preflight.json',0600))exit(2);
        echo 'KIWE_PREFLIGHT_FINAL:'.$json;
    };
    register_shutdown_function(static function()use(&$returned,&$reserve,$publish){$reserve=null;if(!$returned)$publish();});
    $runner=(static function(){/*__FACTORY__*/})();
    $proof=$job['kind']==='private-source-preflight.php'?$runner($root,$private,$job['seconds']):$runner($root,$private);
    $proof['privateId']=$job['id'];$proof['ownerSources']=$manifest;$proof['memoryLimit']=ini_get('memory_limit');
    $proof['cleanup']=!file_exists($root.'/'.$job['base']);
    $returned=true;register_shutdown_function($publish);
    exit($proof['passed']?0:1);
}
try{
    if(realpath(__DIR__)!==$root||basename(__FILE__)!==$job['base']||is_link($root)||is_dir($private))throw new RuntimeException('Fresh exact native source audit required');
    $vault=dirname($private);
    if(!is_dir($vault)&&(!mkdir($vault,0700)||!chmod($vault,0700)))throw new RuntimeException('Private audit parent refused');
    if(is_link($vault)||(fileperms($vault)&0777)!==0700||fileowner($vault)!==posix_geteuid()||!mkdir($private,0700)||!chmod($private,0700))throw new RuntimeException('Private audit ownership refused');
    if(!rename(__FILE__,$private.'/preflight-tool.php')||!chmod($private.'/preflight-tool.php',0600))throw new RuntimeException('Public audit entry retirement refused');
    $stdout=fopen($private.'/native-stdout.private.log','x+b');$stderr=fopen($private.'/native-stderr.private.log','x+b');
    $phpLog=fopen($private.'/native-php-errors.private.log','x+b');
    if(!$stdout||!$stderr||!$phpLog||!chmod($private.'/native-stdout.private.log',0600)||!chmod($private.'/native-stderr.private.log',0600)||!chmod($private.'/native-php-errors.private.log',0600))throw new RuntimeException('Private native output storage refused');
    fclose($phpLog);
    $child=proc_open([PHP_BINARY,'-d','memory_limit=1024M','-d','display_errors=0','-d','log_errors=1','-d','error_log='.$private.'/native-php-errors.private.log',$private.'/preflight-tool.php','--worker'],[0=>['pipe','r'],1=>$stdout,2=>$stderr],$pipes);
    if(!is_resource($child))throw new RuntimeException('Native preflight child refused');
    fclose($pipes[0]);$exit=proc_close($child);fflush($stdout);fflush($stderr);
    $errorBytes=fstat($stderr)['size'];$outputBytes=fstat($stdout)['size'];
    $proof['nativeExitCode']=$exit;
    if($outputBytes>1048576)throw new RuntimeException('Native final receipt exceeds its bound');
    rewind($stdout);$output=stream_get_contents($stdout);fclose($stdout);fclose($stderr);
    $marker='KIWE_PREFLIGHT_FINAL:';$at=strrpos($output,$marker);
    if($at===false)throw new RuntimeException('Native preflight has no final shutdown receipt');
    $proof=json_decode(substr($output,$at+strlen($marker)),true,512,JSON_THROW_ON_ERROR);
    $proof['nativeExitCode']=$exit;$proof['stderrBytes']=$errorBytes;$proof['stdoutNoiseBytes']=$at;
    $proof['phpErrorBytes']=filesize($private.'/native-php-errors.private.log');
    if($exit!==0||$errorBytes!==0||$at!==0||$proof['phpErrorBytes']!==0)$proof['passed']=false;
}catch(Throwable $error){$proof['passed']=false;$proof['error']='Native source audit did not produce an accepted final receipt';$proof['errorClass']=get_class($error);}
$json=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
if(is_dir($private)){file_put_contents($private.'/preflight.json',$json);chmod($private.'/preflight.json',0600);}
echo$json;exit($proof['passed']?0:1);
