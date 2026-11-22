<?php
namespace DSA\Access;
if (!defined('ABSPATH')) exit;

use DSA\MCP\Authorization_Service;
use DSA\MCP\Credentials;
use DSA\MCP\Records;
use DSA\MCP\Urls;
use DSA\Apps\MCP_App_Service;
use DSA\Utilities\Origin_Checker;

/** One native editing home: App preparation and the current actor's revocable connections. */
final class MCP_Workspace_Service {
 public function __construct(private Authorization_Service $auth) {}
 public function register(): void {
  add_action('admin_menu',function(){add_submenu_page(null,'MCP','MCP','read',MCP_App_Service::PAGE,[$this,'document']);},34);
  add_action('load-admin_page_'.MCP_App_Service::PAGE,[$this,'document'],1);
  add_action('rest_api_init',function(){register_rest_route(Urls::NS,'/workspace',['methods'=>['GET','POST'],'callback'=>[$this,'request'],'permission_callback'=>[$this,'permission']]);});
 }
 public function document(): void {
  if(!MCP_App_Service::enabled()||!MCP_App_Service::can_connect())wp_die('Website editing permission is required.','',['response'=>403]);
  Task_Workspace_UI::app_document('mcp','MCP',['endpoint'=>Urls::endpoint('workspace')]);
 }
 public function permission(\WP_REST_Request $r): bool {
  return MCP_App_Service::enabled()&&MCP_App_Service::can_connect()&&wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest')&&Origin_Checker::is_same_site_request()
   &&($r->get_method()==='GET'||($r->get_method()==='POST'&&Origin_Checker::mutation_allowed($r)===true));
 }
 public function projection(): array {
  $items=[];$actor=get_current_user_id();
  $registry=new \DSA\MCP\Operation_Registry();\DSA\MCP\Native_Provider::register($registry);
  do_action('kiwe_mcp_register_providers',$registry,\DSA\Apps\MCP_Provider_Contract::API);
  $described=$registry->describe($registry->scopes());$visibleScopes=array_values(array_unique(array_column($described,'scope')));
  $guidanceScopes=array_values(array_intersect($visibleScopes,['mcp.read','mcp.site.read','mcp.context.read','mcp.apps.read','mcp.content.read']));
  $setupScopes=array_values(array_intersect($visibleScopes,['mcp.setup.read','mcp.setup.write']));
  $softwareScopes=array_values(array_intersect($visibleScopes,['mcp.apps.manage']));
  $authoringScopes=array_values(array_intersect($visibleScopes,['mcp.authoring.read','mcp.authoring.write','mcp.authoring.publish']));
  $storeScopes=array_values(array_intersect($visibleScopes,['store.authoring.read','store.authoring.write','store.authoring.publish']));
  $growthScopes=array_values(array_intersect($visibleScopes,['mcp.seo.read','mcp.campaign.read','mcp.campaign.write','mcp.campaign.send','mcp.campaign.policy','seo.authoring.read','seo.authoring.write']));
  $designScopes=array_values(array_intersect($visibleScopes,['mcp.design.read']));
  $bricksImportScopes=array_values(array_intersect($visibleScopes,[\DSA\MCP\Bricks_Import_Provider::SCOPE]));
  $maintenanceScopes=array_values(array_intersect($visibleScopes,['mcp.authoring.read',\DSA\MCP\Maintenance_Provider::AUDIT_SCOPE,\DSA\MCP\Maintenance_Provider::CLEANUP_SCOPE,\DSA\MCP\Maintenance_Provider::CACHE_SCOPE]));
  $visualScopes=array_values(array_intersect($visibleScopes,[\DSA\MCP\Featured_Image_Provider::READ_SCOPE,\DSA\MCP\Featured_Image_Provider::GENERATE_SCOPE]));
  $clientVisualScopes=array_values(array_intersect($visibleScopes,[\DSA\MCP\Featured_Image_Provider::CLIENT_READ,\DSA\MCP\Featured_Image_Provider::CLIENT_WRITE]));
  $aiScopes=array_values(array_intersect($visibleScopes,['ai.configuration.read','ai.configuration.write']));
  foreach(Records::connections($actor)as$row){$client=Records::get('client',(string)($row['data']['client']??''));$items[]=[
   'id'=>$row['id'],'name'=>Task_Workspace_UI::text($client['data']['name']??'AI application',120),'created'=>gmdate('c',(int)($row['data']['createdAt']??0)),'expires'=>gmdate('c',$row['expiresAt']),
   'lastUsed'=>$row['lastUsedAt']?gmdate('c',$row['lastUsedAt']):'','active'=>Credentials::actor_current($row['data'],$actor),'scopes'=>array_values($row['data']['scopes']??[])
  ];}
  return['schema'=>'kiwe.mcp-workspace.v1','ready'=>Records::ready()&&Urls::secure()&&!is_multisite(),'canPrepare'=>MCP_App_Service::allowed(),'secure'=>Urls::secure(),'multisite'=>is_multisite(),
   'resource'=>Urls::resource(),'serverName'=>'kiwe-'.substr(preg_replace('/[^a-z0-9-]/','-',strtolower((string)wp_parse_url(Urls::resource(),PHP_URL_HOST))),0,32).'-'.substr(hash('sha256',Urls::resource()),0,6),'connections'=>$items,'recommendedScopes'=>$guidanceScopes,'setupScopes'=>$setupScopes,'softwareScopes'=>$softwareScopes,'authoringScopes'=>$authoringScopes,'storeScopes'=>$storeScopes,'growthScopes'=>$growthScopes,'designScopes'=>$designScopes,'bricksImportScopes'=>$bricksImportScopes,'maintenanceScopes'=>$maintenanceScopes,'visualScopes'=>$visualScopes,'clientVisualScopes'=>$clientVisualScopes,'aiScopes'=>$aiScopes,'maxConnections'=>20,'readOnly'=>$setupScopes===[]&&$softwareScopes===[]&&$authoringScopes===[]&&$storeScopes===[]&&$growthScopes===[]&&$bricksImportScopes===[]&&$maintenanceScopes===[]&&!in_array(\DSA\MCP\Featured_Image_Provider::GENERATE_SCOPE,$visualScopes,true)&&!in_array(\DSA\MCP\Featured_Image_Provider::CLIENT_WRITE,$clientVisualScopes,true)&&!in_array('ai.configuration.write',$aiScopes,true)];
 }
 public function request(\WP_REST_Request $r): \WP_REST_Response {
  if(!$this->permission($r))return Task_Workspace_UI::failure(new \WP_Error('kiwe_mcp_forbidden','This MCP connection is unavailable.'),403);
  try{
   if($r->get_method()==='POST'){
    $v=$r->get_json_params();if(!is_array($v)||!is_string($v['action']??null))throw new \RuntimeException('Choose a connection action.');
    if($v['action']==='prepare'&&count($v)===1){if(!MCP_App_Service::allowed()||!Urls::secure())throw new \RuntimeException('Ask your website manager to prepare this HTTPS connection.');Records::install();}
    elseif($v['action']==='revoke'&&count($v)===2&&is_string($v['id']??null))$this->auth->revoke_own($v['id'],get_current_user_id());
    else throw new \RuntimeException('Choose a supported connection action.');
   }return Task_Workspace_UI::response($this->projection());
  }catch(\Throwable){return Task_Workspace_UI::failure(new \WP_Error('kiwe_mcp_change','The connection change could not be confirmed. Refresh and review before retrying.'),409);}
 }
}
