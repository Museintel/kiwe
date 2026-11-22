<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');define('DSA_OPTION_SETTINGS','dsa_settings');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Settings.php';
$settings=(new ReflectionClass(DSA\Settings::class))->newInstanceWithoutConstructor();$checks=[];$check=static function($ok,$label)use(&$checks){$checks[$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
$current=['dock'=>['menu_label'=>'Menu','cart'=>true],'app'=>['home_enabled'=>true,'idle_delay_ms'=>10000,'welcome_message'=>'Hello'],'search'=>['families'=>['posts'=>true,'products'=>true]],'future'=>['keep'=>1]];
$method=new ReflectionMethod($settings,method_exists($settings,'selected_patch')?'selected_patch':'recursive_parse_args');
$changed=$method->invoke($settings,['app'=>['idle_delay_ms'=>11000]],$current);$back=$method->invoke($settings,['app'=>['idle_delay_ms'=>10000]],$changed);
$check($back===$current,'Selected native update and restore preserve exact sibling keys, types and order');
$check(array_keys($changed)===array_keys($current)&&array_keys($changed['app'])===array_keys($current['app']),'Selective patch never promotes edited groups or fields to the beginning');
$check($method->invoke($settings,['app'=>['idle_delay_ms'=>10000]],$current)===$current,'Same-value selected update remains a byte-stable native no-op');
$check($method->invoke($settings,['new'=>['label'=>'Added']],$current)===($current+['new'=>['label'=>'Added']]),'New supported fields append without moving original fields');
$check($method->invoke($settings,['future'=>[]],$current)===$current,'An empty associative patch preserves native siblings');
$list=['items'=>['old','kept']];$check($method->invoke($settings,['items'=>['new']],$list)===['items'=>['new']],'An explicit native list retains its replacement semantics');
$check($method->invoke($settings,['items'=>'invalid'],$list)===$list,'Non-array input cannot replace an existing native list');
$check($method->invoke($settings,['search'=>['families'=>['posts'=>false]]],$current)['search']['families']===['posts'=>false,'products'=>true],'Nested selective choices preserve unrelated native flags');
$check($method->invoke($settings,['commerce'=>['cart_show_delivery'=>true]],['commerce'=>[]])===['commerce'=>['cart_show_delivery'=>true]],'A fresh empty native settings group retains incoming associative field names');
$check($method->invoke($settings,['items'=>['new']],['items'=>[]])===['items'=>['new']],'An explicitly supplied list still replaces an empty list');
echo json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false])."\n";
