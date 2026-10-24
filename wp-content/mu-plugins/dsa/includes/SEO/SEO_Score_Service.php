<?php
namespace DSA\SEO;

use DSA\Onboarding\Design_Context_Profile_Service as Profile;
use DSA\Access\Task_Workspace_UI as UI;
use DSA\Onboarding\Search_Report_Snapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Read-only native checklist worker. Progress and its completed snapshot publish in one CAS. */
final class SEO_Score_Service {
	public const OPTION = Search_Report_Snapshot::OPTION;
	public const CRON = 'kiwe_refresh_seo_checklist_v2';
	private const BYTES = Search_Report_Snapshot::BYTES;
	private bool $marked = false;

	public function register(): void {
		add_action( self::CRON, [ $this, 'step' ] );
		add_action( 'kiwe_refresh_overall_seo_score', [ $this, 'request' ] ); // Old events enqueue, never scan.
		add_action( 'kiwe_seo_score_dirty', [ $this, 'request' ] );
		add_action( 'save_post', [ $this, 'post_changed' ], 30, 2 );
		add_action( 'deleted_post', [ $this, 'post_changed' ], 30, 2 );
		foreach ( [ 'added_post_meta','updated_post_meta','deleted_post_meta' ] as $hook ) add_action( $hook, [ $this, 'meta_changed' ], 30, 4 );
		foreach ( [ 'added_option','updated_option','deleted_option' ] as $hook ) add_action( $hook, [ $this, 'option_changed' ], 30, 3 );
		add_action( 'admin_init', [ $this, 'ensure' ] );
		add_action( 'admin_post_kiwe_update_seo_checklist', [ $this, 'handle' ] );
	}
	public function post_changed( int $id, $post = null ): void {
		$type = is_object( $post ) ? $post->post_type : get_post_type( $id );
		// Public parent changes also affect attached media, including custom post types.
		if ( $type && ! in_array( $type, [ 'revision','nav_menu_item' ], true ) ) $this->request();
	}
	public function meta_changed( $meta_id, int $id, string $key, $value = null ): void {
		if ( in_array( $key, [ '_kiwe_seo_title','_kiwe_seo_description','_kiwe_search_phrases','_wp_attachment_image_alt','_kiwe_seo_intentional_empty_fields' ], true ) ) $this->request();
	}
	public function option_changed( string $name, $old = null, $value = null ): void {
		if ( in_array( $name, [ Profile::OPTION_PROFILE,'blogname','blogdescription','site_icon','kiwe_site_logo_id','kiwe_store_email','kiwe_store_phone','active_plugins' ], true ) || str_starts_with( $name, 'theme_mods_' ) ) $this->request();
	}
	public function ensure(): void {
		if ( ! current_user_can( 'kiwe_manage_context' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) return;
		$read = $this->read();
		if ( is_wp_error( $read ) ) return;
		if ( ! $read['state'] || ( ! $read['state']['job'] && ! self::pending( $read['state'] ) && time() - $read['state']['updatedAt'] > DAY_IN_SECONDS ) ) $this->request();
		else $this->schedule( $read['state'] );
	}
	public function request() {
		if ( $this->marked ) return true;
		$token = bin2hex( random_bytes( 16 ) );
		$state = $this->change( static function( array $state ) use ( $token ) {
			$state = $state ?: [ 'schema'=>1,'requested'=>'','completed'=>'','job'=>null,'report'=>null,'lease'=>null,'error'=>'','nextAt'=>0,'updatedAt'=>time() ];
			$state['requested'] = $token;
			return $state;
		} );
		if ( is_wp_error( $state ) ) return $state;
		$this->marked = true;
		$this->schedule( $state );
		return true;
	}
	/** Public/UI reads are cacheable and never enqueue work or load a catalogue. */
	public static function snapshot(): array { return Search_Report_Snapshot::read(); }
	private static function pending( array $state ): bool { return $state['requested'] !== $state['completed']; }
	public function step(): void {
		$token = bin2hex( random_bytes( 16 ) );
		$state = $this->change( static function( array $state ) use ( $token ) {
			if ( ! $state || ! self::pending( $state ) || $state['error'] || $state['nextAt'] > time() || ( $state['lease'] && $state['lease']['until'] > time() ) ) return new \WP_Error( 'score_wait', 'The checklist is not ready for another step.' );
			$state['lease'] = [ 'token'=>$token,'until'=>time() + 60 ];
			return $state;
		} );
		if ( is_wp_error( $state ) ) { $read = $this->read(); if ( ! is_wp_error( $read ) && $read['state'] ) $this->schedule( $read['state'] ); return; }
		// This work is read-only and can replay safely after a crash; retain a lease-expiry event.
		$this->schedule( $state );
		try {
			$source = new SEO_Score_Source();
			$job = $state['job'];
			if ( ! $job ) {
				$upper = $source->upper();
				if ( is_wp_error( $upper ) ) throw new \RuntimeException( 'read' );
				$evidence = ( new Profile() )->seo_evidence_component();
				if ( is_wp_error( $evidence ) ) throw new \RuntimeException( 'evidence' );
				$job = [ 'request'=>$state['requested'],'upper'=>$upper,'cursor'=>0,'inspected'=>0,'startedAt'=>time(),'commerce'=>function_exists( 'WC' ),'onboarding'=>$evidence,'counts'=>[] ];
				foreach ( [ 'pages','posts','products','media' ] as $scope ) $job['counts'][ $scope ] = [ 'points'=>0,'ready'=>0,'total'=>0 ];
			}
			$rows = $source->page( $job['cursor'], $job['upper'] );
			if ( is_wp_error( $rows ) ) throw new \RuntimeException( 'read' );
			foreach ( $rows as $row ) {
				$item = SEO_Score_Source::points( $row, $job['commerce'] );
				if ( ! $item ) continue;
				$counts =& $job['counts'][ $item['scope'] ];
				$counts['points'] += $item['points']; ++$counts['total'];
				if ( $item['points'] >= 80 ) ++$counts['ready'];
				unset( $counts );
			}
			$job['cursor'] = $rows ? (int) end( $rows )['ID'] : $job['upper'];
			$job['inspected'] += count( $rows );
			$done = count( $rows ) < SEO_Score_Source::PAGE_SIZE || $job['cursor'] >= $job['upper'];
			$saved = $this->change( static function( array $latest ) use ( $token, $job, $done ) {
				if ( ! self::owns( $latest, $token ) ) return new \WP_Error( 'score_lease', 'The checklist step lost its lease.' );
				$latest['lease'] = null;
				$latest['job'] = $done ? null : $job;
				$latest['nextAt'] = time() + 15;
				if ( $done ) { $latest['report'] = self::report( $job ); $latest['completed'] = $job['request']; }
				// A concurrent content change keeps requested different from completed.
				return $latest;
			} );
			if ( ! is_wp_error( $saved ) ) $this->schedule( $saved );
		} catch ( \Throwable $error ) {
			$this->change( static function( array $latest ) use ( $token ) {
				if ( ! self::owns( $latest, $token ) ) return new \WP_Error( 'score_lease', 'The checklist step lost its lease.' );
				$latest['lease'] = null; $latest['error'] = 'The checklist update paused. The last completed snapshot is preserved. Retry after checking website health.';
				return $latest;
			} );
		}
	}
	private static function report( array $job ): array {
		$components = [ 'onboarding'=>$job['onboarding'] ];
		foreach ( [ 'pages'=>20,'posts'=>15,'products'=>20,'media'=>25 ] as $scope=>$weight ) {
			$c = $job['counts'][ $scope ];
			$components[ $scope ] = [ 'label'=>ucfirst( $scope ),'score'=>$c['total'] ? (int) round( $c['points'] / $c['total'] ) : 0,'ready'=>$c['ready'],'total'=>$c['total'],'weight'=>$weight,'active'=>$c['total'] > 0 ];
		}
		$points = 0; $weights = 0;
		foreach ( $components as $c ) if ( $c['active'] ) { $points += $c['score'] * $c['weight']; $weights += $c['weight']; }
		return [ 'schema'=>'kiwe.overall-seo-score.v1','method'=>'native-metadata-checklist.v2','score'=>$weights ? (int) round( $points / $weights ) : 0,'components'=>$components,'calculatedAt'=>gmdate( 'c' ),'scanStartedAt'=>gmdate( 'c', $job['startedAt'] ),'inspected'=>$job['inspected'],'authority'=>[ 'emptyScopesRedistributed'=>true,'unsafeSuggestionsNeverCountAsComplete'=>true,'rankingPrediction'=>false,'atomicContentSnapshot'=>false ] ];
	}
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You cannot update this checklist.', 'dsa' ), '', [ 'response'=>403 ] );
		check_admin_referer( 'kiwe_update_seo_checklist' );
		$read = $this->read();
		if ( is_wp_error( $read ) ) { UI::error( $read ); return; }
		$result = ! $read['state'] || ! self::pending( $read['state'] ) ? $this->request() : true;
		if ( is_wp_error( $result ) ) { UI::error( $result ); return; }
		$result = $this->change( static function( array $state ) { $state['error'] = ''; return $state; } );
		if ( is_wp_error( $result ) ) { UI::error( $result ); return; }
		$this->step();
		UI::finish( admin_url( 'admin.php?page=kiwe-seo' ), __( 'The checklist update was checked. See its progress or any pause below.', 'dsa' ) );
	}
	public static function render(): void {
		$snapshot = self::snapshot(); $report = $snapshot['report'];
		echo '<section class="kiwe-tasks__card"><h2>' . esc_html__( 'SEO checklist', 'dsa' ) . '</h2>';
		if ( $report ) echo '<p>' . esc_html( sprintf( __( 'Last completed checklist: %1$d%% · %2$s. This measures recorded fields, not search rankings.', 'dsa' ), $report['score'], $report['calculatedAt'] ?? '' ) ) . '</p>';
		else echo '<p>' . esc_html__( 'A completed checklist is not available yet.', 'dsa' ) . '</p>';
		if ( ! empty( $snapshot['legacy'] ) ) echo '<p>' . esc_html__( 'This snapshot uses the earlier checklist method. A new update will replace it.', 'dsa' ) . '</p>';
		if ( $snapshot['pending'] ) echo '<p>' . esc_html( sprintf( __( 'Update pending · %d records inspected in this scan. Small steps run through WordPress cron.', 'dsa' ), $snapshot['inspected'] ) ) . '</p>';
		if ( $snapshot['error'] ) echo '<p role="status">' . esc_html( $snapshot['error'] ) . '</p>';
		UI::form( 'kiwe_update_seo_checklist', 'kiwe_update_seo_checklist', [] );
		echo '<button class="button">' . esc_html( $snapshot['pending'] ? __( 'Continue checklist update', 'dsa' ) : __( 'Update checklist', 'dsa' ) ) . '</button></form></section>';
	}
	private function schedule( array $state ): void {
		if ( ! self::pending( $state ) || $state['error'] ) return;
		$when = max( time() + 1, $state['nextAt'], $state['lease']['until'] ?? 0 );
		$existing = wp_next_scheduled( self::CRON );
		if ( $existing && $existing <= $when ) return;
		if ( $existing ) wp_clear_scheduled_hook( self::CRON );
		wp_schedule_single_event( $when, self::CRON );
	}
	private static function owns( array $state, string $token ): bool { return $state && $state['lease'] && hash_equals( $state['lease']['token'], $token ) && $state['lease']['until'] > time(); }
	private function read() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT CASE WHEN OCTET_LENGTH(option_value)<=%d THEN option_value ELSE '' END FROM {$wpdb->options} WHERE option_name=%s", self::BYTES, self::OPTION ) );
		if ( $wpdb->last_error ) return new \WP_Error( 'score_storage', 'Checklist storage is unavailable.' );
		$state = self::decode( $raw );
		if ( null !== $raw && ! $state ) return new \WP_Error( 'score_state', 'Preserve the checklist state for your website manager to inspect.' );
		if ( $state && $state['updatedAt'] > time() ) return new \WP_Error( 'score_clock', 'The website clock moved backwards. Checklist work is paused.' );
		return [ 'raw'=>$raw,'state'=>$state ];
	}
	private function change( callable $callback ) {
		global $wpdb;
		for ( $i = 0; $i < 5; ++$i ) {
			$read = $this->read(); if ( is_wp_error( $read ) ) return $read;
			$next = $callback( $read['state'] ); if ( is_wp_error( $next ) ) return $next;
			$next['updatedAt'] = time(); $raw = wp_json_encode( $next );
			if ( ! self::decode( $raw ) ) return new \WP_Error( 'score_state', 'The checklist result could not be safely stored.' );
			if ( $raw === $read['raw'] ) return $next;
			$sql = null === $read['raw'] ? $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::OPTION, $raw ) : $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $raw, self::OPTION, $read['raw'] );
			$result = $wpdb->query( $sql );
			if ( false === $result ) return new \WP_Error( 'score_storage', 'The checklist update could not be saved.' );
			if ( 1 === $result ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); return $next; }
		}
		return new \WP_Error( 'score_busy', 'The checklist changed. Try again shortly.' );
	}
	private static function decode( $raw ): array { return Search_Report_Snapshot::decode( $raw ); }
	private static function component( $c ): bool { return Search_Report_Snapshot::component( $c ); }
}
