<?php
namespace DSA\Apps {final class Runtime_Registry {public static bool $installed=true;public static function installed($id){return self::$installed;}public static function provider($id){return null;}}}
namespace {
 define('ABSPATH',__DIR__);$hooks=[];$reads=0;$enabled='1';
 function add_action($name,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][$name][]=$callback;}function get_option($key,$default=null){$GLOBALS['reads']++;return$GLOBALS['enabled'];}
 function wp_generate_uuid4(){return'new-fixture-epoch';}function update_option($key,$v,$autoload=false){$GLOBALS['writes'][$key]=$v;}
 $root=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/Apps/';require$root.'Optional_App_Service.php';require$root.'MCP_App_Service.php';
 use DSA\Apps\MCP_App_Service as App;
 $checks=0;function check($v,$why){$GLOBALS['checks']++;if(!$v)throw new RuntimeException($why);}
 foreach(['1','0']as$choice){$enabled=$choice;foreach(['/','/shop/','/wp-json/wp/v2/posts','/wp-admin/admin.php?page=kiwe-workspace','/site/.well-known/acme-challenge/value']as$uri){$_SERVER['REQUEST_URI']=$uri;$_GET=$_POST=$_REQUEST=[];$hooks=[];$reads=0;(new App())->register();check(!isset($hooks['after_setup_theme'])&&!isset($hooks['plugins_loaded'])&&$reads===0,'Ordinary render/admin path loads no MCP engine or option: '.$uri);}}
 foreach(['/wp-json/kiwe-mcp/v1/mcp','/site/.well-known/oauth-authorization-server','/.well-known/oauth-protected-resource/wp-json/kiwe-mcp/v1/mcp','/wp-admin/admin.php?page=kiwe-mcp-app']as$uri){$_SERVER['REQUEST_URI']=$uri;$_GET=$_REQUEST=[];if(str_contains($uri,'?')){$_GET=['page'=>'kiwe-mcp-app'];$_REQUEST=$_GET;}$hooks=[];(new App())->register();check(isset($hooks['after_setup_theme'])&&!isset($hooks['plugins_loaded']),'Explicit MCP intent waits for native rewrite initialization');}
 $_GET=['rest_route'=>'/kiwe-mcp/v1/mcp'];$_SERVER['REQUEST_URI']='/?rest_route=%2Fkiwe-mcp%2Fv1%2Fmcp';check(App::relevant_request(),'Default-permalink MCP request is explicit intent');
 $_GET=[];$_REQUEST=['action'=>'kiwe_mcp_consent'];check(App::relevant_request(),'Native OAuth consent is explicit intent');
 $enabled='0';check(!App::requested(),'Unconfigured/off optional App defaults off');$enabled='1';\DSA\Apps\Runtime_Registry::$installed=false;check(!App::enabled(),'Missing physical App cannot turn itself on');\DSA\Apps\Runtime_Registry::$installed=true;
 $hooks=[];(new App())->register();$updated=end($hooks['updated_option']);$writes=[];$updated('kiwe_mcp_app_enabled_v1','1','0');check(isset($writes['kiwe_mcp_revocation_epoch_v1']),'App Off invalidates delegated access');
 $writes=[];$updated('active_plugins',['kiwe-mcp/kiwe-mcp.php'],[]);check(isset($writes['kiwe_mcp_revocation_epoch_v1']),'Native deactivation invalidates delegated access');
 $writes=[];$updated('active_plugins',['other/plugin.php'],[]);check(!$writes,'Other plugin changes do not revoke MCP connections');
 echo json_encode(['passed'=>true,'checks'=>$checks,'kind'=>'source-level request admission; native render cost is qualified separately']).PHP_EOL;
}
