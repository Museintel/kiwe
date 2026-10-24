<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Native Woo relationships only. No relationship mirror, scheduler or visitor hook. */
final class Product_Merchandising_Workspace_Service {
	private const FIELDS = [ 'children'=>'children', 'upsells'=>'upsell_ids', 'crossSells'=>'cross_sell_ids' ];
	private function error( string $code, string $message, int $status = 400 ) { return new \WP_Error( 'kiwe_merch_' . $code, $message, [ 'status'=>$status ] ); }
	private function product( int $id ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your role cannot manage this product.', 403 );
		$product = wc_get_product( $id );
		if ( ! $product ) return $this->error( 'missing', 'This product is unavailable.', 404 );
		if ( ! in_array( $product->get_type(), [ 'simple','variable','grouped','external' ], true ) || ! in_array( $product->get_status( 'edit' ), [ 'draft','pending','publish','private' ], true ) ) return $this->error( 'type', 'This product’s relationships belong to its native product editor.', 409 );
		return $product;
	}
	private function eligible( $product, int $parent, string $field ): bool {
		return $product && $product->get_id() !== $parent && current_user_can( 'edit_post', $product->get_id() ) && 'publish' === $product->get_status( 'edit' ) && 'hidden' !== $product->get_catalog_visibility( 'edit' ) && in_array( $product->get_type(), 'children' === $field ? [ 'simple' ] : [ 'simple','variable','grouped','external' ], true );
	}
	private function target( int $id ): array {
		$product = wc_get_product( $id );
		if ( ! $product || ! current_user_can( 'edit_post', $id ) ) return [ 'id'=>$id, 'name'=>'Unavailable product', 'available'=>false, 'type'=>'', 'status'=>'', 'visibility'=>'' ];
		return [ 'id'=>$id, 'name'=>(string) $product->get_name( 'edit' ), 'available'=>true, 'type'=>$product->get_type(), 'status'=>$product->get_status( 'edit' ), 'visibility'=>$product->get_catalog_visibility( 'edit' ) ];
	}
	public function review( int $id ) {
		$product = $this->product( $id ); if ( is_wp_error( $product ) ) return $product;
		$lists = []; $targets = []; $editable = true;
		foreach ( self::FIELDS as $field=>$prop ) {
			$ids = 'children' === $field && 'grouped' !== $product->get_type() ? [] : array_values( array_map( 'intval', $product->{'get_'.$prop}( 'edit' ) ) );
			if ( count( $ids ) > 50 || count( array_unique( $ids ) ) !== count( $ids ) ) $editable = false;
			$lists[$field] = array_slice( $ids, 0, 50 );
			foreach ( $lists[$field] as $target ) if ( ! isset( $targets[$target] ) ) $targets[$target] = $this->target( $target );
		}
		$config = ( new \DSA\Settings() )->get( 'commerce', [] );
		$out = [ 'id'=>$id, 'name'=>(string) $product->get_name( 'edit' ), 'type'=>$product->get_type(), 'editable'=>$editable, 'lists'=>$lists, 'targets'=>array_values( $targets ), 'limit'=>50, 'linkedEnabled'=>! empty( $config['linked_products_enabled'] ), 'recommendationsEnabled'=>! empty( $config['commerce_recommendations_enabled'] ) ];
		$out['expected'] = hash( 'sha256', wp_json_encode( [ $out, Commerce_App_Service::fingerprint( $product ) ] ) );
		return $out;
	}
	public function choices( int $id, array $input ) {
		$product = $this->product( $id ); if ( is_wp_error( $product ) ) return $product;
		$field = $input['kind'] ?? 'upsells';
		if ( ! isset( self::FIELDS[$field] ) || ( 'children' === $field && 'grouped' !== $product->get_type() ) ) return $this->error( 'field', 'Choose an available product relationship.' );
		$search = Task_Workspace_UI::text( $input['search'] ?? '', 80 ); $page = max( 1, min( 1000, (int) ( $input['page'] ?? 1 ) ) );
		$category = $input['group'] ?? '';
		if ( '' !== $category && ( ! ctype_digit( (string) $category ) || ! term_exists( (int) $category, 'product_cat' ) ) ) return $this->error( 'category', 'Choose an existing product category.' );
		$args = [ 'status'=>'publish','type'=>'children' === $field ? [ 'simple' ] : [ 'simple','variable','grouped','external' ],'limit'=>21,'offset'=>( $page-1 ) * 20,'orderby'=>'title','order'=>'ASC','exclude'=>[ $id ] ];
		if ( '' !== $search ) { if ( ctype_digit( $search ) && (int) $search > 0 ) { $args['include'] = [ (int) $search ]; $args['offset'] = 0; } else $args['s'] = $search; }
		if ( '' !== $category ) { $term = get_term( (int) $category, 'product_cat' ); if ( ! $term || is_wp_error( $term ) ) return $this->error( 'category', 'This product category is unavailable. Reload the list.' ); $args['category'] = [ $term->slug ]; }
		$products = wc_get_products( $args ); $items = [];
		foreach ( array_slice( $products, 0, 20 ) as $candidate ) if ( $this->eligible( $candidate, $id, $field ) ) $items[] = $this->target( $candidate->get_id() );
		return [ 'items'=>$items,'page'=>$page,'hasNext'=>count( $products ) > 20,'search'=>$search,'kind'=>$field,'group'=>(string) $category ];
	}
	/** Save one complete bounded native list, preserving other relationships and product fields. */
	public function change( int $id, array $input ) {
		if ( array_diff( array_keys( $input ), [ 'field','ids','expected','confirmed' ] ) || true !== ( $input['confirmed'] ?? null ) || ! is_string( $input['field'] ?? null ) || ! isset( self::FIELDS[$input['field']] ) || ! is_array( $input['ids'] ?? null ) || ! array_is_list( $input['ids'] ) || count( $input['ids'] ) > 50 || count( array_unique( $input['ids'], SORT_REGULAR ) ) !== count( $input['ids'] ) || ! is_string( $input['expected'] ?? null ) ) return $this->error( 'input', 'Confirm up to 50 distinct products for one relationship.' );
		foreach ( $input['ids'] as $target ) if ( ! is_int( $target ) || $target < 1 ) return $this->error( 'ids', 'Choose existing products.' );
		$product = $this->product( $id ); if ( is_wp_error( $product ) ) return $product;
		global $wpdb; $key = 'kiwe_merch_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $key ) ) ) return $this->error( 'busy', 'Product relationships are being saved. Reload and review.', 409 );
		try {
			$before = $this->review( $id ); if ( is_wp_error( $before ) ) return $before;
			if ( ! $before['editable'] || ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The product or its relationships changed. Reload and review before saving.', 409 );
			$field = $input['field']; $prop = self::FIELDS[$field];
			if ( 'children' === $field && 'grouped' !== $before['type'] ) return $this->error( 'field', 'Only grouped products have grouped contents.' );
			foreach ( $input['ids'] as $target ) if ( ! in_array( $target, $before['lists'][$field], true ) && ! $this->eligible( wc_get_product( $target ), $id, $field ) ) return $this->error( 'target', 'A selected product is unavailable for this relationship. Reload and choose again.', 409 );
			if ( $before['lists'][$field] === $input['ids'] ) return $before;
			$product = wc_get_product( $id ); $product->{'set_'.$prop}( $input['ids'] ); $saved = $product->save();
			$after = $this->review( $id );
			if ( ! $saved || is_wp_error( $after ) || $after['lists'][$field] !== $input['ids'] ) return $this->error( 'readback', 'The save could not be confirmed. Reload the saved relationships before trying again.', 409 );
			foreach ( self::FIELDS as $other=>$_ ) if ( $other !== $field && $before['lists'][$other] !== $after['lists'][$other] ) return $this->error( 'readback', 'Other relationships changed during the save. Reload and review.', 409 );
			return $after;
		} catch ( \Throwable $error ) { return $this->error( 'write', 'The save could not be confirmed. Reload the saved relationships before trying again.', 409 ); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}
}
