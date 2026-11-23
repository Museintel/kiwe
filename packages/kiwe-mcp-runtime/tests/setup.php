<?php
declare(strict_types=1);
namespace DSA\Access {
 final class Continuity_App_Service {
  const MCP_SETUP_API=1;public static function native_allowed(){return$GLOBALS['role'];}
  public function field_snapshot($area){return['expected'=>hash('sha256',$GLOBALS['mission']),'fields'=>[['path'=>'about.mission','label'=>'Mission','type'=>'textarea'],['path'=>'about.founder.userId','label'=>'Account','type'=>'person'],['path'=>'about.futurePublicFact','label'=>'Future public fact','type'=>'text']],'values'=>['about.mission'=>$GLOBALS['mission'],'about.founder.userId'=>77,'about.futurePublicFact'=>'Native future fact']];}
  public function change_field($area,$body){$GLOBALS['nativeCalls'][]=['owner'=>'business','area'=>$area,'body'=>$body];if($body['expected']!==hash('sha256',$GLOBALS['mission']))return new \WP_Error('stale');$GLOBALS['mission']=$body['value'];return$this->field_snapshot($area);}
  public function services_snapshot($query){return['expected'=>str_repeat('a',64),'planExpected'=>str_repeat('c',64),'recordMode'=>empty($GLOBALS['planMode']),'service'=>['canEdit'=>true],'values'=>['services.items'=>[['recordId'=>empty($GLOBALS['planMode'])?12:0,'stableId'=>'native-plan','title'=>'Owned service','description'=>'Native private draft','status'=>'draft','expected'=>str_repeat('b',64)]]]];}
 }
 final class App_Workspace_Service {
  public static function allowed(){return$GLOBALS['role'];}public static function dock_allowed(){return$GLOBALS['role'];}public static function feedback_allowed(){return$GLOBALS['role'];}public static function editable_fields(){return['app'=>['welcome_message'=>'text']];}public static function url(...$args){return'https://fixture.example/wp-admin/admin.php?page=kiwe-app-settings';}
  public function snapshot(){return['expected'=>str_repeat('c',64),'choices'=>['app'=>['welcome_message'=>'Hello'],'style'=>['sheet_position'=>'bottom']]];}
  public function update($body){$GLOBALS['nativeCalls'][]=['owner'=>'appshell','body'=>$body];$s=$this->snapshot();$s['choices']=array_replace_recursive($s['choices'],$body['changes']);return$s;}
 }
 final class Dock_Workspace_Service{public function fields(){return['style'=>['sheet_position'=>['bottom','left','right']]];}}
 final class Task_Workspace_UI {public static function text($v,$max){return substr(strip_tags((string)$v),0,$max);}}
}
namespace DSA\Onboarding {final class Design_Context_Profile_Service {public static function identity_fields(){return['siteName'=>['type'=>'text','maxLength'=>200],'futureLabel'=>['type'=>'text','maxLength'=>100]];}public function identity_projection(){return['siteName'=>'Fixture','futureLabel'=>'Native added field','expected'=>str_repeat('d',64)];}}}
namespace DSA\MCP {final class Credentials {public static function actor_allowed($id){return$GLOBALS['role'];}}}
namespace DSA\Apps {
 final class Native_State {public static function allowed(){return$GLOBALS['role'];}}
 final class Runtime_Registry {public static function installed($id){return$id==='mcp';}public static function owner($id){return Owner::class;}}
 final class Owner {public static function enabled(){return true;}}
}
namespace {
 define('ABSPATH',__DIR__.'/');define('DSA_VERSION','8.0.0-rc.479');$role=true;$mission='Native original';$nativeCalls=[];$denyPost=false;$checks=[];
 function get_current_user_id(){return 1;}function current_user_can($cap,...$args){return$GLOBALS['role']&&($cap!=='edit_post'||!$GLOBALS['denyPost']);}function admin_url($v=''){return'https://fixture.example/wp-admin/'.$v;}function wp_json_encode($v){return json_encode($v);}function is_wp_error($v){return$v instanceof WP_Error;}class WP_Error{public function __construct(public $code){}public function get_error_message(){return$this->code;}}
 class WP_REST_Response{}
 $root=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';foreach(['Apps/Runtime_Contract','Apps/MCP_Provider_Contract','Apps/Signed_Packet','MCP/Schema','MCP/Operation_Registry','MCP/Setup_Service','MCP/Setup_Provider']as$f)require$root.$f.'.php';
 use DSA\MCP\{Schema,Operation_Registry as Registry,Setup_Provider,Setup_Service};
 $check=static function($v,$label)use(&$checks){$checks[$label]=(bool)$v;if(!$v)throw new RuntimeException('FAIL '.$label);};
 $r=new Registry();$ops=Setup_Provider::operations($r);$check(count($ops)===18,'Eighteen version-gated native setup contracts');
 foreach($ops as$id=>$op){$check(Schema::contract($op['inputSchema'])&&Schema::contract($op['outputSchema']),'Closed native contract '.$id);if($op['effect']==='write')$check(is_callable($op['prepare'])&&is_callable($op['current']),'Write has native review and preimage '.$id);}
 $r->register('mcp',1,static fn()=>['schema'=>'kiwe.mcp-provider.v1','api'=>1,'app'=>'mcp','setup'=>['state'=>'ready','needs'=>[]],'operations'=>$ops]);$scopes=$r->scopes();$pins=$r->freeze($scopes);$s=new Setup_Service($r);
 $read=$r->read('mcp.setup.read',['area'=>'story'],$scopes,$pins);$check(!is_wp_error($read)&&count($read['fields'])===2,'Only native public business field definitions are projected');
 $check(!str_contains(wp_json_encode($read),'founder.userId'),'Native account linkage is omitted from the AI field catalogue');
 $check($read['fields'][1]['path']==='about.futurePublicFact','Future public fields follow the native schema automatically');
 $starterScopes=['mcp.start.context'];
 $starterPins=$r->freeze($starterScopes);
 $check(array_column($r->describe($starterScopes,$starterPins),'id')===['mcp.start.context.read','mcp.start.context.change'],'Start scope grants only its two Design Context operations');
 $initial=$r->read('mcp.start.context.read',['area'=>'identity'],$starterScopes,$starterPins);
 $check(array_column($initial['fields'],'path')===['siteName'],'Start projects its approved native introductory fields, excluding other business settings');
 $check(is_wp_error($r->read('mcp.start.context.read',['area'=>'identity'],['mcp.site.connect'],$pins)),'Existing website proof grants cannot read Start Design Context');
 $identity=$s->read(['area'=>'identity']);$check($identity['fields'][1]['path']==='futureLabel','Identity fields come from their native owner, without a copied allowlist');
 $before=count($nativeCalls);$plan=$r->prepare_change('mcp.context.change',['area'=>'story','field'=>'about.mission','valueJson'=>'"Agreed mission"'],$scopes,$pins);$check(count($nativeCalls)===$before,'Native setup preparation does not call a writer');
 $result=$r->execute_prepared('mcp.context.change',['area'=>'story','field'=>'about.mission','valueJson'=>'"Agreed mission"'],$plan['nativeExpected'],$scopes,$pins);$check($mission==='Agreed mission'&&$nativeCalls[0]['owner']==='business','Agreed field applies through the native business writer');
 $check(json_decode($result['resultJson'],true)['saved']===true,'Native writer readback supplies the mutation receipt');
 foreach([['area'=>'story','field'=>'seo.apiKey','valueJson'=>'"secret"'],['area'=>'store','field'=>'commerce.currency','valueJson'=>'"USD"'],['area'=>'story','field'=>'about.founder.userId','valueJson'=>'77']]as$a){$n=count($nativeCalls);try{$r->prepare_change('mcp.context.change',$a,$scopes,$pins);$check(false,'Unsafe field denied');}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL'))throw$e;$check(count($nativeCalls)===$n,'Unknown/private/financial context field denied: '.$a['field']);}}
 $plan=$r->prepare_change('mcp.appshell.change',['group'=>'style','field'=>'sheet_position','valueJson'=>'"left"'],$scopes,$pins);$r->execute_prepared('mcp.appshell.change',['group'=>'style','field'=>'sheet_position','valueJson'=>'"left"'],$plan['nativeExpected'],$scopes,$pins);$check(end($nativeCalls)['owner']==='appshell','Dock/screen changes reuse the native AppShell writer');
 foreach([['group'=>'secure','field'=>'api_key','valueJson'=>'"x"'],['group'=>'style','field'=>'unknown','valueJson'=>'true']]as$a){$n=count($nativeCalls);try{$s->prepare('appshell',$a);$check(false,'Unknown App field denied');}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL'))throw$e;$check(count($nativeCalls)===$n,'AppShell denies unknown field '.$a['group'].'.'.$a['field']);}}
 foreach(['auth','security','mcp','unknown']as$app)$check(!Schema::value(['app'=>$app,'automatic'=>true],$ops['mcp.app.install']['inputSchema']),'Software install excludes unsupported/self/included App '.$app);
 $check(Schema::value(['record'=>['recordId'=>2000000,'title'=>'Large native ID','status'=>'draft']],$ops['mcp.service.save']['inputSchema']),'Native IDs are not limited by a schema budget constant');
 $check(!Schema::value(['record'=>['title'=>'x','meta'=>['password'=>'x']]],$ops['mcp.service.save']['inputSchema']),'Service input cannot smuggle arbitrary metadata');
 $args=['area'=>'identity','field'=>'siteName','value'=>'Agency storefront','expected'=>str_repeat('d',64)];
 $starterPlan=$r->prepare_change('mcp.start.context.change',$args,$starterScopes,$starterPins);
 $check($starterPlan['nativeExpected']===$args['expected'],'Introductory change uses the native expected identity preimage');
 foreach([array_replace($args,['field'=>'futureLabel']),array_replace($args,['expected'=>str_repeat('e',64)])]as$invalid){
  try{$r->prepare_change('mcp.start.context.change',$invalid,$starterScopes,$starterPins);$check(false,'Refuse unadmitted or stale Start field');}
  catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL'))throw$e;$check(true,'Refused Start attempt '.$invalid['field'].' '.$invalid['expected'][0]);}
 }
 $denyPost=true;$check(!Setup_Service::service_allowed(['record'=>['recordId'=>12]]),'Current native object denial overrides Service authoring');$denyPost=false;
 $method=new ReflectionMethod($s,'service_input');$new=$method->invoke($s,'service',['record'=>['title'=>'New service']]);$check(array_key_exists('stableId',$new['item'])&&is_string($new['item']['stableId']),'New Service defaults retain the native required string identity placeholder');
 $planMode=true;$new=$method->invoke($s,'service-plan',['record'=>['title'=>'New plan']]);$same=$method->invoke($s,'service-plan',['record'=>['title'=>'New plan']]);$check($new['item']['stableId']===$same['item']['stableId']&&strlen($new['item']['stableId'])<=80,'New plan identity stays deterministic across preparation and native application');
 $old=$method->invoke($s,'service-plan',['record'=>['stableId'=>'native-plan','title'=>'Changed plan']]);$check($old['expected']===str_repeat('b',64),'Existing Service plan uses its native record preimage instead of the list preimage');$check($s->read(['area'=>'services'])['fields'][0]['path']==='native-plan','Plan projection uses its stable native identity instead of zero');$planMode=false;
 $check(!Setup_Provider::supported_core('8.0.0-rc.478')&&Setup_Provider::supported_core('8.0.0-rc.479')&&Setup_Provider::supported_core('8.0.0'),'Setup requires the Core with job-owned installation recovery and retains future compatible Core');
 $role=false;$check($r->describe($scopes,$pins)===[],'Role revocation hides all native setup operations');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'kind'=>'source native-owner adapters and schemas; actual WordPress remains separate'])."\n";
}
