<?php
declare(strict_types=1);
namespace DSA\Notifications {
	class Notification_Preference_Service {
		public array $consent=[1=>true,2=>true,3=>true];
		public function enabled():bool{return true;}
		public function user_accepts(int $id,string $channel,string $topic=''):bool{return $this->consent[$id]??false;}
	}
	class Push_Service {
		public function ready():bool{return true;}
		public function active_user_ids(array $ids):array{return $ids;}
	}
	class Campaign_Unsubscribe_Service {
		public static function url(int $id,string $hash,string $topic,string $channel,int $expires):string{return 'https://example.test/?unsubscribe=fixture';}
	}
}
namespace DSA\Communications {
	class Channel_Service {
		public array $calls=[];
		public function available_for_campaign(string $channel):bool{return true;}
		public function send(string $channel,string $to,string $title,string $body,array $context=[]){$this->calls[]=compact('channel','to','title','body','context');return true;}
	}
}
namespace DSA\Access {
	class Team_Workspace_Service {public static function suspended(int $id):bool{return false;}}
	class User_Group_Service {
		public function find(string $id):array{return ['id'=>$id,'smart'=>false];}
		public static function memberships(int $id):array{return ['g-fixture'];}
	}
	class Campaign_Workspace_Service {
		public function __construct(...$args){}
		public function render(array $draft,array $target,string $url,int $expires):array{return ['title'=>$draft['copies'][$target['channel']]['title'],'body'=>$draft['copies'][$target['channel']]['body'],'url'=>$url];}
	}
}
namespace DSA\PhoneKey {
	class PhoneKey_Bridge {
		public static array $contacts=[1=>'one@example.test',2=>'two@example.test',3=>'three@example.test'];
		public static function campaign_contact(int $id,string $channel):string{return self::$contacts[$id]??'';}
	}
}
namespace {
	define('ABSPATH',__DIR__.'/');
	define('DAY_IN_SECONDS',86400);
	class WP_Error {public function __construct(private string $code,private string $message,private array $data=[]){}public function get_error_code():string{return $this->code;}}
	$options=[];$events=[];$checks=0;
	function check($yes,string $label):void{$GLOBALS['checks']++;if(!$yes)throw new \RuntimeException($label);}
	function sanitize_key($value){return (string)$value;}
	function wp_salt($what){return 'fixture-secret';}
	function wp_json_encode($value){return json_encode($value);}
	function wp_generate_password($length,$special,$extra){return str_repeat('a',$length);}
	function get_option($name,$fallback=false){return $GLOBALS['options'][$name]??$fallback;}
	function add_option($name,$value,$deprecated='',$autoload=false){if(array_key_exists($name,$GLOBALS['options']))return false;$GLOBALS['options'][$name]=$value;return true;}
	function update_option($name,$value,$autoload=false){$GLOBALS['options'][$name]=$value;return true;}
	function delete_option($name){unset($GLOBALS['options'][$name]);return true;}
	function wp_schedule_single_event($at,$hook,$args=[],$error=false){$GLOBALS['events'][]=[$at,$hook,$args];return true;}
	function wp_next_scheduled($hook,$args=[]){return false;}
	function wp_clear_scheduled_hook($hook,$args=[]){$GLOBALS['events']=array_values(array_filter($GLOBALS['events'],static fn($event)=>$event[1]!==$hook||$event[2]!==$args));return true;}
	function is_wp_error($value){return $value instanceof WP_Error;}
	function get_userdata($id){return in_array($id,[1,2,3],true)?(object)['ID'=>$id]:false;}
	function is_multisite(){return false;}
	function get_user_meta($id,$key,$single=true){return [];}
	function absint($value){return abs((int)$value);}
	function do_action($name,...$args){$GLOBALS['finished'][]=[$name,$args];}
	function __($text,$domain=''){return $text;}
	function home_url($path){return 'https://example.test'.$path;}
	require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/Notification_Campaign_Service.php';
	$p=new \DSA\Notifications\Notification_Preference_Service();$c=new \DSA\Communications\Channel_Service();$push=new \DSA\Notifications\Push_Service();
	$s=new \DSA\Notifications\Notification_Campaign_Service($p,$c,$push);
	$draft=['id'=>'c-fixture','name'=>'Fixture campaign','type'=>'newsletter','topic'=>'newsletter','group'=>'g-fixture','channels'=>['email'],'copies'=>['email'=>['title'=>'Fixture story','body'=>'Fixture body']],'sourceId'=>0,'scheduledAt'=>0];
	$target=static fn($id)=>['user_id'=>$id,'visitor_hash'=>'','channel'=>'email','contactProof'=>hash_hmac('sha256','email:'.\DSA\PhoneKey\PhoneKey_Bridge::$contacts[$id],wp_salt('auth'))];
	$plan=['draft'=>$draft,'targets'=>[$target(1),$target(2),$target(3)],'url'=>'https://example.test/','categories'=>[],'expires'=>time()+DAY_IN_SECONDS];
	$queued=$s->start_plan($plan);check(!is_wp_error($queued)&&$queued['queued']&&$queued['job']['count']===3,'Reviewed plan queues bounded recipients');
	check(is_wp_error($s->start_plan($plan)),'Global queue refuses a concurrent plan');
	$p->consent[2]=false;\DSA\PhoneKey\PhoneKey_Bridge::$contacts[3]='different@example.test';
	// Simulate the queued cron timestamp becoming due without a real wait or transport.
	$options['dsa_notification_campaign_active']['runAt']=time()-1;
	$s->run_batch($queued['job']['id']);$status=$s->status();
	check($status['active']===null&&$status['history'][0]['sent']===1&&$status['history'][0]['skipped']===2,'Worker skips revoked consent and changed verified contact: '.json_encode($status));
	check(count($c->calls)===1&&$c->calls[0]['to']==='one@example.test','Only approved unchanged contact reaches transport');
	check(!str_contains(json_encode($status),'one@example.test')&&!str_contains(json_encode($status),'Fixture body'),'Public delivery history excludes contact and message body');
	$draft['scheduledAt']=time()+120;$plan['draft']=$draft;$future=$s->start_plan($plan);check(!is_wp_error($future)&&$future['job']['runAt']>$future['job']['created'],'Future schedule is recorded');
	$s->run_batch($future['job']['id']);check($s->status()['active']['attempted']===0,'Early cron cannot deliver a future campaign');
	check(is_wp_error($s->cancel($future['job']['id'],str_repeat('0',64))),'Cancel requires current exact status proof');
	$proof=$s->status()['active']['expected'];$cancelled=$s->cancel($future['job']['id'],$proof);
	check(($cancelled['job']['status']??'')==='cancelled'&&$s->status()['active']===null&&count($c->calls)===1,'Cancelled job keeps prior history and sends no remaining message');
	echo json_encode(['passed'=>true,'checks'=>$checks])."\n";
}
