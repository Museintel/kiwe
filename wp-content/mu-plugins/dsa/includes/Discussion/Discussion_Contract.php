<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Read-only authoring/discovery metadata. Never projects conversations, identities or moderation data. */
final class Discussion_Contract {
	public static function export(): array {
		$site = (int) get_option( Discussion_Target::SITE_PAGE_OPTION, 0 ); $page = $site ? Discussion_Target::get( $site ) : null;
		return [
			'schema'=>'kiwe.discussion.v1', 'authority'=>'wordpress-comments', 'runtimeVersion'=>defined( 'DSA_VERSION' ) ? DSA_VERSION : '',
			'core'=>[ 'posts'=>true, 'websitePageId'=>$page instanceof \WP_Post && 'page' === $page->post_type ? $site : 0, 'threaded'=>(bool) get_option( 'thread_comments' ) ],
			'productReviews'=>\DSA\Apps\Store_App_Service::enabled() && class_exists( '\DSA\Commerce\Discussion_Review_Adapter' ),
			'launcher'=>[ 'attribute'=>'data-dsa-open-module', 'value'=>'comments', 'targetAttribute'=>'data-dsa-discussion-id', 'targets'=>[ 'current','site','native-public-post-id' ], 'example'=>'<button type="button" data-dsa-open-module="comments" data-dsa-discussion-id="{kiwe_discussion_id}" aria-label="Open conversation">Comments</button>' ],
			'bricks'=>[ 'target'=>'{kiwe_discussion_id}', 'count'=>'{kiwe_discussion_count}', 'loopScope'=>'Native WP_Post only. A user or taxonomy loop ID must never be used as a post ID.', 'testimonialTarget'=>'{kiwe_testimonial_target_id}', 'testimonialQuery'=>'kiwe_testimonials', 'testimonialScope'=>'Use only inside the live public testimonial projection. The comment ID is not its discussion target ID.' ],
			'badge'=>[ 'meaning'=>'Approved top-level comments or reviews. Replies have separate counts.', 'owner'=>'Kiwe shared launcher enhancement', 'requiresCustomScript'=>false ],
			'behavior'=>[ 'readApprovedHistory'=>true, 'identity'=>'Existing PhoneKey assurance and chosen public name', 'skipVerification'=>'Native moderation; never borrow an existing account by typed contact', 'reviewPolicy'=>'Native WooCommerce rules when Store is available', 'reactions'=>'Verified-account likes/reports, team hearts and up to three top-level pins', 'pageViewPolling'=>false ],
			'authorRule'=>'Use the existing launcher and current-record binding. Do not generate a second comment form, authentication flow, reaction store, fabricated counts, avatars or moderation engine.',
			'mcp'=>[ 'readOnlyDiscovery'=>true, 'commentMutationTools'=>false ],
		];
	}
}
