<?php
namespace DSA\Link_Hub;

use DSA\Utilities\Content_Visibility;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Shared public subject, independent of discussion state, visitor identity and raw page URLs. */
final class Public_Subject_Service {
	public static function current(): array {
		return self::resolve( did_action( 'wp' ) && is_singular( [ 'post','product' ] ) ? (int) get_queried_object_id() : 0 );
	}
	public static function resolve( int $id ): array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( $post && in_array( $post->post_type, [ 'post','product' ], true ) && ( 'product' !== $post->post_type || \DSA\Apps\Store_App_Service::enabled() ) && Content_Visibility::is_public( $post ) ) {
			$url = self::url( (string) get_permalink( $post ) );
			if ( $url ) return [ 'id'=>(int) $post->ID, 'title'=>self::title( get_the_title( $post ) ), 'url'=>$url ];
		}
		return [ 'id'=>0, 'title'=>self::title( get_bloginfo( 'name' ) ), 'url'=>self::url( home_url( '/' ) ) ];
	}
	private static function title( string $title ): string {
		preg_match( '/\A.{0,180}/us', sanitize_text_field( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), $match );
		return $match[0] ?? '';
	}
	private static function url( string $url ): string {
		if ( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) return '';
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http','https' ], true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] ) ? $url : '';
	}
}
