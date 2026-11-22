<?php
/** Executes the real Campaign Workspace, MCP bridge and queue against bounded synthetic owners. */
namespace DSA\Notifications {
 class Notification_Preference_Service {public array $consent=[1=>true,2=>true,3=>true];public function enabled(){return true;}public function user_accepts($id,$channel,$topic=''){return $this->consent[$id]??false;}}
 class Push_Service {public function ready(){return false;}public function active_user_ids($ids){return [];}}
 class Campaign_Unsubscribe_Service {public static function url($id,$hash,$topic,$channel,$expiry){return 'https://fixture.example/?kiwe_unsubscribe=private-capability-'.$id;}}
 class New_Post_Notification_Service {}
}
namespace DSA\Communications {class Channel_Service {public array $calls=[];public function available_for_campaign($c){return $c==='email';}public function send($c,$to,$title,$body,$ctx){$this->calls[]=func_get_args();return true;}}}
namespace DSA\Access {
 class Team_Workspace_Service {public static function suspended($id){return false;}}
 class Team_App_Service {public static function enabled(){return true;}}
 class User_Group_Service {public static function allowed(){return $GLOBALS['owner'];}public function catalog(){return [['id'=>'email','name'=>'Email','smart'=>true]];}public function find($id){return $id==='email'?$this->catalog()[0]:new \WP_Error('group','No group');}public function candidates($id){return ['ids'=>[1,2,3],'limited'=>false];}public function smart_member($id,$group,$push=[]){return true;}public static function memberships($id){return [];}}
 class Task_Workspace_UI {public static function response($v){return new \WP_REST_Response($v);}public static function failure($e,$status){return new \WP_REST_Response(['error'=>$e->get_error_message()],$status);}public static function text($v,$n){return substr(strip_tags($v),0,$n);}}
}
namespace DSA\Apps {class Store_App_Service {public static function enabled(){return false;}}}
namespace DSA\PhoneKey {class PhoneKey_Bridge {public static array $contacts=[1=>'first@example.invalid',2=>'same@example.invalid',3=>'same@example.invalid'];public static function campaign_contact($id,$c){return self::$contacts[$id]??'';}}}
namespace DSA\MCP {class Credentials {public static function actor_allowed($id){return $GLOBALS['owner'];}}}
namespace DSA\Utilities {class Origin_Checker {public static function is_same_site_request(){return $GLOBALS['origin'];}public static function mutation_allowed($r){return $r->get_header('X-Kiwe-Mutation')==='1';}}}
namespace DSA {class Plugin {public static function instance(){return new self();}public function campaign_workspace(){return $GLOBALS['workspace'];}}}
namespace {
 define('ABSPATH',__DIR__.'/');define('DAY_IN_SECONDS',86400);$options=[];$events=[];$owner=true;$origin=true;$calls=[];$checks=[];
 class WP_Error {public function __construct(private string $code,private string $message,private array $data=[]){}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}public function get_error_data(){return $this->data;}}
 class WP_REST_Response {public function __construct(public array $data,private int $status=200){}public function get_status(){return $this->status;}public function get_data(){return $this->data;}}
 class WP_REST_Request {public function __construct(private string $method='GET',private array $query=[],private array $body=[],private array $headers=[]){}public function get_method(){return $this->method;}public function get_query_params(){return $this->query;}public function get_json_params(){return $this->body;}public function get_header($k){return $this->headers[$k]??'';}}
 function get_option($k,$fallback=false){return $GLOBALS['options'][$k]??$fallback;}function update_option($k,$v,$a=false){$GLOBALS['options'][$k]=$v;return true;}function add_option($k,$v,$d='',$a=false){if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
 function get_current_user_id(){return 1;}function is_user_logged_in(){return true;}function current_user_can($c,...$a){return $GLOBALS['owner'];}function get_userdata($id){return (object)['ID'=>$id];}function get_user_meta($id,$k,$s=true){return [];}function wp_salt($k){return 'synthetic-secret';}
 function is_wp_error($v){return $v instanceof WP_Error;}function wp_json_encode($v){return json_encode($v);}function wp_generate_uuid4(){return bin2hex(random_bytes(16));}function wp_generate_password($n,$a=false,$b=false){return substr(bin2hex(random_bytes($n)),0,$n);}function wp_verify_nonce($v,$n){return $v===$n;}
 function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',$v);}function sanitize_text_field($v){return trim(strip_tags($v));}function sanitize_textarea_field($v){return trim(strip_tags($v));}function home_url($p){return 'https://fixture.example'.$p;}function do_action($k,...$a){}function __($s,$d=''){return $s;}function is_multisite(){return false;}
 function wp_schedule_single_event($at,$hook,$args=[],$error=false){$GLOBALS['events'][]=[$at,$hook,$args];return true;}function wp_next_scheduled($hook,$args=[]){return false;}
 $repo=dirname(__DIR__,3);require $repo.'/tools/release/fixtures/worker-options.php';
 $wpdb=new class($wpdb){public string $prefix='wp_',$options='wp_options',$last_error='';public function __construct(private $native){}public function __call($method,$args){$v=$this->native->$method(...$args);$this->last_error=$this->native->last_error;return $v;}public function get_var($sql){return '1';}};
 $root=$repo.'/wp-content/mu-plugins/dsa/includes/';foreach(['Notifications/Notification_Campaign_Service','Access/Campaign_Workspace_Service','MCP/Authoring_Service','MCP/Campaign_Authoring']as$f)require $root.$f.'.php';
 use DSA\Access\Campaign_Workspace_Service as Workspace;use DSA\MCP\Campaign_Authoring as Bridge;
 function check($v,$label){if(!$v)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}function denies($f,$label){try{$f();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected denial: '.$label);}
 $prefs=new DSA\Notifications\Notification_Preference_Service();$channel=new DSA\Communications\Channel_Service();$delivery=new DSA\Notifications\Notification_Campaign_Service($prefs,$channel,new DSA\Notifications\Push_Service());$workspace=new Workspace($prefs,$delivery);$bridge=new Bridge();
 check($workspace->read(new WP_REST_Request())->get_status()===403,'Browser still requires its nonce independently of native role');
 $input=['id'=>'','name'=>'M06 native fixture','type'=>'newsletter','sourceId'=>0,'group'=>'email','channels'=>['email'],'copies'=>['email'=>['title'=>'Fixture title','body'=>'This is a synthetic campaign.']],'scheduledAt'=>0];
 $plan=$workspace->native_draft_plan($input);check(!is_wp_error($plan)&&!isset($options[Workspace::OPTION])&&!$events&&!$channel->calls,'Native draft planning validates without writes or scheduling');
 $draft=$workspace->native_write(['operation'=>'save',...$input]);check($draft['status']==='draft'&&!$events&&!$channel->calls,'Native draft saves without a browser nonce and never sends');
 $preview=json_decode($bridge->read(['area'=>'audience','id'=>$draft['id']])['resultJson'],true)['data'];check($preview['count']===2&&$preview['excluded']['duplicate']===1,'Actual workspace deduplicates verified destination before preview');
 check(!str_contains(json_encode($preview),'@example.invalid')&&!str_contains(json_encode($preview),'private-capability'),'Actual audience projection contains no contacts or live unsubscribe capability');
 $review=$bridge->prepare('queue',['id'=>$draft['id']]);check($review['needsApproval']&&str_contains($review['after'],'fixture.example')&&!$events,'Exact send review includes the native final source URL without scheduling');
 $prefs->consent[2]=false;$prefs->consent[3]=false;denies(fn()=>$bridge->apply('queue',['id'=>$draft['id']],$review['nativeExpected']),'Native consent changes invalidate approved queue state');check(!$events,'Stale review cannot schedule');$prefs->consent[2]=true;$prefs->consent[3]=true;
 // The Intent service approval boundary is separately tested; invoke its admitted native continuation here.
 $queue=['operation'=>'queue','id'=>$draft['id'],'expected'=>$draft['expected'],'audienceExpected'=>$preview['expected'],'confirmed'=>true];$queued=$workspace->native_write($queue);check(!is_wp_error($queued)&&$queued['queued']&&count($events)===1&&!$channel->calls,'Admitted native queue schedules one job without synchronous delivery');
 check(is_wp_error($workspace->native_write($queue))&&count($events)===1,'A queued draft cannot queue twice');
 $owner=false;check(is_wp_error($workspace->native_read([]))&&is_wp_error($workspace->native_write(['operation'=>'resume','jobId'=>$queued['job']['id']])),'Native role revocation denies direct reads and continuation');$owner=true;
 $prefs->consent[1]=false;DSA\PhoneKey\PhoneKey_Bridge::$contacts[2]='changed@example.invalid';$options['dsa_notification_campaign_active']['runAt']=time()-1;
 $delivery->run_batch($queued['job']['id']);$state=$delivery->status();check(!$state['active']&&$state['history'][0]['skipped']===2&&!$channel->calls,'Actual worker rechecks consent and exact verified destination before I/O: '.json_encode($state));
 check(!str_contains(json_encode($state),'@example.invalid'),'Native delivery history remains aggregate');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false,'actualClient'=>false,'outboundCalls'=>count($channel->calls)])."\n";
}
