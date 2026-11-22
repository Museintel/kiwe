<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** Passive Core evidence survives optional SEO and AI removal. No refresh or model call. */
final class SEO_Guidance {
 public static function allowed():bool{return Credentials::actor_allowed(get_current_user_id())&&(current_user_can('kiwe_manage_context')||current_user_can('kiwe_manage_ai_preferences')||current_user_can('publish_posts'));}
 public static function read():array{
  if(!self::allowed())throw new \RuntimeException('SEO guidance is unavailable to your current role.');
  $snapshot=\DSA\Onboarding\Search_Report_Snapshot::read();$native=$snapshot['report']??null;$report=null;
  if(is_array($native)){
   $report=['score'=>(int)$native['score'],'calculatedAt'=>Guidance_Service::text($native['calculatedAt']??'',60),'components'=>[]];
   foreach(['onboarding','pages','posts','products','media']as$scope){
    if($scope==='products'&&!\DSA\Apps\Store_App_Service::enabled())continue;
    $c=$native['components'][$scope]??null;if(!\DSA\Onboarding\Search_Report_Snapshot::component($c))continue;
    $report['components'][$scope]=['score'=>$c['score'],'ready'=>$c['ready'],'total'=>$c['total'],'weight'=>$c['weight'],'active'=>$c['active'],'label'=>Guidance_Service::text($c['label'],100)];
   }
  }
  $owner=\DSA\Apps\SEO_App_Service::external_owner();if(!$owner)$owner=\DSA\Apps\SEO_App_Service::enabled()?'kiwe':'wordpress';
  $data=['goal'=>\DSA\Onboarding\SEO_Context_Service::goal(),'goalExpected'=>\DSA\Onboarding\SEO_Context_Service::goal_hash(),'owner'=>$owner?:'kiwe','kiweSeoEnabled'=>\DSA\Apps\SEO_App_Service::enabled(),'report'=>$report,'legacy'=>!empty($snapshot['legacy']),'pending'=>!empty($snapshot['pending']),'inspected'=>max(0,(int)($snapshot['inspected']??0)),'reportNeedsInspection'=>!empty($snapshot['error']),'coverage'=>'Saved native checklist only; no Google visibility, traffic or ranking data is implied. Missing or old evidence remains incomplete. Read current Design Context and content before suggesting changes.','contextOperation'=>'mcp.context.read','goalEditingOperation'=>'mcp.context.change'];
  return['resultJson'=>Authoring_Service::json($data,6000)];
 }
}
