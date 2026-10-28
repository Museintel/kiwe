<?php
declare(strict_types=1);
// Actual topic, audience and event services; synthetic WP I/O and bounded delivery adapter.
namespace DSA { class Settings { public function get($key,$default=[]){return $key==='permissions'?['notification_preferences_enabled'=>true]:$default;} } }
namespace DSA\Trust { class Trust_Service {} }
namespace DSA\Access {class Form_Workspace_Service {public static function allowed_for(int $id):bool{return false;}}class Team_Workspace_Service {public static function suspended(int $id):bool{return false;}}class Site_Owner_Service {public static function owner_id():int{return 0;}}}
namespace DSA\Notifications { class Push_Service { public function send_to_users(...$args){throw new \RuntimeException('Unexpected external delivery.');} } }
namespace DSA\Communications {
class Channel_Service {
    public function available_for_campaign(string $channel): bool { return $channel==='email' && !empty($GLOBALS['channel_available']); }
    public function send(string $channel,string $recipient,string $subject,string $message,array $context=[]){
        $GLOBALS['channel_calls'][]=compact('channel','recipient','subject','message','context');
        return $GLOBALS['channel_result'] ?? true;
    }
}
}
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php'; define('ARRAY_A','ARRAY_A');
$noWoo=in_array('--no-woo',$argv,true);
if(!$noWoo){function WC(){return true;}}
class WP_Error {public function __construct(private string $code='',private string $message=''){} public function get_error_message(){return $this->message;}}
class WP_Post {public function __construct(public int $ID,public string $post_type,public int $post_author,public string $post_status='pending',public string $post_title='Synthetic proposal'){} }
$actor=4;$meta=[];$postmeta=[];$count=0;
$users=[1=>(object)['ID'=>1,'roles'=>['shop_manager'],'user_email'=>'shop@example.test'],2=>(object)['ID'=>2,'roles'=>['editor'],'user_email'=>'editor@example.test'],3=>(object)['ID'=>3,'roles'=>['subscriber'],'user_email'=>'member@example.test'],4=>(object)['ID'=>4,'roles'=>['contributor'],'user_email'=>'guest@example.test'],5=>(object)['ID'=>5,'roles'=>['contributor'],'user_email'=>'guest-two@example.test'],6=>(object)['ID'=>6,'roles'=>['shop_manager'],'user_email'=>'manager@example.test'],7=>(object)['ID'=>7,'roles'=>['author'],'user_email'=>'author@example.test']];
$caps=[1=>['edit_products','edit_others_products'],2=>['edit_others_posts'],3=>[],4=>['kiwe_guest_submit'],5=>['kiwe_guest_submit'],6=>['edit_products','edit_others_products'],7=>['edit_posts']];
$allTopics=['admin_guest_submission','admin_guest_product_submission','guest_product_status','guest_post_status','editorial_post_status'];
$rows=[];foreach($users as $id=>$user)$rows[]=['user_id'=>$id,'channels'=>'[]','topics'=>json_encode($id===6?[]:$allTopics),'browser_permission'=>'denied'];
$wpdb=new class {public string $prefix='test_';public function prepare($sql,...$args){return $sql;}public function esc_like($s){return addcslashes($s,'_%\\');}public function get_results($sql,$format){return $GLOBALS['rows'];}};
function get_option($key,$default=false){return $key==='dsa_notification_preferences_db_version'?'2':$default;}
function absint($v){return abs((int)$v);} function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_email($v){return filter_var((string)$v,FILTER_SANITIZE_EMAIL);} function is_wp_error($v){return $v instanceof WP_Error;}
function get_userdata($id){return $GLOBALS['users'][$id]??false;} function get_current_user_id(){return $GLOBALS['actor'];}
function user_can($id,$cap){$id=is_object($id)?$id->ID:$id;return $cap==='read'?isset($GLOBALS['users'][$id]):in_array($cap,$GLOBALS['caps'][$id]??[],true);}function is_multisite(){return false;}function get_users($args){return array_keys(array_filter($GLOBALS['users'],fn($u)=>array_intersect($u->roles,$args['role__in'])));} function current_user_can($cap){return user_can(get_current_user_id(),$cap);}
function is_user_logged_in(){return get_current_user_id()>0;} function __($s,$domain=''){return $s;}
function apply_filters($name,$value,...$args){return $value;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_user_meta($id,$key,$value,$previous=null){if($previous!==null&&get_user_meta($id,$key)!==$previous)return false;$GLOBALS['meta'][$id][$key]=$value;return true;}function add_user_meta($id,$key,$value,$unique=false){if($unique&&isset($GLOBALS['meta'][$id][$key]))return false;return update_user_meta($id,$key,$value);}
function get_post_meta($id,$key,$single=true){return $GLOBALS['postmeta'][$id][$key]??'';}
function get_post($id){return $id instanceof WP_Post?$id:($GLOBALS['posts'][$id]??null);}
function get_the_title($post){return get_post($post)->post_title??'';}
function get_post_status_object($status){return (object)['label'=>ucfirst($status)];}
function get_permalink($post){return 'https://site.test/item/'.get_post($post)->ID;}
function admin_url($path){return 'https://site.test/wp-admin/'.$path;} function home_url($path){return 'https://site.test'.$path;}
function add_query_arg($args,$url){return $url.(str_contains($url,'?')?'&':'?').http_build_query($args);}
function wp_strip_all_tags($value){return strip_tags((string)$value);} function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));} function esc_url_raw($v){return (string)$v;}
function check($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['count']++;echo 'PASS '.$label."\n";}
function inbox($id){return $GLOBALS['meta'][$id]['dsa_admin_notification_inbox']??[];}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Guest_Contribution_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/Admin_Event_Notification_Service.php';
foreach([4,5]as $id)$meta[$id]['kiwe_guest_application_v1']=['status'=>'approved','intents'=>['post','product']];
$posts=[101=>new WP_Post(101,'product',4),102=>new WP_Post(102,'post',4),103=>new WP_Post(103,'product',7),104=>new WP_Post(104,'post',7)];
foreach([101,102]as $id)$postmeta[$id]['_kiwe_guest_submission']=['createdAt'=>'2026-09-06T00:00:00Z'];
$prefs=new DSA\Notifications\Notification_Preference_Service(new DSA\Settings(),new DSA\Trust\Trust_Service());
$events=new DSA\Notifications\Admin_Event_Notification_Service($prefs,new DSA\Notifications\Push_Service(),null,new DSA\Communications\Channel_Service());
$catalog=new ReflectionMethod($prefs,'topics');
$actor=1;$shopTopics=array_column($catalog->invoke($prefs),'id');
check(in_array('admin_guest_product_submission',$shopTopics,true)===!$noWoo,'store review topic follows Woo availability');
check(!in_array('admin_guest_submission',$shopTopics,true),'store reviewer gains no editorial topic');
$actor=2;check(!in_array('admin_guest_product_submission',array_column($catalog->invoke($prefs),'id'),true),'editor gains no product review topic');
check($prefs->audience_user_ids_for_topic('','admin_guest_submission')===[2],'editorial audience stays separate');
check($prefs->audience_user_ids_for_topic('','admin_guest_product_submission')===($noWoo?[]:[1]),'product audience requires commerce capability and explicit topic preference');
check($prefs->audience_user_ids_for_topic('app','admin_guest_product_submission')===[],'product topic respects channel and browser permission');
$actor=4;$events->queue_guest_submission(101,4);
check(count(inbox(1))===($noWoo?0:1)&&inbox(2)===[]&&inbox(3)===[]&&count(inbox(6))===($noWoo?0:1),'native product inbox reaches permitted reviewers without enrolling any gateway');
if(!$noWoo)check(inbox(1)[0]['actionUrl']==='https://site.test/wp-admin/admin.php?page=kiwe-guests&view=review&id=101','Guest-originated product alert opens the exact Kiwe review task');
$events->queue_guest_submission(102,4);check(count(inbox(2))===1,'article proposal still reaches its editorial reviewer');
check(inbox(2)[0]['actionUrl']==='https://site.test/wp-admin/admin.php?page=kiwe-guests&view=review&id=102','Guest article alert opens the exact Kiwe review task');
$prior=$meta;$events->queue_guest_submission(103,7);$events->queue_guest_submission(101,5);
check($meta===$prior,'unmarked products and mismatched submitters cannot emit a review alert');
$posts[101]->post_status='draft';$events->queue_guest_submission(101,4);check($meta===$prior,'saved draft does not claim to await review');
$actor=1;$posts[101]->post_status='publish';$events->queue_author_status('publish','pending',$posts[101]);
check(count(inbox(4))===($noWoo?0:1)&&inbox(5)===[],'product status targets only its author and requires Woo');
if(!$noWoo)check(inbox(4)[0]['type']==='guest_product_status'&&inbox(4)[0]['title']==='Product proposal status updated','product status has its own topic and copy');
$prior=$meta;$events->queue_author_status('publish','publish',$posts[101]);$events->queue_author_status('publish','pending',$posts[103]);$events->queue_author_status('pending','new',$posts[102]);
check($meta===$prior,'unchanged status, first insert and ordinary product are silent');
$actor=4;$events->queue_author_status('draft','pending',$posts[101]);check($meta===$prior,'author does not receive their own status mutation');
$actor=1;$events->queue_author_status('publish','pending',$posts[104]);check(count(inbox(7))===1&&inbox(7)[0]['type']==='editorial_post_status','ordinary Author article status remains supported');
$caps[1]=[];check($prefs->audience_user_ids_for_topic('','admin_guest_product_submission')===[],'revoked store access invalidates previously selected topic');
$meta[4]['kiwe_guest_application_v1']='';check(!in_array(4,$prefs->audience_user_ids_for_topic('','guest_product_status'),true),'missing Guest application invalidates product-status audience');
$channel_available=true;$channel_calls=[];$channel_result=true;
foreach($rows as &$row){if((int)$row['user_id']===2)$row['channels']='["email"]';}unset($row);
$meta[2]['dsa_notification_preferences']=['channels'=>['email'],'topics'=>['admin_guest_submission'],'topicChannels'=>['admin_guest_submission'=>['email']]];
$actor=2;$events->dispatch_event('admin_guest_submission',['id'=>'delivery-accepted','title'=>'Review ready','message'=>'A new article awaits review.','url'=>'https://site.test/wp-admin/admin.php?page=kiwe-guests']);
$accepted=array_values(array_filter(inbox(2),fn($item)=>($item['id']??'')==='delivery-accepted'));
check(count($channel_calls)===1&&count($accepted)===1&&($accepted[0]['deliveries'][0]['channel']??'')==='email'&&($accepted[0]['deliveries'][0]['status']??'')==='accepted','configured email acceptance is recorded on the recipient inbox item');
$channel_result=new WP_Error('mail_failed','Provider rejected the message.');
$events->dispatch_event('admin_guest_submission',['id'=>'delivery-failed','title'=>'Review failed delivery','message'=>'A second article awaits review.']);
$failed=array_values(array_filter(inbox(2),fn($item)=>($item['id']??'')==='delivery-failed'));
check(count($failed)===1&&($failed[0]['deliveries'][0]['status']??'')==='failed'&&($failed[0]['deliveries'][0]['detail']??'')==='The configured service did not accept this update.','provider failure is retained as generic recipient-visible history');
$channel_available=false;$events->dispatch_event('admin_guest_submission',['id'=>'delivery-unavailable','title'=>'Review unavailable delivery','message'=>'A third article awaits review.']);
$unavailable=array_values(array_filter(inbox(2),fn($item)=>($item['id']??'')==='delivery-unavailable'));
check(count($unavailable)===1&&($unavailable[0]['deliveries'][0]['status']??'')==='unavailable','disabled provider is reported without attempting another send');
echo "$count product notification tests passed".($noWoo?' without Woo.':'.')."\n";
}
