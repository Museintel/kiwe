<?php
declare(strict_types=1);
require __DIR__.'/test-context-navigation.php';
class WP_REST_Request {
 public function __construct(private string $method,private string $route,private array $path=[],private array $body=[]){}
 public function get_param($key){return $this->body[$key]??$this->path[$key]??null;}
 public function get_method(){return $this->method;}
 public function get_route(){return $this->route;}
 public function get_url_params(){return $this->path;}
}

class WP_REST_Response {
 public array $headers=[];
 public function __construct(public array $data,public int $status){}
 public function header($key,$value){$this->headers[$key]=$value;}
}
function register_rest_route($namespace,$route,$args){$GLOBALS['task_routes'][$route]=$args;}
$actor=1;$denied=[];$count=0;$library->routes();$nav->routes();
foreach($task_routes as $route=>$definition){check($definition['methods']==='POST'&&is_callable($definition['permission_callback']),'narrow route with native schema and permission: '.$route);}
$requestMenu=fn($body,$item=501,$method='POST')=>new WP_REST_Request($method,'/dsa/v1/workspace/menus/70/items/'.$item.'/label',['id'=>70,'item'=>$item],$body);
$requestMedia=fn($body,$id=206,$kind='media',$method='POST')=>new WP_REST_Request($method,'/dsa/v1/workspace/context/'.$kind.'/'.$id.'/selection',['id'=>$id,'kind'=>$kind],$body);
$menuRequest=$requestMenu(['label'=>'Our studio','expected'=>$fresh(),'operation'=>'remove','id'=>71,'item'=>503,'url'=>'forged']);
check($access->guard_rest(null,null,$menuRequest)===null,'client can reach exact label adapter');
$oldTitle=$posts[503]->post_title;$result=$nav->rest_label($menuRequest);
check($result->status===200&&$result->headers['Cache-Control']==='no-store, private'&&$result->data['menuId']===70&&$result->data['itemId']===501&&$result->data['expected']===$fresh(),'menu readback is private and uses URL identity');
check($posts[501]->post_title==='Our studio'&&$posts[503]->post_title===$oldTitle&&array_keys($last_post_write)===['ID','post_title','post_name'],'forged body scope and wider mutation fields do not reach native writer');
check($nav->rest_label($menuRequest)->status===409,'stale menu submit returns conflict');
$result=$nav->rest_label($requestMenu(['label'=>'','expected'=>$fresh()]));check($result->status===200&&$posts[501]->post_title==='','restoring source label uses the same native operation');
check($nav->rest_label($requestMenu(['label'=>'No','expected'=>$fresh()],503))->status===400,'cross-menu item cannot be written');
check($nav->rest_label($requestMenu(['label'=>['invalid'],'expected'=>$fresh()]))->status===400,'direct callback rejects array label');
$after_test_action=function($hook){if($hook==='wp_update_nav_menu')$GLOBALS['posts'][502]->post_title='Changed in another editor';};
$result=$nav->rest_label($requestMenu(['label'=>'Local label','expected'=>$fresh()]));unset($after_test_action);
check($result->status===409&&$result->data['code']==='kiwe_menu_unconfirmed'&&!isset($result->data['expected'])&&$posts[501]->post_title==='Local label','concurrent other-item change never advances unseen menu fingerprints');
$fail_write=true;check($nav->rest_label($requestMenu(['label'=>'Unwritten','expected'=>$fresh()]))->status===409,'write failure requires readback before another menu save');$fail_write=false;

// Real shared selection writer, including the native form fallback's expected state.
$postmeta[206]=[];$before=count($writes);
$mediaRequest=$requestMedia(['selected'=>true,'expected'=>false,'id'=>201,'kind'=>'testimonial','post_status'=>'publish']);
check($access->guard_rest(null,null,$mediaRequest)===null&&$library->rest_permission($mediaRequest),'client object permission reaches media adapter');
$result=$library->rest_select($mediaRequest);
check($result->status===200&&$result->data['id']===206&&$result->data['selected']===true&&$result->headers['Cache-Control']==='no-store, private'&&count($writes)===$before+1,'one native curation write with canonical identity and private readback');
check($library->rest_select($mediaRequest)->status===409,'stale selection cannot overwrite newer membership');
check($library->rest_select($requestMedia(['selected'=>'false','expected'=>true]))->status===400,'direct callback rejects non-boolean values');
$posts[206]->post_status='private';check($library->rest_select($requestMedia(['selected'=>true,'expected'=>true]))->status===400,'selecting now-private media is denied');
$result=$library->rest_select($requestMedia(['selected'=>false,'expected'=>true]));
check($result->status===200&&!$result->data['selected']&&!$result->data['canSelect'],'revoked public media can still be removed with honest eligibility');$posts[206]->post_status='inherit';
$commentmeta[401]=[];$result=$library->rest_select($requestMedia(['selected'=>true,'expected'=>false],401,'testimonial'));
check($result->status===200&&!str_contains(json_encode($result->data),'private@example')&&!isset($result->data['quote']),'testimonial result reveals no personal source fields');
$denied=['moderate_comments'];check(!$library->rest_permission($requestMedia([],401,'testimonial'))&&$library->rest_select($requestMedia(['selected'=>false,'expected'=>true],401,'testimonial'))->status===403,'moderation permission checked on direct callback');
$denied=['edit_post:206'];check($library->rest_select($requestMedia(['selected'=>true,'expected'=>false]))->status===403,'object edit denial precedes membership readback');$denied=[];
$fail_write=true;check($library->rest_select($requestMedia(['selected'=>true,'expected'=>false]))->status===409,'failed selection write is unconfirmed');$fail_write=false;
foreach([3,4,5,6,0] as $actor){
 check(!$nav->rest_permission($menuRequest)&&!$library->rest_permission($mediaRequest),'unauthorized role denied: '.$actor);
 check($nav->rest_label($menuRequest)->status===403&&$library->rest_select($mediaRequest)->status===403,'direct callbacks enforce role: '.$actor);
}
$actor=1;
foreach(['GET','PUT','PATCH','DELETE'] as $method){check(!$nav->rest_permission($requestMenu([],501,$method))&&!$library->rest_permission($requestMedia([],206,'media',$method)),'write callbacks reject other methods: '.$method);}
foreach(['/dsa/v1/workspace/menus/70','/dsa/v1/workspace/menus/70/items/501/label/extra','/dsa/v1/workspace/context/users/206/selection','/wp/v2/menu-items/501'] as $route){check(is_wp_error($access->guard_rest(null,null,new WP_REST_Request('POST',$route))),'no wider route granted: '.$route);}
$_SERVER['REQUEST_METHOD']='POST';$_POST=['kind'=>'media','record_id'=>'206','selected'=>'1','expected'=>'1','_wpnonce'=>DSA\Access\Context_Workspace_Service::ACTION.'_media_206'];
check(denied(fn()=>$library->handle())&&!DSA\Onboarding\Context_Selection_Service::selected('media',206),'native fallback shares stale-selection protection');
echo "$count reactive task REST checks passed.\n";

// Optional synthetic HTML from the actual PHP renderers, for one grouped browser review.
if (isset($argv[1])) {
 function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES);} function esc_attr__($s,$d=''){return esc_attr($s);} function esc_url($s){return esc_attr($s);}
 function rest_url($s){return 'https://example.test/wp-json/'.$s;}
 function wp_nonce_field($s){echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($s).'">';}
 function selected($a,$b,$echo=false){return $a===$b?' selected':'';}
 function wp_get_nav_menus($args){return [wp_get_nav_menu_object(70)];}
 function get_posts($args){return array_values(array_filter($GLOBALS['posts'],fn($p)=>$p->post_type==='page'&&$p->post_status==='publish'));}
 function get_edit_post_link($id){return admin_url('post.php?post='.$id.'&action=edit');}
 function wp_attachment_is_image($id){return false;}
 function get_the_title($id){return get_post($id)->post_title??'';}
 function wp_trim_words($text,$count){return implode(' ',array_slice(explode(' ',$text),0,$count));}
 $actor=1; $denied=[]; $query_posts=[$posts[201],$posts[206]];
 $posts[201]->post_title='Brand guidelines';$posts[206]->post_title='Studio welcome audio';
 $posts[501]->post_title='Our studio';$posts[501]->title='Our studio';$posts[502]->title='Contact';$posts[502]->post_title='Contact';
 $posts[900]->title='Treatments';$posts[900]->post_title='';
 $_GET=['menu_id'=>'70'];ob_start();$nav->render();$navigation=ob_get_clean();
 $_GET=['kind'=>'media','view'=>'all'];ob_start();$library->render();$media=ob_get_clean();
 $_GET=['kind'=>'testimonial','view'=>'all'];ob_start();$library->render();$testimonials=ob_get_clean();
 foreach(['navigation'=>$navigation,'media'=>$media,'testimonials'=>$testimonials] as $name=>$html){
  check(str_contains($html,'data-kiwe-task=')&&str_contains($html,'data-kiwe-save-state')&&str_contains($html,'name="_wpnonce"')&&str_ends_with($html,'</div>'),'actual full PHP renderer includes native and enhanced controls: '.$name);
  if(!is_dir($argv[1]))throw new RuntimeException('Supply an existing external fixture directory.');
  file_put_contents(rtrim($argv[1],'/\\').'/'.$name.'.html',$html);
 }
}

// Disabled portable capture must not resolve an optional module on ordinary
// client requests; an absent enabled module also stays denied without a fatal.
$actor=1;$options['kiwe_site_owner_policy_v1']=[];$portableAutoloads=0;
$portableProbe=static function($class)use(&$portableAutoloads){if($class==='DSA\\Access\\Portable_Capture_App_Service')++$portableAutoloads;};
spl_autoload_register($portableProbe);
foreach(['','/'.str_repeat('a',32).'/queue','/'.str_repeat('a',32).'/remove']as$suffix){
 $request=new WP_REST_Request('POST','/dsa/v1/workspace/site-captures'.$suffix);
 check($access->guard_rest(null,null,$request) instanceof WP_Error,'disabled capture stays denied without resolving its optional module: '.$suffix);
}
check($portableAutoloads===0&&!class_exists('DSA\\Access\\Portable_Capture_App_Service',false),'disabled capture never invokes an optional module autoloader');
define('KIWE_PORTABLE_CAPTURE_PREVIEW',true);
foreach(['','/'.str_repeat('a',32).'/queue','/'.str_repeat('a',32).'/remove']as$suffix){
 $request=new WP_REST_Request('POST','/dsa/v1/workspace/site-captures'.$suffix);
 check($access->guard_rest(null,null,$request) instanceof WP_Error,'missing enabled capture module fails closed without a fatal: '.$suffix);
}
spl_autoload_unregister($portableProbe);
