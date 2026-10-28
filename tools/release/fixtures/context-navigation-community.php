<?php
// Isolated public-target adapters for the older media/navigation unit fixture.
// Real WP_Comment/ancestor/role/cache/reaction behavior is tested by test-wordpress-v1-completion.php.
namespace DSA\Discussion {
	class Discussion_Target {
		public static function get( int $id ) { $post=\get_post($id); return $post && $post->post_status==='publish' && empty($post->post_password) && ($post->post_type!=='product'||\DSA\Apps\Store_App_Service::enabled()) ? $post : new \WP_Error('private'); }
		public static function types( $post ): array { return $post->post_type==='product' ? ['review','comment',''] : ['comment','']; }
		public static function parent( $post, int $id ) { return true; }
	}
	class Public_Identity { public static function team_member($comment):bool{return false;} public static function comment_name($comment):string{return $comment->comment_author;} }
	class Discussion_Reactions { public const HEART='_kiwe_discussion_business_heart_v1'; public const PIN='_kiwe_discussion_pinned_v1'; }
	class Discussion_Reaction_Store { public static function projection(array $ids,int $actor):array{return [];} }
}
namespace {
	class WP_Comment {
		public $comment_parent=0, $comment_ID=0, $comment_post_ID=0, $comment_approved='', $comment_type='', $comment_content='', $comment_author='', $comment_author_email='', $comment_author_IP='', $user_id=0;
		public function __construct(array $data){foreach($data as$key=>$value)$this->$key=$value;}
	}
	function wp_cache_get_last_changed($group){return 'isolated-public-navigation-fixture';}
	function cache_users($ids){}
	function get_the_title($post){return $post->post_title??'';}
	$GLOBALS['wpdb']=new class {public $comments='wp_comments', $commentmeta='wp_commentmeta'; public function prepare($sql,...$args){return $sql;}};
}
