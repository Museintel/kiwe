<?php
namespace DSA\Apps;
if (!defined('ABSPATH')) exit;

/** Ownership handover is explicit and code-only; the existing native settings remain the source. */
final class Migration_Service {
 public static function recommended(): array {
  return['seo'=>SEO_App_Service::requested()&&SEO_App_Service::external_owner()==='','store'=>Store_App_Service::requested()&&Store_App_Service::available()];
 }
 /** A signed Core profile cannot silently drop an engine this website currently uses. */
 public static function assert_core_profile(array $manifest): void {
  if(!isset($manifest['apps'])){if(!Package_Layout::BUNDLED)throw new \RuntimeException('Independent Core requires an authenticated Core profile update. The bundled release channel is preserved for older installations.');return;}
  $contract=$manifest['apps'];
  if(!is_array($contract)||count($contract)!==3||array_diff(array_keys($contract),['profile','coreApi','minimum'])||($contract['profile']??'')!=='core'||!in_array($contract['coreApi']??null,[1,Runtime_Contract::API],true)||!is_array($contract['minimum']??null)||array_keys($contract['minimum'])!==($contract['coreApi']===1?['seo','store']:Runtime_Contract::IDS))throw new \RuntimeException('The Core App compatibility contract is invalid.');
  if($contract['coreApi']===1)foreach(['guest','ai','sitegraph']as$id)if(!empty($manifest['files'])&&!isset($manifest['files'][match($id){'guest'=>'includes/Access/Guest_Engine_Service.php','ai'=>'includes/AI/AI_Provider_Service.php','sitegraph'=>'includes/Access/SiteGraph_Workspace_Service.php'}]))throw new \RuntimeException('The compatibility bridge must retain its unextracted workspace engines.');
  foreach($contract['minimum']as$version)if(!is_string($version)||!Runtime_Contract::version($version))throw new \RuntimeException('The Core App compatibility version is invalid.');
  $required=[];foreach(array_keys($contract['minimum'])as$id){$owner=Runtime_Registry::owner($id);$required[$id]=Package_Layout::bundled($id)?($id==='seo'?SEO_App_Service::requested()&&SEO_App_Service::external_owner()==='':($id==='store'?Store_App_Service::requested()&&Store_App_Service::available():$owner::requested())):$owner::enabled();}
  $native=Native_State::snapshot();$known=(array)get_option(Code_Recovery::RELEASES,[]);
  foreach($required as$id=>$needed){if(!$needed)continue;
   $app=$native['apps'][$id]??null;$release=$known[$id]??null;
   if(!is_array($app)||!$app['active']||!is_array($release)||!Signed_Packet::release($release)||$app['version']!==$release['version']||version_compare($release['version'],$contract['minimum'][$id],'<'))throw new \RuntimeException('Install and authenticate the compatible Kiwe '.($id==='seo'?'SEO':ucfirst($id)).' App before replacing the bundled Core engine.');
   Package_Verifier::directory(WP_PLUGIN_DIR.'/kiwe-'.$id,$release);
  }
 }
 /** Native migration tools may adopt an independently authenticated package, never a guessed folder. */
 public static function adopt(array $release): void {
  if(!Native_State::allowed()||!Signed_Packet::release($release))throw new \RuntimeException('A native software administrator and an authenticated App release are required.');
  $id=$release['id'];Package_Verifier::directory(WP_PLUGIN_DIR.'/kiwe-'.$id,$release);$native=Native_State::snapshot();
  if(($native['apps'][$id]['version']??'')!==$release['version']||empty($native['apps'][$id]['active']))throw new \RuntimeException('The authenticated native App must be active before handover.');
  $known=(array)get_option(Code_Recovery::RELEASES,[]);$known[$id]=$release;update_option(Code_Recovery::RELEASES,$known,false);
  if(get_option(Code_Recovery::RELEASES,[])!==$known)throw new \RuntimeException('The App migration receipt could not be confirmed.');
 }
}
