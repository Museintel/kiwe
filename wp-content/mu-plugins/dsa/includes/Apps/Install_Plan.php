<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Pure dependency plan. Existing data, providers and update choices remain native. */
final class Install_Plan {
 public const SCHEMA='kiwe.app-install-plan.v1';
 public static function create(string $id,array $release,array $native,bool $automatic): array {
  if(!Signed_Packet::release($release)||$release['id']!==$id)throw new \RuntimeException('Choose an authenticated release of this App.');
  if(!Runtime_Contract::supports($id,$release['version']))throw new \RuntimeException('This App release needs a compatible newer version for your Kiwe Core.');
  global$wp_version;
  if(version_compare(PHP_VERSION,$release['requiresPhp'],'<')||version_compare((string)$wp_version,$release['requiresWp'],'<'))throw new \RuntimeException('The website needs a compatible PHP or WordPress version first.');
  if($id==='seo'&&($native['seoOwner']??'')!=='')throw new \RuntimeException('Keep your current SEO provider until its reviewed handover is complete.');
  $current=$native['apps'][$id]??null;$steps=[];$dependencies=[];
  if(is_array($current)&&version_compare((string)$current['version'],$release['version'],'>'))throw new \RuntimeException('A newer installed App is preserved. Choose a compatible newer release.');
  if($id==='store'){
   $woo=$native['woo']??[];
   if(!empty($woo['installed'])&&version_compare((string)($woo['version']??'0'),$release['requiresWoo'],'<'))throw new \RuntimeException('WooCommerce needs a reviewed compatible update before this App can be installed.');
   if(empty($woo['installed'])){$dependencies[]='Install WooCommerce from WordPress.org';$steps[]='install_woo';}
   if(empty($woo['active'])){$dependencies[]='Activate WooCommerce';$steps[]='activate_woo';}
  }
  $steps=array_merge($steps,['download','verify','place']); // Reusing a version still authenticates every installed byte; a plugin header is not a trust receipt.
  if(empty($current['active']))$steps[]='activate';
  $steps[]='confirm';
  return['schema'=>self::SCHEMA,'id'=>$id,'version'=>$release['version'],'release'=>$release,'automatic'=>$automatic,'dependencies'=>$dependencies,'steps'=>$steps,'native'=>$native,'createdAt'=>time()];
 }
 public static function fingerprint(array $native): string { return hash('sha256',Signed_Packet::canonical($native)); }
 public static function review(array $plan): array {
  return['id'=>$plan['id'],'version'=>$plan['version'],'dependencies'=>$plan['dependencies'],'automatic'=>$plan['automatic'],'preserves'=>'Existing products, orders, settings, SEO details and accounts stay on your website.'];
 }
}
