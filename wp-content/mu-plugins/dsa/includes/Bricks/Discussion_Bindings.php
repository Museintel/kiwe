<?php
namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One current-loop binding for native Bricks controls and the shared Comments launcher. */
final class Discussion_Bindings {
	public static function render( string $name, $post = null ): string {
		$looping = class_exists( '\Bricks\Query' ) && \Bricks\Query::is_looping();
		if ( $looping ) {
			$object = \Bricks\Query::get_loop_object();
			if ( ! $object instanceof \WP_Post ) return '';
			$post = $object;
		} elseif ( ! $post instanceof \WP_Post ) {
			$post = is_numeric( $post ) ? get_post( (int) $post ) : ( is_singular() ? get_post( get_queried_object_id() ) : null );
		}
		$target = $post ? \DSA\Discussion\Discussion_Target::get( (int) $post->ID ) : null;
		if ( ! $target || is_wp_error( $target ) ) return '';
		if ( 'kiwe_discussion_id' === $name ) return (string) $target->ID;
		$count = ( new \DSA\Discussion\Discussion_Service() )->count( $target ); return is_wp_error( $count ) ? '' : (string) $count;
	}
}
