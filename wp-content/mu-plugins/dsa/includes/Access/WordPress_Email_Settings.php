<?php
namespace DSA\Access;
if(!defined('ABSPATH'))exit;

/** A private projection of native Discussion email switches, never a second delivery engine. */
final class WordPress_Email_Settings {
	private const OPTIONS=['comments_notify','moderation_notify'];
	public static function allowed(): bool {return current_user_can('kiwe_manage_notification_policy')&&current_user_can('moderate_comments');}
	private function error(string $code,string $message,int $status=409){return new \WP_Error('kiwe_site_emails_'.$code,$message,['status'=>$status]);}
	public function read(){
		if(!self::allowed())return $this->error('forbidden','Only the client Administrator or designer can change site email settings.',403);
		$raw=[];$values=[];foreach(self::OPTIONS as$key){$raw[$key]=get_option($key,null);if(!in_array($raw[$key],[null,false,true,0,1,'0','1'],true))return $this->error('custom','Custom email settings need your designer to review them.');$values[$key]=(bool)$raw[$key];}
		return ['values'=>$values,'expected'=>hash_hmac('sha256',serialize($raw),wp_salt('nonce'))];
	}
	public function save(array $input){
		if(!self::allowed())return $this->error('forbidden','Your account cannot change site-wide email settings.',403);
		if(array_diff(array_keys($input),['key','value','expected','acceptEffect'])||!in_array($input['key']??null,self::OPTIONS,true)||!is_bool($input['value']??null)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('input','Confirm one site email setting.',400);
		global $wpdb;$lock='kiwe-site-email-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another site email change is running. Refresh and review.');
		try{
			$before=$this->read();if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Site email settings changed. Refresh and review.');$key=$input['key'];if($before['values'][$key]===$input['value'])return $before;
			// WordPress owns sanitization, option hooks/cache and actual comment delivery.
			update_option($key,$input['value']?'1':'0');$after=$this->read();if(is_wp_error($after))return $after;if($after['values']!==array_replace($before['values'],[$key=>$input['value']]))return $this->error('readback','The saved email setting could not be confirmed. Refresh before retrying.');return $after;
		}catch(\Throwable $e){return $this->error('write','Site email saving could not be confirmed. Refresh and review.');}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
}
