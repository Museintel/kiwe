<?php
declare(strict_types=1);
namespace DSA\Onboarding {final class Design_Context_Profile_Service{const OPTION_PROFILE='fixture_context',OPTION_STATUS='fixture_context_status';}}
namespace {
 define('ABSPATH',__DIR__.'/');$blog=1;$busy=false;$commands=[];$cache=[];$work=0;$checks=[];
 function get_current_blog_id(){return$GLOBALS['blog'];}function wp_cache_delete($key,$group){$GLOBALS['cache'][]=[$key,$group];}class WP_Error{public function __construct(public$code,public$message,public$data){}}
 class Database {public string$prefix='fixture_';public function prepare($sql,...$args){return str_replace('%s',"'".$args[0]."'",$sql);}public function get_var($sql){$GLOBALS['commands'][]=$sql;return str_contains($sql,'RELEASE_LOCK')||!$GLOBALS['busy']?'1':'0';}}$wpdb=new Database();
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Context_Write_Coordinator.php';
 use DSA\Onboarding\Context_Write_Coordinator as Guard;
 $check=static function($ok,$name)use(&$checks){$checks[$name]=(bool)$ok;if(!$ok)throw new RuntimeException($name);};
 $r=Guard::run(function()use(&$work){$work++;return Guard::run(function()use(&$work){$work++;return'native';});});
 $check($r==='native'&&$work===2,'Nested native writers share the same admitted context');
 $check(count($commands)===2&&str_contains($commands[0],'GET_LOCK')&&str_contains($commands[0],',0)'),'Only the outer native writer takes a zero-wait lock');
 $check(count($cache)===4,'Original native profile/status/autoload caches refresh before preimage checks');
 $check(str_contains($commands[1],'RELEASE_LOCK'),'Successful native writer releases its own lock');
 $busy=true;$prior=$work;$r=Guard::run(function()use(&$work){$work++;});$check($r instanceof WP_Error&&$r->data['status']===409&&$work===$prior,'Concurrent writer refuses without business work or a deliberate wait');$busy=false;
 $prior=count($commands);try{Guard::run(static function(){throw new RuntimeException('Native failure');});}catch(RuntimeException){}
 $check(count($commands)===$prior+2&&str_contains(end($commands),'RELEASE_LOCK'),'Failure releases the lock and preserves the native exception');
 $check(Guard::run(static fn()=>'recovered')==='recovered','Failure resets the reentrant guard for a later native operation');
 $first=$commands[0];$blog=2;Guard::run(static fn()=>true);$second=$commands[count($commands)-2];$check($first!==$second,'Native blog identity is part of the lock namespace');
 $check(!array_filter($commands,static fn($s)=>preg_match('/START TRANSACTION|COMMIT|ROLLBACK/',$s)),'Coordinator does not commit or roll back another native transaction');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false])."\n";
}
