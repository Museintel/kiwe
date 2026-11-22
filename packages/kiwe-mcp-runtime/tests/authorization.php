<?php
/** Source-level protocol/permission tests. In-memory records are not native SQL evidence. */
namespace DSA\Apps {
 final class MCP_App_Service { public static bool $on=true;public static function enabled(){return self::$on;} }
 final class Runtime_Registry { public static array $active=['mcp'=>true,'google'=>true,'meta'=>true];public static string $checking='';public static function installed($id){return isset(self::$active[$id]);}public static function owner($id){self::$checking=$id;return Registry_Owner::class;} }
 final class Registry_Owner { public static function enabled(){return Runtime_Registry::$active[Runtime_Registry::$checking]??false;} }
}
namespace DSA\Access { final class Team_Workspace_Service {public static bool $suspended=false;public static function suspended($id){return self::$suspended;}} }
namespace DSA\MCP {
 final class Records {
  public static array $rows=[];private static bool $busy=false;
  public static function ready(){return true;}public static function get($kind,$id,$lock=false){return self::$rows[$kind][$id]??null;}
  public static function put($kind,$id,$data,$expires,$user=0,$client=''){self::$rows[$kind][$id]=['id'=>$id,'kind'=>$kind,'data'=>$data,'expiresAt'=>$expires,'userId'=>$user,'lastUsedAt'=>0];}
  public static function delete($kind,$id){unset(self::$rows[$kind][$id]);}
  public static function count($kind,$user=null){return count(array_filter(self::$rows[$kind]??[],fn($r)=>$r['expiresAt']>time()&&($user===null||$r['userId']===$user)));}
  public static function purge(){}
 public static function atomic($fn,$wait=false){if(self::$busy)throw new \RuntimeException('Busy');$before=self::$rows;self::$busy=true;try{return$fn();}catch(\Throwable $e){self::$rows=$before;throw$e;}finally{self::$busy=false;}}
 }
}
namespace {
 define('ABSPATH',__DIR__);$actor=1;$options=['kiwe_mcp_revocation_epoch_v1'=>'source-fixture'];$passwords=[1=>'native-test-hash',2=>'editor-test-hash',3=>'subscriber-test-hash'];$caps=[1=>['kiwe_manage_context'],2=>['edit_posts'],3=>['read']];
 function get_option($key,$default=null){global$options;return$options[$key]??$default;}function home_url($path='/'){return'https://fixture.example'.$path;}function rest_url($path){return'https://fixture.example/wp-json/'.$path;}
 function untrailingslashit($s){return rtrim($s,'/');}function wp_parse_url($s,$part=-1){return parse_url($s,$part);}function wp_salt($key){return'not-a-production-key-'.$key;}
 function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}function is_wp_error($v){return$v instanceof WP_Error;}function is_multisite(){return false;}
 function get_userdata($id){global$passwords;return isset($passwords[$id])?(object)['user_pass'=>$passwords[$id]]:false;}function user_can($id,$cap){global$caps;return in_array($cap,$caps[$id]??[],true);}function get_current_user_id(){global$actor;return$actor;}function wp_set_current_user($id){global$actor;$actor=$id;}
 function wp_strip_all_tags($s){return strip_tags($s);}class WP_Error{public function __construct(public $code,public $message=''){} }
 $root=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';require$root.'Apps/Runtime_Contract.php';require$root.'Apps/MCP_Provider_Contract.php';
 foreach(['Schema','Urls','Credentials','Operation_Registry','OAuth_Failure','Authorization_Service']as$class)require$root.'MCP/'.$class.'.php';
 $checks=0;function check($condition,$label){global$checks;$checks++;if(!$condition)throw new RuntimeException('FAIL '.$label);}
 function denied($fn,$reason=null){try{$fn();}catch(\DSA\MCP\OAuth_Failure $e){return$reason===null||$reason===$e->reason;}return false;}
 use DSA\MCP\{Authorization_Service as Auth,Credentials as Secret,Operation_Registry as Registry,Records,Schema,Urls};
 $empty=['type'=>'object','properties'=>[],'additionalProperties'=>false];$output=['type'=>'object','properties'=>['ready'=>['type'=>'boolean']],'required'=>['ready'],'additionalProperties'=>false];
 $revision=1;$extra=false;$effect='read';$permitted=true;$badOutput=false;
 $registryFactory=function()use(&$revision,&$extra,&$effect,&$permitted,&$badOutput,$empty,$output){
  $registry=new Registry();$registry->register('mcp',1,function()use(&$revision,&$extra,&$effect,&$permitted,&$badOutput,$empty,$output){
   $op=['scope'=>'mcp.read','label'=>'Synthetic permitted guidance','effect'=>$effect,'approval'=>'none','accessVersion'=>$revision,'inputSchema'=>$empty,'outputSchema'=>$output,
    'discoverPermission'=>function()use(&$permitted){return get_current_user_id()>0&&$permitted;},'permission'=>function($args)use(&$permitted){return get_current_user_id()>0&&$permitted;},'execute'=>function($args)use(&$badOutput){return $badOutput?['ready'=>true,'secret'=>'must be rejected']:['ready'=>true];}];
   return['schema'=>'kiwe.mcp-provider.v1','api'=>1,'app'=>'mcp','setup'=>['state'=>'ready','needs'=>[]],'operations'=>['mcp.test.read'=>$op]+($extra?['mcp.new.read'=>$op]:[])];
  });return$registry;
 };
 $registry=$registryFactory();$auth=new Auth($registry);$verifier=Secret::secret();$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
 check(Secret::pkce($verifier,$challenge),'S256 verifier matches');check(!Secret::pkce(str_repeat('a',42),$challenge),'Short verifier rejected');check(!Secret::pkce(str_repeat('a',129),$challenge),'Long verifier rejected');check(!Secret::pkce($verifier,str_repeat('a',43)),'Mismatched verifier rejected');
 foreach(['https://app.example/callback','http://127.0.0.1:8123/callback','http://localhost/callback','http://[::1]/callback']as$uri)check(Urls::valid_redirect($uri),'Supported registered redirect '.$uri);
 foreach(['http://remote.example/callback','javascript:alert(1)','https://user:pw@app.example/cb','https://app.example/cb#fragment','https://app.example/%0d%0aHost:bad','https://app.example\\@evil.example/cb',"https://app.example/\n"]as$uri)check(!Urls::valid_redirect($uri),'Unsafe redirect rejected');
 check(Urls::redirect_matches('http://127.0.0.1:12345/callback',['http://127.0.0.1/callback']),'Native ephemeral callback port');
 check(!Urls::redirect_matches('http://localhost:12345/callback',['http://127.0.0.1/callback']),'Loopback host never substituted');
 check(!Urls::redirect_matches('https://app.example/cb?other=1',['https://app.example/cb']),'Callback query never widened');
 check(Schema::contract($empty)&&Schema::contract($output),'Closed typed schemas');check(!Schema::contract(['type'=>'object','properties'=>[]]),'Open schema rejected');
 check(Schema::value([], $empty)&&!Schema::value(['unexpected'=>true],$empty),'Extra operation inputs rejected');check(!Schema::value(['ready'=>true,'secret'=>'x'],$output),'Extra private output rejected');
 check(!$registry->register('mcp',1,fn()=>[]),'Duplicate provider denied');check(!$registry->register('unknown',1,fn()=>[]),'Uninstalled provider denied');check(!$registry->register('meta',2,fn()=>[]),'Unsupported provider API denied');
 $client=$auth->register_client(['client_name'=>'Synthetic AI','redirect_uris'=>['http://127.0.0.1/callback']]);$clientId=$client['client_id'];
 check(isset($clientId)&&!isset($client['client_secret']),'Public PKCE client, no shared secret');
 foreach([['redirect_uris'=>['http://evil.example/cb']],['redirect_uris'=>[]],['redirect_uris'=>['https://app.example/cb'],'token_endpoint_auth_method'=>'client_secret_post'],['redirect_uris'=>['https://app.example/cb'],'grant_types'=>'authorization_code']]as$v)check(denied(fn()=>$auth->register_client($v)),'Invalid client registration denied');
 $base=['client_id'=>$clientId,'redirect_uri'=>'http://127.0.0.1:8789/callback','response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>$challenge,'scope'=>'mcp.read offline_access','state'=>'client-state','resource'=>Urls::resource()];
 foreach(['resource'=>'https://another.example/mcp','scope'=>'mcp.read google.ads.spend','code_challenge_method'=>'plain','redirect_uri'=>'http://127.0.0.1:8789/evil','state'=>'']as$key=>$value)check(denied(fn()=>$auth->begin(array_replace($base,[$key=>$value]),Secret::secret())),'Authorization rejects changed '.$key);
 $browser=Secret::secret();$flow=$auth->begin($base,$browser);
 check(denied(fn()=>$auth->review($flow,Secret::secret(),1)),'Another browser cannot claim consent');
 wp_set_current_user(3);check(denied(fn()=>$auth->review($flow,$browser,3)),'Subscriber cannot link administrative MCP');wp_set_current_user(1);
 $view=$auth->review($flow,$browser,1);check(count($view['operations'])===1,'Consent contains currently permitted read operation');
 wp_set_current_user(2);check(denied(fn()=>$auth->approve($flow,$browser,2,true,true)),'Another native actor cannot approve the claimed flow');wp_set_current_user(1);
 check(denied(fn()=>$auth->approve($flow,$browser,1,true,true,str_repeat('a',64))),'A different native review snapshot cannot authorize this consent');
 $permitted=false;check(denied(fn()=>$auth->approve($flow,$browser,1,true,true,$view['reviewDigest'])),'A changed operation list requires a fresh native review');$permitted=true;
 $accepted=$auth->approve($flow,$browser,1,true,true,$view['reviewDigest']);check($accepted['state']==='client-state'&&isset($accepted['code']),'Approved native flow returns client state and one-use code');
 $repeated=$auth->approve($flow,$browser,1,true,true,$view['reviewDigest']);
 check($repeated===$accepted&&Records::count('code',1)===1,'An exact repeated native submission only returns the original unredeemed code');
 check(!str_contains(json_encode(Records::$rows),$accepted['code'])&&!str_contains(json_encode(Records::$rows),$browser),'No raw code or browser credential is persisted for duplicate handling');
 check(denied(fn()=>$auth->approve($flow,Secret::secret(),1,true,true,$view['reviewDigest'])),'A second browser cannot recover the original code');
 check(denied(fn()=>$auth->approve($flow,$browser,1,true,false,$view['reviewDigest']))&&denied(fn()=>$auth->approve($flow,$browser,1,false,false,$view['reviewDigest'])),'A repeat cannot change the approved duration or decision');
 $exchange=['grant_type'=>'authorization_code','client_id'=>$clientId,'code'=>$accepted['code'],'redirect_uri'=>$base['redirect_uri'],'resource'=>Urls::resource(),'code_verifier'=>$verifier];
 foreach(['resource'=>'https://other.example/mcp','redirect_uri'=>'http://127.0.0.1:1234/callback','code_verifier'=>Secret::secret()]as$key=>$value)check(denied(fn()=>$auth->exchange(array_replace($exchange,[$key=>$value]))),'Code exchange rejects changed '.$key);
 wp_set_current_user(0);$tokens=$auth->exchange($exchange);check(isset($tokens['access_token'],$tokens['refresh_token'])&&$tokens['expires_in']===900,'Code exchanges without native cookies after exact proof');
 check(denied(fn()=>$auth->exchange($exchange),'invalid_grant'),'Authorization code is one use');
 wp_set_current_user(1);$sent=$auth->approve($flow,$browser,1,true,true,$view['reviewDigest']);
 check(($sent['alreadySent']??false)&&!isset($sent['code'])&&Records::count('grant',1)===1&&$auth->review($flow,$browser,1)['decision']==='allow-offline','A repeat after redemption shows completion without issuing credentials');wp_set_current_user(0);
 $context=$auth->authenticate($tokens['access_token']);check($context['actor']===1&&$context['client']===$clientId,'Resource token retains actor/client binding');
 check(!str_contains(json_encode(Records::$rows),$tokens['access_token'])&&!str_contains(json_encode(Records::$rows),$tokens['refresh_token']),'Database retains no raw access/refresh credential');
 check(denied(fn()=>$auth->authenticate($tokens['refresh_token']),'invalid_token'),'Refresh is not access authority');
 wp_set_current_user(1);$read=$registry->read('mcp.test.read',[],$context['scopes'],$context['pins']);check($read===['ready'=>true],'Admitted read uses current native permission');
 $badOutput=true;check(is_wp_error($registry->read('mcp.test.read',[],$context['scopes'],$context['pins'])),'Private output is withheld');$badOutput=false;
 $permitted=false;check(is_wp_error($registry->read('mcp.test.read',[],$context['scopes'],$context['pins'])),'Removed native permission immediately denies operation');$permitted=true;
 $extra=true;$newRegistry=$registryFactory();check(count($newRegistry->describe($context['scopes'],$context['pins']))===1,'New operation never inherits old consent');
 $revision=2;$changed=$registryFactory();check(!$changed->describe($context['scopes'],$context['pins']),'Changed access version needs new consent');$revision=1;$extra=false;
 $effect='write';$write=$registryFactory();check(!$write->scopes()&&!$write->describe(['mcp.read'])&&is_wp_error($write->read('mcp.test.read',[],['mcp.read'],$context['pins'])),'First transport never grants or executes future writes');$effect='read';
 $future=new Registry();$futureCalls=0;
 foreach(['google','meta']as$app){check($future->register($app,1,function()use($app,&$futureCalls,$empty){$futureCalls++;return['schema'=>'kiwe.mcp-provider.v1','api'=>1,'app'=>$app,'setup'=>['state'=>'needs-business-facts','needs'=>['designContext.business.locations'],'providerConsentRequired'=>true],
  'operations'=>[$app.'.setup.read'=>['scope'=>$app.'.setup.read','label'=>'Synthetic future App setup guidance','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>$empty,
   'outputSchema'=>['type'=>'object','properties'=>['connected'=>['type'=>'boolean']],'required'=>['connected'],'additionalProperties'=>false],
   'discoverPermission'=>fn()=>user_can(get_current_user_id(),'kiwe_manage_context'),'permission'=>fn($args)=>user_can(get_current_user_id(),'kiwe_manage_context'),'execute'=>fn($args)=>['connected'=>false]]]];
 }),'Trusted installed future provider registers without an MCP channel switch');}
 check($futureCalls===0,'Future provider factories do not run at registration');$futureScopes=$future->scopes();check(count($futureScopes)===2&&$futureCalls===2,'Explicit discovery resolves bounded future providers lazily');
 check(!$future->describe(['mcp.read']),'A connection without future App scopes sees none of their operations');$futurePins=$future->freeze($futureScopes);
 check(count($future->setup($futureScopes,$futurePins))===2&&$future->setup($futureScopes,$futurePins)[0]['providerConsentRequired'],'Provider handoff/Design Context needs are explicit, without copying credentials');
 check($future->read('google.setup.read',[],$futureScopes,$futurePins)===['connected'=>false],'Permitted future setup read does not connect an external account');
 \DSA\Apps\Runtime_Registry::$active['google']=false;check(is_wp_error($future->read('google.setup.read',[],$futureScopes,$futurePins))&&count($future->setup($futureScopes,$futurePins))===1,'App Off removes future operations and setup guidance');\DSA\Apps\Runtime_Registry::$active['google']=true;
 $refresh=['grant_type'=>'refresh_token','client_id'=>$clientId,'resource'=>Urls::resource(),'refresh_token'=>$tokens['refresh_token']];
 $forged=Secret::parse($tokens['refresh_token'],'refresh');$forgedToken=Secret::token('refresh',$forged['id'],Secret::secret());
 check(denied(fn()=>$auth->exchange(array_replace($refresh,['refresh_token'=>$forgedToken])),'invalid_grant'),'Forged refresh rejected');check($auth->authenticate($tokens['access_token'])['actor']===1,'Forged secret cannot revoke legitimate connection');
 $rotated=$auth->exchange($refresh);check($rotated['refresh_token']!==$tokens['refresh_token'],'Refresh rotates credentials');check(denied(fn()=>$auth->authenticate($tokens['access_token'])),'Rotation invalidates prior access secret');
 check(denied(fn()=>$auth->exchange($refresh),'invalid_grant'),'Recognized consumed refresh replay rejected');check(denied(fn()=>$auth->authenticate($rotated['access_token'])),'Recognized refresh replay retires the grant');
 function connect($auth,$base,$browser,$verifier){wp_set_current_user(1);$f=$auth->begin($base,$browser);$auth->review($f,$browser,1);$c=$auth->approve($f,$browser,1,true,true);return$auth->exchange(['grant_type'=>'authorization_code','client_id'=>$base['client_id'],'code'=>$c['code'],'redirect_uri'=>$base['redirect_uri'],'resource'=>Urls::resource(),'code_verifier'=>$verifier]);}
 $t=connect($auth,$base,$browser,$verifier);$caps[1]=['read'];check(denied(fn()=>$auth->authenticate($t['access_token'])),'Native role downgrade revokes resource access');$caps[1]=['kiwe_manage_context'];
 \DSA\Access\Team_Workspace_Service::$suspended=true;check(denied(fn()=>$auth->authenticate($t['access_token'])),'Native suspension revokes resource access');\DSA\Access\Team_Workspace_Service::$suspended=false;
 $passwords[1]='changed-native-test-hash';check(denied(fn()=>$auth->authenticate($t['access_token'])),'Password reset retires old authority');$passwords[1]='native-test-hash';
 $options['kiwe_mcp_revocation_epoch_v1']='next-epoch';check(denied(fn()=>$auth->authenticate($t['access_token'])),'Off/re-enable epoch cannot revive old tokens');$options['kiwe_mcp_revocation_epoch_v1']='source-fixture';
 \DSA\Apps\MCP_App_Service::$on=false;check(denied(fn()=>$auth->authenticate($t['access_token'])),'App Off denies existing tokens');\DSA\Apps\MCP_App_Service::$on=true;
 $id=Secret::parse($t['access_token'],'access')['id'];wp_set_current_user(2);check(denied(fn()=>$auth->revoke_own($id,2)),'Another editor cannot revoke an owner connection');wp_set_current_user(1);$auth->revoke_own($id,1);check(denied(fn()=>$auth->authenticate($t['access_token'])),'Native own disconnect immediately invalidates token');
 $f=$auth->begin($base,$browser);$auth->review($f,$browser,1);$cancel=$auth->approve($f,$browser,1,false,false);check(($cancel['error']??'')==='access_denied'&&!isset($cancel['code']),'Cancel returns no code');
 check($auth->approve($f,$browser,1,false,false)===$cancel&&denied(fn()=>$auth->approve($f,$browser,1,true,true)),'Cancellation remains the exact decision on repeat');
 echo json_encode(['passed'=>true,'checks'=>$checks,'kind'=>'source-level authorization and provider contracts','nativeSql'=>false,'actualClient'=>false],JSON_PRETTY_PRINT).PHP_EOL;
}
