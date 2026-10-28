<?php
declare(strict_types=1);

namespace DSA\Apps {
	final class Store_App_Service {
		public static bool $on = true;
		public static function enabled(): bool { return self::$on; }
		public static function content_available( string $type ): bool { return self::$on || ! in_array( $type, [ 'product', 'product_variation' ], true ); }
	}
}
namespace DSA\Diagnostics {
	final class Runtime_Profiler {
		public static function start() { return null; }
		public static function finish( ...$args ): void {}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DSA_DIR', dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	require DSA_DIR . 'includes/Autoloader.php';
	\DSA\Autoloader::register();
	$posts = []; $saved = []; $actor = 2; $checks = []; $events = []; $password_cookie = false; $inject = false; $meta_writes = 0; $denied_passwords = [];

	class WP_Post {
		public int $ID; public string $post_type = 'post'; public string $post_status = 'publish'; public string $post_password = '';
		public int $post_parent = 0; public int $post_author = 7; public string $post_title; public string $post_name; public string $post_content; public string $post_excerpt;
		public function __construct( int $id, array $values = [] ) {
			$this->ID = $id; $this->post_title = 'Native title ' . $id; $this->post_name = 'native-' . $id;
			$this->post_content = 'Native body ' . $id; $this->post_excerpt = 'Native excerpt ' . $id;
			foreach ( $values as $key => $value ) $this->$key = $value;
		}
	}
	class WP_Query {
		public array $posts; public int $found_posts; public int $max_num_pages;
		public function __construct( private array $args ) {
			$GLOBALS['last_query'] = $args;
			$this->posts = array_values( array_filter( $GLOBALS['posts'], static function( $post ) use ( $args ) {
				if ( $GLOBALS['inject'] ) return true;
				return $post->post_type === $args['post_type'] && in_array( $post->post_status, (array) $args['post_status'], true )
					&& ( ! isset( $args['has_password'] ) || $args['has_password'] || '' === $post->post_password )
					&& ( empty( $args['post__in'] ) || in_array( $post->ID, $args['post__in'], true ) );
			} ) );
			$this->found_posts = count( $this->posts ); $this->max_num_pages = $this->posts ? 1 : 0;
		}
		public function get( $key ) { return $this->args[$key] ?? null; }
	}
	function check( bool $pass, string $label ): void { if ( ! $pass ) throw new \RuntimeException( $label ); $GLOBALS['checks'][] = $label; }
	function get_post( $id ) { return $id instanceof WP_Post ? $id : ( $GLOBALS['posts'][(int) $id] ?? null ); }
	function get_post_type( $id ) { return get_post( $id )->post_type ?? false; }
	function get_post_status( $id ) { return get_post( $id )->post_status ?? false; }
	function get_post_type_object( $type ) { return (object) [ 'public' => $type !== 'internal', 'publicly_queryable' => ! in_array( $type, [ 'internal', 'product_variation' ], true ), 'labels' => (object) [ 'singular_name' => $type ] ]; }
	function post_type_exists( $type ) { return in_array( $type, [ 'post', 'page', 'product', 'product_variation', 'story', 'internal', 'attachment' ], true ); }
	function is_post_type_viewable( $type ) { $object = is_object( $type ) ? $type : get_post_type_object( $type ); return $object && $object->publicly_queryable; }
	function is_post_publicly_viewable( $post ) { $post = get_post( $post ); return $post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ); }
	function post_password_required( $post ) { $post = get_post( $post ); return $post && ( in_array( $post->ID, $GLOBALS['denied_passwords'], true ) || ( '' !== $post->post_password && ! $GLOBALS['password_cookie'] ) ); }
	function get_current_user_id() { return $GLOBALS['actor']; }
	function current_user_can( $cap, ...$args ) {
		$post = get_post( $args[0] ?? 0 ); $actor = get_current_user_id();
		if ( ! $actor || ! $post ) return false;
		if ( 1 === $actor ) return true;
		if ( 'edit_post' === $cap ) return 7 === $actor && $post->post_author === $actor;
		return 'read_post' === $cap && ( 'publish' === $post->post_status || $post->post_author === $actor );
	}
	function get_the_title( $id ) { return get_post( $id )->post_title; }
	function get_permalink( $id ) { return 'https://example.test/' . get_post( $id )->post_name; }
	function get_the_excerpt( $id ) { return post_password_required( $id ) ? 'Protected' : get_post( $id )->post_excerpt; }
	function get_the_post_thumbnail_url( ...$args ) { return ''; }
	function get_the_date( ...$args ) { return '2026-10-26'; }
	function get_post_time( ...$args ) { return '2026-10-26'; }
	function get_post_modified_time( ...$args ) { return '2026-10-26'; }
	function get_object_taxonomies( ...$args ) { return []; }
	function get_post_thumbnail_id( ...$args ) { return 0; }
	function get_user_meta( ...$args ) { return $GLOBALS['saved']; }
	function update_user_meta( $id, $key, $items ) { ++$GLOBALS['meta_writes']; $GLOBALS['saved'] = $items; return true; }
	function delete_transient( $key ) { return true; }
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
	function sanitize_title( $s ) { return sanitize_key( $s ); }
	function sanitize_text_field( $s ) { return strip_tags( (string) $s ); }
	function esc_url_raw( $s ) { return (string) $s; }
	function absint( $s ) { return abs( (int) $s ); }
	function wp_strip_all_tags( $s, ...$args ) { return strip_tags( (string) $s ); }
	function wp_trim_words( $s, ...$args ) { return $s; }
	function get_bloginfo( ...$args ) { return 'UTF-8'; }
	function apply_filters( $hook, $value ) { $GLOBALS['events'][] = $hook; return $value; }
	function wp_reset_postdata() {}
	function taxonomy_exists( $s ) { return false; }
	function __( $s, ...$args ) { return $s; }
	function wc_get_product( $id ) { return null; }
	function items( $reader, array $args = [], bool $private = false ): array {
		return $reader->query( $args + [ 'resource' => 'posts', 'fields' => [ 'id', 'title', 'excerpt', 'content' ] ], $private )['data'];
	}
	function ids( array $items ): array { return array_column( $items, 'id' ); }
	try {
		$posts = [
			11 => new WP_Post( 11 ), 12 => new WP_Post( 12, [ 'post_password' => 'synthetic' ] ),
			13 => new WP_Post( 13, [ 'post_status' => 'private' ] ), 14 => new WP_Post( 14, [ 'post_status' => 'draft' ] ),
			15 => new WP_Post( 15, [ 'post_type' => 'product' ] ), 16 => new WP_Post( 16, [ 'post_type' => 'product', 'post_password' => 'synthetic' ] ),
			17 => new WP_Post( 17, [ 'post_type' => 'story' ] ), 18 => new WP_Post( 18, [ 'post_type' => 'internal' ] ),
			19 => new WP_Post( 19, [ 'post_type' => 'product_variation', 'post_parent' => 15 ] ),
			20 => new WP_Post( 20, [ 'post_type' => 'product_variation', 'post_parent' => 16 ] ),
		];
		$reader = new \DSA\Site_Graph\Data_Query_Service(); $service = new \DSA\Saved\Saved_Items_Service();
		check( ids( items( $reader ) ) === [ 11 ], 'Public post queries exclude protected/private/draft rows' );
		check( false === ( $GLOBALS['last_query']['has_password'] ?? null ), 'Native public query excludes passwords before pagination' );
		check( items( $reader, [ 'include' => [ 12 ], 'status' => 'private', 'publicOnly' => false ] ) === [], 'Public include/status arguments cannot expand visibility' );
		$actor = 1;
		check( ids( items( $reader ) ) === [ 11 ], 'Public export ignores administrator identity and password cookie' );
		$password_cookie = true;
		check( ids( items( $reader ) ) === [ 11 ], 'Public export never inherits a viewer password cookie' ); $password_cookie = false;
		check( ids( items( $reader, [], true ) ) === [ 11, 12, 13, 14 ], 'Explicit private administration retains authorized records' );
		check( ids( items( $reader, [ 'publicOnly' => true ], true ) ) === [ 11 ], 'Raised-budget private reader honours public-only authoring visibility' );
		$context = ( new \ReflectionClass( \DSA\Site_Graph\Design_Context_Service::class ) )->newInstanceWithoutConstructor();
		( new \ReflectionProperty( $context, 'data' ) )->setValue( $context, $reader );
		$collection = new \ReflectionMethod( $context, 'collect' );
		check( ids( $collection->invoke( $context, 'posts', 200, [ 'fields' => [ 'id', 'content' ], 'publicOnly' => false ], true ) ) === [ 11 ], 'Actual Design Context collection forces public-only evidence even with administrator budgets' );
		$inject = true; $events = [];
		check( ids( items( $reader ) ) === [ 11, 15, 17, 19 ], 'Serializer rejects protected/private/internal rows even if a query filter injects them' );
		check( count( array_filter( $events, fn( $hook ) => 'the_content' === $hook ) ) === 4, 'Rejected rows never reach content filters or serializers' ); $inject = false;
		check( ids( items( $reader, [ 'resource' => 'products' ] ) ) === [ 15 ], 'Public products retain ordinary records and exclude protected products' );
		check( ids( items( $reader, [ 'resource' => 'story' ] ) ) === [ 17 ], 'Public custom content remains available' );
		$batch = $reader->query( [ 'queries' => [ 'articles' => [ 'resource' => 'posts', 'fields' => [ 'id', 'content' ] ], 'products' => [ 'resource' => 'products', 'fields' => [ 'id', 'content' ] ] ] ], false );
		check( ! str_contains( json_encode( $batch ), 'Native body 12' ) && ! str_contains( json_encode( $batch ), 'Native body 16' ), 'Batched public readers cannot expose protected article or product bodies' );
		$references = new \ReflectionMethod( $reader, 'public_product_references' );
		check( ids( $references->invoke( $reader, [ 15, 16 ], 40 ) ) === [ 15 ], 'Related product references exclude protected product identities' );
		$bundle = new class {
			public function get_bundled_items(): array { return array_map( fn( $id ) => new class( $id ) {
				public function __construct( private int $id ) {}
				public function get_product_id(): int { return $this->id; }
				public function get_quantity(): int { return 1; }
			}, [ 15, 16 ] ); }
		};
		$bundle_rows = ( new \ReflectionMethod( $reader, 'bundle_items' ) )->invoke( $reader, $bundle );
		check( count( $bundle_rows ) === 1 && $bundle_rows[0]['product']['id'] === 15, 'Bundle references retain public items without a hidden-ID fallback' );
		$actor = 2;
		check( [] === $service->normalize_item( [ 'id' => 13, 'title' => 'Stale private title', 'url' => 'https://example.test/stale' ] ), 'Subscriber cannot enrich a private ID or fall back to stale metadata' );
		check( [] === $service->normalize_item( [ 'id' => 14 ] ), 'Subscriber cannot enrich another author draft' );
		check( [] === $service->normalize_item( [ 'id' => 12 ] ), 'A read capability alone does not unlock a password-protected post' );
		check( [] === $service->normalize_item( [ 'id' => 18 ] ), 'Generic native read does not expose an internal post type' );
		check( [] === $service->normalize_item( [ 'id' => 999, 'title' => 'Deleted metadata', 'url' => 'https://example.test/deleted' ] ), 'Missing IDs never return stale client or stored metadata' );
		$normal = $service->normalize_item( [ 'id' => 11 ] ); check( ( $normal['title'] ?? '' ) === 'Native title 11', 'Ordinary bookmark still uses current native identity' );
		check( ( $service->normalize_item( [ 'title' => 'External', 'url' => 'https://outside.test/a' ] )['id'] ?? -1 ) === 0, 'ID-free external bookmarks retain their existing contract' );
		$before = serialize( $saved ); $writes = $meta_writes;
		check( ! $service->mutate( 'add', [ 'id' => 13 ] )['ok'] && serialize( $saved ) === $before && $writes === $meta_writes, 'Rejected private mutation writes no metadata' );
		$saved = [ $normal ]; $before = serialize( $saved ); $posts[11]->post_status = 'private';
		check( [] === $service->current_items() && serialize( $saved ) === $before, 'Public-to-private changes hide existing saved enrichment without deleting rows' );
		$posts[11]->post_status = 'publish'; check( count( $service->current_items() ) === 1, 'Republishing restores the preserved saved row' );
		$actor = 7; check( ( $service->normalize_item( [ 'id' => 14 ] )['id'] ?? 0 ) === 14, 'Native author retains readable own draft' );
		$actor = 1; check( ( $service->normalize_item( [ 'id' => 13 ] )['id'] ?? 0 ) === 13, 'Administrator retains authorized private bookmark' );
		$actor = 2; $password_cookie = true; check( ( $service->normalize_item( [ 'id' => 12 ] )['id'] ?? 0 ) === 12, 'Personal saved projection respects a valid native password cookie' ); $password_cookie = false;
		check( ( $service->normalize_item( [ 'id' => 19 ] )['id'] ?? 0 ) === 19, 'Published product variation remains readable through its public parent' );
		check( [] === $service->normalize_item( [ 'id' => 20 ] ), 'Variation cannot bypass its protected product parent' );
		$posts[21] = new WP_Post( 21, [ 'post_type' => 'product_variation' ] );
		$posts[22] = new WP_Post( 22, [ 'post_type' => 'product_variation', 'post_parent' => 11 ] );
		check( [] === $service->normalize_item( [ 'id' => 21 ] ) && [] === $service->normalize_item( [ 'id' => 22 ] ), 'Orphan and malformed variations cannot inherit generic read access' );
		$posts[23] = new WP_Post( 23, [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 11 ] );
		$posts[24] = new WP_Post( 24, [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 12 ] );
		$posts[25] = new WP_Post( 25, [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 13 ] );
		$posts[26] = new WP_Post( 26, [ 'post_type' => 'attachment', 'post_status' => 'inherit' ] );
		check( \DSA\Utilities\Content_Visibility::is_public( 23 ) && \DSA\Utilities\Content_Visibility::is_public( 26 ), 'Native public-parent and unattached media stay public' );
		check( ! \DSA\Utilities\Content_Visibility::is_public( 24 ) && ! \DSA\Utilities\Content_Visibility::is_public( 25 ), 'Attachment public visibility cannot bypass a protected or private parent' );
		check( [] === $service->normalize_item( [ 'id' => 24 ] ) && [] === $service->normalize_item( [ 'id' => 25 ] ), 'Saved media also checks parent read access' );
		$posts[27] = new WP_Post( 27, [ 'post_status' => 'trash' ] );
		$posts[28] = new WP_Post( 28, [ 'post_type' => 'revision', 'post_parent' => 11 ] );
		check( [] === $service->normalize_item( [ 'id' => 27 ] ) && [] === $service->normalize_item( [ 'id' => 28 ] ), 'Deleted and internal revision rows never become saved-content fallbacks' );
		$denied_passwords = [ 11 ];
		check( ! \DSA\Utilities\Content_Visibility::is_public( 11 ) && [] === $service->normalize_item( [ 'id' => 11 ] ), 'Native password-denial filters remain effective without a stored password' ); $denied_passwords = [];
		\DSA\Apps\Store_App_Service::$on = false;
		check( [] === $service->normalize_item( [ 'id' => 15, 'type' => 'wishlist' ] ), 'Store-off wishlist stays dormant' );
		check( ( $service->normalize_item( [ 'id' => 11 ] )['id'] ?? 0 ) === 11, 'Core bookmark remains available with Store off' );
		check( [] === items( $reader, [ 'resource' => 'products' ] ), 'Store-off product reader remains inert' );
		$actor = 0; check( [] === $service->current_items(), 'Anonymous saved-list reads remain empty' );
		echo json_encode( [ 'passed' => true, 'checks' => count( $checks ), 'cases' => $checks, 'scope' => 'Actual Core services with synthetic WordPress permission/query/storage adapters; no live records or Woo session' ] ) . PHP_EOL;
	} catch ( \Throwable $error ) {
		echo json_encode( [ 'passed' => false, 'checks' => count( $checks ), 'cases' => $checks, 'error' => $error->getMessage() ] ) . PHP_EOL; exit( 1 );
	}
}
