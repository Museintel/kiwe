<?php
/** Exact disposable lab only. The wrapper blocks mail/HTTP/scheduling and retains private receipts. */
function kiwe_mcp_native_tests(callable $check,callable $ledger,string $run,string $mode): array {
 if(PHP_SAPI!=='cli'||home_url()!=='https://plum-giraffe-539524.hostingersite.com'||!preg_match('/^[a-f0-9]{16}$/D',$run)||!in_array($mode,['normal','plain'],true))throw new RuntimeException('Exact MCP lab fixture required.');
 global $wpdb;$initialActor=get_current_user_id();$initialServer=$_SERVER;$ownedUsers=[];$clientIds=[];$rateHashes=[];$failure=null;
 $nativeOptions=['dsa_settings','pk_settings_v3','stp_settings','kiwe_seam_design_context_v1','kiwe_access_policy_v1','kiwe_store_app_enabled_v1','kiwe_seo_workspace_enabled_v1','kiwe_guest_app_enabled_v1','kiwe_ai_app_enabled_v1','kiwe_sitegraph_app_enabled_v1','active_plugins','permalink_structure','template','stylesheet'];
 $snapshot=static function()use($wpdb,$nativeOptions){$out=[];foreach($nativeOptions as$key)$out[$key]=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));return$out;};
 $before=$snapshot();$engineHash=hash_file('sha256',DSA_DIR.'includes/PhoneKey/phonekey-core.php');
 $check(DSA_VERSION==='8.0.0-rc.475'&&DSA\Apps\Runtime_Contract::API===4,'Actual new Core/provider compatibility boundary');
 $check(DSA\Apps\Runtime_Registry::provider('mcp')!==null&&!DSA\Apps\Package_Layout::bundled('mcp'),'Physical optional MCP provider, not a bundled Core engine');
 wp_set_current_user(DSA\Access\Site_Owner_Service::owner_id());
 $check(DSA\Apps\MCP_App_Service::allowed(),'Existing native website-management authority');
 $changed=DSA\Apps\MCP_App_Service::change(true,DSA\Apps\MCP_App_Service::expected());$check($changed===true,'Native App switch enables the independently installed MCP App');
 DSA\MCP\Records::install();wp_cache_delete('kiwe_mcp_storage_v1','options');
 $check(DSA\MCP\Records::ready(),'Native schema readiness survives a fresh option/database read');
 $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like(DSA\MCP\Records::table())),ARRAY_A);
 $check(strtolower($status['Engine']??'')==='innodb','Actual native connection table is transactional');
 $registry=new DSA\MCP\Operation_Registry();DSA\MCP\Native_Provider::register($registry);$auth=new DSA\MCP\Authorization_Service($registry);$http=new DSA\MCP\HTTP_Controller($auth,$registry);
 $deny=static function(callable $fn,?string $reason=null):bool{try{$fn();}catch(DSA\MCP\OAuth_Failure $e){return$reason===null||$reason===$e->reason;}return false;};
 $newClient=static function(string $label)use($auth,&$clientIds,$ledger){$client=$auth->register_client(['client_name'=>$label,'redirect_uris'=>['http://127.0.0.1/callback']]);$clientIds[]=$client['client_id'];$ledger('clients',['ids'=>$clientIds]);return$client['client_id'];};
 $connect=static function(string $client,int $actor,bool $offline=true)use($auth){
  wp_set_current_user($actor);$verifier=DSA\MCP\Credentials::secret();$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');$browser=DSA\MCP\Credentials::secret();
  $params=['client_id'=>$client,'redirect_uri'=>'http://127.0.0.1:8765/callback','response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>$challenge,'state'=>'synthetic-native-state','resource'=>DSA\MCP\Urls::resource(),'scope'=>'mcp.read offline_access'];
  $flow=$auth->begin($params,$browser);$review=$auth->review($flow,$browser,$actor);$approved=$auth->approve($flow,$browser,$actor,true,$offline);
  wp_set_current_user(0);$exchange=['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>$params['redirect_uri'],'code_verifier'=>$verifier,'code'=>$approved['code'],'resource'=>$params['resource']];
  return['tokens'=>$auth->exchange($exchange),'exchange'=>$exchange,'flow'=>$flow,'review'=>$review];
 };
 remove_all_actions('user_register');remove_all_actions('set_user_role');
 try{
  foreach(['editor','subscriber']as$role){$login='kiwe_mcp_'.$run.'_'.$mode.'_'.$role;$check(username_exists($login)===false,'Unused labelled native fixture '.$role);
   $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','display_name'=>'Synthetic MCP '.$role,'user_pass'=>wp_generate_password(48,true,true),'role'=>$role]);
   if(is_wp_error($id))throw new RuntimeException('Native fixture account refused.');$ownedUsers[$role]=(int)$id;$ledger('users',['ids'=>array_values($ownedUsers)]);
  }
  $editor=$ownedUsers['editor'];$subscriber=$ownedUsers['subscriber'];$client=$newClient('Synthetic MCP '.$run.' '.$mode);
  $linked=$connect($client,$editor);$tokens=$linked['tokens'];$grant=$auth->authenticate($tokens['access_token']);
  $check($grant['actor']===$editor&&$grant['client']===$client,'Actual native code/PKCE exchange binds the editor and client without cookies');
  $check($deny(fn()=>$auth->exchange($linked['exchange']),'invalid_grant'),'Actual SQL authorization code cannot be exchanged twice');
  $row=DSA\MCP\Records::get('grant',$grant['id']);$encoded=wp_json_encode($row);
  $check(!str_contains($encoded,$tokens['access_token'])&&!str_contains($encoded,$tokens['refresh_token']),'Actual native row contains no raw bearer/refresh token');
  wp_set_current_user($editor);$read=$registry->read('mcp.connection.read',[],$grant['scopes'],$grant['pins']);
  $check(is_array($read)&&$read['readOnly']===true&&$read['coreVersion']===DSA_VERSION,'Native provider returns only typed read-only connection facts');
  $check(is_wp_error($registry->read('mcp.connection.read',['option'=>'pk_settings_v3'],$grant['scopes'],$grant['pins'])),'Native typed operation rejects arbitrary option input');
  $check(!DSA\MCP\Credentials::actor_allowed($subscriber)&&DSA\MCP\Credentials::actor_allowed($editor),'Actual subscriber cannot acquire editorial MCP authority');
  wp_set_current_user($subscriber);$check($deny(fn()=>$auth->revoke_own($grant['id'],$subscriber),'access_denied'),'Native other actor cannot revoke an editor connection');
  wp_set_current_user($editor);$editUser=get_user_by('id',$editor);$editUser->set_role('subscriber');
  $check($deny(fn()=>$auth->authenticate($tokens['access_token']),'invalid_token'),'Actual native role downgrade immediately denies a delegated token');$editUser->set_role('editor');
  $refresh=['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>DSA\MCP\Urls::resource(),'refresh_token'=>$tokens['refresh_token']];
  $parsed=DSA\MCP\Credentials::parse($tokens['refresh_token'],'refresh');$forged=DSA\MCP\Credentials::token('refresh',$parsed['id'],DSA\MCP\Credentials::secret());
  $check($deny(fn()=>$auth->exchange(array_replace($refresh,['refresh_token'=>$forged])),'invalid_grant'),'Actual native forged refresh fails');
  $check($auth->authenticate($tokens['access_token'])['actor']===$editor,'A forged refresh does not revoke the legitimate native grant');
  $rotated=$auth->exchange($refresh);$check($rotated['refresh_token']!==$tokens['refresh_token'],'Actual native refresh rotates credentials');
  $check($deny(fn()=>$auth->authenticate($tokens['access_token'])),'Native rotation rejects the preceding access credential');
  $check($deny(fn()=>$auth->exchange($refresh),'invalid_grant')&&$deny(fn()=>$auth->authenticate($rotated['access_token'])),'Known consumed refresh reuse commits native grant retirement');
  $off=$connect($client,$editor);wp_set_current_user(DSA\Access\Site_Owner_Service::owner_id());
  $check(DSA\Apps\MCP_App_Service::change(false,DSA\Apps\MCP_App_Service::expected())===true,'Native manager turns the App Off');
  $check($deny(fn()=>$auth->authenticate($off['tokens']['access_token'])),'App Off denies actual native credentials');
  $check(DSA\Apps\MCP_App_Service::change(true,DSA\Apps\MCP_App_Service::expected())===true,'Native manager re-enables the App');
  $check($deny(fn()=>$auth->authenticate($off['tokens']['access_token'])),'Re-enabling cannot revive the preceding native connection');
  // Off also retires old client identities. A fresh native consent is required.
  $client=$newClient('Synthetic MCP fresh '.$run.' '.$mode);$fresh=$connect($client,$editor);$current=$auth->authenticate($fresh['tokens']['access_token']);
  wp_set_current_user($editor);$auth->revoke_own($current['id'],$editor);$check($deny(fn()=>$auth->authenticate($fresh['tokens']['access_token'])),'Native own disconnect immediately denies the connection');
  $metadata=$http->metadata();$check($metadata['code_challenge_methods_supported']===['S256']&&$metadata['authorization_response_iss_parameter_supported']===true,'Native metadata advertises only tested PKCE and issuer-bound callbacks');
  $check(!isset($metadata['client_id_metadata_document_supported']),'Unqualified URL-fetch client registration is not advertised');
  $resource=$http->resource_metadata();$check($resource['resource']===DSA\MCP\Urls::resource()&&$resource['authorization_servers']===[DSA\MCP\Urls::issuer()],'Exact native resource/issuer metadata');
  $check($http->origin_for_client($client,'')&&!$http->origin_for_client($client,'https://unrelated.example')&&!$http->origin_for_client($client,'null'),'Native OAuth admits absent Origin but rejects unrelated/opaque origins');
  $node=new WP_REST_Request('POST','/kiwe-mcp/v1/token');$node->set_header('Content-Type','application/x-www-form-urlencoded');$node->set_body('grant_type=authorization_code&grant_type=refresh_token');
  $ip='198.51.100.'.(10+(hexdec(substr($run,0,2))%28)*8+($mode==='plain'?1:0));$_SERVER['REMOTE_ADDR']=$ip;
  $check(DSA\Utilities\Origin_Checker::client_ip()===$ip,'Labelled documentation address owns the native rate fixture');
  $hash=hash_hmac('sha256','kiwe-mcp-token|'.$ip,wp_salt('nonce'));
  $check($wpdb->get_var($wpdb->prepare('SELECT bucket_hash FROM '.$wpdb->prefix.'dsa_rate_limits WHERE bucket_hash=%s',$hash))===null,'Native fixture rate row is unused');
  $found=false;wp_cache_get('bucket_'.$hash,'dsa_rate_limits',false,$found);$check(!$found,'Native fixture cache-rate key is unused');$rateHashes[]=$hash;
  $check($http->request('token',$node)->get_status()===400,'Actual native HTTP adapter rejects duplicated form parameters');
  // A nested native transaction must survive; MCP cannot commit it accidentally.
  $check($wpdb->query('START TRANSACTION')!==false,'Native transaction fixture starts');$nested=false;
  try{DSA\MCP\Records::atomic(static fn()=>true);}catch(Throwable){$nested=true;}finally{$wpdb->query('ROLLBACK');}
  $check($nested,'Native existing transaction is refused without an implicit commit');
  $rollbackId='p_'.bin2hex(random_bytes(16));
  try{DSA\MCP\Records::atomic(function()use($rollbackId){DSA\MCP\Records::put('pending',$rollbackId,['synthetic'=>true],time()+60);throw new RuntimeException('Synthetic rollback');});}catch(RuntimeException){}
  $check(DSA\MCP\Records::get('pending',$rollbackId)===null,'Actual native rollback retains no half-written authorization state');
  if($mode==='plain')$check(!function_exists('WC')&&!class_exists('Bricks\\Elements',false)&&str_contains(DSA\MCP\Urls::resource(),'rest_route='),'Actual no-Woo/no-Bricks/default-permalink connection');
  return['passed'=>true,'nativeSql'=>true,'mode'=>$mode,'php'=>PHP_VERSION,'woo'=>defined('WC_VERSION')?WC_VERSION:null,'mcpApi'=>1,'credentialsExported'=>false];
 }finally{
  wp_set_current_user(DSA\Access\Site_Owner_Service::owner_id());
  foreach($clientIds as$id){$wpdb->query($wpdb->prepare('DELETE FROM '.DSA\MCP\Records::table().' WHERE client_hash=%s',hash('sha256',$id)));$wpdb->query($wpdb->prepare('DELETE FROM '.DSA\MCP\Records::table().' WHERE id=%s AND kind=%s',$id,'client'));}
  foreach($rateHashes as$hash){$wpdb->delete($wpdb->prefix.'dsa_rate_limits',['bucket_hash'=>$hash],['%s']);wp_cache_delete('bucket_'.$hash,'dsa_rate_limits');}
  require_once ABSPATH.'wp-admin/includes/user.php';foreach($ownedUsers as$id)if(get_userdata($id)&&!wp_delete_user($id))$failure='Synthetic native account cleanup failed.';
  $check($snapshot()===$before,'Original native business/App/PhoneKey/store/SEO controls remain exact');
  $check(hash_file('sha256',DSA_DIR.'includes/PhoneKey/phonekey-core.php')===$engineHash,'PhoneKey engine remains exact');
  foreach($ownedUsers as$id)$check(get_userdata($id)===false,'Synthetic native account retired');
  $check($wpdb->last_error==='','No final native database error');wp_set_current_user($initialActor);$_SERVER=$initialServer;
  if($failure)throw new RuntimeException($failure);
 }
}
