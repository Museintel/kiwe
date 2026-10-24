<?php
namespace DSA\Backup;

use DSA\Access\Backup_Workspace_Service;
use DSA\Access\Site_Owner_Service;
use DSA\Communications\Channel_Service;
use DSA\PhoneKey\PhoneKey_Bridge;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Owner-reviewed hosting operations; a transport receipt is never a backup receipt. */
final class Backup_Request_Service {
	public const TYPE = 'kiwe_backup_request';
	private const META = '_kiwe_backup_request_v1';
	private static ?Channel_Service $channels = null;
	public static function use_channels( Channel_Service $channels ): void { self::$channels = $channels; }
	public static function register_type(): void {
		register_post_type( self::TYPE, [ 'label'=>'Hosting backup requests','public'=>false,'show_ui'=>false,'show_in_rest'=>false,'can_export'=>false,'rewrite'=>false,'supports'=>[ 'title','author' ],'map_meta_cap'=>true,'capability_type'=>[ 'kiwe_backup_request','kiwe_backup_requests' ],'capabilities'=>[ 'read_private_posts'=>'read_private_posts' ] ] );
	}
	private static function owner(): ?\WP_User { $id = Site_Owner_Service::owner_id(); return $id ? ( get_userdata( $id ) ?: null ) : null; }
	private static function proof( \WP_Post $post, array $meta ): string { return hash( 'sha256', (string) wp_json_encode( [ $post->ID, $post->post_modified_gmt, $meta ] ) ); }
	private static function record( int $id ): array {
		$post = get_post( $id ); $meta = get_post_meta( $id, self::META, true );
		if ( ! $post || $post->post_type !== self::TYPE || ! is_array( $meta ) ) throw new \RuntimeException( 'This backup request is unavailable.' );
		$user = get_userdata( (int) $post->post_author );
		return [ 'id'=>$id,'createdAt'=>$post->post_date_gmt . 'Z','requester'=>$user ? $user->display_name : 'Former account','reason'=>$post->post_content,'state'=>$meta['state'],'notification'=>$meta['notification'],'channel'=>$meta['channel'],'note'=>$meta['note'] ?? '', 'expected'=>self::proof( $post, $meta ) ];
	}
	public static function inventory(): array {
		if ( ! Backup_Workspace_Service::allowed() ) throw new \RuntimeException( 'Hosting backup requests are unavailable for your account.' );
		$owner = self::owner(); $email = $owner && is_email( $owner->user_email ) && self::$channels && self::$channels->available_for_campaign( 'email' );
		$whatsapp = $owner && self::$channels && self::$channels->tenant_whatsapp_ready() && '' !== PhoneKey_Bridge::campaign_contact( (int) $owner->ID, 'whatsapp' );
		$ids = get_posts( [ 'post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>30,'orderby'=>'ID','order'=>'DESC','fields'=>'ids','no_found_rows'=>true ] );
		return [ 'canRequest'=>(bool) ( $email || $whatsapp ), 'ownerConfigured'=>(bool) $owner,'canResolve'=>Site_Owner_Service::is_owner(),'items'=>array_map( static fn( $id )=>self::record( (int) $id ), $ids ) ];
	}
	public static function create( array $input ): array {
		if ( ! Backup_Workspace_Service::allowed() ) throw new \RuntimeException( 'Hosting backup requests are unavailable for your account.' );
		$token = $input['requestId'] ?? ''; $reason = $input['reason'] ?? '';
		if ( ! is_string( $token ) || ! preg_match( '/^[a-zA-Z0-9-]{24,64}$/D', $token ) || ! is_string( $reason ) || strlen( $reason ) > 2000 || ! trim( $reason ) ) throw new \RuntimeException( 'Add a short reason for this backup request.' );
		$key = hash( 'sha256', get_current_user_id() . '|' . $token );
		$existing = get_posts( [ 'post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>1,'fields'=>'ids','meta_key'=>'_kiwe_backup_request_key','meta_value'=>$key ] );
		if ( $existing ) return self::record( (int) $existing[0] );
		$available = self::inventory();
		if ( ! $available['canRequest'] ) throw new \RuntimeException( 'The Super Admin needs a configured Kiwe email sender or a verified PhoneKey WhatsApp route before a request can be sent.' );
		if ( get_transient( 'kiwe_backup_request_cooldown' ) ) throw new \RuntimeException( 'A hosting backup was requested recently. Allow 15 minutes before another request.' );
		$old_lock = get_option( 'kiwe_backup_request_lock', null );
		if ( is_array( $old_lock ) && (int) ( $old_lock['at'] ?? 0 ) < time() - 600 ) delete_option( 'kiwe_backup_request_lock' );
		$lock = bin2hex( random_bytes( 12 ) );
		if ( ! add_option( 'kiwe_backup_request_lock', [ 'token'=>$lock,'at'=>time() ], '', false ) ) throw new \RuntimeException( 'Another hosting request is being recorded. Refresh before retrying.' );
		try {
			if ( get_transient( 'kiwe_backup_request_cooldown' ) ) throw new \RuntimeException( 'A hosting backup was requested recently. Allow 15 minutes before another request.' );
			// Capture the authoritative owner before persistence and before transport.
			$owner = self::owner(); if ( ! $owner ) throw new \RuntimeException( 'The Super Admin is no longer configured.' );
			$meta = [ 'state'=>'requested','notification'=>'pending','channel'=>'','note'=>'','owner'=>(int) $owner->ID ];
			$id = wp_insert_post( [ 'post_type'=>self::TYPE,'post_status'=>'private','post_title'=>'Hosting backup request','post_content'=>sanitize_textarea_field( $reason ),'post_author'=>get_current_user_id(),'meta_input'=>[ self::META=>$meta,'_kiwe_backup_request_key'=>$key ] ], true );
			if ( is_wp_error( $id ) || ! $id ) throw new \RuntimeException( 'The hosting request could not be recorded.' );
			if ( get_post_meta( $id, '_kiwe_backup_request_key', true ) !== $key || get_post_meta( $id, self::META, true ) !== $meta ) { wp_delete_post( $id, true ); throw new \RuntimeException( 'The hosting request could not be confirmed.' ); }
			set_transient( 'kiwe_backup_request_cooldown', (int) $id, 15 * MINUTE_IN_SECONDS );
			$actor = wp_get_current_user();
			$subject = 'Backup requested · ' . sanitize_text_field( get_bloginfo( 'name' ) );
			$message = "Hosting backup requested for " . home_url( '/' ) . "\nRequest #" . $id . "\nFrom: " . $actor->display_name . "\nReason: " . wp_html_excerpt( sanitize_textarea_field( $reason ), 600, '…' ) . "\nReview: " . Backup_Workspace_Service::url() . "\nThis is a request; no server backup has been created by Kiwe.";
			$email = sanitize_email( $owner->user_email ); $phone = PhoneKey_Bridge::campaign_contact( (int) $owner->ID, 'whatsapp' );
			$channel = $phone && self::$channels->tenant_whatsapp_ready() ? 'whatsapp' : 'email';
			$meta['channel'] = $channel;
			// Ambiguous failures remain recorded; creating the same ticket never sends twice.
			try {
				$result = self::$channels->send( $channel, 'whatsapp' === $channel ? $phone : $email, $subject, $message, [ 'purpose'=>'hosting_backup_request','request_id'=>'hosting-backup-' . get_current_blog_id() . '-' . $id, 'user_id'=>(int) $owner->ID,'fallback_email'=>$email,'fallback_email_allowed'=>true ] );
				$meta['notification'] = is_wp_error( $result ) || false === $result ? 'failed' : 'accepted';
				if ( is_array( $result ) && 'email_fallback' === ( $result['provider'] ?? '' ) ) $meta['channel'] = 'email';
			} catch ( \Throwable $error ) { $meta['notification'] = 'uncertain'; }
			update_post_meta( $id, self::META, $meta );
			if ( get_post_meta( $id, self::META, true ) !== $meta ) throw new \RuntimeException( 'The request is recorded but its notification state could not be confirmed. Refresh before retrying.' );
			return self::record( (int) $id );
		} finally { $current_lock = get_option( 'kiwe_backup_request_lock', [] ); if ( is_array( $current_lock ) && ( $current_lock['token'] ?? '' ) === $lock ) delete_option( 'kiwe_backup_request_lock' ); }
	}
	public static function resolve( array $input ): array {
		if ( ! Site_Owner_Service::is_owner() ) throw new \RuntimeException( 'Only the Super Admin can resolve a hosting backup request.' );
		$id = absint( $input['id'] ?? 0 ); $current = self::record( $id );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $current['expected'], $input['expected'] ) || 'requested' !== $current['state'] ) throw new \RuntimeException( 'This request changed. Refresh before resolving it.' );
		if ( ! in_array( $input['state'] ?? '', [ 'completed','declined' ], true ) || ! is_string( $input['note'] ?? null ) || strlen( $input['note'] ) > 2000 || ! trim( $input['note'] ) ) throw new \RuntimeException( 'Add the provider result or reason before resolving this request.' );
		$meta = get_post_meta( $id, self::META, true ); $previous = $meta;
		$meta['state'] = $input['state']; $meta['note'] = sanitize_textarea_field( $input['note'] ); $meta['resolvedAt'] = gmdate( 'c' ); $meta['resolvedBy'] = get_current_user_id();
		if ( ! update_post_meta( $id, self::META, $meta, $previous ) || get_post_meta( $id, self::META, true ) !== $meta ) throw new \RuntimeException( 'The request changed or its resolution could not be confirmed.' );
		return self::record( $id );
	}
}
