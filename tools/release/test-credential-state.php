<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);
$GLOBALS['kiwe_test_options']=[];$GLOBALS['credential_stale_cache']=[];$checks=[];
function get_option($key,$default=[]){return $GLOBALS['credential_stale_cache'][$key]??$GLOBALS['kiwe_test_options'][$key]??$default;}
function update_option($key,$value,...$args){kiwe_credential_test_barrier();if($GLOBALS['wpdb']->fail_write)return false;$GLOBALS['kiwe_test_options'][$key]=$value;return true;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}function sanitize_text_field($v){return trim(strip_tags($v));}function absint($v){return abs((int)$v);}function wp_unslash($v){return$v;}function __($v,...$args){return$v;}
function wp_generate_password($n=12,...$args){return substr(bin2hex(random_bytes($n)),0,$n);}function wp_generate_uuid4(){return bin2hex(random_bytes(16));}function get_current_user_id(){return 17;}function wp_hash_password($v){return hash('sha256',$v);}function wp_check_password($v,$h){return hash_equals((string)$h,hash('sha256',$v));}
class WP_REST_Request{function __construct(private string$token,private bool$bearer=true){}function get_header($name){return$name===($this->bearer?'authorization':'x-kiwe-ai-key')?($this->bearer?'Bearer ':'').$this->token:'';}}
require __DIR__.'/fixtures/credential-records.php';
$runtime=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';if(is_file($runtime.'Utilities/Credential_Records.php'))require$runtime.'Utilities/Credential_Records.php';
require$runtime.'AI/Access_Key_Service.php';require$runtime.'AI/Task_Capsule_Service.php';
function test_check($ok,$why){$GLOBALS['checks'][$why]=(bool)$ok;}
function test_reset(){ $GLOBALS['kiwe_test_options']=[];$GLOBALS['credential_stale_cache']=[];$GLOBALS['credential_cache_deleted']=[];$GLOBALS['credential_pause_commit']=false;$GLOBALS['wpdb']=new Kiwe_Credential_Test_DB(); }
function test_issue($kind,$label='Synthetic proof',$policy=[]){return$kind==='key'?(new DSA\AI\Access_Key_Service())->create($label,['site_graph']):(new DSA\AI\Task_Capsule_Service())->issue($label,'content_read',$policy+['maxUses'=>20]);}
function test_service($kind){return$kind==='key'?new DSA\AI\Access_Key_Service():new DSA\AI\Task_Capsule_Service();}
function test_option($kind){return$kind==='key'?'dsa_ai_access_keys':'dsa_sitegraph_task_capsules_v1';}
function test_plain($kind,$issued){return$issued[$kind==='key'?'key':'token'];}
function test_worker($fn){$GLOBALS['credential_pause_commit']=true;$f=new Fiber($fn);$signal=$f->start();if($signal!=='credential_commit_snapshot')throw new RuntimeException('Expected actual storage-write interleaving');return$f;}

foreach(['key','capsule']as$kind){
 test_reset();$issued=test_issue($kind);$service=test_service($kind);$option=test_option($kind);$request=new WP_REST_Request(test_plain($kind,$issued));
 $worker=test_worker(fn()=>$service->authenticate_request($request,'site_graph'));$revoked=$service->revoke($issued['record']['id'],17);$worker->resume();
 test_check($revoked&&!empty($GLOBALS['kiwe_test_options'][$option][0]['revokedAt']),$kind.': in-flight accounting cannot undo revocation');
 test_check(!$worker->getReturn()['ok']&&!$service->authenticate_request($request,'site_graph')['ok'],$kind.': revocation before the claim denies old and later requests');

 test_reset();$first=test_issue($kind);$service=test_service($kind);$request=new WP_REST_Request(test_plain($kind,$first));$option=test_option($kind);
 $worker=test_worker(fn()=>$service->authenticate_request($request,'site_graph'));$second=test_issue($kind,'Second credential');$worker->resume();
 test_check($worker->getReturn()['ok']&&count($GLOBALS['kiwe_test_options'][$option])===2,$kind.': accounting preserves concurrent issuance');
 test_check($service->authenticate_request(new WP_REST_Request(test_plain($kind,$second)),'site_graph')['ok'],$kind.': concurrently issued credential remains usable');

 test_reset();$worker=test_worker(fn()=>test_issue($kind,'First creation'));$second=test_issue($kind,'Second creation');$worker->resume();$first=$worker->getReturn();$service=test_service($kind);
 test_check(count($GLOBALS['kiwe_test_options'][test_option($kind)])===2&&$service->authenticate_request(new WP_REST_Request(test_plain($kind,$first)),'site_graph')['ok']&&$service->authenticate_request(new WP_REST_Request(test_plain($kind,$second)),'site_graph')['ok'],$kind.': absent-row concurrent creation retains both issued credentials');

 test_reset();$first=test_issue($kind);$second=test_issue($kind);$service=test_service($kind);$option=test_option($kind);$worker=test_worker(fn()=>$service->revoke($first['record']['id'],17));$other=$service->revoke($second['record']['id'],18);$worker->resume();
 test_check($other&&$worker->getReturn()&&count(array_filter($GLOBALS['kiwe_test_options'][$option],fn($r)=>!empty($r['revokedAt'])))===2,$kind.': parallel revocations both persist');

 test_reset();$issued=test_issue($kind);$service=test_service($kind);$option=test_option($kind);$before=$GLOBALS['kiwe_test_options'];$GLOBALS['wpdb']->fail_write=true;
 $result=$service->authenticate_request(new WP_REST_Request(test_plain($kind,$issued)),'site_graph');
 test_check(!$result['ok']&&($result['status']??0)===503&&$GLOBALS['kiwe_test_options']===$before,$kind.': failed authentication accounting is fail-closed without state loss');
 test_check(!$service->revoke($issued['record']['id'],17)&&$GLOBALS['kiwe_test_options']===$before,$kind.': failed revocation is not reported successful');
 $created=false;try{test_issue($kind);$created=true;}catch(RuntimeException$e){}
 test_check(!$created&&$GLOBALS['kiwe_test_options']===$before,$kind.': failed creation exposes no unpersisted credential');

 test_reset();$issued=test_issue($kind);$option=test_option($kind);$cached=$GLOBALS['kiwe_test_options'][$option];test_service($kind)->revoke($issued['record']['id'],17);$GLOBALS['credential_stale_cache'][$option]=$cached;
 test_check(!test_service($kind)->authenticate_request(new WP_REST_Request(test_plain($kind,$issued)),'site_graph')['ok'],$kind.': stale option cache cannot authenticate revoked state');

 test_reset();$issued=test_issue($kind);$service=test_service($kind);$GLOBALS['wpdb']->fail_read=true;$before=$GLOBALS['kiwe_test_options'];$result=$service->authenticate_request(new WP_REST_Request(test_plain($kind,$issued)),'site_graph');
 test_check(!$result['ok']&&($result['status']??0)===503&&$GLOBALS['kiwe_test_options']===$before,$kind.': read failure never falls back to cached authority');

 test_reset();$issued=test_issue($kind);$service=test_service($kind);$before=$GLOBALS['kiwe_test_options'];$GLOBALS['wpdb']->conflict=true;$result=$service->authenticate_request(new WP_REST_Request(test_plain($kind,$issued)),'site_graph');
 test_check(!$result['ok']&&($result['status']??0)===503&&$GLOBALS['wpdb']->reads<=8&&$GLOBALS['kiwe_test_options']===$before,$kind.': persistent contention is bounded and does not confirm a write');

 test_reset();$option=test_option($kind);$GLOBALS['kiwe_test_options'][$option]='synthetic corrupt record';$created=false;try{test_issue($kind);$created=true;}catch(RuntimeException$e){}
 test_check(!$created&&$GLOBALS['kiwe_test_options'][$option]==='synthetic corrupt record',$kind.': corrupt existing storage is retained rather than reset');
}

test_reset();$issued=test_issue('capsule','One use',['maxUses'=>1]);$service=test_service('capsule');$request=new WP_REST_Request($issued['token']);$worker=test_worker(fn()=>$service->authenticate_request($request,'site_graph'));$second=$service->authenticate_request($request,'site_graph');$worker->resume();
test_check($second['ok']&&!$worker->getReturn()['ok']&&$GLOBALS['kiwe_test_options'][test_option('capsule')][0]['uses']===1,'Capsule one-use budget admits exactly one interleaved request');

test_reset();$active=test_issue('capsule');$option=test_option('capsule');$expired=$GLOBALS['kiwe_test_options'][$option][0];$expired['id']='old-expired';$expired['expiresUnix']=time()-DAY_IN_SECONDS-60;$GLOBALS['kiwe_test_options'][$option][]=$expired;$service=test_service('capsule');
$worker=test_worker(fn()=>$service->public_records());$service->revoke($active['record']['id'],17);$worker->resume();
test_check(count($GLOBALS['kiwe_test_options'][$option])===1&&!empty($GLOBALS['kiwe_test_options'][$option][0]['revokedAt']),'Expiry pruning cannot restore a concurrently revoked capsule');

test_reset();$issued=test_issue('key');$service=test_service('key');$request=new WP_REST_Request($issued['key'],false);$first=$service->authenticate_request($request,'site_graph');$second=$service->authenticate_request($request,'site_graph');
test_check($first['ok']&&$second['ok'],'Permanent-key same-second telemetry remains idempotently usable');
test_check(!isset($first['record']['hash'])&&!str_contains(serialize($GLOBALS['kiwe_test_options']),$issued['key']),'Credential formats keep plaintext out of storage and hashes out of public records');
$option=test_option('key');$GLOBALS['wpdb']->autoload[$option]='yes';$GLOBALS['kiwe_test_options'][$option][0]['lastUsedAt']='';$service->authenticate_request($request,'site_graph');
test_check(in_array(['alloptions','options'],$GLOBALS['credential_cache_deleted'],true)&&in_array([$option,'options'],$GLOBALS['credential_cache_deleted'],true),'Legacy autoload and individual option caches invalidate after a confirmed write');

$failed=array_keys(array_filter($checks,fn($ok)=>!$ok));echo json_encode(['passed'=>!$failed,'checks'=>$checks,'failed'=>$failed,'scope'=>'Actual credential services with deterministic native-SQL substitutes; synthetic credentials only']).PHP_EOL;exit($failed?1:0);
