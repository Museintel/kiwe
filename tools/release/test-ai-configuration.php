<?php
declare(strict_types=1);
namespace DSA { final class Settings { public function defaults():array {return ['ai'=>['native_provider'=>'none','native_model'=>'','native_base_url'=>'','max_native_tokens'=>3000,'companion_modes'=>['website'=>true]]];} } }
namespace DSA\Security { final class Secret_Store { public static function encrypt($value):string {return $GLOBALS['crypto_failure']?'':'synthetic-encrypted:'.base64_encode($value);} } }
namespace {
define('ABSPATH',__DIR__.'/');
class WP_Error {public function __construct(public $code,public $message){}}
function is_wp_error($value):bool {return $value instanceof WP_Error;}
function wp_http_validate_url($value) {return in_array(parse_url($value,PHP_URL_HOST),['api.example.org'],true)?$value:false;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/AI/AI_Configuration_Service.php';
use DSA\AI\AI_Configuration_Service as Config;
$crypto_failure=false;$checks=0;
function check($condition,$label):void {if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;}
try {
 $original=['native_api_key'=>'synthetic-existing-cipher','unrelated_native_setting'=>true,'native_provider'=>'gemini'];
 check(!array_key_exists('native_api_key',Config::projection($original))&&!array_key_exists('unrelated_native_setting',Config::projection($original)),'Projection exposes neither ciphertext nor unlisted settings');
 $saved=Config::patch(['native_provider'=>'openai_compatible','native_base_url'=>'https://api.example.org/v1','native_api_key'=>''],$original);
 check(!is_wp_error($saved)&&$saved['native_api_key']===$original['native_api_key']&&$saved['unrelated_native_setting'],'Blank key preserves existing encryption and unrelated native configuration');
 foreach ([['native_api_key'=>['forged']],['native_api_key'=>"key\nheader"],['native_api_key'=>str_repeat('a',4097)],['native_provider'=>'unowned'],['native_base_url'=>'http://api.example.org/v1'],['native_base_url'=>'https://user:pass@api.example.org/v1'],['native_base_url'=>'https://api.example.org/v1?key=secret'],['native_base_url'=>'https://127.0.0.1/v1'],['native_model'=>"model\nheader"],['max_native_tokens'=>3000.5],['max_native_tokens'=>12001],['unrelated_native_setting'=>false],['remove_api_key'=>'true'],['companion_modes'=>['unknown'=>true]],['companion_modes'=>['website'=>'true']]] as $input) check(is_wp_error(Config::patch($input,$original)),'Malformed or unauthorized provider configuration is refused');
 $saved=Config::patch(['native_api_key'=>'synthetic-new-key'],$original);
 check(!is_wp_error($saved)&&$saved['native_api_key']!=='synthetic-new-key'&&str_starts_with($saved['native_api_key'],'synthetic-encrypted:'),'New credential uses the secret-store boundary');
 check(Config::patch(['remove_api_key'=>true],$original)['native_api_key']==='','Explicit removal clears only the protected credential');
 check(is_wp_error(Config::patch(['remove_api_key'=>true,'native_api_key'=>'synthetic-new-key'],$original)),'Conflicting remove and replace is refused');
 $crypto_failure=true;check(is_wp_error(Config::patch(['native_api_key'=>'synthetic-new-key'],$original)),'Encryption failure cannot save plaintext or silently lose the old key');
 check($original['native_api_key']==='synthetic-existing-cipher','Failed attempts preserve the caller original');
 echo json_encode(['passed'=>true,'checks'=>$checks,'nativeCryptoExecuted'=>false,'networkRequests'=>0,'cleanup'=>true])."\n";
} catch (Throwable $e) {echo json_encode(['passed'=>false,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
}
