<?php
namespace DSA\Apps;

use DSA\Access\Task_Workspace_UI as UI;
use DSA\Utilities\Origin_Checker;

if (!defined('ABSPATH')) exit;

/** Software lifecycle belongs to native installers; staff keep their existing operational workspaces. */
final class Library_Service {
 public const PAGE='kiwe-app-library';
 public static function url(string $id=''): string { return add_query_arg(array_filter(['page'=>self::PAGE,'app'=>$id]),admin_url('admin.php')); }
 public function register(): void {
  add_action('admin_menu',[$this,'menu'],37);add_action('rest_api_init',[$this,'routes']);
 }
 public function menu(): void {
  if(!Native_State::can_review())return;
  $hook=add_submenu_page(null,'Kiwe Apps','Apps','read',self::PAGE,[$this,'document']);add_action('load-'.$hook,[$this,'document'],1);
 }
 public function document(): void {
  if(!Native_State::can_review())wp_die('Your website administrator manages software installation.','',['response'=>403]);
  UI::app_document('apps','Apps',['endpoint'=>rest_url('dsa/v1/workspace/app-library'),'timeoutMs'=>60000,'appsHome'=>Store_App_Service::home_url()]);
 }
 public function routes(): void {
  register_rest_route('dsa/v1','/workspace/app-library',[
   ['methods'=>'GET','callback'=>[$this,'listing'],'permission_callback'=>[$this,'permission']],
   ['methods'=>'POST','callback'=>[$this,'mutate'],'permission_callback'=>[$this,'permission']],
  ]);
 }
 public function permission(\WP_REST_Request $request): bool {
  return Native_State::can_review()&&in_array($request->get_method(),['GET','POST'],true)&&wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest')&&Origin_Checker::is_same_site_request()&&($request->get_method()==='GET'||true===Origin_Checker::mutation_allowed($request));
 }
 public function snapshot(): array {
  $native=Native_State::snapshot();$catalogue=(new Catalogue_Service())->snapshot();$installer=new Installer_Service();$jobs=$installer->jobs();$items=[];
  foreach(Runtime_Contract::IDS as$id){$recovery=Code_Recovery::state($id);$owner=Runtime_Registry::owner($id);$release=$catalogue['catalogue']['apps'][$id]??null;$installed=$native['apps'][$id]??null;
   $items[]=['id'=>$id,'name'=>match($id){'store'=>'Store','seo'=>'SEO',default=>$owner::NAME},'description'=>$owner::card()['description'],
    'enabled'=>$owner::enabled(),'installed'=>is_array($installed),'version'=>(string)($installed['version']??''),'active'=>!empty($installed['active']),'bundled'=>Package_Layout::bundled($id),'externalOwner'=>$id==='seo'?$native['seoOwner']:'','settingsUrl'=>$owner::enabled()?$owner::url():'','automatic'=>Native_State::automatic($id),'automaticExpected'=>hash('sha256',Signed_Packet::canonical([$native,Native_State::automatic($id)])),'canUpdate'=>current_user_can('update_plugins'),'releaseVersion'=>(string)($release['version']??''),
    'job'=>isset($jobs[$id])?$installer->projection($jobs[$id]):null,
    'recoveryRemovesApp'=>($recovery['before']??null)===[],
    'canRecover'=>Native_State::can_recover()&&($recovery['id']??'')===$id&&($recovery['phase']??'')==='accepted','recoveryExpected'=>($recovery['id']??'')===$id?hash('sha256',Signed_Packet::canonical($recovery)):''];
  }
  return['items'=>$items,'canInstall'=>Native_State::allowed(),'installationMessage'=>is_multisite()?'This first Apps release supports individual WordPress sites. Ask the network administrator for a qualified network migration.':(!wp_is_file_mod_allowed('kiwe_apps')?'WordPress file changes are disabled on this hosting account. Your website manager can review that restriction.':''),'catalogue'=>['available'=>$catalogue['available'],'message'=>$catalogue['message'],'checkedAt'=>$catalogue['checkedAt']],'woo'=>$native['woo'],'nativeUpdatesUrl'=>admin_url('plugins.php')];
 }
 public function listing(\WP_REST_Request $request): \WP_REST_Response {
  if(!$this->permission($request))return UI::failure(new \WP_Error('kiwe_apps_forbidden','Native plugin installation permission is required.'),403);
  // Opening the authorized software library explicitly checks cached release metadata.
  (new Catalogue_Service())->refresh();
  return UI::response($this->snapshot());
 }
 public function mutate(\WP_REST_Request $request): \WP_REST_Response {
  if(!$this->permission($request))return UI::failure(new \WP_Error('kiwe_apps_forbidden','Native plugin installation permission and a current review are required.'),403);
  $body=$request->get_json_params();$operation=is_array($body)?($body['operation']??''):'';
  $keys=['refresh'=>['operation'],'review'=>['operation','app','automatic'],'start'=>['operation','app','automatic','expected'],'advance'=>['operation','app','job','cursor'],'cancel'=>['operation','app','job'],'automatic'=>['operation','app','enabled','expected'],'recover'=>['operation','app','expected']];
  if(!is_string($operation)||!isset($keys[$operation])||!is_array($body)||count($body)!==count($keys[$operation])||array_diff(array_keys($body),$keys[$operation])||($operation!=='refresh'&&!in_array($body['app']??'',Runtime_Contract::IDS,true)))return UI::failure(new \WP_Error('kiwe_apps_request','Review a supported App action.'),400);
  try{
   $installer=new Installer_Service();$id=$body['app']??'';
   if($operation==='refresh'){(new Catalogue_Service())->refresh(true);return UI::response($this->snapshot());}
   if(in_array($operation,['review','start'],true)&&!is_bool($body['automatic']))throw new \RuntimeException('Choose whether this App should use native automatic updates.');
   if($operation==='review')return UI::response($installer->review($id,$body['automatic']));
   if($operation==='start'){if(!is_string($body['expected'])||preg_match('/^[a-f0-9]{64}$/D',$body['expected'])!==1)throw new \RuntimeException('Review this App before installation.');return UI::response(['job'=>$installer->start($id,$body['automatic'],$body['expected'])]);}
   if(in_array($operation,['advance','cancel'],true)){
    if(!is_string($body['job'])||preg_match('/^[a-f0-9-]{36}$/D',$body['job'])!==1)throw new \RuntimeException('Choose the current App installation.');
    if($operation==='cancel'){$installer->cancel($id,$body['job']);return UI::response($this->snapshot());}
    if(!is_int($body['cursor'])||$body['cursor']<0||$body['cursor']>10)throw new \RuntimeException('Review current App progress.');return UI::response(['job'=>$installer->advance($id,$body['job'],$body['cursor'])]);
   }
   if(!Release_Coordinator::acquire())throw new \RuntimeException('Another website update is finishing.');
   try{
    if($operation==='automatic'){
     if(!is_bool($body['enabled'])||!is_string($body['expected'])||!hash_equals(hash('sha256',Signed_Packet::canonical([Native_State::snapshot(),Native_State::automatic($id)])),$body['expected']))throw new \RuntimeException('The native App or update choice changed. Refresh before saving.');Native_State::automatic_choice($id,$body['enabled']);
    }elseif($operation==='recover'){
     if(!Native_State::can_recover())throw new \RuntimeException('Native plugin activation, file changes and software-management permission are required for recovery.');
     $state=Code_Recovery::state($id);if(($state['id']??'')!==$id||!is_string($body['expected'])||!hash_equals(hash('sha256',Signed_Packet::canonical($state)),$body['expected']))throw new \RuntimeException('The recovery review changed. Refresh first.');Code_Recovery::recover(true,$id);
    }
   }finally{Release_Coordinator::release();}
   return UI::response($this->snapshot());
  }catch(\Throwable $error){return UI::failure(new \WP_Error('kiwe_apps_action',$error->getMessage()),409);}
 }
}
