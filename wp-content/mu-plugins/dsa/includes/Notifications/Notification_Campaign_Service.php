<?php

namespace DSA\Notifications;

use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One private, consent-checked campaign at a time; cron contacts three users per pass. */
final class Notification_Campaign_Service {
	private const HISTORY_OPTION = 'dsa_notification_campaign_history';
	private const ACTIVE_OPTION = 'dsa_notification_campaign_active';
	private const LOCK_OPTION = 'dsa_notification_campaign_lock';
	private const HOOK = 'dsa_notification_campaign_batch';
	private $preferences;
	private $channels;
	private $push;

	public function __construct( Notification_Preference_Service $preferences, Channel_Service $channels, Push_Service $push ) {
		$this->preferences = $preferences;
		$this->channels = $channels;
		$this->push = $push;
	}

	public function register(): void { add_action( self::HOOK, [ $this, 'run_batch' ] ); }
	public function topic(): string { return \DSA\Apps\Store_App_Service::enabled() ? 'new_offer' : 'newsletter'; }

	public function preview( string $channel ): array {
		$channel = sanitize_key( $channel );
		$topic = $this->topic();
		$audience = $this->preferences->campaign_audience( $channel, $topic );
		$ids = $audience['ids'];
		return [
			'channel'=>$channel, 'topic'=>$topic, 'count'=>count( $ids ),
			'limited'=>(bool) $audience['limited'], 'sampled'=>(bool) $audience['sampled'],
			'ready'=>$this->channel_ready( $channel ), 'enabled'=>$this->preferences->enabled(),
			'expected'=>hash_hmac( 'sha256', wp_json_encode( [ $channel, $topic, $ids ] ), wp_salt( 'auth' ) ),
		];
	}

	/** Legacy technical form uses this same queued, consent-aware path. */
	public function send( string $channel, string $subject, string $message ): array {
		$preview = $this->preview( $channel );
		$result = $this->start( $channel, $subject, $message, $preview['expected'] );
		return is_wp_error( $result ) ? [ 'ok'=>false, 'message'=>$result->get_error_message(), 'sent'=>0, 'failed'=>0 ] : $result;
	}

	public function start( string $channel, string $subject, string $message, string $expected ) {
		$channel = sanitize_key( $channel );
		$subject = trim( sanitize_text_field( $subject ) );
		$message = trim( sanitize_textarea_field( $message ) );
		if ( ! in_array( $channel, [ 'email', 'whatsapp', 'sms', 'app' ], true ) || '' === $subject || '' === $message || strlen( $subject ) > 320 || strlen( $message ) > 3600 ) {
			return new \WP_Error( 'kiwe_campaign_input', __( 'Choose a valid channel, title and message.', 'dsa' ), [ 'status'=>400 ] );
		}
		$preview = $this->preview( $channel );
		if ( ! hash_equals( $preview['expected'], $expected ) ) return new \WP_Error( 'kiwe_campaign_stale', __( 'The opted-in audience changed. Review it before sending.', 'dsa' ), [ 'status'=>409 ] );
		if ( ! $preview['enabled'] || ! $preview['ready'] ) return new \WP_Error( 'kiwe_campaign_unavailable', __( 'This delivery channel is not ready.', 'dsa' ), [ 'status'=>409 ] );
		if ( 0 === $preview['count'] ) return new \WP_Error( 'kiwe_campaign_empty', __( 'No opted-in account with a usable contact matches this campaign.', 'dsa' ), [ 'status'=>409 ] );
		if ( $preview['limited'] || $preview['sampled'] ) return new \WP_Error( 'kiwe_campaign_size', __( 'The audience exceeds this shared-host campaign limit. No message was sent.', 'dsa' ), [ 'status'=>409 ] );
		$fresh = $this->preferences->campaign_audience( $channel, $preview['topic'] );
		$ids = $fresh['ids'];
		if ( $fresh['limited'] || $fresh['sampled'] || ! hash_equals( $expected, hash_hmac( 'sha256', wp_json_encode( [ $channel, $preview['topic'], $ids ] ), wp_salt( 'auth' ) ) ) ) return new \WP_Error( 'kiwe_campaign_stale', __( 'The opted-in audience changed. Review it before sending.', 'dsa' ), [ 'status'=>409 ] );
		$job = [ 'id'=>strtolower( wp_generate_password( 20, false, false ) ), 'channel'=>$channel, 'topic'=>$preview['topic'], 'subject'=>$subject, 'message'=>$message, 'ids'=>$ids, 'cursor'=>0, 'sent'=>0, 'failed'=>0, 'skipped'=>0, 'unknown'=>0, 'created'=>time(), 'status'=>'queued' ];
		if ( ! add_option( self::ACTIVE_OPTION, $job, '', false ) ) return new \WP_Error( 'kiwe_campaign_busy', __( 'A campaign is already running. Review its status first.', 'dsa' ), [ 'status'=>409 ] );
		$scheduled = wp_schedule_single_event( time() + 5, self::HOOK, [ $job['id'] ], true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			delete_option( self::ACTIVE_OPTION );
			return new \WP_Error( 'kiwe_campaign_schedule', __( 'WordPress could not schedule this campaign. Nothing was sent.', 'dsa' ), [ 'status'=>503 ] );
		}
		return [ 'ok'=>true, 'queued'=>true, 'message'=>__( 'Campaign queued in batches of three opted-in accounts.', 'dsa' ), 'job'=>$this->public_job( $job ) ];
	}

	public function run_batch( string $id = '' ): void {
		$job = get_option( self::ACTIVE_OPTION, [] );
		if ( ! is_array( $job ) || '' === $id || ! hash_equals( (string) ( $job['id'] ?? '' ), $id ) ) return;
		if ( ! empty( $job['runAt'] ) && (int) $job['runAt'] > time() ) return;
		if ( ! add_option( self::LOCK_OPTION, time() + 90, '', false ) ) {
			if ( (int) get_option( self::LOCK_OPTION, 0 ) >= time() ) return;
			delete_option( self::LOCK_OPTION );
			if ( ! add_option( self::LOCK_OPTION, time() + 90, '', false ) ) return;
		}
		try {
			$job = get_option( self::ACTIVE_OPTION, [] );
			if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['id'] ?? '' ), $id ) ) return;
			for ( $step = 0; $step < 3 && (int) $job['cursor'] < count( $job['ids'] ); $step++ ) {
				$target = $job['ids'][ $job['cursor'] ];
				// Commit the cursor before network I/O: a crashed worker cannot resend.
				$job['cursor']++;
				$job['unknown']++;
				$job['status'] = 'sending';
				if ( ! update_option( self::ACTIVE_OPTION, $job, false ) ) return;
				$committed = get_option( self::ACTIVE_OPTION, [] );
				if ( ! is_array( $committed ) || (int) ( $committed['cursor'] ?? -1 ) !== (int) $job['cursor'] ) return;
				try { $result = ! empty( $job['plan'] ) ? $this->deliver_plan_one( $job, $target ) : $this->deliver_one( $job, absint( $target ) ); }
				catch ( \Throwable $error ) { $result = 'unknown'; }
				if ( 'unknown' !== $result ) { $job['unknown']--; $job[ $result ]++; }
				if ( ! update_option( self::ACTIVE_OPTION, $job, false ) ) return;
			}
			if ( (int) $job['cursor'] >= count( $job['ids'] ) ) {
				$job['status'] = 'complete';
				$this->remember( $job );
				do_action( 'kiwe_campaign_finished', $this->public_job( $job ) );
				delete_option( self::ACTIVE_OPTION );
			} else {
				$scheduled = wp_schedule_single_event( time() + 5, self::HOOK, [ $id ], true );
				if ( false === $scheduled || is_wp_error( $scheduled ) ) {
					$job['status'] = 'stalled';
					update_option( self::ACTIVE_OPTION, $job, false );
				}
			}
		} finally { delete_option( self::LOCK_OPTION ); }
	}

	private function deliver_one( array $job, int $user_id ): string {
		$channel = $job['channel'];
		$topic = $job['topic'];
		if ( $topic !== $this->topic() || ! $this->preferences->enabled() || ! $this->channel_ready( $channel ) || ! $this->preferences->user_accepts( $user_id, $channel, $topic ) ) return 'skipped';
		if ( 'app' === $channel ) {
			$stored = get_user_meta( $user_id, 'dsa_notification_preferences', true );
			if ( ! is_array( $stored ) || 'granted' !== sanitize_key( (string) ( $stored['browserPermission'] ?? '' ) ) ) return 'skipped';
			$result = $this->push->send_to_users( [ $user_id ], $job['subject'], $job['message'], home_url( '/' ) );
			return (int) ( $result['sent'] ?? 0 ) > 0 && empty( $result['failed'] ) ? 'sent' : 'failed';
		}
		$recipient = $this->preferences->contact_for_user( $user_id, $channel );
		if ( '' === $recipient ) return 'skipped';
		$context = [ 'purpose'=>'notification_campaign', 'user_id'=>$user_id ];
		if ( 'whatsapp' === $channel && $this->preferences->user_accepts( $user_id, 'email', $topic ) ) {
			$context['fallback_email'] = $this->preferences->contact_for_user( $user_id, 'email' );
			$context['fallback_email_allowed'] = true;
		}
		$body = 'email' === $channel ? $job['message'] : $job['subject'] . "\n\n" . $job['message'];
		$result = $this->channels->send( $channel, $recipient, $job['subject'], $body, $context );
		return is_wp_error( $result ) || false === $result ? 'failed' : 'sent';
	}

	public function status(): array {
		$job = get_option( self::ACTIVE_OPTION, [] );
		return [ 'active'=>is_array( $job ) && ! empty( $job['id'] ) ? $this->public_job( $job ) : null, 'history'=>$this->history() ];
	}
	public function resume( string $id ) {
		$job = get_option( self::ACTIVE_OPTION, [] );
		if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['id'] ?? '' ), $id ) ) return new \WP_Error( 'kiwe_campaign_missing', __( 'This campaign is no longer active. Refresh its status.', 'dsa' ), [ 'status'=>409 ] );
		if ( wp_next_scheduled( self::HOOK, [ $id ] ) ) return [ 'ok'=>true, 'job'=>$this->public_job( $job ), 'message'=>__( 'This campaign is already scheduled.', 'dsa' ) ];
		if ( 'stalled' !== ( $job['status'] ?? '' ) && time() - (int) ( $job['created'] ?? 0 ) < 90 ) return new \WP_Error( 'kiwe_campaign_wait', __( 'Wait for the scheduled batch before checking again.', 'dsa' ), [ 'status'=>409 ] );
		$scheduled = wp_schedule_single_event( max( time()+5,(int)($job['runAt']??0) ), self::HOOK, [ $id ], true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) return new \WP_Error( 'kiwe_campaign_schedule', __( 'WordPress still cannot schedule delivery.', 'dsa' ), [ 'status'=>503 ] );
		$job['status'] = 'queued';
		update_option( self::ACTIVE_OPTION, $job, false );
		return [ 'ok'=>true, 'job'=>$this->public_job( $job ), 'message'=>__( 'Remaining recipients are scheduled. Earlier uncertain attempts will not repeat.', 'dsa' ) ];
	}
	private function public_job( array $job ): array {
		$public = [ 'id'=>(string) $job['id'], 'channel'=>(string) $job['channel'], 'topic'=>(string) $job['topic'], 'subject'=>(string) $job['subject'], 'status'=>(string) $job['status'], 'count'=>count( (array) $job['ids'] ), 'attempted'=>(int) $job['cursor'], 'sent'=>(int) $job['sent'], 'failed'=>(int) $job['failed'], 'skipped'=>(int) $job['skipped'], 'unknown'=>(int) $job['unknown'], 'created'=>(int) $job['created'] ];
		if ( ! empty($job['plan']) ) { $public['campaignId']=$job['plan']['draft']['id'];$public['channels']=$job['plan']['draft']['channels'];$public['runAt']=(int)($job['runAt']??0);$public['expected']=hash_hmac('sha256',wp_json_encode($public),wp_salt('auth')); }
		return $public;
	}

	/** Reviewed multi-channel campaigns share the legacy queue and its commit-before-I/O worker. */
	public function start_plan(array $plan){
		$d=$plan['draft']??[];$targets=$plan['targets']??[];$run=(int)($d['scheduledAt']??0);
		if(!$this->preferences->enabled()||!$targets||count($targets)>500||empty($d['id'])||empty($d['copies'])||($run&&$run<time()+60))return new \WP_Error('kiwe_campaign_plan','Review a current, reachable campaign before queuing it.',['status'=>409]);
		$job=['id'=>strtolower(wp_generate_password(20,false,false)),'channel'=>count($d['channels'])===1?$d['channels'][0]:'multi','topic'=>$d['topic'],'subject'=>$d['name'],'message'=>'','ids'=>$targets,'plan'=>$plan,'cursor'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'unknown'=>0,'created'=>time(),'runAt'=>$run?:time()+5,'status'=>'queued'];
		if(!add_option(self::ACTIVE_OPTION,$job,'',false))return new \WP_Error('kiwe_campaign_busy','Another campaign is active. Review it before sending this one.',['status'=>409]);
		$scheduled=wp_schedule_single_event($job['runAt'],self::HOOK,[$job['id']],true);if(false===$scheduled||is_wp_error($scheduled)){delete_option(self::ACTIVE_OPTION);return new \WP_Error('kiwe_campaign_schedule','WordPress could not schedule this campaign. Nothing was sent.',['status'=>503]);}
		return ['ok'=>true,'queued'=>true,'job'=>$this->public_job($job),'message'=>$run?'Campaign scheduled. Consent and eligibility will be checked again before delivery.':'Campaign queued. Each recipient gets one selected, opted-in channel.'];
	}
	private function deliver_plan_one(array $job,array $target):string{
		$plan=$job['plan'];$d=$plan['draft'];$uid=(int)$target['user_id'];$channel=$target['channel'];
		if(!$this->preferences->enabled()||!$this->channel_ready($channel)||!in_array($channel,$d['channels'],true)||!in_array($d['topic'],['newsletter','new_post','new_offer'],true)||('new_offer'===$d['topic']&&!\DSA\Apps\Store_App_Service::enabled()))return 'skipped';
		if($d['sourceId']){$source=get_post((int)$d['sourceId']);if(!$source||'publish'!==$source->post_status||!hash_equals($plan['url'],(string)get_permalink($source)))return 'skipped';if('post'===$source->post_type&&array_map('intval',wp_get_post_categories((int)$d['sourceId']))!==$plan['categories'])return 'skipped';}
		if($uid){
			if(!get_userdata($uid)||\DSA\Access\Team_Workspace_Service::suspended($uid)||(is_multisite()&&!is_user_member_of_blog($uid))||!$this->preferences->user_accepts($uid,$channel,$d['topic']))return 'skipped';
			$groups=new \DSA\Access\User_Group_Service();$group=$groups->find($d['group']);if(is_wp_error($group))return 'skipped';$active='app'===$channel?$this->push->active_user_ids([$uid]):[];
			if($group['smart']){if(!$groups->smart_member($uid,$d['group'],'app'===$d['group']?$this->push->active_user_ids([$uid]):[]))return 'skipped';}elseif(!in_array($d['group'],$groups::memberships($uid),true))return 'skipped';
			$p=get_user_meta($uid,'dsa_notification_preferences',true);if('new_post'===$d['topic']&&!empty($p['postCategories'])&&!array_intersect(array_map('intval',(array)$p['postCategories']),$plan['categories']))return 'skipped';
			if('app'===$channel&&('granted'!==($p['browserPermission']??'')||!in_array($uid,$active,true)))return 'skipped';
		}else{if('app'!==$channel||'app'!==$d['group']||!$this->preferences->campaign_visitor_accepts($target['visitor_hash'],$d['topic'],$plan['categories'])||!$this->push->active_visitor_hashes([$target['visitor_hash']]))return 'skipped';}
		$workspace=new \DSA\Access\Campaign_Workspace_Service($this->preferences,$this);$copy=$workspace->render($d,$target,$plan['url'],(int)$plan['expires']);
		if('app'===$channel){$result=$uid?$this->push->send_to_users([$uid],$copy['title'],$copy['body'],$copy['url']):$this->push->send_to_visitor_hash($target['visitor_hash'],$copy['title'],$copy['body'],$copy['url']);return (int)($result['sent']??0)>0?'sent':'failed';}
		$recipient=\DSA\PhoneKey\PhoneKey_Bridge::campaign_contact($uid,$channel);if(!$recipient||empty($target['contactProof'])||!hash_equals((string)$target['contactProof'],hash_hmac('sha256',$channel.':'.$recipient,wp_salt('auth'))))return 'skipped';
		$context=['purpose'=>'notification_campaign','topic'=>$d['topic'],'user_id'=>$uid,'campaign_id'=>$d['id']];
		if('email'===$channel){$stop=Campaign_Unsubscribe_Service::url($uid,'',$d['topic'],$channel,(int)$plan['expires']);$context['headers']=['List-Unsubscribe: <'.$stop.'>','List-Unsubscribe-Post: List-Unsubscribe=One-Click'];}
		// Only the reviewed route is attempted. A timeout never triggers an unreviewed second delivery.
		$result=$this->channels->send($channel,$recipient,$copy['title'],$copy['body'],$context);
		if(is_wp_error($result)&&in_array($result->get_error_code(),['http_request_failed','phonekey_whatsapp_delivery'],true))return 'unknown';
		return is_wp_error($result)||false===$result?'failed':'sent';
	}
	public function cancel(string $id,string $expected){
		if(!add_option(self::LOCK_OPTION,time()+90,'',false))return new \WP_Error('kiwe_campaign_working','A delivery batch is active. Refresh its status before cancelling.',['status'=>409]);
		try{$job=get_option(self::ACTIVE_OPTION,[]);if(!is_array($job)||empty($job['plan'])||($job['id']??'')!==$id||!hash_equals($this->public_job($job)['expected'],$expected))return new \WP_Error('kiwe_campaign_stale','The campaign status changed. Refresh before cancelling.',['status'=>409]);$job['status']='cancelled';$this->remember($job);delete_option(self::ACTIVE_OPTION);wp_clear_scheduled_hook(self::HOOK,[$id]);do_action('kiwe_campaign_finished',$this->public_job($job));return ['ok'=>true,'job'=>$this->public_job($job),'message'=>'Remaining recipients cancelled. Earlier attempts stay in the history.'];}finally{delete_option(self::LOCK_OPTION);}
	}
	public function history(): array { $history = get_option( self::HISTORY_OPTION, [] ); return is_array( $history ) ? array_slice( $history, 0, 20 ) : []; }
	public function push_summary(): array { return $this->push->audience_summary(); }
	public function channel_ready( string $channel ): bool { return 'app' === $channel ? $this->push->ready() : $this->channels->available_for_campaign( sanitize_key( $channel ) ); }
	private function remember( array $job ): void { $history = $this->history(); array_unshift( $history, $this->public_job( $job ) ); update_option( self::HISTORY_OPTION, array_slice( $history, 0, 20 ), false ); }
}
