<?php

namespace DSA\Notifications;

use DSA\Security\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Push_Service {
	private const DB_VERSION = '4';
	private const DB_VERSION_OPTION = 'dsa_push_db_version';
	private const KEY_OPTION = 'dsa_push_vapid_keys';

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_install' ], 3 );
		add_action( 'init', [ $this, 'ensure_cleanup_schedule' ], 4 );
		add_action( 'dsa_push_cleanup', [ $this, 'cleanup_expired' ] );
		add_action( 'dsa_push_delivery_batch', [ $this, 'run_delivery_batch' ], 10, 1 );
	}

	public function public_config(): array {
		$keys = $this->keys();
		return [
			'enabled'   => ! empty( $keys['public'] ),
			'publicKey' => (string) ( $keys['public'] ?? '' ),
			'keyId'     => (string) ( $keys['key_id'] ?? '' ),
			'subscribed'=> false,
		];
	}

	public function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) return;
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $this->table();
		$charset = $wpdb->get_charset_collate();
			dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			endpoint_hash char(64) NOT NULL,
			endpoint longtext NOT NULL,
			p256dh longtext NOT NULL,
			auth_secret longtext NOT NULL,
			visitor_hash char(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			phonekey_verified tinyint(1) NOT NULL DEFAULT 0,
			is_app tinyint(1) NOT NULL DEFAULT 0,
			key_id varchar(32) NOT NULL DEFAULT '',
			renewal_hash char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			failure_count int(10) unsigned NOT NULL DEFAULT 0,
			last_error varchar(190) NOT NULL DEFAULT '',
			last_success_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY endpoint_hash (endpoint_hash),
			KEY user_id (user_id),
			KEY audience (status,user_id,is_app),
			KEY visitor_hash (visitor_hash)
		) {$charset};" );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public function save_subscription( array $payload ): array {
		global $wpdb;
		$this->maybe_install();
		$subscription = isset( $payload['subscription'] ) && is_array( $payload['subscription'] ) ? $payload['subscription'] : [];
		$endpoint = esc_url_raw( (string) ( $subscription['endpoint'] ?? '' ) );
		$keys = isset( $subscription['keys'] ) && is_array( $subscription['keys'] ) ? $subscription['keys'] : [];
		$p256dh = sanitize_text_field( (string) ( $keys['p256dh'] ?? '' ) );
		$auth = sanitize_text_field( (string) ( $keys['auth'] ?? '' ) );
		if ( ! $this->valid_endpoint( $endpoint ) || '' === $p256dh || '' === $auth ) {
			return [ 'ok' => false, 'message' => __( 'The browser did not provide a valid push subscription.', 'dsa' ) ];
		}
		$encrypted_endpoint = Secret_Store::encrypt( $endpoint );
		$encrypted_p256dh = Secret_Store::encrypt( $p256dh );
		$encrypted_auth = Secret_Store::encrypt( $auth );
		if ( '' === $encrypted_endpoint || '' === $encrypted_p256dh || '' === $encrypted_auth ) {
			return [ 'ok' => false, 'message' => __( 'This server cannot encrypt push subscription secrets.', 'dsa' ) ];
		}
		$visitor_id = sanitize_text_field( (string) ( $payload['visitorId'] ?? '' ) );
		$user_id = get_current_user_id();
		if ( ! $user_id && '' === $visitor_id ) {
			return [ 'ok' => false, 'message' => __( 'A visitor identity is required to own this push subscription.', 'dsa' ) ];
		}
		$now = current_time( 'mysql' );
		$vapid = $this->keys();
		if ( empty( $vapid['key_id'] ) ) return [ 'ok' => false, 'message' => __( 'Push encryption keys are not ready.', 'dsa' ) ];
		$renewal_token = wp_generate_password( 48, false, false );
		$data = [
			'endpoint_hash' => hash_hmac( 'sha256', $endpoint, wp_salt( 'auth' ) ),
			'endpoint' => $encrypted_endpoint,
			'p256dh' => $encrypted_p256dh,
			'auth_secret' => $encrypted_auth,
			'visitor_hash' => $this->visitor_hash( $visitor_id, $user_id ),
			'user_id' => $user_id,
			'phonekey_verified' => $user_id && function_exists( 'pk_account_verified' ) && pk_account_verified( $user_id ) ? 1 : 0,
			'is_app' => ! empty( $payload['standalone'] ) ? 1 : 0,
			'key_id' => (string) $vapid['key_id'],
			'renewal_hash' => $this->renewal_hash( $renewal_token ),
			'status' => 'active',
			'failure_count' => 0,
			'last_error' => '',
			'updated_at' => $now,
		];
		$presented_renewal = sanitize_text_field( (string) ( $payload['renewalToken'] ?? '' ) );
		$old_endpoint = esc_url_raw( (string) ( $payload['oldEndpoint'] ?? '' ) );
		if ( '' !== $presented_renewal || '' !== $old_endpoint ) {
			return $this->renew_subscription( $data, $presented_renewal, $old_endpoint, $renewal_token );
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE endpoint_hash = %s", $data['endpoint_hash'] ) );
		if ( $existing ) {
			$wpdb->update( $this->table(), $data, [ 'id' => absint( $existing ) ] );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $this->table(), $data );
		}
		return [ 'ok' => true, 'subscribed' => true, 'renewalToken' => $renewal_token ];
	}

	private function renew_subscription( array $data, string $token, string $old_endpoint, string $next_token ): array {
		global $wpdb;
		if ( '' === $token || ! $this->valid_endpoint( $old_endpoint ) ) {
			return [ 'ok' => false, 'status' => 403, 'message' => __( 'Push renewal proof is incomplete.', 'dsa' ) ];
		}
		$old_hash = hash_hmac( 'sha256', $old_endpoint, wp_salt( 'auth' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE endpoint_hash=%s LIMIT 1", $old_hash ), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['renewal_hash'] ) || ! hash_equals( (string) $row['renewal_hash'], $this->renewal_hash( $token ) ) ) {
			return [ 'ok' => false, 'status' => 403, 'message' => __( 'Push renewal proof was rejected.', 'dsa' ) ];
		}
		$data['visitor_hash'] = (string) $row['visitor_hash'];
		$data['user_id'] = absint( $row['user_id'] );
		$data['phonekey_verified'] = absint( $row['phonekey_verified'] );
		$data['is_app'] = absint( $row['is_app'] );
		$data['renewal_hash'] = $this->renewal_hash( $next_token );
		$updated = $wpdb->update( $this->table(), $data, [ 'id' => absint( $row['id'] ) ] );
		if ( false === $updated ) return [ 'ok' => false, 'message' => __( 'Push renewal could not be stored.', 'dsa' ) ];
		return [ 'ok' => true, 'subscribed' => true, 'renewed' => true, 'renewalToken' => $next_token ];
	}

	public function remove_subscription( array $payload ): array {
		global $wpdb;
		$endpoint = esc_url_raw( (string) ( $payload['endpoint'] ?? '' ) );
		if ( '' === $endpoint ) return [ 'ok' => false, 'message' => __( 'Push endpoint is missing.', 'dsa' ) ];
		$endpoint_hash = hash_hmac( 'sha256', $endpoint, wp_salt( 'auth' ) );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id,user_id,visitor_hash FROM {$this->table()} WHERE endpoint_hash=%s LIMIT 1", $endpoint_hash ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return [ 'ok' => true, 'subscribed' => false ];
		}

		$current_user_id = get_current_user_id();
		$owner_user_id   = absint( $row['user_id'] ?? 0 );
		$visitor_id      = sanitize_text_field( (string) ( $payload['visitorId'] ?? '' ) );
		$visitor_hash    = '' !== $visitor_id ? $this->visitor_hash( $visitor_id, $current_user_id ) : '';
		$owned           = $owner_user_id > 0
			? $current_user_id === $owner_user_id
			: '' !== $visitor_id && '' !== $visitor_hash && hash_equals( (string) ( $row['visitor_hash'] ?? '' ), $visitor_hash );

		if ( ! $owned ) {
			return [ 'ok' => false, 'status' => 403, 'message' => __( 'This push subscription does not belong to the current visitor.', 'dsa' ) ];
		}

		$wpdb->delete( $this->table(), [ 'id' => absint( $row['id'] ) ], [ '%d' ] );
		return [ 'ok' => true, 'subscribed' => false ];
	}

	public function audience_summary(): array {
		global $wpdb;
		$this->maybe_install();
		$row = $wpdb->get_row( "SELECT
			COUNT(*) AS devices,
			COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) AS users,
			COUNT(CASE WHEN user_id = 0 THEN 1 END) AS anonymous,
			COUNT(CASE WHEN is_app = 1 THEN 1 END) AS app_devices
			FROM {$this->table()} WHERE status = 'active'", ARRAY_A );
		return [
			'ready' => $this->ready(),
			'devices' => (int) ( $row['devices'] ?? 0 ),
			'users' => (int) ( $row['users'] ?? 0 ),
			'anonymous' => (int) ( $row['anonymous'] ?? 0 ),
			'appDevices' => (int) ( $row['app_devices'] ?? 0 ),
			'health' => $this->diagnostics(),
		];
	}

	public function ensure_cleanup_schedule(): void {
		if ( ! wp_next_scheduled( 'dsa_push_cleanup' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', 'dsa_push_cleanup' );
		}
		foreach ( array_slice( (array) get_option( 'dsa_push_active_jobs', [] ), 0, 20 ) as $job_id ) {
			$job_id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $job_id ) );
			if ( '' === $job_id || ! get_option( 'dsa_push_job_' . $job_id, false ) ) {
				$this->forget_job( $job_id );
				continue;
			}
			if ( ! wp_next_scheduled( 'dsa_push_delivery_batch', [ $job_id ] ) ) {
				wp_schedule_single_event( time() + 5, 'dsa_push_delivery_batch', [ $job_id ] );
			}
		}
	}

	public function cleanup_expired(): void {
		global $wpdb;
		$this->maybe_install();
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table()} WHERE status IN ('expired','reenroll_required') AND updated_at < %s LIMIT 500",
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
		) );
		update_option( 'dsa_push_last_cleanup', time(), false );
		foreach ( array_slice( (array) get_option( 'dsa_push_active_jobs', [] ), 0, 20 ) as $job_id ) {
			$job_id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $job_id ) );
			$job = get_option( 'dsa_push_job_' . $job_id, [] );
			if ( ! is_array( $job ) || empty( $job ) || absint( $job['created'] ?? 0 ) < time() - 2 * DAY_IN_SECONDS ) {
				delete_option( 'dsa_push_job_' . $job_id );
				$this->forget_job( $job_id );
			}
		}
	}

	public function diagnostics(): array {
		global $wpdb;
		$this->maybe_install();
		$crypto = $this->crypto_support();
		$table = $this->table();
		$table_ready = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$row = $table_ready ? $wpdb->get_row( "SELECT
			COUNT(*) AS total,
			SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,
			SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) AS expired,
			SUM(CASE WHEN status='reenroll_required' THEN 1 ELSE 0 END) AS reenroll_required,
			MAX(last_success_at) AS last_success,
			MAX(updated_at) AS last_attempt
			FROM {$table}", ARRAY_A ) : [];
		return [
			'ready'          => $this->ready(),
			'openssl'        => extension_loaded( 'openssl' ),
			'p256'           => $crypto,
			'table'          => $table_ready,
			'cronScheduled'  => (bool) wp_next_scheduled( 'dsa_push_cleanup' ),
			'wpCronDisabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'lastCleanup'    => absint( get_option( 'dsa_push_last_cleanup', 0 ) ),
			'total'          => (int) ( $row['total'] ?? 0 ),
			'active'         => (int) ( $row['active'] ?? 0 ),
			'expired'        => (int) ( $row['expired'] ?? 0 ),
			'reenrollRequired'=> (int) ( $row['reenroll_required'] ?? 0 ),
			'secretStore'    => Secret_Store::diagnostics(),
			'lastSuccess'    => (string) ( $row['last_success'] ?? '' ),
			'lastAttempt'    => (string) ( $row['last_attempt'] ?? '' ),
			'lastJob'        => (array) get_option( 'dsa_push_last_job', [] ),
			'queuedJobs'     => count( (array) get_option( 'dsa_push_active_jobs', [] ) ),
		];
	}

	public function broadcast( string $title, string $message, string $url = '' ): array {
		$this->maybe_install();
		if ( ! $this->ready() ) {
			return [ 'ok' => false, 'message' => __( 'VAPID push delivery is unavailable. OpenSSL with P-256 support is required.', 'dsa' ), 'sent' => 0, 'failed' => 0 ];
		}
		$job_id = strtolower( wp_generate_password( 20, false, false ) );
		$job = [
			'title'    => $this->truncate( sanitize_text_field( $title ?: get_bloginfo( 'name' ) ), 120 ),
			'message'  => $this->truncate( sanitize_textarea_field( $message ), 1200 ),
			'url'      => esc_url_raw( $url ?: home_url( '/' ) ),
			'after_id' => 0,
			'sent'     => 0,
			'failed'   => 0,
			'created'  => time(),
		];
		update_option( 'dsa_push_job_' . $job_id, $job, false );
		$this->remember_job( $job_id );
		$scheduled = wp_schedule_single_event( time() + 1, 'dsa_push_delivery_batch', [ $job_id ], true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			delete_option( 'dsa_push_job_' . $job_id );
			$this->forget_job( $job_id );
			return [ 'ok' => false, 'message' => __( 'WordPress could not schedule this push campaign. Check cron health.', 'dsa' ), 'sent' => 0, 'failed' => 0 ];
		}
		return [ 'ok' => true, 'queued' => true, 'jobId' => $job_id, 'message' => __( 'Push campaign queued in shared-host-safe batches of five devices.', 'dsa' ), 'sent' => 0, 'failed' => 0 ];
	}

	public function run_delivery_batch( string $job_id ): void {
		$job_id = preg_replace( '/[^a-z0-9]/', '', strtolower( $job_id ) );
		$lock = $this->acquire_job_lock( $job_id, 60 );
		if ( ! $lock ) return;
		try {
			$this->process_delivery_batch( $job_id );
		} finally {
			$this->release_job_lock( $lock );
		}
	}

	private function process_delivery_batch( string $job_id ): void {
		global $wpdb;
		$option = 'dsa_push_job_' . $job_id;
		$job = get_option( $option, [] );
		if ( ! is_array( $job ) || empty( $job ) ) {
			$this->forget_job( $job_id );
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table()} WHERE status='active' AND id>%d ORDER BY id ASC LIMIT 5",
			absint( $job['after_id'] ?? 0 )
		), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : [];
		if ( empty( $rows ) ) {
			update_option( 'dsa_push_last_job', [ 'jobId' => $job_id, 'sent' => absint( $job['sent'] ?? 0 ), 'failed' => absint( $job['failed'] ?? 0 ), 'completed' => time() ], false );
			delete_option( $option );
			$this->forget_job( $job_id );
			return;
		}
		$result = $this->send_rows( $rows, (string) ( $job['title'] ?? '' ), (string) ( $job['message'] ?? '' ), (string) ( $job['url'] ?? '' ) );
		$job['sent'] = absint( $job['sent'] ?? 0 ) + absint( $result['sent'] ?? 0 );
		$job['failed'] = absint( $job['failed'] ?? 0 ) + absint( $result['failed'] ?? 0 );
		$last = end( $rows );
		$job['after_id'] = absint( is_array( $last ) ? ( $last['id'] ?? 0 ) : 0 );
		update_option( $option, $job, false );
		$scheduled = wp_schedule_single_event( time() + 5, 'dsa_push_delivery_batch', [ $job_id ], true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			update_option( 'dsa_push_last_job', [ 'jobId' => $job_id, 'sent' => $job['sent'], 'failed' => $job['failed'], 'stalled' => time() ], false );
		}
	}

	private function acquire_job_lock( string $job_id, int $ttl ): string {
		$key = 'dsa_push_job_lock_' . sanitize_key( $job_id );
		$expires = time() + max( 15, $ttl );
		if ( add_option( $key, $expires, '', false ) ) return $key;
		if ( absint( get_option( $key, 0 ) ) < time() ) {
			delete_option( $key );
			if ( add_option( $key, $expires, '', false ) ) return $key;
		}
		return '';
	}

	private function release_job_lock( string $key ): void {
		if ( '' !== $key ) delete_option( $key );
	}

	public function send_to_users( array $user_ids, string $title, string $message, string $url = '', array $meta = [] ): array {
		global $wpdb;
		$this->maybe_install();
		$user_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) ), 0, 100 );
		if ( empty( $user_ids ) ) {
			return [ 'ok' => true, 'message' => __( 'No opted-in administrators matched this alert.', 'dsa' ), 'sent' => 0, 'failed' => 0 ];
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table()} WHERE status = 'active' AND user_id IN ({$placeholders}) ORDER BY updated_at DESC LIMIT 100",
			...$user_ids
		);
		$rows = $wpdb->get_results( $query, ARRAY_A );

		return $this->send_rows( is_array( $rows ) ? $rows : [], $title, $message, $url, $meta );
	}

	private function send_rows( array $rows, string $title, string $message, string $url = '', array $meta = [] ): array {
		if ( ! $this->ready() ) {
			return [ 'ok' => false, 'message' => __( 'VAPID push delivery is unavailable. OpenSSL with P-256 support is required.', 'dsa' ), 'sent' => 0, 'failed' => 0 ];
		}
		$title = $this->truncate( sanitize_text_field( $title ?: get_bloginfo( 'name' ) ), 120 );
		$message = $this->truncate( sanitize_textarea_field( $message ), 1200 );
		$payload_data = [
			'title' => $title,
			'body'  => $message,
			'url'   => esc_url_raw( $url ?: home_url( '/' ) ),
			'icon'  => get_site_icon_url( 192 ),
			'badge' => get_site_icon_url( 96 ),
			'tag'   => 'kiwe-' . substr( md5( $title . '|' . $message ), 0, 12 ),
		];
		foreach ( [ 'eventId', 'eventType', 'kicker', 'aiTitle', 'aiMessage' ] as $key ) {
			if ( isset( $meta[ $key ] ) && '' !== (string) $meta[ $key ] ) {
				$payload_data[ $key ] = $this->truncate( sanitize_text_field( (string) $meta[ $key ] ), 240 );
			}
		}
		$payload = wp_json_encode( $payload_data );
		$sent = 0;
		$failed = 0;
		foreach ( $rows as $row ) {
			$result = $this->send_one( $row, (string) $payload );
			if ( ! empty( $result['ok'] ) ) $sent++; else $failed++;
		}
		return [
			'ok' => $sent > 0 && 0 === $failed,
			'message' => sprintf( __( 'Push accepted for %1$d device(s); %2$d failed. Sends are capped at 100 devices per action.', 'dsa' ), $sent, $failed ),
			'sent' => $sent,
			'failed' => $failed,
		];
	}

	public function ready(): bool {
		if ( ! $this->crypto_support() ) return false;
		$keys = $this->keys();
		return ! empty( $keys['public'] ) && ! empty( $keys['private'] );
	}

	private function crypto_support(): bool {
		$functions = [ 'openssl_pkey_new', 'openssl_pkey_export', 'openssl_pkey_get_details', 'openssl_pkey_get_private', 'openssl_pkey_get_public', 'openssl_pkey_derive', 'openssl_sign', 'openssl_encrypt', 'openssl_get_cipher_methods' ];
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) return false;
		}
		$ciphers = array_map( 'strtolower', openssl_get_cipher_methods() );
		return in_array( 'aes-128-gcm', $ciphers, true );
	}

	private function send_one( array $row, string $payload ): array {
		global $wpdb;
		$endpoint = Secret_Store::decrypt( (string) ( $row['endpoint'] ?? '' ) );
		$p256dh = Secret_Store::decrypt( (string) ( $row['p256dh'] ?? '' ) );
		$auth = Secret_Store::decrypt( (string) ( $row['auth_secret'] ?? '' ) );
		if ( ! $this->valid_endpoint( $endpoint ) || '' === $p256dh || '' === $auth ) {
			$this->mark_reenrollment_required( (int) $row['id'], 'secret_key_mismatch' );
			return [ 'ok' => false ];
		}
		$encrypted = $this->encrypt_payload( $payload, $p256dh, $auth );
		$authorization = $this->vapid_authorization( $endpoint );
		if ( is_wp_error( $encrypted ) || is_wp_error( $authorization ) ) {
			$this->mark_failure( (int) $row['id'], is_wp_error( $encrypted ) ? $encrypted->get_error_code() : $authorization->get_error_code() );
			return [ 'ok' => false ];
		}
		$response = wp_safe_remote_post( $endpoint, [
			'timeout' => 5,
			'redirection' => 0,
			'headers' => [
				'Authorization' => $authorization,
				'Content-Encoding' => 'aes128gcm',
				'Content-Type' => 'application/octet-stream',
				'TTL' => '86400',
				'Urgency' => 'normal',
			],
			'body' => $encrypted,
			'data_format' => 'body',
		] );
		if ( is_wp_error( $response ) ) {
			$this->mark_failure( (int) $row['id'], $response->get_error_code() );
			return [ 'ok' => false ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$wpdb->update( $this->table(), [ 'failure_count' => 0, 'last_error' => '', 'last_success_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row['id'] ] );
			return [ 'ok' => true ];
		}
		$this->mark_failure( (int) $row['id'], 'http_' . $code, in_array( $code, [ 404, 410 ], true ) );
		return [ 'ok' => false ];
	}

	private function encrypt_payload( string $payload, string $receiver_public_b64, string $auth_b64 ) {
		$receiver_public = $this->b64u_decode( $receiver_public_b64 );
		$auth = $this->b64u_decode( $auth_b64 );
		if ( 65 !== strlen( $receiver_public ) || 16 !== strlen( $auth ) || "\x04" !== $receiver_public[0] ) {
			return new \WP_Error( 'dsa_push_keys', __( 'Invalid browser encryption keys.', 'dsa' ) );
		}
		$receiver_key = openssl_pkey_get_public( $this->public_point_pem( $receiver_public ) );
		$ephemeral = openssl_pkey_new( [ 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ] );
		if ( ! $receiver_key || ! $ephemeral ) return new \WP_Error( 'dsa_push_ecdh', __( 'Could not create push encryption keys.', 'dsa' ) );
		$details = openssl_pkey_get_details( $ephemeral );
		$sender_public = $this->raw_public_point( $details );
		$shared = openssl_pkey_derive( $receiver_key, $ephemeral, 32 );
		if ( false === $shared || 65 !== strlen( $sender_public ) ) return new \WP_Error( 'dsa_push_ecdh', __( 'Could not derive push encryption material.', 'dsa' ) );
		$key_info = "WebPush: info\x00" . $receiver_public . $sender_public;
		$prk_key = hash_hmac( 'sha256', $shared, $auth, true );
		$ikm = $this->hkdf_expand( $prk_key, $key_info, 32 );
		$salt = random_bytes( 16 );
		$prk = hash_hmac( 'sha256', $ikm, $salt, true );
		$cek = $this->hkdf_expand( $prk, "Content-Encoding: aes128gcm\x00", 16 );
		$nonce = $this->hkdf_expand( $prk, "Content-Encoding: nonce\x00", 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $ciphertext ) return new \WP_Error( 'dsa_push_encrypt', __( 'Could not encrypt push payload.', 'dsa' ) );
		return $salt . pack( 'N', 4096 ) . chr( strlen( $sender_public ) ) . $sender_public . $ciphertext . $tag;
	}

	private function vapid_authorization( string $endpoint ) {
		$keys = $this->keys();
		$parts = wp_parse_url( $endpoint );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return new \WP_Error( 'dsa_push_audience', __( 'Invalid push audience.', 'dsa' ) );
		$audience = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( ! empty( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '' );
		$email = sanitize_email( get_option( 'admin_email' ) );
		if ( '' === $email ) $email = 'wordpress@' . preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		$header = $this->b64u_encode( wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'ES256' ] ) );
		$claims = $this->b64u_encode( wp_json_encode( [ 'aud' => $audience, 'exp' => time() + 43200, 'sub' => 'mailto:' . $email ] ) );
		$input = $header . '.' . $claims;
		$private = openssl_pkey_get_private( (string) ( $keys['private'] ?? '' ) );
		$signature_der = '';
		if ( ! $private || ! openssl_sign( $input, $signature_der, $private, OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( 'dsa_push_vapid_sign', __( 'Could not sign VAPID token.', 'dsa' ) );
		}
		$signature = $this->ecdsa_der_to_raw( $signature_der );
		if ( 64 !== strlen( $signature ) ) return new \WP_Error( 'dsa_push_vapid_signature', __( 'Invalid VAPID signature.', 'dsa' ) );
		return 'vapid t=' . $input . '.' . $this->b64u_encode( $signature ) . ', k=' . $keys['public'];
	}

	private function keys(): array {
		$stored = get_option( self::KEY_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$private_result = Secret_Store::decrypt_with_status( (string) ( $stored['private'] ?? '' ) );
		$private = 'ok' === ( $private_result['status'] ?? '' ) ? (string) $private_result['value'] : '';
		if ( ! empty( $stored['public'] ) && '' !== $private ) {
			$key_id = (string) ( $stored['key_id'] ?? substr( hash( 'sha256', (string) $stored['public'] ), 0, 16 ) );
			if ( empty( $stored['key_id'] ) || ! empty( $private_result['legacy'] ) ) {
				$stored['key_id'] = $key_id;
				$stored['private'] = Secret_Store::encrypt( $private );
				update_option( self::KEY_OPTION, $stored, false );
			}
			return [ 'public' => (string) $stored['public'], 'private' => $private, 'key_id' => $key_id ];
		}
		$rotated = ! empty( $stored['public'] ) || ! empty( $stored['private'] );
		if ( ! function_exists( 'openssl_pkey_new' ) ) return [];
		$key = openssl_pkey_new( [ 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ] );
		$pem = '';
		if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) return [];
		$details = openssl_pkey_get_details( $key );
		$public = $this->raw_public_point( $details );
		$encrypted = Secret_Store::encrypt( $pem );
		if ( 65 !== strlen( $public ) || '' === $encrypted ) return [];
		$public_b64 = $this->b64u_encode( $public );
		$stored = [ 'public' => $public_b64, 'private' => $encrypted, 'key_id' => substr( hash( 'sha256', $public_b64 ), 0, 16 ), 'created_at' => current_time( 'mysql' ), 'rotated_at' => $rotated ? current_time( 'mysql' ) : '' ];
		update_option( self::KEY_OPTION, $stored, false );
		if ( $rotated ) $this->require_all_subscriptions_to_reenroll( 'vapid_key_rotated' );
		return [ 'public' => $stored['public'], 'private' => $pem, 'key_id' => $stored['key_id'] ];
	}

	private function raw_public_point( $details ): string {
		$x = is_array( $details ) ? (string) ( $details['ec']['x'] ?? '' ) : '';
		$y = is_array( $details ) ? (string) ( $details['ec']['y'] ?? '' ) : '';
		return 32 === strlen( $x ) && 32 === strlen( $y ) ? "\x04" . $x . $y : '';
	}

	private function public_point_pem( string $point ): string {
		$der = hex2bin( '3059301306072A8648CE3D020106082A8648CE3D030107034200' ) . $point;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	private function ecdsa_der_to_raw( string $der ): string {
		$offset = 0;
		if ( ord( $der[ $offset++ ] ?? "\x00" ) !== 0x30 ) return '';
		$this->der_length( $der, $offset );
		if ( ord( $der[ $offset++ ] ?? "\x00" ) !== 0x02 ) return '';
		$r_length = $this->der_length( $der, $offset );
		$r = substr( $der, $offset, $r_length );
		$offset += $r_length;
		if ( ord( $der[ $offset++ ] ?? "\x00" ) !== 0x02 ) return '';
		$s_length = $this->der_length( $der, $offset );
		$s = substr( $der, $offset, $s_length );
		$r = str_pad( ltrim( $r, "\x00" ), 32, "\x00", STR_PAD_LEFT );
		$s = str_pad( ltrim( $s, "\x00" ), 32, "\x00", STR_PAD_LEFT );
		return substr( $r, -32 ) . substr( $s, -32 );
	}

	private function der_length( string $der, int &$offset ): int {
		$length = ord( $der[ $offset++ ] ?? "\x00" );
		if ( $length < 0x80 ) return $length;
		$bytes = $length & 0x7f;
		$length = 0;
		for ( $i = 0; $i < $bytes; $i++ ) $length = ( $length << 8 ) | ord( $der[ $offset++ ] ?? "\x00" );
		return $length;
	}

	private function hkdf_expand( string $prk, string $info, int $length ): string {
		return substr( hash_hmac( 'sha256', $info . "\x01", $prk, true ), 0, $length );
	}

	private function mark_failure( int $id, string $error, bool $expired = false ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT failure_count FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		$failures = (int) ( $row['failure_count'] ?? 0 ) + 1;
		$wpdb->update( $this->table(), [
			'status' => $expired || $failures >= 5 ? 'expired' : 'active',
			'failure_count' => $failures,
			'last_error' => substr( sanitize_key( $error ), 0, 190 ),
			'updated_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] );
	}

	private function mark_reenrollment_required( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [
			'status' => 'reenroll_required',
			'last_error' => substr( sanitize_key( $error ), 0, 190 ),
			'updated_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] );
	}

	private function require_all_subscriptions_to_reenroll( string $reason ): void {
		global $wpdb;
		$this->maybe_install();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table()} SET status='reenroll_required', last_error=%s, updated_at=%s WHERE status='active'",
			sanitize_key( $reason ),
			current_time( 'mysql' )
		) );
		update_option( 'dsa_push_key_rotation', [ 'at' => time(), 'reason' => sanitize_key( $reason ) ], false );
	}

	private function valid_endpoint( string $endpoint ): bool {
		if ( ! wp_http_validate_url( $endpoint ) || 'https' !== strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) ) return false;
		$host = strtolower( (string) wp_parse_url( $endpoint, PHP_URL_HOST ) );
		$allowed = [
			'fcm.googleapis.com',
			'updates.push.services.mozilla.com',
			'web.push.apple.com',
		];
		if ( in_array( $host, $allowed, true ) ) return true;
		foreach ( [ '.push.services.mozilla.com', '.notify.windows.com', '.push.apple.com' ] as $suffix ) {
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) return true;
		}
		return (bool) apply_filters( 'dsa_push_endpoint_allowed', false, $host, $endpoint );
	}

	private function visitor_hash( string $visitor_id, int $user_id ): string {
		$identity = '' !== $visitor_id ? $visitor_id : ( $user_id ? 'user:' . $user_id : 'anonymous' );
		return hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) );
	}

	private function renewal_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
	}

	private function b64u_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private function b64u_decode( string $value ): string {
		$padding = strlen( $value ) % 4;
		if ( $padding ) $value .= str_repeat( '=', 4 - $padding );
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}

	private function truncate( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	private function remember_job( string $job_id ): void {
		$jobs = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_option( 'dsa_push_active_jobs', [] ) ) ) ) );
		$jobs[] = sanitize_key( $job_id );
		update_option( 'dsa_push_active_jobs', array_slice( array_values( array_unique( $jobs ) ), -20 ), false );
	}

	private function forget_job( string $job_id ): void {
		$jobs = array_values( array_filter( (array) get_option( 'dsa_push_active_jobs', [] ), static fn( $candidate ): bool => (string) $candidate !== $job_id ) );
		update_option( 'dsa_push_active_jobs', $jobs, false );
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsa_push_subscriptions';
	}
}
