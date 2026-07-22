<?php

namespace DSA\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presentation-only copy contract for Kiwe DSA screens/sheets.
 *
 * These fields never own capability data. They only rename or describe the
 * chrome around Kiwe-owned Profile, Menu, Search, Saved, Links, Cart,
 * Checkout, Notifications, Install, Games, and AI payloads.
 */
final class Screen_Copy_Schema {
	public static function screens(): array {
		return [
			'profile'       => [
				'label'       => __( 'Profile', 'dsa' ),
				'description' => __( 'Account/profile surface labels. User, orders, addresses, downloads, and auth state remain Kiwe/Woo/WordPress-owned.', 'dsa' ),
				'fields'      => [
					'label'             => self::field( __( 'Dock/module label', 'dsa' ), __( 'Profile / Account', 'dsa' ) ),
					'eyebrow'           => self::field( __( 'Eyebrow', 'dsa' ), __( 'Profile & Activity', 'dsa' ) ),
					'title'             => self::field( __( 'Title', 'dsa' ), __( 'Your account', 'dsa' ) ),
					'intro'             => self::field( __( 'Intro/body copy', 'dsa' ), __( 'Manage your account, orders, and saved activity.', 'dsa' ), 220 ),
					'accountLabel'      => self::field( __( 'Account label', 'dsa' ), __( 'Kiwe account', 'dsa' ) ),
					'editLabel'         => self::field( __( 'Edit button', 'dsa' ), __( 'Edit', 'dsa' ) ),
					'ordersTitle'       => self::field( __( 'Orders row title', 'dsa' ), __( 'Orders', 'dsa' ) ),
					'ordersText'        => self::field( __( 'Orders row text', 'dsa' ), __( 'Track, return, buy again', 'dsa' ), 160 ),
					'downloadsTitle'    => self::field( __( 'Downloads row title', 'dsa' ), __( 'Downloads', 'dsa' ) ),
					'downloadsText'     => self::field( __( 'Downloads row text', 'dsa' ), __( 'Access your digital purchases', 'dsa' ), 160 ),
					'notificationsTitle' => self::field( __( 'Notifications row title', 'dsa' ), __( 'Notification preferences', 'dsa' ) ),
					'notificationsText' => self::field( __( 'Notifications row text', 'dsa' ), __( 'Choose what reaches you', 'dsa' ), 160 ),
					'addressesTitle'    => self::field( __( 'Addresses row title', 'dsa' ), __( 'Addresses', 'dsa' ) ),
					'addressesText'     => self::field( __( 'Addresses row text', 'dsa' ), __( 'Delivery and billing details', 'dsa' ), 160 ),
					'passwordTitle'     => self::field( __( 'Password row title', 'dsa' ), __( 'Password', 'dsa' ) ),
					'passwordText'      => self::field( __( 'Password row text', 'dsa' ), __( 'Send a secure reset email', 'dsa' ), 160 ),
					'signOutLabel'      => self::field( __( 'Sign out label', 'dsa' ), __( 'Sign out', 'dsa' ) ),
					'recentOrdersTitle' => self::field( __( 'Recent orders title', 'dsa' ), __( 'Recent orders', 'dsa' ) ),
				],
			],
			'cart'          => [
				'label'       => __( 'Cart / Bag', 'dsa' ),
				'description' => __( 'WooCommerce cart surface labels. Products, totals, checkout URLs, quantities, and FBT product authority remain live runtime data.', 'dsa' ),
				'fields'      => [
					'label'              => self::field( __( 'Dock/module label', 'dsa' ), __( 'Cart / Bag', 'dsa' ) ),
					'eyebrow'            => self::field( __( 'Eyebrow', 'dsa' ), __( 'Cart', 'dsa' ) ),
					'title'              => self::field( __( 'Title', 'dsa' ), __( 'Your cart / Your tea-time bag', 'dsa' ) ),
					'emptyTitle'         => self::field( __( 'Empty title', 'dsa' ), __( 'Your cart is empty', 'dsa' ) ),
					'emptyText'          => self::field( __( 'Empty text', 'dsa' ), __( 'Add products to continue.', 'dsa' ), 220 ),
					'fbtTitle'           => self::field( __( 'FBT rail title', 'dsa' ), __( 'Frequently Bought Together / Pairs well with', 'dsa' ) ),
					'checkoutLabel'      => self::field( __( 'Checkout label', 'dsa' ), __( 'Checkout', 'dsa' ) ),
					'checkoutEmptyLabel' => self::field( __( 'Empty checkout meta', 'dsa' ), __( 'Empty', 'dsa' ) ),
				],
			],
			'checkout'      => [
				'label'       => __( 'Checkout', 'dsa' ),
				'description' => __( 'Checkout correction surface labels. Payment/order placement remains WooCommerce-owned.', 'dsa' ),
				'fields'      => [
					'label'               => self::field( __( 'Dock/module label', 'dsa' ), __( 'Checkout', 'dsa' ) ),
					'title'               => self::field( __( 'Title', 'dsa' ), __( 'Checkout details', 'dsa' ) ),
					'loadingText'         => self::field( __( 'Loading text', 'dsa' ), __( 'Preparing checkout...', 'dsa' ) ),
					'unavailableText'     => self::field( __( 'Unavailable text', 'dsa' ), __( 'WooCommerce checkout is not available.', 'dsa' ), 220 ),
					'continueLabel'       => self::field( __( 'Continue label', 'dsa' ), __( 'Continue to Place order', 'dsa' ) ),
					'returnLabel'         => self::field( __( 'Return label', 'dsa' ), __( 'Return to Place order', 'dsa' ) ),
					'shippingToggleLabel' => self::field( __( 'Shipping toggle label', 'dsa' ), __( 'Use a different shipping address', 'dsa' ) ),
					'accountToggleLabel'  => self::field( __( 'Account toggle label', 'dsa' ), __( 'Create an account', 'dsa' ) ),
				],
			],
			'search'        => [
				'label'       => __( 'Search', 'dsa' ),
				'description' => __( 'Search surface labels. Indexing, filters, results, and add-to-cart/search behavior remain Kiwe-owned.', 'dsa' ),
				'fields'      => [
					'label'       => self::field( __( 'Dock/module label', 'dsa' ), __( 'Search', 'dsa' ) ),
					'eyebrow'     => self::field( __( 'Eyebrow', 'dsa' ), __( 'Search', 'dsa' ) ),
					'title'       => self::field( __( 'Title', 'dsa' ), __( 'Find what you need.', 'dsa' ) ),
					'intro'       => self::field( __( 'Intro/status copy', 'dsa' ), __( 'Search products, posts, authors, and categories.', 'dsa' ), 220 ),
					'placeholder' => self::field( __( 'Input placeholder', 'dsa' ), __( 'Search products and posts', 'dsa' ) ),
				],
			],
			'menu'          => [
				'label'       => __( 'Menu', 'dsa' ),
				'description' => __( 'Menu surface copy. Menu items and page-context collection remain Kiwe/Menu settings and page semantics.', 'dsa' ),
				'fields'      => [
					'label'          => self::field( __( 'Dock/module label', 'dsa' ), __( 'Menu', 'dsa' ) ),
					'eyebrow'        => self::field( __( 'Eyebrow', 'dsa' ), __( 'Menu', 'dsa' ) ),
					'title'          => self::field( __( 'Title', 'dsa' ), __( 'Move around faster.', 'dsa' ) ),
					'intro'          => self::field( __( 'Intro/body copy', 'dsa' ), __( 'Site links and this page guide.', 'dsa' ), 220 ),
					'contextTitle'   => self::field( __( 'Context title', 'dsa' ), __( 'On this page', 'dsa' ) ),
					'dashboardLabel' => self::field( __( 'Dashboard link label', 'dsa' ), __( 'Dashboard', 'dsa' ) ),
				],
			],
			'saved'         => [
				'label'       => __( 'Saved', 'dsa' ),
				'description' => __( 'Saved surface labels. Wishlist/bookmark state remains Kiwe/WooCommerce-owned.', 'dsa' ),
				'fields'      => [
					'label'                 => self::field( __( 'Dock/module label', 'dsa' ), __( 'Saved', 'dsa' ) ),
					'eyebrow'               => self::field( __( 'Eyebrow', 'dsa' ), __( 'Saved', 'dsa' ) ),
					'title'                 => self::field( __( 'Title', 'dsa' ), __( 'Your saved shelf.', 'dsa' ) ),
					'intro'                 => self::field( __( 'Intro/body copy', 'dsa' ), __( 'Wishlist products and page bookmarks stay close.', 'dsa' ), 220 ),
					'emptyTitle'            => self::field( __( 'Empty title', 'dsa' ), __( 'Nothing saved yet.', 'dsa' ) ),
					'emptyText'             => self::field( __( 'Empty text', 'dsa' ), __( 'Tap the bookmark or heart on products and pages to build this shelf.', 'dsa' ), 220 ),
					'wishlistLabel'         => self::field( __( 'Wishlist group label', 'dsa' ), __( 'Wishlist', 'dsa' ) ),
					'bookmarksLabel'        => self::field( __( 'Bookmarks group label', 'dsa' ), __( 'Bookmarks', 'dsa' ) ),
					'summaryWishlistLabel'  => self::field( __( 'Summary wishlist label', 'dsa' ), __( 'Wishlist', 'dsa' ) ),
					'summaryBookmarksLabel' => self::field( __( 'Summary bookmarks label', 'dsa' ), __( 'Bookmarks', 'dsa' ) ),
					'summaryTotalLabel'     => self::field( __( 'Summary total label', 'dsa' ), __( 'Total saved', 'dsa' ) ),
				],
			],
			'links'         => [
				'label'       => __( 'Links', 'dsa' ),
				'description' => __( 'Links hub labels. Social URLs, site logo, optional score, and commerce routes remain Kiwe-owned.', 'dsa' ),
				'fields'      => [
					'label'     => self::field( __( 'Dock/module label', 'dsa' ), __( 'Links', 'dsa' ) ),
					'eyebrow'   => self::field( __( 'Eyebrow', 'dsa' ), __( 'Links', 'dsa' ) ),
					'title'     => self::field( __( 'Title fallback', 'dsa' ), __( 'Store links', 'dsa' ) ),
					'intro'     => self::field( __( 'Intro/body copy', 'dsa' ), __( 'Store links, social proof, trust, and recent content in one Appsite surface.', 'dsa' ), 220 ),
					'shopLabel' => self::field( __( 'Shop action label', 'dsa' ), __( 'Shop', 'dsa' ) ),
					'shopMeta'  => self::field( __( 'Shop action meta', 'dsa' ), __( 'Open store', 'dsa' ) ),
					'cartLabel' => self::field( __( 'Cart action label', 'dsa' ), __( 'Cart', 'dsa' ) ),
					'cartMeta'  => self::field( __( 'Cart action meta', 'dsa' ), __( 'Open cart', 'dsa' ) ),
				],
			],
			'notifications' => [
				'label'       => __( 'Notifications', 'dsa' ),
				'description' => __( 'Notification preference labels. Channels, topics, opt-in state, and delivery remain Kiwe-owned.', 'dsa' ),
				'fields'      => [
					'label'            => self::field( __( 'Dock/module label', 'dsa' ), __( 'Notifications', 'dsa' ) ),
					'eyebrow'          => self::field( __( 'Eyebrow', 'dsa' ), __( 'Notifications', 'dsa' ) ),
					'title'            => self::field( __( 'Title', 'dsa' ), __( 'Choose what reaches you.', 'dsa' ) ),
					'intro'            => self::field( __( 'Intro/body copy', 'dsa' ), __( 'Useful moments only. Every choice stays optional and editable.', 'dsa' ), 220 ),
					'topicsLegend'     => self::field( __( 'Topics legend', 'dsa' ), __( 'What matters to you?', 'dsa' ) ),
					'channelsLegend'   => self::field( __( 'Channels legend', 'dsa' ), __( 'How should we reach you?', 'dsa' ) ),
					'appText'          => self::field( __( 'App notification text', 'dsa' ), __( 'App notifications use the same no-store installation journey from Home.', 'dsa' ), 220 ),
					'submitLabel'      => self::field( __( 'Submit label', 'dsa' ), __( 'Save my choices', 'dsa' ) ),
					'emailPlaceholder' => self::field( __( 'Email placeholder', 'dsa' ), __( 'Email for email updates', 'dsa' ) ),
					'phonePlaceholder' => self::field( __( 'Phone placeholder', 'dsa' ), __( 'Phone for WhatsApp or SMS', 'dsa' ) ),
				],
			],
			'ios-install'   => [
				'label'       => __( 'iOS Install', 'dsa' ),
				'description' => __( 'iOS install-guide labels. Browser install capability remains core-owned.', 'dsa' ),
				'fields'      => [
					'label'          => self::field( __( 'Dock/module label', 'dsa' ), __( 'iPhone and iPad App', 'dsa' ) ),
					'eyebrow'        => self::field( __( 'Eyebrow', 'dsa' ), __( 'For iPhone & iPad', 'dsa' ) ),
					'title'          => self::field( __( 'Title', 'dsa' ), __( 'Add the Appsite.', 'dsa' ) ),
					'intro'          => self::field( __( 'Intro/body copy', 'dsa' ), __( 'Give this Appsite its own Home Screen place, then open it once to finish notifications.', 'dsa' ), 220 ),
					'stepOneTitle'   => self::field( __( 'Step 1 title', 'dsa' ), __( 'Open Safari Share', 'dsa' ) ),
					'stepOneText'    => self::field( __( 'Step 1 text', 'dsa' ), __( 'Use the Share button in Safari.', 'dsa' ), 160 ),
					'stepTwoTitle'   => self::field( __( 'Step 2 title', 'dsa' ), __( 'Add to Home Screen', 'dsa' ) ),
					'stepTwoText'    => self::field( __( 'Step 2 text', 'dsa' ), __( 'Choose Add to Home Screen from the share sheet.', 'dsa' ), 160 ),
					'stepThreeTitle' => self::field( __( 'Step 3 title', 'dsa' ), __( 'Tap Add, then open the app', 'dsa' ) ),
					'stepThreeText'  => self::field( __( 'Step 3 text', 'dsa' ), __( 'Your notification choices are already waiting there.', 'dsa' ), 160 ),
					'doneLabel'      => self::field( __( 'Done label', 'dsa' ), __( 'I added it', 'dsa' ) ),
				],
			],
			'games'         => [
				'label'       => __( 'Games', 'dsa' ),
				'description' => __( 'Game surface labels. Canvas, scoring, rewards, coupons, and game lifecycle remain Kiwe-owned.', 'dsa' ),
				'fields'      => [
					'label'           => self::field( __( 'Dock/module label', 'dsa' ), __( 'Game', 'dsa' ) ),
					'eyebrow'         => self::field( __( 'Eyebrow', 'dsa' ), __( 'Game', 'dsa' ) ),
					'startTitle'      => self::field( __( 'Start title', 'dsa' ), __( 'Are You Game! for discount??', 'dsa' ) ),
					'startText'       => self::field( __( 'Desktop start text', 'dsa' ), __( 'Press any key to start', 'dsa' ) ),
					'mobileStartText' => self::field( __( 'Mobile start text', 'dsa' ), __( 'Touch to start', 'dsa' ) ),
					'chooseText'      => self::field( __( 'Choose-game text', 'dsa' ), __( 'Choose a game', 'dsa' ) ),
					'scoreLabel'      => self::field( __( 'Score label', 'dsa' ), __( 'Score', 'dsa' ) ),
					'bestLabel'       => self::field( __( 'Best label', 'dsa' ), __( 'Best', 'dsa' ) ),
				],
			],
			'ai'            => [
				'label'       => __( 'AI Assistant', 'dsa' ),
				'description' => __( 'AI surface labels. Insight generation, actions, memory, tray state, and chat authority remain Kiwe-owned.', 'dsa' ),
				'fields'      => [
					'label'           => self::field( __( 'Dock/module label', 'dsa' ), __( 'AI Assistant', 'dsa' ) ),
					'eyebrow'         => self::field( __( 'Eyebrow', 'dsa' ), __( 'AI Assistant', 'dsa' ) ),
					'title'           => self::field( __( 'Title', 'dsa' ), __( 'Useful things, at the right moment.', 'dsa' ) ),
					'intro'           => self::field( __( 'Intro/body copy', 'dsa' ), __( 'DSA keeps the decisions deterministic; this surface only arranges the useful signals.', 'dsa' ), 220 ),
					'emptyTitle'      => self::field( __( 'Empty title', 'dsa' ), __( 'You are all caught up.', 'dsa' ) ),
					'emptyText'       => self::field( __( 'Empty text', 'dsa' ), __( 'New account, cart, notification, and safety insights will collect here.', 'dsa' ), 220 ),
					'chatPlaceholder' => self::field( __( 'Chat placeholder', 'dsa' ), __( 'Chat with AI', 'dsa' ) ),
				],
			],
		];
	}

	public static function sanitize( array $screens ): array {
		$out = [];

		foreach ( self::screens() as $screen => $definition ) {
			if ( empty( $screens[ $screen ] ) || ! is_array( $screens[ $screen ] ) ) {
				continue;
			}

			$screen_out = [];
			foreach ( $definition['fields'] as $key => $field ) {
				if ( ! array_key_exists( $key, $screens[ $screen ] ) ) {
					continue;
				}

				$value = sanitize_text_field( (string) $screens[ $screen ][ $key ] );
				if ( '' === $value ) {
					continue;
				}

				$screen_out[ $key ] = substr( $value, 0, max( 1, (int) ( $field['max'] ?? 120 ) ) );
			}

			if ( [] !== $screen_out ) {
				$out[ $screen ] = $screen_out;
			}
		}

		return $out;
	}

	private static function field( string $label, string $placeholder, int $max = 120 ): array {
		return [
			'label'       => $label,
			'placeholder' => $placeholder,
			'max'         => $max,
		];
	}
}
