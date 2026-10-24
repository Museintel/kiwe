<?php
/** Separate-process CAS proof. CLI wrapper supplies authorized test constants and a private directory. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_RACE_DIRECTORY'))exit(2);
use DSA\AI\AI_Allowance_Service as A;
use DSA\SEO\SEO_Job_Store as J;
$dir=KIWE_RACE_DIRECTORY;$mode=$argv[1]??'';$native=$wpdb->options;$table=$wpdb->prefix.'kiwe63qa_race';
if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table)||!is_dir($dir)||is_link($dir))exit(3);
$marker=$dir.'/owned-table.json';
if($mode==='seed'){
 if(file_exists($marker)||$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))))exit(4);
 if($wpdb->query("CREATE TABLE {$table} LIKE {$native}")===false)exit(5);
 file_put_contents($marker,wp_json_encode(['table'=>$table,'created'=>gmdate('c')]));chmod($marker,0600);
 $wpdb->options=$table;$job=(new J())->start('all_posts',1,1,1);$wpdb->options=$native;
 if(is_wp_error($job))exit(6);file_put_contents($dir.'/race-job.json',wp_json_encode(['id'=>$job['id']]));exit(0);
}
$owned=json_decode((string)@file_get_contents($marker),true);if(($owned['table']??'')!==$table)exit(7);
$wpdb->options=$table;
$settingsFilter=static fn()=>['ai'=>['request_allowance'=>['minute'=>60,'day'=>100,'month'=>2],'token_allowance'=>['minute'=>1000,'day'=>1000,'month'=>200]]];
add_filter('pre_option_'.DSA_OPTION_SETTINGS,$settingsFilter,PHP_INT_MAX);
if(preg_match('/^worker([1-8])$/D',$mode,$m)){
 $a=new A(new DSA\Settings());$r=$a->reserve(100);$settled=!empty($r['ok'])?$a->settle($r['ticket'],20):null;
 $job=json_decode(file_get_contents($dir.'/race-job.json'),true);$claim=(new J())->claim($job['id']);
 $out=['worker'=>(int)$m[1],'reserved'=>!empty($r['ok']),'settled'=>!empty($settled['settled']),'leaseWon'=>!is_wp_error($claim)&&!empty($claim['lease'])];
 file_put_contents($dir.'/'.$mode.'.json',wp_json_encode($out));exit(0);
}
if($mode==='check'){
 $results=[];for($i=1;$i<=8;++$i)$results[]=json_decode((string)@file_get_contents($dir.'/worker'.$i.'.json'),true);
 $a=(new A(new DSA\Settings()))->status();$all=count(array_filter($results,'is_array'))===8;
 $out=['schema'=>'kiwe-native-ai-race/v1','workers'=>8,'complete'=>$all,'reservations'=>count(array_filter($results,static fn($r)=>!empty($r['reserved']))),'settlements'=>count(array_filter($results,static fn($r)=>!empty($r['settled']))),'leaseWinners'=>count(array_filter($results,static fn($r)=>!empty($r['leaseWon']))),'requestUnits'=>$a['used']['month']??null,'tokenUnits'=>$a['tokenBudget']['used']['month']??null];
 $out['passed']=$all&&$out['reservations']===2&&$out['settlements']===2&&$out['leaseWinners']===1&&$out['requestUnits']===2&&$out['tokenUnits']===40;
 $wpdb->options=$native;$out['cleanup']=$wpdb->query("DROP TABLE {$table}")!==false;
 file_put_contents($dir.'/race-result.json',wp_json_encode($out,JSON_PRETTY_PRINT));exit($out['passed']&&$out['cleanup']?0:8);
}
exit(9);
