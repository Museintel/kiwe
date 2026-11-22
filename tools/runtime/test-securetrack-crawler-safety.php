<?php
declare(strict_types=1);
namespace {
define('ABSPATH',__DIR__.'/');
$options=['stp_settings'=>[]];$updates=0;$checks=[];
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){if(($GLOBALS['options'][$key]??null)===$value)return false;$GLOBALS['options'][$key]=$value;$GLOBALS['updates']++;return true;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
function absint($v){return abs((int)$v);}
function stp_cfg($refresh=false){return $GLOBALS['options']['stp_settings'];}
function check($ok,$why){$GLOBALS['checks'][$why]=(bool)$ok;if(!$ok)throw new \RuntimeException($why);}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Settings_Policy.php';
}
namespace KiweCrawlerSafety {
class Denied extends \RuntimeException {public function __construct(public int $status){parent::__construct('Request ended');}}
$blocked=false;$effects=[];$risk=0;$trap=true;
function stp_enforcement_paused(){return false;}
function stp_cfg(){return ['adaptive_waf'=>1,'honeypot_enabled'=>$GLOBALS['trap']??true,'waf_block_score'=>80];}
function stp_is_block_check_exempt(){return false;}
function wp_parse_url($v,$component){return parse_url($v,$component);}
function stp_get_ip(){return '192.0.2.16';}
function stp_block_decision($ip){return ['blocked'=>$GLOBALS['blocked']??false];}
function stp_deny_blocked_request($decision,$stage){$GLOBALS['effects'][]='existing-denial';throw new Denied(403);}
function get_transient($key){return false;}
function stp_honeypot_path(){return '/stp-honeypot-synthetic';}
function nocache_headers(){$GLOBALS['effects'][]='no-cache';}
function wp_die($message,$title,$args){throw new Denied($args['response']);}
function stp_is_static_request(){return false;}
function stp_request_payload(){return $GLOBALS['payload']??'';}
// Supply detection outcomes at the parser boundary: parser repair is a
// separate native-authoring compatibility task, not part of this release.
function stp_scan_payload($payload){return ['score'=>str_contains($payload,'UNION SELECT')?85:0,'flags'=>['synthetic_attack_signal'=>true]];}
function stp_log(...$args){$GLOBALS['effects'][]='attack-log';}
function stp_tarpit_response(){$GLOBALS['effects'][]='rate-response';}
function home_url($uri=''){return 'https://synthetic.example.test'.$uri;}
function stp_upsert_ip(...$args){throw new \RuntimeException('Crawler visit must never persist an IP');}
function wp_json_encode($v){return json_encode($v);}
$source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
$from=strpos($source,'function stp_waf_guard()');$to=strpos($source,"add_action( 'init', 'stp_waf_guard'",$from);
eval('namespace KiweCrawlerSafety;'.substr($source,$from,$to-$from));
}
namespace {
try{
 $secure=['enabled'=>true,'protection_profile'=>'recommended','auto_logout_enabled'=>false];
 $original=['adaptive_waf'=>0,'block_brute_force'=>1,'honeypot_enabled'=>0,'rl_frontend_per_min'=>27,'future_native_extension'=>['preserve'=>true],'idle_timeout_mins'=>0,'idle_timeout_roles'=>[]];$options['stp_settings']=$original;
 DSA\Secure\SecureTrack_Settings_Policy::sync_from_kiwe($secure);
 check($options['stp_settings']===$original&&$updates===0,'Ordinary recommended-profile boot preserves individual choices without writes');
 DSA\Secure\SecureTrack_Settings_Policy::sync_from_kiwe($secure,true);
 check($options['stp_settings']['adaptive_waf']===1&&$options['stp_settings']['block_brute_force']===1&&$options['stp_settings']['honeypot_enabled']===0,'Explicit recommended preset retains protections and never enables a crawler trap');
 check($options['stp_settings']['future_native_extension']===$original['future_native_extension'],'Explicit preset preserves unrelated native extensions');
 $options['stp_settings']['adaptive_waf']=0;$options['stp_settings']['endpoint_rate_limits']=0;$saved=$options['stp_settings'];$before=$updates;
 for($i=0;$i<3;$i++)DSA\Secure\SecureTrack_Settings_Policy::sync_from_kiwe($secure);
 check($options['stp_settings']===$saved&&$updates===$before,'Later requests cannot reapply a preset over saved overrides');
 DSA\Secure\SecureTrack_Settings_Policy::sync_from_kiwe(['enabled'=>true,'protection_profile'=>'monitor'],true);
 check($options['stp_settings']===$saved,'Keep individual choices does not disable the security engine');
 $idle=$secure+['auto_logout_roles'=>['editor']];$idle['auto_logout_enabled']=true;$idle['auto_logout_minutes']=17;
 DSA\Secure\SecureTrack_Settings_Policy::sync_from_kiwe($idle);
 check($options['stp_settings']['idle_timeout_mins']===17&&$options['stp_settings']['adaptive_waf']===0,'Idle policy remains synchronized independently of preset application');
 foreach([true,false]as$trap)foreach(['GET','HEAD']as$method)foreach(['Googlebot','ordinary browser','spoofed Googlebot']as$ua){$effects=[];$blocked=false;$_SERVER=['REQUEST_METHOD'=>$method,'REQUEST_URI'=>'/stp-honeypot-synthetic','HTTP_USER_AGENT'=>$ua];try{KiweCrawlerSafety\stp_waf_guard();throw new RuntimeException('Legacy path must terminate');}catch(KiweCrawlerSafety\Denied$e){check($e->status===404&&$effects===['no-cache'],'Retired link '.$method.' trap='.(int)$trap.' ua='.$ua.' has no IP/risk/log persistence');}}
 $blocked=true;$effects=[];try{KiweCrawlerSafety\stp_waf_guard();}catch(KiweCrawlerSafety\Denied$e){check($e->status===403&&$effects===['existing-denial'],'Existing explicit containment remains enforced even at a retired URL');}$blocked=false;
 $_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/sitemap_index.xml','HTTP_USER_AGENT'=>'Googlebot'];$payload='';$effects=[];KiweCrawlerSafety\stp_waf_guard();check($effects===[],'Ordinary sitemap crawl adds no trap persistence, exemption or provider work');
 $_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/article/','HTTP_USER_AGENT'=>'Googlebot'];$payload='UNION SELECT password FROM users';$effects=[];try{KiweCrawlerSafety\stp_waf_guard();throw new RuntimeException('Spoofed crawler payload must be checked');}catch(KiweCrawlerSafety\Denied$e){check($e->status===403&&in_array('attack-log',$effects,true),'Crawler-looking user agent cannot bypass actual attack detection');}
 $footer=substr($source,strpos($source,'//  FRONTEND JS'));check(!str_contains($footer,'home_url( stp_honeypot_path() )'),'Public footer emits no discoverable trap link');
 echo json_encode(['passed'=>true,'checks'=>$checks,'count'=>count($checks),'nativeSqlExecuted'=>false,'scope'=>'Actual preset and WAF control flow with synthetic WordPress I/O and parser detection signals; existing parser defect remains separate']).PHP_EOL;
}catch(Throwable$e){echo json_encode(['passed'=>false,'checks'=>$checks,'error'=>$e->getMessage()]).PHP_EOL;exit(1);}
}
