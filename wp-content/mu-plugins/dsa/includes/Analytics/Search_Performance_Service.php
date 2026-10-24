<?php
namespace DSA\Analytics;

use DSA\Security\Secret_Store;
use DSA\Utilities\Origin_Checker;
use DSA\Access\Task_Workspace_UI as UI;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Website-owned, read-only provider. Reads show stored facts; only owned background work synchronizes. */
final class Search_Performance_Service {
	public const OPTION = 'kiwe_search_connection_v1';
	public const SECRET = 'kiwe_search_credentials_v1';
	public const JOB = 'kiwe_search_job_v1';
	public const STATE = 'kiwe_search_oauth_state_v1';
	public const HOOK = 'kiwe_search_background_v1';
	public function register(): void {
		add_action('rest_api_init',[$this,'routes']);add_action('admin_post_kiwe_search_oauth',[$this,'callback']);add_action(self::HOOK,[$this,'tick']);
	}
	public function routes(): void { register_rest_route('dsa/v1','/workspace/analytics/search-source',[
		['methods'=>'GET','callback'=>[$this,'read'],'permission_callback'=>[$this,'permission']],
		['methods'=>'POST','callback'=>[$this,'mutate'],'permission_callback'=>[$this,'permission']],
	]); }
	public function permission( \WP_REST_Request $r ): bool {
		return current_user_can('manage_options')&&in_array($r->get_method(),['GET','POST'],true)&&wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest')&&Origin_Checker::is_same_site_request()&&($r->get_method()==='GET'||true===Origin_Checker::mutation_allowed($r));
	}
	public static function connection(): array { $v=get_option(self::OPTION,[]);return is_array($v)&&($v['schema']??null)===1?$v:[]; }
	public static function expected(): string { return hash('sha256',(string)wp_json_encode([self::connection(),get_option(self::SECRET,''),get_option(self::JOB,[])])); }
	public static function binding(): string { return hash('sha256',untrailingslashit(home_url('/')).'|'.get_current_blog_id().'|'.wp_salt('secure_auth')); }
	private static function fail( string $code, string $message ) { return Search_Performance_Model::error($code,$message); }
	private static function save( string $option, $value ): bool { update_option($option,$value,false);return get_option($option)!==null&&get_option($option)===$value; }
	private static function credentials() {
		$value=Secret_Store::decrypt((string)get_option(self::SECRET,''));$v=json_decode($value,true);
		if(!is_array($v)||($v['binding']??'')!==self::binding()||!is_string($v['clientId']??null)||!is_string($v['clientSecret']??null))return self::fail('credentials','Reconnect this website’s search source. Its encrypted credentials are unavailable.');return $v;
	}
	private static function save_credentials( #[\SensitiveParameter] array $value ): bool {
		$raw=wp_json_encode($value);$encrypted=is_string($raw)?Secret_Store::encrypt($raw):'';return $encrypted!==''&&self::save(self::SECRET,$encrypted);
	}
	public static function status(): array {
		$c=self::connection();$job=get_option(self::JOB,[]);$types=is_array($c['types']??null)?$c['types']:['web'];$configured=!is_wp_error(self::credentials());
		return ['schema'=>'kiwe.search-source.v1','expected'=>self::expected(),'configured'=>$configured,'storageReady'=>Search_Performance_Store::ready(),'connected'=>$configured&&($c['status']??'')==='ready','enabled'=>!empty($c['enabled']),'property'=>$c['property']??'','types'=>$types,'availableTypes'=>Search_Performance_Model::TYPES,'properties'=>$c['properties']??[],'status'=>$c['status']??'not_connected','lastCheck'=>$c['checkedAt']??null,'redirectUri'=>admin_url('admin-post.php?action=kiwe_search_oauth'),'schedulePresent'=>(bool)wp_next_scheduled(self::HOOK),'job'=>is_array($job)?array_intersect_key($job,array_flip(['kind','phase','date','typeIndex','datasetIndex','offset','cursor','highWater','imported','skipped','visited','nextAt','failures','reason','updatedAt','done'])):[],'limits'=>['retentionDays'=>Search_Performance_Model::DAYS,'detailsPerDay'=>Search_Performance_Model::DETAIL,'rows'=>Search_Performance_Store::MAX_ROWS,'legacyRows'=>10000]];
	}
	public function read( \WP_REST_Request $r ): \WP_REST_Response { if(!$this->permission($r))return UI::failure(self::fail('permission','Website-manager access is required.'),403);return UI::response(self::status()); }
	public function mutate( \WP_REST_Request $r ): \WP_REST_Response {
		if(!$this->permission($r))return UI::failure(self::fail('permission','Website-manager access is required.'),403);
		$v=$r->get_json_params();
		if(!is_array($v)||($v['confirmed']??null)!==true||!is_string($v['expected']??null)||!preg_match('/^[a-f0-9]{64}$/D',$v['expected'])||!in_array($v['operation']??'',['prepare','configure','authorize','select','start','import','pause','disconnect'],true))return UI::failure(self::fail('request','Review and confirm an available search-source task.'),400);
		$allow=['confirmed','expected','operation','clientId','clientSecret','property','types','days'];if(array_diff(array_keys($v),$allow))return UI::failure(self::fail('request','Unknown search-source fields were refused.'),400);
		if($v['operation']==='prepare'){
			if(!hash_equals(self::expected(),$v['expected']))return UI::failure(self::fail('changed','The search source changed. Refresh before continuing.'),409);
			$result=Search_Performance_Store::prepare();return is_wp_error($result)?UI::failure($result,409):UI::response(['saved'=>true,'source'=>self::status()]);
		}
		$result=Search_Performance_Store::lock(function()use($v){
			if(!hash_equals(self::expected(),$v['expected']))return self::fail('changed','The connection or background work changed. Refresh before continuing.');
			switch($v['operation']){
				case 'configure':return $this->configure($v);
				case 'authorize':return $this->authorize();
				case 'select':return $this->select($v);
				case 'start':return $this->start($v);
				case 'import':return $this->import_start($v);
				case 'pause':$c=self::connection();if(!$c)return true;$c['enabled']=false;wp_clear_scheduled_hook(self::HOOK);return self::save(self::OPTION,$c)?true:self::fail('storage','The paused choice could not be confirmed.');
				case 'disconnect':return $this->disconnect();
			}return false;
		});
		if(is_wp_error($result))return UI::failure($result,409);return UI::response(['saved'=>true,'authorizeUrl'=>is_array($result)?($result['authorizeUrl']??''):'','source'=>self::status()]);
	}
	private function configure( array $v ) {
		$id=$v['clientId']??null;$secret=$v['clientSecret']??null;
		if(!is_string($id)||!preg_match('/^[A-Za-z0-9._-]{5,180}\.apps\.googleusercontent\.com$/D',$id)||!Search_Performance_Model::text($secret,256)||strlen($secret)<8||!str_starts_with(admin_url('/'),'https://'))return self::fail('configuration','Use this website’s Google web-application credentials and an HTTPS callback.');
		$credentials=['binding'=>self::binding(),'clientId'=>$id,'clientSecret'=>$secret,'access'=>'','refresh'=>'','expires'=>0];
		if(!self::save_credentials($credentials))return self::fail('crypto','The credentials could not be encrypted and confirmed.');
		wp_clear_scheduled_hook(self::HOOK);delete_option(self::STATE);delete_option(self::JOB);
		$c=['schema'=>1,'epoch'=>bin2hex(random_bytes(16)),'status'=>'configured','enabled'=>false,'property'=>'','types'=>['web'],'properties'=>[]];
		return self::save(self::OPTION,$c)?true:self::fail('storage','The connection could not be confirmed.');
	}
	private function authorize() {
		$c=self::connection();$credentials=self::credentials();if(is_wp_error($credentials)||!$c)return self::fail('configuration','Configure website-owned Google credentials first.');
		$state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
		$pending=['hash'=>hash('sha256',$state),'actor'=>get_current_user_id(),'session'=>hash('sha256',wp_get_session_token()),'binding'=>self::binding(),'epoch'=>$c['epoch'],'expires'=>time()+600,'verifier'=>$verifier];
		$encrypted=Secret_Store::encrypt((string)wp_json_encode($pending));if(!$encrypted||!self::save(self::STATE,$encrypted))return self::fail('state','The authorization request could not be retained.');
		return ['authorizeUrl'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$credentials['clientId'],'redirect_uri'=>admin_url('admin-post.php?action=kiwe_search_oauth'),'response_type'=>'code','scope'=>Search_Performance_Model::SCOPE,'access_type'=>'offline','prompt'=>'consent','include_granted_scopes'=>'false','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'],'','&',PHP_QUERY_RFC3986)];
	}
	/** Fixed Google endpoints, no redirects or provider body/credentials in errors or reports. */
	private static function http( string $url, string $method, #[\SensitiveParameter] array $args = [] ) {
		if(!preg_match('~^https://(?:oauth2\.googleapis\.com/token|www\.googleapis\.com/webmasters/v3/(?:sites|sites/[^/?]+(?:/searchAnalytics/query)?))$~D',$url))return self::fail('endpoint','The provider endpoint was refused.');
		$response=wp_safe_remote_request($url,['method'=>$method,'timeout'=>4,'redirection'=>0,'limit_response_size'=>131072,'sslverify'=>true,...$args]);
		if(is_wp_error($response))return self::fail('network','The search provider is temporarily unavailable.');
		$code=(int)wp_remote_retrieve_response_code($response);$raw=wp_remote_retrieve_body($response);
		if($code===429||$code>=500){$retry=wp_remote_retrieve_header($response,'retry-after');return new \WP_Error('kiwe_search_quota','The search provider needs a later retry.',['retryAfter'=>is_numeric($retry)?max(900,min(86400,(int)$retry)):900]);}
		if($code===401)return self::fail('expired','The search authorization expired.');
		if($code===403){$e=json_decode(is_string($raw)?$raw:'',true);$errors=is_array($e['error']??null)?($e['error']['errors']??[]):[];$reasons=is_array($errors)?array_column(array_filter($errors,'is_array'),'reason'):[];if(array_intersect($reasons,['quotaExceeded','rateLimitExceeded','userRateLimitExceeded','dailyLimitExceeded']))return new \WP_Error('kiwe_search_quota','The search quota needs a later retry.',['retryAfter'=>900]);return self::fail('permission','This account no longer has access to the chosen search property.');}
		if($code!==200||!is_string($raw)||strlen($raw)>=131072){$e=json_decode(is_string($raw)?$raw:'',true);return self::fail(($e['error']??'')==='invalid_grant'?'revoked':'response','The search authorization or response could not be confirmed.');}
		$v=json_decode($raw,true,12);return is_array($v)?$v:self::fail('response','The search provider returned an unreadable response.');
	}
	private static function token( #[\SensitiveParameter] array $body, #[\SensitiveParameter] array $credentials ) {
		$v=self::http('https://oauth2.googleapis.com/token','POST',['body'=>$body]);if(is_wp_error($v))return $v;
		if(!Search_Performance_Model::text($v['access_token']??null,8192)||strlen($v['access_token'])<8||($v['token_type']??'')!=='Bearer'||!is_numeric($v['expires_in']??null)||(int)$v['expires_in']<60||(int)$v['expires_in']>86400||!is_string($v['scope']??Search_Performance_Model::SCOPE)||trim($v['scope']??Search_Performance_Model::SCOPE)!==Search_Performance_Model::SCOPE)return self::fail('token','A read-only search grant could not be confirmed.');
		$credentials['access']=$v['access_token'];$credentials['expires']=time()+(int)$v['expires_in'];
		if(isset($v['refresh_token'])){if(!Search_Performance_Model::text($v['refresh_token'],8192)||strlen($v['refresh_token'])<8)return self::fail('token','A background search grant could not be confirmed.');$credentials['refresh']=$v['refresh_token'];}
		if(empty($credentials['refresh'])||!self::save_credentials($credentials))return self::fail('token','The website’s background search grant could not be retained.');return $credentials;
	}
	private static function access() {
		$v=self::credentials();if(is_wp_error($v))return $v;if(!empty($v['access'])&&(int)$v['expires']>time()+60)return $v['access'];
		if(empty($v['refresh']))return self::fail('revoked','Reconnect the website’s search source.');
		$v=self::token(['grant_type'=>'refresh_token','refresh_token'=>$v['refresh'],'client_id'=>$v['clientId'],'client_secret'=>$v['clientSecret']],$v);return is_wp_error($v)?$v:$v['access'];
	}
	/** State is consumed once, under the same connection lock, before exchanging any code. */
	public function complete( array $query ) {
		if(!is_user_logged_in()||!current_user_can('manage_options'))return self::fail('permission','Website-manager access is required.');
		return Search_Performance_Store::lock(function()use($query){
			$c=self::connection();$pending=json_decode(Secret_Store::decrypt((string)get_option(self::STATE,'')),true);
			if(!is_array($pending)||!is_string($query['state']??null)||strlen($query['state'])!==64||!hash_equals($pending['hash']??'',hash('sha256',$query['state']))||($pending['actor']??0)!==get_current_user_id()||!hash_equals($pending['session']??'',hash('sha256',wp_get_session_token()))||($pending['binding']??'')!==self::binding()||($pending['epoch']??'')!==($c['epoch']??null)||(int)($pending['expires']??0)<time())return self::fail('state','The authorization request expired or belongs to another account or website.');
			delete_option(self::STATE);if(get_option(self::STATE,false)!==false)return self::fail('state','The authorization request could not be consumed.');
			if(isset($query['error']))return self::fail('denied','Search access was not granted.');
			if(!Search_Performance_Model::text($query['code']??null,4096)||$query['code']==='')return self::fail('code','The authorization response was incomplete.');
			$v=self::credentials();if(is_wp_error($v))return $v;
			$v=self::token(['grant_type'=>'authorization_code','code'=>$query['code'],'client_id'=>$v['clientId'],'client_secret'=>$v['clientSecret'],'redirect_uri'=>admin_url('admin-post.php?action=kiwe_search_oauth'),'code_verifier'=>$pending['verifier']],$v);
			if(is_wp_error($v))return $v;$body=self::http('https://www.googleapis.com/webmasters/v3/sites','GET',['headers'=>['Authorization'=>'Bearer '.$v['access']]]);if(is_wp_error($body))return $body;
			$properties=Search_Performance_Model::properties($body,home_url('/'));if(is_wp_error($properties))return $properties;
			$c['properties']=array_slice($properties,0,20);$c['status']='ready';$c['enabled']=false;$c['property']='';$c['checkedAt']=time();
			return self::save(self::OPTION,$c)?true:self::fail('storage','Search property permissions could not be confirmed.');
		});
	}
	public function callback(): void {
		$result=$this->complete(wp_unslash($_GET));nocache_headers();wp_safe_redirect(add_query_arg(['page'=>'kiwe-analytics','search_connection'=>is_wp_error($result)?'review':'ready'],admin_url('admin.php')));exit;
	}
	private function select( array $v ) {
		$c=self::connection();$property=$v['property']??'';$types=$v['types']??['web'];
		if(($c['status']??'')!=='ready'||!is_string($property)||!in_array($property,array_column($c['properties']??[],'property'),true)||!Search_Performance_Model::property($property,home_url('/'))||!is_array($types)||!array_is_list($types)||!$types||count($types)>6||array_diff($types,Search_Performance_Model::TYPES)||count(array_unique($types))!==count($types))return self::fail('property','Choose an authorized property for this website and supported search types.');
		$c['property']=$property;$c['types']=$types;$c['enabled']=false;$c['epoch']=bin2hex(random_bytes(16));wp_clear_scheduled_hook(self::HOOK);delete_option(self::JOB);
		return self::save(self::OPTION,$c)?true:self::fail('storage','The chosen search property could not be confirmed.');
	}
	private function start( array $v ) {
		$c=self::connection();$days=$v['days']??30;if(!Search_Performance_Store::ready()||!is_int($days)||$days<1||$days>180||empty($c['property'])||($c['status']??'')!=='ready')return self::fail('property','Prepare reporting storage, connect and choose an authorized property before synchronizing.');
		$c['enabled']=true;if(!self::save(self::OPTION,$c))return self::fail('storage','Search synchronization could not be enabled.');
		$range=Search_Performance_Model::today()->modify('-3 days');$job=['kind'=>'gsc','phase'=>'permission','epoch'=>$c['epoch'],'date'=>$range->format('Y-m-d'),'from'=>$range->modify('-'.($days-1).' days')->format('Y-m-d'),'to'=>$range->format('Y-m-d'),'typeIndex'=>0,'datasetIndex'=>0,'offset'=>0,'pending'=>[],'failures'=>0,'done'=>false];
		return $this->queue($job,1);
	}
	private function queue( array $job, int $delay ) {
		$job['nextAt']=time()+$delay+(hexdec(substr(hash('sha256',home_url('/')),0,2))%61);$job['updatedAt']=time();
		if(!self::save(self::JOB,$job))return self::fail('storage','The background checkpoint could not be confirmed.');
		wp_clear_scheduled_hook(self::HOOK);$ok=wp_schedule_single_event($job['nextAt'],self::HOOK,[],true);return is_wp_error($ok)||!$ok?self::fail('schedule','The background checkpoint is saved but its schedule needs repair.'):true;
	}
	private function disconnect() {
		$c=self::connection();$c['enabled']=false;$c['status']='disconnected';$c['properties']=[];$c['epoch']=bin2hex(random_bytes(16));
		wp_clear_scheduled_hook(self::HOOK);delete_option(self::SECRET);delete_option(self::STATE);delete_option(self::JOB);
		return self::save(self::OPTION,$c)?true:self::fail('storage','Disconnection could not be confirmed.');
	}
	/** One keyset read per legacy step; source tables, grants and settings remain untouched. */
	private function import_start( array $v ) {
		global $wpdb;$c=self::connection();$property=$v['property']??($c['property']??home_url('/'));
		if(!Search_Performance_Store::ready()||!is_string($property)||!Search_Performance_Model::property($property,home_url('/')))return self::fail('property','Prepare reporting storage and choose historical evidence belonging to this website.');
		$table=$wpdb->prefix.'rank_math_analytics_gsc';if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table)return self::fail('history','Supported Rank Math search history is unavailable.');
		$columns=$wpdb->get_results('SHOW COLUMNS FROM '.$wpdb->prefix.'rank_math_analytics_gsc',ARRAY_A);
		if($wpdb->last_error||array_column($columns,'Field')!==['id','created','query','page','clicks','impressions','position','ctr'])return self::fail('history','Supported Rank Math search history is unavailable. Unknown source columns need review.');
		$patterns=['/^bigint(?:\(\d+\))? unsigned$/','/^timestamp(?:\(\d+\))?$/','/^varchar\(1000\)$/','/^varchar\(500\)$/','/^mediumint(?:\(\d+\))?$/','/^mediumint(?:\(\d+\))?$/','/^double$/','/^double$/'];foreach($columns as $i=>$column)if(!preg_match($patterns[$i],strtolower($column['Type'])))return self::fail('history','Historical column sizes or date semantics need a supported source adapter.');
		$indexes=$wpdb->get_results('SHOW INDEX FROM '.$wpdb->prefix.'rank_math_analytics_gsc',ARRAY_A);if($wpdb->last_error||!array_filter($indexes,static fn($r)=>$r['Key_name']==='PRIMARY'&&$r['Column_name']==='id'&&(int)$r['Non_unique']===0))return self::fail('history','Historical search requires its native ID index.');
		$high=$wpdb->get_var('SELECT MAX(id) FROM '.$wpdb->prefix.'rank_math_analytics_gsc');if($wpdb->last_error||!$high)return self::fail('history','No historical search rows are available.');
		if(!$c)$c=['schema'=>1,'epoch'=>bin2hex(random_bytes(16)),'status'=>'history_only','enabled'=>false,'property'=>'','types'=>['web'],'properties'=>[]];
		$c['historyProperty']=$property;$c['enabled']=true;if(!self::save(self::OPTION,$c))return self::fail('storage','Historical import could not be confirmed.');
		$job=['kind'=>'rank-math','phase'=>'history','epoch'=>$c['epoch'],'property'=>$property,'cursor'=>0,'highWater'=>(int)$high,'imported'=>0,'skipped'=>0,'visited'=>0,'failures'=>0,'done'=>false];return $this->queue($job,1);
	}
	private function legacy_step( array $job ) {
		global $wpdb;$table=$wpdb->prefix.'rank_math_analytics_gsc';
		$rows=$wpdb->get_results($wpdb->prepare("SELECT id,DATE_FORMAT(created,'%%Y-%%m-%%d') day,query,page,clicks,impressions,position,ctr FROM {$table} FORCE INDEX(PRIMARY) WHERE id>%d AND id<=%d ORDER BY id LIMIT 100",$job['cursor'],$job['highWater']),ARRAY_A);
		if($wpdb->last_error||!is_array($rows))return self::fail('history','Historical search is awaiting a source read retry.');
		if(!$rows){$job['done']=true;$job['phase']='complete';return $job;}
		$cut=Search_Performance_Model::today()->modify('-'.Search_Performance_Model::DAYS.' days')->format('Y-m-d');$data=[];
		foreach($rows as $row){
			$m=Search_Performance_Model::metrics($row);if(is_wp_error($m)||!Search_Performance_Model::date($row['day'])||!Search_Performance_Model::text($row['query'],1000)||!Search_Performance_Model::text($row['page'],500))return self::fail('history','A historical row exceeded its supported source semantics.');
			if($row['day']<$cut){$job['skipped']++;continue;}if($row['day']>Search_Performance_Model::today()->format('Y-m-d'))return self::fail('history','A future historical date needs review.');
			$page=Search_Performance_Model::url($row['page'])?$row['page']:home_url('/'.ltrim($row['page'],'/'));
			if(!Search_Performance_Model::url($page)||!Search_Performance_Model::property(home_url('/'),$page))return self::fail('history','A historical page does not belong to this website.');
			$data[]=['day'=>$row['day'],'entity'=>hash('sha256','rank-math:'.$row['id']),'label'=>$page,'secondary'=>$row['query'],...$m];
		}
		if($job['imported']+count($data)>10000){$job['done']=true;$job['phase']='limited';$job['reason']='history_capacity';return $job;}
		if($data){$day=max(array_column($data,'day'));$put=Search_Performance_Store::put($job['property'],'rank-math','unknown','legacy',$day,'unknown',$data,hash('sha256',(string)wp_json_encode($rows)),(string)$job['cursor']);if(is_wp_error($put))return $put;}
		$job['cursor']=(int)end($rows)['id'];$job['imported']+=count($data);$job['visited']+=count($rows);if($job['cursor']>=$job['highWater']||$job['visited']>=100000){$job['done']=true;$job['phase']=$job['visited']>=100000?'limited':'complete';}
		return $job;
	}
	private function provider_step( array $job, array $c ) {
		$token=self::access();if(is_wp_error($token))return $token;$headers=['Authorization'=>'Bearer '.$token];
		if($job['phase']==='permission'){
			$body=self::http('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($c['property']),'GET',['headers'=>$headers]);if(is_wp_error($body))return $body;
			if(($body['siteUrl']??'')!==$c['property']||!in_array($body['permissionLevel']??'',['siteOwner','siteFullUser','siteRestrictedUser'],true))return self::fail('permission','Search property access needs review.');
			$c['status']='ready';$c['checkedAt']=time();if(!self::save(self::OPTION,$c))return self::fail('storage','Search permission readback could not be retained.');$job['phase']='query';return $job;
		}
		$type=$c['types'][$job['typeIndex']]??'';$datasets=in_array($type,['discover','googleNews'],true)?['site','page']:['site','page','query'];$dataset=$datasets[$job['datasetIndex']]??'';
		$request=Search_Performance_Model::query($job['date'],$type,$dataset,$job['offset']);if(is_wp_error($request))return $request;
		$body=self::http('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($c['property']).'/searchAnalytics/query','POST',['headers'=>[...$headers,'Content-Type'=>'application/json'],'body'=>wp_json_encode($request)]);if(is_wp_error($body))return $body;
		$rows=Search_Performance_Model::response($body,$request);if(is_wp_error($rows))return $rows;
		$pending=array_merge($job['pending'],$rows);$keys=array_column($pending,'entity');if(count(array_unique($keys))!==count($keys))return self::fail('pagination','Search rows changed during pagination. Restart this backfill before accepting them.');
		if($dataset!=='site'&&$job['offset']===0&&count($rows)===Search_Performance_Model::PAGE){$job['pending']=$pending;$job['offset']=Search_Performance_Model::PAGE;return $job;}
		$put=Search_Performance_Store::put($c['property'],'gsc',$type,$dataset,$job['date'],$request['aggregationType'],$pending,hash('sha256',(string)wp_json_encode($pending)));if(is_wp_error($put))return $put;
		$job['pending']=[];$job['offset']=0;$job['datasetIndex']++;
		if($job['datasetIndex']>=count($datasets)){$job['datasetIndex']=0;$job['typeIndex']++;}
		if($job['typeIndex']>=count($c['types'])){$job['typeIndex']=0;$job['date']=(new \DateTimeImmutable($job['date']))->modify('-1 day')->format('Y-m-d');}
		if($job['date']<$job['from']){$job['done']=true;$job['phase']='complete';}return $job;
	}
	/** Ordinary requests cannot execute a drain, refresh grant, enumerate history or create storage. */
	public function tick(): void {
		if(!(defined('DOING_CRON')&&DOING_CRON)&&PHP_SAPI!=='cli')return;
		Search_Performance_Store::lock(function(){
			$c=self::connection();$job=get_option(self::JOB,[]);if(empty($c['enabled'])||!is_array($job)||($job['epoch']??'')!==($c['epoch']??null)||(int)($job['nextAt']??0)>time()||!Search_Performance_Store::ready())return;
			$prune=Search_Performance_Store::prune();if(is_wp_error($prune)){$this->backoff($job,$c,$prune);return;}
			if(!empty($job['done'])){
				if($job['kind']!=='gsc')return;$range=Search_Performance_Model::today()->modify('-3 days');$job=array_merge($job,['phase'=>'permission','date'=>$range->format('Y-m-d'),'to'=>$range->format('Y-m-d'),'from'=>$range->modify('-2 days')->format('Y-m-d'),'typeIndex'=>0,'datasetIndex'=>0,'offset'=>0,'pending'=>[],'done'=>false]);
			}
			$result=$job['kind']==='rank-math'?$this->legacy_step($job):$this->provider_step($job,$c);
			if(is_wp_error($result)){$this->backoff($job,$c,$result);return;}
			$result['failures']=0;unset($result['reason']);$delay=!empty($result['done'])?86400:120;
			if(!empty($result['done'])&&$result['kind']==='rank-math'){$result['updatedAt']=time();$result['nextAt']=0;self::save(self::JOB,$result);return;}
			$this->queue($result,$delay);
		});
	}
	private function backoff( array $job, array $c, \WP_Error $error ): void {
		$code=$error->get_error_code();$job['reason']=$code;$job['failures']=min(16,(int)($job['failures']??0)+1);
		if(in_array($code,['kiwe_search_revoked','kiwe_search_credentials','kiwe_search_permission'],true)){$c['enabled']=false;$c['status']=$code==='kiwe_search_permission'?'permission':'reconnect';self::save(self::OPTION,$c);$job['nextAt']=0;$job['updatedAt']=time();self::save(self::JOB,$job);return;}
		if($code==='kiwe_search_expired'){$v=self::credentials();if(!is_wp_error($v)){$v['expires']=0;self::save_credentials($v);}}
		$data=$error->get_error_data();$delay=max(900,min(86400,300*2**min(8,$job['failures'])));if(is_array($data))$delay=max($delay,(int)($data['retryAfter']??0));$this->queue($job,$delay);
	}
	public static function report( int $days = 30, string $url = '', string $type = 'web' ): array {
		$c=self::connection();$range=Search_Performance_Model::range($days);$type=in_array($type,Search_Performance_Model::TYPES,true)?$type:'web';$property=$c['property']??'';$history=$c['historyProperty']??'';
		$out=['available'=>false,'type'=>$type,'timezone'=>'America/Los_Angeles','from'=>$range['from'],'to'=>$range['to'],'sourceStatus'=>$c['status']??'not_connected','note'=>'Search impressions, clicks and positions need a connected search-performance source. Local visits do not measure Google rankings.','current'=>null,'previous'=>null,'history'=>null];
		if($property){$a=Search_Performance_Store::period($property,'gsc',$type,$range['from'],$range['to'],$url);$b=Search_Performance_Store::period($property,'gsc',$type,$range['prior'],$range['priorTo'],$url);$out['current']=$a;$out['previous']=$b;if(!$url)$out['topQueries']=Search_Performance_Store::queries($property,$type,$range['from'],$range['to']);$out['available']=$a['available'];$out['note']='Google Search Console · '.$type.' · final data, '.$range['from'].' to '.$range['to'].' in Pacific time. Dates end three days before today; recent finalized values can still change. '.($url?'Page detail is limited to the top 40 returned pages per day; a missing page is unavailable, not zero. Comparisons are withheld.':'Property totals use their own aggregation, separate from page and query detail. Comparisons require observed data for every day.').' Search Console can omit data; query detail is never exhaustive.';}
		if($history){$a=Search_Performance_Store::period($history,'rank-math','unknown',$range['from'],$range['to'],$url);$out['history']=$a;$out['available']=$out['available']||$a['available'];if($a['available'])$out['note'].=' Imported Rank Math query/page rows are separate lower-bound evidence, with unknown search type and their stored calendar dates. They are never added to provider totals or treated as complete coverage.';}
		return $out;
	}
}
