<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

use DSA\Apps\Native_State;
use DSA\Apps\Runtime_Registry;
use DSA\Apps\Runtime_Contract;
use DSA\Apps\Library_Service;
use DSA\Apps\Package_Layout;

/** Passive native software facts. No release refresh, installation, provider call or settings save. */
final class App_Guidance {
 public function __construct(private ?Operation_Registry $registry=null){}
 public const INTENTS=['review','commerce','search','contributions','site-design','built-in-ai','external-ai','connections'];
 public static function allowed(): bool { return Credentials::actor_allowed(get_current_user_id())&&Native_State::can_review(); }
 public function read(array $args): array {
  $native=Native_State::snapshot();$intent=$args['intent']??'review';$items=[];
  foreach(Runtime_Contract::IDS as$id){
   $owner=Runtime_Registry::owner($id);$plugin=$native['apps'][$id]??null;$descriptor=Runtime_Registry::provider($id);
   $bundled=Package_Layout::bundled($id);$installed=$bundled||is_array($plugin);$enabled=$owner::enabled();
   $active=$bundled||!empty($plugin['active']);$compatible=Runtime_Registry::installed($id);
   $state=!$installed?'not-installed':(!$active?'inactive':(!$compatible?'incompatible':($enabled?'review-setup':'disabled')));
   $dependency='';if($id==='store'&&!($native['woo']['active']??false)){$state='needs-dependency';$dependency='WooCommerce';}
   $external=$id==='seo'?(string)$native['seoOwner']:'';
   $recommendation='review-only';$reason='Keep the current choice unless the website needs this capability.';
   if($id==='store'&&$intent==='commerce'){$recommendation=$enabled?'keep':'review-install-or-enable';$reason='Commerce needs the Store App and an admitted WooCommerce installation. A business label does not install either one.';}
   elseif($id==='seo'&&$external!==''){$recommendation='keep-current-provider';$reason='The existing SEO provider owns metadata and discovery. Preserve it; any handover needs a separate reviewed migration.';}
   elseif($id==='seo'&&$intent==='search'){$recommendation=$enabled?'keep':'review-install-or-enable';$reason='Kiwe SEO can manage search metadata, but complete Design Context and the SEO goal remain available in Core without it.';}
   elseif($id==='guest'&&$intent==='contributions'){$recommendation=$enabled?'keep':'review-install-or-enable';$reason='Use Guest only when visitors should submit editorial contributions; it is not required for ordinary comments.';}
   elseif($id==='sitegraph'&&$intent==='site-design'){$recommendation=$enabled?'keep':'review-optional';$reason='Review the SiteGraph App for its optional publication and inspection tools. Core Design Context and the Start/Seam authoring brief remain independent.';}
   elseif($id==='ai'&&$intent==='built-in-ai'){$recommendation=$enabled?'review-provider-setup':'review-optional';$reason='The AI App owns its model/provider credentials, budgets and built-in assistance. Never ask for its keys in chat.';}
   elseif($id==='ai'&&$intent==='external-ai'){$recommendation='not-required';$reason='A connected external AI can use permitted MCP guidance without enabling the AI App or adding a model key.';}
   elseif($id==='mcp'){$recommendation='keep';$reason='This connection uses MCP. Disabling or removing it disconnects remote AI access; the local website remains the authority.';}
   // The original Store/SEO owners predate the named-App constant. Match the
   // existing native Library projection rather than assuming a new interface.
   $name=match($id){'store'=>'Store','seo'=>'SEO',default=>$owner::NAME};
   $items[]=['id'=>$id,'name'=>Guidance_Service::text($name,100),'installed'=>$installed,'active'=>$active,'compatible'=>$compatible,'enabled'=>$enabled,
    'version'=>Guidance_Service::text($bundled?(defined('DSA_VERSION')?DSA_VERSION:''):($plugin['version']??$descriptor['version']??''),60),'state'=>$state,'dependency'=>$dependency,'externalOwner'=>Guidance_Service::text($external,100),
    'settingsUrl'=>$enabled?Guidance_Service::text($owner::url(),2048):'','libraryUrl'=>Guidance_Service::text(Library_Service::url($id),2048),
    'nativeCanInstall'=>Native_State::allowed(),'nativeCanRemove'=>$installed&&Native_State::can_remove($id),'automaticUpdates'=>Native_State::automatic($id),
    'recommendation'=>$recommendation,'reason'=>$reason,'setupVerified'=>false,'providerLinkedVerified'=>false];
  }
  $writes=$this->registry?Setup_Provider::connected_writes($this->registry):[];
  return['schema'=>'kiwe.mcp-app-guidance.v1','intent'=>$intent,'apps'=>$items,'readOnly'=>true,'mcpCanInstall'=>in_array('mcp.app.install',$writes,true),'mcpCanConfigure'=>in_array('mcp.app.change',$writes,true)||in_array('mcp.appshell.change',$writes,true),'mcpCanRemove'=>false,'includedCoreApps'=>['Auth','Security'],
   'guidance'=>[
    'Installation, activation, configuration, external-account linking and action approval are separate facts. Installed/enabled never proves provider setup or a working payment, delivery or campaign.',
    'Use the native Apps library for supported signed installation/update/removal. Recommendations are proposals, not permission; this batch cannot perform those changes.',
    'A false removal permission means this native release does not admit removal for this App/account. Do not delete its files directly or replace removal with data loss.',
    'Auth and Security are included Core Apps and cannot be removed. Keep their current tested policies.',
    'Google, Meta and future Apps supply their own readiness/permissions through the versioned provider contract. They are not installed merely because they appear in a future plan; this connection grants no provider credentials, messaging permission or spending authority.',
   ]];
 }
}
