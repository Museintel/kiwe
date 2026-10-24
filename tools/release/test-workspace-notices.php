<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
$root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/';
foreach(['Access/Workspace_Design_Service','Access/Workspace_Notice_UI','Access/Account_Setup_Notice_Service','PhoneKey/PhoneKey_Bridge','Design/Seam_Token_Service','Onboarding/Onboarding_Service']as $file)require_once $root.'includes/'.$file.'.php';
function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_html($s);}
function wp_strip_all_tags($s){return strip_tags($s);}
function is_network_admin(){return $GLOBALS['network_admin'];}function is_user_admin(){return $GLOBALS['user_admin'];}
function add_action($hook,$callback,$priority=10){$GLOBALS['hooks'][]=[$hook,$callback,$priority];}
function wp_style_is($name,$status){return isset($GLOBALS['styles'][$name]);}
function wp_enqueue_style($name,...$args){$GLOBALS['styles'][$name]=$args;}
function wp_add_inline_style($name,$css){$GLOBALS['inline'][$name]=$css;}
function render_notice($fn){ob_start();$fn();return ob_get_clean();}
$hooks=[];$styles=[];$inline=[];$network_admin=false;$user_admin=false;$factor_reads=[];
$security=new DSA\Access\Account_Setup_Notice_Service();
if(in_array('--no-key',$argv,true)){
 check($security->steps()===[],'disabled Key leaves account reminder dormant');
 $security->assets();check(!$styles&&render_notice([$security,'render'])==='','disabled Key adds no notice/assets');
 exit;
}
// Synthetic factor I/O; the account service and native completion function execute.
function pk_is_privileged($id){return in_array($id,[1,2,3,5,6],true);}
function pk_credential_count($id){$GLOBALS['factor_reads'][]=$id;return (int)$GLOBALS['factors']['passkey'];}
function pk_admin_phone_bound($id){$GLOBALS['factor_reads'][]=$id;return $GLOBALS['factors']['phone'];}
function pk_account_verified($id){$GLOBALS['factor_reads'][]=$id;return $GLOBALS['factors']['verified'];}
function pk_surface_login_url(){return 'https://example.test/?kiwe-auth=1';}
// Evaluate this unchanged native predicate only, avoiding booting the full auth runtime.
$core=file_get_contents($root.'includes/PhoneKey/phonekey-core.php');
preg_match('/function pk_admin_enrollment_complete\( \$user_id \) \{.*?\n\}/s',$core,$predicate);
if(empty($predicate[0]))throw new RuntimeException('Native enrollment predicate missing');eval($predicate[0]);
check(substr_count($core,'Account_Setup_Notice_Service()')===1&&!str_contains($core,"add_action( 'admin_notices', function ()"),'one reminder registration replaces the old paragraph');
$security->register();check($hooks[0][0]==='admin_notices'&&$hooks[0][2]===5&&$hooks[1][0]==='admin_enqueue_scripts','security reminder precedes business notice with separate scoped assets');
$actor=1;
for($mask=0;$mask<16;$mask++){
 $factors=['passkey'=>(bool)($mask&2),'phone'=>(bool)($mask&4),'verified'=>(bool)($mask&8)];$usermeta[1]['pk_admin_password_bound_at']=($mask&1)?'2026-09-08':'';
 $expected=4-substr_count(decbin($mask),'1');$steps=$security->steps();$html=render_notice([$security,'render']);
 check(count($steps)===$expected&&substr_count($html,'<li>')===$expected,'current native factors determine only outstanding steps: '.$mask);
 if($expected)check(str_contains($html,'notice-warning')&&str_contains($html,'<details>')&&!str_contains($html,'<details open')&&str_contains($html,'https://example.test/?kiwe-auth=1')&&!str_contains($html,'is-dismissible'),'warning and secure entry stay visible without dismissing requirements: '.$mask);
 else check($html==='','completed enrollment removes the reminder');
}
$factors=['passkey'=>false,'phone'=>false,'verified'=>false];$usermeta=[];
foreach([0,4]as $actor){$factor_reads=[];$styles=[];$security->assets();check($security->steps()===[]&&!$styles&&!$factor_reads,'anonymous/ordinary subscriber has no factor queries or notice: '.$actor);}
foreach([1,2,3,5,6]as $actor)check(count($security->steps())===4,'privileged current actor retains security guidance: '.$actor);
$actor=1;$styles=[];$security->assets();$security->assets();
check(array_keys($styles)===['kiwe-workspace-tokens','kiwe-app-primitives','kiwe-workspace-notices']&&!str_contains(implode(' ',array_keys($styles)),'script'),'notice loads only private tokens, shared primitives and scoped CSS');
check(str_contains($inline['kiwe-app-primitives'],'.kiwe-workspace-notice'),'standalone owner notices receive the same token projection');
$unsafe=render_notice(fn()=>DSA\Access\Workspace_Notice_UI::render('evil','<img src=x>','<script>bad</script>','https://example.test/?a=1&b=2','<b>Action</b>',['<svg>']));
check(!str_contains($unsafe,'<script>')&&!str_contains($unsafe,'<img')&&!str_contains($unsafe,'<svg>')&&str_contains($unsafe,'notice-info'),'notice text escapes at rendering and priority is constrained');
$r=new ReflectionClass(DSA\Onboarding\Onboarding_Service::class);$business=$r->newInstanceWithoutConstructor();
$p=$r->getProperty('profiles');$p->setValue($business,new DSA\Onboarding\Design_Context_Profile_Service());
$pagenow='index.php';$actor=1;$options['kiwe_onboarding_status_v1']=[];
$businessHtml=render_notice([$business,'notice']);
check(str_contains($businessHtml,'Continue business setup')&&str_contains($businessHtml,'admin.php?page=kiwe-design-context')&&!str_contains($businessHtml,'<details>'),'client Home links to the resumable context journey');
$denied=['kiwe_manage_context'];check(render_notice([$business,'notice'])==='','business notice requires the same capability as its editing home');$denied=[];
foreach(['post.php','admin.php','upload.php']as $pagenow){$styles=[];$business->notice_assets();check(render_notice([$business,'notice'])===''&&!$styles,'business reminder stays out of focused task screens: '.$pagenow);}
$pagenow='index.php';$options['kiwe_onboarding_status_v1']=['completed'=>true];$styles=[];$business->notice_assets();check(render_notice([$business,'notice'])===''&&!$styles,'native completion removes the business reminder and scoped assets');
$options['kiwe_onboarding_status_v1']=[];
foreach(['network_admin','user_admin']as $scope){$GLOBALS[$scope]=true;check(render_notice([$business,'notice'])==='','no site business notice on '.$scope);$GLOBALS[$scope]=false;}
foreach([3,4,5,6]as $actor)check(render_notice([$business,'notice'])==='','non-context role gets no business reminder: '.$actor);
check(!$writes,'rendering and expansion have no persistent security or context writes');
echo "$count notice behavior checks passed.\n";
if(isset($argv[1])){
 $actor=1;$pagenow='index.php';$factors=['passkey'=>false,'phone'=>false,'verified'=>false];$usermeta=[];
 $css=DSA\Access\Workspace_Design_Service::stylesheet().file_get_contents($root.'assets/css/app-primitives.css').file_get_contents($root.'assets/css/workspace-notices.css');
 $html='<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kiwe setup reminders — synthetic review</title><style>body{margin:0;background:#f0f2f2;font-family:system-ui;color:#1f2933}main{margin:auto;max-width:1120px;padding:24px}header{font-size:13px;padding:16px;background:white}.synthetic-task{background:white;padding:24px;border-radius:24px;border:1px solid #ccc;margin-top:24px}a{color:inherit}@media(max-width:600px){main{padding:14px}}</style><style>'.$css.'</style><header>Kiwe · Synthetic component review, not an authenticated WordPress screen</header><main>'.render_notice([$security,'render']).render_notice([$business,'notice']).'<section class="synthetic-task"><h1>Your workspace</h1><p>Your business tasks remain close at hand.</p><a href="#task">Update website content</a></section><div class="notice notice-error"><p>Unrelated plugin error remains visible.</p></div></main></html>';
 file_put_contents($argv[1],$html);
}
