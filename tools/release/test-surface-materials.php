<?php
namespace DSA {
 class Settings { public array $value=['enabled'=>true];public function get($key,$fallback=null){return $this->value[$key]??$fallback;} }
 class Environment {public static bool $public=true;public static function should_render_frontend():bool{return self::$public;} }
}
namespace {
 define('ABSPATH',__DIR__.'/');require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php';
 $reflection=new ReflectionClass(DSA\Public_Endpoint\Assets::class);$assets=$reflection->newInstanceWithoutConstructor();$settings=new DSA\Settings();$reflection->getProperty('settings')->setValue($assets,$settings);$checks=0;
 $check=static function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);};
 $render=static function()use($assets){ob_start();try{$assets->print_material_style();return ob_get_contents();}finally{ob_end_clean();}};
 $css=$render();$check(substr_count($css,'id="dsa-surface-materials"')===1,'One shared material stylesheet');
 foreach(['dsa-dock-material-glass','dsa-dock-material-solid','data-dsa-material="glass"','data-dsa-material="solid"']as$key)$check(str_contains($css,$key),'Material definition includes '.$key);
 $check(substr_count($css,'72%, transparent')===1&&substr_count($css,'48%, transparent')===1,'Single light/dark glass definition for Dock Home and Transition');
 $check(str_contains($css,'blur(var(--kiwe-glass-blur, var(--dsa-blur-strength, 0.625rem)))'),'Glass shares the canonical Dock blur token');
 $check(str_contains($css,'--dsa-runtime-dock-control-filter: none')&&str_contains($css,'var(--kiwe-color-surface-raised,'),'Solid uses the Dock opaque surface and disables blur');
 $check(str_contains($css,'html[data-kiwe-theme="dark"]')&&str_contains($css,'html[data-brx-theme="dark"]'),'Existing native colour modes select shared materials');
 $source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');$check(!str_contains($source,'blur(min(var(--dsa-blur-strength')&&!str_contains($source,'background:linear-gradient(115deg,rgba(245,247,251,'),'Independent Home material definitions are retired');
 $check(substr_count($source,'72%, transparent')===1,'Footer guard does not duplicate the material definition');
 $settings->value['enabled']=false;$check($render()==='','Disabled Surface prints no material CSS');$settings->value['enabled']=true;DSA\Environment::$public=false;$check($render()==='','Private/native requests retain frontend exclusions');
 echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'Actual PHP shared material printer; computed styles and independent settings require native/browser acceptance']).PHP_EOL;
}
