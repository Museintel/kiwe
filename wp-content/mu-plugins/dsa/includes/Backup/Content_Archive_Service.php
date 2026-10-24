<?php
namespace DSA\Backup;

use DSA\Access\Backup_Workspace_Service;
use DSA\Access\Template_Workspace_Service;
use DSA\Onboarding\Design_Context_Profile_Service as Profile;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native, same-site content portability. Completed archives never contain truncated collections. */
final class Content_Archive_Service {
	public const SCHEMA = 'kiwe.content-archive.v1';
	public const MAX_BYTES = 10485760;
	public const MAX_RECORDS = 200;
	public const REVIEW_SECONDS = 900;
	private const SEO_META = [ '_kiwe_seo_title','_kiwe_seo_description','_kiwe_search_phrases','_kiwe_seo_robots','_kiwe_search_visibility','_kiwe_seo_canonical_url','_kiwe_seo_facebook_title','_kiwe_seo_facebook_description','_kiwe_seo_facebook_image','_kiwe_seo_twitter_title','_kiwe_seo_twitter_description','_kiwe_seo_twitter_image' ];
	private const POST_META = [ ...self::SEO_META,'rank_math_title','rank_math_description','rank_math_focus_keyword','rank_math_robots','_wp_page_template','rank_math_canonical_url','_kiwe_schema_graph_v1' ];
	private const TERM_META = [ ...self::SEO_META,'rank_math_title','rank_math_description','rank_math_focus_keyword','rank_math_robots' ];
	private const BUILDER_META = [ '_bricks_page_content_2','_bricks_page_header_2','_bricks_page_footer_2','_bricks_page_settings' ];
	private const PRODUCT_FIELDS = [ 'name','description','short_description','regular_price','sale_price','date_on_sale_from','date_on_sale_to','virtual','downloadable','tax_status','tax_class','manage_stock','stock_quantity','stock_status','backorders','sold_individually','weight','length','width','height','shipping_class_id','reviews_allowed','purchase_note','menu_order','image_id','gallery_image_ids','sku','attributes','default_attributes','children','upsell_ids','cross_sell_ids','product_url','button_text' ];
	/** The existing explicit connection and developer-opted-in fields own the Service schema. */
	public static function service_definition(): array {
		if ( ! class_exists( Profile::class ) ) return [];
		$stored = get_option( Profile::OPTION_PROFILE, [] );
		$source = is_array( $stored ) && is_string( $stored['services']['sourcePostType'] ?? null ) ? $stored['services']['sourcePostType'] : '';
		$profile = new Profile(); $object = $source ? get_post_type_object( $source ) : null;
		if ( ! $object || ! isset( $profile->service_post_types()[$source] ) ) return [];
		$taxonomies = []; foreach ( $profile->service_taxonomies( $source ) as $name=>$definition ) $taxonomies[$name] = ! empty( $definition['hierarchical'] );
		$fields = []; foreach ( $profile->service_meta_fields( $source ) as $name=>$definition ) $fields[$name] = $definition['type'];
		if ( ! $taxonomies ) $fields[Profile::SERVICE_CATEGORY_PLAN_META] = 'string';
		if ( count( $taxonomies ) > 30 || count( $fields ) > 100 ) return [];
		ksort( $taxonomies ); ksort( $fields );
		return [ 'postType'=>$source,'hierarchical'=>! empty( $object->hierarchical ),'taxonomies'=>$taxonomies,'fields'=>$fields ];
	}
	public static function service_scope( string $scope ): bool { return in_array( $scope, [ 'service','services' ], true ) || str_starts_with( $scope, 'service_category:' ); }
	public static function category_scope( string $taxonomy, bool $service = false ): string {
		if ( $service ) return array_key_exists( $taxonomy, self::service_definition()['taxonomies'] ?? [] ) ? 'service_category:' . $taxonomy : '';
		return [ 'category'=>'category','product_cat'=>'product_category' ][$taxonomy] ?? '';
	}
	public static function type( string $scope ): string {
		if ( self::service_scope( $scope ) ) { $definition = self::service_definition(); return in_array( $scope, [ 'service','services' ], true ) || self::taxonomy( $scope ) ? ( $definition['postType'] ?? '' ) : ''; }
		return [ 'post'=>'post','posts'=>'post','page'=>'page','pages'=>'page','product'=>'product','products'=>'product','category'=>'post','product_category'=>'product','template'=>'bricks_template','templates'=>'bricks_template' ][$scope] ?? '';
	}
	private static function collection( string $scope ): bool { return in_array( $scope, [ 'posts','pages','products','templates','services' ], true ); }
	public static function single_scope( string $type ): string { return 'bricks_template' === $type ? 'template' : ( ( self::service_definition()['postType'] ?? '' ) === $type ? 'service' : $type ); }
	/** Dates refer to native creation dates, inclusive in the site's current timezone. */
	public static function selection( array $input ): array {
		if ( array_diff( array_keys( $input ), [ 'from','to' ] ) ) throw new \RuntimeException( 'Choose only a start and end date.' );
		$out = [ 'from'=>'','to'=>'','timezone'=>wp_timezone_string() ];
		foreach ( [ 'from','to' ] as $key ) {
			$value = array_key_exists( $key, $input ) ? $input[$key] : '';
			if ( ! is_string( $value ) ) throw new \RuntimeException( 'A backup date is invalid.' );
			if ( '' !== $value ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
				if ( ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ) || (int) substr( $value, 0, 4 ) < 1 || ! $date || $date->format( 'Y-m-d' ) !== $value ) throw new \RuntimeException( 'Choose a valid backup date.' );
			}
			$out[$key] = $value;
		}
		if ( $out['from'] && $out['to'] && strcmp( $out['from'], $out['to'] ) > 0 ) throw new \RuntimeException( 'The end date must follow the start date.' );
		return $out;
	}
	public static function can_export_collection( string $scope ): bool {
		if ( ! self::collection( $scope ) || ! is_user_logged_in() ) return false;
		$type = self::type( $scope ); $object = get_post_type_object( $type );
		if ( ! $object || ! current_user_can( $object->cap->edit_posts ) || ( 'product' === $type && ! function_exists( 'wc_get_product' ) ) ) return false;
		return 'bricks_template' !== $type || ( Template_Workspace_Service::allowed() && Template_Workspace_Service::available() );
	}
	public static function taxonomy( string $scope ): string {
		if ( preg_match( '/^service_category:([a-z0-9_-]{1,32})$/D', $scope, $match ) ) return array_key_exists( $match[1], self::service_definition()['taxonomies'] ?? [] ) ? $match[1] : '';
		return [ 'category'=>'category','product_category'=>'product_cat' ][$scope] ?? '';
	}
	private static function taxes( string $type ): array {
		if ( 'bricks_template' === $type ) { $taxes = []; foreach ( [ 'BRICKS_DB_TEMPLATE_TAX_TAG','BRICKS_DB_TEMPLATE_TAX_BUNDLE' ] as $name ) if ( defined( $name ) && taxonomy_exists( constant( $name ) ) ) $taxes[] = constant( $name ); return $taxes; }
		if ( ! in_array( $type, [ 'post','page','product' ], true ) && ( self::service_definition()['postType'] ?? '' ) === $type ) return array_keys( self::service_definition()['taxonomies'] );
		return 'post' === $type ? [ 'category','post_tag' ] : ( 'product' === $type ? [ 'product_cat','product_tag' ] : [] );
	}
	private static function service_value( $value, string $type ) {
		if ( ! is_scalar( $value ) || ! self::safe( $value ) ) throw new \RuntimeException( 'A registered Service field needs its own archive adapter.' );
		if ( 'boolean' === $type ) return ! empty( $value );
		if ( 'integer' === $type && ! is_bool( $value ) && preg_match( '/^-?[0-9]+$/D', (string) $value ) && (string) (int) $value === (string) $value ) return (int) $value;
		if ( 'number' === $type && ! is_bool( $value ) && is_numeric( $value ) && is_finite( (float) $value ) ) return (float) $value;
		if ( 'string' === $type && is_string( $value ) && sanitize_textarea_field( $value ) === $value ) return $value;
		throw new \RuntimeException( 'A registered Service field does not match its supported native type.' );
	}
	private static function service_values( int $id, array $definition ): array {
		$out = [];
		foreach ( $definition['fields'] as $key=>$type ) if ( metadata_exists( 'post', $id, $key ) ) {
			if ( ! self::can_service_field( $id, $key ) ) throw new \RuntimeException( 'Your account cannot archive a registered Service field.' );
			$out[$key] = self::service_value( get_post_meta( $id, $key, true ), $type );
		}
		return $out;
	}
	/** The Profile owner writes its protected category-plan field through native record authority. */
	private static function can_service_field( int $id, string $key ): bool {
		return Profile::SERVICE_CATEGORY_PLAN_META === $key ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_post_meta', $id, $key );
	}
	public static function can_import( string $scope ): bool {
		$type = self::type( $scope ); $object = $type ? get_post_type_object( $type ) : null;
		if ( 'bricks_template' === $type ) return Template_Workspace_Service::allowed() && Template_Workspace_Service::available() && $object && current_user_can( 'create_kiwe_templates' ) && current_user_can( $object->cap->edit_posts );
		$can_create = $object && ( current_user_can( $object->cap->create_posts ) || ( 'page' === $type && Backup_Workspace_Service::allowed() && ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_create_page_drafts' ) ) ) );
		if ( ! is_user_logged_in() || ! $object || ! $can_create || ! current_user_can( $object->cap->edit_posts ) || ( 'product' === $type && ! function_exists( 'wc_get_product' ) ) ) return false;
		$taxonomy = self::taxonomy( $scope );
		return ! $taxonomy || ( Backup_Workspace_Service::allowed() && get_taxonomy( $taxonomy ) && current_user_can( get_taxonomy( $taxonomy )->cap->manage_terms ) );
	}
	public static function can_export( string $scope, int $id ): bool {
		if ( self::collection( $scope ) ) return 0 === $id && self::can_export_collection( $scope );
		if ( ! is_user_logged_in() || ! $id ) return false;
		$type = self::type( $scope ); if ( ! $type || ( 'product' === $type && ! function_exists( 'wc_get_product' ) ) ) return false;
		if ( 'bricks_template' === $type && ( ! Template_Workspace_Service::allowed() || ! Template_Workspace_Service::available() ) ) return false;
		$tax = self::taxonomy( $scope );
		if ( $tax ) {
			$term = get_term( $id, $tax ); $object = get_taxonomy( $tax );
			return Backup_Workspace_Service::allowed() && $object && current_user_can( $object->cap->manage_terms ) && $term && ! is_wp_error( $term );
		}
		$post = get_post( $id );
		$allowed = $post && $post->post_type === $type && ! in_array( $post->post_status, [ 'trash','auto-draft','inherit' ], true ) && current_user_can( 'edit_post', $id ) && ( Backup_Workspace_Service::allowed() || 'bricks_template' === $type || (int) $post->post_author === get_current_user_id() );
		if ( $allowed && self::service_scope( $scope ) ) foreach ( self::service_definition()['fields'] as $key=>$field ) if ( ! self::can_service_field( $id, $key ) ) return false;
		return $allowed;
	}
	/** Woo variation work is authorized through its draft parent, as in the native product editor. */
	public static function can_edit_imported( int $id ): bool {
		$post = get_post( $id ); if ( ! $post ) return false;
		if ( 'product_variation' === $post->post_type ) { $parent = get_post( (int) $post->post_parent ); return $parent && 'product' === $parent->post_type && 'draft' === $parent->post_status && current_user_can( 'edit_post', (int) $parent->ID ); }
		return 'draft' === $post->post_status && current_user_can( 'edit_post', $id );
	}
	private static function safe( $value, int $depth = 0 ): bool {
		if ( $depth > 24 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_string( $value ) && strlen( $value ) > 1000000 ) return false;
		if ( is_array( $value ) ) { if ( count( $value ) > 2000 ) return false; foreach ( $value as $entry ) if ( ! self::safe( $entry, $depth + 1 ) ) return false; }
		return true;
	}
	private static function meta( int $id, array $keys, bool $term = false ): array {
		$out = [];
		foreach ( $keys as $key ) if ( metadata_exists( $term ? 'term' : 'post', $id, $key ) ) {
			$value = $term ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			if ( ! self::safe( $value ) ) throw new \RuntimeException( 'A supported content field exceeds the archive limit.' );
			$out[$key] = $value;
		}
		self::assert_seo_metadata( $out );
		return $out;
	}
	/** Shared content archive validation; copied content carries no source undo authority. */
	public static function assert_seo_metadata( array $meta ): void {
		if ( array_key_exists( '_kiwe_schema_graph_v1', $meta ) && is_wp_error( \DSA\Schema\Schema_Template_Service::validate( $meta['_kiwe_schema_graph_v1'] ) ) ) throw new Archive_Error( 'The archived schema graph needs a supported declarative format.' );
		foreach ( [ '_kiwe_seo_canonical_url','_kiwe_seo_facebook_image','_kiwe_seo_twitter_image' ] as $key ) if ( array_key_exists( $key, $meta ) && ( ! is_string( $meta[$key] ) || ( $meta[$key] !== '' && \DSA\Onboarding\Search_Defaults_Model::url( $meta[$key] ) === '' ) ) ) throw new Archive_Error( 'An archived SEO URL is unsupported.' );
		foreach ( [ '_kiwe_seo_facebook_title','_kiwe_seo_facebook_description','_kiwe_seo_twitter_title','_kiwe_seo_twitter_description' ] as $key ) if ( array_key_exists( $key, $meta ) && ( ! is_string( $meta[$key] ) || strlen( $meta[$key] ) > 1000 || str_contains( $meta[$key], "\0" ) || ! preg_match( '//u', $meta[$key] ) ) ) throw new Archive_Error( 'An archived sharing field is unsupported.' );
	}
	private static function term( int $id, string $taxonomy, array &$out, array $trail = [] ): void {
		$key = $taxonomy . ':' . $id; if ( isset( $out[$key] ) ) return;
		if ( isset( $trail[$key] ) || count( $trail ) > 30 || count( $out ) >= 1000 ) throw new \RuntimeException( 'The category hierarchy exceeds the archive limit.' );
		$term = get_term( $id, $taxonomy ); if ( ! $term || is_wp_error( $term ) ) throw new \RuntimeException( 'A category or tag changed during export. Try again.' );
		$trail[$key] = true;
		if ( $term->parent ) self::term( (int) $term->parent, $taxonomy, $out, $trail );
		$out[$key] = [ 'id'=>$id,'taxonomy'=>$taxonomy,'name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent'=>(int) $term->parent,'meta'=>self::meta( $id, self::TERM_META, true ) ];
	}
	private static function product( \WC_Product $product ): array {
		$type = $product->get_type(); if ( ! in_array( $type, [ 'simple','variable','grouped','external','variation' ], true ) ) throw new \RuntimeException( 'This third-party product type needs its own archive adapter.' );
		if ( $product->is_downloadable() ) throw new \RuntimeException( 'Downloadable product files need the media archive unit before this product can be exported.' );
		$out = [ 'type'=>$type ]; if ( 'variation' === $type ) $out['variationStatus'] = 'private' === $product->get_status( 'edit' ) ? 'private' : 'publish'; $data = $product->get_data();
		foreach ( self::PRODUCT_FIELDS as $name ) if ( array_key_exists( $name, $data ) ) {
			$getter = 'get_' . $name; $value = 'children' === $name && 'grouped' !== $type ? $product->$getter() : $product->$getter( 'edit' );
			if ( $value instanceof \WC_DateTime ) $value = $value->getTimestamp();
			if ( 'attributes' === $name && 'variation' !== $type ) $value = array_values( array_map( static fn( $attribute )=>[ 'id'=>$attribute->get_id(),'name'=>$attribute->get_name(),'options'=>$attribute->get_options(),'position'=>$attribute->get_position(),'visible'=>$attribute->get_visible(),'variation'=>$attribute->get_variation() ], $product->get_attributes( 'edit' ) ) );
			if ( ! self::safe( $value ) ) throw new \RuntimeException( 'A product field cannot be exported safely.' );
			$out[$name] = $value;
		}
		return $out;
	}
	private static function record( \WP_Post $post, array &$terms ): array {
		$ids = [];
		foreach ( self::taxes( $post->post_type ) as $taxonomy ) {
			$native = wp_get_object_terms( (int) $post->ID, $taxonomy, [ 'fields'=>'ids' ] ); if ( is_wp_error( $native ) ) throw new \RuntimeException( 'A content category could not be read.' );
			$ids[$taxonomy] = array_map( 'intval', $native ); foreach ( $ids[$taxonomy] as $id ) self::term( $id, $taxonomy, $terms );
		}
		$meta = self::meta( (int) $post->ID, self::POST_META ); $builder = self::meta( (int) $post->ID, self::BUILDER_META );
		$record = [ 'id'=>(int) $post->ID,'type'=>$post->post_type,'title'=>$post->post_title,'content'=>$post->post_content,'excerpt'=>$post->post_excerpt,'author'=>(int) $post->post_author,'date'=>$post->post_date,'dateGmt'=>$post->post_date_gmt,'slug'=>$post->post_name,'parent'=>(int) $post->post_parent,'menuOrder'=>(int) $post->menu_order,'featuredImage'=>(int) get_post_thumbnail_id( $post->ID ),'terms'=>$ids,'meta'=>$meta,'builder'=>$builder ];
		if ( 'bricks_template' === $post->post_type ) {
			$settings = get_post_meta( $post->ID, BRICKS_DB_TEMPLATE_SETTINGS, true );
			if ( '' === $settings ) $settings = [];
			if ( ! is_array( $settings ) || ! self::safe( $settings ) ) throw new \RuntimeException( 'The Bricks template settings are unsupported.' );
			// Bricks itself removes placement conditions when cloning a template.
			unset( $settings['templateConditions'] );
			$record['template'] = [ 'type'=>get_post_meta( $post->ID, BRICKS_DB_TEMPLATE_TYPE, true ),'settings'=>$settings ];
		}
		$service = self::service_definition();
		if ( $post->post_type === ( $service['postType'] ?? '' ) ) $record['serviceMeta'] = self::service_values( (int) $post->ID, $service );
		if ( in_array( $post->post_type, [ 'product','product_variation' ], true ) ) { $product = wc_get_product( $post->ID ); if ( ! $product ) throw new \RuntimeException( 'A product changed during export.' ); $record['product'] = self::product( $product ); }
		return $record;
	}
	/** Native Bricks fields shared by dependency review and copy recovery; no derived cache. */
	public static function template_data( int $id ): array {
		if ( ! self::can_export( 'template', $id ) || get_post( $id )->post_type !== 'bricks_template' ) throw new Archive_Error( 'A referenced Bricks template is missing or unavailable for your account.' );
		$settings = get_post_meta( $id, BRICKS_DB_TEMPLATE_SETTINGS, true );
		if ( '' === $settings ) $settings = [];
		if ( ! is_array( $settings ) ) throw new Archive_Error( 'A native Bricks template has unsupported settings.' );
		return [ 'builder'=>self::meta( $id, self::BUILDER_META ),'settings'=>$settings,'type'=>get_post_meta( $id, BRICKS_DB_TEMPLATE_TYPE, true ) ];
	}
	/** Publication may change slug/dates/status, but authored content/conditions must stay exact. */
	public static function template_fingerprint( int $id ): string {
		$data = self::template_data( $id ); $terms = []; $record = self::record( get_post( $id ), $terms );
		unset( $record['slug'], $record['date'], $record['dateGmt'] );
		return hash( 'sha256', (string) wp_json_encode( [ $record,$terms,$data ] ) );
	}
	private static function path( string $id, bool $stage = false ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) throw new \RuntimeException( 'Invalid content archive reference.' );
		$directory = Configuration_Snapshot_Service::private_directory( true );
		return $directory . DIRECTORY_SEPARATOR . ( $stage ? 'content-review-' : 'content-' ) . $id . '.kiwe';
	}
	private static function write( string $path, string $bytes ): void {
		if ( is_link( $path ) || file_exists( $path ) ) throw new \RuntimeException( 'The private archive reference is already in use.' );
		$stream = @fopen( $path, 'x+b' ); if ( ! $stream ) throw new \RuntimeException( 'The private archive could not be created.' );
		@chmod( $path, 0600 ); $written = fwrite( $stream, $bytes ); fclose( $stream );
		if ( $written !== strlen( $bytes ) ) { @unlink( $path ); throw new \RuntimeException( 'The private archive could not be saved.' ); }
	}
	private static function sweep(): void {
		$directory = Configuration_Snapshot_Service::private_directory(); if ( ! $directory ) return;
		foreach ( glob( $directory . DIRECTORY_SEPARATOR . 'content-*.kiwe' ) ?: [] as $path ) if ( ! is_link( $path ) && is_file( $path ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) @unlink( $path );
	}
	/** Freeze collection membership. Jobs and single-request exports share native selection and authority. */
	public function plan( string $scope, int $id, array $selection = [], int $maximum = self::MAX_RECORDS ): array {
		if ( ! self::can_export( $scope, $id ) ) throw new \RuntimeException( 'You can download only content your account is allowed to export.' );
		$filter = self::selection( $selection ); $bulk = self::collection( $scope );
		if ( ! $bulk && ! self::taxonomy( $scope ) && ( $filter['from'] || $filter['to'] ) ) throw new \RuntimeException( 'Date ranges apply to a collection, not one record.' );
		$type = self::type( $scope ); $tax = self::taxonomy( $scope ); $terms = []; $label = '';
		if ( $tax || $bulk ) {
			if ( $tax ) { self::term( $id, $tax, $terms ); $label = get_term( $id, $tax )->name; }
			else $label = [ 'posts'=>'Posts','pages'=>'Pages','products'=>'Products','templates'=>'Bricks templates','services'=>'Services' ][$scope];
			$args = [ 'post_type'=>$type,'post_status'=>[ 'publish','future','draft','pending','private' ],'posts_per_page'=>$maximum + 1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>false ];
			if ( $tax ) $args['tax_query'] = [ [ 'taxonomy'=>$tax,'terms'=>[ $id ],'include_children'=>true ] ];
			if ( ! Backup_Workspace_Service::allowed() && 'bricks_template' !== $type ) $args['author'] = get_current_user_id();
			if ( $filter['from'] || $filter['to'] ) { $date = [ 'column'=>'post_date','inclusive'=>true ]; if ( $filter['from'] ) $date['after'] = $filter['from'] . ' 00:00:00'; if ( $filter['to'] ) $date['before'] = $filter['to'] . ' 23:59:59'; $args['date_query'] = [ $date ]; }
			$query = new \WP_Query( $args );
			if ( $query->found_posts > $maximum || count( $query->posts ) > $maximum ) throw new \RuntimeException( 'This selection exceeds ' . $maximum . ' records. Choose a shorter date range; no partial archive was created.' );
			$ids = array_map( 'intval', $query->posts );
			if ( $bulk && ! $ids ) throw new \RuntimeException( 'No eligible records match this date range.' );
		} else { $ids = [ $id ]; $label = get_post( $id )->post_title; }
		$plan = [ 'scope'=>$scope,'sourceId'=>$id,'label'=>$label,'createdAt'=>gmdate( 'c' ),'ids'=>$ids,'selection'=>( $bulk || $filter['from'] || $filter['to'] ) ? $filter : null ];
		if ( self::service_scope( $scope ) ) $plan['serviceDefinition'] = self::service_definition();
		return $plan;
	}
	/** Capture only a bounded subset of a frozen selection using the v1 native adapters. */
	public function capture( array $plan, array $ids ): array {
		$scope = $plan['scope']; $id = $plan['sourceId']; $type = self::type( $scope ); $tax = self::taxonomy( $scope ); $terms = []; $records = [];
		if ( ! self::can_export( $scope, $id ) || array_diff( $ids, $plan['ids'] ) || count( $ids ) > self::MAX_RECORDS ) throw new \RuntimeException( 'The saved selection is unavailable for your account.' );
		if ( self::service_scope( $scope ) && ( $plan['serviceDefinition'] ?? null ) !== self::service_definition() ) throw new \RuntimeException( 'The Service connection or registered fields changed. Start a fresh backup.' );
		if ( $tax ) self::term( $id, $tax, $terms );
		foreach ( $ids as $post_id ) {
			if ( ! self::can_export( self::single_scope( $type ), $post_id ) ) throw new \RuntimeException( 'The complete collection cannot be exported with your current permissions.' );
			$post = get_post( $post_id ); $records[] = self::record( $post, $terms );
			if ( 'product' === $type && wc_get_product( $post_id )->is_type( 'variable' ) ) foreach ( wc_get_product( $post_id )->get_children() as $child ) {
				$variation = get_post( $child ); if ( ! $variation || 'product_variation' !== $variation->post_type || (int) $variation->post_parent !== $post_id ) throw new \RuntimeException( 'A product variation changed during export.' );
				$records[] = self::record( $variation, $terms );
			}
			if ( count( $records ) > self::MAX_RECORDS ) throw new \RuntimeException( 'This collection and its variations exceed 200 records. No partial archive was created.' );
		}
		$payload = [ 'scope'=>$scope,'sourceId'=>$id,'label'=>$plan['label'],'createdAt'=>$plan['createdAt'],'records'=>$records,'terms'=>array_values( $terms ) ];
		if ( isset( $plan['serviceDefinition'] ) ) $payload['serviceDefinition'] = $plan['serviceDefinition'];
		if ( $plan['selection'] ) $payload['selection'] = $plan['selection'];
		// Same validator is used for export and import, so unsupported dependencies are visible now.
		$this->validate( $payload, false ); return $payload;
	}
	public function export( string $scope, int $id, string $phrase, array $selection = [] ): array {
		$plan = $this->plan( $scope, $id, $selection ); $ids = $plan['ids']; $payload = $this->capture( $plan, $ids );
		self::sweep(); $directory = Configuration_Snapshot_Service::private_directory( true );
		if ( count( glob( $directory . DIRECTORY_SEPARATOR . 'content-*.kiwe' ) ?: [] ) >= 60 ) throw new \RuntimeException( 'Too many temporary content archives are retained. Retry after they expire.' );
		$bytes = Archive_Cipher::seal( $payload, self::SCHEMA, $phrase, [ 'createdAt'=>$payload['createdAt'] ], self::MAX_BYTES );
		$reference = bin2hex( random_bytes( 16 ) ); $path = self::path( $reference ); self::write( $path, $bytes );
		$authorization = [ 'actor'=>get_current_user_id(),'scope'=>$scope,'sourceId'=>$id,'recordIds'=>$ids,'hash'=>hash( 'sha256', $bytes ) ];
		if ( isset( $plan['serviceDefinition'] ) ) $authorization['serviceDefinition'] = $plan['serviceDefinition'];
		if ( ! set_transient( 'kiwe_content_export_' . $reference, $authorization, DAY_IN_SECONDS ) ) { @unlink( $path ); throw new \RuntimeException( 'The archive download could not be authorized.' ); }
		return [ 'id'=>$reference,'label'=>$plan['label'],'count'=>count( $ids ),'variations'=>count( $payload['records'] )-count( $ids ),'bytes'=>strlen( $bytes ),'expiresIn'=>DAY_IN_SECONDS,'selection'=>$payload['selection'] ?? null ];
	}
	public function download_path( string $id ): string {
		$authorization = get_transient( 'kiwe_content_export_' . $id );
		if ( ! is_array( $authorization ) || (int) $authorization['actor'] !== get_current_user_id() || ! self::can_export( $authorization['scope'], (int) $authorization['sourceId'] ) ) throw new \RuntimeException( 'This archive download is expired or unavailable for your account.' );
		if ( self::service_scope( $authorization['scope'] ) && ( $authorization['serviceDefinition'] ?? null ) !== self::service_definition() ) throw new \RuntimeException( 'The Service connection or registered fields changed after capture.' );
		foreach ( $authorization['recordIds'] ?? [] as $source_id ) if ( ! self::can_export( self::single_scope( self::type( $authorization['scope'] ) ), (int) $source_id ) ) throw new \RuntimeException( 'Your permission to download a record in this archive changed.' );
		$path = self::path( $id );
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES || ! hash_equals( $authorization['hash'], hash_file( 'sha256', $path ) ) ) throw new \RuntimeException( 'The archive failed its integrity check.' );
		return $path;
	}
	public function validate( array $payload, bool $import = true, array $references = [], bool $skip_missing_media = false ): array {
		$scope = $payload['scope'] ?? ''; $type = is_string( $scope ) ? self::type( $scope ) : '';
		if ( ! $type || ! is_int( $payload['sourceId'] ?? null ) || ! is_string( $payload['label'] ?? null ) || ! is_array( $payload['records'] ?? null ) || count( $payload['records'] ) > self::MAX_RECORDS || ! is_array( $payload['terms'] ?? null ) || count( $payload['terms'] ) > 1000 || ! self::safe( $payload ) ) throw new \RuntimeException( 'This content archive has an unsupported manifest.' );
		$service = self::service_scope( $scope ) ? self::service_definition() : [];
		if ( $service ? ( $payload['serviceDefinition'] ?? null ) !== $service : isset( $payload['serviceDefinition'] ) ) throw new \RuntimeException( 'The archived Service connection, taxonomies or registered fields no longer match this website.' );
		$bulk = self::collection( $scope );
		if ( $bulk && ( 0 !== $payload['sourceId'] || ! $payload['records'] || ! isset( $payload['selection'] ) ) ) throw new \RuntimeException( 'The collection manifest is incomplete.' );
		if ( isset( $payload['selection'] ) ) {
			$selection = $payload['selection'];
			if ( ! is_array( $selection ) || array_diff( array_keys( $selection ), [ 'from','to','timezone' ] ) || ! is_string( $selection['timezone'] ?? null ) || strlen( $selection['timezone'] ) > 80 ) throw new \RuntimeException( 'The archive date range is invalid.' );
			self::selection( [ 'from'=>$selection['from'] ?? null,'to'=>$selection['to'] ?? null ] );
			try { new \DateTimeZone( $selection['timezone'] ); } catch ( \Throwable $error ) { throw new \RuntimeException( 'The archive timezone is invalid.' ); }
			if ( ! $bulk && ! self::taxonomy( $scope ) ) throw new \RuntimeException( 'An individual record cannot contain a collection selection.' );
		}
		if ( $import && ! self::can_import( $scope ) ) throw new \RuntimeException( 'Your account cannot import this content type.' );
		$known = []; $terms = []; $warnings = []; $media = []; $builder = false;
		foreach ( $payload['terms'] as $term ) {
			if ( ! is_array( $term ) || array_diff( array_keys( $term ), [ 'id','taxonomy','name','slug','description','parent','meta' ] ) || ! is_int( $term['id'] ?? null ) || $term['id'] < 1 || ! in_array( $term['taxonomy'] ?? '', self::taxes( $type ), true ) || ! is_string( $term['name'] ?? null ) || ! trim( $term['name'] ) || strlen( $term['name'] ) > 800 || ! is_string( $term['slug'] ?? null ) || ! is_string( $term['description'] ?? null ) || ! is_int( $term['parent'] ?? null ) || ! is_array( $term['meta'] ?? null ) || array_diff( array_keys( $term['meta'] ), self::TERM_META ) ) throw new \RuntimeException( 'A category or tag in this archive is invalid.' );
			$key = $term['taxonomy'] . ':' . $term['id']; if ( isset( $terms[$key] ) ) throw new \RuntimeException( 'The archive repeats a category or tag.' ); $terms[$key] = $term;
			$object = get_taxonomy( $term['taxonomy'] ); if ( ! $object ) throw new \RuntimeException( 'A required category or tag taxonomy is unavailable.' );
			self::assert_seo_metadata( $term['meta'] );
			$existing = get_term_by( 'slug', $term['slug'], $term['taxonomy'] );
			if ( $import && ! current_user_can( $object->cap->assign_terms ) ) throw new \RuntimeException( 'Your account cannot assign the archived categories or tags.' );
			if ( $import && ! $existing && ! current_user_can( $object->cap->manage_terms ) ) throw new \RuntimeException( 'An archived category or tag is missing. A client administrator must restore it first.' );
			if ( $import && $existing ) $warnings[] = 'Reuse existing ' . ( $object->hierarchical ? 'category' : 'tag' ) . ': ' . $existing->name . '. Its current settings are kept.';
		}
		foreach ( $terms as $term ) {
			$trail = []; $cursor = $term;
			while ( $cursor['parent'] ) { $key = $cursor['taxonomy'] . ':' . $cursor['parent']; if ( ! isset( $terms[$key] ) || isset( $trail[$key] ) || count( $trail ) > 30 ) throw new \RuntimeException( 'The archive category hierarchy is incomplete or cyclic.' ); $trail[$key] = true; $cursor = $terms[$key]; }
		}
		$allowed = [ 'id','type','title','content','excerpt','author','date','dateGmt','slug','parent','menuOrder','featuredImage','terms','meta','builder','product','template','serviceMeta' ];
		foreach ( $payload['records'] as $record ) {
			if ( ! is_array( $record ) || array_diff( array_keys( $record ), $allowed ) || ! is_int( $record['id'] ?? null ) || $record['id'] < 1 || isset( $known[$record['id']] ) || ! in_array( $record['type'] ?? '', 'product' === $type ? [ 'product','product_variation' ] : [ $type ], true ) || ! is_array( $record['terms'] ?? null ) || ! is_array( $record['meta'] ?? null ) || array_diff( array_keys( $record['meta'] ), self::POST_META ) || ! is_array( $record['builder'] ?? null ) || array_diff( array_keys( $record['builder'] ), self::BUILDER_META ) ) throw new \RuntimeException( 'A content record in this archive is invalid.' );
			foreach ( [ 'title'=>800,'content'=>1000000,'excerpt'=>1000000,'date'=>30,'dateGmt'=>30,'slug'=>800 ] as $name=>$max ) if ( ! is_string( $record[$name] ?? null ) || strlen( $record[$name] ) > $max ) throw new \RuntimeException( 'A content field in this archive is invalid.' );
			foreach ( [ 'author','parent','menuOrder','featuredImage' ] as $name ) if ( ! is_int( $record[$name] ?? null ) || $record[$name] < 0 ) throw new \RuntimeException( 'A content reference in this archive is invalid.' );
			if ( $service ) {
				if ( ! is_array( $record['serviceMeta'] ?? null ) || array_diff( array_keys( $record['serviceMeta'] ), array_keys( $service['fields'] ) ) ) throw new \RuntimeException( 'The archive includes an unsupported Service field.' );
				foreach ( $record['serviceMeta'] as $key=>$value ) if ( wp_json_encode( self::service_value( $value, $service['fields'][$key] ) ) !== wp_json_encode( $value ) ) throw new \RuntimeException( 'An archived Service field has an invalid native value.' );
				if ( ! $service['hierarchical'] && $record['parent'] ) throw new \RuntimeException( 'This Service source does not support parent records.' );
			} elseif ( isset( $record['serviceMeta'] ) ) throw new \RuntimeException( 'Service fields are only supported on the connected Service source.' );
			if ( $import && 'bricks_template' !== $type && ! Backup_Workspace_Service::allowed() && $record['author'] !== get_current_user_id() ) throw new \RuntimeException( 'You can import only your own archived content.' );
			if ( isset( $payload['selection'] ) && 'product_variation' !== $record['type'] ) { $date = $record['date']; if ( ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $date ) || ( $selection['from'] && strcmp( $date, $selection['from'] . ' 00:00:00' ) < 0 ) || ( $selection['to'] && strcmp( $date, $selection['to'] . ' 23:59:59' ) > 0 ) ) throw new \RuntimeException( 'An archived record falls outside the selected date range.' ); }
			if ( 'bricks_template' === $type ) {
				$template = $record['template'] ?? null;
				if ( ! Template_Workspace_Service::available() || ! is_array( $template ) || array_diff( array_keys( $template ), [ 'type','settings' ] ) || ! is_string( $template['type'] ?? null ) || ! isset( Template_Workspace_Service::types()[$template['type']] ) || ! is_array( $template['settings'] ?? null ) || isset( $template['settings']['templateConditions'] ) ) throw new \RuntimeException( 'The Bricks template type or settings are unavailable or unsupported.' );
				$builder = true;
			} elseif ( isset( $record['template'] ) ) throw new \RuntimeException( 'Template fields are only supported on Bricks templates.' );
			self::assert_seo_metadata( $record['meta'] );
			$known[$record['id']] = $record;
			foreach ( $record['terms'] as $taxonomy=>$ids ) { if ( ! in_array( $taxonomy, self::taxes( $type ), true ) || ! is_array( $ids ) ) throw new \RuntimeException( 'The archive contains an unsupported taxonomy assignment.' ); foreach ( $ids as $id ) if ( ! is_int( $id ) || ! isset( $terms[$taxonomy . ':' . $id] ) ) throw new \RuntimeException( 'A category assignment is missing from the archive.' ); }
			if ( $record['featuredImage'] ) $media[] = $record['featuredImage'];
			if ( $record['builder'] ) $builder = true;
			if ( isset( $record['product'] ) ) {
				$product = $record['product'];
				if ( 'variation' === ( $product['type'] ?? '' ) && ! in_array( $product['variationStatus'] ?? '', [ 'publish','private' ], true ) ) throw new \RuntimeException( 'An archived variation state is invalid.' );
				if ( ! function_exists( 'wc_get_product' ) || ! is_array( $product ) || array_diff( array_keys( $product ), [ ...self::PRODUCT_FIELDS,'type','variationStatus' ] ) || ! in_array( $product['type'] ?? '', [ 'simple','variable','grouped','external','variation' ], true ) || ( 'product_variation' === $record['type'] ) !== ( 'variation' === $product['type'] ) || ! empty( $product['downloadable'] ) ) throw new \RuntimeException( 'An archived product type or file dependency is unsupported.' );
				if ( ! is_string( $product['sku'] ?? null ) || ! is_array( $product['attributes'] ?? null ) || ! is_array( $product['gallery_image_ids'] ?? [] ) || ! is_array( $product['children'] ?? [] ) ) throw new \RuntimeException( 'An archived product field is invalid.' );
				if ( $import && $product['sku'] && wc_get_product_id_by_sku( $product['sku'] ) ) $warnings[] = 'SKU “' . $product['sku'] . '” is already used. The imported draft will leave its SKU empty.';
				if ( ! empty( $product['image_id'] ) ) $media[] = (int) $product['image_id']; foreach ( $product['gallery_image_ids'] ?? [] as $image ) $media[] = (int) $image;
				foreach ( $product['attributes'] as $attribute ) if ( 'variation' !== $product['type'] && ( ! is_array( $attribute ) || array_diff( array_keys( $attribute ), [ 'id','name','options','position','visible','variation' ] ) || ! is_string( $attribute['name'] ?? null ) || ! is_array( $attribute['options'] ?? null ) || ( ! empty( $attribute['id'] ) && ! taxonomy_exists( $attribute['name'] ) ) ) ) throw new \RuntimeException( 'A required product attribute taxonomy is unavailable.' );
			} elseif ( 'product' === $type ) throw new \RuntimeException( 'An archived product is missing its native product data.' );
		}
		foreach ( $known as $record ) {
			if ( 'product' === $record['type'] ) foreach ( [ 'upsell_ids','cross_sell_ids' ] as $relationship ) {
				$targets = $record['product'][$relationship] ?? [];
				if ( ! is_array( $targets ) || ! array_is_list( $targets ) || count( $targets ) > 1000 || count( array_unique( $targets, SORT_REGULAR ) ) !== count( $targets ) ) throw new \RuntimeException( 'An archived recommendation list is invalid or too large.' );
				foreach ( $targets as $target ) {
					if ( ! is_int( $target ) || $target < 1 || ( isset( $known[$target] ) && 'product' !== $known[$target]['type'] ) ) throw new \RuntimeException( 'An archived recommendation target is invalid.' );
					if ( ! isset( $known[$target] ) && ! in_array( $target, $references, true ) && ( ! wc_get_product( $target ) || ! current_user_can( 'edit_post', $target ) ) ) throw new \RuntimeException( 'A product recommendation dependency is missing or unavailable for your account.' );
				}
			}
			if ( 'product_variation' === $record['type'] && ( ! isset( $known[$record['parent']] ) || 'variable' !== ( $known[$record['parent']]['product']['type'] ?? '' ) ) ) throw new \RuntimeException( 'A variation is missing its parent product.' );
			if ( 'grouped' === ( $record['product']['type'] ?? '' ) ) foreach ( $record['product']['children'] ?? [] as $child ) if ( ! isset( $known[$child] ) && ! in_array( $child, $references, true ) && ! wc_get_product( (int) $child ) ) throw new \RuntimeException( 'A grouped product dependency is missing. Restore its child product first.' );
			if ( ( 'page' === $type || $service ) && $record['parent'] ) {
				if ( $record['parent'] === $record['id'] || ( isset( $known[$record['parent']] ) && $known[$record['parent']]['type'] !== $type ) || ( ! isset( $known[$record['parent']] ) && ! in_array( $record['parent'], $references, true ) && ( ! get_post( $record['parent'] ) || $type !== get_post( $record['parent'] )->post_type || ! current_user_can( 'edit_post', $record['parent'] ) ) ) ) throw new \RuntimeException( 'The parent content record is missing or unavailable for your account.' );
			}
		}
		if ( $builder && $import && ( ! class_exists( '\Bricks\Helpers' ) || ! Template_Workspace_Service::allowed() ) ) throw new \RuntimeException( 'Bricks content requires the active Bricks theme and permission to work on templates.' );
		if ( ! $skip_missing_media ) foreach ( array_unique( $media ) as $id ) if ( ! wp_attachment_is_image( $id ) ) throw new \RuntimeException( 'An archived image is missing. Restore media before importing this content.' );
		$tax = self::taxonomy( $scope );
		if ( $tax ) foreach ( $known as $record ) {
			if ( 'product_variation' === $record['type'] ) continue; $belongs = false;
			foreach ( $record['terms'][$tax] ?? [] as $term_id ) { $cursor = $terms[$tax . ':' . $term_id]; for ( $depth=0; $depth<32; $depth++ ) { if ( $cursor['id'] === $payload['sourceId'] ) { $belongs = true; break; } if ( ! $cursor['parent'] ) break; $cursor = $terms[$tax . ':' . $cursor['parent']]; } }
			if ( ! $belongs ) throw new \RuntimeException( 'A collection record is not assigned to the archived category.' );
		}
		if ( $tax && ! isset( $terms[$tax . ':' . $payload['sourceId']] ) ) throw new \RuntimeException( 'The category itself is missing from this collection archive.' );
		if ( ! $tax && ! $bulk && ( count( array_filter( $known, static fn( $record )=>'product_variation' !== $record['type'] ) ) !== 1 || ! isset( $known[$payload['sourceId']] ) ) ) throw new \RuntimeException( 'This individual archive has an invalid collection manifest.' );
		return [ 'known'=>$known,'terms'=>$terms,'warnings'=>array_values( array_unique( $warnings ) ) ];
	}
	public static function dependency_proof( array $payload, array $owned_terms = [] ): string {
		$state = [];
		if ( self::service_scope( $payload['scope'] ) ) $state[] = self::service_definition();
		foreach ( $payload['terms'] as $term ) { $native = get_term_by( 'slug', $term['slug'], $term['taxonomy'] ); $value = $native ? [ (int) $native->term_id, $native->name, (int) $native->parent, $native->description ] : null; $guard = $native ? ( $owned_terms[$term['taxonomy'] . ':' . $native->term_id] ?? null ) : null; $state[] = $guard && hash_equals( $guard, hash( 'sha256', (string) wp_json_encode( $value ) ) ) ? null : $value; }
		foreach ( $payload['records'] as $record ) { $state[] = [ $record['id'], (bool) get_userdata( $record['author'] ), $record['parent'], get_post( $record['parent'] )->post_status ?? null ]; if ( isset( $record['product'] ) ) $state[] = wc_get_product_id_by_sku( $record['product']['sku'] ); }
		return hash( 'sha256', (string) wp_json_encode( $state ) );
	}
	/** Validate structure before touching identifiers; map only one explicit completed recovery. */
	public function reviewed_payload( array $payload, array $references = [], string $media_recovery = '', string $expected_proof = '', bool $files = false ): array {
		$validation = $this->validate( $payload, true, $references, '' !== $media_recovery ); $recovery = null;
		if ( $media_recovery ) {
			$recovery = ( new Media_Archive_Job_Service() )->recovery_map( $media_recovery, $files );
			if ( $expected_proof && ! hash_equals( $expected_proof, $recovery['proof'] ) ) throw new Archive_Error( 'The selected media recovery changed. Review a new content import.' );
			$payload = Media_Archive_Service::remap_content( $payload, $recovery );
			$validation = $this->validate( $payload, true, $references );
		}
		return [ 'payload'=>$payload,'validation'=>$validation,'recovery'=>$recovery ];
	}
	public function preview( string $bytes, string $phrase, string $scope, array $references = [], string $media_recovery = '' ): array {
		$payload = Archive_Cipher::open( $bytes, self::SCHEMA, $phrase, self::MAX_BYTES );
		if ( ! is_string( $payload['scope'] ?? null ) || ! self::type( $scope ) || self::type( $payload['scope'] ) !== self::type( $scope ) || self::taxonomy( $payload['scope'] ) !== self::taxonomy( $scope ) ) throw new \RuntimeException( 'Choose an archive for this content area.' );
		$checked = $this->reviewed_payload( $payload, $references, $media_recovery, '', true ); $payload = $checked['payload']; $validation = $checked['validation']; self::sweep();
		$directory = Configuration_Snapshot_Service::private_directory( true );
		if ( count( glob( $directory . DIRECTORY_SEPARATOR . 'content-*.kiwe' ) ?: [] ) >= 60 ) throw new \RuntimeException( 'Too many temporary content archives are retained. Retry after they expire.' );
		$id = bin2hex( random_bytes( 16 ) ); self::write( self::path( $id, true ), $bytes );
		$review = [ 'actor'=>get_current_user_id(),'fileHash'=>hash( 'sha256', $bytes ),'dependencyHash'=>self::dependency_proof( $payload ),'scope'=>$payload['scope'] ];
		if ( $references ) $review['references'] = $references;
		if ( $checked['recovery'] ) { $review['mediaRecovery'] = $media_recovery; $review['mediaProof'] = $checked['recovery']['proof']; }
		if ( ! set_transient( 'kiwe_content_review_' . $id, $review, self::REVIEW_SECONDS ) ) { @unlink( self::path( $id, true ) ); throw new \RuntimeException( 'The content import review could not be saved.' ); }
		$items = []; foreach ( $payload['records'] as $record ) if ( 'product_variation' !== $record['type'] ) { $author = get_userdata( $record['author'] ); $items[] = [ 'title'=>$record['title'],'type'=>$record['type'],'author'=>$author ? $author->display_name : 'Your account (original author unavailable)','sourceExists'=>(bool) get_post( $record['id'] ) ]; }
		return [ 'token'=>$id,'scope'=>$payload['scope'],'label'=>$payload['label'],'items'=>$items,'count'=>count( $items ),'variations'=>count( $payload['records'] )-count( $items ),'warnings'=>$validation['warnings'],'categoryCount'=>count( $payload['terms'] ),'selection'=>$payload['selection'] ?? null,'recoveredMedia'=>'' !== $media_recovery,'includes'=>[ 'Native text and SEO','Categories and tags','Existing local image references' ],'excludes'=>[ 'Media files','Orders and accounts','Navigation placement','Third-party metadata','Bricks placement conditions and global theme settings' ],'mode'=>'new-drafts' ];
	}
	private static function create_term( array $term, int $parent, array &$created ): int {
		$existing = get_term_by( 'slug', $term['slug'], $term['taxonomy'] ); if ( $existing ) return (int) $existing->term_id;
		$object = get_taxonomy( $term['taxonomy'] ); if ( ! $object || ! current_user_can( $object->cap->manage_terms ) ) throw new \RuntimeException( 'Your permission to restore this category changed.' );
		$result = wp_insert_term( $term['name'], $term['taxonomy'], [ 'slug'=>$term['slug'],'description'=>wp_kses_post( $term['description'] ),'parent'=>$parent ] );
		if ( is_wp_error( $result ) ) throw new \RuntimeException( 'An archived category or tag could not be restored.' );
		$id = (int) $result['term_id']; $created[] = [ $id,$term['taxonomy'] ];
		$native = get_term( $id, $term['taxonomy'] );
		if ( ! $native || is_wp_error( $native ) || $native->name !== $term['name'] || $native->slug !== sanitize_title( $term['slug'] ) || $native->description !== wp_kses_post( $term['description'] ) || (int) $native->parent !== $parent ) throw new \RuntimeException( 'Category fields or hierarchy readback failed.' );
		foreach ( $term['meta'] as $key=>$value ) { update_term_meta( $id, $key, wp_slash( $value ) ); if ( wp_json_encode( get_term_meta( $id, $key, true ) ) !== wp_json_encode( $value ) ) throw new \RuntimeException( 'Category SEO readback failed.' ); }
		return $id;
	}
	private static function create_product( array $record, int $parent, array &$created, string $marker ): int {
		$data = $record['product']; $class = [ 'simple'=>'WC_Product_Simple','variable'=>'WC_Product_Variable','grouped'=>'WC_Product_Grouped','external'=>'WC_Product_External','variation'=>'WC_Product_Variation' ][$data['type']];
		$product = new $class(); $props = $data; $props['slug'] = $marker; unset( $props['type'],$props['children'],$props['variationStatus'] );
		$props['name'] = sanitize_text_field( $props['name'] ); $props['description'] = wp_kses_post( $props['description'] ); $props['short_description'] = wp_kses_post( $props['short_description'] ); // Woo requires publish/private children to keep variations editable; their parent stays draft.
		$props['status'] = 'variation' === $data['type'] ? $data['variationStatus'] : 'draft';
		if ( $props['sku'] && wc_get_product_id_by_sku( $props['sku'] ) ) $props['sku'] = '';
		if ( 'variation' !== $data['type'] ) $props['attributes'] = array_map( static function( $input ) { $attribute = new \WC_Product_Attribute(); foreach ( [ 'id','name','options','position','visible','variation' ] as $name ) { $setter = 'set_' . $name; $attribute->$setter( $input[$name] ); } return $attribute; }, $data['attributes'] );
		if ( $parent ) $props['parent_id'] = $parent;
		$result = $product->set_props( $props ); if ( is_wp_error( $result ) ) throw new \RuntimeException( 'The native product validator rejected an archived field.' );
		$expected = self::product( $product );
		$id = (int) $product->save(); if ( ! $id ) throw new \RuntimeException( 'The native product draft could not be saved.' ); $created[] = $id;
		$native = wc_get_product( $id ); if ( ! $native ) throw new \RuntimeException( 'The product draft could not be read back.' );
		$actual = self::product( $native ); unset( $expected['children'], $actual['children'] );
		// Woo derives an unmanaged variable parent's stock from its children, which are restored next.
		if ( 'variable' === $data['type'] && ! $data['manage_stock'] ) unset( $expected['stock_status'], $actual['stock_status'] );
		if ( wp_json_encode( $actual ) !== wp_json_encode( $expected ) ) throw new \RuntimeException( 'Product field readback failed.' );
		return $id;
	}
	public function apply( string $token, string $phrase, ?callable $observer = null, array $known_map = [] ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) throw new \RuntimeException( 'Invalid import review.' );
		$review = get_transient( 'kiwe_content_review_' . $token );
		if ( ! is_array( $review ) || $review['actor'] !== get_current_user_id() || ! self::can_import( $review['scope'] ) ) throw new \RuntimeException( 'This review expired or is unavailable for your account.' );
		if ( isset( $review['completed'] ) ) return $review['completed'];
		$path = self::path( $token, true ); if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES || ! hash_equals( $review['fileHash'], hash_file( 'sha256', $path ) ) ) throw new \RuntimeException( 'The reviewed archive changed.' );
		$payload = Archive_Cipher::open( (string) file_get_contents( $path ), self::SCHEMA, $phrase, self::MAX_BYTES );
		$checked = $this->reviewed_payload( $payload, $review['references'] ?? [], $review['mediaRecovery'] ?? '', $review['mediaProof'] ?? '', true ); $payload = $checked['payload']; $validated = $checked['validation'];
		if ( ! hash_equals( $review['dependencyHash'], self::dependency_proof( $payload ) ) ) throw new \RuntimeException( 'A category, author or product dependency changed. Review the file again.' );
		$lock = 'kiwe_content_import_' . $token; if ( ! add_option( $lock, time(), '', false ) ) throw new \RuntimeException( 'This import is already running. Refresh before retrying.' );
		$created = []; $created_terms = []; $map = []; $term_map = []; $scope = null; $term_scope = null;
		// Journal our new IDs before downstream save hooks can throw before returning them.
		$mark_insert = static function( $data, $args, $raw, $update ) use ( &$scope ) { if ( ! $update && $scope && ( $raw['post_name'] ?? '' ) === $scope['marker'] && ( $data['post_name'] ?? '' ) !== $scope['marker'] ) throw new \RuntimeException( 'A native save filter changed the import journal marker.' ); return $data; };
		$capture_insert = static function( $new, $old, $post ) use ( &$scope, &$created, $observer ) { if ( 'new' === $old && $scope && $post->post_type === $scope['type'] && $post->post_name === $scope['marker'] ) { $created[] = (int) $post->ID; if ( $observer ) $observer( 'post', (int) $post->ID, $scope ); } };
		$capture_term = static function( $id, $tt_id, $tax ) use ( &$term_scope, &$created_terms, $observer ) { if ( $term_scope && $tax === $term_scope['taxonomy'] ) { $native = get_term( $id, $tax ); if ( $native && ! is_wp_error( $native ) && $native->slug === sanitize_title( $term_scope['slug'] ) ) { $created_terms[] = [ (int) $id,$tax ]; if ( $observer ) $observer( 'term', (int) $id, [ 'taxonomy'=>$tax ] ); } } };
		add_filter( 'wp_insert_post_data', $mark_insert, PHP_INT_MAX, 4 ); add_action( 'transition_post_status', $capture_insert, PHP_INT_MIN, 3 ); add_action( 'create_term', $capture_term, PHP_INT_MIN, 3 );
		try {
			$remaining = $validated['terms'];
			while ( $remaining ) {
				$progress = false;
				foreach ( $remaining as $key=>$term ) if ( ! $term['parent'] || isset( $term_map[$term['taxonomy'] . ':' . $term['parent']] ) ) { $term_scope = get_term_by( 'slug', $term['slug'], $term['taxonomy'] ) ? null : $term; $term_map[$key] = self::create_term( $term, $term['parent'] ? $term_map[$term['taxonomy'] . ':' . $term['parent']] : 0, $created_terms ); if ( $observer && $term_scope ) $observer( 'term', $term_map[$key], [ 'taxonomy'=>$term['taxonomy'] ] ); $term_scope = null; unset( $remaining[$key] ); $progress = true; }
				if ( ! $progress ) throw new \RuntimeException( 'The category hierarchy cannot be restored.' );
			}
			$records = $payload['records']; usort( $records, static fn( $a,$b )=>(int) ( 'product_variation' === $a['type'] ) <=> (int) ( 'product_variation' === $b['type'] ) );
			foreach ( $records as $record ) {
				if ( ! self::can_import( $review['scope'] ) ) throw new \RuntimeException( 'Your import permissions changed.' );
				$author = Backup_Workspace_Service::allowed() && get_userdata( $record['author'] ) ? $record['author'] : get_current_user_id();
				$parent = 'product_variation' === $record['type'] ? ( $map[$record['parent']] ?? 0 ) : 0;
				$scope = [ 'type'=>$record['type'],'marker'=>'kiwe-import-' . $token . '-' . $record['id'] ];
				if ( isset( $record['product'] ) ) $id = self::create_product( $record, $parent, $created, $scope['marker'] );
				else {
					$id = wp_insert_post( [ 'post_type'=>$record['type'],'post_name'=>$scope['marker'],'post_status'=>'draft','post_title'=>wp_slash( sanitize_text_field( $record['title'] ) ),'post_content'=>wp_slash( wp_kses_post( $record['content'] ) ),'post_excerpt'=>wp_slash( wp_kses_post( $record['excerpt'] ) ),'post_author'=>$author,'post_date'=>$record['date'],'post_date_gmt'=>$record['dateGmt'],'menu_order'=>$record['menuOrder'] ], true );
					if ( is_wp_error( $id ) || ! $id ) throw new \RuntimeException( 'An imported draft could not be saved.' ); $created[] = (int) $id;
				}
				if ( $observer ) $observer( 'post', (int) $id, $scope ); $scope = null; $map[$record['id']] = (int) $id;
				if ( is_wp_error( wp_update_post( [ 'ID'=>$id,'post_name'=>$record['slug'] ], true ) ) ) throw new \RuntimeException( 'The imported draft slug could not be restored.' );
				if ( isset( $record['product'] ) && is_wp_error( wp_update_post( [ 'ID'=>$id,'post_author'=>$author ], true ) ) ) throw new \RuntimeException( 'The product author could not be restored.' );
				foreach ( $record['terms'] as $taxonomy=>$ids ) { $wanted = array_map( static fn( $term_id )=>$term_map[$taxonomy . ':' . $term_id], $ids ); $result = wp_set_object_terms( $id, $wanted, $taxonomy ); if ( is_wp_error( $result ) ) throw new \RuntimeException( 'An imported category assignment failed.' ); $actual = array_map( 'intval', wp_get_object_terms( $id, $taxonomy, [ 'fields'=>'ids' ] ) ); sort( $wanted ); sort( $actual ); if ( $wanted !== $actual ) throw new \RuntimeException( 'Category assignment readback failed.' ); }
				if ( $record['featuredImage'] ) { set_post_thumbnail( $id, $record['featuredImage'] ); if ( (int) get_post_thumbnail_id( $id ) !== $record['featuredImage'] ) throw new \RuntimeException( 'The imported cover image could not be confirmed.' ); }
				foreach ( [ ...$record['meta'],...$record['builder'] ] as $key=>$value ) { update_post_meta( $id, $key, wp_slash( $value ) ); if ( wp_json_encode( get_post_meta( $id, $key, true ) ) !== wp_json_encode( $value ) ) throw new \RuntimeException( 'An imported SEO or builder field could not be confirmed.' ); }
				if ( isset( $record['serviceMeta'] ) ) foreach ( $record['serviceMeta'] as $key=>$value ) {
					if ( ! self::can_service_field( $id, $key ) ) throw new \RuntimeException( 'Your account cannot restore a registered Service field.' );
					update_post_meta( $id, $key, wp_slash( 'boolean' === $payload['serviceDefinition']['fields'][$key] ? ( $value ? '1' : '0' ) : $value ) );
					if ( ! metadata_exists( 'post', $id, $key ) || wp_json_encode( self::service_value( get_post_meta( $id, $key, true ), $payload['serviceDefinition']['fields'][$key] ) ) !== wp_json_encode( $value ) ) throw new \RuntimeException( 'A registered Service field failed native readback.' );
				}
				if ( isset( $record['template'] ) ) foreach ( [ BRICKS_DB_TEMPLATE_TYPE=>$record['template']['type'],BRICKS_DB_TEMPLATE_SETTINGS=>$record['template']['settings'] ] as $key=>$value ) { update_post_meta( $id, $key, wp_slash( $value ) ); if ( wp_json_encode( get_post_meta( $id, $key, true ) ) !== wp_json_encode( $value ) ) throw new \RuntimeException( 'Bricks template metadata readback failed.' ); }
				$native = get_post( $id ); if ( ! $native || ( 'product_variation' === $record['type'] ? $record['product']['variationStatus'] : 'draft' ) !== $native->post_status || (int) $native->post_author !== $author || ( ! isset( $record['product'] ) && ( $native->post_title !== sanitize_text_field( $record['title'] ) || $native->post_content !== wp_kses_post( $record['content'] ) || $native->post_excerpt !== wp_kses_post( $record['excerpt'] ) ) ) ) throw new \RuntimeException( 'The imported draft failed native readback.' );
			}
			$this->reconcile( $records, $map + $known_map, $review['references'] ?? [] );
			$result = [ 'ids'=>array_values( $map ),'count'=>count( array_filter( $records, static fn( $record )=>'product_variation' !== $record['type'] ) ),'categoryId'=>self::taxonomy( $payload['scope'] ) ? $term_map[self::taxonomy( $payload['scope'] ) . ':' . $payload['sourceId']] : null,'state'=>'drafts' ];
			if ( $observer ) { $result['sourceMap'] = $map; $result['createdTerms'] = array_values( array_unique( $created_terms, SORT_REGULAR ) ); }
			$review['completed'] = $result;
			if ( ! set_transient( 'kiwe_content_review_' . $token, $review, self::REVIEW_SECONDS ) ) throw new \RuntimeException( 'The import completion receipt could not be saved.' );
			if ( $observer ) $observer( 'complete', 0, $result );
			@unlink( $path ); return $result;
		} catch ( \Throwable $error ) {
			$scope = null; $cleanup = true;
			foreach ( array_reverse( array_unique( $created ) ) as $id ) { wp_delete_post( $id, true ); if ( get_post( $id ) ) $cleanup = false; }
			foreach ( array_reverse( $created_terms ) as [ $id,$tax ] ) {
				$term = get_term( $id, $tax ); if ( ! $term || is_wp_error( $term ) ) continue;
				$users = get_objects_in_term( $id, $tax );
				if ( is_wp_error( $users ) || $users ) { $cleanup = false; continue; }
				wp_delete_term( $id, $tax ); if ( get_term( $id, $tax ) ) $cleanup = false;
			}
			delete_transient( 'kiwe_content_review_' . $token ); @unlink( $path );
			throw new \RuntimeException( $error->getMessage() . ( $cleanup ? ' Newly created drafts were removed.' : ' Some new records could not be removed; ask the Super Admin to inspect this import before retrying.' ) );
		} finally { $scope = null; $term_scope = null; remove_filter( 'wp_insert_post_data', $mark_insert, PHP_INT_MAX ); remove_action( 'transition_post_status', $capture_insert, PHP_INT_MIN ); remove_action( 'create_term', $capture_term, PHP_INT_MIN ); delete_option( $lock ); }
	}
	/** Re-run native relationships after all frames have their new IDs. Never writes original records. */
	public function reconcile( array $records, array $map, array $references = [] ): void {
			foreach ( $records as $record ) {
				$id = $map[$record['id']];
				if ( $id === $record['id'] || ! self::can_edit_imported( $id ) || get_post( $id )->post_type !== $record['type'] ) throw new \RuntimeException( 'An imported draft changed before relationships were restored.' );
				if ( 'bricks_template' === $record['type'] ) {
					$templates = []; foreach ( $map as $source=>$copy ) $templates[$source] = [ 'id'=>$copy ];
					$fields = $record['builder'] + [ BRICKS_DB_TEMPLATE_SETTINGS=>$record['template']['settings'] ];
					foreach ( $fields as $key=>$value ) {
						$value = Bricks_Design_Snapshot_Service::remap_value( $value, [], $templates );
						update_post_meta( $id, $key, wp_slash( $value ) );
						if ( wp_json_encode( get_post_meta( $id, $key, true ) ) !== wp_json_encode( $value ) ) throw new Archive_Error( 'A recovered Bricks template reference failed native readback.' );
					}
				}
				if ( ( 'page' === $record['type'] || ( self::service_definition()['postType'] ?? '' ) === $record['type'] ) && $record['parent'] && ( ! in_array( $record['parent'], $references, true ) || isset( $map[$record['parent']] ) ) ) { $parent = $map[$record['parent']] ?? $record['parent']; wp_update_post( [ 'ID'=>$id,'post_parent'=>$parent ] ); if ( (int) get_post( $id )->post_parent !== $parent ) throw new \RuntimeException( 'Content hierarchy readback failed.' ); }
				if ( 'grouped' === ( $record['product']['type'] ?? '' ) && ! array_diff( array_intersect( $record['product']['children'] ?? [], $references ), array_keys( $map ) ) ) { $product = wc_get_product( $id ); $children = array_map( static fn( $child )=>$map[$child] ?? $child, $record['product']['children'] ?? [] ); $product->set_children( $children ); $product->save(); if ( wc_get_product( $id )->get_children( 'edit' ) !== $children ) throw new \RuntimeException( 'Grouped product dependency readback failed.' ); }
				if ( 'product' === $record['type'] ) foreach ( [ 'upsell_ids','cross_sell_ids' ] as $relationship ) {
					$original = $record['product'][$relationship] ?? [];
					if ( array_diff( array_intersect( $original, $references ), array_keys( $map ) ) ) continue;
					$wanted = array_map( static fn( $target )=>$map[$target] ?? $target, $original ); $product = wc_get_product( $id );
					if ( $product->{'get_'.$relationship}( 'edit' ) === $wanted ) continue;
					$product->{'set_'.$relationship}( $wanted ); $product->save();
					if ( wc_get_product( $id )->{'get_'.$relationship}( 'edit' ) !== $wanted ) throw new \RuntimeException( 'Product recommendation relationship readback failed.' );
				}
				if ( 'variable' === ( $record['product']['type'] ?? '' ) ) {
					\WC_Product_Variable::sync( $id );
					$wanted = []; foreach ( $records as $child ) if ( 'product_variation' === $child['type'] && $child['parent'] === $record['id'] ) $wanted[] = $map[$child['id']];
					$actual = wc_get_product( $id )->get_children(); sort( $wanted ); sort( $actual );
					if ( $wanted !== $actual ) throw new \RuntimeException( 'The restored product variation collection failed native readback.' );
					if ( wc_get_product( $id )->get_stock_status( 'edit' ) !== $record['product']['stock_status'] ) throw new \RuntimeException( 'The restored variable product stock failed native readback.' );
				}
			}
	}
}
