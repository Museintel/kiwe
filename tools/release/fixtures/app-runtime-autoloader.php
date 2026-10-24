<?php
// Synthetic I/O fixtures use the same small Core provider classes as WordPress's autoloader.
spl_autoload_register(static function(string $class):void{
 $base=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';
 if(str_starts_with($class,'DSA\\Apps\\'))$file=$base.'Apps/'.substr($class,9).'.php';
 elseif(in_array($class,['DSA\\Runtime\\Private_Storage','DSA\\Runtime\\Release_Storage'],true))$file=$base.'Runtime/'.substr($class,12).'.php';
 elseif($class==='DSA\\Commerce\\Store_Commerce_Analytics_Service')$file=$base.'Commerce/Store_Commerce_Analytics_Service.php';
 elseif($class==='DSA\\Onboarding\\Native_Indexing_Service')$file=$base.'Onboarding/Native_Indexing_Service.php';
 else return;
 if(is_file($file))require_once$file;
});
