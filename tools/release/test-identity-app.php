<?php
declare(strict_types=1);
define('KIWE_REACT_WORKSPACE', true);
require __DIR__.'/test-design-context-native.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Identity_App_Service.php';
function wp_json_encode($v){return json_encode($v);} function wp_parse_url($v){return parse_url($v);} function site_url($p=''){return 'https://test.example'.$p;}
function home_url($p=''){return 'https://test.example'.$p;} function is_user_logged_in(){return true;} function is_admin(){return true;} function is_network_admin(){return false;} function is_user_admin(){return false;} function wp_doing_ajax(){return false;} function wp_doing_cron(){return false;}
function wp_create_nonce($v){return 'wp_rest';} function rest_url($v){return 'https://test.example/wp-json/'.$v;} function admin_url($v=''){return 'https://test.example/wp-admin/'.$v;}
function wp_enqueue_media(){} function wp_enqueue_style(...$v){} function wp_enqueue_script(...$v){} function wp_localize_script(...$v){} function wp_print_styles(...$v){} function wp_print_scripts(...$v){} function nocache_headers(){} function language_attributes(){echo 'lang="en"';}
function register_rest_route($namespace,$route,$definition){$GLOBALS['identity_routes'][$route]=$definition;} function add_action($hook,$callback,...$args){$GLOBALS['identity_hooks'][$hook]=$callback;} function add_menu_page(...$args){$GLOBALS['identity_menu'][]=$args;return 'hook';}
class WP_REST_Request {public function __construct(private string $method='GET',private array $body=[],private array $headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1']){} public function get_method(){return $this->method;} public function get_json_params(){return $this->body;} public function get_header($key){return array_change_key_case($this->headers)[strtolower($key)]??'';}}
class WP_REST_Response {public array $headers=[];public function __construct(public array $data,public int $status){} public function header($k,$v){$this->headers[$k]=$v;}}
use DSA\Access\Identity_App_Service as App;
use DSA\Onboarding\Design_Context_Profile_Service as Context;
$app=new App();$app->register();$app->routes();
check(count($identity_routes)===1&&count($identity_hooks)===3,'identity app is opt-in and registers one private route/document');$app->menu();check(count($identity_menu)===1&&$identity_menu[0][3]==='kiwe-identity','identity screen is directly reachable from the native app shell');
$read=$app->read(new WP_REST_Request());check($read->status===200&&array_keys($read->data)===['siteName','tagline','industry','industrySector','siteType','logoId','logoInverseId','siteIconId','phone','email','logoUrl','logoInverseUrl','siteIconUrl','squareLogoId','squareLogoUrl','roundLogoId','roundLogoUrl','activities','expected'],'identity projection is explicit and private');
$flat=['expected'=>$read->data['expected'],'siteName'=>'Injected','changes'=>['tagline'=>'Allowed shape'],'option'=>'forged'];check($app->save(new WP_REST_Request('POST',$flat))->status===400,'identity request rejects flat fields and top-level injection');
$body=['expected'=>$read->data['expected'],'changes'=>['siteName'=>'Updated studio','tagline'=>'A clearer promise','industry'=>'Design studio','industrySector'=>'professional_services','siteType'=>'service','logoId'=>12,'logoInverseId'=>12,'siteIconId'=>12]];
$before=$options;$saved=$app->save(new WP_REST_Request('POST',$body));check($saved->status===200&&$saved->data['siteName']==='Updated studio'&&$saved->data['tagline']==='A clearer promise','identity save uses selective native Design Context writer and readback');check($options['blogname']==='Updated studio'&&$options['blogdescription']==='A clearer promise'&&$options[Context::OPTION_PROFILE]['identity']['siteType']==='service','native WordPress values and context mirror are updated together');check($options[Context::OPTION_PROFILE]['contact']['email']===$before[Context::OPTION_PROFILE]['contact']['email'],'selective identity update preserves unrelated contact values');
check($app->save(new WP_REST_Request('POST',$body))->status===409,'stale identity fingerprint is rejected');
$fresh=$app->read(new WP_REST_Request())->data;$conflict=$app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['siteName'=>'Another editor']]));check($conflict->status===200&&$conflict->data['siteName']==='Another editor'&&$conflict->data['tagline']==='A clearer promise','current fingerprint permits one atomic field save without replacing siblings');
$fresh=$app->read(new WP_REST_Request())->data;$forged=$app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['tagline'=>'Safe','contact'=>['email'=>'forged@example.test']]]));check($forged->status===400&&$options['blogdescription']==='A clearer promise','unknown nested identity fields reject the entire mutation');
check($app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>[]]))->status===400,'empty identity mutation is rejected');
check($app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['logoId'=>13]]))->status===400&&$app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['siteIconId'=>'12']]))->status===400,'identity images require real image attachment integers');
$cleared=$app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['logoId'=>0,'logoInverseId'=>0,'siteIconId'=>0]]));check($cleared->status===200&&$cleared->data['logoId']===0&&$cleared->data['siteIconId']===0&&$options['logo']===0&&$options['inverse']===0&&$options['theme_custom_logo']===0&&$options['site_icon']===0,'explicit image removal reaches every native identity owner and remains a valid incomplete setup state');
$requestBody=['expected'=>$cleared->data['expected'],'changes'=>['tagline'=>'No access']];
if(!function_exists('get_post')){function get_post($id){return $GLOBALS['identity_test_attachments'][$id]??null;}}
$identity_test_attachments=[12=>(object)['ID'=>12,'post_type'=>'attachment','post_status'=>'inherit','post_password'=>'','post_parent'=>0]];
$fresh=$app->read(new WP_REST_Request())->data;
$activities=array_fill_keys(array_keys($fresh['activities']),false);$activities['commerce']=true;$activities['publication']=true;
$mixed=$app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['activities'=>$activities,'squareLogoId'=>12,'roundLogoId'=>12]]));
check($mixed->status===200&&$mixed->data['activities']===$activities&&$mixed->data['squareLogoId']===12,'mixed business activities and optional public logos share the existing selective writer');
check($app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['tagline'=>'Stale']]))->status===409,'new activity and asset changes participate in the stale fingerprint');
$fresh=$mixed->data;$before=$options;
foreach([['publication'=>true],$activities+['forged'=>true],array_replace($activities,['publication'=>'1'])]as$invalid){$bad=$app->save(new WP_REST_Request('POST',['expected'=>$fresh['expected'],'changes'=>['tagline'=>'Do not save','activities'=>$invalid]]));check($bad->status===400&&$options===$before,'invalid activity map rejects the whole mutation without side effects');}
$identity_test_attachments[12]->post_password='protected';
$bad=$app->save(new WP_REST_Request('POST',['expected'=>$app->read(new WP_REST_Request())->data['expected'],'changes'=>['roundLogoId'=>12]]));
check($bad->status===400&&$options===$before,'optional business logos cannot publish a protected attachment');
check($app->read(new WP_REST_Request())->data['roundLogoId']===0,'a later native media privacy change is revalidated on read');
$identity_test_attachments[12]->post_password='';
$requestBody=['expected'=>$app->read(new WP_REST_Request())->data['expected'],'changes'=>['tagline'=>'No access']];
check($app->read(new WP_REST_Request('POST'))->status===403&&$app->save(new WP_REST_Request('GET',$requestBody))->status===403,'wrong methods cannot read/write');
foreach([[],['X-WP-Nonce'=>'bad','X-Kiwe-Mutation'=>'1'],['X-WP-Nonce'=>'wp_rest']] as $headers){$request=new WP_REST_Request('POST',$requestBody,$headers);check($app->save($request)->status===403,'identity mutation requires nonce and proof');}
$_SERVER['HTTP_ORIGIN']='https://other.example';check($app->read(new WP_REST_Request())->status===403&&$app->save(new WP_REST_Request('POST',$requestBody))->status===403,'cross-origin identity access is denied');unset($_SERVER['HTTP_ORIGIN']);
$actor=3;check($app->read(new WP_REST_Request())->status===403&&$app->save(new WP_REST_Request('POST',$requestBody))->status===403,'lower role cannot access business identity');$actor=1;
echo "Identity app boundary checks passed.\n";
