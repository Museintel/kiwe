<?php
/** Source-level native owner/permission regressions. Real SQL and client consent remain separate. */
namespace DSA\Apps {
 final class Runtime_Registry {public static function installed($id){return in_array($id,['mcp','seo'],true);}public static function owner($id){return $id==='seo'?SEO_App_Service::class:Owner::class;}}
 final class Owner {public static function enabled(){return true;}}
 final class Store_App_Service {public static function enabled(){return $GLOBALS['store'];}}
 final class SEO_App_Service {public static function enabled(){return $GLOBALS['seo']&&!$GLOBALS['external'];}public static function external_owner(){return $GLOBALS['external'];}}
}
namespace DSA\MCP {final class Credentials {public static function actor_allowed($id){return $id===1&&!$GLOBALS['revoked'];}}}
namespace DSA\Onboarding {
 final class SEO_Context_Service {public static function goal(){return ['key'=>'read','label'=>'Readership'];}public static function goal_hash(){return $GLOBALS['goal'];}}
 final class Search_Report_Snapshot {public static function read(){return $GLOBALS['snapshot'];}public static function component($v){return is_array($v)&&isset($v['score'],$v['ready'],$v['total'],$v['weight'],$v['label'],$v['active']);}}
}
namespace DSA\Access {
 final class Task_Workspace_UI {public static function text($s,$n){return substr(strip_tags($s),0,$n);}}
 final class Campaign_Workspace_Service {
  const NATIVE_API=1;
  public static function native_allowed(){return $GLOBALS['canCampaign'];}
  public function catalog(){return ['types'=>[],'groups'=>array_map(fn($n)=>['id'=>'g-'.$n,'name'=>'Group '.$n,'smart'=>false,'description'=>'Audience','private'=>'never export'],range(1,20)),'channels'=>[]];}
  public function native_read($q){$GLOBALS['reads']++;if(!empty($q['id']))return ['draft'=>$GLOBALS['draft']];return ['items'=>[$GLOBALS['draft']],'delivery'=>$GLOBALS['delivery']];}
  public function plan($d){return ['plan'=>['targets'=>[['email'=>'private@example.test']]],'preview'=>$GLOBALS['audience']];}
  public function native_audience($id){return $GLOBALS['audience'];}
  public function native_draft_plan($a){return ['before'=>null,'after'=>$a,'expected'=>hash('sha256',serialize($GLOBALS['draft']))];}
  public function native_write($a){$GLOBALS['writes'][]=$a;return $a['operation']==='save'?['id'=>'c-new','status'=>'draft','expected'=>str_repeat('c',64)]:['queued'=>true,'job'=>['id'=>'job']];}
  public function automations(){return ['cart'=>null,'orders'=>null];}
 }
}
namespace DSA {final class Plugin {public static function instance(){return new self();}public function campaign_workspace(){return new \DSA\Access\Campaign_Workspace_Service();}}}
namespace DSA\SEO {
 final class SEO_Refinement_Service {const AUTHORING_API=1;}
 final class SEO_Defaults_Service {public static function defaults(){return ['home'=>[]];}}
}
namespace {
 define('ABSPATH',__DIR__.'/');define('DSA_VERSION','8.0.0-rc.484');
 class WP_Error {public function __construct(public string $code,public string $message=''){}public function get_error_message(){return $this->message;}}
 class WP_REST_Response {public function __construct(private array $data,private int $status=200){}public function get_status(){return $this->status;}public function get_data(){return $this->data;}}
 $revoked=false;$canCampaign=true;$seo=true;$store=false;$external='';$goal=str_repeat('a',64);$reads=0;$writes=[];$checks=[];
 $snapshot=['report'=>['score'=>73,'calculatedAt'=>'2026-11-01','private'=>'secret','components'=>['posts'=>['score'=>70,'ready'=>7,'total'=>10,'weight'=>40,'active'=>true,'label'=>'Posts','private'=>'secret'],'products'=>['score'=>100,'ready'=>1,'total'=>1,'weight'=>10,'active'=>true,'label'=>'Products']]],'pending'=>false,'inspected'=>10];
 $draft=['id'=>'c-test','name'=>'Story','status'=>'draft','expected'=>str_repeat('b',64),'type'=>'newsletter','sourceId'=>0,'group'=>'email','channels'=>['email'],'copies'=>['email'=>['title'=>'Story','body'=>'Small agreed copy']],'scheduledAt'=>0];
 $delivery=['active'=>null,'history'=>[]];$audience=['count'=>3,'registered'=>3,'anonymous'=>0,'counts'=>['email'=>3],'excluded'=>[],'enabled'=>true,'limited'=>false,'expected'=>str_repeat('d',64),'copies'=>['email'=>['title'=>'Story','body'=>'Small agreed copy and {personal unsubscribe link}','url'=>'https://fixture.example/']]];
 function get_current_user_id(){return 1;}function current_user_can($c,...$a){return !$GLOBALS['revoked'];}function wp_json_encode($v,$f=0){return json_encode($v,$f);}function is_wp_error($v){return $v instanceof WP_Error;}function wp_strip_all_tags($v){return strip_tags($v);}
 $wpdb=new class {public string $prefix='fixture_';public function prepare($s,...$a){return $s;}public function get_var($s){return '1';}};
 $root=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';foreach(['Apps/Runtime_Contract','Apps/MCP_Provider_Contract','MCP/Schema','MCP/Operation_Registry','MCP/Authoring_Service','MCP/Guidance_Service','MCP/SEO_Guidance','MCP/Campaign_Authoring','MCP/Growth_Provider','SEO/MCP_SEO_Provider']as$f)require $root.$f.'.php';
 use DSA\MCP\{Growth_Provider as Provider,Campaign_Authoring as Campaign,SEO_Guidance as Guidance,Schema,Operation_Registry as Registry};
 function check($v,$label){if(!$v)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}
 function denies($fn,$label){try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected denial: '.$label);}
 $store=true;$ops=Provider::operations();check(count($ops)===6,'Six Store-on Core growth operations have explicit scopes');$store=false;
 foreach($ops as$id=>$op){check(Schema::contract($op['inputSchema'])&&Schema::contract($op['outputSchema']),'Closed schemas '.$id);check(!in_array($op['scope'],['mcp.read','mcp.setup.write','mcp.authoring.write'],true),'No earlier scope gains growth access '.$id);if(in_array($id,['mcp.campaign.queue','mcp.campaign.control','mcp.campaign.automation'],true))check($op['approval']==='required','Exact native review '.$id);}
 $r=new Registry();$packet=['schema'=>DSA\Apps\MCP_Provider_Contract::SCHEMA,'api'=>1,'app'=>'mcp','setup'=>['state'=>'ready','needs'=>[],'providerConsentRequired'=>false],'operations'=>$ops];$r->register('mcp',1,fn()=>$packet);
 check($r->describe(['mcp.read','mcp.setup.write','mcp.authoring.write'])===[],'Existing read/setup/authoring grants cannot see new operations');
 $scopes=$r->scopes();$pins=$r->freeze($scopes);check(count($pins)===5,'Store-Off hides the sixth commerce policy operation');check(count(Provider::operations())===5,'Store-Off never advertises an order/cart automation operation');
 foreach($ops as$id=>$op)if($op['effect']==='write'){check(!Schema::value(['approved'=>true],$op['inputSchema']),'Client cannot mint native approval '.$id);}
 $data=json_decode(Guidance::read()['resultJson'],true);check(!str_contains(json_encode($data),'secret')&&!isset($data['report']['components']['products']),'SEO snapshot allowlist excludes private extras and dormant Store');
 $seo=false;$external='rank_math';$data=json_decode(Guidance::read()['resultJson'],true);check($data['owner']==='rank_math'&&!$data['kiweSeoEnabled']&&$data['goal']['key']==='read','Core SEO guidance survives external owner and SEO/AI Off');
 check(!DSA\SEO\MCP_SEO_Provider::allowed(),'External owner suppresses optional Kiwe metadata editing');
 $external='';check(json_decode(Guidance::read()['resultJson'],true)['owner']==='wordpress','SEO Off reports WordPress rather than claiming active Kiwe ownership');$seo=true;
 $s=new Campaign();$catalog=json_decode($s->read(['area'=>'catalog'])['resultJson'],true);check(count($catalog['data']['groups'])===8&&$catalog['data']['hasMore']&&!str_contains(json_encode($catalog),'never export'),'Group catalogue is paged and field-allowlisted');
 $preview=json_decode($s->read(['area'=>'audience','id'=>'c-test'])['resultJson'],true);check($preview['data']['count']===3&&!str_contains(json_encode($preview),'private@example.test'),'Audience read exposes aggregates and never private targets');
 $plan=$s->prepare('queue',['id'=>'c-test']);check($plan['needsApproval']&&str_contains($plan['after'],'personal unsubscribe link')&&$writes===[],'Queue preparation includes full final copy without sending');
 $audience['expected']=str_repeat('e',64);denies(fn()=>$s->apply('queue',['id'=>'c-test'],$plan['nativeExpected']),'Changed audience invalidates exact queue review');check($writes===[],'Stale queue performs no native write');
 $audience['enabled']=false;denies(fn()=>$s->prepare('queue',['id'=>'c-test']),'Disabled consent owner prevents queue');$audience['enabled']=true;
 $audience['count']=0;denies(fn()=>$s->prepare('queue',['id'=>'c-test']),'No opted-in audience prevents queue');$audience['count']=3;
 $audience['limited']=true;denies(fn()=>$s->prepare('queue',['id'=>'c-test']),'Over-limit audience prevents queue');$audience['limited']=false;
 $delivery['active']=['id'=>'other'];denies(fn()=>$s->prepare('queue',['id'=>'c-test']),'Active delivery prevents another queue');$delivery['active']=null;
 $audience['copies']['email']['body']=str_repeat('z',2600);denies(fn()=>$s->prepare('queue',['id'=>'c-test']),'Oversized final review refuses instead of truncating message');$audience['copies']['email']['body']='Agreed body';
 $delivery['active']=['id'=>'job','expected'=>'state','count'=>3];$plan=$s->prepare('control',['action'=>'resume','id'=>'job']);check($plan['needsApproval'],'Resume requires a fresh review');$delivery['active']['count']=2;denies(fn()=>$s->apply('control',['action'=>'resume','id'=>'job'],$plan['nativeExpected']),'Progressed worker invalidates resume review');
 denies(fn()=>$s->prepare('automation',['kind'=>'cart','fieldsJson'=>'{}']),'Store Off excludes cart automation');
 $canCampaign=false;denies(fn()=>$s->read(['area'=>'catalog']),'Native campaign role revocation denies reads');$canCampaign=true;$revoked=true;
 check($r->describe($scopes,$pins)===[],'Actor revocation removes growth operations');denies(fn()=>Guidance::read(),'Revoked actor cannot read saved SEO evidence');$revoked=false;
 // The higher per-provider budget is bounded; native owner growth is not lost at operation 33.
 $template=$ops['mcp.seo.guidance'];$many=[];foreach(range(1,64)as$n)$many['mcp.test.'.$n]=$template;
 $r=new Registry();$packet['operations']=$many;$r->register('mcp',1,fn()=>$packet);check(count($r->describe(['mcp.seo.read']))===64,'Provider accepts a bounded 64 operations');
 $many['mcp.test.65']=$template;$r=new Registry();$packet['operations']=$many;$r->register('mcp',1,fn()=>$packet);check($r->describe(['mcp.seo.read'])===[],'Provider fails closed above 64 operations');
 $seoOps=DSA\SEO\MCP_SEO_Provider::operations();check(count($seoOps)===4,'Optional SEO provider has four independently consented operations');foreach($seoOps as$id=>$op){check(Schema::contract($op['inputSchema'])&&Schema::contract($op['outputSchema']),'Closed optional SEO schema '.$id);if($id!=='seo.authoring.read')check($op['scope']==='seo.authoring.write','SEO writes need their own scope '.$id);}
 echo json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false,'actualClient'=>false])."\n";
}
