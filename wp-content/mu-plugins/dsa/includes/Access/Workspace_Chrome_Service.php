<?php
namespace DSA\Access;

use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Shared presentation data for private React documents; never owns business records. */
final class Workspace_Chrome_Service {
	public static function account_links(): array {
		$links = [ [ 'label'=>__( 'Account','dsa' ),'url'=>home_url( '/?kiwe-screen=profile' ) ] ];
		if ( Workspace_Admin_Service::can_open_native_dashboard( wp_get_current_user() ) ) $links[] = [ 'label'=>__( 'WordPress dashboard','dsa' ),'url'=>admin_url( 'index.php?kiwe-native-dashboard=1' ) ];
		if ( App_Workspace_Service::enabled() && App_Workspace_Service::allowed() ) $links[] = [ 'label'=>__( 'App settings','dsa' ),'url'=>App_Workspace_Service::url() ];
		return $links;
	}
	public static function projection( ?int $notice_count = null,bool $home = false ): array {
		$can_notify = current_user_can( 'kiwe_use_notifications' );
		if ( null === $notice_count ) $notice_count = $can_notify ? Workspace_Notice_UI::notice_count( Workspace_Notice_UI::capture_admin_notices() ) : 0;
		return [
			...Site_Identity_Service::workspace_brand(),
			'contextProgress' => $home ? \DSA\Onboarding\Design_Context_Journey_Service::projection() : null,
			'contextUrl'      => current_user_can( 'kiwe_manage_context' ) ? \DSA\Onboarding\Design_Context_Journey_Service::url() : '',
			'viewerId'        => get_current_user_id(),
			'trashEndpoint'   => class_exists( Trash_Workspace_Service::class ) && Trash_Workspace_Service::allowed() ? rest_url( 'dsa/v1/workspace/trash' ) : '',
			'trashUrls'       => class_exists( Trash_Workspace_Service::class ) ? array_map( [ Trash_Workspace_Service::class, 'url' ], array_combine( array_keys( Trash_Workspace_Service::types() ), array_keys( Trash_Workspace_Service::types() ) ) ) : [],
			'analyticsUrl'    => Analytics_Workspace_Service::allowed() ? Analytics_Workspace_Service::url() : '',
			'seoEnabled'      => \DSA\AI\AI_Feature_Policy::seo_enabled(),
			'siteUrl'         => home_url( '/' ),
			'profileUrl'      => home_url( '/?kiwe-screen=profile' ),
			'accountLinks'    => self::account_links(),
			'notificationUrl' => $can_notify ? admin_url( 'admin.php?page=kiwe-notifications' ) : '',
			'noticeCount'     => $can_notify ? max( 0, $notice_count ) : 0,
			'inboxUnreadCount'=> $can_notify ? \DSA\Notifications\Admin_Event_Notification_Service::unread_count() : 0,
		];
	}
	/** Native and React documents deliberately share the same markup classes and CSS owner. */
	public static function header_markup( bool $home,int $notice_count ): void {
		$config = self::projection( $notice_count );
		?><div class="kiwe-context-chrome"><header class="kiwe-workspace-header"><div class="kiwe-workspace-brand">
		<a class="workspace-site-brand" href="<?php echo esc_url( Workspace_Admin_Service::url() ); ?>" aria-label="<?php echo esc_attr( $config['siteName'] . ' home' ); ?>"><?php if ( $config['siteLogo'] ) : ?><img src="<?php echo esc_url( $config['siteLogo'] ); ?>" alt=""><?php else : ?><strong><?php echo esc_html( $config['siteName'] ); ?></strong><?php endif; ?></a>
		<div class="workspace-brand-meta"><?php if ( $config['siteTagline'] ) : ?><span class="workspace-site-tagline"><?php echo esc_html( $config['siteTagline'] ); ?></span><?php endif; ?><?php if ( $config['contextUrl'] ) : ?><a class="workspace-site-edit" href="<?php echo esc_url( $config['contextUrl'] ); ?>" aria-label="<?php esc_attr_e( 'Edit website identity','dsa' ); ?>"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a><?php endif; ?></div></div>
		<nav class="kiwe-workspace-header-actions" aria-label="<?php esc_attr_e( 'Account and website','dsa' ); ?>"><a class="kiwe-workspace-site-link" href="<?php echo esc_url( $config['siteUrl'] ); ?>"><span>View website</span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3h7v7M21 3l-11 11M10 3H3v18h18v-7"/></svg></a>
		<?php if ( $config['notificationUrl'] ) : ?><a class="kiwe-workspace-bell kiwe-shell-bell" href="<?php echo esc_url( $config['notificationUrl'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Notifications, %d new updates','dsa' ),$config['inboxUnreadCount'] ) ); ?>"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg><?php if ( $config['inboxUnreadCount'] ) : ?><b class="kiwe-shell-bell-count" aria-hidden="true"><?php echo esc_html( $config['inboxUnreadCount'] ); ?></b><?php endif; ?></a><?php endif; ?>
		<details class="kiwe-account-menu"><summary aria-label="<?php esc_attr_e( 'Account menu','dsa' ); ?>"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg><span>Account</span><span aria-hidden="true">⌄</span></summary><nav aria-label="<?php esc_attr_e( 'Account settings','dsa' ); ?>"><?php foreach ( $config['accountLinks'] as $link ) : ?><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a><?php endforeach; ?></nav></details></nav></header><?php if ( $home ) \DSA\Onboarding\Design_Context_Journey_Service::progress_markup(); ?></div><?php
	}
}
