<?php

namespace DSA\Onboarding;

use DSA\AI\AI_Broker_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Controlled, field-level editorial review for owner Design Context. */
final class Design_Context_Refinement_Service {
	private const OPTION = 'kiwe_design_context_refinement_v1';
	private const SCHEMA = 'kiwe.design-context-refinement.v1';

	public function __construct( private Design_Context_Profile_Service $profiles, private AI_Broker_Service $broker ) {}

	public function register(): void {
		add_action( 'admin_post_kiwe_generate_context_refinement', [ $this, 'handle_generate' ] );
		add_action( 'admin_post_kiwe_review_context_refinement', [ $this, 'handle_review' ] );
	}

	public function render_panel(): void {
		$proposal = $this->proposal();
		$status = $this->broker->status( 'design_context' );
		$message = sanitize_key( (string) ( $_GET['refinement'] ?? '' ) );
		$detail = sanitize_key( (string) ( $_GET['refinement-detail'] ?? '' ) );
		$messages = [
			'generated'=>__( 'Kiwe AI prepared editorial suggestions. Nothing changed until you accept it.', 'dsa' ),
			'accepted'=>__( 'The selected refinement was accepted into owner context.', 'dsa' ),
			'accepted_all'=>__( 'All still-current suggestions were accepted. Stale suggestions were left for review.', 'dsa' ),
			'rejected'=>__( 'The suggestion was rejected and owner content was preserved.', 'dsa' ),
			'regenerated'=>__( 'Kiwe AI prepared a new suggestion for that field only.', 'dsa' ),
			'failed'=>__( 'Kiwe AI could not produce a contract-valid refinement. No owner content changed.', 'dsa' ),
			'stale'=>__( 'That field changed after the suggestion was created. Generate a fresh refinement instead.', 'dsa' ),
		];
		?>
		<section class="kiwe-refinement" id="kiwe-refinement">
			<div><span class="kiwe-onboarding__eyebrow"><?php esc_html_e( 'Optional AI review', 'dsa' ); ?></span><h2><?php esc_html_e( 'Refine owner copy without losing owner facts', 'dsa' ); ?></h2><p><?php esc_html_e( 'Kiwe proposes grammar, clarity and search-intent improvements, and may draft eligible missing copy such as a mission or vision. Legal identifiers, verified claims, names, contacts, addresses, prices, products and publishing state are outside its authority.', 'dsa' ); ?></p></div>
			<?php if ( isset( $messages[ $message ] ) ) : ?><div class="notice notice-<?php echo 'failed' === $message || 'stale' === $message ? 'warning' : 'success'; ?> inline"><p><?php echo esc_html( $messages[ $message ] ); ?></p></div><?php endif; ?>
			<?php if ( 'failed' === $message && '' !== $detail ) : ?><p class="description"><code><?php echo esc_html( $detail ); ?></code> · <?php esc_html_e( 'This diagnostic contains no prompt, owner content or provider secret.', 'dsa' ); ?></p><?php endif; ?>
			<?php if ( ! $this->profiles->is_complete() ) : ?><p class="description"><?php esc_html_e( 'Save owner context before requesting refinement.', 'dsa' ); ?></p><?php elseif ( empty( $status['profile']['enabled'] ) ) : ?><p class="description"><?php esc_html_e( 'The shared Kiwe AI provider is not enabled for SiteGraph/Design Context. Configure it once in Kiwe AI; no separate onboarding key is used.', 'dsa' ); ?></p><?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_generate_context_refinement"><?php wp_nonce_field( 'kiwe_generate_context_refinement' ); ?><button class="button button-primary" type="submit"><?php echo $proposal ? esc_html__( 'Generate fresh full review', 'dsa' ) : esc_html__( 'Refine my Design Context', 'dsa' ); ?></button></form>
			<?php endif; ?>
			<?php if ( $proposal ) : ?>
				<div class="kiwe-refinement__summary"><strong><?php echo esc_html( sprintf( __( '%d field suggestions', 'dsa' ), count( (array) $proposal['changes'] ) ) ); ?></strong><span><?php esc_html_e( 'Each suggestion is bound to the original field value. If that value changes, acceptance fails closed.', 'dsa' ); ?></span></div>
				<div class="kiwe-refinement__list">
				<?php foreach ( (array) $proposal['changes'] as $id=>$change ) : ?>
					<article class="kiwe-refinement-card kiwe-refinement-card--<?php echo esc_attr( sanitize_key( (string) ( $change['status'] ?? 'pending' ) ) ); ?>">
						<header><div><small><?php echo esc_html( (string) ( $change['label'] ?? $change['path'] ) ); ?></small><strong><?php echo esc_html( ucfirst( (string) ( $change['status'] ?? 'pending' ) ) ); ?></strong></div><span><?php echo esc_html( (string) ( $change['reason'] ?? '' ) ); ?></span></header>
						<div class="kiwe-refinement-card__compare"><div><b><?php esc_html_e( 'Owner text', 'dsa' ); ?></b><p><?php echo nl2br( esc_html( (string) ( $change['original'] ?? '' ) ) ); ?></p></div><div><b><?php esc_html_e( 'Suggested text', 'dsa' ); ?></b><p><?php echo nl2br( esc_html( (string) ( $change['value'] ?? '' ) ) ); ?></p></div></div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_review_context_refinement"><input type="hidden" name="change_id" value="<?php echo esc_attr( (string) $id ); ?>"><?php wp_nonce_field( 'kiwe_review_context_refinement' ); ?><button class="button button-primary" name="decision" value="accept" type="submit"><?php esc_html_e( 'Accept', 'dsa' ); ?></button><button class="button" name="decision" value="regenerate" type="submit"><?php esc_html_e( 'Try another', 'dsa' ); ?></button><button class="button-link-delete" name="decision" value="reject" type="submit"><?php esc_html_e( 'Reject', 'dsa' ); ?></button></form>
					</article>
				<?php endforeach; ?>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kiwe-refinement__accept-all"><input type="hidden" name="action" value="kiwe_review_context_refinement"><?php wp_nonce_field( 'kiwe_review_context_refinement' ); ?><button class="button button-primary" name="decision" value="accept_all" type="submit"><?php esc_html_e( 'Accept all still-current suggestions', 'dsa' ); ?></button></form>
			<?php endif; ?>
		</section>
		<?php
	}

	public function handle_generate(): void {
		$this->authorize( 'kiwe_generate_context_refinement' );
		$result = $this->generate();
		$this->redirect( is_wp_error( $result ) ? 'failed_' . sanitize_key( $result->get_error_code() ) : 'generated' );
	}

	public function handle_review(): void {
		$this->authorize( 'kiwe_review_context_refinement' );
		$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
		$id = sanitize_key( (string) ( $_POST['change_id'] ?? '' ) );
		if ( 'regenerate' === $decision ) {
			$proposal = $this->proposal();
			$path = (string) ( $proposal['changes'][ $id ]['path'] ?? '' );
			$result = $path ? $this->generate( $path ) : new \WP_Error( 'missing', 'Missing field.' );
			$this->redirect( is_wp_error( $result ) ? 'failed' : 'regenerated' );
		}
		if ( 'accept_all' === $decision ) {
			$this->accept_all();
			$this->redirect( 'accepted_all' );
		}
		if ( ! $id || ! in_array( $decision, [ 'accept','reject' ], true ) ) $this->redirect( 'failed' );
		if ( 'reject' === $decision ) {
			$this->set_status( $id, 'rejected' );
			$this->redirect( 'rejected' );
		}
		$this->redirect( $this->accept( $id ) ? 'accepted' : 'stale' );
	}

	private function generate( string $only_path = '' ) {
		$fields = $this->fields();
		if ( $only_path ) $fields = isset( $fields[ $only_path ] ) ? [ $only_path=>$fields[ $only_path ] ] : [];
		if ( ! $fields ) return new \WP_Error( 'empty', __( 'There is no eligible copy to refine.', 'dsa' ) );
		$packet = [];
		foreach ( $fields as $path=>$field ) $packet[] = [ 'path'=>$path, 'label'=>$field['label'], 'current'=>$field['value'], 'mayFillWhenEmpty'=>$field['mayFill'], 'maxLength'=>$field['maxLength'] ];
		$result = $this->broker->request( [
			'service'=>'design_context', 'capability'=>'refine', 'operation'=>$only_path ? 'regenerate_field' : 'refine_owner_context',
			'system'=>'Act as a precise editorial and ethical SEO copy editor. Return only JSON. Preserve language, voice, verified facts and meaning. Correct grammar and improve reader clarity. You may fill an empty field only when mayFillWhenEmpty is true and the supplied context supports it. Never invent claims, awards, dates, people, locations, credentials, prices, legal identifiers, guarantees or keywords. Avoid keyword stuffing and ranking promises. Keep suggestions concise and prioritize a complete contract-valid response over verbosity.',
			'user'=>(string) wp_json_encode( [
				'output'=>[ 'schema'=>self::SCHEMA, 'changes'=>[ [ 'path'=>'one supplied path', 'value'=>'proposed copy', 'reason'=>'short explanation', 'confidence'=>0.0 ] ] ],
				'context'=>$this->context_for_ai(), 'editableFields'=>$packet,
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'responseSchema'=>$this->response_schema( array_keys( $fields ) ),
		] );
		$data = is_array( $result['validation']['data'] ?? null ) ? $result['validation']['data'] : [];
		if ( empty( $result['ok'] ) ) return new \WP_Error( sanitize_key( (string) ( $result['reason'] ?? $result['error']['code'] ?? 'provider' ) ), __( 'AI refinement could not complete.', 'dsa' ) );
		if ( self::SCHEMA !== (string) ( $data['schema'] ?? '' ) ) return new \WP_Error( 'schema', __( 'AI refinement failed its output contract.', 'dsa' ) );
		$changes = [];
		foreach ( array_slice( is_array( $data['changes'] ?? null ) ? $data['changes'] : [], 0, 30 ) as $change ) {
			if ( ! is_array( $change ) ) continue;
			$path = (string) ( $change['path'] ?? '' );
			if ( ! isset( $fields[ $path ] ) ) continue;
			$field = $fields[ $path ];
			$value = substr( sanitize_textarea_field( (string) ( $change['value'] ?? '' ) ), 0, $field['maxLength'] );
			if ( '' === trim( $value ) || $value === $field['value'] || ( '' === $field['value'] && empty( $field['mayFill'] ) ) ) continue;
			$id = substr( hash( 'sha256', $path ), 0, 16 );
			$changes[ $id ] = [ 'path'=>$path, 'label'=>$field['label'], 'original'=>$field['value'], 'originalHash'=>hash( 'sha256', $field['value'] ), 'value'=>$value, 'reason'=>substr( sanitize_text_field( (string) ( $change['reason'] ?? '' ) ), 0, 300 ), 'confidence'=>max( 0, min( 1, (float) ( $change['confidence'] ?? 0 ) ) ), 'status'=>'pending' ];
		}
		if ( ! $changes ) return new \WP_Error( 'no_changes', __( 'AI returned no safe, useful changes.', 'dsa' ) );
		$stored = $only_path ? $this->proposal() : [];
		$stored_changes = is_array( $stored['changes'] ?? null ) ? $stored['changes'] : [];
		if ( $only_path ) foreach ( $stored_changes as $id=>$change ) if ( $only_path === ( $change['path'] ?? '' ) ) unset( $stored_changes[ $id ] );
		$proposal = [ 'schema'=>self::SCHEMA, 'generatedAt'=>gmdate( 'c' ), 'generatedBy'=>get_current_user_id(), 'changes'=>array_replace( $stored_changes, $changes ), 'authority'=>[ 'proposalOnly'=>true, 'mayPublish'=>false, 'lockedFactsPreserved'=>true ] ];
		update_option( self::OPTION, $proposal, false );
		return $proposal;
	}

	private function response_schema( array $paths ): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => [ 'schema','changes' ],
			'properties' => [
				'schema' => [ 'type'=>'string', 'enum'=>[ self::SCHEMA ] ],
				'changes' => [
					'type'=>'array', 'maxItems'=>count( $paths ),
					'items'=>[
						'type'=>'object', 'additionalProperties'=>false,
						'required'=>[ 'path','value','reason','confidence' ],
						'properties'=>[
							'path'=>[ 'type'=>'string', 'enum'=>array_values( $paths ) ],
							'value'=>[ 'type'=>'string', 'maxLength'=>5000 ],
							'reason'=>[ 'type'=>'string', 'maxLength'=>300 ],
							'confidence'=>[ 'type'=>'number' ],
						],
					],
				],
			],
		];
	}

	private function accept( string $id ): bool {
		$proposal = $this->proposal();
		$change = $proposal['changes'][ $id ] ?? null;
		if ( ! is_array( $change ) ) return false;
		$status = (string) ( $change['status'] ?? '' );
		if ( ! in_array( $status, [ 'pending', 'rejected' ], true ) ) return false;
		$current = $this->fields()[ $change['path'] ]['value'] ?? null;
		if ( ! is_string( $current ) || ! hash_equals( (string) $change['originalHash'], hash( 'sha256', $current ) ) ) return false;
		$applied = $this->profiles->apply_editorial_refinements( [ $change['path']=>$change['value'] ], get_current_user_id() );
		if ( empty( $applied ) ) return false;
		$this->set_status( $id, 'accepted' );
		return true;
	}

	private function accept_all(): void {
		foreach ( array_keys( (array) ( $this->proposal()['changes'] ?? [] ) ) as $id ) $this->accept( (string) $id );
	}

	private function set_status( string $id, string $status ): void {
		$proposal = $this->proposal();
		if ( ! isset( $proposal['changes'][ $id ] ) ) return;
		$proposal['changes'][ $id ]['status'] = $status;
		$proposal['changes'][ $id ]['reviewedAt'] = gmdate( 'c' );
		$proposal['changes'][ $id ]['reviewedBy'] = get_current_user_id();
		update_option( self::OPTION, $proposal, false );
	}

	private function proposal(): array {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) && self::SCHEMA === ( $value['schema'] ?? '' ) ? $value : [];
	}

	private function fields(): array {
		$p = $this->profiles->current();
		$definitions = [
			'identity.tagline'=>[ 'Short tagline',160,true ], 'identity.description'=>[ 'Business description',5000,true ],
			'audience.primary'=>[ 'Primary audience',500,true ], 'audience.locations'=>[ 'Audience locations',500,true ], 'audience.needs'=>[ 'Audience needs',2000,true ],
			'about.story'=>[ 'Brand story',5000,true ], 'about.mission'=>[ 'Mission',2000,true ], 'about.vision'=>[ 'Vision',2000,true ], 'about.values'=>[ 'Values',2000,true ], 'about.usp'=>[ 'Unique selling proposition',2000,true ],
			'about.founder.bio'=>[ 'Founder bio',3000,false ], 'brand.notes'=>[ 'Brand notes',3000,true ],
			'seo.homepageDescription'=>[ 'Homepage search description',320,true ], 'seo.searchIntent'=>[ 'Customer search intent',240,true ], 'seo.proofPoints'=>[ 'Verified proof points',1600,false ],
		];
		$out = [];
		foreach ( $definitions as $path=>$definition ) {
			$value = $p;
			foreach ( explode( '.', $path ) as $segment ) $value = is_array( $value ) ? ( $value[ $segment ] ?? '' ) : '';
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' === $value && ! $definition[2] ) continue;
			$out[ $path ] = [ 'label'=>(string) $definition[0], 'maxLength'=>(int) $definition[1], 'mayFill'=>(bool) $definition[2], 'value'=>$value ];
		}
		return $out;
	}

	private function context_for_ai(): array {
		$context = $this->profiles->public_context( false );
		$about = is_array( $context['about'] ?? null ) ? $context['about'] : [];
		$founder = is_array( $about['founder'] ?? null ) ? $about['founder'] : [];
		$services = is_array( $context['services']['items'] ?? null ) ? $context['services']['items'] : [];
		return [
			'schema'=>'kiwe.design-context-editorial-evidence.v1',
			'identity'=>array_intersect_key( (array) ( $context['identity'] ?? [] ), array_flip( [ 'siteName','tagline','description','industry','industrySector','siteType' ] ) ),
			'audience'=>array_intersect_key( (array) ( $context['audience'] ?? [] ), array_flip( [ 'primary','locations','needs' ] ) ),
			'about'=>[
				'story'=>(string) ( $about['story'] ?? '' ), 'mission'=>(string) ( $about['mission'] ?? '' ), 'vision'=>(string) ( $about['vision'] ?? '' ),
				'values'=>(string) ( $about['values'] ?? '' ), 'usp'=>(string) ( $about['usp'] ?? '' ),
				'founder'=>array_intersect_key( $founder, array_flip( [ 'enabled','name','role','bio' ] ) ),
			],
			'brand'=>array_intersect_key( (array) ( $context['brand'] ?? [] ), array_flip( [ 'tone','notes','colors' ] ) ),
			'seo'=>array_intersect_key( (array) ( $context['seo'] ?? [] ), array_flip( [ 'homepageDescription','legalName','foundedYear','primaryGoal','searchIntent','proofPoints' ] ) ),
			'contentSignals'=>array_intersect_key( (array) ( $context['contentPlan'] ?? [] ), array_flip( [ 'showBlogRailOnHome','highlightBestsellers' ] ) ),
			'commerce'=>array_intersect_key( (array) ( $context['commercePlan'] ?? [] ), array_flip( [ 'enabled','hasBundles','currency','sellingLocations' ] ) ),
			'services'=>array_values( array_slice( array_map( static fn( $service ): array => array_intersect_key( is_array( $service ) ? $service : [], array_flip( [ 'title','summary','categoryPaths' ] ) ), $services ), 0, 20 ) ),
			'authority'=>[ 'editableFieldsSuppliedSeparately'=>true, 'ownerEvidenceWins'=>true, 'mayInventFacts'=>false, 'excluded'=>[ 'contacts','operationalAddress','legalIdentifiers','mediaResources','adminIdentities' ] ],
		];
	}

	private function authorize( string $nonce ): void {
		check_admin_referer( $nonce );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to refine Design Context.', 'dsa' ) );
	}

	private function redirect( string $status ): void {
		$normalized = sanitize_key( $status );
		$notice = str_starts_with( $normalized, 'failed_' ) ? 'failed' : $normalized;
		$detail = str_starts_with( $normalized, 'failed_' ) ? substr( $normalized, 7 ) : '';
		$url = add_query_arg( [ 'saved'=>'1', 'refinement'=>$notice ], admin_url( 'admin.php?page=kiwe-onboarding' ) );
		if ( '' !== $detail ) $url = add_query_arg( 'refinement-detail', $detail, $url );
		wp_safe_redirect( $url . '#kiwe-refinement' );
		exit;
	}
}
