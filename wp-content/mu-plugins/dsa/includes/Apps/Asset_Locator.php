<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Shared references follow their installed owner; no directory scans or remote reads. */
final class Asset_Locator {
 public static function url(string $source): string {
  if(!Runtime_Contract::relative($source))return '';
  if(is_file(DSA_DIR.$source))return DSA_URL.$source;
  return Runtime_Registry::asset($source);
 }
 public static function version(string $source): string {
  if(is_file(DSA_DIR.$source))return DSA_VERSION;
  foreach(Runtime_Contract::IDS as$id){$provider=Runtime_Registry::provider($id);if(isset($provider['assets'][$source]))return$provider['version'];}
  return DSA_VERSION;
 }
}
