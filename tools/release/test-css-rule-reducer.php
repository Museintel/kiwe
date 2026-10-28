<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/fixture/');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Performance/CSS_Rule_Reducer.php';
use DSA\Performance\CSS_Rule_Reducer as Reducer;
$checks=0;
$assert=static function($v,$name)use(&$checks){$checks++;if(!$v)throw new RuntimeException($name);};
$cases=[
 ['final duplicate','.x{color:red}.x{color:red}','.x{color:red}'],
 ['cascade order','.x{color:red}.x{color:blue}.x{color:red}','.x{color:blue}.x{color:red}'],
 ['important cascade','.x{color:red!important}.x{color:blue!important}.x{color:red!important}','.x{color:blue!important}.x{color:red!important}'],
 ['declaration order differs','.x{color:red;background:blue}.x{background:blue;color:red}',null],
 ['different selector','.a{color:red}.b{color:red}',null],
 ['different declaration','.x{color:red}.x{color:blue}',null],
 ['comments stay','/*a*/.x{color:red}/*b*/.x{color:red}','/*a*//*b*/.x{color:red}'],
 ['body comments','.x{/*a*/color:red/*b*/}.x{/*a*/color:red/*b*/}','.x{/*a*/color:red/*b*/}'],
 ['string punctuation','.x{content:"} ; {"}.x{content:"} ; {"}','.x{content:"} ; {"}'],
 ['quoted escape','.x{content:"a\"}b"}.x{content:"a\"}b"}','.x{content:"a\"}b"}'],
 ['quoted SVG','.x{background:url("data:image/svg+xml,<svg>{}</svg>")}.x{background:url("data:image/svg+xml,<svg>{}</svg>")}', '.x{background:url("data:image/svg+xml,<svg>{}</svg>")}'],
 ['unquoted function','.x{background:url(data:foo;bar)}.x{background:url(data:foo;bar)}','.x{background:url(data:foo;bar)}'],
 ['escaped selector','.x\{y{color:red}.x\{y{color:red}', '.x\{y{color:red}'],
 ['unicode','.स्वाद{--रंग:red}.स्वाद{--रंग:red}', '.स्वाद{--रंग:red}'],
 ['media siblings','@media(min-width:1px){.x{color:red}.x{color:red}}','@media(min-width:1px){.x{color:red}}'],
 ['different actual parents','@media(min-width:1px){.x{color:red}}@media(min-width:1px){.x{color:red}}',null],
 ['different media','@media(min-width:1px){.x{color:red}}@media(max-width:2px){.x{color:red}}',null],
 ['supports siblings','@supports(display:grid){.x{display:grid}.x{display:grid}}','@supports(display:grid){.x{display:grid}}'],
 ['nested groups','@media(all){@supports(display:grid){.x{color:red}.x{color:red}}}','@media(all){@supports(display:grid){.x{color:red}}}'],
 ['root last wins across media','.x{color:red}@media(all){.x{color:blue}}.x{color:red}','@media(all){.x{color:blue}}.x{color:red}'],
 ['opaque layer','@layer a{.x{color:red}.x{color:red}}',null],
 ['layer ordering barrier','.x{color:red}@layer a{}.x{color:red}',null],
 ['late import barrier','.x{color:red}@import "x.css";.x{color:red}',null],
 ['namespace barrier','.x{color:red}@namespace x "urn:test";.x{color:red}',null],
 ['opaque keyframes','@keyframes a{0%{opacity:0}0%{opacity:0}100%{opacity:1}}',null],
 ['font-face preserved','@font-face{font-family:A;src:url(a)}@font-face{font-family:A;src:url(a)}',null],
 ['property preserved','@property --x{syntax:"<color>";inherits:false;initial-value:red}',null],
 ['nested rules','.o{& .x{color:red}& .x{color:red}}',null],
 ['nested at statement','.x{@layer a;color:red}.x{@layer a;color:red}',null],
 ['custom token block','.x{--thing:{color:red}}.x{--thing:{color:red}}',null],
 ['escaped property','.x{co\6cor:red}.x{co\6cor:red}',null],
 ['hacked property','.x{*color:red}.x{*color:red}',null],
 ['unclosed quote','.x{color:red}.x{color:red}.bad{content:"',null],
 ['unclosed block','.x{color:red}.x{color:red}.bad{',null],
 ['unclosed comment','.x{color:red}.x{color:red}/*',null],
 ['unbalanced delimiter','.x{color:red}.x{color:red}.bad[',null],
 ['invalid delimiter order','.x{color:red}.x{color:red}.bad([)]{}',null],
 ['unexpected close','.x{color:red}.x{color:red}}',null],
 ['dangling escape',".x{color:red}.x{color:red}\\",null],
 ['unfinished trailing prelude','.x{color:red}.x{color:red}.unfinished',null],
 ['root statement barrier','.x{color:red};.x{color:red}',null],
 ['HTML comment boundary','<!--.x{color:red}.x{color:red}-->',null],
 ['empty','',null],
 ['only comment','/* test */',null],
];
foreach($cases as[$name,$input,$expected]){
 $actual=Reducer::reduce($input);
 $assert($actual===($expected??$input),$name);
 $assert(Reducer::reduce($actual)===$actual,'Idempotence: '.$name);
}
$large=str_repeat(' ',4194305);$assert(Reducer::reduce($large)===$large,'Byte bound');
$deep=str_repeat('@media(all){',70).'.x{color:red}.x{color:red}'.str_repeat('}',70);$assert(Reducer::reduce($deep)===$deep,'Depth bound');
$many=str_repeat('.x{color:red}',20001);$assert(Reducer::reduce($many)===$many,'Rule bound');
$long='.x{--'.str_repeat('x',20000).':red}.x{color:red}.x{color:red}';$assert(is_string(Reducer::reduce($long)),'Long declaration remains bounded');
echo json_encode(['passed'=>true,'checks'=>$checks,'cases'=>count($cases),'writes'=>0],JSON_THROW_ON_ERROR),PHP_EOL;
