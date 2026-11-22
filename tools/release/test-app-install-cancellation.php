<?php
declare(strict_types=1);
namespace DSA\Apps {
 final class Native_State {public static function allowed(){return true;}}
 final class Release_Coordinator {public static function acquire(){return true;}public static function release(){}}
 final class Runtime_Contract {const IDS=['ai'];}
 final class Code_Recovery {public static array $value=[],$calls=[];public static function state($id){return self::$value;}public static function recover($strict,$id){self::$calls[]=$id;}}
}
namespace DSA\Runtime {final class Release_Storage {public static function downloads(){return __DIR__.'/nonexistent-fixture-downloads';}}}
namespace {
 define('ABSPATH',__DIR__.'/');function get_option($key,$default=false){return$GLOBALS['options'][$key]??$default;}function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}function wp_normalize_path($path){return str_replace('\\','/',$path);}
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Installer_Service.php';
 use DSA\Apps\{Installer_Service as Installer,Code_Recovery as Recovery};$checks=[];$id='00000000-0000-4000-8000-000000000001';$hash=str_repeat('a',64);
 $check=static function($ok,$label)use(&$checks){$checks[$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
 $seed=static function($state,int $cursor=0)use($id,$hash){Recovery::$value=$state;Recovery::$calls=[];$GLOBALS['options']=[Installer::OPTION=>['ai'=>['id'=>$id,'status'=>'queued','cursor'=>$cursor,'plan'=>['id'=>'ai','steps'=>['download','verify','place','confirm'],'release'=>['sha256'=>$hash]]]]];};
 $old=['phase'=>'accepted','release'=>['sha256'=>$hash],'installationJob'=>'an-earlier-installation'];$seed($old);(new Installer())->cancel('ai',$id);$check(Recovery::$calls===[]&&Recovery::$value===$old,'Cancelling before placement never rolls back an earlier accepted same-version installation');
 $seed($old,3);(new Installer())->cancel('ai',$id);$check(Recovery::$calls===[],'Cancelling a reused authenticated version does not recover an earlier job');
 foreach(['placing','awaiting_boot','accepted','recovering']as$phase){$seed(['phase'=>$phase,'release'=>['sha256'=>$hash],'installationJob'=>$id],3);(new Installer())->cancel('ai',$id);$check(Recovery::$calls===['ai'],'Exact job-owned native recovery is retained: '.$phase);}
 $seed(['phase'=>'awaiting_boot','release'=>['sha256'=>$hash]],3);$denied=false;try{(new Installer())->cancel('ai',$id);}catch(RuntimeException){$denied=true;}$check($denied&&Recovery::$calls===[]&&get_option(Installer::OPTION)['ai']['status']==='queued','Unbound legacy in-flight recovery requires native review instead of guessing');
 $seed(['phase'=>'awaiting_boot','release'=>['sha256'=>$hash],'installationJob'=>'another-job'],3);$denied=false;try{(new Installer())->cancel('ai',$id);}catch(RuntimeException){$denied=true;}$check($denied&&Recovery::$calls===[],'Another installation owns its pending recovery');
 $seed($old);$denied=false;try{(new Installer())->cancel('ai','00000000-0000-4000-8000-000000000002');}catch(RuntimeException){$denied=true;}$check($denied&&Recovery::$calls===[],'A stale job identifier cannot cancel native work');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false,'physicalCodeChanged'=>false])."\n";
}
