<?php

namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Kiwe-only folders for native post/product tags; term identity and assignments remain native. */
final class Tag_Group_Service {
	public const OPTION = 'kiwe_tag_groups_v1';
	public const META = '_kiwe_tag_group_id';
	public const PAGE_SIZE = 20;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		if ( ! Taxonomy_App_Service::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/tag-groups', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'groups_response' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'create_response' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/tag-groups/tags', [ 'methods'=>'GET', 'callback'=>[ $this, 'tags_response' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/tag-groups/bundle', [ 'methods'=>'GET', 'callback'=>[ $this, 'bundle_response' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/tag-groups/move', [ 'methods'=>'POST', 'callback'=>[ $this, 'move_response' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/tag-groups/(?P<id>[1-9][0-9]*)', [ 'methods'=>'POST', 'callback'=>[ $this, 'rename_response' ], 'permission_callback'=>[ $this, 'permission' ] ] );
	}

	public function permission( \WP_REST_Request $request ): bool {
		if ( ! Taxonomy_App_Service::enabled() || ! is_user_logged_in() || ! in_array( $request->get_method(), [ 'GET','POST' ], true ) ) return false;
		if ( ! $this->can_kind( 'post' ) && ! $this->can_kind( 'product' ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}

	private function taxonomy( string $kind ): string { return 'post' === $kind ? 'post_tag' : ( 'product' === $kind ? 'product_tag' : '' ); }
	private function can_kind( string $kind, bool $write = false ): bool {
		$taxonomy = $this->taxonomy( $kind ); $object = $taxonomy ? get_taxonomy( $taxonomy ) : null;
		if ( ! $object || ! current_user_can( 'product' === $kind ? 'edit_products' : 'edit_posts' ) ) return false;
		return $write ? current_user_can( $object->cap->manage_terms ) && current_user_can( $object->cap->edit_terms ) : current_user_can( $object->cap->assign_terms );
	}
	/** Recovery uses the same native folder authority as ordinary editing. */
	public function can_manage_kind( string $kind ): bool { return $this->can_kind( $kind,true ); }
	private function error( string $code, string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_tag_group_' . $code, $message, [ 'status'=>$status ] ); }
	private function response( $value ): \WP_REST_Response { return is_wp_error( $value ) ? Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $value ); }
	private function store(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, static fn( $value ): bool => is_array( $value ) && isset( $value['id'], $value['kind'], $value['name'] ) && in_array( $value['kind'], [ 'post','product' ], true ) ) ) : [];
	}
	/** Small native owner projection for authoring and explicitly bound public tags. No REST/edit grant. */
	public function dynamic_groups(): array {
		$out = [];
		foreach ( array_slice( $this->store(), 0, 100 ) as $group ) {
			$id = absint( $group['id'] );
			if ( ! $id || isset( $out[ $id ] ) || ! taxonomy_exists( $this->taxonomy( $group['kind'] ) ) ) continue;
			$out[ $id ] = [ 'id'=>$id, 'kind'=>$group['kind'], 'name'=>sanitize_text_field( (string) $group['name'] ), 'taxonomy'=>$this->taxonomy( $group['kind'] ) ];
		}
		return $out;
	}
	private function project( array $group ): array {
		return [ 'id'=>(int) $group['id'], 'kind'=>(string) $group['kind'], 'name'=>(string) $group['name'], 'expected'=>hash( 'sha256', wp_json_encode( [ (int) $group['id'], (string) $group['kind'], (string) $group['name'] ] ) ) ];
	}
	private function find( array $groups, int $id, string $kind ) {
		foreach ( $groups as $group ) if ( (int) $group['id'] === $id && $group['kind'] === $kind ) return $group;
		return $this->error( 'missing', 'This tag category no longer exists. Refresh and review.', 409 );
	}
	private function input( $value ): array { return is_array( $value ) ? $value : []; }
	private function name( $value ) {
		$name = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		$length = preg_match_all( '/./us', $name );
		return '' !== $name && false !== $length && $length <= 100 ? $name : $this->error( 'name', 'Enter a category name of 1 to 100 characters.' );
	}
	private function kind( array $input, bool $write = false ) {
		$kind = sanitize_key( (string) ( $input['kind'] ?? '' ) );
		return $this->can_kind( $kind, $write ) ? $kind : $this->error( 'forbidden', 'Tag categories are unavailable for your account.', 403 );
	}

	public function groups_response( \WP_REST_Request $request ): \WP_REST_Response {
		$kind = $this->kind( $request->get_query_params() ); if ( is_wp_error( $kind ) ) return $this->response( $kind );
		return $this->response( [ 'kind'=>$kind, 'groups'=>array_values( array_map( [ $this, 'project' ], array_filter( $this->store(), static fn( $group ): bool => $group['kind'] === $kind ) ) ) ] );
	}
	public function create_response( \WP_REST_Request $request ): \WP_REST_Response {
		$input = $this->input( $request->get_json_params() ); $kind = $this->kind( $input, true ); if ( is_wp_error( $kind ) ) return $this->response( $kind );
		$name = $this->name( $input['name'] ?? null ); if ( is_wp_error( $name ) ) return $this->response( $name );
		$groups = $this->store(); if ( count( $groups ) >= 100 ) return $this->response( $this->error( 'limit', 'This workspace has reached its tag category limit.' ) );
		foreach ( $groups as $group ) if ( $group['kind'] === $kind && 0 === strcasecmp( $group['name'], $name ) ) return $this->response( $this->error( 'duplicate', 'This tag category already exists.' ) );
		$id = $groups ? max( array_map( static fn( $group ): int => (int) $group['id'], $groups ) ) + 1 : 1;
		$groups[] = [ 'id'=>$id, 'kind'=>$kind, 'name'=>$name ];
		if ( ! update_option( self::OPTION, $groups, false ) ) return $this->response( $this->error( 'write', 'The category save could not be confirmed. Refresh before retrying.', 409 ) );
		$after = $this->find( $this->store(), $id, $kind );
		return $this->response( is_wp_error( $after ) || $after['name'] !== $name ? $this->error( 'readback', 'The new category could not be confirmed. Refresh before retrying.', 409 ) : $this->project( $after ) );
	}
	public function rename_response( \WP_REST_Request $request ): \WP_REST_Response {
		$input = $this->input( $request->get_json_params() ); $kind = $this->kind( $input, true ); if ( is_wp_error( $kind ) ) return $this->response( $kind );
		$id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ); $groups = $this->store(); $before = $this->find( $groups, $id, $kind ); if ( is_wp_error( $before ) ) return $this->response( $before );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $this->project( $before )['expected'], $input['expected'] ) ) return $this->response( $this->error( 'stale', 'This category changed. Refresh before saving.', 409 ) );
		$name = $this->name( $input['name'] ?? null ); if ( is_wp_error( $name ) ) return $this->response( $name );
		foreach ( $groups as $group ) if ( (int) $group['id'] !== $id && $group['kind'] === $kind && 0 === strcasecmp( $group['name'], $name ) ) return $this->response( $this->error( 'duplicate', 'This tag category already exists.' ) );
		if ( $name === $before['name'] ) return $this->response( $this->project( $before ) );
		foreach ( $groups as &$group ) if ( (int) $group['id'] === $id && $group['kind'] === $kind ) $group['name'] = $name; unset( $group );
		if ( ! update_option( self::OPTION, $groups, false ) ) return $this->response( $this->error( 'write', 'The category rename could not be confirmed. Refresh before retrying.', 409 ) );
		$after = $this->find( $this->store(), $id, $kind );
		return $this->response( is_wp_error( $after ) || $after['name'] !== $name ? $this->error( 'readback', 'The category rename could not be confirmed. Refresh before retrying.', 409 ) : $this->project( $after ) );
	}
	public function tags_response( \WP_REST_Request $request ): \WP_REST_Response {
		$input = $request->get_query_params(); $kind = $this->kind( $input ); if ( is_wp_error( $kind ) ) return $this->response( $kind );
		$id = max( 0, (int) ( $input['groupId'] ?? 0 ) );
		if ( $id && is_wp_error( $this->find( $this->store(), $id, $kind ) ) ) return $this->response( $this->error( 'missing', 'This tag category no longer exists.', 409 ) );
		$page = max( 1, min( 10000, (int) ( $input['page'] ?? 1 ) ) );
		$search = Task_Workspace_UI::text( $input['search'] ?? '', 80 );
		$args = [ 'taxonomy'=>$this->taxonomy( $kind ), 'hide_empty'=>false, 'orderby'=>'name', 'order'=>'ASC', 'number'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'search'=>$search, 'meta_query'=>[ [ 'key'=>self::META, 'compare'=>$id ? '=' : 'NOT EXISTS', ...( $id ? [ 'value'=>(string) $id ] : [] ) ] ] ];
		$terms = get_terms( $args ); if ( is_wp_error( $terms ) ) return $this->response( $this->error( 'list', 'Tags could not be loaded. Try again.', 500 ) );
		return $this->response( [ 'kind'=>$kind, 'groupId'=>$id, 'page'=>$page, 'hasNext'=>count( $terms ) > self::PAGE_SIZE, 'items'=>array_map( static fn( $term ): array => [ 'id'=>(int) $term->term_id, 'name'=>(string) $term->name, 'count'=>(int) $term->count ], array_slice( $terms, 0, self::PAGE_SIZE ) ) ] );
	}
	/** Resolve a folder at selection time; never trust cached client IDs as a complete bundle. */
	public function bundle_response( \WP_REST_Request $request ): \WP_REST_Response {
		$input = $request->get_query_params(); $kind = $this->kind( $input ); if ( is_wp_error( $kind ) ) return $this->response( $kind );
		$id = Task_Workspace_UI::id( $input['groupId'] ?? 0 );
		if ( ! $id || is_wp_error( $this->find( $this->store(), $id, $kind ) ) ) return $this->response( $this->error( 'missing', 'This tag category no longer exists. Refresh and review.', 409 ) );
		$terms = get_terms( [ 'taxonomy'=>$this->taxonomy( $kind ), 'hide_empty'=>false, 'number'=>101, 'fields'=>'ids', 'meta_query'=>[ [ 'key'=>self::META, 'value'=>(string) $id, 'compare'=>'=' ] ] ] );
		if ( is_wp_error( $terms ) ) return $this->response( $this->error( 'list', 'The category could not be read. Try again.', 500 ) );
		if ( count( $terms ) > 100 ) return $this->response( $this->error( 'large', 'This category has more than 100 tags. Select its tags individually.' ) );
		return $this->response( [ 'kind'=>$kind, 'groupId'=>$id, 'tagIds'=>array_values( array_map( 'intval', $terms ) ) ] );
	}
	public function move_response( \WP_REST_Request $request ): \WP_REST_Response {
		$input = $this->input( $request->get_json_params() ); $kind = $this->kind( $input, true ); if ( is_wp_error( $kind ) ) return $this->response( $kind );
		$source = max( 0, (int) ( $input['sourceId'] ?? -1 ) ); $target = max( 0, (int) ( $input['targetId'] ?? -1 ) );
		$groups = $this->store();
		if ( $source === $target || ( $source && is_wp_error( $this->find( $groups, $source, $kind ) ) ) || ( $target && is_wp_error( $this->find( $groups, $target, $kind ) ) ) ) return $this->response( $this->error( 'target', 'Choose a different existing tag category.' ) );
		$ids = $input['tagIds'] ?? null;
		if ( ! is_array( $ids ) || ! $ids || count( $ids ) > self::PAGE_SIZE || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) ) return $this->response( $this->error( 'selection', 'Choose 1 to 20 tags on this page.' ) );
		foreach ( $ids as $id ) if ( ! is_int( $id ) || $id < 1 ) return $this->response( $this->error( 'selection', 'The selected tags are invalid.' ) );
		$taxonomy = $this->taxonomy( $kind ); $out = [ 'requested'=>count( $ids ), 'moved'=>0, 'failed'=>0, 'outcomes'=>[] ];
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy ); $current = (int) get_term_meta( $id, self::META, true ); $status = 'moved';
			if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $taxonomy ) $status = 'missing';
			elseif ( ! current_user_can( 'edit_term', $id ) ) $status = 'forbidden';
			elseif ( $current !== $source ) $status = 'source_changed';
			else {
				$saved = $target ? update_term_meta( $id, self::META, $target ) : delete_term_meta( $id, self::META );
				if ( ! $saved || (int) get_term_meta( $id, self::META, true ) !== $target ) $status = 'readback_failed';
			}
			$out['outcomes'][] = [ 'tagId'=>$id, 'status'=>$status ]; $out[ 'moved' === $status ? 'moved' : 'failed' ]++;
		}
		return $this->response( $out );
	}
}
