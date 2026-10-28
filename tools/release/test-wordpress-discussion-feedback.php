<?php
if(PHP_SAPI!=='cli'||!defined('KIWE_DISCUSSION_NATIVE_TEST')||!defined('KIWE_DISCUSSION_OWNED_DATABASE')||DB_NAME!==KIWE_DISCUSSION_OWNED_DATABASE)throw new RuntimeException('Owned discussion fixture required.');

function kiwe_discussion_feedback_tests(callable $check,bool $store):void {
    global $wpdb;$code=static fn($r)=>is_wp_error($r)?$r->get_error_code():'';
    $known=(int)get_user_by('email','proven@example.test')->ID;$unknown=(int)get_user_by('email','unknown@example.test')->ID;
    wp_set_current_user(1);foreach(['comment_moderation'=>'0','comment_previously_approved'=>'0','comment_registration'=>'0','thread_comments'=>'1','thread_comments_depth'=>'5']as$key=>$value)update_option($key,$value);
    $post=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Private Feedback timeline fixture','comment_status'=>'open','post_author'=>1]);
    $page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Website conversation fixture','comment_status'=>'open','post_author'=>1]);add_post_type_support('page','comments');
    $seed=static function($target,$user,$text,$status=1,$parent=0){$id=wp_insert_comment(['comment_post_ID'=>$target,'comment_parent'=>$parent,'comment_type'=>get_post_type($target)==='product'?'review':'comment','user_id'=>$user,'comment_author'=>'Public Fixture Reader','comment_author_email'=>'proven@example.test','comment_content'=>$text,'comment_approved'=>$status]);add_comment_meta($id,'_kiwe_discussion_name_explicit_v1','1');return $id;};
    $root=$seed($post,$known,'A verified native contribution.');$held=$seed($page,$unknown,'A subscriber awaiting moderation.',0);$website=$seed($page,$known,'Website feedback.');
    add_comment_meta($held,'_kiwe_discussion_verified_v1','1');DSA\Discussion\Discussion_Reaction_Store::set($held,$known,'like',true);DSA\Discussion\Discussion_Reaction_Store::set($held,1,'like',true);DSA\Discussion\Discussion_Reaction_Store::set($held,$known,'report',true);
    if($store){$product=new WC_Product_Simple();$product->set_name('Feedback product fixture');$product->set_status('publish');$product->set_regular_price('10');$productId=$product->save();$productComment=$seed($productId,$known,'A product contribution.');$order=wc_create_order(['customer_id'=>$known]);$order->add_product($product,1);$order->set_status('completed');$order->save();}
    $moderation=new DSA\Access\Comment_Moderation_Service();$all=$moderation->listing();
    $check($all['status']==='all'&&$all['type']==='all'&&$all['sort']==='date_desc','Daily opens the combined native timeline rather than a pending-only or product-only queue');
    $ids=array_column($all['items'],'id');$sorted=$ids;rsort($sorted);$check($ids===$sorted&&in_array($held,$ids,true)&&in_array($root,$ids,true),'Daily mixes approved and pending contributions in deterministic newest-first order');
    $heldRecord=$moderation->record($held);$verifiedRecord=$moderation->record($root);
    $check($heldRecord['likes']===2&&$heldRecord['reports']===1&&$heldRecord['tone']==='waiting'&&!$heldRecord['verified'],'Private cards show likes and reports even while held; a spoofed proof marker cannot turn a subscriber green');
    $resolve=['id'=>$held,'expected'=>$heldRecord['reportsExpected'],'actorKey'=>(new DSA\Discussion\Discussion_Service())->current_actor_key()];$resolved=(new DSA\Access\Comment_Moderation_Service())->resolve_reports($resolve);
    $check(!is_wp_error($resolved)&&$resolved['reports']===0&&$resolved['likes']===2&&get_comment($held)->comment_approved==='0','Reviewing reports on a held comment preserves its likes and native moderation status');
    DSA\Discussion\Discussion_Reaction_Store::set($held,$known,'report',true);DSA\Discussion\Discussion_Reactions::changed($page);
    $check($code((new DSA\Access\Comment_Moderation_Service())->resolve_reports($resolve))==='kiwe_feedback_stale','An old report-review receipt cannot silently clear a later report');
    $wpdb->query('START TRANSACTION');$marker='cf23_ambient_probe';update_option($marker,'pending',false);$beforeSession=(int)$wpdb->get_var('SELECT @@session.in_transaction');
    $ambient=(new DSA\Access\Comment_Moderation_Service())->resolve_reports(['id'=>$held,'expected'=>(new DSA\Access\Comment_Moderation_Service())->record($held)['reportsExpected'],'actorKey'=>(new DSA\Discussion\Discussion_Service())->current_actor_key()]);
    $check($code($ambient)==='kiwe_feedback_storage'&&$beforeSession===1&&(int)$wpdb->get_var('SELECT @@session.in_transaction')===1,'Report review refuses an ambient caller transaction without rolling it back');$wpdb->query('ROLLBACK');wp_cache_delete($marker,'options');
    $check($verifiedRecord['verified']&&$verifiedRecord['tone']==='success','Published participation with current native Partial proof receives the green card');
    $posts=(new DSA\Access\Comment_Moderation_Service())->listing(['type'=>'post']);$pages=(new DSA\Access\Comment_Moderation_Service())->listing(['type'=>'website']);
    $check($posts['items']&&array_unique(array_column($posts['items'],'context'))===['post']&&$pages['items']&&array_unique(array_column($pages['items'],'context'))===['website'],'Post and Website filters use the actual native content context');
    if($store){$products=(new DSA\Access\Comment_Moderation_Service())->listing(['type'=>'product']);$check($products['hasReviews']&&count($products['items'])>0&&array_unique(array_column($products['items'],'context'))===['product'],'The Store adds a Product filter to the same activity stream');
        if(($verifiedRecord['purchase']['purchases']??0)<1){$q=wc_get_orders(['customer_id'=>$known,'status'=>wc_get_is_paid_statuses(),'limit'=>1,'paginate'=>true]);throw new RuntimeException('Synthetic commerce context diagnostics: '.wp_json_encode(['canEdit'=>current_user_can('edit_shop_orders'),'canEditOthers'=>current_user_can('edit_others_shop_orders'),'proof'=>$verifiedRecord['verified'],'context'=>$verifiedRecord['purchase'],'orderStatus'=>$order->get_status(),'orderOwnerMatches'=>(int)$order->get_customer_id()===$known,'queryTotal'=>$q->total??null]));}
        $check($verifiedRecord['purchase']['purchases']>=1,'Authorized commerce staff see paid orders attached to a proven native customer');}
    else $check(!$all['hasReviews']&&!class_exists('DSA\\Commerce\\Discussion_Review_Adapter',false),'Non-Store Feedback exposes no product filter or commerce-history adapter');
    $guest=$seed($post,0,'Typed buyer email without ownership.');$check((new DSA\Access\Comment_Moderation_Service())->record($guest)['purchase']===null,'A guest’s typed buyer email never attributes private purchases');
    $editor=wp_create_user('feedback-editor',wp_generate_password(),'feedback-editor@example.test');(new WP_User($editor))->set_role('editor');wp_set_current_user($editor);
    $editorRecord=(new DSA\Access\Comment_Moderation_Service())->record($root);$editorList=(new DSA\Access\Comment_Moderation_Service())->listing();
    $check(!is_wp_error($editorRecord)&&$editorRecord['purchase']===null&&$editorList['policy']===null,'Editorial moderators receive Feedback without commerce history or global policy controls');
    $check($code(DSA\Discussion\Discussion_Settings::save(['field'=>'moderateAll','value'=>true,'expected'=>'x']))==='kiwe_discussion_policy_denied','A moderator cannot change the site owner’s native discussion policy');
    wp_set_current_user($known);$check($code((new DSA\Access\Comment_Moderation_Service())->listing())==='kiwe_feedback_forbidden','An ordinary participant cannot enter the private Feedback timeline');
    wp_set_current_user(1);for($i=0;$i<22;++$i)$seed($post,0,'Timeline continuation '.$i);
    $focus=(new DSA\Access\Comment_Moderation_Service())->listing(['id'=>$root]);
    $check($focus['focus']['id']===$root&&count($focus['items'])===16&&!in_array($root,array_column($focus['items'],'id'),true)&&$focus['hasNext'],'A notification focuses its older record alongside the full continuing activity list');
    $publicService=new DSA\Discussion\Discussion_Service();$focused=$publicService->listing($post,0,0,'latest','',$root);$privateFocus=$publicService->listing($post,0,0,'latest','',$held);
    $check($focused['focus']['id']===$root&&count($focused['items'])===12&&$privateFocus['focus']===null&&$privateFocus['focusUnavailable'],'Public deep links can focus approved history without exposing another target or pending record');
    $policy=DSA\Discussion\Discussion_Settings::read();$countBefore=wp_count_posts('page')->publish;$set=DSA\Discussion\Discussion_Settings::save(['field'=>'sitePage','value'=>(int)$page,'expected'=>$policy['expected']]);
    $check(!is_wp_error($set)&&DSA\Discussion\Discussion_Target::automatic_id(0)===$page&&DSA\Discussion\Discussion_Target::descriptor(get_post($page))['kind']==='site'&&wp_count_posts('page')->publish===$countBefore,'Site-wide feedback selects an existing public native page without creating a target');
    $check($code(DSA\Discussion\Discussion_Settings::save(['field'=>'accountRequired','value'=>true,'expected'=>$policy['expected']]))==='kiwe_discussion_policy_stale','A stale policy snapshot cannot overwrite a newer setting');
    $private=wp_insert_post(['post_type'=>'page','post_status'=>'private','post_title'=>'Private target fixture']);
    $check($code(DSA\Discussion\Discussion_Settings::save(['field'=>'sitePage','value'=>(int)$private,'expected'=>$set['expected']]))==='kiwe_discussion_policy_input','Private pages cannot become a public website-feedback target');
    $deny=static fn($value,$old)=>$old;add_filter('pre_update_option_comment_registration',$deny,PHP_INT_MAX,2);$failed=DSA\Discussion\Discussion_Settings::save(['field'=>'accountRequired','value'=>true,'expected'=>$set['expected']]);remove_filter('pre_update_option_comment_registration',$deny,PHP_INT_MAX);
    $check($code($failed)==='kiwe_discussion_policy_write'&&!get_option('comment_registration'),'A failed native policy write remains truthful and preserves the old choice');
    $reset=DSA\Discussion\Discussion_Settings::save(['field'=>'sitePage','value'=>0,'expected'=>DSA\Discussion\Discussion_Settings::read()['expected']]);$check(!is_wp_error($reset)&&DSA\Discussion\Discussion_Target::automatic_id(0)===0,'Turning off the optional site-wide target preserves its native page and comments');
    // This bare-service fixture does not boot Core's role installer. Give only its synthetic
    // native owner the real schema-maintenance capability before testing the actual router.
    wp_get_current_user()->add_cap('kiwe_manage_notification_policy');
    $preferences=new DSA\Notifications\Notification_Preference_Service(new DSA\Settings(),new DSA\Trust\Trust_Service());$preferences->maybe_install();
    $check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'dsa_notification_preferences')))===$wpdb->prefix.'dsa_notification_preferences','The actual preference schema exists before native notification routing is qualified');
    $notifier=new DSA\Notifications\Admin_Event_Notification_Service($preferences,new DSA\Notifications\Push_Service());$notifier->register(false);$activity=new DSA\Access\Workspace_Activity_Service();$activity->register();
    foreach([1,$known]as$id)(new WP_User($id))->add_cap('kiwe_use_notifications');
    $body=['id'=>$root,'expected'=>(new DSA\Access\Comment_Moderation_Service())->record($root)['expected'],'text'=>'A native website-team reply.','name'=>'Website Team','sendId'=>wp_generate_uuid4(),'actorKey'=>$publicService->current_actor_key()];
    $reply=(new DSA\Access\Comment_Moderation_Service())->reply($body);$repeat=(new DSA\Access\Comment_Moderation_Service())->reply($body);
    $check(!is_wp_error($reply)&&$reply['status']==='published'&&!is_wp_error($repeat)&&$repeat['id']===$reply['id']&&$repeat['repeated'],'Daily replies use the same native exactly-once composer and team authority');
    $check((int)get_comment($reply['id'])->comment_parent===$root&&DSA\Discussion\Public_Identity::team_member(get_comment($reply['id'])),'A website-team reply remains a native child with its current team badge');
    $state=get_option(DSA\Access\Workspace_Activity_Service::OPTION);$check(($state['totals']['feedback']??0)===1&&wp_next_scheduled('dsa_admin_notification_event',['new_comment',$reply['id']]),'A committed Send increments Daily once and queues owner delivery after commit');
    $denyMeta=static fn($value,$object,$key)=>$key==='_kiwe_discussion_body_v1'?false:$value;add_filter('add_comment_metadata',$denyMeta,PHP_INT_MAX,3);
    $bad=$publicService->submit($post,['sendId'=>wp_generate_uuid4(),'name'=>'Website Team','text'=>'Rollback must not notify.']);remove_filter('add_comment_metadata',$denyMeta,PHP_INT_MAX);
    $after=get_option(DSA\Access\Workspace_Activity_Service::OPTION);$check(is_wp_error($bad)&&($after['totals']['feedback']??0)===1,'A rolled-back native Send cannot leave a phantom Daily arrival');
    $notifier->dispatch('discussion_reply',$reply['id']);$inbox=get_user_meta($known,'dsa_admin_notification_inbox',true);
    $check(count(array_filter((array)$inbox,static fn($item)=>($item['type']??'')==='discussion_update'))===1,'A queued approved reply reaches only its proven native parent owner’s existing inbox');
    $beforeInbox=$inbox;wp_update_comment(['comment_ID'=>$guest,'comment_parent'=>$root]);$notifier->dispatch('discussion_status',$guest);
    $check(get_user_meta($known,'dsa_admin_notification_inbox',true)===$beforeInbox,'A typed guest contact cannot redirect approval notifications into an existing account');
    DSA\Discussion\Discussion_Reaction_Store::set($root,1,'like',true);update_comment_meta($root,DSA\Discussion\Discussion_Reactions::HEART,'1');$notifier->dispatch('discussion_like',$root);$notifier->dispatch('discussion_heart',$root);$notifier->dispatch('new_comment',$reply['id']);
    $inbox=get_user_meta($known,'dsa_admin_notification_inbox',true);$owner=get_user_meta(1,'dsa_admin_notification_inbox',true);
    $check(count(array_filter((array)$inbox,static fn($item)=>($item['type']??'')==='discussion_update'))===3,'Likes and business hearts use the same role-safe personal conversation topic');
    $ownerComments=array_values(array_filter((array)$owner,static fn($item)=>($item['type']??'')==='admin_new_comment'));
    $check($ownerComments&&str_contains($ownerComments[0]['actionUrl'],'page=kiwe-feedback')&&str_contains($ownerComments[0]['actionUrl'],'feedback='),'Owner notification links enter the full Feedback timeline with a focused record');
    wp_set_current_user($known);wp_set_comment_status($root,'hold');$visible=$notifier->pull_current_user();
    $check(!array_filter($visible,static fn($item)=>($item['type']??'')==='discussion_update')&&DSA\Notifications\Admin_Event_Notification_Service::unread_count()===0,'Held ancestry removes stale personal conversation notices from both inbox and badge');
    wp_set_current_user(1);wp_set_comment_status($root,'approve');
    $check($preferences->personal_topic('discussion_update')&&$preferences->topic_allowed_for_user($known,'discussion_update')&&!$preferences->topic_allowed_for_user($known,'admin_comment_report'),'Personal conversation and owner-report entitlements remain distinct and role-scoped');
    update_user_meta(1,'_kiwe_account_suspended','1');$check($code((new DSA\Access\Comment_Moderation_Service())->listing())==='kiwe_feedback_forbidden'&&DSA\Discussion\Discussion_Settings::read()===[],'Suspended staff cannot retain private Feedback or policy access');delete_user_meta(1,'_kiwe_account_suspended');
    if($store){
        $policy=DSA\Commerce\Discussion_Review_Adapter::policy();$changed=DSA\Commerce\Discussion_Review_Adapter::save_policy(['field'=>'enabled','value'=>true,'expected'=>$policy['expected']]);
        $check(!is_wp_error($changed)&&get_option('woocommerce_enable_reviews')==='yes','Store review controls save the original WooCommerce option');
        $buyerOnly=DSA\Commerce\Discussion_Review_Adapter::save_policy(['field'=>'buyersOnly','value'=>true,'expected'=>$changed['expected']]);
        $check(!is_wp_error($buyerOnly)&&DSA\Commerce\Discussion_Review_Adapter::validate($productId,$productComment,0)===true&&is_wp_error(DSA\Commerce\Discussion_Review_Adapter::validate($productId,0,5)),'Buyer-only product reviews do not prevent the non-buying website team from replying without another rating');
        $check($code(DSA\Commerce\Discussion_Review_Adapter::save_policy(['field'=>'stars','value'=>false,'expected'=>$policy['expected']]))==='kiwe_review_policy_stale','Stale Store review controls cannot overwrite current native policy');
        $current=DSA\Commerce\Discussion_Review_Adapter::policy();$starsOff=DSA\Commerce\Discussion_Review_Adapter::save_policy(['field'=>'stars','value'=>false,'expected'=>$current['expected']]);update_comment_meta($productComment,'rating','5');
        $check(!is_wp_error($starsOff)&&DSA\Commerce\Discussion_Review_Adapter::rating(get_comment($productComment))===0,'Public review stars respect WooCommerce’s disabled-rating setting while native rating data remains');
        $label=DSA\Commerce\Discussion_Review_Adapter::save_policy(['field'=>'buyerLabel','value'=>false,'expected'=>$starsOff['expected']]);update_comment_meta($productComment,'verified','1');
        $check(!is_wp_error($label)&&!DSA\Commerce\Discussion_Review_Adapter::verified_badge(get_comment($productComment),[$known=>true]),'Public purchase badges respect the native WooCommerce label control');
        wp_set_current_user($editor);$check($code(DSA\Commerce\Discussion_Review_Adapter::save_policy(['field'=>'enabled','value'=>false,'expected'=>$label['expected']]))==='kiwe_review_policy_denied','An editorial moderator cannot change the Store’s review policy');wp_set_current_user(1);
    }
}
