<?php
/** Virtual time/header boundary around the actual denial helper; no real delays. */
namespace KiweWorkerBudget;
if ( PHP_SAPI !== 'cli' ) exit(2);
$mode=$argv[1]??'on'; $headers=[]; $wait=0; $extensions=0; $sql=0;
function stp_enforcement_paused(){global $mode;return $mode==='paused';}
function stp_cfg(){global $mode;return ['tarpit_enabled'=>$mode==='off'?0:1];}
function headers_sent(){global $mode;return $mode==='headers-sent';}
function header($value,...$args){global $headers;$headers[]=$value;}
function set_time_limit($seconds){global $extensions;$extensions++;}
function sleep($seconds){global $wait;$wait+=$seconds;}
function ob_flush(){}
function flush(){}
$source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
$from=strpos($source,'function stp_tarpit_response()');$to=strpos($source,'function stp_waf_normalize(',$from);
if($from===false||$to===false)exit(3);
eval('namespace KiweWorkerBudget;'.substr($source,$from,$to-$from));
$returned=false;$value=null;
register_shutdown_function(function()use(&$returned,&$value){
 global $mode,$headers,$wait,$extensions;
 $active=$mode==='on';
 $checks=[
  ['name'=>$mode.': no deliberate worker wait or timeout extension','passed'=>$wait===0&&$extensions===0],
  ['name'=>$mode.': active denial terminates; inactive path returns control','passed'=>$active?!$returned:($returned&&$value===false)],
  ['name'=>$mode.': active response is 429 with retry guidance and no-store','passed'=>$active?(in_array('HTTP/1.1 429 Too Many Requests',$headers,true)&&in_array('Retry-After: 60',$headers,true)&&in_array('Cache-Control: no-store, private',$headers,true)):!$headers],
 ];
 ob_end_clean();$passed=!in_array(false,array_column($checks,'passed'),true);
 echo json_encode(['passed'=>$passed,'checks'=>$checks,'virtualWaitSeconds'=>$wait])."\n";
 if(!$passed)exit(1);
});
ob_start();$value=stp_tarpit_response();$returned=true;
