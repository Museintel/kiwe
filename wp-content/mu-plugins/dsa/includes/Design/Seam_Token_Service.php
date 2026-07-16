<?php

namespace DSA\Design;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seam_Token_Service {
	public static function universal_tokens(): array {
		$tokens = [
			self::token( 'color-brand', '#d6006f', 'color', 'Primary brand color for CTAs and active states.', '--color-brand' ),
			self::token( 'color-accent', '#24c6a1', 'color', 'Secondary accent for highlights and supportive actions.', '--color-accent' ),
			self::token( 'color-hero', 'rgba(20,24,34,0.18)', 'color', 'Quiet oversized hero and transition text.', '--color-hero' ),
			self::token( 'color-neutral', '#64717d', 'color', 'Neutral UI tone for quiet chrome and borders.', '--color-neutral' ),
			self::token( 'color-surface', '#f6f8f7', 'color', 'Base page and section surface.', '--color-surface' ),
			self::token( 'color-surface-raised', 'color-mix(in oklch, var(--kiwe-color-surface) 88%, white)', 'color', 'Raised cards and panels.', '--color-surface-raised' ),
			self::token( 'color-surface-sunken', 'color-mix(in oklch, var(--kiwe-color-surface) 82%, black)', 'color', 'Sunken controls and input beds.', '--color-surface-sunken' ),
			self::token( 'color-surface-overlay', 'rgba(246,248,247,0.72)', 'color', 'Soft overlay and frosted panels.', '--color-surface-overlay' ),
			self::token( 'color-text', '#1f2933', 'color', 'Primary readable text.', '--color-text' ),
			self::token( 'color-text-muted', '#64717d', 'color', 'Secondary text, metadata, and helper copy.', '--color-text-muted' ),
			self::token( 'color-text-disabled', '#9aa4af', 'color', 'Disabled or unavailable text.', '--color-text-disabled' ),
			self::token( 'color-text-inverse', '#ffffff', 'color', 'Text on dark or saturated surfaces.', '--color-text-inverse' ),
			self::token( 'color-border', 'rgba(31,41,51,0.16)', 'color', 'Default divider and card border.', '--color-border' ),
			self::token( 'color-shadow', 'rgba(31,41,51,0.18)', 'color', 'Base shadow color.', '--color-shadow' ),
			self::token( 'color-success', '#12a66a', 'color', 'Positive and in-stock state.', '--color-success' ),
			self::token( 'color-warning', '#c98600', 'color', 'Caution and low-stock state.', '--color-warning' ),
			self::token( 'color-danger', '#d83a52', 'color', 'Error, destructive, or out-of-stock state.', '--color-danger' ),
			self::token( 'color-info', '#2d7ff9', 'color', 'Informational state.', '--color-info' ),
			self::token( 'type-micro', 'clamp(10px, 0.08vw + 9.7px, 10.8px)', 'type', 'Timestamps, legal copy, and dense metadata.', '--type-micro' ),
			self::token( 'type-caption', 'clamp(11px, 0.12vw + 10.6px, 12px)', 'type', 'Tiny labels, badges, and metadata.', '--type-caption' ),
			self::token( 'type-sm', 'clamp(12.8px, 0.14vw + 12.4px, 14px)', 'type', 'Compact UI copy and dense cards.', '--type-sm' ),
			self::token( 'type-body', 'clamp(15px, 0.18vw + 14.4px, 16px)', 'type', 'Default paragraph and control text.', '--type-body' ),
			self::token( 'type-lead', 'clamp(18px, 0.45vw + 16.6px, 23px)', 'type', 'Intro copy and prominent descriptions.', '--type-lead' ),
			self::token( 'type-h6', 'clamp(18px, 0.45vw + 16.6px, 23px)', 'type', 'Small headings.', '--type-h6' ),
			self::token( 'type-h5', 'clamp(20px, 0.75vw + 17.6px, 28px)', 'type', 'Card and panel headings.', '--type-h5' ),
			self::token( 'type-h4', 'clamp(24px, 1.1vw + 20.5px, 36px)', 'type', 'Section subheads.', '--type-h4' ),
			self::token( 'type-h3', 'clamp(30px, 1.8vw + 24.2px, 50px)', 'type', 'Strong section headings.', '--type-h3' ),
			self::token( 'type-h2', 'clamp(38px, 3vw + 28.4px, 72px)', 'type', 'Page headings.', '--type-h2' ),
			self::token( 'type-h1', 'clamp(52px, 5vw + 36px, 108px)', 'type', 'Hero-scale headings.', '--type-h1' ),
			self::token( 'font-display', 'Inter, system-ui, sans-serif', 'font', 'Display and heading font stack.', '--font-display' ),
			self::token( 'font-body', 'Inter, system-ui, sans-serif', 'font', 'Body and UI font stack.', '--font-body' ),
			self::token( 'font-mono', 'ui-monospace, Menlo, monospace', 'font', 'Code and data font stack.', '--font-mono' ),
			self::token( 'space-xxs', 'clamp(4px, 0.36vw + 2.86px, 8px)', 'space', 'Minimum inline icon and label gap.', '--space-xxs' ),
			self::token( 'space-xs', 'clamp(6px, 0.54vw + 4.29px, 12px)', 'space', 'Small related-item gap.', '--space-xs' ),
			self::token( 'space-sm', 'clamp(9px, 0.8vw + 6.43px, 18px)', 'space', 'Standard component padding.', '--space-sm' ),
			self::token( 'space-md', 'clamp(13.5px, 1.21vw + 9.64px, 27px)', 'space', 'Standard layout gap.', '--space-md' ),
			self::token( 'space-lg', 'clamp(20.25px, 1.81vw + 14.46px, 40.5px)', 'space', 'Section internal padding.', '--space-lg' ),
			self::token( 'space-xl', 'clamp(30px, 2.7vw + 21.4px, 60px)', 'space', 'Large section rhythm.', '--space-xl' ),
			self::token( 'radius-xs', '2.5px', 'radius', 'Tight chips and tiny controls.', '--radius-xs' ),
			self::token( 'radius-sm', '5px', 'radius', 'Compact buttons and inputs.', '--radius-sm' ),
			self::token( 'radius-md', '10px', 'radius', 'Default cards.', '--radius-md' ),
			self::token( 'radius-lg', '15px', 'radius', 'Large cards and drawers.', '--radius-lg' ),
			self::token( 'radius-xl', '20px', 'radius', 'Sheet-style panels.', '--radius-xl' ),
			self::token( 'radius-full', '9999px', 'radius', 'Pills, avatars, and circular controls.', '--radius-full' ),
			self::token( 'density', '1', 'scene', 'Inherited scene density baseline.', '--density' ),
			self::token( 'scene-dramatic', '1.4', 'scene', 'Hero sections and expressive moments.', 'data-scene=dramatic' ),
			self::token( 'scene-elevated', '1.15', 'scene', 'Feature and highlight sections.', 'data-scene=elevated' ),
			self::token( 'scene-standard', '1', 'scene', 'Default page sections.', 'data-scene=standard' ),
			self::token( 'scene-compact', '0.85', 'scene', 'Sidebars, footers, dense panels.', 'data-scene=compact' ),
			self::token( 'scene-micro', '0.7', 'scene', 'Metadata and fine print.', 'data-scene=micro' ),
			self::token( 'motion-duration-instant', '50ms', 'motion', 'Immediate feedback.', '--motion-duration-instant' ),
			self::token( 'motion-duration-fast', '150ms', 'motion', 'Hover and micro-interactions.', '--motion-duration-fast' ),
			self::token( 'motion-duration-normal', '300ms', 'motion', 'Default UI transitions.', '--motion-duration-normal' ),
			self::token( 'motion-duration-slow', '600ms', 'motion', 'Major entrances and exits.', '--motion-duration-slow' ),
			self::token( 'motion-duration-crawl', '1000ms', 'motion', 'Deliberate editorial motion.', '--motion-duration-crawl' ),
			self::token( 'motion-easing-standard', 'cubic-bezier(0.4, 0, 0.2, 1)', 'motion', 'General easing curve.', '--motion-easing-standard' ),
			self::token( 'motion-easing-enter', 'cubic-bezier(0, 0, 0.2, 1)', 'motion', 'Entrance easing curve.', '--motion-easing-enter' ),
			self::token( 'motion-easing-exit', 'cubic-bezier(0.4, 0, 1, 1)', 'motion', 'Exit easing curve.', '--motion-easing-exit' ),
			self::token( 'motion-easing-spring', 'cubic-bezier(0.34, 1.56, 0.64, 1)', 'motion', 'Elastic feedback curve.', '--motion-easing-spring' ),
			self::token( 'glass-blur', '10px', 'component', 'Default frosted-glass blur shared by Kiwe Surface components.', '--glass-blur' ),
			self::token( 'control-size-sm', '30px', 'component', 'Compressed interactive control size for constrained viewports.', '--control-size-sm' ),
			self::token( 'control-size', '48px', 'component', 'Preferred interactive control and dock item size.', '--control-size' ),
			self::token( 'control-size-ai-sm', '40px', 'component', 'Compressed AI launcher size.', '--control-size-ai-sm' ),
			self::token( 'control-size-ai', '54px', 'component', 'Preferred AI launcher size.', '--control-size-ai' ),
			self::token( 'icon-size-sm', '18px', 'component', 'Compressed interface icon size.', '--icon-size-sm' ),
			self::token( 'icon-size', '23px', 'component', 'Preferred interface icon size.', '--icon-size' ),
			self::token( 'badge-size-sm', '16px', 'component', 'Compressed notification badge size.', '--badge-size-sm' ),
			self::token( 'badge-size', '18px', 'component', 'Preferred notification badge size.', '--badge-size' ),
			self::token( 'dock-gap-min', '1px', 'component', 'Minimum gap between dock controls.', '--dock-gap-min' ),
			self::token( 'dock-gap', '2px', 'component', 'Preferred gap between dock controls.', '--dock-gap' ),
			self::token( 'dock-padding-min', '6px', 'component', 'Minimum dock capsule padding.', '--dock-padding-min' ),
			self::token( 'dock-padding', '8px', 'component', 'Preferred dock capsule padding.', '--dock-padding' ),
			self::token( 'dock-border', '1px', 'component', 'Dock capsule border width.', '--dock-border' ),
			self::token( 'dock-cluster-gap-min', '6px', 'component', 'Minimum gap between the main dock and AI launcher.', '--dock-cluster-gap-min' ),
			self::token( 'dock-cluster-gap', '14px', 'component', 'Preferred gap between the main dock and AI launcher.', '--dock-cluster-gap' ),
			self::token( 'viewport-gutter', '12px', 'layout', 'Minimum safe clearance from viewport edges.', '--viewport-gutter' ),
			self::token( 'content-width', '1120px', 'layout', 'Default centered page content width.', '--content-width' ),
			self::token( 'content-width-narrow', '760px', 'layout', 'Narrow article, form, and reading column width.', '--content-width-narrow' ),
			self::token( 'grid-min-col', '240px', 'layout', 'Minimum responsive grid column width.', '--grid-min-col' ),
			self::token( 'section-gap', 'var(--kiwe-space-xl)', 'layout', 'Gap between adjacent page sections.', '--section-gap' ),
			self::token( 'stack-gap', 'var(--kiwe-space-md)', 'layout', 'Vertical stack rhythm for related blocks.', '--stack-gap' ),
			self::token( 'grid-gap', 'var(--kiwe-space-md)', 'layout', 'Default gap between grid cells.', '--grid-gap' ),
			self::token( 'z-base', '0', 'z-index', 'Base document layer.', '--z-base' ),
			self::token( 'z-raised', '10', 'z-index', 'Raised cards, dropdown children, and hover affordances.', '--z-raised' ),
			self::token( 'z-sticky', '100', 'z-index', 'Sticky headers and persistent page chrome.', '--z-sticky' ),
			self::token( 'z-overlay', '1000', 'z-index', 'Page overlays below the Kiwe dock and Surface.', '--z-overlay' ),
			self::token( 'z-dock', '9000', 'z-index', 'Floating dock layer.', '--z-dock' ),
			self::token( 'z-drawer', '9100', 'z-index', 'Drawers and sheets.', '--z-drawer' ),
			self::token( 'z-modal', '9200', 'z-index', 'Modal panels.', '--z-modal' ),
			self::token( 'z-toast', '9300', 'z-index', 'Toasts and AI notification cards.', '--z-toast' ),
			self::token( 'cluster-gap', 'var(--kiwe-space-sm)', 'layout', 'Inline cluster gap.', '--cluster-gap' ),
		];

		return array_values( $tokens );
	}

	public static function css_variables( array $overrides = [] ): array {
		$variables = [];

		foreach ( self::tokens_with_overrides( $overrides ) as $token ) {
			$name = (string) ( $token['name'] ?? '' );
			$css_var = (string) ( $token['cssVar'] ?? '' );
			$value = (string) ( $token['value'] ?? '' );

			if ( '' !== $css_var && '' !== $value ) {
				$variables[ $css_var ] = $value;
			}
		}

		return $variables;
	}

	public static function seam_alias_stylesheet( array $overrides = [] ): string {
		$lines = [];

		foreach ( self::tokens_with_overrides( $overrides ) as $token ) {
			$alias = self::clean_css_custom_property( (string) ( $token['seamAlias'] ?? '' ) );
			$value = self::clean_value( (string) ( $token['value'] ?? '' ) );

			if ( '' === $alias || '' === $value ) {
				continue;
			}

			$lines[] = "\t" . $alias . ': ' . $value . ';';
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return ":root {\n" . implode( "\n", $lines ) . "\n}\n";
	}

	public static function tokens_with_overrides( array $overrides = [] ): array {
		$tokens = self::universal_tokens();

		foreach ( $tokens as &$token ) {
			$name = (string) ( $token['name'] ?? '' );
			if ( array_key_exists( $name, $overrides ) ) {
				$value = self::clean_value( $overrides[ $name ] );
				if ( '' !== $value ) {
					$token['value'] = $value;
				}
			}
		}
		unset( $token );

		return $tokens;
	}

	public static function export_for_bricks( ?array $tokens = null ): array {
		$tokens = self::normalize_tokens( $tokens ?: self::universal_tokens() );
		$categories = self::categories_for_tokens( $tokens );
		$category_by_key = [];

		foreach ( $categories as $category ) {
			$category_by_key[ $category['key'] ] = $category['id'];
		}

		$variables = [];

		foreach ( $tokens as $token ) {
			$name  = self::clean_name( $token['name'] ?? '' );
			$value = self::clean_value( $token['value'] ?? '' );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$type = (string) ( $token['type'] ?? self::classify( $name, $value ) );
			$key  = self::group_key( $type, $name );

			$variables[] = [
				'id'          => self::stable_id( 'kwv-' . $name ),
				'name'        => 'kiwe-' . $name,
				'value'       => $value,
				'category'    => $category_by_key[ $key ] ?? self::stable_id( 'kwc-' . $key ),
				'source'      => 'kiwe-universal',
				'description' => sanitize_text_field( (string) ( $token['description'] ?? '' ) ),
			];
		}

		$out_categories = [];

		foreach ( $categories as $category ) {
			unset( $category['key'] );
			$out_categories[] = $category;
		}

		$framework_classes = self::framework_classes_for_bricks();

		return [
			'variables'    => $variables,
			'categories'   => $out_categories,
			'colorPalette' => self::color_palette_for_bricks( $tokens ),
			'classes'      => $framework_classes['classes'],
			'classCategories' => $framework_classes['categories'],
			'classVocabulary' => self::class_vocabulary_groups(),
		];
	}

	public static function framework_classes_for_bricks(): array {
		$groups = self::class_vocabulary_groups();

		$categories = [];
		$classes    = [];

		foreach ( $groups as $key => $group ) {
			$category_id = self::stable_id( 'kw-seam-class-category-' . $key );
			$categories[] = [
				'id'     => $category_id,
				'name'   => 'Kiwe Seam ' . $group['label'],
				'source' => 'kiwe-seam',
			];

			foreach ( $group['classes'] as $name ) {
				$classes[] = [
					'id'       => self::stable_id( 'kw-seam-class-' . $name ),
					'name'     => $name,
					'category' => $category_id,
					'settings' => [],
					'source'   => 'kiwe-seam',
				];
			}
		}

		return [
			'categories' => $categories,
			'classes'    => $classes,
		];
	}

	public static function class_vocabulary_groups(): array {
		return [
			'flow' => [
				'label' => 'Flow',
				'classes' => [
					'seam-stack',
					'seam-row',
					'seam-cluster',
					'seam-inline',
					'seam-grid',
					'seam-dense',
					'seam-sidebar',
					'seam-center',
					'seam-spread',
					'seam-cover',
					'seam-frame',
					'seam-reel',
					'seam-horizontal-rail',
					'seam-vertical-rail',
				],
			],
			'role' => [
				'label' => 'Role Core',
				'classes' => [
					'seam-section',
					'seam-container',
					'seam-main',
					'seam-header',
					'seam-footer',
					'seam-aside',
					'seam-hero',
					'seam-lead',
					'seam-eyebrow',
					'seam-label',
					'seam-caption',
					'seam-hint',
					'seam-micro',
					'seam-card',
					'seam-panel',
					'seam-surface',
					'seam-media',
					'seam-avatar',
					'seam-button',
					'seam-link',
					'seam-icon',
					'seam-badge',
					'seam-chip',
					'seam-nav',
					'seam-actions',
					'seam-toolbar',
					'seam-menu',
					'seam-form',
					'seam-field',
					'seam-input',
					'seam-textarea',
					'seam-select',
					'seam-modal',
					'seam-dialog',
					'seam-drawer',
					'seam-toast',
					'seam-testimonial',
					'seam-price',
					'seam-progress',
					'seam-skeleton',
				],
			],
			'content' => [
				'label' => 'Content',
				'classes' => [
					'seam-article',
					'seam-post',
					'seam-story',
					'seam-feature',
					'seam-callout',
					'seam-note',
					'seam-stat',
					'seam-metric',
					'seam-counter',
					'seam-quote',
					'seam-citation',
					'seam-author',
					'seam-byline',
					'seam-date',
					'seam-kicker',
					'seam-title',
					'seam-subtitle',
					'seam-summary',
					'seam-body',
					'seam-divider',
				],
			],
			'commerce' => [
				'label' => 'Commerce',
				'classes' => [
					'seam-product',
					'seam-product-card',
					'seam-product-grid',
					'seam-product-rail',
					'seam-product-media',
					'seam-product-title',
					'seam-product-summary',
					'seam-product-price',
					'seam-product-rating',
					'seam-product-badge',
					'seam-product-options',
					'seam-add-to-cart',
					'seam-cart-line',
					'seam-cart-summary',
					'seam-checkout-cta',
					'seam-discount',
					'seam-trust-badge',
					'seam-shipping-note',
				],
			],
			'navigation' => [
				'label' => 'Navigation',
				'classes' => [
					'seam-breadcrumbs',
					'seam-breadcrumb',
					'seam-pagination',
					'seam-page-link',
					'seam-tabs',
					'seam-tab-list',
					'seam-tab',
					'seam-tab-panel',
					'seam-stepper',
					'seam-step',
					'seam-toc',
					'seam-toc-link',
					'seam-skip-link',
					'seam-search-bar',
					'seam-filter-bar',
					'seam-sort-control',
				],
			],
			'disclosure' => [
				'label' => 'Disclosure',
				'classes' => [
					'seam-accordion',
					'seam-accordion-item',
					'seam-accordion-trigger',
					'seam-accordion-panel',
					'seam-details',
					'seam-summary-control',
					'seam-dropdown',
					'seam-dropdown-trigger',
					'seam-dropdown-panel',
					'seam-popover',
					'seam-tooltip',
					'seam-faq',
					'seam-faq-item',
				],
			],
			'data' => [
				'label' => 'Data',
				'classes' => [
					'seam-table',
					'seam-table-wrap',
					'seam-table-head',
					'seam-table-body',
					'seam-table-row',
					'seam-table-cell',
					'seam-table-caption',
					'seam-data-grid',
					'seam-data-row',
					'seam-data-cell',
					'seam-chart',
					'seam-chart-legend',
					'seam-meter',
					'seam-kpi',
					'seam-comparison',
					'seam-comparison-row',
					'seam-timeline',
					'seam-timeline-item',
				],
			],
			'media' => [
				'label' => 'Media',
				'classes' => [
					'seam-image',
					'seam-picture',
					'seam-video',
					'seam-embed',
					'seam-figure',
					'seam-figcaption',
					'seam-gallery',
					'seam-gallery-item',
					'seam-carousel',
					'seam-slide',
					'seam-slider',
					'seam-thumbnail',
					'seam-logo',
					'seam-map',
					'seam-poster',
					'seam-scrim',
					'seam-overlay',
				],
			],
			'form' => [
				'label' => 'Form',
				'classes' => [
					'seam-form-row',
					'seam-form-group',
					'seam-control',
					'seam-control-label',
					'seam-control-help',
					'seam-control-error',
					'seam-checkbox',
					'seam-radio',
					'seam-switch',
					'seam-range',
					'seam-search-input',
					'seam-submit',
					'seam-reset',
					'seam-validation',
				],
			],
			'tone' => [
				'label' => 'Tone',
				'classes' => [ 'seam-tone-brand', 'seam-tone-accent', 'seam-tone-neutral', 'seam-tone-muted', 'seam-tone-success', 'seam-tone-warning', 'seam-tone-danger', 'seam-tone-info', 'seam-tone-surface', 'seam-tone-inverse' ],
			],
			'size' => [
				'label' => 'Size',
				'classes' => [ 'seam-size-2xs', 'seam-size-xs', 'seam-size-sm', 'seam-size-md', 'seam-size-lg', 'seam-size-xl', 'seam-size-2xl', 'seam-size-3xl' ],
			],
			'density' => [
				'label' => 'Density',
				'classes' => [ 'seam-density-compact', 'seam-density-comfortable', 'seam-density-spacious', 'seam-density-airy' ],
			],
			'emphasis' => [
				'label' => 'Emphasis',
				'classes' => [ 'seam-emphasis-quiet', 'seam-emphasis-normal', 'seam-emphasis-strong', 'seam-emphasis-featured', 'seam-emphasis-hero' ],
			],
			'scene' => [
				'label' => 'Scene',
				'classes' => [ 'seam-scene-dramatic', 'seam-scene-elevated', 'seam-scene-standard', 'seam-scene-compact', 'seam-scene-micro' ],
			],
			'state' => [
				'label' => 'State',
				'classes' => [ 'seam-is-loading', 'seam-is-disabled', 'seam-is-selected', 'seam-is-current', 'seam-is-error', 'seam-is-success', 'seam-is-warning', 'seam-is-collapsed', 'seam-is-featured', 'seam-is-hidden', 'seam-print-hidden' ],
			],
			'motion' => [
				'label' => 'Motion',
				'classes' => [ 'seam-fade-up', 'seam-scale-in', 'seam-view-fade-up' ],
			],
			'shape' => [
				'label' => 'Shape',
				'classes' => [ 'seam-shape-square', 'seam-shape-sharp', 'seam-shape-soft', 'seam-shape-rounded', 'seam-shape-pill', 'seam-shape-circle' ],
			],
			'placement' => [
				'label' => 'Placement',
				'classes' => [
					'seam-place-top',
					'seam-place-right',
					'seam-place-bottom',
					'seam-place-left',
					'seam-place-center',
					'seam-place-start',
					'seam-place-end',
					'seam-sticky-top',
					'seam-sticky-bottom',
					'seam-fixed-top',
					'seam-fixed-bottom',
				],
			],
			'aspect' => [
				'label' => 'Aspect',
				'classes' => [ 'seam-ratio-square', 'seam-ratio-video', 'seam-ratio-photo', 'seam-ratio-portrait', 'seam-ratio-landscape', 'seam-ratio-wide', 'seam-fit-cover', 'seam-fit-contain', 'seam-fit-fill' ],
			],
			'flow-control' => [
				'label' => 'Flow Control',
				'classes' => [
					'seam-flow-density-compact',
					'seam-flow-density-comfortable',
					'seam-flow-density-spacious',
					'seam-gap-none',
					'seam-gap-xxs',
					'seam-gap-xs',
					'seam-gap-sm',
					'seam-gap-md',
					'seam-gap-lg',
					'seam-gap-xl',
					'seam-align-start',
					'seam-align-center',
					'seam-align-end',
					'seam-align-stretch',
					'seam-justify-start',
					'seam-justify-center',
					'seam-justify-end',
					'seam-justify-between',
					'seam-justify-around',
					'seam-wrap',
					'seam-nowrap',
				],
			],
			'utility' => [
				'label' => 'Utility',
				'classes' => [
					'seam-narrow',
					'seam-wide',
					'seam-full',
					'seam-bleed',
					'seam-inset',
					'seam-visually-hidden',
					'seam-no-overflow',
					'seam-overflow-auto',
					'seam-overflow-x',
					'seam-overflow-y',
					'seam-clickable',
					'seam-unclickable',
					'seam-print-hidden',
				],
			],
		];
	}

	public static function color_palette_for_bricks( ?array $tokens = null ): array {
		$colors = [];

		foreach ( self::normalize_tokens( $tokens ?: self::universal_tokens() ) as $token ) {
			$name  = self::clean_name( $token['name'] ?? '' );
			$value = self::clean_value( $token['value'] ?? '' );
			$type  = (string) ( $token['type'] ?? self::classify( $name, $value ) );

			if ( '' === $name || '' === $value || ( 'color' !== $type && ! str_starts_with( $name, 'color-' ) ) ) {
				continue;
			}

			$color = [
				'id'     => self::stable_id( 'kw-color-' . $name ),
				'name'   => 'kiwe-' . $name,
				'source' => 'kiwe-universal',
			];

			if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
				$color['light'] = $value;
				$color['raw']   = 'var(--kiwe-' . $name . ')';
			} elseif ( preg_match( '/^rgba?\([^)]+\)$/i', $value ) || preg_match( '/^hsla?\([^)]+\)$/i', $value ) || preg_match( '/^oklch\([^)]+\)$/i', $value ) ) {
				$color['light'] = $value;
				$color['raw']   = $value;
			} else {
				$color['raw'] = $value;
			}

			$colors[] = $color;
		}

		if ( empty( $colors ) ) {
			return [];
		}

		return [
			[
				'id'     => self::stable_id( 'kw-palette-universal' ),
				'name'   => 'Kiwe Universal',
				'source' => 'kiwe-universal',
				'colors' => $colors,
			],
		];
	}

	public static function counts( ?array $tokens = null ): array {
		$counts = [];

		foreach ( self::normalize_tokens( $tokens ?: self::universal_tokens() ) as $token ) {
			$type = (string) ( $token['type'] ?? self::classify( $token['name'] ?? '', $token['value'] ?? '' ) );
			$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
		}

		ksort( $counts );

		return $counts;
	}

	public static function slider_value( array $token ): ?array {
		$value = (string) ( $token['value'] ?? '' );

		if ( ! preg_match( '/-?\d+(?:\.\d+)?/', $value, $match ) ) {
			return null;
		}

		$number = (float) $match[0];
		$type   = (string) ( $token['type'] ?? self::classify( $token['name'] ?? '', $value ) );
		$max    = 100.0;

		if ( 'motion' === $type ) {
			$max = 1200.0;
		} elseif ( 'z-index' === $type ) {
			$max = 10000.0;
		} elseif ( 'scene' === $type ) {
			$max = 2.0;
		} elseif ( in_array( $type, [ 'type', 'space', 'radius' ], true ) ) {
			$max = max( 64.0, $number * 2.0 );
		}

		return [
			'value' => $number,
			'max'   => $max,
		];
	}

	private static function token( string $name, string $value, string $type, string $description, string $seam_alias = '' ): array {
		$name = self::clean_name( $name );

		return [
			'id'          => self::stable_id( 'token-' . $name ),
			'name'        => $name,
			'cssVar'      => '--kiwe-' . $name,
			'value'       => self::clean_value( $value ),
			'type'        => $type,
			'source'      => 'kiwe.universal',
			'description' => $description,
			'seamAlias'   => $seam_alias,
			'writable'    => false,
		];
	}

	private static function normalize_tokens( array $tokens ): array {
		$out = [];

		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			$name  = self::clean_name( $token['name'] ?? '' );
			$value = self::clean_value( $token['value'] ?? '' );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$type = (string) ( $token['type'] ?? self::classify( $name, $value ) );

			$out[ $name ] = [
				'id'          => self::stable_id( 'token-' . $name ),
				'name'        => $name,
				'cssVar'      => '--kiwe-' . $name,
				'value'       => $value,
				'type'        => $type,
				'source'      => sanitize_text_field( (string) ( $token['source'] ?? 'kiwe.universal' ) ),
				'description' => sanitize_text_field( (string) ( $token['description'] ?? '' ) ),
				'seamAlias'   => sanitize_text_field( (string) ( $token['seamAlias'] ?? '' ) ),
				'writable'    => false,
			];
		}

		ksort( $out );

		return array_values( $out );
	}

	private static function categories_for_tokens( array $tokens ): array {
		$groups = [];

		foreach ( self::normalize_tokens( $tokens ) as $token ) {
			$name = self::clean_name( $token['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$type = (string) ( $token['type'] ?? self::classify( $name, $token['value'] ?? '' ) );
			$key  = self::group_key( $type, $name );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'key'         => $key,
					'id'          => self::stable_id( 'kwc-' . $key ),
					'name'        => 'Kiwe ' . self::group_label( $key ),
					'description' => 'Kiwe universal ' . strtolower( self::group_label( $key ) ) . ' tokens for Bricks page design.',
					'source'      => 'kiwe-universal',
					'scale'       => [],
				];
			}

			$groups[ $key ]['scale'][] = 'kiwe-' . $name;
		}

		foreach ( $groups as &$group ) {
			$group['scale'] = array_values( array_unique( $group['scale'] ) );
			sort( $group['scale'] );
		}
		unset( $group );

		ksort( $groups );

		return array_values( $groups );
	}

	private static function group_key( string $type, string $name ): string {
		if ( 'color' === $type || str_starts_with( $name, 'color-' ) ) {
			return 'color';
		}

		if ( str_starts_with( $name, 'font-' ) ) {
			return 'font';
		}

		if ( str_starts_with( $name, 'type-' ) ) {
			return 'type';
		}

		if ( str_starts_with( $name, 'space-' ) ) {
			return 'space';
		}

		if ( str_starts_with( $name, 'radius-' ) ) {
			return 'radius';
		}

		if ( str_starts_with( $name, 'scene-' ) || 'density' === $name ) {
			return 'scene';
		}

		if ( str_starts_with( $name, 'motion-' ) ) {
			return 'motion';
		}

		if ( str_starts_with( $name, 'content-' ) || str_starts_with( $name, 'grid-' ) || str_starts_with( $name, 'section-' ) || str_starts_with( $name, 'stack-' ) || str_starts_with( $name, 'cluster-' ) ) {
			return 'layout';
		}

		if ( str_starts_with( $name, 'z-' ) ) {
			return 'z';
		}

		return 'component' === $type ? 'component' : 'project';
	}

	private static function group_label( string $key ): string {
		$labels = [
			'color'     => 'Color',
			'font'      => 'Font',
			'type'      => 'Type',
			'space'     => 'Space',
			'radius'    => 'Radius',
			'scene'     => 'Scene',
			'motion'    => 'Motion',
			'layout'    => 'Layout',
			'z'         => 'Z',
			'component' => 'Component',
			'project'   => 'Project',
		];

		return $labels[ $key ] ?? ucfirst( $key );
	}

	private static function classify( string $name, string $value ): string {
		if ( preg_match( '/^(#|rgb|hsl|oklch|color-mix|var\(--kiwe-color-)/i', trim( $value ) ) || str_contains( $name, 'color' ) || str_contains( $name, 'brand' ) || str_contains( $name, 'accent' ) ) {
			return 'color';
		}

		if ( str_starts_with( $name, 'font-' ) ) {
			return 'font';
		}

		if ( str_starts_with( $name, 'type-' ) ) {
			return 'type';
		}

		if ( str_starts_with( $name, 'space-' ) ) {
			return 'space';
		}

		if ( str_starts_with( $name, 'radius-' ) ) {
			return 'radius';
		}

		if ( str_starts_with( $name, 'scene-' ) || 'density' === $name ) {
			return 'scene';
		}

		if ( str_starts_with( $name, 'motion-' ) ) {
			return 'motion';
		}

		if ( str_starts_with( $name, 'content-' ) || str_starts_with( $name, 'grid-' ) || str_starts_with( $name, 'section-' ) || str_starts_with( $name, 'stack-' ) || str_starts_with( $name, 'cluster-' ) ) {
			return 'layout';
		}

		if ( str_starts_with( $name, 'z-' ) ) {
			return 'z-index';
		}

		if ( preg_match( '/(padding|gap|width|height|col|size)/', $name ) ) {
			return 'component';
		}

		return 'project';
	}

	private static function clean_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = preg_replace( '/^--?kiwe-/', '', $name );
		$name = ltrim( $name, '-' );
		$name = preg_replace( '/[^a-z0-9_-]+/', '-', $name );
		$name = trim( (string) $name, '-' );

		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{1,80}$/', $name ) ) {
			return '';
		}

		return $name;
	}

	private static function clean_value( string $value ): string {
		$value = wp_strip_all_tags( trim( $value ) );
		$value = str_replace( [ ';', '{', '}', '<', '>' ], '', $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value );

		return trim( (string) $value );
	}

	private static function clean_css_custom_property( string $name ): string {
		$name = trim( $name );

		if ( ! preg_match( '/^--[a-z0-9][a-z0-9_-]{1,80}$/i', $name ) ) {
			return '';
		}

		return strtolower( $name );
	}

	private static function stable_id( string $seed ): string {
		return 'kw' . substr( md5( $seed ), 0, 8 );
	}
}
