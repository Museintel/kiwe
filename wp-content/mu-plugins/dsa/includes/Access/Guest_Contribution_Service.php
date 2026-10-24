<?php

namespace DSA\Access;

use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Application-gated guest contributions.
 *
 * WordPress' contributor role is retained as the compatibility primitive, but
 * Kiwe never exposes its native editor. Approved guests receive one narrow
 * create-once workspace; submitted posts are immutable to their author.
 */
final class Guest_Contribution_Service {
	public const META = 'kiwe_guest_application_v1';
	public const SETTINGS_OPTION = 'kiwe_guest_contribution_settings';
	private const POST_META = '_kiwe_guest_submission';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 24 );
		add_action( 'admin_post_kiwe_guest_save_settings', [ $this, 'handle_settings' ] );
		add_action( 'admin_post_kiwe_guest_decide', [ $this, 'handle_decision' ] );
		add_action( 'admin_post_kiwe_guest_submit', [ $this, 'handle_submission' ] );
		add_filter( 'translate_user_role', [ $this, 'guest_role_label' ], 10, 2 );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/account/guest-application',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'apply' ],
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return is_user_logged_in() && Origin_Checker::mutation_allowed( $request );
				},
			]
		);
	}

	public static function application( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		return is_array( $stored ) ? $stored : [];
	}

	public static function is_submission( int $post_id ): bool {
		$stored = get_post_meta( $post_id, self::POST_META, true );
		return is_array( $stored ) && ! empty( $stored['createdAt'] );
	}

	public static function approved( int $user_id ): bool {
		return 'approved' === sanitize_key( (string) ( self::application( $user_id )['status'] ?? '' ) );
	}

	public static function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$commerce = \DSA\Apps\Store_App_Service::enabled();
		$posts = ! empty( $stored['posts_enabled'] );
		$products = $commerce && ! empty( $stored['products_enabled'] );

		return [
			'postsEnabled'      => $posts,
			'productsEnabled'   => $products,
			'commerceAvailable' => $commerce,
			'anyEnabled'        => $posts || $products,
		];
	}

	public static function profile_state( int $user_id ): array {
		$application = self::application( $user_id );
		$user = get_userdata( $user_id );
		$roles = $user ? (array) $user->roles : [];
		$status = sanitize_key( (string) ( $application['status'] ?? '' ) );
		if ( '' === $status && in_array( 'contributor', $roles, true ) ) {
			$status = 'approved';
		}
		$verified = \DSA\PhoneKey\PhoneKey_Bridge::account_verified( $user_id );
		$features = self::settings();
		$has_admin_access = self::has_admin_area_access( $user_id, $roles );

		return [
			'eligible'          => $user_id > 0 && $verified && ! $has_admin_access && ! empty( $features['anyEnabled'] ),
			'identityVerified'  => $verified,
			'hasAdminAccess'    => $has_admin_access,
			'status'            => $status ?: 'none',
			'intents'           => array_values( array_intersect( [ 'post', 'product' ], array_map( 'sanitize_key', (array) ( $application['intents'] ?? [] ) ) ) ),
			'commerceAvailable' => ! empty( $features['commerceAvailable'] ),
			'postsAvailable'    => ! empty( $features['postsEnabled'] ),
			'productsAvailable' => ! empty( $features['productsEnabled'] ),
			'applicationsEnabled' => ! empty( $features['anyEnabled'] ),
			'canSubmitPosts'    => 'approved' === $status && in_array( 'contributor', $roles, true ) && ! empty( $features['postsEnabled'] ),
			'label'             => self::status_label( $status ),
		];
	}

	public function apply( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$state = self::profile_state( $user_id );
		if ( empty( $state['identityVerified'] ) ) {
			return new WP_Error( 'kiwe_guest_verification_required', __( 'Verify your email address or phone number before applying.', 'dsa' ), [ 'status' => 403 ] );
		}
		if ( empty( $state['eligible'] ) || in_array( $state['status'], [ 'pending', 'approved' ], true ) ) {
			return new WP_Error( 'kiwe_guest_application_unavailable', __( 'A Guest application cannot be started for this account.', 'dsa' ), [ 'status' => 409 ] );
		}

		$prior = self::application( $user_id );
		$decided_at = isset( $prior['decidedAt'] ) ? strtotime( (string) $prior['decidedAt'] ) : 0;
		if ( 'denied' === ( $prior['status'] ?? '' ) && $decided_at && ( time() - $decided_at ) < 7 * DAY_IN_SECONDS ) {
			return new WP_Error( 'kiwe_guest_application_cooldown', __( 'This application was recently reviewed. You can apply again after seven days.', 'dsa' ), [ 'status' => 429 ] );
		}

		$allowed = [];
		if ( ! empty( $state['postsAvailable'] ) ) {
			$allowed[] = 'post';
		}
		if ( ! empty( $state['productsAvailable'] ) ) {
			$allowed[] = 'product';
		}
		if ( [] === $allowed ) {
			return new WP_Error( 'kiwe_guest_applications_disabled', __( 'Guest applications are not enabled on this site.', 'dsa' ), [ 'status' => 403 ] );
		}
		$intents = array_values( array_intersect( $allowed, array_map( 'sanitize_key', (array) $request->get_param( 'intents' ) ) ) );
		if ( [] === $intents ) {
			$intents = [ $allowed[0] ];
		}
		$application = [
			'status'    => 'pending',
			'intents'   => $intents,
			'appliedAt' => gmdate( 'c' ),
			'decidedAt' => '',
			'decidedBy' => 0,
			'reason'    => '',
		];
		update_user_meta( $user_id, self::META, $application );
		do_action( 'kiwe_guest_application_submitted', $user_id, $application );

		return new WP_REST_Response( [ 'ok' => true, 'guestApplication' => self::profile_state( $user_id ) ], 200 );
	}

	public function menu(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$count = current_user_can( 'kiwe_manage_guest_applications' ) ? $this->pending_count() : 0;
		$label = __( 'Guest', 'dsa' );
		if ( $count > 0 ) {
			$label .= ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . $count . '</span></span>';
		}
		if ( current_user_can( 'kiwe_manage_guest_applications' ) || current_user_can( 'kiwe_guest_submit' ) || ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && ( current_user_can( 'edit_others_posts' ) || current_user_can( 'edit_others_products' ) ) ) ) {
			add_menu_page( __( 'Guest', 'dsa' ), $label, 'read', 'kiwe-guests', [ $this, 'render' ], 'dashicons-welcome-write-blog', 31 );
		}
	}

	public function render(): void {
		if ( current_user_can( 'kiwe_manage_guest_applications' ) ) {
			$this->render_applications();
			return;
		}
		if ( current_user_can( 'kiwe_guest_submit' ) && self::approved( get_current_user_id() ) ) {
			$this->render_workspace();
			return;
		}
		wp_die( esc_html__( 'Guest access is not active for this account.', 'dsa' ), '', [ 'response' => 403 ] );
	}

	public function handle_settings(): void {
		check_admin_referer( 'kiwe_guest_save_settings' );
		$result = $this->save_workspace_settings( [ 'postsEnabled'=>! empty( $_POST['posts_enabled'] ), 'productsEnabled'=>! empty( $_POST['products_enabled'] ) ] );
		if ( is_wp_error( $result ) ) Task_Workspace_UI::error( $result );
		wp_safe_redirect( add_query_arg( [ 'page'=>'kiwe-guests', 'settings-updated'=>1 ], admin_url( 'admin.php' ) ) ); exit;
	}
	public function handle_decision(): void {
		check_admin_referer( 'kiwe_guest_decide' );
		$result = $this->decide_application( absint( $_POST['user_id'] ?? 0 ), sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) Task_Workspace_UI::error( $result );
		wp_safe_redirect( add_query_arg( [ 'page'=>'kiwe-guests', 'updated'=>$result['status'] ], admin_url( 'admin.php' ) ) ); exit;
	}
	public function handle_submission(): void {
		check_admin_referer( 'kiwe_guest_submit' );
		$result = $this->submit_workspace( [ 'type'=>sanitize_key( wp_unslash( $_POST['submission_type'] ?? 'post' ) ), 'title'=>wp_unslash( $_POST['post_title'] ?? '' ), 'content'=>wp_unslash( $_POST['post_content'] ?? '' ), 'status'=>sanitize_key( wp_unslash( $_POST['post_status'] ?? 'pending' ) ), 'price'=>wp_unslash( $_POST['regular_price'] ?? '' ) ] );
		if ( is_wp_error( $result ) ) Task_Workspace_UI::error( $result );
		wp_safe_redirect( add_query_arg( [ 'page'=>'kiwe-guests', 'submitted'=>$result['status'] ], admin_url( 'admin.php' ) ) ); exit;
	}
	/** Paged role-scoped projection. Reads never write, queue work or send messages. */
	public function workspace( array $input = [] ): array {
		$user_id = get_current_user_id();
		$manage = is_user_logged_in() && current_user_can( 'kiwe_manage_guest_applications' );
		$types = [];
		if ( current_user_can( 'edit_posts' ) && current_user_can( 'edit_others_posts' ) ) $types[] = 'post';
		if ( \DSA\Apps\Store_App_Service::enabled() && current_user_can( 'edit_products' ) && current_user_can( 'edit_others_products' ) ) $types[] = 'product';
		$submit = is_user_logged_in() && current_user_can( 'kiwe_guest_submit' ) && self::approved( $user_id );
		$views = array_merge( $manage ? [ 'applications' ] : [], $types ? [ 'review' ] : [], $submit ? [ 'mine' ] : [] );
		$view = $manage && 'setup' === ( $input['view'] ?? '' ) ? 'setup' : ( in_array( $input['view'] ?? '', $views, true ) ? $input['view'] : ( $views[0] ?? '' ) );
		$requested_id = max( 0, is_scalar( $input['id'] ?? null ) ? (int) $input['id'] : 0 );
		$page = $requested_id > 0 ? 1 : max( 1, min( 1000, is_scalar( $input['page'] ?? null ) ? (int) $input['page'] : 1 ) );
		$search = Task_Workspace_UI::text( is_scalar( $input['search'] ?? null ) ? (string) $input['search'] : '', 80 );
		$sort = in_array( $input['sort'] ?? '', [ 'date_desc','date_asc','title_asc','title_desc' ], true ) ? $input['sort'] : 'date_desc';
		$status = in_array( $input['status'] ?? '', [ 'draft','pending','publish','future','private' ], true ) ? $input['status'] : 'all';
		$kind = in_array( $input['kind'] ?? '', [ 'post','product' ], true ) && in_array( $input['kind'], $types, true ) ? $input['kind'] : 'all';
		$out = [ 'settings'=>self::settings(), 'settingsExpected'=>self::settings_fingerprint(), 'views'=>$views, 'view'=>$view, 'page'=>$page, 'search'=>$search, 'sort'=>$sort, 'status'=>$status, 'kind'=>$kind, 'items'=>[], 'hasNext'=>false, 'categories'=>[], 'canManage'=>$manage, 'canSubmit'=>$submit, 'submitTypes'=>[], 'reviewTypes'=>$types, 'publishTypes'=>[] ];
		if ( ! is_user_logged_in() || ! $views ) return $out;
		if ( 'setup' === $view ) return $out;
		foreach ( $types as $type ) {
			if ( current_user_can( 'product' === $type ? 'publish_products' : 'publish_posts' ) ) $out['publishTypes'][] = $type;
			$tax = 'product' === $type ? 'product_cat' : 'category';
			$terms = get_terms( [ 'taxonomy'=>$tax, 'hide_empty'=>false, 'number'=>100 ] );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) $out['categories'][] = [ 'id'=>(int) $term->term_id, 'name'=>(string) $term->name, 'type'=>$type ];
		}
		if ( $submit ) foreach ( (array) ( self::application( $user_id )['intents'] ?? [] ) as $type ) {
			if ( ( 'post' === $type && $out['settings']['postsEnabled'] ) || ( 'product' === $type && $out['settings']['productsEnabled'] ) ) $out['submitTypes'][] = $type;
		}
		if ( 'applications' === $view ) {
			if ( $requested_id > 0 ) {
				$candidate = get_userdata( $requested_id );
				$records = $candidate && self::application( $requested_id ) ? [ $candidate ] : [];
			} else {
				$args = [ 'meta_key'=>self::META, 'number'=>21, 'offset'=>( $page - 1 ) * 20, 'orderby'=>str_starts_with( $sort, 'title_' ) ? 'display_name' : 'registered', 'order'=>str_ends_with( $sort, '_asc' ) ? 'ASC' : 'DESC', 'count_total'=>false ];
				if ( '' !== $search ) { $args['search'] = '*' . str_replace( '*', '', $search ) . '*'; $args['search_columns'] = [ 'display_name' ]; }
				$records = get_users( $args );
			}
			foreach ( array_slice( $records, 0, 20 ) as $user ) $out['items'][] = $this->project_application( $user );
		} else {
			if ( $requested_id > 0 ) {
				$candidate = get_post( $requested_id );
				$records = $candidate ? [ $candidate ] : [];
			} else {
				$post_types = 'mine' === $view ? ( \DSA\Apps\Store_App_Service::enabled() ? [ 'post','product' ] : [ 'post' ] ) : $types;
				if ( 'all' !== $kind && in_array( $kind, $post_types, true ) ) $post_types = [ $kind ];
				$args = [ 'post_type'=>$post_types, 'post_status'=>'all' === $status ? [ 'draft','pending','publish','future','private' ] : [ $status ], 'posts_per_page'=>21, 'offset'=>( $page - 1 ) * 20, 'orderby'=>str_starts_with( $sort, 'title_' ) ? 'title' : 'modified', 'order'=>str_ends_with( $sort, '_asc' ) ? 'ASC' : 'DESC', 's'=>$search, 'meta_key'=>self::POST_META, 'meta_compare'=>'EXISTS', 'no_found_rows'=>true ];
				if ( 'mine' === $view ) $args['author'] = $user_id;
				$records = get_posts( $args );
			}
			foreach ( array_slice( $records, 0, 20 ) as $post ) {
				if ( ! self::is_submission( (int) $post->ID ) ) continue;
				if ( ! in_array( $post->post_type, 'mine' === $view ? [ 'post','product' ] : $types, true ) ) continue;
				if ( ! in_array( $post->post_status, [ 'draft','pending','publish','future','private' ], true ) ) continue;
				if ( 'mine' === $view ? (int) $post->post_author !== $user_id : ! current_user_can( 'edit_post', (int) $post->ID ) ) continue;
				$out['items'][] = $this->project_submission( $post );
			}
		}
		$out['hasNext'] = 0 === $requested_id && count( $records ) > 20;
		return $out;
	}

	public static function settings_fingerprint(): string { return hash( 'sha256', wp_json_encode( self::settings() ) ); }

	public function decide_application( int $user_id, string $decision, string $reason = '', ?string $expected = null ) {
		if ( ! current_user_can( 'kiwe_manage_guest_applications' ) ) return new WP_Error( 'kiwe_guest_forbidden', 'You cannot review Guest applications.', [ 'status'=>403 ] );
		$user = get_userdata( $user_id ); $application = self::application( $user_id );
		if ( ! $user || 'pending' !== ( $application['status'] ?? '' ) || ! in_array( $decision, [ 'approved','denied' ], true ) || ( null !== $expected && ! hash_equals( $this->project_application( $user )['expected'], $expected ) ) ) return new WP_Error( 'kiwe_guest_stale', 'The application changed. Refresh and review it.', [ 'status'=>409 ] );
		if ( 'approved' === $decision && ( ! \DSA\PhoneKey\PhoneKey_Bridge::account_verified( $user_id ) || self::has_admin_area_access( $user_id, (array) $user->roles ) ) ) return new WP_Error( 'kiwe_guest_eligibility', 'This account is no longer eligible for Guest approval.', [ 'status'=>409 ] );
		$had_contributor = in_array( 'contributor', (array) $user->roles, true );
		$next = array_replace( $application, [ 'status'=>$decision, 'decidedAt'=>gmdate( 'c' ), 'decidedBy'=>get_current_user_id(), 'reason'=>'denied' === $decision ? substr( sanitize_text_field( $reason ), 0, 180 ) : '' ] );
		if ( ! update_user_meta( $user_id, self::META, $next, $application ) ) return new WP_Error( 'kiwe_guest_stale', 'The decision could not be confirmed. Refresh before retrying.', [ 'status'=>409 ] );
		if ( 'approved' === $decision ) $user->add_role( 'contributor' );
		else $user->remove_role( 'contributor' );
		$user = get_userdata( $user_id );
		if ( ! $user || ( 'approved' === $decision ) !== in_array( 'contributor', (array) $user->roles, true ) ) {
			if ( $user ) {
				if ( $had_contributor ) $user->add_role( 'contributor' );
				else $user->remove_role( 'contributor' );
			}
			$meta_restored = update_user_meta( $user_id, self::META, $application, $next );
			$restored_user = get_userdata( $user_id );
			$role_restored = $restored_user && $had_contributor === in_array( 'contributor', (array) $restored_user->roles, true );
			if ( $meta_restored && $role_restored && self::application( $user_id ) === $application ) {
				return new WP_Error( 'kiwe_guest_role', 'Guest access could not be confirmed, so the application remains pending. Refresh before retrying.', [ 'status'=>409 ] );
			}
			return new WP_Error( 'kiwe_guest_role_recovery', 'Guest access could not be confirmed and the application needs a site manager to review it before retrying.', [ 'status'=>409 ] );
		}
		do_action( 'kiwe_guest_application_decided', $user_id, $decision, $next );
		return $this->project_application( $user );
	}

	public function save_workspace_settings( array $input ) {
		if ( ! current_user_can( 'kiwe_manage_guest_applications' ) ) return new WP_Error( 'kiwe_guest_forbidden', 'Guest settings are unavailable.', [ 'status'=>403 ] );
		if ( ! is_bool( $input['postsEnabled'] ?? null ) || ! is_bool( $input['productsEnabled'] ?? null ) ) return new WP_Error( 'kiwe_guest_settings', 'Choose the contribution types.', [ 'status'=>400 ] );
		if ( isset( $input['expected'] ) && ! hash_equals( self::settings_fingerprint(), (string) $input['expected'] ) ) return new WP_Error( 'kiwe_guest_stale', 'Guest settings changed elsewhere. Reload before saving.', [ 'status'=>409 ] );
		$commerce = \DSA\Apps\Store_App_Service::enabled();
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$values = [ 'posts_enabled'=>$input['postsEnabled'], 'products_enabled'=>$commerce ? $input['productsEnabled'] : !empty( $stored['products_enabled'] ) ];
		update_option( self::SETTINGS_OPTION, $values, false );
		if ( $values !== get_option( self::SETTINGS_OPTION ) ) return new WP_Error( 'kiwe_guest_settings', 'Guest settings could not be confirmed.', [ 'status'=>409 ] );
		return [ 'settings'=>self::settings(), 'settingsExpected'=>self::settings_fingerprint() ];
	}

	private function writing( array $input ) {
		if ( ! is_string( $input['title'] ?? null ) || ! is_string( $input['content'] ?? null ) || strlen( $input['title'] ) > 960 || strlen( $input['content'] ) > 100000 ) return new WP_Error( 'kiwe_guest_content', 'Add a title and content within the writing limit.', [ 'status'=>400 ] );
		$title = sanitize_text_field( $input['title'] );
		if ( '' === $title || preg_match_all( '/./us', $title ) > 240 ) return new WP_Error( 'kiwe_guest_title', 'Enter a title of up to 240 characters.', [ 'status'=>400 ] );
		return [ 'title'=>$title, 'content'=>wp_kses_post( $input['content'] ) ];
	}

	public function review_submission( int $post_id, array $input ) {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_submission( $post_id ) || ! in_array( $post->post_type, [ 'post','product' ], true ) || ! in_array( $post->post_status, [ 'draft','pending','publish' ], true ) ) return new WP_Error( 'kiwe_guest_missing', 'This contribution is unavailable for review.', [ 'status'=>404 ] );
		$product = 'product' === $post->post_type;
		if ( $product && ! \DSA\Apps\Store_App_Service::enabled() ) return new WP_Error( 'kiwe_guest_store_off', 'Turn on Kiwe Store before reviewing product contributions.', [ 'status'=>409 ] );
		if ( ! current_user_can( $product ? 'edit_others_products' : 'edit_others_posts' ) || ! current_user_can( 'edit_post', $post_id ) ) return new WP_Error( 'kiwe_guest_forbidden', 'Your role cannot review this contribution.', [ 'status'=>403 ] );
		$before = $this->project_submission( $post );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return new WP_Error( 'kiwe_guest_stale', 'This contribution changed elsewhere. Reload before saving.', [ 'status'=>409 ] );
		$writing = $this->writing( $input ); if ( is_wp_error( $writing ) ) return $writing;
		$status = $input['status'] ?? '';
		if ( ! in_array( $status, [ 'draft','pending','publish' ], true ) ) return new WP_Error( 'kiwe_guest_status', 'Choose draft, review or publish.', [ 'status'=>400 ] );
		if ( 'publish' === $status && ! current_user_can( $product ? 'publish_products' : 'publish_posts' ) ) return new WP_Error( 'kiwe_guest_publish', 'Your role cannot publish this contribution.', [ 'status'=>403 ] );
		$tax = $product ? 'product_cat' : 'category'; $taxonomy = get_taxonomy( $tax );
		$categories = $input['categories'] ?? null;
		if ( ! is_array( $categories ) || count( $categories ) > 100 || ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) return new WP_Error( 'kiwe_guest_categories', 'Category assignment is unavailable.', [ 'status'=>403 ] );
		foreach ( $categories as $id ) if ( ! is_int( $id ) || $id < 1 || ! term_exists( $id, $tax ) ) return new WP_Error( 'kiwe_guest_categories', 'Choose existing categories.', [ 'status'=>400 ] );
		$categories = array_values( array_unique( $categories ) );
		if ( $product ) {
			if ( ! function_exists( 'wc_get_product' ) ) return new WP_Error( 'kiwe_guest_woo', 'WooCommerce is unavailable.', [ 'status'=>409 ] );
			try {
				$record = wc_get_product( $post_id ); if ( ! $record ) return new WP_Error( 'kiwe_guest_product', 'The product proposal could not be loaded.', [ 'status'=>409 ] );
				$record->set_name( $writing['title'] ); $record->set_description( $writing['content'] ); $record->set_status( $status ); $record->set_category_ids( $categories ); $saved = $record->save();
			} catch ( \Throwable $error ) { return new WP_Error( 'kiwe_guest_write', 'The product save could not be confirmed. Reload before retrying.', [ 'status'=>409 ] ); }
		} else {
			$saved = wp_update_post( wp_slash( [ 'ID'=>$post_id, 'post_title'=>$writing['title'], 'post_content'=>$writing['content'], 'post_status'=>$status, 'post_category'=>$categories ] ), true );
		}
		if ( is_wp_error( $saved ) || ! $saved ) return new WP_Error( 'kiwe_guest_write', 'The contribution save could not be confirmed.', [ 'status'=>409 ] );
		$readback = get_post( $post_id );
		if ( ! $readback ) return new WP_Error( 'kiwe_guest_unconfirmed', 'The saved contribution could not be read. Reload before retrying.', [ 'status'=>409 ] );
		$after = $this->project_submission( $readback );
		if ( $after['title'] !== $writing['title'] || $after['content'] !== $writing['content'] || $after['status'] !== $status || array_diff( $categories, $after['categories'] ) || array_diff( $after['categories'], $categories ) ) return new WP_Error( 'kiwe_guest_unconfirmed', 'The saved contribution differs. Reload and review it.', [ 'status'=>409 ] );
		return $after;
	}

	public function submit_workspace( array $input ) {
		$user_id = get_current_user_id();
		if ( ! current_user_can( 'kiwe_guest_submit' ) || ! self::approved( $user_id ) ) return new WP_Error( 'kiwe_guest_forbidden', 'Approved Guest access is required.', [ 'status'=>403 ] );
		$type = $input['type'] ?? ''; $status = $input['status'] ?? '';
		$features = self::settings(); $intents = (array) ( self::application( $user_id )['intents'] ?? [] );
		if ( ! in_array( $type, [ 'post','product' ], true ) || ! in_array( $type, $intents, true ) || ! $features[ 'post' === $type ? 'postsEnabled' : 'productsEnabled' ] ) return new WP_Error( 'kiwe_guest_type', 'This contribution type is not active for your account.', [ 'status'=>403 ] );
		if ( ! in_array( $status, [ 'draft','pending' ], true ) ) return new WP_Error( 'kiwe_guest_status', 'Guest contributions must be drafts or await review.', [ 'status'=>403 ] );
		$writing = $this->writing( $input ); if ( is_wp_error( $writing ) ) return $writing;
		if ( strlen( $writing['title'] ) < 5 || strlen( wp_strip_all_tags( $writing['content'] ) ) < ( 'product' === $type ? 20 : 50 ) ) return new WP_Error( 'kiwe_guest_content', 'Add a clear title and enough detail for review.', [ 'status'=>400 ] );
		$price = $input['price'] ?? '';
		if ( 'product' === $type && ( ! class_exists( '\WC_Product_Simple' ) || ! is_string( $price ) || ( '' !== $price && ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/D', $price ) ) ) ) return new WP_Error( 'kiwe_guest_price', 'Enter a valid proposed price.', [ 'status'=>400 ] );
		$key = 'kiwe_guest_submit_' . $user_id;
		if ( get_transient( $key ) ) return new WP_Error( 'kiwe_guest_wait', 'Please wait before sending another contribution.', [ 'status'=>429 ] );
		set_transient( $key, 1, 15 );
		$id = wp_insert_post( wp_slash( [ 'post_type'    => $type, 'post_author'=>$user_id, 'post_title'=>$writing['title'], 'post_content'=>$writing['content'], 'post_status'=>$status ] ), true );
		if ( is_wp_error( $id ) || ! $id ) return new WP_Error( 'kiwe_guest_write', 'The contribution could not be confirmed. Check your contributions before trying again.', [ 'status'=>409 ] );
		update_post_meta( (int) $id, self::POST_META, [ 'createdAt'=>gmdate( 'c' ), 'immutableForAuthor' => true ] );
		if ( 'product' === $type ) {
			try {
				$record = new \WC_Product_Simple( (int) $id ); $record->set_regular_price( $price ); $record->update_meta_data( '_kiwe_guest_seller_user_id', $user_id );
				if ( ! $record->save() ) return new WP_Error( 'kiwe_guest_product', 'The proposal was created but its price could not be confirmed. Ask your reviewer to check it.', [ 'status'=>409 ] );
			} catch ( \Throwable $error ) { return new WP_Error( 'kiwe_guest_product', 'The contribution was created but its price needs review. Check your contributions before trying again.', [ 'status'=>409 ] ); }
		}
		if ( ! self::is_submission( (int) $id ) ) return new WP_Error( 'kiwe_guest_unconfirmed', 'Contribution metadata could not be confirmed. Ask your editor to review it before retrying.', [ 'status'=>409 ] );
		$readback = get_post( (int) $id );
		if ( ! $readback || (int) $readback->post_author !== $user_id || $readback->post_type !== $type || $readback->post_title !== $writing['title'] || $readback->post_content !== $writing['content'] || $readback->post_status !== $status ) return new WP_Error( 'kiwe_guest_unconfirmed', 'Your contribution differs from the saved record. Check your contributions before retrying.', [ 'status'=>409 ] );
		if ( 'pending' === $status ) do_action( 'kiwe_guest_submission_received', (int) $id, $user_id );
		return $this->project_submission( $readback );
	}

	private function project_application( $user ): array {
		$app = self::application( (int) $user->ID );
		$verified = (bool) \DSA\PhoneKey\PhoneKey_Bridge::account_verified( (int) $user->ID );
		return [ 'id'=>(int) $user->ID, 'name'=>sanitize_text_field( $user->display_name ?: $user->user_login ), 'verified'=>$verified, 'intents'=>array_values( array_intersect( [ 'post','product' ], (array) ( $app['intents'] ?? [] ) ) ), 'status'=>sanitize_key( $app['status'] ?? 'none' ), 'reason'=>sanitize_text_field( $app['reason'] ?? '' ), 'roles'=>array_values( (array) $user->roles ), 'expected'=>hash( 'sha256', wp_json_encode( [ $app, $user->roles, $verified ] ) ) ];
	}

	private function project_submission( $post ): array {
		$categories = wp_get_object_terms( (int) $post->ID, 'product' === $post->post_type ? 'product_cat' : 'category', [ 'fields'=>'ids' ] );
		$categories = is_array( $categories ) ? array_map( 'intval', $categories ) : []; sort( $categories, SORT_NUMERIC );
		$author = get_userdata( (int) $post->post_author );
		$out = [ 'id'=>(int) $post->ID, 'type'=>(string) $post->post_type, 'title'=>(string) $post->post_title, 'content'=>(string) $post->post_content, 'status'=>(string) $post->post_status, 'authorId'=>(int) $post->post_author, 'authorName'=>$author ? sanitize_text_field( $author->display_name ) : '', 'categories'=>$categories, 'modifiedGmt'=>(string) $post->post_modified_gmt ];
		$out['expected'] = hash( 'sha256', wp_json_encode( $out ) ); return $out;
	}
	public function guest_role_label( string $translation, string $text ): string {
		return 'Contributor' === $text ? __( 'Guest', 'dsa' ) : $translation;
	}

	private function render_applications(): void {
		$users = get_users( [ 'meta_key' => self::META, 'number' => 200, 'orderby' => 'registered', 'order' => 'DESC' ] );
		$features = self::settings();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Guest applications', 'dsa' ); ?></h1>
		<h2><?php esc_html_e( 'Guest contribution settings', 'dsa' ); ?></h2>
		<p><?php esc_html_e( 'Guest applications remain hidden until at least one contribution type is enabled. Only partially verified people without administrator-area access can apply.', 'dsa' ); ?></p>
		<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Guest contribution settings saved.', 'dsa' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_guest_save_settings"><?php wp_nonce_field( 'kiwe_guest_save_settings' ); ?>
		<p><label><input type="checkbox" name="posts_enabled" value="1" <?php checked( ! empty( $features['postsEnabled'] ) ); ?>> <strong><?php esc_html_e( 'Enable Guest posts', 'dsa' ); ?></strong></label></p>
		<?php if ( ! empty( $features['commerceAvailable'] ) ) : ?><p><label><input type="checkbox" name="products_enabled" value="1" <?php checked( ! empty( $features['productsEnabled'] ) ); ?>> <strong><?php esc_html_e( 'Enable product proposals', 'dsa' ); ?></strong></label></p><?php else : ?><p class="description"><?php esc_html_e( 'Product proposals become available here only when WooCommerce is active.', 'dsa' ); ?></p><?php endif; ?>
		<?php submit_button( __( 'Save Guest settings', 'dsa' ), 'primary', 'submit', false ); ?></form><hr>
		<p><?php esc_html_e( 'Approve verified people for the isolated Guest submission workspace. Product proposals remain unpublished until store staff review them; approval never grants order, payout or store-settings access.', 'dsa' ); ?></p>
		<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Application updated.', 'dsa' ); ?></p></div><?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Person', 'dsa' ); ?></th><th><?php esc_html_e( 'Verified contact', 'dsa' ); ?></th><th><?php esc_html_e( 'Purpose', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Action', 'dsa' ); ?></th></tr></thead><tbody>
		<?php if ( [] === $users ) : ?><tr><td colspan="5"><?php esc_html_e( 'No Guest applications yet.', 'dsa' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $users as $user ) : $application = self::application( (int) $user->ID ); $status = sanitize_key( (string) ( $application['status'] ?? 'pending' ) ); ?>
		<tr><td><strong><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></strong><br><small><?php echo esc_html( $user->user_email ); ?></small></td>
		<td><?php echo esc_html( self::verified_contact_label( (int) $user->ID ) ); ?></td><td><?php echo esc_html( implode( ', ', array_map( [ $this, 'intent_label' ], (array) ( $application['intents'] ?? [] ) ) ) ); ?></td><td><?php echo esc_html( self::status_label( $status ) ); ?></td>
		<td><?php if ( 'pending' === $status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_guest_decide"><input type="hidden" name="user_id" value="<?php echo (int) $user->ID; ?>"><?php wp_nonce_field( 'kiwe_guest_decide' ); ?><input name="reason" maxlength="180" placeholder="Reason if denied"><button class="button button-primary" name="decision" value="approved"><?php esc_html_e( 'Approve', 'dsa' ); ?></button> <button class="button" name="decision" value="denied"><?php esc_html_e( 'Deny', 'dsa' ); ?></button></form><?php else : ?>—<?php endif; ?></td></tr>
		<?php endforeach; ?></tbody></table></div>
		<?php
	}

	private function render_workspace(): void {
		$application = self::application( get_current_user_id() );
		$features = self::settings();
		$post_enabled = ! empty( $features['postsEnabled'] ) && in_array( 'post', (array) ( $application['intents'] ?? [] ), true );
		$product_enabled = ! empty( $features['productsEnabled'] ) && function_exists( 'WC' ) && in_array( 'product', (array) ( $application['intents'] ?? [] ), true );
		$post_types = post_type_exists( 'product' ) ? [ 'post', 'product' ] : 'post';
		$posts = get_posts( [ 'author' => get_current_user_id(), 'post_type' => $post_types, 'post_status' => [ 'draft', 'pending', 'publish', 'future', 'private' ], 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC' ] );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Guest contributions', 'dsa' ); ?></h1><p><?php esc_html_e( 'Create a new contribution once. Drafts and submitted articles are read-only here; an editor owns every later change.', 'dsa' ); ?></p>
		<?php if ( isset( $_GET['submitted'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Your contribution was received.', 'dsa' ); ?></p></div><?php endif; ?>
		<?php if ( $post_enabled || $product_enabled ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px"><input type="hidden" name="action" value="kiwe_guest_submit"><?php wp_nonce_field( 'kiwe_guest_submit' ); ?><?php if ( $post_enabled && $product_enabled ) : ?><p><label><strong><?php esc_html_e( 'Contribution type', 'dsa' ); ?></strong><br><select name="submission_type"><option value="post"><?php esc_html_e( 'Guest article', 'dsa' ); ?></option><option value="product"><?php esc_html_e( 'Product proposal', 'dsa' ); ?></option></select></label></p><?php else : ?><input type="hidden" name="submission_type" value="<?php echo $product_enabled ? 'product' : 'post'; ?>"><?php endif; ?><p><label><strong><?php esc_html_e( 'Title', 'dsa' ); ?></strong><br><input class="large-text" name="post_title" maxlength="240" required></label></p><p><label><strong><?php esc_html_e( 'Article or product details', 'dsa' ); ?></strong><br><textarea class="large-text" name="post_content" rows="16" required></textarea></label></p><?php if ( $product_enabled ) : ?><p><label><strong><?php esc_html_e( 'Proposed price (product only)', 'dsa' ); ?></strong><br><input name="regular_price" inputmode="decimal"></label></p><?php endif; ?><p><button class="button" name="post_status" value="draft"><?php esc_html_e( 'Save read-only draft', 'dsa' ); ?></button> <button class="button button-primary" name="post_status" value="pending"><?php esc_html_e( 'Submit for review', 'dsa' ); ?></button></p></form><?php else : ?><div class="notice notice-info inline"><p><?php esc_html_e( 'Guest submissions are currently paused by the site owner. Your existing submissions remain visible below.', 'dsa' ); ?></p></div><?php endif; ?>
		<h2><?php esc_html_e( 'Your contributions', 'dsa' ); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Title', 'dsa' ); ?></th><th><?php esc_html_e( 'Type', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Sent', 'dsa' ); ?></th></tr></thead><tbody><?php if ( [] === $posts ) : ?><tr><td colspan="4"><?php esc_html_e( 'Nothing submitted yet.', 'dsa' ); ?></td></tr><?php endif; ?><?php foreach ( $posts as $post ) : ?><tr><td><?php echo esc_html( get_the_title( $post ) ); ?></td><td><?php echo esc_html( 'product' === $post->post_type ? __( 'Product proposal', 'dsa' ) : __( 'Guest article', 'dsa' ) ); ?></td><td><?php echo esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ); ?></td><td><?php echo esc_html( get_the_date( '', $post ) ); ?></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
	}

	private function pending_count(): int {
		$ids = get_users( [ 'meta_key' => self::META, 'fields' => 'ID', 'number' => 200 ] );
		$count = 0;
		foreach ( (array) $ids as $id ) if ( 'pending' === ( self::application( (int) $id )['status'] ?? '' ) ) $count++;
		return $count;
	}

	private static function verified_contact_label( int $user_id ): string {
		$factors = \DSA\PhoneKey\PhoneKey_Bridge::verified_factors( $user_id );
		$email = ! empty( $factors['email'] );
		$phone = ! empty( $factors['phone'] );
		return $email && $phone ? __( 'Email and phone', 'dsa' ) : ( $phone ? __( 'Phone', 'dsa' ) : __( 'Email', 'dsa' ) );
	}

	private static function has_admin_area_access( int $user_id, array $roles ): bool {
		if ( $user_id <= 0 ) return false;
		if ( is_multisite() && is_super_admin( $user_id ) ) return true;
		if ( array_intersect( [ Site_Owner_Service::ROLE, 'administrator', 'shop_manager', 'editor', 'author', 'contributor' ], $roles ) ) return true;
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_posts' ) || user_can( $user_id, 'edit_products' ) || user_can( $user_id, 'kiwe_guest_submit' );
	}

	private static function status_label( string $status ): string {
		return [ 'pending' => __( 'Pending approval', 'dsa' ), 'approved' => __( 'Guest', 'dsa' ), 'denied' => __( 'Denied', 'dsa' ) ][ $status ] ?? __( 'Apply for Guest', 'dsa' );
	}

	private function intent_label( string $intent ): string {
		return 'product' === sanitize_key( $intent ) ? __( 'Sell products', 'dsa' ) : __( 'Guest posts', 'dsa' );
	}
}
