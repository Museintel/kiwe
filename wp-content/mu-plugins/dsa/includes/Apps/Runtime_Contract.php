<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Small provider contract. No options, catalogue, filesystem enumeration or business services. */
final class Runtime_Contract {
 public const VERSION = '1.0.0';
 public const SCHEMA = 'kiwe.app-provider.v1';
 public const IDS = ['seo','store'];

 public static function version(string $value): bool {
  return strlen($value) <= 48 && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:rc|beta|alpha)\.[1-9][0-9]*)?$/D', $value) === 1;
 }
 public static function relative(string $value): bool {
  return strlen($value) <= 180 && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\/.\-]*$/D', $value) === 1
   && !str_contains($value, '//') && !array_intersect(explode('/', $value), ['.','..']) && !str_ends_with($value, '/');
 }
 /** The signed package builder and a normal plugin's fixed bootstrap supply this descriptor. */
 public static function validate(array $value): bool {
  if (array_diff(array_keys($value), ['schema','id','version','coreApi','requiresPhp','requiresWp','requiresWoo','namespaces','classes','assets'])) return false;
  if (($value['schema'] ?? '') !== self::SCHEMA || !in_array($value['id'] ?? '', self::IDS, true)
   || !is_string($value['version'] ?? null) || !self::version($value['version']) || ($value['coreApi'] ?? null) !== 1) return false;
  foreach (['requiresPhp','requiresWp'] as $key) if (!is_string($value[$key] ?? null) || preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $value[$key]) !== 1) return false;
  if (!is_string($value['requiresWoo'] ?? null) || ($value['id'] === 'seo' ? $value['requiresWoo'] !== '' : preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $value['requiresWoo']) !== 1)) return false;
  foreach (['namespaces','classes','assets'] as $key) if (!is_array($value[$key] ?? null) || count($value[$key]) > 160) return false;
  foreach ($value['namespaces'] as $prefix => $path) {
   if (!in_array($prefix, $value['id'] === 'seo' ? ['DSA\\SEO\\'] : ['DSA\\Commerce\\','DSA\\Rewards\\'], true)
    || !is_string($path) || !self::relative(rtrim($path, '/')) || !str_ends_with($path, '/')) return false;
  }
  foreach ($value['classes'] as $name => $path) {
   if (!is_string($name) || ($value['id'] === 'seo' ? $name !== 'DSA\\Schema\\Schema_Geo_Service' : preg_match('/^DSA\\\\(?:Access|Rest|Bricks|Notifications)\\\\[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1)
    || !is_string($path) || !self::relative($path) || !str_ends_with($path, '.php')) return false;
  }
  foreach ($value['assets'] as $name => $path) if (!is_string($name) || !self::relative($name) || !is_string($path) || !self::relative($path) || preg_match('/\.(?:css|js|json|svg)$/D', $path) !== 1) return false;
  return true;
 }
 public static function supports(string $id,string $version): bool {
  return isset(Package_Layout::MINIMUM[$id]) && self::version($version) && version_compare($version,Package_Layout::MINIMUM[$id],'>=');
 }
 public static function compatible(array $value): bool {
  global $wp_version;
  return self::validate($value) && self::supports($value['id'],$value['version']) && version_compare(PHP_VERSION, $value['requiresPhp'], '>=')
   && version_compare((string)($wp_version ?? '0'), $value['requiresWp'], '>=');
 }
}
