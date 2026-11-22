<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

use DSA\Apps\MCP_App_Service;

/** Site-local OAuth authorization-code/PKCE, with resource-only opaque credentials. */
final class Authorization_Service {
 public const ACCESS_TTL=900, REFRESH_TTL=1209600, FLOW_TTL=600, CODE_TTL=180;
 public function __construct(private Operation_Registry $registry) {}
 public function ready(): void { if(!MCP_App_Service::enabled()||!Urls::secure()||!Records::ready()||is_multisite())throw new OAuth_Failure('temporarily_unavailable',503); }
 private function text(array $v,string $key,int $max,bool $required=true): string {
  $value=$v[$key]??'';if(!is_string($value)||strlen($value)>$max||($required&&$value==='')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new OAuth_Failure();return$value;
 }
 public function scopes(string $value): array {
  if(strlen($value)>1024)throw new OAuth_Failure('invalid_scope');
  $scopes=array_values(array_unique(explode(' ',trim($value))));$allowed=array_merge($this->registry->scopes(),['offline_access']);
  if(!$value||count($scopes)>24||!in_array('mcp.read',$scopes,true)||array_diff($scopes,$allowed))throw new OAuth_Failure('invalid_scope');
  sort($scopes);return$scopes;
 }
 public function register_client(array $v): array {
  $this->ready();
  $redirects=$v['redirect_uris']??null;
  if(!is_array($redirects)||!array_is_list($redirects)||count($redirects)<1||count($redirects)>5)throw new OAuth_Failure('invalid_redirect_uri');
  foreach($redirects as$uri)if(!is_string($uri)||!Urls::valid_redirect($uri))throw new OAuth_Failure('invalid_redirect_uri');
  if(!is_array($v['grant_types']??[])||!is_array($v['response_types']??[])||($v['token_endpoint_auth_method']??'none')!=='none'||array_diff($v['grant_types']??['authorization_code','refresh_token'],['authorization_code','refresh_token'])
   ||($v['response_types']??['code'])!==['code'])throw new OAuth_Failure('invalid_client_metadata');
  $name=$this->text($v,'client_name',120,false);$name=trim(wp_strip_all_tags($name));if($name==='')$name='AI application';
  return Records::atomic(function()use($redirects,$name){
   Records::purge();if(Records::count('client')>=200)throw new OAuth_Failure('temporarily_unavailable',429);
   $id=Credentials::id('cl');$now=time();
   Records::put('client',$id,['name'=>$name,'redirects'=>array_values(array_unique($redirects)),'binding'=>Urls::binding(),'createdAt'=>$now],$now+86400);
   return['client_id'=>$id,'client_id_issued_at'=>$now,'client_name'=>$name,'redirect_uris'=>$redirects,'token_endpoint_auth_method'=>'none','grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code']];
  });
 }
 private function client(string $id,bool $lock=false): array {
  if(preg_match('/^cl_[a-f0-9]{32}$/D',$id)!==1)throw new OAuth_Failure('invalid_client');
  $row=Records::get('client',$id,$lock);
  if(!$row||$row['expiresAt']<=time()||!is_string($row['data']['binding']??null)||!hash_equals(Urls::binding(),$row['data']['binding']))throw new OAuth_Failure('invalid_client');return$row;
 }
 /** Login continuation carries just an opaque flow ID; all OAuth parameters stay server-side. */
 public function begin(array $v,string $browserSecret): string {
  $this->ready();$client=$this->text($v,'client_id',80);$row=$this->client($client);
  $redirect=$this->text($v,'redirect_uri',2048);if(!Urls::redirect_matches($redirect,$row['data']['redirects']))throw new OAuth_Failure('invalid_redirect_uri');
  if($this->text($v,'response_type',30)!=='code'||$this->text($v,'code_challenge_method',10)!=='S256')throw new OAuth_Failure('invalid_request');
  $challenge=$this->text($v,'code_challenge',128);if(preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)!==1)throw new OAuth_Failure();
  if(!hash_equals(Urls::resource(),$this->text($v,'resource',2048)))throw new OAuth_Failure('invalid_target');
  // A client omitting scopes requests only Kiwe's guidance reads. Native consent
  // still lists the actor's exact operations; external App scopes are opt-in.
  $default=array_values(array_intersect($this->registry->scopes(),['mcp.read','mcp.site.read','mcp.context.read','mcp.apps.read','mcp.content.read']));
  $state=$this->text($v,'state',512);$scopes=$this->scopes($this->text($v,'scope',1024,false)?:implode(' ',$default));
  if(strlen($browserSecret)!==43)throw new OAuth_Failure();
  return Records::atomic(function()use($client,$redirect,$challenge,$state,$scopes,$browserSecret){
   Records::purge();if(Records::count('pending')>=200)throw new OAuth_Failure('temporarily_unavailable',429);
   $id=Credentials::id('p');Records::put('pending',$id,['client'=>$client,'redirect'=>$redirect,'challenge'=>$challenge,'state'=>$state,'scopes'=>$scopes,'browserHash'=>Credentials::digest($browserSecret),'binding'=>Urls::binding()],time()+self::FLOW_TTL,0,$client);return$id;
  });
 }
 private function flow(string $id,string $browserSecret,bool $lock=false): array {
  if(preg_match('/^p_[a-f0-9]{32}$/D',$id)!==1)throw new OAuth_Failure();
  $row=Records::get('pending',$id,$lock);
  if(!$row||$row['expiresAt']<=time()||!Credentials::matches($browserSecret,$row['data']['browserHash']??null)
   ||!hash_equals(Urls::binding(),$row['data']['binding']??''))throw new OAuth_Failure('access_denied',403);
  $this->client($row['data']['client']);return$row;
 }
 public function review(string $id,string $browserSecret,int $actor): array {
  $this->ready();if(!Credentials::actor_allowed($actor)||$actor!==get_current_user_id())throw new OAuth_Failure('access_denied',403);
  return Records::atomic(function()use($id,$browserSecret,$actor){
   $row=$this->flow($id,$browserSecret,true);$data=$row['data'];
   if($row['userId']!==0&&$row['userId']!==$actor)throw new OAuth_Failure('access_denied',403);
   $actor_pin=Credentials::actor_pin($actor);
   if($row['userId']!==0&&!hash_equals($actor_pin,$data['actorPin']??''))throw new OAuth_Failure('access_denied',403);
   if(isset($data['decision']))return['decision'=>$data['decision']];
   $pins=$this->registry->freeze($data['scopes']);
   if(!$pins)throw new OAuth_Failure('access_denied',403);
   $review_digest=self::review_digest($pins);
   if($row['userId']===0||($data['reviewDigest']??'')!==$review_digest){
    $data['actorPin']=$actor_pin;$data['reviewDigest']=$review_digest;
    Records::put('pending',$id,$data,$row['expiresAt'],$actor,$data['client']);
   }
   $client=$this->client($data['client']);
   return['id'=>$id,'clientName'=>$client['data']['name'],'redirect'=>$data['redirect'],'scopes'=>$data['scopes'],'reviewDigest'=>$review_digest,'operations'=>$this->registry->describe($data['scopes'],$pins)];
  });
 }
 private static function review_digest(array $pins): string { ksort($pins);return hash('sha256',wp_json_encode($pins)); }
 /** Reconstruct only the same unredeemed code from the verified short-lived browser secret. */
 private static function browser_code_secret(string $flow,string $code,string $browserSecret): string {
  $message="kiwe-mcp-browser-code-v1\0".Urls::binding()."\0".$flow."\0".$code;
  return rtrim(strtr(base64_encode(hash_hmac('sha256',$message,$browserSecret,true)),'+/','-_'),'=');
 }
 /** Caller must verify a fresh native nonce and the browser-bound flow cookie before invoking. */
 public function approve(string $id,string $browserSecret,int $actor,bool $allow,bool $offline,?string $reviewDigest=null): array {
  $this->ready();if($actor!==get_current_user_id()||!Credentials::actor_allowed($actor))throw new OAuth_Failure('access_denied',403);
  return Records::atomic(function()use($id,$browserSecret,$actor,$allow,$offline,$reviewDigest){
   $row=$this->flow($id,$browserSecret,true);$data=$row['data'];
   if($row['userId']!==$actor||!hash_equals(Credentials::actor_pin($actor),$data['actorPin']??''))throw new OAuth_Failure('access_denied',403);
   if(!is_string($data['reviewDigest']??null)||($reviewDigest!==null&&!hash_equals($data['reviewDigest'],$reviewDigest)))throw new OAuth_Failure('access_denied',403);
   $offline=$offline&&in_array('offline_access',$data['scopes'],true);
   $decision=$allow?($offline?'allow-offline':'allow-once'):'cancel';
   $expires=min($row['expiresAt'],time()+self::CODE_TTL);
   $result=['redirect'=>$data['redirect'],'state'=>$data['state'],'browserUntil'=>$expires];
   if(isset($data['decision'])){
    if($data['decision']!==$decision)throw new OAuth_Failure('access_denied',403);
    if($decision==='cancel')return$result+['error'=>'access_denied'];
    $code_id=$data['codeId'];$code=Records::get('code',$code_id,true);
    if(!$code||$code['expiresAt']<=time())return$result+['alreadySent'=>true];
    $secret=self::browser_code_secret($id,$code_id,$browserSecret);
    if($code['userId']!==$actor||($code['data']['client']??'')!==$data['client']||!Credentials::matches($secret,$code['data']['hash']??null))throw new OAuth_Failure('access_denied',403);
    return$result+['code'=>Credentials::token('code',$code_id,$secret)];
   }
   $reviewed_pins=$this->registry->freeze($data['scopes']);
   if(!$reviewed_pins||!hash_equals($data['reviewDigest'],self::review_digest($reviewed_pins)))throw new OAuth_Failure('access_denied',403);
   if(!$allow){
    Records::put('pending',$id,$data+['decision'=>$decision],$expires,$actor,$data['client']);
    return$result+['error'=>'access_denied'];
   }
   if(Records::count('grant',$actor)>=20||Records::count('code',$actor)>=20||Records::count('grant')>=2000)throw new OAuth_Failure('temporarily_unavailable',429);
   $scopes=$this->scopes(implode(' ',$data['scopes']));if(!$offline)$scopes=array_values(array_diff($scopes,['offline_access']));
   $code_id=Credentials::id('c');$secret=self::browser_code_secret($id,$code_id,$browserSecret);
   Records::put('code',$code_id,['hash'=>Credentials::digest($secret),'client'=>$data['client'],'redirect'=>$data['redirect'],'challenge'=>$data['challenge'],'scopes'=>$scopes,'pins'=>$reviewed_pins,'actorPin'=>$data['actorPin'],'binding'=>Urls::binding(),'active'=>true],time()+self::CODE_TTL,$actor,$data['client']);
   $client=$this->client($data['client'],true);Records::put('client',$data['client'],$client['data'],time()+2592000);
   Records::put('pending',$id,$data+['decision'=>$decision,'codeId'=>$code_id],$expires,$actor,$data['client']);
   return$result+['code'=>Credentials::token('code',$code_id,$secret)];
  },true);
 }
 public function exchange(array $v): array {
  $this->ready();$type=$this->text($v,'grant_type',40);$client=$this->text($v,'client_id',80);$this->client($client);
  if(!hash_equals(Urls::resource(),$this->text($v,'resource',2048)))throw new OAuth_Failure('invalid_target');
  if($type==='authorization_code')return$this->code($client,$v);
  if($type==='refresh_token')return$this->refresh($client,$v);
  throw new OAuth_Failure('unsupported_grant_type');
 }
 private function code(string $client,array $v): array {
  $token=Credentials::parse($this->text($v,'code',180),'code');if(!$token)throw new OAuth_Failure('invalid_grant');
  $redirect=$this->text($v,'redirect_uri',2048);$verifier=$this->text($v,'code_verifier',128);
  return Records::atomic(function()use($client,$token,$redirect,$verifier){
   $row=Records::get('code',$token['id'],true);$data=$row['data']??[];
   if(!$row||$row['expiresAt']<=time()||($data['client']??'')!==$client||($data['redirect']??'')!==$redirect||!Credentials::matches($token['secret'],$data['hash']??null)
    ||!Credentials::pkce($verifier,$data['challenge']??'')||!Credentials::actor_current($data,$row['userId']))throw new OAuth_Failure('invalid_grant');
   if(Records::count('grant',$row['userId'])>=20||Records::count('grant')>=2000)throw new OAuth_Failure('temporarily_unavailable',429);
   // A replay or schema/role change never broadens a previously approved set of operations.
   $admitted=$this->registry->retain($data['pins'],$data['scopes']);if(!$admitted)throw new OAuth_Failure('invalid_grant');
   $id=Credentials::id('g');$access=Credentials::secret();$refresh=Credentials::secret();$now=time();$offline=in_array('offline_access',$data['scopes'],true);
   $grant=['client'=>$client,'scopes'=>$data['scopes'],'pins'=>$admitted,'actorPin'=>$data['actorPin'],'binding'=>$data['binding'],'active'=>true,'accessHash'=>Credentials::digest($access),'accessUntil'=>$now+self::ACCESS_TTL,
    'refreshHash'=>$offline?Credentials::digest($refresh):'','usedRefresh'=>[],'createdAt'=>$now];
   Records::put('grant',$id,$grant,$now+($offline?self::REFRESH_TTL:self::ACCESS_TTL),$row['userId'],$client);Records::delete('code',$token['id']);
   return$this->tokens($id,$access,$offline?$refresh:'',$data['scopes']);
  });
 }
 private function refresh(string $client,array $v): array {
  $token=Credentials::parse($this->text($v,'refresh_token',180),'refresh');if(!$token)throw new OAuth_Failure('invalid_grant');
  $result=Records::atomic(function()use($client,$token,$v){
   $row=Records::get('grant',$token['id'],true);$data=$row['data']??[];
   if(!$row||$row['expiresAt']<=time()||($data['client']??'')!==$client||!Credentials::actor_current($data,$row['userId']))throw new OAuth_Failure('invalid_grant');
   if(!Credentials::matches($token['secret'],$data['refreshHash']??'')){
    if(in_array(Credentials::digest($token['secret']),$data['usedRefresh']??[],true)){Records::delete('grant',$token['id']);return['replayed'=>true];}
    throw new OAuth_Failure('invalid_grant');
   }
   // Refresh may narrow scopes, never add them. Existing operation pins are never replaced.
   if(isset($v['scope'])){ $scopes=$this->scopes($this->text($v,'scope',1024));if(array_diff($scopes,$data['scopes']))throw new OAuth_Failure('invalid_scope');$data['scopes']=$scopes; }
   $access=Credentials::secret();$offline=in_array('offline_access',$data['scopes'],true);$refresh=$offline?Credentials::secret():'';$data['usedRefresh']=array_slice(array_merge($data['usedRefresh'],[$data['refreshHash']]),-8);
   $data['accessHash']=Credentials::digest($access);$data['accessUntil']=min(time()+self::ACCESS_TTL,$row['expiresAt']);$data['refreshHash']=$offline?Credentials::digest($refresh):'';
   Records::put('grant',$token['id'],$data,$offline?$row['expiresAt']:$data['accessUntil'],$row['userId'],$client);
   return$this->tokens($token['id'],$access,$refresh,$data['scopes'],$data['accessUntil']-time());
  });
  if(isset($result['replayed']))throw new OAuth_Failure('invalid_grant');return$result;
 }
 private function tokens(string $id,string $access,string $refresh,array $scopes,int $ttl=self::ACCESS_TTL): array {
  $out=['access_token'=>Credentials::token('access',$id,$access),'token_type'=>'Bearer','expires_in'=>$ttl,'scope'=>implode(' ',$scopes)];
  if($refresh!=='')$out['refresh_token']=Credentials::token('refresh',$id,$refresh);return$out;
 }
 public function authenticate(string $bearer): array {
  $this->ready();$token=Credentials::parse($bearer,'access');if(!$token)throw new OAuth_Failure('invalid_token',401);
  $row=Records::get('grant',$token['id']);$data=$row['data']??[];
  if(!$row||$row['expiresAt']<=time()||($data['accessUntil']??0)<=time()||!Credentials::matches($token['secret'],$data['accessHash']??'')||!Credentials::actor_current($data,$row['userId']))throw new OAuth_Failure('invalid_token',401);
  $this->client($data['client']);return['id'=>$token['id'],'actor'=>$row['userId'],'client'=>$data['client'],'scopes'=>$data['scopes'],'pins'=>$data['pins']];
 }
 /** Native cookie review can inspect only its own still-valid delegated grant. */
 public function native_review_grant(string $id,int $actor): array {
  $this->ready();$row=Records::get('grant',$id);$data=$row['data']??[];
  if($actor!==get_current_user_id()||!$row||$row['userId']!==$actor||$row['expiresAt']<=time()||!Credentials::actor_current($data,$actor))throw new OAuth_Failure('access_denied',403);
  $this->client($data['client']);return['id'=>$id,'actor'=>$actor,'client'=>$data['client'],'scopes'=>$data['scopes'],'pins'=>$data['pins']];
 }
 public function revoke(array $v): void {
  $this->ready();$client=$this->text($v,'client_id',80);$this->client($client);$raw=$this->text($v,'token',180);
  $kind=str_starts_with($raw,'kiwe_mcp_refresh_')?'refresh':'access';$token=Credentials::parse($raw,$kind);if(!$token)return;
  Records::atomic(function()use($client,$token,$kind){$row=Records::get('grant',$token['id'],true);$data=$row['data']??[];
   if($row&&($data['client']??'')===$client&&(Credentials::matches($token['secret'],$data[$kind.'Hash']??'')||($kind==='refresh'&&in_array(Credentials::digest($token['secret']),$data['usedRefresh']??[],true))))Records::delete('grant',$token['id']);
  });
 }
 public function revoke_own(string $id,int $actor): void {
  if($actor!==get_current_user_id()||!Credentials::actor_allowed($actor))throw new OAuth_Failure('access_denied',403);
  Records::atomic(function()use($id,$actor){$row=Records::get('grant',$id,true);if(!$row||$row['userId']!==$actor)throw new OAuth_Failure('access_denied',403);Records::delete('grant',$id);});
 }
}
