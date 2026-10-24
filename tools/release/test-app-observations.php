<?php
/** Behavioral regression against the real native audience writer; no WordPress/network required. */
namespace DSA { final class Settings {} }
namespace DSA\Trust { final class Trust_Service {} }
namespace {
define('ABSPATH',__DIR__.'/../../');define('ARRAY_A','ARRAY_A');
function get_current_user_id(){return 7;}function wp_salt($type){return 'app-observation-proof-only';}function current_time($type){return '2026-10-22 05:00:00';}function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}function absint($v){return abs((int)$v);}function wp_json_encode($v){return json_encode($v);}function get_option($name,$fallback=false){return $name==='dsa_notification_preferences_db_version'?(new ReflectionClass(DSA\Notifications\Notification_Preference_Service::class))->getConstant('DB_VERSION'):$fallback;}
class AppObservationDatabase {
 public string $prefix='test_';public array $row=['id'=>1,'user_id'=>7,'is_app'=>1,'app_installed_at'=>null,'app_first_opened_at'=>'2026-10-21 10:00:00','app_last_opened_at'=>'2026-10-21 10:00:00','app_platform'=>'android'];
 function prepare($sql,...$args){return $sql;}function get_var($sql){return 1;}function get_row($sql,$output=null){return $this->row;}function update($table,$data,$where){$this->row=array_replace($this->row,$data);return 1;}
}
$wpdb=new AppObservationDatabase();require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php';
$service=new DSA\Notifications\Notification_Preference_Service(new DSA\Settings(),new DSA\Trust\Trust_Service());$method=new ReflectionMethod($service,'upsert_audience_record');
$preferences=['browserPermission'=>'default','topics'=>[],'inboxTopics'=>[],'channels'=>[],'productCategories'=>[],'postCategories'=>[],'productIds'=>[],'context'=>'browser'];
$before=$wpdb->row;$result=$method->invoke($service,'owned-proof-device',$preferences,['standalone'=>false]);
$checks=[['label'=>'ordinary browser preferences preserve previous app membership','passed'=>$result===true&&$wpdb->row['is_app']===1],['label'=>'ordinary browser use does not advance last app use','passed'=>$wpdb->row['app_last_opened_at']===$before['app_last_opened_at']]];
$passed=!in_array(false,array_column($checks,'passed'),true);echo json_encode(['schema'=>'kiwe.app-observation-regression.v1','passed'=>$passed,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($passed?0:1);
}
