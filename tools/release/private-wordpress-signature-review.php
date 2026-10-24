<?php
declare(strict_types=1);
/** Verify only: actual source WordPress compatibility functions plus a frozen Kiwe release verifier. */
if(PHP_SAPI!=='cli')return null;
return static function(array $c):array {
    $proof=['schema'=>'kiwe.private-wordpress-signature-review.v1','passed'=>false,'wordpressBooted'=>false,'databaseConnected'=>false,'sourceWrites'=>0,'updateExecuted'=>false,'providerRequests'=>0,'phpSapi'=>PHP_SAPI,'phpVersion'=>PHP_VERSION,'nativeSodiumInitiallyLoaded'=>extension_loaded('sodium'),'checks'=>[]];
    $check=static function(bool $ok,string $label)use(&$proof):void{$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
    try{
        $root=$c['sourceRoot'];$check(realpath($root)===$root&&!is_link($root),'exact original source root');
        foreach(['compat.php','http.php','sodium_compat/autoload.php']as$name){$file=$root.'/wp-includes/'.$name;$check(is_file($file)&&!is_link($file)&&realpath($file)===$file,'ordinary original WordPress verifier dependency '.$name);$proof['sourceFiles'][$name]=hash_file('sha256',$file);}
        if(!defined('WPINC'))define('WPINC','wp-includes');require $root.'/wp-includes/compat.php';require $root.'/wp-includes/http.php';
        $check(function_exists('sodium_crypto_sign_verify_detached')&&function_exists('wp_parse_url'),'actual WordPress compatibility and URL functions are callable');
        $fn=new ReflectionFunction('sodium_crypto_sign_verify_detached');$proof['verificationFunctionInternal']=$fn->isInternal();$proof['verificationFunctionSource']=$fn->isInternal()?'native PHP extension':str_replace($root.'/','',$fn->getFileName());
        if(($c['mode']??null)==='pure')$check(!$proof['nativeSodiumInitiallyLoaded']&&!$fn->isInternal(),'no-ini process invokes the actual source pure PHP Sodium fallback');
        $class=new ReflectionClass(DSA\Runtime\Update_Service::class);$service=$class->newInstanceWithoutConstructor();$method=$class->getMethod('verify_release');$release=$c['release'];$channel=$release['channel'];
        $check($method->invoke($service,$release,$channel)===true,'frozen RC306 release signature verifies with actual source functions');
        $altered=$release;$altered['sha256']=str_repeat($release['sha256'][0]==='a'?'b':'a',64);$check($method->invoke($service,$altered,$channel)===false,'altered archive digest refuses');
        $altered=$release;$altered['packageManifestSha256']=str_repeat($release['packageManifestSha256'][0]==='a'?'b':'a',64);$check($method->invoke($service,$altered,$channel)===false,'altered inner manifest digest refuses');
        $check($method->invoke($service,$release,$channel==='stable'?'beta':'stable')===false,'different release channel refuses');
        $altered=$release;$altered['keyId']='untrusted-key';$check($method->invoke($service,$altered,$channel)===false,'untrusted signing-key identity refuses');
        $signature=base64_decode($release['signature'],true);$signature[0]=chr(ord($signature[0])^1);$altered=$release;$altered['signature']=base64_encode($signature);$check($method->invoke($service,$altered,$channel)===false,'altered same-length signature refuses');
        $altered=$release;$altered['packageUrl']='https://example.invalid/update.zip';$check($method->invoke($service,$altered,$channel)===false,'untrusted download origin refuses before any request');
        $proof['passed']=true;$proof['webFpmExecutionQualified']=false;
    }catch(Throwable $error){$proof['error']='Source-bound signature-only review did not qualify';$proof['errorClass']=get_class($error);}
    return $proof;
};
