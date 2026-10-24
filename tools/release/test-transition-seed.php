<?php
/** Execute the actual early asset printer without defining an invented path alias. */
namespace DSA {
 class Settings { public array $value=['enabled'=>true,'visual_effects'=>['show_on_navigation'=>true]];public function get($key,$fallback=null){return $this->value[$key]??$fallback;} }
 class Environment {public static bool $public=true;public static function should_render_frontend():bool{return self::$public;} }
}
namespace {
 define('ABSPATH',__DIR__.'/');define('DSA_VERSION','source-fixture');define('DSA_DIR',dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/');
 function wp_json_encode($value){return json_encode($value,JSON_THROW_ON_ERROR);}
 function admin_url($path){return 'https://site.test/wp-admin/'.$path;}
 function esc_url($value){return htmlspecialchars($value,ENT_QUOTES);}
 set_error_handler(static function($level,$message){throw new RuntimeException($message);});
 require DSA_DIR.'includes/Public_Endpoint/Assets.php';
 $settings=new DSA\Settings();$reflection=new ReflectionClass(DSA\Public_Endpoint\Assets::class);$assets=$reflection->newInstanceWithoutConstructor();$reflection->getProperty('settings')->setValue($assets,$settings);$checks=0;
 $check=static function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);};
 $render=static function()use($assets){ob_start();try{$assets->print_boot_seed();return ob_get_contents();}finally{ob_end_clean();}};
 $html=$render();$check(str_contains($html,'id="dsa-boot-seed"'),'Existing boot seed retained');$check(str_contains($html,'id="dsa-transition-reader"')&&str_contains($html,file_get_contents(DSA_DIR.'assets/js/transition-reader.js')),'Actual reader bytes print through the existing canonical DSA_DIR');$check(!defined('DSA_PATH'),'No invented alias masks a frontend fatal');
 $check(str_contains($html,'id="dsa-startup-hydration"')&&str_contains($html,file_get_contents(DSA_DIR.'assets/js/startup-hydration.js'))&&str_contains($html,'data-dsa-runtime-endpoint="https://site.test/wp-admin/admin-ajax.php?action=dsa_runtime_hydrate"'),'Actual private startup read prints once in the early head');
 $check(substr_count($html,'id="dsa-startup-hydration"')===1&&str_contains($html,'data-no-optimize="1" data-no-defer="1" data-cfasync="false"'),'Existing optimizers preserve the early inline read');
 $settings->value['visual_effects']['show_on_navigation']=false;$html=$render();$check(str_contains($html,'id="dsa-boot-seed"')&&str_contains($html,'id="dsa-startup-hydration"')&&!str_contains($html,'dsa-transition-reader'),'Private startup does not depend on optional transitions');
 $settings->value['enabled']=false;$check($render()==='','Disabled Surface adds no boot or reader');$settings->value['enabled']=true;DSA\Environment::$public=false;$check($render()==='','Private request exclusions are retained');
 restore_error_handler();echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'Actual PHP early asset printer and canonical source file, not just syntax/string matching']).PHP_EOL;
}
