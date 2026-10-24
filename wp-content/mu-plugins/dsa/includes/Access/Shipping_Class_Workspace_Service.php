<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Native shipping terms only. No parallel catalog, rate writer or product reassignment. */
final class Shipping_Class_Workspace_Service {
	private const TAXONOMY = 'product_shipping_class';
	private const LIMIT = 200;

	private function allowed(): bool {
		return class_exists( 'WC_Shipping_Zones' ) && taxonomy_exists( self::TAXONOMY ) &&
			( current_user_can( 'edit_products' ) || ( current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' ) ) );
	}
	private function capable( string $operation ): bool {
		$taxonomy = get_taxonomy( self::TAXONOMY );
		$cap = $taxonomy->cap->$operation ?? '';
		return current_user_can( 'edit_products' ) && current_user_can( 'kiwe_manage_context' ) && is_string( $cap ) && $cap !== '' && current_user_can( $cap );
	}
	private function error( string $code, string $message, int $status = 409 ) {
		return new \WP_Error( 'kiwe_shipping_class_' . $code, $message, [ 'status' => $status ] );
	}
	private function rows( string $sql ) {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return $wpdb->last_error !== '' || ! is_array( $rows ) ? $this->error( 'read', 'Shipping classes could not be read. Refresh and review.' ) : $rows;
	}
	private function name( $value ) {
		if ( ! is_string( $value ) ) return $this->error( 'input', 'Enter a plain shipping-class name.', 400 );
		$name = trim( $value );
		if ( $name === '' || strlen( $name ) > 120 || sanitize_text_field( $name ) !== $name || sanitize_title( $name ) === '' ) return $this->error( 'input', 'Enter a plain shipping-class name up to 120 bytes.', 400 );
		return $name;
	}
	private function display( string $name ): string {
		return html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Bounded whole-catalog snapshot; never silently delete from a truncated dependency view. */
	private function state() {
		global $wpdb;
		$terms = $this->rows( "SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.description, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_shipping_class' ORDER BY t.term_id ASC LIMIT 201" );
		if ( is_wp_error( $terms ) ) return $terms;
		$use = $this->rows( "SELECT tt.term_id, COUNT(tr.object_id) AS assigned, SUM(CASE WHEN p.post_type = 'product' THEN 1 ELSE 0 END) AS products, SUM(CASE WHEN p.post_type = 'product_variation' THEN 1 ELSE 0 END) AS variations FROM {$wpdb->term_taxonomy} tt LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = 'product_shipping_class' GROUP BY tt.term_id ORDER BY tt.term_id ASC LIMIT 201" );
		if ( is_wp_error( $use ) ) return $use;
		$methods = $this->rows( "SELECT instance_id, method_id, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods ORDER BY instance_id ASC LIMIT 201" );
		if ( is_wp_error( $methods ) ) return $methods;
		$metadata = $this->rows( "SELECT DISTINCT tm.term_id FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = 'product_shipping_class' ORDER BY tm.term_id ASC LIMIT 201" );
		if ( is_wp_error( $metadata ) ) return $metadata;
		$customMetadata = array_fill_keys( array_column( $metadata, 'term_id' ), true );
		$pattern = $wpdb->esc_like( 'woocommerce_flat_rate_' ) . '%' . $wpdb->esc_like( '_settings' );
		$options = $this->rows( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s ORDER BY option_name ASC LIMIT 201", 'woocommerce_flat_rate_settings', $pattern ) );
		if ( is_wp_error( $options ) ) return $options;
		$raw = []; $uncertain = count( $options ) > self::LIMIT || count( $methods ) > self::LIMIT;
		foreach ( array_slice( $options, 0, self::LIMIT ) as $option ) {
			$key = (string) $option['option_name'];
			if ( $key !== 'woocommerce_flat_rate_settings' && ! preg_match( '/^woocommerce_flat_rate_[1-9][0-9]*_settings$/D', $key ) ) continue;
			$value = get_option( $key, [] );
			if ( ! is_array( $value ) ) { $uncertain = true; continue; }
			$raw[$key] = $value;
		}
		$registry = \WC_Shipping::instance()->get_shipping_method_class_names();
		$core = [ 'flat_rate' => 'WC_Shipping_Flat_Rate', 'free_shipping' => 'WC_Shipping_Free_Shipping', 'local_pickup' => 'WC_Shipping_Local_Pickup' ];
		$providers = [];
		foreach ( array_slice( $methods, 0, self::LIMIT ) as $method ) {
			$kind = (string) $method['method_id'];
			if ( ! isset( $core[$kind] ) || ( $registry[$kind] ?? null ) !== $core[$kind] ) $providers[$kind] = true;
		}
		$usage = [];
		foreach ( $use as $row ) $usage[(int) $row['term_id']] = [ 'assigned' => (int) $row['assigned'], 'products' => (int) $row['products'], 'variations' => (int) $row['variations'] ];
		$large = count( $terms ) > self::LIMIT || count( $use ) > self::LIMIT || count( $metadata ) > self::LIMIT;
		$items = [];
		foreach ( array_slice( $terms, 0, self::LIMIT ) as $term ) {
			$id = (int) $term['term_id']; $assigned = $usage[$id] ?? [ 'assigned' => 0, 'products' => 0, 'variations' => 0 ]; $charges = 0;
			foreach ( $raw as $key => $values ) {
				$field = 'class_cost_' . ( $key === 'woocommerce_flat_rate_settings' ? $term['slug'] : $id );
				if ( array_key_exists( $field, $values ) && $values[$field] !== '' ) $charges++;
			}
			$canDelete = ! $large && ! $uncertain && ! $providers && ! isset( $customMetadata[$id] ) && $this->capable( 'delete_terms' ) && $assigned['assigned'] === 0 && $charges === 0;
			$items[] = [ 'id' => $id, 'name' => $this->display( (string) $term['name'] ), 'slug' => (string) $term['slug'], 'productCount' => $assigned['products'], 'variationCount' => $assigned['variations'], 'otherCount' => max( 0, $assigned['assigned'] - $assigned['products'] - $assigned['variations'] ), 'chargeCount' => $charges, 'customMetadata' => isset( $customMetadata[$id] ), 'canRename' => ! $large && $this->capable( 'edit_terms' ), 'canDelete' => $canDelete ];
		}
		return [ '_terms' => $terms, '_rates' => $raw, '_methods' => $methods, 'items' => $items, 'large' => $large, 'dependenciesComplete' => ! $uncertain, 'providerManaged' => ! empty( $providers ), 'canCreate' => ! $large && count( $terms ) < self::LIMIT && $this->capable( 'manage_terms' ), 'expected' => hash_hmac( 'sha256', serialize( [ $terms, $use, $methods, $raw, $metadata, $uncertain, $providers ] ), wp_salt( 'nonce' ) ) ];
	}

	public function classes( array $input = [] ) {
		if ( ! $this->allowed() ) return $this->error( 'forbidden', 'Shipping classes are unavailable for your account.', 403 );
		$state = $this->state(); if ( is_wp_error( $state ) ) return $state;
		unset( $state['_terms'], $state['_rates'], $state['_methods'] );
		$page = max( 1, min( 10, (int) ( $input['page'] ?? 1 ) ) );
		$search = is_string( $input['search'] ?? '' ) ? Task_Workspace_UI::text( $input['search'] ?? '', 120 ) : '';
		$sort = in_array( $input['sort'] ?? '', [ 'name_asc', 'name_desc', 'id_desc' ], true ) ? $input['sort'] : 'name_asc';
		$mode = in_array( $input['mode'] ?? '', [ 'all', 'in_use', 'unused' ], true ) ? $input['mode'] : 'all';
		$items = array_values( array_filter( $state['items'], static function( $item ) use ( $search, $mode ) { $used = $item['productCount'] + $item['variationCount'] + $item['otherCount'] + $item['chargeCount'] > 0 || $item['customMetadata']; return ( $search === '' || stripos( $item['name'], $search ) !== false ) && ( $mode === 'all' || ( $mode === 'in_use' ? $used : ! $used ) ); } ) );
		usort( $items, static function( $a, $b ) use ( $sort ) { $order = $sort === 'id_desc' ? $b['id'] <=> $a['id'] : strnatcasecmp( $a['name'], $b['name'] ); if ( $sort === 'name_desc' ) $order = -$order; return $order ?: $a['id'] <=> $b['id']; } );
		return array_replace( $state, [ 'items' => array_slice( $items, ( $page - 1 ) * 20, 20 ), 'page' => $page, 'hasNext' => count( $items ) > $page * 20, 'search' => $search, 'sort' => $sort, 'mode' => $mode ] );
	}

	private function begin_change(): bool {
		global $wpdb;
		$names = [ $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->posts, $wpdb->options ];
		$engines = $this->rows( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s,%s,%s)', ...$names ) );
		if ( is_wp_error( $engines ) || count( $engines ) !== count( $names ) || (string) $wpdb->get_var( 'SELECT @@session.in_transaction' ) !== '0' ) return false;
		foreach ( $engines as $row ) if ( strtoupper( (string) $row['ENGINE'] ) !== 'INNODB' ) return false;
		return $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) !== false && $wpdb->query( 'START TRANSACTION' ) !== false;
	}

	/** WordPress owns term writes/hooks. Preserve slug, metadata, assignments and every rate. */
	public function change( int $id, array $input ) {
		$action = is_string( $input['action'] ?? null ) ? $input['action'] : '';
		$operation = [ 'create' => 'manage_terms', 'rename' => 'edit_terms', 'delete' => 'delete_terms' ][$action] ?? '';
		if ( ! $this->allowed() || ! $operation || ! $this->capable( $operation ) ) return $this->error( 'forbidden', 'Your account cannot make this store-wide class change.', 403 );
		if ( array_diff( array_keys( $input ), [ 'action', $action === 'delete' ? 'confirmName' : 'name', 'expected', 'acceptEffect' ] ) || ! is_string( $input['expected'] ?? null ) || ( $input['acceptEffect'] ?? false ) !== true || ( $action === 'create' ? $id !== 0 : $id < 1 ) ) return $this->error( 'input', 'Review and confirm one shipping-class change.', 400 );
		$name = $action !== 'delete' ? $this->name( $input['name'] ?? null ) : ( $input['confirmName'] ?? null );
		if ( is_wp_error( $name ) ) return $name;
		if ( ! is_string( $name ) ) return $this->error( 'input', 'Confirm the displayed shipping-class name.', 400 );
		global $wpdb;
		$lock = 'kiwe-shipping-classes-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== '1' ) return $this->error( 'busy', 'Another class change is running. Refresh and review.' );
		$transaction = false; $touched = false; $affected = $id; $cacheIds = []; $optionKeys = [];
		try {
			$before = $this->state(); if ( is_wp_error( $before ) ) return $before;
			$cacheIds = array_column( $before['_terms'], 'term_id' ); $optionKeys = array_keys( $before['_rates'] );
			if ( ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The classes, assignments or delivery settings changed. Refresh and review.' );
			$old = array_column( $before['items'], null, 'id' );
			if ( $before['large'] ) return $this->error( 'large', 'Your designer manages this catalog of more than 200 classes.' );
			if ( $action === 'create' && ! $before['canCreate'] ) return $this->error( 'large', 'This catalog has reached the 200-class editing limit.' );
			if ( $id && ! isset( $old[$id] ) ) return $this->error( 'missing', 'This shipping class no longer exists.', 404 );
			if ( $action === 'delete' && ( ! hash_equals( $old[$id]['name'], $name ) || ! $old[$id]['canDelete'] ) ) return $this->error( 'in_use', 'Remove its assignments and saved charges first. Extension providers or incomplete dependency reads need designer review.' );
			if ( $action !== 'delete' ) {
				foreach ( $old as $termId => $item ) if ( $termId !== $id && ( strcasecmp( $item['name'], $name ) === 0 || ( $action === 'create' && $item['slug'] === sanitize_title( $name ) ) ) ) return $this->error( 'duplicate', 'A shipping class already uses that name or address.' );
				if ( $action === 'rename' && $old[$id]['name'] === $name ) return [ 'changed' => false, 'action' => $action, 'id' => $id ];
			}
			$transaction = $this->begin_change();
			if ( ! $transaction ) return $this->error( 'transaction', 'Safe class changes need native InnoDB tables and an available transaction.' );
			$fresh = $this->state(); if ( is_wp_error( $fresh ) || ! hash_equals( $fresh['expected'], $before['expected'] ) ) return $this->error( 'stale', 'Delivery setup changed before saving. Refresh and review.' );
			$metadata = $id ? get_term_meta( $id ) : [];
			if ( $action === 'delete' && $metadata ) return $this->error( 'metadata', 'This class has extension metadata. Your designer must review it before deletion.' );
			$touched = true;
			if ( $action === 'create' ) {
				$result = wp_insert_term( wp_slash( $name ), self::TAXONOMY );
				$affected = is_array( $result ) ? (int) ( $result['term_id'] ?? 0 ) : 0;
			} elseif ( $action === 'rename' ) {
				$result = wp_update_term( $id, self::TAXONOMY, [ 'name' => wp_slash( $name ), 'slug' => $old[$id]['slug'] ] );
			} else {
				$result = wp_delete_term( $id, self::TAXONOMY );
			}
			if ( is_wp_error( $result ) || ! $result || $wpdb->last_error !== '' || $affected < 1 ) throw new \RuntimeException( 'write' );
			$after = $this->state(); if ( is_wp_error( $after ) ) throw new \RuntimeException( 'readback' );
			$cacheIds = array_unique( array_merge( $cacheIds, array_column( $after['_terms'], 'term_id' ) ) );
			if ( $after['large'] !== $before['large'] || $after['dependenciesComplete'] !== $before['dependenciesComplete'] || $after['providerManaged'] !== $before['providerManaged'] ) throw new \RuntimeException( 'provider' );
			$current = array_column( $after['items'], null, 'id' );
			if ( $after['_rates'] !== $before['_rates'] || $after['_methods'] !== $before['_methods'] || ( $action === 'rename' && get_term_meta( $id ) !== $metadata ) ) throw new \RuntimeException( 'settings' );
			$oldTerms = array_column( $before['_terms'], null, 'term_id' ); $currentTerms = array_column( $after['_terms'], null, 'term_id' );
			if ( $action === 'rename' ) { $savedTerm = $currentTerms[$id] ?? []; unset( $savedTerm['name'] ); $oldTerm = $oldTerms[$id]; unset( $oldTerm['name'] ); if ( $savedTerm !== $oldTerm ) throw new \RuntimeException( 'term' ); }
			if ( $id ) unset( $oldTerms[$id] ); unset( $currentTerms[$affected] );
			if ( $currentTerms !== $oldTerms ) throw new \RuntimeException( 'terms' );
			if ( $action === 'delete' ) { unset( $old[$id] ); if ( isset( $current[$id] ) ) throw new \RuntimeException( 'delete' ); }
			else {
				if ( ! isset( $current[$affected] ) || $current[$affected]['name'] !== $name ) throw new \RuntimeException( 'name' );
				if ( $action === 'rename' ) { $wanted = $old[$id]; $wanted['name'] = $name; if ( $current[$id] !== $wanted ) throw new \RuntimeException( 'preservation' ); unset( $old[$id] ); }
				elseif ( $current[$affected]['productCount'] || $current[$affected]['variationCount'] || $current[$affected]['otherCount'] || $current[$affected]['chargeCount'] ) throw new \RuntimeException( 'dependency' );
				unset( $current[$affected] );
			}
			if ( $current !== $old ) throw new \RuntimeException( 'preservation' );
			if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'commit' );
			$transaction = false;
			return [ 'changed' => true, 'action' => $action, 'id' => $affected ];
		} catch ( \Throwable $error ) {
			return $this->error( 'write', 'The class change could not be confirmed. Refresh the saved list before retrying.' );
		} finally {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); foreach ( $optionKeys as $key ) wp_cache_delete( $key, 'options' ); }
			if ( $touched ) { $cacheIds[] = $affected; clean_term_cache( $cacheIds, self::TAXONOMY ); foreach ( $cacheIds as $termId ) wp_cache_delete( $termId, 'term_meta' ); \WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' ); \WC_Cache_Helper::get_transient_version( 'shipping', true ); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
