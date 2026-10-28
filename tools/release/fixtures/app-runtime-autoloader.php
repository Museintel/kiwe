<?php
// Synthetic I/O fixtures use the same small Core provider classes as WordPress's autoloader.
spl_autoload_register(static function(string $class):void{
 $base=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';
 if(str_starts_with($class,'DSA\\Apps\\'))$file=$base.'Apps/'.substr($class,9).'.php';
 elseif(in_array($class,['DSA\\Runtime\\Private_Storage','DSA\\Runtime\\Release_Storage'],true))$file=$base.'Runtime/'.substr($class,12).'.php';
 elseif(in_array($class,['DSA\\Commerce\\Checkout_Field_Service','DSA\\Commerce\\Checkout_Details_Model','DSA\\Commerce\\Checkout_Session_Details','DSA\\Commerce\\Discussion_Review_Adapter'],true))$file=$base.'Commerce/'.substr($class,13).'.php';
 elseif($class==='DSA\\Access\\Guest_Engine_Service')$file=$base.'Access/Guest_Engine_Service.php';
 elseif($class==='DSA\\Access\\App_Workspace_Service')$file=$base.'Access/App_Workspace_Service.php';
 elseif($class==='DSA\\Commerce\\Store_Commerce_Analytics_Service')$file=$base.'Commerce/Store_Commerce_Analytics_Service.php';
 elseif($class==='DSA\\Onboarding\\Native_Indexing_Service')$file=$base.'Onboarding/Native_Indexing_Service.php';
 elseif($class==='DSA\\Utilities\\Content_Visibility')$file=$base.'Utilities/Content_Visibility.php';
 elseif(str_starts_with($class,'DSA\\Saved\\'))$file=$base.'Saved/'.substr($class,10).'.php';
 else return;
 if(is_file($file))require_once$file;
});
