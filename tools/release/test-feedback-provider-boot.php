<?php
declare(strict_types=1);
namespace DSA\Discussion { final class Feedback_Engine_Service {public static int $registrations=0;public function register():void{self::$registrations++;}} }
namespace {
 define('ABSPATH',__DIR__.'/fixture/');define('DSA_URL','https://fixture.invalid/');
 function __($v,$domain=''){return$v;}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function wp_parse_args($v,$d=[]){return array_merge($d,$v);}function esc_url($v){return$v;}function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES);}
 $optionReads=0;function get_option($key,$default=false){$GLOBALS['optionReads']++;return false;}
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Discussion/Feedback_Access.php';require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Modules/Module_Registry.php';require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Plugin.php';
 $checks=[];$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
 $modules=new \DSA\Modules\Module_Registry();$modules->register_defaults();$plugin=(new ReflectionClass(\DSA\Plugin::class))->newInstanceWithoutConstructor();(new ReflectionProperty(\DSA\Plugin::class,'modules'))->setValue($plugin,$modules);
 $check(\DSA\Discussion\Feedback_Access::enabled()&&$optionReads===0,'Built-in Feedback ignores obsolete optional-App flags without option reads');
 $check(($modules->get('comments')['panel']??'')==='comments','Core provides its contextual Comments module without an installed App');
 $plugin->register_feedback_services();$check(\DSA\Discussion\Feedback_Engine_Service::$registrations===1,'Core starts the built-in engine');
 $original=$modules->get('comments');$plugin->register_feedback_services();$modules->register_feedback_module();$check(\DSA\Discussion\Feedback_Engine_Service::$registrations===1&&$modules->get('comments')===$original,'Repeated admission never duplicates hooks or replaces the module');
 $other=new \DSA\Modules\Module_Registry();$other->register_defaults();$check($other->get('comments')!==null&&$other->get('search')!==null&&$other->get('cart')!==null,'A fresh Core retains Feedback and the other shared module definitions');
 $check($optionReads===0,'Built-in module registration adds no option/catalogue lookup');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'assertions'=>$checks,'scope'=>'Actual built-in availability, Plugin registration and Module Registry; hosted physical proof remains required.']);
}
