<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
foreach(['Archive_Error','Portable_Database_Capture','Portable_Recovery_Migration']as$c)require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$c.'.php';
use DSA\Backup\Portable_Recovery_Migration as Migration;
$proof=['schema'=>'kiwe.recovery-migration-local.v1','runtime'=>PHP_VERSION,'nativeDatabaseExecuted'=>false,'passed'=>false,'cleanup'=>true,'checks'=>[]];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal '.$label);};
$column=static fn($n,$t='longtext')=>['name'=>$n,'type'=>$t];
$table=static fn($suffix,$columns,$primary)=>['name'=>'old_'.$suffix,'columns'=>$columns,'primary'=>$primary];
$options=$table('options',[$column('option_id','bigint'),$column('option_name','varchar(191)'),$column('option_value')],['option_id']);
$usermeta=$table('usermeta',[$column('umeta_id','bigint'),$column('meta_key','varchar(255)'),$column('meta_value')],['umeta_id']);
$deps=['activePlugins'=>['wp-content/plugins/example/main.php'],'mustUseEntries'=>['wp-content/mu-plugins/loader.php'],'themes'=>['template'=>['directory'=>'wp-content/themes/bricks','root'=>'wp-content/themes'],'stylesheet'=>['directory'=>'wp-content/themes/child','root'=>'wp-content/themes']]];
$review=['receipt'=>['recoverySha256'=>str_repeat('a',64)],'database'=>['prefix'=>'old_','tables'=>[$options,$usermeta]],'source'=>['scope'=>'ordinary-single-site-native-layout','platform'=>['wordpress'=>'7.1.2','kiwe'=>'8.0.0-fixture','operatingSystem'=>PHP_OS_FAMILY],'layout'=>['content'=>'wp-content','plugins'=>'wp-content/plugins','mustUsePlugins'=>'wp-content/mu-plugins','uploads'=>'wp-content/uploads','themeRoots'=>['wp-content/themes']],'dependencies'=>$deps]];
$spec=['schema'=>Migration::SCHEMA,'recoverySha256'=>$review['receipt']['recoverySha256'],'source'=>['home'=>'https://source.invalid','siteurl'=>'https://source.invalid/wp','root'=>'/srv/source'],'destination'=>['home'=>'https://target.invalid','siteurl'=>'https://target.invalid/cms','root'=>'/srv/target'],'versions'=>['wordpress'=>'7.1.2','kiwe'=>'8.0.0-fixture'],'dependencies'=>$deps,'tableSuffixes'=>['options','usermeta'],'consistencyAcceptance'=>'observed-stable-not-atomic'];
try{
 $m=Migration::review($spec,$review,'/srv/target');
 foreach([
  'canonical'=>'https://source.invalid/article', 'legacy-http'=>'http://source.invalid/article', 'relative'=>'//source.invalid/article',
  'subdirectory'=>'https://source.invalid/wp/uploads/a.jpg', 'query'=>'https://source.invalid?q=1#top', 'path'=>'/srv/source/wp-content/uploads/a.jpg',
  'similar-host'=>'https://source.invalid.evil/article', 'host-suffix'=>'https://source.invalidly/article', 'similar-root'=>'/srv/source2/upload', 'nested-root'=>'/other/srv/source/upload', 'foreign-scheme'=>'ftp://source.invalid/article', 'email'=>'admin@source.invalid', 'literal-prefix'=>'old_capabilities',
 ]as$name=>$value){$expected=match($name){'canonical','legacy-http'=>'https://target.invalid/article','relative'=>'//target.invalid/article','subdirectory'=>'https://target.invalid/cms/uploads/a.jpg','query'=>'https://target.invalid?q=1#top','path'=>'/srv/target/wp-content/uploads/a.jpg',default=>$value};$check($m->value($value)===$expected,$name.' byte mapping');}
 $native=['unicode'=>'नमस्ते https://source.invalid/media','nested'=>serialize(['url'=>'https://source.invalid/wp/a','path'=>'/srv/source/file','id'=>42]),'none'=>null,'empty'=>'','bool'=>false,'float'=>1.25,'integer'=>PHP_INT_MAX];
 $expected=$native;$expected['unicode']='नमस्ते https://target.invalid/media';$expected['nested']=serialize(['url'=>'https://target.invalid/cms/a','path'=>'/srv/target/file','id'=>42]);
 $check($m->value(serialize($native))===serialize($expected),'nested serialized UTF8 lengths scalar identities and empty values');
 $object=(object)['url'=>'https://source.invalid/wp/a','id'=>42];$expectedObject=(object)['url'=>'https://target.invalid/cms/a','id'=>42];$check($m->value(serialize($object))===serialize($expectedObject),'serialized object bytes mapped without constructing the class');
 $spy='O:12:"NeverWakeUp":1:{s:3:"url";s:22:"https://source.invalid";}';
 // Deliberately malformed class length is denied, never handed to PHP object loading.
 $reject(static fn()=>$m->value($spy),'object class byte length mismatch');
 $json='{"id":18446744073709551615,"url":"https:\\/\\/source.invalid\\/wp\\/a","path":"/srv/source/file","float":1.000000000000001}';
 $mapped=$m->value($json);$check($mapped==='{"id":18446744073709551615,"url":"https:\\/\\/target.invalid\\/cms\\/a","path":"/srv/target/file","float":1.000000000000001}','JSON escaped URLs preserve numeric precision and formatting');
 $bricks=[['id'=>'abc123','name'=>'image','parent'=>0,'settings'=>['image'=>['id'=>55,'url'=>'https://source.invalid/wp-content/uploads/photo.jpg'],'link'=>['url'=>'https://source.invalid/wp/page']]]];$new=$bricks;$new[0]['settings']['image']['url']='https://target.invalid/wp-content/uploads/photo.jpg';$new[0]['settings']['link']['url']='https://target.invalid/cms/page';
 $check($m->value(serialize($bricks))===serialize($new),'Bricks nested settings preserve element parent media IDs and names');
	$styleText=['i::before { mask-image: url("https://source.invalid/wp/icon.svg"); content: "Native icon"; }','b: a literal label with https://source.invalid/page','d: is ordinary text here; https://source.invalid/photo'];
	$mappedStyleText=['i::before { mask-image: url("https://target.invalid/cms/icon.svg"); content: "Native icon"; }','b: a literal label with https://target.invalid/page','d: is ordinary text here; https://target.invalid/photo'];
	$check($m->value(serialize($styleText))===serialize($mappedStyleText),'quoted native style and label strings retain their string type');
	$check($m->value(json_encode($styleText,JSON_UNESCAPED_SLASHES))===json_encode($mappedStyleText,JSON_UNESCAPED_SLASHES),'JSON style and label strings retain their string type');
	$scalarStrings=['b:0;','i:42;','d:1.25;'];$check($m->value(serialize($scalarStrings))===serialize($scalarStrings),'quoted serialized scalar bytes remain exact text');
	$longStyle='i::before { background-image: url("https://source.invalid/wp/icon.svg"); }'.str_repeat(' /* ordinary design text */',150000);
	$longMapped='i::before { background-image: url("https://target.invalid/cms/icon.svg"); }'.str_repeat(' /* ordinary design text */',150000);
	$check($m->value(serialize(['_cssCustom'=>$longStyle]))===serialize(['_cssCustom'=>$longMapped]),'large native Bricks style text migrates without scalar misclassification');
	$nearBudget=[];for($i=0;$i<49999;++$i)$nearBudget['field_'.$i]='https://source.invalid/media';$nearMapped=array_fill_keys(array_keys($nearBudget),'https://target.invalid/media');
	$budgeted=Migration::review($spec,$review,'/srv/target');$check($budgeted->value(serialize($nearBudget))===serialize($nearMapped),'99999 logical serialized nodes fit the unchanged 100000-node budget');
	$nearBudget['one_more']='https://source.invalid/media';$budgeted=Migration::review($spec,$review,'/srv/target');$reject(static fn()=>$budgeted->value(serialize($nearBudget)),'100001 logical serialized nodes still refuse');unset($nearBudget,$nearMapped,$budgeted);
 $check($m->value('a:1:{s:3:"key";s:4:"none";}')==='a:1:{s:3:"key";s:4:"none";}','unchanged serialization stays byte exact');
 foreach(['s:999:"https://source.invalid";','a:2:{s:1:"k";N;}','a:1:{N;N;}','b:3;','i:01;','d:broken;','a:0:{}garbage','C:4:"Fake":0:{}','R:1;','r:1;','E:4:"A::B";','a:50001:{}','{"url":"https://source.invalid",}']as$i=>$invalid)$reject(static fn()=>$m->value($invalid),'malformed or unsupported encoding '.$i);
 $deep='N;';for($i=0;$i<70;++$i)$deep='a:1:{i:0;'.$deep.'}';$reject(static fn()=>$m->value($deep),'depth resource bound');
 foreach(['extra','digest','dependency','version','layout','platform','destination','url-credentials','url-path','table-suffix','ambiguous','claim','binary-option']as$kind){$s=$spec;$r=$review;
  switch($kind){case'extra':$s['extra']=true;break;case'digest':$s['recoverySha256']=str_repeat('b',64);break;case'dependency':$s['dependencies']['activePlugins']=[];break;case'version':$s['versions']['wordpress']='6.0';break;case'layout':$r['source']['layout']['uploads']='uploads';break;case'platform':$r['source']['platform']['operatingSystem']='unknown';break;case'destination':$s['destination']['root']='/other';break;case'url-credentials':$s['source']['home']='https://secret:pass@source.invalid';break;case'url-path':$s['source']['siteurl']='https://source.invalid/../wp';break;case'table-suffix':$s['tableSuffixes']=[];break;case'ambiguous':$s['source']['siteurl']=$s['source']['home'];break;case'claim':$s['consistencyAcceptance']='atomic';break;case'binary-option':$r['database']['tables'][0]['columns'][2]['type']='longblob';break;}
  $reject(static fn()=>Migration::review($s,$r,'/srv/target'),'source policy refuses '.$kind);
 }
 $check($m->row($options,['1','home','https://source.invalid'],'new_')===['1','home','https://target.invalid'],'native home identity validated and mapped');
 $check($m->row($options,['2','siteurl','https://source.invalid/wp'],'new_')===['2','siteurl','https://target.invalid/cms'],'native siteurl identity validated and mapped');
 $roles=serialize(['administrator'=>['name'=>'Administrator','capabilities'=>['edit_posts'=>true]]]);$check($m->row($options,['3','old_user_roles',$roles],'new_')===['3','new_user_roles',$roles],'role option key maps and capabilities retain native identities');
 $check($m->row($usermeta,['1','old_capabilities',serialize(['author'=>true])],'new_')===['1','new_capabilities',serialize(['author'=>true])],'user capability key maps');
 $check($m->row($usermeta,['2','old_user_level','2'],'new_')===['2','new_user_level','2'],'user level key maps');
 $check($m->row($usermeta,['3','other_capabilities','old_marker'],'new_')===['3','other_capabilities','old_marker'],'foreign user metadata prefix remains exact');$m->complete();$check(true,'complete identities accepted');
 $reject(static fn()=>$m->row($options,['4','home','https://source.invalid'],'new_'),'duplicate source home');$fresh=Migration::review($spec,$review,'/srv/target');$reject(static fn()=>$fresh->complete(),'missing native identities');$reject(static fn()=>$fresh->row($options,['1','home','https://other.invalid'],'new_'),'source URL assertion does not match captured option');
 $posts=$table('posts',[$column('ID','bigint'),$column('guid'),$column('post_content'),$column('binary_data','longblob'),$column('null_data')],['ID']);$binary="https://source.invalid\0\xff";
 $check($fresh->row($posts,['9','https://source.invalid?p=9','<p>https://source.invalid/article</p>',$binary,null],'new_')===['9','https://source.invalid?p=9','<p>https://target.invalid/article</p>',$binary,null],'GUID IDs binary and NULL preserved alongside mapped content');
 $keyTable=$table('custom',[$column('key'),$column('data')],['key']);$check($fresh->row($keyTable,['https://source.invalid','https://source.invalid/a'],'new_')===['https://source.invalid','https://target.invalid/a'],'textual primary key unchanged');
 $s=$spec;$s['destination']['home']='https://source.invalid/new';$s['destination']['siteurl']='https://source.invalid/new/cms';$once=Migration::review($s,$review,'/srv/target');$check($once->value('https://source.invalid/wp/a')==='https://source.invalid/new/cms/a','overlapping destination maps once without cascading');
 $reject(static fn()=>$fresh->value(serialize(['https://source.invalid'=>'a','http://source.invalid'=>'b'])),'serialized key collision refuses data loss');
 $check($fresh->value('{"https://source.invalid":"https://source.invalid/a"}')==='{"https://source.invalid":"https://target.invalid/a"}','JSON object keys retain identity');
 $reject(static fn()=>$fresh->value('['.str_repeat('0,',100001).'0]'),'JSON allocation bound before decoding');
 class NeverWakeUp { public function __wakeup(){ $GLOBALS['migrationWoke']=true; } }
 $GLOBALS['migrationWoke']=false;$opaque='O:11:"NeverWakeUp":1:{s:3:"url";s:22:"https://source.invalid";}';$check($fresh->value($opaque)==='O:11:"NeverWakeUp":1:{s:3:"url";s:22:"https://target.invalid";}'&&$GLOBALS['migrationWoke']===false,'known wakeup class remains inert');
 foreach(['kiwe_search_buckets_v1','kiwe_search_rows_v1','stp_report_days','stp_report_sessions','stp_report_activity']as$suffix){$history=$table($suffix,[$column('key'),$column('label')],['key']);$cells=[str_repeat('a',64),'https://source.invalid/story'];$check($fresh->row($history,$cells,'new_')===$cells,'source historical hashes and labels retain their paired provenance: '.$suffix);}
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($proof['passed']?0:1);
