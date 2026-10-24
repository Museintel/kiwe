<?php
/** CLI-only native SQL/OAuth-contract fixture; Google responses are synthetic and outbound work is blocked. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||KIWE_SEO_NATIVE_TEST!==true)throw new RuntimeException('Owned native search fixture required.');

function kiwe_search_test_isolate():void{
	kiwe_seo_test_isolate();global $wpdb;
	$extras=[$wpdb->prefix.'rank_math_analytics_gsc',DSA\Analytics\Search_Performance_Store::table('buckets'),DSA\Analytics\Search_Performance_Store::table('rows')];
	$GLOBALS['kiwe_search_original']=[];$GLOBALS['kiwe_search_created']=[];
	foreach($extras as $t){$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;$GLOBALS['kiwe_search_original'][$t]=$exists?hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$t.' ORDER BY 1',ARRAY_A))):null;}
	remove_filter('query',$GLOBALS['kiwe_seo_test_write_guard'],PHP_INT_MAX);$allow=array_merge(array_values($GLOBALS['kiwe_seo_test_tables']),$extras);
	$GLOBALS['kiwe_seo_test_write_guard']=static function($sql)use($allow,$extras){
		if(preg_match('/^SHOW TABLES LIKE /i',$sql))foreach($GLOBALS['kiwe_search_created'] as $t)if(str_contains(str_replace('\\','',$sql),$t))return "SELECT '".$t."'";
		if(preg_match('/^CREATE TABLE ([A-Za-z0-9_]+)/i',$sql,$m)&&in_array($m[1],$extras,true)){$GLOBALS['kiwe_search_created'][$m[1]]=$m[1];$sql=preg_replace('/^CREATE TABLE /i','CREATE TEMPORARY TABLE ',$sql,1);}
		if(preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|ALTER\s+TABLE|DROP(?:\s+TEMPORARY)?\s+TABLE|TRUNCATE(?:\s+TABLE)?|CREATE(?:\s+TEMPORARY)?\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?([A-Za-z0-9_]+)/i',$sql,$m)&&!in_array($m[1],$allow,true))throw new RuntimeException('Unowned native write refused: '.$m[1]);
		if(preg_match('/^\s*(?:CREATE|DROP)\s+TABLE/i',$sql))throw new RuntimeException('Permanent native DDL refused.');return $sql;
	};add_filter('query',$GLOBALS['kiwe_seo_test_write_guard'],PHP_INT_MAX);
	foreach([DSA\Analytics\Search_Performance_Service::OPTION,DSA\Analytics\Search_Performance_Service::SECRET,DSA\Analytics\Search_Performance_Service::JOB,DSA\Analytics\Search_Performance_Service::STATE,DSA\Analytics\Search_Performance_Store::OPTION] as $key)delete_option($key);
	update_option('stp_settings',['track_pages'=>0,'track_visitors'=>0],false);
	$GLOBALS['search_http_handler']=static fn()=>new WP_Error('synthetic_http','No synthetic provider response configured.');$GLOBALS['search_http_requests']=[];
	add_filter('pre_http_request',static function($prior,$args,$url){$GLOBALS['search_http_requests'][]=['url'=>$url,'method'=>$args['method']??'GET','args'=>$args];return ($GLOBALS['search_http_handler'])($url,$args);},PHP_INT_MAX,3);
}
function kiwe_search_test_run():array{
	global $wpdb,$wp_rewrite;
	$proof=['schema'=>'kiwe.search-performance.native.v1','passed'=>false,'cleanup'=>false,'providerAccepted'=>false,'checks'=>[],'observations'=>[]];$GLOBALS['kiwe_seo_test_partial_proof']=&$proof;
	$assert=static function($ok,$label)use(&$proof){$proof['checks'][$label]=(bool)$ok;kiwe_seo_test_progress($proof);if(!$ok)throw new RuntimeException($label);};
	$service=new DSA\Analytics\Search_Performance_Service();$source=DSA\Analytics\Search_Performance_Service::class;$store=DSA\Analytics\Search_Performance_Store::class;$model=DSA\Analytics\Search_Performance_Model::class;
	$request=static function($method='GET',$operation='',$fields=[],$mutation=true,$expected=null)use($source){$r=new WP_REST_Request($method);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('Content-Type','application/json');if($mutation)$r->set_header('X-Kiwe-Mutation','1');if($method==='POST')$r->set_body(wp_json_encode(['operation'=>$operation,'confirmed'=>true,'expected'=>$expected??$source::expected(),...$fields]));return $r;};
	$http=static fn($data,$status=200,$headers=[])=>['headers'=>$headers,'body'=>wp_json_encode($data),'response'=>['code'=>$status,'message'=>'Synthetic'],'cookies'=>[],'filename'=>null];
	try{
		foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url','transition_post_status','publish_post'] as $h)remove_all_actions($h);
		$seed=static function($name,$role)use($wpdb){$wpdb->insert($wpdb->users,['user_login'=>'synthetic-search-'.$name,'user_pass'=>wp_hash_password(wp_generate_password(24)),'user_email'=>$name.'@example.test','display_name'=>'Synthetic '.$name,'user_registered'=>current_time('mysql',true)]);$id=(int)$wpdb->insert_id;update_user_meta($id,$wpdb->prefix.'capabilities',[$role=>true]);clean_user_cache($id);return $id;};
		$owner=$seed('manager','administrator');$author=$seed('writer','author');$subscriber=$seed('reader','subscriber');wp_set_current_user($owner);
		$assert(current_user_can('manage_options'),'Native WordPress manager authority remains required');
		$trace=[];$filter=static function($sql)use(&$trace){$trace[]=$sql;return $sql;};add_filter('query',$filter,999998);$before=count($GLOBALS['search_http_requests']);
		$read=$service->read($request());$report=$source::report(30);remove_filter('query',$filter,999998);
		$assert($read->get_status()===200&&$read->get_data()['configured']===false&&$report['available']===false,'Missing search setup displays unavailable rather than invented metrics');
		$assert(count($GLOBALS['search_http_requests'])===$before&&!array_filter($trace,static fn($q)=>preg_match('/^(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)/i',$q)),'Disabled search reads perform no provider work writes or schema setup');
		$assert($service->mutate($request('POST','prepare',[],false))->get_status()===403,'Native mutation proof is required before storage setup');
		wp_set_current_user($author);$assert($service->read($request())->get_status()===403,'Authors cannot read or manage provider credentials');
		wp_set_current_user($subscriber);$assert($service->mutate($request('POST','prepare'))->get_status()===403,'Subscribers cannot prepare reporting storage');wp_set_current_user($owner);
		$assert($service->mutate($request('POST','prepare'))->get_status()===200&&$store::ready(),'Manager prepares exact transactional search tables');
		$assert($store::prepare()===true,'Repeated storage preparation validates native indexes and schema');
		$assert(count($GLOBALS['kiwe_search_created'])===2,'Storage preparation creates only the two owned temporary reporting tables');
		$driftTable=$store::table('rows');$wpdb->query("ALTER TABLE {$driftTable} MODIFY position decimal(10,2) NULL");
		$assert(is_wp_error($store::prepare())&&!$store::ready(),'Unknown native numeric schema revokes readiness without rewriting stored facts');
		$wpdb->query("ALTER TABLE {$driftTable} MODIFY position double NULL");$assert($store::prepare()===true,'An explicitly repaired exact native schema can regain readiness');
		$c=$service->mutate($request('POST','configure',['clientId'=>'synthetic-search-client.apps.googleusercontent.com','clientSecret'=>'SYNTHETIC_SEARCH_SECRET_NOT_A_REAL_GRANT']));
		$assert($c->get_status()===200&&DSA\Security\Secret_Store::is_encrypted(get_option($source::SECRET)),'Website credentials are retained encrypted and non-autoloaded');
		$assert(!str_contains(wp_json_encode($source::status()),'SYNTHETIC_SEARCH_SECRET')&&!in_array($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",$source::SECRET)),wp_autoload_values_to_autoload(),true),'Status never returns credential material and secret storage stays out of autoload');
		$assert($service->mutate($request('POST','pause',[],true,str_repeat('0',64)))->get_status()===409,'Stale connection checkpoints are refused');
		$begin=$service->mutate($request('POST','authorize'));parse_str(wp_parse_url($begin->get_data()['authorizeUrl'],PHP_URL_QUERY),$oauth);
		$assert($oauth['scope']===$model::SCOPE&&$oauth['code_challenge_method']==='S256'&&$oauth['access_type']==='offline','Authorization uses only read-only scope with state and PKCE');
		$assert(is_wp_error($service->complete(['state'=>str_repeat('f',64),'code'=>'SYNTHETIC_CODE'])),'Wrong OAuth state cannot exchange a code');
		wp_set_current_user($author);$assert(is_wp_error($service->complete(['state'=>$oauth['state'],'code'=>'SYNTHETIC_CODE'])),'Another native actor cannot consume a manager authorization');wp_set_current_user($owner);
		$pending=json_decode(DSA\Security\Secret_Store::decrypt(get_option($source::STATE)),true);
		$assert(hash('sha256',$oauth['state'])===$pending['hash']&&!str_contains(get_option($source::STATE),$pending['verifier']),'OAuth pending verifier is encrypted and actor session bound');
		$GLOBALS['search_http_handler']=static function($url,$args)use($http,$source,$model,$oauth,$pending){
			if($url==='https://oauth2.googleapis.com/token'){if(($args['body']['code_verifier']??'')!==$pending['verifier'])return new WP_Error('synthetic_pkce','Verifier mismatch.');return $http(['access_token'=>'SYNTHETIC_ACCESS_GRANT','refresh_token'=>'SYNTHETIC_REFRESH_GRANT','token_type'=>'Bearer','expires_in'=>3600,'scope'=>$model::SCOPE]);}
			return $http(['siteEntry'=>[['siteUrl'=>home_url('/'),'permissionLevel'=>'siteOwner'],['siteUrl'=>'https://unrelated.example.test/','permissionLevel'=>'siteOwner'],['siteUrl'=>'sc-domain:hostingersite.com','permissionLevel'=>'siteUnverifiedUser']]]);
		};
		$assert($service->complete(['state'=>$oauth['state'],'code'=>'SYNTHETIC_CODE'])===true,'Native callback confirms an encrypted background grant and website property permissions');
		$before=count($GLOBALS['search_http_requests']);$assert(is_wp_error($service->complete(['state'=>$oauth['state'],'code'=>'SYNTHETIC_CODE']))&&count($GLOBALS['search_http_requests'])===$before,'Consumed OAuth response cannot replay or repeat provider work');
		$assert(count($source::status()['properties'])===1&&$proof['providerAccepted']===false,'Synthetic grant evidence is never labelled actual provider acceptance');
		$assert($service->mutate($request('POST','select',['property'=>'https://unrelated.example.test/','types'=>['web']]))->get_status()===409,'Unrelated property cannot be selected');
		$assert($service->mutate($request('POST','select',['property'=>home_url('/'),'types'=>['web','news']]))->get_status()===200,'Authorized website property and explicit search types save');
		$assert($service->mutate($request('POST','start',['days'=>1]))->get_status()===200&&wp_next_scheduled($source::HOOK),'Synchronization saves a bounded native cron checkpoint');
		$run=static function()use($source,$service){$job=get_option($source::JOB);$job['nextAt']=0;update_option($source::JOB,$job,false);wp_clear_scheduled_hook($source::HOOK);$service->tick();return get_option($source::JOB);};
		$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['siteUrl'=>home_url('/'),'permissionLevel'=>'siteOwner']);$job=$run();$assert($job['phase']==='query','Background permission recheck advances without fetching page content');
		$deadline=$job['date'];$calls=0;
		$GLOBALS['search_http_handler']=static function($url,$args)use($http,&$calls){$calls++;$q=json_decode($args['body'],true);return $http(['responseAggregationType'=>$q['aggregationType'],'rows'=>[['clicks'=>5,'impressions'=>50,'ctr'=>0.1,'position'=>2.5]]]);};
		$job=$run();$assert($calls===1&&$job['datasetIndex']===1,'Each background step performs one search query and publishes one property bucket');
		$a=$source::report(7);$assert($a['current']['metrics']['clicks']===5&&$a['current']['complete']===false,'Missing provider dates retain observations but withhold complete-period comparisons');
		$pages=[];for($i=0;$i<20;$i++)$pages[]=['keys'=>[home_url('/page-'.$i.'/')],'clicks'=>1,'impressions'=>10,'ctr'=>0.1,'position'=>2+$i/10];
		$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['responseAggregationType'=>'byPage','rows'=>$pages]);$job=$run();
		$assert($job['offset']===20&&count($job['pending'])===20,'First detail page is checkpointed without exposing an incomplete bucket');
		$assert($store::period(home_url('/'),'gsc','web',$deadline,$deadline,home_url('/page-0/'))['available']===false,'Unpublished detail is invisible to report readers');
		$pages2=[];for($i=20;$i<40;$i++)$pages2[]=['keys'=>[home_url('/page-'.$i.'/')],'clicks'=>1,'impressions'=>10,'ctr'=>0.1,'position'=>2+$i/10];
		$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['responseAggregationType'=>'byPage','rows'=>$pages2]);$job=$run();
		$assert($job['offset']===0&&$job['datasetIndex']===2,'Interrupted pagination resumes at the saved offset and stops at forty detail rows');
		$p=$store::period(home_url('/'),'gsc','web',$deadline,$deadline,home_url('/page-0/'));$assert($p['available']&&$p['metrics']['clicks']===1&&!$p['complete'],'Scoped page facts are indexed and explicitly incomplete');
		$assert(!$store::period(home_url('/'),'gsc','web',$deadline,$deadline,home_url('/missing-page/'))['available'],'A page missing from top detail is unavailable rather than zero');
		$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['error'=>'quota'],429,['retry-after'=>'1200']);$before=count($GLOBALS['search_http_requests']);$job=$run();
		$assert($job['reason']==='kiwe_search_quota'&&$job['nextAt']>=time()+1200&&count($GLOBALS['search_http_requests'])===$before+1,'Provider quota uses retained exponential backoff and Retry-After without a retry loop');
		$before=count($GLOBALS['search_http_requests']);$service->tick();$assert(count($GLOBALS['search_http_requests'])===$before,'A scheduled future retry cannot be drained early');
		$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['error'=>'expired'],401);$job=$run();$assert($job['reason']==='kiwe_search_expired','Expired access is recorded for later refresh');
		$grant=json_decode(DSA\Security\Secret_Store::decrypt(get_option($source::SECRET)),true);$assert($grant['expires']===0,'Expired access is invalidated without dropping the refresh grant');
		$refreshes=0;$GLOBALS['search_http_handler']=static function($url,$args)use($http,$model,&$refreshes){if(str_ends_with($url,'/token')){$refreshes++;return $http(['access_token'=>'SYNTHETIC_REFRESHED_ACCESS','token_type'=>'Bearer','expires_in'=>3600,'scope'=>$model::SCOPE]);}return $http(['responseAggregationType'=>'byProperty','rows'=>[]]);};$job=$run();
		$assert($refreshes===1&&$job['typeIndex']===1,'Later background step refreshes once and resumes the same query checkpoint');
		$savedGrant=get_option($source::SECRET);$grant=json_decode(DSA\Security\Secret_Store::decrypt($savedGrant),true);$grant['expires']=0;update_option($source::SECRET,DSA\Security\Secret_Store::encrypt(wp_json_encode($grant)),false);
		$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['error'=>'invalid_grant'],400);$before=count($GLOBALS['search_http_requests']);$job=$run();
		$assert($job['reason']==='kiwe_search_revoked'&&!$source::status()['enabled']&&$source::status()['status']==='reconnect'&&count($GLOBALS['search_http_requests'])===$before+1,'A revoked refresh grant pauses background work after one exchange and preserves saved facts');
		$before=count($GLOBALS['search_http_requests']);$service->tick();$assert(count($GLOBALS['search_http_requests'])===$before,'A revoked grant cannot enter an automatic retry loop');
		update_option($source::SECRET,$savedGrant,false);$restored=$source::connection();$restored['enabled']=true;$restored['status']='ready';update_option($source::OPTION,$restored,false);
		$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['error'=>['errors'=>[['reason'=>'insufficientPermissions']]]],403);$job=$run();
		$assert(!$source::status()['enabled']&&$source::status()['status']==='permission'&&$source::report(7)['available'],'Revoked property permission pauses synchronization while retaining stored evidence');
		$before=count($GLOBALS['search_http_requests']);$read=$service->read($request());$source::report(7);$assert(count($GLOBALS['search_http_requests'])===$before,'Connected dashboard reads never refresh grants or query Google');
		$nativeTable=$store::table('rows');$beforeRows=hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$nativeTable} ORDER BY row_key",ARRAY_A)));
		$one=[['day'=>$deadline,'entity'=>hash('sha256',''),'label'=>'','secondary'=>'','clicks'=>7,'impressions'=>70,'position'=>1.5]];
		$assert(is_wp_error($store::put(home_url('/'),'gsc','web','site',$deadline,'byProperty',$one,hash('sha256','x'))),'Native facts cannot be published without writer ownership');
		$put=static fn($rows)=>$store::lock(static fn()=>$store::put(home_url('/'),'gsc','web','site',$deadline,'byProperty',$rows,hash('sha256',wp_json_encode($rows))));
		$assert(!is_wp_error($put($one))&&!is_wp_error($put($one))&&$store::period(home_url('/'),'gsc','web',$deadline,$deadline)['metrics']['clicks']===7,'Repeated publication replaces the same bucket instead of adding duplicate counts');
		$beforeRows=hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$nativeTable} ORDER BY row_key",ARRAY_A)));$interrupt=static function($sql)use($nativeTable){if(str_starts_with($sql,'INSERT INTO '.$nativeTable))throw new RuntimeException('Synthetic interruption before reporting insert');return $sql;};add_filter('query',$interrupt,999999);$failed=$put([['day'=>$deadline,'entity'=>hash('sha256',''),'label'=>'','secondary'=>'','clicks'=>9,'impressions'=>90,'position'=>4.5]]);remove_filter('query',$interrupt,999999);
		$assert(is_wp_error($failed)&&$beforeRows===hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$nativeTable} ORDER BY row_key",ARRAY_A))),'Interrupted publication rolls back deletion and preserves the exact previous facts');
		$second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$keyMethod=new ReflectionMethod($store,'lock_key');$key=$keyMethod->invoke(null);$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$key));$time=microtime(true);$busy=$store::lock(static fn()=>true);$elapsed=microtime(true)-$time;
		$originalDb=$wpdb->dbname;try{$wpdb->dbname=$originalDb.'_synthetic_other_site';$otherSite=$store::lock(static fn()=>true);}finally{$wpdb->dbname=$originalDb;}$assert($otherSite===true,'Different database identities with the same prefix do not compete for a shared-server lock');
		$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$key));$second->close();$assert(is_wp_error($busy)&&$elapsed<0.25,'Another database connection cannot force a writer wait');
		$range=$model::range(7);$rows=[];for($i=0;$i<14;$i++){$d=(new DateTimeImmutable($range['to']))->modify('-'.$i.' days')->format('Y-m-d');$row=[['day'=>$d,'entity'=>hash('sha256',''),'label'=>'','secondary'=>'','clicks'=>1,'impressions'=>10,'position'=>$i<7?2.5:3.5]];$result=$store::lock(static fn()=>$store::put(home_url('/'),'gsc','web','site',$d,'byProperty',$row,hash('sha256',$d)));if(is_wp_error($result))throw new RuntimeException('Synthetic complete-period publication failed');}
		$complete=$source::report(7);$assert($complete['current']['complete']&&$complete['previous']['complete']&&$complete['current']['metrics']['position']===2.5,'Complete current and previous provider periods preserve weighted positions');
		$query='Synthetic query <script>';for($i=0;$i<2;$i++){$d=(new DateTimeImmutable($deadline))->modify('-'.$i.' days')->format('Y-m-d');$queryRows=[['day'=>$d,'entity'=>hash('sha256',$query),'label'=>$query,'secondary'=>'','clicks'=>1+$i,'impressions'=>10+20*$i,'position'=>2+2*$i]];$qput=$store::lock(static fn()=>$store::put(home_url('/'),'gsc','web','query',$d,'byProperty',$queryRows,hash('sha256',$d.'query')));if(is_wp_error($qput))throw new RuntimeException('Synthetic query publication failed');}
		$queries=$source::report(7)['topQueries'];$assert(count($queries)===1&&$queries[0]['query']===$query&&$queries[0]['clicks']===3&&$queries[0]['impressions']===40&&abs($queries[0]['position']-3.5)<0.00001,'Stored top queries use bounded aggregation and weighted positions without interpreting labels');
		$assert(!isset($source::report(7,home_url('/page-0/'))['topQueries']),'Property query text is excluded from individual record reports');
		$postId=wp_insert_post(['post_title'=>'Synthetic search context','post_type'=>'post','post_status'=>'draft','post_author'=>$author],true);if(is_wp_error($postId))throw new RuntimeException('Synthetic native post creation failed');$postUrl=get_permalink($postId);
		$newsRows=[['day'=>$deadline,'entity'=>hash('sha256',$postUrl),'label'=>$postUrl,'secondary'=>'','clicks'=>3,'impressions'=>30,'position'=>4.25]];$nput=$store::lock(static fn()=>$store::put(home_url('/'),'gsc','news','page',$deadline,'byPage',$newsRows,hash('sha256','news')));if(is_wp_error($nput))throw new RuntimeException('Synthetic news publication failed');
		$before=count($GLOBALS['search_http_requests']);$reports=new DSA\Analytics\Analytics_Report_Service();$news=$reports->report('post',(int)$postId,7,'news');$searchSection=array_values(array_filter($news['sections'],static fn($s)=>$s['key']==='search_current'));
		$assert($news['searchPerformance']['type']==='news'&&$searchSection[0]['metrics'][0]['value']===3.0&&$searchSection[0]['metrics'][0]['previous']===null&&count($GLOBALS['search_http_requests'])===$before,'The shared native record report reads only its chosen search type and withholds incomplete comparisons');
		$analytics=new DSA\Access\Analytics_Workspace_Service();wp_set_current_user($author);$ar=$request();$ar->set_query_params(['scope'=>'post','id'=>(int)$postId,'days'=>7,'type'=>'news']);$authorized=$analytics->read($ar);$ar->set_query_params(['scope'=>'post','id'=>(int)$postId,'days'=>7,'type'=>'web']);$web=$analytics->read($ar);
		$assert($authorized->get_status()===200&&$authorized->get_data()['searchSourceUrl']===''&&$authorized->get_data()['searchType']==='news'&&$authorized->get_data()['expected']!==$web->get_data()['expected'],'An author can read their own typed report without provider controls or interchangeable review checkpoints');
		wp_set_current_user($subscriber);$assert($analytics->read($ar)->get_status()===403,'A subscriber cannot read a private record search report');wp_set_current_user($owner);
		$buckets=$store::table('buckets');$wpdb->query("UPDATE {$buckets} SET row_count=60001 WHERE search_type='web' AND dataset='site' LIMIT 1");$assert(is_wp_error($put($one)),'Cardinality admission refuses an oversized existing budget without deleting reports');$wpdb->query("UPDATE {$buckets} SET row_count=1 WHERE row_count=60001");
		$expired=$model::today()->modify('-191 days')->format('Y-m-d');$old=[['day'=>$expired,'entity'=>hash('sha256',''),'label'=>'','secondary'=>'','clicks'=>0,'impressions'=>0,'position'=>null]];$store::lock(static fn()=>$store::put(home_url('/'),'gsc','web','site',$expired,'byProperty',$old,hash('sha256',$expired)));$store::lock(static fn()=>$store::prune());
		$assert(!$store::period(home_url('/'),'gsc','web',$expired,$expired)['available']&&$store::period(home_url('/'),'gsc','web',$deadline,$deadline)['available'],'Indexed bounded retention removes expired facts while keeping current reports');
		$sourceTable=$wpdb->prefix.'rank_math_analytics_gsc';$wpdb->query("CREATE TEMPORARY TABLE {$sourceTable}(id bigint(20) unsigned NOT NULL AUTO_INCREMENT,created timestamp NOT NULL,query varchar(1000) NOT NULL,page varchar(500) NOT NULL,clicks mediumint(6) NOT NULL,impressions mediumint(6) NOT NULL,position double NOT NULL,ctr double NOT NULL,PRIMARY KEY(id),KEY analytics_query(query(190)),KEY analytics_page(page(190)),KEY clicks(clicks),KEY rank_position(position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$GLOBALS['kiwe_search_created'][$sourceTable]=$sourceTable;
		for($i=1;$i<=250;$i++)$wpdb->insert($sourceTable,['created'=>$deadline.' 00:00:00','query'=>'Synthetic term '.$i,'page'=>'/article/','clicks'=>1,'impressions'=>10,'position'=>2.25,'ctr'=>0.1]);
		$rawHash=hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$sourceTable} ORDER BY id",ARRAY_A)));
		$assert($service->mutate($request('POST','import'))->get_status()===200,'Historical import accepts the supported source schema without provider credentials');
		$before=count($GLOBALS['search_http_requests']);$job=$run();$assert($job['cursor']===100&&$job['imported']===100&&!$job['done'],'Historical keyset import stops at one hundred rows and retains its checkpoint');
		$job=$run();$job=$run();$assert($job['cursor']===250&&$job['imported']===250&&$job['done']&&count($GLOBALS['search_http_requests'])===$before,'Interrupted historical work resumes in bounded SQL steps without contacting Google');
		$history=$source::report(7,home_url('/article/'));$assert($history['history']['metrics']['clicks']===250&&$history['history']['metrics']['position']===2.25&&!$history['history']['complete'],'Imported page/query history is separate scoped lower-bound evidence with fractional positions');
		$assert($service->mutate($request('POST','import'))->get_status()===200,'Repeated legacy import starts a reviewed deterministic replay');$run();$run();$run();$history2=$source::report(7,home_url('/article/'));$assert($history2['history']['metrics']===$history['history']['metrics'],'Repeated historical import does not multiply source evidence');
		$assert($rawHash===hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$sourceTable} ORDER BY id",ARRAY_A))),'All Rank Math source rows remain byte-exact after imports');
		$wpdb->query("ALTER TABLE {$sourceTable} ADD unsupported text NULL");$assert($service->mutate($request('POST','import'))->get_status()===409,'Extended historical source behavior is refused before materialization');$wpdb->query("ALTER TABLE {$sourceTable} DROP unsupported");
		$explain=$wpdb->get_results($wpdb->prepare("EXPLAIN SELECT clicks FROM {$nativeTable} WHERE property_hash=%s AND source='gsc' AND search_type='web' AND dataset='page' AND page_hash=%s AND day>=%s AND day<=%s",hash('sha256',home_url('/')),hash('sha256',home_url('/page-0/')),$range['from'],$range['to']),ARRAY_A);$assert(in_array($explain[0]['key']??null,['page_period','period'],true),'Actual SQL planner uses the indexed scoped reporting path');$proof['observations']['pageReportPlan']=array_intersect_key($explain[0],array_flip(['type','key','rows','Extra']));
		$choices=DSA\Backup\Search_Performance_Snapshot_Service::read();$assert($choices===['schema'=>1,'types'=>['web','news']]&&!str_contains(wp_json_encode($choices),'SYNTHETIC'),'Native backup projection captures typed choices and no provider authority');
		$capture=new ReflectionMethod(DSA\Backup\Configuration_Snapshot_Service::class,'capture');$projection=$capture->invoke(null,[DSA\Backup\Search_Performance_Snapshot_Service::NAME]);$assert(isset(DSA\Backup\Configuration_Snapshot_Service::option_groups()['Search reporting'])&&$projection[DSA\Backup\Search_Performance_Snapshot_Service::NAME]['value']===$choices,'Existing native encrypted snapshot dispatcher projects the search choices adapter');
		DSA\Backup\Search_Performance_Snapshot_Service::write(['exists'=>true,'value'=>['schema'=>1,'types'=>['web']]]);$assert(!$source::status()['enabled']&&!wp_next_scheduled($source::HOOK)&&DSA\Backup\Search_Performance_Snapshot_Service::read()===['schema'=>1,'types'=>['web']],'Reviewed same-site choice recovery pauses synchronization and cancels copied work');
		$assert(!DSA\Backup\Portable_Recovery_Ownership::option($source::SECRET)&&!DSA\Backup\Portable_Recovery_Ownership::option($source::OPTION)&&!DSA\Backup\Portable_Recovery_Ownership::option($source::JOB),'Inactive destination policy refuses copied provider credentials grants and jobs');
		$assert($service->mutate($request('POST','disconnect'))->get_status()===200&&get_option($source::SECRET,false)===false&&$source::report(7)['available'],'Disconnect retires local provider authority without deleting saved evidence');
		$assert(!$source::status()['configured']&&!$source::status()['connected'],'Saved reporting choices after disconnect do not masquerade as provider credentials');
		$historyOnly=$service->mutate($request('POST','import'));$assert($historyOnly->get_status()===200&&!$source::status()['configured']&&!$source::status()['connected'],'Historical import without a grant cannot expose a configured Google connection');
		$service->mutate($request('POST','configure',['clientId'=>'synthetic-search-client.apps.googleusercontent.com','clientSecret'=>'SYNTHETIC_SEARCH_SECRET_NOT_A_REAL_GRANT']));$begin=$service->mutate($request('POST','authorize'));parse_str(wp_parse_url($begin->get_data()['authorizeUrl'],PHP_URL_QUERY),$deniedState);$before=count($GLOBALS['search_http_requests']);
		$assert(is_wp_error($service->complete(['state'=>$deniedState['state'],'error'=>'access_denied']))&&get_option($source::STATE,false)===false&&count($GLOBALS['search_http_requests'])===$before,'Declined consent consumes its pending state without exchanging a code');
		foreach([[$model::SCOPE.' https://www.googleapis.com/auth/drive'],[['malformed']]] as $i=>$scope){$begin=$service->mutate($request('POST','authorize'));parse_str(wp_parse_url($begin->get_data()['authorizeUrl'],PHP_URL_QUERY),$badState);$GLOBALS['search_http_handler']=static fn($url,$args)=>$http(['access_token'=>'SYNTHETIC_TOO_BROAD_GRANT','refresh_token'=>'SYNTHETIC_REFRESH_GRANT','token_type'=>'Bearer','expires_in'=>3600,'scope'=>$scope[0]]);$assert(is_wp_error($service->complete(['state'=>$badState['state'],'code'=>'SYNTHETIC_CODE']))&&!$source::status()['connected'],'Broader or malformed OAuth scope cannot become connection authority '.$i);}
		$service->mutate($request('POST','disconnect'));
		$assert(!array_filter($GLOBALS['search_http_requests'],static fn($r)=>($r['args']['timeout']??0)!==4||($r['args']['redirection']??-1)!==0||($r['args']['limit_response_size']??0)!==131072||($r['args']['sslverify']??null)!==true),'Every native provider request uses fixed time response redirect and TLS boundaries');
		$proof['observations']['providerCalls']=count($GLOBALS['search_http_requests']);$proof['observations']['provider']='Synthetic HTTP contract, no actual configured Google grant';$proof['passed']=true;
	}catch(Throwable $e){$proof['error']=$e->getMessage();$proof['line']=$e->getLine();}
	finally{wp_clear_scheduled_hook($source::HOOK);remove_all_actions('shutdown');kiwe_search_test_cleanup($proof);kiwe_seo_test_progress($proof);}
	$proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);$proof['checkCount']=count($proof['checks']);return $proof;
}
function kiwe_search_test_cleanup(array &$proof):void{
	global $wpdb;$clean=true;
	foreach($GLOBALS['kiwe_search_created']??[] as $t){if(!isset($GLOBALS['kiwe_search_original'][$t])&& !array_key_exists($t,$GLOBALS['kiwe_search_original'])){$clean=false;continue;}if(false===$wpdb->query('DROP TEMPORARY TABLE '.$t))$clean=false;}
	$GLOBALS['kiwe_search_created']=[];
	foreach($GLOBALS['kiwe_search_original']??[] as $t=>$sha){$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;$same=$sha===null?!$exists:$exists&&$sha===hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$t.' ORDER BY 1',ARRAY_A)));$proof['checks']['Original extra '.$t.' preserved']=$same;$clean=$clean&&$same;}
	kiwe_seo_test_cleanup($proof);$proof['cleanup']=$proof['cleanup']&&$clean;
}
