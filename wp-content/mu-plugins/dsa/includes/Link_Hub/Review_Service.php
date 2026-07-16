<?php

namespace DSA\Link_Hub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Review_Service {
	public function review_data( array $config, bool $allow_google = true ): array {
		if ( $allow_google && 'google' === ( $config['review_source'] ?? 'manual' ) ) {
			$google = $this->google_review( $config );

			if ( ! empty( $google['text'] ) ) {
				return $google;
			}
		}

		$lines = preg_split( '/\r\n|\r|\n/', (string) ( $config['testimonials'] ?? '' ) );
		$lines = array_values(
			array_filter(
				array_map( 'trim', is_array( $lines ) ? $lines : [] )
			)
		);

		if ( empty( $lines ) ) {
			$lines = [ __( 'Trusted by customers who come back for the taste and care.', 'dsa' ) ];
		}

		$text = $lines[ count( $lines ) > 1 ? wp_rand( 0, count( $lines ) - 1 ) : 0 ];

		return [
			'source' => $allow_google ? 'testimonial' : sanitize_key( $config['review_source'] ?? 'manual' ),
			'text'   => $text,
			'author' => get_bloginfo( 'name' ),
			'rating' => 5,
		];
	}

	private function google_review( array $config ): array {
		$place_id = trim( (string) ( $config['google_place_id'] ?? '' ) );
		$api_key  = trim( (string) ( $config['google_api_key'] ?? '' ) );

		if ( '' === $place_id || '' === $api_key ) {
			return [];
		}

		$cache_key = 'dsa_google_reviews_' . md5( $place_id );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://places.googleapis.com/v1/places/' . rawurlencode( $place_id ),
			[
				'timeout' => 5,
				'headers' => [
					'X-Goog-Api-Key'   => $api_key,
					'X-Goog-FieldMask' => 'reviews',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$reviews = isset( $body['reviews'] ) && is_array( $body['reviews'] ) ? $body['reviews'] : [];

		if ( empty( $reviews ) ) {
			return [];
		}

		$review = $reviews[ count( $reviews ) > 1 ? wp_rand( 0, count( $reviews ) - 1 ) : 0 ];
		$text   = $review['text']['text'] ?? $review['originalText']['text'] ?? '';
		$out    = [
			'source' => 'google',
			'text'   => wp_strip_all_tags( (string) $text ),
			'author' => sanitize_text_field( $review['authorAttribution']['displayName'] ?? 'Google review' ),
			'rating' => max( 0, min( 5, (int) ( $review['rating'] ?? 5 ) ) ),
		];

		set_transient( $cache_key, $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}
}
