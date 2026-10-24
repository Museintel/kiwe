<?php
/** Direct App authority regression; actual native role mapping is checked on the lab. */
define('ABSPATH',__DIR__.'/');$caps=[];
function current_user_can(string $cap):bool{return !empty($GLOBALS['caps'][$cap]);}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/App_Workspace_Service.php';
$checks=[];$check=static function(bool $ok,string $label)use(&$checks){$checks[]=['passed'=>$ok,'label'=>$label];};
$caps=['kiwe_manage_context'=>true];$check(DSA\Access\App_Workspace_Service::allowed(),'Client business settings authority admits App without technical manage_options');
$check(!DSA\Access\App_Workspace_Service::audience_allowed(),'App settings alone cannot enumerate app visitors');
$caps+=['list_users'=>true,'edit_users'=>true];$check(DSA\Access\App_Workspace_Service::audience_allowed(),'App audience requires all existing native account permissions');
$caps=['manage_options'=>true,'list_users'=>true,'edit_users'=>true];$check(!DSA\Access\App_Workspace_Service::allowed(),'Technical options alone cannot bypass the Kiwe business boundary');
$passed=!in_array(false,array_column($checks,'passed'),true);echo json_encode(['passed'=>$passed,'checks'=>$checks])."\n";exit($passed?0:1);
