<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
$root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/';
foreach(['Access/Task_Workspace_UI','Access/Workspace_Design_Service','Design/Seam_Token_Service']as $file)require $root.'includes/'.$file.'.php';
function wp_json_encode($v){return json_encode($v);}function wp_strip_all_tags($s){return strip_tags($s);}
function esc_attr($s){return esc_html($s);}function esc_attr__($s,$d=''){return esc_attr($s);}function esc_attr_e($s,$d=''){echo esc_attr($s);}function esc_html_e($s,$d=''){echo esc_html($s);}function esc_url($s){return esc_attr($s);}
function wp_nonce_field($nonce,$name='_wpnonce'){echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($nonce).'">';}
function selected($a,$b,$echo=true){$result=$a===$b?' selected':'';if($echo)echo $result;return $result;}
function submit_button($label){echo '<p class="submit"><input type="submit" class="button button-primary" value="'.esc_attr($label).'"></p>';}
function wp_style_is($name,$status){return isset($GLOBALS['styles'][$name]);}function wp_enqueue_style($name,...$args){$GLOBALS['styles'][$name]=$args;}function wp_add_inline_style($name,$css){$GLOBALS['inline'][$name]=$css;}
function wp_enqueue_script($name,...$args){$GLOBALS['scripts'][$name]=$args;}function wp_localize_script($name,$key,$data){$GLOBALS['localized'][$key]=$data;}
use DSA\Access\Category_Workspace_Service as Categories;
use DSA\Access\Team_Workspace_Service as Team;
use DSA\Onboarding\Design_Context_Profile_Service as Context;
$category=new Categories();$team=new Team();
$expected=Categories::fingerprint($terms[10]);$terms[10]->name='Newer name';$term_write=null;
$result=$category->rename_category(10,['name'=>'Older edit','expected'=>$expected]);check($result instanceof WP_Error&&$result->code==='kiwe_category_stale'&&$term_write===null,'stale rename leaves the newer native name untouched');
check($category->rename_category(10,['name'=>'Approved name','expected'=>Categories::fingerprint($terms[10])])===true,'fresh category name reaches confirmed native readback');
$after_native_term_write=function($id){$GLOBALS['terms'][$id]->name='Concurrent filter name';};
check(is_wp_error($category->rename_category(10,['name'=>'Cannot confirm','expected'=>Categories::fingerprint($terms[10])])),'a changed native readback does not produce a saved receipt');unset($after_native_term_write);
$current=Categories::fingerprint($terms[10]);$terms[10]->description='Another editor updated details';check(is_wp_error($category->rename_category(10,['name'=>'Stale view','expected'=>$current])),'concurrent category details require review');
$valid=['team_member'=>'1','team_title'=>'Studio lead','team_order'=>'2'];$expected=Team::fingerprint(3);$usermeta[3][Context::USER_META_TEAM_TITLE]='Newer title';$writes=[];
$result=$team->save_member(3,$valid+['expected'=>$expected]);check($result instanceof WP_Error&&$result->code==='kiwe_team_stale'&&!$writes,'stale team form leaves newer title untouched');
check($team->save_member(3,$valid+['expected'=>Team::fingerprint(3)])===true,'fresh team form confirms native metadata');
$after_native_meta_write=function($id,$key){if($key===Context::USER_META_TEAM_MEMBER)$GLOBALS['usermeta'][$id][Context::USER_META_TEAM_TITLE]='Concurrent update';};
$result=$team->save_member(3,['team_member'=>'0','team_title'=>'Updated title','team_order'=>'4','expected'=>Team::fingerprint(3)]);
check($result instanceof WP_Error&&$result->code==='kiwe_team_unconfirmed','a later field write changing an earlier field is unconfirmed');unset($after_native_meta_write);
foreach([null,[],str_repeat('0',64)]as $bad){check(is_wp_error($team->save_member(3,$valid+['expected'=>$bad])),'invalid/stale team comparison denied');check(is_wp_error($category->rename_category(10,['name'=>'Old','expected'=>$bad])),'invalid/stale category comparison denied');}
$_SERVER['REQUEST_METHOD']='POST';$_POST=['category_id'=>'10','name'=>'Cached form','_wpnonce'=>'kiwe_category_rename_10'];check(denied(fn()=>$category->handle_rename()),'cached native rename without expected state must reload');
$_POST=['user_id'=>'3','kiwe_team_nonce'=>Team::ACTION.'_3']+$valid;check(denied(fn()=>$team->handle_save()),'cached native team form without expected state must reload');
$styles=[];$scripts=[];$inline=[];$localized=[];$team->assets('unrelated');check(!$styles&&!$scripts,'unrelated screen loads no native-form assets');
$denied=['kiwe_manage_team'];$team->assets('toplevel_page_kiwe-team');check(!$styles&&!$scripts,'denied actor loads no Team assets');$denied=[];
$team->assets('toplevel_page_kiwe-team');check(array_keys($scripts)===['kiwe-native-forms']&&$styles['kiwe-team-workspace'][1]===['kiwe-native-forms'],'Team loads one native feedback script and shared style dependencies');
$screen=new ReflectionProperty(Categories::class,'screen');$screen->setValue($category,'posts_page_kiwe-categories');$category->assets('posts_page_kiwe-categories');check($styles['kiwe-category-workspace'][1]===['kiwe-native-forms']&&count($scripts)===1,'Categories reuses the same optional native form assets');
if(isset($argv[1])){
 $users[3]->display_name='Asha Mehta';$users[6]->display_name='Rohan Shah';$query_users=[$users[3],$users[6]];
 $usermeta[3]=[Context::USER_META_TEAM_MEMBER=>'1',Context::USER_META_TEAM_TITLE=>'Creative director',Context::USER_META_TEAM_ORDER=>'1'];$usermeta[6]=[];
 $terms[10]->name='Studio news';$terms[20]->name='Design ideas';$terms[30]->name='Client stories';
 $posts[101]->post_title='Behind the studio';$posts[102]->post_title='A new collection';$query_posts=[$posts[101],$posts[102]];
 foreach(['categories','category','team']as $view){
  $_GET=$view==='category'?['category_id'=>'10']:[];ob_start();($view==='team'?$team:$category)->render();$html=ob_get_clean();
  check(str_contains($html,'kiwe-native-workspace')&&str_contains($html,'data-kiwe-native-form')&&str_contains($html,'data-kiwe-native-state'),'actual complete renderer includes native form feedback: '.$view);
  $css=DSA\Access\Workspace_Design_Service::stylesheet();foreach(['app-primitives','workspace-native-forms',$view==='team'?'team-workspace':'category-workspace']as $name)$css.=file_get_contents($root.'assets/css/'.$name.'.css');
  $script=file_get_contents($root.'assets/js/workspace-native-forms.js');
  file_put_contents(rtrim($argv[1],'/\\').'/'.$view.'.html','<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kiwe '.$view.' synthetic review</title><style>body{margin:0;background:#f0f2f2;color:#1f2933;font-family:system-ui}main{max-width:1240px;padding:24px;margin:auto}header.synthetic{padding:14px;background:white;font-size:13px}a{color:inherit}input,select,button{font:inherit}@media(max-width:600px){main{padding:14px}}</style><style>'.$css.'</style><header class="synthetic">Kiwe · Synthetic task review; no native WordPress session</header><main>'.$html.'</main><script>window.KIWE_NATIVE_FORMS='.json_encode($localized['KIWE_NATIVE_FORMS']).';</script><script>'.$script.'</script></html>');
 }
}
echo "$count native task form checks passed.\n";
