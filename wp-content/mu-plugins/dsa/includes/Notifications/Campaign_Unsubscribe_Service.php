<?php
namespace DSA\Notifications;
if ( ! defined('ABSPATH') ) exit;

/** A signed decline capability; GET never changes preferences or reveals a recipient. */
final class Campaign_Unsubscribe_Service {
	private Notification_Preference_Service $preferences;
	public function __construct(Notification_Preference_Service $preferences){$this->preferences=$preferences;}
	public function register():void{add_action('template_redirect',[$this,'landing'],0);}
	public static function url(int $id,string $hash,string $topic,string $channel,int $expires):string{
		$t=array_search($topic,['newsletter','new_offer','new_post'],true);$c=array_search($channel,['email','sms','whatsapp','app'],true);
		if(false===$t||false===$c)return '';
		$payload=($id?'u'.base_convert((string)$id,10,36):'v'.$hash).'.'.$t.$c.'.'.base_convert((string)$expires,10,36);
		$token=$payload.'.'.substr(hash_hmac('sha256',$payload,wp_salt('auth')),0,32);
		return add_query_arg('kiwe_unsubscribe',$token,home_url('/'));
	}
	public static function decode(string $token){
		if(!preg_match('/^((?:u[a-z0-9]{1,12}|v[a-f0-9]{64}))\.([0-2])([0-3])\.([a-z0-9]{1,10})\.([a-f0-9]{32})$/',$token,$m))return false;
		$payload=substr($token,0,-33);if(!hash_equals(substr(hash_hmac('sha256',$payload,wp_salt('auth')),0,32),$m[5])||(int)base_convert($m[4],36,10)<time())return false;
		return ['id'=>'u'===$m[1][0]?(int)base_convert(substr($m[1],1),36,10):0,'hash'=>'v'===$m[1][0]?substr($m[1],1):'','topic'=>['newsletter','new_offer','new_post'][(int)$m[2]],'channel'=>['email','sms','whatsapp','app'][(int)$m[3]]];
	}
	public function landing():void{
		if(!isset($_GET['kiwe_unsubscribe']))return;
		$token=is_string($_GET['kiwe_unsubscribe'])?(string)wp_unslash($_GET['kiwe_unsubscribe']):'';$scope=self::decode($token);nocache_headers();header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
		if(!$scope){status_header(400);wp_die(esc_html__('This unsubscribe link is invalid or expired. Open your notification preferences on the website.','dsa'),'Unsubscribe',['response'=>400]);}
		$done=false;if('POST'===($_SERVER['REQUEST_METHOD']??'')){
			// RFC 8058 mail clients use the same narrow signed capability. Browser users explicitly submit the form.
			$done=$this->preferences->decline_campaign($scope['id'],$scope['hash'],$scope['topic'],$scope['channel']);if(!$done)status_header(503);
		}
		?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notification preferences</title><style>body{font:17px/1.6 system-ui;background:#f8f7f3;color:#203b30;padding:24px}main{max-width:600px;margin:10vh auto;padding:30px;border:1px solid #80998b;border-radius:12px}button,a{font:inherit;padding:12px;color:inherit}button{background:#203b30;color:white;border:0;border-radius:5px}</style></head><body><main><h1><?php echo esc_html($done?'Unsubscribed.':'Your notification choices.'); ?></h1><p><?php echo esc_html($done?'This topic will no longer arrive through this channel. Your other choices remain available.':'Stop this topic through '.('app'===$scope['channel']?'browser notifications':$scope['channel']).'?'); ?></p><?php if(!$done): ?><form method="post"><button name="unsubscribe" value="1">Unsubscribe</button></form><?php endif; ?><p><a href="<?php echo esc_url(home_url('/')); ?>">Return to website</a></p></main></body></html><?php exit;
	}
}
