<?php
/** Actual hook registration and document policy; synthetic WordPress I/O, no credentials or network. */
namespace DSA {
 final class Settings { public function __construct(public array $data){} public function all(){return $this->data;} public function get($key,$default=null){return $this->data[$key]??$default;} }
}
namespace DSA\Public_Endpoint {
 function header($value){$GLOBALS['headers'][]=$value;}
 function headers_sent(){return false;}
}
namespace {
 define('ABSPATH',__DIR__);$root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
 require $root.'Environment.php';require $root.'Public_Endpoint/Assets.php';require $root.'Public_Endpoint/Surface_Renderer.php';
 $hooks=[];$blocked=[];$headers=[];$nocache=0;$actions=[];
 function add_action($hook,$callback,$priority=10){$GLOBALS['hooks'][$hook][]=$callback;}function add_filter($hook,$callback,$priority=10){add_action($hook,$callback,$priority);}
 function apply_filters($hook,$value){return $hook==='dsa_should_render_frontend'?empty($GLOBALS['blocked']['filter']):$value;}
 function is_admin(){return !empty($GLOBALS['blocked']['admin']);}function wp_doing_ajax(){return !empty($GLOBALS['blocked']['ajax']);}function wp_doing_cron(){return !empty($GLOBALS['blocked']['cron']);}
 function wp_is_json_request(){return !empty($GLOBALS['blocked']['json']);}function is_feed(){return !empty($GLOBALS['blocked']['feed']);}function is_preview(){return !empty($GLOBALS['blocked']['preview']);}function is_customize_preview(){return !empty($GLOBALS['blocked']['customize']);}
 function bricks_is_builder(){return !empty($GLOBALS['blocked']['builder']);}function bricks_is_builder_call(){return !empty($GLOBALS['blocked']['builder_call']);}
 function nocache_headers(){$GLOBALS['nocache']++;}function do_action($hook,...$args){$GLOBALS['actions'][]=$hook;}
 function check($ok,$name){if(!$ok)throw new \RuntimeException($name);echo "PASS $name\n";}
 function service($class,$settings){$r=new \ReflectionClass($class);$object=$r->newInstanceWithoutConstructor();$r->getProperty('settings')->setValue($object,new \DSA\Settings($settings));return $object;}
 $base=['enabled'=>true,'phonekey'=>['enabled'=>true],'diagnostics'=>['raw_convert_test_mode'=>true,'accessibility_preview_mode'=>false]];
 $cases=[
  ['clean conversion',[],[],false],['explicit login',['kiwe-auth'=>'1'],[],true],['explicit profile',['kiwe-screen'=>'profile'],[],true],
  ['wrong profile',['kiwe-screen'=>'account'],[],false],['array profile',['kiwe-screen'=>['profile']],[],false],
  ['wrong value',['kiwe-auth'=>'0'],[],false],['array query',['kiwe-auth'=>['1']],[],false],['padded value',['kiwe-auth'=>' 1'],[],false],
  ['unrelated query',['kiwe-auth-proof'=>'1'],[],false],['disabled Key',['kiwe-auth'=>'1'],['phonekey'=>['enabled'=>false]],false],
  ['disabled surface',['kiwe-auth'=>'1'],['enabled'=>false],false],
  ['accessibility preview',[],['diagnostics'=>['accessibility_preview_mode'=>true]],true],
  ['ordinary mode',[],['diagnostics'=>['raw_convert_test_mode'=>false]],true],
 ];
 foreach($cases as [$label,$query,$changes,$expected]){
  $_GET=$query;$_SERVER['REQUEST_METHOD']='GET';$settings=array_replace_recursive($base,$changes);
  foreach(['Assets'=>'wp_enqueue_scripts','Surface_Renderer'=>'wp_footer']as $name=>$hook){$hooks=[];$object=service('DSA\\Public_Endpoint\\'.$name,$settings);$object->register();check(!empty($hooks[$hook])===$expected,$name.' registration: '.$label);}
 }
 foreach([['kiwe-auth'=>'1'],['kiwe-screen'=>'profile']]as $query){$_GET=$query;foreach(['POST','OPTIONS']as $method){$_SERVER['REQUEST_METHOD']=$method;check(!\DSA\Environment::allows_surface_registration($base),'non-document method denied: '.$method);}}
 $_GET=['kiwe-auth'=>'1'];
 $_SERVER['REQUEST_METHOD']='HEAD';check(\DSA\Environment::allows_surface_registration($base),'HEAD has the same document policy');$_SERVER['REQUEST_METHOD']='GET';
 $assets=service('DSA\\Public_Endpoint\\Assets',$base);$hooks=[];$assets->register();check(isset($hooks['template_redirect']),'explicit login registers document cache protection');
 foreach(['admin','ajax','cron','json','feed','preview','customize','builder','builder_call','filter']as $context){$blocked=[$context=>true];$headers=[];$nocache=0;$assets->protect_auth_document();check(!$headers&&!$nocache,'non-rendering context remains excluded: '.$context);}
 $blocked=[];$assets->protect_auth_document();check($nocache===1&&defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE&&in_array('litespeed_control_set_nocache',$actions,true),'auth document tells WordPress and LiteSpeed not to cache');check(in_array('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',$headers,true)&&in_array('X-Robots-Tag: noindex, nofollow, noarchive',$headers,true),'auth document is private and excluded from indexing');
 $_GET=['kiwe-screen'=>'profile'];$nocache=0;$headers=[];$assets->protect_auth_document();check($nocache===1&&in_array('X-Robots-Tag: noindex, nofollow, noarchive',$headers,true),'explicit Profile document is private and uncached');$_GET=['kiwe-auth'=>'1'];
 foreach([['phonekey'=>['enabled'=>false]],['enabled'=>false]]as $change){$nocache=0;$headers=[];service('DSA\\Public_Endpoint\\Assets',array_replace_recursive($base,$change))->protect_auth_document();check(!$nocache&&!$headers,'disabled feature does not acquire auth document policy');}
 $_GET=[];$nocache=0;$headers=[];$assets->protect_auth_document();check(!$nocache&&!$headers,'ordinary documents keep their cache policy');
 echo "Auth surface registration behavior passed.\n";
}
