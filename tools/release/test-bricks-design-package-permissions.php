<?php
declare(strict_types=1);
namespace DSA\Backup {
	final class Archive_Error extends \RuntimeException {}
	final class Bricks_Design_Package_Service {
		public static bool $allowed=false; public static int $writes=0;
		public static function allowed():bool{return self::$allowed;}
		public function apply_settings(string $id,string $phrase,int $revision):array{self::$writes++;return ['id'=>$id,'mode'=>'import','status'=>'complete'];}
		public function remove(string $id):array{self::$writes++;return ['removed'=>true];}
	}
}
namespace DSA\Access {
	final class Content_Archive_App_Service {public static bool $guard=true;public function permission(\WP_REST_Request $request):bool{return self::$guard;}}
	final class Task_Workspace_UI {public static function failure(\WP_Error $error,int $status):\WP_REST_Response{return new \WP_REST_Response(['error'=>$error->message],$status);}public static function response(array $data):\WP_REST_Response{return new \WP_REST_Response($data,200);}}
}
namespace {
	define('ABSPATH',__DIR__);$read=true;$hooks=[];$routes=[];$checks=[];
	function current_user_can(string $cap):bool{return $GLOBALS['read'];}
	function add_action($name,$callback):void{$GLOBALS['hooks'][]=$name;}
	function register_rest_route($ns,$path,$definition):void{$GLOBALS['routes'][$path]=$definition;}
	class WP_REST_Request {public function __construct(public string $route,public array $body=[]){}public function get_route():string{return $this->route;}public function get_json_params():array{return $this->body;}public function get_param(string $key){return $key==='id'?str_repeat('a',32):null;}}
	class WP_REST_Response {public function __construct(public array $data,public int $status){}}
	class WP_Error {public function __construct(public string $code,public string $message){}}
	require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Bricks_Design_Package_App_Service.php';
	$check=static function(bool $ok,string $label)use(&$checks):void{$checks[$label]=$ok;if(!$ok)throw new \RuntimeException($label);};$app=new \DSA\Access\Bricks_Design_Package_App_Service();
	$check($app->permission(new WP_REST_Request('/workspace/design-packages/jobs')),'own inventory entrance stays available when native design permission is lost');
	$check($app->permission(new WP_REST_Request('/workspace/design-packages/jobs/'.str_repeat('a',32).'/remove')),'own private cleanup entrance needs read plus the existing request guard');
	foreach(['export','preview','jobs/'.str_repeat('a',32).'/step','jobs/'.str_repeat('a',32).'/review_settings','jobs/'.str_repeat('a',32).'/apply_settings']as$path)$check(!$app->permission(new WP_REST_Request('/workspace/design-packages/'.$path)),'lost native access denies '.$path);
	\DSA\Backup\Bricks_Design_Package_Service::$allowed=true;$check($app->permission(new WP_REST_Request('/workspace/design-packages/export')),'full native design access reaches the guarded export entrance');
	\DSA\Access\Content_Archive_App_Service::$guard=false;$check(!$app->permission(new WP_REST_Request('/workspace/design-packages/export'))&&!$app->permission(new WP_REST_Request('/workspace/design-packages/jobs')),'existing nonce origin mutation guard remains required');\DSA\Access\Content_Archive_App_Service::$guard=true;
	$read=false;$check(!$app->permission(new WP_REST_Request('/workspace/design-packages/jobs'))&&!$app->permission(new WP_REST_Request('/workspace/design-packages/jobs/'.str_repeat('a',32).'/remove')),'read capability loss denies private inventory and cleanup');$read=true;
	$app->register();$check(!$hooks,'disabled workspace registers no package hooks');define('KIWE_REACT_WORKSPACE',true);$app->register();$check(count($hooks)===2,'enabled workspace registers existing REST and download hook families');$app->routes();$check(count($routes)===7,'fixed seven routes expose no arbitrary restore action');
	foreach(['export','preview','step','review_settings','apply_settings','remove']as$action){$path=in_array($action,['export','preview'],true)?'/workspace/design-packages/'.$action:'/workspace/design-packages/jobs/(?P<id>[a-f0-9]{32})/'.$action;$check($routes[$path]['methods']==='POST'&&$routes[$path]['permission_callback']===[ $app,'permission' ],'mutation '.$action.' retains POST and shared permission guard');}
	$body=['passphrase'=>'Synthetic guarded recovery phrase','revision'=>1,'confirm'=>false];$check($app->job_apply_settings(new WP_REST_Request('/apply_settings',$body))->status===400&&\DSA\Backup\Bricks_Design_Package_Service::$writes===0,'unconfirmed settings action cannot call the native writer');$body['confirm']=true;$body['revision']='1';$check($app->job_apply_settings(new WP_REST_Request('/apply_settings',$body))->status===400&&\DSA\Backup\Bricks_Design_Package_Service::$writes===0,'string revision cannot enter the native writer');$body['revision']=1;$check($app->job_apply_settings(new WP_REST_Request('/apply_settings',$body))->status===200&&\DSA\Backup\Bricks_Design_Package_Service::$writes===1,'explicit reviewed confirmation calls only the fixed settings owner');
	$check($app->job_remove(new WP_REST_Request('/remove',['confirm'=>false]))->status===400,'unconfirmed private removal refused');$check($app->job_remove(new WP_REST_Request('/remove',['confirm'=>true]))->data['removed'],'explicit private removal calls the native job owner');
	echo json_encode(['passed'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}
