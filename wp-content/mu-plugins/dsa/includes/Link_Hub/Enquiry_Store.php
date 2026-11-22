<?php

namespace DSA\Link_Hub;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Core-owned private enquiries. Native Bricks submissions remain in their own table. */
final class Enquiry_Store {

	public const TYPE = 'kiwe_enquiry';

	/** Registration has no query, record allocation or provider dependency. */
	public static function register(): void {
		register_post_type( self::TYPE, [
			'label'=>__( 'Private enquiries', 'dsa' ), 'public'=>false, 'publicly_queryable'=>false,
			'exclude_from_search'=>true, 'show_ui'=>false, 'show_in_menu'=>false, 'show_in_nav_menus'=>false,
			'show_in_rest'=>false, 'query_var'=>false, 'rewrite'=>false, 'can_export'=>false,
			'supports'=>false, 'capability_type'=>'kiwe_enquiry', 'map_meta_cap'=>true,
			'capabilities'=>array_fill_keys( [ 'edit_post','read_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts','read' ], 'do_not_allow' ),
		] );
	}

	/** One native post insert, serialized per signed ticket; retries cannot create another lead. */
	public static function save( array $record ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $record['key'] ?? '' ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['requestHash'] ?? '' ) ) return Enquiry_Service::error( 'The enquiry could not be prepared.' );
		$lock = 'kiwe_enq_' . substr( hash( 'sha256', $wpdb->posts . '|' . $record['key'] ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) return Enquiry_Service::error( 'Your enquiry is busy. Please try again.', 503 );
		try {
			$slug = 'kwe-' . $record['key'];
			$old = $wpdb->get_row( $wpdb->prepare( "SELECT ID,post_status,LEFT(post_content,32769) AS post_content FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s LIMIT 1", self::TYPE, $slug ), ARRAY_A );
			if ( $wpdb->last_error ) return Enquiry_Service::error( 'Your enquiry could not be saved. Please try again.', 503 );
			if ( is_array( $old ) ) {
				$value = self::decode( $old['post_content'] );
				if ( 'private' !== $old['post_status'] || ! $value || ! hash_equals( $value['requestHash'], $record['requestHash'] ) ) return Enquiry_Service::error( 'This enquiry was already used. Start a new enquiry for another message.', 409 );
				return [ 'id'=>(int) $old['ID'], 'created'=>false, 'record'=>$value ];
			}
			// Expired signed intents may confirm an identical saved receipt, never create a lead.
			if ( ! is_int( $record['admittedUntil'] ?? null ) || $record['admittedUntil'] < time() ) return new \WP_Error( 'kiwe_link_enquiry_expired', 'This enquiry session expired. Refresh it and retry with your message.', [ 'status'=>409 ] );
			$json = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( ! is_string( $json ) || strlen( $json ) > Enquiry_Service::MAX_BODY ) return Enquiry_Service::error( 'This message is too large. Please shorten it.' );
			// Native wp_insert_post substitutes the current actor for an empty/zero author. Keep the
			// record unowned, so ordinary private-post author queries cannot expose the enquiry.
			$private = static function ( array $data ) use ( $slug ): array {
				if ( self::TYPE === ( $data['post_type'] ?? '' ) && $slug === ( $data['post_name'] ?? '' ) ) {
					$data['post_author'] = 0; $data['post_status'] = 'private';
				}
				return $data;
			};
			add_filter( 'wp_insert_post_data', $private, PHP_INT_MAX );
			try { $id = wp_insert_post( wp_slash( [ 'post_type'=>self::TYPE, 'post_status'=>'private', 'post_author'=>0, 'post_title'=>'Link enquiry', 'post_name'=>$slug, 'post_content'=>$json, 'comment_status'=>'closed', 'ping_status'=>'closed' ] ), true ); }
			finally { remove_filter( 'wp_insert_post_data', $private, PHP_INT_MAX ); }
			if ( is_wp_error( $id ) || ! $id ) return Enquiry_Service::error( 'Your enquiry could not be saved. Please try again.', 503 );
			$stored = self::record( (int) $id );
			if ( ! $stored || $stored['value'] !== $record ) return Enquiry_Service::error( 'The saved enquiry could not be confirmed. Keep your message and try again.', 503 );
			return [ 'id'=>(int) $id, 'created'=>true, 'record'=>$stored['value'] ];
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function decode( string $json ): ?array {
		if ( strlen( $json ) > Enquiry_Service::MAX_BODY ) return null;
		$row = json_decode( $json, true );
		if ( ! is_array( $row ) || Enquiry_Service::SCHEMA !== ( $row['schema'] ?? null ) || ! is_string( $row['requestHash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row['requestHash'] ) || ! in_array( $row['channel'] ?? '', Enquiry_Service::CHANNELS, true ) ) return null;
		foreach ( [ 'name','contact','contactKind','message' ] as $key ) if ( ! is_string( $row[$key] ?? null ) ) return null;
		if ( ! is_int( $row['subjectId'] ?? null ) || ! is_bool( $row['verifiedContact'] ?? null ) ) return null;
		$body = []; foreach ( [ 'channel','name','contact','contactKind','message','subjectId' ] as $key ) $body[$key] = $row[$key];
		if ( ! hash_equals( $row['requestHash'], hash( 'sha256', wp_json_encode( $body ) ) ) ) return null;
		return $row;
	}

	/** Internal owner adapters must apply Forms permission before projecting private answers. */
	public static function record( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID,post_date_gmt,LEFT(post_content,32769) AS post_content FROM {$wpdb->posts} WHERE ID=%d AND post_type=%s AND post_status='private' AND post_author=0 LIMIT 1", $id, self::TYPE ), ARRAY_A );
		$value = is_array( $row ) && ! $wpdb->last_error ? self::decode( $row['post_content'] ) : null;
		return $value ? [ 'id'=>(int) $row['ID'], 'created'=>$row['post_date_gmt'], 'value'=>$value ] : null;
	}

	public static function title( string $channel ): string { return sprintf( __( '%s enquiry', 'dsa' ), [ 'whatsapp'=>'WhatsApp','email'=>'Email','x'=>'X','instagram'=>'Instagram','facebook'=>'Facebook' ][$channel] ?? 'Link' ); }

	/** Internal native deletion; the Forms route or validated privacy processor owns permission. */
	public static function remove( int $id ) {
		global $wpdb;
		$owned = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d AND post_type=%s AND post_status IN ('private','trash') AND post_author=0 LIMIT 1", $id, self::TYPE ) );
		if ( $wpdb->last_error ) return Enquiry_Service::error( 'The enquiry could not be read. Please retry.', 503 );
		if ( ! $owned ) return Enquiry_Service::error( 'This enquiry is unavailable.', 404 );
		if ( ! wp_delete_post( (int) $owned, true ) ) return Enquiry_Service::error( 'The enquiry could not be removed. Please retry.', 503 );
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d LIMIT 1", $id ) );
		return null === $remaining && ! $wpdb->last_error ? true : Enquiry_Service::error( 'The enquiry removal could not be confirmed. Please retry.', 503 );
	}

	public static function item( array $row ): array {
		$value = self::decode( $row['post_content'] );
		return [ 'id'=>(int) $row['ID'], 'source'=>'links', 'formId'=>Enquiry_Service::FORM, 'title'=>$value ? self::title( $value['channel'] ) : __( 'Unreadable enquiry', 'dsa' ), 'created'=>(string) $row['post_date_gmt'], 'page'=>__( 'Links', 'dsa' ), 'name'=>$value['name'] ?? '', 'verifiedContact'=>true === ( $value['verifiedContact'] ?? false ) ];
	}

	/** Independent ID ceiling: never compare native Bricks IDs with WordPress post IDs. */
	public static function activity( int $after = 0 ): ?array {
		global $wpdb;
		$ceiling = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='private' AND post_author=0", self::TYPE ) );
		if ( $wpdb->last_error || null === $ceiling ) return null;
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='private' AND post_author=0 AND ID>%d AND ID<=%d", self::TYPE, max( 0, $after ), (int) $ceiling ) );
		return $wpdb->last_error || null === $count ? null : [ 'ceiling'=>(int) $ceiling, 'count'=>(int) $count ];
	}
}
