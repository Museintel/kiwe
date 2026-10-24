<?php

namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded post editor over WordPress posts, categories and native capabilities. */
final class Post_Workspace_Service {
	public const PAGE = 'kiwe-posts';
	private const PAGE_SIZE = 12;
	private const MAX_PAGE = 10000;
	private const STATUSES = [ 'draft', 'pending', 'publish', 'private', 'future' ];

	public function register(): void {
		if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE ) ( new Post_App_Service() )->register();
	}

	public static function url( int $id = 0 ): string {
		$args = [ 'page' => self::PAGE ];
		if ( $id > 0 ) $args['post_id'] = $id;
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }

	public function allowed(): bool { return is_user_logged_in() && current_user_can( 'edit_posts' ); }

	private function can_create(): bool { return $this->allowed() && current_user_can( 'create_posts' ); }

	private function can_manage( int $id ): bool {
		$post = $id > 0 ? get_post( $id ) : null;
		return $this->allowed() && $post && 'post' === $post->post_type && ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) && current_user_can( 'edit_post', $id );
	}

	private function guest_write_guard( int $id ) {
		if ( $id > 0 && class_exists( Guest_Contribution_Service::class ) && Guest_Contribution_Service::is_submission( $id ) ) return new \WP_Error( 'kiwe_post_guest_review', __( 'Review Guest submissions in the Guest workspace.', 'dsa' ), [ 'status'=>409 ] );
		return null;
	}

	private function navigation( array $input ): array {
		$search = is_string( $input['search'] ?? null ) ? Task_Workspace_UI::text( str_replace( '*', '', $input['search'] ), 80 ) : '';
		$page = is_scalar( $input['page'] ?? null ) ? (int) $input['page'] : 1;
		$status = is_string( $input['status'] ?? null ) && in_array( $input['status'], self::STATUSES, true ) ? $input['status'] : 'all';
		$sort = is_string( $input['sort'] ?? null ) && in_array( $input['sort'], [ 'modified_desc','modified_asc','date_desc','date_asc','title_asc','title_desc' ], true ) ? $input['sort'] : 'modified_desc';
		$author = current_user_can( 'edit_others_posts' ) && is_scalar( $input['author'] ?? null ) ? absint( $input['author'] ) : 0;
		return [ 'page'=>max( 1, min( self::MAX_PAGE, $page ) ), 'search'=>$search, 'status'=>$status, 'sort'=>$sort, 'author'=>$author ];
	}

	public function listing( array $input = [] ): array {
		$nav = $this->navigation( $input );
		$result = [ 'navigation'=>$nav, 'posts'=>[], 'categories'=>$this->categories(), 'tags'=>$this->tags(), 'menus'=>( new Navigation_Workspace_Service() )->dsa_menus(), 'canCreateCategories'=>$this->can_create_categories(), 'canCreateTags'=>$this->can_create_tags(), 'seoOwner'=>$this->seo_owner(), 'has_next'=>false ];
		if ( ! $this->allowed() ) return $result;
		[ $orderby, $order ] = explode( '_', $nav['sort'], 2 );
		$args = [
			'post_type'=>'post', 'post_status'=> 'all' === $nav['status'] ? self::STATUSES : [ $nav['status'] ],
			'posts_per_page'=>self::PAGE_SIZE + 1, 'offset'=>( $nav['page'] - 1 ) * self::PAGE_SIZE,
			'orderby'=>[ $orderby=>strtoupper( $order ), 'ID'=>strtoupper( $order ) ], 's'=>$nav['search'], 'no_found_rows'=>true,
		];
		if ( ! current_user_can( 'edit_others_posts' ) ) $args['author'] = get_current_user_id();
		elseif ( $nav['author'] ) $args['author'] = $nav['author'];
		$posts = get_posts( $args );
		$result['has_next'] = count( $posts ) > self::PAGE_SIZE && $nav['page'] < self::MAX_PAGE;
		foreach ( array_slice( $posts, 0, self::PAGE_SIZE ) as $post ) {
			$projection = $this->post( (int) $post->ID );
			if ( ! is_wp_error( $projection ) ) $result['posts'][] = $projection;
		}
		return $result;
	}

	/** Bounded author lookup is separate from the ordinary list request. */
	public function authors( array $input ) {
		if ( ! $this->allowed() || ! current_user_can( 'edit_others_posts' ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'Author search is unavailable for your account.', 'dsa' ), [ 'status'=>403 ] );
		$search = is_string( $input['search'] ?? null ) ? Task_Workspace_UI::text( str_replace( '*', '', $input['search'] ), 80 ) : '';
		if ( strlen( $search ) < 2 ) return [ 'authors'=>[] ];
		$users = get_users( [ 'search'=>'*' . $search . '*', 'search_columns'=>[ 'display_name','user_login' ], 'number'=>20, 'fields'=>[ 'ID','display_name' ], 'orderby'=>'display_name', 'order'=>'ASC' ] );
		$authors = [];
		foreach ( $users as $user ) if ( user_can( $user->ID, 'edit_posts' ) || user_can( $user->ID, 'kiwe_guest_submit' ) ) $authors[] = [ 'id'=>(int) $user->ID, 'name'=>sanitize_text_field( (string) $user->display_name ) ];
		return [ 'authors'=>$authors ];
	}

	private function categories(): array {
		$terms = get_categories( [ 'hide_empty'=>false, 'number'=>100, 'orderby'=>'name', 'order'=>'ASC' ] );
		$out = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) if ( $term instanceof \WP_Term || is_object( $term ) ) $out[] = [ 'id'=>(int) $term->term_id, 'name'=>sanitize_text_field( (string) $term->name ) ];
		return $out;
	}

	private function tags(): array {
		$terms = get_tags( [ 'hide_empty'=>false, 'number'=>100, 'orderby'=>'name', 'order'=>'ASC' ] );
		$out = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) if ( $term instanceof \WP_Term || is_object( $term ) ) $out[] = [ 'id'=>(int) $term->term_id, 'name'=>sanitize_text_field( (string) $term->name ) ];
		return $out;
	}

	/** Search native terms on demand so large publication taxonomies stay usable. */
	public function terms( array $input ) {
		if ( ! $this->allowed() ) return new \WP_Error( 'kiwe_post_forbidden', __( 'Post terms are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] );
		$taxonomy = is_string( $input['taxonomy'] ?? null ) ? sanitize_key( $input['taxonomy'] ) : '';
		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) return new \WP_Error( 'kiwe_post_terms', __( 'Choose categories or tags.', 'dsa' ), [ 'status'=>400 ] );
		$search = is_string( $input['search'] ?? null ) ? Task_Workspace_UI::text( str_replace( '*', '', $input['search'] ), 80 ) : '';
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id && ! $this->can_manage( $post_id ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'This post is unavailable in your workspace.', 'dsa' ), [ 'status'=>403 ] );
		$terms = get_terms( [ 'taxonomy'=>$taxonomy, 'hide_empty'=>false, 'number'=>60, 'orderby'=>'name', 'order'=>'ASC', 'search'=>$search ] );
		if ( is_wp_error( $terms ) ) return new \WP_Error( 'kiwe_post_terms', __( 'WordPress could not read those terms. Try again.', 'dsa' ), [ 'status'=>503 ] );
		$selected = $post_id ? ( 'category' === $taxonomy ? wp_get_post_categories( $post_id, [ 'fields'=>'ids' ] ) : wp_get_post_terms( $post_id, $taxonomy, [ 'fields'=>'ids' ] ) ) : [];
		if ( is_wp_error( $selected ) ) return new \WP_Error( 'kiwe_post_terms', __( 'WordPress could not read selected terms. Try again.', 'dsa' ), [ 'status'=>503 ] );
		$ids = array_slice( array_values( array_unique( array_map( 'absint', (array) $selected ) ) ), 0, 20 );
		if ( $ids ) { $assigned = get_terms( [ 'taxonomy'=>$taxonomy, 'hide_empty'=>false, 'include'=>$ids, 'number'=>20 ] ); if ( is_wp_error( $assigned ) ) return new \WP_Error( 'kiwe_post_terms', __( 'WordPress could not read selected terms. Try again.', 'dsa' ), [ 'status'=>503 ] ); $terms = array_merge( (array) $assigned, (array) $terms ); }
		$out = [];
		foreach ( array_slice( (array) $terms, 0, 80 ) as $term ) if ( $term instanceof \WP_Term || is_object( $term ) ) $out[ (int) $term->term_id ] = [ 'id'=>(int) $term->term_id, 'name'=>sanitize_text_field( (string) $term->name ) ];
		return [ 'taxonomy'=>$taxonomy, 'terms'=>array_values( $out ) ];
	}

	/** Create or reuse one native term without submitting or leaving the post editor. */
	public function create_term( array $input ) {
		if ( ! $this->allowed() ) return new \WP_Error( 'kiwe_post_forbidden', __( 'Post terms are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] );
		if ( array_diff( array_keys( $input ), [ 'taxonomy','name' ] ) || ! is_string( $input['taxonomy'] ?? null ) || ! is_string( $input['name'] ?? null ) ) return new \WP_Error( 'kiwe_post_term_input', __( 'Enter one category or tag name.', 'dsa' ), [ 'status'=>400 ] );
		$taxonomy = sanitize_key( $input['taxonomy'] );
		if ( ! in_array( $taxonomy, [ 'category','post_tag' ], true ) ) return new \WP_Error( 'kiwe_post_terms', __( 'Choose categories or tags.', 'dsa' ), [ 'status'=>400 ] );
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || empty( $object->cap->manage_terms ) || ! current_user_can( $object->cap->manage_terms ) ) return new \WP_Error( 'kiwe_post_term_create', __( 'Your role can use existing terms but cannot create this one.', 'dsa' ), [ 'status'=>403 ] );
		$name = sanitize_text_field( $input['name'] );
		if ( '' === $name || preg_match_all( '/./us', $name ) > 80 ) return new \WP_Error( 'kiwe_post_term_name', __( 'Enter one name of at most 80 characters.', 'dsa' ), [ 'status'=>400 ] );
		$existing = term_exists( $name, $taxonomy );
		$created = false;
		if ( $existing ) $id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		else {
			$result = wp_insert_term( $name, $taxonomy );
			if ( is_wp_error( $result ) ) {
				if ( 'term_exists' !== $result->get_error_code() || (int) $result->get_error_data() < 1 ) return new \WP_Error( 'kiwe_post_term_write', __( 'WordPress could not create that term. Reload and review.', 'dsa' ), [ 'status'=>409 ] );
				$id = (int) $result->get_error_data();
			} else { $id = (int) $result['term_id']; $created = true; }
		}
		$term = get_term( $id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || (int) $term->term_id !== $id ) return new \WP_Error( 'kiwe_post_term_readback', __( 'The term save could not be confirmed. Reload and review.', 'dsa' ), [ 'status'=>409 ] );
		return [ 'taxonomy'=>$taxonomy, 'created'=>$created, 'term'=>[ 'id'=>$id, 'name'=>sanitize_text_field( (string) $term->name ) ] ];
	}

	private function can_create_tags(): bool {
		$taxonomy = get_taxonomy( 'post_tag' );
		return $taxonomy && ! empty( $taxonomy->cap->manage_terms ) && current_user_can( $taxonomy->cap->manage_terms );
	}

	private function can_create_categories(): bool {
		$taxonomy = get_taxonomy( 'category' );
		return $taxonomy && ! empty( $taxonomy->cap->manage_terms ) && current_user_can( $taxonomy->cap->manage_terms );
	}

	private function seo_owner(): string { return defined( 'RANK_MATH_VERSION' ) ? 'rank_math' : 'kiwe'; }

	private function seo( int $id ): array {
		if ( 'rank_math' === $this->seo_owner() ) return [ 'title'=>(string) get_post_meta( $id, 'rank_math_title', true ), 'description'=>(string) get_post_meta( $id, 'rank_math_description', true ), 'focusKeyword'=>(string) get_post_meta( $id, 'rank_math_focus_keyword', true ) ];
		$phrases = get_post_meta( $id, '_kiwe_search_phrases', true );
		return [ 'title'=>(string) get_post_meta( $id, '_kiwe_seo_title', true ), 'description'=>(string) get_post_meta( $id, '_kiwe_seo_description', true ), 'focusKeyword'=>implode( ', ', is_array( $phrases ) ? $phrases : [] ) ];
	}

	private static function state( \WP_Post $post ): array {
		$categories = function_exists( 'wp_get_post_categories' ) ? array_map( 'intval', (array) wp_get_post_categories( $post->ID, [ 'fields'=>'ids' ] ) ) : [];
		sort( $categories, SORT_NUMERIC );
		$tags = function_exists( 'wp_get_post_terms' ) ? wp_get_post_terms( $post->ID, 'post_tag', [ 'fields'=>'ids' ] ) : [];
		$tags = is_wp_error( $tags ) ? [] : array_map( 'intval', (array) $tags );
		sort( $tags, SORT_NUMERIC );
		return [ (int) $post->ID, (string) $post->post_title, (string) $post->post_content, (string) $post->post_status, (string) $post->post_date, (string) $post->post_date_gmt, (string) $post->post_modified_gmt, $categories, $tags, (int) get_post_thumbnail_id( $post->ID ), ( new self() )->seo( (int) $post->ID ), (string) $post->post_excerpt ];
	}

	public static function fingerprint( \WP_Post $post ): string { return hash( 'sha256', wp_json_encode( self::state( $post ) ) ); }

	public function post( int $id ) {
		if ( ! $this->can_manage( $id ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'This post is unavailable in your workspace.', 'dsa' ), [ 'status'=>403 ] );
		$post = get_post( $id );
		$author = get_userdata( (int) $post->post_author );
		$categories = function_exists( 'wp_get_post_categories' ) ? array_map( 'intval', (array) wp_get_post_categories( $id, [ 'fields'=>'ids' ] ) ) : [];
		return [
			'id'=>(int) $post->ID, 'title'=>(string) $post->post_title, 'content'=>(string) $post->post_content,
			'status'=>(string) $post->post_status, 'authorId'=>(int) $post->post_author, 'authorName'=>$author ? sanitize_text_field( (string) $author->display_name ) : '',
			'viewUrl'=>'publish' === $post->post_status ? esc_url_raw( (string) get_permalink( $post ) ) : '',
			'guestSubmission'=>class_exists( Guest_Contribution_Service::class ) && Guest_Contribution_Service::is_submission( $id ),
			'canTrash'=>current_user_can( 'delete_post', $id ),
			'canExport'=>class_exists( \DSA\Backup\Content_Archive_Service::class ) && \DSA\Backup\Content_Archive_Service::can_export( 'post', $id ),
			'featuredImage'=>Workspace_Media::image( (int) get_post_thumbnail_id( $id ) ), 'categories'=>$categories,
			'tags'=>$this->post_tag_ids( $id ), 'seo'=>$this->seo( $id ), 'modifiedGmt'=>(string) $post->post_modified_gmt,
			'scheduledLocal'=>'future' === $post->post_status ? substr( str_replace( ' ', 'T', (string) $post->post_date ), 0, 16 ) : '',
			'expected'=>self::fingerprint( $post ),
			'controls'=>class_exists( Record_Card_Control_Service::class ) ? Record_Card_Control_Service::project( 'post', $id ) : null,
		];
	}

	private function normalized_categories( $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 20 ) return [];
		$out = [];
		foreach ( $value as $id ) if ( is_int( $id ) || ( is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) ) ) $out[] = (int) $id;
		return array_values( array_unique( $out ) );
	}

	private function normalized_tag_ids( $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 20 ) return [];
		$out = [];
		foreach ( $value as $id ) if ( is_int( $id ) || ( is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) ) ) $out[] = (int) $id;
		return array_values( array_unique( $out ) );
	}

	private function normalized_tag_names( $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 20 ) return [];
		$out = [];
		foreach ( $value as $name ) {
			if ( ! is_string( $name ) ) continue;
			$name = sanitize_text_field( $name );
			if ( '' === $name || preg_match_all( '/./us', $name ) > 80 ) continue;
			$out[ strtolower( $name ) ] = $name;
		}
		return array_values( $out );
	}

	private function post_tag_ids( int $id ): array {
		$ids = wp_get_post_terms( $id, 'post_tag', [ 'fields'=>'ids' ] );
		if ( is_wp_error( $ids ) ) return [];
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/** Core category terms remain the only category authority. */
	private function resolve_categories( array $ids, array $names ) {
		$resolved = [];
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) return new \WP_Error( 'kiwe_post_categories', __( 'One of the selected categories no longer exists. Reload before saving.', 'dsa' ), [ 'status'=>409 ] );
			$resolved[] = $id;
		}
		foreach ( $names as $name ) {
			$existing = term_exists( $name, 'category' );
			if ( $existing ) { $resolved[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ); continue; }
			if ( ! $this->can_create_categories() ) return new \WP_Error( 'kiwe_post_category_create', __( 'Your role can use existing categories but cannot create new ones.', 'dsa' ), [ 'status'=>403 ] );
			$created = wp_insert_term( $name, 'category' );
			if ( is_wp_error( $created ) ) return new \WP_Error( 'kiwe_post_category_write', __( 'WordPress could not create that category. Your writing is still here; reload before retrying.', 'dsa' ), [ 'status'=>409 ] );
			$resolved[] = (int) $created['term_id'];
		}
		$resolved = array_values( array_unique( $resolved ) );
		if ( count( $resolved ) > 20 ) return new \WP_Error( 'kiwe_post_categories', __( 'Choose at most 20 categories.', 'dsa' ), [ 'status'=>400 ] );
		sort( $resolved, SORT_NUMERIC );
		return $resolved;
	}

	/** Resolve selected native tags and, when allowed, create explicitly named tags before the post write. */
	private function resolve_tags( array $ids, array $names ) {
		$resolved = [];
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'post_tag' );
			if ( ! $term || is_wp_error( $term ) ) return new \WP_Error( 'kiwe_post_tags', __( 'One of the selected tags no longer exists. Reload the post before saving.', 'dsa' ), [ 'status'=>409 ] );
			$resolved[] = $id;
		}
		foreach ( $names as $name ) {
			$existing = term_exists( $name, 'post_tag' );
			if ( $existing ) {
				$resolved[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
				continue;
			}
			if ( ! $this->can_create_tags() ) return new \WP_Error( 'kiwe_post_tag_create', __( 'Your role can use existing tags but cannot create new ones.', 'dsa' ), [ 'status'=>403 ] );
			$created = wp_insert_term( $name, 'post_tag' );
			if ( is_wp_error( $created ) ) {
				if ( 'term_exists' === $created->get_error_code() && (int) $created->get_error_data() > 0 ) $resolved[] = (int) $created->get_error_data();
				else return new \WP_Error( 'kiwe_post_tag_write', __( 'WordPress could not create that tag. Your writing is still here; reload before retrying.', 'dsa' ), [ 'status'=>409 ] );
			} else $resolved[] = (int) $created['term_id'];
		}
		$resolved = array_values( array_unique( array_filter( array_map( 'intval', $resolved ) ) ) );
		if ( count( $resolved ) > 20 ) return new \WP_Error( 'kiwe_post_tags', __( 'Choose at most 20 tags.', 'dsa' ), [ 'status'=>400 ] );
		sort( $resolved, SORT_NUMERIC );
		return $resolved;
	}

	private function status( string $status ): string {
		return in_array( $status, [ 'draft', 'pending', 'publish', 'future' ], true ) ? $status : 'draft';
	}

	/** Create or update one post; all writes are narrow core post/category writes with readback. */
	public function save( int $id, array $input ) {
		$guest_guard = $this->guest_write_guard( $id );
		if ( $guest_guard ) return $guest_guard;
		$title = is_string( $input['title'] ?? null ) ? sanitize_text_field( $input['title'] ) : '';
		$raw_content = is_string( $input['content'] ?? null ) ? $input['content'] : '';
		$content_length = preg_match_all( '/./us', $raw_content );
		if ( false === $content_length || $content_length > 100000 ) return new \WP_Error( 'kiwe_post_content', __( 'Post content must be at most 100,000 characters.', 'dsa' ), [ 'status'=>400 ] );
		$content = $id > 0 && ( $previous = get_post( $id ) ) && (string) $previous->post_content === $raw_content ? $raw_content : wp_kses_post( $raw_content );
		$status = is_string( $input['status'] ?? null ) ? $this->status( sanitize_key( $input['status'] ) ) : 'draft';
		$categories = $this->normalized_categories( $input['categories'] ?? [] );
		$raw_new_categories = $input['newCategories'] ?? [];
		$new_categories = $this->normalized_tag_names( $raw_new_categories );
		$raw_tag_ids = $input['tags'] ?? [];
		$raw_new_tags = $input['newTags'] ?? [];
		$tag_ids = $this->normalized_tag_ids( $raw_tag_ids );
		$new_tags = $this->normalized_tag_names( $raw_new_tags );
		if ( '' === $title || preg_match_all( '/./us', $title ) > 200 ) return new \WP_Error( 'kiwe_post_title', __( 'Enter a post title of at most 200 characters.', 'dsa' ), [ 'status'=>400 ] );
		if ( ! is_string( $input['status'] ?? null ) || ! in_array( $input['status'], [ 'draft', 'pending', 'publish', 'future' ], true ) ) return new \WP_Error( 'kiwe_post_status', __( 'Choose draft, pending review, publish now or schedule.', 'dsa' ), [ 'status'=>400 ] );
		if ( ! is_array( $input['categories'] ?? null ) || count( $input['categories'] ) !== count( $categories ) || ! is_array( $raw_new_categories ) || count( $raw_new_categories ) !== count( $new_categories ) ) return new \WP_Error( 'kiwe_post_categories', __( 'Choose valid categories and keep each new name under 80 characters.', 'dsa' ), [ 'status'=>400 ] );
		if ( ! is_array( $raw_tag_ids ) || count( $raw_tag_ids ) !== count( $tag_ids ) || ! is_array( $raw_new_tags ) || count( $raw_new_tags ) !== count( $new_tags ) ) return new \WP_Error( 'kiwe_post_tags', __( 'Choose valid tags and keep each new tag under 80 characters.', 'dsa' ), [ 'status'=>400 ] );
		if ( in_array( $status, [ 'publish', 'future' ], true ) && ! current_user_can( 'publish_posts' ) ) return new \WP_Error( 'kiwe_post_publish', __( 'Your role can save this post for review but cannot publish or schedule it.', 'dsa' ), [ 'status'=>403 ] );
		$scheduled = null;
		if ( 'future' === $status ) {
			$raw_date = $input['scheduledLocal'] ?? null;
			if ( ! is_string( $raw_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $raw_date ) ) return new \WP_Error( 'kiwe_post_schedule', __( 'Choose a date and time in the website timezone.', 'dsa' ), [ 'status'=>400 ] );
			$scheduled = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $raw_date, wp_timezone() );
			if ( ! $scheduled || $scheduled->format( 'Y-m-d\TH:i' ) !== $raw_date || $scheduled->getTimestamp() < time() + 300 ) return new \WP_Error( 'kiwe_post_schedule', __( 'Choose a valid time at least five minutes from now.', 'dsa' ), [ 'status'=>400 ] );
		} elseif ( array_key_exists( 'scheduledLocal', $input ) && '' !== $input['scheduledLocal'] ) return new \WP_Error( 'kiwe_post_schedule', __( 'A scheduled time is only used when you choose Schedule.', 'dsa' ), [ 'status'=>400 ] );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( $id > 0 ) {
			if ( ! $this->can_manage( $id ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'This post is unavailable in your workspace.', 'dsa' ), [ 'status'=>403 ] );
			if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::fingerprint( $post ), $input['expected'] ) ) return new \WP_Error( 'kiwe_post_stale', __( 'This post changed elsewhere. Reload it before saving.', 'dsa' ), [ 'status'=>409 ] );
		} elseif ( ! $this->can_create() ) return new \WP_Error( 'kiwe_post_create_forbidden', __( 'Post creation is unavailable for your role.', 'dsa' ), [ 'status'=>403 ] );
		$seo_input = $input['seo'] ?? ( $post ? $this->seo( $id ) : [ 'title'=>'', 'description'=>'', 'focusKeyword'=>'' ] );
		if ( ! is_array( $seo_input ) || ! isset( $seo_input['title'], $seo_input['description'], $seo_input['focusKeyword'] ) || ! is_string( $seo_input['title'] ) || ! is_string( $seo_input['description'] ) || ! is_string( $seo_input['focusKeyword'] ) ) return new \WP_Error( 'kiwe_post_seo', __( 'The search details were incomplete. Reload before saving.', 'dsa' ), [ 'status'=>400 ] );
		$seo = [ 'title'=>sanitize_text_field( $seo_input['title'] ), 'description'=>sanitize_text_field( $seo_input['description'] ), 'focusKeyword'=>sanitize_text_field( $seo_input['focusKeyword'] ) ];
		if ( preg_match_all( '/./us', $seo['title'] ) > 200 || preg_match_all( '/./us', $seo['description'] ) > 320 || preg_match_all( '/./us', $seo['focusKeyword'] ) > 500 ) return new \WP_Error( 'kiwe_post_seo', __( 'Search details are too long.', 'dsa' ), [ 'status'=>400 ] );
		if ( 'kiwe' === $this->seo_owner() ) { $phrases = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $seo['focusKeyword'] ) ) ) ) ); if ( count( $phrases ) > 10 ) return new \WP_Error( 'kiwe_post_seo', __( 'Choose at most ten search phrases.', 'dsa' ), [ 'status'=>400 ] ); $seo['focusKeyword'] = implode( ', ', $phrases ); }
		$image = array_key_exists( 'featuredImageId', $input ) ? Workspace_Media::validate( $input['featuredImageId'], $post ? (int) get_post_thumbnail_id( $id ) : 0 ) : null;
		if ( is_wp_error( $image ) ) return $image;
		$categories = $this->resolve_categories( $categories, $new_categories );
		if ( is_wp_error( $categories ) ) return $categories;
		$tags = $this->resolve_tags( $tag_ids, $new_tags );
		if ( is_wp_error( $tags ) ) return $tags;
		$before_tags = $post ? $this->post_tag_ids( $id ) : [];
		$fields = [ 'post_title'=>$title, 'post_content'=>$content, 'post_status'=>$status ];
		if ( $scheduled ) { $fields['post_date']=$scheduled->format( 'Y-m-d H:i:s' ); $fields['post_date_gmt']=$scheduled->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); }
		elseif ( $post && 'future' === $post->post_status ) { $fields['post_date']=current_time( 'mysql' ); $fields['post_date_gmt']=current_time( 'mysql', true ); }
		if ( ! $post ) { $fields['post_type']='post'; $fields['post_author']=get_current_user_id(); $fields['post_category']=$categories; $fields['tags_input']=$tags; $saved=wp_insert_post( wp_slash( $fields ), true ); }
		else { $fields['ID']=$id; $saved=wp_update_post( wp_slash( $fields ), true ); }
		if ( is_wp_error( $saved ) || ! $saved ) return new \WP_Error( 'kiwe_post_write', __( 'The post could not be saved. Your writing is still here; reload before retrying.', 'dsa' ), [ 'status'=>500 ] );
		$saved_id = (int) $saved;
		if ( $post && function_exists( 'wp_set_post_categories' ) ) {
			$category_result = wp_set_post_categories( $saved_id, $categories, false );
			if ( is_wp_error( $category_result ) ) return new \WP_Error( 'kiwe_post_categories', __( 'The post saved, but its categories could not be confirmed. Reload before retrying.', 'dsa' ), [ 'status'=>409 ] );
		}
		if ( $post ) {
			$tag_result = wp_set_post_terms( $saved_id, $tags, 'post_tag', false );
			if ( is_wp_error( $tag_result ) || $this->post_tag_ids( $saved_id ) !== $tags ) {
				// Best-effort restore keeps a transient taxonomy failure from silently
				// replacing the post's previously confirmed tags.
				wp_set_post_terms( $saved_id, $before_tags, 'post_tag', false );
				return new \WP_Error( 'kiwe_post_tags_unconfirmed', __( 'The post saved, but WordPress could not confirm its tags. The previous tags were restored where possible; reload before retrying.', 'dsa' ), [ 'status'=>409 ] );
			}
		}
		if ( null !== $image ) { if ( $image ) set_post_thumbnail( $saved_id, $image ); else delete_post_thumbnail( $saved_id ); }
		$seo_keys = 'rank_math' === $this->seo_owner() ? [ 'title'=>'rank_math_title', 'description'=>'rank_math_description', 'focusKeyword'=>'rank_math_focus_keyword' ] : [ 'title'=>'_kiwe_seo_title', 'description'=>'_kiwe_seo_description', 'focusKeyword'=>'_kiwe_search_phrases' ];
		foreach ( $seo_keys as $field=>$key ) {
			$value = 'kiwe' === $this->seo_owner() && 'focusKeyword' === $field ? $phrases : $seo[ $field ];
			if ( $value !== get_post_meta( $saved_id, $key, true ) ) update_post_meta( $saved_id, $key, $value );
		}
		$after = $this->post( $saved_id );
		if ( null !== $image && (int) get_post_thumbnail_id( $saved_id ) !== $image ) return new \WP_Error( 'kiwe_post_image_unconfirmed', 'The post saved, but its image could not be confirmed. Reload and review.', [ 'status'=>409 ] );
		if ( is_wp_error( $after ) || $after['title'] !== $title || $after['content'] !== $content || $after['status'] !== $status || ( $scheduled && $after['scheduledLocal'] !== $scheduled->format( 'Y-m-d\TH:i' ) ) || array_values( array_diff( $after['categories'], $categories ) ) || array_values( array_diff( $categories, $after['categories'] ) ) || $after['tags'] !== $tags || $after['seo'] !== $seo ) return new \WP_Error( 'kiwe_post_unconfirmed', __( 'The post changed during the save. Reload and review it.', 'dsa' ), [ 'status'=>409 ] );
		return $after;
	}

	private function own_autosave( int $post_id ) {
		$autosave = wp_get_post_autosave( $post_id, get_current_user_id() );
		return $autosave instanceof \WP_Post && (int) $autosave->post_parent === $post_id && (int) $autosave->post_author === get_current_user_id() && (int) wp_is_post_autosave( $autosave ) === $post_id ? $autosave : null;
	}

	private function revision( int $post_id, int $revision_id ) {
		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision || (int) $revision->post_parent !== $post_id ) return new \WP_Error( 'kiwe_post_revision', __( 'That saved version is unavailable.', 'dsa' ), [ 'status'=>404 ] );
		if ( wp_is_post_autosave( $revision ) ) {
			$own = $this->own_autosave( $post_id );
			if ( ! $own || (int) $own->ID !== $revision_id ) return new \WP_Error( 'kiwe_post_revision', __( 'That saved version is unavailable.', 'dsa' ), [ 'status'=>404 ] );
		}
		return $revision;
	}

	private static function revision_fingerprint( \WP_Post $revision ): string {
		return Task_Workspace_UI::revision_fingerprint( $revision );
	}

	private static function revision_summary( \WP_Post $revision ): array {
		return [
			'id'=>(int) $revision->ID, 'title'=>sanitize_text_field( (string) $revision->post_title ),
			'excerpt'=>wp_html_excerpt( wp_strip_all_tags( (string) $revision->post_content ), 140, '…' ),
			'readerExcerpt'=>wp_html_excerpt( wp_strip_all_tags( (string) $revision->post_excerpt ), 400, '…' ),
			'modifiedGmt'=>(string) $revision->post_modified_gmt, 'expected'=>self::revision_fingerprint( $revision ),
		];
	}

	/** Only small version summaries are listed; the post editor itself remains the full-content authority. */
	public function revisions( int $id ) {
		if ( ! $this->can_manage( $id ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'This post is unavailable in your workspace.', 'dsa' ), [ 'status'=>403 ] );
		$post = get_post( $id );
		$rows = wp_get_post_revisions( $id, [ 'posts_per_page'=>9, 'orderby'=>'date', 'order'=>'DESC', 'check_enabled'=>false ] );
		$items = [];
		foreach ( is_array( $rows ) ? $rows : [] as $revision ) {
			if ( ! $revision instanceof \WP_Post || (int) $revision->post_parent !== $id || wp_is_post_autosave( $revision ) ) continue;
			$items[] = self::revision_summary( $revision );
			if ( count( $items ) === 8 ) break;
		}
		$autosave = $this->own_autosave( $id );
		$recovery = null;
		if ( $autosave && ! Task_Workspace_UI::revision_text_matches( $autosave, $post ) ) {
			$recovery = self::revision_summary( $autosave );
		}
		return [ 'postId'=>$id, 'expected'=>self::fingerprint( $post ), 'autosave'=>$recovery, 'revisions'=>$items ];
	}

	/** Save title/body to WordPress's current-user autosave without changing the visible post. */
	public function autosave( int $id, array $input ) {
		$guest_guard = $this->guest_write_guard( $id );
		if ( $guest_guard ) return $guest_guard;
		if ( ! $this->can_manage( $id ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'This post is unavailable in your workspace.', 'dsa' ), [ 'status'=>403 ] );
		$keys = array_keys( $input ); sort( $keys );
		if ( $keys !== [ 'autosaveExpected', 'content', 'expected', 'title' ] || ! is_string( $input['expected'] ?? null ) || ! is_string( $input['autosaveExpected'] ?? null ) || ! is_string( $input['title'] ?? null ) || ! is_string( $input['content'] ?? null ) ) return new \WP_Error( 'kiwe_post_autosave_input', __( 'The recovery copy was incomplete. Reload before trying again.', 'dsa' ), [ 'status'=>400 ] );
		$post = get_post( $id );
		if ( ! hash_equals( self::fingerprint( $post ), $input['expected'] ) ) return new \WP_Error( 'kiwe_post_stale', __( 'This post changed elsewhere. Reload it before saving a recovery copy.', 'dsa' ), [ 'status'=>409 ] );
		$title = sanitize_text_field( $input['title'] );
		if ( preg_match_all( '/./us', $title ) > 200 ) return new \WP_Error( 'kiwe_post_title', __( 'Post title must be at most 200 characters.', 'dsa' ), [ 'status'=>400 ] );
		$content_length = preg_match_all( '/./us', $input['content'] );
		if ( false === $content_length || $content_length > 100000 ) return new \WP_Error( 'kiwe_post_content', __( 'Post content must be at most 100,000 characters.', 'dsa' ), [ 'status'=>400 ] );
		$content = wp_kses_post( $input['content'] );
		$current = $this->own_autosave( $id );
		$current_expected = $current ? self::revision_fingerprint( $current ) : '';
		$current_is_recovery = $current && ! Task_Workspace_UI::revision_text_matches( $current, $post );
		$observed_current = '' !== $input['autosaveExpected'] && hash_equals( $current_expected, $input['autosaveExpected'] );
		if ( ( $current_is_recovery && ! $observed_current ) || ( ! $current_is_recovery && '' !== $input['autosaveExpected'] && ! $observed_current ) ) return new \WP_Error( 'kiwe_post_autosave_stale', __( 'A different recovery copy already exists. Review it before replacing anything.', 'dsa' ), [ 'status'=>409 ] );
		if ( ! function_exists( 'wp_autosave_post_revisioned_meta_fields' ) ) require_once ABSPATH . 'wp-admin/includes/post.php';
		$controller = new \WP_REST_Autosaves_Controller( 'post' );
		$saved = $controller->create_post_autosave( [ 'ID'=>$id, 'post_title'=>$title, 'post_content'=>$content, 'post_excerpt'=>(string) ( $post->post_excerpt ?? '' ) ] );
		if ( is_wp_error( $saved ) || ! $saved ) return new \WP_Error( 'kiwe_post_autosave_write', __( 'WordPress could not confirm the recovery copy. Your writing remains in this tab.', 'dsa' ), [ 'status'=>500 ] );
		$after = $this->own_autosave( $id );
		if ( ! $after || (int) $after->ID !== (int) $saved || (string) $after->post_title !== $title || (string) $after->post_content !== $content || (string) $after->post_excerpt !== (string) $post->post_excerpt || ! hash_equals( self::fingerprint( $post ), self::fingerprint( get_post( $id ) ) ) ) return new \WP_Error( 'kiwe_post_autosave_unconfirmed', __( 'The recovery copy could not be confirmed. Reload before retrying.', 'dsa' ), [ 'status'=>409 ] );
		return [ 'postId'=>$id, 'expected'=>self::fingerprint( $post ), 'autosave'=>self::revision_summary( $after ) ];
	}

	/** Remove only the signed-in writer's reviewed autosave. */
	public function discard_autosave( int $id, int $autosave_id, array $input ) {
		$guest_guard = $this->guest_write_guard( $id );
		if ( $guest_guard ) return $guest_guard;
		if ( ! $this->can_manage( $id ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'This post is unavailable in your workspace.', 'dsa' ), [ 'status'=>403 ] );
		$keys = array_keys( $input ); sort( $keys );
		if ( $keys !== [ 'expected', 'recoveryExpected' ] || ! is_string( $input['expected'] ?? null ) || ! is_string( $input['recoveryExpected'] ?? null ) ) return new \WP_Error( 'kiwe_post_autosave_input', __( 'Reload the recovery copy before discarding it.', 'dsa' ), [ 'status'=>400 ] );
		$post = get_post( $id );
		if ( ! hash_equals( self::fingerprint( $post ), $input['expected'] ) ) return new \WP_Error( 'kiwe_post_stale', __( 'This post changed elsewhere. Reload it before discarding the recovery copy.', 'dsa' ), [ 'status'=>409 ] );
		$current = $this->own_autosave( $id );
		if ( ! $current || (int) $current->ID !== $autosave_id ) return new \WP_Error( 'kiwe_post_revision', __( 'That recovery copy is unavailable.', 'dsa' ), [ 'status'=>404 ] );
		if ( ! hash_equals( self::revision_fingerprint( $current ), $input['recoveryExpected'] ) ) return new \WP_Error( 'kiwe_post_autosave_stale', __( 'That recovery copy changed. Reload it before discarding.', 'dsa' ), [ 'status'=>409 ] );
		if ( ! wp_delete_post_revision( $autosave_id ) || $this->own_autosave( $id ) ) return new \WP_Error( 'kiwe_post_autosave_delete', __( 'WordPress could not confirm the recovery copy was discarded.', 'dsa' ), [ 'status'=>409 ] );
		return [ 'postId'=>$id, 'expected'=>self::fingerprint( get_post( $id ) ), 'autosave'=>null ];
	}

	/** WordPress restores title/body/excerpt; publication and relationships stay current. */
	public function restore_revision( int $id, int $revision_id, array $input ) {
		$guest_guard = $this->guest_write_guard( $id );
		if ( $guest_guard ) return $guest_guard;
		if ( ! $this->can_manage( $id ) ) return new \WP_Error( 'kiwe_post_forbidden', __( 'This post is unavailable in your workspace.', 'dsa' ), [ 'status'=>403 ] );
		if ( array_keys( $input ) !== [ 'expected', 'revisionExpected' ] || ! is_string( $input['expected'] ) || ! is_string( $input['revisionExpected'] ) ) return new \WP_Error( 'kiwe_post_revision_input', __( 'Reload the saved versions before restoring.', 'dsa' ), [ 'status'=>400 ] );
		$post = get_post( $id );
		if ( ! hash_equals( self::fingerprint( $post ), $input['expected'] ) ) return new \WP_Error( 'kiwe_post_stale', __( 'This post changed elsewhere. Reload it before restoring.', 'dsa' ), [ 'status'=>409 ] );
		$revision = $this->revision( $id, $revision_id );
		if ( is_wp_error( $revision ) ) return $revision;
		if ( ! hash_equals( self::revision_fingerprint( $revision ), $input['revisionExpected'] ) ) return new \WP_Error( 'kiwe_post_revision_stale', __( 'This saved version changed. Reload before restoring.', 'dsa' ), [ 'status'=>409 ] );
		$before_categories = array_map( 'intval', (array) wp_get_post_categories( $id, [ 'fields'=>'ids' ] ) );
		sort( $before_categories, SORT_NUMERIC );
		$before_image = (int) get_post_thumbnail_id( $id );
		$before_date = [ (string) $post->post_date, (string) $post->post_date_gmt ];
		$restored = Task_Workspace_UI::restore_revision_writing( $post, $revision_id );
		if ( is_wp_error( $restored ) || (int) $restored !== $id ) return new \WP_Error( 'kiwe_post_restore', __( 'WordPress could not confirm the restore. Reload this post before retrying.', 'dsa' ), [ 'status'=>409 ] );
		$after = $this->post( $id );
		$reloaded = get_post( $id );
		if ( is_wp_error( $after ) || ! $reloaded || ! Task_Workspace_UI::revision_text_matches( $revision, $reloaded ) || $after['status'] !== $post->post_status || $after['categories'] !== $before_categories || (int) get_post_thumbnail_id( $id ) !== $before_image || [ (string) $reloaded->post_date, (string) $reloaded->post_date_gmt ] !== $before_date ) return new \WP_Error( 'kiwe_post_restore_unconfirmed', __( 'The restore could not be confirmed. Reload and review the post.', 'dsa' ), [ 'status'=>409 ] );
		return $after;
	}
}
