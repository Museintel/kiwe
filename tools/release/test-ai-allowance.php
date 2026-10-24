<?php
declare(strict_types=1);
namespace DSA { class Settings { public array $ai=[]; public function get($key,$default=[]){return $key==='ai'?$this->ai:$default;} public function defaults(){return ['ai'=>[]];} } }
namespace DSA\Security { class Secret_Store { public static function decrypt($v){return $v;} public static function decrypt_with_status($v){return ['status'=>$v?'ok':'empty','stored'=>(bool)$v,'encrypted'=>true];} } }
namespace DSA\AI { function time(){return $GLOBALS['clock'];} function usleep($n){} }
namespace {
define('ABSPATH',__DIR__.'/');
$clock=strtotime('2026-09-06 12:00:00 UTC');$checks=[];$network=[];$responses=[];$wpCalls=0;$wpAvailable=true;
function __($s,$d=''){return $s;}function absint($v){return abs((int)$v);}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function sanitize_text_field($v){return trim(strip_tags((string)$v));}function wp_json_encode($v){return json_encode($v);}function wp_cache_delete($k,$g){}function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error {public function __construct(private string $code,private string $message){}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}}
function wp_remote_post($url,$args){$GLOBALS['network'][]=json_decode($args['body'],true);return array_shift($GLOBALS['responses'])??['status'=>200,'body'=>json_encode(['choices'=>[['message'=>['content'=>'Synthetic response']]]])];}
function wp_remote_retrieve_response_code($r){return $r['status'];}function wp_remote_retrieve_body($r){return $r['body']??'';}function wp_remote_retrieve_header($r,$h){return $r['headers'][$h]??'';}
function wp_ai_client_prompt($text){return new class {public function is_supported_for_text_generation(){return $GLOBALS['wpAvailable'];}public function using_system_instruction($s){return $this;}public function using_temperature($v){return $this;}public function using_max_tokens($v){return $this;}public function using_model_preference($v){return $this;}public function generate_text(){$GLOBALS['wpCalls']++;return 'WordPress native result';}};}
class LedgerDatabase {
 public string $options='wp_options';public string $last_error='';public ?string $raw=null;public bool $failRead=false;public bool $failWrite=false;public int $reads=0;public int $writes=0;public $contender=null;
 public function prepare($sql,...$args){return json_encode([$sql,$args]);}
 public function get_var($query){++$this->reads;$this->last_error=$this->failRead?'Synthetic storage failure':'';return $this->raw;}
 public function query($query){++$this->writes;if($this->failWrite)return false;[$sql,$a]=json_decode($query,true);if($this->contender){$c=$this->contender;$this->contender=null;$c();}if(str_starts_with($sql,'INSERT IGNORE')){if($this->raw!==null)return 0;$this->raw=$a[1];return 1;}if(!str_contains($sql,'AND BINARY option_value=BINARY %s'))throw new RuntimeException('Missing atomic comparison');if($this->raw!==$a[2])return 0;$this->raw=$a[0];return 1;}
}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/AI/AI_Allowance_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/WP7/AI_Client_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/AI/AI_Usage_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/AI/AI_Provider_Service.php';
use DSA\AI\AI_Allowance_Service as Allowance;
use DSA\AI\AI_Provider_Service as Provider;
function check($label,$pass){$GLOBALS['checks'][]=(bool)$pass;echo ($pass?'PASS ':'FAIL ').$label.PHP_EOL;}
function setup(array $limits=['minute'=>2,'day'=>30,'month'=>300]){$GLOBALS['wpdb']=new LedgerDatabase();$GLOBALS['network']=[];$GLOBALS['responses']=[];$s=new DSA\Settings();$s->ai=['allow_native_generation'=>true,'native_provider'=>'gemini','native_model'=>'gemini-3.7-flash','native_api_key'=>'synthetic-fixture','max_native_tokens'=>800,'request_allowance'=>$limits];return $s;}
$s=setup();$a=new Allowance($s);
check('status is read-only on fresh install',$a->status()['used']['month']===0&&$wpdb->writes===0);
check('first attempt reserves all site windows',$a->reserve()['ok']&&$a->status()['used']===['minute'=>1,'day'=>1,'month'=>1]);
$b=new Allowance($s);check('consumers share the same site ledger',$b->reserve()['ok']&&$a->reserve()['reason']==='site_ai_minute_limit');
$clock+=60;check('minute rollover retains day and month use',$a->reserve()['ok']&&$a->status()['used']===['minute'=>1,'day'=>3,'month'=>3]);
$clock=strtotime('2026-09-07 00:00:00 UTC');check('day rollover retains monthly use',$a->reserve()['ok']&&$a->status()['used']['day']===1&&$a->status()['used']['month']===4);
$clock=strtotime('2026-10-01 00:00:00 UTC');check('month rollover resets all windows',$a->reserve()['ok']&&$a->status()['used']['month']===1);
$s->ai['native_api_key']='rotated-synthetic';check('key rotation cannot replenish quota',$a->status()['used']['month']===1);
$s->ai['request_allowance']['month']=1;check('lowering a ceiling does not erase spend',$a->reserve()['reason']==='site_ai_month_limit');
$s=setup(['minute'=>1,'day'=>20,'month'=>20]);$a=new Allowance($s);$b=new Allowance($s);$wpdb->contender=static function()use($b){check('simulated competing first insert succeeds',$b->reserve()['ok']);};check('losing first insert rechecks quota',$a->reserve()['reason']==='site_ai_minute_limit'&&$a->status()['used']['month']===1);
$s=setup(['minute'=>2,'day'=>20,'month'=>20]);$a=new Allowance($s);$a->reserve();$b=new Allowance($s);$wpdb->contender=static function()use($b){$b->reserve();};check('compare-and-swap collision cannot overspend',$a->reserve()['reason']==='site_ai_minute_limit'&&$a->status()['used']['month']===2);
$wpdb->raw='broken';check('corrupt ledger fails closed',$a->reserve()['reason']==='site_ai_ledger_invalid'&&!$a->status()['usageAvailable']);
$wpdb->raw=null;$wpdb->failRead=true;check('read failure cannot reset usage',$a->reserve()['reason']==='site_ai_storage_unavailable'&&$wpdb->raw===null);
$wpdb->failRead=false;$wpdb->failWrite=true;check('write failure denies an attempt',$a->reserve()['reason']==='site_ai_storage_unavailable');
$s=setup();$a=new Allowance($s);$a->reserve();$before=$wpdb->raw;$clock-=1;check('clock reversal cannot reopen a window',$a->reserve()['reason']==='site_ai_clock_changed'&&$wpdb->raw===$before);$clock+=1;
$hold=$a->cooldown('120');check('provider cooldown affects every consumer',(new Allowance($s))->reserve()['reason']==='site_ai_cooldown'&&$hold['retryAt']===$clock+120);
$a->cooldown('60');check('shorter concurrent cooldown cannot reduce a hold',$a->status()['retryAt']===$clock+120);
$clock+=120;check('expired cooldown allows bounded work',$a->reserve()['ok']);
$s=setup();$a=new Allowance($s);check('missing Retry-After uses bounded one-hour cooldown',$a->cooldown()['retryAt']===$clock+3600);
$s=setup();$a=new Allowance($s);check('HTTP date Retry-After is respected',$a->cooldown(gmdate('D, d M Y H:i:s',$clock+86400).' GMT')['retryAt']===$clock+86400);
check('negative and malformed limits pause rather than increase allowance',Allowance::sanitize_limits(['minute'=>-1,'day'=>[],'month'=>'n/a'])===['minute'=>0,'day'=>0,'month'=>0]);
check('missing submitted fields preserve prior limits',Allowance::sanitize_limits([],['minute'=>1,'day'=>2,'month'=>3])===['minute'=>1,'day'=>2,'month'=>3]);
$s=setup(['minute'=>0,'day'=>20,'month'=>20]);$p=new Provider($s);$r=$p->generate(['user'=>'Synthetic request']);check('zero allowance blocks transport',$r['ok']===false&&!$r['called']&&count($network)===0);
$s=setup();$s->ai['allow_native_generation']=false;$p=new Provider($s);$p->generate(['user'=>'Synthetic request']);check('disabled generation performs no ledger or network work',$wpdb->reads===0&&$wpdb->writes===0&&count($network)===0);
$s=setup();$s->ai['native_api_key']='';$p=new Provider($s);$p->generate(['user'=>'Synthetic request']);check('missing credentials do not consume an allowance',$wpdb->writes===0&&count($network)===0);
$s=setup();$p=new Provider($s);$p->generate(['user'=>str_repeat('x',90001)]);check('direct transport rejects oversized prompts before spending',$wpdb->writes===0&&count($network)===0);
$s=setup(['minute'=>1,'day'=>20,'month'=>20]);$responses=[['status'=>503]];$p=new Provider($s);$r=$p->generate(['user'=>'Synthetic request']);check('retry cannot exceed shared limit',count($network)===1&&$r['called']&&$r['responseMeta']['attempts']===1&&$r['reason']==='site_ai_minute_limit');
$s=setup();$responses=[['status'=>503],['status'=>200,'body'=>'{"steps":[{"type":"model_output","content":[{"type":"text","text":"Synthetic response"}]}]}']];$p=new Provider($s);$r=$p->generate(['user'=>'Synthetic request']);check('successful retry consumes two attempts',$r['ok']&&count($network)===2&&(new Allowance($s))->status()['used']['month']===2);
check('Gemini Interactions receives configured output limit',$network[0]['generation_config']['max_output_tokens']===800);
$s=setup();$responses=[new WP_Error('timeout','Synthetic timeout')];$r=(new Provider($s))->generate(['user'=>'Synthetic request']);check('uncertain timeout remains charged',$r['called']&&!$r['ok']&&(new Allowance($s))->status()['used']['month']===1);
$s=setup();$responses=[['status'=>429,'headers'=>['retry-after'=>'180']]];$p=new Provider($s);$r=$p->generate(['user'=>'Synthetic request']);$next=$p->generate(['user'=>'Different consumer']);check('429 pauses site and is not retried',$r['httpStatus']===429&&$r['retryAt']===$clock+180&&!$next['called']&&count($network)===1);
$s=setup();$s->ai['native_provider']='wordpress_ai_client';$p=new Provider($s);$before=$wpCalls;$p->generate(['user'=>'Synthetic request']);$p->generate(['user'=>'Synthetic request']);$r=$p->generate(['user'=>'Synthetic request']);check('WordPress AI entry uses same site allowance',$wpCalls===$before+2&&!$r['called']);
$s=setup();$s->ai['native_provider']='wordpress_ai_client';$wpAvailable=false;(new Provider($s))->generate(['user'=>'Synthetic request']);check('unavailable native client consumes no allowance',$wpdb->writes===0);
require __DIR__.'/test-ai-token-allowance-cases.php';
echo count(array_filter($checks)).'/'.count($checks).' AI allowance behavior checks passed.'.PHP_EOL;exit(in_array(false,$checks,true)?1:0);
}
