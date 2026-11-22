<?php
namespace DSA\Discussion;
if ( ! defined('ABSPATH') ) exit;

/** Built-in discussion execution; public assets remain contextual and lazy. */
final class Feedback_Engine_Service {
	public function register(): void {
		if ( ! \DSA\Discussion\Feedback_Access::enabled() ) return;
		add_filter('pre_comment_approved',static fn($approved,$data)=>Discussion_Service::approval($approved,$data),PHP_INT_MAX,2);
		add_action('admin_init',static fn()=>Discussion_Reaction_Store::install(),26);
		add_action('wp_insert_comment',static fn($id,$comment)=>Discussion_Reactions::native_insert($id,$comment),20,2);
		add_action('transition_comment_status',static fn($new,$old,$comment)=>Discussion_Reactions::native_transition($new,$old,$comment),20,3);
		add_action('deleted_comment',static fn($id,$comment)=>Discussion_Reactions::native_deleted((int)$id,$comment),20,2);
		add_action('deleted_user',static fn($id)=>Discussion_Reaction_Store::delete_user((int)$id));
		add_action('rest_api_init',static fn()=>(new \DSA\Rest\Discussion_Controller())->routes());
		(new \DSA\Access\Feedback_Workspace_Service())->register();
	}
}
