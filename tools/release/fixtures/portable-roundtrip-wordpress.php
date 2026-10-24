<?php
declare(strict_types=1);
/** Native child for a fresh, nonserved WordPress/Bricks qualification site. */
if(PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows')exit(2);
$packet=json_decode((string)fgets(STDIN,65537),true);
if(!is_array($packet)||!preg_match('#^/tmp/kiwe-roundtrip-qa-[a-f0-9]{24}/(?:source|destination)/public$#D',$packet['root']??'')||realpath($packet['root'])!==$packet['root'])exit(2);
define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_INSTALLING',$packet['action']==='seed');define('WP_USE_THEMES',false);
if($packet['action']==='acceptance'){
 define('KIWE_WORKSPACE_TEST_SITE',true);define('KIWE_REACT_WORKSPACE',true);define('KIWE_WORKSPACE_EXPECTED_VERSION',$packet['expectedVersion']);
 $GLOBALS['kiwe_media_tables_isolated']=true;$GLOBALS['kiwe_service_history_tables_isolated']=true;
 $GLOBALS['kiwe_media_mail']=[];$GLOBALS['kiwe_media_http']=[];$GLOBALS['kiwe_service_history_mail']=[];$GLOBALS['kiwe_service_history_http']=[];
}
$fixtureHost=str_contains($packet['root'],'/source/')?'source.qualification.invalid':'destination.qualification.invalid';
$_SERVER['DOCUMENT_ROOT']=$packet['root'];
$_SERVER['HTTP_HOST']=$fixtureHost;$_SERVER['SERVER_NAME']=$fixtureHost;$_SERVER['HTTPS']='on';$_SERVER['SERVER_PORT']='443';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='127.0.0.1';
$proof=['schema'=>'kiwe.native-nonwoo-roundtrip-child.v1','passed'=>false,'checks'=>[]];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
ob_start();
try{
 define('ABSPATH',$packet['root'].'/');require_once ABSPATH.'wp-includes/plugin.php';
 add_filter('pre_wp_mail',static fn()=>false,PHP_INT_MAX);add_filter('pre_http_request',static fn()=>new WP_Error('qualification_blocked','Qualification does not send HTTP'),PHP_INT_MAX);add_filter('pre_schedule_event',static fn($pre,$event)=>$packet['action']==='acceptance'&&$event->hook==='bricks_regenerate_css_files'?null:false,PHP_INT_MAX,2);
 if($packet['action']==='acceptance'&&$packet['fixture']==='test-wordpress-kiwe-configuration-snapshots.php')add_action('muplugins_loaded',static function(){global$wpdb;$table=$wpdb->prefix.'kiwechoices266_'.bin2hex(random_bytes(6)).'_options';if($wpdb->query('CREATE TABLE `'.$table.'` LIKE `'.$wpdb->options.'`')===false||$wpdb->query('INSERT INTO `'.$table.'` SELECT * FROM `'.$wpdb->options.'`')===false)throw new RuntimeException('Owned configuration options replica refused');$wpdb->options=$table;$GLOBALS['wp_object_cache']=new WP_Object_Cache();},PHP_INT_MAX);
 require $packet['root'].'/wp-load.php';
 register_shutdown_function(static function()use(&$proof){
  $last=error_get_last();if($last&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)){
   $proof['passed']=false;$proof['nativeShutdownError']=['type'=>$last['type'],'file'=>basename($last['file']),'line'=>$last['line'],'messageSha256'=>hash('sha256',$last['message'])];
  }
  echo json_encode($proof,JSON_UNESCAPED_SLASHES);
 });
 add_filter('pre_wp_mail',static fn()=>false);add_filter('pre_http_request',static fn()=>new WP_Error('qualification_blocked','Qualification does not send HTTP'),PHP_INT_MAX);
 if($packet['action']!=='seed')$check(class_exists('Bricks\\Frontend'),'installed active Bricks theme loads in the native process');
 if(in_array($packet['action'],['seed','populate'],true)){
  if($packet['action']==='seed'){
   require_once ABSPATH.'wp-admin/includes/upgrade.php';
   wp_install('Portable non-Woo qualification',$packet['login'],$packet['email'],true,'',$packet['password']);
  }
  $owner=(int)get_user_by('login',$packet['login'])->ID;wp_set_current_user($owner);
  update_option('kiwe_access_policy_v1',['enabled'=>false,'ownerId'=>$owner]);
  update_option('blog_public','0');update_option('users_can_register','0');update_option('cron',[]);update_option('active_plugins',[]);
  switch_theme('bricks');
  if($packet['source']&&$packet['action']==='populate'){
   $author=wp_insert_user(['user_login'=>'qualification_author','user_pass'=>bin2hex(random_bytes(24)),'user_email'=>'author@qualification.invalid','role'=>'author','display_name'=>'Qualification Author']);
   if(is_wp_error($author))throw new RuntimeException('Native author creation failed');
   $category=wp_insert_term('Qualification Blog','category');$tag=wp_insert_term('Qualification Topic','post_tag');
   if(is_wp_error($category)||is_wp_error($tag))throw new RuntimeException('Native taxonomy creation failed');
   $post=wp_insert_post(['post_title'=>'Portable qualification article','post_content'=>'<p><strong>Native article</strong> with <em>formatting</em>.</p>','post_author'=>$author,'post_status'=>'publish','post_type'=>'post'],true);
   $page=wp_insert_post(['post_title'=>'Portable qualification home','post_content'=>'Portable home fallback','post_author'=>$owner,'post_status'=>'publish','post_type'=>'page'],true);
   if(is_wp_error($post)||is_wp_error($page))throw new RuntimeException('Native content creation failed');
   wp_set_post_terms($post,[(int)$category['term_id']],'category');wp_set_post_terms($post,[(int)$tag['term_id']],'post_tag');
   require_once ABSPATH.'wp-admin/includes/post.php';wp_set_current_user($author);
   $revision=_wp_put_post_revision($post);
   $autosave=wp_create_post_autosave(['post_ID'=>$post,'post_title'=>'Unsaved qualification article','content'=>'<p>Unsaved native writing</p>','excerpt'=>'Unsaved native excerpt']);
   $proof['historyCreation']=['saved'=>is_wp_error($revision)?$revision->get_error_code():$revision,'autosave'=>is_wp_error($autosave)?$autosave->get_error_code():$autosave];
   if(is_wp_error($revision)||!$revision||is_wp_error($autosave)||!$autosave)throw new RuntimeException('Native editorial history creation failed');
   wp_set_current_user($owner);
   $settings=(new DSA\Settings())->defaults();$settings['phonekey']['enabled']=true;$settings['secure']['enabled']=true;update_option('dsa_settings',$settings,false);
   update_option('pk_settings_v3',['popup_display'=>'logged_out','identifier_mode'=>'email_or_phone','return_message'=>'Portable welcome','return_seconds'=>12,'otp_target_limit'=>7,'whatsapp_secret'=>'synthetic-held-phone-secret','protect_wp_admin'=>1,'role_matrix'=>['administrator'=>['step_up'=>'totp_required']]]);
   update_option('stp_settings',['red_threshold'=>72,'yellow_threshold'=>28,'track_admin_activity'=>1,'alert_hourly_limit'=>9,'v2_ai_provider'=>'gemini','v2_ai_key_enc'=>'synthetic-held-security-secret','break_glass_slug'=>str_repeat('copied-security-route-',2)]);
   $uploads=wp_upload_dir(null,false,true);if(!is_dir($uploads['basedir']))wp_mkdir_p($uploads['basedir']);
   $proof['sourceMedia']=['httpsRequest'=>is_ssl(),'uploadMatchesSiteUrl'=>$uploads['baseurl']===site_url('/wp-content/uploads'),'uploadHasExpectedOrigin'=>str_starts_with($uploads['baseurl'],'https://source.qualification.invalid/'),'contentHasExpectedOrigin'=>str_starts_with(WP_CONTENT_URL,'https://source.qualification.invalid/')];
   $check($proof['sourceMedia']['uploadMatchesSiteUrl']&&$proof['sourceMedia']['uploadHasExpectedOrigin'],'source native media uses the installed fixture origin after a fresh bootstrap');
   $image=$uploads['basedir'].'/qualification.png';file_put_contents($image,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
   $attachment=wp_insert_attachment(['post_title'=>'Qualification image','post_mime_type'=>'image/png','post_status'=>'inherit'],$image,$page,true);
   if(is_wp_error($attachment))throw new RuntimeException('Native attachment creation failed');
   wp_update_attachment_metadata($attachment,['width'=>1,'height'=>1,'file'=>'qualification.png','sizes'=>[]]);set_post_thumbnail($post,$attachment);
   $media=fopen($uploads['basedir'].'/qualification-large.bin','x+b');$chunk=str_repeat('Native portable media ',32768);for($i=0;$i<24;++$i)fwrite($media,$chunk);fflush($media);fsync($media);fclose($media);
   $elements=[['id'=>'native-heading','name'=>'heading','parent'=>0,'children'=>[],'settings'=>['text'=>'Portable Bricks heading','tag'=>'h1']],['id'=>'native-text','name'=>'text-basic','parent'=>0,'children'=>[],'settings'=>['text'=>'Portable Bricks body']],['id'=>'native-image','name'=>'image','parent'=>0,'children'=>[],'settings'=>['image'=>['id'=>$attachment,'url'=>$uploads['baseurl'].'/qualification.png']]]];
   update_post_meta($page,'_bricks_page_content_2',$elements);update_post_meta($page,'_bricks_editor_mode','bricks');
   $templates=[];foreach(['header','footer','section']as$type){$id=wp_insert_post(['post_title'=>'Qualification '.$type,'post_type'=>'bricks_template','post_status'=>'publish','post_author'=>$owner]);update_post_meta($id,'_bricks_template_type',$type);update_post_meta($id,'_bricks_page_content_2',[['id'=>'qualification-'.$type,'name'=>'text-basic','parent'=>0,'children'=>[],'settings'=>['text'=>'Portable '.$type.' template']]]);$templates[$type]=$id;}
   update_option('show_on_front','page');update_option('page_on_front',$page);
   update_option('bricks_global_classes',[['id'=>'qualification-class','name'=>'qualification-accent','settings'=>['_typography'=>['color'=>['hex'=>'#173d30']]]]]);
   update_option('bricks_settings',['remoteTemplatesPassword'=>'synthetic-held-secret','customBreakpoints'=>[]]);
   $proof['entities']=['owner'=>$owner,'author'=>(int)$author,'post'=>(int)$post,'page'=>(int)$page,'attachment'=>(int)$attachment,'category'=>(int)$category['term_id'],'tag'=>(int)$tag['term_id'],'templates'=>$templates,'revision'=>(int)$revision,'autosave'=>(int)$autosave];
  }else $proof['owner']=$owner;
  $check($owner>0&&current_user_can('manage_options'),'prepared native owner has administrator authority');
  $check(!class_exists('WooCommerce')&&get_option('active_plugins')===[],'fresh site has no Woo or active regular plugins');
  $check(wp_get_theme('bricks')->exists(),'real Bricks theme is present');
 }elseif($packet['action']==='capture'){
  global $wpdb;
  $plan=DSA\Backup\Portable_File_Inventory::scan(rtrim(ABSPATH,'/'),50000,8589934592,null,'posix',true);
  $source=DSA\Backup\Portable_Source_Compatibility::inspect(rtrim(ABSPATH,'/'),$plan);
  $db=fopen($packet['directory'].'/database.kiwe','x+b');$files=fopen($packet['directory'].'/files.kiwe','x+b');chmod($packet['directory'].'/database.kiwe',0600);chmod($packet['directory'].'/files.kiwe',0600);
  $observed=DSA\Backup\Portable_Source_Observation::capture(rtrim(ABSPATH,'/'),$plan,$wpdb,$db,$files,$packet['phrase']);
  rewind($db);rewind($files);$out=fopen($packet['directory'].'/recovery.kiwebackup','x+b');chmod($packet['directory'].'/recovery.kiwebackup',0600);
  $set=DSA\Backup\Portable_Recovery_Set::create($db,$files,$source,$out,$packet['phrase']);fflush($out);fsync($out);fclose($out);fclose($db);fclose($files);
  $observed['recoveryArchiveId']=$set['archiveId'];$observed['recoverySha256']=$set['sha256'];
  file_put_contents($packet['directory'].'/observation.kiwesource',DSA\Backup\Portable_Source_Observation::seal($observed,$packet['phrase']));chmod($packet['directory'].'/observation.kiwesource',0600);
  $in=fopen($packet['directory'].'/recovery.kiwebackup','rb');$verified=DSA\Backup\Portable_Recovery_Set::verify($in,$packet['phrase']);fclose($in);
  $check($verified['archiveId']===$set['archiveId']&&hash_file('sha256',$packet['directory'].'/recovery.kiwebackup')===$set['sha256'],'saved native complete set authenticates');
  $check($observed['observedStable']===true&&$observed['database']['transactionEnded']===true,'files and native read view complete without source drift');
  $check(!class_exists('WooCommerce')&&$source['dependencies']['activePlugins']===[],'captured source is native non-Woo');
  $proof['capture']=['archiveId'=>$set['archiveId'],'sha256'=>$set['sha256'],'bytes'=>filesize($packet['directory'].'/recovery.kiwebackup'),'observationSha256'=>hash_file('sha256',$packet['directory'].'/observation.kiwesource'),'observationBytes'=>filesize($packet['directory'].'/observation.kiwesource'),'files'=>count($plan['files']),'fileBytes'=>array_sum(array_column($plan['files'],'bytes')),'tables'=>$observed['database']['tables'],'wordpress'=>$source['platform']['wordpress'],'bricks'=>wp_get_theme('bricks')->get('Version')];
 }elseif($packet['action']==='review'){
  $e=$packet['entities'];wp_set_current_user($packet['owner']);
  $check(current_user_can('manage_options')&&wp_check_password($packet['password'],wp_get_current_user()->user_pass,$packet['owner']),'destination native owner can authenticate after recovery');
  $check(get_post($e['post'])->post_title==='Portable qualification article'&&(int)get_post($e['post'])->post_author===$e['author'],'native post and source author ID survive recovery');
  $check(get_post($e['page'])->post_title==='Portable qualification home'&&(int)get_option('page_on_front')===$e['page'],'page and native front-page relationship survive');
  $check(wp_get_post_terms($e['post'],'category',['fields'=>'ids'])===[$e['category']]&&wp_get_post_terms($e['post'],'post_tag',['fields'=>'ids'])===[$e['tag']],'category and tag relationships survive native reads');
  $check(get_post_thumbnail_id($e['post'])===$e['attachment']&&is_file(get_attached_file($e['attachment'])),'attachment ID relationship and actual media file survive');
  $check(str_starts_with(wp_get_attachment_url($e['attachment']),$packet['destinationUrl']),'native attachment URL uses the destination origin');
  $elements=get_post_meta($e['page'],'_bricks_page_content_2',true);
  $check($elements[2]['settings']['image']['id']===$e['attachment']&&str_starts_with($elements[2]['settings']['image']['url'],$packet['destinationUrl']),'Bricks serialized image IDs and URLs survive migration');
  foreach($e['templates']as$type=>$id)$check(get_post($id)->post_type==='bricks_template'&&get_post_meta($id,'_bricks_template_type',true)===$type,'native Bricks '.$type.' template survives');
  $check(get_option('bricks_global_classes')[0]['id']==='qualification-class','native Bricks global presentation survives');
  $check(!array_key_exists('remoteTemplatesPassword',get_option('bricks_settings')),'copied Bricks remote credential remains quarantined');
  $check(!class_exists('WooCommerce')&&get_option('active_plugins')===[],'recovered bootstrap remains non-Woo');
  $copied=get_userdata($e['author']);$check($copied->roles===['subscriber']&&get_user_meta($e['author'],'_kiwe_account_suspended',true)==='1','copied author cannot inherit source access');
  $check(defined('DSA_VERSION')&&DSA_VERSION===$packet['expectedVersion'],'current captured Kiwe bootstraps at the recovered root');
  global $post;$post=get_post($e['page']);setup_postdata($post);Bricks\Database::set_page_data($e['page']);
  $html=Bricks\Frontend::render_data($elements);
  $check(is_string($html)&&str_contains($html,'Portable Bricks heading')&&str_contains($html,'Portable Bricks body'),'actual Bricks frontend renderer produces recovered authored content');
  $proof['renderedBytes']=strlen($html);
  $saved=get_post($e['revision']);$unsaved=wp_get_post_autosave($e['post'],$e['author']);
  $check($saved&&wp_is_post_revision($saved)===$e['post']&&$saved->post_content==='<p><strong>Native article</strong> with <em>formatting</em>.</p>','saved native revision retains original writing and parent after recovery');
  $check($unsaved&&(int)$unsaved->ID===$e['autosave']&&$unsaved->post_title==='Unsaved qualification article'&&$unsaved->post_excerpt==='Unsaved native excerpt'&&(int)$unsaved->post_author===$e['author'],'unsaved native autosave retains writing author and parent after recovery');
  $check(get_post($e['post'])->post_title==='Portable qualification article','recovered autosave does not silently overwrite saved publication');
  $phone=pk_settings();$security=stp_cfg(true);
  $check($phone['return_message']==='Portable welcome'&&$phone['return_seconds']===12&&$phone['otp_target_limit']===7&&$phone['protect_wp_admin']===0&&$phone['whatsapp_secret']===''&&$phone['trusted_devices']===0,'native PhoneKey getter reads recovered choices without copied enrollment trust or credentials');
  $check($security['red_threshold']===72&&$security['yellow_threshold']===28&&$security['track_admin_activity']===1&&$security['alert_hourly_limit']===9&&$security['v2_ai_provider']==='none'&&$security['v2_ai_key_enc']===''&&!str_contains($security['break_glass_slug'],'copied-security-route'),'native SecureTrack getter reads recovered reporting choices with fresh destination route and no source AI');
 }elseif($packet['action']==='acceptance'){
  $allowed=['test-wordpress-workspaces.php','test-wordpress-nonwoo-editorial.php','test-wordpress-react-apps.php','test-wordpress-media-archives.php','test-wordpress-account-profile-snapshots.php','test-wordpress-bricks-design-snapshots.php','test-wordpress-service-history.php','test-wordpress-kiwe-configuration-snapshots.php'];
  $check(in_array($packet['fixture'],$allowed,true)&&!class_exists('WooCommerce')&&get_option('active_plugins')===[],'acceptance fixture uses a fresh owned non-Woo namespace');
  $GLOBALS['kiwe_media_fixture_post_floor']=(int)$GLOBALS['wpdb']->get_var('SELECT COALESCE(MAX(ID),0)+1 FROM '.$GLOBALS['wpdb']->posts);
  wp_set_current_user($packet['owner']);$GLOBALS['kiwe_native_design_actor']=$packet['owner'];
  $fixture=(static function($path){global$wpdb;return require$path;})(dirname(__DIR__).'/'.$packet['fixture']);
  $proof['fixture']=$fixture;$check(is_array($fixture)&&($fixture['passed']??false)===true&&($fixture['cleanup']??false)===true,'current native fixture passes and retires its owned records');
 }else throw new RuntimeException('Unknown qualification action');
 // Run real native shutdown work inside the checked process boundary. A fatal callback
 // must not escape after a success packet; WordPress's later hook sees an empty action list.
 shutdown_action_hook();remove_all_actions('shutdown');$proof['shutdownCompleted']=true;
 $proof['passed']=true;
}catch(Throwable$e){
 $failed=array_keys(array_filter($proof['checks'],static fn($ok)=>$ok===false));
 $proof['error']=$failed[0]??'Native WordPress operation refused';
 $proof['nativeError']=['class'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine(),
  'trace'=>array_map(static fn($f)=>['function'=>$f['function'],'class'=>$f['class']??null,'file'=>isset($f['file'])?basename($f['file']):null,'line'=>$f['line']??null],array_slice($e->getTrace(),0,5))];
 if(function_exists('remove_all_actions'))remove_all_actions('shutdown');
}
$noise=ob_get_level()>0?ob_get_clean():'';$proof['unexpectedOutput']=$noise!=='';$proof['passed']=$proof['passed']&&!$proof['unexpectedOutput'];
exit($proof['passed']?0:1);
