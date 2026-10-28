<?php
declare(strict_types=1);
define('ARRAY_A','ARRAY_A');define('MINUTE_IN_SECONDS',60);define('PK_FLOW_TTL',900);$checks=[];$GLOBALS['claim_now']='2026-10-26 00:00:00';$GLOBALS['verified_factors']=[];
require __DIR__.'/fixtures/phonekey-functions.php';
$source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
foreach(['pk_claim_challenge','pk_token_row','pk_rest_verify_email','pk_rest_verify_phone']as$name){$code=kiwe_phonekey_function($source,$name);if($code!==null)eval($code);elseif($name!=='pk_claim_challenge')throw new RuntimeException('Required real function missing');}
function sanitize_key($v){return$v;}function sanitize_text_field($v){return$v;}function absint($v){return abs((int)$v);}function pk_t($v){return'wp_pk_'.$v;}function pk_hmac($v){return hash('sha256',$v);}function pk_now(){return$GLOBALS['claim_now'];}function pk_meta_decode($v){return json_decode($v,true)?:[];}
function pk_flow_user($v){return$v==='synthetic-flow'?[17,['id'=>9],$GLOBALS['claim_flow_meta']??[]]:[0,null,[]];}function pk_rate_limit(...$v){return true;}function pk_log(...$v){}function get_userdata($id){return(object)['user_email'=>'synthetic@example.test'];}
function pk_mark_factor_verified($id,$type,...$v){$GLOBALS['verified_factors'][]=[$id,$type];}function pk_complete_counterpart_flow(...$v){return 0;}function is_wp_error($v){return false;}function pk_factor(...$v){return null;}function pk_decrypt($v){return$v;}
function pk_next_security_setup_response($id,$meta){return['ok'=>true,'next'=>'synthetic-next'];}function pk_new_device_recovery_response(...$v){return['ok'=>true,'next'=>'synthetic-passkey'];}
function pk_is_privileged($id){return!empty($GLOBALS['claim_privileged']);}function pk_credential_count($id){return 1;}function pk_flag_admin_enrollment($id){$GLOBALS['claim_admin_flagged']=true;}function pk_step_up_required($id){return true;}function pk_issue_token(...$v){return'synthetic-stepup';}function pk_totp_factor($id){return[];}
class WP_REST_Request{function __construct(private array$params){}function get_param($key){return$this->params[$key]??null;}}
class WP_REST_Response{function __construct(public array$data,public int$status=200){}}
function rest_ensure_response($v){return$v instanceof WP_REST_Response?$v:new WP_REST_Response($v);}
final class Claim_Test_DB{
 public array$rows=[];public bool$fail=false;public bool$pause=false;public array$updates=[];
 function prepare($sql,...$args){return[$sql,$args];}
 function get_row($query,$format){[$sql,$args]=$query;$found=null;foreach($this->rows as$row){if($row['used']||$row['expires_at']<=pk_now())continue;if(str_contains($sql,'token_hash=%s')){$eligible=$row['purpose']===$args[0]&&$row['token_hash']===$args[1];}else{$purpose=str_contains($sql,"purpose='email_otp'")?'email_otp':'phone_otp';$eligible=$row['purpose']===$purpose&&$row['user_id']===$args[0];}if($eligible)$found=$row;}if($this->pause&&Fiber::getCurrent())Fiber::suspend('eligible_challenge_snapshot');return$found;}
 function update($table,$values,$where){if($this->fail){$this->updates[]=false;return false;}$id=$where['id'];$count=empty($this->rows[$id]['used'])?1:0;$this->rows[$id]=array_replace($this->rows[$id],$values);$this->updates[]=$count;return$count;}
 function query($query){[$sql,$args]=$query;if($this->fail){$this->updates[]=false;return false;}[$usedAt,$id,$purpose,$hash,$now]=$args;$row=$this->rows[$id]??null;$eligible=$row&&!$row['used']&&$row['purpose']===$purpose&&$row['token_hash']===$hash&&$row['expires_at']>$now;$this->updates[]=$eligible?1:0;if(!$eligible)return 0;$this->rows[$id]['used']=1;$this->rows[$id]['used_at']=$usedAt;return 1;}
}
function claim_reset($purpose='email_magic'){$GLOBALS['claim_now']='2026-10-26 00:00:00';$GLOBALS['verified_factors']=[];$GLOBALS['claim_flow_meta']=[];$GLOBALS['claim_privileged']=false;$GLOBALS['claim_admin_flagged']=false;$GLOBALS['wpdb']=new Claim_Test_DB();$GLOBALS['wpdb']->rows[1]=['id'=>1,'user_id'=>17,'purpose'=>$purpose,'token_hash'=>pk_hmac('synthetic-token'),'meta'=>json_encode(['flow_token'=>'synthetic-flow','otp_hash'=>pk_hmac('123456')]),'used'=>0,'expires_at'=>'2026-10-26 01:00:00'];}
function claim_check($ok,$why){$GLOBALS['checks'][$why]=(bool)$ok;}
function claim_worker($fn){$GLOBALS['wpdb']->pause=true;$f=new Fiber($fn);if($f->start()!=='eligible_challenge_snapshot')throw new RuntimeException('Expected real claim-read interleaving');return$f;}

claim_reset();$a=claim_worker(fn()=>pk_token_row('email_magic','synthetic-token',true));$b=claim_worker(fn()=>pk_token_row('email_magic','synthetic-token',true));$a->resume();$b->resume();
claim_check($a->getReturn()!==null&&$b->getReturn()===null&&$wpdb->updates===[1,0],'Parallel token consumers admit exactly one winner');
claim_reset();$wpdb->fail=true;claim_check(pk_token_row('email_magic','synthetic-token',true)===null,'Failed token claim never authenticates');
claim_reset();$a=claim_worker(fn()=>pk_token_row('email_magic','synthetic-token',true));$GLOBALS['claim_now']='2026-10-26 02:00:00';$a->resume();claim_check($a->getReturn()===null&&!$wpdb->rows[1]['used'],'Expiry is rechecked at token consumption');
claim_reset();$a=claim_worker(fn()=>pk_token_row('email_magic','synthetic-token',true));$wpdb->rows[1]['purpose']='stepup';$a->resume();claim_check($a->getReturn()===null&&!$wpdb->rows[1]['used'],'Challenge purpose remains bound at consumption');
claim_reset();$a=claim_worker(fn()=>pk_token_row('email_magic','synthetic-token',true));$wpdb->rows[1]['token_hash']=pk_hmac('replacement-token');$a->resume();claim_check($a->getReturn()===null&&!$wpdb->rows[1]['used'],'Challenge token hash remains bound at consumption');
claim_reset();$peek=pk_token_row('email_magic','synthetic-token',false);claim_check($peek!==null&&!$wpdb->rows[1]['used']&&$wpdb->updates===[],'Deliberate challenge preview remains nonconsuming');
claim_reset();$wpdb->rows[1]['used']=1;claim_check(pk_token_row('email_magic','synthetic-token',true)===null,'Used token remains denied');
claim_reset();$wpdb->rows[1]['expires_at']=pk_now();claim_check(pk_token_row('email_magic','synthetic-token',true)===null,'Expired token remains denied');
claim_reset();claim_check(pk_token_row('email_magic','wrong-token',true)===null&&pk_token_row('webauthn_login','synthetic-token',true)===null,'Wrong token and wrong purpose remain denied');

foreach(['email','phone']as$type){
 $fn='pk_rest_verify_'.$type;$r=new WP_REST_Request(['token'=>'synthetic-flow','code'=>'123456']);
 claim_reset($type.'_otp');$a=claim_worker(fn()=>$fn($r));$b=claim_worker(fn()=>$fn($r));$a->resume();$b->resume();
 claim_check(!empty($a->getReturn()->data['ok'])&&empty($b->getReturn()->data['ok'])&&count($GLOBALS['verified_factors'])===1,$type.': only the OTP claim winner verifies a factor or advances login');
 claim_reset($type.'_otp');$wpdb->fail=true;$out=$fn($r);claim_check(empty($out->data['ok'])&&!$GLOBALS['verified_factors']&&!$wpdb->rows[1]['used'],$type.': failed OTP database write has no verification effects');
 claim_reset($type.'_otp');$a=claim_worker(fn()=>$fn($r));$GLOBALS['claim_now']='2026-10-26 02:00:00';$a->resume();claim_check(empty($a->getReturn()->data['ok'])&&!$GLOBALS['verified_factors'],$type.': OTP expiry is rechecked at claim time');
 claim_reset($type.'_otp');$wpdb->rows[1]['meta']=json_encode(['flow_token'=>'another-flow','otp_hash'=>pk_hmac('123456')]);$out=$fn($r);claim_check($out->status===403&&!$GLOBALS['verified_factors']&&!$wpdb->rows[1]['used'],$type.': wrong flow binding does not consume or verify');
 claim_reset($type.'_otp');$out=$fn(new WP_REST_Request(['token'=>'synthetic-flow','code'=>'654321']));claim_check($out->status===403&&!$GLOBALS['verified_factors']&&!$wpdb->rows[1]['used'],$type.': wrong code remains denied before consumption');
 claim_reset($type.'_otp');$wpdb->rows[1]['used']=1;$out=$fn($r);claim_check(empty($out->data['ok'])&&!$GLOBALS['verified_factors'],$type.': replay of a consumed OTP remains denied');
 claim_reset($type.'_otp');$wpdb->rows[1]['meta']=json_encode(['flow_token'=>'synthetic-flow','otp_hash'=>pk_hmac('654321')]);$out=$fn($r);claim_check($out->status===403&&!$wpdb->rows[1]['used'],$type.': replacement OTP accepts its own code only');
 claim_reset($type.'_otp');$out=$fn(new WP_REST_Request(['token'=>'expired-flow','code'=>'123456']));claim_check($out->status===403&&!$GLOBALS['verified_factors']&&!$wpdb->rows[1]['used'],$type.': expired flow remains denied');
}
claim_reset('phone_otp');$GLOBALS['claim_flow_meta']=['mode'=>'bind_phone_required'];$GLOBALS['claim_privileged']=true;$out=pk_rest_verify_phone(new WP_REST_Request(['token'=>'synthetic-flow','code'=>'123456']));
claim_check(!empty($out->data['requiresTotp'])&&!empty($GLOBALS['claim_admin_flagged']),'Successful administrator phone proof still requires the existing TOTP step');
$failed=array_keys(array_filter($checks,fn($ok)=>!$ok));echo json_encode(['passed'=>!$failed,'checks'=>$checks,'failed'=>$failed,'scope'=>'Exact named PhoneKey functions with deterministic database/interleavings and synthetic factors; no real sign-in, provider or device']).PHP_EOL;exit($failed?1:0);
