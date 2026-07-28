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
	private const TEMPLATE_UPLOAD_CUSTOM_CSS_BYTES = 2500;
	private const TEMPLATE_UPLOAD_MAPPABLE_CSS_MIN = 12;
	private const LARGE_TEMPLATE_ELEMENT_COUNT     = 180;
	private const MIN_NATIVE_STYLE_CONTROLS        = 60;
	private const TOKEN_FINDING_LIMIT              = 40;

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
		$this->validate_tokenized_native_lengths(
			array_merge(
				isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [],
				isset( $conversion['globalClasses'] ) && is_array( $conversion['globalClasses'] ) ? $conversion['globalClasses'] : []
			),
			$findings,
			'$.elements/globalClasses',
			$this->collect_declared_css_variables( $conversion )
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
				$this->add( $findings, 'fail', 'bricks_conversion_forbidden_source_lane', '/convert /bricks source must be the page artifact only. Do not convert combined-preview, appshell-theme, DSA/AppShell preview markup, theme-package.json, or theme.css into Bricks.', '$.source' );
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

		$native_controls = $this->count_native_style_controls( array_merge( $elements, isset( $template['global_classes'] ) && is_array( $template['global_classes'] ) ? $template['global_classes'] : [] ) );
		$this->validate_tokenized_native_lengths(
			array_merge(
				$elements,
				isset( $template['global_classes'] ) && is_array( $template['global_classes'] ) ? $template['global_classes'] : [],
				isset( $template['globalClasses'] ) && is_array( $template['globalClasses'] ) ? $template['globalClasses'] : []
			),
			$findings,
			'$.content/header/footer/global_classes',
			$this->collect_declared_css_variables( $template )
		);
		if ( count( $elements ) >= self::LARGE_TEMPLATE_ELEMENT_COUNT && $native_controls < self::MIN_NATIVE_STYLE_CONTROLS ) {
			$this->add( $findings, 'fail', 'bricks_template_not_native_editable_enough', sprintf( 'Large Bricks template export has %1$d elements but only %2$d native style/layout controls. Full-page template uploads must preserve editable Bricks controls instead of relying on source/page CSS that may not follow insertion.', count( $elements ), $native_controls ), '$.content' );
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
					'Bricks native style "%1$s" on "%2$s" uses literal length "%3$s". /convert /bricks outputs must follow the Kiwe token ladder for spacing, sizing, radius, type, shadow, transform, and responsive layout controls: use an official var(--kiwe-*)/var(--seam-*) token when the meaning and property domain match; otherwise use a declared project variable; otherwise use a real fluid clamp() only when source responsive states prove different min/max values. Plain values are valid only at the named token definition layer for roles such as fixed primitive, geometry input, content limit, or responsive guard. No-op clamps such as clamp(22px, 22px, 22px) do not count as tokenization.',
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
			if ( preg_match( '/^(?:kiwe|seam)-/i', $name ) || ! empty( $declared_variables[ $name ] ) || ! empty( $declared_variables[ '--' . $name ] ) ) {
				return true;
			}
		}

		return false;
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

		return (bool) preg_match( self::OFFICIAL_TOKEN_VAR_PATTERN, $value )
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
