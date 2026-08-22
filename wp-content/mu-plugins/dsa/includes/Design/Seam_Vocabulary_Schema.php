<?php

namespace DSA\Design;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical Seam vocabulary contract.
 *
 * Seam is the page/section framework layer underneath Kiwe's AppShell. The
 * attributes are the canonical semantic vocabulary; classes are the adoption
 * bridge for Bricks, other builders, and AI-generated HTML.
 */
final class Seam_Vocabulary_Schema {
	public static function contract(): array {
		return [
			'schemaVersion' => 1,
			'namespace'     => 'kiwe.seam',
			'mode'          => 'read-only',
			'purpose'       => 'Platform-agnostic page framework vocabulary for roles, flows, scenes, tones, states, shape, and safe behavior hooks.',
			'attributes'    => [
				'role' => [
					'attribute' => 'data-role',
					'classPrefix' => 'seam-',
					'visualMode' => 'semantic-headless',
					'requiredForVisualMeaning' => false,
					'notes' => 'Roles identify meaning for CSS, JavaScript, audits, Bricks, and AI tools. They do not apply starter card/button/modal visuals, padding, background, border, shadow, or radius. Build appearance with neutral Seam primitives, universal Kiwe/Seam tokens, and site CSS.',
					'values' => [
						'section',
						'container',
						'hero',
						'lead',
						'eyebrow',
						'label',
						'caption',
						'hint',
						'micro',
						'card',
						'media',
						'avatar',
						'button',
						'badge',
						'chip',
						'nav',
						'actions',
						'form',
						'field',
						'input',
						'textarea',
						'select',
						'modal',
						'toast',
						'testimonial',
						'price',
						'progress',
						'skeleton',
						'footer',
						'aside',
					],
				],
				'flow' => [
					'attribute' => 'data-flow',
					'classPrefix' => 'seam-',
					'containerOnly' => true,
					'values' => [ 'stack', 'row', 'cluster', 'inline', 'grid', 'dense', 'sidebar', 'center', 'spread', 'cover', 'frame', 'reel', 'horizontal-rail', 'vertical-rail' ],
				],
				'tone' => [
					'attribute' => 'data-tone',
					'classPrefix' => 'seam-tone-',
					'values' => [ 'brand', 'accent', 'neutral', 'muted', 'success', 'warning', 'danger', 'info', 'surface', 'inverse' ],
				],
				'scene' => [
					'attribute' => 'data-scene',
					'classPrefix' => 'seam-scene-',
					'values' => [ 'dramatic', 'elevated', 'standard', 'compact', 'micro' ],
				],
				'state' => [
					'attribute' => 'data-state',
					'classPrefix' => 'seam-is-',
					'multiple' => true,
					'values' => [ 'loading', 'disabled', 'selected', 'current', 'error', 'success', 'warning', 'collapsed', 'featured', 'hidden', 'print-hidden' ],
				],
				'motion' => [
					'attribute' => 'data-motion',
					'classPrefix' => 'seam-',
					'values' => [ 'fade-up', 'scale-in', 'view-fade-up' ],
				],
				'shape' => [
					'attribute' => 'data-shape',
					'classPrefix' => 'seam-shape-',
					'rareOverride' => true,
					'values' => [ 'square', 'sharp', 'soft', 'rounded', 'pill', 'circle' ],
				],
				'flow-density' => [
					'attribute' => 'data-flow-density',
					'classPrefix' => 'seam-flow-density-',
					'containerOnly' => true,
					'values' => [ 'compact', 'comfortable', 'spacious' ],
				],
				'gap' => [
					'attribute' => 'data-gap',
					'values' => [ 'none', 'xxs', 'xs', 'sm', 'md', 'lg', 'xl' ],
				],
				'align' => [
					'attribute' => 'data-align',
					'values' => [ 'start', 'center', 'end', 'stretch' ],
				],
				'justify' => [
					'attribute' => 'data-justify',
					'values' => [ 'start', 'center', 'end', 'between', 'around' ],
				],
				'theme' => [
					'attribute' => 'data-theme',
					'values' => [ 'dark' ],
					'notes' => 'Light is the implicit default. Kiwe AppShell dark mode uses html[data-kiwe-theme="dark"] and bridges Bricks frontend dark mode through html[data-brx-theme="dark"].',
				],
			],
			'bodyClasses' => [
				'seam-heading' => [ 'balanced', 'quiet', 'bold' ],
				'seam-motion' => [ 'minimal', 'standard', 'expressive' ],
			],
			'behaviorAttributes' => [
				'data-seam-bind',
				'data-seam-bind-text',
				'data-seam-bind-html',
				'data-seam-bind-attr',
				'data-seam-template',
				'data-seam-each',
				'data-seam-if',
				'data-seam-unless',
				'data-seam-show',
				'data-seam-model',
			],
			'capabilityAttributes' => [
				'purpose' => 'Builder-neutral Appsite capability hooks. Public page authors, Bricks layouts, block themes, and AI-generated HTML may use these attributes to call Kiwe-owned runtime journeys without recreating Kiwe behavior.',
				'authorRule' => 'Use these attributes only on real interactive controls or semantic page regions. Preserve visible UI and add the smallest capability hook that matches the intent. Never create duplicate JavaScript authority for saved items, notifications, AppShell screens, theme switching, cart, checkout, auth, search, service workers, focus trapping, or browser history.',
				'groups' => [
					'appshellLaunchers' => [
						'status' => 'live',
						'authority' => 'kiwe-appshell',
						'attributes' => [
							[
								'attribute' => 'data-dsa-open-module',
								'values' => [ 'menu', 'search', 'profile', 'links', 'saved', 'cart', 'theme', 'ai', 'notifications', 'ios-install', 'games' ],
								'purpose' => 'Open a registered Kiwe DSA/AppShell screen from a page/header/Bricks/custom control. The value theme toggles light/dark mode instead of opening a sheet.',
								'example' => '<button data-dsa-open-module="cart" type="button">Cart</button>',
							],
						],
					],
					'savedItems' => [
						'status' => 'live',
						'authority' => 'kiwe-saved-items',
						'attributes' => [
							[
								'attribute' => 'data-kiwe-save',
								'values' => [ 'wishlist', 'bookmark', 'auto' ],
								'purpose' => 'Toggle the nearest item into Kiwe Saved. Use wishlist for WooCommerce product controls, bookmark for articles/pages, and auto only when the context is unambiguous.',
								'example' => '<button data-kiwe-save="wishlist" data-kiwe-save-id="{post_id}" data-kiwe-save-title="{post_title}" data-kiwe-save-url="{post_url}" type="button">Wishlist</button>',
							],
							[
								'attribute' => 'data-kiwe-save-id',
								'purpose' => 'Stable post/product ID, recommended in Bricks query loops.',
							],
							[
								'attribute' => 'data-kiwe-save-title',
								'purpose' => 'Optional saved-item title override. Kiwe otherwise reads the nearest card heading.',
							],
							[
								'attribute' => 'data-kiwe-save-url',
								'purpose' => 'Optional saved-item URL override. Kiwe otherwise reads the nearest card link or current URL.',
							],
							[
								'attribute' => 'data-kiwe-save-image',
								'purpose' => 'Optional saved-item image URL. Kiwe otherwise reads the nearest card image.',
							],
						],
					],
					'browserNotifications' => [
						'status' => 'live',
						'authority' => 'kiwe-notification-journey',
						'attributes' => [
							[
								'attribute' => 'data-kiwe-notifications',
								'purpose' => 'Start the browser-notification preference journey after an explicit visitor click. Kiwe never prompts during a protected flow.',
								'example' => '<button data-kiwe-notifications data-kiwe-notification-status-target="#notification-status" type="button">Turn on notifications</button>',
							],
							[
								'attribute' => 'data-kiwe-notification-status-target',
								'purpose' => 'Optional CSS selector for an on-page live-status element that receives the current notification-permission message.',
							],
							[
								'attribute' => 'data-kiwe-notification-topic',
								'purpose' => 'Optional topic hint for the notification preference screen. Use only real site topics exposed by Kiwe.',
							],
							[
								'attribute' => 'data-dsa-native-notification-request',
								'purpose' => 'Advanced direct browser-permission request for a real visitor-click control. Prefer data-kiwe-notifications unless the UI is explicitly asking for native permission now.',
							],
						],
					],
					'themeControls' => [
						'status' => 'live',
						'authority' => 'kiwe-theme-runtime',
						'attributes' => [
							[
								'attribute' => 'data-kiwe-theme-toggle',
								'purpose' => 'Toggle Kiwe/Bricks light-dark mode from a page/header/Bricks/custom control without adding the dock theme icon.',
								'example' => '<button data-kiwe-theme-toggle type="button">Toggle theme</button>',
							],
							[
								'attribute' => 'data-kiwe-theme-status-target',
								'purpose' => 'Optional selector for a status element that receives the current light/dark state label.',
							],
						],
					],
					'contactActions' => [
						'status' => 'live',
						'authority' => 'kiwe-design-context',
						'attributes' => [
							[
								'attribute' => 'data-kiwe-contact',
								'values' => [ 'phone', 'email', 'whatsapp', 'directions' ],
								'purpose' => 'Turn a real link or button into a contact action backed by the approved Kiwe Design Context. Kiwe resolves the destination; page markup must not duplicate phone, email, WhatsApp, or address values.',
								'example' => '<a data-kiwe-contact="whatsapp" data-kiwe-contact-message="Hello, I would like to know more." href="{kiwe_whatsapp_url}">Chat on WhatsApp</a>',
							],
							[
								'attribute' => 'data-kiwe-contact-message',
								'purpose' => 'Optional human-visible WhatsApp starter message. Kiwe URL-encodes it only after a visitor activates the control. Do not place private data or secrets here.',
							],
						],
						'nativeRecipes' => [
							'phone' => 'Prefer a Bricks-native link whose URL is {kiwe_store_phone_url}; retain data-kiwe-contact="phone" as semantic capability metadata.',
							'email' => 'Prefer a Bricks-native link whose URL is {kiwe_store_email_url}; retain data-kiwe-contact="email" as semantic capability metadata.',
							'whatsapp' => 'Prefer a Bricks-native link whose URL is {kiwe_whatsapp_url}; data-kiwe-contact-message may add a prefilled message at runtime.',
							'directions' => 'Prefer a Bricks-native link whose URL is {kiwe_directions_url}. For a Bricks Map element, compose its native address control from the kiwe_store_address_* dynamic tags instead of embedding a map iframe.',
						],
					],
					'socialProfiles' => [
						'status' => 'live',
						'authority' => 'kiwe-public-identity',
						'attributes' => [
							[
								'attribute' => 'data-kiwe-social',
								'values' => [ 'facebook', 'instagram', 'x', 'youtube', 'pinterest', 'linkedin' ],
								'purpose' => 'Open the selected public business profile from Kiwe Design Context/DSA Links. Use only on a real link or button; Kiwe ignores unknown networks and missing URLs.',
								'example' => '<a data-kiwe-social="instagram" href="{kiwe_instagram_url}">Instagram</a>',
							],
						],
					],
					'menuContext' => [
						'status' => 'live',
						'authority' => 'kiwe-menu-context',
						'attributes' => [
							[
								'attribute' => 'id + data-role="section" / .seam-section + aria-label|aria-labelledby|visible heading',
								'purpose' => 'Expose real page sections to Kiwe Menu context without hidden duplicate anchors. Kiwe prefers semantic sections, then falls back to admin-selected heading levels.',
								'example' => '<section id="heritage" class="seam-section" data-role="section" aria-labelledby="heritage-title"><h2 id="heritage-title">Heritage</h2></section>',
							],
						],
					],
					'dynamicAndBricksIntent' => [
						'status' => 'live-contract',
						'authority' => 'kiwe-bricks-dynamic-planning',
						'attributes' => [
							[
								'attribute' => 'data-kiwe-query-template',
								'purpose' => 'Marks a source region whose preview/sample cards should become a Bricks query loop or dynamic binding plan.',
								'example' => '<section data-kiwe-query-template="featured-products" data-role="section" class="seam-section seam-horizontal-rail"></section>',
							],
							[
								'attribute' => 'data-kiwe-binding',
								'purpose' => 'Marks a repeated sample card or field that belongs to a named Kiwe dynamic binding plan.',
							],
						],
					],
				],
				'candidateAttributes' => [
					[
						'attribute' => 'data-kiwe-share',
						'status' => 'candidate-not-live',
						'purpose' => 'Future Web Share / copy-link capability for products, posts, offers, and pages.',
					],
					[
						'attribute' => 'data-kiwe-compare',
						'status' => 'candidate-not-live',
						'purpose' => 'Future product/content comparison tray.',
					],
					[
						'attribute' => 'data-kiwe-recently-viewed',
						'status' => 'candidate-not-live',
						'purpose' => 'Future recently-viewed product/post rail and personalization signal.',
					],
					[
						'attribute' => 'data-kiwe-follow',
						'status' => 'candidate-not-live',
						'purpose' => 'Future author, brand, topic, category, or product-subscription follow intent.',
					],
					[
						'attribute' => 'data-kiwe-ai-context',
						'status' => 'candidate-not-live',
						'purpose' => 'Future safe context handoff to Kiwe AI from a selected section/card without exposing private data.',
					],
					[
						'attribute' => 'data-kiwe-feedback',
						'status' => 'candidate-not-live',
						'purpose' => 'Future lightweight rating, helpful/not helpful, or report-an-issue intent.',
					],
					[
						'attribute' => 'data-kiwe-offer',
						'status' => 'candidate-not-live',
						'purpose' => 'Future coupon, bundle, FBT, or personalization trigger that remains Woo/Kiwe-owned.',
					],
				],
			],
			'protectedShadowAttributes' => [
				'purpose' => 'Kiwe DSA uses data-seam-* shadow metadata on live AppShell panels so tools can understand the framework role without generic Seam CSS restyling those panels.',
				'attributes' => [
					'data-seam-root',
					'data-seam-role',
					'data-seam-flow',
					'data-seam-tone',
					'data-seam-scene',
					'data-seam-state',
					'data-seam-motion',
					'data-seam-shape',
					'data-seam-slot',
					'data-seam-surface-panel',
					'data-seam-authority',
				],
				'authorRule' => 'Theme authors and page builders should use public data-role/data-flow/classes for normal page markup. Do not add data-seam-* manually unless writing Kiwe runtime/core integration code.',
			],
			'appShellAdoption' => [
				'purpose' => 'Defines which public Seam classes Kiwe DSA itself may apply inside live sheets/screens. Normal WordPress pages and Bricks layouts may use the full public Seam vocabulary; this map is specifically for Kiwe AppShell internals where generic classes can accidentally alter geometry.',
				'levels' => [
					'public-adopted' => 'Runtime may add the public seam-* class to existing DSA markup because visual impact is low and reviewed.',
					'shadow-only'    => 'Runtime annotates the role with protected data-seam-* metadata only. This preserves AppShell isolation from site CSS even when core Seam role classes are headless.',
					'authority-only' => 'Runtime/state authority only. Themes and Seam must not create behavior for this concern.',
				],
				'publicAdopted' => [
					'eyebrow' => [
						'classes' => [ 'seam-eyebrow', 'seam-tone-brand' ],
						'reason'  => 'Text-only role; existing DSA selectors already own surface typography and color.',
					],
					'caption' => [
						'classes' => [ 'seam-caption', 'seam-tone-muted' ],
						'reason'  => 'Text-only helper; safe for muted supporting copy.',
					],
					'price' => [
						'classes' => [ 'seam-price', 'seam-tone-brand' ],
						'reason'  => 'Numeric/text-only helper; tabular numeric styling is compatible with DSA price landmarks.',
					],
				],
				'shadowOnly' => [
					'card' => 'AppShell keeps card semantics on protected data-seam-* metadata so site CSS cannot accidentally style live DSA internals.',
					'button' => 'AppShell keeps button semantics protected so site CSS cannot override DSA controls.',
					'input' => 'AppShell keeps field/input semantics protected because Profile, PhoneKey, checkout, and validation states are authority-owned.',
					'media' => 'AppShell keeps media semantics protected because DSA media geometry is screen-specific.',
					'badge' => 'AppShell keeps badge semantics protected so site CSS cannot alter notification/status badges.',
					'nav' => 'AppShell keeps nav semantics protected because dock and context navigation use Geometry Engine variables.',
					'actions' => 'AppShell keeps action-row semantics protected because action placement is panel/dock/context-owned.',
					'form' => 'AppShell keeps form semantics protected because Profile, PhoneKey, checkout, and notification forms are authority-owned.',
					'field' => 'AppShell keeps field semantics protected because validation, account, and checkout fields are authority-owned.',
					'modal' => 'AppShell keeps modal semantics protected because DSA sheet/screen geometry owns this surface.',
				],
				'authorityOnly' => [
					'cart',
					'checkout',
					'payment',
					'auth',
					'phonekey',
					'search-query',
					'bricks-query',
					'service-worker',
					'browser-history',
					'focus-trap',
				],
			],
			'authority' => [
				'presentation' => 'Seam roles, flows, tones, scenes, states, motion, shape, and classes.',
				'appShell'     => 'Kiwe DSA owns dock, sheets/screens, geometry, Search, Cart, Checkout, Profile, AI, PWA, PhoneKey, and lifecycle.',
				'commerce'     => 'WooCommerce and Kiwe commerce modules own cart and checkout mutation. Seam page code must not create another cart authority.',
				'builders'     => 'Bricks and other builders own page content authoring. Seam provides portable classes/attributes and tokens.',
			],
		];
	}
}
