<?php

namespace DSA\SEO;

use DSA\AI\AI_Broker_Service;
use DSA\Onboarding\Design_Context_Enhancement_Service;
use DSA\Onboarding\Design_Context_Profile_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Review-first, shared-host-safe editorial SEO batches. */
final class SEO_Refinement_Service {
	private const JOB_OPTION = 'kiwe_seo_refinement_job_v1';
	private const SCHEMA = 'kiwe.seo-refinement-batch.v1';
	private const CRON = 'kiwe_seo_refinement_batch';
	private const BATCH_SIZE = 5;

	public function __construct( private AI_Broker_Service $broker, private Design_Context_Profile_Service $profiles ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 22 );
		add_action( 'admin_post_kiwe_start_seo_refinement', [ $this, 'handle_start' ] );
		add_action( 'admin_post_kiwe_run_seo_refinement', [ $this, 'handle_run' ] );
		add_action( 'admin_post_kiwe_review_seo_refinement', [ $this, 'handle_review' ] );
		add_action( self::CRON, [ $this, 'run_batch' ], 10, 1 );
		add_filter( 'document_title_parts', [ $this, 'document_title' ] );
	}

	public function menu(): void {
		add_submenu_page( 'kiwe', __( 'Kiwe SEO', 'dsa' ), __( 'SEO', 'dsa' ), 'manage_options', 'kiwe-seo', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage SEO proposals.', 'dsa' ) );
		$job = $this->job();
		$ai = $this->broker->status( 'seo' );
		$notice = sanitize_key( (string) ( $_GET['seo-refinement'] ?? '' ) );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Kiwe SEO', 'dsa' ); ?></h1><p><?php esc_html_e( 'Create bounded AI proposals for native WordPress and WooCommerce content. Kiwe never promises rankings, emits obsolete meta-keywords, changes URLs or filenames, or publishes AI text without administrator acceptance.', 'dsa' ); ?></p>
		<?php if ( $notice ) : ?><div class="notice notice-info"><p><?php echo esc_html( $this->notice( $notice ) ); ?></p></div><?php endif; ?>
		<?php if ( empty( $ai['profile']['enabled'] ) ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'Enable the shared Kiwe AI provider for SiteGraph/SEO first. SEO uses the same broker and credentials; it has no separate AI configuration.', 'dsa' ); ?></p></div><?php endif; ?>
		<div class="card" style="max-width:none"><h2><?php esc_html_e( 'Start a review batch', 'dsa' ); ?></h2><p><?php esc_html_e( 'Each background request contains at most five public records. Existing dedicated SEO plugins retain frontend authority.', 'dsa' ); ?></p><div style="display:flex;gap:10px;flex-wrap:wrap"><?php foreach ( $this->scopes() as $scope=>$label ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_start_seo_refinement"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>"><?php wp_nonce_field( 'kiwe_start_seo_refinement' ); ?><button class="button button-primary" type="submit" <?php disabled( empty( $ai['profile']['enabled'] ) ); ?>><?php echo esc_html( $label ); ?></button></form><?php endforeach; ?></div></div>
		<?php if ( $job ) : $total=count( (array) $job['ids'] ); $cursor=absint( $job['cursor'] ?? 0 ); ?>
		<div class="card" style="max-width:none"><h2><?php echo esc_html( sprintf( __( '%1$s · %2$d of %3$d inspected', 'dsa' ), $this->scopes()[ $job['scope'] ] ?? $job['scope'], min( $cursor, $total ), $total ) ); ?></h2><p><?php echo esc_html( sprintf( __( 'Status: %1$s · %2$d proposals · %3$d contract errors', 'dsa' ), (string) $job['status'], count( (array) $job['proposals'] ), absint( $job['errors'] ?? 0 ) ) ); ?></p><?php if ( 'complete' !== $job['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_run_seo_refinement"><?php wp_nonce_field( 'kiwe_run_seo_refinement' ); ?><button class="button" type="submit"><?php esc_html_e( 'Run next five now', 'dsa' ); ?></button></form><?php endif; ?></div>
		<?php if ( ! empty( $job['proposals'] ) ) : ?><h2><?php esc_html_e( 'Review proposals', 'dsa' ); ?></h2><div style="display:grid;gap:14px"><?php foreach ( (array) $job['proposals'] as $id=>$proposal ) : ?><article class="card" style="max-width:none"><h3><?php echo esc_html( (string) $proposal['label'] ); ?> <small>· <?php echo esc_html( ucfirst( (string) $proposal['status'] ) ); ?></small></h3><table class="widefat striped"><tbody><?php foreach ( (array) $proposal['fields'] as $field=>$value ) : ?><tr><th style="width:180px"><?php echo esc_html( $this->field_label( (string) $field ) ); ?></th><td><?php echo nl2br( esc_html( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) ); ?></td></tr><?php endforeach; ?></tbody></table><p><?php echo esc_html( (string) ( $proposal['reason'] ?? '' ) ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_review_seo_refinement"><input type="hidden" name="proposal_id" value="<?php echo esc_attr( (string) $id ); ?>"><?php wp_nonce_field( 'kiwe_review_seo_refinement' ); ?><button class="button button-primary" name="decision" value="accept" type="submit"><?php esc_html_e( 'Accept proposal', 'dsa' ); ?></button> <button class="button-link-delete" name="decision" value="reject" type="submit"><?php esc_html_e( 'Reject', 'dsa' ); ?></button></form></article><?php endforeach; ?></div><?php endif; ?>
		<?php endif; ?></div>
		<?php
	}

	public function handle_start(): void {
		$this->authorize( 'kiwe_start_seo_refinement' );
		$scope = sanitize_key( (string) ( $_POST['scope'] ?? '' ) );
		if ( ! isset( $this->scopes()[ $scope ] ) ) $this->redirect( 'invalid' );
		$ids = $this->scope_ids( $scope );
		$job = [ 'schema'=>self::SCHEMA, 'id'=>wp_generate_uuid4(), 'scope'=>$scope, 'ids'=>$ids, 'cursor'=>0, 'status'=>$ids ? 'queued' : 'complete', 'proposals'=>[], 'errors'=>0, 'createdAt'=>gmdate( 'c' ), 'createdBy'=>get_current_user_id() ];
		update_option( self::JOB_OPTION, $job, false );
		if ( $ids ) wp_schedule_single_event( time() + 1, self::CRON, [ $job['id'] ] );
		$this->redirect( $ids ? 'queued' : 'empty' );
	}

	public function handle_run(): void {
		$this->authorize( 'kiwe_run_seo_refinement' );
		$job = $this->job();
		if ( $job ) $this->run_batch( (string) $job['id'] );
		$this->redirect( 'processed' );
	}

	public function run_batch( string $job_id ): void {
		$job = $this->job();
		if ( ! $job || ! hash_equals( (string) $job['id'], $job_id ) || 'complete' === ( $job['status'] ?? '' ) ) return;
		$lock = 'kiwe_seo_refinement_lock_' . sanitize_key( $job_id );
		if ( get_transient( $lock ) ) return;
		set_transient( $lock, '1', 2 * MINUTE_IN_SECONDS );
		try {
		$ids = array_slice( (array) $job['ids'], absint( $job['cursor'] ), self::BATCH_SIZE );
		$records = [];
		foreach ( $ids as $id ) { $record=$this->record( absint( $id ), (string) $job['scope'] ); if ( $record ) $records[]=$record; }
		if ( $records ) {
			$result = $this->broker->request( [
				'service'=>'seo', 'capability'=>'propose_batch', 'operation'=>'seo_' . sanitize_key( (string) $job['scope'] ),
				'system'=>'Return only contract JSON. Improve discoverability and reader clarity without keyword stuffing. Use siteContext only as verified editorial context for audience, business goal, brand voice, search intent and proof points; do not force every context fact into every record. Prefer a natural SEO title under 60 characters and a useful meta description around 120-160 characters. Preserve verified meaning and product facts. Do not invent claims, locations, credentials, ingredients, benefits, prices, availability or guarantees. For media, leave alt empty when the supplied filename, metadata and parent context do not prove what the media depicts. Search phrases are internal planning phrases and must never be emitted as meta keywords. Do not change URLs or filenames.',
				'user'=>(string) wp_json_encode( [ 'schema'=>self::SCHEMA, 'scope'=>$job['scope'], 'siteContext'=>$this->site_context(), 'records'=>$records, 'output'=>[ 'schema'=>self::SCHEMA, 'proposals'=>[ [ 'id'=>0, 'fields'=>'all_media' === $job['scope'] ? [ 'title'=>'','alt'=>'','caption'=>'','description'=>'' ] : [ 'seoTitle'=>'','metaDescription'=>'','excerpt'=>'','searchPhrases'=>[] ], 'reason'=>'' ] ] ] ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			] );
			$data = is_array( $result['validation']['data'] ?? null ) ? $result['validation']['data'] : [];
			if ( ! empty( $result['ok'] ) && self::SCHEMA === ( $data['schema'] ?? '' ) ) $job['proposals'] = array_replace( (array) $job['proposals'], $this->sanitize_proposals( (array) ( $data['proposals'] ?? [] ), $records, (string) $job['scope'] ) );
			else {
				$job['errors'] = absint( $job['errors'] ?? 0 ) + 1;
				$job['status'] = 'paused';
				$job['lastErrorAt'] = gmdate( 'c' );
				update_option( self::JOB_OPTION, $job, false );
				return;
			}
		}
		$job['cursor'] = min( count( (array) $job['ids'] ), absint( $job['cursor'] ) + count( $ids ) );
		$job['status'] = $job['cursor'] >= count( (array) $job['ids'] ) ? 'complete' : 'queued';
		$job['updatedAt'] = gmdate( 'c' );
		update_option( self::JOB_OPTION, $job, false );
		if ( 'complete' !== $job['status'] ) wp_schedule_single_event( time() + 20, self::CRON, [ $job['id'] ] );
		} finally {
			delete_transient( $lock );
		}
	}

	public function handle_review(): void {
		$this->authorize( 'kiwe_review_seo_refinement' );
		$id = sanitize_key( (string) ( $_POST['proposal_id'] ?? '' ) );
		$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
		$job = $this->job(); $proposal = $job['proposals'][ $id ] ?? null;
		if ( ! is_array( $proposal ) || ! in_array( $decision, [ 'accept','reject' ], true ) ) $this->redirect( 'invalid' );
		if ( 'accept' === $decision && ! $this->apply( $proposal ) ) $this->redirect( 'stale' );
		$job = $this->job();
		$job['proposals'][ $id ]['status'] = 'accept' === $decision ? 'accepted' : 'rejected';
		$job['proposals'][ $id ]['reviewedAt'] = gmdate( 'c' );
		$job['proposals'][ $id ]['reviewedBy'] = get_current_user_id();
		update_option( self::JOB_OPTION, $job, false );
		$this->redirect( 'accept' === $decision ? 'accepted' : 'rejected' );
	}

	public function document_title( array $parts ): array {
		if ( ! is_singular() || $this->dedicated_seo_plugin_active() ) return $parts;
		$title = trim( (string) get_post_meta( get_queried_object_id(), '_kiwe_seo_title', true ) );
		if ( $title ) $parts['title'] = $title;
		return $parts;
	}

	public static function singular_description(): string {
		if ( ! is_singular() ) return '';
		return trim( (string) get_post_meta( get_queried_object_id(), '_kiwe_seo_description', true ) );
	}

	private function sanitize_proposals( array $input, array $records, string $scope ): array {
		$known=[]; foreach ( $records as $record ) $known[ (int) $record['id'] ]=$record;
		$out=[];
		foreach ( $input as $proposal ) {
			if ( ! is_array( $proposal ) ) continue; $id=absint( $proposal['id'] ?? 0 ); if ( ! isset( $known[ $id ] ) ) continue;
			$fields=is_array( $proposal['fields'] ?? null ) ? $proposal['fields'] : [];
			$clean = str_starts_with( $scope, 'all_media' ) ? [
				'title'=>substr( sanitize_text_field( (string) ( $fields['title'] ?? '' ) ),0,200 ), 'alt'=>substr( sanitize_text_field( (string) ( $fields['alt'] ?? '' ) ),0,300 ),
				'caption'=>substr( sanitize_textarea_field( (string) ( $fields['caption'] ?? '' ) ),0,1000 ), 'description'=>substr( sanitize_textarea_field( (string) ( $fields['description'] ?? '' ) ),0,3000 ),
			] : [
				'seoTitle'=>substr( sanitize_text_field( (string) ( $fields['seoTitle'] ?? '' ) ),0,200 ), 'metaDescription'=>substr( sanitize_textarea_field( (string) ( $fields['metaDescription'] ?? '' ) ),0,320 ),
				'excerpt'=>substr( sanitize_textarea_field( (string) ( $fields['excerpt'] ?? '' ) ),0,1000 ), 'searchPhrases'=>array_values( array_unique( array_filter( array_map( static fn( $v ): string=>substr( sanitize_text_field( (string) $v ),0,120 ), array_slice( is_array( $fields['searchPhrases'] ?? null ) ? $fields['searchPhrases'] : [],0,10 ) ) ) ) ),
			];
			$clean=array_filter( $clean, static fn( $v ): bool=>[] !== $v && '' !== $v ); if ( ! $clean ) continue;
			$key=substr( hash( 'sha256', $scope . '|' . $id ),0,16 );
			$out[ $key ]=[ 'objectId'=>$id, 'scope'=>$scope, 'label'=>(string) $known[$id]['label'], 'originalHash'=>(string) $known[$id]['sourceHash'], 'fields'=>$clean, 'reason'=>substr( sanitize_text_field( (string) ( $proposal['reason'] ?? '' ) ),0,300 ), 'status'=>'pending' ];
		}
		return $out;
	}

	private function apply( array $proposal ): bool {
		$id=absint( $proposal['objectId'] ?? 0 ); $scope=(string) ( $proposal['scope'] ?? '' ); $record=$this->record( $id, $scope );
		if ( ! $record || ! hash_equals( (string) $proposal['originalHash'], (string) $record['sourceHash'] ) ) return false;
		$fields=(array) $proposal['fields'];
		if ( str_starts_with( $scope, 'all_media' ) ) {
			$update=[ 'ID'=>$id ]; if ( isset( $fields['title'] ) ) $update['post_title']=$fields['title']; if ( isset( $fields['caption'] ) ) $update['post_excerpt']=$fields['caption']; if ( isset( $fields['description'] ) ) $update['post_content']=$fields['description'];
			if ( count( $update ) > 1 ) wp_update_post( wp_slash( $update ) ); if ( isset( $fields['alt'] ) && wp_attachment_is_image( $id ) ) update_post_meta( $id, '_wp_attachment_image_alt', $fields['alt'] );
		} else {
			if ( isset( $fields['seoTitle'] ) ) update_post_meta( $id, '_kiwe_seo_title', $fields['seoTitle'] ); if ( isset( $fields['metaDescription'] ) ) update_post_meta( $id, '_kiwe_seo_description', $fields['metaDescription'] ); if ( isset( $fields['searchPhrases'] ) ) update_post_meta( $id, '_kiwe_search_phrases', $fields['searchPhrases'] );
			if ( isset( $fields['excerpt'] ) ) wp_update_post( wp_slash( [ 'ID'=>$id, 'post_excerpt'=>$fields['excerpt'] ] ) );
		}
		return true;
	}

	private function record( int $id, string $scope ): array {
		$post=get_post( $id ); if ( ! $post ) return [];
		if ( str_starts_with( $scope, 'all_media' ) ) {
			if ( 'attachment' !== $post->post_type ) return [];
			$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
			$data=[ 'id'=>$id, 'label'=>$post->post_title ?: basename( (string) get_attached_file( $id ) ), 'mimeType'=>get_post_mime_type( $id ), 'title'=>$post->post_title, 'alt'=>get_post_meta( $id, '_wp_attachment_image_alt', true ), 'caption'=>$post->post_excerpt, 'description'=>$post->post_content, 'filename'=>basename( (string) get_attached_file( $id ) ), 'url'=>wp_get_attachment_url( $id ), 'parentContext'=>$parent ? [ 'type'=>$parent->post_type, 'title'=>$parent->post_title, 'excerpt'=>$parent->post_excerpt ] : null ];
		} else {
			$expected=[ 'all_posts'=>'post','all_pages'=>'page','all_products'=>'product' ][ $scope ] ?? ''; if ( $post->post_type !== $expected || 'publish' !== $post->post_status ) return [];
			$data=[ 'id'=>$id, 'label'=>$post->post_title, 'postType'=>$post->post_type, 'title'=>$post->post_title, 'excerpt'=>$post->post_excerpt, 'content'=>substr( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ),0,4000 ), 'currentSeoTitle'=>get_post_meta( $id, '_kiwe_seo_title', true ), 'currentMetaDescription'=>get_post_meta( $id, '_kiwe_seo_description', true ) ];
		}
		$data['sourceHash']=hash( 'sha256', (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); return $data;
	}

	/**
	 * Provide the model with owner-approved editorial evidence without sending
	 * operational addresses, contact details, media-library resources or admin
	 * identities. SEO remains record-scoped and review-first.
	 */
	private function site_context(): array {
		$context = ( new Design_Context_Enhancement_Service( $this->profiles ) )->resolved_public_context( false );
		$about = is_array( $context['about'] ?? null ) ? $context['about'] : [];
		$team = is_array( $about['team'] ?? null ) ? $about['team'] : [];
		$services = is_array( $context['services']['items'] ?? null ) ? $context['services']['items'] : [];

		return [
			'schema' => 'kiwe.seo-site-context.v1',
			'identity' => array_intersect_key( (array) ( $context['identity'] ?? [] ), array_flip( [ 'siteName','tagline','description','industry','industrySector','siteType' ] ) ),
			'audience' => array_intersect_key( (array) ( $context['audience'] ?? [] ), array_flip( [ 'primary','locations','needs' ] ) ),
			'about' => [
				'story' => (string) ( $about['story'] ?? '' ),
				'mission' => (string) ( $about['mission'] ?? '' ),
				'vision' => (string) ( $about['vision'] ?? '' ),
				'values' => (string) ( $about['values'] ?? '' ),
				'usp' => (string) ( $about['usp'] ?? '' ),
				'founderLed' => ! empty( $about['founder']['enabled'] ),
				'teamEnabled' => ! empty( $team['enabled'] ),
			],
			'seo' => array_intersect_key( (array) ( $context['seo'] ?? [] ), array_flip( [ 'homepageDescription','legalName','foundedYear','primaryGoal','searchIntent','proofPoints' ] ) ),
			'contentSignals' => array_intersect_key( (array) ( $context['contentPlan'] ?? [] ), array_flip( [ 'showBlogRailOnHome','highlightBestsellers' ] ) ),
			'commerce' => array_intersect_key( (array) ( $context['commercePlan'] ?? [] ), array_flip( [ 'enabled','hasBundles','currency','sellingLocations' ] ) ),
			'services' => array_values( array_slice( array_map( static fn( $service ): array => array_intersect_key( is_array( $service ) ? $service : [], array_flip( [ 'title','summary','categoryPaths' ] ) ), $services ), 0, 30 ) ),
			'authority' => [ 'source'=>'resolved-owner-design-context', 'ownerEvidenceWins'=>true, 'mayInventFacts'=>false ],
		];
	}

	private function scope_ids( string $scope ): array {
		$type=[ 'all_posts'=>'post','all_pages'=>'page','all_products'=>'product','all_media'=>'attachment' ][ $scope ] ?? '';
		if ( ! $type || ! post_type_exists( $type ) ) return [];
		return array_map( 'absint', get_posts( [ 'post_type'=>$type, 'post_status'=>'attachment' === $type ? 'inherit' : 'publish', 'posts_per_page'=>1000, 'orderby'=>'ID', 'order'=>'ASC', 'fields'=>'ids', 'no_found_rows'=>true ] ) );
	}

	private function scopes(): array { return [ 'all_posts'=>__( 'All posts', 'dsa' ), 'all_pages'=>__( 'All pages', 'dsa' ), 'all_products'=>__( 'All products', 'dsa' ), 'all_media'=>__( 'All media', 'dsa' ) ]; }
	private function field_label( string $field ): string { return [ 'seoTitle'=>'SEO title', 'metaDescription'=>'Search description', 'excerpt'=>'Excerpt', 'searchPhrases'=>'Search intent phrases', 'title'=>'Media title', 'alt'=>'Alternative text', 'caption'=>'Caption', 'description'=>'Media description' ][ $field ] ?? $field; }
	private function job(): array { $job=get_option( self::JOB_OPTION, [] ); return is_array( $job ) && self::SCHEMA === ( $job['schema'] ?? '' ) ? $job : []; }
	private function authorize( string $nonce ): void { check_admin_referer( $nonce ); if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage SEO proposals.', 'dsa' ) ); }
	private function redirect( string $status ): void { wp_safe_redirect( admin_url( 'admin.php?page=kiwe-seo&seo-refinement=' . sanitize_key( $status ) ) ); exit; }
	private function notice( string $key ): string { return [ 'queued'=>__( 'The shared-host-safe SEO review was queued.', 'dsa' ), 'empty'=>__( 'No eligible public records were found.', 'dsa' ), 'processed'=>__( 'The next bounded batch was processed.', 'dsa' ), 'accepted'=>__( 'The reviewed proposal was applied.', 'dsa' ), 'rejected'=>__( 'The proposal was rejected; content was preserved.', 'dsa' ), 'stale'=>__( 'The source changed after this proposal. Start a fresh review.', 'dsa' ), 'invalid'=>__( 'The requested SEO action was invalid.', 'dsa' ) ][ $key ] ?? __( 'SEO review updated.', 'dsa' ); }
	private function dedicated_seo_plugin_active(): bool { return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ); }
}
