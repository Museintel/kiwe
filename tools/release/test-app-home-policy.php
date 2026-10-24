<?php
namespace DSA\Diagnostics { final class Runtime_Profiler { public static function start(){return 0;}public static function finish(...$args){} } }
namespace {
define('ABSPATH',__DIR__.'/../../');define('DSA_OPTION_SETTINGS','dsa_settings');
$raw=null;$writes=0;function get_option($name,$fallback=false){return $name==='dsa_settings'?$GLOBALS['raw']:$fallback;}function update_option(...$args){$GLOBALS['writes']++;return true;}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function sanitize_text_field($v){return (string)$v;}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Settings.php';require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Trigger/Trigger_Service.php';
$checks=[];$check=static function($ok,$label)use(&$checks){$checks[]=['passed'=>(bool)$ok,'label'=>$label];if(!$ok)throw new \RuntimeException($label);};
$read=static function($value){$GLOBALS['raw']=$value;return(new \DSA\Settings())->all();};
$fresh=$read(null);$check($fresh['app']['home_enabled']===false&&$fresh['app']['idle_delay_ms']===10000,'Fresh Home is optional with a 10-second inactivity default');
$old=['app'=>['idle_enabled'=>false,'idle_delay_ms'=>60000],'visual_effects'=>['initial_preloader_enabled'=>false]];$before=$old;$state=$read($old);$check(!$state['app']['home_enabled']&&$state['app']['idle_delay_ms']===10000&&$GLOBALS['raw']===$before,'An unused legacy 60-second default becomes 10 without changing stored data');
$old['app']['idle_enabled']=true;$old['app']['idle_delay_ms']=90000;$state=$read($old);$check($state['app']['home_enabled']&&$state['app']['idle_delay_ms']===90000,'An intentional previous inactivity choice is preserved');
$old['app']['idle_enabled']=false;$old['visual_effects']['initial_preloader_enabled']=true;$state=$read($old);$check($state['app']['home_enabled'],'An existing immediate Home remains enabled during compatibility resolution');
$old['app']['home_enabled']=false;$state=$read($old);$rules=array_column((new \DSA\Trigger\Trigger_Service())->contract($state,[])['rules'],null,'id');$check(!$rules['first_session_home']['enabled']&&!$rules['idle_home']['enabled'],'Explicit Home off suppresses both immediate and idle display despite old flags');
$old['app']['home_enabled']=true;$old['visual_effects']['initial_preloader_enabled']=false;$old['app']['idle_delay_ms']=10000;$state=$read($old);$rules=array_column((new \DSA\Trigger\Trigger_Service())->contract($state,[])['rules'],null,'id');$check(!$rules['first_session_home']['enabled']&&$rules['idle_home']['enabled']&&$rules['idle_home']['delayMs']===10000,'Home on with before-load off waits for 10 seconds of inactivity');
$old['visual_effects']['initial_preloader_enabled']=true;$state=$read($old);$rules=array_column((new \DSA\Trigger\Trigger_Service())->contract($state,[])['rules'],null,'id');$check($rules['first_session_home']['enabled']&&$rules['first_session_home']['frequency']==='session_once'&&$rules['idle_home']['frequency']==='after_idle','Immediate entry is session-limited while inactivity can recur');
$old['app']['show_install_actions']=false;$state=$read($old);$check($state['app']['home_enabled']&&$state['service_worker']&&$state['permissions']['pwa_enabled'],'Home visibility and install-button visibility are independent of core App support');
$check($writes===0,'Resolving Home policy does not write settings or create a worker');
echo json_encode(['passed'=>true,'checks'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
}
