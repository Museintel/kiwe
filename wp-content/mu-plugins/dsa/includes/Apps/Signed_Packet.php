<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Shared public release trust. Verification never reads a catalogue or changes an installed App. */
final class Signed_Packet {
 public const KEY_ID = 'kiwe-release-2026-01';
 public const PUBLIC_KEY = 'v6qwuL3+Zqx76G5A1MCqkvnzOM62fyXudc8OCgXqKzg=';
 public const ORIGIN = 'https://app.kiwelaunch.com';
 public const RELEASE_SCHEMA = 'kiwe.app-release.v1';
 public const CATALOG_SCHEMA = 'kiwe.apps-catalog.v1';

 public static function canonical(array $value): string {
  $normalize = static function($item) use (&$normalize) {
   if (is_array($item)) {
    if (!array_is_list($item)) ksort($item, SORT_STRING);
    foreach ($item as $key=>$child) $item[$key]=$normalize($child);
   } elseif (!is_string($item) && !is_int($item) && !is_bool($item) && $item !== null) throw new \InvalidArgumentException('Only exact JSON values are supported.');
   return $item;
  };
  return json_encode($normalize($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }
 public static function verify(array $value): bool {
  if (($value['keyId'] ?? '') !== self::KEY_ID || !is_string($value['signature'] ?? null) || strlen($value['signature']) !== 88 || !function_exists('sodium_crypto_sign_verify_detached')) return false;
  $signature=base64_decode($value['signature'],true);$key=base64_decode(self::PUBLIC_KEY,true);
  if (!is_string($signature)||strlen($signature)!==64||!is_string($key)||strlen($key)!==32) return false;
  unset($value['signature']);
  try { return sodium_crypto_sign_verify_detached($signature,self::canonical($value),$key); } catch (\Throwable) { return false; }
 }
 public static function url(string $url): bool {
  $parts=parse_url($url);
  return is_array($parts) && ($parts['scheme']??'')==='https' && ($parts['host']??'')==='app.kiwelaunch.com'
   && !array_intersect(array_keys($parts),['user','pass','port','query','fragment'])
   && str_starts_with($parts['path']??'','/apps/v1/') && !str_contains($parts['path'],'%')
   && !str_contains($parts['path'],'//') && !array_intersect(explode('/',$parts['path']),['.','..'])
   && preg_match('/^\/apps\/v1\/[a-zA-Z0-9_.\/-]+$/D',$parts['path'])===1;
 }
 public static function timestamp(mixed $value): bool {
  if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value)!==1) return false;
  $time=strtotime($value);return $time!==false && gmdate('Y-m-d\TH:i:s\Z',$time)===$value;
 }
 public static function release(array $value): bool {
  $keys=['schema','id','version','channel','coreApi','requiresPhp','requiresWp','requiresWoo','packageUrl','sha256','bytes','packageManifestSha256','publishedAt','keyId','signature'];
  if (count($value)!==count($keys)||array_diff(array_keys($value),$keys)||($value['schema']??'')!==self::RELEASE_SCHEMA) return false;
  if (!in_array($value['id']??'',Runtime_Contract::IDS,true)||!is_string($value['version']??null)||!Runtime_Contract::version($value['version'])||($value['coreApi']??null)!==1||!in_array($value['channel']??'',['stable','candidate'],true)) return false;
  foreach(['requiresPhp','requiresWp']as$key)if(!is_string($value[$key]??null)||preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$value[$key])!==1)return false;
  if(!is_string($value['requiresWoo']??null)||($value['id']==='seo'?$value['requiresWoo']!=='':preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$value['requiresWoo'])!==1))return false;
  foreach(['sha256','packageManifestSha256']as$key)if(!is_string($value[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$value[$key])!==1)return false;
  if(!is_int($value['bytes']??null)||$value['bytes']<1||$value['bytes']>16777216||!self::timestamp($value['publishedAt']??null)||!is_string($value['packageUrl']??null)||!self::url($value['packageUrl']))return false;
  $expected='/apps/v1/releases/'.$value['id'].'/'.$value['version'].'/kiwe-'.$value['id'].'.zip';
  return parse_url($value['packageUrl'],PHP_URL_PATH)===$expected && self::verify($value);
 }
 public static function catalogue(array $value, ?int $now=null): bool {
  $keys=['schema','channel','coreApi','publishedAt','expiresAt','apps','revoked','keyId','signature'];
  if(count($value)!==count($keys)||array_diff(array_keys($value),$keys)||($value['schema']??'')!==self::CATALOG_SCHEMA||($value['coreApi']??null)!==1||!in_array($value['channel']??'',['stable','candidate'],true))return false;
  if(!self::timestamp($value['publishedAt']??null)||!self::timestamp($value['expiresAt']??null)||!is_array($value['apps']??null)||count($value['apps'])>count(Runtime_Contract::IDS)||!is_array($value['revoked']??null)||!array_is_list($value['revoked'])||count($value['revoked'])>32)return false;
  $now??=time();$published=strtotime($value['publishedAt']);$expires=strtotime($value['expiresAt']);
  if($published>$now+300||$expires<=$now||$expires<=$published||$expires-$published>604800)return false;
  foreach($value['apps']as$id=>$release)if(!is_array($release)||!self::release($release)||$release['id']!==$id||$release['channel']!==$value['channel'])return false;
  foreach($value['revoked']as$entry)if(!is_string($entry)||preg_match('/^(?:seo|store)@[0-9]+\.[0-9]+\.[0-9]+(?:-(?:rc|beta|alpha)\.[1-9][0-9]*)?$/D',$entry)!==1)return false;
  return self::verify($value);
 }
}
