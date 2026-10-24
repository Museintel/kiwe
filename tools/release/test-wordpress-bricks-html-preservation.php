<?php
/** Real Bricks restricted-role saves. Disposable named lab only. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Site_Owner_Service as Owner;use DSA\Access\Template_Workspace_Service as Templates;
$proof=['schema'=>'kiwe.native-bricks-html-preservation.v1','version'=>DSA_VERSION,'checks'=>[],'passed'=>false,'cleanup'=>false];$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$originalTable=$wpdb->options;$originalCache=$GLOBALS['wp_object_cache'];$table=$wpdb->prefix.'kiwe_html_qa_'.bin2hex(random_bytes(4));$isolated=false;$users=[];$posts=[];$filters=[];$mail=[];$http=[];$original=[];$postRequest=$_POST;$request=$_REQUEST;
foreach([Owner::OPTION,DSA_OPTION_SETTINGS,BRICKS_DB_GLOBAL_SETTINGS,'active_plugins','template','stylesheet']as$key)$original[$key]=get_option($key);
$filter=static function($hook,$fn,$args=1)use(&$filters){add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
try{
 $sourceScripts=$GLOBALS['wp_scripts']??null;
 try{
  $GLOBALS['wp_scripts']=new WP_Scripts();
  DSA\Bricks\Source_Runtime_Bridge::observe('<p>Plain native content</p>');
  $assert(!wp_script_is('kiwe-source-runtime','enqueued'),'plain native content has no bridge request');
  $registration='<script data-brx-source-runtime-bundle="source-unit" data-seam-runtime="1">/* approved compiler registration */</script>';
  DSA\Bricks\Source_Runtime_Bridge::observe($registration);DSA\Bricks\Source_Runtime_Bridge::observe($registration);
  $assert(wp_script_is('kiwe-source-runtime','enqueued'),'dependent source runtime admitted without a Dock dependency');
  ob_start();wp_scripts()->do_items(['kiwe-source-runtime'],1);$printed=ob_get_clean();
  $assert(substr_count($printed,'assets/js/source-runtime.js')===1&&str_contains($printed,DSA_VERSION),'one versioned cached native bridge');
  ob_start();wp_scripts()->do_items(['kiwe-source-runtime'],1);$repeated=ob_get_clean();$assert(!str_contains($repeated,'<script'),'native done handles do not print a second bridge');
  foreach(['&amp; figure.brxe-image'=>'& figure.brxe-image','&amp;:hover > img'=>'&:hover > img',':is(&amp;, .other)'=>':is(&, .other)','[data-x="&amp; figure"]'=>'[data-x="&amp; figure"]','.literal\\&amp; img'=>'.literal\\&amp; img','&amp;amp; img'=>'&amp;amp; img','/* &amp; */ &amp; img'=>'/* &amp; */ & img']as$input=>$expected)$assert(DSA\Bricks\Source_Runtime_Bridge::normalize_selector($input)===$expected,'native selector boundary '.$input);
 }finally{$GLOBALS['wp_scripts']=$sourceScripts;}
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&DSA\Bricks\Bricks_Integration::is_active_theme(),'matching native Bricks candidate');$assert(!wp_using_ext_object_cache(),'request-local test cache');
 $dependency=['title'=>'Native dependency boundary','type'=>'content','content'=>[['id'=>'bridge','name'=>'html','settings'=>['html'=>$registration]]],'generator'=>['runtimeDependencies'=>['sourceBridge'=>['version'=>DSA\Bricks\Source_Runtime_Bridge::VERSION,'delivery'=>'kiwe-cached','requiresKiwe'=>true]]]];
 $validator=new DSA\AI\Bricks_Conversion_Validator();
 $dependencyFinding=static function(array$artifact)use($validator):bool{return in_array('bricks_template_source_runtime_dependency',array_column($validator->validate($artifact)['findings'],'code'),true);};
 $assert(null===DSA\Bricks\Source_Runtime_Bridge::dependency_error($dependency)&&!$dependencyFinding($dependency),'installed native validator admits the matching runtime declaration');
 foreach(['missing'=>static function(&$a){unset($a['generator']);},'version'=>static function(&$a){$a['generator']['runtimeDependencies']['sourceBridge']['version']='unsupported';},'portability'=>static function(&$a){$a['generator']['runtimeDependencies']['sourceBridge']['requiresKiwe']=false;},'duplicate'=>static function(&$a){$a['content'][]=$a['content'][0];},'data-only'=>static function(&$a){$a['content'][0]['settings']['html']=str_replace('<script ','<script type="application/json" ',$a['content'][0]['settings']['html']);}]as$label=>$mutate){$invalid=$dependency;$mutate($invalid);$assert($dependencyFinding($invalid),'installed native validator rejects '.$label.' runtime dependency');}
 $legacy=$dependency;unset($legacy['generator']);$legacy['content'][0]['settings']['html']=str_replace(' data-seam-runtime="1"','',$registration);$assert(!$dependencyFinding($legacy),'installed validator retains legacy portable import admission');
 $filter('pre_wp_mail',static function($value,$args)use(&$mail){$mail[]=true;return true;},2);$filter('pre_http_request',static function($value,$args,$url)use(&$http){$http[]=true;return new WP_Error('kiwe_qa_no_outbound','Outbound blocked');},3);
 $ownerOff=static fn()=>[];$filter('pre_option_'.Owner::OPTION,$ownerOff);
 $user=wp_insert_user(['user_login'=>'kiwehtmlqa_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(40,true,true),'user_email'=>'html_'.bin2hex(random_bytes(5)).'@example.invalid','role'=>'administrator']);$assert(!is_wp_error($user),'synthetic builder account created');$user=(int)$user;$users[]=$user;
 $assert(false!==$wpdb->query("CREATE TABLE {$table} LIKE {$originalTable}"),'isolated options created');$isolated=true;$assert(false!==$wpdb->query("INSERT INTO {$table} SELECT * FROM {$originalTable}"),'original options copied');$wpdb->options=$table;$GLOBALS['wp_object_cache']=new WP_Object_Cache();remove_filter('pre_option_'.Owner::OPTION,$ownerOff,PHP_INT_MAX);$filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$actor?:1]);
 $page=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Kiwe HTML save QA','post_author'=>$user],true);$assert(!is_wp_error($page)&&$page>0,'synthetic native page created');$posts[]=(int)$page;
 $html='<script data-brx-source-runtime-bundle="qa">window.kiweQa="approved";</script><svg viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>';$seed=[['id'=>'qahtml','name'=>'html','parent'=>0,'children'=>[],'settings'=>['html'=>$html]],['id'=>'qacopy','name'=>'text-basic','parent'=>0,'children'=>[],'settings'=>['text'=>'Original copy']]];
 $seed[0]['selectors']=[['id'=>'qaselector','selector'=>'& figure.brxe-image','settings'=>['_display'=>'block']],['id'=>'qaquoted','selector'=>'& [data-title="&amp; text"]','settings'=>['_display'=>'block']]];
 $seed[0]['selectors'][]=['id'=>'qacombinator','selector'=>'&>figure.brxe-image','settings'=>['_width'=>'154']];
 $seed[0]['settings']['_cssCustom']='.qa>figure { color:red; content:"&amp; &gt;"; }';
 update_post_meta($page,BRICKS_DB_PAGE_CONTENT,wp_slash($seed));$assert(get_post_meta($page,BRICKS_DB_PAGE_CONTENT,true)===$seed,'approved native HTML fixture exact');
 if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
 $bridge=new DSA\Bricks\Bricks_Integration(new DSA\Element_Registry(),new DSA\Settings());
 foreach(['administrator',Templates::ROLE]as$role){$native=new WP_User($user);$native->set_role($role);wp_set_current_user(0);wp_set_current_user($user);(new DSA\Access\WordPress_Role_Access_Service())->enforce_bricks_boundary();
  $assert(Templates::builder_actor()&&current_user_can('edit_post',$page)&&!current_user_can('unfiltered_html')&&!Bricks\Capabilities::current_user_can_execute_code(),'native restricted '.$role.' permission boundary');
  $_POST=['action'=>'bricks_save_post','postId'=>$page,'nonce'=>wp_create_nonce('bricks-nonce-builder')];$_REQUEST=$_POST;$input=$seed;$input[1]['settings']['text']='Edited by '.$role;$checked=Bricks\Helpers::security_check_elements_before_save(wp_slash($input),$page,'content');$stripped=wp_unslash($checked);
  $assert(!str_contains($stripped[0]['settings']['html'],'<script')&&!str_contains($stripped[0]['settings']['html'],'<svg'),'native Bricks reproduces sanitation loss '.$role);
  $assert($stripped[0]['selectors'][0]['selector']==='&amp; figure.brxe-image','native Bricks reproduces encoded nesting selector '.$role);
  $assert($stripped[0]['selectors'][2]['selector']==='&amp;&gt;figure.brxe-image','native Bricks reproduces encoded nesting combinator '.$role);
  $assert(str_contains($stripped[0]['settings']['_cssCustom'],'.qa&gt;figure'),'native Bricks reproduces encoded CSS-control selector '.$role);
  update_post_meta($page,BRICKS_DB_PAGE_CONTENT,$checked);$saved=get_post_meta($page,BRICKS_DB_PAGE_CONTENT,true);$assert($saved===$input,'native save preserves approved HTML and real copy edit '.$role);
  $element=new Bricks\Element_Html($saved[0]);ob_start();$element->render();$rendered=ob_get_clean();$assert($rendered===$html,'native renderer keeps script and SVG markup '.$role);
  $new=$input;$new[]=['id'=>'qanew','name'=>'html','parent'=>0,'children'=>[],'settings'=>['html'=>'<script>window.kiweQa="injected";</script>']];$denied=wp_unslash(Bricks\Helpers::security_check_elements_before_save(wp_slash($new),$page,'content'));$projected=$bridge->preserve_authored_html($denied,BRICKS_DB_PAGE_CONTENT,'post');$assert(!str_contains($projected[2]['settings']['html'],'<script'),'new script cannot inherit approval '.$role);
  $tampered=$stripped;$tampered[0]['settings']['html']='window.kiweQa="modified";';$projected=$bridge->preserve_authored_html($tampered,BRICKS_DB_PAGE_CONTENT,'post');$assert($projected[0]['settings']['html']===$tampered[0]['settings']['html']&&!str_contains($projected[0]['settings']['html'],'<script'),'modified script cannot inherit approval '.$role);
  $_REQUEST['nonce']='invalid';$assert($bridge->preserve_authored_html($stripped,BRICKS_DB_PAGE_CONTENT,'post')===$stripped,'invalid nonce cannot restore markup '.$role);$_REQUEST=$_POST;
 }
 $native=new WP_User($user);$native->set_role('subscriber');wp_set_current_user(0);wp_set_current_user($user);$_POST['nonce']=wp_create_nonce('bricks-nonce-builder');$_REQUEST=$_POST;$assert(!Templates::builder_actor()&&!current_user_can('edit_post',$page)&&$bridge->preserve_authored_html($stripped,BRICKS_DB_PAGE_CONTENT,'post')===$stripped,'subscriber receives no authoring or code authority');$proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
finally{try{$_POST=[];$_REQUEST=[];wp_set_current_user($actor);foreach(array_reverse($posts)as$id){wp_delete_post($id,true);$assert(!get_post($id),'synthetic page retired');}$wpdb->options=$originalTable;$GLOBALS['wp_object_cache']=$originalCache;foreach($filters as[$hook,$fn])if($hook==='pre_option_'.Owner::OPTION)remove_filter($hook,$fn,PHP_INT_MAX);wp_set_current_user($actor);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id){wp_delete_user($id);$assert(!get_userdata($id),'synthetic account retired');}if($isolated)$assert(false!==$wpdb->query("DROP TABLE {$table}"),'isolated options retired');foreach($original as$key=>$value)$assert(get_option($key)===$value,'original choice retained '.$key);$proof['cleanup']=true;}catch(Throwable$e){$proof['passed']=false;$proof['cleanupError']=$e->getMessage();}foreach($filters as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);$_POST=$postRequest;$_REQUEST=$request;$proof['mailAttempts']=count($mail);$proof['httpAttempts']=count($http);}
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
