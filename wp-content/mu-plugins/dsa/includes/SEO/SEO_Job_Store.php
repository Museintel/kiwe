<?php

namespace DSA\SEO;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One bounded site job; native content remains in WordPress. All mutations use byte-exact CAS. */
final class SEO_Job_Store {
	public const OPTION = 'kiwe_seo_refinement_job_v2';
	public const CAPACITY = 20;
	private const MAX_BYTES = 262144;
	private const LEASE_SECONDS = 120;

	/** Do not deserialize the unbounded legacy option just to explain compatibility. */
	public function has_legacy(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name=%s", 'kiwe_seo_refinement_job_v1' ) );
	}

	public function read() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT CASE WHEN OCTET_LENGTH(option_value)<=%d THEN option_value ELSE '' END FROM {$wpdb->options} WHERE option_name=%s", self::MAX_BYTES, self::OPTION ) );
		if ( ! empty( $wpdb->last_error ) ) return $this->error( 'storage', 'SEO job storage is unavailable.' );
		if ( null === $raw ) return [ 'raw'=>null, 'job'=>[] ];
		$job = is_string( $raw ) && strlen( $raw ) <= self::MAX_BYTES ? json_decode( $raw, true ) : null;
		if ( ! $this->valid( $job ) ) return $this->error( 'invalid_state', 'The SEO job could not be validated. Preserve it for your website manager to inspect.' );
		if ( $job['updatedAt'] > time() ) return $this->error( 'clock_changed', 'The website clock moved backwards. SEO work is paused.' );
		return [ 'raw'=>$raw, 'job'=>$job ];
	}

	public function start( string $scope, int $upper, int $total, int $actor ) {
		if ( ! in_array( $scope, [ 'all_posts','all_pages','all_products','all_media' ], true ) || $upper < 0 || $total < 0 || $actor < 1 ) return $this->error( 'invalid', 'Invalid SEO scope.' );
		$id = bin2hex( random_bytes( 16 ) );
		return $this->change( function( array $old ) use ( $scope, $upper, $total, $actor, $id ) {
			if ( $old && ( ! in_array( $old['status'], [ 'complete','cancelled' ], true ) || $old['proposals'] || $this->busy( $old ) ) ) return $this->error( 'job_exists', 'Finish or cancel the current job and review its remaining proposals first.' );
			return [ 'schema'=>2, 'id'=>$id, 'scope'=>$scope, 'upperId'=>$upper, 'cursorId'=>0, 'initialTotal'=>$total, 'scanComplete'=>0 === $upper,
				'counts'=>[ 'inspected'=>0, 'eligible'=>0, 'proposed'=>0, 'applied'=>0, 'dismissed'=>0, 'skipped'=>0, 'errors'=>0 ],
				'status'=>0 === $upper ? 'complete' : 'queued', 'proposals'=>[], 'batchSize'=>5, 'nextAt'=>time(), 'lastError'=>null, 'lease'=>null, 'createdBy'=>$actor, 'createdAt'=>time(), 'updatedAt'=>time() ];
		} );
	}

	/** Controls preserve a live lease, so a replacement job/reviewer cannot race ongoing work. */
	public function control( string $id, string $action ) {
		return $this->change( function( array $job ) use ( $id, $action ) {
			if ( ! $job || $job['id'] !== $id || ! in_array( $action, [ 'pause','resume','cancel' ], true ) ) return $this->error( 'stale_job', 'This SEO job is no longer current.' );
			if ( 'cancel' === $action ) { $job['status'] = 'cancelled'; return $job; }
			if ( in_array( $job['status'], [ 'cancelled','complete' ], true ) ) return $this->error( 'finished', 'Start a new review after clearing remaining proposals.' );
			if ( 'pause' === $action ) { $job['status'] = 'paused'; return $job; }
			if ( $this->busy( $job ) ) return $this->error( 'busy', 'A bounded operation is still finishing. Try again shortly.' );
			if ( $job['nextAt'] > time() ) return $this->error( 'waiting', 'The shared allowance is still paused. Resume after the displayed time.' );
			if ( count( $job['proposals'] ) >= self::CAPACITY ) return $this->error( 'review_full', 'Review some proposals before resuming.' );
			$job['lease'] = null;
			$job['status'] = $job['scanComplete'] ? ( $job['proposals'] ? 'review' : 'complete' ) : 'queued';
			$job['lastError'] = null;
			return $job;
		} );
	}

	public function claim( string $id, string $proposal = '', string $version = '' ) {
		$token = bin2hex( random_bytes( 16 ) );
		return $this->change( function( array $job ) use ( $id, $proposal, $version, $token ) {
			if ( ! $job || $job['id'] !== $id ) return $this->error( 'stale_job', 'This SEO job is no longer current.' );
			if ( $this->busy( $job ) ) return $this->error( 'busy', 'Another bounded operation is still finishing.' );
			if ( $job['lease'] ) {
				$job['lease'] = null;
				if ( 'cancelled' !== $job['status'] ) $job['status'] = 'paused';
				$job['lastError'] = [ 'code'=>'worker_interrupted', 'message'=>'The previous operation was interrupted. Check native content before resuming; an already sent AI request may have been charged.', 'retryAt'=>0 ];
				return $job;
			}
			if ( '' === $proposal ) {
				if ( 'queued' !== $job['status'] || $job['nextAt'] > time() ) return $this->error( 'not_ready', 'This job is paused or waiting.' );
				if ( count( $job['proposals'] ) >= self::CAPACITY ) { $job['status'] = 'review'; return $job; }
			} elseif ( ! isset( $job['proposals'][ $proposal ] ) || ! hash_equals( self::proposal_version( $job['proposals'][ $proposal ] ), $version ) ) return $this->error( 'stale_proposal', 'This proposal changed. Refresh before reviewing it.' );
			$job['lease'] = [ 'token'=>$token, 'until'=>time() + self::LEASE_SECONDS, 'kind'=>'' === $proposal ? 'work' : 'review' ];
			return $job;
		} );
	}

	/** Fenced completion merges only worker-owned fields; pause/cancel controls win. */
	public function finish( string $id, string $token, array $result, bool $review = false ) {
		return $this->change( function( array $job ) use ( $id, $token, $result, $review ) {
			if ( ! $job || $job['id'] !== $id || ! $job['lease'] || ! hash_equals( $job['lease']['token'], $token ) || ! $this->busy( $job ) ) return $this->error( 'lease_lost', 'This operation lost its lease. Refresh and inspect the current job.' );
			$status = $job['status'];
			$job['lease'] = null;
			if ( 'cancelled' === $status && ! $review ) return $job;
			foreach ( [ 'cursorId','scanComplete','counts','proposals','batchSize','nextAt','lastError','status' ] as $field ) if ( array_key_exists( $field, $result ) ) $job[ $field ] = $result[ $field ];
			if ( in_array( $status, [ 'paused','cancelled' ], true ) ) $job['status'] = $status;
			return $job;
		} );
	}

	public function owns( string $id, string $token, bool $work = false ): bool {
		$read = $this->read();
		return ! is_wp_error( $read ) && $read['job'] && $read['job']['id'] === $id && $this->busy( $read['job'] ) && hash_equals( $read['job']['lease']['token'], $token ) && ( ! $work || ( 'queued' === $read['job']['status'] && 'work' === $read['job']['lease']['kind'] ) );
	}

	public static function proposal_version( array $proposal ): string { return hash( 'sha256', (string) wp_json_encode( $proposal ) ); }

	private function busy( array $job ): bool { return $job['lease'] && $job['lease']['until'] > time(); }

	/** Callback is pure: never put native writes or provider calls inside the CAS retry. */
	private function change( callable $callback ) {
		global $wpdb;
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$read = $this->read();
			if ( is_wp_error( $read ) ) return $read;
			$job = $callback( $read['job'] );
			if ( is_wp_error( $job ) ) return $job;
			$job['updatedAt'] = time();
			if ( ! $this->valid( $job ) ) return $this->error( 'invalid_write', 'The SEO result could not be safely stored.' );
			$next = wp_json_encode( $job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $next ) || strlen( $next ) > self::MAX_BYTES ) return $this->error( 'capacity', 'The SEO review buffer is full. Review existing proposals first.' );
			if ( $next === $read['raw'] ) return $job;
			$sql = null === $read['raw']
				? $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::OPTION, $next )
				: $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $next, self::OPTION, $read['raw'] );
			$written = $wpdb->query( $sql );
			if ( false === $written ) return $this->error( 'storage', 'The SEO job could not be saved.' );
			if ( 1 === $written ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); return $job; }
		}
		return $this->error( 'busy', 'The SEO job changed during this action. Refresh and try again.' );
	}

	private function valid( $job ): bool {
		if ( ! is_array( $job ) || 2 !== ( $job['schema'] ?? null ) || ! is_string( $job['id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $job['id'] ) || ! in_array( $job['scope'] ?? null, [ 'all_posts','all_pages','all_products','all_media' ], true ) ) return false;
		foreach ( [ 'upperId','cursorId','initialTotal','batchSize','nextAt','createdBy','createdAt','updatedAt' ] as $key ) if ( ! is_int( $job[ $key ] ?? null ) || $job[ $key ] < 0 ) return false;
		if ( $job['cursorId'] > $job['upperId'] || $job['batchSize'] < 1 || $job['batchSize'] > 5 || $job['createdBy'] < 1 || $job['createdAt'] > $job['updatedAt'] || ! is_bool( $job['scanComplete'] ?? null ) ) return false;
		if ( ! in_array( $job['status'] ?? null, [ 'queued','paused','cancelled','review','complete' ], true ) || ! is_array( $job['counts'] ?? null ) || ! is_array( $job['proposals'] ?? null ) || count( $job['proposals'] ) > self::CAPACITY ) return false;
		foreach ( [ 'inspected','eligible','proposed','applied','dismissed','skipped','errors' ] as $key ) if ( ! is_int( $job['counts'][ $key ] ?? null ) || $job['counts'][ $key ] < 0 ) return false;
		if ( ! array_key_exists( 'lease', $job ) || ! array_key_exists( 'lastError', $job ) || ( null !== $job['lastError'] && ( ! is_array( $job['lastError'] ) || ! is_string( $job['lastError']['message'] ?? null ) || ! is_string( $job['lastError']['code'] ?? null ) || ! is_int( $job['lastError']['retryAt'] ?? null ) ) ) ) return false;
		if ( null !== $job['lease'] && ( ! is_array( $job['lease'] ) || ! is_string( $job['lease']['token'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $job['lease']['token'] ) || ! is_int( $job['lease']['until'] ?? null ) || ! in_array( $job['lease']['kind'] ?? null, [ 'work','review' ], true ) ) ) return false;
		foreach ( $job['proposals'] as $id=>$proposal ) if ( ! preg_match( '/^[a-f0-9]{16}$/D', (string) $id ) || ! is_array( $proposal ) || ! is_int( $proposal['objectId'] ?? null ) || $proposal['objectId'] < 1 || ( $proposal['scope'] ?? '' ) !== $job['scope'] || ! is_string( $proposal['originalHash'] ?? null ) || ! is_array( $proposal['fields'] ?? null ) || ! is_string( $proposal['label'] ?? null ) || ! is_string( $proposal['reason'] ?? null ) ) return false;
		foreach ( $job['proposals'] as $proposal ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/D', $proposal['originalHash'] ) || strlen( $proposal['label'] ) > 200 || strlen( $proposal['reason'] ) > 300 || ! $proposal['fields'] ) return false;
			$limits = 'all_media' === $job['scope'] ? [ 'title'=>200,'alt'=>300,'caption'=>1000,'description'=>3000 ] : [ 'seoTitle'=>200,'metaDescription'=>320,'excerpt'=>1000,'searchPhrases'=>120 ];
			foreach ( $proposal['fields'] as $key=>$value ) {
				if ( ! isset( $limits[ $key ] ) ) return false;
				if ( 'searchPhrases' === $key ) {
					if ( ! is_array( $value ) || count( $value ) > 10 ) return false;
					foreach ( $value as $phrase ) if ( ! is_string( $phrase ) || strlen( $phrase ) > 120 ) return false;
				} elseif ( ! is_string( $value ) || strlen( $value ) > $limits[ $key ] ) return false;
			}
		}
		return true;
	}

	private function error( string $code, string $message ): \WP_Error { return new \WP_Error( 'kiwe_seo_' . $code, __( $message, 'dsa' ) ); }
}
