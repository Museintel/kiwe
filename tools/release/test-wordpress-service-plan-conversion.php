<?php
/** Native conversion proof. The runner must isolate options/users/content before plugins initialize. */
if ( PHP_SAPI!=='cli' || !defined('ABSPATH') || !defined('KIWE_WORKSPACE_TEST_SITE') || KIWE_WORKSPACE_TEST_SITE!==true || !defined('KIWE_WORKSPACE_EXPECTED_VERSION') || empty($GLOBALS['kiwe_service_conversion_tables_isolated']) ) exit(2);
use DSA\Onboarding\Native_Service_Conversion_Service as Conversion;
use DSA\Onboarding\Native_Service_Source_Service as Source;
use DSA\Onboarding\Design_Context_Profile_Service as Profile;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Backup\Archive_Cipher as Cipher;

$proof=['schema'=>'kiwe-native-service-conversion.v1','kiwe'=>DSA_VERSION,'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'checks'=>[],'cleanup'=>false];
$GLOBALS['kiwe_service_conversion_proof'] =& $proof;
$actor=get_current_user_id(); $prefix='kiwesvc_'.bin2hex(random_bytes(6)); $users=[]; $ids=[]; $hooks=[];
$assert=static function(bool $ok,string $label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>$ok];};
$must=static function($value){if(is_wp_error($value)||!$value)throw new RuntimeException(is_wp_error($value)?$value->get_error_message():'Native fixture unavailable');return $value;};
$dispatch=static function(string $method,array $body=[],bool $nonce=true,bool $mutation=true){$r=new WP_REST_Request($method,'/dsa/v1/workspace/continuity/services');if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('X-Kiwe-Mutation','1');if($method==='GET')$r->set_query_params($body);else{$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_do_request($r);};
$sourceCount=static fn()=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s",Source::POST_TYPE));
$termCount=static fn()=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s",Source::TAXONOMY));
$resetRegistration=static function(){if(post_type_exists(Source::POST_TYPE))unregister_post_type(Source::POST_TYPE);if(taxonomy_exists(Source::TAXONOMY))unregister_taxonomy(Source::TAXONOMY);};
try {
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty(DSA\Runtime\Package_Manifest::verify(true)['valid']),'complete matching Kiwe candidate');
 $assert(!function_exists('WC')&&!post_type_exists('product'),'Woo genuinely unloaded');
 $assert(!class_exists('LiteSpeed\\Core',false),'LiteSpeed file-cache purge is excluded only inside the isolated CLI fixture');
 $assert(!post_type_exists(Source::POST_TYPE)&&!taxonomy_exists(Source::TAXONOMY),'unbound plans do not register a public Service source at initialization');
 foreach(['user_register','kiwe_notification_event','dsa_admin_notification_event','transition_post_status']as$event)remove_all_actions($event);
 update_option(Owner::OPTION,[],false);
 foreach(['owner'=>'administrator','client'=>'administrator','author'=>'author','subscriber'=>'subscriber']as$name=>$role)$users[$name]=(int)$must(wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','role'=>$role]));
 update_option(Owner::OPTION,['enabled'=>true,'ownerId'=>$users['owner'],'rankMath'=>'inline'],false); wp_set_current_user($users['client']);
 $assert(current_user_can('kiwe_manage_context')&&!current_user_can('edit_kiwe_services')&&!current_user_can('delete_kiwe_services')&&!current_user_can('manage_options'),'unbound client has context review authority without premature native or technical capabilities');
 $image=(int)$must(wp_insert_attachment(['post_title'=>$prefix.' Image','post_mime_type'=>'image/png','post_status'=>'inherit','post_author'=>$users['client'],'guid'=>home_url('/wp-content/uploads/'.$prefix.'.png')],'',0,true)); $ids[]=$image;
 update_post_meta($image,'_wp_attached_file',$prefix.'.png');update_post_meta($image,'_wp_attachment_metadata',['width'=>64,'height'=>64,'file'=>$prefix.'.png','sizes'=>[]]);
 $foreign=(int)$must(wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>$prefix.' Unrelated','post_author'=>$users['author'],'post_content'=>'Keep this writing.'],true));$ids[]=$foreign;
 $foreignBefore=serialize([get_post($foreign),get_post_meta($foreign)]);$imageBefore=serialize([get_post($image),get_post_meta($image)]);
 $plan=['stableId'=>'plan-advice','recordId'=>0,'title'=>$prefix.' Advice','summary'=>"A quote: \"hi\"\nA slash: \\",'description'=>'<p><strong>Native Service</strong> details.</p>','status'=>'publish','imageId'=>$image,'parentId'=>0,'menuOrder'=>7,'categoryPaths'=>"{$prefix} Advice > Design\n{$prefix} Reviews",'taxonomyPaths'=>[],'meta'=>[]];
 $second=array_replace($plan,['stableId'=>'plan-audit','title'=>$prefix.' Audit','summary'=>'Independent review','status'=>'pending','imageId'=>0,'menuOrder'=>2,'categoryPaths'=>$prefix.' Advice > Design']);
 $original=['identity'=>['untouched'=>'original context'],'brand'=>['notes'=>'Keep the client brand.'],'services'=>['enabled'=>true,'sourcePostType'=>'','useForNavigation'=>true,'items'=>[$plan,$second]]];
 $seed=static function()use($original,$resetRegistration){$resetRegistration();delete_option(Conversion::OPTION);update_option(Profile::OPTION_PROFILE,$original,false);};$seed();
 $preview=$dispatch('GET',['operation'=>'service_conversion']);$review=$preview->get_data();$expected=$review['expected']??'';
 $assert($preview->get_status()===200&&$review['count']===2&&$review['items'][0]['status']==='draft'&&$review['items'][0]['originalStatus']==='publish'&&$review['items'][0]['imageId']===$image,'native preview maps published and pending plans to drafts with existing images');
 $assert(get_option(Profile::OPTION_PROFILE)===$original&&$sourceCount()===0&&$termCount()===0&&!post_type_exists(Source::POST_TYPE)&&!get_option(Conversion::OPTION,false),'preview leaves native content terms source and settings unchanged');
 $body=['operation'=>'convert_service_plans','expected'=>$expected];
 $assert($dispatch('POST',$body,false)->get_status()===403&&$dispatch('POST',$body,true,false)->get_status()===403,'native conversion requires nonce and mutation intent');
 $assert($dispatch('POST',$body+['source'=>'external_service'])->get_status()===400&&$sourceCount()===0,'request cannot supply a different native source or extra migration fields');
 wp_set_current_user($users['author']);$assert($dispatch('GET',['operation'=>'service_conversion'])->get_status()===403&&$dispatch('POST',$body)->get_status()===403,'ordinary Author cannot review or convert website Service plans');wp_set_current_user($users['client']);
 $changed=$original;$changed['services']['items'][0]['summary']='Concurrent plan edit';update_option(Profile::OPTION_PROFILE,$changed,false);
 $assert($dispatch('POST',$body)->get_status()===409&&$sourceCount()===0&&get_option(Profile::OPTION_PROFILE)===$changed,'stale conversion refuses concurrent plan changes without losing that edit');$seed();
 update_post_meta($image,'_wp_attached_file',$prefix.'-changed.png');
 $assert($dispatch('POST',$body)->get_status()===409&&$sourceCount()===0,'a native planned-image change also invalidates the open conversion review');update_post_meta($image,'_wp_attached_file',$prefix.'.png');
 register_post_type('service',['public'=>true,'show_ui'=>true,'rewrite'=>false]);
 $assert($dispatch('GET',['operation'=>'service_conversion'])->get_status()===409&&get_option(Profile::OPTION_PROFILE)===$original,'an existing external Service source cannot be replaced');unregister_post_type('service');
 $reserved=(int)$must(wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>$prefix.' Reserved route','post_name'=>'service','post_author'=>$users['owner']],true));$ids[]=$reserved;
 $assert($dispatch('GET',['operation'=>'service_conversion'])->get_status()===409&&$sourceCount()===0,'a native Page using the reserved Service route blocks conversion');wp_delete_post($reserved,true);
 $wpdb->query('START TRANSACTION');$nested=$dispatch('POST',$body);$nestedActive=$wpdb->get_var('SELECT @@session.in_transaction');$wpdb->query('ROLLBACK');
 $assert($nested->get_status()===409&&$nestedActive==='1'&&$sourceCount()===0&&get_option(Profile::OPTION_PROFILE)===$original,'an existing native transaction is refused without committing or rolling back its owner');
 $competing=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$conversionLock='kiwe-service-conversion-'.substr(hash('sha256',$wpdb->prefix),0,32);
 try{$must($competing->get_var($competing->prepare('SELECT GET_LOCK(%s,0)',$conversionLock))==='1');$busy=$dispatch('POST',$body);$assert($busy->get_status()===409&&$sourceCount()===0&&get_option(Profile::OPTION_PROFILE)===$original,'a competing native connection cannot run two Service conversions');}finally{$competing->get_var($competing->prepare('SELECT RELEASE_LOCK(%s)',$conversionLock));$competing->close();}
 $must($wpdb->query("ALTER TABLE {$wpdb->postmeta} ENGINE=MyISAM")!==false);$unsupported=$dispatch('POST',$body);$must($wpdb->query("ALTER TABLE {$wpdb->postmeta} ENGINE=InnoDB")!==false);
 $assert($unsupported->get_status()===409&&$sourceCount()===0&&get_option(Profile::OPTION_PROFILE)===$original,'a nontransactional native metadata table refuses conversion without source changes');
 $denyNative=static function($caps){if(post_type_exists(Source::POST_TYPE))$caps['edit_kiwe_services']=false;return $caps;};add_filter('user_has_cap',$denyNative,PHP_INT_MAX,4);
 try{$deniedNative=$dispatch('POST',$body);}finally{remove_filter('user_has_cap',$denyNative,PHP_INT_MAX);}
 $assert($deniedNative->get_status()===409&&$sourceCount()===0&&get_option(Profile::OPTION_PROFILE)===$original&&!post_type_exists(Source::POST_TYPE),'native authority is rechecked after activation and its denial rolls back the source before records exist');
 // A native hook failure after an insert must roll back the entire migration and cached identities.
 $inserted=[];$late=static function($id,$post)use(&$inserted){if($post->post_type===Source::POST_TYPE){$inserted[]=(int)$id;if(count($inserted)===2)throw new RuntimeException('Synthetic late Service write failure');}};
 add_action('wp_after_insert_post',$late,PHP_INT_MAX,2);$failed=$dispatch('POST',$body);remove_action('wp_after_insert_post',$late,PHP_INT_MAX);
 $assert($failed->get_status()===409&&$sourceCount()===0&&$termCount()===0&&get_option(Profile::OPTION_PROFILE)===$original&&!get_option(Conversion::OPTION,false),'late native insert failure rolls back all drafts categories Profile and recovery');
 $assert(count($inserted)===2&&!get_post($inserted[0])&&!get_post($inserted[1])&&!post_type_exists(Source::POST_TYPE)&&!taxonomy_exists(Source::TAXONOMY),'failed native IDs and their registration do not survive in WordPress caches');
 // A final extension edit after the recovery option is written cannot become a reported success.
 $mutate=static function($key)use($sourceCount){if($key===Conversion::OPTION){global $wpdb;$id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s ORDER BY ID LIMIT 1",Source::POST_TYPE));wp_update_post(['ID'=>$id,'post_title'=>'Synthetic final hook edit']);}};
 add_action('added_option',$mutate,PHP_INT_MAX,1);$failed=$dispatch('POST',$body);remove_action('added_option',$mutate,PHP_INT_MAX);
 $assert($failed->get_status()===409&&$sourceCount()===0&&$termCount()===0&&get_option(Profile::OPTION_PROFILE)===$original&&!get_option(Conversion::OPTION,false),'late recovery-write hook mutation is detected and rolled back before commit');
 $observed=[];$observe=static function($id,$terms,$ttids,$taxonomy)use(&$observed){if($taxonomy===Source::TAXONOMY){$post=get_post($id);$observed[]=['title'=>$post->post_title,'summary'=>$post->post_excerpt,'description'=>$post->post_content,'imageId'=>(int)get_post_thumbnail_id($id),'paths'=>(new Profile())->service_catalog(['id'=>$id])['items'][0]['taxonomyPaths']??[]];}};add_action('set_object_terms',$observe,PHP_INT_MAX,4);
 try{$converted=$dispatch('POST',$body);}finally{remove_action('set_object_terms',$observe,PHP_INT_MAX);}$result=$converted->get_data();
 if($converted->get_status()!==200){
  $proof['conversionDiagnostic']=['status'=>$converted->get_status(),'response'=>$result,'observed'=>$observed];
  $resetRegistration();(new Source())->register_types();$diagnosticProfile=$original;$diagnosticProfile['services']=['enabled'=>true,'sourcePostType'=>Source::POST_TYPE,'items'=>[]];update_option(Profile::OPTION_PROFILE,$diagnosticProfile,false);
  try{$first=Conversion::prepare_plans($original['services'])[0]['plan'];$proof['conversionDiagnostic']['directWriter']=(new Profile())->save_service_record(Source::POST_TYPE,$first,$users['client']);}
  catch(Throwable $error){$proof['conversionDiagnostic']['nativeError']=['class'=>get_class($error),'message'=>$error->getMessage(),'trace'=>array_map(static fn($row)=>['file'=>basename($row['file']??''),'line'=>$row['line']??0,'function'=>$row['function']??''],array_slice($error->getTrace(),0,12))];}
  throw new RuntimeException('Native Service conversion diagnostic captured');
 }
 $recovery=get_option(Conversion::OPTION,false);$nativeIds=$recovery['ids']??[];
 $assert($converted->get_status()===200&&($result['changed']??false)&&count($nativeIds)===2&&$sourceCount()===2&&$termCount()===3,'reviewed native conversion creates exactly its two Service drafts and shared category hierarchy');
 $assert(current_user_can('edit_kiwe_services')&&current_user_can('delete_kiwe_services')&&current_user_can('manage_kiwe_service_category')&&!current_user_can('manage_options'),'selected native source projects narrow client record and taxonomy authority');
 wp_set_current_user($users['owner']);$assert(current_user_can('edit_kiwe_services')&&current_user_can('delete_kiwe_services')&&current_user_can('manage_kiwe_service_category'),'site owner shares the configured native Service capability projection');wp_set_current_user($users['client']);
 $current=get_option(Profile::OPTION_PROFILE);$assert($current['services']['sourcePostType']===Source::POST_TYPE&&$current['services']['items']===[]&&$current['brand']===$original['brand']&&$current['identity']===$original['identity']&&$current['services']['useForNavigation']===true,'native source replaces only its plan section without a copied current-record inventory');
 $assert(get_post_status($nativeIds[0])==='draft'&&get_post_status($nativeIds[1])==='draft'&&get_post($nativeIds[0])->post_excerpt===$plan['summary']&&get_post($nativeIds[0])->post_content===$plan['description']&&(int)get_post_thumbnail_id($nativeIds[0])===$image&&(int)get_post($nativeIds[0])->menu_order===7,'native saved drafts preserve formatting quotes newlines image reference and order');
 $assert(is_array($recovery)&&!str_contains($recovery['before'],$prefix.' Advice')&&Cipher::open($recovery['before'],Conversion::SCHEMA,wp_salt('auth'),1048576)['services']===$original['services'],'shared authenticated encryption retains the exact original plans in private non-autoloaded recovery');
 $validation=new ReflectionMethod(DSA\Backup\Configuration_Snapshot_Service::class,'validate_references');$backupRefused=false;
 try{$validation->invoke(null,[Profile::OPTION_PROFILE=>['exists'=>true,'value'=>$original]]);}catch(Throwable $error){$backupRefused=true;}
 $assert($backupRefused&&$sourceCount()===2&&get_option(Conversion::OPTION)===$recovery,'native settings-only restore refuses to strand a protected converted Service collection');
 $validation->invoke(null,[Profile::OPTION_PROFILE=>['exists'=>true,'value'=>$current]]);$assert(true,'native unchanged Service settings remain compatible with settings recovery');
 $assert($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",Conversion::OPTION))==='off','recovery is not autoloaded on normal website requests');
 $assert($dispatch('POST',$body)->get_status()===409&&$sourceCount()===2,'replayed conversion cannot duplicate native records');
 $all=$dispatch('GET',['mode'=>'records','status'=>'all','sort'=>'title_asc'])->get_data();$published=$dispatch('GET',['mode'=>'records','status'=>'publish'])->get_data();
 $assert(!empty($all['recordMode'])&&count($all['values']['services.items'])===2&&$all['values']['services.items'][0]['title']===$plan['title']&&count($published['values']['services.items'])===0,'native Services sorting and status filters reach their real query');
 $assert(!empty($all['service']['conversionRecovery']['available'])&&$all['recordUrls']===[],'fresh native drafts expose guarded undo without published frontend links');
 $undo=$dispatch('GET',['operation'=>'service_conversion_undo']);$undoReview=$undo->get_data();$undoBody=['operation'=>'undo_service_conversion','expected'=>$undoReview['expected']??''];
 $assert($undo->get_status()===200&&$undoReview['count']===2&&$undoReview['items'][0]['status']==='publish','native recovery review shows the retained original plan visibility');
 // Unrelated Design Context changes survive this Service-only undo.
 $later=$current;$later['brand']['notes']='Later client brand edit';update_option(Profile::OPTION_PROFILE,$later,false);
 $delayed=static function($id,$post){if($post->post_type===Source::POST_TYPE)throw new RuntimeException('Synthetic late delete failure');};
 add_action('deleted_post',$delayed,PHP_INT_MAX,2);$failed=$dispatch('POST',$undoBody);remove_action('deleted_post',$delayed,PHP_INT_MAX);
 $assert($failed->get_status()===409&&$sourceCount()===2&&$termCount()===3&&get_option(Conversion::OPTION)===$recovery&&get_option(Profile::OPTION_PROFILE)===$later,'late undo delete failure rolls back native drafts categories source and recovery');
 $assert(get_post($nativeIds[0])&&get_post($nativeIds[1])&&post_type_exists(Source::POST_TYPE)&&(new Conversion())->undo_summary()['available'],'failed undo cleans caches and still permits a fresh complete recovery');
 $undone=$dispatch('POST',$undoBody);$restored=get_option(Profile::OPTION_PROFILE);
 $assert($undone->get_status()===200&&($undone->get_data()['mode']??'')==='undo'&&$restored['services']===$original['services']&&$restored['brand']['notes']==='Later client brand edit','guarded undo recovers exact original plans while preserving unrelated later context');
 $assert($sourceCount()===0&&$termCount()===0&&!get_option(Conversion::OPTION,false)&&!post_type_exists(Source::POST_TYPE)&&!taxonomy_exists(Source::TAXONOMY),'successful undo removes only its native drafts revisions categories and recovery state');
 $assert($dispatch('POST',$undoBody)->get_status()===403&&$sourceCount()===0&&!current_user_can('delete_kiwe_services'),'replayed undo has no native deletion authority and cannot delete anything else');
 // Maximum supported plan count stays bounded on actual WordPress and can be undone.
 $seed();$large=$original;$large['services']['items']=[];for($i=0;$i<50;$i++)$large['services']['items'][]=array_replace($second,['stableId'=>'plan-bound-'.$i,'title'=>$prefix.' Bounded '.str_pad((string)$i,2,'0',STR_PAD_LEFT),'categoryPaths'=>'','menuOrder'=>$i]);update_option(Profile::OPTION_PROFILE,$large,false);
 $largeReview=$dispatch('GET',['operation'=>'service_conversion'])->get_data();$started=microtime(true);$largeSave=$dispatch('POST',['operation'=>'convert_service_plans','expected'=>$largeReview['expected']??'']);$proof['largestConversionSeconds']=round(microtime(true)-$started,3);
 $assert($largeSave->get_status()===200&&$sourceCount()===50&&$proof['largestConversionSeconds']<12,'all 50 supported plans convert within the current native request budget');
 $largeUndo=$dispatch('GET',['operation'=>'service_conversion_undo'])->get_data();$largeRestore=$dispatch('POST',['operation'=>'undo_service_conversion','expected'=>$largeUndo['expected']??'']);
 $assert($largeRestore->get_status()===200&&get_option(Profile::OPTION_PROFILE)['services']===$large['services']&&$sourceCount()===0,'maximum-size native conversion recovers its complete original plan collection');
 $retireScene=static function()use($wpdb,$seed){foreach($wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s ORDER BY ID DESC",Source::POST_TYPE))as$id)wp_delete_post((int)$id,true);foreach(array_reverse(get_terms(['taxonomy'=>Source::TAXONOMY,'hide_empty'=>false,'fields'=>'ids']))as$id)wp_delete_term((int)$id,Source::TAXONOMY);$seed();};
 foreach(['category','shared_term','metadata','menu','parent','additional_service','rotated_salt']as$case){
  $seed();$casePreview=$dispatch('GET',['operation'=>'service_conversion'])->get_data();$caseSave=$dispatch('POST',['operation'=>'convert_service_plans','expected'=>$casePreview['expected']??'']);$must($caseSave->get_status()===200);$caseRecovery=get_option(Conversion::OPTION);$caseIds=$caseRecovery['ids'];$caseUndo=$dispatch('GET',['operation'=>'service_conversion_undo'])->get_data();$menu=0;$dependent=0;$salt=null;
  if($case==='category'){$t=get_terms(['taxonomy'=>Source::TAXONOMY,'hide_empty'=>false,'number'=>1])[0];wp_update_term($t->term_id,Source::TAXONOMY,['name'=>$prefix.' Renamed category']);}
  if($case==='shared_term'){$t=get_terms(['taxonomy'=>Source::TAXONOMY,'hide_empty'=>false,'number'=>1])[0];wp_set_object_terms($foreign,[(int)$t->term_id],Source::TAXONOMY);}
  if($case==='metadata')update_post_meta($caseIds[0],'_kiwe_seo_title','Later native SEO');
  if($case==='menu'){$menu=(int)$must(wp_create_nav_menu($prefix.' Foreign menu'));$must(wp_update_nav_menu_item($menu,0,['menu-item-object'=>Source::POST_TYPE,'menu-item-object-id'=>$caseIds[0],'menu-item-type'=>'post_type','menu-item-status'=>'publish']));}
  if($case==='parent')$dependent=(int)$must(wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>$prefix.' Foreign child','post_parent'=>$caseIds[0],'post_author'=>$users['owner']],true));
  if($case==='additional_service')$must(wp_insert_post(['post_type'=>Source::POST_TYPE,'post_status'=>'draft','post_title'=>$prefix.' Later Service','post_author'=>$users['client']],true));
  if($case==='rotated_salt'){$salt=static fn($value,$scheme)=>$scheme==='auth'?'synthetic-rotated-auth-salt-retains-native-data':$value;add_filter('salt',$salt,PHP_INT_MAX,2);}
  $caseAttempt=$dispatch('POST',['operation'=>'undo_service_conversion','expected'=>$caseUndo['expected']??'']);
  $assert($caseAttempt->get_status()===409&&get_post($caseIds[0])&&get_post($caseIds[1])&&get_option(Conversion::OPTION)===$caseRecovery,'native '.$case.' change blocks destructive undo and preserves conversion recovery');
  if($salt)remove_filter('salt',$salt,PHP_INT_MAX);if($menu)wp_delete_nav_menu($menu);if($dependent)wp_delete_post($dependent,true);if($case==='shared_term')wp_set_object_terms($foreign,[],Source::TAXONOMY);
  $retireScene();
 }
 // Each new native dependency or edit makes destructive undo unavailable.
 $seed();$review=$dispatch('GET',['operation'=>'service_conversion'])->get_data();$must($dispatch('POST',['operation'=>'convert_service_plans','expected'=>$review['expected']])->get_status()===200);$recovery=get_option(Conversion::OPTION);$nativeIds=$recovery['ids'];
 $keep=get_post($nativeIds[0]);$keepTitle=$keep->post_title;wp_update_post(['ID'=>$nativeIds[0],'post_title'=>$prefix.' Edited native Service'],true);
 $blockedReview=$dispatch('GET',['operation'=>'service_conversion_undo']);$blockedData=$blockedReview->get_data();
 $assert($blockedReview->get_status()===200&&($blockedData['canUndo']??true)===false&&($blockedData['expected']??'bad')===''&&($blockedData['items'][0]['description']??'')===$plan['description']&&$sourceCount()===2&&get_option(Conversion::OPTION)===$recovery,'edited native Services retain a read-only original-plan review without destructive undo');
 $assert($dispatch('POST',['operation'=>'undo_service_conversion','expected'=>$undoReview['expected']??''])->get_status()===409&&$sourceCount()===2,'an edited native Service rejects a previously accepted destructive recovery review');
 // Restoring the title does not erase native revision/modified history: undo must remain blocked.
 wp_update_post(['ID'=>$nativeIds[0],'post_title'=>$keepTitle],true);$assert((new Conversion())->undo_summary()['available']===false,'changing a name back does not make edited native history disposable');
 $assert(serialize([get_post($foreign),get_post_meta($foreign)])===$foreignBefore&&serialize([get_post($image),get_post_meta($image)])===$imageBefore,'foreign article and existing image remain byte-for-byte unchanged throughout conversion and undo');
 wp_set_current_user($users['subscriber']);$assert($dispatch('GET',['operation'=>'service_conversion_undo'])->get_status()===403&&$dispatch('POST',['operation'=>'undo_service_conversion','expected'=>str_repeat('0',64)])->get_status()===403,'Subscriber cannot read retained private plans or delete converted drafts');wp_set_current_user(0);$assert(in_array($dispatch('GET',['operation'=>'service_conversion'])->get_status(),[401,403],true),'anonymous visitor cannot see Service plans or recovery');
 $assert(empty($GLOBALS['kiwe_service_conversion_mail'])&&empty($GLOBALS['kiwe_service_conversion_http']),'native acceptance sends no mail or provider request');
} catch(Throwable $error) { $proof['error']=['message'=>$error->getMessage(),'line'=>$error->getLine()]; }
finally {
 wp_set_current_user($users['owner']??$actor); foreach($hooks as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);
 // All record/user/term tables are disposable native replicas. The runner drops only its exact owned replicas.
 delete_option(Conversion::OPTION);wp_set_current_user($actor);$proof['cleanup']=true;
}
$proof['passed']=!isset($proof['error'])&&$proof['cleanup']&&!in_array(false,array_column($proof['checks'],'passed'),true);return $proof;
