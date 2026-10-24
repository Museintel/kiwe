<?php
namespace DSA\Bricks;

use DSA\Commerce\Linked_Products_Service;
use DSA\Commerce\Product_Context_Service;
use DSA\Commerce\Store_Analytics_Service;
use DSA\Access\Tag_Group_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native public projections for builder authoring; no business storage, sessions or background work. */
final class Commerce_Binding_Service {
	private static ?array $offer_ids = null;
	public static function invalidate(): void { self::$offer_ids = null; }
	public function __construct( private ?Linked_Products_Service $linked, private ?Store_Analytics_Service $offers ) {}

	public static function record_fields(): array {
		$out = [];
		foreach ( [ 'id'=>['post_id','number'], 'title'=>['post_title','text'], 'url'=>['post_url','link'], 'image'=>['featured_image','image'], 'price'=>['woo_product_price','text'], 'regularPrice'=>['woo_product_regular_price','text'], 'salePrice'=>['woo_product_sale_price','text'], 'sku'=>['woo_product_sku','text'], 'description'=>['post_content','text'], 'summary'=>['woo_product_excerpt','text'], 'imageAlt'=>['kiwe_product_image_alt','text'], 'priceText'=>['kiwe_product_price_text','text'] ] as $key=>[$tag,$kind] ) {
			$out['commerce.product.'.$key] = [ 'tag'=>'{'.$tag.'}', 'kind'=>$kind, 'owner'=>'woocommerce', 'scope'=>'current-public-product' ];
		}
		foreach ( self::pair_fields() as $key=>$kind ) $out['commerce.offer.'.$key] = [ 'tag'=>'{kiwe_pair_'.$key.'}', 'kind'=>$kind, 'owner'=>'kiwe-product-offer-metadata', 'scope'=>'current-public-trigger-product' ];
		return $out;
	}

	private static function pair_fields(): array {
		return [ 'id'=>'number', 'title'=>'text', 'trigger_id'=>'number', 'trigger_title'=>'text', 'trigger_url'=>'link', 'trigger_image'=>'image', 'trigger_image_alt'=>'text', 'trigger_price'=>'text', 'trigger_price_text'=>'text', 'companion_id'=>'number', 'companion_title'=>'text', 'companion_url'=>'link', 'companion_image'=>'image', 'companion_image_alt'=>'text', 'companion_price'=>'text', 'companion_price_text'=>'text', 'discount'=>'number', 'discount_type'=>'text', 'discount_scope'=>'text', 'discount_label'=>'text', 'scope_label'=>'text', 'available'=>'boolean' ];
	}

	/** Enumeration is requested only by the chooser/handoff/explicit offers query; ordinary tag rendering does not scan. */
	public static function configured_offer_ids(): array {
		if ( ! function_exists( 'wc_get_product' ) ) return [];
		return self::$offer_ids ??= array_map( 'absint', get_posts( [ 'post_type'=>'product', 'post_status'=>'publish', 'has_password'=>false, 'posts_per_page'=>100, 'fields'=>'ids', 'no_found_rows'=>true, 'orderby'=>'ID', 'order'=>'ASC', 'meta_query'=>[ [ 'key'=>'_sc_upsell_product_id', 'value'=>0, 'compare'=>'>', 'type'=>'NUMERIC' ] ] ] ) );
	}

	public function definitions( bool $configured = true ): array {
		$out = [ 'kiwe_product_image_alt'=>__( 'Current product image description', 'dsa' ), 'kiwe_product_price_text'=>__( 'Current product price · plain text', 'dsa' ) ];
		if ( ! function_exists( 'wc_get_product' ) ) return [];
		foreach ( self::pair_fields() as $field=>$kind ) $out['kiwe_pair_'.$field] = __( 'Current product pair', 'dsa' ) . ' · ' . $field;
		if ( $configured && $this->offers ) foreach ( self::configured_offer_ids() as $id ) {
			$pair = $this->offers->public_pair_configuration( $id );
			if ( ! $pair ) continue;
			$label = wp_strip_all_tags( $pair['trigger']->get_name() ) . ' + ' . wp_strip_all_tags( $pair['companion']->get_name() );
			foreach ( self::pair_fields() as $field=>$kind ) $out['kiwe_pair_'.$id.'_'.$field] = $label . ' · ' . $field;
		}
		return $out;
	}

	public function catalogue(): array {
		$fields = function_exists( 'wc_get_product' ) ? self::record_fields() : [];
		if ( $this->offers ) foreach ( self::configured_offer_ids() as $id ) {
			if ( ! $this->offers->public_pair_configuration( $id ) ) continue;
			foreach ( self::pair_fields() as $key=>$kind ) $fields['commerce.offers.offer_'.$id.'.'.$key] = [ 'tag'=>'{kiwe_pair_'.$id.'_'.$key.'}', 'kind'=>$kind, 'owner'=>'kiwe-product-offer-metadata', 'scope'=>'public-pair', 'triggerId'=>$id ];
		}
		if ( class_exists( Tag_Group_Service::class ) ) foreach ( (new Tag_Group_Service())->dynamic_groups() as $id=>$group ) foreach ( [ 'current'=>'', 'name'=>'_name', 'all'=>'_all' ] as $key=>$suffix ) {
			$fields['tagGroups.group_'.$id.'.'.$key] = [ 'tag'=>'{kiwe_tag_group_'.$id.$suffix.'}', 'kind'=>'text', 'owner'=>'native-tag-group', 'scope'=>'public-terms', 'groupId'=>(int)$id ];
		}
		return [ 'fields'=>$fields, 'collections'=> ! function_exists('wc_get_product') ? [] : [
			'commerce.products'=>[ 'query'=>[ 'objectType'=>'post', 'post_type'=>['product'], 'post_status'=>'publish' ], 'fieldsPrefix'=>'commerce.product' ],
			'commerce.relationships'=>[ 'query'=>[ 'objectType'=>'post', 'post_type'=>['product'] ], 'relationControl'=>'kiweProductRelation', 'sourceControl'=>'kiweRelationSource', 'relations'=>['upsells','cross_sells','grouped','companion'], 'emptyPolicy'=>'no-results', 'fieldsPrefix'=>'commerce.product' ],
			'commerce.offers'=>[ 'query'=>[ 'objectType'=>'post', 'post_type'=>['product'], 'kiweProductRelation'=>'configured_offers' ], 'fieldsPrefix'=>'commerce.offer', 'identity'=>'native-trigger-product-id', 'enumerationLimit'=>100, 'totalsAuthority'=>'WooCommerce cart and coupon engine; no preview total or automatic claim' ],
		] ];
	}

	public function render( string $name, $post, string $context ) {
		if ( ! preg_match( '/^kiwe_(?:product_(image_alt|price_text)|pair_(?:([1-9][0-9]{0,9})_)?([a-z_]+))$/D', $name, $match ) ) return null;
		$is_image = str_ends_with( $name, '_image' );
		$empty = $is_image && in_array( $context, ['image','media'], true ) ? [] : '';
		if ( ! function_exists( 'wc_get_product' ) ) return $empty;
		$id = ! empty( $match[2] ) ? (int)$match[2] : ( is_object( $post ) ? (int)($post->ID ?? 0) : absint( $post ) );
		if ( ! $id && class_exists( '\Bricks\Query' ) && \Bricks\Query::is_looping() ) {
			$object = \Bricks\Query::get_loop_object(); $id = $object instanceof \WP_Post ? (int)$object->ID : 0;
		}
		if ( ! $id ) $id = (int)get_queried_object_id();
		if ( ! empty( $match[1] ) ) {
			$product = Product_Context_Service::public_product( $id );
			if ( ! $product || in_array( $context, ['image','media','link'], true ) ) return '';
			return 'image_alt' === $match[1] ? self::image_alt( $product ) : self::plain( $product->get_price_html() );
		}
		$field = $match[3] ?? '';
		if ( ! isset( self::pair_fields()[$field] ) ) return null;
		$pair = $this->offers ? $this->offers->public_pair_configuration( $id ) : [];
		if ( ! $pair ) return $empty;
		if ( preg_match( '/^(trigger|companion)_(id|title|url|image|image_alt|price|price_text)$/D', $field, $part ) ) {
			$product = $pair[$part[1]];
			if ( 'image' === $part[2] ) {
				$image = (int)$product->get_image_id();
				if ( ! $image || ! wp_attachment_is_image($image) || ! \DSA\Onboarding\Context_Selection_Service::public_media(get_post($image)) ) $image = 0;
				return in_array( $context, ['image','media'], true ) ? ($image ? [$image] : []) : ($image ? esc_url_raw((string)wp_get_attachment_url($image)) : '');
			}
			if ( in_array( $context, ['image','media'], true ) || ('link' === $context && 'url' !== $part[2]) ) return '';
			return match ( $part[2] ) {
				'id'=>(string)$product->get_id(), 'title'=>self::plain($product->get_name()), 'url'=>esc_url_raw($product->get_permalink()),
				'image_alt'=>self::image_alt($product), 'price'=>wp_kses_post($product->get_price_html()), 'price_text'=>self::plain($product->get_price_html()), default=>'',
			};
		}
		if ( in_array( $context, ['image','media','link'], true ) ) return '';
		return match ( $field ) {
			'id'=>(string)$id, 'title'=>self::plain($pair['trigger']->get_name()).' + '.self::plain($pair['companion']->get_name()),
			'discount'=>(string)$pair['discount']['value'], 'discount_type'=>$pair['discount']['type'], 'discount_scope'=>$pair['discount']['scope'],
			'discount_label'=>$pair['label'], 'scope_label'=>$pair['scopeLabel'], 'available'=>$pair['available'] ? '1' : '', default=>'',
		};
	}

	private static function plain( string $html ): string { return esc_html( html_entity_decode( wp_strip_all_tags($html), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8' ) ); }
	private static function image_alt( $product ): string {
		$image = (int)$product->get_image_id();
		return $image && wp_attachment_is_image($image) && \DSA\Onboarding\Context_Selection_Service::public_media(get_post($image)) ? sanitize_text_field((string)get_post_meta($image,'_wp_attachment_image_alt',true)) : '';
	}

	public static function controls( array $controls ): array {
		if ( ! function_exists('wc_get_product') ) return $controls;
		$controls['kiweProductRelation'] = [ 'tab'=>'content', 'type'=>'select', 'label'=>__('Product collection','dsa'), 'options'=>['upsells'=>__('Up-sells','dsa'),'cross_sells'=>__('Cross-sells','dsa'),'grouped'=>__('Grouped product children','dsa'),'companion'=>__('Configured companion','dsa'),'configured_offers'=>__('Configured Kiwe pairs','dsa'),'public_products'=>__('Selected public products','dsa')], 'placeholder'=>__('Use the normal query','dsa'), 'required'=>['hasLoop','!=',''] ];
		$controls['kiweRelationSource'] = [ 'tab'=>'content', 'type'=>'number', 'min'=>1, 'label'=>__('Source product ID','dsa'), 'description'=>__('Leave empty for the current product. Relationships stay in the native product editor.','dsa'), 'required'=>[['hasLoop','!=',''],['kiweProductRelation','!=',''],['kiweProductRelation','!=','configured_offers'],['kiweProductRelation','!=','public_products']] ];
		return $controls;
	}

	public function query_vars( array $vars, array $settings, $element_id = '', $element_name = '' ): array {
		$relation = $settings['kiweProductRelation'] ?? $settings['query']['kiweProductRelation'] ?? '';
		if ( ! in_array( $relation, ['upsells','cross_sells','grouped','companion','configured_offers','public_products'], true ) ) return $vars;
		$ids = [];
		if ( 'public_products' === $relation ) {
			// An explicit bounded selection, never an unfiltered second catalogue query.
			foreach ( array_slice(array_unique(array_map('absint',(array)($vars['post__in'] ?? []))),0,100) as $id ) if ( Product_Context_Service::public_product($id) ) $ids[] = $id;
		} elseif ( 'configured_offers' === $relation && $this->offers ) {
			foreach ( self::configured_offer_ids() as $id ) if ( $this->offers->public_pair_configuration($id) ) $ids[] = $id;
		} else {
			$id = absint($settings['kiweRelationSource'] ?? $settings['query']['kiweRelationSource'] ?? 0);
			if ( ! $id && class_exists('\Bricks\Query') && \Bricks\Query::is_looping() ) { $object = \Bricks\Query::get_loop_object(); $id = $object instanceof \WP_Post ? (int)$object->ID : 0; }
			if ( ! $id ) $id = (int)get_queried_object_id();
			$ids = $this->linked ? $this->linked->public_relationship_ids($id,$relation) : [];
		}
		if ( ! empty($vars['post__in']) ) $ids = array_values(array_intersect($ids,array_map('absint',(array)$vars['post__in'])));
		$vars['post_type'] = ['product','product_variation']; $vars['post_status'] = 'publish'; $vars['has_password'] = false;
		$vars['post__in'] = $ids ?: [0]; $vars['orderby'] = 'post__in'; $vars['posts_per_page'] = max(1,min(100,(int)($vars['posts_per_page'] ?? 12)));
		unset($vars['kiweProductRelation'],$vars['kiweRelationSource'],$vars['order']);
		return $vars;
	}
}
