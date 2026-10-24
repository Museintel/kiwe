<?php

namespace DSA\Notifications;

use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded, consent-checked first-publication alerts for a publisher site. */
final class New_Post_Notification_Service {
	private const HOOK = 'dsa_new_post_notification_batch';
	private const HISTORY = 'dsa_new_post_notification_history';
	private const LATEST = 'dsa_new_post_notification_latest';
	private const LOCK = 'dsa_new_post_notification_worker_lock';
	private const META = '_kiwe_new_post_alert_queued';
	private const SCAN = 25;
	private const RECIPIENTS = 3;

	public function __construct( private Notification_Preference_Service $preferences, private Channel_Service $channels, private Push_Service $push ) {}

	public function register( bool $publish_enabled = true ): void {
		if ( $publish_enabled ) add_action( 'wp_after_insert_post', [ $this, 'queue_first_publish' ], 30, 4 );
		add_action( self::HOOK, [ $this, 'run_batch' ], 10, 1 );
	}

	public function queue_first_publish( $post_id, $post, $update, $post_before ): void {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! is_object( $post ) || 'post' !== ( $post->post_type ?? '' ) || 'publish' !== ( $post->post_status ?? '' ) || '' !== (string) ( $post->post_password ?? '' ) || ( is_object( $post_before ) && 'publish' === ( $post_before->post_status ?? '' ) ) || ! $this->preferences->enabled() ) return;
		// Woo-only products have their own topic and are outside this publisher path.
		if ( ! add_post_meta( $post_id, self::META, (string) time(), true ) ) return;
		$ceiling = $this->preferences->post_alert_ceiling();
		if ( $ceiling < 1 ) { update_post_meta( $post_id, self::META, 'no_audience' ); return; }
		$categories = wp_get_post_categories( $post_id, [ 'fields'=>'ids' ] );
		$job = [ 'postId'=>$post_id, 'ceiling'=>$ceiling, 'cursor'=>0, 'categories'=>array_values( array_unique( array_map( 'absint', is_array( $categories ) ? $categories : [] ) ) ), 'status'=>'queued', 'scanned'=>0, 'sent'=>0, 'failed'=>0, 'skipped'=>0, 'unknown'=>0, 'created'=>time() ];
		if ( ! add_option( $this->job_key( $post_id ), $job, '', false ) ) { delete_post_meta( $post_id, self::META ); return; }
		$scheduled = wp_schedule_single_event( time() + 5, self::HOOK, [ $post_id ], true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			delete_option( $this->job_key( $post_id ) );
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_option( self::LATEST, $post_id, false );
	}

	public function run_batch( $post_id ): void {
		$post_id = absint( $post_id );
		$key = $this->job_key( $post_id );
		$job = get_option( $key, [] );
		if ( ! $post_id || ! is_array( $job ) || (int) ( $job['postId'] ?? 0 ) !== $post_id ) return;
		if ( ! add_option( self::LOCK, time() + 90, '', false ) ) {
			if ( (int) get_option( self::LOCK, 0 ) >= time() ) {
				$retry = wp_schedule_single_event( time() + 15, self::HOOK, [ $post_id ], true );
				if ( false === $retry || is_wp_error( $retry ) ) { $job['status']='stalled'; update_option( $key, $job, false ); }
				return;
			}
			delete_option( self::LOCK );
			if ( ! add_option( self::LOCK, time() + 90, '', false ) ) return;
		}
		try {
			$job = get_option( $key, [] );
			if ( ! is_array( $job ) || (int) ( $job['postId'] ?? 0 ) !== $post_id ) return;
			$post = get_post( $post_id );
			if ( ! $this->preferences->enabled() || ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type || '' !== (string) $post->post_password ) { $this->complete( $job, 'stopped' ); return; }
			$rows = $this->preferences->post_alert_rows_after( (int) $job['cursor'], (int) $job['ceiling'], self::SCAN );
			$used = 0;
			$scanned = 0;
			$uncommitted = false;
			foreach ( $rows as $row ) {
				$row_id = absint( $row['id'] ?? 0 );
				if ( $row_id <= (int) $job['cursor'] ) continue;
				$job['cursor'] = $row_id;
				++$job['scanned']; ++$scanned;
				$inbox_user = $this->preferences->post_alert_inbox_user_for_row( $row, (array) $job['categories'] );
				if ( $inbox_user ) do_action( 'kiwe_notification_event', 'new_post', [ 'id'=>'post-' . $post_id,'userIds'=>[ $inbox_user ],'inboxOnly'=>true,'objectId'=>$post_id,'title'=>sanitize_text_field( wp_strip_all_tags( $post->post_title ) ),'message'=>__( 'A new article is ready in a category you follow.', 'dsa' ),'url'=>get_permalink( $post ),'actionLabel'=>__( 'Read article', 'dsa' ) ] );
				$channels = $this->preferences->post_alert_channels_for_row( $row, (array) $job['categories'] );
				if ( ! $channels ) { ++$job['skipped']; $uncommitted = true; continue; }
				// Commit progress before any external call; uncertain sends never replay.
				++$job['unknown']; $job['status'] = 'sending';
				if ( ! update_option( $key, $job, false ) ) return;
				$committed = get_option( $key, [] );
				if ( ! is_array( $committed ) || (int) ( $committed['cursor'] ?? -1 ) !== $row_id ) return;
				try { $result = $this->deliver( $post, $row, $channels ); }
				catch ( \Throwable $error ) { $result = 'unknown'; }
				if ( 'unknown' !== $result ) { --$job['unknown']; ++$job[ $result ]; }
				if ( ! update_option( $key, $job, false ) ) return;
				$uncommitted = false;
				if ( ++$used >= self::RECIPIENTS ) break;
			}
			if ( $uncommitted && ! update_option( $key, $job, false ) ) return;
			$exhausted = ! $rows || ( $scanned === count( $rows ) && count( $rows ) < self::SCAN ) || (int) $job['cursor'] >= (int) $job['ceiling'];
			if ( $exhausted ) { $this->complete( $job, 'complete' ); return; }
			$scheduled = wp_schedule_single_event( time() + 5, self::HOOK, [ $post_id ], true );
			if ( false === $scheduled || is_wp_error( $scheduled ) ) {
				$job['status'] = 'stalled';
				update_option( $key, $job, false );
			}
		} finally { delete_option( self::LOCK ); }
	}

	private function deliver( $post, array $row, array $channels ): string {
		$title = sanitize_text_field( wp_strip_all_tags( (string) $post->post_title ) );
		$subject = sprintf( __( 'New on %1$s: %2$s', 'dsa' ), get_bloginfo( 'name' ), $title );
		$url = get_permalink( $post );
		$body = sprintf( __( '%1$s has a new post: %2$s', 'dsa' ), get_bloginfo( 'name' ), $title ) . "\n" . $url;
		$user_id = absint( $row['user_id'] ?? 0 );
		$accepted = 0;
		$categories = wp_get_post_categories( $post->ID, [ 'fields'=>'ids' ] );
		$current_channels = $this->preferences->post_alert_channels_for_row( $row, is_array( $categories ) ? $categories : [] );
		foreach ( $channels as $channel ) {
			// Another device can withdraw consent between audience discovery and send.
			if ( ! in_array( $channel, $current_channels, true ) ) continue;
			if ( 'app' === $channel ) {
				if ( ! $this->push->ready() ) continue;
				$result = $user_id
					? $this->push->send_to_users( [ $user_id ], $subject, $body, $url, [ 'eventType'=>'new_post' ] )
					: $this->push->send_to_visitor_hash( (string) $row['visitor_hash'], $subject, $body, $url, [ 'eventType'=>'new_post' ] );
				if ( (int) ( $result['sent'] ?? 0 ) > 0 ) ++$accepted;
				continue;
			}
			if ( ! $user_id || ! $this->channels->available_for_campaign( $channel ) ) continue;
			$contact = $this->preferences->contact_for_user( $user_id, $channel );
			if ( '' === $contact ) continue;
			// Key.kiwe deliberately exposes a small transport-purpose allowlist.
			// Keep the generic consented notification purpose and carry the exact
			// subscription topic as bounded context for direct webhook adapters.
			$context = [ 'purpose'=>'notification_campaign', 'topic'=>'new_post', 'user_id'=>$user_id, 'post_id'=>$post->ID ];
			if ( 'whatsapp' === $channel && $this->preferences->user_accepts( $user_id, 'email', 'new_post' ) ) {
				$context['fallback_email'] = $this->preferences->contact_for_user( $user_id, 'email' );
				$context['fallback_email_allowed'] = true;
			}
			$result = $this->channels->send( $channel, $contact, $subject, $body, $context );
			if ( ! is_wp_error( $result ) && false !== $result ) ++$accepted;
		}
		return $accepted ? 'sent' : 'failed';
	}

	private function complete( array $job, string $status ): void {
		$job['status'] = $status;
		$summary = $this->public_job( $job );
		$history = (array) get_option( self::HISTORY, [] );
		array_unshift( $history, $summary );
		update_option( self::HISTORY, array_slice( $history, 0, 20 ), false );
		update_post_meta( (int) $job['postId'], self::META, $status );
		delete_option( $this->job_key( (int) $job['postId'] ) );
	}

	public function status(): array {
		$latest = absint( get_option( self::LATEST, 0 ) );
		$job = $latest ? get_option( $this->job_key( $latest ), [] ) : [];
		return [ 'active'=>is_array( $job ) && ! empty( $job['postId'] ) ? $this->public_job( $job ) : null, 'history'=>array_slice( (array) get_option( self::HISTORY, [] ), 0, 20 ) ];
	}

	public function resume( int $post_id ) {
		$job = get_option( $this->job_key( $post_id ), [] );
		if ( ! is_array( $job ) || (int) ( $job['postId'] ?? 0 ) !== $post_id ) return new \WP_Error( 'kiwe_post_alert_missing', __( 'This post alert is no longer active.', 'dsa' ), [ 'status'=>409 ] );
		if ( wp_next_scheduled( self::HOOK, [ $post_id ] ) ) return [ 'ok'=>true, 'job'=>$this->public_job( $job ) ];
		if ( 'stalled' !== ( $job['status'] ?? '' ) && time() - (int) $job['created'] < 90 ) return new \WP_Error( 'kiwe_post_alert_wait', __( 'The next alert batch is still pending.', 'dsa' ), [ 'status'=>409 ] );
		$scheduled = wp_schedule_single_event( time() + 5, self::HOOK, [ $post_id ], true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) return new \WP_Error( 'kiwe_post_alert_schedule', __( 'WordPress could not resume this alert.', 'dsa' ), [ 'status'=>503 ] );
		$job['status'] = 'queued';
		update_option( $this->job_key( $post_id ), $job, false );
		return [ 'ok'=>true, 'job'=>$this->public_job( $job ) ];
	}

	private function public_job( array $job ): array {
		return [ 'postId'=>(int) $job['postId'], 'title'=>sanitize_text_field( get_the_title( (int) $job['postId'] ) ), 'status'=>(string) $job['status'], 'scanned'=>(int) $job['scanned'], 'sent'=>(int) $job['sent'], 'failed'=>(int) $job['failed'], 'skipped'=>(int) $job['skipped'], 'unknown'=>(int) $job['unknown'], 'created'=>(int) $job['created'] ];
	}

	private function job_key( int $post_id ): string { return 'dsa_new_post_alert_' . $post_id; }
}
