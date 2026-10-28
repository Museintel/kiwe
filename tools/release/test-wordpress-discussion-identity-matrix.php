<?php
/** Actual unchanged PhoneKey, native comments and captured transports; never sends to a real provider. */
if(PHP_SAPI!=='cli'||!defined('KIWE_DISCUSSION_NATIVE_TEST')||!defined('KIWE_DISCUSSION_OWNED_DATABASE')||DB_NAME!==KIWE_DISCUSSION_OWNED_DATABASE)throw new RuntimeException('Owned discussion fixture required.');

function kiwe_discussion_identity_matrix_tests(callable $check):void {
    global $wpdb;
    $before=get_option('pk_settings_v3');$native=[];foreach(['require_name_email','comment_registration','comment_moderation','comment_previously_approved']as$key)$native[$key]=get_option($key);
    $service=new DSA\Discussion\Discussion_Service();$controller=new DSA\Rest\Discussion_Controller();$data=static fn($r)=>$r instanceof WP_REST_Response?$r->get_data():$r;
    $request=static function(array $body,int $post):WP_REST_Request{$r=new WP_REST_Request('POST');$r->set_header('Content-Type','application/json');$r->set_header('X-Kiwe-Mutation','1');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode($body));$r->set_url_params(['postId'=>$post]);return $r;};
    $post=wp_insert_post(['post_title'=>'Contact policy matrix','post_type'=>'post','post_status'=>'publish','comment_status'=>'open','post_author'=>1]);
    $send=static function(string $text,string $email='')use($controller,$request,$service,$post){return $controller->submit($request(['sendId'=>wp_generate_uuid4(),'text'=>$text,'name'=>'Matrix Reader','email'=>$email,'parent'=>0,'rating'=>0,'actorKey'=>$service->composer($post)['actorKey']],$post));};
    $configure=static function(string $mode,bool $phone)use($before){update_option('pk_settings_v3',array_replace((array)$before,['identifier_mode'=>$mode,'app_identifier_mode'=>$mode,'sms_webhook'=>$phone?'https://phone-fixture.invalid/capture':'','whatsapp_webhook'=>'','whatsapp_mode'=>'generic_webhook','whatsapp_email_fallback'=>false]));};
    $anonymous=static function(string $label){wp_set_current_user(0);$_COOKIE[PK_VISITOR_COOKIE]=substr(hash('sha256',$label),0,32);};
    $messages=[];$phones=[];
    $mail=static function($result,$attributes)use(&$messages){$messages[]=$attributes;return true;};
    $transport=static function($result,$args,$url)use(&$phones){if($url!=='https://phone-fixture.invalid/capture')return $result;$phones[]=json_decode($args['body'],true);return ['headers'=>[],'body'=>'{"ok":true}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];};
    add_filter('pre_wp_mail',$mail,PHP_INT_MAX,2);add_filter('pre_http_request',$transport,PHP_INT_MAX,3);
    $verify=static function(array $flow,string $type)use($data,&$messages,&$phones){$r=new WP_REST_Request('POST');$r->set_param('token',$flow['token']);$sent=$data(pk_rest_resend_otp($r));if(empty($sent['ok']))throw new RuntimeException('Captured '.$type.' transport did not accept its native code: '.($sent['message']??'No native message'));if($type==='phone')$code=end($phones)['code']??'';else{preg_match('/code is: ([0-9]{6})/',end($messages)['message']??'',$match);$code=$match[1]??'';}if(!preg_match('/^[0-9]{6}$/D',$code))throw new RuntimeException('Captured native code missing.');$r->set_param('code',$code);$r->set_param('deferOtpDelivery',true);return $data(pk_rest_verify_code($r));};
    $finish=static function(array $proof)use($data){if(!get_current_user_id()){$r=new WP_REST_Request('POST');$r->set_param('token',$proof['token']??'');$result=$data(pk_rest_continue_later($r));if(empty($result['ok']))throw new RuntimeException('Native Partial continuation did not succeed.');}};
    try{
        foreach(['require_name_email'=>'1','comment_registration'=>'0','comment_moderation'=>'0','comment_previously_approved'=>'0']as$key=>$value)update_option($key,$value);
        $wpdb->query('DELETE FROM '.$wpdb->prefix.'dsa_rate_limits');
        $configure('phone',true);$anonymous('phone-proven');$config=$service->composer($post);$snapshot=serialize(get_option('pk_settings_v3'));
        $flow=$data($controller->identity($request(['identifier'=>'+12025550111'],$post)));
        $check($config['identifierMode']==='phone'&&$config['phoneReady']&&!empty($flow['token'])&&($flow['identifierType']??'')==='phone'&&!isset($flow['displayName']),'Phone-only composer advertises the native provider and exposes no account profile');
        $proof=$verify($flow,'phone');$finish($proof);$phoneUser=get_current_user_id();$receipt=$data($send('Phone-only contact proof posts without a fabricated email.'));
        $check($phoneUser>0&&pk_factor_verified($phoneUser,'phone')&&get_userdata($phoneUser)->user_email===''&&!empty($receipt['ok'])&&$receipt['status']==='published'&&(int)get_comment($receipt['id'])->user_id===$phoneUser,'Native phone proof grants Partial participation and preserves an email-free account');
        $check(serialize(get_option('pk_settings_v3'))===$snapshot,'Phone-only participation changes no saved identity policy');
        $configure('phone',false);$anonymous('phone-skip');$snapshot=serialize(get_option('pk_settings_v3'));$flow=$data($controller->identity($request(['identifier'=>'+12025550112'],$post)));
        $check(!$service->composer($post)['phoneReady']&&!empty($flow['subscribeOnSkip'])&&!get_current_user_id(),'A missing phone provider offers only the native new-subscriber grace, without fabricated OTP proof');
        $finish($flow);$skipped=get_current_user_id();$held=$data($send('A new phone subscriber remains held without verification.'));
        $check($skipped>0&&!pk_account_verified($skipped)&&!empty($held['ok'])&&$held['status']==='pending'&&(int)get_comment($held['id'])->user_id===$skipped,'Permitted phone Skip creates only its genuine low-assurance subscriber and a moderated native comment');
        $anonymous('known-phone-claim');$existing=$data($controller->identity($request(['identifier'=>'+12025550112'],$post)));$denied=$send('Existing phone claim cannot bypass a required native email.');
        $check(empty($existing['subscribeOnSkip'])&&!get_current_user_id()&&is_wp_error($denied)&&$denied->get_error_code()==='require_name_email'&&$denied->get_error_data()['status']===400,'An existing phone claim grants no session; native guest-email refusal is a real failed HTTP request');
        update_option('require_name_email','0');$unowned=$data($send('Native guest mode can hold an unowned phone claim.'));
        $check(!empty($unowned['ok'])&&$unowned['status']==='pending'&&!(int)get_comment($unowned['id'])->user_id,'Native optional guest-email policy permits only an unowned held contribution');
        update_option('comment_registration','1');$refused=$send('Native account-required policy still wins.');
        $check(is_wp_error($refused)&&$refused->get_error_code()==='not_logged_in'&&!get_current_user_id(),'Native account-required policy cannot be bypassed by the inline composer');
        $check(serialize(get_option('pk_settings_v3'))===$snapshot,'Provider-unavailable and guest-policy paths preserve saved PhoneKey choices');
        update_option('comment_registration','0');update_option('require_name_email','1');
        $configure('email_and_phone',false);$anonymous('dual-unavailable');$dual=$controller->identity($request(['email'=>'dual-unavailable@example.test','phone'=>'+12025550113'],$post));$dualData=$data($dual);
        $check($dual instanceof WP_REST_Response&&$dual->get_status()===503&&($dualData['code']??'')==='phone_delivery_unavailable'&&!get_user_by('email','dual-unavailable@example.test'),'Dual-contact setup without a provider refuses before creating an account');
        $configure('email_and_phone',true);$anonymous('dual-proven');$snapshot=serialize(get_option('pk_settings_v3'));$flow=$data($controller->identity($request(['email'=>'dual-proven@example.test','phone'=>'+12025550114'],$post)));
        $first=$verify($flow,'email');$check(($first['next']??'')==='verify_phone'&&!get_current_user_id(),'Dual-contact email proof keeps the native phone step and cannot create a premature session');
        $proof=$verify($first,'phone');$finish($proof);$dualUser=get_current_user_id();$receipt=$data($send('Both native configured contacts are proven.'));
        $check($dualUser>0&&pk_factor_verified($dualUser,'email')&&pk_factor_verified($dualUser,'phone')&&!empty($receipt['ok'])&&$receipt['status']==='published','Dual-contact participation completes both native factor steps before immediate publication');
        $check(serialize(get_option('pk_settings_v3'))===$snapshot&&count($phones)===2,'Dual-contact proof preserves saved policy and uses only the two captured phone deliveries');
        $configure('email_or_phone',true);$anonymous('dual-conflict');$conflict=$controller->identity($request(['identifier'=>'dual-proven@example.test'],$post));
        $check(!get_current_user_id()&&!isset($data($conflict)['displayName']),'Either-contact returning identity still exposes no account profile or session from a typed address');
    }finally{remove_filter('pre_wp_mail',$mail,PHP_INT_MAX);remove_filter('pre_http_request',$transport,PHP_INT_MAX);update_option('pk_settings_v3',$before);foreach($native as$key=>$value)update_option($key,$value);wp_set_current_user(1);$wpdb->query('DELETE FROM '.$wpdb->prefix.'dsa_rate_limits');}
}
