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
					'notes' => 'Light is the implicit default. Kiwe AppShell dark mode remains html[data-kiwe-theme="dark"].',
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
