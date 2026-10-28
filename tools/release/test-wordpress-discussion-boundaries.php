<?php
if(PHP_SAPI!=='cli'||!defined('KIWE_DISCUSSION_NATIVE_TEST')||!defined('KIWE_DISCUSSION_OWNED_DATABASE')||DB_NAME!==KIWE_DISCUSSION_OWNED_DATABASE)throw new RuntimeException('Owned discussion fixture required.');

function kiwe_discussion_boundary_tests(callable $check,bool $store):void {
    global $wpdb;$code=static fn($r)=>is_wp_error($r)?$r->get_error_code():'';wp_set_current_user(1);
    $post=wp_insert_post(['post_type'=>'post','post_title'=>'Failure boundary fixture','post_status'=>'publish','comment_status'=>'open']);
    $id=wp_insert_comment(['comment_post_ID'=>$post,'comment_author'=>'Boundary Reader','comment_content'=>'Approved native history survives an optional-store fault.','comment_approved'=>1]);
    $service=new DSA\Discussion\Discussion_Service();$controller=new DSA\Rest\Discussion_Controller();
    $big=new WP_REST_Request('POST');$big->set_header('X-Kiwe-Mutation','1');$big->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$big->set_body(str_repeat(' ',35001));$big->set_url_params(['postId'=>$post]);
    $check($code($controller->submit($big))==='kiwe_discussion_body_size','Oversized public requests are refused before JSON decoding or admission work');
    $check($code($service->submit($post,['sendId'=>wp_generate_uuid4(),'name'=>'Reader','text'=>str_repeat('x',30001)]))==='kiwe_discussion_input_size','Oversized direct contribution data is refused before text normalization and database writes');
    $deny=static fn($query)=>str_contains($query,'SELECT comment_post_ID,COUNT(*) AS total')?'SELECT * FROM cf23_intentionally_missing_counts':$query;
    $old=$wpdb->suppress_errors(true);add_filter('query',$deny);$counts=$service->counts([$post]);remove_filter('query',$deny);$wpdb->suppress_errors($old);
    $check($code($counts)==='kiwe_discussion_counts_unavailable','A failed count query cannot be misrepresented as zero comments');
    wp_cache_set_last_changed('comment');$denyNative=static fn($query)=>preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+'.preg_quote($GLOBALS['wpdb']->comments,'/').'\b/i',$query)?'SELECT * FROM cf23_intentionally_missing_native_count':$query;
    $old=$wpdb->suppress_errors(true);add_filter('query',$denyNative);$nativeCount=$service->count(get_post($post));remove_filter('query',$denyNative);$wpdb->suppress_errors($old);
    $retryCount=$service->count(get_post($post));if($code($nativeCount)!=='kiwe_discussion_counts_unavailable'||$retryCount!==1)throw new RuntimeException('Native count diagnostic: '.wp_json_encode(['code'=>$code($nativeCount),'value'=>is_wp_error($nativeCount)?null:$nativeCount,'retry'=>is_wp_error($retryCount)?$retryCount->get_error_code():$retryCount]));
    $check(true,'Native count failure is unavailable rather than zero and cannot cache a false empty discussion');
    $denyReplies=static fn($query)=>str_contains($query,'SELECT comment_parent, COUNT(*) AS total')?'SELECT * FROM cf23_intentionally_missing_replies':$query;wp_cache_set_last_changed('comment');
    $old=$wpdb->suppress_errors(true);add_filter('query',$denyReplies);$replyFailure=$service->listing($post);remove_filter('query',$denyReplies);$wpdb->suppress_errors($old);
    $check($code($replyFailure)==='kiwe_discussion_replies_unavailable','A failed visible-reply aggregate cannot be cached or presented as zero replies');
    $denyLimiter=static fn($query)=>str_contains($query,'INSERT INTO '.$GLOBALS['wpdb']->prefix.'dsa_rate_limits')?'SELECT * FROM cf23_intentionally_missing_limiter':$query;
    $old=$wpdb->suppress_errors(true);add_filter('query',$denyLimiter);$legacy=DSA\Utilities\Atomic_Rate_Limiter::allow('legacy-storage-fixture',1);$strict=DSA\Utilities\Atomic_Rate_Limiter::allow('strict-storage-fixture',1,60,false);remove_filter('query',$denyLimiter);$wpdb->suppress_errors($old);
    $check($legacy&&!$strict,'Real SQL write failure preserves existing fail-open callers and fails discussion admission closed');
    $table=DSA\Discussion\Discussion_Reaction_Store::table();$renamed=$table.'_cf23_probe';
    $original=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$table} ORDER BY comment_id,user_id,kind",ARRAY_A)));
    $wpdb->query("RENAME TABLE `{$table}` TO `{$renamed}`");
    try{
        $old=$wpdb->suppress_errors(true);$projection=DSA\Discussion\Discussion_Reaction_Store::projection([$id],0);$wpdb->suppress_errors($old);$queryStart=count($wpdb->queries);
        $history=$service->listing($post,0,0,'top');$export=DSA\Discussion\Discussion_Privacy::export('proven@example.test');$visitorQueries=array_slice($wpdb->queries,$queryStart);
        $check(is_wp_error($projection)&&!is_wp_error($history)&&$history['sort']==='latest'&&$history['count']===1&&!$history['viewer']['reactionsAvailable'],'Missing reaction storage degrades to real approved native history with actions unavailable');
        $check($code($export)==='kiwe_discussion_privacy_storage','A configured but missing reaction store cannot produce a false completed privacy export');
        $check(!array_filter($visitorQueries,static fn($row)=>preg_match('/^\s*(CREATE|ALTER|DROP)\b/i',$row[0])),'Public degraded reads never create, repair or drop database schema');
    }finally{$wpdb->query("RENAME TABLE `{$renamed}` TO `{$table}`");}
    $check(DSA\Discussion\Discussion_Reaction_Store::install()&&hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$table} ORDER BY comment_id,user_id,kind",ARRAY_A)))===$original,'Authorized schema recheck recovers without losing original reactions');
    $contract=DSA\Discussion\Discussion_Contract::export();
    $check($contract['mcp']['readOnlyDiscovery']&&!$contract['mcp']['commentMutationTools']&&$contract['productReviews']===$store&&!str_contains(wp_json_encode($contract),'@example.test'),'SiteGraph/MCP authoring discovery describes real capabilities without contacts, discussion content or write tools');
}
