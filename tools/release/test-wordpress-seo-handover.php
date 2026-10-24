<?php
/** Current handover regression scope. Other STSEO-7 source/backup scopes remain separately required. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_SEO_NATIVE_TEST' ) || true !== KIWE_SEO_NATIVE_TEST ) throw new RuntimeException( 'Owned native handover fixture required.' );

function kiwe_handover_test_isolate(): void { kiwe_seo_test_isolate(); }
function kiwe_handover_test_cleanup( array &$proof ): void { kiwe_seo_test_cleanup( $proof ); }
/** Metadata key order is not a WordPress content property; values and existence are. */
function kiwe_handover_record_meta(int $id):array {$values=get_post_meta($id);ksort($values,SORT_STRING);return$values;}
function kiwe_handover_test_run(): array {
 global $wpdb;
 $proof = [ 'schema'=>'kiwe.seo-handover-native-metadata.v1', 'passed'=>false, 'cleanup'=>false, 'checks'=>[], 'scope'=>'Native metadata import/undo and per-scope ownership; not full source handover' ];
 $GLOBALS['kiwe_seo_test_partial_proof'] =& $proof;
 $check = static function( $value, string $label ) use ( &$proof ): void { $proof['checks'][$label]=(bool)$value; kiwe_seo_test_progress($proof); if(!$value)throw new RuntimeException($label); };
 $request = static function( string $operation, array $state, string $scope='post', bool $mutation=true ): WP_REST_Request {
  $r=new WP_REST_Request('POST');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('x-kiwe-mutation','1');$r->set_header('content-type','application/json');$r->set_body(wp_json_encode(['operation'=>$operation,'scope'=>$scope,'expected'=>$state['expected'],'confirmed'=>true]));return$r;
 };
 $worker = static function( $service, WP_REST_Response $response ) use ( $check ): void {
  $check($response->get_status()===200&&isset($response->get_data()['active']['id']),'Reviewed native queue action schedules a current source job');
  $job=$response->get_data()['active']['id'];wp_clear_scheduled_hook(DSA\SEO\SEO_Metadata_Migration_Service::CRON,[$job]);$service->run($job);
 };
 $second=null;
 try {
  foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url','transition_post_status','publish_post']as$hook)remove_all_actions($hook);
  delete_option(DSA\SEO\SEO_Metadata_Migration_Service::OPTION);
  $settings=get_option('dsa_settings',[]);$settings['secure']['enabled']=false;update_option('dsa_settings',$settings,false);
  $seed = static function( string $login, string $role ) use ( $wpdb ): int {
   if(!$wpdb->insert($wpdb->users,['user_login'=>$login,'user_pass'=>'!synthetic-not-a-password','user_email'=>$login.'@example.test','user_registered'=>'2026-10-07 00:00:00','display_name'=>$login]))throw new RuntimeException('Owned native user unavailable');
   $id=(int)$wpdb->insert_id;update_user_meta($id,$wpdb->prefix.'capabilities',[$role=>true]);update_user_meta($id,$wpdb->prefix.'user_level',$role==='administrator'?10:2);return$id;
  };
  $owner=$seed('handover-owner','administrator');$author=$seed('handover-author','author');wp_set_current_user($owner);
  $check(current_user_can('manage_options')&&user_can($author,'edit_posts')&&!user_can($author,'manage_options'),'Native owner and author retain real WordPress capabilities');
  $post=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Synthetic original article','post_author'=>$author],true);
  $other=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Synthetic original page','post_author'=>$owner],true);
  $term=wp_insert_term('Synthetic category','category');
  $check(!is_wp_error($post)&&!is_wp_error($other)&&!is_wp_error($term),'Native content and category creation stays in owned temporary tables');$category=(int)$term['term_id'];
  update_post_meta($post,'rank_math_title','%title%');update_post_meta($post,'rank_math_description','Original source description');update_post_meta($post,'_kiwe_seo_title','');
  update_post_meta($post,'rank_math_canonical_url',home_url('/synthetic-handover-canonical/'));update_post_meta($post,'rank_math_robots',['index','follow']);
  update_post_meta($other,'rank_math_title','Original page title');update_term_meta($category,'rank_math_title','%term%');
  $sourceRows=static fn()=>[$wpdb->get_results("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$post},{$other}) AND meta_key LIKE 'rank_math_%' ORDER BY meta_id",ARRAY_A),$wpdb->get_results("SELECT term_id,meta_key,meta_value FROM {$wpdb->termmeta} WHERE term_id={$category} AND meta_key LIKE 'rank_math_%' ORDER BY meta_id",ARRAY_A)];
  $sourceHash=hash('sha256',serialize($sourceRows()));
  $before=kiwe_handover_record_meta($post);$otherBefore=kiwe_handover_record_meta($other);$termBefore=get_term_meta($category);
  $service=new DSA\SEO\SEO_Metadata_Migration_Service();$state=$service->status();
  $check($service->mutate($request('start',$state,'post',false))->get_status()===403,'Actual mutation proof is required before import scheduling');
  wp_set_current_user($author);$check($service->mutate($request('start',$state))->get_status()===403,'Actual author cannot manage website source import');wp_set_current_user($owner);
  $queued=$service->mutate($request('start',$state));
  $check(get_post_meta($post,'_kiwe_seo_title',true)==='','Actual REST scheduling performs no metadata import inline');$worker($service,$queued);
  $postState=$service->status();$postJob=$postState['scopes']['post']['lastImport']['id'];
  $check(get_post_meta($post,'_kiwe_seo_title',true)==='%title%'&&get_post_meta($other,'_kiwe_seo_title',true)==='Original page title','Actual import updates an existing empty field and adds missing fields through native metadata');
  $journal=get_post_meta($post,DSA\SEO\SEO_Metadata_Migration_Service::BACKUP_META,true);
  $check($journal['schema']===2&&$journal['imported']['_kiwe_seo_title']==='%title%'&&$journal['values']['_kiwe_seo_title']===['exists'=>true,'value'=>''],'Native journal retains exact prior existence and the planned imported value');
  if(function_exists('kiwe_handover_output_cases'))$proof['renderedHandover']=kiwe_handover_output_cases($check,(int)$post);
  $worker($service,$service->mutate($request('start',$postState,'term')));$both=$service->status();$termJob=$both['scopes']['term']['lastImport']['id'];
  $check($both['scopes']['post']['lastImport']['id']===$postJob&&$both['scopes']['term']['lastImport']['id']===$termJob,'Native post and taxonomy imports retain separate rollback references');
  update_post_meta($post,'_kiwe_seo_title','Newer owner title');$newer=kiwe_handover_record_meta($post);
  $worker($service,$service->mutate($request('rollback',$both,'post')));$conflicted=$service->status();
  $check(kiwe_handover_record_meta($post)===$newer,'Actual rollback preflight preserves every field on a record with a newer edit');
  $check(kiwe_handover_record_meta($other)===$otherBefore&&$conflicted['scopes']['post']['rollbackRecords']===1,'Native unaffected record restores while the conflicting source journal remains discoverable');
  $check(get_term_meta($category,'_kiwe_seo_title',true)==='%term%'&&$conflicted['scopes']['term']['canRollback'],'Native post rollback leaves the independent term import intact');
  $worker($service,$service->mutate($request('rollback',$conflicted,'term')));
  $check(get_term_meta($category)===$termBefore&&$service->status()['scopes']['post']['canRollback'],'Actual taxonomy undo remains independent of the post conflict');
  update_post_meta($post,'_kiwe_seo_title','%title%');$worker($service,$service->mutate($request('rollback',$service->status(),'post')));
  $check(kiwe_handover_record_meta($post)===$before&&!$service->status()['scopes']['post']['canRollback'],'Native conflict resolution and exact retry retire only completed journals');

  $worker($service,$service->mutate($request('start',$service->status(),'post')));
  $hook=static function($mid,$id,$key,$value)use($post){if($id===$post&&$key==='_kiwe_seo_title'){remove_all_actions('update_post_meta');delete_post_meta($post,'_kiwe_seo_title');}};
  add_action('update_post_meta',$hook,PHP_INT_MAX,4);
  $worker($service,$service->mutate($request('rollback',$service->status(),'post')));remove_action('update_post_meta',$hook,PHP_INT_MAX);
  $check(!metadata_exists('post',$post,'_kiwe_seo_title')&&metadata_exists('post',$post,DSA\SEO\SEO_Metadata_Migration_Service::MARKER_META),'Actual deletion interleaved after preflight is not recreated by native conditional SQL');
  update_post_meta($post,'_kiwe_seo_title','%title%');$worker($service,$service->mutate($request('rollback',$service->status(),'post')));
  $check(kiwe_handover_record_meta($post)===$before,'Native conditional retry restores the original existing empty field exactly');

  delete_post_meta($post,'_kiwe_seo_title');$missingBefore=kiwe_handover_record_meta($post);
  $worker($service,$service->mutate($request('start',$service->status(),'post')));
  $hook=static function($mids,$id,$key,$value)use($post){if($id===$post&&$key==='_kiwe_seo_title'){remove_all_actions('delete_post_meta');update_post_meta($post,'_kiwe_seo_title','Concurrent replacement');}};
  add_action('delete_post_meta',$hook,PHP_INT_MAX,4);$worker($service,$service->mutate($request('rollback',$service->status(),'post')));remove_action('delete_post_meta',$hook,PHP_INT_MAX);
  $check(get_post_meta($post,'_kiwe_seo_title',true)==='Concurrent replacement'&&$service->status()['scopes']['post']['canRollback'],'Actual value interleaved before conditional deletion is preserved');
  update_post_meta($post,'_kiwe_seo_title','%title%');$worker($service,$service->mutate($request('rollback',$service->status(),'post')));
  $check(kiwe_handover_record_meta($post)===$missingBefore,'Native successful retry restores exact missing-field existence');

  $worker($service,$service->mutate($request('start',$service->status(),'post')));$leaseBefore=kiwe_handover_record_meta($post);$stateBefore=$service->status();
  $leaseKey='kiwe_meta_'.substr(hash('sha256',(string)$wpdb->dbname.'|'.$wpdb->prefix),0,40);
  $leaseState=null;$hook=static function($mids,$id,$key,$value)use($post,$leaseKey,$service,&$leaseState){global$wpdb;if($id===$post&&$key==='_kiwe_seo_title'){$leaseState=$service->status()['active'];$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$leaseKey));}};
  add_action('delete_post_meta',$hook,PHP_INT_MAX,4);
  $queued=$service->mutate($request('rollback',$stateBefore,'post'));$job=$queued->get_data()['active']['id'];$queuedState=$service->status();$service->run($job);remove_action('delete_post_meta',$hook,PHP_INT_MAX);
  $check(kiwe_handover_record_meta($post)===$leaseBefore,'Lost native session lease after a metadata hook prevents conditional writes and journal retirement');
  $check($leaseState!==null&&$service->status()['active']===$leaseState,'A former native lease owner cannot publish newer progress or job state');
  $worker($service,$service->mutate($request('resume',$service->status(),'post')));
  $check(kiwe_handover_record_meta($post)===$missingBefore,'Fresh native ownership resumes a lease-lost action to exact prior metadata');

  $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);if(!$second->dbh)throw new RuntimeException('Second native SQL connection unavailable');
  $key='kiwe_meta_'.substr(hash('sha256',(string)$wpdb->dbname.'|'.$wpdb->prefix),0,40);
  $check((string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$key))==='1','Actual second SQL session obtains the same-site writer lease');
  $state=$service->status();$start=microtime(true);$busy=$service->mutate($request('start',$state));
  $check($busy->get_status()===409&&microtime(true)-$start<1&&$service->status()['active']===null,'Actual competing native writer returns immediately without scheduling a job');
  $old=$wpdb->dbname;$wpdb->dbname=$old.'_other_identity';
  try{$different=$service->mutate($request('start',$service->status()));$check($different->get_status()===200,'A different logical database identity has an independent no-wait writer lock');}
  finally{$wpdb->dbname=$old;$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$key));$second->close();$second=null;}
  $worker($service,$different);$worker($service,$service->mutate($request('rollback',$service->status(),'post')));
  if(function_exists('kiwe_handover_metadata_recovery_cases'))kiwe_handover_metadata_recovery_cases($check,$service,$request,$worker,(int)$post);
  if(function_exists('kiwe_handover_backup_cases'))kiwe_handover_backup_cases($check,(int)$post,$owner,$author,$category);
  if(function_exists('kiwe_handover_reporting_recovery_cases'))kiwe_handover_reporting_recovery_cases($check);
  if(function_exists('kiwe_handover_settings_cases'))kiwe_handover_settings_cases($check,$owner,$author);
  $check($sourceHash===hash('sha256',serialize($sourceRows())),'All original native Rank Math source fields remain byte-exact through import undo and content copies');
  $check(($GLOBALS['kiwe_seo_test_http_calls']??0)===0&&($GLOBALS['kiwe_seo_test_mail_calls']??0)===0,'Native import and undo perform no remote AI provider email or campaign work');
  $proof['platform']=['wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'database'=>$wpdb->db_version()];$proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();$proof['line']=$e->getLine();$proof['errorClass']=get_class($e);}
 finally {
  if($second){$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$key));$second->close();}
  try{do_action('shutdown');$proof['checks']['Native shutdown completes within isolated authority']=true;}catch(Throwable$e){$proof['checks']['Native shutdown completes within isolated authority']=false;$proof['shutdownError']=$e->getMessage();}
  remove_all_actions('shutdown');kiwe_seo_test_cleanup($proof);kiwe_seo_test_progress($proof);
 }
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);$proof['checkCount']=count($proof['checks']);return$proof;
}
