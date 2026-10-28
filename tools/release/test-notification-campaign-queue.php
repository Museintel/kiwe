<?php
declare(strict_types=1);
namespace DSA\Notifications {
	class Notification_Preference_Service {
		public array $ids=[1,2,3,4,5]; public array $consent=[1=>true,2=>true,3=>true,4=>true,5=>true]; public bool $on=true; public bool $limited=false;
		public function enabled(): bool { return $this->on; }
		public function campaign_audience(string $channel,string $topic): array { return ['ids'=>$this->ids,'limited'=>$this->limited,'sampled'=>false]; }
		public function user_accepts(int $id,string $channel,string $topic=''): bool { return $this->consent[$id]??false; }
		public function contact_for_user(int $id,string $channel): string { return $channel==='email'?"member$id@example.test":"+1555000000$id"; }
	}
	class Push_Service {
		public int $sent=0; public function ready(): bool {return true;}
		public function audience_summary(): array{return ['ready'=>true,'devices'=>5];}
		public function send_to_users(array $ids,string $title,string $message,string $url=''): array{$this->sent++;return ['sent'=>1,'failed'=>0];}
		public function broadcast(): void {throw new \RuntimeException('Broadcast bypassed consent');}
	}
}
namespace DSA\Communications {
	class Channel_Service {
		public bool $ready=true; public array $calls=[];
		public function available_for_campaign(string $channel): bool {return $this->ready;}
		public function send(string $channel,string $to,string $subject,string $message,array $context=[]){$this->calls[]=compact('channel','to','subject','message','context');return true;}
	}
}
namespace {
	define('ABSPATH',__DIR__.'/');
	require_once __DIR__.'/fixtures/worker-options.php';
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
	class WP_Error {public function __construct(private string $code,private string $message,private array $data=[]){}public function get_error_message(): string{return $this->message;}}
	$options=[];$events=[];$scheduled=true;$checks=0;$meta=[];
	function do_action($hook,...$args) {}
	function ok($condition,$label){$GLOBALS['checks']++;if(!$condition)throw new \RuntimeException($label);}
	function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
	function sanitize_text_field($value){return strip_tags((string)$value);} function sanitize_textarea_field($value){return strip_tags((string)$value);}
	function __($value,$domain=''){return $value;} function wp_json_encode($value){return json_encode($value);}
	function wp_salt($key){return 'fixture-salt';} function wp_generate_password($length,$special,$extra){return str_repeat('a',$length);}
	function add_option($key,$value,$deprecated='',$autoload=false){if(array_key_exists($key,$GLOBALS['options']))return false;$GLOBALS['options'][$key]=$value;return true;}
	function get_option($key,$fallback=false){return $GLOBALS['options'][$key]??$fallback;}
	function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
	function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
	function wp_schedule_single_event($when,$hook,$args=[],$wp_error=false){if(!$GLOBALS['scheduled'])return false;$GLOBALS['events'][]=[$when,$hook,$args];return true;}
	function wp_next_scheduled($hook,$args=[]){foreach($GLOBALS['events'] as $event)if($event[1]===$hook&&$event[2]===$args)return $event[0];return false;}
	function is_wp_error($value){return $value instanceof WP_Error;} function home_url($path){return 'https://example.test'.$path;}
	function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??[];} function absint($value){return abs((int)$value);}
	require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Notifications/Notification_Campaign_Service.php';
	$p=new \DSA\Notifications\Notification_Preference_Service();$c=new \DSA\Communications\Channel_Service();$push=new \DSA\Notifications\Push_Service();
	$s=new \DSA\Notifications\Notification_Campaign_Service($p,$c,$push);
	$view=$s->preview('email');ok($view['topic']==='newsletter'&&$view['count']===5&&strlen($view['expected'])===64,'Bounded explicit topic preview');
	ok(is_wp_error($s->start('email','Hello','Message',str_repeat('0',64))),'Stale audience rejected');
	$queued=$s->start('email','Hello','Private body',$view['expected']);ok(($queued['queued']??false)===true&&count($events)===1,'Queued once');
	ok(is_wp_error($s->start('email','Again','Message',$view['expected'])),'Concurrent campaign rejected');
	$id=$queued['job']['id'];$s->run_batch($id);$live=$s->status()['active'];ok($live['attempted']===3&&$live['sent']===3&&count($c->calls)===3,'Three only per pass');
	$p->consent[4]=false;$s->run_batch($id);$done=$s->status();ok($done['active']===null&&$done['history'][0]['sent']===4&&$done['history'][0]['skipped']===1,'Revocation checked before send');
	ok(count($c->calls)===4&&$push->sent===0,'No broadcast or revoked recipient');
	$record=json_encode($done['history']);ok(!str_contains($record,'Private body')&&!str_contains($record,'member1@')&&!str_contains($record,'ids'),'History hides body, contacts and recipient IDs');
	$p->limited=true;ok(is_wp_error($s->start('email','Oversize','Message',$s->preview('email')['expected'])),'Oversize fails closed');$p->limited=false;
	$c->ready=false;ok(is_wp_error($s->start('email','Unavailable','Message',$s->preview('email')['expected'])),'Unconfigured provider rejected');$c->ready=true;
	$p->on=false;ok(is_wp_error($s->start('email','Paused','Message',$s->preview('email')['expected'])),'Global preference pause enforced');$p->on=true;
	$GLOBALS['scheduled']=false;ok(is_wp_error($s->start('email','Cron down','Message',$s->preview('email')['expected']))&&$s->status()['active']===null,'Failed schedule leaves no active job');
	$GLOBALS['scheduled']=true;$p->ids=[1];$meta[1]['dsa_notification_preferences']=['browserPermission'=>'granted'];
	$app=$s->start('app','App update','Message',$s->preview('app')['expected']);$s->run_batch($app['job']['id']);ok($push->sent===1&&$s->history()[0]['sent']===1,'App campaign targets a consented user instead of broadcasting');
	$wa=$s->start('whatsapp','WhatsApp update','Message',$s->preview('whatsapp')['expected']);$s->run_batch($wa['job']['id']);ok(($c->calls[count($c->calls)-1]['context']['fallback_email_allowed']??false)===true,'Fallback email requires its own explicit consent');
	$p->ids=[1,2,3,4,5];$p->consent[4]=true;$GLOBALS['events']=[];$retry=$s->start('email','Recoverable','Message',$s->preview('email')['expected']);$GLOBALS['events']=[];$GLOBALS['scheduled']=false;
	$s->run_batch($retry['job']['id']);ok($s->status()['active']['status']==='stalled'&&$s->status()['active']['attempted']===3,'Failed next cron leaves reviewed campaign stalled after three');
	$GLOBALS['scheduled']=true;$resumed=$s->resume($retry['job']['id']);$GLOBALS['events']=[];$s->run_batch($retry['job']['id']);ok(($resumed['ok']??false)&&$s->status()['active']===null&&$s->history()[0]['sent']===5,'Resume completes only remaining recipients');
	echo json_encode(['passed'=>true,'checks'=>$checks])."\n";
}
