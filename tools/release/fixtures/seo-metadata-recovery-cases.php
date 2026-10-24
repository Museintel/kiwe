<?php
use DSA\SEO\SEO_Metadata_Migration_Service as Migration;
// Continuation of the synthetic metadata harness: current row comparisons need separate native proof.
$cycle_posts=$postmeta;$cycle_terms=$termmeta;$cycle_state=$service->status();
$migration_busy=true;
check(409===$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$cycle_state['expected']]))->status&&$service->status()['active']===null,'a competing native writer returns immediately without scheduling');
$migration_busy=false;
$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$cycle_state['expected']]));$post_job=$queued->data['active']['id'];$service->run($post_job);
$post_done=$service->status();$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'term','confirmed'=>true,'expected'=>$post_done['expected']]));
check(200===$queued->status,'retained post journals do not block a separate taxonomy import');
$term_job=$queued->data['active']['id'];$service->run($term_job);$both=$service->status();
check($both['scopes']['post']['lastImport']['id']===$post_job&&$both['scopes']['term']['lastImport']['id']===$term_job&&$both['scopes']['post']['rollbackRecords']===2&&$both['scopes']['term']['rollbackRecords']===1,'both latest source jobs retain independent rollback references and counts');
$imported_title=get_post_meta(201,'_kiwe_seo_title',true);$postmeta[201]['_kiwe_seo_title']='Newer client title';$edited=$postmeta[201];
$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$both['expected']]));$service->run($undo->data['active']['id']);$conflicted=$service->status();
check($postmeta[201]===$edited,'rollback preflight preserves all fields on a record containing a newer client edit');
check(!metadata_exists('post',202,Migration::MARKER_META)&&$conflicted['scopes']['post']['rollbackRecords']===1&&$conflicted['scopes']['post']['canRollback'],'unaffected records undo while a conflict retains its original source reference');
check($conflicted['scopes']['term']['lastImport']['id']===$term_job&&get_term_meta(41,'_kiwe_seo_title',true)==='%term% %sep% %sitename%','post rollback does not touch the taxonomy journal or fields');
$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'term','confirmed'=>true,'expected'=>$conflicted['expected']]));$service->run($undo->data['active']['id']);
check($termmeta===$cycle_terms&&$service->status()['scopes']['post']['canRollback'],'taxonomy undo remains available independently of a newer post conflict');
$postmeta[201]['_kiwe_seo_title']=$imported_title;$retry=$service->status();$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$retry['expected']]));$service->run($undo->data['active']['id']);
check($postmeta===$cycle_posts&&$service->status()['lastImport']===null,'reviewed field resolution permits a later exact retry and retires only finished journals');

$postmeta[201]['_kiwe_seo_title']='';$empty_before=$postmeta;$ready=$service->status();
$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($queued->data['active']['id']);
$imported_title=get_post_meta(201,'_kiwe_seo_title',true);$ready=$service->status();$metadata_race=['_kiwe_seo_title'=>['delete',null]];
$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);
check(!metadata_exists('post',201,'_kiwe_seo_title')&&metadata_exists('post',201,Migration::MARKER_META),'a deletion between review and conditional undo is never recreated');
check($service->status()['scopes']['post']['canRollback'],'an interrupted conditional undo keeps its original journal discoverable');
$postmeta[201]['_kiwe_seo_title']=$imported_title;$ready=$service->status();$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);
check($postmeta===$empty_before,'conditional retry restores an existing empty field with exact existence');
unset($postmeta[201]['_kiwe_seo_title']);

$ready=$service->status();$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($queued->data['active']['id']);
$original_title=get_post_meta(201,'_kiwe_seo_title',true);$ready=$service->status();$metadata_race=['_kiwe_seo_title'=>['edit','Concurrent title']];
$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);
check(get_post_meta(201,'_kiwe_seo_title',true)==='Concurrent title'&&metadata_exists('post',201,Migration::BACKUP_META),'native binary-value comparison preserves a concurrent replacement');
$postmeta[201]['_kiwe_seo_title']=$original_title;$ready=$service->status();$metadata_sql_failure=true;
$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);$failed_undo=$service->status();
check($failed_undo['active']['status']==='stalled'&&$failed_undo['active']['cursor']===0&&metadata_exists('post',201,Migration::BACKUP_META),'failed conditional SQL stalls without cursor advancement or journal retirement');
$metadata_sql_failure=false;$wpdb->last_error='';$resume=$service->mutate(new WP_REST_Request('POST',['operation'=>'resume','confirmed'=>true,'expected'=>$failed_undo['expected']]));$service->run($resume->data['active']['id']);
check($postmeta===$cycle_posts&&$service->status()['lastImport']===null,'failed SQL resumes through the same original journal to exact prior metadata');

$saved_options=$options;$legacy='abcdefabcdefabcd';$options[DSA\SEO\SEO_Metadata_Migration_Service::OPTION]=['schema'=>1,'active'=>null,'lastImport'=>['id'=>$legacy,'scope'=>'post','operation'=>'import','status'=>'complete'],'history'=>[]];
$postmeta[201][DSA\SEO\SEO_Metadata_Migration_Service::MARKER_META]=$legacy;$postmeta[201][DSA\SEO\SEO_Metadata_Migration_Service::BACKUP_META]=['schema'=>1,'job'=>$legacy,'values'=>['_kiwe_seo_title'=>['exists'=>false,'value'=>'']]];$postmeta[201]['_kiwe_seo_title']='An older journal cannot own this edit';$legacy_before=$postmeta[201];
$ready=$service->status();$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);
check($postmeta[201]===$legacy_before&&$service->status()['scopes']['post']['canRollback'],'legacy journals preserve unknown newer ownership instead of guessing an undo');
$options=$saved_options;$postmeta=$cycle_posts;

$ready=$service->status();$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($queued->data['active']['id']);
$leaseBefore=$postmeta;$ready=$service->status();$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$queuedState=$service->status();
$metadata_action=static function($hook,...$args)use($service){if($hook==='delete_post_meta'){$GLOBALS['metadata_state_at_loss']=$service->status()['active'];$GLOBALS['metadata_lease_lost']=true;}};$service->run($queued->data['active']['id']);unset($metadata_action);
check($postmeta===$leaseBefore,'lost session ownership after native hook prevents conditional undo and journal retirement');
check($service->status()['active']===$metadata_state_at_loss,'former session owner cannot publish job state after losing its lease');
$metadata_lease_lost=false;$ready=$service->status();$resume=$service->mutate(new WP_REST_Request('POST',['operation'=>'resume','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($resume->data['active']['id']);
check($postmeta===$cycle_posts,'fresh session ownership resumes a lease-lost job to exact prior metadata');

$ready=$service->status();$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($queued->data['active']['id']);$ready=$service->status();
$metadata_retire_failure=true;$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);
check(metadata_exists('post',201,Migration::BACKUP_META)&&metadata_exists('post',201,Migration::MARKER_META)&&$service->status()['active']['cursor']===0,'failed joint journal retirement leaves both original recovery references discoverable');
$metadata_retire_failure=false;$wpdb->last_error='';$ready=$service->status();$resume=$service->mutate(new WP_REST_Request('POST',['operation'=>'resume','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($resume->data['active']['id']);
check($postmeta===$cycle_posts&&$service->status()['lastImport']===null,'retry retires both journal rows after fields were already restored');

$cycle_posts=$postmeta;$postmeta[202]['rank_math_description']="Writer's C:\\draft";$ready=$service->status();$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($queued->data['active']['id']);
check(get_post_meta(202,'_kiwe_seo_description',true)==="Writer's C:\\draft"&&get_post_meta(202,Migration::BACKUP_META,true)['imported']['_kiwe_seo_description']==="Writer's C:\\draft",'native slashing preserves exact escaped imported values in their journal');
$ready=$service->status();$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);$postmeta[202]['rank_math_description']=$cycle_posts[202]['rank_math_description'];
check($postmeta===$cycle_posts,'escaped imported values retain an exact undo boundary');

$postmeta[202]['rank_math_title']='%title% %page%';unset($postmeta[202]['_kiwe_seo_title']);$ready=$service->status();$queued=$service->mutate(new WP_REST_Request('POST',['operation'=>'start','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($queued->data['active']['id']);
check(!metadata_exists('post',202,'_kiwe_seo_title')&&$service->status()['lastImport']['unsupported']>0,'source pagination with different semantics is reported and never imported lossily');
$ready=$service->status();$undo=$service->mutate(new WP_REST_Request('POST',['operation'=>'rollback','scope'=>'post','confirmed'=>true,'expected'=>$ready['expected']]));$service->run($undo->data['active']['id']);$postmeta=$cycle_posts;
