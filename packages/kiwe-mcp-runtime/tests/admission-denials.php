<?php
declare(strict_types=1);

namespace DSA\Utilities {
 final class Atomic_Rate_Limiter { public static function allow(...$arguments):bool { return true; } }
 final class Origin_Checker { public static function client_ip():string { return '192.0.2.18'; } }
}
namespace DSA\MCP {
 final class Authorization_Service {}
 final class Auth_Context {
  public static ?array $context=null;
  public static array $grant=['client'=>'fixture-client','scopes'=>['mcp.read']];
  public static function clear():void { self::$context=null; }
  public static function establish($service,string $token):array {
   \wp_set_current_user(42);
   return self::$context=self::$grant;
  }
 }
 final class Urls {
  public const NS='kiwe-mcp/v1';
  public static function request_host_matches():bool { return true; }
  public static function challenge(...$arguments):string { return 'Bearer resource_metadata="https://fixture.invalid/metadata"'; }
 }
 final class HTTP_Controller {
  public bool $originAllowed=false;
  public function origin_for_client(...$arguments):bool { return $this->originAllowed; }
  public static function response(array $data,int $status):\WP_REST_Response { return new \WP_REST_Response($data,$status); }
 }
}
namespace {
 define('ABSPATH',__DIR__);
 $actor=0;
 function wp_set_current_user(int $id):void { $GLOBALS['actor']=$id; }
 final class WP_REST_Request {
  public function __construct(private string $route,private string $authorization='') {}
  public function get_route():string { return $this->route; }
  public function get_method():string { return 'POST'; }
  public function get_body():string { return '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'; }
  public function get_header(string $header):string {
   return $header==='Authorization'&&$this->authorization!==''?'Bearer '.$this->authorization:'';
  }
 }
 final class WP_REST_Response {
  public array $headers=[];
  public function __construct(public array $data,public int $status) {}
  public function header(string $key,string $value):void { $this->headers[$key]=$value; }
 }
 $source=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/MCP/';
 require $source.'OAuth_Failure.php';
 require $source.'Engine.php';
 $type=new ReflectionClass(DSA\MCP\Engine::class);
 $engine=$type->newInstanceWithoutConstructor();
 $http=new DSA\MCP\HTTP_Controller();
 foreach(['admissionDenials'=>new WeakMap(),'auth'=>new DSA\MCP\Authorization_Service(),'http'=>$http] as $key=>$value)
  $type->getProperty($key)->setValue($engine,$value);
 $checks=0;
 $check=static function(bool $ok,string $message)use(&$checks):void {
  ++$checks;
  if(!$ok)throw new RuntimeException($message);
 };
 $path='/kiwe-mcp/v1/mcp';
 foreach([
  ['anonymous','',false,['mcp.read'],401,'invalid_token'],
  ['wrong client origin','kiwe_mcp_access_fixture',false,['mcp.read'],403,'access_denied'],
  ['missing scope','kiwe_mcp_access_fixture',true,[],403,'insufficient_scope'],
  ['unavailable transport','kiwe_mcp_access_fixture',true,['mcp.read'],503,'temporarily_unavailable'],
 ] as [$name,$token,$origin,$scopes,$status,$error]){
  $request=new WP_REST_Request($path,$token);
  $http->originAllowed=$origin;
  DSA\MCP\Auth_Context::$grant=['client'=>'fixture-client','scopes'=>$scopes];
  $early=$engine->admit(null,null,$request);
  $check($early instanceof WP_REST_Response&&$early->status===$status&&$early->data['error']===$error,$name.' is rejected at its native admission gate');
  $check(DSA\MCP\Auth_Context::$context===null&&$actor===0,$name.' leaves no granted actor context behind');
  // An existing third-party REST integration discards the earlier result.
  $afterForeignFilter=null;
  $late=$engine->preserve_admission_denial($afterForeignFilter,null,$request);
  $check($late===$early,$name.' retains its original refusal after a later response reset');
  if(in_array($status,[401,403],true))$check(str_starts_with($late->headers['WWW-Authenticate']??'','Bearer '),$name.' retains the client authentication challenge');
 }
 $other=new WP_REST_Request('/wp/v2/posts');
 $native=new WP_REST_Response(['native'=>true],418);
 $check($engine->admit($native,null,$other)===$native&&$engine->preserve_admission_denial($native,null,$other)===$native,
  'Another REST resource retains its own native result');
 $fresh=new WP_REST_Request($path,'kiwe_mcp_access_other');
 $check($engine->preserve_admission_denial(null,null,$fresh)===null,'A new request cannot inherit an earlier request refusal');
 echo json_encode(['passed'=>true,'checks'=>$checks,'laterFilterMayResetResponse'=>true]),PHP_EOL;
}
