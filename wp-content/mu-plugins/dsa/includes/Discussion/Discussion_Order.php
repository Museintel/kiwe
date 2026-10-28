<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native comment query ordering with a target-bound, short-lived keyset cursor. */
final class Discussion_Order {
	public static function cursor( int $post, int $parent, string $revision, array $record ): string {
		$payload=[ 'blog'=>get_current_blog_id(),'post'=>$post,'parent'=>$parent,'revision'=>$revision,'expires'=>time()+600,'pin'=>!empty($record['pinned'])?1:0,'heart'=>!empty($record['hearted'])?1:0,'likes'=>(int)($record['likes']??0),'id'=>(int)$record['id'] ];
		$body=rtrim(strtr(base64_encode(wp_json_encode($payload)),'+/','-_'),'=');
		return $body.'.'.hash_hmac('sha256',$body,wp_salt('nonce'));
	}
	public static function decode( string $cursor, int $post, int $parent, string $revision ) {
		if(''===$cursor)return [];
		if(strlen($cursor)>1000||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$cursor,$match)||!hash_equals(hash_hmac('sha256',$match[1],wp_salt('nonce')),$match[2]))return self::invalid();
		$body=base64_decode(strtr($match[1],'-_','+/'),true);$value=is_string($body)?json_decode($body,true):null;
		$keys=['blog','post','parent','revision','expires','pin','heart','likes','id'];
		if(!is_array($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys))return self::invalid();
		foreach(['blog','post','parent','expires','pin','heart','likes','id']as$key)if(!is_int($value[$key]))return self::invalid();
		if($value['blog']!==get_current_blog_id()||$value['post']!==$post||$value['parent']!==$parent||!is_string($value['revision'])||$value['expires']<time()||$value['expires']>time()+600||!in_array($value['pin'],[0,1],true)||!in_array($value['heart'],[0,1],true)||$value['likes']<0||$value['id']<1)return self::invalid();
		if(!hash_equals($revision,$value['revision']))return new \WP_Error('kiwe_discussion_cursor_changed',__('The conversation changed. Refresh it or choose Latest to keep browsing.','dsa'),['status'=>409]);
		return $value;
	}
	private static function invalid(): \WP_Error { return new \WP_Error('kiwe_discussion_cursor',__('Refresh this conversation before showing more.','dsa'),['status'=>400]); }
	public static function clauses( array $clauses, \WP_Post $post, int $parent, array $after ): array {
		if(!Discussion_Reaction_Store::ready())return $clauses;
		global $wpdb;$table=Discussion_Reaction_Store::table();$comments=$wpdb->comments;
		$scope=$wpdb->prepare("SELECT comment_ID FROM {$comments} WHERE comment_post_ID=%d AND comment_parent=%d AND comment_approved='1'",$post->ID,$parent);
		$meta=$wpdb->prepare("SELECT comment_id,MAX(meta_key=%s AND meta_value='1') AS pinned,MAX(meta_key=%s AND CAST(meta_value AS UNSIGNED)>0) AS hearted FROM {$wpdb->commentmeta} WHERE meta_key IN (%s,%s) AND comment_id IN ({$scope}) GROUP BY comment_id",Discussion_Reactions::PIN,Discussion_Reactions::HEART,Discussion_Reactions::PIN,Discussion_Reactions::HEART);
		$likes="SELECT comment_id,COUNT(*) AS likes FROM {$table} WHERE kind='like' AND comment_id IN ({$scope}) GROUP BY comment_id";
		$clauses['join'].=" LEFT JOIN ({$meta}) kiwe_discussion_rank ON kiwe_discussion_rank.comment_id={$comments}.comment_ID LEFT JOIN ({$likes}) kiwe_discussion_likes ON kiwe_discussion_likes.comment_id={$comments}.comment_ID";
		$p='COALESCE(kiwe_discussion_rank.pinned,0)';$h='COALESCE(kiwe_discussion_rank.hearted,0)';$l='COALESCE(kiwe_discussion_likes.likes,0)';
		$clauses['orderby']="{$p} DESC,{$h} DESC,{$l} DESC,{$comments}.comment_ID DESC";
		if($after)$clauses['where'].=$wpdb->prepare(" AND ({$p}<%d OR ({$p}=%d AND {$h}<%d) OR ({$p}=%d AND {$h}=%d AND {$l}<%d) OR ({$p}=%d AND {$h}=%d AND {$l}=%d AND {$comments}.comment_ID<%d))",$after['pin'],$after['pin'],$after['heart'],$after['pin'],$after['heart'],$after['likes'],$after['pin'],$after['heart'],$after['likes'],$after['id']);
		return $clauses;
	}
}
