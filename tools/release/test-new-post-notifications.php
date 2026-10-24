<?php
declare(strict_types=1);

namespace DSA\Notifications {
	class Notification_Preference_Service {
		public bool $on = true;
		public array $rows = [];
		public array $channels = [];
		public function enabled(): bool { return $this->on; }
		public function post_alert_ceiling(): int { return count( $this->rows ); }
		public function post_alert_inbox_user_for_row(array $row,array $categories=[]):int{return (int)($row['inbox_user']??0);}
		public function post_alert_rows_after( int $cursor, int $ceiling, int $limit = 25 ): array {
			return array_values( array_filter( $this->rows, static fn( array $row ): bool => (int) $row['id'] > $cursor && (int) $row['id'] <= $ceiling ) );
		}
		public function post_alert_channels_for_row( array $row, array $category_ids = [], bool $filter_categories = true ): array {
			$id = (int) $row['id'];
			if ( $filter_categories && ! empty( $row['categories'] ) && ! array_intersect( $row['categories'], $category_ids ) ) return [];
			return $this->channels[ $id ] ?? [];
		}
		public function contact_for_user( int $user_id, string $channel ): string {
			return 'email' === $channel ? "person{$user_id}@example.test" : "+155500000{$user_id}";
		}
		public function user_accepts( int $user_id, string $channel, string $topic = '' ): bool { return true; }
	}

	class Push_Service {
		public bool $available = true;
		public bool $accept = true;
		public array $user_calls = [];
		public array $visitor_calls = [];
		public function ready(): bool { return $this->available; }
		public function send_to_users( array $ids, string $title, string $message, string $url = '', array $meta = [] ): array {
			$this->user_calls[] = compact( 'ids', 'title', 'message', 'url', 'meta' );
			return [ 'sent' => $this->accept ? 1 : 0, 'failed' => $this->accept ? 0 : 1 ];
		}
		public function send_to_visitor_hash( string $visitor_hash, string $title, string $message, string $url = '', array $meta = [] ): array {
			$this->visitor_calls[] = compact( 'visitor_hash', 'title', 'message', 'url', 'meta' );
			return [ 'sent' => $this->accept ? 1 : 0, 'failed' => $this->accept ? 0 : 1 ];
		}
	}
}

namespace DSA\Communications {
	class Channel_Service {
		public bool $available = true;
		public bool $accept = true;
		public array $calls = [];
		public function available_for_campaign( string $channel ): bool { return $this->available; }
		public function send( string $channel, string $to, string $subject, string $message, array $context = [] ) {
			$this->calls[] = compact( 'channel', 'to', 'subject', 'message', 'context' );
			return $this->accept;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
	$options = [];
	$post_meta = [];
	$posts = [];
	$post_categories = [];
	$events = [];
	$schedule_allowed = true;
	$checks = 0;

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = [] ) {}
		public function get_error_message(): string { return $this->message; }
	}

	function ok( $condition, string $label ): void { ++$GLOBALS['checks']; if ( ! $condition ) throw new \RuntimeException( $label ); }
	function add_action( ...$args ): void {}
	function do_action(...$args):void{$GLOBALS['inbox_events'][]=$args;}
	function __( $value, $domain = '' ) { return $value; }
	function absint( $value ): int { return abs( (int) $value ); }
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
	function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function get_bloginfo( $field ): string { return 'Fixture Press'; }
	function get_permalink( $post ): string { $id = is_object( $post ) ? (int) $post->ID : (int) $post; return "https://example.test/?p={$id}"; }
	function get_the_title( $post_id ): string { return (string) ( $GLOBALS['posts'][ (int) $post_id ]->post_title ?? '' ); }
	function get_post( $post_id ) { return $GLOBALS['posts'][ (int) $post_id ] ?? null; }
	function wp_get_post_categories( $post_id, $args = [] ): array { return $GLOBALS['post_categories'][ (int) $post_id ] ?? []; }
	function add_post_meta( $post_id, $key, $value, $unique = false ): bool {
		if ( $unique && array_key_exists( $key, $GLOBALS['post_meta'][ (int) $post_id ] ?? [] ) ) return false;
		$GLOBALS['post_meta'][ (int) $post_id ][ $key ] = $value;
		return true;
	}
	function update_post_meta( $post_id, $key, $value ): bool { $GLOBALS['post_meta'][ (int) $post_id ][ $key ] = $value; return true; }
	function delete_post_meta( $post_id, $key ): bool { unset( $GLOBALS['post_meta'][ (int) $post_id ][ $key ] ); return true; }
	function add_option( $key, $value, $deprecated = '', $autoload = false ): bool {
		if ( array_key_exists( $key, $GLOBALS['options'] ) ) return false;
		$GLOBALS['options'][ $key ] = $value;
		return true;
	}
	function get_option( $key, $fallback = false ) { return $GLOBALS['options'][ $key ] ?? $fallback; }
	function update_option( $key, $value, $autoload = false ): bool { $GLOBALS['options'][ $key ] = $value; return true; }
	function delete_option( $key ): bool { unset( $GLOBALS['options'][ $key ] ); return true; }
	function wp_schedule_single_event( $when, $hook, $args = [], $wp_error = false ) {
		if ( ! $GLOBALS['schedule_allowed'] ) return false;
		$GLOBALS['events'][] = [ $when, $hook, $args ];
		return true;
	}
	function wp_next_scheduled( $hook, $args = [] ) {
		foreach ( $GLOBALS['events'] as $event ) if ( $event[1] === $hook && $event[2] === $args ) return $event[0];
		return false;
	}

	require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Notifications/New_Post_Notification_Service.php';

	$preferences = new \DSA\Notifications\Notification_Preference_Service();
	$preferences->rows = [
		[ 'id'=>1, 'user_id'=>11, 'visitor_hash'=>'', 'categories'=>[ 7 ] ],
		[ 'id'=>2, 'user_id'=>0, 'visitor_hash'=>str_repeat( 'a', 64 ), 'categories'=>[ 7 ] ],
		[ 'id'=>3, 'user_id'=>12, 'visitor_hash'=>'', 'categories'=>[ 9 ] ],
		[ 'id'=>4, 'user_id'=>13, 'visitor_hash'=>'', 'categories'=>[] ],
		[ 'id'=>5, 'user_id'=>14, 'visitor_hash'=>'', 'categories'=>[ 7 ] ],
		[ 'id'=>6, 'user_id'=>15, 'visitor_hash'=>'', 'categories'=>[ 7 ] ],
		[ 'id'=>7, 'user_id'=>16, 'visitor_hash'=>'', 'categories'=>[ 7 ], 'inbox_user'=>16 ],
	];
	$preferences->channels = [ 1=>[ 'email','app' ], 2=>[ 'app' ], 3=>[ 'email' ], 4=>[ 'sms' ], 5=>[ 'whatsapp' ], 6=>[ 'email' ], 7=>[] ];
	$channels = new \DSA\Communications\Channel_Service();
	$push = new \DSA\Notifications\Push_Service();
	$service = new \DSA\Notifications\New_Post_Notification_Service( $preferences, $channels, $push );

	$posts[101] = (object) [ 'ID'=>101, 'post_type'=>'post', 'post_status'=>'publish', 'post_password'=>'', 'post_title'=>'A new public story' ];
	$post_categories[101] = [ 7 ];
	$before = (object) [ 'post_status'=>'draft' ];
	$service->queue_first_publish( 101, $posts[101], true, $before );
	ok( isset( $options['dsa_new_post_alert_101'] ) && count( $events ) === 1, 'First public publication queues one bounded job.' );
	$service->queue_first_publish( 101, $posts[101], true, $before );
	ok( count( $events ) === 1, 'Post marker prevents a duplicate publication queue.' );

	$service->run_batch( 101 );
	$active = $service->status()['active'];
	ok( $active['scanned'] === 4 && $active['sent'] === 3 && $active['skipped'] === 1, 'One pass addresses at most three eligible people while scanning category mismatches.' );
	ok( count( $push->user_calls ) === 1 && count( $push->visitor_calls ) === 1 && $push->visitor_calls[0]['visitor_hash'] === str_repeat( 'a', 64 ), 'Registered and exact anonymous PWA identities use separate push paths.' );
	ok( count( $channels->calls ) === 2 && $channels->calls[0]['channel'] === 'email' && $channels->calls[1]['channel'] === 'sms', 'Each registered person receives only currently selected ready channels.' );
	ok( $channels->calls[0]['context']['purpose'] === 'notification_campaign' && $channels->calls[0]['context']['topic'] === 'new_post', 'Existing Key transport purpose carries the exact post topic without widening its gateway allowlist.' );

	$preferences->channels[5] = []; // Consent changed after discovery, before the next pass.
	$service->run_batch( 101 );
	$status = $service->status();
	ok( $status['active'] === null && $status['history'][0]['sent'] === 4 && $status['history'][0]['skipped'] === 3, 'Later batches recheck consent and complete without sending to a revoked subscriber.' );
	$native=$GLOBALS['inbox_events']??[];ok(count($native)===1&&$native[0][0]==='kiwe_notification_event'&&$native[0][1]==='new_post'&&$native[0][2]['userIds']===[16]&&$native[0][2]['inboxOnly']===true&&$native[0][2]['objectId']===101,'Native-only post choice uses the shared exact-recipient inbox ingress without a gateway send.');
	$serialized = json_encode( $status );
	ok( ! str_contains( $serialized, 'person15@' ) && ! str_contains( $serialized, str_repeat( 'a', 64 ) ) && ! str_contains( $serialized, 'A new public story has a new post' ), 'Status and history omit contacts, visitor hashes and message bodies.' );

	$posts[102] = (object) [ 'ID'=>102, 'post_type'=>'post', 'post_status'=>'publish', 'post_password'=>'secret', 'post_title'=>'Private' ];
	$service->queue_first_publish( 102, $posts[102], true, $before );
	ok( ! isset( $options['dsa_new_post_alert_102'] ), 'Password-protected publication is not queued.' );
	$posts[103] = (object) [ 'ID'=>103, 'post_type'=>'post', 'post_status'=>'publish', 'post_password'=>'', 'post_title'=>'Republished' ];
	$service->queue_first_publish( 103, $posts[103], true, (object) [ 'post_status'=>'publish' ] );
	ok( ! isset( $options['dsa_new_post_alert_103'] ), 'Updating or republishing an already public post is not queued.' );

	$posts[104] = (object) [ 'ID'=>104, 'post_type'=>'post', 'post_status'=>'publish', 'post_password'=>'', 'post_title'=>'Cron unavailable' ];
	$post_categories[104] = [ 7 ];
	$schedule_allowed = false;
	$service->queue_first_publish( 104, $posts[104], true, $before );
	ok( ! isset( $options['dsa_new_post_alert_104'] ) && ! isset( $post_meta[104]['_kiwe_new_post_alert_queued'] ), 'A failed initial schedule removes the job and publication marker so recovery remains possible.' );
	$schedule_allowed = true;

	$posts[105] = (object) [ 'ID'=>105, 'post_type'=>'post', 'post_status'=>'publish', 'post_password'=>'', 'post_title'=>'Worker lock' ];
	$post_categories[105] = [ 7 ];
	$service->queue_first_publish( 105, $posts[105], true, $before );
	$options['dsa_new_post_notification_worker_lock'] = time() + 60;
	$event_count = count( $events );
	$service->run_batch( 105 );
	ok( count( $events ) === $event_count + 1 && $service->status()['active']['scanned'] === 0, 'A busy global worker reschedules instead of sending concurrently.' );
	unset( $options['dsa_new_post_notification_worker_lock'] );

	$events = [];
	$options['dsa_new_post_alert_105']['status'] = 'stalled';
	$resume = $service->resume( 105 );
	ok( ( $resume['ok'] ?? false ) === true && count( $events ) === 1 && $resume['job']['status'] === 'queued', 'A client can resume a stalled post-alert job without rebuilding its audience snapshot.' );

	echo json_encode( [ 'passed'=>true, 'checks'=>$checks ] ) . "\n";
}
