<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Woo owns definitions; WordPress owns values. No storefront hooks or shadow catalog. */
final class Product_Attribute_Workspace_Service {

	private const LIMIT = 200;
	public static function available(): bool { return function_exists( 'wc_get_attribute' ) && function_exists( 'wc_create_attribute' ) && function_exists( 'wc_update_attribute' ) && function_exists( 'wc_delete_attribute' ); }
	public static function allowed(): bool { return self::available() && current_user_can( 'edit_products' ); }
	public static function manageable(): bool { return self::allowed() && current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_product_terms' ); }
	private function error( string $code, string $message, int $status = 409 ) { return new \WP_Error( 'kiwe_attribute_' . $code, $message, [ 'status'=>$status ] ); }
	private function rows( string $sql ) {
		global $wpdb;
		$value = $wpdb->get_results( $sql, ARRAY_A );
		return $wpdb->last_error !== '' || ! is_array( $value ) ? $this->error( 'read', 'Attributes could not be read. Refresh and review.' ) : $value;
	}
	private function text( $value ) {
		if ( ! is_string( $value ) ) return $this->error( 'input', 'Enter a plain name.', 400 );
		$name = trim( $value );
		return $name === '' || strlen( $name ) > 120 || sanitize_text_field( $name ) !== $name ? $this->error( 'input', 'Enter a plain name up to 120 bytes.', 400 ) : $name;
	}
	private function display( string $name ): string { return html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
	private function token( array $value ): string { return hash_hmac( 'sha256', serialize( $value ), wp_salt( 'nonce' ) ); }
	private function catalog_state() {
		global $wpdb;
		$rows = $this->rows( "SELECT attribute_id, attribute_name, attribute_label, attribute_type, attribute_orderby, attribute_public FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id ASC LIMIT 201" );
		if ( is_wp_error( $rows ) ) return $rows;
		$large = count( $rows ) > self::LIMIT;
		$items = [];
		foreach ( array_slice( $rows, 0, self::LIMIT ) as $row ) {
			$id = (int) $row['attribute_id'];
			$items[] = [ 'id'=>$id, 'name'=>$this->display( (string) $row['attribute_label'] ), 'slug'=>(string) $row['attribute_name'], 'type'=>(string) $row['attribute_type'], 'orderBy'=>(string) $row['attribute_orderby'], 'hasArchives'=>(bool) $row['attribute_public'], 'canRename'=>! $large && $row['attribute_type'] === 'select' && self::manageable(), 'canOpen'=>taxonomy_exists( 'pa_' . $row['attribute_name'] ) ];
		}
		return [ '_rows'=>$rows, 'items'=>$items, 'large'=>$large, 'canCreate'=>! $large && count( $rows ) < self::LIMIT && self::manageable(), 'expected'=>$this->token( $rows ) ];
	}
	public function catalog( array $query = [] ) {
		if ( ! self::allowed() ) return $this->error( 'forbidden', 'Attributes are unavailable for your account.', 403 );
		$state = $this->catalog_state(); if ( is_wp_error( $state ) ) return $state;
		unset( $state['_rows'] );
		return $this->page( $state, $query );
	}
	private function page( array $state, array $query ): array {
		$page = max( 1, min( 10, (int) ( $query['page'] ?? 1 ) ) );
		$search = is_string( $query['search'] ?? null ) ? Task_Workspace_UI::text( $query['search'], 120 ) : '';
		$sort = in_array( $query['sort'] ?? '', [ 'name_asc','name_desc','id_desc' ], true ) ? $query['sort'] : 'name_asc';
		$items = array_values( array_filter( $state['items'], static fn( $item )=>$search === '' || stripos( $item['name'], $search ) !== false ) );
		usort( $items, static function( $a, $b ) use ( $sort ) { $value = $sort === 'id_desc' ? $b['id'] <=> $a['id'] : strnatcasecmp( $a['name'], $b['name'] ); return ( $sort === 'name_desc' ? -$value : $value ) ?: $a['id'] <=> $b['id']; } );
		return array_replace( $state, [ 'items'=>array_slice( $items, ( $page - 1 ) * 20, 20 ), 'page'=>$page, 'hasNext'=>count( $items ) > $page * 20, 'search'=>$search, 'sort'=>$sort ] );
	}
	private function values_state( int $id ) {
		global $wpdb;
		$catalog = $this->catalog_state(); if ( is_wp_error( $catalog ) ) return $catalog;
		$attributes = array_column( $catalog['items'], null, 'id' );
		$attribute = $attributes[$id] ?? null;
		if ( ! $attribute ) return $this->error( 'missing', 'This attribute no longer exists.', 404 );
		$taxonomy = 'pa_' . $attribute['slug'];
		if ( ! taxonomy_exists( $taxonomy ) ) return $this->error( 'registration', 'Refresh to open the newly created attribute.' );
		$terms = $this->rows( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.description, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s ORDER BY t.term_id ASC LIMIT 201", $taxonomy ) );
		$meta = $this->rows( $wpdb->prepare( "SELECT tm.meta_id, tm.term_id, tm.meta_key, tm.meta_value FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=tm.term_id WHERE tt.taxonomy=%s ORDER BY tm.meta_id ASC LIMIT 1001", $taxonomy ) );
		$use = $this->rows( $wpdb->prepare( "SELECT tt.term_id, COUNT(tr.object_id) AS assigned FROM {$wpdb->term_taxonomy} tt LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tt.taxonomy=%s GROUP BY tt.term_id ORDER BY tt.term_id ASC LIMIT 201", $taxonomy ) );
		$variations = $this->rows( $wpdb->prepare( "SELECT meta_value AS slug, COUNT(*) AS assigned FROM {$wpdb->postmeta} WHERE meta_key=%s GROUP BY meta_value ORDER BY meta_value ASC LIMIT 201", 'attribute_' . $taxonomy ) );
		$defaults = $this->rows( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_default_attributes' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $taxonomy ) . '%' ) );
		foreach ( [ $terms,$meta,$use,$variations,$defaults ] as $value ) if ( is_wp_error( $value ) ) return $value;
		$large = $catalog['large'] || count( $terms ) > self::LIMIT || count( $meta ) > 1000 || count( $use ) > self::LIMIT || count( $variations ) > self::LIMIT;
		$counts = array_column( $use, 'assigned', 'term_id' ); $variationCounts = array_column( $variations, 'assigned', 'slug' );
		$metadata = array_fill_keys( array_column( array_filter( $meta, static fn( $row )=>$row['meta_key'] !== 'order' ), 'term_id' ), true );
		$native = get_taxonomy( $taxonomy );
		$capable = static fn( $operation )=>self::manageable() && isset( $native->cap->$operation ) && current_user_can( $native->cap->$operation );
		$items = [];
		foreach ( array_slice( $terms, 0, self::LIMIT ) as $term ) {
			$termId = (int) $term['term_id'];
			$assigned = (int) ( $counts[$termId] ?? 0 );
			$variationCount = (int) ( $variationCounts[$term['slug']] ?? 0 ) + (int) ( $variationCounts[''] ?? 0 );
			$items[] = [ 'id'=>$termId, 'name'=>$this->display( (string) $term['name'] ), 'slug'=>(string) $term['slug'], 'assigned'=>$assigned, 'variationCount'=>$variationCount, 'customMetadata'=>isset( $metadata[$termId] ), 'canRename'=>! $large && $attribute['type'] === 'select' && $capable( 'edit_terms' ), 'canDelete'=>! $large && $attribute['type'] === 'select' && $capable( 'delete_terms' ) && $assigned === 0 && $variationCount === 0 && ! $defaults && ! isset( $metadata[$termId] ) ];
		}
		return [ '_terms'=>$terms, '_meta'=>$meta, '_use'=>$use, '_variations'=>$variations, '_defaults'=>$defaults, 'attribute'=>$attribute, 'items'=>$items, 'large'=>$large, 'canCreate'=>! $large && count( $terms ) < self::LIMIT && $attribute['type'] === 'select' && $capable( 'manage_terms' ), 'expected'=>$this->token( [ $catalog['_rows'],$terms,$meta,$use,$variations,$defaults ] ) ];
	}
	public function values( int $id, array $query = [] ) {
		if ( ! self::allowed() ) return $this->error( 'forbidden', 'Attribute values are unavailable for your account.', 403 );
		$state = $this->values_state( $id ); if ( is_wp_error( $state ) ) return $state;
		unset( $state['_terms'],$state['_meta'],$state['_use'],$state['_variations'],$state['_defaults'] );
		return $this->page( $state, $query );
	}
	/** One bounded native label inventory for the existing encrypted settings owner. */
	public function label_snapshot() {
		if ( ! self::manageable() ) return $this->error( 'forbidden','Your account cannot recover attribute labels.',403 );
		global $wpdb;
		$catalog = $this->catalog_state(); if ( is_wp_error( $catalog ) ) return $catalog;
		if ( $catalog['large'] || count( $catalog['items'] ) > 50 ) return $this->error( 'limit','Attribute label snapshots support at most 50 definitions and 500 values.' );
		$records = []; $taxonomies = [];
		foreach ( $catalog['items'] as $attribute ) {
			if ( $attribute['type'] !== 'select' || ! $attribute['canOpen'] || ! $attribute['canRename'] ) return $this->error( 'type','An extended or unregistered attribute needs native review before label recovery.' );
			$taxonomy = 'pa_' . $attribute['slug']; $native = get_taxonomy( $taxonomy );
			if ( ! $native || ! current_user_can( $native->cap->edit_terms ) ) return $this->error( 'forbidden','Your account cannot recover these native attribute values.',403 );
			$taxonomies[] = $taxonomy;
			$identity = hash( 'sha256', serialize( [ $attribute['id'],$attribute['slug'],$attribute['type'],$attribute['orderBy'],$attribute['hasArchives'] ] ) );
			$records[$taxonomy] = [ 'id'=>$attribute['id'],'identity'=>$identity,'name'=>$attribute['name'],'terms'=>[] ];
		}
		if ( $taxonomies ) {
			$terms = $this->rows( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy IN (" . implode( ',',array_fill( 0,count($taxonomies),'%s' ) ) . ') ORDER BY t.term_id ASC LIMIT 501', ...$taxonomies ) );
			if ( is_wp_error( $terms ) ) return $terms;
			if ( count( $terms ) > 500 ) return $this->error( 'limit','Attribute label snapshots support at most 500 values.' );
			foreach ( $terms as $term ) {
				$name = $this->display((string)$term['name']);
				if ( is_wp_error($this->text($name)) || count($records[$term['taxonomy']]['terms'])>=self::LIMIT ) return $this->error('limit','A native attribute value needs review before label recovery.');
				$records[$term['taxonomy']]['terms'][] = [ 'id'=>(int) $term['term_id'],'identity'=>hash('sha256',serialize([(int)$term['term_id'],(string)$term['taxonomy'],(string)$term['slug']])),'name'=>$name ];
			}
		}
		foreach ($records as $record) if (is_wp_error($this->text($record['name']))) return $this->error('label','A native attribute name needs review before label recovery.');
		return [ 'schema'=>'kiwe.product-attribute-labels.v1','records'=>array_values($records) ];
	}
	private function transaction(): bool {
		global $wpdb;
		$tables = [ $wpdb->prefix . 'woocommerce_attribute_taxonomies', $wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->termmeta,$wpdb->posts,$wpdb->postmeta,$wpdb->options ];
		$rows = $this->rows( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')', ...$tables ) );
		if ( is_wp_error( $rows ) || count( $rows ) !== count( $tables ) || (string) $wpdb->get_var( 'SELECT @@session.in_transaction' ) !== '0' ) return false;
		foreach ( $rows as $row ) if ( strtoupper( (string) $row['ENGINE'] ) !== 'INNODB' ) return false;
		return $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) !== false && $wpdb->query( 'START TRANSACTION' ) !== false;
	}
	/** Names only on existing records: slugs, native types, archives, relationships and SEO stay current. */
	public function change( int $attributeId, int $termId, array $input, bool $value = false ) {
		$action = $input['action'] ?? null;
		if ( ! self::manageable() || ! in_array( $action, [ 'create','rename','delete' ], true ) ) return $this->error( 'forbidden', 'Your account cannot change store attributes.', 403 );
		$id = $value ? $termId : $attributeId;
		if ( $value ) {
			$attribute = wc_get_attribute( $attributeId );
			$native = $attribute ? get_taxonomy( $attribute->slug ) : null;
			$operation = [ 'create'=>'manage_terms','rename'=>'edit_terms','delete'=>'delete_terms' ][$action];
			if ( ! $native || ! isset($native->cap->$operation) || !current_user_can($native->cap->$operation) ) return $this->error('forbidden','Your native taxonomy role cannot make this change.',403);
		}
		$keys = [ 'action','expected','acceptEffect', $action === 'delete' ? 'confirmName' : 'name', ...(! $value && $action === 'create' ? [ 'slug' ] : []) ];
		if ( array_diff( array_keys( $input ), $keys ) || ! is_string( $input['expected'] ?? null ) || ( $input['acceptEffect'] ?? false ) !== true || ( $action === 'create' ? $id !== 0 : $id < 1 ) ) return $this->error( 'input', 'Review one attribute change.', 400 );
		// Global deletion also discards values in Woo. Keep it out of this label/value task.
		if ( ! $value && $action === 'delete' ) return $this->error( 'protected', 'Attribute definitions and their product choices are protected. Use the native store recovery review before removing a definition.' );
		$name = $action === 'delete' ? ( $input['confirmName'] ?? null ) : $this->text( $input['name'] ?? null );
		if ( is_wp_error( $name ) ) return $name;
		if ( ! is_string( $name ) ) return $this->error( 'input', 'Confirm the displayed value name.', 400 );
		global $wpdb;
		$lock = 'kiwe-attributes-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== '1' ) return $this->error( 'busy', 'Another attribute change is running. Refresh and review.' );
		$transaction = false; $touched = false; $affected = $id; $taxonomy = ''; $cacheIds = [];
		try {
			$before = $value ? $this->values_state( $attributeId ) : $this->catalog_state();
			if ( is_wp_error( $before ) ) return $before;
			if ( ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The native attributes or their choices changed. Refresh and review.' );
			$old = array_column( $before['items'], null, 'id' );
			if ( $before['large'] || ( $action === 'create' && ! $before['canCreate'] ) ) return $this->error( 'limit', 'This catalog needs designer review before another change.' );
			if ( $id && ! isset( $old[$id] ) ) return $this->error( 'missing', 'This saved attribute or value is unavailable.', 404 );
			if ( $action === 'rename' && ! $old[$id]['canRename'] ) return $this->error( 'forbidden', 'This native attribute type or role is review-only.', 403 );
			if ( $action === 'delete' && ( ! $old[$id]['canDelete'] || ! hash_equals( $old[$id]['name'], $name ) ) ) return $this->error( 'in_use', 'Assigned, variation-linked or annotated values are protected.' );
			if ( $action !== 'delete' ) {
				// Native IDs and addresses identify records; Woo permits matching display labels.
				if ( $action === 'rename' && $old[$id]['name'] === $name ) return [ 'changed'=>false,'action'=>$action,'id'=>$id ];
			}
			$slug = '';
			if ( ! $value && $action === 'create' ) {
				$slug = $input['slug'] ?? null;
				$limit = function_exists( 'wc_get_attribute_slug_max_byte_length' ) ? wc_get_attribute_slug_max_byte_length() : 28;
				if ( ! is_string( $slug ) || $slug === '' || strlen( $slug ) > $limit || wc_sanitize_taxonomy_name( $slug ) !== $slug || str_starts_with( $slug, 'pa_' ) || wc_check_if_attribute_name_is_reserved( $slug ) || taxonomy_exists( 'pa_' . $slug ) ) return $this->error( 'slug', 'Use a short unique lowercase attribute address.', 400 );
				foreach ( $old as $item ) if ( $item['slug'] === $slug ) return $this->error( 'duplicate', 'An attribute already uses this address.' );
			}
			$transaction = $this->transaction();
			if ( ! $transaction ) return $this->error( 'transaction', 'Safe attribute changes need native InnoDB tables and an available transaction.' );
			$fresh = $value ? $this->values_state( $attributeId ) : $this->catalog_state();
			if ( is_wp_error( $fresh ) || ! hash_equals( $fresh['expected'], $before['expected'] ) ) return $this->error( 'stale', 'The catalog changed before saving. Refresh and review.' );
			$touched = true;
			if ( $value ) {
				$taxonomy = 'pa_' . $before['attribute']['slug']; $cacheIds = array_column( $before['_terms'], 'term_id' );
				$result = $action === 'create' ? wp_insert_term( wp_slash( $name ), $taxonomy ) : ( $action === 'rename' ? wp_update_term( $id, $taxonomy, [ 'name'=>wp_slash( $name ),'slug'=>$old[$id]['slug'] ] ) : wp_delete_term( $id, $taxonomy ) );
				$affected = $action === 'create' && is_array( $result ) ? (int) ( $result['term_id'] ?? 0 ) : $id;
			} else {
				$result = $action === 'create' ? wc_create_attribute( wp_slash( [ 'name'=>$name,'slug'=>$slug,'type'=>'select','order_by'=>'menu_order','has_archives'=>false ] ) ) : wc_update_attribute( $id, wp_slash( [ 'name'=>$name ] ) );
				$affected = is_int( $result ) ? $result : 0;
			}
			if ( is_wp_error( $result ) || ! $result || $affected < 1 || $wpdb->last_error !== '' ) throw new \RuntimeException( 'native writer' );
			$after = $value ? $this->values_state( $attributeId ) : $this->catalog_state();
			if ( is_wp_error( $after ) || $after['large'] ) throw new \RuntimeException( 'readback' );
			$current = array_column( $after['items'], null, 'id' );
			if ( $action === 'delete' ) { if ( isset( $current[$affected] ) ) throw new \RuntimeException( 'delete' ); }
			elseif ( ! isset( $current[$affected] ) || $current[$affected]['name'] !== $name ) throw new \RuntimeException( 'name' );
			if ( $action === 'rename' ) { $wanted = $old[$id]; $wanted['name'] = $name; if ( $current[$id] !== $wanted ) throw new \RuntimeException( 'identity' ); }
			if ( $id ) unset( $old[$id] ); unset( $current[$affected] );
			if ( $current !== $old ) throw new \RuntimeException( 'other records' );
			if ( $value ) {
				// Creating/deleting an unused term changes its zero-count usage row only.
				$useBefore = array_column( $before['_use'], null, 'term_id' ); $useAfter = array_column( $after['_use'], null, 'term_id' );
				$metaBefore = array_values( array_filter( $before['_meta'], static fn( $row )=>(int) $row['term_id'] !== $id ) );
				$metaAfter = array_values( array_filter( $after['_meta'], static fn( $row )=>(int) $row['term_id'] !== $affected ) );
				if ( $id ) unset( $useBefore[$id] ); unset( $useAfter[$affected] );
				if ( $after['attribute'] !== $before['attribute'] || $metaBefore !== $metaAfter || ( $action === 'rename' && $after['_meta'] !== $before['_meta'] ) || $after['_variations'] !== $before['_variations'] || $after['_defaults'] !== $before['_defaults'] || $useBefore !== $useAfter || ( $action === 'rename' && $after['_use'] !== $before['_use'] ) || ( $action === 'create' && ( $after['items'][array_search( $affected, array_column( $after['items'],'id' ), true )]['assigned'] ?? 1 ) !== 0 ) ) throw new \RuntimeException( 'relationships or metadata' );
			}
			$rawBefore = array_column( $value ? $before['_terms'] : $before['_rows'], null, $value ? 'term_id' : 'attribute_id' );
			$rawAfter = array_column( $value ? $after['_terms'] : $after['_rows'], null, $value ? 'term_id' : 'attribute_id' );
			if ( $action === 'rename' ) { $a = $rawBefore[$id]; $b = $rawAfter[$id]; unset( $a[$value ? 'name' : 'attribute_label'], $b[$value ? 'name' : 'attribute_label'] ); if ( $a !== $b ) throw new \RuntimeException( 'native identity' ); }
			if ( $id ) unset( $rawBefore[$id] ); unset( $rawAfter[$affected] );
			if ( $rawBefore !== $rawAfter ) throw new \RuntimeException( 'native catalog' );
			if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'commit' );
			$transaction = false;
			return [ 'changed'=>true,'action'=>$action,'id'=>$affected ];
		} catch ( \Throwable $error ) { return $this->error( 'write', 'The attribute change could not be confirmed. Refresh before retrying.' ); }
		finally {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); wp_cache_delete( 'alloptions','options' ); wp_cache_delete( 'notoptions','options' ); wp_cache_delete( 'cron','options' ); }
			if ( $touched ) {
				delete_transient( 'wc_attribute_taxonomies' ); \WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
				if ( $taxonomy ) { $cacheIds[] = $affected; clean_term_cache( array_unique( $cacheIds ),$taxonomy ); foreach ( $cacheIds as $term ) wp_cache_delete( $term,'term_meta' ); }
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
