<?php
namespace DSA { class Settings {public bool $enabled=true; function get($key,$default=null){return $key==='enabled'?$this->enabled:['checkout_surface_enabled'=>false];}} }
namespace DSA\Commerce {class Store_Recovery_Gate_Service {public static bool $paused=false;static function is_paused(){return self::$paused;}}}
namespace {define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
function get_option($key,$default=false){return $default;}
function apply_filters($hook,$value){return ['enabled'=>true,'allowed'=>false];}function __($s,...$a){return $s;}$checks=[];function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][]=$label;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Service.php';$settings=new DSA\Settings();$checkout=new DSA\Commerce\Checkout_Service($settings);
check(!$checkout->enabled(),'non-Woo core journey is unavailable');check(!$checkout->available(),'non-Woo cannot load checkout');
eval('function WC(){return $GLOBALS["syntheticWC"];}');$GLOBALS['syntheticWC']=(object)[];
check($checkout->enabled(),'installed Woo ignores obsolete off switch');$settings->enabled=false;check(!$checkout->enabled(),'disabled Surface remains inert');$settings->enabled=true;
DSA\Commerce\Store_Recovery_Gate_Service::$paused=true;check(!$checkout->enabled(),'recovery pause still denies checkout');DSA\Commerce\Store_Recovery_Gate_Service::$paused=false;
check(!$checkout->available(),'required identity denies before cart loading');$GLOBALS['syntheticWC']=null;check(!$checkout->enabled(),'missing Woo instance is unavailable');echo json_encode(['passed'=>true,'checks'=>count($checks),'scope'=>'Actual checkout owner with synthetic boundaries; no native checkout proof']).PHP_EOL;
}
