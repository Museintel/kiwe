<?php

namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/** First publication on a site whose root currently shows its posts index. */
final class Homepage_Assignment_Service {
	private const CHECKPOINT = 'kiwe_bricks_homepage_assignment_v1';
	private const CREATED_BY = '_kiwe_homepage_assignment_v1';
	private const READING = [ 'show_on_front', 'page_on_front', 'page_for_posts' ];
	private const SHARED_PAGES = [ 'wp_page_for_privacy_policy', 'woocommerce_shop_page_id', 'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id' ];

	private function read_routes(): array {
		return [
			'show_on_front'=>(string) get_option( 'show_on_front' ),
			'page_on_front'=>(int) get_option( 'page_on_front' ),
			'page_for_posts'=>(int) get_option( 'page_for_posts' ),
		];
	}

	private function key( array $args ): string {
		return $this->hash( [ home_url( '/' ), $args['job'], $args['archiveSha256'], $args['draftId'] ] );
	}

	private function hash( array $state ): string { return hash( 'sha256', serialize( $state ) ); }

	private function checkpoint(): ?array {
		$state = get_option( self::CHECKPOINT, null );
		if ( $state === null ) return null;
		if ( ! is_array( $state ) || ( $state['schema'] ?? 0 ) !== 1 || ! is_string( $state['site'] ?? null ) ) {
			throw new Compiler_Package_Error( 'The saved first-homepage setup requires native review.' );
		}
		if ( $state['site'] !== home_url( '/' ) ) return null;
		if ( ! in_array( $state['phase'] ?? '', [ 'placing', 'active', 'restoring', 'restored' ], true )
			|| ! is_array( $state['before'] ?? null ) || array_keys( $state['before'] ) !== self::READING
			|| $state['before']['show_on_front'] !== 'posts' || ! is_array( $state['stamps'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $state['key'] ?? '' ) ) ) {
			throw new Compiler_Package_Error( 'The saved first-homepage setup requires native review.' );
		}
		return $state;
	}

	private function save( array $state ): void {
		if ( $state !== get_option( self::CHECKPOINT, null ) ) update_option( self::CHECKPOINT, $state, false );
		if ( get_option( self::CHECKPOINT, null ) !== $state ) {
			throw new Compiler_Package_Error( 'WordPress did not confirm the homepage setup checkpoint.' );
		}
	}

	private function active_routes( array $state ): array {
		return [ 'show_on_front'=>'page', 'page_on_front'=>$state['homeId'], 'page_for_posts'=>$state['blogId'] ];
	}

	private function guarded_page( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== 'page' || $post->post_password !== '' ) return null;
		foreach ( self::SHARED_PAGES as $option ) if ( $id === (int) get_option( $option ) ) return null;
		return $post;
	}

	private function known_blog( int $id ): ?\WP_Post {
		$post = $this->guarded_page( $id );
		return $post && $post->post_status === 'publish' ? $post : null;
	}

	private function post_types(): ?array {
		$types = ( (array) get_option( BRICKS_DB_GLOBAL_SETTINGS, [] ) )['postTypes'] ?? null;
		if ( $types !== null && ! is_array( $types ) ) throw new Compiler_Package_Error( 'Review the native Bricks post type settings.' );
		return $types;
	}

	private function available_slug( string $base ): string {
		$types = array_values( get_post_types( [ 'public'=>true ], 'names' ) );
		for ( $number = 1; $number <= 50; $number++ ) {
			$slug = $number === 1 ? $base : $base . '-' . $number;
			if ( wp_unique_post_slug( $slug, 0, 'publish', 'page', 0 ) === $slug
				&& ! get_page_by_path( $slug, OBJECT, $types )
				&& ! url_to_postid( home_url( user_trailingslashit( $slug, 'page' ) ) ) ) return $slug;
		}
		throw new Compiler_Package_Error( 'WordPress could not allocate a separate Home or Blog address.' );
	}

	private function stamp( \WP_Post $post ): string {
		$meta = get_post_meta( $post->ID );
		unset( $meta['_edit_lock'], $meta['_edit_last'] );
		ksort( $meta );
		return $this->hash( [
			$post->ID, $post->post_name, $post->post_title, $post->post_content, $post->post_excerpt,
			(int) $post->post_author, (int) $post->post_parent, $post->comment_status, $post->ping_status, $meta,
		] );
	}

	/** Slug lookup recovers an interrupted WordPress insert even before the ID was checkpointed. */
	private function created_page( array $state, string $role ): ?\WP_Post {
		$slug = $state[$role . 'Slug'];
		$id = $state[$role . 'Id'];
		$by_slug = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $id && ! $by_slug ) return null;
		$post = $this->guarded_page( $id ?: (int) $by_slug->ID );
		if ( ! $post || ! $by_slug || (int) $by_slug->ID !== (int) $post->ID || $post->post_name !== $slug
			|| (int) $post->post_parent !== 0 || ! in_array( $post->post_status, [ 'draft', 'publish' ], true )
			|| ! current_user_can( 'edit_post', $post->ID )
			|| get_post_meta( $post->ID, self::CREATED_BY, true ) !== $state['key'] . ':' . $role ) {
			throw new Compiler_Package_Error( 'A planned Home or Blog page changed or now belongs to another route.' );
		}
		return $post;
	}

	private function created_roles( array $state ): array {
		return $state['blogSlug'] ? [ 'home', 'blog' ] : [ 'home' ];
	}

	private function check_page( array $state, string $role, bool $published ): \WP_Post {
		$page = $this->created_page( $state, $role );
		if ( ! $page || ( $published && $page->post_status !== 'publish' ) || ! isset( $state['stamps'][$role] )
			|| ! hash_equals( $state['stamps'][$role], $this->stamp( $page ) ) ) {
			throw new Compiler_Package_Error( 'A page created during homepage setup has newer edits. Review it in WordPress.' );
		}
		return $page;
	}

	private function check_pages( array $state, bool $published = false ): void {
		foreach ( $this->created_roles( $state ) as $role ) $this->check_page( $state, $role, $published );
		if ( $published && ! $this->known_blog( $state['blogId'] ) ) {
			throw new Compiler_Package_Error( 'The assigned Blog page is unavailable.' );
		}
	}

	private function check_route_progress( array $state, array $current ): void {
		if ( $current['show_on_front'] !== 'posts' && $current !== $this->active_routes( $state ) ) {
			throw new Compiler_Package_Error( 'The active WordPress homepage changed during setup.' );
		}
		foreach ( [ 'page_on_front'=>'homeId', 'page_for_posts'=>'blogId' ] as $option=>$key ) {
			if ( $current[$option] !== $state['before'][$option] && ( ! $state[$key] || $current[$option] !== $state[$key] ) ) {
				throw new Compiler_Package_Error( 'The WordPress Reading settings changed during homepage setup.' );
			}
		}
	}

	public function owns_recovery( array $args ): bool {
		return ( $this->checkpoint()['key'] ?? '' ) === $this->key( $args );
	}

	/** Return null for the existing static-page publication path. */
	public function status( array $args ): ?array {
		$current = $this->read_routes();
		$state = $this->checkpoint();
		if ( $state && $state['key'] === $this->key( $args ) ) {
			if ( $state['phase'] === 'restoring' ) return [ 'page'=>$state['homeId'], 'status'=>'restore_in_progress' ];
			if ( $state['phase'] === 'restored' && $current === $state['before'] ) return [ 'page'=>$state['homeId'], 'status'=>'restored' ];
			if ( $current === $this->active_routes( $state ) && $state['stamps'] ) {
				try { $this->check_pages( $state, true ); }
				catch ( Compiler_Package_Error ) { return [ 'page'=>$state['homeId'], 'status'=>'review_current_homepage' ]; }
				return [ 'page'=>$state['homeId'], 'status'=>'published', 'blogUrl'=>get_permalink( $state['blogId'] ) ];
			}
			if ( $state['phase'] === 'active' ) return [ 'page'=>$state['homeId'], 'status'=>'review_current_homepage' ];
		}
		return $current['show_on_front'] === 'posts' ? [ 'page'=>0, 'status'=>'draft_ready' ] : null;
	}

	public function plan( array $args, bool $restore ): array {
		if ( ! Homepage_Publication_Service::allowed() ) throw new Compiler_Package_Error( 'Only the WordPress site owner can assign the homepage.' );
		$current = $this->read_routes();
		$prior = $this->checkpoint();
		$key = $this->key( $args );
		$content = [];
		if ( $restore ) {
			if ( ! $prior || $prior['key'] !== $key || ! $prior['stamps']
				|| ( $prior['phase'] !== 'restoring' && $current !== $this->active_routes( $prior ) ) ) {
				throw new Compiler_Package_Error( 'The current homepage does not match a restorable first publication.' );
			}
			$state = $prior;
			$this->check_pages( $state, $state['phase'] !== 'restoring' );
			$this->check_route_progress( $state, $current );
		} else {
			if ( $current['show_on_front'] !== 'posts'
				|| ( $prior && ( $prior['phase'] === 'restoring'
					|| ( $prior['phase'] !== 'restored' && ( $prior['phase'] !== 'placing' || $prior['key'] !== $key ) ) ) ) ) {
				throw new Compiler_Package_Error( 'Finish or restore the saved homepage setup before starting another.' );
			}
			$content = ( new Compiler_Import_Service() )->homepage_content( $args['job'], $args['archiveSha256'], $args['draftId'] );
			$source = $this->hash( $content );
			$reuse = $prior && $prior['key'] === $key && $prior['source'] === $source;
			if ( $reuse && $prior['phase'] === 'restored' && $current !== $prior['before'] ) {
				throw new Compiler_Package_Error( 'The restored Reading settings were edited. Review this setup in WordPress.' );
			}
			if ( $reuse ) {
				$state = $prior;
				$this->check_route_progress( $state, $current );
				if ( $state['stamps'] ) $this->check_pages( $state );
			} else {
				$blog = $this->known_blog( $current['page_for_posts'] );
				$state = [
					'schema'=>1, 'site'=>home_url( '/' ), 'key'=>$key, 'phase'=>'placing', 'source'=>$source, 'before'=>$current,
					'homeSlug'=>$this->available_slug( 'home' ), 'homeId'=>0, 'homeTitle'=>__( 'Home', 'dsa' ),
					'blogSlug'=>$blog ? '' : $this->available_slug( 'blog' ), 'blogId'=>$blog ? (int) $blog->ID : 0,
					'blogTitle'=>__( 'Blog', 'dsa' ),
					'editorBefore'=>$this->post_types(), 'editorAdded'=>false, 'stamps'=>[],
				];
			}
			if ( $state['source'] !== $source ) throw new Compiler_Package_Error( 'The reviewed homepage source changed.' );
			if ( ! $state['blogSlug'] && ! $this->known_blog( $state['blogId'] ) ) {
				throw new Compiler_Package_Error( 'The assigned Blog page is no longer available.' );
			}
		}
		$pages = [];
		foreach ( $this->created_roles( $state ) as $role ) {
			$post = $this->created_page( $state, $role );
			$pages[$role] = $post ? [ $post->post_status, $this->stamp( $post ) ] : null;
		}
		$expected = $this->hash( [
			$restore, $prior, $state, $current, $pages, $this->post_types(), (string) get_option( 'permalink_structure' ),
			Framework_Style_Service::fingerprint( Framework_Style_Service::snapshot() ),
		] );
		$blog_label = $state['blogSlug'] ? 'a new Blog page (' . $state['blogSlug'] . ')'
			: 'the assigned Blog page (' . get_permalink( $state['blogId'] ) . ')';
		$before = home_url( '/' ) . ( $restore ? ' · Published Home and Blog' : ' · Latest posts on the homepage' );
		$after = $restore ? 'Return the posts index to the homepage and keep the pages created for this setup as drafts.'
			: 'Publish the reviewed Home layout at the website root and move the existing posts index to ' . $blog_label . '.';
		if ( ! $restore && ! in_array( 'page', $this->post_types() ?? [], true ) ) $after .= ' Enable Bricks editing for Pages.';
		return [ 'assignment'=>true, 'state'=>$state, 'content'=>$content, 'expected'=>$expected, 'beforeText'=>$before, 'afterText'=>$after ];
	}

	private function ensure_page( array &$state, string $role ): \WP_Post {
		$post = $this->created_page( $state, $role );
		if ( ! $post ) {
			$slug = $state[$role . 'Slug'];
			// Use the exact approved slug. A newly occupied address requires a new review.
			if ( get_page_by_path( $slug, OBJECT, array_values( get_post_types( [ 'public'=>true ], 'names' ) ) )
				|| url_to_postid( home_url( user_trailingslashit( $slug, 'page' ) ) ) ) {
				throw new Compiler_Package_Error( 'An approved Home or Blog address was taken. Review the current website.' );
			}
			$id = wp_insert_post( [
				'post_type'=>'page', 'post_status'=>'draft', 'post_name'=>$slug,
				'post_title'=>$state[$role . 'Title'],
				'post_author'=>get_current_user_id(), 'meta_input'=>[ self::CREATED_BY=>$state['key'] . ':' . $role ],
			], true );
			if ( is_wp_error( $id ) || ! $id ) throw new Compiler_Package_Error( 'WordPress could not prepare a page for the approved setup.' );
			$state[$role . 'Id'] = $id;
			$post = $this->created_page( $state, $role );
			if ( ! $post ) throw new Compiler_Package_Error( 'WordPress could not confirm the new page.' );
		}
		if ( $post->post_title !== $state[$role . 'Title'] || $post->post_content !== '' || $post->post_excerpt !== '' ) {
			throw new Compiler_Package_Error( 'The page created for this setup has newer writing. Review it in WordPress.' );
		}
		$state[$role . 'Id'] = (int) $post->ID;
		$this->save( $state );
		return $post;
	}

	private function set_page_status( \WP_Post $post, string $status ): void {
		if ( $post->post_status === $status ) return;
		$saved = wp_update_post( [ 'ID'=>$post->ID, 'post_status'=>$status ], true );
		if ( is_wp_error( $saved ) || $saved !== $post->ID || get_post_status( $post->ID ) !== $status
			|| get_post_field( 'post_name', $post->ID, 'raw' ) !== $post->post_name ) {
			throw new Compiler_Package_Error( 'WordPress could not confirm the reviewed page publication status and address.' );
		}
	}

	private function write_route( array $state, string $name, string|int $value ): void {
		$current = $this->read_routes();
		$this->check_route_progress( $state, $current );
		if ( $current[$name] === $value ) return;
		if ( $name === 'show_on_front' && $value === 'page'
			&& ( $current['page_on_front'] !== $state['homeId'] || $current['page_for_posts'] !== $state['blogId'] ) ) {
			throw new Compiler_Package_Error( 'WordPress did not confirm both reviewed page assignments.' );
		}
		update_option( $name, $value );
		if ( $this->read_routes()[$name] !== $value ) throw new Compiler_Package_Error( 'WordPress did not confirm its Reading settings. Continue the saved setup.' );
	}

	private function set_editor( ?array $types ): void {
		$settings = (array) get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		if ( $types === null ) unset( $settings['postTypes'] );
		else $settings['postTypes'] = $types;
		update_option( BRICKS_DB_GLOBAL_SETTINGS, $settings );
		if ( $this->post_types() !== $types ) throw new Compiler_Package_Error( 'WordPress did not confirm Bricks page editing.' );
	}

	public function apply( array $args, array $plan, bool $restore ): int {
		$state = $plan['state'];
		if ( $restore ) {
			$state['phase'] = 'restoring';
			$this->save( $state );
			$this->write_route( $state, 'show_on_front', 'posts' );
			foreach ( array_slice( self::READING, 1 ) as $option ) $this->write_route( $state, $option, $state['before'][$option] );
			foreach ( $this->created_roles( $state ) as $role ) {
				$this->set_page_status( $this->check_page( $state, $role, false ), 'draft' );
				$state['stamps'][$role] = $this->stamp( $this->created_page( $state, $role ) );
				$this->save( $state );
			}
			$enabled = array_merge( $state['editorBefore'] ?? [], [ 'page' ] );
			if ( $state['editorAdded'] && $this->post_types() === $enabled ) $this->set_editor( $state['editorBefore'] );
			$state['phase'] = 'restored';
		} else {
			if ( $state['phase'] === 'restored' ) {
				$state['editorBefore'] = $this->post_types();
				$state['editorAdded'] = false;
			}
			$state['phase'] = 'placing';
			$this->save( $state );
			$content = ( new Compiler_Import_Service() )->homepage_content( $args['job'], $args['archiveSha256'], $args['draftId'], true );
			if ( $content !== $plan['content'] ) throw new Compiler_Package_Error( 'The reviewed Bricks draft changed.' );
			$home = $this->ensure_page( $state, 'home' );
			if ( $state['blogSlug'] ) $this->ensure_page( $state, 'blog' );
			elseif ( ! $this->known_blog( $state['blogId'] ) ) throw new Compiler_Package_Error( 'The assigned Blog page changed.' );

			// Evaluate the normal Bricks destination with the proposed page ID in this request only.
			$front = static fn() => $home->ID;
			add_filter( 'pre_option_page_on_front', $front );
			try {
				$override = \Bricks\Database::find_template_id( \Bricks\Database::get_all_templates_by_type(), 'content', 'content', $home->ID, '' );
				if ( $override && (int) $override !== $home->ID ) throw new Compiler_Package_Error( 'A separate Bricks template would control the new homepage. Review its conditions first.' );
			} finally { remove_filter( 'pre_option_page_on_front', $front ); }

			$stored = get_post_meta( $home->ID, BRICKS_DB_PAGE_CONTENT, false );
			if ( $stored !== [ $content ] ) {
				if ( $stored || $home->post_status !== 'draft' ) throw new Compiler_Package_Error( 'The new homepage already has a different layout.' );
				$checked = \Bricks\Helpers::security_check_elements_before_save( wp_slash( $content ), $home->ID, 'content' );
				if ( wp_unslash( $checked ) !== $content ) throw new Compiler_Package_Error( 'Bricks would change the reviewed layout.' );
				add_post_meta( $home->ID, BRICKS_DB_PAGE_CONTENT, wp_slash( $content ), true );
				if ( get_post_meta( $home->ID, BRICKS_DB_PAGE_CONTENT, false ) !== [ $content ] ) {
					throw new Compiler_Package_Error( 'The reviewed homepage layout did not save.' );
				}
			}
			if ( ! in_array( 'page', $this->post_types() ?? [], true ) ) {
				$state['editorAdded'] = true;
				$state['editorBefore'] = $this->post_types();
				$this->save( $state );
				$this->set_editor( array_merge( $state['editorBefore'] ?? [], [ 'page' ] ) );
			}
			if ( \Bricks\Database::get_setting( 'cssLoading' ) === 'file' ) {
				\Bricks\Assets_Files::generate_post_css_file( $home->ID, 'content', $content );
			}
			foreach ( $this->created_roles( $state ) as $role ) {
				$this->set_page_status( $this->created_page( $state, $role ), 'publish' );
				$state['stamps'][$role] = $this->stamp( $this->created_page( $state, $role ) );
			}
			$this->save( $state );
			$this->write_route( $state, 'page_on_front', $state['homeId'] );
			$this->write_route( $state, 'page_for_posts', $state['blogId'] );
			$this->write_route( $state, 'show_on_front', 'page' );
			if ( $this->read_routes() !== $this->active_routes( $state ) ) {
				throw new Compiler_Package_Error( 'The new homepage route needs native readback.' );
			}
			$state['phase'] = 'active';
		}
		$this->save( $state );
		do_action( 'litespeed_purge_url', home_url( '/' ) );
		do_action( 'litespeed_purge_post', $state['blogId'] );
		return $state['homeId'];
	}
}
