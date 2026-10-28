<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Settings.php';
$settings=new DSA\Settings();$method=new ReflectionMethod($settings,'recursive_parse_args');$checks=0;
function check($value,$label):void{if(!$value)throw new RuntimeException($label);$GLOBALS['checks']++;}
try{
 $copy=['profile'=>['title'=>'Example account'],'search'=>['title'=>'Find something']];
 $value=$method->invoke($settings,['theme_screens'=>$copy],['theme_screens'=>[]]);
 check($value['theme_screens']===$copy,'Named screen IDs survive an empty native default');
 $future=['publisher'=>['name'=>'Example publication','enabled'=>false],'unrelated'=>'retain'];
 check($method->invoke($settings,['extension'=>$future],['extension'=>[]])['extension']===$future,'Future named settings retain keys and value types');
 $old=['items'=>[['id'=>1],['id'=>2]]];
 check($method->invoke($settings,['items'=>[]],$old)['items']===[],'Explicitly clearing a list still clears it');
 check($method->invoke($settings,['items'=>[5=>['id'=>3]]],$old)['items']===[['id'=>3]],'A nonempty declared list retains ordered-list normalization');
 check($method->invoke($settings,['items'=>[['id'=>3]]],['items'=>[]])['items']===[['id'=>3]],'A fresh ordinary list keeps its native shape');
 $raw=['theme_screens'=>['profile'=>['title'=>'Old','intro'=>'Retain']],'private'=>['keep'=>'synthetic-only'],'enabled'=>false];
 $next=$method->invoke($settings,['theme_screens'=>['profile'=>['title'=>'New']]],$raw);
 check($next['theme_screens']['profile']===['title'=>'New','intro'=>'Retain']&&$next['private']===$raw['private']&&$next['enabled']===false,'A partial named change preserves other fields, private data and explicit false');
 echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$checks,'nativeSqlExecuted'=>false]).PHP_EOL;
}catch(Throwable $error){echo json_encode(['passed'=>false,'cleanup'=>true,'checks'=>$checks,'error'=>$error->getMessage()]).PHP_EOL;exit(1);}
