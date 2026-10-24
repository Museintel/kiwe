<?php
namespace DSA\SEO;

use DSA\Onboarding\Context_Selection_Service as Visibility;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Fixed-size native projections: no post bodies, full metadata inventory or legacy AI job. */
final class SEO_Score_Source {
	public const PAGE_SIZE = 25;
	public function upper() {
		global $wpdb;
		$id = $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		return $wpdb->last_error ? new \WP_Error( 'score_read', 'Could not read the content boundary.' ) : (int) $id;
	}
	public function page( int $cursor, int $upper ) {
		global $wpdb;
		$present = static fn( string $column ): string => "LENGTH(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column},CHAR(9),''),CHAR(10),''),CHAR(11),''),CHAR(13),''),CHAR(0),'')))>0";
		$meta = static fn( string $key, string $expression ): string => "(SELECT {$expression} FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='{$key}' ORDER BY m.meta_id ASC LIMIT 1)";
		$sql = "SELECT p.ID,p.post_type,p.post_status,(p.post_password='') AS unprotected,p.post_parent,
			parent.post_type AS parent_type,parent.post_status AS parent_status,(parent.post_password='') AS parent_unprotected,
			(p.post_mime_type LIKE 'image/%') AS is_image," . $present( 'p.post_title' ) . ' AS has_title,' . $present( 'p.post_excerpt' ) . ' AS has_excerpt,' . $present( 'p.post_content' ) . ' AS has_description,' .
			$meta( '_kiwe_seo_title', $present( 'm.meta_value' ) ) . ' AS has_seo_title,' . $meta( '_kiwe_seo_description', $present( 'm.meta_value' ) ) . ' AS has_seo_description,' .
			$meta( '_wp_attachment_image_alt', $present( 'm.meta_value' ) ) . ' AS has_alt,' . $meta( '_kiwe_search_phrases', 'LEFT(m.meta_value,2048)' ) . ' AS phrases,' .
			$meta( '_kiwe_seo_intentional_empty_fields', 'LEFT(m.meta_value,512)' ) . " AS intentional
			FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent
			WHERE p.ID>%d AND p.ID<=%d AND p.post_type IN ('post','page','product','attachment') ORDER BY p.ID ASC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cursor, $upper, self::PAGE_SIZE ), ARRAY_A );
		return $wpdb->last_error || ! is_array( $rows ) ? new \WP_Error( 'score_read', 'Could not read the next checklist page.' ) : $rows;
	}
	/** A checklist tests available fields, never model approval or guaranteed search performance. */
	public static function points( array $row, bool $commerce ): ?array {
		$type = $row['post_type'];
		$post = (object) [ 'post_type'=>$type, 'post_status'=>$row['post_status'], 'post_password'=>! empty( $row['unprotected'] ) ? '' : 'protected' ];
		if ( 'attachment' === $type ) {
			if ( 'inherit' !== $post->post_status || $post->post_password ) return null;
			if ( $row['post_parent'] && ! Visibility::public_post( (object) [ 'post_type'=>$row['parent_type'], 'post_status'=>$row['parent_status'], 'post_password'=>! empty( $row['parent_unprotected'] ) ? '' : 'protected' ] ) ) return null;
			$image = ! empty( $row['is_image'] );
			$points = ! empty( $row['has_title'] ) ? ( $image ? 20 : 30 ) : 0;
			if ( $image ) $points += ! empty( $row['has_alt'] ) || in_array( 'alt', self::strings( $row['intentional'] ), true ) ? 40 : 0;
			$points += ! empty( $row['has_excerpt'] ) ? ( $image ? 20 : 30 ) : 0;
			$points += ! empty( $row['has_description'] ) ? ( $image ? 20 : 40 ) : 0;
			return [ 'scope'=>'media', 'points'=>$points ];
		}
		if ( ! in_array( $type, [ 'post','page','product' ], true ) || ! Visibility::public_post( $post ) || ( 'product' === $type && ! $commerce ) ) return null;
		$points = ( ! empty( $row['has_seo_title'] ) ? 40 : 0 ) + ( ! empty( $row['has_seo_description'] ) ? 40 : 0 ) + ( ! empty( $row['has_excerpt'] ) ? 10 : 0 ) + ( self::strings( $row['phrases'] ) ? 10 : 0 );
		return [ 'scope'=>[ 'post'=>'posts','page'=>'pages','product'=>'products' ][ $type ], 'points'=>$points ];
	}
	private static function strings( $value ): array {
		if ( ! is_string( $value ) || ! str_starts_with( $value, 'a:' ) ) return [];
		$items = @unserialize( $value, [ 'allowed_classes'=>false, 'max_depth'=>16 ] );
		return is_array( $items ) ? array_values( array_filter( $items, static fn( $item ): bool => is_string( $item ) && '' !== trim( $item ) ) ) : [];
	}
}
