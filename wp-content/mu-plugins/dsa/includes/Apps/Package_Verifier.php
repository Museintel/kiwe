<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Verify bytes and every member before the native upgrader may extract executable code. */
final class Package_Verifier {
 public const MANIFEST='kiwe-app-manifest.json';
 public const MAX_FILES=600;
 public const MAX_UNPACKED=67108864;

 public static function archive(string $file,array $release): array {
  if(!Signed_Packet::release($release)||!is_file($file)||is_link($file)||filesize($file)!==$release['bytes']||!hash_equals($release['sha256'],(string)hash_file('sha256',$file)))throw new \RuntimeException('The App download does not match its signed release.');
  if(!class_exists(\ZipArchive::class))throw new \RuntimeException('The PHP ZIP extension is required to verify this App.');
  $zip=new \ZipArchive();if($zip->open($file)!==true)throw new \RuntimeException('The App archive cannot be read.');
  try {
   if($zip->numFiles<3||$zip->numFiles>self::MAX_FILES+1)throw new \RuntimeException('The App archive has an invalid member count.');
   $prefix='kiwe-'.$release['id'].'/';$members=[];$total=0;
   for($i=0;$i<$zip->numFiles;$i++){
    $entry=$zip->statIndex($i);$name=is_array($entry)?$entry['name']:'';
    if(!is_string($name)||!Runtime_Contract::relative($name)||!str_starts_with($name,$prefix)||isset($members[$name])||!empty($entry['encryption_method']))throw new \RuntimeException('The App archive contains an unsafe or repeated member.');
    $os=0;$attributes=0;$zip->getExternalAttributesIndex($i,$os,$attributes);$kind=($attributes>>16)&0170000;
    if(($os===\ZipArchive::OPSYS_UNIX&&$kind!==0&&$kind!==0100000)||$entry['size']<1||$entry['size']>8388608)throw new \RuntimeException('The App archive contains an unsupported file.');
    $total+=$entry['size'];if($total>self::MAX_UNPACKED)throw new \RuntimeException('The App archive exceeds its extraction limit.');
    $members[$name]=['index'=>$i,'bytes'=>$entry['size']];
   }
   $manifest=$zip->getFromName($prefix.self::MANIFEST);
   if(!is_string($manifest)||!hash_equals($release['packageManifestSha256'],hash('sha256',$manifest)))throw new \RuntimeException('The App inventory does not match its signed release.');
   $inventory=self::manifest($manifest,$release);
   if(count($members)!==count($inventory['files'])+1)throw new \RuntimeException('The App archive has undeclared files.');
   foreach($inventory['files']as$name=>$proof){
    $member=$members[$prefix.$name]??null;
    if(!$member||$member['bytes']!==$proof['bytes'])throw new \RuntimeException('The App file inventory is incomplete.');
    $body=$zip->getFromIndex($member['index']);
    if(!is_string($body)||!hash_equals($proof['sha256'],hash('sha256',$body)))throw new \RuntimeException('An App file failed authentication.');
   }
   self::descriptor((string)$zip->getFromName($prefix.'provider.json'),$release);
   return $inventory+['unpackedBytes'=>$total];
  }finally{$zip->close();}
 }
 private static function manifest(string $body,array $release): array {
  try{$value=json_decode($body,true,12,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('The App file inventory is invalid.');}
  if(!is_array($value)||count($value)!==5||array_diff(array_keys($value),['schema','id','version','coreApi','files'])||($value['schema']??'')!=='kiwe.app-files.v1'||($value['id']??'')!==$release['id']||($value['version']??'')!==$release['version']||($value['coreApi']??null)!==$release['coreApi']||!is_array($value['files']??null)||count($value['files'])<2||count($value['files'])>self::MAX_FILES)throw new \RuntimeException('The App file inventory is incompatible.');
  foreach($value['files']as$name=>$proof)if(!is_string($name)||!Runtime_Contract::relative($name)||$name===self::MANIFEST||!is_array($proof)||count($proof)!==2||array_diff(array_keys($proof),['bytes','sha256'])||!is_int($proof['bytes']??null)||$proof['bytes']<1||$proof['bytes']>8388608||!is_string($proof['sha256']??null)||preg_match('/^[a-f0-9]{64}$/D',$proof['sha256'])!==1)throw new \RuntimeException('The App inventory contains an unsupported file proof.');
  if(!isset($value['files']['kiwe-'.$release['id'].'.php'],$value['files']['provider.json']))throw new \RuntimeException('The App bootstrap is missing from its inventory.');
  return$value;
 }
 private static function descriptor(string $body,array $release): array {
  try{$provider=json_decode($body,true,12,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('The App provider is invalid.');}
  if(!is_array($provider)||!Runtime_Contract::compatible($provider))throw new \RuntimeException('The App provider requires a compatible Core, PHP and WordPress version.');
  foreach(['id','version','coreApi','requiresPhp','requiresWp','requiresWoo']as$key)if($provider[$key]!==$release[$key])throw new \RuntimeException('The App provider does not match its release.');
  return$provider;
 }
 /** Readback is bounded to the authenticated inventory, including refusal of extra files. */
 public static function directory(string $root,array $release): array {
  if(!Signed_Packet::release($release)||is_link($root)||!is_dir($root))throw new \RuntimeException('The installed App location is invalid.');
  $body=@file_get_contents($root.'/'.self::MANIFEST);
  if(!is_string($body)||!hash_equals($release['packageManifestSha256'],hash('sha256',$body)))throw new \RuntimeException('The installed App inventory could not be authenticated.');
  $inventory=self::manifest($body,$release);$seen=[];
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS))as$file){
   if($file->isLink()||!$file->isFile())throw new \RuntimeException('The installed App contains an unsupported member.');
   $name=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));if($name===self::MANIFEST)continue;
   $proof=$inventory['files'][$name]??null;
   if(!$proof||$file->getSize()!==$proof['bytes']||!hash_equals($proof['sha256'],(string)hash_file('sha256',$file->getPathname())))throw new \RuntimeException('The installed App does not match its authenticated inventory.');
   $seen[$name]=true;
  }
  if(count($seen)!==count($inventory['files']))throw new \RuntimeException('The installed App is incomplete.');
  self::descriptor((string)@file_get_contents($root.'/provider.json'),$release);return$inventory;
 }
 public static function capacity(string $directory,int $bytes): void {
  $free=disk_free_space($directory);
  if($free===false||$free<$bytes*3+33554432)throw new \RuntimeException('The hosting account needs more free disk space before this App can be installed.');
  if(!is_writable($directory))throw new \RuntimeException('WordPress cannot write to its plugin directory. Ask the website manager to check hosting permissions.');
  // PHP has no portable free-inode API. Native staging must finish and verify every file before activation.
 }
}
