<?php
if(PHP_SAPI!=='cli'||!defined('KIWE_DISCUSSION_NATIVE_TEST')||!defined('KIWE_DISCUSSION_OWNED_DATABASE')||DB_NAME!==KIWE_DISCUSSION_OWNED_DATABASE)throw new RuntimeException('Owned discussion fixture required.');

function kiwe_discussion_privacy_tests(callable $check,int $article,int $root,int $known,callable $seed):void {
    global $wpdb;$table=DSA\Discussion\Discussion_Reaction_Store::table();
    $privacyUser=wp_create_user('privacy-reader',wp_generate_password(),'privacy@example.test');
    $target=wp_insert_post(['post_title'=>'Private export synthetic fixture','post_type'=>'post','post_status'=>'publish','comment_status'=>'open']);
    $first=0;for($i=0;$i<101;++$i){$id=$seed($target,'Synthetic privacy comment '.$i);if(!$first)$first=$id;DSA\Discussion\Discussion_Reaction_Store::set($id,$privacyUser,'like',true);}
    DSA\Discussion\Discussion_Reaction_Store::set($first,$known,'like',true);
    $firstExport=DSA\Discussion\Discussion_Privacy::export('privacy@example.test',1);$secondExport=DSA\Discussion\Discussion_Privacy::export('privacy@example.test',2);
    $check(count($firstExport['data'])===100&&!$firstExport['done']&&count($secondExport['data'])===1&&$secondExport['done'],'Native privacy export is bounded and reaches the exact account-linked reaction history');
    $check(!str_contains(wp_json_encode($firstExport),'@')&&!str_contains(wp_json_encode($firstExport),'Synthetic privacy comment'),'Reaction exports contain only owned actions and native IDs, without copying another person’s contact or text');
    $unknown=DSA\Discussion\Discussion_Privacy::export('private-fixture@example.test');
    $check($unknown['data']===[]&&$unknown['done'],'A typed comment email cannot claim reactions belonging to a different native account');
    $before=DSA\Discussion\Discussion_Reactions::revision($article);
    $deny=static fn($value,$old)=>$old;add_filter('pre_update_option_kiwe_discussion_privacy_revision_v1',$deny,PHP_INT_MAX,2);
    $failed=DSA\Discussion\Discussion_Privacy::erase('privacy@example.test');remove_filter('pre_update_option_kiwe_discussion_privacy_revision_v1',$deny,PHP_INT_MAX);
    $check(!$failed['items_removed']&&$failed['items_retained']&&!$failed['done']&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d",$privacyUser))===101,'Failed privacy invalidation rolls back the erasure and reports that a retry is required');
    $erase=DSA\Discussion\Discussion_Privacy::erase('privacy@example.test',1);$tail=DSA\Discussion\Discussion_Privacy::erase('privacy@example.test',2);
    $check($erase['items_removed']&&!$erase['done']&&$tail['items_removed']&&$tail['done']&&!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE user_id=%d",$privacyUser)),'Paged native privacy erasure removes every remaining action without offset skips');
    $check(DSA\Discussion\Discussion_Reaction_Store::has($first,$known,'like')&&get_comment($first)&&DSA\Discussion\Discussion_Reactions::revision($article)!==$before,'Erasure preserves other accounts and native comments, while invalidating old rank cursors');
    $again=DSA\Discussion\Discussion_Privacy::erase('privacy@example.test',3);
    $check(!$again['items_removed']&&!$again['items_retained']&&$again['done'],'Repeating a completed privacy erasure is harmless');
    wp_set_current_user($privacyUser);DSA\Discussion\Public_Identity::remember_current_name('Chosen Privacy Reader');$provenance=[];
    for($i=0;$i<23;++$i){$id=wp_insert_comment(['comment_post_ID'=>$target,'user_id'=>$privacyUser,'comment_author'=>'Chosen Privacy Reader','comment_author_email'=>'privacy@example.test','comment_content'=>'Native text remains owned by the native privacy eraser.','comment_approved'=>1]);$provenance[]=$id;foreach(DSA\Discussion\Discussion_Service::privacy_metadata()as$key)add_comment_meta($id,$key,'synthetic-private-participation');}
    $denyDelete=static fn($value,$object,$key)=>$key==='_kiwe_discussion_actor_v1'?false:$value;add_filter('delete_comment_metadata',$denyDelete,PHP_INT_MAX,3);$failedProvenance=DSA\Discussion\Discussion_Privacy::erase_provenance('privacy@example.test');remove_filter('delete_comment_metadata',$denyDelete,PHP_INT_MAX);
    $check(is_wp_error($failedProvenance),'An incomplete participation-metadata erasure reports failure rather than false completion');
    $firstPass=DSA\Discussion\Discussion_Privacy::erase_provenance('privacy@example.test',1);$lastPass=DSA\Discussion\Discussion_Privacy::erase_provenance('privacy@example.test',2);$left=[];foreach($provenance as$id)foreach(DSA\Discussion\Discussion_Service::privacy_metadata()as$key)if(metadata_exists('comment',$id,$key))$left[]=$id;
    $check($firstPass['items_removed']&&!$firstPass['done']&&$lastPass['done']&&!$left&&!metadata_exists('user',$privacyUser,'_kiwe_discussion_name_choice_v1'),'Bounded privacy retries erase all Send/body/actor/proof/name metadata and adjacent name-choice consent');
    $check(get_comment($provenance[0])->comment_author_email==='privacy@example.test'&&get_userdata($privacyUser)->display_name==='Chosen Privacy Reader','Kiwe provenance erasure leaves native contact/name anonymization to WordPress’s existing owners');
    DSA\Discussion\Discussion_Reaction_Store::set($root,$privacyUser,'like',true);DSA\Discussion\Discussion_Reaction_Store::set($root,$privacyUser,'report',true);
    require_once ABSPATH.'wp-admin/includes/user.php';wp_set_current_user(1);$before=DSA\Discussion\Discussion_Reactions::revision($article);wp_delete_user($privacyUser);
    $check(!get_userdata($privacyUser)&&!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE user_id=%d",$privacyUser))&&DSA\Discussion\Discussion_Reactions::revision($article)!==$before,'Native account deletion removes its likes and reports and invalidates public ranking');
    $exports=DSA\Discussion\Discussion_Privacy::exporters([]);$erasers=DSA\Discussion\Discussion_Privacy::erasers(['comments'=>['callback'=>'wp_comments_personal_data_eraser']]);
    $check(is_callable($exports['kiwe-discussion-reactions']['callback'])&&is_callable($erasers['kiwe-discussion-reactions']['callback'])&&array_key_first($erasers)==='kiwe-discussion-provenance'&&isset($erasers['comments']),'Native privacy tools erase Kiwe provenance before native comment lookup is anonymized, without replacing native handling');
}
