<?php
namespace DSA\Onboarding;
if(!defined('ABSPATH'))exit;

/** One zero-wait native write boundary shared by browser and delegated edits. */
final class Context_Write_Coordinator {
 private static int $depth=0;
 public static function run(callable $work) {
  if(self::$depth){self::$depth++;try{return$work();}finally{self::$depth--;}}
  global$wpdb;$database=defined('DB_NAME')?DB_NAME:ABSPATH;
  $lock='kiwe-context-'.substr(hash('sha256',$database.'|'.$wpdb->prefix.'|'.get_current_blog_id()),0,40);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')return new \WP_Error('kiwe_context_busy','Another business change is finishing. Refresh and review before saving.',['status'=>409]);
  self::$depth=1;
  try{
   // Plans may have read these in this request before a different writer
   // finished. Refresh the native option cache before checking preimages.
   foreach([Design_Context_Profile_Service::OPTION_PROFILE,Design_Context_Profile_Service::OPTION_STATUS,'alloptions','notoptions']as$key)wp_cache_delete($key,'options');
   return$work();
  }finally{self::$depth=0;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
}
