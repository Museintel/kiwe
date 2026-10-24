<?php
use DSA\Onboarding\Design_Context_Profile_Service as Context;
/** Actual business renderer with synthetic WordPress I/O; never claims native WP acceptance. */
ob_start();require __DIR__.'/test-design-context-native.php';ob_end_clean();
$root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/';
define('DSA_URL','https://example.test/wp-content/mu-plugins/dsa/');define('DSA_VERSION','test');
foreach(['Access/Workspace_Design_Service','Design/Seam_Token_Service','Onboarding/Design_Context_Journey_Service','Onboarding/Onboarding_Service']as $file)require $root.'includes/'.$file.'.php';
eval('namespace DSA\\Communications; class Channel_Service {}');
eval('namespace DSA\\Onboarding; class Design_Context_Refinement_Service {}');
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}function esc_textarea($v){return esc_html($v);}function esc_html__($v,$d=''){return esc_html($v);}function esc_html_e($v,$d=''){echo esc_html($v);}function esc_attr_e($v,$d=''){echo esc_attr($v);}
function selected($a,$b,$echo=true){$s=(string)$a===(string)$b?' selected':'';if($echo)echo $s;return $s;}function checked($a,$b=true,$echo=true){$s=(string)$a===(string)$b?' checked':'';if($echo)echo $s;return $s;}
function admin_url($p=''){return 'https://example.test/wp-admin/'.$p;}function wp_die($message){throw new RuntimeException($message);}
function add_query_arg($args,$url){return $url.'&'.http_build_query($args);}function wp_check_password($value,$hash){return password_verify($value,$hash);}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="synthetic-nonce">';}function wp_json_encode($v){return json_encode($v);}function get_transient($key){return false;}function delete_transient($key){}function wp_get_attachment_image_url($id,$size){return 'https://example.test/image.png';}
function wp_enqueue_media(){$GLOBALS['media_calls']++;}function wp_style_is($name,$state){return isset($GLOBALS['styles'][$name]);}function wp_enqueue_style($name,...$args){$GLOBALS['styles'][$name]=$args;}function wp_enqueue_script($name,...$args){$GLOBALS['scripts'][$name]=$args;}function wp_add_inline_style($name,$css){$GLOBALS['inline']=$css;}function wp_localize_script($name,$key,$config){$GLOBALS['config']=$config;}
$actor=1;$users[1]->caps['manage_options']=false;$users[1]->caps['kiwe_manage_context']=true;
$options[Context::OPTION_PROFILE]=[];$service=new DSA\Onboarding\Onboarding_Service(new Context(),new DSA\Communications\Channel_Service(),new DSA\Onboarding\Design_Context_Refinement_Service());
$styles=[];$scripts=[];$media_calls=0;$_GET=['page'=>'other'];$service->assets('other');check(!$styles&&!$scripts&&!$media_calls,'unrelated screen loads no business assets');
$users[1]->caps['kiwe_manage_context']=false;$_GET=['page'=>'kiwe-identity'];$service->assets('');check(!$styles&&!$media_calls,'denied client gets no business assets');$users[1]->caps['kiwe_manage_context']=true;
check(!in_array('kiwe-store',DSA\Onboarding\Onboarding_Service::section_slugs(),true),'no commerce integration omits Store');
$commerce=true;
foreach(['identity','story','contact','brand','website-plan','services','store']as $section){
 $styles=[];$scripts=[];$media_calls=0;$_GET=['page'=>'kiwe-'.$section,'saved'=>'1'];$service->assets('');
 check($media_calls===(int)in_array($section,['identity','story','services'],true),'media assets only on image-using section: '.$section);
 check(($styles['kiwe-onboarding'][1]??[])===['kiwe-app-primitives']&&!isset($config['saved']),'shared tokens and no URL-forged saved state: '.$section);
 ob_start();$service->render();$html=ob_get_clean();
 check(substr_count($html,'aria-current="page"')===1&&str_contains($html,'Business details sections')&&str_contains($html,'data-kiwe-onboarding-form'),'actual section render has one current destination and native form: '.$section);
 if($section==='services')check(!str_contains($html,'name="context[services][sourcePostType]"')&&str_contains($html,'Website connection'),'client sees binding status without an ignored technical selector');
 if(isset($argv[1])){if(!is_dir($argv[1]))mkdir($argv[1],0777,true);file_put_contents($argv[1].'/'.$section.'.html',$html);file_put_contents($argv[1].'/'.$section.'-config.json',json_encode($config));file_put_contents($argv[1].'/tokens.css',DSA\Access\Workspace_Design_Service::stylesheet());}
}
$options['kiwe_onboarding_invitations_v1']=['fixture'=>['id'=>'fixture','userId'=>1,'expiresAt'=>time()+100,'tokenHash'=>password_hash('fixture-token',PASSWORD_DEFAULT)]];
$_GET=['page'=>'kiwe-contact','kiwe_invite'=>'fixture','kiwe_token'=>'fixture-token'];ob_start();$service->render();$html=ob_get_clean();check(substr_count($html,'kiwe_invite=fixture&amp;kiwe_token=fixture-token')===7,'section navigation preserves an already verified invitation');
$options['kiwe_onboarding_invitations_v1']['fixture']['userId']=2;
try{$service->render();throw new RuntimeException('Wrong account invitation was accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'belongs to another administrator'),'cross-account invitation remains denied before render');}
$users[1]->caps['manage_options']=true;ob_start();invoke($service,'service_source_control','service',['service'=>['label'=>'Services']]);$html=ob_get_clean();check(str_contains($html,'data-kiwe-service-source')&&str_contains($html,'value="service"'),'technical owner retains the original binding control');
echo "Business workspace behavior passed.\n";
