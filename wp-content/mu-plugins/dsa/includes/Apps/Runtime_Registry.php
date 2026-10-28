<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Normal plugins announce code locations before Core admits optional engines at plugins_loaded. */
final class Runtime_Registry {
 private static array $providers = [];
 private static array $errors = [];
 public static function owner(string $id): string {
  return match($id){'seo'=>SEO_App_Service::class,'store'=>Store_App_Service::class,'guest'=>Guest_App_Service::class,'ai'=>AI_App_Service::class,'sitegraph'=>SiteGraph_App_Service::class,default=>throw new \InvalidArgumentException('Unknown App.')};
 }

 public static function register(array $descriptor, string $root): bool {
  $id = is_string($descriptor['id'] ?? null) ? $descriptor['id'] : '';
  if (!Runtime_Contract::compatible($descriptor) || !defined('WP_PLUGIN_DIR') || isset(self::$providers[$id])
   || (function_exists('did_action') && did_action('plugins_loaded') > 0)) return self::refuse($id, 'incompatible_or_late');
  $root = rtrim(str_replace('\\','/',$root), '/');
  $expected = rtrim(str_replace('\\','/',WP_PLUGIN_DIR), '/') . '/kiwe-' . $id;
  if ($root !== $expected || is_link($root) || str_replace('\\','/',(string)realpath($root)) !== $root) return self::refuse($id, 'package_location');
  self::$providers[$id] = ['descriptor'=>$descriptor, 'root'=>$root];
  return true;
 }
 private static function refuse(string $id, string $reason): bool {
  if (in_array($id, Runtime_Contract::IDS, true)) self::$errors[$id] = $reason;
  return false;
 }
 public static function installed(string $id): bool {
  return in_array($id, Runtime_Contract::IDS, true) && (Package_Layout::bundled($id) || isset(self::$providers[$id]));
 }
 public static function provider(string $id): ?array { return self::$providers[$id]['descriptor'] ?? null; }
 public static function diagnostics(): array { return self::$errors; }
 /** Read-only Site Graph/MCP discovery. Describes current engines without limiting the full authoring brief. */
 public static function capabilities(): array {
  $apps=[];
  // Preserve the published v1 Start/Seam contract. Software inventory uses the App library.
  foreach(['seo','store'] as$id){$owner=self::owner($id);$provider=self::provider($id);$apps[$id]=['installed'=>self::installed($id),'enabled'=>$owner::enabled(),'source'=>Package_Layout::bundled($id)?'bundled':($provider?'plugin':'absent'),'version'=>Package_Layout::bundled($id)?(defined('DSA_VERSION')?DSA_VERSION:''):(string)($provider['version']??'')];}
  return['schema'=>'kiwe.app-capabilities.v1','coreApi'=>1,'apps'=>$apps,'designContextOwner'=>'core'];
 }
 public static function load(string $class): bool {
  if (!str_starts_with($class, 'DSA\\') || str_contains($class, '..') || str_contains($class, '/')) return false;
  if (defined('DSA_DIR') && is_file(DSA_DIR . 'includes/' . str_replace('\\','/',substr($class,4)) . '.php')) return false;
  foreach (self::$providers as $provider) {
   $descriptor = $provider['descriptor']; $relative = $descriptor['classes'][$class] ?? '';
   if ($relative === '') foreach ($descriptor['namespaces'] as $prefix => $path) if (str_starts_with($class, $prefix)) {
    $relative = $path . str_replace('\\','/',substr($class,strlen($prefix))) . '.php'; break;
   }
   if ($relative === '' || !Runtime_Contract::relative($relative)) continue;
   $file = $provider['root'] . '/' . $relative;
   if (!is_file($file) || is_link($file) || str_replace('\\','/',(string)realpath($file)) !== $file) continue;
   require_once $file;
   return class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false);
  }
  return false;
 }
 public static function asset(string $source): string {
  foreach (self::$providers as $provider) {
   $relative = $provider['descriptor']['assets'][$source] ?? '';
   if ($relative !== '') return plugins_url($relative, $provider['root'] . '/kiwe-' . $provider['descriptor']['id'] . '.php');
  }
  return '';
 }
}
