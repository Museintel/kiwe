<?php
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
$out=['schema'=>'kiwe.native-badge-display.v1','checks'=>[],'passed'=>false,'cleanup'=>false];
$assert=static function($ok,$label)use(&$out){$out['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
try{
 $package=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($package['valid'])&&!empty($package['complete']),'complete matching signed runtime');
 $private=dirname(rtrim(ABSPATH,'/')).'/kiwe-private-rc350-badges-display';$previous=json_decode(file_get_contents($private.'/previous-manifest.json'),true,512,JSON_THROW_ON_ERROR);$native=0;
 foreach($previous as$name=>$hash)if(str_ends_with($name,'.php')&&!in_array($name,['dsa.php','dsa/dsa.php'],true)){$assert(hash_file('sha256',WP_CONTENT_DIR.'/mu-plugins/'.$name)===$hash,'accepted native authority unchanged '.$name);$native++;}
 $assert($native>100,'all accepted native PHP owners checked');
 $before=get_user_meta(get_current_user_id(),'dsa_admin_notification_inbox',true);$prefs=new DSA\Notifications\Notification_Preference_Service(new DSA\Settings(),new DSA\Trust\Trust_Service());$inbox=new DSA\Notifications\Admin_Event_Notification_Service($prefs,(new ReflectionProperty(DSA\Plugin::class,'push'))->getValue(DSA\Plugin::instance()));
 $items=$inbox->pull_current_user();$count=DSA\Notifications\Admin_Event_Notification_Service::unread_count();$assert(count($items)<=20&&$count>=0&&$count<=40,'current permitted native inbox remains bounded');$assert(get_user_meta(get_current_user_id(),'dsa_admin_notification_inbox',true)===$before,'readback does not acknowledge another account or change history');
 $setup=DSA\Access\Analytics_Reporting_Setup_Service::projection();$assert($setup['secureReady']&&$setup['recordingVisits']&&$setup['cartsReady']&&!$setup['restoredBlocked'],'previously accepted native reporting remains ready');
 $source=file_get_contents(DSA_DIR.'assets/js/surface.js');$assert(!str_contains($source,'active.length + unreadAiNotificationCount()')&&!str_contains($source,'activeAiInsights().length + unreadAiNotificationCount()'),'Dock summaries and badges share the native new count');$out['cleanup']=true;$out['passed']=true;
}catch(Throwable$e){$out['error']=$e->getMessage();}
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;
