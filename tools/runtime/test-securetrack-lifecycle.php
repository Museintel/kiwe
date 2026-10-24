<?php
if (PHP_SAPI!=='cli')exit(2);
define('ABSPATH',__DIR__.'/');define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);
$options=[];$events=[];$cleared=[];$checks=[];$writes=0;$admin=true;$transients=[];$rate=true;$calls=0;
function get_option($k,$fallback=false){return$GLOBALS['options'][$k]??$fallback;}
function current_time($kind){return'2026-10-07 12:00:00';}
function wp_timezone(){return new DateTimeZone('Asia/Kolkata');}
function wp_timezone_string(){return'Asia/Kolkata';}
function stp_rate_limit(...$args){$GLOBALS['calls']++;return$GLOBALS['rate'];}
function wp_get_scheduled_event($hook){return isset($GLOBALS['events'][$hook])?(object)$GLOBALS['events'][$hook]:false;}
function wp_clear_scheduled_hook($hook){$GLOBALS['cleared'][]=$hook;unset($GLOBALS['events'][$hook]);}
function wp_schedule_event($at,$recurrence,$hook){$GLOBALS['writes']++;$GLOBALS['events'][$hook]=['timestamp'=>$at,'interval'=>$recurrence==='stp_1min'?60:300];}
function current_user_can($cap){return$GLOBALS['admin'];}
function get_transient($key){return$GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$seconds){$GLOBALS['transients'][$key]=$value;}
$wpdb=(object)['prefix'=>'qa_','dbname'=>'synthetic'];
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Lifecycle_Service.php';
use DSA\Secure\SecureTrack_Lifecycle_Service as Life;
$assert=static function($name,$ok)use(&$checks){$checks[]=['name'=>$name,'passed'=>(bool)$ok];};
$assert('Safe new-site age defaults',Life::policy()['green_trim_days']===30&&Life::policy()['yellow_trim_days']===90);
$options['stp_settings']=['green_trim_days'=>0,'yellow_trim_days'=>0,'other'=>17];$p=Life::policy();
$assert('Explicit unlimited retention remains unlimited',$p['green_trim_days']===0&&$p['yellow_trim_days']===0&&$p['page_trim_days']===0);
$assert('Policy projection does not mutate native settings',$options['stp_settings']===['green_trim_days'=>0,'yellow_trim_days'=>0,'other'=>17]);
$stored=['page_trim_days'=>0,'inactive_state_days'=>0,'resolved_alert_days'=>0,'queue_trim_days'=>0,'lifecycle_enabled'=>0,'v2_ai_key_enc'=>'obsolete'];
$saved=Life::preserve_settings(['green_trim_days'=>7,'yellow_trim_days'=>0],$stored);
$assert('Legacy form saves preserve all explicit lifecycle zero/disabled choices',count($saved)===7&&array_sum($saved)===7);
$assert('Legacy form preservation cannot resurrect unrelated provider credentials',!isset($saved['v2_ai_key_enc']));
$assert('Explicit incoming lifecycle choices retain their authority',Life::preserve_settings(['lifecycle_enabled'=>1],$stored)['lifecycle_enabled']===1);
$assert('Zero days never expires history',!Life::old_clock('2000-01-01 00:00:00',0));
$assert('Calendar cutoff uses the website clock',Life::old_clock('2026-09-07 11:59:59',30)&&!Life::old_clock('2026-09-07 12:00:00',30));
$assert('Future clocks are preserved',!Life::old_clock('2027-01-01 00:00:00',1));
foreach(['0000-00-00 00:00:00','2026-02-30 12:00:00','2026-09-31 12:00:00','not-a-date','2026-01-01','2026-01-01 25:00:00']as$bad)$assert('Invalid clock is held: '.$bad,!Life::old_clock($bad,1));
$assert('Disabled/uninstalled lifecycle leaves the existing optional path intact',Life::optional_allowed('visits')&&Life::reserve_visit()&&$calls===0);
Life::schedule();$assert('Uninstalled schema schedules no work',$writes===0);
$options['stp_lifecycle_schema']='1';
$assert('Missing health refuses optional detail',!Life::optional_allowed('visits')&&!Life::reserve_visit()&&$calls===0);
$options['stp_lifecycle_budget']=['asOf'=>time()-901,'allowed'=>['visits'=>true]];
$assert('Stopped background processing refuses optional detail',!Life::optional_allowed('visits'));
$options['stp_lifecycle_budget']=['asOf'=>time(),'allowed'=>['visits'=>true,'learning'=>false]];
$assert('Healthy tracking reuses the native daily counter',Life::reserve_visit()&&$calls===1);
$rate=false;$assert('Quota failure refuses only the ordinary visit',!Life::reserve_visit()&&$calls===2);
$assert('Each optional feature has independent capacity',!Life::optional_allowed('learning')&&Life::optional_allowed('visits'));
$events=['stp_cron_cleanup'=>['timestamp'=>time(),'interval'=>86400],'unrelated'=>['timestamp'=>1,'interval'=>3600]];Life::schedule();
$assert('Daily cleanup upgrades to five minutes',$events['stp_cron_cleanup']['interval']===300&&$cleared===['stp_cron_cleanup']);
$assert('Reports receive a one-minute schedule',$events['stp_cron_reports']['interval']===60);
$assert('No unrelated cron is removed',$events['unrelated']===['timestamp'=>1,'interval'=>3600]);
$n=$writes;Life::schedule();$assert('Repeated scheduling never duplicates work',$writes===$n);
$assert('Cleanup and report start times are staggered',$events['stp_cron_cleanup']['timestamp']-$events['stp_cron_reports']['timestamp']===90);
$admin=false;unset($events['stp_cron_reports']);Life::verify_schedule();$assert('Schedule maintenance requires the native owner capability',!isset($events['stp_cron_reports']));
$admin=true;Life::verify_schedule();$assert('Owner maintenance repairs a missing schedule',isset($events['stp_cron_reports']));
$n=$writes;unset($events['stp_cron_reports']);Life::verify_schedule();$assert('Maintenance verification is throttled',$writes===$n);
$passed=!in_array(false,array_column($checks,'passed'),true);echo json_encode(['passed'=>$passed,'checks'=>$checks])."\n";exit($passed?0:1);
