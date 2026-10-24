<?php
declare(strict_types=1);
namespace {
	$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kiwe-organization-qa-'.bin2hex( random_bytes(6) ); mkdir( $root.'/public_html',0700,true );
	define( 'ABSPATH',$root.'/public_html/' ); define( 'DAY_IN_SECONDS',86400 ); define( 'DSA_OPTION_SETTINGS','dsa_settings' );
	$checks = []; $options = []; $transients = []; $terms = []; $termMeta = []; $users = []; $userMeta = []; $menus = []; $items = []; $caps = []; $signedIn = true; $owner = true; $actor = 1; $product = false; $failUser = 0; $site = 'https://organization.example.test/';
	function current_user_can( $cap,...$args ) { return $GLOBALS['caps'][$cap.( $args ? ':'.$args[0] : '' )] ?? $GLOBALS['caps'][$cap] ?? true; }
	function is_user_logged_in() { return $GLOBALS['signedIn']; }
	function is_multisite() { return false; }
	function get_current_user_id() { return $GLOBALS['actor']; }
	function get_current_blog_id() { return 1; }
	function get_option( $key,$default=false ) { return $GLOBALS['options'][$key] ?? $default; }
	function update_option( $key,$value,$autoload=null ) { $GLOBALS['options'][$key]=$value; return true; }
	function add_option( $key,$value,$unused='',$autoload=null ) { if(array_key_exists($key,$GLOBALS['options']))return false; $GLOBALS['options'][$key]=$value; return true; }
	function delete_option( $key ) { unset($GLOBALS['options'][$key]); return true; }
	function get_transient( $key ) { return $GLOBALS['transients'][$key]??false; }
	function set_transient( $key,$value,$expiry ) { $GLOBALS['transients'][$key]=$value; return true; }
	function delete_transient( $key ) { unset($GLOBALS['transients'][$key]); return true; }
	function wp_json_encode( $value,$flags=0 ) { return json_encode($value,$flags); }
	function sanitize_text_field( $value ) { return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$value))); }
	function __( $value,$domain='' ) { return $value; }
	function wp_normalize_path( $path ) { return str_replace('\\','/',$path); }
	function wp_mkdir_p( $path ) { return is_dir($path)||mkdir($path,0700,true); }
	function home_url( $path='' ) { return $GLOBALS['site'].ltrim($path,'/'); }
	function untrailingslashit( $value ) { return rtrim($value,'/\\'); }
	function trailingslashit( $value ) { return rtrim($value,'/\\').'/'; }
	function get_stylesheet() { return 'synthetic-theme'; }
	function get_theme_mod( $name,$default=false ) { return $default; }
	function absint( $value ) { return abs((int)$value); }
	function apply_filters( $name,$value ) { return $value; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error { public function __construct(public string $code='',public string $message='',public array $data=[]){} }
	function taxonomy_exists( $taxonomy ) { return 'post_tag'===$taxonomy||('product_tag'===$taxonomy&&$GLOBALS['product']); }
	function get_taxonomy( $taxonomy ) { return taxonomy_exists($taxonomy)?(object)['cap'=>(object)['manage_terms'=>'manage_terms','edit_terms'=>'edit_terms','assign_terms'=>'assign_terms']]:null; }
	function get_term( $id,$taxonomy ) { $term=$GLOBALS['terms'][$id]??false; return $term&&$term->taxonomy===$taxonomy?$term:false; }
	function get_terms( $args ) { $rows=array_filter($GLOBALS['terms'],fn($term)=>$term->taxonomy===$args['taxonomy']&&isset($GLOBALS['termMeta'][$term->term_id][DSA\Access\Tag_Group_Service::META])); usort($rows,fn($a,$b)=>$a->term_id<=>$b->term_id); return array_slice($rows,0,$args['number']); }
	function get_term_meta( $id,$key,$single=true ) { $values=$GLOBALS['termMeta'][$id][$key]??[]; return $single?($values[0]??''):$values; }
	function update_term_meta( $id,$key,$value ) { $GLOBALS['termMeta'][$id][$key]=[$value]; return true; }
	function delete_term_meta( $id,$key ) { unset($GLOBALS['termMeta'][$id][$key]); return true; }
	function get_userdata( $id ) { return $GLOBALS['users'][$id]??false; }
	function get_users( $args ) { $ids=array_keys(array_filter($GLOBALS['users'],fn($u)=>isset($GLOBALS['userMeta'][$u->ID][DSA\Access\User_Group_Service::META]))); sort($ids,SORT_NUMERIC); return array_slice($ids,0,$args['number']); }
	function get_user_meta( $id,$key,$single=true ) { $values=$GLOBALS['userMeta'][$id][$key]??[]; return $single?($values[0]??''):$values; }
	function update_user_meta( $id,$key,$value ) { if($GLOBALS['failUser']===$id){$GLOBALS['failUser']=0;return false;} $GLOBALS['userMeta'][$id][$key]=[$value]; return true; }
	function delete_user_meta( $id,$key ) { unset($GLOBALS['userMeta'][$id][$key]); return true; }
	function wp_get_nav_menu_object( $id ) { return $GLOBALS['menus'][$id]??false; }
	function wp_get_nav_menu_items( $id,$args=[] ) { return $GLOBALS['items'][$id]??false; }
	function wp_get_nav_menus( $args=[] ) { return array_values($GLOBALS['menus']); }
	function expect( bool $ok,string $label ) { $GLOBALS['checks'][$label]=$ok; if(!$ok)throw new \RuntimeException($label); }
	function rejects( callable $work,string $label ) { $thrown=false;try{$work();}catch(\RuntimeException $e){$thrown=true;}expect($thrown,$label); }
	function clean( string $path ) { foreach(scandir($path)?:[] as $name){if(in_array($name,['.','..'],true))continue;$file=$path.DIRECTORY_SEPARATOR.$name;is_dir($file)?clean($file):unlink($file);}rmdir($path); }
}
namespace DSA\Access {
	class Backup_Workspace_Service { public static function allowed(): bool { return $GLOBALS['signedIn']&&$GLOBALS['owner']; } }
	class Site_Owner_Service { public static function is_owner(?int $id=null): bool { return null===$id?$GLOBALS['owner']:$id===999; } }
}
namespace {
	require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Tag_Group_Service.php';
	require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/User_Group_Service.php';
	require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Navigation_Workspace_Service.php';
	require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
	require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Cipher.php';
	require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Organization_Snapshot_Service.php';
	require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Configuration_Snapshot_Service.php';
	use DSA\Backup\Organization_Snapshot_Service as State;
	use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
	use DSA\Access\Tag_Group_Service as Tags;
	use DSA\Access\User_Group_Service as Groups;
	$passed=false;$error='';$cleanup=false;
	try {
		$g1='g-1111111111111111';$g2='g-2222222222222222';$g3='g-3333333333333333';
		$options[Tags::OPTION]=[['id'=>2,'kind'=>'post','name'=>'Topics'],['id'=>1,'kind'=>'post','name'=>'Seasons']];
		$options[Groups::OPTION]=[['id'=>$g2,'name'=>'Readers'],['id'=>$g1,'name'=>'Members']];
		$options[DSA_OPTION_SETTINGS]=['dock'=>['menu_nav_ids'=>[31,32],'menu_nav_id'=>31,'menu_group_limits'=>[31=>12,32=>20],'unrelated'=>'keep'],'phonekey'=>['secret'=>'synthetic-never-export']];
		foreach([11,12] as $id)$terms[$id]=(object)['term_id'=>$id,'term_taxonomy_id'=>$id+100,'taxonomy'=>'post_tag','slug'=>'synthetic-tag-'.$id,'name'=>'Tag '.$id];
		$termMeta[11][Tags::META]=['1'];$termMeta[12][Tags::META]=['2'];
		foreach([20,21,22] as $id)$users[$id]=(object)['ID'=>$id,'user_login'=>'synthetic'.$id,'user_registered'=>'2026-01-01 12:00:00','user_email'=>'synthetic'.$id.'@example.invalid','display_name'=>'Person '.$id,'roles'=>['subscriber']];
		$userMeta[20][Groups::META]=[[$g1]];$userMeta[21][Groups::META]=[[$g2]];
		$userMeta[20]['dsa_notification_preferences']=[['topics'=>[],'channels'=>[],'browserPermission'=>'denied','updatedAt'=>123]];
		$userMeta[20]['session_tokens']=[['synthetic'=>'never-export']];$userMeta[20]['pk_email_verified_at']=['123'];
		foreach([31,32,33] as $id){$menus[$id]=(object)['term_id'=>$id,'slug'=>'menu-'.$id,'name'=>'Menu '.$id,'taxonomy'=>'nav_menu'];$items[$id]=[];}
		$originalOptions=$options;$originalTerms=$termMeta;$originalUsers=$userMeta;
		expect(array_keys(State::groups())===['Tags','User groups','Navigation'],'available groups use native capabilities');
		$caps['list_users']=false;expect(!isset(State::groups()['User groups']),'audience selection disappears without native account-list authority');rejects(fn()=>State::read(State::AUDIENCES),'direct capture rejects revoked account authority');$caps=[];
		$tags=State::read(State::TAGS);$audiences=State::read(State::AUDIENCES);$navigation=State::read(State::NAVIGATION);
		expect($tags['groups']===$options[Tags::OPTION]&&$audiences['groups']===$options[Groups::OPTION],'capture retains original folder and group order');
		expect(!str_contains(wp_json_encode([$tags,$audiences,$navigation]),'synthetic-never-export')&&!str_contains(wp_json_encode($audiences),'session_tokens')&&!str_contains(wp_json_encode($audiences),'dsa_notification_preferences'),'archive excludes credentials sessions and consent payloads');
		$bad=$tags;$bad['groups'][]=$bad['groups'][0];rejects(fn()=>State::validate(State::TAGS,$bad),'duplicate native folder IDs rejected');
		$bad=$tags;$bad['records'][0]['group']=99;rejects(fn()=>State::validate(State::TAGS,$bad),'orphan folder assignment rejected');
		$bad=$audiences;$bad['records'][0]['groups'][]='email';rejects(fn()=>State::validate(State::AUDIENCES,$bad),'smart audience enrollment cannot be imported');
		$bad=$audiences;$bad['roles']=['administrator'];rejects(fn()=>State::validate(State::AUDIENCES,$bad),'unknown role authority rejected');
		$bad=$audiences;$bad['records'][0]['groups']=[$g1,$g1];rejects(fn()=>State::validate(State::AUDIENCES,$bad),'duplicate memberships rejected');
		$bad=$navigation;$bad['menus']=[];rejects(fn()=>State::validate(State::NAVIGATION,$bad),'missing navigation dependency inventory rejected');
		$bad=$tags;$bad['groups'][]=['id'=>3,'kind'=>'product','name'=>'Product topics'];rejects(fn()=>State::validate(State::TAGS,$bad),'absent Woo taxonomy cannot borrow tag permissions');
		$bad=$tags;$bad['records'][0]['identity']=str_repeat('a',64);rejects(fn()=>State::validate(State::TAGS,$bad),'changed native tag identity rejected');
		$users[20]->roles=['author'];rejects(fn()=>State::validate(State::AUDIENCES,$audiences),'role changes invalidate referenced account proof');$users[20]->roles=['subscriber'];
		$caps['edit_user:20']=false;rejects(fn()=>State::validate(State::AUDIENCES,$audiences),'per-account editing denial respected');$caps=[];
		$menus[31]->name='Changed';rejects(fn()=>State::validate(State::NAVIGATION,$navigation),'changed referenced menu rejected');$menus[31]->name='Menu 31';
		$service=new Snapshots();$phrase='synthetic-organization-recovery-phrase';
		$copy=$service->create($phrase,'Organization',['Tags','User groups','Navigation']);$bytes=file_get_contents($service->download_path($copy['id']));
		expect(!str_contains($bytes,'Readers')&&!str_contains($bytes,'synthetic20'),'organization content is encrypted in original private format');
		$decoded=DSA\Backup\Archive_Cipher::open($bytes,Snapshots::SCHEMA,$phrase,Snapshots::MAX_FILE);
		expect(array_keys($decoded['options'])===[State::TAGS,State::AUDIENCES,State::NAVIGATION]&&!isset($options[State::TAGS]),'virtual keys do not create shadow options');
		rejects(fn()=>$service->preview_upload($bytes,'incorrect-phrase'),'wrong recovery passphrase rejected');
		$options[Tags::OPTION][0]['name']='Changed topics';$termMeta[11][Tags::META]=['2'];
		$options[Groups::OPTION][1]['name']='Changed members';$options[Groups::OPTION][]=['id'=>$g3,'name'=>'New audience'];
		$userMeta[20][Groups::META]=[[$g2,$g3]];$userMeta[22][Groups::META]=[[$g1]];
		$options[DSA_OPTION_SETTINGS]['dock']['menu_nav_ids']=[32];$options[DSA_OPTION_SETTINGS]['dock']['menu_nav_id']=32;
		$review=$service->preview_upload($bytes,$phrase);
		expect($review['changedCount']===3&&count($review['organization'])===3,'native review reports three changed units');
		expect(count($review['organization'][0]['changes'])===2&&count($review['organization'][1]['changes'])===2&&count($review['organization'][2]['changes'])===2,'review identifies every rename membership and menu policy change');
		$result=$service->apply($review['token'],$phrase);
		expect($result['restored']===3&&count($service->inventory())===2,'native settings transaction retains encrypted before-copy');
		expect($options[Tags::OPTION]===$originalOptions[Tags::OPTION]&&$termMeta===$originalTerms,'native tag assignments and folder order restored exactly');
		expect($userMeta[20][Groups::META]===[[$g3,$g1]]&&$userMeta[22][Groups::META]===[[$g1]]&&end($options[Groups::OPTION])['id']===$g3,'newer group memberships and newer accounts preserved');
		expect($userMeta[20]['dsa_notification_preferences']===$originalUsers[20]['dsa_notification_preferences']&&$userMeta[20]['session_tokens']===$originalUsers[20]['session_tokens']&&$userMeta[20]['pk_email_verified_at']===$originalUsers[20]['pk_email_verified_at'],'consent sessions and factor assurance remain untouched');
		expect($options[DSA_OPTION_SETTINGS]===$originalOptions[DSA_OPTION_SETTINGS],'native navigation writer preserves unrelated settings and secrets');
		$review=$service->preview_upload($bytes,$phrase);$userMeta[22][Groups::META]=[[$g3]];rejects(fn()=>$service->apply($review['token'],$phrase),'new membership after review invalidates whole restore');
		$review=$service->preview_upload($bytes,$phrase);$caps['kiwe_manage_team']=false;rejects(fn()=>$service->apply($review['token'],$phrase),'permission loss between review and apply denied');$caps=[];
		$options[Tags::OPTION][0]['name']='Keep before rollback';$termMeta[11][Tags::META]=['2'];$userMeta[20][Groups::META]=[[$g2,$g3]];
		$before=[$options,$termMeta,$userMeta];$review=$service->preview_upload($bytes,$phrase);$failUser=20;
		rejects(fn()=>$service->apply($review['token'],$phrase),'failed native membership write aborts combined recovery');
		expect([$options,$termMeta,$userMeta]===$before,'reverse rollback restores exact native state after partial unit failure');
		$options[Tags::OPTION]=[];unset($termMeta[11][Tags::META],$termMeta[12][Tags::META]);
		$review=$service->preview_upload($bytes,$phrase);$service->apply($review['token'],$phrase);
		expect($options[Tags::OPTION]===$originalOptions[Tags::OPTION]&&$termMeta===$originalTerms,'missing folder definitions and assignments recover against existing native tags');
		$bad=$tags;for($i=0;$i<=State::MAX_RECORDS;$i++)$bad['records'][]=$tags['records'][0];rejects(fn()=>State::validate(State::TAGS,$bad),'oversized record sets rejected instead of truncated');
		$owner=false;rejects(fn()=>State::read(State::TAGS),'unprivileged direct organization capture denied');$owner=true;
		$passed=true;
	}catch(\Throwable $e){$error=$e->getMessage();}
	finally{clean($root);$cleanup=!file_exists($root);}
	echo json_encode(['passed'=>$passed,'checks'=>$checks,'cleanup'=>$cleanup,'error'=>$error],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($passed&&$cleanup?0:1);
}
