<?php
declare(strict_types=1);
require __DIR__.'/test-native-pages.php';
class WP_REST_Request {
 public function __construct(private string $method,private string $route,private array $params=[]){}
 public function get_param($key){return $this->params[$key]??null;}
 public function get_method(){return $this->method;}
 public function get_route(){return $this->route;}
}
class WP_REST_Response {
 public array $headers=[];
 public function __construct(public array $data,public int $status){}
 public function header($key,$value){$this->headers[$key]=$value;}
}
function register_rest_route($namespace,$route,$args){$GLOBALS['registered_route']=[$namespace,$route,$args];}
$actor=1;$n=0;
$pages->routes();
check($registered_route[2]['methods']==='POST'&&is_callable($registered_route[2]['permission_callback']),'REST exposes only the scoped POST with a permission callback');
$request=new WP_REST_Request('POST','/dsa/v1/workspace/pages/11/name',['id'=>11,'page_title'=>'Our studio','expected_title'=>"Client's story",'post_content'=>'forged','post_name'=>'forged']);
check($access->guard_rest(null,null,$request)===null&&$pages->rest_title_permission(),'client title permission reaches the narrow adapter');
$response=$pages->rest_title($request);
check($response instanceof WP_REST_Response&&$response->status===200&&$response->data['title']==='Our studio'&&$response->headers['Cache-Control']==='no-store, private','REST returns the native readback with private no-store');
check($test_posts[11]->post_name==='original-url'&&$test_posts[11]->post_content==='Keep content','REST ignores forged layout and URL fields');
$calls=$page_update_calls;$response=$pages->rest_title($request);
check($response instanceof WP_Error&&$response->get_error_data()['status']===409&&$page_update_calls===$calls,'REST reports stale edits as 409 without a write');
foreach(['PUT','PATCH','DELETE']as $method)check($access->guard_rest(null,null,new WP_REST_Request($method,$request->get_route())) instanceof WP_Error,'other workspace methods remain blocked: '.$method);
foreach(['/dsa/v1/workspace/pages/11','/dsa/v1/workspace/pages/11/name/extra']as $route)check($access->guard_rest(null,null,new WP_REST_Request('POST',$route)) instanceof WP_Error,'no broad Kiwe page write route is granted: '.$route);
check($access->guard_rest(null,null,new WP_REST_Request('POST','/wp/v2/pages/11'))===null,'client Bricks page design reaches native REST object and nonce checks');
foreach([3,4]as $actor){
 check($access->guard_rest(null,null,new WP_REST_Request('POST','/wp/v2/pages/11')) instanceof WP_Error,'non-builder cannot write native Page content: '.$actor);
 check(!$pages->rest_title_permission(),'non-client cannot name pages: '.$actor);
 $response=$pages->rest_title($request);
 check($response instanceof WP_Error&&$response->get_error_data()['status']===403&&$page_update_calls===$calls,'direct callback rechecks permission: '.$actor);
}
$actor=0;check(!$pages->rest_title_permission(),'anonymous request cannot name pages');
$actor=2;check($pages->rest_title_permission(),'technical owner retains native page authority');
$actor=1;$page_write_silent=true;
$response=$pages->rest_title(new WP_REST_Request('POST',$request->get_route(),['id'=>11,'page_title'=>'Unconfirmed','expected_title'=>'Our studio']));
check($response instanceof WP_Error&&$response->get_error_data()['status']===400,'native readback failure cannot return a saved response');
echo "$n workspace REST behavior checks passed.\n";
