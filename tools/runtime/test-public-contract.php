<?php
namespace DSA {
 final class Settings { public function __construct(public array $value){} public function all():array{return $this->value;} }
}
namespace {
 define('ABSPATH',__DIR__.'/');
 function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s));}
 function sanitize_hex_color($s){return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i',$s)?$s:null;}
 function wp_strip_all_tags($s){return strip_tags((string)$s);}
 function absint($s){return abs((int)$s);}
 $base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
 require $base.'Design/Seam_Token_Service.php';require $base.'Design/Seam_Vocabulary_Schema.php';require $base.'Public_Endpoint/Assets.php';
 $ref=new ReflectionClass(DSA\Public_Endpoint\Assets::class);$asset=$ref->newInstanceWithoutConstructor();
 $cases=[[],['dsa_theme'=>['active_color'=>'#123456','hover_color'=>'#654321'],'tokens'=>['enabled'=>true,'overrides'=>['font-body'=>'Synthetic Serif, serif','space-xs'=>'calc(3px + 1vw)','not-a-token'=>'bad','color-danger'=>'<b>#b00020</b>']]],['tokens'=>['enabled'=>false,'overrides'=>['color-brand'=>'#abcdef']]]];
 $out=[];
 foreach($cases as $settings){
  $ref->getProperty('settings')->setValue($asset,new DSA\Settings($settings));
  $compact=$ref->getMethod('kiwe_tokens_data')->invoke($asset);
  $items=DSA\Design\Seam_Token_Service::tokens_with_overrides(DSA\Design\Seam_Token_Service::overrides_from_settings($settings));
  $expected=$compact;unset($expected['values']);$expected['items']=array_slice($items,0,80);
  $out[]=['compact'=>$compact,'expected'=>$expected];
 }
 echo json_encode(['cases'=>$out,'catalog'=>['tokens'=>array_slice(DSA\Design\Seam_Token_Service::tokens_with_overrides(),0,80),'vocabulary'=>DSA\Design\Seam_Vocabulary_Schema::contract()]],JSON_THROW_ON_ERROR);
}
