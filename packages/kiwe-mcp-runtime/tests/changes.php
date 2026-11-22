<?php
declare(strict_types=1);
namespace DSA\Apps {
 final class Runtime_Registry {public static function installed($id){return$id==='mcp';}public static function owner($id){return Owner::class;}}
 final class Owner {public static function enabled(){return$GLOBALS['enabled'];}}
}
namespace DSA\MCP {
 final class Auth_Context {public static function current(){return$GLOBALS['grantActive']&&($GLOBALS['bearerActive']??true)?$GLOBALS['grant']:null;}}
 final class Credentials {
  public static function actor_allowed(int $actor): bool {return $actor>0;}
  public static function actor_pin($actor){return'fixture-pin-'.$actor;}
 }
 final class Urls {public static function binding(){return$GLOBALS['site'];}public static function resource(){return'https://fixture.example/wp-json/kiwe-mcp/v1/mcp';}}
 final class Records {
  public static array $rows=[];public static function table(){return'fixture_mcp_records';}
  public static function get($kind,$id,$lock=false){return self::$rows[$kind][$id]??null;}
  public static function put($kind,$id,$data,$expires,$user=0,$client=''){if(strlen(json_encode($data))>32768)throw new \RuntimeException('Record budget');self::$rows[$kind][$id]=['id'=>$id,'userId'=>$user,'expiresAt'=>$expires,'data'=>$data];}
  public static function count($kind,$user=null){return count(array_filter(self::$rows[$kind]??[],static fn($r)=>$r['expiresAt']>time()&&($user===null||$r['userId']===$user)));}
  public static function purge(){}public static function atomic($work){$before=self::$rows;try{return$work();}catch(\Throwable $e){self::$rows=$before;throw$e;}}
 }
 final class Authorization_Service {public function native_review_grant($id,$actor){$g=$GLOBALS['grant'];if(!$GLOBALS['grantActive']||$g['id']!==$id||$g['actor']!==$actor||$actor!==\get_current_user_id())throw new \RuntimeException('Native actor denied');return$g;}}
}
namespace {
 define('ABSPATH',__DIR__.'/');$enabled=true;$site='fixture-site';$actor=1;$grantActive=true;$nativeAllowed=true;$objectExists=true;$nativeValue='original';$writes=0;$approval=false;$throwAfterWrite=false;$lockAvailable=true;
 function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}function is_wp_error($v){return$v instanceof WP_Error;}class WP_Error{public function __construct(public $code,public $message=''){}public function get_error_message(){return$this->message;}}
 function get_current_user_id(){return$GLOBALS['actor'];}function admin_url($p=''){return'https://fixture.example/wp-admin/'.$p;}function add_query_arg($args,$url){return$url.'?'.http_build_query($args);}
 function stp_cfg(){return['adaptive_waf'=>1,'waf_block_score'=>80];}function stp_enforcement_paused(){return false;}
 class WP_REST_Request {public function __construct(private string $body){}public function get_body(){return$this->body;}}
 class Database{public function prepare($sql,...$args){return$sql;}public function get_var($sql){return str_contains($sql,'RELEASE_LOCK')||$GLOBALS['lockAvailable']?'1':'0';}}$wpdb=new Database();
 $root=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';foreach(['Apps/Runtime_Contract','Apps/MCP_Provider_Contract','Apps/Signed_Packet','MCP/Schema','MCP/Operation_Registry','MCP/Unconfirmed_Write','MCP/Intent_Service']as$f)require$root.$f.'.php';
 use DSA\MCP\{Operation_Registry as Registry,Intent_Service as Intents,Records,Schema};
 $checks=[];$check=static function($ok,$label)use(&$checks){$checks[$label]=(bool)$ok;if(!$ok)throw new RuntimeException('FAIL '.$label);};
 $denied=static function(callable $call,string $label)use($check){$before=$GLOBALS['writes'];try{$call();$check(false,$label);}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL '))throw$e;$check($GLOBALS['writes']===$before,$label);}};
 $object=static fn($p,$r=[])=>['type'=>'object','properties'=>$p,'required'=>$r,'additionalProperties'=>false];$input=$object(['value'=>['type'=>'string','maxLength'=>200]],['value']);$output=$object(['saved'=>['type'=>'boolean'],'value'=>['type'=>'string','maxLength'=>200]],['saved','value']);
 $read=['scope'=>'mcp.read','label'=>'Read fixture','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>$object([]),'outputSchema'=>$object([]),'discoverPermission'=>static fn()=>true,'permission'=>static fn()=>true,'execute'=>static fn()=>[]];
 $write=['scope'=>'mcp.setup.write','label'=>'Change native fixture','effect'=>'write','approval'=>'none','accessVersion'=>1,'inputSchema'=>$input,'outputSchema'=>$output,'discoverPermission'=>static fn()=>$GLOBALS['nativeAllowed'],'permission'=>static fn()=>$GLOBALS['nativeAllowed']&&$GLOBALS['objectExists'],
  'prepare'=>static fn($a)=>['nativeExpected'=>hash('sha256',$GLOBALS['nativeValue']),'summary'=>'Update fixture','before'=>$GLOBALS['nativeValue'],'after'=>$a['value'],'needsApproval'=>$GLOBALS['approval']],
  'current'=>static fn($a)=>hash('sha256',$GLOBALS['nativeValue']),
  'execute'=>static function($a,$expected){$GLOBALS['writes']++;$GLOBALS['nativeValue']=$a['value'];if($GLOBALS['throwAfterWrite'])throw new RuntimeException('Synthetic interruption after native effect');return['saved'=>true,'value'=>$GLOBALS['nativeValue']];}];
 function registry_fixture($read,$write){$r=new Registry();$r->register('mcp',1,static fn()=>['schema'=>'kiwe.mcp-provider.v1','api'=>1,'app'=>'mcp','setup'=>['state'=>'ready','needs'=>[]],'operations'=>['mcp.connection.read'=>$read,'mcp.fixture.change'=>$write]]);return$r;}
 $r=registry_fixture($read,$write);$scopes=$r->scopes();$pins=$r->freeze($scopes);$grant=['id'=>'g_'.str_repeat('1',32),'actor'=>1,'client'=>'c_fixture','scopes'=>$scopes,'pins'=>$pins];$original=$grant;$intents=new Intents($r);
 $check(count($r->describe($scopes,$pins))===2,'Implemented native read/write contracts discover separately');
 $oldPins=$r->freeze(['mcp.read']);$check(count($r->describe($scopes,$oldPins))===1,'Old operation pins do not expand when a scope is supplied');
 $check(is_wp_error($r->read('mcp.fixture.change',['value'=>'bad'],$scopes,$pins))&&$writes===0,'Read transport cannot execute a native write');
 $denied(fn()=>$intents->prepare('mcp.fixture.change',['value'=>'bad','approved'=>true],str_repeat('a',16)),'Client approval property is rejected by the closed schema');
 $denied(fn()=>$intents->prepare('mcp.fixture.change',['value'=>'bad'],'short'),'Request identifiers are bounded deduplication hints');
 $p=$intents->prepare('mcp.fixture.change',['value'=>'one'],str_repeat('a',16));$check($p['state']==='prepared'&&$writes===0,'Preparation has no native business effect');
 $check($intents->prepare('mcp.fixture.change',['value'=>'one'],str_repeat('a',16))['id']===$p['id'],'Identical prepare returns the same intent');
 $denied(fn()=>$intents->prepare('mcp.fixture.change',['value'=>'different'],str_repeat('a',16)),'Idempotency key cannot change its inputs');
 $done=$intents->apply($p['id']);$check($done['state']==='complete'&&$nativeValue==='one'&&$writes===1,'Native writer executes once and supplies a receipt');
 $check($intents->apply($p['id'])===$done&&$writes===1,'Completed application returns its receipt without another write');
 foreach(['actor','client','grant','site','pin','scope','role','disabled','revoked']as$case){$saved=$grant;$savedSite=$site;
  if($case==='actor')$grant['actor']=2;if($case==='client')$grant['client']='other';if($case==='grant')$grant['id']='g_'.str_repeat('2',32);if($case==='site')$site='other-site';if($case==='pin')$grant['pins']['mcp.fixture.change']=str_repeat('0',64);if($case==='scope')$grant['scopes']=['mcp.read'];if($case==='role')$nativeAllowed=false;if($case==='disabled')$enabled=false;if($case==='revoked')$grantActive=false;
  $denied(fn()=>$intents->status($p['id']),'Private intent denies changed '.$case);$grant=$saved;$site=$savedSite;$nativeAllowed=$enabled=$grantActive=true;
 }
 $approval=true;$p=$intents->prepare('mcp.fixture.change',['value'=>'approved'],str_repeat('b',16));$check($p['approvalRequired']&&$p['approvalUrl']!==''&&$writes===1,'Consequential plan provides a native review link');
 $denied(fn()=>$intents->apply($p['id']),'Missing native approval cannot be replaced by a prepared ID');
 $actor=2;$denied(fn()=>$intents->approve($p['id'],new DSA\MCP\Authorization_Service(),true),'Another native actor cannot approve this grant');$actor=1;
 $intents->approve($p['id'],new DSA\MCP\Authorization_Service(),true);$check($intents->apply($p['id'])['state']==='complete'&&$nativeValue==='approved','Exact same-actor native approval admits the original proposal');
 $p=$intents->prepare('mcp.fixture.change',['value'=>'cancelled'],str_repeat('c',16));$intents->approve($p['id'],new DSA\MCP\Authorization_Service(),false);$before=$writes;$check($intents->apply($p['id'])['state']==='cancelled'&&$writes===$before,'Cancelled proposal never reaches its native writer');
 $approval=false;$p=$intents->prepare('mcp.fixture.change',['value'=>'stale'],str_repeat('d',16));$nativeValue='changed elsewhere';$denied(fn()=>$intents->apply($p['id']),'Stale native preimage refuses application');
 $p=$intents->prepare('mcp.fixture.change',['value'=>'late'],str_repeat('e',16));Records::$rows['intent'][$p['id']]['data']['reviewUntil']=time()-1;$denied(fn()=>$intents->apply($p['id']),'Expired review refuses application');
 $p=$intents->prepare('mcp.fixture.change',['value'=>'once despite interruption'],str_repeat('f',16));$throwAfterWrite=true;$before=$writes;$r1=$intents->apply($p['id']);$check($r1['state']==='needs-review'&&$writes===$before+1,'Interrupted native effect remains unconfirmed rather than reported successful');$throwAfterWrite=false;$check($intents->apply($p['id'])['state']==='needs-review'&&$writes===$before+1,'Unconfirmed native effect is never blindly repeated');
 $partial=$write;$partial['execute']=static function($a,$expected){$GLOBALS['writes']++;throw new DSA\MCP\Unconfirmed_Write('page',77,new RuntimeException('Private native failure details'));};$partialRegistry=registry_fixture($read,$partial);$savedGrant=$grant;$grant['pins']=$partialRegistry->freeze($scopes);$partialIntents=new Intents($partialRegistry);$p=$partialIntents->prepare('mcp.fixture.change',['value'=>'partial Page'],str_repeat('h',16));$before=$writes;$receipt=$partialIntents->apply($p['id']);$check($receipt['state']==='needs-review'&&json_decode($receipt['result']['resultJson'],true)===['type'=>'page','id'=>77,'confirmed'=>false]&&str_contains($receipt['message'],'Page draft 77'),'Unconfirmed Page identity survives the actual intent receipt');$check(!str_contains(wp_json_encode($receipt),'Private native failure details'),'Partial-save receipt exposes no internal native failure details');$check($partialIntents->apply($p['id'])===$receipt&&$writes===$before+1,'A partial Page receipt never repeats its native creation');$grant=$savedGrant;
 $p=$intents->prepare('mcp.fixture.change',['value'=>'running'],str_repeat('g',16));Records::$rows['intent'][$p['id']]['data']['state']='running';$before=$writes;$check($intents->apply($p['id'])['state']==='running'&&$writes===$before,'A killed running action returns readback guidance without executing');
 $lockAvailable=false;$denied(fn()=>$intents->apply($p['id']),'Concurrent application cannot acquire a busy native lock');$lockAvailable=true;
 $required=$write;$required['approval']='required';$required['prepare']=static fn($a)=>['nativeExpected'=>hash('sha256',$GLOBALS['nativeValue']),'summary'=>'Required','before'=>'','after'=>'','needsApproval'=>false];$rr=registry_fixture($read,$required);$plan=$rr->prepare_change('mcp.fixture.change',['value'=>'x'],$rr->scopes(),$rr->freeze($rr->scopes()));$check($plan['needsApproval'],'Provider plan cannot lower the declared approval requirement');
 $bad=$write;unset($bad['prepare']);$br=registry_fixture($read,$bad);$check($br->describe(['mcp.read','mcp.setup.write'])===[],'Unimplemented write contract fails closed at provider admission');
 $check(!str_contains(wp_json_encode($done),'fixture-pin'),'Private binding and actor pin are omitted from receipts');
 // Actual late body inspection: no native auth or PHP/SQL bypass is inferred.
 $core=file_get_contents($root.'Secure/securetrack-core.php');$from=strpos($core,'function stp_waf_normalize(');$to=strpos($core,'function stp_request_payload(',$from);eval(substr($core,$from,$to-$from));
 require$root.'Secure/SecureTrack_Payload_Service.php';require$root.'MCP/Engine.php';$engine=(new ReflectionClass(DSA\MCP\Engine::class))->newInstanceWithoutConstructor();(new ReflectionProperty($engine,'registry'))->setValue($engine,$r);$inspect=new ReflectionMethod($engine,'inspect_body');
 $rpc=['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'kiwe_mcp_prepare_change','arguments'=>['operation'=>'mcp.fixture.change','arguments'=>['value'=>'UNION SELECT examples and system($safe_input) documentation'],'requestId'=>str_repeat('z',16)]]];
 $check($inspect->invoke($engine,new WP_REST_Request(wp_json_encode($rpc)),$grant),'Only an admitted typed MCP authoring argument is data after native authentication');
 $narrow=$grant;$narrow['scopes']=['mcp.read'];$check(!$inspect->invoke($engine,new WP_REST_Request(wp_json_encode($rpc)),$narrow),'A read-only grant cannot claim the authoring-body boundary');
 $badRpc=$rpc;$badRpc['params']['arguments']['operation']='mcp.unknown';$check(!$inspect->invoke($engine,new WP_REST_Request(wp_json_encode($badRpc)),$grant),'Unknown MCP operation retains attack screening');
 $badRpc=$rpc;$badRpc['params']['arguments']['arguments']['approved']=true;$check(!$inspect->invoke($engine,new WP_REST_Request(wp_json_encode($badRpc)),$grant),'Malformed operation input retains attack screening');
 $badRpc=$rpc;$badRpc['extra']='UNION SELECT password FROM accounts';$check(!$inspect->invoke($engine,new WP_REST_Request(wp_json_encode($badRpc)),$grant),'Extra RPC envelope fields retain full screening');
 $nativeAllowed=false;$check(!$inspect->invoke($engine,new WP_REST_Request(wp_json_encode($rpc)),$grant),'Current native role denial removes authored-data admission');$nativeAllowed=true;
 // Native browser reviews have a WordPress session, never the AI's bearer.
 // Their current-state callback may need to validate an earlier grant-bound intent.
 $browserWrite=$write;$approval=true;$throwCurrent=false;
 $browserWrite['current']=static function($a)use($r,$done){
  (new Intents($r))->status($done['id']);
  if($GLOBALS['throwCurrent'])throw new RuntimeException('Synthetic native state read failure');
  return hash('sha256',$GLOBALS['nativeValue']);
 };
 $browserRegistry=registry_fixture($read,$browserWrite);$browserIntents=new Intents($browserRegistry);
 $priorGrant=$grant;$grant['pins']=$browserRegistry->freeze($scopes);
 $proposal=$browserIntents->prepare('mcp.fixture.change',['value'=>'native browser review'],str_repeat('n',16));
 $bearerActive=false;$before=$writes;
 $check(DSA\MCP\Auth_Context::current()===null,'Native browser review starts without delegated bearer authentication');
 $view=$browserIntents->native_review($proposal['id'],new DSA\MCP\Authorization_Service());
 $check($view['review']['id']===$proposal['id']&&$writes===$before,'A native review validates its original intent without a bearer or a write');
 $denied(fn()=>$browserIntents->status($proposal['id']),'Native review context does not escape into a later request operation');
 $throwCurrent=true;$denied(fn()=>$browserIntents->native_review($proposal['id'],new DSA\MCP\Authorization_Service()),'A failing native state callback retains refusal');$throwCurrent=false;
 $denied(fn()=>$browserIntents->status($proposal['id']),'A failed native review also clears its temporary origin context');
 $actor=2;$denied(fn()=>$browserIntents->native_review($proposal['id'],new DSA\MCP\Authorization_Service()),'Native review still refuses a different signed-in actor');$actor=1;
 $grantActive=false;$denied(fn()=>$browserIntents->native_review($proposal['id'],new DSA\MCP\Authorization_Service()),'Native review still refuses a revoked connection');$grantActive=true;$bearerActive=true;$grant=$priorGrant;

 // A completed review is an owner receipt, even when its connection has ended.
 $approval = true;
 $batch = [
  $intents->prepare('mcp.fixture.change', ['value'=>'first reviewed change'], 'closed-receipt-fixture-a')['id'],
  $intents->prepare('mcp.fixture.change', ['value'=>'second reviewed change'], 'closed-receipt-fixture-b')['id'],
 ];
 $check($intents->native_batch_receipt($batch) === null, 'An unapproved group has no owner receipt');
 $batchBinding = Records::$rows['intent'][$batch[0]]['data']['binding'];
 $batchPins = array_map(static fn($id)=>[$id, Records::$rows['intent'][$id]['data']['planDigest']], $batch);
 $batchDigest = hash('sha256', DSA\Apps\Signed_Packet::canonical([$batchBinding, $batchPins]));
 foreach ($batchPins as [$id, $planDigest]) {
  Records::$rows['intent'][$id]['data']['approval'] = ['actor'=>1, 'at'=>time(), 'digest'=>$planDigest, 'batch'=>$batchDigest];
 }
 Records::$rows['intent'][$batch[0]]['data']['state'] = 'complete';
 $check($intents->native_batch_receipt($batch)['state'] === 'approved', 'A partly saved group shows its recorded approval');
 Records::$rows['intent'][$batch[1]]['data']['state'] = 'needs-review';
 $check($intents->native_batch_receipt($batch)['state'] === 'needs-check', 'A group with an unconfirmed write does not claim completion');
 Records::$rows['intent'][$batch[1]]['data']['state'] = 'complete';
 $grantActive = false;
 $receiptRows = Records::$rows;
 $check($intents->native_batch_receipt($batch) === ['digest'=>$batchDigest, 'state'=>'complete'] &&
  Records::$rows === $receiptRows && DSA\MCP\Auth_Context::current() === null,
  'The owner can read the exact completion receipt after revocation without restoring authority or writing records');
 $check($intents->native_batch_receipt(array_reverse($batch)) === null, 'A reordered group cannot show the original completion receipt');
 $actor = 2;
 $check($intents->native_batch_receipt($batch) === null, 'Another signed-in actor cannot read the completed group');
 $actor = 1;
 $site = 'another-fixture-site';
 $check($intents->native_batch_receipt($batch) === null, 'The completed group remains bound to its original website');
 $site = 'fixture-site';

 echo json_encode(['passed'=>true,'checks'=>count($checks),'kind'=>'source-level intent admission/idempotency; native SQL and browser nonce remain separate'])."\n";
}
