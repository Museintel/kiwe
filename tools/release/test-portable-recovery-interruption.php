<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('ABSPATH',__DIR__.'/');
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Recovery_Activation']as$c)require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$c.'.php';
use DSA\Backup\Portable_Recovery_Activation as Activation;
$p=['schema'=>'kiwe.recovery-interruption-local-proof.v1','passed'=>false,'cleanup'=>true,'checks'=>[]];
$check=static function(bool$ok,string$label)use(&$p){$p['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$deny=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$entry=static fn($path,$body)=>['path'=>$path,'bytes'=>strlen($body),'sha256'=>hash('sha256',$body)];
$plan=static function($files,$dirs=[])use($entry){return['files'=>array_map(static fn($v)=>$entry($v[0],$v[1]),$files),'directories'=>$dirs,'exclusions'=>[],'bytes'=>array_sum(array_map(static fn($v)=>strlen($v[1]),$files)),'namePolicy'=>'posix'];};
try{
 $accepted=['building'=>['original/0/1'],'prepared'=>['original/0/1'],'parking'=>['original/0/1','missing/1/1'],'parked'=>['missing/1/1'],'publishing'=>['missing/1/1','recovered/1/0'],'active'=>['recovered/1/0'],'reversing'=>['recovered/1/0','missing/1/1','original/0/1'],'reversed'=>['original/0/1'],'cancelled'=>['original/0/1'],'retiring'=>['original/0/1','original/0/0']];
 foreach($accepted as$status=>$cases)foreach(['original','recovered','missing']as$public)foreach([false,true]as$before)foreach([false,true]as$next){$layout=['public'=>$public,'before'=>$before,'next'=>$next];$name=$public.'/'.(int)$before.'/'.(int)$next;if(in_array($name,$cases,true)){Activation::checkpoint_placement($status,$layout);$check(true,'supported checkpoint '.$status.' '.$name);}else$deny(static fn()=>Activation::checkpoint_placement($status,$layout),'ambiguous or foreign checkpoint refused '.$status.' '.$name);}
 $deny(static fn()=>Activation::checkpoint_placement('other',['public'=>'original','before'=>false,'next'=>true]),'unknown checkpoint refused');
 $deny(static fn()=>Activation::checkpoint_placement('building',['public'=>'original','before'=>false,'next'=>1]),'untyped physical layout refused');
 $source=$plan([['index.php','Captured code'],['wp-config.php','Captured secret'],['wp-content/db.php','Captured database'],['wp-content/uploads/a.bin',"\0sample"]],['wp-content','wp-content/uploads']);
 $before=$plan([['.htaccess','Prepared rules'],['.well-known/acme/token','Prepared native token'],['index.php','Prepared home'],['wp-config.php','Prepared secret']],['.well-known','.well-known/acme']);
 $next=Activation::assembly_plan($source,$before,'Reviewed native configuration');
 $check(array_column($next['files'],'path')===['.htaccess','.well-known/acme/token','index.php','wp-config.php','wp-content/uploads/a.bin'],'assembly preserves exact native host files and excludes captured credentials/drop-in');
 $check($next['directories']===['.well-known','.well-known/acme','wp-content','wp-content/uploads'],'assembly includes prepared host directories and source parents');
 $check($next['files'][3]['sha256']===hash('sha256','Reviewed native configuration'),'assembly binds reviewed configuration rather than source config');
 $actual=$plan([['index.php','Captured code']]);$check(Activation::subset_plan($actual,$next)===[],'acknowledged complete subset accepted for interrupted retirement');
 $short=$plan([['index.php','Cap']]);$check(Activation::subset_plan($short,$next,true)===$short['files'],'building identifies shorter bytes for separate source-prefix proof');
 $deny(static fn()=>Activation::subset_plan($short,$next),'retirement cannot accept unacknowledged partial bytes');
 $changed=$plan([['index.php','Changed work!']]);$deny(static fn()=>Activation::subset_plan($changed,$next,true),'same-length changed work is refused');
 $unknown=$plan([['unknown.php','New work']]);$deny(static fn()=>Activation::subset_plan($unknown,$next,true),'unknown copied file is refused');
 $unknown=$plan([],['unknown']);$deny(static fn()=>Activation::subset_plan($unknown,$next,true),'unknown copied directory is refused');
 $old=['revision'=>1,'status'=>'building','assembled'=>false,'activated'=>false,'retirementTree'=>null,'binding'=>'exact-source'];$new=$old;$new['revision']=2;$new['status']='prepared';$new['assembled']=true;Activation::receipt_transition($old,$new);$check(true,'authenticated immediate prepared successor accepted');
 foreach(['revision'=>3,'status'=>'active','binding'=>'foreign-source']as$key=>$value){$bad=$new;$bad[$key]=$value;$deny(static fn()=>Activation::receipt_transition($old,$bad),'pending journal cannot skip or change '.$key);}
 $old=$new;$new['revision']=3;$new['status']='parking';Activation::receipt_transition($old,$new);$check(true,'immediate parking intent accepted');
 $old=$new;$new['revision']=4;$new['status']='parked';Activation::receipt_transition($old,$new);$check(true,'parked acknowledgement accepted');
 $old=$new;$new['revision']=5;$new['status']='publishing';Activation::receipt_transition($old,$new);$check(true,'publish intent accepted');
 $old=$new;$new['revision']=6;$new['status']='active';$new['activated']=true;Activation::receipt_transition($old,$new);$check(true,'completed activation successor accepted');
 $old=$new;$new['revision']=7;$new['status']='reversing';Activation::receipt_transition($old,$new);$check(true,'reverse intent successor accepted');
 $old=$new;$new['revision']=8;$new['status']='reversed';$new['activated']=false;$new['retirementTree']=$next;Activation::receipt_transition($old,$new);$check(true,'completed reversal binds exact copied retirement tree');
 $old=$new;$new['revision']=9;$new['status']='retiring';Activation::receipt_transition($old,$new);$check(true,'retirement intent successor accepted');
 $bad=$new;$bad['revision']=10;$bad['retirementTree']=$actual;$deny(static fn()=>Activation::receipt_transition($new,$bad),'retirement acknowledgement cannot replace the approved tree');
 $p['passed']=true;
}catch(Throwable$e){$p['error']=$e->getMessage();}
echo json_encode($p,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($p['passed']&&$p['cleanup']?0:1);
