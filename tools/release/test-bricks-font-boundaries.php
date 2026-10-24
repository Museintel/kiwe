<?php
declare(strict_types=1);
namespace { define('ABSPATH',__DIR__); define('BRICKS_DB_GLOBAL_CLASSES_TRASH','trash'); }
namespace DSA\Backup { class Bricks_Design_Snapshot_Service { public static function handles(string $name):bool{return in_array($name,['design','trash'],true);} } }
namespace {
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Bricks_Font_Archive_Service.php';
use DSA\Backup\Bricks_Font_Archive_Service as Fonts;
$checks=[];function check(bool $ok,string $label):void{$GLOBALS['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);}
function reject(callable $action):bool{try{$action();return false;}catch(DSA\Backup\Archive_Error $e){return true;}}
$faces=['400normal'=>['woff2'=>17,'woff'=>'18'],'700italic'=>[['ttf'=>19,'unicode-range'=>'U+0000-00FF, U+0100-017F'],['otf'=>20,'eot'=>21]]];
check(Fonts::faces($faces)===$faces,'native variant and subset shape is lossless');
check(Fonts::file_ids($faces)===[17,18,19,20,21],'all five native local font formats are recognized');
$mapped=Fonts::faces($faces,[17=>['id'=>117],18=>['id'=>118],19=>['id'=>119],20=>['id'=>120],21=>['id'=>121]]);
check($mapped['400normal']['woff2']===117&&$mapped['400normal']['woff']==='118'&&$mapped['700italic'][1]['eot']===121,'complete mapping retains native scalar types');
check(reject(fn()=>Fonts::faces($faces,[17=>['id'=>117]])),'missing map cannot silently preserve broken face references');
foreach([[],['400normal'=>[]],['400normal'=>['woff2'=>0]],['400normal'=>['woff2'=>true]],['400normal'=>['woff2'=>-1]],['400normal'=>['woff2'=>'0017']],['400normal'=>['woff2'=>'https://outside.test/font']],['400normal'=>['woff2'=>17,'javascript'=>'x']],['400normal'=>['woff2'=>17,'unicode-range'=>'U+0000;}body{color:red']],['400normal'=>['unicode-range'=>'U+0000']],['400normal'=>[['woff2'=>17],[]]],['700;}body{'=>['woff2'=>17]]]as$i=>$bad)check(reject(fn()=>Fonts::faces($bad)),'unsafe or incomplete font face refused '.$i);
$options=['design'=>['exists'=>true,'value'=>[['settings'=>['fontFamily'=>'custom_font_17','text'=>'custom_font_17'],'title'=>'custom_font_17'],['settings'=>['fontFamily'=>'custom_font_99']]]],'ordinary'=>['exists'=>true,'value'=>['fontFamily'=>'custom_font_17']]];
$result=Fonts::remap_design($options,[17=>117]);
check(Fonts::remap_design(['trash'=>['exists'=>true,'value'=>['fontFamily'=>'custom_font_17']]],[17=>117])['trash']['value']['fontFamily']==='custom_font_17','inactive class trash is preserved exactly');
check($result['design']['value'][0]['settings']['fontFamily']==='custom_font_117','typed active design reference reconnects');
check($result['ordinary']===$options['ordinary']&&$result['design']['value'][0]['title']==='custom_font_17'&&$result['design']['value'][0]['settings']['text']==='custom_font_17','ordinary settings and text never undergo broad replacement');
check($result['design']['value'][1]===$options['design']['value'][1],'unrelated native font ID remains unchanged');
check(reject(fn()=>Fonts::remap_design($options,[])),'empty recovery cannot masquerade as a font map');
$deep=['fontFamily'=>'custom_font_17'];for($i=0;$i<22;$i++)$deep=['children'=>$deep];check(reject(fn()=>Fonts::remap_design(['design'=>['exists'=>true,'value'=>$deep]],[17=>117])),'deep dependency graphs are refused');
echo json_encode(['passed'=>true,'checks'=>$checks],JSON_UNESCAPED_SLASHES).PHP_EOL;
}
