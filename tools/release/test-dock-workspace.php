<?php
namespace DSA\Diagnostics { final class Runtime_Profiler { public static function start(){return 0;} public static function finish(...$args){} } }
namespace DSA\Access { final class Navigation_Workspace_Service { public static function url(){return 'https://example.test/wp-admin/admin.php?page=kiwe-navigation';} } }
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';define('DSA_URL','https://example.test/dsa/');define('KIWE_REACT_WORKSPACE',true);define('DSA_OPTION_SETTINGS','dsa_settings');
$allowed=true;$logged=true;$checks=[];$store=[];$race=false;$queryCount=0;
function check($ok,$label){global$checks;if(!$ok)throw new \RuntimeException($label);$checks[]=$label;}
function __($s,$domain=''){return $s;}function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}function sanitize_text_field($s){return trim(strip_tags((string)$s));}function sanitize_title($s){return str_replace(' ','-',strtolower($s));}function sanitize_hex_color($s){return preg_match('/^#[a-f0-9]{6}$/iD',$s)?$s:null;}function esc_url($s){return $s;}function esc_attr($s){return $s;}function esc_url_raw($s){return preg_match('#^https?://#',$s)?$s:'';}function absint($s){return abs((int)$s);}function wp_parse_args($a,$b){return array_merge($b,$a);}function wp_json_encode($s){return json_encode($s);}function wp_parse_url($s){return parse_url($s);}function home_url($s=''){return 'https://example.test'.$s;}function site_url($s=''){return home_url($s);}function is_admin(){return true;}
function wp_unslash($s){return is_array($s)?array_map('wp_unslash',$s):(is_string($s)?stripslashes($s):$s);}function wp_slash($s){return is_array($s)?array_map('wp_slash',$s):(is_string($s)?addslashes($s):$s);}function wp_get_nav_menus($a=[]){return [(object)['term_id'=>8,'name'=>'Main menu','count'=>2]];}function is_nav_menu($id){return $id===8;}function current_user_can($cap){return isset($GLOBALS['caps'])?!empty($GLOBALS['caps'][$cap]):$GLOBALS['allowed'];}function is_user_logged_in(){return $GLOBALS['logged'];}function wp_verify_nonce($n,$action){return $n==='nonce';}function get_option($key,$default=false){return $GLOBALS['store'][$key]??$default;}function update_option($key,$value,$autoload=null){$GLOBALS['store'][$key]=$value;return true;}function wp_cache_get(...$args){return false;}function wp_cache_delete(...$args){}function do_action(...$args){}function maybe_serialize($v){return serialize($v);}function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error {public function __construct(public $code,public $message='',public $data=[]){}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}public function get_error_data(){return $this->data;}}
class WP_REST_Request {public function __construct(private $method='GET',private $headers=['x-wp-nonce'=>'nonce','x-kiwe-mutation'=>'1'],private $json=null){}public function get_method(){return $this->method;}public function get_header($key){return $this->headers[strtolower($key)]??'';}public function get_json_params(){return $this->json;}}
class WP_REST_Response {public $headers=[];public function __construct(public $data,public $status){}public function header($name,$value){$this->headers[$name]=$value;}}
function register_rest_route($namespace,$path,$handlers){$GLOBALS['test_routes'][$namespace.$path]=$handlers;}
$wpdb=new class {public $options='wp_options';public function prepare($query,...$args){return $args;}public function query($args){global$store,$race,$queryCount;$queryCount++;if($race)return 0;[$next,$key,$expected]=$args;if(serialize($store[$key])!==$expected)return 0;$store[$key]=unserialize($next);return 1;}};
$root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';foreach(['Settings.php','Theme/Theme_Package_Service.php','Modules/Module_Registry.php','Dock/Dock_Settings_Service.php','Utilities/Origin_Checker.php','Access/Task_Workspace_UI.php','Access/Dock_Workspace_Service.php']as$file)require $root.$file;
$store['dsa_settings']=(new \DSA\Settings())->defaults();$store['dsa_settings']['phonekey']['private_key']='synthetic-preserve';$store['dsa_settings']['extension']=['future'=>'keep'];$service=new \DSA\Access\Dock_Workspace_Service();$initial=$store;
$state=$service->snapshot();check(!str_contains(json_encode($state),'synthetic-preserve'),'projection excludes authentication/provider state');check($state['menus'][0]['id']===8,'projection uses native menu identities');
$store['dsa_settings']['dock']['item_order']=array_values(array_filter($store['dsa_settings']['dock']['item_order'],static fn($id)=>$id!=='comments'));$store['dsa_settings']['dock']['enabled_items']['comments']=false;$older=$store;$available=$service->snapshot();
check(in_array('comments',$available['choices']['dock']['item_order'],true)&&$available['choices']['dock']['enabled_items']['comments']===false,'New Comments control is available when an older saved order omits it, while its off choice remains off');
$prior=array_values(array_filter($older['dsa_settings']['dock']['item_order'],static fn($id)=>isset($available['labels'][$id])));check(array_slice($available['choices']['dock']['item_order'],0,count($prior))===$prior,'New controls append without reordering existing visible buttons');check($store===$older,'Discovering a new Dock control writes no native settings');$store=$initial;$state=$service->snapshot();
$fields=(new ReflectionMethod($service,'fields'))->invoke($service);$defaults=(new \DSA\Settings())->defaults();foreach($fields as$group=>$names)foreach($names as$key=>$type)check(array_key_exists($key,$defaults[$group]),'control binds to existing native owner '.$group.'.'.$key);
check($state['choices']['commerce']===[]&&!isset($state['labels']['cart'])&&!in_array('cart',$state['choices']['dock']['item_order'],true),'inactive Store hides cart Dock and DSA choices');
$save=function($changes)use($service){return $service->update(['expected'=>$service->snapshot()['expected'],'changes'=>$changes]);};
$storedBeforeReveal=$store;
unset($store['dsa_settings']['dock']['reveal_on_scroll']);
check($service->snapshot()['choices']['dock']['reveal_on_scroll']===false&&!isset($store['dsa_settings']['dock']['reveal_on_scroll']),'An existing site gets immediate Dock visibility without a read-time write');
$result=$save(['dock'=>['reveal_on_scroll'=>true]]);
check(!is_wp_error($result)&&$result['choices']['dock']['reveal_on_scroll']===true,'The native Dock writer enables scroll reveal independently');
$result=$save(['dock'=>['mobile_horizontal_position'=>'center']]);
check(!is_wp_error($result)&&$result['choices']['dock']['reveal_on_scroll']===true,'Saving ordinary Dock choices keeps the reveal flag');
$result=$save(['dock'=>['reveal_on_scroll'=>false]]);
check(!is_wp_error($result)&&$result['choices']['dock']['reveal_on_scroll']===false,'The native Dock writer disables scroll reveal explicitly');
$store=$storedBeforeReveal;
$result=$save(['dock'=>['menu_nav_ids'=>[8],'menu_label'=>"Writer's menu",'enabled_items'=>['ai'=>false],'mobile_orientation'=>'horizontal','mobile_horizontal_position'=>'center'],'style'=>['mode'=>'sheet'],'dsa_theme'=>['active_color'=>'#552b1c']]);check(!is_wp_error($result),'valid choices save through shared normalizer');check($store['dsa_settings']['dock']['menu_nav_id']===8&&$store['dsa_settings']['dock']['menu_nav_ids']===[8],'menu primary identity follows native groups');check($store['dsa_settings']['dock']['menu_label']==="Writer's menu",'JSON text is unslashed once');check($store['dsa_settings']['dock']['enabled_items']['search']===true,'partial button save preserves unrelated buttons');check($store['dsa_settings']['phonekey']===$initial['dsa_settings']['phonekey']&&$store['dsa_settings']['extension']===$initial['dsa_settings']['extension'],'unrelated private and future fields preserved');
check(is_wp_error($service->update(['expected'=>$state['expected'],'changes'=>['enabled'=>false]])),'stale client refused');
foreach([['enabled'=>'true'],['phonekey'=>['enabled'=>true]],['dock'=>['menu_nav_ids'=>[999]]],['dock'=>['menu_nav_ids'=>['8']]],['dock'=>['mobile_orientation'=>'diagonal']],['dock'=>['enabled_items'=>['unknown'=>true]]],['dock'=>['enabled_items'=>['menu'=>'yes']]],['dock'=>['item_order'=>['menu','menu']]],['dock'=>['item_order'=>[['menu']]]],['dock'=>['custom_items'=>[['url'=>'javascript:alert(1)']]]],['dsa_theme'=>['active_color'=>'red']],['commerce'=>['cart_surface_enabled'=>true]]]as$bad){$before=$store;check(is_wp_error($save($bad))&&$store===$before,'malformed, unowned or non-Woo choice denied '.count($checks));}
$beforeHidden=$store['dsa_settings']['dock'];$visibleOrder=$service->snapshot()['choices']['dock']['item_order'];$visibleOrder=array_reverse($visibleOrder);$visibleButtons=$service->snapshot()['choices']['dock']['enabled_items'];$visibleButtons['search']=false;
$changedHidden=$save(['dock'=>['item_order'=>$visibleOrder,'enabled_items'=>$visibleButtons]]);check(!is_wp_error($changedHidden)&&$store['dsa_settings']['dock']['enabled_items']['cart']===$beforeHidden['enabled_items']['cart'],'inactive reorder and visibility save preserve dormant cart visibility');
check(array_search('cart',$store['dsa_settings']['dock']['item_order'],true)===min(array_search('cart',$beforeHidden['item_order'],true),count($visibleOrder)),'inactive reorder preserves the cart slot for re-enabling');
$noStoreBefore=$store;
$noStore=$service->snapshot();
$storeProfile=['schema'=>'kiwe.dock-profile.v1','choices'=>['dock'=>['visible'=>true,'item_order'=>['menu','cart'],'enabled_items'=>['menu'=>true,'cart'=>true],'focus_item'=>'cart'],'commerce'=>['cart_surface_enabled'=>true]]];
$noStoreResult=$service->apply_profile(['expected'=>$noStore['expected'],'profile'=>$storeProfile]);
check(!is_wp_error($noStoreResult)&&$noStoreResult['choices']['dock']['visible']&&!isset($noStoreResult['choices']['dock']['enabled_items']['cart']),'A store profile applies compatible choices on a non-store site');
check($store['dsa_settings']['commerce']===$noStoreBefore['dsa_settings']['commerce']&&$store['dsa_settings']['dock']['enabled_items']['cart']===$noStoreBefore['dsa_settings']['dock']['enabled_items']['cart'],'Store-only settings and dormant cart choices stay local');
$store=$noStoreBefore;
eval('function WC(){return (object)[];}');
check($service->snapshot()['choices']['commerce']['cart_quantity_controls']===true&&isset($service->snapshot()['labels']['cart']),'active Woo Store exposes existing cart choices and native defaults');
check(array_column($state['themes'],'id')===['legacy','kiwe2027'],'installed native themes are projected without package settings or CSS');
$legacyRaw=$store;$store['dsa_settings']['style']['sheet_origin']='above_dock';$store['dsa_settings']['style']['sheet_backdrop']='none';$capturedLegacy=$store;$resolvedLegacy=(new \DSA\Settings())->all();check($resolvedLegacy['style']['sheet_origin']==='bottom'&&$resolvedLegacy['style']['sheet_backdrop']==='fade'&&$store===$capturedLegacy,'legacy sheet choices project to modal rules without rewriting stored state');check(!isset($service->snapshot()['choices']['style']['sheet_origin'])&&$service->snapshot()['choices']['style']['sheet_backdrop']==='fade','client controls cannot reintroduce ambiguous modal choices');$store=$legacyRaw;
$beforeTheme=$store['dsa_settings'];$result=$save(['style'=>['active_theme_id'=>'kiwe2027','sheet_position'=>'right','sheet_animation'=>'soft','sheet_backdrop'=>'fade','sheet_spacing'=>'inset','sheet_duration_ms'=>450,'sheet_max_height'=>90,'sheet_width_percent'=>80]]);check(!is_wp_error($result),'installed theme and complete sheet choices save');check($store['dsa_settings']['style']['active_theme_id']==='kiwe2027'&&$store['dsa_settings']['style']['visual_profile']==='kiwe2027','theme selection pairs its actual native runtime foundation');check($store['dsa_settings']['dock']===$beforeTheme['dock']&&$store['dsa_settings']['dsa_theme']===$beforeTheme['dsa_theme'],'theme selection preserves menu modules layout and brand colours');check($store['dsa_settings']['style']['sheet_duration_ms']===450&&$store['dsa_settings']['style']['sheet_max_height']===90&&$store['dsa_settings']['style']['sheet_width_percent']===80,'integer screen dimensions read back through native owner');
$save(['style'=>['active_theme_id'=>'legacy']]);check($store['dsa_settings']['style']['visual_profile']==='legacy','legacy selection pairs its native foundation');
$store['dsa_installed_themes']=[['id'=>'qa-skin','name'=>'QA skin','profile'=>'marketplace','settings'=>['style'=>['mode'=>'classic'],'dock'=>['enabled_items'=>['menu'=>false]]],'css'=>'synthetic-css-private']];$stateTheme=$service->snapshot();check(!str_contains(json_encode($stateTheme),'synthetic-css-private')&&!isset($stateTheme['themes'][2]['settings']),'installed package internals stay private');$beforeTheme=$store['dsa_settings'];$result=$save(['style'=>['active_theme_id'=>'qa-skin']]);check(!is_wp_error($result)&&$store['dsa_settings']['style']['visual_profile']==='kiwe2027','custom installed skin selects the native modern foundation');check($store['dsa_settings']['style']['mode']===$beforeTheme['style']['mode']&&$store['dsa_settings']['dock']===$beforeTheme['dock'],'custom skin selection cannot overwrite website layout or modules');$stateTheme=$service->snapshot();$store['dsa_installed_themes'][0]['css']='changed-css';check(is_wp_error($service->update(['expected'=>$stateTheme['expected'],'changes'=>['style'=>['active_theme_id'=>'qa-skin']]])),'installed package update invalidates stale theme choice');
foreach([['active_theme_id'=>'not-installed'],['active_theme_id'=>'KIWE2027'],['visual_profile'=>'legacy'],['sheet_duration_ms'=>119],['sheet_duration_ms'=>901],['sheet_duration_ms'=>'450'],['sheet_max_height'=>97],['sheet_width_percent'=>49],['sheet_animation'=>'bounce']]as$bad){$before=$store;check(is_wp_error($save(['style'=>$bad]))&&$store===$before,'invalid theme or sheet bounds denied '.count($checks));}
$raw=$store;$race=true;check(is_wp_error($save(['dock'=>['menu_label'=>'Concurrent']]))&&$store===$raw,'atomic conflict cannot overwrite newer settings');$race=false;
$portableBefore=$store;
$profileState=$service->snapshot();
$profile=$service->export_profile($profileState);
check($profile['schema']==='kiwe.dock-profile.v1'&&isset($profile['choices']['dock']['mobile_orientation'],$profile['choices']['commerce']['cart_surface_enabled']),'Export returns versioned Dock and Store presentation');
check(!isset($profile['choices']['dock']['menu_nav_ids'],$profile['choices']['dock']['phonekey_visibility'],$profile['choices']['style']['active_theme_id'],$profile['choices']['dsa_theme'])&&!str_contains(json_encode($profile),'synthetic-preserve'),'The portable profile carries presentation and built-in themes');
$profile['choices']['dock']['visible']=true;
$profile['choices']['dock']['reveal_on_scroll']=true;
$profile['choices']['dock']['mobile_horizontal_position']='center';
$profile['choices']['style']['mode']='sheet';
$profile['choices']['style']['active_theme_id']='kiwe2027';
$profileBody=['expected'=>$profileState['expected'],'profile'=>$profile];
$portableResult=$service->apply_profile($profileBody);
check(!is_wp_error($portableResult)&&$portableResult['choices']['dock']['mobile_horizontal_position']==='center'&&$portableResult['choices']['style']['mode']==='sheet','A portable profile applies through the native Dock writer');
check($service->export_profile($portableResult)['choices']['dock']['reveal_on_scroll']===true,'Selected scroll reveal survives portable import and export');
check($store['dsa_settings']['dock']['menu_nav_ids']===$portableBefore['dsa_settings']['dock']['menu_nav_ids']&&$store['dsa_settings']['dsa_theme']===$portableBefore['dsa_settings']['dsa_theme']&&$store['dsa_settings']['phonekey']===$portableBefore['dsa_settings']['phonekey'],'Applying presentation preserves local navigation, colour and identity');
check(is_wp_error($service->apply_profile($profileBody)),'Stale portable imports fail the native revision check');
foreach ([['dock'=>['menu_nav_ids'=>[8]]],['dock'=>['enabled_items'=>['site-custom'=>true]]],['dock'=>['item_order'=>['cart','cart']]],['style'=>['active_theme_id'=>'qa-skin']],['commerce'=>['cart_quantity_controls'=>'true']]] as $bad) {
	$before=$store;$fresh=$service->snapshot();
	$badProfile=['schema'=>'kiwe.dock-profile.v1','choices'=>$bad];
	check(is_wp_error($service->apply_profile(['expected'=>$fresh['expected'],'profile'=>$badProfile]))&&$before===$store,'Out-of-scope or invalid portable choices cause no write');
}
$store=$portableBefore;
$service->routes();
$profileRoute=$GLOBALS['test_routes']['dsa/v1/workspace/dock/profile'];
check($profileRoute[0]['permission_callback']===[$service,'permission']&&$profileRoute[1]['permission_callback']===[$service,'permission'],'Exports and imports use the native Dock authorization boundary');
$exported=$service->read_profile(new WP_REST_Request());
check($exported->status===200&&$exported->headers['Cache-Control']==='no-store, private'&&$exported->data['profile']['schema']==='kiwe.dock-profile.v1','Export returns a private portable profile and destination revision');
$partial=['schema'=>'kiwe.dock-profile.v1','choices'=>['dock'=>['reveal_on_scroll'=>true]]];
$headers=['x-wp-nonce'=>'nonce','x-kiwe-mutation'=>'1'];
$body=['expected'=>$exported->data['expected'],'profile'=>$partial];
$reply=$service->save_profile(new WP_REST_Request('POST',$headers,$body));
check($reply->status===200&&$reply->data['profile']['choices']['dock']['reveal_on_scroll']===true,'Native profile route saves a single setting and returns the stored state');
$refusal=$service->save_profile(new WP_REST_Request('POST',['x-wp-nonce'=>'nonce'],$body));
check($refusal->status===403,'A profile mutation requires the native mutation proof');
$store=$portableBefore;
$allowed=false;check(is_wp_error($save(['enabled'=>false])),'direct writer enforces capability');$allowed=true;
$_SERVER['HTTP_ORIGIN']='https://example.test';check($service->permission(new WP_REST_Request('POST')),'fresh authenticated same-origin mutation accepted');
foreach([new WP_REST_Request('DELETE'),new WP_REST_Request('POST',['x-wp-nonce'=>'bad','x-kiwe-mutation'=>'1']),new WP_REST_Request('POST',['x-wp-nonce'=>'nonce'])]as$request)check(!$service->permission($request),'wrong method, nonce or mutation proof denied');
$_SERVER['HTTP_ORIGIN']='https://foreign.example';check(!$service->permission(new WP_REST_Request()),'cross-site read denied');$_SERVER['HTTP_ORIGIN']='https://example.test';$logged=false;check(!$service->permission(new WP_REST_Request()),'anonymous denied');$logged=true;$allowed=false;check(!$service->permission(new WP_REST_Request()),'unprivileged account denied');
echo json_encode(['passed'=>true,'checks'=>count($checks),'scope'=>'Actual shared settings, selective atomic writer and permission service against synthetic WordPress I/O']).PHP_EOL;
}
