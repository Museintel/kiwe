<?php

namespace DSA\Access;

use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Opt-in private taxonomy adapter for the client workspace.
 *
 * WordPress remains the taxonomy and relationship authority. This service
 * only exposes an allowlisted projection for post, product and configured
 * service taxonomies and delegates writes to native term APIs.
 */
final class Taxonomy_App_Service {
	public const PAGE = 'kiwe-categories';
	public const SERVICE_PAGE = 'kiwe-service-categories';
	public const PRODUCT_PAGE = 'kiwe-product-categories';
	public const TAGS_PAGE = 'kiwe-tags';
	public const PAGE_SIZE = 20;
	public const MOVE_LIMIT = 20;
	public const VISIBILITY_META = '_kiwe_search_visibility';

	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }

	public function register(): void {
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'menu' ], 35 );
			add_action( 'load-toplevel_page_' . self::SERVICE_PAGE, [ $this, 'service_document' ], 1 );
			add_action( 'load-toplevel_page_' . self::TAGS_PAGE, [ $this, 'tags_document' ], 1 );
		}
	}

	public function menu(): void {
		if ( ! self::enabled() || ! $this->definitions() ) return;
		if ( isset( $this->definitions()['product_cat'] ) ) {
			$screen = add_menu_page( __( 'Product categories', 'dsa' ), __( 'Product categories', 'dsa' ), 'manage_product_terms', self::PRODUCT_PAGE, [ $this, 'render_fallback' ], 'dashicons-category', 34 );
			add_action( 'load-' . $screen, [ $this, 'product_document' ], 1 );
		}
		if ( $this->service_definitions() ) add_menu_page( __( 'Service categories', 'dsa' ), __( 'Service categories', 'dsa' ), 'read', self::SERVICE_PAGE, [ $this, 'render_fallback' ], 'dashicons-category', 33 );
		if ( $this->tag_definitions() ) add_menu_page( __( 'Tags', 'dsa' ), __( 'Tags', 'dsa' ), 'read', self::TAGS_PAGE, [ $this, 'render_fallback' ], 'dashicons-tag', 35 );
	}

	public function category_document(): void {
		if ( ! self::enabled() || ! is_admin() || (string) ( $_GET['page'] ?? '' ) !== self::PAGE || ! $this->can_any() ) return;
		$this->document( 'categories' );
	}

	public function product_document(): void {
		if ( ! self::enabled() || ! is_admin() || (string) ( $_GET['page'] ?? '' ) !== self::PRODUCT_PAGE || ! isset( $this->definitions()['product_cat'] ) ) return;
		$this->document( 'products' );
	}

	public function service_document(): void {
		if ( ! self::enabled() || ! is_admin() || ! $this->service_definitions() ) return;
		$this->document( 'services' );
	}

	public function tags_document(): void {
		if ( ! self::enabled() || ! is_admin() || (string) ( $_GET['page'] ?? '' ) !== self::TAGS_PAGE || ! $this->tag_definitions() ) return;
		$this->document( 'tags' );
	}

	private function document( string $view ): void {
		if ( is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || ! $this->can_any() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ) return;
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		wp_enqueue_style( 'kiwe-taxonomy-app', DSA_URL . 'assets/css/taxonomy-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-taxonomy-app', DSA_URL . 'assets/js/taxonomy-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-taxonomy-app', 'KIWE_TAXONOMY_APP', [
			'endpoint'=>add_query_arg( 'kind', 'tags' === $view ? 'tags' : 'categories', rest_url( 'dsa/v1/workspace/taxonomies' ) ), 'tagGroupEndpoint'=>rest_url( 'dsa/v1/workspace/tag-groups' ), 'userGroupsEndpoint'=>rest_url( 'dsa/v1/workspace/user-groups' ), 'canManageUserGroups'=>User_Group_Service::allowed(), 'navigationEndpoint'=>rest_url( 'dsa/v1/workspace/navigation' ), 'seoEndpoint'=>rest_url( 'dsa/v1/workspace/seo' ), 'seoUrl'=>\DSA\Apps\SEO_App_Service::url(), 'nonce'=>wp_create_nonce( 'wp_rest' ),
			'canManageNavigation'=>current_user_can( 'kiwe_manage_navigation' ), 'tagKinds'=>array_values( array_map( static fn( $definition ): string => $definition['objectType'], $this->tag_definitions() ) ), 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection(), ...( class_exists( Content_Archive_App_Service::class ) ? Content_Archive_App_Service::config() : [] ), 'view'=>$view, 'initialTaxonomy'=>sanitize_key( (string) ( $_GET['taxonomy'] ?? '' ) ),
		] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( 'tags' === $view ? 'Tags · Kiwe' : 'Categories · Kiwe' ); ?></title><?php wp_print_styles( [ 'kiwe-taxonomy-app' ] ); ?></head><body class="kiwe-taxonomy-app"><div id="kiwe-taxonomy-app"><p><?php echo esc_html( 'tags' === $view ? 'Opening your tags…' : 'Opening your categories…' ); ?></p><noscript><?php esc_html_e( 'This workspace needs JavaScript. Switch off the experiment to restore the native editor.', 'dsa' ); ?></noscript></div><?php wp_print_scripts( [ 'wp-element', 'kiwe-taxonomy-app' ] ); ?></body></html><?php
		exit;
	}

	public function render_fallback(): void { wp_safe_redirect( admin_url( 'edit.php?page=' . self::PAGE ) ); exit; }

	public function routes(): void {
		if ( ! self::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/taxonomies', [ 'methods'=>'GET', 'callback'=>[ $this, 'definitions_response' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/taxonomies/(?P<taxonomy>[a-z0-9_-]+)', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'listing_response' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'create_response' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>[1-9][0-9]*)', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'detail_response' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'term_response' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>[1-9][0-9]*)/move', [ 'methods'=>'POST', 'callback'=>[ $this, 'move_response' ], 'permission_callback'=>[ $this, 'permission' ] ] );
	}

	public function permission( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! is_user_logged_in() || ! $this->can_any() || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}

	private function response( $value, int $status = 200 ): \WP_REST_Response {
		if ( is_wp_error( $value ) ) return Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? $status ) );
		return Task_Workspace_UI::response( $value, $status );
	}

	private function denied(): \WP_REST_Response { return $this->response( new \WP_Error( 'kiwe_taxonomy_forbidden', __( 'Content groups are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ), 403 ); }

	private function can_any(): bool { return (bool) ( $this->definitions() || $this->tag_definitions() ); }

	/** Tags are native terms. Kiwe groups around them live in Tag_Group_Service. */
	public function tag_definitions(): array {
		$out = [];
		foreach ( [ 'post_tag'=>'post', 'product_tag'=>'product' ] as $taxonomy=>$post_type ) {
			if ( ! \DSA\Apps\Store_App_Service::taxonomy_available( $taxonomy ) ) continue;
			$object = get_taxonomy( $taxonomy );
			if ( ! $object || ! current_user_can( $object->cap->manage_terms ) || ! current_user_can( 'product' === $post_type ? 'edit_products' : 'edit_posts' ) ) continue;
			$out[ $taxonomy ] = [ 'id'=>$taxonomy, 'label'=>sanitize_text_field( (string) ( $object->labels->name ?? $object->label ?? $taxonomy ) ), 'objectType'=>$post_type, 'postType'=>$post_type, 'hierarchical'=>false, 'canManage'=>true ];
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	public function definitions(): array {
		$out = [];
		foreach ( [ 'category', 'product_cat' ] as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object || empty( $object->show_ui ) && 'category' !== $taxonomy || ! current_user_can( $object->cap->manage_terms ?? 'manage_categories' ) ) continue;
			$post_type = 'category' === $taxonomy ? 'post' : 'product';
			if ( 'product' === $post_type && ( ! \DSA\Apps\Store_App_Service::enabled() || ! post_type_exists( 'product' ) ) ) continue;
			$out[ $taxonomy ] = [ 'id'=>$taxonomy, 'label'=>sanitize_text_field( (string) ( $object->labels->name ?? $object->label ?? $taxonomy ) ), 'objectType'=>$post_type, 'postType'=>$post_type, 'hierarchical'=>! empty( $object->hierarchical ), 'canManage'=>true ];
		}
		$profiles = new Design_Context_Profile_Service();
		$stored = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		$source = is_array( $stored ) ? sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) ) : '';
		if ( $source && isset( $profiles->service_post_types()[ $source ] ) ) {
			$source_object = get_post_type_object( $source );
			foreach ( $profiles->service_taxonomies( $source ) as $taxonomy=>$meta ) {
				$object = get_taxonomy( $taxonomy );
				if ( ! $object || ! current_user_can( $object->cap->manage_terms ?? 'manage_categories' ) || ! current_user_can( $source_object->cap->edit_posts ?? 'edit_posts' ) ) continue;
				$out[ $taxonomy ] = [ 'id'=>$taxonomy, 'label'=>$meta['label'], 'objectType'=>'service', 'postType'=>$source, 'hierarchical'=>! empty( $meta['hierarchical'] ), 'canManage'=>true ];
			}
		}
		return $out;
	}

	/** Definitions visible from the dedicated Services destination only. */
	public function service_definitions(): array {
		return array_filter( $this->definitions(), static fn( $definition ): bool => 'service' === ( $definition['objectType'] ?? '' ) );
	}

	private function definition( string $taxonomy ) {
		$taxonomy = sanitize_key( $taxonomy );
		$definitions = array_merge( $this->definitions(), $this->tag_definitions() );
		return $definitions[ $taxonomy ] ?? new \WP_Error( 'kiwe_taxonomy_missing', __( 'This content group is unavailable. Refresh and choose an existing group.', 'dsa' ) );
	}

	private function page( $value ): int { return is_scalar( $value ) ? max( 1, min( 10000, (int) $value ) ) : 1; }
	private function text( $value, int $limit = 200 ): string { return Task_Workspace_UI::text( $value, $limit ); }

	public function definitions_response( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$definitions = 'tags' === (string) $request->get_param( 'kind' ) ? $this->tag_definitions() : $this->definitions();
		foreach ( $definitions as &$definition ) $definition['archiveScope'] = \DSA\Backup\Content_Archive_Service::category_scope( $definition['id'], 'service' === $definition['objectType'] );
		unset( $definition );
		return $this->response( [ 'taxonomies'=>array_values( $definitions ) ] );
	}

	public function listing_response( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$taxonomy = $this->definition( (string) ( $request->get_url_params()['taxonomy'] ?? '' ) );
		if ( is_wp_error( $taxonomy ) ) return $this->response( $taxonomy );
		return $this->response( $this->list_terms( $taxonomy['id'], $request->get_query_params() ) );
	}

	public function detail_response( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$taxonomy = $this->definition( (string) ( $request->get_url_params()['taxonomy'] ?? '' ) ); $id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 );
		if ( is_wp_error( $taxonomy ) ) return $this->response( $taxonomy );
		return $this->response( $this->detail( $taxonomy, $id, $request->get_query_params() ) );
	}

	public function create_response( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$taxonomy = $this->definition( (string) ( $request->get_url_params()['taxonomy'] ?? '' ) ); if ( is_wp_error( $taxonomy ) ) return $this->response( $taxonomy );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		$result = $this->create_term( $taxonomy['id'], $body );
		if ( is_wp_error( $result ) ) return $this->response( $result );
		$term = get_term( $result, $taxonomy['id'] ); return $this->response( $this->project_term( $term, $taxonomy ) );
	}

	public function term_response( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$taxonomy = $this->definition( (string) ( $request->get_url_params()['taxonomy'] ?? '' ) ); $id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 );
		if ( is_wp_error( $taxonomy ) ) return $this->response( $taxonomy );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		$term = get_term( $id, $taxonomy['id'] ); if ( ! $term || is_wp_error( $term ) ) return $this->response( new \WP_Error( 'kiwe_taxonomy_missing', __( 'This content group no longer exists. Refresh and try again.', 'dsa' ) ) );
		if ( isset( $body['operation'] ) && 'menu' === $body['operation'] ) $result = $this->set_menu( $taxonomy['id'], $id, $body );
		elseif ( isset( $body['operation'] ) && 'indexing' === $body['operation'] ) $result = $this->set_indexing( $taxonomy['id'], $id, $body );
		else $result = $this->rename_term( $taxonomy['id'], $id, $body );
		if ( is_wp_error( $result ) ) return $this->response( $result, 409 );
		$after = get_term( $id, $taxonomy['id'] ); return $this->response( $this->project_term( $after, $taxonomy ) );
	}

	public function move_response( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$taxonomy = $this->definition( (string) ( $request->get_url_params()['taxonomy'] ?? '' ) ); $id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 );
		if ( is_wp_error( $taxonomy ) ) return $this->response( $taxonomy );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		$result = $this->move_objects( $taxonomy, $id, Task_Workspace_UI::id( $body['targetId'] ?? 0 ), is_array( $body['objectIds'] ?? null ) ? $body['objectIds'] : [] );
		return $this->response( $result );
	}

	private function term( string $taxonomy, int $id ) { $term = $id > 0 ? get_term( $id, $taxonomy ) : null; return $term && ! is_wp_error( $term ) && $taxonomy === $term->taxonomy ? $term : new \WP_Error( 'kiwe_taxonomy_missing', __( 'This content group no longer exists.', 'dsa' ) ); }

	private function list_terms( string $taxonomy, array $input, int $exclude = 0 ) {
		$definition = $this->definition( $taxonomy ); if ( is_wp_error( $definition ) ) return $definition;
		$page = $this->page( $input['page'] ?? 1 ); $search = $this->text( $input['search'] ?? '', 200 );
		$terms = get_terms( [ 'taxonomy'=>$taxonomy, 'hide_empty'=>false, 'orderby'=>'name', 'order'=>'ASC', 'number'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'search'=>$search, 'exclude'=>$exclude ? [ $exclude ] : [] ] );
		if ( is_wp_error( $terms ) ) return new \WP_Error( 'kiwe_taxonomy_list', __( 'Content groups could not be loaded. Try again.', 'dsa' ) );
		return [ 'items'=>array_values( array_map( fn( $term ): array => $this->project_term( $term, $definition ), array_slice( $terms, 0, self::PAGE_SIZE ) ) ), 'page'=>$page, 'has_next'=>count( $terms ) > self::PAGE_SIZE, 'search'=>$search, 'taxonomy'=>$definition, 'menus'=>class_exists( Navigation_Workspace_Service::class ) ? ( new Navigation_Workspace_Service() )->dsa_menus() : [] ];
	}

	private function detail( array $definition, int $id, array $input = [] ) {
		$term = $this->term( $definition['id'], $id ); if ( is_wp_error( $term ) ) return $term;
		$page = $this->page( $input['objectsPage'] ?? 1 );
		$target_page = $this->page( $input['targetsPage'] ?? 1 );
		$target_search = $this->text( $input['targetSearch'] ?? '', 200 );
		$records = $this->list_objects( $definition, $id, $page ); if ( is_wp_error( $records ) ) return $records;
		$targets = $this->list_terms( $definition['id'], [ 'page'=>$target_page, 'search'=>$target_search ], $id ); if ( is_wp_error( $targets ) ) return $targets;
		return [ 'term'=>$this->project_term( $term, $definition ), 'objects'=>$records['items'], 'objects_page'=>$page, 'objects_has_next'=>$records['has_next'], 'targets'=>$targets['items'], 'targets_page'=>$target_page, 'targets_search'=>$target_search, 'targets_has_next'=>$targets['has_next'] ];
	}

	private function project_term( $term, array $definition ): array {
		$visibility = \DSA\Onboarding\SEO_Context_Service::term_noindex( (int) $term->term_id ) ? 'secondary' : 'primary';
		$url = get_term_link( $term );
		return [ 'id'=>(int) $term->term_id, 'taxonomy'=>$definition['id'], 'name'=>sanitize_text_field( (string) $term->name ), 'slug'=>sanitize_title( (string) $term->slug ), 'parent'=>(int) $term->parent, 'description'=>sanitize_textarea_field( (string) $term->description ), 'canExport'=>class_exists( \DSA\Backup\Content_Archive_Service::class ) && \DSA\Backup\Content_Archive_Service::can_export( \DSA\Backup\Content_Archive_Service::category_scope( $term->taxonomy, 'service' === $definition['objectType'] ), (int) $term->term_id ), 'count'=>(int) $term->count, 'indexable'=>'secondary' !== $visibility, 'objectType'=>$definition['objectType'], 'label'=>$definition['label'], 'viewUrl'=>is_wp_error( $url ) ? '' : esc_url_raw( (string) $url ), 'expected'=>self::fingerprint( $term ) ];
	}

	private function project_object( $post ): array {
		return [ 'id'=>(int) $post->ID, 'title'=>sanitize_text_field( (string) $post->post_title ), 'status'=>sanitize_key( (string) $post->post_status ), 'type'=>sanitize_key( (string) $post->post_type ), 'excerpt'=>sanitize_textarea_field( (string) $post->post_excerpt ), 'modifiedGmt'=>sanitize_text_field( (string) $post->post_modified_gmt ), 'expected'=>hash( 'sha256', wp_json_encode( [ (int) $post->ID, $post->post_title, $post->post_status, $post->post_modified_gmt ] ) ) ];
	}

	private function list_objects( array $definition, int $term_id, int $page ) {
		$query = new \WP_Query( [ 'post_type'=>$definition['postType'], 'post_status'=>[ 'publish','future','draft','pending','private' ], 'posts_per_page'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'orderby'=>'ID', 'order'=>'DESC', 'no_found_rows'=>true, 'ignore_sticky_posts'=>true, 'update_post_meta_cache'=>false, 'update_post_term_cache'=>false, 'tax_query'=>[ [ 'taxonomy'=>$definition['id'], 'field'=>'term_id', 'terms'=>[ $term_id ], 'include_children'=>false ] ] ] );
		$items = [];
		foreach ( array_slice( is_array( $query->posts ?? null ) ? $query->posts : [], 0, self::PAGE_SIZE ) as $post ) if ( $post && current_user_can( 'edit_post', (int) $post->ID ) ) $items[] = $this->project_object( $post );
		return [ 'items'=>$items, 'page'=>$page, 'has_next'=>count( $query->posts ?? [] ) > self::PAGE_SIZE ];
	}

	private function name( array $input ) {
		$name = $this->text( $input['name'] ?? '', 200 ); $length = preg_match_all( '/./us', $name );
		return '' !== $name && false !== $length ? $name : new \WP_Error( 'kiwe_taxonomy_name', __( 'Enter a name of 1 to 200 characters.', 'dsa' ) );
	}

	private function create_term( string $taxonomy, array $input ) {
		$definition = $this->definition( $taxonomy ); if ( is_wp_error( $definition ) ) return $definition;
		$object = get_taxonomy( $taxonomy ); if ( ! $object || ! current_user_can( $object->cap->edit_terms ?? 'manage_categories' ) ) return new \WP_Error( 'kiwe_taxonomy_forbidden', __( 'Your account cannot create this group.', 'dsa' ), [ 'status'=>403 ] );
		$name = $this->name( $input ); if ( is_wp_error( $name ) ) return $name;
		$parent = Task_Workspace_UI::id( $input['parent'] ?? 0 );
		$result = wp_insert_term( $name, $taxonomy, $parent ? [ 'parent'=>$parent ] : [] );
		return is_wp_error( $result ) ? new \WP_Error( 'kiwe_taxonomy_create', __( 'The group could not be created. Check whether that name already exists.', 'dsa' ) ) : (int) $result['term_id'];
	}

	public static function fingerprint( $term ): string { return hash( 'sha256', wp_json_encode( [ (int) $term->term_id, $term->name, $term->slug, (int) $term->parent, $term->description, (string) get_term_meta( (int) $term->term_id, self::VISIBILITY_META, true ), defined( 'RANK_MATH_VERSION' ) ? 'rank_math' : 'kiwe', get_term_meta( (int) $term->term_id, 'rank_math_robots', true ) ] ) ); }

	private function rename_term( string $taxonomy, int $id, array $input ) {
		$definition = $this->definition( $taxonomy ); if ( is_wp_error( $definition ) ) return $definition;
		$object = get_taxonomy( $taxonomy ); $term = $this->term( $taxonomy, $id );
		if ( is_wp_error( $term ) || ! $object || ! current_user_can( $object->cap->edit_terms ?? 'manage_categories' ) || ! current_user_can( 'edit_term', $id ) ) return new \WP_Error( 'kiwe_taxonomy_forbidden', __( 'Your account cannot rename this group.', 'dsa' ), [ 'status'=>403 ] );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::fingerprint( $term ), $input['expected'] ) ) return new \WP_Error( 'kiwe_taxonomy_stale', __( 'This group changed since it opened. Refresh and review it before saving.', 'dsa' ), [ 'status'=>409 ] );
		$name = $this->name( $input ); if ( is_wp_error( $name ) ) return $name;
		$before = clone $term; $result = wp_update_term( $id, $taxonomy, [ 'name'=>$name ] ); $after = is_wp_error( $result ) ? $result : $this->term( $taxonomy, $id );
		$before->name = $name;
		return is_wp_error( $after ) || ! hash_equals( self::fingerprint( $before ), self::fingerprint( $after ) ) ? new \WP_Error( 'kiwe_taxonomy_save', __( 'The group save could not be confirmed. Refresh and review it.', 'dsa' ), [ 'status'=>409 ] ) : true;
	}

	private function set_indexing( string $taxonomy, int $id, array $input ) {
		$term = $this->term( $taxonomy, $id ); $object = get_taxonomy( $taxonomy );
		if ( is_wp_error( $term ) || ! $object || ! current_user_can( $object->cap->edit_terms ?? 'manage_categories' ) || ! current_user_can( 'edit_term', $id ) ) return new \WP_Error( 'kiwe_taxonomy_forbidden', __( 'Your account cannot change search visibility.', 'dsa' ), [ 'status'=>403 ] );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::fingerprint( $term ), $input['expected'] ) ) return new \WP_Error( 'kiwe_taxonomy_stale', __( 'This group changed since it opened. Refresh before changing search visibility.', 'dsa' ), [ 'status'=>409 ] );
		$indexable = $input['indexable'] ?? null; if ( ! is_bool( $indexable ) ) return new \WP_Error( 'kiwe_taxonomy_indexing', __( 'Choose whether this group can appear in search.', 'dsa' ) );
		return \DSA\Onboarding\SEO_Context_Service::set_term_indexing( $id, ! $indexable );
	}

	private function set_menu( string $taxonomy, int $id, array $input ) {
		$term = $this->term( $taxonomy, $id );
		if ( isset( $this->tag_definitions()[ $taxonomy ] ) || is_wp_error( $term ) || ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_taxonomy_forbidden', __( 'Your account cannot change this menu.', 'dsa' ), [ 'status'=>403 ] );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::fingerprint( $term ), $input['expected'] ) ) return new \WP_Error( 'kiwe_taxonomy_stale', __( 'This group changed. Reload before editing its menu.', 'dsa' ), [ 'status'=>409 ] );
		$menu_id = $input['menuId'] ?? null; $included = $input['included'] ?? null;
		if ( ! is_int( $menu_id ) || ! is_bool( $included ) || ! is_string( $input['menuExpected'] ?? null ) ) return new \WP_Error( 'kiwe_taxonomy_menu', __( 'Choose a configured DSA menu.', 'dsa' ) );
		$navigation = new Navigation_Workspace_Service(); $menu = null;
		foreach ( $navigation->dsa_menus() as $candidate ) if ( $candidate['id'] === $menu_id ) $menu = $candidate;
		if ( ! $menu || ! hash_equals( $menu['expected'], $input['menuExpected'] ) ) return new \WP_Error( 'kiwe_taxonomy_menu', __( 'The DSA menu changed. Reload before saving.', 'dsa' ), [ 'status'=>409 ] );
		$item = null; foreach ( $menu['items'] as $candidate ) if ( 'taxonomy' === $candidate['type'] && $taxonomy === $candidate['object'] && $id === $candidate['objectId'] ) $item = $candidate;
		if ( $included === (bool) $item ) return true;
		$result = $navigation->change( $menu_id, [ 'operation'=>$included ? 'add' : 'remove', 'source_kind'=>$taxonomy, 'source_id'=>$id, 'parent_id'=>0, 'item_id'=>$item['id'] ?? 0, 'expected'=>$menu['expected'] ] );
		if ( is_wp_error( $result ) ) return $result;
		foreach ( $navigation->dsa_menus() as $after ) if ( $after['id'] === $menu_id ) {
			$present = false; foreach ( $after['items'] as $candidate ) if ( 'taxonomy' === $candidate['type'] && $taxonomy === $candidate['object'] && $id === $candidate['objectId'] ) $present = true;
			if ( $present === $included ) return true;
		}
		return new \WP_Error( 'kiwe_taxonomy_menu', __( 'The menu change could not be confirmed. Reload and review.', 'dsa' ), [ 'status'=>409 ] );
	}

	private function move_objects( array $definition, int $source_id, int $target_id, array $object_ids ) {
		$taxonomy = $definition['id']; $object = get_taxonomy( $taxonomy );
		if ( ! $object || ! current_user_can( $object->cap->assign_terms ?? 'edit_posts' ) ) return new \WP_Error( 'kiwe_taxonomy_forbidden', __( 'Your account cannot move items in this group.', 'dsa' ), [ 'status'=>403 ] );
		$source = $this->term( $taxonomy, $source_id ); $target = $this->term( $taxonomy, $target_id );
		if ( $source_id === $target_id || is_wp_error( $source ) || is_wp_error( $target ) ) return new \WP_Error( 'kiwe_taxonomy_target', __( 'Choose a different existing destination group.', 'dsa' ) );
		if ( ! $object_ids || count( $object_ids ) > self::MOVE_LIMIT ) return new \WP_Error( 'kiwe_taxonomy_selection', __( 'Choose between 1 and 20 items to move.', 'dsa' ) );
		$raw_ids = array_map( static fn( $value ): int => Task_Workspace_UI::id( $value ), $object_ids );
		if ( in_array( 0, $raw_ids, true ) ) return new \WP_Error( 'kiwe_taxonomy_selection', __( 'The selection contains an invalid item. Refresh and select again.', 'dsa' ) );
		$ids = array_values( array_unique( $raw_ids ) );
		$result = [ 'requested'=>count( $ids ), 'moved'=>0, 'failed'=>0, 'outcomes'=>[] ];
		foreach ( $ids as $id ) {
			$post = get_post( $id ); $code = 'moved';
			if ( ! $post || $definition['postType'] !== get_post_type( $id ) || ! in_array( $post->post_status, [ 'publish','future','draft','pending','private' ], true ) ) $code = 'unavailable';
			elseif ( ! current_user_can( 'edit_post', $id ) ) $code = 'forbidden';
			else {
				$before = wp_get_object_terms( $id, $taxonomy, [ 'fields'=>'ids' ] );
				if ( is_wp_error( $before ) ) $code = 'read_failed';
				elseif ( ! in_array( $source_id, array_map( 'intval', $before ), true ) ) $code = 'source_changed';
				else {
					$added = wp_add_object_terms( $id, [ $target_id ], $taxonomy ); $after_add = is_wp_error( $added ) ? $added : wp_get_object_terms( $id, $taxonomy, [ 'fields'=>'ids' ] );
					if ( is_wp_error( $after_add ) || ! in_array( $target_id, array_map( 'intval', $after_add ), true ) ) $code = 'target_failed';
					else { $removed = wp_remove_object_terms( $id, [ $source_id ], $taxonomy ); $after = wp_get_object_terms( $id, $taxonomy, [ 'fields'=>'ids' ] ); if ( is_wp_error( $removed ) || false === $removed || is_wp_error( $after ) || in_array( $source_id, array_map( 'intval', $after ), true ) || ! in_array( $target_id, array_map( 'intval', $after ), true ) ) $code = 'incomplete'; }
				}
			}
			$result['outcomes'][] = [ 'objectId'=>$id, 'status'=>$code ]; $result[ 'moved' === $code ? 'moved' : 'failed' ]++;
		}
		return $result;
	}
}
