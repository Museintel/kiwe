<?php
namespace DSA { class Settings {} }
namespace DSA\Bricks { class Compiler_Import_Plan { public static function allowed(): bool { return true; } } }
namespace DSA\Backup { class Archive_Job_Files { public static function quota(int $actor): void { throw new \RuntimeException('Large import must stop before file/quota work'); } } }
namespace {
define('ABSPATH',__DIR__.'/');$GLOBALS['secret_test_salt']='synthetic-initial-salt';$GLOBALS['secret_test_previous']=[];$GLOBALS['compat_calls']=0;
function wp_salt($kind){return $GLOBALS['secret_test_salt'];}
function apply_filters($name,$value){return $name==='dsa_secret_store_previous_keys'?$GLOBALS['secret_test_previous']:$value;}
function home_url($path=''){return 'https://example.invalid'.$path;}
if(!function_exists('sodium_crypto_secretbox')){
 if(!defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES'))define('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES',24);
 function sodium_crypto_secretbox($value,$nonce,$key){$GLOBALS['compat_calls']++;throw new \RuntimeException('Native encryption should precede this compatibility function');}
 function sodium_crypto_secretbox_open($value,$nonce,$key){$GLOBALS['compat_calls']++;throw new \RuntimeException('Synthetic fallback is not a cryptographic implementation');}
}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Security/Secret_Store.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Package_Error.php';
use DSA\Security\Secret_Store as Store;
$checks=0;$check=static function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new \RuntimeException($label);};
$native=Store::supports_native_encryption();
if(!$native){
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Import_Service.php';
 $blocked=false;try{(new DSA\Bricks\Compiler_Import_Service())->stage('/not-a-real-package.zip');}catch(DSA\Bricks\Compiler_Package_Error $e){$blocked=str_contains($e->getMessage(),'native Sodium or OpenSSL');}
 $check($blocked,'A compatibility-only host stops a large import before any file work');
 $check(Store::encrypt('synthetic')==='','Failed compatibility encryption never returns plaintext');
 echo json_encode(['passed'=>true,'checks'=>$checks,'mode'=>'compatibility-only-denial','native'=>false]).PHP_EOL;exit;
}
$values=["A\0binary\xffsecret",str_repeat('Synthetic checkpoint value ',120000)];$timer=microtime(true);
foreach($values as$value){
 $stored=Store::encrypt($value);$expected=extension_loaded('sodium')?'sodium':'gcm';
 $check(str_starts_with($stored,'dsa-secret:v2:'.$expected.':'),'New writes prefer a native authenticated primitive');
 $read=Store::decrypt_with_status($stored);$check($read['status']==='ok'&&$read['value']===$value,'Actual native binary/large-value round trip');
 $parts=explode(':',$stored,5);$legacy=($expected==='sodium'?'dsa-sodium:':'dsa-gcm:').$parts[4];
 $check(Store::decrypt($legacy)===$value,'Existing native ciphertext format remains readable');
 $raw=base64_decode($parts[4],true);$raw[strlen($raw)-1]=chr(ord($raw[strlen($raw)-1])^1);$parts[4]=base64_encode($raw);
 $check(Store::decrypt_with_status(implode(':',$parts))['status']==='invalid','A modified authenticated ciphertext is refused');
}
$value='Synthetic rotation proof';$old=Store::encrypt($value);$old_key=hash('sha256',$GLOBALS['secret_test_salt'].'|kiwe-secret-store-v1',true);
$GLOBALS['secret_test_salt']='synthetic-next-salt';$check(Store::decrypt_with_status($old)['status']==='key_mismatch','A different key cannot decrypt an existing ciphertext');
$GLOBALS['secret_test_previous']=[base64_encode($old_key)];$check(Store::decrypt($old)===$value,'Explicit existing recovery-key support remains effective');
$check(Store::encrypt('')===''&&Store::decrypt_with_status('')['status']==='empty','Empty values preserve their existing contract');
$check(Store::diagnostics()['nativeEncryption']===true&&!array_key_exists('key',Store::diagnostics()),'Readiness distinguishes native encryption without exposing key material');
$check($GLOBALS['compat_calls']===0,'A PHP fallback is never invoked while native encryption is available');
echo json_encode(['passed'=>true,'checks'=>$checks,'mode'=>extension_loaded('sodium')?'native-sodium':'native-openssl-before-php-fallback','seconds'=>microtime(true)-$timer,'native'=>true]).PHP_EOL;
}
