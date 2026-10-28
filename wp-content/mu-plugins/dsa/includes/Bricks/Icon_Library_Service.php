<?php

namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native Bricks Icon dynamic data, using the same packaged artwork as the Dock. */
final class Icon_Library_Service {
	/** Names are fixed authoring vocabulary, never filesystem paths or uploaded SVGs. */
	private const SYMBOLS = [
		'arrow_right' => 'arrow-right', 'bell' => 'bell', 'bookmark' => 'bookmark',
		'circle_alert' => 'circle-alert', 'download' => 'download', 'grip' => 'grip',
		'heart' => 'heart', 'key_round' => 'key-round', 'log_out' => 'log-out',
		'map_pin' => 'map-pin', 'package' => 'package', 'search' => 'search',
		'share_2' => 'share-2', 'shield_check' => 'shield-check', 'shopping_bag' => 'shopping-bag',
		'sparkles' => 'sparkles', 'sun_moon' => 'sun-moon', 'user_round' => 'user-round',
		'cart' => 'shopping-bag', 'notifications' => 'bell', 'wishlist' => 'heart',
		'menu' => 'grip', 'profile' => 'user-round', 'links' => 'share-2',
		'saved' => 'bookmark', 'theme' => 'sun-moon', 'secure' => 'shield-check',
		'app_ios' => 'app-ios', 'app_android' => 'app-android',
		'message_circle' => 'message-circle', 'comments' => 'message-circle',
		'thumbs_up' => 'thumbs-up', 'flag' => 'flag', 'pin' => 'pin',
	];

	private static ?string $sprite = null;
	private static array $rendered = [];

	/** The chooser enumerates no users, records, files, options or providers. */
	public static function definitions(): array {
		$definitions = [];
		foreach ( self::SYMBOLS as $name => $symbol ) {
			$definitions[ 'kiwe_icon_' . $name ] = sprintf(
				/* translators: %s: Built-in icon name. */
				__( 'Icon: %s', 'dsa' ),
				ucwords( str_replace( '_', ' ', $name ) )
			);
		}
		return $definitions;
	}

	/**
	 * Bricks calls native Icon dynamic data in text context before render_svg().
	 * Image, URL and attribute contexts must never receive executable markup.
	 */
	public static function render( string $tag, string $context = 'text' ): string {
		if ( 'text' !== $context || ! str_starts_with( $tag, 'kiwe_icon_' ) ) return '';
		$name = substr( $tag, strlen( 'kiwe_icon_' ) );
		$symbol = self::SYMBOLS[ $name ] ?? '';
		if ( '' === $symbol ) return '';
		if ( array_key_exists( $symbol, self::$rendered ) ) return self::$rendered[ $symbol ];

		if ( null === self::$sprite ) {
			// Fixed package-relative asset; read only when a real icon is rendered.
			$path = dirname( __DIR__, 2 ) . '/assets/icons/lucide/sprite.svg';
			$contents = is_readable( $path ) ? file_get_contents( $path, false, null, 0, 65537 ) : false;
			self::$sprite = is_string( $contents ) && strlen( $contents ) <= 65536 ? $contents : '';
		}
		self::$rendered[ $symbol ] = '';
		if ( ! preg_match( '/<symbol\b[^>]*\bid="' . preg_quote( $symbol, '/' ) . '"[^>]*>(.*?)<\/symbol>/s', self::$sprite, $match ) ) return '';
		$paths = trim( $match[1] );
		// This is trusted package artwork, but fail closed if its shape vocabulary changes.
		if ( '' === $paths || preg_match( '/<(?!\/?(?:path|circle|line|polyline|polygon|rect|ellipse)(?:\s|\/?>))/i', $paths ) || preg_match( '/\s(?:on[a-z]+|(?:[a-z]+:)?href|style)\s*=|url\s*\(/i', $paths ) ) return '';

		$view_box = in_array($symbol,['app-ios','app-android'],true)?'0 0 48 48':'0 0 24 24';
		return self::$rendered[ $symbol ] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $view_box . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	}
}
