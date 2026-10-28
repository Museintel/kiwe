<?php
declare(strict_types=1);
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
function get_option($key,$default=false){return $default;}

class WP_Error {public function __construct(public string $code,public string $message,public array $data=[]){ }public function get_error_data(){return $this->data;}}
class WP_Comment {public int $comment_ID;public int $comment_post_ID;public int $user_id=0;public int $comment_parent=0;public string $comment_date_gmt='2026-10-28 00:00:00';public string $comment_type;public string $comment_approved;public string $comment_author;public string $comment_author_email;public string $comment_content;public function __construct(array $data){foreach($data as$key=>$value)$this->$key=$value;}}
function is_wp_error($value){return $value instanceof WP_Error;}
$actor=1;$caps=[1=>['moderate_comments','kiwe_manage_context','read_post'],2=>['moderate_comments','read_post'],3=>['read_post']];
$comments=[
 11=>new WP_Comment(['comment_ID'=>11,'comment_post_ID'=>21,'comment_type'=>'comment','comment_approved'=>'0','comment_author'=>'Pending person','comment_author_email'=>'private@example.test','comment_content'=>'A pending useful comment.']),
 12=>new WP_Comment(['comment_ID'=>12,'comment_post_ID'=>22,'comment_type'=>'review','comment_approved'=>'1','comment_author'=>'Reviewer','comment_author_email'=>'review@example.test','comment_content'=>'A real product review.']),
];
$posts=[21=>(object)['ID'=>21,'post_title'=>'Public article','post_status'=>'publish','post_type'=>'post'],22=>(object)['ID'=>22,'post_title'=>'Public product','post_status'=>'publish','post_type'=>'product']];$ratings=[12=>4];$writes=0;$locked=false;
function get_current_user_id(){return $GLOBALS['actor'];}
function is_multisite(){return false;}
function get_post_type($id){return $GLOBALS['posts'][$id]->post_type??'';}
function current_user_can($cap,...$args){return in_array($cap,$GLOBALS['caps'][$GLOBALS['actor']]??[],true);}
function get_comment($id){return $GLOBALS['comments'][$id]??null;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_the_title($post){return $post->post_title??'';}
function get_comment_link($comment){return 'https://test.example/item#comment-'.$comment->comment_ID;}
function get_comment_meta($id,$key,$single=true){return $key==='rating'?($GLOBALS['ratings'][$id]??0):'';}
function wp_set_comment_status($id,$status,$wp_error=false){$map=['hold'=>'0','approve'=>'1','spam'=>'spam','trash'=>'trash'];if(!isset($GLOBALS['comments'][$id],$map[$status]))return false;$GLOBALS['comments'][$id]->comment_approved=$map[$status];$GLOBALS['writes']++;return true;}
function get_comments($args){$items=array_values($GLOBALS['comments']);$status=$args['status']??'all';if($status!=='all'){$map=['hold'=>'0','approve'=>'1','spam'=>'spam','trash'=>'trash'];$items=array_values(array_filter($items,fn($v)=>(string)$v->comment_approved===($map[$status]??$status)));}$types=$args['type__in']??[];if($types)$items=array_values(array_filter($items,fn($v)=>in_array((string)$v->comment_type,$types,true)));$meta=$args['meta_key']??'';if($meta===DSA\Onboarding\Context_Selection_Service::TESTIMONIAL_META)$items=array_values(array_filter($items,fn($v)=>DSA\Onboarding\Context_Selection_Service::selected('testimonial',$v->comment_ID)));$search=$args['search']??'';if($search!=='')$items=array_values(array_filter($items,fn($v)=>str_contains(strtolower($v->comment_author.' '.$v->comment_content),strtolower($search))));usort($items,fn($a,$b)=>$b->comment_ID<=>$a->comment_ID);return array_slice($items,(int)($args['offset']??0),(int)($args['number']??20));}
function wp_trim_words($text,$count){$words=preg_split('/\s+/',trim($text));return implode(' ',array_slice($words,0,$count));}
function wp_strip_all_tags($text){return strip_tags($text);}
function wp_json_encode($value){return json_encode($value);}
function wp_salt($scheme){return 'fixture-salt';}
function __($value,$domain=null){return $value;}
function WC(){return true;}
$wpdb=new class {public string $prefix='wp_';public function prepare($sql,...$args){return $sql;}public function get_var($sql){return str_contains($sql,'GET_LOCK')&&$GLOBALS['locked']?'0':'1';}};
}
namespace DSA\Access {
final class Team_Workspace_Service {public static function suspended($id){return false;}}
final class Task_Workspace_UI {public static function text($value,$length=200){$value=trim((string)$value);return function_exists('mb_substr')?\mb_substr($value,0,$length):substr($value,0,$length);}public static function id($value){return max(0,(int)$value);}}
}
// This small fixture retains its original native moderation/curation scope. The real discussion
// services, proof, reactions, roles and Store context have separate actual WordPress integration tests.
namespace DSA\PhoneKey {final class PhoneKey_Bridge {public static function verified_accounts(array $ids):array{return [];}}}
namespace DSA\Discussion {
final class Public_Identity {public static function current_name(){return 'Fixture moderator';}}
final class Discussion_Target {public static function get($id){return new \WP_Error('unavailable','No public DSA fixture in this legacy moderation test');}}
final class Discussion_Reaction_Store {public static function ready(){return false;}public static function projection(array $ids,int $actor){return [];}}
final class Discussion_Service {public function current_actor_key(){return str_repeat('f',64);}}
}
namespace DSA\Onboarding {
final class Context_Selection_Service {
 public const TESTIMONIAL_META='_kiwe_design_context_testimonial';private static array $selected=[];
 public static function selected($kind,$id){return !empty(self::$selected[$id]);}
 public static function public_testimonial($comment){return $comment&&$comment->comment_approved==='1'&&($GLOBALS['posts'][$comment->comment_post_ID]->post_status??'')==='publish';}
 public function select($kind,$id,$selected,$expected=false){if(!\current_user_can('kiwe_manage_context')||!\current_user_can('moderate_comments'))return new \WP_Error('forbidden','forbidden');if(self::selected($kind,$id)!==$expected)return new \WP_Error('stale','stale');if($selected&&!self::public_testimonial(\get_comment($id)))return new \WP_Error('private','private');self::$selected[$id]=$selected;return true;}
}
}
namespace {
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Comment_Moderation_Service.php';
$service=new DSA\Access\Comment_Moderation_Service();$count=0;function check($ok,$name){if(!$ok)throw new RuntimeException('FAIL '.$name);$GLOBALS['count']++;echo "PASS $name\n";}
$pending=$service->listing(['status'=>'hold','type'=>'all']);check(count($pending['items'])===1&&$pending['items'][0]['id']===11&&$pending['hasReviews']&&$pending['canCurate'],'Bounded pending feedback projects native comments and Woo availability');
check(!str_contains(json_encode($pending),'private@example.test')&&!array_key_exists('email',$pending['items'][0]),'Feedback projection excludes private email and account fields');
$before=$pending['items'][0];$approved=$service->change_status(['id'=>11,'status'=>'approve','expected'=>$before['expected'],'acceptEffect'=>true]);check(!is_wp_error($approved)&&$approved['status']==='approve'&&$approved['public']&&$writes===1,'Approval uses native WordPress status and confirms readback');
check(is_wp_error($service->change_status(['id'=>11,'status'=>'trash','expected'=>$before['expected'],'acceptEffect'=>true]))&&$writes===1,'Stale moderation cannot overwrite a newer status');
$chosen=$service->select(['id'=>11,'selected'=>true,'expected'=>false]);check(!is_wp_error($chosen)&&$chosen['selected']&&$chosen['public'],'Approved public comment enters Design Context through shared selector');
$held=$service->change_status(['id'=>11,'status'=>'hold','expected'=>$chosen['expected'],'acceptEffect'=>true]);check($held['selected']&&!$held['public'],'Revoked approval preserves membership but immediately suppresses public testimony');
$testimonials=$service->listing(['status'=>'all','type'=>'testimonial']);check(count($testimonials['items'])===1&&$testimonials['items'][0]['id']===11,'Testimonials collection filters native membership before pagination');
$removed=$service->select(['id'=>11,'selected'=>false,'expected'=>true]);check(!$removed['selected']&&count($service->listing(['status'=>'all','type'=>'testimonial'])['items'])===0,'Unavailable selected testimony can still be removed from its tab');
$content=$comments[11]->comment_content;$actor=2;$record=$service->record(12);check(!is_wp_error($record)&&!$record['canSelect']&&$record['rating']===4,'Moderator without context authority can manage reviews but cannot curate testimonials');
check(is_wp_error($service->select(['id'=>12,'selected'=>true,'expected'=>false])),'Moderator cannot escalate into Design Context curation');
check(is_wp_error($service->listing(['status'=>'all','type'=>'testimonial'])),'Moderator without Design Context cannot request testimonial collection');
$actor=3;check(is_wp_error($service->listing())&&is_wp_error($service->change_status(['id'=>12,'status'=>'hold','expected'=>$record['expected'],'acceptEffect'=>true])),'Non-moderator cannot read or change feedback');
check($comments[11]->comment_content===$content,'Moderation and curation never rewrite customer words');
$actor=1;$locked=true;check(is_wp_error($service->change_status(['id'=>12,'status'=>'hold','expected'=>$record['expected'],'acceptEffect'=>true])),'Busy comment lock fails immediately');
echo "$count feedback moderation checks passed.\n";
}
