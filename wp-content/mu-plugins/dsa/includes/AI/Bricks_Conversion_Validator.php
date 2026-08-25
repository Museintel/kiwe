<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bricks_Conversion_Validator {
	private const KNOWN_BRICKS_ELEMENTS = [
		'section',
		'container',
		'block',
		'div',
		'heading',
		'text-basic',
		'text',
		'text-link',
		'rich-text',
		'button',
		'icon',
		'image',
		'svg',
		'video',
		'audio',
		'divider',
		'form',
		'html',
		'code',
		'accordion',
		'accordion-nested',
		'tabs',
		'tabs-nested',
		'slider',
		'carousel',
		'post-title',
		'post-excerpt',
		'post-content',
		'post-featured-image',
		'posts',
		'query-results-summary',
		'filter-search',
		'product-title',
		'product-price',
		'product-add-to-cart',
		'product-short-description',
		'product-images',
		'product-upsells',
		'product-related',
		'woocommerce-breadcrumbs',
		'woocommerce-mini-cart',
	];

	private const SEMANTIC_HTML_ELEMENT_NAME_MISUSE = [
		'nav',
		'main',
		'article',
		'aside',
		'header',
		'footer',
		'figure',
		'figcaption',
		'ul',
		'ol',
		'li',
		'a',
		'span',
		'p',
	];

	private const BRICKS_IMPORT_METHODS = [
		'review-only',
		'bricks-clipboard-json',
		'bricks-admin-template-upload',
		'kiwe-staging-executor',
	];

	private const COMMON_DYNAMIC_TAGS = [
		'{post_title}',
		'{post_content}',
		'{post_excerpt}',
		'{post_date}',
		'{post_url}',
		'{post_id}',
		'{featured_image}',
		'{site_title}',
		'{site_tagline}',
		'{site_url}',
		'{term_name}',
		'{term_description}',
		'{woo_product_price}',
		'{woo_product_weight}',
		'{kiwe_site_logo}',
		'{kiwe_site_logo_inverse}',
		'{kiwe_store_phone_url}',
		'{kiwe_store_email_url}',
		'{kiwe_whatsapp_url}',
		'{kiwe_directions_url}',
	];

	private const KIWE_CAPABILITY_ATTRIBUTES = [
		'data-kiwe-save',
		'data-kiwe-save-id',
		'data-kiwe-save-title',
		'data-kiwe-save-url',
		'data-kiwe-save-image',
		'data-kiwe-notifications',
		'data-kiwe-notification-status-target',
		'data-kiwe-notification-topic',
		'data-dsa-native-notification-request',
		'data-kiwe-theme-toggle',
		'data-kiwe-theme-status-target',
		'data-kiwe-contact',
		'data-kiwe-contact-message',
		'data-kiwe-social',
		'data-kiwe-query-template',
		'data-kiwe-binding',
	];

	private const RESPONSIVE_LAYOUT_KEY_PATTERN = '/^_(?:cssCustom|direction|display|grid|gridItem|gridTemplate|gridAuto|align|justify|place|flex|gap|rowGap|columnGap|order|width|widthMin|widthMax|height|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|aspectRatio|margin|padding|position|top|right|bottom|left|zIndex|overflow|masonry)[A-Za-z0-9_]*:[a-z][a-z0-9_-]{1,48}(?::[a-z-]+)?$/i';
	private const COMPLEX_LAYOUT_PATTERN        = '/\b(?:bento|campaign-grid|masonry|editorial-grid)\b|grid-template-(?:columns|rows|areas)\s*:|grid-auto-(?:columns|rows|flow)\s*:|grid-column\s*:|grid-row\s*:|@media[\s\S]{0,1600}(?:grid-template|grid-column|grid-row|flex-direction|\.nc-section-head|\.seam-spread)/i';
	private const NATIVE_STYLE_CONTROL_PATTERN  = '/^_(?:typography|background|gradient|border|boxShadow|transform|transformOrigin|cssFilters|cssTransition|display|grid(?:Template|Auto|Item)?[A-Za-z0-9_]*|justifyItemsGrid|alignItemsGrid|justifyContentGrid|alignContentGrid|direction|alignSelf|alignItems|justifyContent|flexWrap|flexGrow|flexShrink|flexBasis|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|position|top|right|bottom|left|zIndex|overflow|objectFit|objectPosition|opacity|isolation|mixBlendMode|pointerEvents|perspective|perspectiveOrigin|color|textAlign|font|lineHeight|letterSpacing)(?::|$)/';
	private const MAPPABLE_CSS_PATTERN          = '/\b(?:display|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|align-items|align-self|justify-content|justify-items|align-content|gap|row-gap|column-gap|grid-template-columns|grid-template-rows|grid-auto-flow|grid-auto-columns|grid-auto-rows|grid-column|grid-row|width|max-width|min-width|height|max-height|min-height|aspect-ratio|margin(?:-(?:top|right|bottom|left))?|padding(?:-(?:top|right|bottom|left))?|position|top|right|bottom|left|z-index|overflow|opacity|background(?:-color|-image|-size|-position|-repeat)?|color|border(?:-(?:radius|color|width|style))?|box-shadow|font(?:-(?:family|size|weight|style))?|line-height|letter-spacing|text-align|text-transform|transform|filter|transition)\s*:/i';
	private const TOKEN_OWNED_NATIVE_CONTROL_PATTERN = '/^_(?:typography|border|boxShadow|transform|grid(?:Template|Auto|Item)?[A-Za-z0-9_]*|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|top|right|bottom|left|font|lineHeight|letterSpacing)(?::|$)/';
	private const TOKEN_OWNED_NESTED_KEY_PATTERN     = '/^(?:font-size|fontSize|line-height|lineHeight|letter-spacing|letterSpacing|top|right|bottom|left|width|height|widthMin|widthMax|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|radius|offsetX|offsetY|blur|spread|translateX|translateY|translateZ|x|y|gap|rowGap|columnGap)$/i';
	private const LITERAL_LENGTH_PATTERN             = '/-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)\b/i';
	private const OFFICIAL_TOKEN_VAR_PATTERN         = '/var\(\s*--(?:kiwe|seam)-/i';
	private const SELF_CLAMP_LENGTH_PATTERN          = '/clamp\(\s*(-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)\b)\s*,\s*\1\s*,\s*\1\s*\)/i';
	private const TOKEN_OWNED_COLOR_CONTROL_PATTERN  = '/^_(?:typography|background|gradient|border|boxShadow|cssFilters|color|fill|stroke|cssCustom)(?::|$)/';
	private const TOKEN_OWNED_COLOR_NESTED_KEY_PATTERN = '/^(?:color|background|backgroundColor|background-color|backgroundImage|background-image|gradient|raw|hex|rgb|hsl|hue|saturation|lightness|fill|stroke|borderColor|border-color|shadowColor|shadow-color)$/i';
	private const COLOR_LITERAL_PATTERN              = '/#[0-9a-fA-F]{3,8}\b|\b(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color)\s*\([^)]*\)|(?<![-\w])(?:white|black)(?![-\w])/i';
	private const TEMPLATE_UPLOAD_CUSTOM_CSS_BYTES = 2500;
	private const TEMPLATE_UPLOAD_MAPPABLE_CSS_MIN = 12;
	private const LARGE_TEMPLATE_ELEMENT_COUNT     = 180;
	private const MIN_NATIVE_STYLE_CONTROLS        = 60;
	private const MIN_ELEMENT_NATIVE_CONTROLS_PER_ELEMENT = 1.15;
	private const MAX_CLASS_ONLY_ELEMENT_RATIO            = 0.25;
	private const SUPPORTED_TEMPLATE_BRICKS_VERSION_PATTERN = '/^2\.3(?:\.|$)/';
	private const REVIEW_ONLY_CODE_ELEMENT_ALLOWANCE_PATTERN = '/\b(?:review-only|manual-review|unsupported|code-exception)\b/i';
	private const TEMPLATE_UPLOAD_SAFE_CLASS_PREFIX_PATTERN = '/^(?:kiwe|seam|dsa|sf|nc|bv|bio|appsite)-/i';
	private const BRICKS_COMPILE_UNSAFE_CONTROL_PATTERN = '/^_(?:minWidth|maxWidth|minHeight|maxHeight)(?::|$)/';
	private const BRICKS_FONT_FAMILY_TOKEN_PATTERN = '/var\(\s*--/i';
	private const SEMANTIC_HEADING_TAG_PATTERN = '/^h[1-6]$/i';
	private const SEMANTIC_HEADING_TYPE_TOKEN_PATTERN = '/var\(\s*--(?:kiwe|seam)-type-h[1-6]\b/i';
	private const TEMPLATE_UPLOAD_GENERIC_CLASS_ALLOWLIST = [
		'is-active',
		'is-current',
		'is-disabled',
		'is-loading',
		'is-empty',
		'is-hidden',
	];
	private const TOKEN_FINDING_LIMIT              = 40;
	private const COLOR_FINDING_LIMIT              = 40;
	private const CSS_VAR_FINDING_LIMIT            = 40;

	public function validate( array $conversion, array $site_graph = [], string $source_html = '', array $binding = [] ): array {
		$findings = [];
		$index    = $this->graph_index( $site_graph );

		if ( $this->is_likely_bricks_template_export( $conversion ) ) {
			$this->validate_native_template_export( $conversion, $findings );

			return [
				'ok'       => ! $this->has_level( $findings, 'fail' ),
				'schema'   => 'kiwe.bricks-conversion-validation.v1',
				'mode'     => 'native-bricks-template',
				'counts'   => $this->counts( $findings ),
				'summary'  => [
					'elements'       => $this->template_element_count( $conversion ),
					'hasSourceHtml'  => '' !== trim( $source_html ),
					'hasSiteGraph'   => [] !== $site_graph,
					'hasBindingPlan' => [] !== $binding,
				],
				'findings' => $findings,
			];
		}

		$this->validate_root( $conversion, $findings );
		$this->validate_elements( $conversion, $findings, $index );
		$runtime_code_elements = $this->bricks_runtime_code_elements( isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [] );
		foreach ( array_slice( $runtime_code_elements, 0, 20 ) as $item ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_runtime_code_element',
				sprintf( 'Bricks Code element "%1$s" contains runtime/custom-code settings (%2$s). SEAM Compiler must not ship representable page layout/design or JavaScript authority as a Code element; use native Bricks elements, controls, interactions, Kiwe capability attributes, or an explicit review-only unsupported exception.', (string) ( $item['label'] ?? '' ), implode( ', ', (array) ( $item['keys'] ?? [] ) ) ),
				str_replace( '$.content/header/footer', '$.elements', (string) ( $item['path'] ?? '$.elements' ) )
			);
		}
		if ( count( $runtime_code_elements ) > 20 ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_runtime_code_element_overflow',
				sprintf( 'Bricks conversion contains %d additional runtime Code elements. Treat external-converter output as scaffold/review-only until normalized.', count( $runtime_code_elements ) - 20 ),
				'$.elements'
			);
		}
		$this->validate_tokenized_native_lengths(
			array_merge(
				isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [],
				isset( $conversion['globalClasses'] ) && is_array( $conversion['globalClasses'] ) ? $conversion['globalClasses'] : []
			),
			$findings,
			'$.elements/globalClasses',
			$this->collect_declared_css_variables( $conversion )
		);
		$this->validate_tokenized_native_colors(
			array_merge(
				isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [],
				isset( $conversion['globalClasses'] ) && is_array( $conversion['globalClasses'] ) ? $conversion['globalClasses'] : []
			),
			$findings,
			'$.elements/globalClasses'
		);
		$this->validate_css_variable_fallbacks(
			array_merge(
				isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [],
				isset( $conversion['globalClasses'] ) && is_array( $conversion['globalClasses'] ) ? $conversion['globalClasses'] : []
			),
			$findings,
			'$.elements/globalClasses'
		);
		$this->validate_template_upload_conversion_css( $conversion, $findings );
		$this->validate_source_parity( $conversion, $source_html, $findings );
		$this->validate_responsive_layout_fidelity( $conversion, $source_html, $findings );
		$this->validate_dynamic_tags( $conversion, $findings, $index, [] !== $site_graph );
		if ( [] !== $binding ) {
			$binding_report = ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
			if ( empty( $binding_report['ok'] ) ) {
				$this->add( $findings, 'fail', 'linked_binding_plan_failed', 'Linked binding plan did not pass validation.' );
			}
		}

		return [
			'ok'       => ! $this->has_level( $findings, 'fail' ),
			'schema'   => 'kiwe.bricks-conversion-validation.v1',
			'counts'   => $this->counts( $findings ),
			'summary'  => [
				'elements'       => isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? count( $conversion['elements'] ) : 0,
				'hasSourceHtml'  => '' !== trim( $source_html ),
				'hasSiteGraph'   => [] !== $site_graph,
				'hasBindingPlan' => [] !== $binding,
			],
			'findings' => $findings,
		];
	}

	private function validate_root( array $conversion, array &$findings ): void {
		foreach ( [ 'schema', 'source', 'target', 'conversion', 'elements', 'fidelity', 'report' ] as $key ) {
			if ( ! array_key_exists( $key, $conversion ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_missing_root_key', sprintf( 'Missing root key "%s".', $key ), '$.' . $key );
			}
		}
		if ( 'kiwe.bricks-conversion.v1' !== (string) ( $conversion['schema'] ?? '' ) ) {
			$this->add( $findings, 'fail', 'invalid_bricks_conversion_schema', 'schema must be kiwe.bricks-conversion.v1.', '$.schema' );
		}
		$source = isset( $conversion['source'] ) && is_array( $conversion['source'] ) ? $conversion['source'] : [];
		if ( [] === $source ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_source', 'source must describe the page artifact being converted.', '$.source' );
		} else {
			$source_text = (string) wp_json_encode( $source );
			$source_html = str_replace( '\\', '/', (string) ( $source['html'] ?? $source['path'] ?? '' ) );
			if ( preg_match( '#(^|[\\\\/])(combined-preview|appshell-theme|ui-system)([\\\\/]|$)|theme-package\.json|css[\\\\/]theme\.css|\b(?:dsa\s*theme|appshell|app\s*shell)\b#i', $source_text ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_forbidden_source_lane', 'SEAM Compiler source must be the page artifact only. Do not convert combined-preview, appshell-theme, DSA/AppShell preview markup, theme-package.json, or theme.css into Bricks.', '$.source' );
			}
			if ( '' !== $source_html && ! str_ends_with( $source_html, 'website/bricks-paste.html' ) ) {
				$this->add( $findings, 'warn', 'bricks_conversion_noncanonical_source_path', 'source.html should point to website/bricks-paste.html. Combined previews and AppShell theme previews are never Bricks conversion sources.', '$.source.html' );
			}
		}
		$target = isset( $conversion['target'] ) && is_array( $conversion['target'] ) ? $conversion['target'] : [];
		if ( 'bricks' !== (string) ( $target['builder'] ?? '' ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_wrong_builder', 'target.builder must be bricks.', '$.target.builder' );
		}
		if ( ! str_contains( strtolower( (string) ( $target['format'] ?? '' ) ), 'bricks' ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_format', 'target.format must identify Bricks element JSON.', '$.target.format' );
		}
		$import_method = (string) ( $target['importMethod'] ?? '' );
		if ( '' === $import_method ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_import_method', 'target.importMethod is required. Use review-only, bricks-clipboard-json, bricks-admin-template-upload, or kiwe-staging-executor. Kiwe conversion JSON is not itself a Bricks My Templates upload file.', '$.target.importMethod' );
		} elseif ( ! in_array( $import_method, self::BRICKS_IMPORT_METHODS, true ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_invalid_import_method', 'target.importMethod is not a supported Kiwe Bricks import method.', '$.target.importMethod' );
		}
		if ( 'bricks-admin-template-upload' === $import_method && '' === (string) ( $target['templateExportPath'] ?? '' ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_template_export_path', 'target.templateExportPath is required when target.importMethod is bricks-admin-template-upload.', '$.target.templateExportPath' );
		}
		$authority = (string) ( $target['applyAuthority'] ?? '' );
		if ( '' === $authority || ( preg_match( '/(?:auto|direct|save|publish|mutat|write)/i', $authority ) && ! preg_match( '/(?:human|review|trusted|adapter|staging)/i', $authority ) ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_unsafe_apply_authority', 'applyAuthority must point to human review or a trusted Kiwe staging adapter.', '$.target.applyAuthority' );
		}
		if ( ! isset( $conversion['elements'] ) || ! is_array( $conversion['elements'] ) || [] === $conversion['elements'] ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_elements', 'elements must be a non-empty array.', '$.elements' );
		}
		$fidelity = isset( $conversion['fidelity'] ) && is_array( $conversion['fidelity'] ) ? $conversion['fidelity'] : [];
		if ( empty( $fidelity['sourceSelectors'] ) || ! is_array( $fidelity['sourceSelectors'] ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_fidelity_map', 'fidelity.sourceSelectors must map important source regions to Bricks element IDs.', '$.fidelity.sourceSelectors' );
		}
		foreach ( [ 'elementMapping', 'dynamicIntent', 'responsiveIntent', 'interactions', 'conditions', 'unsupported' ] as $key ) {
			if ( isset( $fidelity[ $key ] ) && ! is_array( $fidelity[ $key ] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_fidelity_lane', sprintf( 'fidelity.%s must be an array when present.', $key ), '$.fidelity.' . $key );
			}
		}
		$report = isset( $conversion['report'] ) && is_array( $conversion['report'] ) ? $conversion['report'] : [];
		if ( ! isset( $report['manualReview'] ) || ! is_array( $report['manualReview'] ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_manual_review_lane', 'report.manualReview must be an array, even when empty.', '$.report.manualReview' );
		}
	}

	private function is_likely_bricks_template_export( array $data ): bool {
		if ( 'kiwe.bricks-conversion.v1' === (string) ( $data['schema'] ?? '' ) || isset( $data['elements'] ) ) {
			return false;
		}
		return isset( $data['content'] ) || isset( $data['header'] ) || isset( $data['footer'] ) || isset( $data['templateType'] ) || isset( $data['pageSettings'] ) || isset( $data['bundles'] );
	}

	private function validate_native_template_export( array $template, array &$findings ): void {
		$title = trim( (string) ( $template['title'] ?? '' ) );
		if ( '' === $title ) {
			$this->add( $findings, 'fail', 'bricks_template_missing_title', 'Bricks template export is missing title. Bricks imports this as "(no title)".', '$.title' );
		} elseif ( preg_match( '/^\(?\s*no\s+title\s*\)?$/i', $title ) ) {
			$this->add( $findings, 'fail', 'bricks_template_no_title', 'Bricks template export title is "(no title)". Provide a real human-readable title before upload.', '$.title' );
		}

		$template_type = trim( (string) ( $template['templateType'] ?? '' ) );
		if ( '' === $template_type ) {
			$this->add( $findings, 'fail', 'bricks_template_missing_template_type', 'Bricks template export must include templateType so Bricks stores the imported template in the intended area/type.', '$.templateType' );
		}

		$elements = array_merge(
			isset( $template['content'] ) && is_array( $template['content'] ) ? $template['content'] : [],
			isset( $template['header'] ) && is_array( $template['header'] ) ? $template['header'] : [],
			isset( $template['footer'] ) && is_array( $template['footer'] ) ? $template['footer'] : []
		);
		if ( [] === $elements ) {
			$this->add( $findings, 'fail', 'bricks_template_missing_data', 'Bricks template export must contain a non-empty content, header, or footer array. Otherwise Bricks insert reports "This template has no data".' );
		}

		if ( empty( $template['version'] ) ) {
			$this->add( $findings, 'warn', 'bricks_template_missing_version', 'Bricks template export should include the target Bricks version used to author/verify the native template.', '$.version' );
		} elseif ( ! preg_match( self::SUPPORTED_TEMPLATE_BRICKS_VERSION_PATTERN, (string) $template['version'] ) ) {
			$this->add( $findings, 'fail', 'bricks_template_unsupported_target_version', sprintf( 'Bricks template export declares version "%s". Kiwe production template uploads currently target the public Bricks 2.3.x importer/runtime; do not emit unreleased/beta 2.4 template metadata unless the contract is explicitly updated after a public Bricks release.', (string) $template['version'] ), '$.version' );
		}

		$unsafe_class_names = [];
		if ( isset( $template['global_classes'] ) && is_array( $template['global_classes'] ) ) {
			foreach ( $template['global_classes'] as $template_class ) {
				$name = is_array( $template_class ) ? trim( (string) ( $template_class['name'] ?? '' ) ) : '';
				if ( '' !== $name && ! $this->is_collision_safe_template_class_name( $name ) ) {
					$unsafe_class_names[] = $name;
				}
			}
		}

		if ( [] !== $unsafe_class_names ) {
			$this->add(
				$findings,
				'fail',
				'bricks_template_unscoped_global_class_names',
				sprintf(
					'Bricks template upload contains %1$d unscoped global class name(s): %2$s%3$s. Bricks My Templates skips or remaps imported class styles when a local class has the same id or name, so SEAM Compiler must namespace project visual global classes (for example nc-promo-card, bv-product-card, sf-hero-grid) and keep plain semantic names only in _cssClasses/attributes, not importable global_classes.',
					count( $unsafe_class_names ),
					implode( ', ', array_map( static fn( $name ) => '"' . $name . '"', array_slice( $unsafe_class_names, 0, 12 ) ) ),
					count( $unsafe_class_names ) > 12 ? ', ...' : ''
				),
				'$.global_classes'
			);
		}

		$variable_name_findings = $this->template_variable_name_findings( $template );
		$prefixed_variable_name_findings = array_values(
			array_filter(
				$variable_name_findings,
				static fn( $item ) => 'variable-value-has-fallback' !== ( $item['type'] ?? '' )
			)
		);
		$variable_value_fallback_findings = array_values(
			array_filter(
				$variable_name_findings,
				static fn( $item ) => 'variable-value-has-fallback' === ( $item['type'] ?? '' )
			)
		);
		foreach ( array_slice( $prefixed_variable_name_findings, 0, 20 ) as $item ) {
			$this->add(
				$findings,
				'fail',
				'bricks_template_variable_name_has_css_prefix',
				sprintf(
					'Bricks global variable "%1$s" includes the CSS custom-property prefix. Native Bricks global_variables/globalVariables names must be stored without leading "--" because Bricks emits the "--" prefix when compiling CSS. Keeping it here compiles to "----%2$s", while page controls consume "var(%1$s)", leaving the frontend disconnected from the token.',
					(string) ( $item['name'] ?? '' ),
					ltrim( (string) ( $item['name'] ?? '' ), '-' )
				),
				(string) ( $item['path'] ?? '$.global_variables' )
			);
		}
		if ( count( $prefixed_variable_name_findings ) > 20 ) {
			$this->add(
				$findings,
				'fail',
				'bricks_template_variable_name_prefix_overflow',
				sprintf( 'Bricks template export contains %d additional global variable names with leading "--". Store names as "kiwe-color-brand" or "nc-app-max", not "--kiwe-color-brand" or "--nc-app-max".', count( $prefixed_variable_name_findings ) - 20 ),
				'$.global_variables'
			);
		}
		foreach ( array_slice( $variable_value_fallback_findings, 0, 20 ) as $item ) {
			$this->add(
				$findings,
				'fail',
				'bricks_template_variable_value_has_fallback',
				sprintf(
					'Bricks global variable "%1$s" references "%2$s" with an inline fallback in "%3$s". Template variables must not smuggle render values through fallbacks; define the real value in the paired Kiwe Framework profile / Bricks variable push and consume bare variables in the template.',
					(string) ( $item['name'] ?? '' ),
					(string) ( $item['variable'] ?? '' ),
					(string) ( $item['value'] ?? '' )
				),
				(string) ( $item['path'] ?? '$.global_variables' )
			);
		}
		if ( count( $variable_value_fallback_findings ) > 20 ) {
			$this->add(
				$findings,
				'fail',
				'bricks_template_variable_value_has_fallback_overflow',
				sprintf( 'Bricks template export contains %d additional global variable values with inline CSS-variable fallbacks. Remove the fallbacks and keep the values in the Framework profile.', count( $variable_value_fallback_findings ) - 20 ),
				'$.global_variables'
			);
		}

		foreach ( $elements as $position => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$name = (string) ( $element['name'] ?? '' );
			if ( in_array( $name, self::SEMANTIC_HTML_ELEMENT_NAME_MISUSE, true ) ) {
				$this->add( $findings, 'fail', 'bricks_template_semantic_tag_as_element', sprintf( 'Bricks template export uses semantic HTML tag "%s" as an element name. Use a supported Bricks element such as block/div/container and set tag/customTag to "%s"; otherwise Bricks can render "%s: PHP class does not exist".', $name, $name, $name ), '$.content/header/footer[' . (int) $position . '].name' );
			} elseif ( '' !== $name && ! in_array( $name, self::KNOWN_BRICKS_ELEMENTS, true ) ) {
				$this->add( $findings, 'warn', 'bricks_template_unknown_element', sprintf( 'Bricks template export uses element "%s" that is not in the Kiwe known Bricks element list. Confirm it exists on the target Bricks installation before upload.', $name ), '$.content/header/footer[' . (int) $position . '].name' );
			}
		}

		$custom_css = $this->custom_css_text( [ 'pageSettings' => $template['pageSettings'] ?? [], 'settings' => $template['settings'] ?? [] ] );
		$css_bytes  = strlen( $custom_css );
		$mappable   = $this->count_mappable_css( $custom_css );
		if ( $css_bytes >= self::TEMPLATE_UPLOAD_CUSTOM_CSS_BYTES || $mappable >= self::TEMPLATE_UPLOAD_MAPPABLE_CSS_MIN || preg_match( '/@media\b|#home-campaigns\b|\.nc-(?:bento|campaign|section-head)|grid-template|flex-direction/i', $custom_css ) ) {
			$this->add( $findings, 'fail', 'bricks_template_depends_on_page_custom_css', sprintf( 'Bricks template export carries %1$d page/template custom CSS bytes and %2$d mappable declarations. Bricks My Templates insertion can leave pageSettings custom CSS behind or collide with stale target-page CSS; move ordinary layout/design into native element settings, importable globalClasses/globalVariables, or documented tiny exceptions.', $css_bytes, $mappable ), '$.pageSettings.customCss' );
		}

		$template_style_items = array_merge(
			$elements,
			isset( $template['global_classes'] ) && is_array( $template['global_classes'] ) ? $template['global_classes'] : [],
			isset( $template['globalClasses'] ) && is_array( $template['globalClasses'] ) ? $template['globalClasses'] : []
		);
		$native_controls = $this->count_native_style_controls( array_merge( $elements, isset( $template['global_classes'] ) && is_array( $template['global_classes'] ) ? $template['global_classes'] : [] ) );
		$runtime_code_elements = $this->bricks_runtime_code_elements( $elements );
		foreach ( array_slice( $runtime_code_elements, 0, 20 ) as $item ) {
			$this->add(
				$findings,
				'fail',
				'bricks_template_runtime_code_element',
				sprintf( 'Bricks Code element "%1$s" contains runtime/custom-code settings (%2$s). External converters may park CSS/JS in Code elements for manual review, but production SEAM Compiler output must decompose representable layout/design into native Bricks elements, controls, variables, attributes, interactions, and documented unsupported exceptions instead of shipping Code-element authority.', (string) ( $item['label'] ?? '' ), implode( ', ', (array) ( $item['keys'] ?? [] ) ) ),
				(string) ( $item['path'] ?? '$.content/header/footer' )
			);
		}
		if ( count( $runtime_code_elements ) > 20 ) {
			$this->add(
				$findings,
				'fail',
				'bricks_template_runtime_code_element_overflow',
				sprintf( 'Bricks template export contains %d additional runtime Code elements. Treat external-converter output as scaffold/review-only until those Code elements are normalized or documented as explicit unsupported exceptions.', count( $runtime_code_elements ) - 20 ),
				'$.content/header/footer'
			);
		}
		foreach ( array_slice( $this->bricks_implicit_layout_controls( $template_style_items ), 0, 40 ) as $item ) {
			$type = (string) ( $item['type'] ?? '' );
			if ( 'missing-flex-direction' === $type ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_missing_flex_direction',
					sprintf( 'Bricks layout element "%s" sets _display:flex but omits _direction. Bricks source-backed layout controls must explicitly own flex direction; relying on browser defaults causes rail/card drift and makes the visual editor ambiguous.', (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'missing-grid-columns' === $type ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_missing_grid_columns',
					sprintf( 'Bricks layout element "%s" sets _display:grid but omits _gridTemplateColumns/_gridAutoColumns. Grid layout must be represented by Bricks-native grid controls, not implicit CSS/default behavior.', (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'rail-missing-flex-display' === $type ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_rail_missing_flex_display',
					sprintf( 'Seam horizontal rail "%s" must set Bricks _display:flex on the actual item track. Rail semantics alone do not create Bricks-native layout ownership.', (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'rail-missing-row-direction' === $type ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_rail_missing_row_direction',
					sprintf( 'Seam horizontal rail "%s" must set Bricks _direction:row. This is the source-backed control that preserves category/product rail orientation in Bricks 2.3.x/2.4.', (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'rail-missing-overflow' === $type ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_rail_missing_overflow',
					sprintf( 'Seam horizontal rail "%s" must set Bricks _overflow:auto or scroll so the actual rail track remains scrollable after import.', (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'rail-missing-gap' === $type ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_rail_missing_gap',
					sprintf( 'Seam horizontal rail "%s" must expose a tokenized Bricks _columnGap or _gap control; spacing cannot be hidden in defaults or external CSS.', (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			}
		}
		foreach ( array_slice( $this->bricks_compiler_unsafe_controls( $template_style_items ), 0, 40 ) as $item ) {
			if ( 'unsupported-control' === (string) ( $item['type'] ?? '' ) ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_compiler_unsafe_control',
					sprintf( 'Bricks native control "%1$s" on "%2$s" is not compiler-safe for My Templates output. Use Bricks source-backed controls "_widthMin", "_widthMax", "_heightMin", or "_heightMax" instead of "_minWidth", "_maxWidth", "_minHeight", or "_maxHeight"; otherwise the frontend CSS silently drops the intended rule.', (string) ( $item['key'] ?? '' ), (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'font-family-token' === (string) ( $item['type'] ?? '' ) ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_typography_font_family_token',
					sprintf( 'Bricks typography control "%1$s" on "%2$s" stores font-family as "%3$s". Bricks compiles typography font families as quoted values, so CSS-variable font stacks become invalid like font-family: "var(--kiwe-font-body, ...)". Use a concrete Bricks font-family value in _typography and keep tokenized font families in the Framework/theme layer.', (string) ( $item['key'] ?? '' ), (string) ( $item['label'] ?? '' ), (string) ( $item['value'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'semantic-heading-font-size-lock' === (string) ( $item['type'] ?? '' ) ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_semantic_heading_font_size_lock',
					sprintf( 'Bricks semantic heading "%1$s" is tagged "%2$s" but locks its own font-size to "%3$s". Semantic heading scale belongs in Kiwe > Framework / Bricks Theme Style; remove local heading-token font-size so changing h3 to h2/h4 in Bricks uses the selected heading level.', (string) ( $item['label'] ?? '' ), (string) ( $item['tag'] ?? '' ), (string) ( $item['value'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'color-shape' === (string) ( $item['type'] ?? '' ) ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_color_control_string_shape',
					sprintf( 'Bricks color control "%1$s" on "%2$s" stores color as a plain string "%3$s". Bricks frontend CSS generation expects color objects such as { "raw": "var(--kiwe-color-surface)" } for background, border, typography and related native controls; plain strings can remain in JSON but be omitted from frontend CSS.', (string) ( $item['key'] ?? '' ), (string) ( $item['label'] ?? '' ), (string) ( $item['value'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'radius-shape' === (string) ( $item['type'] ?? '' ) ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_border_radius_corner_shape',
					sprintf( 'Bricks border-radius control "%1$s" on "%2$s" uses CSS corner keys "%3$s". Bricks frontend CSS generation reads radius.top, radius.right, radius.bottom, and radius.left, then maps them to the four CSS corners; topLeft/topRight/bottomRight/bottomLeft can remain in JSON but silently compile to no radius.', (string) ( $item['key'] ?? '' ), (string) ( $item['label'] ?? '' ), (string) ( $item['value'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			} elseif ( 'background-gradient-color' === (string) ( $item['type'] ?? '' ) ) {
				$this->add(
					$findings,
					'fail',
					'bricks_template_background_gradient_in_color',
					sprintf( 'Bricks background color control "%1$s" on "%2$s" stores a gradient in color.raw. Bricks compiles _background.color to background-color, where gradients are invalid; use the native "_gradient" control with tokenized color stops and keep _background.color as a solid fallback.', (string) ( $item['key'] ?? '' ), (string) ( $item['label'] ?? '' ) ),
					(string) ( $item['path'] ?? '$.content/header/footer/global_classes' )
				);
			}
		}
		$this->validate_tokenized_native_lengths(
			$template_style_items,
			$findings,
			'$.content/header/footer/global_classes',
			$this->collect_declared_css_variables( $template )
		);
		$this->validate_tokenized_native_colors(
			$template_style_items,
			$findings,
			'$.content/header/footer/global_classes'
		);
		$this->validate_css_variable_fallbacks(
			$template_style_items,
			$findings,
			'$.content/header/footer/global_classes'
		);
		$this->validate_project_variable_framework_proof(
			$template,
			$template_style_items,
			$findings,
			'$.content/header/footer/global_classes'
		);
		if ( count( $elements ) >= self::LARGE_TEMPLATE_ELEMENT_COUNT && $native_controls < self::MIN_NATIVE_STYLE_CONTROLS ) {
			$this->add( $findings, 'fail', 'bricks_template_not_native_editable_enough', sprintf( 'Large Bricks template export has %1$d elements but only %2$d native style/layout controls. Full-page template uploads must preserve editable Bricks controls instead of relying on source/page CSS that may not follow insertion.', count( $elements ), $native_controls ), '$.content' );
		}

		$editability = $this->template_editability_stats( $elements );
		if ( count( $elements ) >= self::LARGE_TEMPLATE_ELEMENT_COUNT && $editability['controls_per_element'] < self::MIN_ELEMENT_NATIVE_CONTROLS_PER_ELEMENT ) {
			$this->add( $findings, 'fail', 'bricks_template_element_native_controls_too_low', sprintf( 'Large Bricks template export has %1$d element-level native style/layout controls across %2$d elements (%3$.2f per element). This is too class-dependent for a visual-editor handoff: grid/flex, spacing, sizing, typography, color, borders, radius, shadows, and responsive overrides must be editable on elements where the source design depends on them, not only in importable global_classes.', $editability['element_controls'], count( $elements ), $editability['controls_per_element'] ), '$.content' );
		}
		if ( count( $elements ) >= self::LARGE_TEMPLATE_ELEMENT_COUNT && $editability['class_only_ratio'] > self::MAX_CLASS_ONLY_ELEMENT_RATIO ) {
			$this->add( $findings, 'fail', 'bricks_template_class_hydration_dependency', sprintf( 'Large Bricks template export has %1$d of %2$d elements (%3$d%%) carrying global-class dependencies without element-level native style/layout controls. Bricks My Templates can skip or remap global class definitions when class names already exist, so SEAM Compiler must keep the rendered design resilient with sufficient element-native controls instead of relying mainly on class hydration.', $editability['class_only_elements'], count( $elements ), (int) round( $editability['class_only_ratio'] * 100 ) ), '$.content' );
		}

		$styled_global_classes = $this->styled_template_global_classes( isset( $template['global_classes'] ) && is_array( $template['global_classes'] ) ? $template['global_classes'] : [] );
		if ( count( $elements ) >= self::LARGE_TEMPLATE_ELEMENT_COUNT && [] !== $styled_global_classes ) {
			$names = array_map(
				static function ( array $item ): string {
					return '' !== (string) ( $item['name'] ?? '' ) ? (string) $item['name'] : ( '' !== (string) ( $item['id'] ?? '' ) ? (string) $item['id'] : '(unnamed)' );
				},
				array_slice( $styled_global_classes, 0, 12 )
			);
			$this->add(
				$findings,
				'fail',
				'bricks_template_multi_owner_global_class_styles',
				sprintf( 'Large Bricks template export imports %1$d styled global_classes (%2$s%3$s) while element-native controls already own visual fidelity. This creates multi-owner "ghost styling" in Bricks: removing a color/radius/spacing from the visible element or class can leave the same style active from another layer. SEAM Compiler template uploads must use element-native controls as the render/edit owner and keep imported global_classes semantic/name-only; reusable project classes belong in the Framework profile push, not as duplicate styled classes in the template upload.', count( $styled_global_classes ), implode( ', ', $names ), count( $styled_global_classes ) > 12 ? ', ...' : '' ),
				'$.global_classes'
			);
		}
	}

	private function template_element_count( array $template ): int {
		return count(
			array_merge(
				isset( $template['content'] ) && is_array( $template['content'] ) ? $template['content'] : [],
				isset( $template['header'] ) && is_array( $template['header'] ) ? $template['header'] : [],
				isset( $template['footer'] ) && is_array( $template['footer'] ) ? $template['footer'] : []
			)
		);
	}

	private function validate_template_upload_conversion_css( array $conversion, array &$findings ): void {
		$target = isset( $conversion['target'] ) && is_array( $conversion['target'] ) ? $conversion['target'] : [];
		if ( 'bricks-admin-template-upload' !== (string) ( $target['importMethod'] ?? '' ) ) {
			return;
		}
		$custom_css = $this->custom_css_text( [ 'pageSettings' => $conversion['pageSettings'] ?? [] ] );
		$css_bytes  = strlen( $custom_css );
		$mappable   = $this->count_mappable_css( $custom_css );
		if ( $css_bytes >= self::TEMPLATE_UPLOAD_CUSTOM_CSS_BYTES || $mappable >= self::TEMPLATE_UPLOAD_MAPPABLE_CSS_MIN || preg_match( '/@media\b|#home-campaigns\b|\.nc-(?:bento|campaign|section-head)|grid-template|flex-direction/i', $custom_css ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_template_upload_page_css_dependency', sprintf( 'target.importMethod is bricks-admin-template-upload, but the Kiwe conversion envelope carries %1$d pageSettings custom CSS bytes and %2$d mappable declarations. Template-upload handoffs must not rely on pageSettings.customCss for ordinary layout/design; use native element settings/globalClasses/globalVariables first.', $css_bytes, $mappable ), '$.pageSettings.customCss' );
		}
	}

	private function validate_elements( array $conversion, array &$findings, array $index ): void {
		$elements = isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [];
		$ids      = [];
		foreach ( $elements as $position => $element ) {
			if ( ! is_array( $element ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_element', sprintf( 'elements[%d] must be an object.', (int) $position ), '$.elements' );
				continue;
			}
			$id = (string) ( $element['id'] ?? '' );
			if ( '' === $id ) {
				$this->add( $findings, 'fail', 'bricks_conversion_element_missing_id', sprintf( 'elements[%d].id is required.', (int) $position ), '$.elements' );
			} elseif ( isset( $ids[ $id ] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_duplicate_element_id', sprintf( 'Duplicate element id "%s".', $id ), '$.elements' );
			}
			if ( '' !== $id ) {
				$ids[ $id ] = true;
			}
			if ( '' === (string) ( $element['name'] ?? '' ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_element_missing_name', sprintf( 'Element "%s" is missing name.', '' !== $id ? $id : '#' . (int) $position ), '$.elements' );
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			if ( isset( $settings['_conditions'] ) && ! is_array( $settings['_conditions'] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_conditions', sprintf( 'Element "%s" has _conditions but it is not an array.', $id ), '$.elements' );
			}
			if ( isset( $settings['_interactions'] ) && ! is_array( $settings['_interactions'] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_interactions', sprintf( 'Element "%s" has _interactions but it is not an array.', $id ), '$.elements' );
			} elseif ( isset( $settings['_interactions'] ) ) {
				foreach ( $settings['_interactions'] as $interaction ) {
					if ( is_array( $interaction ) && 'javascript' === (string) ( $interaction['action'] ?? $interaction['actionType'] ?? '' ) ) {
						$this->add( $findings, 'fail', 'bricks_conversion_javascript_interaction', sprintf( 'Element "%s" uses Bricks javascript interaction action.', $id ), '$.elements' );
					}
				}
			}
			$query = isset( $settings['query'] ) && is_array( $settings['query'] ) ? $settings['query'] : [];
			if ( [] !== $query ) {
				$this->validate_query( $query, $findings, $index, $id );
			}
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$parent = (string) ( $element['parent'] ?? '' );
			if ( '' !== $parent && '0' !== $parent && empty( $ids[ $parent ] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_missing_parent', sprintf( 'Element "%s" references missing parent "%s".', (string) ( $element['id'] ?? '' ), $parent ), '$.elements' );
			}
		}
	}

	private function validate_query( array $query, array &$findings, array $index, string $element_id ): void {
		$object_type = (string) ( $query['objectType'] ?? $query['object_type'] ?? '' );
		if ( '' !== $object_type && $index['queryTypes'] && empty( $index['queryTypes'][ $object_type ] ) ) {
			$this->add( $findings, 'warn', 'bricks_conversion_query_object_type_unverified', sprintf( 'Element "%s" uses query objectType "%s" not found in Site Graph.', $element_id, $object_type ), '$.elements' );
		}
		$post_types = $this->array_value( $query, 'post_type' );
		foreach ( $post_types as $post_type ) {
			if ( $index['postTypes'] && empty( $index['postTypes'][ (string) $post_type ] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_query_post_type_missing', sprintf( 'Element "%s" uses post type "%s" missing from Site Graph.', $element_id, (string) $post_type ), '$.elements' );
			}
		}
	}

	private function validate_responsive_layout_fidelity( array $conversion, string $source_html, array &$findings ): void {
		$conversion_text = wp_json_encode( $conversion );
		$conversion_text = is_string( $conversion_text ) ? $conversion_text : '';
		$elements        = isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [];
		$overrides       = $this->collect_responsive_layout_overrides( $elements );
		$has_complex     = (bool) preg_match( self::COMPLEX_LAYOUT_PATTERN, $source_html . "\n" . $conversion_text );
		$fidelity        = isset( $conversion['fidelity'] ) && is_array( $conversion['fidelity'] ) ? $conversion['fidelity'] : [];
		$responsive      = isset( $fidelity['responsiveIntent'] ) && is_array( $fidelity['responsiveIntent'] ) ? $fidelity['responsiveIntent'] : [];

		if ( ( $has_complex || [] !== $overrides ) && [] === $responsive ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_responsive_intent', 'fidelity.responsiveIntent must be a non-empty array when source/conversion uses complex bento/grid/campaign layout or Bricks breakpoint layout overrides.', '$.fidelity.responsiveIntent' );
		}

		foreach ( $responsive as $index => $item ) {
			$item_text = is_array( $item ) ? (string) wp_json_encode( $item ) : (string) $item;
			if ( ! is_array( $item ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_responsive_intent', sprintf( 'fidelity.responsiveIntent[%d] must be an object.', (int) $index ), '$.fidelity.responsiveIntent' );
				continue;
			}
			if ( ! preg_match( '/desktop|tablet|mobile|narrow|breakpoint|viewport|range/i', $item_text ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_responsive_intent_missing_breakpoint', sprintf( 'fidelity.responsiveIntent[%d] must identify the breakpoint or viewport range.', (int) $index ), '$.fidelity.responsiveIntent' );
			}
			if ( ! preg_match( '/selector|source|element|bricks|mappedElementIds|id/i', $item_text ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_responsive_intent_missing_mapping', sprintf( 'fidelity.responsiveIntent[%d] must connect the source selector to Bricks element IDs/settings.', (int) $index ), '$.fidelity.responsiveIntent' );
			}
			if ( ! preg_match( '/grid|flex|direction|columns|rows|span|wrap|align|justify|flow/i', $item_text ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_responsive_intent_missing_behavior', sprintf( 'fidelity.responsiveIntent[%d] must state the preserved layout behavior.', (int) $index ), '$.fidelity.responsiveIntent' );
			}
		}

		if ( $has_complex && ! preg_match( '/#home-campaigns|bento|campaign|grid-template|grid-column|grid-row/i', (string) wp_json_encode( $fidelity['sourceSelectors'] ?? [] ) ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_complex_layout_fidelity', 'fidelity.sourceSelectors must explicitly include complex bento/grid/campaign regions such as #home-campaigns/.nc-bento and their mapped Bricks element IDs.', '$.fidelity.sourceSelectors' );
		}

		if ( $has_complex && [] !== $responsive && ! preg_match( '/#home-campaigns|bento|campaign|grid|columns|rows|span/i', (string) wp_json_encode( $responsive ) ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_complex_responsive_fidelity', 'fidelity.responsiveIntent must explicitly describe bento/grid/campaign responsive behavior so Bricks desktop/tablet/mobile layouts cannot silently drift.', '$.fidelity.responsiveIntent' );
		}

		$responsive_text = (string) wp_json_encode( $responsive );
		foreach ( $overrides as $override ) {
			$key     = (string) ( $override['key'] ?? '' );
			$value   = strtolower( (string) ( $override['value'] ?? '' ) );
			$id      = (string) ( $override['id'] ?? '' );
			$classes = (string) ( $override['classes'] ?? '' );
			$css_id  = (string) ( $override['cssId'] ?? '' );
			if ( preg_match( '/\bseam-spread\b/', $classes . ' ' . $css_id ) && preg_match( '/_(?:direction|flexDirection):/i', $key ) && 'column' === $value && ! preg_match( '/' . preg_quote( '' !== $id ? $id : 'missing-id', '/' ) . '|' . preg_quote( '' !== $css_id ? $css_id : 'missing-css-id', '/' ) . '|seam-spread|section-head/i', $responsive_text ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_unproven_seam_spread_direction_override', sprintf( 'Element "%s" changes seam-spread to column at %s without a responsiveIntent entry tied to source evidence.', '' !== $id ? $id : 'unknown', $key ), '$.fidelity.responsiveIntent' );
			}
		}
	}

	private function collect_responsive_layout_overrides( array $elements ): array {
		$out = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			foreach ( $settings as $key => $value ) {
				if ( preg_match( self::RESPONSIVE_LAYOUT_KEY_PATTERN, (string) $key ) ) {
					$out[] = [
						'id'      => (string) ( $element['id'] ?? '' ),
						'key'     => (string) $key,
						'value'   => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
						'classes' => (string) ( $settings['_cssClasses'] ?? '' ),
						'cssId'   => (string) ( $settings['_cssId'] ?? '' ),
					];
				}
			}
		}
		return $out;
	}

	private function validate_source_parity( array $conversion, string $source_html, array &$findings ): void {
		$conversion_text = wp_json_encode( $conversion );
		$conversion_text = is_string( $conversion_text ) ? $conversion_text : '';
		if ( '' === trim( $source_html ) ) {
			$this->add( $findings, 'warn', 'bricks_conversion_missing_source_html', 'No sourceHtml was supplied, so source-to-conversion parity could not be fully checked.' );
			return;
		}
		if ( preg_match( '/data-dsa-(?:surface|dock|screen|sheet|cart-panel|profile-panel)/i', $source_html ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_source_contains_appshell', 'Source HTML must remain page-only and must not include AppShell shell markup.' );
		}
		if ( preg_match_all( '/(?:^|[{}]|\\\\n|\\\\r|\n|\r)\s*([^{}@]{0,760})\{/i', $source_html, $seam_selector_matches ) ) {
			$selectors = [];
			foreach ( $seam_selector_matches[1] as $selector_group ) {
				foreach ( explode( ',', (string) $selector_group ) as $selector ) {
					$selector = trim( preg_replace( '/\/\*[\s\S]*?\*\//', '', (string) $selector ) );
					$selector = trim( preg_replace( '/\s+/', ' ', str_replace( [ '\\n', '\\r' ], ' ', $selector ) ) );
					if ( '' === $selector || ! preg_match( '/(?:^|[\s>+~(:])\.seam-[a-z0-9_-]+|\[data-(?:flow|role|tone|state)\b/i', $selector ) ) {
						continue;
					}
					$selectors[ $selector ] = true;
				}
			}
			if ( [] !== $selectors ) {
				$this->add( $findings, 'fail', 'bricks_conversion_source_redefines_seam_selector', sprintf( 'Source CSS redefines bare Seam framework selectors (%s). Move visual rules to project classes before converting to Bricks.', implode( ', ', array_slice( array_keys( $selectors ), 0, 8 ) ) ) );
			}
		}
		if ( preg_match( '/<[a-z][a-z0-9-]*\b[^>]*class\s*=\s*["\'][^"\']*\bseam-nav\b[^"\']*\bseam-horizontal-rail\b[^"\']*["\'][^>]*>/i', $source_html ) || preg_match( '/<[a-z][a-z0-9-]*\b[^>]*class\s*=\s*["\'][^"\']*\bseam-nav\b[^"\']*["\'][^>]*data-flow\s*=\s*["\'](?:reel|horizontal-rail)["\'][^>]*>/i', $source_html ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_source_nav_rail_wrapper', 'Source applies Seam rail flow to a seam-nav wrapper. Put .seam-horizontal-rail/data-flow="horizontal-rail" only on the actual item track before converting to Bricks.' );
		}
		if ( preg_match_all( '/<([a-z][a-z0-9-]*)\b[^>]*(?:class\s*=\s*["\'][^"\']*\bseam-horizontal-rail\b[^"\']*["\']|data-flow\s*=\s*["\'](?:reel|horizontal-rail)["\'])[^>]*>([\s\S]{0,5200}?)(?:<\/\1>|$)/i', $source_html, $rail_matches, PREG_SET_ORDER ) ) {
			foreach ( $rail_matches as $rail_match ) {
				$inner = (string) ( $rail_match[2] ?? '' );
				if ( preg_match( '/(?:class\s*=\s*["\'][^"\']*\bseam-horizontal-rail\b[^"\']*["\']|data-flow\s*=\s*["\'](?:reel|horizontal-rail)["\']|class\s*=\s*["\'][^"\']*\bseam-container\b)/i', $inner ) ) {
					$this->add( $findings, 'fail', 'bricks_conversion_source_rail_on_wrapper', 'Source applies Seam rail flow to a wrapper containing a container or descendant rail. Put .seam-horizontal-rail/data-flow="horizontal-rail" only on the actual item track before converting to Bricks.' );
					break;
				}
			}
		}
		if ( preg_match_all( '/class\s*=\s*["\']([^"\']+)["\']/i', $source_html, $class_matches ) ) {
			$seam = [];
			foreach ( $class_matches[1] as $classes ) {
				foreach ( preg_split( '/\s+/', (string) $classes ) as $class ) {
					if ( preg_match( '/^seam-/', $class ) ) {
						$seam[ $class ] = true;
					}
				}
			}
			if ( [] !== $seam ) {
				$missing = [];
				foreach ( array_keys( $seam ) as $class ) {
					if ( ! str_contains( $conversion_text, $class ) ) {
						$missing[] = $class;
					}
				}
				if ( count( $missing ) === count( $seam ) ) {
					$this->add( $findings, 'fail', 'bricks_conversion_lost_seam_classes', 'No source Seam classes are preserved in the Bricks conversion package.' );
				} elseif ( [] !== $missing ) {
					$this->add( $findings, 'warn', 'bricks_conversion_partial_seam_loss', sprintf( 'Some source Seam classes are not visible in the conversion package: %s.', implode( ', ', array_slice( $missing, 0, 12 ) ) ) );
				}
			}
		}
		if ( preg_match_all( '/data-dsa-open-module\s*=\s*["\']([^"\']+)["\']/i', $source_html, $launcher_matches ) ) {
			foreach ( $launcher_matches[1] as $module ) {
				if ( ! str_contains( $conversion_text, 'data-dsa-open-module' ) || ! str_contains( $conversion_text, (string) $module ) ) {
					$this->add( $findings, 'fail', 'bricks_conversion_lost_kiwe_launcher', sprintf( 'Source launcher data-dsa-open-module="%s" was not preserved.', (string) $module ) );
				}
			}
		}
		$attribute_pattern = '/\b(' . implode( '|', array_map( static fn( string $name ): string => preg_quote( $name, '/' ), self::KIWE_CAPABILITY_ATTRIBUTES ) ) . ')(?:\s*=\s*["\']([^"\']*)["\'])?/i';
		if ( preg_match_all( $attribute_pattern, $source_html, $attribute_matches, PREG_SET_ORDER ) ) {
			$seen = [];
			foreach ( $attribute_matches as $match ) {
				$name  = (string) ( $match[1] ?? '' );
				$value = trim( (string) ( $match[2] ?? '' ) );
				$key   = $name . '=' . $value;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				if ( ! str_contains( $conversion_text, $name ) ) {
					$this->add( $findings, 'fail', 'bricks_conversion_lost_kiwe_capability_attribute', sprintf( 'Source Kiwe capability attribute %1$s%2$s was not preserved.', $name, '' !== $value ? '="' . $value . '"' : '' ) );
				} elseif ( '' !== $value && ! str_contains( $conversion_text, $value ) ) {
					$this->add( $findings, 'warn', 'bricks_conversion_kiwe_capability_value_not_visible', sprintf( 'Source Kiwe capability attribute %1$s value "%2$s" is not visible in the conversion package.', $name, $value ) );
				}
			}
		}
		if ( preg_match( '/data-kiwe-query-template\s*=/i', $source_html ) && ! preg_match( '/"query"\s*:|"dynamicIntent"\s*:\s*\[[^\]]+\]/i', $conversion_text ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_query_intent', 'Source has data-kiwe-query-template markers but conversion has no Bricks query settings or fidelity.dynamicIntent.' );
		}
		if ( preg_match( '/data-dsa-surface|data-dsa-screen|data-dsa-dock/i', $conversion_text ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_contains_appshell_markup', 'Bricks conversion JSON must remain page-only and cannot contain AppShell shell markup.' );
		}
		if ( preg_match( '/<script\b|javascript:|on[a-z]+\s*=/i', $conversion_text ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_executable_code', 'Bricks conversion package appears to contain executable script or inline event code.' );
		}
	}

	private function validate_dynamic_tags( array $conversion, array &$findings, array $index, bool $has_graph ): void {
		$text = wp_json_encode( $conversion );
		if ( ! is_string( $text ) || ! preg_match_all( '/\{[A-Za-z_][A-Za-z0-9_.:-]{0,120}\}/', $text, $matches ) ) {
			return;
		}
		$tags = array_values( array_unique( $matches[0] ) );
		if ( [] !== $tags && ! $has_graph ) {
			$this->add( $findings, 'warn', 'bricks_conversion_dynamic_tags_unverified', 'Dynamic tags are present but no Site Graph was supplied.' );
			return;
		}
		foreach ( $tags as $tag ) {
			if ( ! isset( $index['dynamicTags'][ $tag ] ) ) {
				$this->add( $findings, 'warn', 'bricks_conversion_unknown_dynamic_tag', sprintf( 'Dynamic tag "%s" is not listed in Site Graph dynamic tags or common Kiwe tags.', $tag ) );
			}
		}
	}

	private function graph_index( array $site_graph ): array {
		$index = [
			'postTypes'   => [],
			'queryTypes'  => [],
			'dynamicTags' => array_fill_keys( self::COMMON_DYNAMIC_TAGS, true ),
		];
		foreach ( $this->array_value( $site_graph['wordpress'] ?? [], 'postTypes' ) as $post_type ) {
			if ( is_array( $post_type ) && ! empty( $post_type['name'] ) ) {
				$index['postTypes'][ (string) $post_type['name'] ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['customContent'] ?? [], 'postTypes' ) as $post_type ) {
			if ( is_array( $post_type ) && ! empty( $post_type['name'] ) ) {
				$index['postTypes'][ (string) $post_type['name'] ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'queryLoopTypes' ) as $query_type ) {
			if ( is_array( $query_type ) && ! empty( $query_type['objectType'] ) ) {
				$index['queryTypes'][ (string) $query_type['objectType'] ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'dynamicTags' ) as $tag ) {
			$name = is_array( $tag ) ? (string) ( $tag['name'] ?? $tag['tag'] ?? '' ) : (string) $tag;
			$name = $this->normalize_dynamic_tag( $name );
			if ( '' !== $name ) {
				$index['dynamicTags'][ $name ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'kiweDynamicTags' ) as $tag ) {
			$name = $this->normalize_dynamic_tag( (string) $tag );
			if ( '' !== $name ) {
				$index['dynamicTags'][ $name ] = true;
			}
		}
		return $index;
	}

	private function normalize_dynamic_tag( string $tag ): string {
		$tag = trim( $tag );
		if ( '' === $tag ) {
			return '';
		}
		return str_starts_with( $tag, '{' ) ? $tag : '{' . trim( $tag, '{}' ) . '}';
	}

	private function array_value( array $data, string $key ): array {
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : [];
	}

	private function custom_css_text( mixed $value ): string {
		$out = [];
		$this->collect_custom_css_text( $value, $out );
		return implode( "\n", $out );
	}

	private function collect_custom_css_text( mixed $value, array &$out ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $item ) && '' !== trim( $item ) && ( 'customCss' === (string) $key || preg_match( '/^_cssCustom(?::|$)/', (string) $key ) ) ) {
					$out[] = $item;
				}
				if ( is_array( $item ) ) {
					$this->collect_custom_css_text( $item, $out );
				}
			}
		}
	}

	private function count_mappable_css( string $css ): int {
		if ( '' === $css ) {
			return 0;
		}
		preg_match_all( self::MAPPABLE_CSS_PATTERN, $css, $matches );
		return isset( $matches[0] ) ? count( $matches[0] ) : 0;
	}

	private function template_variable_name_findings( array $template ): array {
		$findings = [];
		foreach ( [ 'global_variables', 'globalVariables' ] as $lane ) {
			$variables = isset( $template[ $lane ] ) && is_array( $template[ $lane ] ) ? $template[ $lane ] : [];
			foreach ( $variables as $index => $variable ) {
				if ( ! is_array( $variable ) ) {
					continue;
				}
				$name = trim( (string) ( $variable['name'] ?? '' ) );
				if ( str_starts_with( $name, '--' ) ) {
					$findings[] = [
						'lane'  => $lane,
						'index' => (int) $index,
						'name'  => $name,
						'path'  => '$.' . $lane . '[' . (int) $index . '].name',
					];
				}
				$value = trim( (string) ( $variable['value'] ?? '' ) );
				if ( '' !== $value && false !== strpos( $value, 'var(' ) ) {
					foreach ( $this->extract_css_function_calls( $value, 'var' ) as $call ) {
						$args = $this->split_css_args( $call );
						if ( count( $args ) < 2 ) {
							continue;
						}
						$css_variable = trim( (string) ( $args[0] ?? '' ) );
						if ( ! preg_match( '/^--[a-z][a-z0-9_-]*$/i', $css_variable ) ) {
							continue;
						}
						$findings[] = [
							'lane'     => $lane,
							'index'    => (int) $index,
							'name'     => $name,
							'variable' => $css_variable,
							'value'    => $value,
							'path'     => '$.' . $lane . '[' . (int) $index . '].value',
							'type'     => 'variable-value-has-fallback',
						];
					}
				}
			}
		}
		return $findings;
	}

	private function bricks_implicit_layout_controls( array $items ): array {
		$findings = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$settings = $item['settings'];
			$label    = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$name     = strtolower( (string) ( $item['name'] ?? '' ) );
			$classes  = (string) ( $settings['_cssClasses'] ?? '' );
			$display  = strtolower( (string) ( $settings['_display'] ?? '' ) );
			$is_layout = in_array( $name, [ 'section', 'container', 'block', 'div' ], true );
			$is_rail   = preg_match( '/\bseam-horizontal-rail\b/', $classes ) || $this->setting_has_attribute( $settings, 'data-flow', '/^horizontal-rail$/i' );

			if ( $is_layout && 'flex' === $display && ! array_key_exists( '_direction', $settings ) ) {
				$findings[] = [
					'type'  => 'missing-flex-direction',
					'label' => $label,
					'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings._direction',
				];
			}

			if ( $is_layout && 'grid' === $display ) {
				$has_columns = false;
				foreach ( array_keys( $settings ) as $key ) {
					if ( preg_match( '/^_grid(?:TemplateColumns|AutoColumns)(?::|$)/', (string) $key ) ) {
						$has_columns = true;
						break;
					}
				}
				if ( ! $has_columns ) {
					$findings[] = [
						'type'  => 'missing-grid-columns',
						'label' => $label,
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings._gridTemplateColumns',
					];
				}
			}

			if ( $is_rail ) {
				if ( 'flex' !== $display ) {
					$findings[] = [
						'type'  => 'rail-missing-flex-display',
						'label' => $label,
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings._display',
					];
				}
				if ( 'row' !== strtolower( (string) ( $settings['_direction'] ?? '' ) ) ) {
					$findings[] = [
						'type'  => 'rail-missing-row-direction',
						'label' => $label,
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings._direction',
					];
				}
				if ( ! preg_match( '/(?:auto|scroll)/i', (string) ( $settings['_overflow'] ?? '' ) ) ) {
					$findings[] = [
						'type'  => 'rail-missing-overflow',
						'label' => $label,
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings._overflow',
					];
				}
				if ( ! array_key_exists( '_columnGap', $settings ) && ! array_key_exists( '_gap', $settings ) ) {
					$findings[] = [
						'type'  => 'rail-missing-gap',
						'label' => $label,
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings._columnGap',
					];
				}
			}
		}
		return $findings;
	}

	private function setting_has_attribute( array $settings, string $name, ?string $pattern = null ): bool {
		if ( ! isset( $settings['_attributes'] ) || ! is_array( $settings['_attributes'] ) ) {
			return false;
		}
		$wanted = strtolower( $name );
		foreach ( $settings['_attributes'] as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			if ( strtolower( (string) ( $attribute['name'] ?? '' ) ) !== $wanted ) {
				continue;
			}
			if ( null === $pattern || preg_match( $pattern, (string) ( $attribute['value'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function bricks_runtime_code_elements( array $items ): array {
		$findings = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || 'code' !== strtolower( (string) ( $item['name'] ?? '' ) ) ) {
				continue;
			}
			$settings    = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : [];
			$review_text = wp_json_encode(
				[
					'classes'    => $settings['_cssClasses'] ?? '',
					'attributes' => $settings['_attributes'] ?? [],
					'kiwe'       => $item['kiwe'] ?? [],
				]
			);
			if ( is_string( $review_text ) && preg_match( self::REVIEW_ONLY_CODE_ELEMENT_ALLOWANCE_PATTERN, $review_text ) ) {
				continue;
			}
			$runtime_keys = [];
			foreach ( $settings as $key => $value ) {
				$key = (string) $key;
				if ( ! preg_match( '/^(?:code|css|cssCode|javascriptCode|js|html|php|executeCode)$/i', $key ) ) {
					continue;
				}
				if ( 'executeCode' === $key && true === $value ) {
					$runtime_keys[] = $key;
					continue;
				}
				if ( is_array( $value ) ) {
					if ( [] !== $value ) {
						$runtime_keys[] = $key;
					}
					continue;
				}
				if ( is_object( $value ) ) {
					$runtime_keys[] = $key;
					continue;
				}
				if ( '' !== trim( (string) $value ) ) {
					$runtime_keys[] = $key;
				}
			}
			if ( [] === $runtime_keys ) {
				continue;
			}
			$findings[] = [
				'label' => (string) ( $item['id'] ?? $item['label'] ?? $item['name'] ?? 'item-' . (int) $index ),
				'keys'  => array_values( array_unique( $runtime_keys ) ),
				'path'  => '$.content/header/footer[' . (int) $index . '].settings',
			];
		}
		return $findings;
	}

	private function bricks_compiler_unsafe_controls( array $items ): array {
		$findings = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label               = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$is_semantic_heading = 'heading' === strtolower( (string) ( $item['name'] ?? '' ) ) && preg_match( self::SEMANTIC_HEADING_TAG_PATTERN, (string) ( $item['settings']['tag'] ?? '' ) );
			foreach ( $item['settings'] as $key => $value ) {
				$key = (string) $key;
				if ( preg_match( self::BRICKS_COMPILE_UNSAFE_CONTROL_PATTERN, $key ) ) {
					$findings[] = [
						'type'  => 'unsupported-control',
						'label' => $label,
						'key'   => $key,
						'value' => $value,
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key,
					];
				}
				if ( ( '_typography' === $key || preg_match( '/^_typography:/', $key ) ) && is_array( $value ) ) {
					$font_size = $value['font-size'] ?? $value['fontSize'] ?? $value['font_size'] ?? null;
					if ( $is_semantic_heading && is_string( $font_size ) && preg_match( self::SEMANTIC_HEADING_TYPE_TOKEN_PATTERN, $font_size ) ) {
						$findings[] = [
							'type'  => 'semantic-heading-font-size-lock',
							'label' => $label,
							'key'   => $key,
							'value' => $font_size,
							'tag'   => (string) ( $item['settings']['tag'] ?? '' ),
							'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key . '.font-size',
						];
					}
					$font_family = $value['font-family'] ?? $value['fontFamily'] ?? $value['font_family'] ?? null;
					if ( is_string( $font_family ) && preg_match( self::BRICKS_FONT_FAMILY_TOKEN_PATTERN, $font_family ) ) {
						$findings[] = [
							'type'  => 'font-family-token',
							'label' => $label,
							'key'   => $key,
							'value' => $font_family,
							'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key . '.font-family',
						];
					}
				}
				if ( ( '_background' === $key || preg_match( '/^_background:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_string( $value['color'] ) ) {
					$findings[] = [
						'type'  => 'color-shape',
						'label' => $label,
						'key'   => $key,
						'value' => $value['color'],
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key . '.color',
					];
				}
				if ( ( '_background' === $key || preg_match( '/^_background:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_array( $value['color'] ) && isset( $value['color']['raw'] ) && is_string( $value['color']['raw'] ) && preg_match( '/gradient\(/i', $value['color']['raw'] ) ) {
					$findings[] = [
						'type'  => 'background-gradient-color',
						'label' => $label,
						'key'   => $key,
						'value' => $value['color']['raw'],
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key . '.color.raw',
					];
				}
				if ( ( '_border' === $key || preg_match( '/^_border:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_string( $value['color'] ) ) {
					$findings[] = [
						'type'  => 'color-shape',
						'label' => $label,
						'key'   => $key,
						'value' => $value['color'],
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key . '.color',
					];
				}
				if ( ( '_border' === $key || preg_match( '/^_border:/', $key ) ) && is_array( $value ) && isset( $value['radius'] ) && is_array( $value['radius'] ) ) {
					$invalid_radius_keys = [];
					foreach ( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] as $radius_key ) {
						if ( array_key_exists( $radius_key, $value['radius'] ) ) {
							$invalid_radius_keys[] = $radius_key;
						}
					}
					if ( $invalid_radius_keys ) {
						$findings[] = [
							'type'  => 'radius-shape',
							'label' => $label,
							'key'   => $key,
							'value' => implode( ', ', $invalid_radius_keys ),
							'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key . '.radius',
						];
					}
				}
				if ( ( '_typography' === $key || preg_match( '/^_typography:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_string( $value['color'] ) ) {
					$findings[] = [
						'type'  => 'color-shape',
						'label' => $label,
						'key'   => $key,
						'value' => $value['color'],
						'path'  => '$.content/header/footer/global_classes[' . (int) $index . '].settings.' . $key . '.color',
					];
				}
			}
		}
		return $findings;
	}

	private function count_native_style_controls( array $items ): int {
		$count = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			foreach ( array_keys( $item['settings'] ) as $key ) {
				if ( preg_match( self::NATIVE_STYLE_CONTROL_PATTERN, (string) $key ) && ! preg_match( '/^_cssCustom(?::|$)/', (string) $key ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	private function count_native_style_controls_on_item( array $item ): int {
		if ( ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( array_keys( $item['settings'] ) as $key ) {
			if ( preg_match( self::NATIVE_STYLE_CONTROL_PATTERN, (string) $key ) && ! preg_match( '/^_cssCustom(?::|$)/', (string) $key ) ) {
				++$count;
			}
		}
		return $count;
	}

	private function styled_template_global_classes( array $global_classes ): array {
		$styled = [];
		foreach ( $global_classes as $global_class ) {
			if ( ! is_array( $global_class ) ) {
				continue;
			}
			$controls = $this->count_native_style_controls_on_item( $global_class );
			if ( $controls > 0 ) {
				$styled[] = [
					'id'       => (string) ( $global_class['id'] ?? '' ),
					'name'     => (string) ( $global_class['name'] ?? '' ),
					'controls' => $controls,
				];
			}
		}
		return $styled;
	}

	private function template_editability_stats( array $elements ): array {
		$element_controls    = 0;
		$class_only_elements = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$controls = $this->count_native_style_controls_on_item( $element );
			$element_controls += $controls;
			$classes = isset( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ? $element['settings']['_cssGlobalClasses'] : [];
			if ( 0 === $controls && [] !== $classes ) {
				++$class_only_elements;
			}
		}
		$total = count( $elements );
		return [
			'element_controls'     => $element_controls,
			'class_only_elements'  => $class_only_elements,
			'controls_per_element' => $total > 0 ? $element_controls / $total : 0.0,
			'class_only_ratio'     => $total > 0 ? $class_only_elements / $total : 0.0,
		];
	}

	private function is_collision_safe_template_class_name( string $name ): bool {
		$name = trim( $name );
		if ( '' === $name ) {
			return true;
		}
		if ( in_array( $name, self::TEMPLATE_UPLOAD_GENERIC_CLASS_ALLOWLIST, true ) ) {
			return true;
		}
		return 1 === preg_match( self::TEMPLATE_UPLOAD_SAFE_CLASS_PREFIX_PATTERN, $name );
	}

	private function validate_tokenized_native_lengths( array $items, array &$findings, string $path, array $declared_variables = [] ): void {
		$found = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_untokenized_native_lengths( $item['settings'], $values, $path . '[' . (int) $index . '].settings', false, $declared_variables );
			foreach ( $values as $value ) {
				$value['label'] = $label;
				$found[]        = $value;
			}
		}

		foreach ( array_slice( $found, 0, self::TOKEN_FINDING_LIMIT ) as $item ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_untokenized_native_length',
				sprintf(
					'Bricks native style "%1$s" on "%2$s" uses literal length "%3$s". Framework-mode SEAM Compiler output must follow the Kiwe token ladder for spacing, sizing, radius, type, shadow, transform, and responsive layout controls: use an official var(--kiwe-*)/var(--seam-*) token when the meaning and property domain match; otherwise use a declared project variable; otherwise use a real fluid clamp() only when source responsive states prove different min/max values. Plain values are valid only at the named token definition layer for roles such as fixed primitive, geometry input, content limit, or responsive guard. No-op clamps such as clamp(22px, 22px, 22px) do not count as tokenization.',
					(string) ( $item['path'] ?? '' ),
					(string) ( $item['label'] ?? '' ),
					(string) ( $item['value'] ?? '' )
				),
				(string) ( $item['path'] ?? '' )
			);
		}

		if ( count( $found ) > self::TOKEN_FINDING_LIMIT ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_untokenized_native_length_overflow',
				sprintf( 'Bricks native styles contain %d additional untokenized literal length values beyond the first %d. Fix with official tokens, declared project variables, or real fluid clamps from proven responsive states, then rerun /audit /bricksconversion.', count( $found ) - self::TOKEN_FINDING_LIMIT, self::TOKEN_FINDING_LIMIT ),
				$path
			);
		}
	}

	private function validate_tokenized_native_colors( array $items, array &$findings, string $path ): void {
		$found = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_untokenized_native_colors( $item['settings'], $values, $path . '[' . (int) $index . '].settings' );
			foreach ( $values as $value ) {
				$value['label'] = $label;
				$found[]        = $value;
			}
		}

		foreach ( array_slice( $found, 0, self::COLOR_FINDING_LIMIT ) as $item ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_untokenized_native_color',
				sprintf(
					'Bricks native style "%1$s" on "%2$s" uses direct color literal(s) "%3$s". Framework-mode SEAM Compiler output must be fully token integrated: component colors, backgrounds, gradients, borders, shadows, fills, and local CSS variables must consume bare var(--kiwe-*), var(--seam-*), or declared project variables from the Framework profile/globalVariables. Literal colors are allowed at the Framework/global-variable definition layer, but not as direct component styling, CSS-variable fallbacks, color: #fff, or --pack-bg: #f5b942.',
					(string) ( $item['path'] ?? '' ),
					(string) ( $item['label'] ?? '' ),
					implode( ', ', array_map( 'strval', (array) ( $item['literals'] ?? [] ) ) )
				),
				(string) ( $item['path'] ?? '' )
			);
		}

		if ( count( $found ) > self::COLOR_FINDING_LIMIT ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_untokenized_native_color_overflow',
				sprintf( 'Bricks native styles contain %d additional untokenized direct color values beyond the first %d. Fix with official Kiwe/Seam tokens or declared project variables, then rerun /audit /bricksconversion.', count( $found ) - self::COLOR_FINDING_LIMIT, self::COLOR_FINDING_LIMIT ),
				$path
			);
		}
	}

	private function validate_css_variable_fallbacks( array $items, array &$findings, string $path ): void {
		$found = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_css_variables_with_fallback( $item['settings'], $values, $path . '[' . (int) $index . '].settings' );
			foreach ( $values as $value ) {
				$value['label'] = $label;
				$found[]        = $value;
			}
		}

		foreach ( array_slice( $found, 0, self::CSS_VAR_FINDING_LIMIT ) as $item ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_css_var_has_fallback',
				sprintf(
					'Bricks native style "%1$s" on "%2$s" references "%3$s" with an inline fallback in "%4$s". SeamFlow template render-owner settings must consume bare Framework/project variables only, e.g. var(%3$s). Put the actual value in the paired Kiwe Framework profile / Bricks variable push so missing profile setup fails visibly instead of silently rendering from hidden fallback values.',
					(string) ( $item['path'] ?? '' ),
					(string) ( $item['label'] ?? '' ),
					(string) ( $item['variable'] ?? '' ),
					(string) ( $item['value'] ?? '' )
				),
				(string) ( $item['path'] ?? '' )
			);
		}

		if ( count( $found ) > self::CSS_VAR_FINDING_LIMIT ) {
			$this->add(
				$findings,
				'fail',
				'bricks_conversion_css_var_has_fallback_overflow',
				sprintf( 'Bricks native styles contain %d additional CSS variable references with inline fallbacks beyond the first %d. Remove fallbacks from Bricks render-owner settings and define those values in the paired Framework profile, then rerun /audit /bricksconversion.', count( $found ) - self::CSS_VAR_FINDING_LIMIT, self::CSS_VAR_FINDING_LIMIT ),
				$path
			);
		}
	}

	private function validate_project_variable_framework_proof( array $template, array $items, array &$findings, string $path ): void {
		$uses = [];
		$unknown_reserved = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_native_owned_css_variables( $item['settings'], $values, $path . '[' . (int) $index . '].settings' );
			foreach ( $values as $value ) {
				$variable = $this->normalize_css_variable_name( (string) ( $value['variable'] ?? '' ) );
				if ( '' === $variable || $this->is_official_framework_variable( $variable ) ) {
					continue;
				}
				if ( $this->is_reserved_framework_variable_name( $variable ) ) {
					$value['label']    = $label;
					$value['variable'] = $variable;
					$unknown_reserved[] = $value;
					continue;
				}
				$value['label'] = $label;
				$value['variable'] = $variable;
				$uses[] = $value;
			}
		}

		$unknown_names = array_values( array_unique( array_map( static fn( array $item ): string => (string) ( $item['variable'] ?? '' ), $unknown_reserved ) ) );
		sort( $unknown_names );
		if ( [] !== $unknown_names ) {
			$first_use = $unknown_reserved[0] ?? [];
			$this->add(
				$findings,
				'fail',
				'bricks_template_unknown_framework_variable',
				sprintf(
					'Bricks template uses %1$d reserved-looking Framework variable(s) that are not in the Kiwe universal token registry: %2$s%3$s. Do not invent --kiwe-* or --seam-* variables. Map to an existing official token, declare a collision-safe project variable such as --nc-*, or formally add the new token to Kiwe universal registry before SEAM Compiler validation can pass.',
					count( $unknown_names ),
					implode( ', ', array_slice( $unknown_names, 0, 20 ) ),
					count( $unknown_names ) > 20 ? ', ...' : ''
				),
				(string) ( $first_use['path'] ?? '$.content' )
			);
		}

		$required = array_values( array_unique( array_map( static fn( array $item ): string => (string) ( $item['variable'] ?? '' ), $uses ) ) );
		sort( $required );
		if ( [] === $required ) {
			return;
		}

		$proof = $this->framework_profile_project_variable_names_from_template_metadata( $template );
		$missing = array_values( array_filter( $required, static fn( string $name ): bool => empty( $proof[ $name ] ) ) );
		if ( [] === $missing ) {
			return;
		}

		$template_declared = $this->template_declared_variable_names( $template );
		$template_only = array_values( array_filter( $missing, static fn( string $name ): bool => ! empty( $template_declared[ $name ] ) ) );
		$first_use = $uses[0] ?? [];
		$message = sprintf(
			'Bricks template consumes %1$d project CSS variable(s) in native element controls, but Framework-profile proof is missing for %2$d: %3$s%4$s. ',
			count( $required ),
			count( $missing ),
			implode( ', ', array_slice( $missing, 0, 20 ) ),
			count( $missing ) > 20 ? ', ...' : ''
		);
		if ( [] !== $template_only ) {
			$message .= sprintf(
				'These variable(s) appear only in the template globalVariables lane (%1$s%2$s), but Bricks My Templates import does not reliably install template-local globalVariables into the site variable manager. ',
				implode( ', ', array_slice( $template_only, 0, 12 ) ),
				count( $template_only ) > 12 ? ', ...' : ''
			);
		}
		$message .= 'SEAM Compiler must pair project variables with Kiwe > Framework profile output/push proof, or use only official --kiwe-/--seam- variables already installed by the Framework.';

		$this->add(
			$findings,
			'fail',
			'bricks_template_missing_framework_project_variable_proof',
			$message,
			(string) ( $first_use['path'] ?? '$.content' )
		);
	}

	private function normalize_css_variable_name( string $name ): string {
		$clean = preg_replace( '/^--/', '', trim( $name ) );
		if ( ! is_string( $clean ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/i', $clean ) ) {
			return '';
		}
		return '--' . $clean;
	}

	private function is_reserved_framework_variable_name( string $name ): bool {
		return (bool) preg_match( '/^--(?:kiwe|seam)-/i', $name );
	}

	private function is_official_framework_variable( string $name ): bool {
		$normalized = strtolower( $this->normalize_css_variable_name( $name ) );
		if ( '' === $normalized ) {
			return false;
		}
		$official = $this->official_framework_variable_names();
		return ! empty( $official[ $normalized ] );
	}

	private function official_framework_variable_names(): array {
		static $official = null;

		if ( is_array( $official ) ) {
			return $official;
		}

		$official = [];
		if ( class_exists( '\DSA\Design\Seam_Token_Service' ) && method_exists( '\DSA\Design\Seam_Token_Service', 'universal_tokens' ) ) {
			foreach ( \DSA\Design\Seam_Token_Service::universal_tokens() as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}
				$css_var = strtolower( $this->normalize_css_variable_name( (string) ( $token['cssVar'] ?? '' ) ) );
				if ( '' !== $css_var ) {
					$official[ $css_var ] = true;
				}
				$alias = strtolower( $this->normalize_css_variable_name( (string) ( $token['seamAlias'] ?? '' ) ) );
				if ( '' !== $alias && 0 === strpos( $alias, '--seam-' ) ) {
					$official[ $alias ] = true;
				}
			}
		}

		return $official;
	}

	private function uses_official_framework_variable( string $value ): bool {
		if ( ! preg_match_all( '/var\(\s*(--[a-z][a-z0-9_-]*)/i', $value, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $name ) {
			if ( $this->is_official_framework_variable( (string) $name ) ) {
				return true;
			}
		}

		return false;
	}

	private function template_declared_variable_names( array $template ): array {
		$names = [];
		foreach ( [ 'global_variables', 'globalVariables' ] as $lane ) {
			foreach ( isset( $template[ $lane ] ) && is_array( $template[ $lane ] ) ? $template[ $lane ] : [] as $variable ) {
				if ( ! is_array( $variable ) ) {
					continue;
				}
				$name = $this->normalize_css_variable_name( (string) ( $variable['name'] ?? $variable['variable'] ?? $variable['key'] ?? $variable['id'] ?? '' ) );
				if ( '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}
		return $names;
	}

	private function framework_profile_project_variable_names_from_template_metadata( array $template ): array {
		$names = [];
		$framework = [];
		if ( isset( $template['kiwe']['frameworkProfile'] ) && is_array( $template['kiwe']['frameworkProfile'] ) ) {
			$framework = $template['kiwe']['frameworkProfile'];
		} elseif ( isset( $template['frameworkProfile'] ) && is_array( $template['frameworkProfile'] ) ) {
			$framework = $template['frameworkProfile'];
		}

		foreach ( [ 'projectVariables', 'variables', 'requiredVariables' ] as $key ) {
			foreach ( isset( $framework[ $key ] ) && is_array( $framework[ $key ] ) ? $framework[ $key ] : [] as $variable ) {
				$name = '';
				if ( is_array( $variable ) ) {
					$name = $this->normalize_css_variable_name( (string) ( $variable['name'] ?? $variable['variable'] ?? $variable['key'] ?? $variable['id'] ?? '' ) );
				} elseif ( is_scalar( $variable ) ) {
					$name = $this->normalize_css_variable_name( (string) $variable );
				}
				if ( '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}

		return $names;
	}

	private function collect_declared_css_variables( mixed $value, array &$out = [] ): array {
		if ( is_array( $value ) ) {
			foreach ( [ 'name', 'variable', 'key', 'id' ] as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
					$clean = preg_replace( '/^--/', '', trim( $value[ $key ] ) );
					if ( is_string( $clean ) && preg_match( '/^(?:kiwe|seam|[a-z][a-z0-9]*)-[a-z0-9][a-z0-9-]*$/i', $clean ) ) {
						$out[ $clean ]       = true;
						$out[ '--' . $clean ] = true;
					}
				}
			}
			foreach ( $value as $item ) {
				$this->collect_declared_css_variables( $item, $out );
			}
		}

		return $out;
	}

	private function uses_declared_project_variable( string $value, array $declared_variables ): bool {
		if ( ! preg_match_all( '/var\(\s*--([a-z][a-z0-9]*-[a-z0-9][a-z0-9-]*)/i', $value, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $name ) {
			$name = (string) $name;
			if ( $this->is_official_framework_variable( '--' . $name ) || ! empty( $declared_variables[ $name ] ) || ! empty( $declared_variables[ '--' . $name ] ) ) {
				return true;
			}
		}

		return false;
	}

	private function extract_css_function_ranges( string $value, string $function_name ): array {
		$text   = $value;
		$lower  = strtolower( $text );
		$needle = strtolower( $function_name ) . '(';
		$ranges = [];
		$index  = 0;
		$len    = strlen( $text );

		while ( false !== ( $index = strpos( $lower, $needle, $index ) ) ) {
			$depth = 0;
			$end   = -1;

			for ( $i = $index; $i < $len; ++$i ) {
				$char = $text[ $i ];
				if ( '(' === $char ) {
					++$depth;
				} elseif ( ')' === $char ) {
					--$depth;
					if ( 0 === $depth ) {
						$end = $i;
						break;
					}
				}
			}

			if ( -1 === $end ) {
				break;
			}

			$ranges[] = [ 'start' => $index, 'end' => $end ];
			$index    = $end + 1;
		}

		return $ranges;
	}

	private function offset_inside_ranges( int $offset, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) ) {
				continue;
			}
			$start = (int) ( $range['start'] ?? -1 );
			$end   = (int) ( $range['end'] ?? -1 );
			if ( $offset >= $start && $offset <= $end ) {
				return true;
			}
		}

		return false;
	}

	private function collect_direct_color_literals( string $value ): array {
		$var_ranges = $this->extract_css_function_ranges( $value, 'var' );
		if ( ! preg_match_all( self::COLOR_LITERAL_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$literals = [];
		foreach ( $matches[0] as $match ) {
			if ( ! is_array( $match ) || ! isset( $match[0], $match[1] ) ) {
				continue;
			}
			$literal = trim( (string) $match[0] );
			if ( '' === $literal || $this->offset_inside_ranges( (int) $match[1], $var_ranges ) ) {
				continue;
			}
			$literals[] = $literal;
		}

		return $literals;
	}

	private function color_owned_child( bool $parent_owned, string $key ): bool {
		return $parent_owned || preg_match( self::TOKEN_OWNED_COLOR_CONTROL_PATTERN, $key ) || preg_match( self::TOKEN_OWNED_COLOR_NESTED_KEY_PATTERN, $key );
	}

	private function collect_untokenized_native_colors( mixed $value, array &$out, string $path, bool $parent_owned = false ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$this->collect_untokenized_native_colors( $item, $out, $path . '.' . (string) $key, $this->color_owned_child( $parent_owned, (string) $key ) );
			}
			return;
		}

		if ( ! $parent_owned || ! is_string( $value ) ) {
			return;
		}

		$literals = $this->collect_direct_color_literals( $value );
		if ( [] !== $literals ) {
			$out[] = [
				'path'     => $path,
				'value'    => $value,
				'literals' => $literals,
			];
		}
	}

	private function collect_css_variables_with_fallback( mixed $value, array &$out, string $path, bool $parent_owned = false ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$key_string = (string) $key;
				$owned      = $parent_owned
					|| preg_match( self::NATIVE_STYLE_CONTROL_PATTERN, $key_string )
					|| preg_match( self::TOKEN_OWNED_NESTED_KEY_PATTERN, $key_string )
					|| preg_match( self::TOKEN_OWNED_COLOR_NESTED_KEY_PATTERN, $key_string );
				$this->collect_css_variables_with_fallback( $item, $out, $path . '.' . $key_string, (bool) $owned );
			}
			return;
		}

		if ( ! $parent_owned || ! is_string( $value ) || false === strpos( $value, 'var(' ) ) {
			return;
		}

		foreach ( $this->extract_css_function_calls( $value, 'var' ) as $call ) {
			$args = $this->split_css_args( $call );
			if ( count( $args ) < 2 ) {
				continue;
			}
			$variable = trim( (string) ( $args[0] ?? '' ) );
			if ( ! preg_match( '/^--[a-z][a-z0-9_-]*$/i', $variable ) ) {
				continue;
			}
			$out[] = [
				'path'     => $path,
				'value'    => $value,
				'variable' => $variable,
			];
		}
	}

	private function collect_native_owned_css_variables( mixed $value, array &$out, string $path, bool $parent_owned = false ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$key_string = (string) $key;
				$owned      = $parent_owned
					|| preg_match( self::NATIVE_STYLE_CONTROL_PATTERN, $key_string )
					|| preg_match( self::TOKEN_OWNED_NESTED_KEY_PATTERN, $key_string )
					|| preg_match( self::TOKEN_OWNED_COLOR_NESTED_KEY_PATTERN, $key_string );
				$this->collect_native_owned_css_variables( $item, $out, $path . '.' . $key_string, (bool) $owned );
			}
			return;
		}

		if ( ! $parent_owned || ! is_string( $value ) || false === strpos( $value, 'var(' ) ) {
			return;
		}

		foreach ( $this->extract_css_function_calls( $value, 'var' ) as $call ) {
			$args     = $this->split_css_args( $call );
			$variable = $this->normalize_css_variable_name( (string) ( $args[0] ?? '' ) );
			if ( '' === $variable ) {
				continue;
			}
			$out[] = [
				'path'        => $path,
				'value'       => $value,
				'variable'    => $variable,
				'hasFallback' => count( $args ) >= 2,
			];
		}
	}

	private function extract_css_function_calls( string $value, string $function_name ): array {
		$text   = $value;
		$lower  = strtolower( $text );
		$needle = strtolower( $function_name ) . '(';
		$calls  = [];
		$index  = 0;

		while ( false !== ( $index = strpos( $lower, $needle, $index ) ) ) {
			$depth = 0;
			$end   = -1;
			$len   = strlen( $text );

			for ( $i = $index; $i < $len; ++$i ) {
				$char = $text[ $i ];
				if ( '(' === $char ) {
					++$depth;
				} elseif ( ')' === $char ) {
					--$depth;
					if ( 0 === $depth ) {
						$end = $i;
						break;
					}
				}
			}

			if ( -1 === $end ) {
				break;
			}

			$calls[] = substr( $text, $index + strlen( $needle ), $end - $index - strlen( $needle ) );
			$index   = $end + 1;
		}

		return $calls;
	}

	private function split_css_args( string $value ): array {
		$args  = [];
		$depth = 0;
		$start = 0;
		$len   = strlen( $value );

		for ( $i = 0; $i < $len; ++$i ) {
			$char = $value[ $i ];
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$args[] = trim( substr( $value, $start, $i - $start ) );
				$start  = $i + 1;
			}
		}

		$args[] = trim( substr( $value, $start ) );
		return $args;
	}

	private function parse_simple_css_length( string $value ): ?array {
		if ( ! preg_match( '/^(-?(?:\d+|\d*\.\d+))(px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)$/i', trim( $value ), $match ) ) {
			return null;
		}

		return [
			'value' => (float) $match[1],
			'unit'  => strtolower( (string) $match[2] ),
		];
	}

	private function has_valid_kiwe_fluid_clamp( string $value ): bool {
		foreach ( $this->extract_css_function_calls( $value, 'clamp' ) as $call ) {
			$args = $this->split_css_args( $call );
			if ( 3 !== count( $args ) ) {
				continue;
			}

			$min = $this->parse_simple_css_length( (string) $args[0] );
			$max = $this->parse_simple_css_length( (string) $args[2] );
			if ( null === $min || null === $max || $min['unit'] !== $max['unit'] || (float) $min['value'] === (float) $max['value'] ) {
				continue;
			}

			$unit      = preg_quote( (string) $min['unit'], '/' );
			$preferred = trim( (string) $args[1] );
			if ( preg_match( '/^calc\(\s*-?(?:\d+|\d*\.\d+)' . $unit . '\s*[+-]\s*-?(?:\d+|\d*\.\d+)vw\s*\)$/i', $preferred ) ) {
				return true;
			}
		}

		return false;
	}

	private function has_noop_clamp( string $value ): bool {
		if ( preg_match( self::SELF_CLAMP_LENGTH_PATTERN, $value ) ) {
			return true;
		}

		foreach ( $this->extract_css_function_calls( $value, 'clamp' ) as $call ) {
			$args = $this->split_css_args( $call );
			if ( 3 !== count( $args ) ) {
				continue;
			}

			if ( $args[0] === $args[1] && $args[1] === $args[2] ) {
				return true;
			}

			$min = $this->parse_simple_css_length( (string) $args[0] );
			$max = $this->parse_simple_css_length( (string) $args[2] );
			if ( null !== $min && null !== $max && $min['unit'] === $max['unit'] && (float) $min['value'] === (float) $max['value'] ) {
				return true;
			}
		}

		return false;
	}

	private function has_tokenized_length( string $value, array $declared_variables ): bool {
		if ( $this->has_noop_clamp( $value ) ) {
			return false;
		}

		return $this->uses_official_framework_variable( $value )
			|| $this->uses_declared_project_variable( $value, $declared_variables )
			|| $this->has_valid_kiwe_fluid_clamp( $value );
	}

	private function collect_untokenized_native_lengths( mixed $value, array &$out, string $path, bool $parent_owned = false, array $declared_variables = [] ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$owned = $parent_owned || preg_match( self::TOKEN_OWNED_NATIVE_CONTROL_PATTERN, (string) $key ) || preg_match( self::TOKEN_OWNED_NESTED_KEY_PATTERN, (string) $key );
				$this->collect_untokenized_native_lengths( $item, $out, $path . '.' . (string) $key, (bool) $owned, $declared_variables );
			}
			return;
		}

		if ( ! $parent_owned || ! is_string( $value ) ) {
			return;
		}

		if ( preg_match( self::LITERAL_LENGTH_PATTERN, $value ) && ! $this->has_tokenized_length( $value, $declared_variables ) ) {
			$out[] = [
				'path'  => $path,
				'value' => $value,
			];
		}
	}

	private function add( array &$findings, string $level, string $code, string $message, string $path = '' ): void {
		$findings[] = [
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
			'path'    => $path,
		];
	}

	private function has_level( array $findings, string $level ): bool {
		foreach ( $findings as $finding ) {
			if ( $level === (string) ( $finding['level'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function counts( array $findings ): array {
		$counts = [ 'fail' => 0, 'warn' => 0, 'info' => 0 ];
		foreach ( $findings as $finding ) {
			$level = (string) ( $finding['level'] ?? 'info' );
			if ( isset( $counts[ $level ] ) ) {
				$counts[ $level ]++;
			}
		}
		return $counts;
	}
}
