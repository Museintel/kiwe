<?php
namespace DSA\Runtime;
if (!defined('ABSPATH')) exit;

/** Stable, site-bound storage outside both the document root and native updater scratch. */
final class Private_Storage {
 public static function path(string $purpose): string {
  if(!in_array($purpose,['backups','apps','releases'],true))throw new \RuntimeException('Unknown private storage purpose.');
  $root=realpath(ABSPATH);
  $document=isset($_SERVER['DOCUMENT_ROOT'])&&is_string($_SERVER['DOCUMENT_ROOT'])&&$_SERVER['DOCUMENT_ROOT']!==''?realpath($_SERVER['DOCUMENT_ROOT']):false;
  if(!$document&&$root&&in_array(basename($root),['public_html','htdocs','www','html'],true))$document=$root;
  $parent=$document?dirname($document):'';
  if(!$root||!$document||!$parent||$parent===$document)throw new \RuntimeException('A private storage directory outside the website is unavailable.');
  $base=$parent.DIRECTORY_SEPARATOR.'kiwe-private-'.$purpose;
  $normalized=str_replace('\\','/',$base).'/';
  foreach([$root,$document]as$public)if(str_starts_with($normalized,rtrim(str_replace('\\','/',$public),'/').'/'))throw new \RuntimeException('Private storage cannot be inside the website.');
  if(is_link($base))throw new \RuntimeException('Redirected private storage is refused.');
  return $purpose==='backups'?$base:$base.DIRECTORY_SEPARATOR.'site-'.substr(hash('sha256',str_replace('\\','/',$root)),0,16);
 }
 public static function directory(string $purpose,bool $create=false): string {
  $path=self::path($purpose);
  if($create&&!is_dir($path)&&!wp_mkdir_p($path))throw new \RuntimeException('Private storage could not be created. Ask the website manager to check hosting ownership.');
  if(!is_dir($path))return '';
  $real=realpath($path);
  if(!$real||is_link($path)||str_replace('\\','/',$real)!==str_replace('\\','/',$path))throw new \RuntimeException('Private storage is not at its verified location.');
  if($create){@chmod($real,0700);if($purpose!=='backups')@chmod(dirname($real),0700);}
  return $real;
 }
}
