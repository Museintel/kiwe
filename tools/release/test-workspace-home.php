<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Admin_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Design_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Page_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php';
function esc_html_e($s,$d=''){echo esc_html($s);}function esc_attr__($s,$d=''){return esc_html($s);}function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_html($s);}
function sanitize_html_class($s){return sanitize_key($s);}function wp_date($s){return '10';}function get_bloginfo($s){return $s==='name'?'Juniper Studio':'Thoughtful spaces, made for living.';}
function wp_strip_all_tags($s){return strip_tags($s);}
function number_format_i18n($s){return (string)$s;}function wp_parse_url($s){return parse_url($s);}
function wp_count_posts($type,$permission){$GLOBALS['counts_called'][]=[$type,$permission];return (object)['draft'=>8,'pending'=>3,'publish'=>42];}
function wp_count_comments(){$GLOBALS['comments_called']++;return (object)['moderated'=>2];}
function wp_style_is($name,$status){return isset($GLOBALS['styles'][$name]);}
function wp_enqueue_style($name,...$args){$GLOBALS['styles'][$name]=$args;}
function wp_add_inline_style($name,$value){$GLOBALS['inline'][$name]=$value;}
function wp_add_dashboard_widget($id,$title,$callback){$GLOBALS['registered_widgets'][$id]=['title'=>$title,'callback'=>$callback];}
$counts_called=[];$comments_called=0;$styles=[];$inline=[];
$options['kiwe_seam_design_context_v1']['about']['team']['enabledByOwner']=true; // This Home fixture includes a public team.
$workspace=new DSA\Access\Workspace_Admin_Service();
$users[1]->first_name='Asha';$users[3]->first_name='Asha';$users[5]->first_name='Asha';$users[6]->first_name='Asha';
function render_home($service){ob_start();$service->render_dashboard();$service->navigation();return ob_get_clean();}
$pagenow='admin.php';$_GET=['page'=>DSA\Access\Workspace_Admin_Service::PAGE];
$actor=1;$html=render_home($workspace);
$workspace->dashboard();$widget=$GLOBALS['registered_widgets']['kiwe-workspace-home']??[];
check(!empty($widget['title'])&&is_callable($widget['callback']??null),'client Home registers a titled widget that native WordPress will render');
check(str_contains($html,'Juniper Studio')&&str_contains($html,'Media')&&str_contains($html,'Categories')&&str_contains($html,'Team'),'client Home links existing task homes with native identity');
check($counts_called===[['post','readable']]&&$comments_called===1,'client overview requests bounded readable native counts');
check(substr_count($html,'aria-current="page"')===1,'mobile Home has one active destination');
check(str_contains($html,'href="https://example.test/wp-admin/post-new.php"')&&str_contains($html,'New post')&&str_contains($html,'edit.php?post_status=pending'),'administrator can reach native creation and review from Home');
preg_match('/<nav class="kiwe-workspace__dock".*?<\/nav>/s',$html,$dock);
check(substr_count($dock[0],'<a ')===5&&str_contains($dock[0],'edit.php'),'administrator mobile dock includes Posts within five destinations');
$pagenow='edit.php';$_GET=[];$html=render_home($workspace);check(substr_count($html,'aria-current="page"')===1&&str_contains($html,'edit.php" aria-current="page"'),'native Posts list is the selected dock destination');$pagenow='admin.php';$_GET=['page'=>DSA\Access\Workspace_Admin_Service::PAGE];
$denied=['create_posts'];$html=render_home($workspace);check(!str_contains($html,'post-new.php')&&str_contains($html,'>Posts<'),'revoked create permission removes New post while keeping permitted list');
$denied=['edit_posts'];$html=render_home($workspace);check(!str_contains($html,'post-new.php')&&!str_contains($html,'>Posts<')&&!str_contains($html,'Review submissions'),'revoked post editing removes all posting task shortcuts');$denied=[];
$menu=[['Dashboard','read','index.php']];$submenu=['index.php'=>[['Dashboard','read','index.php']]];$workspace->menu_labels();
check($menu[0][0]==='Home'&&$submenu['index.php'][0][0]==='Home','Home renaming changes the actual native menu arrays');
$workspace->assets();check(array_keys($styles)===['kiwe-workspace-tokens','kiwe-app-primitives','kiwe-workspace-admin'],'active workspace loads only the private token projection, primitives and its own shell styles');
$registry=DSA\Design\Seam_Token_Service::css_variables();$css=DSA\Access\Workspace_Design_Service::stylesheet();
check(str_contains($css,'--kiwe-radius-xl:'.$registry['--kiwe-radius-xl'].';')&&strlen($css)<2400,'workspace projects the existing registry as a small stylesheet');
foreach([3,5]as $actor){$counts_called=[];$comments_called=0;$html=render_home($workspace);check(!$counts_called&&!$comments_called&&!str_contains($html,'Content at a glance'),'staff Home avoids other authors counters: '.$actor);}
$actor=5;check(str_contains($html,'Guest workspace')&&!str_contains($html,'kiwe-identity'),'Guest Home stays in contribution tasks');
check(!str_contains($html,'post-new.php')&&!str_contains($html,'>Posts<'),'Guests keep the submission workspace without a native posting route');
$actor=3;$html=render_home($workspace);check(str_contains($html,'post-new.php')&&str_contains($html,'Manage your articles and drafts')&&!str_contains($html,'post_status=pending'),'Authors reach native creation and own posts without a site review queue');
$actor=6;$html=render_home($workspace);check(str_contains($html,'Content at a glance')&&!str_contains($html,'Design library'),'editor has publishing overview without client identity controls');
check(!str_contains($html,'post-new.php')&&str_contains($html,'edit.php?post_status=pending'),'Editors reach review while preserving the existing no-create policy');
$users[7]=new WP_User(7,['shop_manager']);$actor=7;$html=render_home($workspace);check(!str_contains($html,'href="https://example.test/wp-admin/post-new.php"')&&!str_contains($html,'>Posts<'),'shop role does not gain article posting');
$actor=4;check(render_home($workspace)==='','subscriber has no staff workspace');
$actor=2;$styles=[];check(str_contains(render_home($workspace),'Juniper Studio'),'technical owner can render Home');$pagenow='plugins.php';$_GET=[];$workspace->assets();check(!$styles&&$workspace->body_class('native')==='native','technical screens keep original admin chrome');$pagenow='admin.php';$_GET=['page'=>DSA\Access\Workspace_Admin_Service::PAGE];
$options[DSA\Access\Site_Owner_Service::OPTION]['enabled']=false;$actor=1;$users[1]->allcaps['read']=true;check(str_contains(render_home($workspace),'Juniper Studio'),'administrator Home remains available before ownership activation');
echo "$count dashboard behavior checks passed.\n";
// Optional synthetic HTML only; used for visual review, never claimed as native WP proof.
if(isset($argv[1])){
 $options[DSA\Access\Site_Owner_Service::OPTION]['enabled']=true;$actor=(int)($argv[2]??1);$pagenow='admin.php';$_GET=['page'=>DSA\Access\Workspace_Admin_Service::PAGE];if(!in_array($actor,[1,3,5,6,7],true))throw new RuntimeException('Unsupported synthetic preview actor');
 $base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/assets/css/';
 $css=DSA\Access\Workspace_Design_Service::stylesheet().file_get_contents($base.'app-primitives.css').file_get_contents($base.'workspace-admin.css');
 file_put_contents($argv[1],'<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kiwe Home — synthetic layout preview</title><style>body{margin:0;font-family:system-ui}#wpcontent{padding-left:32px}#adminmenuwrap{position:fixed;top:0;bottom:0;background:white;border-right:1px solid #ddd;padding:28px 0}#adminmenuwrap p{padding:0 24px}#adminmenuwrap a{display:block;padding:12px 24px;color:#1f2933;text-decoration:none}@media(max-width:782px){#adminmenuwrap{display:none}}</style><style>'.$css.'</style><body class="kiwe-workspace-admin index-php"><aside id="adminmenuwrap"><p>✳ &nbsp; Kiwe Workspace</p><a href="#">Home</a><a href="#">Posts</a><a href="#">Media</a><a href="#">Pages</a></aside><div id="wpcontent"><div id="wpbody-content">'.render_home($workspace).'</div></div></body></html>');
}
