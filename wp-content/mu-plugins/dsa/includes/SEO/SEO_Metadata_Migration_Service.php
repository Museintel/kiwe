<?php

namespace DSA\SEO;

use DSA\Access\Task_Workspace_UI as UI;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded, reversible import of the Rank Math fields Kiwe can render natively. */
final class SEO_Metadata_Migration_Service {
	public const OPTION = 'kiwe_seo_metadata_migration_v1';
	public const CRON = 'kiwe_seo_metadata_migration_batch_v1';
	public const BACKUP_META = '_kiwe_seo_migration_backup_v1';
	public const MARKER_META = '_kiwe_seo_migration_job_v1';
	private string $target_type = 'post';
	private int $unsupported = 0;
	private string $writer_key = '';
	private const BATCH = 25;
	private const HISTORY = 10;
	private const SOURCE_KEYS = [
		'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_robots', 'rank_math_canonical_url',
		'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_facebook_image',
		'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_twitter_image',
	];
	private const TARGET_KEYS = [
		'_kiwe_seo_title', '_kiwe_seo_description', '_kiwe_search_phrases', '_kiwe_seo_robots', '_kiwe_seo_canonical_url',
		'_kiwe_seo_facebook_title', '_kiwe_seo_facebook_description', '_kiwe_seo_facebook_image',
		'_kiwe_seo_twitter_title', '_kiwe_seo_twitter_description', '_kiwe_seo_twitter_image',
	];

	public function register(): void {
		add_action( self::CRON, [ $this, 'run' ], 10, 1 );
		if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE ) add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		( new SEO_Settings_Handover_Service() )->routes();
		register_rest_route( 'dsa/v1', '/workspace/ai/seo-migration', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'mutate' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}

	public function permission( \WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $request->get_method(), [ 'GET','POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return UI::failure( new \WP_Error( 'kiwe_seo_migration_forbidden', __( 'SEO migration is available only to the website manager.', 'dsa' ) ), 403 );
		return UI::response( $this->status() );
	}

	public function mutate( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return UI::failure( new \WP_Error( 'kiwe_seo_migration_forbidden', __( 'SEO migration is available only to the website manager.', 'dsa' ) ), 403 );
		$body = $request->get_json_params();
		$operation = is_array( $body ) && is_string( $body['operation'] ?? null ) ? sanitize_key( $body['operation'] ) : '';
		$expected = is_array( $body ) && is_string( $body['expected'] ?? null ) ? (string) $body['expected'] : '';
		$confirmed = is_array( $body ) && true === ( $body['confirmed'] ?? null );
		if ( ! in_array( $operation, [ 'start','resume','rollback' ], true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! $confirmed ) return UI::failure( new \WP_Error( 'kiwe_seo_migration_request', __( 'Review and confirm the SEO migration action.', 'dsa' ) ), 400 );
		$scope = $body['scope'] ?? ( 'start' === $operation ? 'post' : ( $this->state()['lastImport']['scope'] ?? 'post' ) );
		if ( ! in_array( $scope, [ 'post', 'term' ], true ) ) return UI::failure( new \WP_Error( 'kiwe_seo_migration_scope', 'Choose post or taxonomy metadata.' ), 400 );
		$result = $this->owned( function() use ( $expected, $operation, $scope ) {
			$current = $this->status();
			if ( ! hash_equals( (string) $current['expected'], $expected ) ) return new \WP_Error( 'kiwe_seo_migration_changed', __( 'The SEO migration inventory changed. Refresh and review it again.', 'dsa' ) );
			return match ( $operation ) {
				'start' => $this->start_import( $current, $scope ),
				'rollback' => $this->start_rollback( $current, $scope ),
				default => $this->resume( $current ),
			};
		} );
		if ( is_wp_error( $result ) ) return UI::failure( $result, 409 );
		return UI::response( $this->status() );
	}

	public function status(): array {
		$state = $this->state();
		$source = $this->source_stats();
		$terms = $this->source_stats( 'term' );
		$active = is_array( $state['active'] ?? null ) ? $this->public_job( $state['active'] ) : null;
		$last = is_array( $state['lastImport'] ?? null ) ? $state['lastImport'] : null;
		$scopes = [];
		foreach ( [ 'post'=>$source, 'term'=>$terms ] as $scope=>$inventory ) {
			$prior = $state['lastImports'][$scope] ?? null;
			$count = $prior ? $this->rollback_count( (string) $prior['id'], $scope ) : 0;
			$scopes[$scope] = [ 'source'=>$inventory, 'lastImport'=>$prior ? $this->public_job( $prior ) : null, 'rollbackRecords'=>$count, 'canStart'=>null === $active && $inventory['records'] > 0 && 0 === $count, 'canRollback'=>null === $active && $count > 0 ];
		}
		$selected = $last['scope'] ?? 'post';
		$rollback_records = $scopes[$selected]['rollbackRecords'];
		$view = [
			'schema'=>'kiwe.seo-metadata-migration.v1',
			'source'=>[
				'id'=>'rank_math', 'label'=>'Rank Math', 'detected'=>( $source['records'] + $terms['records'] ) > 0,
				'pluginActive'=>defined( 'RANK_MATH_VERSION' ), 'records'=>$source['records'], 'fields'=>$source['fields'],
			],
			'supportedFields'=>[
				'SEO title', 'meta description', 'focus phrases', 'robots directives', 'canonical URL',
				'Facebook title, description and image', 'Twitter title, description and image',
			],
			'notImported'=>[ 'plugin schema graphs', 'redirections', 'analytics', 'instant indexing', 'plugin settings and role capabilities', 'unsupported template variables' ],
			'policy'=>[
				'mode'=>'fill_empty', 'sourcePreserved'=>true, 'reversible'=>true,
				'frontendAuthority'=>defined( 'RANK_MATH_VERSION' ) ? 'rank_math' : 'kiwe',
			],
			'termSource'=>$terms,
			'scopes'=>$scopes,
			'active'=>$active,
			'lastImport'=>$last ? $this->public_job( $last ) : null,
			'rollbackRecords'=>$rollback_records,
			'canStart'=>null === $active && ( $source['records'] + $terms['records'] ) > 0 && 0 === $rollback_records,
			'canRollback'=>null === $active && $rollback_records > 0,
			'history'=>array_map( [ $this, 'public_job' ], array_slice( is_array( $state['history'] ?? null ) ? $state['history'] : [], 0, self::HISTORY ) ),
		];
		$view['expected'] = hash( 'sha256', (string) wp_json_encode( [ $source['fingerprint'], $terms['fingerprint'], $view['active'], $view['lastImport'], $scopes ] ) );
		return $view;
	}

	private function start_import( array $status, string $scope = 'post' ) {
		if ( $status['active'] ) return new \WP_Error( 'kiwe_seo_migration_active', __( 'Another SEO migration action is already active.', 'dsa' ) );
		if ( ! $status['scopes'][$scope]['canStart'] ) return new \WP_Error( 'kiwe_seo_migration_unavailable', __( 'No eligible Rank Math inventory is ready in this scope, or its previous import still has rollback data.', 'dsa' ) );
		$source = $this->source_stats( $scope );
		if ( ! $source['records'] ) return new \WP_Error( 'kiwe_seo_migration_empty', 'No eligible source metadata exists in this scope.' );
		$job = $this->new_job( 'import', (int) $source['upper'], '', $scope );
		return $this->save_and_schedule( $job );
	}

	private function start_rollback( array $status, string $scope ) {
		if ( $status['active'] ) return new \WP_Error( 'kiwe_seo_migration_active', __( 'Another SEO migration action is already active.', 'dsa' ) );
		$state = $this->state();
		$last = $state['lastImports'][$scope] ?? [];
		$source_job = sanitize_key( (string) ( $last['id'] ?? '' ) );
		if ( ! $status['scopes'][$scope]['canRollback'] || ! preg_match( '/^[a-f0-9]{16}$/D', $source_job ) ) return new \WP_Error( 'kiwe_seo_migration_rollback', __( 'There is no retained Kiwe SEO import to roll back in this scope.', 'dsa' ) );
		$job = $this->new_job( 'rollback', PHP_INT_MAX, $source_job, (string) ( $last['scope'] ?? 'post' ) );
		return $this->save_and_schedule( $job );
	}

	private function resume( array $status ) {
		$state = $this->state();
		$job = is_array( $state['active'] ?? null ) ? $state['active'] : [];
		if ( ! $job || ! in_array( (string) ( $job['status'] ?? '' ), [ 'queued','running','stalled' ], true ) ) return new \WP_Error( 'kiwe_seo_migration_resume', __( 'There is no interrupted SEO migration action to resume.', 'dsa' ) );
		$job['status'] = 'queued'; $job['reason'] = ''; $job['updated'] = time();
		return $this->save_and_schedule( $job );
	}

	private function new_job( string $operation, int $upper, string $source_job, string $scope = 'post' ): array {
		return [
			'id'=>bin2hex( random_bytes( 8 ) ), 'operation'=>$operation, 'scope'=>$scope, 'unsupported'=>0, 'sourceJob'=>$source_job, 'status'=>'queued', 'reason'=>'',
			'cursor'=>0, 'upper'=>max( 0, $upper ), 'scanned'=>0, 'records'=>0, 'fields'=>0, 'skipped'=>0, 'conflicts'=>0,
			'failed'=>0, 'actor'=>get_current_user_id(), 'created'=>time(), 'updated'=>time(),
		];
	}

	private function save_and_schedule( array $job ) {
		$state = $this->state(); $state['active'] = $job;
		if ( ! $this->write_state( $state ) ) return new \WP_Error( 'kiwe_seo_migration_storage', __( 'The SEO migration state could not be saved.', 'dsa' ) );
		// A stalled/lease-lost action may still have its earlier event. Reuse it on resume.
		if ( ! wp_next_scheduled( self::CRON, [ $job['id'] ] ) && ! wp_schedule_single_event( time() + 5, self::CRON, [ $job['id'] ] ) ) {
			$state = $this->state();
			if ( ( $state['active']['id'] ?? '' ) === $job['id'] ) { $state['active']['status']='stalled'; $state['active']['reason']='schedule_failed'; $state['active']['updated']=time(); $this->write_state( $state ); }
			return new \WP_Error( 'kiwe_seo_migration_schedule', __( 'The SEO migration could not be scheduled. Refresh and resume it.', 'dsa' ) );
		}
		return true;
	}

	public function run( string $id ): void {
		$id = sanitize_key( $id );
		if ( ! preg_match( '/^[a-f0-9]{16}$/D', $id ) ) return;
		$this->owned( function() use ( $id ) {
			$state = $this->state(); $job = is_array( $state['active'] ?? null ) ? $state['active'] : [];
			if ( ( $job['id'] ?? '' ) !== $id ) return;
			if ( ! user_can( absint( $job['actor'] ?? 0 ), 'manage_options' ) ) { $this->stall( $job, 'actor_permission_lost' ); return; }
			$this->target_type = 'term' === ( $job['scope'] ?? '' ) ? 'term' : 'post';
			$job['status']='running'; $job['reason']=''; $job['updated']=time(); $state['active']=$job; if ( ! $this->write_state( $state ) ) return;
			$ids = 'rollback' === $job['operation'] ? $this->next_rollback_ids( $job ) : $this->next_import_ids( $job );
			global $wpdb;
			if ( ! empty( $wpdb->last_error ) ) { $this->stall( $job, 'source_read_failed' ); return; }
			foreach ( $ids as $object_id ) {
				$result = 'rollback' === $job['operation'] ? $this->rollback_record( $object_id, (string) $job['sourceJob'] ) : $this->import_record( $object_id, $id );
				if ( ! empty( $wpdb->last_error ) || ! empty( $result['failed'] ) ) { ++$job['failed']; $this->stall( $job, 'record_write_failed' ); return; }
				++$job['scanned']; $job['cursor'] = max( $job['cursor'], $object_id );
				foreach ( [ 'records','fields','skipped','conflicts','failed','unsupported' ] as $key ) $job[$key] = absint( $job[$key] ?? 0 ) + absint( $result[$key] ?? 0 );
			}
			$job['updated']=time();
			if ( count( $ids ) < self::BATCH || ( 'import' === $job['operation'] && $job['cursor'] >= $job['upper'] ) ) { $this->finish( $job ); return; }
			$state=$this->state(); if ( ( $state['active']['id'] ?? '' ) !== $id ) return; $state['active']=$job; $this->write_state( $state );
			if ( ! wp_schedule_single_event( time() + 5, self::CRON, [ $id ] ) ) $this->stall( $job, 'schedule_failed' );
		} );
	}

	/** Session ownership is immediate and scoped to the actual website tables. */
	private function owned( callable $operation ) {
		global $wpdb;
		$key = 'kiwe_meta_' . substr( hash( 'sha256', (string) ( $wpdb->dbname ?? '' ) . '|' . $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $key ) ) ) return new \WP_Error( 'kiwe_seo_migration_busy', 'Another SEO migration writer is active. Refresh before retrying.' );
		$this->writer_key = $key;
		try { return $operation(); } finally { $this->writer_key = ''; $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	/** A reconnect must not let a former worker publish into the current worker's state. */
	private function owns_writer(): bool {
		global $wpdb;
		return '' !== $this->writer_key && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', $this->writer_key ) );
	}

	private function import_record( int $id, string $job_id ): array {
		$existing_marker = (string) $this->meta_get( $id, self::MARKER_META, true );
		$existing_backup = $this->meta_get( $id, self::BACKUP_META, true );
		if ( '' !== $existing_marker || $this->meta_exists( $id, self::BACKUP_META ) ) {
			if ( ( '' !== $existing_marker && $existing_marker !== $job_id ) || ! is_array( $existing_backup ) || ( $existing_backup['job'] ?? '' ) !== $job_id ) return [ 'conflicts'=>1 ];
			// A process can stop after storing the journal, before its locator. Reuse it;
			// never replace the original before-image with partially imported values.
			if ( '' === $existing_marker && ! $this->meta_add( $id, self::MARKER_META, $job_id ) ) return [ 'failed'=>1 ];
			$restored = $this->restore_backup( $id, $existing_backup );
			if ( ! $restored['ok'] ) return $restored['conflicts'] ? [ 'conflicts'=>1 ] : [ 'failed'=>1 ];
			if ( ! $this->retire_journal( $id, $existing_backup, $job_id ) ) return [ 'failed'=>1 ];
		}
		$post = 'term' === $this->target_type ? get_term( $id ) : get_post( $id ); if ( ! $post || is_wp_error( $post ) ) return [ 'failed'=>1 ];
		if ( $post instanceof \WP_Term ? ! in_array( $post->taxonomy, get_taxonomies( [ 'public'=>true ] ), true ) : ( ! in_array( $post->post_type, $this->post_types(), true ) || ! in_array( $post->post_status, [ 'publish','draft','pending','future','private' ], true ) ) ) return [ 'skipped'=>1 ];
		$this->unsupported = 0;
		$values = $this->mapped_values( $post );
		if ( ! $values ) return [ 'skipped'=>1, 'unsupported'=>$this->unsupported ];
		$write=[]; $conflicts=0;
		foreach ( $values as $key=>$value ) {
			$exists = $this->meta_exists( $id, $key ); $current = $exists ? $this->meta_get( $id, $key, true ) : null;
			if ( $exists && ! $this->empty_value( $current ) ) { if ( $current !== $value ) ++$conflicts; continue; }
			$write[$key]=$value;
		}
		if ( ! $write ) return [ 'skipped'=>1, 'conflicts'=>$conflicts, 'unsupported'=>$this->unsupported ];
		$backup=[ 'schema'=>2, 'job'=>$job_id, 'values'=>[], 'imported'=>[] ];
		foreach ( array_keys( $write ) as $key ) $backup['values'][$key]=[ 'exists'=>$this->meta_exists( $id, $key ), 'value'=>$this->meta_get( $id, $key, true ) ];
		if ( $post instanceof \WP_Post && 'page' === $post->post_type && isset( $write['_kiwe_seo_robots'] ) ) {
			$key=Design_Context_Profile_Service::PAGE_VISIBILITY_META; $backup['values'][$key]=[ 'exists'=>$this->meta_exists( $id, $key ), 'value'=>$this->meta_get( $id, $key, true ) ];
			$write[$key]=in_array( 'noindex', (array) $write['_kiwe_seo_robots'], true ) ? 'secondary' : 'primary';
		}
		$backup['imported'] = $write;
		if ( ! $this->meta_add( $id, self::BACKUP_META, $backup ) ) return [ 'failed'=>1 ];
		if ( $this->meta_get( $id, self::BACKUP_META, true ) !== $backup ) return [ 'failed'=>1 ];
		if ( ! $this->meta_add( $id, self::MARKER_META, $job_id ) || $this->meta_get( $id, self::MARKER_META, true ) !== $job_id ) return [ 'failed'=>1, 'conflicts'=>$conflicts ];
		$written=0;
		foreach ( $write as $key=>$value ) {
			if ( ! $this->import_field( $id, $key, $value, $backup['values'][$key] ) ) {
				$restored = $this->restore_backup( $id, $backup );
				if ( $restored['ok'] ) $this->retire_journal( $id, $backup, $job_id );
				return [ 'failed'=>1, 'conflicts'=>$conflicts + $restored['conflicts'] ];
			}
			++$written;
		}
		$this->clean_target_cache( $id );
		return [ 'records'=>1, 'fields'=>$written, 'conflicts'=>$conflicts, 'unsupported'=>$this->unsupported ];
	}

	/** Existing empty fields use a binary native-row comparison; missing fields are add-only. */
	private function import_field( int $id, string $key, $value, array $before ): bool {
		global $wpdb;
		if ( ! $this->owns_writer() ) return false;
		$table = 'term' === $this->target_type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $this->target_type ? 'term_id' : 'post_id';
		if ( $before['exists'] ) {
			$rows = $this->metadata_rows( $id, $key );
			if ( $wpdb->last_error || count( $rows ) !== 1 || $rows[0]['value'] !== (string) maybe_serialize( $before['value'] ) ) return false;
			$mid = (int) $rows[0]['id'];
			do_action( 'update_' . $this->target_type . '_meta', $mid, $id, $key, $value );
			if ( ! $this->owns_writer() ) return false;
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET meta_value=%s WHERE meta_id=%d AND {$column}=%d AND meta_key=%s AND BINARY meta_value=%s LIMIT 1", maybe_serialize( $value ), $mid, $id, $key, maybe_serialize( $before['value'] ) ) );
			wp_cache_delete( $id, $this->target_type . '_meta' );
			if ( 1 !== (int) $changed || $wpdb->last_error ) return false;
			do_action( 'updated_' . $this->target_type . '_meta', $mid, $id, $key, $value );
		} else {
			$added = 'term' === $this->target_type ? add_term_meta( $id, $key, wp_slash( $value ), true ) : add_post_meta( $id, $key, wp_slash( $value ), true );
			if ( ! $added ) return false;
		}
		$rows = $this->metadata_rows( $id, $key );
		return ! $wpdb->last_error && count( $rows ) === 1 && $rows[0]['value'] === (string) maybe_serialize( $value ) && $this->meta_get( $id, $key, true ) === $value;
	}

	private function metadata_rows( int $id, string $key ): array {
		global $wpdb;
		$table = 'term' === $this->target_type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $this->target_type ? 'term_id' : 'post_id';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id AS id,meta_value AS value FROM {$table} WHERE {$column}=%d AND meta_key=%s ORDER BY meta_id LIMIT 2", $id, $key ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	private function rollback_record( int $id, string $source_job ): array {
		if ( $this->meta_get( $id, self::MARKER_META, true ) !== $source_job ) return [ 'skipped'=>1 ];
		$backup=$this->meta_get( $id, self::BACKUP_META, true );
		if ( ! is_array( $backup ) || ( $backup['job'] ?? '' ) !== $source_job || ! is_array( $backup['values'] ?? null ) ) return [ 'failed'=>1 ];
		$restored = $this->restore_backup( $id, $backup );
		if ( ! $restored['ok'] ) return $restored['conflicts'] ? [ 'conflicts'=>1 ] : [ 'failed'=>1 ];
		if ( ! $this->retire_journal( $id, $backup, $source_job ) ) return [ 'failed'=>1 ];
		$this->clean_target_cache( $id );
		return [ 'records'=>1, 'fields'=>count( $backup['values'] ) ];
	}

	/** One conditional statement retires the exact journal and locator together. */
	private function retire_journal( int $id, array $backup, string $job ): bool {
		global $wpdb;
		if ( ! $this->owns_writer() ) return false;
		$b = $this->metadata_rows( $id, self::BACKUP_META ); $m = $this->metadata_rows( $id, self::MARKER_META );
		if ( $wpdb->last_error || count( $b ) !== 1 || count( $m ) !== 1 || $b[0]['value'] !== (string) maybe_serialize( $backup ) || $m[0]['value'] !== $job ) return false;
		$table = 'term' === $this->target_type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $this->target_type ? 'term_id' : 'post_id';
		foreach ( [ self::BACKUP_META=>$backup, self::MARKER_META=>$job ] as $key=>$value ) do_action( 'delete_' . $this->target_type . '_meta', [ (int) ( self::BACKUP_META === $key ? $b[0]['id'] : $m[0]['id'] ) ], $id, $key, $value );
		if ( ! $this->owns_writer() ) return false;
		$sql = "DELETE j FROM {$table} j INNER JOIN {$table} b ON b.{$column}=j.{$column} AND b.meta_id=%d AND b.meta_key=%s AND BINARY b.meta_value=%s INNER JOIN {$table} m ON m.{$column}=j.{$column} AND m.meta_id=%d AND m.meta_key=%s AND BINARY m.meta_value=%s WHERE j.{$column}=%d AND j.meta_id IN (b.meta_id,m.meta_id) AND IS_USED_LOCK(%s)=CONNECTION_ID()";
		$changed = $wpdb->query( $wpdb->prepare( $sql, (int) $b[0]['id'], self::BACKUP_META, maybe_serialize( $backup ), (int) $m[0]['id'], self::MARKER_META, $job, $id, $this->writer_key ) );
		wp_cache_delete( $id, $this->target_type . '_meta' );
		if ( $changed !== 2 || $wpdb->last_error ) return false;
		foreach ( [ self::BACKUP_META=>$backup, self::MARKER_META=>$job ] as $key=>$value ) do_action( 'deleted_' . $this->target_type . '_meta', [ (int) ( self::BACKUP_META === $key ? $b[0]['id'] : $m[0]['id'] ) ], $id, $key, $value );
		return ! $this->meta_exists( $id, self::BACKUP_META ) && ! $this->meta_exists( $id, self::MARKER_META );
	}

	private function restore_backup( int $id, array $backup ): array {
		$conflict = [ 'ok'=>false, 'conflicts'=>1 ]; $failure = [ 'ok'=>false, 'conflicts'=>0 ];
		if ( ! $this->owns_writer() ) return $failure;
		// Old journals did not retain the imported value. Never guess away a newer edit.
		if ( ( $backup['schema'] ?? null ) !== 2 || ! is_array( $backup['values'] ?? null ) || ! $backup['values'] || count( $backup['values'] ) > 12 || ! is_array( $backup['imported'] ?? null ) || array_keys( $backup['values'] ) !== array_keys( $backup['imported'] ) ) return $conflict;
		global $wpdb;
		$table = 'term' === $this->target_type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $this->target_type ? 'term_id' : 'post_id'; $plans = [];
		foreach ( $backup['values'] as $key=>$entry ) {
			if ( ! in_array( $key, array_merge( self::TARGET_KEYS, [ Design_Context_Profile_Service::PAGE_VISIBILITY_META ] ), true ) || ! is_array( $entry ) || array_diff( array_keys( $entry ), [ 'exists', 'value' ] ) || ! is_bool( $entry['exists'] ?? null ) || ! array_key_exists( 'value', $entry ) || ! self::journal_value( $entry['value'] ) || ! self::journal_value( $backup['imported'][$key] ) ) return $conflict;
			$rows = $this->metadata_rows( $id, $key );
			if ( $wpdb->last_error || ! is_array( $rows ) ) return $failure;
			if ( count( $rows ) > 1 ) return $conflict;
			$before = maybe_serialize( $entry['value'] ); $imported = maybe_serialize( $backup['imported'][$key] );
			if ( $entry['exists'] ? count( $rows ) === 1 && $rows[0]['value'] === (string) $before : ! $rows ) continue;
			if ( count( $rows ) !== 1 || $rows[0]['value'] !== (string) $imported || ! ctype_digit( (string) $rows[0]['id'] ) || (int) $rows[0]['id'] < 1 ) return $conflict;
			$plans[] = [ 'id'=>(int) $rows[0]['id'], 'key'=>$key, 'entry'=>$entry, 'before'=>$before, 'imported'=>$imported ];
		}
		foreach ( $plans as $plan ) {
			$key = $plan['key']; $entry = $plan['entry']; $mid = $plan['id'];
			if ( $entry['exists'] ) {
				do_action( 'update_' . $this->target_type . '_meta', $mid, $id, $key, $entry['value'] );
				if ( ! $this->owns_writer() ) return $failure;
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET meta_value=%s WHERE meta_id=%d AND {$column}=%d AND meta_key=%s AND BINARY meta_value=%s LIMIT 1", $plan['before'], $mid, $id, $key, $plan['imported'] ) );
			} else {
				do_action( 'delete_' . $this->target_type . '_meta', [ $mid ], $id, $key, $backup['imported'][$key] );
				if ( ! $this->owns_writer() ) return $failure;
				$changed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_id=%d AND {$column}=%d AND meta_key=%s AND BINARY meta_value=%s LIMIT 1", $mid, $id, $key, $plan['imported'] ) );
			}
			wp_cache_delete( $id, $this->target_type . '_meta' );
			if ( false === $changed || $wpdb->last_error ) return $failure;
			if ( 1 !== (int) $changed ) return $conflict;
			do_action( ( $entry['exists'] ? 'updated_' : 'deleted_' ) . $this->target_type . '_meta', $entry['exists'] ? $mid : [ $mid ], $id, $key, $entry['exists'] ? $entry['value'] : $backup['imported'][$key] );
			if ( $entry['exists'] ? $this->meta_get( $id, $key, true ) !== $entry['value'] : $this->meta_exists( $id, $key ) ) return $conflict;
		}
		return [ 'ok'=>true, 'conflicts'=>0 ];
	}

	private static function journal_value( $value, int $depth = 0 ): bool {
		if ( is_string( $value ) ) return strlen( $value ) <= 8192 && ! str_contains( $value, "\0" ) && (bool) preg_match( '//u', $value );
		if ( is_array( $value ) ) { if ( $depth > 1 || count( $value ) > 20 ) return false; foreach ( $value as $item ) if ( ! self::journal_value( $item, $depth + 1 ) ) return false; return true; }
		return null === $value || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
	}

	private function mapped_values( \WP_Post|\WP_Term $post ): array {
		$id=(int)( $post instanceof \WP_Term ? $post->term_id : $post->ID ); $out=[];
		$text=[
			'rank_math_title'=>[ '_kiwe_seo_title',200 ], 'rank_math_description'=>[ '_kiwe_seo_description',320 ],
			'rank_math_facebook_title'=>[ '_kiwe_seo_facebook_title',200 ], 'rank_math_facebook_description'=>[ '_kiwe_seo_facebook_description',320 ],
			'rank_math_twitter_title'=>[ '_kiwe_seo_twitter_title',200 ], 'rank_math_twitter_description'=>[ '_kiwe_seo_twitter_description',320 ],
		];
		foreach ( $text as $source=>$target ) { $raw=$this->meta_get($id,$source,true); if ( ! is_string( $raw ) ) { ++$this->unsupported; continue; } $value=$this->rank_math_text($raw,$post,$target[1]); if ( ''!==$value ) $out[$target[0]]=$value; }
		$focus=$this->meta_get($id,'rank_math_focus_keyword',true); if ( ! is_string( $focus ) ) ++$this->unsupported; elseif ( ''!==trim($focus) ) { $phrases=[]; $parts=preg_split('/\s*,\s*/',$focus) ?: []; if ( count( $parts ) > 10 ) ++$this->unsupported; foreach ( array_slice( $parts,0,10 ) as $phrase ) { if ( mb_strlen( $phrase ) > 120 ) ++$this->unsupported; $phrase=$this->text($phrase,120); if($phrase!=='')$phrases[]=$phrase; } if($phrases)$out['_kiwe_search_phrases']=array_values(array_unique($phrases)); }
		$raw=$this->meta_get($id,'rank_math_robots',true);
		if ( '' !== $raw ) {
			$robots = is_array( $raw ) ? array_values( array_intersect( $raw, SEO_Defaults_Service::ROBOTS ) ) : [];
			if ( ! is_array( $raw ) || count( $robots ) !== count( $raw ) ) ++$this->unsupported;
			if ( false === SEO_Defaults_Service::validate_robots( $robots ) ) ++$this->unsupported;
			elseif ( $robots ) $out['_kiwe_seo_robots']=array_values(array_unique($robots));
		}
		foreach ( ['rank_math_canonical_url'=>'_kiwe_seo_canonical_url','rank_math_facebook_image'=>'_kiwe_seo_facebook_image','rank_math_twitter_image'=>'_kiwe_seo_twitter_image'] as $source=>$target ) {
			$raw=$this->meta_get($id,$source,true);
			if ( ! is_string( $raw ) ) { ++$this->unsupported; continue; }
			if ( '' === $raw ) continue;
			$url=SEO_Defaults_Service::url($raw); if($url)$out[$target]=$url; else ++$this->unsupported;
		}
		return $out;
	}

	private function rank_math_text( string $value, \WP_Post|\WP_Term $post, int $limit ): string {
		$value = trim( $value );
		if ( '' === $value ) return '';
		if ( mb_strlen( $value ) > $limit || ! SEO_Defaults_Service::valid_template( $value ) || str_contains( $value, '%page%' ) ) { ++$this->unsupported; return ''; }
		// Keep supported variables live: titles, dates and categories can change after import.
		return $value;
	}

	private function next_import_ids( array $job ): array {
		if ( 'term' === ( $job['scope'] ?? '' ) ) return $this->term_ids( $job );
		global $wpdb; $keys=$this->sql_keys(self::SOURCE_KEYS); $types=$this->sql_keys($this->post_types());
		$sql="SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.ID>%d AND p.ID<=%d AND p.post_type IN ({$types}) AND p.post_status IN ('publish','draft','pending','future','private') AND m.meta_key IN ({$keys}) ORDER BY p.ID ASC LIMIT %d";
		return array_map('absint',(array)$wpdb->get_col($wpdb->prepare($sql,absint($job['cursor']),absint($job['upper']),self::BATCH)));
	}

	private function next_rollback_ids( array $job ): array {
		if ( 'term' === ( $job['scope'] ?? '' ) ) return $this->term_ids( $job, true );
		global $wpdb;
		$sql="SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.ID>%d AND m.meta_key=%s AND m.meta_value=%s ORDER BY p.ID ASC LIMIT %d";
		return array_map('absint',(array)$wpdb->get_col($wpdb->prepare($sql,absint($job['cursor']),self::MARKER_META,(string)$job['sourceJob'],self::BATCH)));
	}

	private function source_stats( string $scope = 'post' ): array {
		if ( 'term' === $scope ) return $this->term_stats();
		global $wpdb; $keys=$this->sql_keys(self::SOURCE_KEYS); $types=$this->sql_keys($this->post_types());
		$sql="SELECT COUNT(DISTINCT p.ID) records,COUNT(m.meta_id) fields,COALESCE(MAX(p.ID),0) upper,COALESCE(MAX(m.meta_id),0) marker FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type IN ({$types}) AND p.post_status IN ('publish','draft','pending','future','private') AND m.meta_key IN ({$keys})";
		$row=(array)$wpdb->get_row($sql,ARRAY_A);
		$out=[ 'records'=>absint($row['records']??0),'fields'=>absint($row['fields']??0),'upper'=>absint($row['upper']??0),'marker'=>absint($row['marker']??0) ];
		$out['fingerprint']=hash('sha256',implode('|',$out)); return $out;
	}

	private function rollback_count( string $job, string $scope = 'post' ): int {
		if ( 'term' === $scope ) { global $wpdb; if ( ! isset( $wpdb->termmeta ) || ! preg_match( '/^[a-f0-9]{16}$/D', $job ) ) return 0; return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key=%s AND meta_value=%s", self::MARKER_META, $job ) ) ); }
		if(!preg_match('/^[a-f0-9]{16}$/D',$job))return 0; global $wpdb;
		return absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",self::MARKER_META,$job)));
	}

	private function finish( array $job ): void {
		$job['status']='complete'; $job['reason']=''; $job['updated']=time();
		$state=$this->state(); if(($state['active']['id']??'')!==$job['id'])return; $state['active']=null;
		$scope = $job['scope'] ?? 'post';
		if ( 'import' === $job['operation'] ) $state['lastImports'][$scope] = $job;
		elseif ( 0 === $this->rollback_count( (string) $job['sourceJob'], $scope ) ) unset( $state['lastImports'][$scope] );
		$imports = array_values( $state['lastImports'] ); usort( $imports, static fn( $a, $b )=>( $b['updated'] ?? 0 ) <=> ( $a['updated'] ?? 0 ) );
		$state['lastImport'] = 'import' === $job['operation'] ? $job : ( $imports[0] ?? null );
		array_unshift($state['history'],$job); $state['history']=array_slice($state['history'],0,self::HISTORY); $this->write_state($state);
	}

	private function stall( array $job, string $reason ): void {
		$state=$this->state(); if(($state['active']['id']??'')!==($job['id']??''))return; $job['status']='stalled'; $job['reason']=sanitize_key($reason); $job['updated']=time(); $state['active']=$job; $this->write_state($state);
	}

	private function public_job( array $job ): array {
		return array_intersect_key($job,array_flip(['id','operation','scope','unsupported','status','reason','cursor','upper','scanned','records','fields','skipped','conflicts','failed','created','updated']));
	}

	private function state(): array {
		$state=get_option(self::OPTION,[]); if(!is_array($state)||1!==($state['schema']??0))return ['schema'=>1,'active'=>null,'lastImport'=>null,'lastImports'=>[],'history'=>[]];
		$imports = [];
		foreach ( [ 'post', 'term' ] as $scope ) if ( is_array( $state['lastImports'][$scope] ?? null ) ) $imports[$scope] = $state['lastImports'][$scope];
		$legacy = $state['lastImport'] ?? null;
		if ( is_array( $legacy ) && in_array( $legacy['scope'] ?? 'post', [ 'post', 'term' ], true ) && ! isset( $imports[$legacy['scope'] ?? 'post'] ) ) $imports[$legacy['scope'] ?? 'post'] = $legacy;
		$state['lastImports'] = $imports;
		$state['history']=is_array($state['history']??null)?array_slice($state['history'],0,self::HISTORY):[]; return $state;
	}

	private function write_state( array $state ): bool {
		if ( ! $this->owns_writer() ) return false;
		$state['schema']=1; $state['history']=array_slice(is_array($state['history']??null)?$state['history']:[],0,self::HISTORY); update_option(self::OPTION,$state,false); return get_option(self::OPTION,[])===$state;
	}


	private function meta_get( int $id, string $key, bool $single = true ) { return 'term' === $this->target_type ? get_term_meta( $id, $key, $single ) : get_post_meta( $id, $key, $single ); }
	private function meta_add( int $id, string $key, $value ) { if ( ! $this->owns_writer() ) return false; return 'term' === $this->target_type ? add_term_meta( $id, $key, wp_slash( $value ), true ) : add_post_meta( $id, $key, wp_slash( $value ), true ); }
	private function meta_exists( int $id, string $key ): bool { return metadata_exists( $this->target_type, $id, $key ); }
	private function clean_target_cache( int $id ): void { if ( 'term' === $this->target_type ) clean_term_cache( $id ); else clean_post_cache( $id ); }
	private function term_stats(): array {
		global $wpdb;
		if ( ! isset( $wpdb->termmeta, $wpdb->terms, $wpdb->term_taxonomy ) ) { $row = []; }
		else { $keys = $this->sql_keys( self::SOURCE_KEYS ); $tax = $this->sql_keys( array_keys( get_taxonomies( [ 'public'=>true ] ) ) );
			$row = (array) $wpdb->get_row( "SELECT COUNT(DISTINCT t.term_id) records,COUNT(m.meta_id) fields,COALESCE(MAX(t.term_id),0) upper,COALESCE(MAX(m.meta_id),0) marker FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id INNER JOIN {$wpdb->termmeta} m ON m.term_id=t.term_id WHERE tt.taxonomy IN ({$tax}) AND m.meta_key IN ({$keys})", ARRAY_A ); }
		$out = [ 'records'=>absint( $row['records'] ?? 0 ), 'fields'=>absint( $row['fields'] ?? 0 ), 'upper'=>absint( $row['upper'] ?? 0 ), 'marker'=>absint( $row['marker'] ?? 0 ) ]; $out['fingerprint'] = hash( 'sha256', implode( '|', $out ) ); return $out;
	}
	private function term_ids( array $job, bool $rollback = false ): array {
		global $wpdb;
		if ( $rollback ) return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE term_id>%d AND meta_key=%s AND meta_value=%s ORDER BY term_id ASC LIMIT %d", absint( $job['cursor'] ), self::MARKER_META, (string) $job['sourceJob'], self::BATCH ) ) );
		$keys = $this->sql_keys( self::SOURCE_KEYS ); $tax = $this->sql_keys( array_keys( get_taxonomies( [ 'public'=>true ] ) ) );
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id INNER JOIN {$wpdb->termmeta} m ON m.term_id=t.term_id WHERE t.term_id>%d AND t.term_id<=%d AND tt.taxonomy IN ({$tax}) AND m.meta_key IN ({$keys}) ORDER BY t.term_id ASC LIMIT %d", absint( $job['cursor'] ), absint( $job['upper'] ), self::BATCH ) ) );
	}

	private function sql_keys( array $keys ): string { return "'".implode("','",array_map('esc_sql',$keys))."'"; }
	private function post_types(): array { return array_values( array_diff( get_post_types( [ 'public'=>true ] ), [ 'attachment' ] ) ); }
	private function empty_value( $value ): bool { return ''===$value||null===$value||[]===$value; }
	private function text( $value, int $limit ): string { $value=sanitize_text_field((string)$value); return function_exists('mb_substr')?mb_substr($value,0,$limit):substr($value,0,$limit); }
}
