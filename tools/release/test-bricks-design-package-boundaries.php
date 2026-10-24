<?php
declare(strict_types=1);
define('ABSPATH',__DIR__);define('BRICKS_VERSION','2.3.10');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Configuration_Snapshot_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Content_Archive_Job_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Media_Archive_Job_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Bricks_Design_Package_Service.php';
use DSA\Backup\Bricks_Design_Package_Service as Design;
use DSA\Backup\Archive_Error;
$checks=[];
$check=static function(bool $ok,string $label)use(&$checks):void{$checks[$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(array $index,array $names):bool{try{Design::validate_index($index,$names);return false;}catch(Archive_Error $error){return true;}};
$units=[['name'=>'settings.kiwe','schema'=>DSA\Backup\Configuration_Snapshot_Service::SCHEMA,'bytes'=>100,'hash'=>str_repeat('a',64)],['name'=>'templates.kiwe-set','schema'=>DSA\Backup\Content_Archive_Job_Service::SCHEMA,'bytes'=>200,'hash'=>str_repeat('b',64)],['name'=>'assets.kiwe-media','schema'=>DSA\Backup\Media_Archive_Job_Service::DESIGN_SCHEMA,'bytes'=>300,'hash'=>str_repeat('c',64)]];
$counts=['settings'=>13,'templates'=>12,'images'=>2,'fonts'=>1,'attachments'=>3,'files'=>5,'rawBytes'=>1024];
$index=['schema'=>Design::SCHEMA,'createdAt'=>'2026-10-04T00:00:00Z','bricksVersion'=>BRICKS_VERSION,'units'=>$units,'counts'=>$counts];$names=['manifest.kiwe'=>99,'settings.kiwe'=>100,'templates.kiwe-set'=>200,'assets.kiwe-media'=>300];
Design::validate_index($index,$names);$check(true,'closed native three-unit design package accepted');
foreach(['templates','images','fonts','attachments','files','rawBytes']as$key){$bad=$index;$bad['counts'][$key]=-1;$check($reject($bad,$names),'negative '.$key.' count refused');$bad=$index;$bad['counts'][$key]='1';$check($reject($bad,$names),'string '.$key.' count refused');}
foreach(['settings'=>14,'templates'=>1001,'images'=>201,'fonts'=>101,'attachments'=>201,'files'=>2001,'rawBytes'=>67108865]as$key=>$value){$bad=$index;$bad['counts'][$key]=$value;$check($reject($bad,$names),'oversized '.$key.' count refused');}
foreach(['schema','bricksVersion','createdAt','counts','units']as$key){$bad=$index;unset($bad[$key]);$check($reject($bad,$names),'missing '.$key.' refused');}
$bad=$index;$bad['bricksVersion']='2.4.0';$check($reject($bad,$names),'different native builder version requires a supported migration');
$bad=$index;$bad['callback']='unsafe';$check($reject($bad,$names),'arbitrary recovery callbacks cannot register');
$bad=$index;$bad['units'][]=$units[0];$check($reject($bad,$names),'duplicate or fourth unit refused');
$bad=$index;$bad['units'][1]['schema']='kiwe.content-set.v2';$check($reject($bad,$names),'unsupported native unit schema refused');
$bad=$index;$bad['units'][2]['schema']=DSA\Backup\Media_Archive_Job_Service::SCHEMA;$check($reject($bad,$names),'ordinary media cannot replace exact design assets');
$bad=$index;$bad['units'][0]['name']='../settings.kiwe';$check($reject($bad,$names),'path traversal unit refused');
$bad=$index;$bad['units'][0]['hash']='bad';$check($reject($bad,$names),'invalid unit hash refused');
$bad=$index;$bad['units'][0]['bytes']=101;$check($reject($bad,$names),'entry size and unit declaration must agree');
$bad=$index;$bad['units']=array_slice($units,1);$check($reject($bad,array_diff_key($names,['settings.kiwe'=>true])),'settings unit is mandatory');
$bad=$index;$bad['units']=array_slice($units,0,2);$check($reject($bad,array_diff_key($names,['assets.kiwe-media'=>true])),'declared assets cannot be silently omitted');
$bad=$index;$bad['counts']['templates']=0;$check($reject($bad,$names),'unexpected template archive refused');
$check($reject($index,$names+['secret.php'=>1]),'unexpected archive entry refused');
$bad=$index;$bad['units']=[$units[0]];foreach(array_keys($counts)as$key)if($key!=='settings')$bad['counts'][$key]=0;Design::validate_index($bad,['manifest.kiwe'=>99,'settings.kiwe'=>100]);$check(true,'settings-only empty design remains a supported package');
echo json_encode(['passed'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
