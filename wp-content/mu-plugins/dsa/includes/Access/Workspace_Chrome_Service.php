<?php
namespace DSA\Access;

use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Shared presentation data for private React documents; never owns business records. */
final class Workspace_Chrome_Service {
	public static function projection( ?int $notice_count = null ): array {
		$can_notify = current_user_can( 'kiwe_use_notifications' );
		if ( null === $notice_count ) $notice_count = $can_notify ? Workspace_Notice_UI::notice_count( Workspace_Notice_UI::capture_admin_notices() ) : 0;
		return [
			...Site_Identity_Service::workspace_brand(),
			'contextProgress' => \DSA\Onboarding\Design_Context_Journey_Service::projection(),
			'contextUrl'      => current_user_can( 'kiwe_manage_context' ) ? \DSA\Onboarding\Design_Context_Journey_Service::url() : '',
			'viewerId'        => get_current_user_id(),
			'trashEndpoint'   => class_exists( Trash_Workspace_Service::class ) && Trash_Workspace_Service::allowed() ? rest_url( 'dsa/v1/workspace/trash' ) : '',
			'trashUrls'       => class_exists( Trash_Workspace_Service::class ) ? array_map( [ Trash_Workspace_Service::class, 'url' ], array_combine( array_keys( Trash_Workspace_Service::types() ), array_keys( Trash_Workspace_Service::types() ) ) ) : [],
			'analyticsUrl'    => Analytics_Workspace_Service::allowed() ? Analytics_Workspace_Service::url() : '',
			'seoEnabled'      => \DSA\AI\AI_Feature_Policy::seo_enabled(),
			'siteUrl'         => home_url( '/' ),
			'profileUrl'      => home_url( '/?kiwe-screen=profile' ),
			'notificationUrl' => $can_notify ? admin_url( 'admin.php?page=kiwe-notifications' ) : '',
			'noticeCount'     => $can_notify ? max( 0, $notice_count ) : 0,
			'inboxUnreadCount'=> $can_notify ? \DSA\Notifications\Admin_Event_Notification_Service::unread_count() : 0,
		];
	}
}
