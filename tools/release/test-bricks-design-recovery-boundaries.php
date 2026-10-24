<?php
declare(strict_types=1);
define('ABSPATH',__DIR__);
define('BRICKS_DB_GLOBAL_CLASSES_TRASH','bricks_global_classes_trash');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Bricks_Design_Snapshot_Service.php';
use DSA\Backup\Bricks_Design_Snapshot_Service as Design;
$checks=[];
function check(bool $ok,string $label):void{$GLOBALS['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);}
function reject(callable $action):bool{try{$action();return false;}catch(DSA\Backup\Archive_Error $error){return true;}}
$media=[7=>['id'=>107,'urls'=>['https://qa.test/old.png'=>'https://qa.test/new.png','https://qa.test/old-small.png'=>'https://qa.test/new-small.png']]];
$templates=[17=>['id'=>117,'status'=>'publish'],18=>['id'=>118,'status'=>'draft']];
$image=['id'=>'7','url'=>'https://qa.test/old-small.png?v=2#focus','full'=>'https://qa.test/old.png','size'=>'small'];
$source=['image'=>$image,'template'=>'17','interactions'=>[['target'=>'popup','templateId'=>17]],'text'=>'17 https://qa.test/old.png','id'=>7,'url'=>'unrelated','note'=>['templateId'=>17],'untouched'=>['id'=>8,'full'=>'https://qa.test/other.png']];
// Keep an ordinary array out of the typed descriptor root.
unset($source['id'],$source['url']);
$mapped=Design::remap_value($source,$media,$templates,true);
check($mapped['image']['id']==='107'&&$mapped['image']['url']==='https://qa.test/new-small.png?v=2#focus'&&$mapped['image']['full']==='https://qa.test/new.png','registered original/derivative URLs and string IDs reconnect together');
check($mapped['template']==='117'&&$mapped['interactions'][0]['templateId']===117,'native Template elements and popup interactions preserve scalar types');
check($mapped['text']===$source['text']&&$mapped['note']===$source['note']&&$mapped['untouched']===$source['untouched'],'ordinary text, other templateId fields and unselected images stay exact');
check(Design::remap_value(['id'=>7,'url'=>'https://qa.test/old.png'],$media)['id']===107,'URL-only typed descriptors are recognized');
check(reject(fn()=>Design::remap_value(['id'=>7,'full'=>'https://qa.test/unregistered.png'],$media)),'mismatched native URLs cannot become a new ID with an old file');
check(reject(fn()=>Design::remap_value(['id'=>7,'full'=>false,'url'=>'https://qa.test/old.png'],$media)),'malformed typed image field refused');
check(reject(fn()=>Design::remap_value(['template'=>18],[],$templates,true)),'live design cannot silently activate draft copies');
check(Design::remap_value(['template'=>18],[],$templates)['template']===118,'draft-only content recovery can reconnect nested drafts');
check(Design::remap_value(['template'=>99],[],$templates,true)['template']===99,'unselected template IDs stay unchanged');
foreach([0,true,'007',-7,7.0,[],new stdClass]as$i=>$id)check(reject(fn()=>Design::remap_value(['id'=>$id,'full'=>'https://qa.test/old.png'],$media)),'unsupported descriptor ID refused '.$i);
check(reject(fn()=>Design::remap_value(['template'=>17],[],[17=>['id'=>'117']])), 'untyped recovery IDs refused');
check(reject(fn()=>Design::remap_value(['id'=>7,'url'=>'https://qa.test/old.png'],[7=>['id'=>107,'urls'=>[]]])), 'incomplete registered URL map refused');
$deep=['template'=>17];for($i=0;$i<22;$i++)$deep=['children'=>$deep];check(reject(fn()=>Design::remap_value($deep,[],$templates)),'deep recovery graph refused');
echo json_encode(['passed'=>true,'checks'=>$checks],JSON_UNESCAPED_SLASHES).PHP_EOL;
