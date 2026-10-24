<?php
/** Isolated native proof: the CLI runner owns replica tables and blocks all delivery. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE || empty( $GLOBALS['kiwe_media_tables_isolated'] ) ) exit(2);

use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Backup\Organization_Snapshot_Service as State;
use DSA\Access\Backup_Workspace_Service as Workspace;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Tag_Group_Service as Tags;
use DSA\Access\User_Group_Service as Groups;

$proof = [ 'schema'=>'kiwe-native-organization-snapshots.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>false ];
$GLOBALS['kiwe_media_proof'] =& $proof;
$assert = static function ( bool $ok,string $label ) use ( &$proof ): void { $proof['checks'][] = [ 'label'=>$label,'passed'=>$ok ]; if ( ! $ok ) throw new RuntimeException( $label ); };
$reject = static function ( callable $work,string $label ) use ( $assert ): void { $thrown=false;try{$work();}catch(Throwable $error){$thrown=true;}$assert($thrown,$label); };
$prefix='kiweorganizationqa_'.bin2hex(random_bytes(6));$actor=get_current_user_id();$copies=[];$reviews=[];$filters=[];$service=new Snapshots();$phrase='synthetic-organization-recovery-phrase';$clean=true;
$filter=static function(string $hook,callable $callback,int $args=1)use(&$filters):void{add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
$dispatch=static function(string $method,string $path='',?array $body=null,bool $nonce=true,bool $mutation=true){$request=new WP_REST_Request($method,'/dsa/v1/workspace/backups'.$path);if($nonce)$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$request->set_header('X-Kiwe-Mutation','1');if($body!==null){$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($body));}return rest_do_request($request);};
$review=static function(string $bytes)use($service,$phrase,&$reviews):array{$value=$service->preview_upload($bytes,$phrase);$reviews[]=$value['token'];return $value;};
$collect=static function()use($service,&$copies):void{foreach($service->inventory()as$copy)$copies[$copy['id']]=true;};
try {
	$manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'exact installed package and loader match');
	$assert(!function_exists('WC')&&get_taxonomy('post_tag')&&!get_taxonomy('product_tag'),'actual non-Woo native taxonomy path');
	$assert(!$service->inventory(),'test begins with empty private settings inventory');
	$off=static fn()=>[];$filter('pre_option_'.Owner::OPTION,$off);
	$people=[];foreach(['owner'=>'administrator','client'=>'administrator','member'=>'subscriber','newer'=>'subscriber','author'=>'author']as$name=>$role){$id=wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(40,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','display_name'=>'Synthetic '.ucfirst($name),'role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Native replica account creation failed');$people[$name]=(int)$id;}
	remove_filter('pre_option_'.Owner::OPTION,$off,PHP_INT_MAX);$filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$people['owner'],'rankMath'=>'inline']);
	wp_set_current_user($people['client']);$assert(Workspace::allowed()&&!current_user_can('manage_options')&&Groups::allowed(),'client recovery authority retains native role boundary');
	$assert($dispatch('GET','',null,false)->get_status()===403&&$dispatch('POST','',['groups'=>['Tags'],'passphrase'=>$phrase],true,false)->get_status()===403,'nonce and mutation proof required by actual routes');
	$_SERVER['HTTP_ORIGIN']='https://outside.invalid';$assert($dispatch('GET')->get_status()===403,'cross-site native inventory denied');unset($_SERVER['HTTP_ORIGIN']);
	wp_set_current_user($people['author']);$assert($dispatch('GET')->get_status()===403,'author cannot enter account recovery');$reject(static fn()=>State::read(State::AUDIENCES),'direct organization owner cannot bypass native denial');wp_set_current_user($people['client']);
	// Remove copied metadata only inside this runner's owned native replicas.
	$wpdb->delete($wpdb->usermeta,['meta_key'=>Groups::META]);wp_cache_flush();wp_set_current_user($people['client']);
	$termIds=[];foreach(['one','two']as$name){$term=wp_insert_term($prefix.' '.$name,'post_tag',['slug'=>$prefix.'-'.$name]);if(is_wp_error($term))throw new RuntimeException('Native tag creation failed');$termIds[]=(int)$term['term_id'];}
	$g1='g-1111111111111111';$g2='g-2222222222222222';$g3='g-3333333333333333';
	$tagGroups=[['id'=>2,'kind'=>'post','name'=>'Synthetic topics'],['id'=>1,'kind'=>'post','name'=>'Synthetic seasons']];$userGroups=[['id'=>$g2,'name'=>'Synthetic readers'],['id'=>$g1,'name'=>'Synthetic members']];
	update_option(Tags::OPTION,$tagGroups,false);update_option(Groups::OPTION,$userGroups,false);update_term_meta($termIds[0],Tags::META,'1');update_term_meta($termIds[1],Tags::META,'2');update_user_meta($people['member'],Groups::META,[$g1]);
	$consent=['topics'=>[],'channels'=>[],'browserPermission'=>'denied','updatedAt'=>123];update_user_meta($people['member'],'dsa_notification_preferences',$consent);update_user_meta($people['member'],'session_tokens',['synthetic'=>'untouched']);update_user_meta($people['member'],'pk_email_verified_at','123');
	$menuIds=[];foreach(['A','B']as$name){$id=wp_create_nav_menu($prefix.' '.$name);if(is_wp_error($id))throw new RuntimeException('Native menu creation failed');$menuIds[]=(int)$id;}
	$settings=(array)get_option(DSA_OPTION_SETTINGS,[]);$settings['dock']=array_replace((array)($settings['dock']??[]),['menu_nav_ids'=>$menuIds,'menu_nav_id'=>$menuIds[0],'menu_group_limits'=>[$menuIds[0]=>12,$menuIds[1]=>20]]);update_option(DSA_OPTION_SETTINGS,$settings,false);$unrelated=array_diff_key($settings,['dock'=>true]);
	$protected=get_userdata($people['member']);$accountHash=hash('sha256',serialize([$protected->user_login,$protected->user_email,$protected->user_pass,$protected->roles]));
	$assert(array_intersect(['Tags','User groups','Navigation'],array_keys(Snapshots::option_groups()))===['Tags','User groups','Navigation'],'client sees three native organization choices');
	$tags=State::read(State::TAGS);$audiences=State::read(State::AUDIENCES);$navigation=State::read(State::NAVIGATION);$assert($tags['groups']===$tagGroups&&$audiences['groups']===$userGroups,'original native folder and audience order retained');
	$bad=$audiences;$bad['records'][0]['groups']=['email'];$reject(static fn()=>State::validate(State::AUDIENCES,$bad),'smart verified routes cannot be imported as group memberships');
	$bad=$tags;$bad['groups'][]=['id'=>3,'kind'=>'product','name'=>'Synthetic products'];$reject(static fn()=>State::validate(State::TAGS,$bad),'absent product taxonomy cannot be recovered');
	$bad=$tags;$bad['records'][0]['group']=100;$reject(static fn()=>State::validate(State::TAGS,$bad),'missing folder dependency rejected');
	$bad=$audiences;$bad['records'][0]['identity']=str_repeat('f',64);$reject(static fn()=>State::validate(State::AUDIENCES,$bad),'changed account identity rejected');
	$bad=$audiences;$bad['roles']=['administrator'];$reject(static fn()=>State::validate(State::AUDIENCES,$bad),'archive cannot introduce role authority');
	$response=$dispatch('POST','',['label'=>'Synthetic organization','groups'=>['Tags','User groups','Navigation'],'passphrase'=>$phrase]);$body=$response->get_data();$assert($response->get_status()===200&&isset($body['created']['id']),'actual client REST creation succeeds');$copy=$body['created'];$collect();$bytes=file_get_contents($service->download_path($copy['id']));
	$assert(is_string($bytes)&&!str_contains($bytes,'Synthetic members')&&!str_contains($bytes,$prefix),'actual archive encrypts organization and account references');
	$reject(static fn()=>$service->preview_upload($bytes,'incorrect-phrase'),'actual wrong passphrase rejected');
	update_option(Tags::OPTION,[['id'=>2,'kind'=>'post','name'=>'Changed topics'],['id'=>1,'kind'=>'post','name'=>'Synthetic seasons']],false);update_term_meta($termIds[0],Tags::META,'2');
	update_option(Groups::OPTION,[['id'=>$g2,'name'=>'Synthetic readers'],['id'=>$g1,'name'=>'Changed members'],['id'=>$g3,'name'=>'Newer audience']],false);update_user_meta($people['member'],Groups::META,[$g2,$g3]);update_user_meta($people['newer'],Groups::META,[$g1]);
	$now=(array)get_option(DSA_OPTION_SETTINGS,[]);$now['dock']['menu_nav_ids']=[$menuIds[1]];$now['dock']['menu_nav_id']=$menuIds[1];update_option(DSA_OPTION_SETTINGS,$now,false);
	$response=$dispatch('POST','/preview',['id'=>$copy['id'],'passphrase'=>$phrase]);$body=$response->get_data();$assert($response->get_status()===200&&($body['review']['changedCount']??0)===3,'actual native REST review detects all three changed tasks');$reviews[]=$body['review']['token'];$assert(count($body['review']['organization'])===3&&count($body['review']['organization'][0]['changes'])===2&&count($body['review']['organization'][1]['changes'])===2,'client review identifies every changed native assignment');
	$token=$body['review']['token'];$assert($dispatch('POST','/restore',['token'=>$token,'passphrase'=>$phrase])->get_status()===400,'actual recovery refuses a missing explicit confirmation');
	$response=$dispatch('POST','/restore',['token'=>$token,'passphrase'=>$phrase,'confirm'=>true]);$body=$response->get_data();if($response->get_status()!==200)$proof['restoreFailure']=['status'=>$response->get_status(),'code'=>$body['code']??null,'message'=>$body['message']??null];$assert($response->get_status()===200&&($body['restored']['restored']??0)===3,'actual reviewed client REST applies native recovery');$collect();
	$assert(get_option(Tags::OPTION)===$tagGroups&&get_term_meta($termIds[0],Tags::META,true)==='1','real native folder and assignment readback matches');
	$assert(get_user_meta($people['member'],Groups::META,true)===[$g3,$g1]&&get_user_meta($people['newer'],Groups::META,true)===[$g1]&&count(get_option(Groups::OPTION))===3,'real recovery preserves newer audiences and newer account memberships');
	$assert(get_user_meta($people['member'],'dsa_notification_preferences',true)===$consent&&get_user_meta($people['member'],'session_tokens',true)===['synthetic'=>'untouched']&&get_user_meta($people['member'],'pk_email_verified_at',true)==='123','native opt-out sessions and verification never replay');
	$after=get_userdata($people['member']);$assert($accountHash===hash('sha256',serialize([$after->user_login,$after->user_email,$after->user_pass,$after->roles])),'native passwords roles and identity unchanged');
	$assert((new DSA\Access\Navigation_Workspace_Service())->backup_policy()['ids']===$menuIds&&array_diff_key((array)get_option(DSA_OPTION_SETTINGS,[]),['dock'=>true])===$unrelated,'native navigation owner restores policy without other setting writes');
	$assert(count($service->inventory())===2,'encrypted before-copy retained by original transaction');
	$r=$review($bytes);update_user_meta($people['newer'],Groups::META,[$g3]);$reject(static fn()=>$service->apply($r['token'],$phrase),'membership edit after review blocks recovery');
	$r=$review($bytes);wp_set_current_user($people['owner']);$reject(static fn()=>$service->apply($r['token'],$phrase),'foreign actor cannot apply client review');wp_set_current_user($people['client']);
	$r=$review($bytes);(new WP_User($people['member']))->set_role('author');$reject(static fn()=>$service->apply($r['token'],$phrase),'actual native role change invalidates account reference');(new WP_User($people['member']))->set_role('subscriber');
	$r=$review($bytes);wp_update_nav_menu_object($menuIds[0],['menu-name'=>$prefix.' Changed']);$reject(static fn()=>$service->apply($r['token'],$phrase),'native menu rename invalidates policy recovery');wp_update_nav_menu_object($menuIds[0],['menu-name'=>$prefix.' A']);
	update_option(Tags::OPTION,[['id'=>2,'kind'=>'post','name'=>'Keep before rollback'],['id'=>1,'kind'=>'post','name'=>'Synthetic seasons']],false);update_term_meta($termIds[0],Tags::META,'2');update_user_meta($people['member'],Groups::META,[$g2,$g3]);
	$before=[State::read(State::TAGS),State::read(State::AUDIENCES),State::read(State::NAVIGATION)];$r=$review($bytes);$failed=false;
	$deny=static function($check,$id,$key)use(&$failed,$people){if(!$failed&&(int)$id===$people['member']&&$key===Groups::META){$failed=true;return false;}return $check;};add_filter('update_user_metadata',$deny,PHP_INT_MAX,3);
	try{$reject(static fn()=>$service->apply($r['token'],$phrase),'actual failed membership write aborts reviewed restore');}finally{remove_filter('update_user_metadata',$deny,PHP_INT_MAX);}$collect();
	$assert($failed&&[State::read(State::TAGS),State::read(State::AUDIENCES),State::read(State::NAVIGATION)]===$before,'actual reverse rollback restores entire native state after partial failure');
	update_option(Tags::OPTION,[],false);foreach($termIds as$id)delete_term_meta($id,Tags::META);$r=$review($bytes);$service->apply($r['token'],$phrase);$collect();$assert(get_option(Tags::OPTION)===$tagGroups&&get_term_meta($termIds[0],Tags::META,true)==='1','missing definitions recover against existing native terms');
	$assert([]===$GLOBALS['kiwe_media_mail']&&[]===$GLOBALS['kiwe_media_http'],'no mail gateway or provider work attempted');$proof['passed']=true;
}catch(Throwable $error){$proof['passed']=false;$proof['error']=['message'=>$error->getMessage(),'line'=>$error->getLine()];}
finally{
	try{wp_set_current_user($people['client']??$actor);$collect();foreach($reviews as$token)$service->retire_review($token);foreach(array_keys($copies)as$id)$service->remove($id);$clean=[]===$service->inventory();}catch(Throwable $error){$clean=false;$proof['cleanupError']=$error->getMessage();}
	foreach($filters as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);wp_set_current_user($actor);$proof['cleanup']=$clean;
}
return $proof;
