<?php
namespace DSA\Discussion;

use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Discussion_Reactions {
	public const HEART = '_kiwe_discussion_business_heart_v1';
	public const PIN = '_kiwe_discussion_pinned_v1';
	private const REVISION = '_kiwe_discussion_revision_v1';
	public const MAX_PINS = 3;
	public static function revision( int $post ): string { return (string) get_post_meta( $post, self::REVISION, true ) . ':' . Discussion_Privacy::revision(); }
	public static function changed( int $post ): bool {
		$value = bin2hex( random_bytes( 12 ) ); update_post_meta( $post, self::REVISION, $value );
		return hash_equals( $value, (string) get_post_meta( $post, self::REVISION, true ) );
	}
	public static function native_insert( $id, $comment ): void {
		if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved && in_array( $comment->comment_type, [ '', 'comment','review' ], true ) && ! is_wp_error( Discussion_Target::get( (int) $comment->comment_post_ID ) ) ) self::changed( (int) $comment->comment_post_ID );
	}
	public static function native_transition( string $new, string $old, $comment ): void {
		if ( 'new' !== $old && $new !== $old && ( 'approved' === $new || 'approved' === $old ) && $comment instanceof \WP_Comment && in_array( $comment->comment_type, [ '', 'comment','review' ], true ) && ! is_wp_error( Discussion_Target::get( (int) $comment->comment_post_ID ) ) ) self::changed( (int) $comment->comment_post_ID );
	}
	public static function native_deleted( int $id, $comment ): void { self::native_insert($id,$comment); Discussion_Reaction_Store::delete_comment($id); }
	public static function viewer( int $post ): array {
		$id = get_current_user_id(); $user = $id ? get_userdata( $id ) : null;
		$active = $user && '1' !== (string) get_user_meta( $id, '_kiwe_account_suspended', true );
		$moderator = $active && current_user_can( 'moderate_comments' ) && current_user_can( 'read_post', $post );
		return [ 'canLike'=>(bool) ( $active && ( $moderator || Public_Identity::verified( $id ) ) ), 'canModerate'=>(bool) $moderator,'signedIn'=>(bool) $user ];
	}
	public static function expected( \WP_Comment $comment ): string {
		return hash_hmac( 'sha256', wp_json_encode( [ $comment->comment_ID,$comment->comment_post_ID,$comment->comment_approved,$comment->comment_content,get_comment_meta( $comment->comment_ID,self::HEART,true ),get_comment_meta( $comment->comment_ID,self::PIN,true ) ] ), wp_salt( 'nonce' ) );
	}
	public static function decorate( \WP_Comment $comment, array $counts, array $viewer ): array {
		$heart = (int) get_comment_meta( $comment->comment_ID,self::HEART,true ) > 0;
		$out = [ 'likes'=>(int) ( $counts['likes'] ?? 0 ), 'liked'=>! empty( $counts['liked'] ), 'reported'=>! empty( $counts['reported'] ), 'hearted'=>$heart,'pinned'=>'1' === (string) get_comment_meta( $comment->comment_ID,self::PIN,true ) ];
		if ( $viewer['canModerate'] ) $out['moderation']=[ 'expected'=>self::expected($comment),'reports'=>(int)($counts['reports']??0) ];
		return $out;
	}
	private function error( string $code, string $message, int $status = 409 ): \WP_Error { return new \WP_Error( 'kiwe_discussion_' . $code,$message,[ 'status'=>$status ] ); }
	public function set( int $post_id, int $comment_id, array $input ) {
		if ( array_diff( array_keys($input),[ 'kind','enabled','expected','actorKey' ] ) || ! in_array($input['kind']??null,['like','report','heart','pin'],true) || ! is_bool($input['enabled']??null) || !is_string($input['actorKey']??null) || (isset($input['expected'])&&!is_string($input['expected'])) ) return $this->error('reaction_input',__('Choose one conversation action.','dsa'),400);
		$post=Discussion_Target::get($post_id);if(is_wp_error($post))return $post;
		if(get_current_user_id()&&'1'===(string)get_user_meta(get_current_user_id(),'_kiwe_account_suspended',true))return $this->error('account',__('This account cannot use conversation actions. Contact the website team.','dsa'),403);
		$viewer=self::viewer($post_id);$staff=in_array($input['kind'],['heart','pin'],true);
		if(!$viewer[$staff?'canModerate':'canLike'])return $this->error('reaction_identity',$staff?__('This action is for the website team.','dsa'):__('Sign in with a verified contact to react.','dsa'),403);
		if(!hash_equals((new Discussion_Service())->current_actor_key(),$input['actorKey']))return $this->error('actor',__('Your account changed. Reopen the conversation.','dsa'));
		if(!Discussion_Reaction_Store::ready())return $this->error('reaction_unavailable',__('Conversation actions are temporarily unavailable.','dsa'),503);
		global $wpdb;$kind=$input['kind'];$user=get_current_user_id();$post_lock='kiwe-discussion-post-'.substr(hash('sha256',$wpdb->prefix.':'.$post_id),0,32);$comment_lock='kiwe-feedback-'.substr(hash('sha256',$wpdb->prefix.':'.$comment_id),0,32);$post_locked=false;$comment_locked=false;
		try {
			if('pin'===$kind){$post_locked='1'===(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$post_lock));if(!$post_locked)return $this->error('busy',__('Another conversation action is finishing. Try again.','dsa'));}
			$comment_locked='1'===(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$comment_lock));if(!$comment_locked)return $this->error('busy',__('This comment is being updated. Try again.','dsa'));
			$comment=get_comment($comment_id);
			if(!$comment||(int)$comment->comment_post_ID!==$post_id||'1'!==(string)$comment->comment_approved||!in_array($comment->comment_type,Discussion_Target::types($post),true))return Discussion_Target::unavailable();
			$ancestry=Discussion_Target::parent($post,$comment_id);if(is_wp_error($ancestry))return $ancestry;
			if($staff&&(!isset($input['expected'])||!hash_equals(self::expected($comment),$input['expected'])))return $this->error('reaction_stale',__('This comment changed. Refresh its actions.','dsa'));
			if('pin'===$kind&&$comment->comment_parent)return $this->error('pin_reply',__('Pin a top-level comment, rather than a reply.','dsa'),400);
			$current=$staff?('heart'===$kind?(int)get_comment_meta($comment_id,self::HEART,true)>0:'1'===(string)get_comment_meta($comment_id,self::PIN,true)):Discussion_Reaction_Store::has($comment_id,$user,$kind);
			if($wpdb->last_error)return $this->error('reaction_storage',__('Conversation actions are temporarily unavailable.','dsa'),503);
			if($current!==$input['enabled']) {
				$limit='report'===$kind?6:40;
				if(!Atomic_Rate_Limiter::allow('discussion-action-ip|'.Origin_Checker::client_ip(),60,600,false)||!Atomic_Rate_Limiter::allow('discussion-action-user|'.$kind.'|'.$user,$limit,600,false))return $this->error('reaction_rate',__('Please wait before another conversation action.','dsa'),429);
				if('pin'===$kind&&$input['enabled']) {
					$types=Discussion_Target::types($post);$sql="SELECT COUNT(DISTINCT c.comment_ID) FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1' WHERE c.comment_post_ID=%d AND c.comment_parent=0 AND c.comment_approved='1' AND c.comment_type IN (".implode(',',array_fill(0,count($types),'%s')).')';
					$pins=(int)$wpdb->get_var($wpdb->prepare($sql,array_merge([self::PIN,$post_id],$types)));if($wpdb->last_error)return $this->error('reaction_storage',__('Pins are temporarily unavailable.','dsa'),503);
					if($pins>=self::MAX_PINS)return $this->error('pin_limit',__('Unpin a comment before pinning another. Up to three can stay at the top.','dsa'));
				}
				if(!Discussion_Storage::begin(true))return $this->error('reaction_storage',__('Conversation actions are temporarily unavailable.','dsa'),503);
				$committed=false;
				try {
					$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID=%d FOR UPDATE",$comment_id));
					if($wpdb->last_error)return $this->error('reaction_storage',__('This action is busy. Try again shortly.','dsa'),503);
					if(!$row||(int)$row->comment_post_ID!==$post_id||'1'!==(string)$row->comment_approved)return $this->error('reaction_changed',__('This comment changed. Refresh the conversation.','dsa'));
					$comment=new \WP_Comment($row);wp_cache_delete($comment_id,'comment_meta');
					if($staff&&!hash_equals(self::expected($comment),$input['expected']))return $this->error('reaction_stale',__('This comment changed. Refresh its actions.','dsa'));
					$written=$staff?$this->staff_set($comment,$kind,$input['enabled'],$user):Discussion_Reaction_Store::set($comment_id,$user,$kind,$input['enabled']);
					if(!$written||!self::changed($post_id)||!Discussion_Storage::commit())return $this->error('reaction_write',__('This action could not be confirmed. Refresh before retrying.','dsa'),503);
					$committed=true;
				} finally {
					if(!$committed){Discussion_Storage::rollback();wp_cache_delete($comment_id,'comment_meta');wp_cache_delete($post_id,'post_meta');}
					Discussion_Storage::restore();
				}
				if(in_array($kind,['like','heart','pin'],true))wp_cache_set_last_changed('kiwe_testimonials'); // After commit: another request must not retain a pre-commit membership projection.
				do_action('kiwe_discussion_reaction_changed',$comment_id,$kind,$input['enabled'],$user);
			}
			$counts=Discussion_Reaction_Store::projection([$comment_id],$user);
			if(is_wp_error($counts))return $counts;
			return [ 'ok'=>true,'id'=>$comment_id,'reaction'=>self::decorate($comment,$counts[$comment_id]??[],$viewer),'revision'=>self::revision($post_id) ];
		} finally { if($comment_locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$comment_lock));if($post_locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$post_lock)); }
	}
	private function staff_set( \WP_Comment $comment, string $kind, bool $on, int $user ): bool {
		$key='heart'===$kind?self::HEART:self::PIN;
		if($on)update_comment_meta($comment->comment_ID,$key,'1');else delete_comment_meta($comment->comment_ID,$key);
		return $on?('heart'===$kind?(int)get_comment_meta($comment->comment_ID,$key,true)>0:'1'===(string)get_comment_meta($comment->comment_ID,$key,true)):''===(string)get_comment_meta($comment->comment_ID,$key,true);
	}
}
