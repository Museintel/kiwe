<?php

namespace DSA\Runtime;

use DSA\Element_Registry;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Editorial_Fragment_Service {
	private const VERSION = 1;
	private const OFFLINE_VERSION = 1;

	public function __construct( private Element_Registry $registry ) {}

	public function envelope( string $url ): array {
		$target = $this->resolve_target( $url );

		if ( is_wp_error( $target ) ) {
			return [
				'version' => self::VERSION,
				'ok' => false,
				'morphReady' => false,
				'fallback' => [ 'mode' => 'full_document', 'reason' => $target->get_error_code() ],
			];
		}

		global $post, $wp_query;
		$previous_post  = $post;
		$previous_query = $wp_query;
		$query = new WP_Query(
			[
				'p' => $target->ID,
				'post_type' => $target->post_type,
				'post_status' => 'publish',
				'posts_per_page' => 1,
				'no_found_rows' => true,
			]
		);

		if ( ! $query->have_posts() ) {
			return $this->failure( 'editorial_query_failed' );
		}

		$wp_query = $query;
		$post = $query->post;
		setup_postdata( $post );

		try {
			$rendered = $this->render_content( $post );
			$html = $this->strip_executable_content( $rendered['html'] );
			$blockers = $this->content_blockers( $rendered['html'] );
			$snapshot = $this->registry->snapshot_for_post( (int) $post->ID );
			$assets = $this->asset_contract();
			$canonical = get_permalink( $post );
			$title = wp_get_document_title();

			return [
				'version' => self::VERSION,
				'ok' => '' !== trim( $html ),
				'morphReady' => false,
				'route' => [
					'kind' => 'editorial_singular',
					'postId' => (int) $post->ID,
					'postType' => sanitize_key( (string) $post->post_type ),
					'canonicalUrl' => esc_url_raw( (string) $canonical ),
				],
				'document' => [
					'title' => sanitize_text_field( $title ?: get_the_title( $post ) ),
					'metaDescription' => sanitize_text_field( wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt( $post ) ), 32, '' ) ),
					'bodyClasses' => array_values( array_unique( array_map( 'sanitize_html_class', get_body_class() ) ) ),
				],
				'content' => [
					'html' => $html,
					'hash' => hash( 'sha256', $html ),
					'renderer' => $rendered['renderer'],
					'confidence' => $rendered['confidence'],
					'blockers' => $blockers,
				],
				'registry' => [
					'available' => ! empty( $snapshot ),
					'version' => absint( $snapshot['version'] ?? 0 ),
					'count' => absint( $snapshot['count'] ?? 0 ),
					'contentHash' => sanitize_text_field( (string) ( $snapshot['contentHash'] ?? '' ) ),
					'postModifiedGmt' => sanitize_text_field( (string) ( $snapshot['postModifiedGmt'] ?? '' ) ),
				],
				'assets' => $assets,
				'cache' => [
					'scope' => 'private',
					'header' => 'private, no-store',
					'vary' => [ 'Cookie' ],
					'reason' => 'Dynamic builder and WordPress filters may depend on visitor context.',
				],
				'fallback' => [
					'mode' => 'full_document',
					'reason' => $blockers ? 'content_blockers' : 's14_reconciliation_required',
				],
			];
		} finally {
			wp_reset_postdata();
			$post = $previous_post;
			$wp_query = $previous_query;
		}
	}

	public function offline_document( string $url ): array {
		$target = $this->resolve_target( $url );
		if ( is_wp_error( $target ) ) {
			return $this->offline_failure( $target->get_error_code() );
		}

		if ( $this->has_bricks_content( (int) $target->ID ) ) {
			return $this->offline_failure( 'builder_content_requires_network' );
		}

		$source = trim( (string) $target->post_content );
		if ( '' === $source ) {
			return $this->offline_failure( 'empty_editorial_content' );
		}

		$content = wp_kses_post( wpautop( strip_shortcodes( $source ) ) );
		$content = (string) preg_replace( '#<(?:form|button|input|select|textarea|iframe|object|embed|script|style)\b[^>]*>.*?</(?:form|button|select|textarea|iframe|object|embed|script|style)>#is', '', $content );
		$content = (string) preg_replace( '#<(?:input)\b[^>]*\/?>#i', '', $content );
		$content = trim( $content );
		if ( '' === wp_strip_all_tags( $content ) ) {
			return $this->offline_failure( 'no_static_editorial_text' );
		}

		$canonical = get_permalink( $target );
		return [
			'version' => self::OFFLINE_VERSION,
			'ok' => true,
			'offlineReady' => true,
			'route' => [
				'postId' => (int) $target->ID,
				'postType' => sanitize_key( (string) $target->post_type ),
				'canonicalUrl' => esc_url_raw( (string) $canonical ),
				'modifiedGmt' => sanitize_text_field( (string) $target->post_modified_gmt ),
			],
			'document' => [
				'title' => sanitize_text_field( (string) $target->post_title ),
				'description' => sanitize_text_field( wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) ( $target->post_excerpt ?: $source ) ) ), 32, '' ) ),
			],
			'content' => [
				'html' => $content,
				'hash' => hash( 'sha256', $content ),
				'source' => 'wordpress-static-content',
			],
			'media' => $this->offline_media_hints( $target, $content ),
			'cache' => [
				'scope' => 'public-editorial',
				'version' => self::OFFLINE_VERSION,
				'maxAge' => 300,
				'staleWhileRevalidate' => 86400,
			],
		];
	}

	private function resolve_target( string $url ) {
		$url = esc_url_raw( $url );
		$target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $url || '' === $target_host || ! in_array( $target_host, $this->same_site_hosts(), true ) ) {
			return new \WP_Error( 'cross_origin_or_invalid_url' );
		}

		parse_str( $query, $params );
		if ( array_intersect( [ 'add-to-cart', 'wc-ajax', 'bricks', 'preview', 'preview_id', 'nonce', '_wpnonce' ], array_keys( $params ) ) ) {
			return new \WP_Error( 'unsafe_query' );
		}

		$post_id = url_to_postid( $url );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, [ 'post', 'page' ], true ) || post_password_required( $post ) ) {
			return new \WP_Error( 'not_approved_editorial_singular' );
		}

		return $post;
	}

	private function render_content( \WP_Post $post ): array {
		if ( class_exists( '\\Bricks\\Helpers' ) && class_exists( '\\Bricks\\Frontend' ) && method_exists( '\\Bricks\\Helpers', 'get_bricks_data' ) && method_exists( '\\Bricks\\Frontend', 'render_data' ) ) {
			$data = \Bricks\Helpers::get_bricks_data( (int) $post->ID, 'content', true );
			if ( is_array( $data ) && $data ) {
				return [
					'html' => (string) \Bricks\Frontend::render_data( $data, 'content' ),
					'renderer' => 'bricks-2.3-contract',
					'confidence' => 'builder_verified',
				];
			}
		}

		return [
			'html' => (string) apply_filters( 'the_content', $post->post_content ),
			'renderer' => 'wordpress-the-content',
			'confidence' => 'wordpress_filtered',
		];
	}

	private function has_bricks_content( int $post_id ): bool {
		if ( ! class_exists( '\\Bricks\\Helpers' ) || ! method_exists( '\\Bricks\\Helpers', 'get_bricks_data' ) ) {
			return false;
		}
		$data = \Bricks\Helpers::get_bricks_data( $post_id, 'content', true );
		return is_array( $data ) && ! empty( $data );
	}

	private function offline_media_hints( \WP_Post $post, string $content ): array {
		$urls = [];
		$featured = get_the_post_thumbnail_url( $post, 'large' );
		if ( $featured ) $urls[] = $featured;
		if ( preg_match_all( '#<img\b[^>]+src=["\']([^"\']+)["\']#i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		$allowed_hosts = $this->same_site_hosts();
		$hints = [];
		foreach ( array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, 8 ) as $media_url ) {
			$media_url = esc_url_raw( (string) $media_url );
			if ( '' === $media_url || ! in_array( strtolower( (string) wp_parse_url( $media_url, PHP_URL_HOST ) ), $allowed_hosts, true ) ) continue;
			$attachment_id = attachment_url_to_postid( $media_url );
			$metadata = $attachment_id ? wp_get_attachment_metadata( $attachment_id ) : [];
			$hints[] = [
				'url' => $media_url,
				'type' => $attachment_id ? sanitize_mime_type( (string) get_post_mime_type( $attachment_id ) ) : '',
				'width' => absint( $metadata['width'] ?? 0 ),
				'height' => absint( $metadata['height'] ?? 0 ),
			];
		}
		return $hints;
	}

	private function same_site_hosts(): array {
		$hosts = [];
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( is_string( $home_host ) && '' !== $home_host ) {
			$hosts[] = strtolower( $home_host );
		}

		$request_host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$request_host = strtolower( preg_replace( '/:\d+$/', '', $request_host ) );

		if ( '' !== $request_host ) {
			$hosts[] = $request_host;
		}

		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	private function strip_executable_content( string $html ): string {
		$html = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$html = (string) preg_replace( '#<base\b[^>]*>#i', '', $html );
		return trim( $html );
	}

	private function content_blockers( string $html ): array {
		$checks = [
			'script' => '/<script\b/i',
			'form' => '/<form\b/i',
			'iframe' => '/<iframe\b/i',
			'inline_event_handler' => '/\son[a-z]+\s*=/i',
			'nonce_field' => '/(?:_wpnonce|wp_rest)/i',
		];
		$found = [];
		foreach ( $checks as $label => $pattern ) {
			if ( preg_match( $pattern, $html ) ) $found[] = $label;
		}
		return $found;
	}

	private function asset_contract(): array {
		return [
			'complete' => false,
			'scripts' => $this->asset_records( wp_scripts(), 'script' ),
			'styles' => $this->asset_records( wp_styles(), 'style' ),
			'reason' => 'S13 records assets observed during isolated rendering; S14 must reconcile them against the destination document before morphing.',
		];
	}

	private function asset_records( $dependencies, string $kind ): array {
		if ( ! is_object( $dependencies ) || ! is_array( $dependencies->registered ?? null ) ) return [];
		$handles = array_values( array_unique( array_merge( (array) ( $dependencies->queue ?? [] ), (array) ( $dependencies->done ?? [] ) ) ) );
		$out = [];
		foreach ( $handles as $handle ) {
			$handle = sanitize_key( (string) $handle );
			if ( ! isset( $dependencies->registered[ $handle ] ) ) continue;
			$asset = $dependencies->registered[ $handle ];
			$out[] = [
				'handle' => $handle,
				'kind' => $kind,
				'src' => esc_url_raw( (string) ( $asset->src ?? '' ) ),
				'deps' => array_values( array_map( 'sanitize_key', (array) ( $asset->deps ?? [] ) ) ),
				'version' => sanitize_text_field( (string) ( $asset->ver ?? '' ) ),
			];
		}
		return $out;
	}

	private function failure( string $reason ): array {
		return [ 'version' => self::VERSION, 'ok' => false, 'morphReady' => false, 'fallback' => [ 'mode' => 'full_document', 'reason' => $reason ] ];
	}

	private function offline_failure( string $reason ): array {
		return [
			'version' => self::OFFLINE_VERSION,
			'ok' => false,
			'offlineReady' => false,
			'fallback' => [ 'mode' => 'network_only', 'reason' => sanitize_key( $reason ) ],
		];
	}
}
