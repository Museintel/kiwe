<?php
declare(strict_types=1);

namespace Bricks {
 final class Setup { public static function get_control_options($name): array { return $name === 'templateTypes' ? ['header'=>'Header','footer'=>'Footer'] : []; } }
 final class Helpers { public static string $url = ''; public static function get_builder_edit_link($id): string { return self::$url ?: 'https://example.test/?bricks=run&p='.$id; } }
}
namespace DSA\Utilities {
 final class Origin_Checker { public static function is_same_site_request(): bool { return true; } public static function mutation_allowed($request): bool { return $request->get_header('X-Kiwe-Mutation') === '1'; } }
}
namespace DSA\AI {
 final class AI_Feature_Policy { public const CAPABILITY = 'kiwe_manage_ai_preferences'; public static function site_choices(): array { return ['ai'=>false]; } public static function analytics_enabled(array $settings): bool { return false; } }
}
namespace DSA\Analytics {
 final class Analytics_Report_Service {
  public static function goal(): array { return ['key'=>'read','label'=>'Readership']; }
  public static function goal_hash(): string { return hash('sha256','test-goal'); }
  public function report(string $scope,int $id,int $days): array { return ['schema'=>'kiwe.analytics-report.v1','scope'=>$scope,'id'=>$id,'label'=>$id?'Article':'Your website','sections'=>[],'goal'=>self::goal(),'from'=>'2026-09-01','to'=>'2026-09-30','comparison'=>'Previous period']; }
 }
}
namespace DSA\Onboarding { final class Design_Context_Profile_Service { public const OPTION_PROFILE = 'profile'; public function service_post_types(): array { return []; } } }
namespace DSA { final class Settings { public function get($key,$default=[]): array { return []; } } }
namespace DSA\Access {
 final class Analytics_Reporting_Setup_Service { public static function projection(): array { return ['canPrepare'=>false,'needed'=>false,'secureReady'=>true,'cartsReady'=>true,'recordingVisits'=>true,'restoredBlocked'=>false]; } }
 final class Site_Owner_Service { public static function is_owner(int $id=0): bool { return $id === 2 || (!$id && $GLOBALS['actor'] === 2); } }
 final class Team_Workspace_Service { public const SUSPENDED_META = 'suspended'; }
 final class Team_App_Service { public static function enabled(): bool { return true; } }
 final class AI_Workspace_Service { public static function url(): string { return 'https://example.test/wp-admin/admin.php?page=kiwe-ai'; } }
 final class Task_Workspace_UI {
  public static function text($value,int $max): string { return substr(trim(strip_tags((string)$value)),0,$max); }
  public static function id($value): int { return max(0,(int)$value); }
  public static function response(array $data,int $status=200): \WP_REST_Response { return new \WP_REST_Response($data,$status); }
  public static function failure(\WP_Error $error,int $status): \WP_REST_Response { return new \WP_REST_Response(['code'=>$error->code,'message'=>$error->message],$status); }
 }
}
namespace {
 define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
 define('BRICKS_DB_TEMPLATE_SLUG','bricks_template');
 define('BRICKS_DB_TEMPLATE_TYPE','_bricks_template_type');
 $actor=1;$available=true;$posts=[];$meta=[];$options=[];$transients=[];$writes=0;$count=0;
 $users=[1=>(object)['roles'=>['administrator']],2=>(object)['roles'=>['administrator']],3=>(object)['roles'=>['kiwe_web_designer']],4=>(object)['roles'=>['editor']],5=>(object)['roles'=>['subscriber']]];
 class WP_Post { public string $post_content='';public string $post_password='';public function __construct(public int $ID,public string $post_type,public string $post_title,public string $post_status='draft',public string $post_modified_gmt='2026-09-29 10:00:00',public string $post_modified='2026-09-29 15:30:00'){} }
 class WP_Error { public function __construct(public string $code,public string $message){} }
 class WP_REST_Response { public function __construct(public array $data,public int $status=200){} }
 class WP_REST_Request implements \ArrayAccess {
  public function __construct(private string $method='GET',private array $query=[],private array $body=[],private array $headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1']){}
  public function get_method(): string { return $this->method; }
  public function get_query_params(): array { return $this->query; }
  public function get_json_params(): array { return $this->body; }
  public function get_header($name): string { return $this->headers[$name]??''; }
  public function offsetGet(mixed $name): mixed { return $this->query[$name]??null; }
  public function offsetExists(mixed $name): bool { return isset($this->query[$name]); }
  public function offsetSet(mixed $name,mixed $value): void { throw new \RuntimeException('Request is immutable in this test.'); }
  public function offsetUnset(mixed $name): void { throw new \RuntimeException('Request is immutable in this test.'); }
 }
 class WP_Query {
  public array $posts=[];public int $max_num_pages=1;
  public function __construct(array $args){$GLOBALS['query_args']=$args;foreach($GLOBALS['posts'] as $post)if($post->post_type===$args['post_type']&&($args['post_status']==='all'||in_array($post->post_status,(array)$args['post_status'],true)))$this->posts[]=$post;}
 }
 function check($yes,string $name): void { if(!$yes)throw new \RuntimeException('FAIL '.$name);$GLOBALS['count']++;echo 'PASS '.$name."\n"; }
 function is_wp_error($value): bool { return $value instanceof WP_Error; }
 function __($text,$domain=''): string { return $text; }
 function is_user_logged_in(): bool { return $GLOBALS['actor']>0; }
 function get_current_user_id(): int { return $GLOBALS['actor']; }
 function get_userdata($id) { return $GLOBALS['users'][$id]??false; }
 function get_user_meta($id,$key,$single=true) { return ''; }
 function current_user_can($cap,...$args): bool {
  $actor=$GLOBALS['actor'];$role=$GLOBALS['users'][$actor]->roles[0]??'';
  if($cap==='edit_post')return in_array($role,['administrator','kiwe_web_designer','editor'],true)&&isset($GLOBALS['posts'][$args[0]??0])&&($role!=='kiwe_web_designer'||in_array($GLOBALS['posts'][$args[0]]->post_type,['page','bricks_template'],true));
  if($role==='administrator')return true;
  if($role==='kiwe_web_designer')return in_array($cap,['kiwe_manage_templates','create_kiwe_templates','edit_pages'],true);
  if($role==='editor')return in_array($cap,['edit_posts','edit_pages'],true);
  return false;
 }
 function post_type_exists($type): bool { return $GLOBALS['available']&&in_array($type,['bricks_template','page','post','product'],true); }
 function get_post_type_object($type) { return (object)['cap'=>(object)['edit_others_posts'=>'edit_others_posts']]; }
 function get_post($id) { return $GLOBALS['posts'][$id]??null; }
 function get_post_type($id) { return get_post($id)->post_type??false; }
 function get_post_meta($id,$key,$single=true) { return $GLOBALS['meta'][$id][$key]??''; }
 function get_option($key,$default=false) { return $GLOBALS['options'][$key]??$default; }
 function rest_url($path=''): string { return 'https://example.test/wp-json/'.$path; }
 function get_the_title($id): string { return get_post($id)->post_title??''; }
 function wp_insert_post($payload,$error=false) {
  $GLOBALS['writes']++;$id=101;$GLOBALS['posts'][$id]=new WP_Post($id,$payload['post_type'],stripslashes($payload['post_title']),$payload['post_status']);$GLOBALS['meta'][$id]=$payload['meta_input'];return $id;
 }
 function wp_update_post($payload,$error=false) { $GLOBALS['writes']++;$GLOBALS['posts'][$payload['ID']]->post_title=stripslashes($payload['post_title']);return $payload['ID']; }
 function wp_slash($value) { return addslashes($value); }
 function wp_json_encode($value) { return json_encode($value); }
 function wp_verify_nonce($value,$action): bool { return $value===$action; }
 function absint($value): int { return abs((int)$value); }
 function sanitize_key($value): string { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',(string)$value)); }
 function admin_url($path=''): string { return 'https://example.test/wp-admin/'.$path; }
 function home_url($path=''): string { return 'https://example.test'.$path; }
 function add_query_arg($args,$url): string { return $url.'?'.http_build_query($args); }
 function wp_parse_url($url) { return parse_url($url); }
 function esc_url_raw($url): string { return $url; }
 function get_transient($key) { return $GLOBALS['transients'][$key]??false; }
 function set_transient($key,$value,$ttl) { $GLOBALS['transients'][$key]=$value;return true; }
 function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
 function wp_generate_password($length,$special,$extra): string { return str_repeat('a',$length); }
 function wp_die($message,$title='',$args=[]) { throw new \RuntimeException($message); }
 function function_exists_wc(): bool { return false; }
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Template_Workspace_Service.php';
 require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Analytics_Workspace_Service.php';
 $templates=new DSA\Access\Template_Workspace_Service();
 $analytics=new DSA\Access\Analytics_Workspace_Service();
 $get=new WP_REST_Request();$post=static fn(array $body)=>new WP_REST_Request('POST',[],$body);
 check($templates->listing($get)->data['available']===true,'Bricks types are read from the installed catalog');
 check($templates->listing($get)->data['types']===['header'=>'Header','footer'=>'Footer'],'Header and Footer are native type choices');
 $bad=$templates->create($post(['title'=>'X','type'=>'invented']));
 check($bad->status===400&&$writes===0,'unknown template type cannot create a record');
 $made=$templates->create($post(['title'=>'Client header','type'=>'header']));
 check($made->status===201&&$posts[101]->post_status==='draft'&&$meta[101][BRICKS_DB_TEMPLATE_TYPE]==='header','new template is one native Bricks draft');
 check($made->data['item']['builderUrl']==='https://example.test/?bricks=run&p=101','Bricks owns the frontend editor URL');
 Bricks\Helpers::$url='https://another.example.test/?bricks=run&p=101';
 check(DSA\Access\Template_Workspace_Service::builder_url(101)==='','cross-host builder link is rejected');
 Bricks\Helpers::$url='';
 $stale=$templates->rename(new WP_REST_Request('POST',['id'=>101],['title'=>'New header','expected'=>str_repeat('0',64)]));
 check($stale->status===409&&$writes===1,'stale rename leaves the native title intact');
 $renamed=$templates->rename(new WP_REST_Request('POST',['id'=>101],['title'=>'New header','expected'=>$made->data['item']['expected']]));
 check($renamed->status===200&&$posts[101]->post_title==='New header'&&$meta[101][BRICKS_DB_TEMPLATE_TYPE]==='header','rename changes only the native title');
 $actor=3;
 check($templates->listing($get)->status===200&&DSA\Access\Template_Workspace_Service::builder_actor(),'Web Designer can work on Templates');
 $actor=4;
 check($templates->listing($get)->status===403&&$templates->create($post(['title'=>'No','type'=>'footer']))->status===403,'editor cannot read or create Templates');
 check(!DSA\Access\Template_Workspace_Service::builder_actor(),'editor cannot enter the Bricks builder');
 $actor=1;$available=false;
 check($templates->listing($get)->data['available']===false&&$templates->create($post(['title'=>'No','type'=>'footer']))->status===403,'Bricks absence is a read-only empty state');
 $available=true;
 $posts[201]=new WP_Post(201,'post','Published article','publish');
 $posts[202]=new WP_Post(202,'post','Draft article','draft');
 $read=$analytics->read(new WP_REST_Request('GET',['scope'=>'post','id'=>201]));
 check($read->status===200&&$read->data['id']===201&&$read->data['canReview']===false,'authorized contextual Post report reads without invoking AI');
 $actor=5;
 check($analytics->read(new WP_REST_Request('GET',['scope'=>'post','id'=>201]))->status===403,'subscriber cannot read contextual Analytics');
 $actor=1;
 check($analytics->read(new WP_REST_Request('GET',['scope'=>'post','id'=>0]))->status===200,'editorial collection remains browseable');
 check($analytics->review($post(['scope'=>'post','id'=>202,'days'=>30,'expected'=>'wrong']))->status===409,'draft or stale record cannot be sent for AI review');
 $mutationWithoutProof=new WP_REST_Request('POST',[],['title'=>'No'],['X-WP-Nonce'=>'wp_rest']);
 check($templates->create($mutationWithoutProof)->status===403,'mutation proof is required for native writes');
 echo "$count template and analytics boundary checks passed.\n";
}
