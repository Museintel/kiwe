<?php

/** Compare the same explicit authored metadata in actual provider-on and provider-off boots. */
function kiwe_handover_output_cases(callable $check,int $post):array {
 global $wp_query,$wp_the_query;
 $oldQuery=$wp_query;$oldMain=$wp_the_query;$oldPost=$GLOBALS['post']??null;$source=get_post($post);$original=$post;
 // Publish a dedicated render fixture. Rendering must not mutate the undo fixture's
 // native slug/date/revision metadata merely to establish a public query context.
 $post=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>$source->post_title,'post_author'=>$source->post_author,'post_content'=>$source->post_content],true);
 if(is_wp_error($post))throw new RuntimeException('Native rendered handover fixture unavailable');
 foreach(get_post_meta($original)as$key=>$values)if((str_starts_with($key,'rank_math_')||str_starts_with($key,'_kiwe_seo_'))&&!in_array($key,[DSA\SEO\SEO_Metadata_Migration_Service::BACKUP_META,DSA\SEO\SEO_Metadata_Migration_Service::MARKER_META],true))foreach($values as$value)add_post_meta($post,$key,wp_slash(maybe_unserialize($value)));
 try{
  $wp_query=new WP_Query(['p'=>$post,'post_type'=>'post']);$wp_the_query=$wp_query;$GLOBALS['post']=$wp_query->post;
  $external=defined('RANK_MATH_VERSION');
  if($external){
   $front=null;$variables=null;
   foreach(($GLOBALS['wp_filter']['wp']->callbacks??[])as$callbacks)foreach($callbacks as$item){$fn=$item['function'];if(!is_array($fn))continue;if(($fn[0]??null)instanceof \RankMath\Frontend\Frontend&&($fn[1]??'')==='integrations'){$front=$fn[0];$fn();}elseif(($fn[0]??null)instanceof \RankMath\Replace_Variables\Manager&&($fn[1]??'')==='setup'){$variables=$fn[0];$fn();}}
   $check($front instanceof \RankMath\Frontend\Frontend&&$variables instanceof \RankMath\Replace_Variables\Manager,'Actual provider public lifecycle is initialized for rendered handover comparison');
  }
  ob_start();do_action('wp_head');$head=(string)ob_get_clean();$values=['title'=>wp_get_document_title()];$counts=[];
  preg_match_all('~<(?:meta|link)\b[^>]*>~i',$head,$tags);
  foreach($tags[0]as$tag){$attributes=[];preg_match_all('~([a-z:-]+)\s*=\s*(["\x27])(.*?)\2~is',$tag,$matches,PREG_SET_ORDER);foreach($matches as$m)$attributes[strtolower($m[1])]=html_entity_decode($m[3],ENT_QUOTES|ENT_HTML5,'UTF-8');$key=$attributes['name']??$attributes['property']??$attributes['rel']??'';if(!in_array($key,['description','canonical','og:title','og:description','twitter:title','twitter:description','robots'],true))continue;$counts[$key]=($counts[$key]??0)+1;$values[$key]=$attributes['content']??$attributes['href']??'';}
  $title=get_post($post)->post_title;$expected=['title'=>$title,'description'=>'Original source description','canonical'=>home_url('/synthetic-handover-canonical/'),'og:title'=>$title,'og:description'=>'Original source description','twitter:title'=>$title,'twitter:description'=>'Original source description'];
  $GLOBALS['kiwe_seo_test_partial_proof']['renderedHandoverObservation']=['owner'=>$external?'rank_math':'kiwe','values'=>$values,'counts'=>$counts,'expected'=>$expected];
  foreach($expected as$key=>$value)$check(($values[$key]??null)===$value&&($key==='title'||($counts[$key]??0)===1),'Rendered supported handover value is exact and has one owner: '.$key);
  $check(!str_contains($values['robots']??'','noindex')&&!str_contains($values['robots']??'','nofollow'),'Rendered source indexing remains indexable after native import');
  $check($external?!str_contains($head,'data-kiwe-seo'):str_contains($head,'data-kiwe-seo'),'Rendered head authority follows the actual active provider');
  return ['owner'=>$external?'rank_math':'kiwe','providerVersion'=>$external?RANK_MATH_VERSION:null,'supportedValues'=>$expected,'unsupportedImplicitProviderFeaturesCertified'=>false];
 }finally{wp_delete_post($post,true);$wp_query=$oldQuery;$wp_the_query=$oldMain;$GLOBALS['post']=$oldPost;}
}

/** Native interruption checkpoints, separate from successful import/undo. */
function kiwe_handover_metadata_recovery_cases(callable $check,$service,callable $request,callable $worker,int $post):void {
 global $wpdb;$M=DSA\SEO\SEO_Metadata_Migration_Service::class;$before=kiwe_handover_record_meta($post);$hook=null;$fault=null;
 try{
  $queued=$service->mutate($request('start',$service->status()));$id=$queued->get_data()['active']['id'];wp_clear_scheduled_hook($M::CRON,[$id]);
  $hook=static function($mid,$record,$key)use($post,$M){if($record===$post&&$key===$M::BACKUP_META)throw new RuntimeException('Synthetic stop after native journal, before locator');};add_action('added_post_meta',$hook,PHP_INT_MAX,3);$stopped=false;
  try{$service->run($id);}catch(Throwable$e){$stopped=true;}finally{remove_action('added_post_meta',$hook,PHP_INT_MAX);$hook=null;}
  $check($stopped&&metadata_exists('post',$post,$M::BACKUP_META)&&!metadata_exists('post',$post,$M::MARKER_META),'Native stop between journal creation and locator retains the original before-image');
  $worker($service,$service->mutate($request('resume',$service->status())));
  $check(get_post_meta($post,'_kiwe_seo_title',true)==='%title%'&&get_post_meta($post,$M::BACKUP_META,true)['values']['_kiwe_seo_title']===['exists'=>array_key_exists('_kiwe_seo_title',$before),'value'=>$before['_kiwe_seo_title'][0]??''],'Native resume reuses the orphan journal before applying source values');
  $fault=static function($sql)use($wpdb){return str_starts_with($sql,'DELETE j FROM '.$wpdb->postmeta.' ')?'SELECT 0':$sql;};add_filter('query',$fault,PHP_INT_MAX);
  $worker($service,$service->mutate($request('rollback',$service->status(),'post')));remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
  $check(metadata_exists('post',$post,$M::BACKUP_META)&&metadata_exists('post',$post,$M::MARKER_META)&&$service->status()['active']['cursor']===0,'Native failed atomic retirement leaves both exact journal and locator discoverable');
  $worker($service,$service->mutate($request('resume',$service->status())));$check(kiwe_handover_record_meta($post)===$before,'Native retry retires the journal pair after prior target fields were already restored');
 }finally{if($hook)remove_action('added_post_meta',$hook,PHP_INT_MAX);if($fault)remove_filter('query',$fault,PHP_INT_MAX);}
}
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_SEO_NATIVE_TEST' ) || true !== KIWE_SEO_NATIVE_TEST ) throw new RuntimeException( 'Owned native backup fixture required.' );

/** Actual same-site archives and native metadata; every created private copy is retired by identity. */
function kiwe_handover_backup_cases( callable $check, int $post, int $owner, int $author, int $category ): void {
 global $wpdb;
 $service=new DSA\Backup\Configuration_Snapshot_Service();$content=new DSA\Backup\Content_Archive_Service();$copies=[];$archives=[];$stages=[];$restored=[];
 $password='synthetic-native-handover-backup-phrase';
 $trackStage=static function(array $review,string $bytes)use(&$stages):void{$row=get_transient('kiwe_backup_review_'.$review['token']);if(!is_array($row)||!preg_match('/^[a-f0-9]{24}$/D',$row['id']??''))throw new RuntimeException('Actual native staged file identity unavailable');$stages['stage-'.$row['id'].'.kiwe']=hash('sha256',$bytes);};
 try{
  wp_set_current_user($owner);update_option('kiwe_access_policy_v1',['enabled'=>true,'ownerId'=>$owner],false);
  $check(DSA\Backup\SEO_State_Snapshot_Service::allowed(),'Native site owner has shared SEO backup authority');
  $protected=[];foreach(['kiwe_seo_workspace_enabled_v1','kiwe_ai_opt_in_global_v1','active_plugins','rank-math-options-titles']as$key)$protected[$key]=get_option($key,null);
  delete_option(DSA\SEO\SEO_Defaults_Service::OPTION);
  $defaults=DSA\SEO\SEO_Defaults_Service::validate(['separator'=>'|','postTypes'=>['post'=>['title'=>'%title% %sep% %sitename%']],'news'=>['enabled'=>true,'publication'=>'Synthetic native paper','language'=>'en']]);
  $directory=DSA\Backup\Configuration_Snapshot_Service::private_directory(true);
  $previous=dirname(rtrim(ABSPATH,'/')).'/kiwe-private-stseo7/attempt-b.json';
  if(is_file($previous)&&!is_link($previous)){
   $prior=json_decode(file_get_contents($previous),true);$retired=0;
   if(($prior['passed']??null)!==false||($prior['sourceHashes']['seo-handover-native-backups.php']??'')!=='59dedf5e32fb80d5cee251eeba472c003e1aeee16f00dd98870bb7bb4b605023')throw new RuntimeException('Previous fixture attribution refused');
   $candidates=glob($directory.'/stage-*.kiwe')?:[];if(count($candidates)>60)throw new RuntimeException('Owned prior staging scan exceeds its boundary');
   foreach($candidates as$path){
    if(is_link($path)||!is_file($path)||filesize($path)>65536||filemtime($path)<filemtime($previous)-120||filemtime($path)>filemtime($previous))continue;
    $body=file_get_contents($path);$header=json_decode($body,true);if(($header['label']??'')!=='Synthetic handover defaults')continue;
    try{$payload=DSA\Backup\Archive_Cipher::open($body,DSA\Backup\Configuration_Snapshot_Service::SCHEMA,$password,65536);}catch(Throwable$e){continue;}
    if(($payload['options']??null)!==[DSA\Backup\SEO_State_Snapshot_Service::NAME=>['exists'=>true,'value'=>['schema'=>1,'defaults'=>$defaults]]])continue;
    if(!preg_match('/^stage-[a-f0-9]{24}\.kiwe$/D',basename($path))||!unlink($path))throw new RuntimeException('Authenticated prior synthetic stage retirement refused');++$retired;
   }
   $completedPath=dirname($previous).'/attempt-c.json';$completed=is_file($completedPath)&&!is_link($completedPath)?json_decode(file_get_contents($completedPath),true):null;
   $already=$retired===0&&($completed['passed']??null)===true&&($completed['cleanup']??null)===true&&($completed['sourceHashes']['seo-handover-native-backups.php']??'')==='1df4a0dc7019c4d6238aa3a818c58dc81cfcc32f68fed6f30ab0e1300caee926'&&($completed['native']['checks']['Previous interrupted synthetic review is authenticated by site cipher source defaults and creation window then retired']??null)===true&&($completed['native']['checks']['Every synthetic native review stage is retired']??null)===true;
   $check($retired===1||$already,'Previously interrupted synthetic review is retired or independently recorded as already retired');
  }
  $legacy=json_decode('{"c":"1df4a0dc7019c4d6238aa3a818c58dc81cfcc32f68fed6f30ab0e1300caee926","g":"8d23f8971188dd3c54a8f5dfd330ebfcfa03bc7989ebdb47b160e4b8fe2ebd70","h":"70c6b00ab11c75894d876ab4f0e7788c3ebfc8c59feaba654d756dbdb9403c47"}',true);$retiredLegacy=0;
  foreach($legacy as$letter=>$sha){$receiptPath=dirname($previous).'/attempt-'.$letter.'.json';if(!is_file($receiptPath)||is_link($receiptPath))continue;$receipt=json_decode(file_get_contents($receiptPath),true);if(($receipt['sourceHashes']['seo-handover-native-backups.php']??null)!==$sha||($receipt['attempt']??null)!==$letter)throw new RuntimeException('Historical stage fixture attribution refused');
   $candidates=glob($directory.'/stage-*.kiwe')?:[];if(count($candidates)>60)throw new RuntimeException('Historical stage scan exceeds the closed boundary');
   foreach($candidates as$path){if(is_link($path)||!is_file($path)||filesize($path)>65536||filemtime($path)<filemtime($receiptPath)-120||filemtime($path)>filemtime($receiptPath))continue;$body=file_get_contents($path);$header=json_decode($body,true);if(($header['label']??null)!=='Synthetic handover defaults')continue;
    try{$payload=DSA\Backup\Archive_Cipher::open($body,DSA\Backup\Configuration_Snapshot_Service::SCHEMA,$password,65536);}catch(Throwable$e){continue;}
    if(($payload['options']??null)!==[DSA\Backup\SEO_State_Snapshot_Service::NAME=>['exists'=>true,'value'=>['schema'=>1,'defaults'=>$defaults]]])continue;
    if(!preg_match('/^stage-[a-f0-9]{24}\.kiwe$/D',basename($path))||!unlink($path))throw new RuntimeException('Authenticated historical stage retirement refused');++$retiredLegacy;
   }
  }
  $GLOBALS['kiwe_handover_retired_historical_reviews']=$retiredLegacy;
  $check(true,'Historical C G H review cleanup is attributed to exact fixture receipts site cipher payload and creation window');
  DSA\Backup\SEO_State_Snapshot_Service::write(['exists'=>true,'value'=>['schema'=>1,'defaults'=>$defaults]]);
  $check(get_option(DSA\SEO\SEO_Defaults_Service::OPTION)===$defaults,'Native defaults adapter uses exact WordPress option readback');
  $copy=$service->create($password,'Synthetic handover defaults',[DSA\Backup\SEO_State_Snapshot_Service::GROUP]);$copies[]=$copy['id'];$bytes=file_get_contents($service->download_path($copy['id']));
  $check(!str_contains($bytes,'Synthetic native paper'),'Native settings archive contains authenticated ciphertext rather than plaintext defaults');
  delete_option(DSA\SEO\SEO_Defaults_Service::OPTION);$review=$service->preview_upload($bytes,$password);$trackStage($review,$bytes);update_option(DSA\SEO\SEO_Defaults_Service::OPTION,DSA\SEO\SEO_Defaults_Service::defaults(),false);
  $refused=false;try{$service->apply($review['token'],$password);}catch(Throwable$e){$refused=true;}
  $check($refused&&get_option(DSA\SEO\SEO_Defaults_Service::OPTION)===DSA\SEO\SEO_Defaults_Service::defaults(),'Native newer defaults invalidate the reviewed encrypted restore');
  delete_option(DSA\SEO\SEO_Defaults_Service::OPTION);$review=$service->preview_upload($bytes,$password);$trackStage($review,$bytes);
  $observer=static function($phase,$value)use(&$copies){if($phase==='prepared')$copies[]=$value['rollbackId'];};
  $done=$service->apply($review['token'],$password,$observer);
  $check($done['restored']===1&&get_option(DSA\SEO\SEO_Defaults_Service::OPTION)===$defaults,'Native encrypted snapshot restores current SEO defaults through shared dispatch');
  $undoBytes=file_get_contents($service->download_path($done['rollbackId']));$undo=$service->preview_upload($undoBytes,$password);$trackStage($undo,$undoBytes);$service->apply($undo['token'],$password,$observer);
  $missing=new stdClass();$check(get_option(DSA\SEO\SEO_Defaults_Service::OPTION,$missing)===$missing,'Native before-restore copy restores exact option absence');
  $review=$service->preview_upload($bytes,$password);$trackStage($review,$bytes);$newer=DSA\SEO\SEO_Defaults_Service::validate(['home'=>['title'=>'Native newer edit after restore']]);$refused=false;
  try{$service->apply($review['token'],$password,static function($phase,$value)use(&$copies,$newer){if($phase==='prepared')$copies[]=$value['rollbackId'];if($phase==='complete'){update_option(DSA\SEO\SEO_Defaults_Service::OPTION,$newer,false);throw new RuntimeException('Synthetic completion failure after newer edit');}});}catch(Throwable$e){$refused=true;}
  $check($refused&&get_option(DSA\SEO\SEO_Defaults_Service::OPTION)===$newer,'Native failed settings completion preserves observed newer client defaults instead of overwriting them during rollback');delete_option(DSA\SEO\SEO_Defaults_Service::OPTION);
  $review=$service->preview_upload($bytes,$password);$trackStage($review,$bytes);$queryHook=null;$interleaved=false;
  $queryHook=static function($sql)use($wpdb,$newer,&$queryHook,&$interleaved){if(str_starts_with($sql,'INSERT IGNORE INTO '.$wpdb->options.' ')&&str_contains($sql,"'kiwe_seo_defaults_v1'")){remove_filter('query',$queryHook,PHP_INT_MAX);$interleaved=true;update_option(DSA\SEO\SEO_Defaults_Service::OPTION,$newer,false);}return$sql;};add_filter('query',$queryHook,PHP_INT_MAX);$refused=false;
  try{$service->apply($review['token'],$password,static function($phase,$value)use(&$copies){if($phase==='prepared')$copies[]=$value['rollbackId'];});}catch(Throwable$e){$refused=true;}finally{remove_filter('query',$queryHook,PHP_INT_MAX);}
  $check($interleaved&&$refused&&get_option(DSA\SEO\SEO_Defaults_Service::OPTION)===$newer,'Native restore protects an edit interleaved inside the physical option insert, after both earlier review reads');delete_option(DSA\SEO\SEO_Defaults_Service::OPTION);
  $after=[];foreach(array_keys($protected)as$key)$after[$key]=get_option($key,null);
  $check($after===$protected,'Native restore preserves source provider data and SEO AI plugin choices');
  wp_set_current_user($author);$refused=false;try{DSA\Backup\SEO_State_Snapshot_Service::read();}catch(Throwable$e){$refused=true;}
  $check($refused&&!isset(DSA\Backup\Configuration_Snapshot_Service::option_groups()[DSA\Backup\SEO_State_Snapshot_Service::GROUP]),'Native author cannot inspect or restore global SEO snapshot state');wp_set_current_user($owner);
  $graph=[['@type'=>'NewsArticle','headline'=>'%title%','url'=>'%permalink%']];
  update_post_meta($post,'_kiwe_schema_graph_v1',$graph);update_post_meta($post,'_kiwe_seo_facebook_title','Synthetic sharing title');update_post_meta($post,'_kiwe_seo_twitter_image',home_url('/synthetic-picture.jpg'));
  update_term_meta($category,'_kiwe_seo_canonical_url',home_url('/synthetic-category/'));update_term_meta($category,'_kiwe_seo_facebook_title','Synthetic category sharing');wp_set_post_terms($post,[$category],'category');
  update_post_meta($post,'_kiwe_schema_import_v1',['schema'=>1,'graph'=>$graph,'sourceSha'=>str_repeat('a',64),'importedSha'=>hash('sha256',wp_json_encode($graph))]);
  $archive=$content->export('post',$post,$password);$archivePath=$content->download_path($archive['id']);$body=file_get_contents($archivePath);$archives[$archivePath]=hash('sha256',$body);$review=$content->preview($body,$password,'post');$stages['content-review-'.$review['token'].'.kiwe']=hash('sha256',$body);$result=$content->apply($review['token'],$password);$restored=$result['ids']??[];
  if(!is_array($restored))throw new RuntimeException('Native content recovery identities require explicit readback');
  $id=(int)($restored[0]??0);
  $check($id>0&&get_post_status($id)==='draft'&&get_post_meta($id,'_kiwe_schema_graph_v1',true)===$graph,'Native content roundtrip preserves the declarative schema in a new draft');
  $check(get_post_meta($id,'_kiwe_seo_facebook_title',true)==='Synthetic sharing title'&&get_post_meta($id,'_kiwe_seo_twitter_image',true)===home_url('/synthetic-picture.jpg'),'Native content roundtrip retains sharing title and image fields');
  $check(!metadata_exists('post',$id,'_kiwe_schema_import_v1')&&!metadata_exists('post',$id,DSA\SEO\SEO_Metadata_Migration_Service::BACKUP_META),'Native copied content does not inherit source undo journals');
  $check(get_post_meta($post,'_kiwe_schema_graph_v1',true)===$graph&&get_post_meta($post,'_kiwe_schema_import_v1',true)['graph']===$graph,'Native content export and copy leave original graph and journal intact');
 }finally{
  wp_set_current_user($owner);foreach(array_unique($copies)as$id)$service->remove($id);
  $directory=DSA\Backup\Configuration_Snapshot_Service::private_directory();
  foreach($archives as$path=>$hash){if(realpath(dirname($path))!==$directory||is_link($path)||hash_file('sha256',$path)!==$hash||!unlink($path))throw new RuntimeException('Exact owned native archive retirement failed');}
  foreach($stages as$name=>$hash){$path=$directory.'/'.$name;if(!file_exists($path))continue;if(!preg_match('/^(?:stage-[a-f0-9]{24}|content-review-[a-f0-9]{32})\.kiwe$/D',$name)||is_link($path)||hash_file('sha256',$path)!==$hash||!unlink($path))throw new RuntimeException('Exact owned native review retirement failed');}
  $check(!array_filter(array_unique($copies),static function($id)use($service){try{$service->download_path($id);return true;}catch(Throwable$e){return false;}}),'Every synthetic native settings copy and undo copy is retired');
  $check(!array_filter(array_keys($archives),'file_exists'),'Every synthetic native content archive is retired');
  $check(!array_filter(array_keys($stages),static fn($name)=>file_exists($directory.'/'.$name)),'Every synthetic native review stage is retired');
 }
}

/** Native physical option CAS, missing-state recovery and newer-edit protection. */
function kiwe_handover_settings_cases(callable $check,int $owner,int $author):void {
 global$wpdb;$class=DSA\SEO\SEO_Settings_Handover_Service::class;$defaults=DSA\SEO\SEO_Defaults_Service::OPTION;
 $keys=['rank-math-options-titles','rank-math-options-sitemap','rank-math-options-general',$defaults,$class::JOURNAL];$prior=[];foreach($keys as$key)$prior[$key]=get_option($key,null);
 $choices=[get_option('kiwe_seo_workspace_enabled_v1',null),get_option('dsa_settings',null)];$service=new $class();$queryHook=null;$leaseHook=null;
 $request=static function(string $method,array $body=[],bool $mutation=true):WP_REST_Request{$r=new WP_REST_Request($method);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('x-kiwe-mutation','1');if($method==='POST'){$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($body));}return$r;};
 $read=static function()use($service,$request){$r=$service->read($request('GET'));if($r->get_status()!==200)throw new RuntimeException('Native settings review failed: '.wp_json_encode($r->get_data()));return$r->get_data();};
 $call=static fn($operation,$state,$mutation=true)=>$service->mutate($request('POST',['operation'=>$operation,'confirmed'=>true,'expected'=>$state['expected']],$mutation));
 try{
  wp_set_current_user($owner);update_option('rank-math-options-titles',['title_separator'=>'·','pt_post_title'=>'%title% %sep% %sitename%','pt_post_custom_robots'=>'off'],false);update_option('rank-math-options-sitemap',['items_per_page'=>'200','authors_sitemap'=>'off'],false);delete_option('rank-math-options-general');delete_option($defaults);delete_option($class::JOURNAL);
  $source=static fn()=>[get_option('rank-math-options-titles',null),get_option('rank-math-options-sitemap',null),get_option('rank-math-options-general',null)];$sourceBefore=$source();$view=$read();
  $check($view['canImport']&&!$view['canUndo']&&!$view['providerRemovalReady']&&count($view['mappedFields'])===4,'Native bounded website settings review never certifies source removal');
  $check($call('import',$view,false)->get_status()===403,'Native global settings import requires actual mutation proof');
  wp_set_current_user($author);$check($service->read($request('GET'))->get_status()===403&&$call('import',$view)->get_status()===403,'Actual author cannot inspect or import global source settings');wp_set_current_user($owner);
  $stale=$view;$stale['expected']=str_repeat('0',64);$check($call('import',$stale)->get_status()===409&&get_option($class::JOURNAL,null)===null,'Native stale settings review creates no journal or default writes');
  $saved=$call('import',$view);$check($saved->get_status()===200&&get_option($defaults)['separator']==='·'&&get_option($defaults)['sitemap']['pageSize']===200,'Actual stored website settings stage into the shared native defaults');
  $j=get_option($class::JOURNAL);$check($j['before']===null&&$j['after']===maybe_serialize(get_option($defaults))&&$read()['canUndo'],'Native settings journal retains exact prior option absence and imported bytes');
  $newer=['separator'=>'!','home'=>['title'=>'Newer website title']];update_option($defaults,$newer,false);$conflict=$read();$check(!$conflict['canUndo']&&$call('undo',$conflict)->get_status()===409&&get_option($defaults)===$newer&&get_option($class::JOURNAL)===$j,'Native global settings undo protects newer edits and retains its journal');
  update_option($defaults,maybe_unserialize($j['after']),false);$check($call('undo',$read())->get_status()===200&&get_option($defaults,null)===null&&get_option($class::JOURNAL,null)===null,'Native exact settings retry restores prior absence and retires only its own journal');
  $existing=['separator'=>'/','news'=>['enabled'=>false]];update_option($defaults,$existing,false);$v=$read();$check(in_array('separator',$v['nativeConflicts'],true),'Native review identifies existing Kiwe settings instead of replacing them');
  $check($call('import',$v)->get_status()===200&&get_option($defaults)['separator']==='/'&&get_option($defaults)['news']===$existing['news'],'Native partial settings import preserves existing website choices');
  $check($call('undo',$read())->get_status()===200&&get_option($defaults)===$existing,'Native settings undo restores exact existing partial option bytes');
  delete_option($defaults);$lease='kiwe_defaults_'.substr(hash('sha256',(string)$wpdb->dbname.'|'.$wpdb->prefix),0,40);
  $leaseHook=static function($name,$value)use($class,$lease){global$wpdb;if($name===$class::JOURNAL)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lease));};add_action('added_option',$leaseHook,PHP_INT_MAX,2);
  $result=$call('import',$read());remove_action('added_option',$leaseHook,PHP_INT_MAX);$leaseHook=null;
  $check($result->get_status()===409&&get_option($defaults,null)===null&&$read()['canUndo'],'Native lost settings lease pauses before applying defaults with a recoverable journal');
  $check($call('undo',$read())->get_status()===200&&get_option($class::JOURNAL,null)===null,'Native journal from an interrupted settings import can be retired without invented writes');
  update_option($defaults,$existing,false);
  $queryHook=static function($sql)use($wpdb,$defaults,$newer,&$queryHook){if(str_starts_with($sql,'UPDATE '.$wpdb->options.' ')&&str_contains($sql,"option_name='".$defaults."'")){remove_filter('query',$queryHook,PHP_INT_MAX);update_option($defaults,$newer,false);}return$sql;};add_filter('query',$queryHook,PHP_INT_MAX);
  $result=$call('import',$read());remove_filter('query',$queryHook,PHP_INT_MAX);$queryHook=null;
  $check($result->get_status()===409&&get_option($defaults)===$newer&&$read()['journalRetained']&&!$read()['canUndo'],'Actual interleaved website edit defeats binary conditional SQL and remains protected');
  $j=get_option($class::JOURNAL);update_option($defaults,maybe_unserialize($j['after']),false);$check($call('undo',$read())->get_status()===200&&get_option($defaults)===$existing,'Fresh native review can resolve a retained settings conflict through exact conditional undo');
  $titles=get_option('rank-math-options-titles');$titles['facebook_secret']='Synthetic provider secret never displayed';$titles['pt_post_title']='%title% %page%';update_option('rank-math-options-titles',$titles,false);$v=$read();$check(in_array('titles.facebook_secret',$v['blockedFields'],true)&&in_array('titles.pt_post_title',$v['blockedFields'],true)&&!str_contains(wp_json_encode($v),'Synthetic provider secret'),'Actual readiness report exposes unresolved field names without source credentials or lossy pagination');
  update_option('rank-math-options-titles',$sourceBefore[0],false);$check($source()===$sourceBefore&&$choices===[get_option('kiwe_seo_workspace_enabled_v1',null),get_option('dsa_settings',null)],'Native website settings handover preserves Rank Math source and existing SEO AI security choices');
 }finally{if($queryHook)remove_filter('query',$queryHook,PHP_INT_MAX);if($leaseHook)remove_action('added_option',$leaseHook,PHP_INT_MAX);foreach($prior as$key=>$value)if($value===null)delete_option($key);else update_option($key,$value,false);wp_set_current_user($owner);$check(!array_filter($keys,static fn($key)=>get_option($key,null)!==$prior[$key]),'Every synthetic website settings and journal tuple is restored before native cleanup');}
}

/** Real SQL at the inactive recovery boundary; not a complete site cutover claim. */
function kiwe_handover_reporting_recovery_cases( callable $check ): void {
 global $wpdb;$db=$wpdb->dbh;if(!$db instanceof mysqli)throw new RuntimeException('Native recovery bridge requires mysqli');
 $Owner=DSA\Backup\Portable_Recovery_Ownership::class;$prefix='kiwe293history_'.bin2hex(random_bytes(6)).'_';$live=$prefix.'destination_';$created=[];$tables=[];
 $q=static fn($v)=>"X'".bin2hex((string)$v)."'";
 $query=static function($sql)use($db){$r=$db->query($sql);if($r===false)throw new RuntimeException('Private native recovery statement failed.');if($r instanceof mysqli_result){$v=$r->fetch_all(MYSQLI_NUM);$r->free();return$v;}return[];};
 $create=static function($name,$sql)use($query,&$created){$query('CREATE TEMPORARY TABLE '.$name.' '.$sql);$created[]=$name;};
 $descriptor=static function($suffix,$columns,$primary,$rows)use($prefix,&$tables){$tables[]=['name'=>$prefix.$suffix,'columns'=>$columns,'primary'=>$primary,'rows'=>$rows];};
 try{
  foreach([$prefix,$live]as$p)foreach(['options','users','usermeta']as$name)$create($p.$name,'LIKE '.$wpdb->$name);
  $option=static function($p,$key,$value)use($query,$q){$query('INSERT INTO '.$p.'options (option_name,option_value,autoload) VALUES ('.$q($key).','.$q($value).",'no')");};
  $meta=static function($p,$id,$key,$value)use($query,$q){$query('INSERT INTO '.$p.'usermeta (user_id,meta_key,meta_value) VALUES ('.$id.','.$q($key).','.$q($value).')');};
  $roles=['administrator'=>['name'=>'Administrator','capabilities'=>['read'=>true,'manage_options'=>true]],'subscriber'=>['name'=>'Subscriber','capabilities'=>['read'=>true]]];
  $user=static function($p,$id,$login)use($query,$q){$values=[(string)$id,$login,wp_hash_password('Synthetic private recovery account'),$login,$login.'@example.invalid','','2026-10-07 00:00:00','','0',$login];$query('INSERT INTO '.$p.'users (ID,user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES ('.implode(',',array_map($q,$values)).')');};
  $user($live,7,'fixture-destination-owner');$option($live,$live.'user_roles',serialize($roles));$meta($live,7,$live.'capabilities',serialize(['administrator'=>true]));
  $user($prefix,11,'fixture-source-writer');$option($prefix,$prefix.'user_roles',serialize($roles));$meta($prefix,11,$prefix.'capabilities',serialize(['administrator'=>true]));$meta($prefix,11,'first_name','Writer');$meta($prefix,11,'pk_verified_at','2026-10-01');$meta($prefix,11,'dsa_notification_preferences',serialize(['channels'=>['sms'],'topics'=>['campaigns']]));$meta($prefix,11,'_kiwe_campaign_groups',serialize(['g-1234567890abcdef']));
  foreach(['kiwe_search_connection_v1'=>serialize(['schema'=>1,'status'=>'ready','enabled'=>true,'property'=>'https://source.invalid/','types'=>['web']]),'kiwe_search_credentials_v1'=>'copied-private-provider','kiwe_search_job_v1'=>serialize(['status'=>'queued']),'kiwe_search_store_v1'=>serialize(['schema'=>1]),'stp_report_schema'=>'1','cron'=>serialize(['source_job'=>'queued']),'active_plugins'=>serialize(['source/plugin.php']),'kiwe_seo_metadata_migration_v1'=>serialize(['schema'=>1,'active'=>['id'=>'copied']])]as$key=>$value)$option($prefix,$key,$value);
  $defaults=['schema'=>1,'separator'=>'·','postTypes'=>['post'=>['title'=>'%title% %sep% %sitename%']], 'news'=>['enabled'=>true,'publication'=>'Historical News','language'=>'en']];$option($prefix,'kiwe_seo_defaults_v1',serialize($defaults));
  foreach(['options'=>['option_id','option_name','option_value','autoload'],'users'=>['ID','user_login','user_pass','user_nicename','user_email','user_url','user_registered','user_activation_key','user_status','display_name'],'usermeta'=>['umeta_id','user_id','meta_key','meta_value']]as$suffix=>$cols)$descriptor($suffix,$cols,[$cols[0]],(int)$query('SELECT COUNT(*) FROM '.$prefix.$suffix)[0][0]);
  $create($prefix.'posts',"(ID bigint unsigned NOT NULL,post_author bigint unsigned NOT NULL,post_content text NOT NULL,PRIMARY KEY(ID)) ENGINE=InnoDB");$query('INSERT INTO '.$prefix."posts VALUES(55,11,'Historical article')");$descriptor('posts',['ID','post_author','post_content'],['ID'],1);
  foreach(['postmeta'=>'post_id','termmeta'=>'term_id']as$suffix=>$object){$create($prefix.$suffix,'(meta_id bigint unsigned NOT NULL AUTO_INCREMENT,'.$object.' bigint unsigned NOT NULL,meta_key varchar(255) NOT NULL,meta_value longtext NOT NULL,PRIMARY KEY(meta_id),KEY meta_key(meta_key)) ENGINE=InnoDB');foreach(['_kiwe_seo_title'=>'Historical title','_kiwe_schema_graph_v1'=>serialize([['@type'=>'Article','name'=>'Historical article']]),'_kiwe_seo_migration_backup_v1'=>'copied-undo','_kiwe_seo_migration_job_v1'=>'copied-owner','_kiwe_schema_import_v1'=>'copied-schema-undo']as$key=>$value)$query('INSERT INTO '.$prefix.$suffix.' ('.$object.',meta_key,meta_value) VALUES(55,'.$q($key).','.$q($value).')');$descriptor($suffix,['meta_id',$object,'meta_key','meta_value'],['meta_id'],5);}
  $hash=str_repeat('a',64);$entity=str_repeat('d',64);$url='https://source.invalid/historical-story';$property=str_repeat('c',64);$scope=hash('sha256',$url);
  $facts=[
   'kiwe_search_buckets_v1'=>[$hash,$property,'gsc','web','page','2026-10-01','byPage',str_repeat('b',64),'1',(string)strlen($url),'1790812800'],
   'kiwe_search_rows_v1'=>[hash('sha256',$hash.'|'.$entity),$hash,$property,'gsc','web','page','2026-10-01',$entity,hash('sha256',$url),$url,'','3','40','2.5'],
   'stp_report_days'=>['2026-10-01',$scope,'4','10','2','1','0','1'],
   'stp_report_sessions'=>['2026-10-01',$scope,'77'],
   'stp_report_activity'=>['11','2026-10-01 01:02:03'],
  ];
  foreach($facts as$suffix=>$row){$d=$Owner::reporting_definition($suffix);$cols=array_map(static function($key){$type=match($key){'day'=>'date','last_activity'=>'datetime','label','secondary_label'=>'text','source'=>'varchar(24)','search_type','dataset','aggregation'=>'varchar(16)','position'=>'double','bucket','row_key','property_hash','source_sha','entity','page_hash','scope_hash'=>'char(64) CHARACTER SET ascii COLLATE ascii_bin',default=>'bigint unsigned'};return $key.' '.$type.' NOT NULL';},$d['columns']);$create($prefix.$suffix,'('.implode(',',$cols).',PRIMARY KEY('.implode(',',$d['primary']).')) ENGINE=InnoDB');$query('INSERT INTO '.$prefix.$suffix.' VALUES('.implode(',',array_map($q,$row)).')');$descriptor($suffix,$d['columns'],$d['primary'],1);}
  $create($prefix.'stp_sessions','(id bigint unsigned NOT NULL,payload text NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB');$query('INSERT INTO '.$prefix."stp_sessions VALUES(77,'copied-session-authority')");$descriptor('stp_sessions',['id','payload'],['id'],1);
  $columns=$Owner::redirect_columns();$create($prefix.'kiwe_seo_redirects_v1','(id bigint unsigned NOT NULL,source_hash char(64) NOT NULL,fold_hash char(64) NOT NULL,source_path varchar(2048) NOT NULL,ignore_case tinyint unsigned NOT NULL,destination varchar(2048) NOT NULL,code smallint unsigned NOT NULL,state varchar(12) NOT NULL,source_id bigint unsigned NOT NULL,source_sha char(64) NOT NULL,imported_sha char(64) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB');
  $rule=['8',hash('sha256','c:old-story'),hash('sha256','old-story'),'old-story','0','https://destination.invalid/new-story','301','active','55',str_repeat('a',64),str_repeat('b',64)];$pending=$rule;$pending[0]='9';$pending[3]='pending-story';$pending[1]=hash('sha256','c:pending-story');$pending[2]=hash('sha256','pending-story');$pending[7]='pending';
  foreach([$rule,$pending]as$row)$query('INSERT INTO '.$prefix.'kiwe_seo_redirects_v1 VALUES('.implode(',',array_map($q,$row)).')');$descriptor('kiwe_seo_redirects_v1',$columns,['id'],2);$option($prefix,'kiwe_seo_redirect_store_v1',serialize(['schema'=>1,'enabled'=>true,'observe404'=>true]));
  $spec=['schema'=>$Owner::SCHEMA,'recoverySha256'=>str_repeat('e',64),'destinationOwnerId'=>7,'sourceOwnerId'=>null,'mode'=>'quarantine-copied-authority'];$nativeBefore=[];foreach(['options','users','usermeta']as$suffix)$nativeBefore[$suffix]=$query('SELECT * FROM '.$live.$suffix.' ORDER BY 1');$destination=$Owner::destination($query,$live,$spec);$report=$Owner::apply($query,$tables,$prefix,$live,$spec,$destination);
  $check($query('SELECT option_value FROM '.$prefix.'options WHERE option_name='.$q('kiwe_seo_defaults_v1'))[0][0]===serialize($defaults),'Native inactive recovery preserves exact shared SEO defaults without executing WordPress settings');
  $restoredRule=$rule;$restoredRule[8]='0';$restoredRule[9]=$restoredRule[10]=str_repeat('0',64);
  $check($query('SELECT * FROM '.$prefix.'kiwe_seo_redirects_v1')===[$restoredRule],'Native inactive recovery preserves active mapped exact redirects without copied undo authority');
  $check($report['preservedRedirectTables']===[['suffix'=>'kiwe_seo_redirects_v1','activeRules'=>1,'pendingRulesRetired'=>1,'copiedImportAuthority'=>false]],'Native recovered redirect receipt distinguishes authored active rules from interrupted pending work');
  $check($query('SELECT option_value FROM '.$prefix.'options WHERE option_name='.$q('kiwe_seo_redirect_store_v1'))[0][0]===serialize(['schema'=>1,'enabled'=>true,'observe404'=>false]),'Native recovered redirect configuration retains its choice and pauses copied observation work');
  foreach($facts as$suffix=>$row)$check($query('SELECT * FROM '.$prefix.$suffix)===[$row],'Native inactive recovery retains exact source-labelled history: '.$suffix);
  $state=unserialize($query('SELECT option_value FROM '.$prefix.'options WHERE option_name='.$q('kiwe_restored_reporting_v1'))[0][0],['allowed_classes'=>false]);
  $check($state===['schema'=>1,'recoverySha256'=>$spec['recoverySha256'],'tables'=>array_keys($facts),'identityMapped'=>false],'Native copied history has a durable unmapped-source marker beyond first bootstrap');
  $guard=DSA\Analytics\Restored_Reporting_State::class;$prior=get_option($guard::OPTION,null);$priorSearch=get_option(DSA\Analytics\Search_Performance_Store::OPTION,null);$priorSecure=get_option('stp_report_schema',null);
  try{update_option($guard::OPTION,$state,false);update_option(DSA\Analytics\Search_Performance_Store::OPTION,['schema'=>1],false);update_option('stp_report_schema','1',false);$check(!DSA\Analytics\Search_Performance_Store::ready()&&!DSA\Secure\SecureTrack_Report_Service::enabled(),'Native readiness markers cannot reclassify copied summary or search facts as destination activity');$check(is_wp_error(DSA\Analytics\Search_Performance_Store::prepare()),'Explicit native search preparation refuses unmapped copied history before any DDL or connection enrollment');}
  finally{foreach([$guard::OPTION=>$prior,DSA\Analytics\Search_Performance_Store::OPTION=>$priorSearch,'stp_report_schema'=>$priorSecure]as$key=>$value)if($value===null)delete_option($key);else update_option($key,$value,false);}
  $check(count($report['preservedReportingTables'])===5&&!array_filter($report['preservedReportingTables'],static fn($v)=>$v['mode']!=='inactive-source-history'||$v['sourceIdentifiersMapped']!==false),'Native ownership receipt labels preserved history without destination mapping claims');
  foreach(['kiwe_search_connection_v1','kiwe_search_credentials_v1','kiwe_search_job_v1','kiwe_search_store_v1','stp_report_schema','kiwe_seo_metadata_migration_v1']as$key)$check(!$query('SELECT option_name FROM '.$prefix.'options WHERE option_name='.$q($key)),'Native preserved history cannot activate copied provider or execution state: '.$key);
  $check($query('SELECT COUNT(*) FROM '.$prefix.'stp_sessions')[0][0]==='0','Native copied session authority is quarantined while summary facts remain');
  $check($query('SELECT post_author,post_content FROM '.$prefix.'posts WHERE ID=55')[0]===['11','Historical article'],'Native recovery bridge retains authored content and original author relationship');
  $kept=$query('SELECT meta_key,meta_value FROM '.$prefix.'postmeta ORDER BY BINARY meta_key');$check(count($kept)===2&&$kept[0][0]==='_kiwe_schema_graph_v1'&&$kept[1]===['_kiwe_seo_title','Historical title'],'Native schema and SEO facts survive without copied undo authority');
  $check($query('SELECT COUNT(*) FROM '.$prefix."termmeta WHERE meta_key IN ('_kiwe_seo_migration_backup_v1','_kiwe_seo_migration_job_v1','_kiwe_schema_import_v1')")[0][0]==='0','Native taxonomy restore removes copied metadata and schema undo journals');
  $check(!$query('SELECT meta_key FROM '.$prefix."usermeta WHERE meta_key IN ('pk_verified_at','dsa_notification_preferences')")&&$query('SELECT meta_value FROM '.$prefix."usermeta WHERE user_id=11 AND meta_key='_kiwe_account_suspended'")[0][0]==='1','Native historical activity never restores verified contact or campaign consent');
  $check($query('SELECT meta_value FROM '.$prefix."usermeta WHERE user_id=11 AND meta_key='_kiwe_campaign_groups'")[0][0]===serialize(['g-1234567890abcdef']),'Native organization relationships survive with fresh delivery consent required');
  foreach($nativeBefore as$suffix=>$rows)$check($query('SELECT * FROM '.$live.$suffix.' ORDER BY 1')===$rows,'Native prepared destination remains exact: '.$suffix);
 }finally{foreach(array_reverse($created)as$name)$query('DROP TEMPORARY TABLE '.$name);$check(count($created)===16,'All sixteen exact private recovery fixture tables are retired');}
}
