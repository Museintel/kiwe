<?php
/** Targeted native PhoneKey/composer integration, within the fresh owned discussion fixture only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_DISCUSSION_NATIVE_TEST' ) || ! defined( 'KIWE_DISCUSSION_OWNED_DATABASE' ) || DB_NAME !== KIWE_DISCUSSION_OWNED_DATABASE ) throw new RuntimeException( 'Owned discussion integration required.' );

function kiwe_discussion_composer_tests( callable $check ): void {
    global $wpdb;
    $data = static fn( $r ) => $r instanceof WP_REST_Response ? $r->get_data() : $r;
    $request = static function( array $body, int $post ): WP_REST_Request {
        $r=new WP_REST_Request('POST'); $r->set_header('Content-Type','application/json'); $r->set_header('X-Kiwe-Mutation','1'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_body(wp_json_encode($body)); $r->set_url_params(['postId'=>$post]); return $r;
    };
    $post=wp_insert_post(['post_title'=>'Inline verification fixture','post_status'=>'publish','post_type'=>'post','comment_status'=>'open','post_author'=>1]);
    if (is_wp_error(DSA\Discussion\Discussion_Target::get((int)$post))) { $p=get_post($post); throw new RuntimeException('Composer target diagnostic: '.wp_json_encode(['id'=>$post,'type'=>$p->post_type??null,'status'=>$p->post_status??null,'password'=>!empty($p->post_password),'public'=>$p?DSA\Utilities\Content_Visibility::is_public($p):false,'supports'=>post_type_supports('post','comments'),'dbError'=>$wpdb->last_error])); }
    update_option('comment_moderation','0'); update_option('comment_previously_approved','0'); update_option('comment_registration','0');
    update_option('pk_settings_v3',array_replace(pk_default_settings(),['email_delivery'=>'magic_link','identifier_mode'=>'email','app_identifier_mode'=>'email']));
    $settingsBefore=serialize(get_option('pk_settings_v3'));
    $service=new DSA\Discussion\Discussion_Service(); $controller=new DSA\Rest\Discussion_Controller();
    $captured=[];
    $mail=static function($sent,$attributes)use(&$captured){$captured[]=$attributes;return true;};
    add_filter('pre_wp_mail',$mail,PHP_INT_MAX,2);
    try {
        wp_set_current_user(0); $_COOKIE[PK_VISITOR_COOKIE]=str_repeat('d',32);
        $anonymous=$service->composer($post);
        $start=$controller->identity($request(['identifier'=>'composer-new@example.test'],$post)); $flow=$data($start);
        if (!$start instanceof WP_REST_Response || $start->get_status()!==200 || empty($flow['token']) || empty($flow['deliveryPending']) || $captured) throw new RuntimeException('Composer start diagnostic: '.wp_json_encode(['status'=>$start instanceof WP_REST_Response?$start->get_status():null,'error'=>is_wp_error($start)?$start->get_error_code():($flow['code']??null),'mode'=>is_array($flow)?($flow['mode']??null):null,'deferred'=>is_array($flow)?($flow['deliveryPending']??null):null,'mailCount'=>count($captured)]));
        $check($start instanceof WP_REST_Response && $start->get_status()===200 && !empty($flow['token']) && !empty($flow['deliveryPending']) && !$captured,'Composer reuses PhoneKey identification with deferred OTP delivery');
        $account=get_user_by('email','composer-new@example.test');
        $check($account && in_array('subscriber',$account->roles,true) && get_current_user_id()===0 && !pk_account_verified($account->ID),'New commenter uses the existing native Subscriber role without premature sign-in or proof');
        $check(!array_key_exists('displayName',$flow) && !array_key_exists('roles',$flow) && serialize(get_option('pk_settings_v3'))===$settingsBefore && pk_settings()['email_delivery']==='magic_link','Composer delivery presentation exposes no account name and changes no saved PhoneKey setting');
        $codeRequest=new WP_REST_Request('POST');$codeRequest->set_param('token',$flow['token']);
        $delivery=$data(pk_rest_resend_otp($codeRequest)); $message=end($captured)['message']??'';preg_match('/code is: ([0-9]{6})/',$message,$match);$code=$match[1]??'';
        $check(!empty($delivery['ok']) && strlen($code)===6 && count($captured)===1,'Existing resend service delivers exactly one six-digit code through the captured native mail transport');
        $codeRequest->set_param('code','000000');$wrong=pk_rest_verify_code($codeRequest);
        $check($wrong instanceof WP_REST_Response && $wrong->get_status()===403 && !pk_account_verified($account->ID),'Incorrect inline code cannot verify the account');
        $codeRequest->set_param('code',$code);$proof=$data(pk_rest_verify_code($codeRequest));
        $check(!empty($proof['ok']) && pk_account_verified($account->ID) && get_current_user_id()===0 && !empty($proof['token']),'Native OTP proves one contact before existing PhoneKey completes the session');
        $finish=new WP_REST_Request('POST');$finish->set_param('token',$proof['token']);$finished=$data(pk_rest_continue_later($finish));
        $check(!empty($finished['ok']) && get_current_user_id()===$account->ID && pk_security_completion($account->ID)['state']==='partial','Existing PhoneKey continuation grants its ordinary Partial session without requiring passkey setup');
        $composer=$service->composer($post);
        $body=['sendId'=>wp_generate_uuid4(),'parent'=>0,'name'=>'Inline Reader','text'=>'One explicit Send after native OTP.','email'=>'','rating'=>0,'actorKey'=>$anonymous['actorKey']];
        $stale=$controller->submit($request($body,$post));
        $check(is_wp_error($stale) && $stale->get_error_code()==='kiwe_discussion_actor','A stale pre-login actor cannot submit as the newly authenticated account');
        $body['actorKey']=$composer['actorKey'];$receipt=$data($controller->submit($request($body,$post)));$again=$data($controller->submit($request($body,$post)));
        $check(!empty($receipt['ok']) && $receipt['status']==='published' && !empty($again['repeated']) && $again['id']===$receipt['id'],'The existing OTP continuation completes one explicit Send and its retry produces one native record');
        $check($data(pk_rest_verify_code($codeRequest))['ok']===false,'A consumed OTP cannot be replayed');
        wp_set_current_user(0);$_COOKIE[PK_VISITOR_COOKIE]=str_repeat('e',32);
        $new=$data($controller->identity($request(['identifier'=>'composer-skip@example.test'],$post)));
        $check(!empty($new['subscribeOnSkip']) && !get_current_user_id(),'Skip subscription is offered only when PhoneKey permits its existing new-account grace');
        $finish->set_param('token',$new['token']);$skipped=$data(pk_rest_continue_later($finish));$skipUser=get_current_user_id();
        $check(!empty($skipped['ok']) && $skipUser>0 && !pk_account_verified($skipUser),'Skip uses the existing low-assurance session without inventing a verified state');
        $body=['sendId'=>wp_generate_uuid4(),'parent'=>0,'name'=>'Moderated Reader','text'=>'Skipped OTP stays pending.','email'=>'','rating'=>0,'actorKey'=>$service->composer($post)['actorKey']];
        $pending=$data($controller->submit($request($body,$post)));
        $check(!empty($pending['ok']) && $pending['status']==='pending' && (int)get_comment($pending['id'])->user_id===$skipUser,'Skipped verification is linked to its genuine new subscriber and held for moderation');
        wp_set_current_user(0);$_COOKIE[PK_VISITOR_COOKIE]=str_repeat('f',32);
        $known=$data($controller->identity($request(['identifier'=>'composer-new@example.test'],$post)));
        $check(empty($known['subscribeOnSkip']) && !get_current_user_id() && !isset($known['displayName']),'A typed existing account cannot reuse the new-subscriber grace or expose its profile');
        $forbiddenFinish=new WP_REST_Request('POST');$forbiddenFinish->set_param('token',$known['token']);$denied=pk_rest_continue_later($forbiddenFinish);
        $check($denied instanceof WP_REST_Response && $denied->get_status()===403 && !get_current_user_id(),'An existing verified account still requires its original PhoneKey ownership journey');
        $body=['sendId'=>wp_generate_uuid4(),'parent'=>0,'name'=>'Guest Reader','text'=>'Known contact claim stays unowned.','email'=>'composer-new@example.test','rating'=>0,'actorKey'=>$service->composer($post)['actorKey']];
        $unowned=$data($controller->submit($request($body,$post)));
        $check(!empty($unowned['ok']) && $unowned['status']==='pending' && !get_comment($unowned['id'])->user_id,'A known-contact Skip can only become an unowned moderated native guest comment');
        wp_set_current_user(1);
        $privileged=$controller->identity($request([],$post));
        $check($privileged instanceof WP_REST_Response && $privileged->get_status()===403,'Privileged PhoneKey refusal retains its native HTTP status through the composer adapter');
        $private=wp_insert_post(['post_title'=>'Private discussion fixture','post_type'=>'post','post_status'=>'private','comment_status'=>'open']);
        $closed=$controller->identity($request(['identifier'=>'never-create@example.test'],$private));
        $check(is_wp_error($closed) && !get_user_by('email','never-create@example.test'),'An unavailable discussion cannot create accounts or start authentication');
        $countsRequest=new WP_REST_Request('GET');$countsRequest->set_param('ids',$post.','.$private);$counts=$controller->counts($countsRequest)->get_data()['counts'];
        $check($counts[$post]['count']===1 && !$counts[$private]['available'] && $counts[$private]['count']===0,'Attributed counts reveal only approved top-level records on eligible public targets');
        $contextRequest=new WP_REST_Request('GET');$contextRequest->set_param('url','https://outside.example.test/?p='.$post);
        $check(is_wp_error($controller->context($contextRequest)),'Morph discussion context never resolves an external URL');
        $contextRequest->set_param('url',home_url('/?p='.$post));$context=$controller->context($contextRequest)->get_data();
        $check($context['targetId']===$post && $context['count']===1,'Native URL context resolves the current article without exposing hidden content');
        $check(DSA\Bricks\Discussion_Bindings::render('kiwe_discussion_id',get_post($post))===(string)$post && DSA\Bricks\Discussion_Bindings::render('kiwe_discussion_count',get_post($post))==='1' && DSA\Bricks\Discussion_Bindings::render('kiwe_discussion_id',get_post($private))==='','Native post bindings share public target and root-count authority');
        $check(serialize(get_option('pk_settings_v3'))===$settingsBefore,'All inline verification paths preserve saved PhoneKey policy');
    } finally { remove_filter('pre_wp_mail',$mail,PHP_INT_MAX); }
}
