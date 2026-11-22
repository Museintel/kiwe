<?php
namespace DSA\Link_Hub;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native validated privacy requests, never a public contact/account lookup. */
final class Enquiry_Privacy {
	private const SIZE = 20;
	public static function exporters( array $items ): array {
		$items['kiwe-link-enquiries'] = [ 'exporter_friendly_name'=>__( 'Kiwe link enquiries', 'dsa' ), 'callback'=>[ self::class,'export' ] ]; return $items;
	}
	public static function erasers( array $items ): array {
		$items['kiwe-link-enquiries'] = [ 'eraser_friendly_name'=>__( 'Kiwe link enquiries', 'dsa' ), 'callback'=>[ self::class,'erase' ] ]; return $items;
	}
	private static function rows( string $email, int $offset = 0 ) {
		global $wpdb;
		if ( ! is_email( $email ) ) return [];
		$json = "CASE WHEN JSON_VALID(post_content) THEN post_content ELSE '{}' END";
		$where = "(JSON_UNQUOTE(JSON_EXTRACT({$json}, '$.contactKind'))='email' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT({$json}, '$.contact')))=LOWER(%s))";
		$args = [ Enquiry_Store::TYPE, Enquiry_Service::SCHEMA, $email ];
		// A native email request may cover a phone only through both actually verified factors.
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$own_email = \DSA\PhoneKey\PhoneKey_Bridge::campaign_contact( (int) $user->ID, 'email' );
			$phone = \DSA\PhoneKey\PhoneKey_Bridge::campaign_contact( (int) $user->ID, 'whatsapp' );
			if ( $own_email && 0 === strcasecmp( $own_email, $email ) && $phone ) { $where .= " OR (JSON_UNQUOTE(JSON_EXTRACT({$json}, '$.contactKind'))='phone' AND BINARY JSON_UNQUOTE(JSON_EXTRACT({$json}, '$.contact'))=BINARY %s)"; $args[] = $phone; }
		}
		$args[] = self::SIZE + 1; $args[] = max( 0, $offset );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_date_gmt,LEFT(post_content,32769) AS post_content FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('private','trash') AND post_author=0 AND JSON_UNQUOTE(JSON_EXTRACT({$json}, '$.schema'))=%s AND ({$where}) ORDER BY ID LIMIT %d OFFSET %d", $args ), ARRAY_A );
		return is_array( $rows ) && ! $wpdb->last_error ? $rows : new \WP_Error( 'kiwe_enquiry_privacy', __( 'Enquiries could not be read. Retry this privacy request when storage is available.', 'dsa' ) );
	}
	public static function export( string $email, int $page = 1 ) {
		$rows = self::rows( $email, ( max( 1, $page ) - 1 ) * self::SIZE ); if ( is_wp_error( $rows ) ) return $rows;
		$items = [];
		foreach ( array_slice( $rows, 0, self::SIZE ) as $row ) {
			$value = Enquiry_Store::decode( $row['post_content'] ); if ( ! $value ) continue;
			$items[] = [ 'group_id'=>'kiwe-link-enquiries', 'group_label'=>__( 'Link enquiries', 'dsa' ), 'item_id'=>'kiwe-link-enquiry-' . (int) $row['ID'], 'data'=>[
				[ 'name'=>__( 'Date (UTC)', 'dsa' ),'value'=>$row['post_date_gmt'] ],
				[ 'name'=>__( 'Channel', 'dsa' ),'value'=>$value['channel'] ],
				[ 'name'=>__( 'Name', 'dsa' ),'value'=>esc_html( $value['name'] ) ],
				[ 'name'=>__( 'Contact', 'dsa' ),'value'=>esc_html( $value['contact'] ) ],
				[ 'name'=>__( 'Message', 'dsa' ),'value'=>esc_html( $value['message'] ) ],
			] ];
		}
		return [ 'data'=>$items, 'done'=>count( $rows ) <= self::SIZE ];
	}
	public static function erase( string $email, int $page = 1 ) {
		// Removing from the remaining first page prevents deletion from skipping later records.
		$rows = self::rows( $email ); if ( is_wp_error( $rows ) ) return $rows;
		foreach ( array_slice( $rows, 0, self::SIZE ) as $row ) if ( is_wp_error( Enquiry_Store::remove( (int) $row['ID'] ) ) ) return new \WP_Error( 'kiwe_enquiry_privacy', __( 'Some enquiries could not be removed. Retry this privacy request.', 'dsa' ) );
		return [ 'items_removed'=>(bool) $rows, 'items_retained'=>false, 'messages'=>[], 'done'=>count( $rows ) <= self::SIZE ];
	}
}
