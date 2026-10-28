<?php
/** Bounded local query evidence, not hosted latency or whole-package performance acceptance. */
if(PHP_SAPI!=='cli'||!defined('KIWE_DISCUSSION_NATIVE_TEST')||!defined('KIWE_DISCUSSION_OWNED_DATABASE')||DB_NAME!==KIWE_DISCUSSION_OWNED_DATABASE)throw new RuntimeException('Owned discussion fixture required.');

function kiwe_discussion_query_cost_tests(callable $check):void {
    global $wpdb;wp_set_current_user(1);
    $post=wp_insert_post(['post_title'=>'Synthetic conversation query fixture','post_type'=>'post','post_status'=>'publish','comment_status'=>'open']);
    $other=wp_insert_post(['post_title'=>'Unrelated synthetic history','post_type'=>'post','post_status'=>'publish','comment_status'=>'open']);
    // Seed only native historical rows in this throwaway database; no visitor writes or outgoing events.
    foreach([$post,$other]as$target)for($batch=0;$batch<12;$batch++){
        $values=[];for($n=0;$n<1000;$n++)$values[]=$wpdb->prepare("(%d,'Load Reader','Synthetic query-cost record.','1','comment',0,'2026-10-28 00:00:00','2026-10-28 00:00:00')",$target);
        if(false===$wpdb->query("INSERT INTO {$wpdb->comments} (comment_post_ID,comment_author,comment_content,comment_approved,comment_type,comment_parent,comment_date,comment_date_gmt) VALUES ".implode(',',$values)))throw new RuntimeException('Owned native load fixture could not be seeded.');
    }
    $first=(int)$wpdb->get_var($wpdb->prepare("SELECT MIN(comment_ID) FROM {$wpdb->comments} WHERE comment_post_ID=%d",$post));
    add_comment_meta($first,DSA\Discussion\Discussion_Reactions::PIN,'1');DSA\Discussion\Discussion_Reactions::changed($post);wp_cache_set_last_changed('comment');
    $service=new DSA\Discussion\Discussion_Service();$proof=['nativeRowsPerTarget'=>12000,'totalSyntheticRows'=>24000,'profiles'=>[],'scope'=>'Source-level native WordPress/MariaDB on localhost, with no persistent object cache; not hosted latency.'];
    foreach(['latest','top']as$sort){
        wp_cache_flush();$start=count($wpdb->queries);$at=microtime(true);$result=$service->listing($post,0,0,$sort);$ms=(microtime(true)-$at)*1000;$queries=array_slice($wpdb->queries,$start);
        $check(!is_wp_error($result)&&count($result['items'])===12&&$result['count']===12000&&($sort!=='top'||$result['items'][0]['id']===$first),'A large native '.$sort.' conversation returns one bounded page with the exact root count');
        $plans=[];foreach($queries as$row)if(preg_match('/^\s*SELECT\b/i',$row[0])&&str_contains($row[0],$wpdb->comments)&&($sort!=='top'||str_contains($row[0],'kiwe_discussion_rank'))){
            $plan=$wpdb->get_results('EXPLAIN '.$row[0],ARRAY_A);if($wpdb->last_error)throw new RuntimeException('Actual native conversation EXPLAIN failed.');
            foreach($plan as$item)$plans[]=array_intersect_key($item,array_flip(['select_type','table','type','key','rows','Extra']));
        }
        $proof['profiles'][$sort]=['coldMs'=>round($ms,3),'queries'=>count($queries),'nativeExplain'=>$plans];
        $check(count($queries)<=45&&$plans&&strlen(wp_json_encode($result))<18000,'Large '.$sort.' reads retain bounded hydration, query count and public response size');
        $warm=[];for($i=0;$i<3;$i++){$at=microtime(true);$service->listing($post,0,0,$sort);$warm[]=round((microtime(true)-$at)*1000,3);}$proof['profiles'][$sort]['warmMs']=$warm;
    }
    $GLOBALS['kiwe_discussion_query_cost']=$proof;
}
