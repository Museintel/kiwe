<?php

namespace DSA\AI;

use DSA\Design\Seam_Token_Service;
use DSA\Settings;
use DSA\Theme\Theme_Package_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Staging_Execution_Service {
	private const MAX_HTML_BYTES = 450000;
	private const MAX_CSS_BYTES  = 180000;
	private const MAX_BRICKS_JSON_BYTES = 1800000;
	private const MAX_OPS        = 12;
	private const BRICKS_SETTING_OPTIONS = [
		'bricks_global_settings',
		'bricks_theme_styles',
		'bricks_global_classes',
		'bricks_global_variables',
	];

	public function __construct( private ?Settings $settings = null ) {}

	public function execute( array $request, array $context = [], array $stage = [] ): array {
		$operations = isset( $request['operations'] ) && is_array( $request['operations'] ) ? array_slice( $request['operations'], 0, self::MAX_OPS ) : [];
		$types      = array_map( fn( $operation ): string => is_array( $operation ) ? $this->operation_type( $operation ) : '', $operations );
		$created_at = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$id         = 'staging-exec-' . substr( hash( 'sha256', wp_json_encode( $request ) . '|' . $created_at ), 0, 16 );
		$blockers   = $this->global_blockers( $request, $operations, $stage );
		$results    = [];

		if ( [] === $blockers ) {
			foreach ( $operations as $operation ) {
				$results[] = is_array( $operation ) ? $this->execute_operation( $operation, $id ) : $this->failure( 'invalid_operation', 'Operation must be an object.' );
			}
		}

		$failed = array_values( array_filter( $results, static fn( array $result ): bool => empty( $result['ok'] ) ) );
		$raw_bricks_written = (bool) array_filter( $results, static fn( array $result ): bool => ! empty( $result['rawBricksMetaWritten'] ) );

		return [
			'schema'                  => 'kiwe.staging-execution-result.v1',
			'id'                      => $id,
			'createdAt'               => $created_at,
			'createdBy'               => isset( $context['userId'] ) ? absint( $context['userId'] ) : 0,
			'apiKeyId'                => sanitize_text_field( (string) ( $context['apiKeyId'] ?? '' ) ),
			'stageId'                 => sanitize_text_field( (string) ( $stage['id'] ?? '' ) ),
			'status'                  => [] === $blockers && [] === $failed ? 'staging-execution-complete' : ( [] === $blockers ? 'staging-execution-partial' : 'staging-execution-blocked' ),
			'stagingOnly'             => true,
			'actualMutationExecuted'  => [] === $blockers && [] !== $results,
			'actualPublishExecuted'   => [] === $blockers && (bool) array_filter( $results, static fn( array $result ): bool => ! empty( $result['published'] ) ),
			'mutatesWooCommerce'      => [] === $blockers && $this->has_woocommerce_mutation( $types ),
			'runsCheckoutCartAuth'    => [] === $blockers && $this->has_runtime_operation( $types ),
			'rawBricksMetaWritten'    => [] === $blockers && $raw_bricks_written,
			'blockers'                => $blockers,
			'operationsRequested'     => count( $operations ),
			'results'                 => $results,
			'postExecutionChecklist'  => [
				'Fetch /ai/site-inspection and confirm created IDs.',
				'Open created staging page URLs on desktop/tablet/mobile.',
				'Open Kiwe dock screens and header launchers on created pages.',
				'If Bricks HTML/CSS conversion ran, inspect the generated Bricks page/template JSON, CSS page settings, and frontend render.',
				'If Woo/cart/checkout/auth/raw Bricks operations ran, review returned IDs/hashes and rollback metadata before any next mutation.',
			],
		];
	}

	private function global_blockers( array $request, array $operations, array $stage ): array {
		$blockers = [];
		if ( empty( $request['confirmControlledStagingExecution'] ) ) {
			$blockers[] = 'confirmControlledStagingExecution must be true.';
		}
		if ( empty( $request['stagingSiteConfirmed'] ) ) {
			$blockers[] = 'stagingSiteConfirmed must be true.';
		}
		if ( ! $this->is_likely_staging() && empty( $request['allowCurrentHostAsStaging'] ) ) {
			$blockers[] = 'Current host does not look like staging. Set allowCurrentHostAsStaging only after confirming this is not production.';
		}
		if ( [] === $operations ) {
			$blockers[] = 'At least one operation is required.';
		}
		if ( ! empty( $stage ) ) {
			if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
				$blockers[] = 'Stage schema is invalid.';
			}
			if ( ! isset( $stage['bricksControlledAdapter'] ) || ! is_array( $stage['bricksControlledAdapter'] ) ) {
				$blockers[] = 'Stage is missing a Bricks controlled adapter artifact.';
			}
		}

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$type = $this->operation_type( $operation );
			if ( $this->has_woocommerce_mutation( [ $type ] ) && empty( $request['confirmWooCommerceMutation'] ) ) {
				$blockers[] = sprintf( 'Operation type %s requires confirmWooCommerceMutation true.', $type );
			}
			if ( $this->has_runtime_operation( [ $type ] ) && empty( $request['confirmRuntimeExecution'] ) ) {
				$blockers[] = sprintf( 'Operation type %s requires confirmRuntimeExecution true.', $type );
			}
			if ( 'auth.run' === $type && empty( $request['confirmAuthRuntime'] ) && in_array( sanitize_key( (string) ( $operation['action'] ?? 'probe' ) ), [ 'create_test_user', 'delete_test_user' ], true ) ) {
				$blockers[] = 'auth.run user creation/deletion requires confirmAuthRuntime true.';
			}
			if ( in_array( $type, [ 'bricks.raw-meta-write', 'bricks.page.from-html', 'bricks.template.from-html' ], true ) && empty( $request['confirmRawBricksJsonWrite'] ) ) {
				$blockers[] = sprintf( '%s requires confirmRawBricksJsonWrite true.', $type );
			}
		}

		return array_values( array_unique( $blockers ) );
	}

	private function execute_operation( array $operation, string $execution_id ): array {
		$type = $this->operation_type( $operation );

		if ( 'wordpress.page.upsert' === $type ) {
			return $this->upsert_post( $operation, 'page', $execution_id );
		}

		if ( 'wordpress.post.upsert' === $type ) {
			return $this->upsert_post( $operation, 'post', $execution_id );
		}

		if ( 'bricks.page.from-html' === $type ) {
			return $this->upsert_bricks_page_from_html( $operation, $execution_id );
		}

		if ( 'bricks.template.from-html' === $type ) {
			return $this->upsert_bricks_template_from_html( $operation, $execution_id );
		}

		if ( 'bricks.template.create' === $type || 'bricks.template.upsert' === $type ) {
			return $this->upsert_bricks_template( $operation, $execution_id );
		}

		if ( 'bricks.settings.patch' === $type ) {
			return $this->patch_bricks_settings( $operation, $execution_id );
		}

		if ( 'kiwe.framework.push-bricks' === $type ) {
			return $this->push_kiwe_framework_to_bricks( $operation, $execution_id );
		}

		if ( 'kiwe.framework-profile.apply' === $type ) {
			return $this->apply_kiwe_framework_profile( $operation, $execution_id );
		}

		if ( 'kiwe.theme-package.install-activate' === $type ) {
			return $this->install_activate_theme( $operation );
		}

		$controlled_mutations = new Controlled_Mutation_Service( $this->settings );
		if ( $controlled_mutations->supports( $type ) ) {
			return $controlled_mutations->execute( $operation, $type, $execution_id );
		}

		return $this->failure( 'unsupported_operation', sprintf( 'Unsupported staging operation type: %s', $type ) );
	}

	private function operation_type( array $operation ): string {
		return strtolower( preg_replace( '/[^a-z0-9._-]+/', '', (string) ( $operation['type'] ?? '' ) ) );
	}

	private function has_woocommerce_mutation( array $types ): bool {
		foreach ( $types as $type ) {
			if ( str_starts_with( $type, 'woocommerce.' ) || 'checkout.run' === $type ) {
				return true;
			}
		}

		return false;
	}

	private function has_runtime_operation( array $types ): bool {
		foreach ( $types as $type ) {
			if ( in_array( $type, [ 'cart.run', 'checkout.run', 'auth.run' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	private function upsert_post( array $operation, string $post_type, string $execution_id ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return $this->failure( 'post_type_missing', sprintf( 'Post type %s is not registered.', $post_type ) );
		}
		$html = (string) ( $operation['html'] ?? $operation['bricksPasteHtml'] ?? '' );
		if ( '' === trim( $html ) ) {
			return $this->failure( 'missing_html', 'Page/post operation requires html or bricksPasteHtml.' );
		}
		$css = (string) ( $operation['css'] ?? '' );
		if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
			return $this->failure( 'html_too_large', 'HTML exceeds staging executor size budget.' );
		}
		if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
			return $this->failure( 'css_too_large', 'CSS exceeds staging executor size budget.' );
		}

		$sanitized_content = $this->sanitize_staging_html( $html, $css );

		$post_id = absint( $operation['postId'] ?? 0 );
		$status  = $this->post_status( $operation );
		$args    = [
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => sanitize_text_field( (string) ( $operation['title'] ?? 'Kiwe staging page' ) ),
			'post_name'    => sanitize_title( (string) ( $operation['slug'] ?? '' ) ),
			'post_content' => $sanitized_content,
			'post_excerpt' => sanitize_text_field( (string) ( $operation['excerpt'] ?? '' ) ),
			'meta_input'   => [
				'_kiwe_ai_staging_execution' => $execution_id,
				'_kiwe_ai_source_hash'       => hash( 'sha256', $html . "\n/* css */\n" . $css ),
				'_kiwe_bricks_paste_html'    => $sanitized_content,
			],
		];

		if ( $post_id ) {
			if ( get_post_type( $post_id ) !== $post_type ) {
				return $this->failure( 'target_type_mismatch', 'Existing target post type does not match operation.' );
			}
			if ( empty( $operation['allowUpdate'] ) ) {
				return $this->failure( 'update_not_allowed', 'Updating an existing post requires allowUpdate true.' );
			}
			$args['ID'] = $post_id;
			$result = wp_update_post( wp_slash( $args ), true );
		} else {
			$result = wp_insert_post( wp_slash( $args ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $this->failure( 'wp_post_error', $result->get_error_message() );
		}

		$post_id = absint( $result );

		return [
			'ok'        => true,
			'type'      => $operation['type'] ?? '',
			'postId'    => $post_id,
			'postType'  => $post_type,
			'status'    => get_post_status( $post_id ),
			'url'       => 'publish' === get_post_status( $post_id ) ? esc_url_raw( get_permalink( $post_id ) ) : '',
			'editUrl'   => esc_url_raw( get_edit_post_link( $post_id, 'raw' ) ?: '' ),
			'published' => 'publish' === get_post_status( $post_id ),
			'storedBricksPasteHtml' => true,
		];
	}

	private function upsert_bricks_template( array $operation, string $execution_id ): array {
		$post_type = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		if ( ! post_type_exists( $post_type ) ) {
			return $this->failure( 'bricks_template_missing', 'Bricks template post type is not registered.' );
		}

		$result = $this->upsert_post( array_merge( $operation, [ 'type' => 'wordpress.page.upsert' ] ), $post_type, $execution_id );
		if ( empty( $result['ok'] ) ) {
			return $result;
		}

		$post_id      = absint( $result['postId'] ?? 0 );
		$type_key     = defined( 'BRICKS_DB_TEMPLATE_TYPE' ) ? BRICKS_DB_TEMPLATE_TYPE : '_bricks_template_type';
		$settings_key = defined( 'BRICKS_DB_TEMPLATE_SETTINGS' ) ? BRICKS_DB_TEMPLATE_SETTINGS : '_bricks_template_settings';
		$template_type = sanitize_key( (string) ( $operation['templateType'] ?? 'content' ) );
		update_post_meta( $post_id, $type_key, $template_type );
		if ( isset( $operation['templateSettings'] ) && is_array( $operation['templateSettings'] ) ) {
			update_post_meta( $post_id, $settings_key, $this->sanitize_template_settings( $operation['templateSettings'] ) );
		}

		$result['postType']      = $post_type;
		$result['templateType']  = $template_type;
		$result['bricksTemplate'] = true;
		$this->flush_bricks_template_cache( $post_id );

		return $result;
	}

	private function upsert_bricks_page_from_html( array $operation, string $execution_id ): array {
		$result = $this->upsert_post(
			array_merge(
				$operation,
				[
					'type'           => 'wordpress.page.upsert',
					'html'           => $this->bricks_managed_placeholder(),
					'bricksPasteHtml' => $this->bricks_managed_placeholder(),
					'css'            => '',
				]
			),
			'page',
			$execution_id
		);
		if ( empty( $result['ok'] ) ) {
			return $result;
		}

		$conversion = $this->apply_bricks_conversion( absint( $result['postId'] ?? 0 ), $operation, $execution_id );
		if ( empty( $conversion['ok'] ) ) {
			return array_merge( $result, $conversion, [ 'ok' => false ] );
		}

		return array_merge( $result, $conversion, [ 'bricksPage' => true, 'storedBricksPasteHtml' => false, 'visiblePostContent' => 'kiwe-managed-placeholder' ] );
	}

	private function upsert_bricks_template_from_html( array $operation, string $execution_id ): array {
		$result = $this->upsert_bricks_template(
			array_merge(
				$operation,
				[
					'type'           => 'bricks.template.upsert',
					'html'           => $this->bricks_managed_placeholder(),
					'bricksPasteHtml' => $this->bricks_managed_placeholder(),
					'css'            => '',
				]
			),
			$execution_id
		);
		if ( empty( $result['ok'] ) ) {
			return $result;
		}

		$conversion = $this->apply_bricks_conversion( absint( $result['postId'] ?? 0 ), $operation, $execution_id );
		if ( empty( $conversion['ok'] ) ) {
			return array_merge( $result, $conversion, [ 'ok' => false ] );
		}

		return array_merge( $result, $conversion, [ 'bricksTemplate' => true, 'storedBricksPasteHtml' => false, 'visiblePostContent' => 'kiwe-managed-placeholder' ] );
	}

	private function apply_bricks_conversion( int $post_id, array $operation, string $execution_id ): array {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $this->failure( 'target_post_missing', 'Bricks conversion target post was not found.' );
		}

		$html = (string) ( $operation['html'] ?? $operation['bricksPasteHtml'] ?? '' );
		$css  = (string) ( $operation['css'] ?? '' );
		if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
			return $this->failure( 'html_too_large', 'HTML exceeds staging executor size budget.' );
		}
		if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
			return $this->failure( 'css_too_large', 'CSS exceeds staging executor size budget.' );
		}

		$converter  = new Bricks_Html_Css_Converter_Service();
		$conversion = $converter->convert(
			$html,
			$css,
			[
				'createGlobalClasses' => ! empty( $operation['createGlobalClasses'] ),
				'extractVariables'    => ! empty( $operation['extractVariables'] ),
				'pageSettings'        => isset( $operation['pageSettings'] ) && is_array( $operation['pageSettings'] ) ? $operation['pageSettings'] : [],
			]
		);

		if ( empty( $conversion['success'] ) || empty( $conversion['elements'] ) || ! is_array( $conversion['elements'] ) ) {
			return [
				'ok'       => false,
				'code'     => 'bricks_conversion_failed',
				'message'  => 'HTML/CSS could not be converted to Bricks elements.',
				'warnings' => isset( $conversion['warnings'] ) && is_array( $conversion['warnings'] ) ? $conversion['warnings'] : [],
				'errors'   => isset( $conversion['errors'] ) && is_array( $conversion['errors'] ) ? $conversion['errors'] : [],
			];
		}

		$elements_json = wp_json_encode( $conversion['elements'] );
		if ( ! is_string( $elements_json ) || strlen( $elements_json ) > self::MAX_BRICKS_JSON_BYTES ) {
			return $this->failure( 'bricks_json_too_large', 'Converted Bricks element JSON exceeds staging safety budget.' );
		}

		$content_key  = defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';
		$settings_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$mode_key     = '_bricks_editor_mode';
		$previous = [
			$content_key  => get_post_meta( $post_id, $content_key, true ),
			$settings_key => get_post_meta( $post_id, $settings_key, true ),
			$mode_key     => get_post_meta( $post_id, $mode_key, true ),
		];
		update_post_meta( $post_id, '_kiwe_ai_bricks_conversion_backup_' . substr( $execution_id, -12 ), $previous );

		update_post_meta( $post_id, $content_key, $conversion['elements'] );
		$written_elements = get_post_meta( $post_id, $content_key, true );
		if ( ! is_array( $written_elements ) || count( $written_elements ) !== count( $conversion['elements'] ) ) {
			return [
				'ok'       => false,
				'code'     => 'bricks_content_meta_blocked',
				'message'  => 'Converted Bricks element JSON was not persisted. Bricks capability or post-meta guards likely blocked the write.',
				'expectedElementCount' => count( $conversion['elements'] ),
				'storedElementCount'   => is_array( $written_elements ) ? count( $written_elements ) : 0,
			];
		}
		update_post_meta( $post_id, '_kiwe_bricks_source_hash', hash( 'sha256', $html . "\n/* css */\n" . $css ) );
		update_post_meta( $post_id, '_kiwe_bricks_source_bytes', strlen( $html ) + strlen( $css ) );

		$current_settings = get_post_meta( $post_id, $settings_key, true );
		$current_settings = is_array( $current_settings ) ? $current_settings : [];
		$page_settings    = isset( $conversion['pageSettings'] ) && is_array( $conversion['pageSettings'] ) ? $this->sanitize_bricks_page_settings( $conversion['pageSettings'] ) : [];
		update_post_meta( $post_id, $settings_key, array_merge( $current_settings, $page_settings ) );
		update_post_meta( $post_id, $mode_key, 'bricks' );
		update_post_meta( $post_id, '_kiwe_ai_bricks_conversion_hash', hash( 'sha256', $elements_json . "\n/* css */\n" . (string) ( $page_settings['customCss'] ?? '' ) ) );
		update_post_meta( $post_id, '_kiwe_ai_bricks_conversion_converter', sanitize_text_field( (string) ( $conversion['converter'] ?? '' ) ) );
		$this->flush_bricks_template_cache( $post_id );

		return [
			'ok'                    => true,
			'type'                  => $operation['type'] ?? '',
			'convertedToBricksJson' => true,
			'rawBricksMetaWritten'  => true,
			'converter'             => sanitize_text_field( (string) ( $conversion['converter'] ?? '' ) ),
			'elementCount'          => count( $conversion['elements'] ),
			'pageSettingsWritten'   => array_values( array_keys( $page_settings ) ),
			'bricksContentMetaKey'  => $content_key,
			'bricksSettingsMetaKey' => $settings_key,
			'warnings'              => isset( $conversion['warnings'] ) && is_array( $conversion['warnings'] ) ? $conversion['warnings'] : [],
			'globalClassesCount'    => isset( $conversion['globalClasses'] ) && is_array( $conversion['globalClasses'] ) ? count( $conversion['globalClasses'] ) : 0,
			'globalVariablesCount'  => isset( $conversion['globalVariables'] ) && is_array( $conversion['globalVariables'] ) ? count( $conversion['globalVariables'] ) : 0,
		];
	}

	private function bricks_managed_placeholder(): string {
		return '<div hidden data-kiwe-bricks-managed="true" aria-hidden="true"></div>';
	}

	private function flush_bricks_template_cache( int $post_id ): void {
		if ( ! $post_id || ! function_exists( 'get_post_type' ) || ! function_exists( 'wp_cache_set' ) ) {
			return;
		}
		$template_post_type = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		if ( get_post_type( $post_id ) === $template_post_type ) {
			wp_cache_set( 'last_changed', microtime(), 'bricks_' . $template_post_type );
		}
	}

	private function sanitize_bricks_page_settings( array $settings ): array {
		$out = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( 'customCss' === $key ) {
				$css = $this->sanitize_staging_css( (string) $value );
				if ( '' !== $css ) {
					$out['customCss'] = $css;
				}
				continue;
			}
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $key ) || preg_match( '/script|code|php|password|secret|token|key|license|nonce/i', $key ) ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = sanitize_text_field( substr( (string) $value, 0, 4000 ) );
			}
		}

		return $out;
	}

	private function install_activate_theme( array $operation ): array {
		if ( ! $this->settings ) {
			return $this->failure( 'settings_unavailable', 'Kiwe settings service is unavailable.' );
		}
		$package = isset( $operation['package'] ) && is_array( $operation['package'] ) ? $operation['package'] : [];
		if ( [] === $package ) {
			return $this->failure( 'missing_theme_package', 'Theme operation requires package.' );
		}
		$service = new Theme_Package_Service();
		$result  = $service->install( $package, [ 'createdAt' => gmdate( 'c' ) ] );
		if ( empty( $result['ok'] ) ) {
			return $this->failure( 'theme_install_failed', (string) ( $result['message'] ?? 'Theme install failed.' ) );
		}
		$record = $service->find( (string) ( $result['record']['id'] ?? '' ) );
		if ( [] !== $record && ! empty( $operation['activate'] ) ) {
			$this->settings->update( $service->safe_settings_overlay( $record, $this->settings->all() ) );
		}

		return [
			'ok'        => true,
			'type'      => 'kiwe.theme-package.install-activate',
			'record'    => $result['record'],
			'activated' => ! empty( $operation['activate'] ),
		];
	}

	private function post_status( array $operation ): string {
		$status = sanitize_key( (string) ( $operation['status'] ?? 'draft' ) );
		if ( 'publish' === $status && empty( $operation['publishOnStaging'] ) ) {
			return 'draft';
		}

		return in_array( $status, [ 'draft', 'private', 'publish' ], true ) ? $status : 'draft';
	}

	private function patch_bricks_settings( array $operation, string $execution_id ): array {
		$option = sanitize_key( (string) ( $operation['option'] ?? '' ) );
		if ( ! in_array( $option, self::BRICKS_SETTING_OPTIONS, true ) ) {
			return $this->failure( 'bricks_setting_option_not_allowed', 'Only known Bricks settings/options may be patched in staging.' );
		}
		$patch = isset( $operation['patch'] ) && is_array( $operation['patch'] ) ? $operation['patch'] : [];
		if ( [] === $patch ) {
			return $this->failure( 'missing_patch', 'Bricks settings patch must include a non-empty patch object.' );
		}

		$current = get_option( $option, [] );
		$current = is_array( $current ) ? $current : [];
		$next    = $current;
		foreach ( $patch as $path => $value ) {
			$segments = $this->safe_path_segments( (string) $path );
			if ( [] === $segments ) {
				return $this->failure( 'invalid_patch_path', 'Bricks settings patch contains an unsafe path.' );
			}
			$this->set_nested_value( $next, $segments, $this->sanitize_nested_payload( $value ) );
		}

		update_option( $option, $next, false );
		$history   = get_option( 'dsa_ai_staging_bricks_settings_patches', [] );
		$history   = is_array( $history ) ? array_slice( $history, -19 ) : [];
		$history[] = [
			'executionId' => $execution_id,
			'option'      => $option,
			'previousHash' => hash( 'sha256', wp_json_encode( $current ) ?: '' ),
			'nextHash'    => hash( 'sha256', wp_json_encode( $next ) ?: '' ),
			'patchedAt'   => gmdate( 'c' ),
		];
		update_option( 'dsa_ai_staging_bricks_settings_patches', $history, false );

		return [
			'ok'           => true,
			'type'         => 'bricks.settings.patch',
			'option'       => $option,
			'patchedPaths' => array_map( 'strval', array_keys( $patch ) ),
			'previousHash' => hash( 'sha256', wp_json_encode( $current ) ?: '' ),
			'nextHash'     => hash( 'sha256', wp_json_encode( $next ) ?: '' ),
		];
	}

	private function push_kiwe_framework_to_bricks( array $operation, string $execution_id ): array {
		if ( ! $this->settings ) {
			return $this->failure( 'settings_unavailable', 'Kiwe settings service is unavailable.' );
		}
		if ( ! defined( 'BRICKS_DB_GLOBAL_VARIABLES' ) || ! defined( 'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES' ) ) {
			return $this->failure( 'bricks_unavailable', 'Bricks framework storage is unavailable.' );
		}

		$settings = $this->settings->all();
		$token_settings = isset( $settings['tokens'] ) && is_array( $settings['tokens'] ) ? $settings['tokens'] : [];
		$theme_style_settings = isset( $token_settings['bricks_theme_style'] ) && is_array( $token_settings['bricks_theme_style'] ) ? $token_settings['bricks_theme_style'] : [];
		$export = Seam_Token_Service::export_for_bricks(
			Seam_Token_Service::tokens_with_overrides( Seam_Token_Service::overrides_from_settings( $settings ) ),
			[
				'id'    => sanitize_key( (string) ( $theme_style_settings['id'] ?? 'kiwe-global-design' ) ) ?: 'kiwe-global-design',
				'label' => sanitize_text_field( (string) ( $theme_style_settings['label'] ?? 'Kiwe Universal Design Tokens' ) ) ?: 'Kiwe Universal Design Tokens',
			]
		);

		$kiwe_variables        = isset( $export['variables'] ) && is_array( $export['variables'] ) ? $export['variables'] : [];
		$kiwe_categories       = isset( $export['categories'] ) && is_array( $export['categories'] ) ? $export['categories'] : [];
		$kiwe_palette          = isset( $export['colorPalette'] ) && is_array( $export['colorPalette'] ) ? $export['colorPalette'] : [];
		$kiwe_theme_style      = isset( $export['themeStyle'] ) && is_array( $export['themeStyle'] ) ? $export['themeStyle'] : [];
		$kiwe_classes          = isset( $export['classes'] ) && is_array( $export['classes'] ) ? $export['classes'] : [];
		$kiwe_class_categories = isset( $export['classCategories'] ) && is_array( $export['classCategories'] ) ? $export['classCategories'] : [];

		$current_variables        = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$current_categories       = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
		$current_palette          = defined( 'BRICKS_DB_COLOR_PALETTE' ) ? get_option( BRICKS_DB_COLOR_PALETTE, [] ) : [];
		$current_theme_styles     = defined( 'BRICKS_DB_THEME_STYLES' ) ? get_option( BRICKS_DB_THEME_STYLES, [] ) : [];
		$current_classes          = defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ? get_option( BRICKS_DB_GLOBAL_CLASSES, [] ) : [];
		$current_class_categories = defined( 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES' ) ? get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] ) : [];

		$current_variables        = is_array( $current_variables ) ? $current_variables : [];
		$current_categories       = is_array( $current_categories ) ? $current_categories : [];
		$current_palette          = is_array( $current_palette ) ? $current_palette : [];
		$current_theme_styles     = is_array( $current_theme_styles ) ? $current_theme_styles : [];
		$current_classes          = is_array( $current_classes ) ? $current_classes : [];
		$current_class_categories = is_array( $current_class_categories ) ? $current_class_categories : [];

		update_option(
			'dsa_ai_framework_push_backup',
			[
				'executionId'     => $execution_id,
				'createdAt'       => gmdate( 'c' ),
				'variablesHash'   => hash( 'sha256', wp_json_encode( $current_variables ) ?: '' ),
				'categoriesHash'  => hash( 'sha256', wp_json_encode( $current_categories ) ?: '' ),
				'paletteHash'     => hash( 'sha256', wp_json_encode( $current_palette ) ?: '' ),
				'themeStylesHash' => hash( 'sha256', wp_json_encode( $current_theme_styles ) ?: '' ),
				'classesHash'     => hash( 'sha256', wp_json_encode( $current_classes ) ?: '' ),
			],
			false
		);

		$merged_variables = array_values(
			array_filter(
				$current_variables,
				static function ( $variable ): bool {
					if ( ! is_array( $variable ) ) {
						return false;
					}
					$name   = isset( $variable['name'] ) ? (string) $variable['name'] : '';
					$source = isset( $variable['source'] ) ? (string) $variable['source'] : '';
					return 'kiwe-universal' !== $source && ! str_starts_with( $name, 'kiwe-' );
				}
			)
		);
		$merged_categories = array_values(
			array_filter(
				$current_categories,
				static function ( $category ): bool {
					if ( ! is_array( $category ) ) {
						return false;
					}
					$name   = isset( $category['name'] ) ? (string) $category['name'] : '';
					$source = isset( $category['source'] ) ? (string) $category['source'] : '';
					return 'kiwe-universal' !== $source && ! str_starts_with( $name, 'Kiwe ' );
				}
			)
		);
		$merged_palette = array_values(
			array_filter(
				$current_palette,
				static function ( $palette ): bool {
					if ( ! is_array( $palette ) ) {
						return false;
					}
					$name   = isset( $palette['name'] ) ? (string) $palette['name'] : '';
					$source = isset( $palette['source'] ) ? (string) $palette['source'] : '';
					return 'kiwe-universal' !== $source && 'Kiwe Universal' !== $name;
				}
			)
		);
		$kiwe_class_names = array_flip(
			array_filter(
				array_map(
					static fn( $class ): string => is_array( $class ) ? (string) ( $class['name'] ?? '' ) : '',
					$kiwe_classes
				)
			)
		);
		$merged_classes = array_values(
			array_filter(
				$current_classes,
				static function ( $class ) use ( $kiwe_class_names ): bool {
					if ( ! is_array( $class ) ) {
						return false;
					}
					$name   = isset( $class['name'] ) ? (string) $class['name'] : '';
					$source = isset( $class['source'] ) ? (string) $class['source'] : '';
					return 'kiwe-seam' !== $source && ! isset( $kiwe_class_names[ $name ] );
				}
			)
		);
		$merged_class_categories = array_values(
			array_filter(
				$current_class_categories,
				static function ( $category ): bool {
					if ( ! is_array( $category ) ) {
						return false;
					}
					$name   = isset( $category['name'] ) ? (string) $category['name'] : '';
					$source = isset( $category['source'] ) ? (string) $category['source'] : '';
					return 'kiwe-seam' !== $source && ! str_starts_with( $name, 'Kiwe Seam ' );
				}
			)
		);

		$merged_variables        = array_merge( $merged_variables, $kiwe_variables );
		$merged_categories       = array_merge( $merged_categories, $kiwe_categories );
		$merged_palette          = array_merge( $merged_palette, $kiwe_palette );
		$merged_classes          = array_merge( $merged_classes, $kiwe_classes );
		$merged_class_categories = array_merge( $merged_class_categories, $kiwe_class_categories );

		update_option( BRICKS_DB_GLOBAL_VARIABLES, $merged_variables, false );
		update_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, $merged_categories, false );
		if ( defined( 'BRICKS_DB_COLOR_PALETTE' ) ) {
			update_option( BRICKS_DB_COLOR_PALETTE, $merged_palette, false );
		}
		$theme_style_pushed = false;
		if ( defined( 'BRICKS_DB_THEME_STYLES' ) && ! empty( $kiwe_theme_style['id'] ) && ! empty( $kiwe_theme_style['settings'] ) && ! empty( $theme_style_settings['enabled'] ) ) {
			$current_theme_styles[ (string) $kiwe_theme_style['id'] ] = [
				'label'    => sanitize_text_field( (string) ( $kiwe_theme_style['label'] ?? 'Kiwe Universal Design Tokens' ) ),
				'settings' => $kiwe_theme_style['settings'],
			];
			update_option( BRICKS_DB_THEME_STYLES, $current_theme_styles, false );
			$theme_style_pushed = true;
		}
		if ( defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ) {
			if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'save_global_classes_in_db' ) ) {
				\Bricks\Helpers::save_global_classes_in_db( $merged_classes );
			} else {
				update_option( BRICKS_DB_GLOBAL_CLASSES, $merged_classes, false );
			}
		}
		if ( defined( 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES' ) ) {
			update_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, $merged_class_categories, false );
		}

		return [
			'ok'               => true,
			'type'             => 'kiwe.framework.push-bricks',
			'variables'        => count( $kiwe_variables ),
			'categories'       => count( $kiwe_categories ),
			'classes'          => count( $kiwe_classes ),
			'classCategories'  => count( $kiwe_class_categories ),
			'palettePushed'    => defined( 'BRICKS_DB_COLOR_PALETTE' ),
			'themeStylePushed' => $theme_style_pushed,
			'nonKiwePreserved' => true,
			'requestedBy'      => sanitize_text_field( (string) ( $operation['reason'] ?? '' ) ),
		];
	}

	private function apply_kiwe_framework_profile( array $operation, string $execution_id ): array {
		if ( ! $this->settings ) {
			return $this->failure( 'settings_unavailable', 'Kiwe settings service is unavailable.' );
		}

		$payload = isset( $operation['profile'] ) && is_array( $operation['profile'] ) ? $operation['profile'] : [];
		if ( [] === $payload && isset( $operation['package'] ) && is_array( $operation['package'] ) ) {
			$payload = $operation['package'];
		}
		if ( [] === $payload && isset( $operation['tokens'] ) && is_array( $operation['tokens'] ) ) {
			$payload = [ 'tokens' => $operation['tokens'] ];
		}
		if ( [] === $payload ) {
			return $this->failure( 'missing_framework_profile', 'Framework profile operation requires profile, package, or tokens.' );
		}

		$tokens_input = $this->framework_profile_tokens_input( $payload );
		if ( ! is_array( $tokens_input ) ) {
			return $this->failure( 'invalid_framework_profile', 'Framework profile must contain settings.tokens or a raw tokens object.' );
		}

		$settings = $this->settings->all();
		$current  = isset( $settings['tokens'] ) && is_array( $settings['tokens'] ) ? $settings['tokens'] : [];
		$defaults = $this->settings->defaults()['tokens'] ?? [
			'enabled'            => true,
			'profile_label'      => 'Kiwe Universal',
			'overrides'          => [],
			'bricks_theme_style' => [
				'enabled' => true,
				'id'      => 'kiwe-global-design',
				'label'   => 'Kiwe Universal Design Tokens',
			],
		];
		$next = $this->sanitize_framework_token_settings( $tokens_input, $current, $defaults );

		update_option(
			'dsa_ai_framework_profile_backup',
			[
				'executionId'  => $execution_id,
				'createdAt'    => gmdate( 'c' ),
				'previousHash' => hash( 'sha256', wp_json_encode( $current ) ?: '' ),
				'nextHash'     => hash( 'sha256', wp_json_encode( $next ) ?: '' ),
				'requestedBy'  => sanitize_text_field( (string) ( $operation['reason'] ?? '' ) ),
			],
			false
		);

		$settings['tokens'] = $next;
		$this->settings->update( $settings );

		return [
			'ok'                 => true,
			'type'               => 'kiwe.framework-profile.apply',
			'profileLabel'       => (string) ( $next['profile_label'] ?? 'Kiwe Universal' ),
			'overridesApplied'   => count( is_array( $next['overrides'] ?? null ) ? $next['overrides'] : [] ),
			'overrideNames'      => array_values( array_keys( is_array( $next['overrides'] ?? null ) ? $next['overrides'] : [] ) ),
			'bricksThemeStyle'   => [
				'enabled' => ! empty( $next['bricks_theme_style']['enabled'] ),
				'id'      => (string) ( $next['bricks_theme_style']['id'] ?? 'kiwe-global-design' ),
				'label'   => (string) ( $next['bricks_theme_style']['label'] ?? 'Kiwe Universal Design Tokens' ),
			],
			'pushToBricksNeeded' => true,
			'requestedBy'        => sanitize_text_field( (string) ( $operation['reason'] ?? '' ) ),
		];
	}

	private function framework_profile_tokens_input( array $payload ): ?array {
		if ( isset( $payload['settings'] ) && is_array( $payload['settings'] ) && isset( $payload['settings']['tokens'] ) && is_array( $payload['settings']['tokens'] ) ) {
			return $payload['settings']['tokens'];
		}
		if ( isset( $payload['tokens'] ) && is_array( $payload['tokens'] ) ) {
			return $payload['tokens'];
		}
		if ( array_intersect( [ 'enabled', 'profile_label', 'overrides', 'bricks_theme_style' ], array_keys( $payload ) ) ) {
			return $payload;
		}

		return null;
	}

	private function sanitize_framework_token_settings( array $input, array $current, array $defaults ): array {
		$next = wp_parse_args( $current, $defaults );
		$next['bricks_theme_style'] = wp_parse_args(
			is_array( $next['bricks_theme_style'] ?? null ) ? $next['bricks_theme_style'] : [],
			is_array( $defaults['bricks_theme_style'] ?? null ) ? $defaults['bricks_theme_style'] : []
		);

		if ( array_key_exists( 'enabled', $input ) ) {
			$next['enabled'] = ! empty( $input['enabled'] );
		}
		if ( array_key_exists( 'profile_label', $input ) ) {
			$label = sanitize_text_field( (string) $input['profile_label'] );
			$next['profile_label'] = '' !== $label ? substr( $label, 0, 80 ) : (string) ( $defaults['profile_label'] ?? 'Kiwe Universal' );
		}
		if ( isset( $input['overrides'] ) && is_array( $input['overrides'] ) ) {
			$next['overrides'] = Seam_Token_Service::sanitize_overrides( $input['overrides'] );
		} else {
			$next['overrides'] = Seam_Token_Service::sanitize_overrides( is_array( $next['overrides'] ?? null ) ? $next['overrides'] : [] );
		}

		if ( isset( $input['bricks_theme_style'] ) && is_array( $input['bricks_theme_style'] ) ) {
			$style_input = $input['bricks_theme_style'];
			if ( array_key_exists( 'enabled', $style_input ) ) {
				$next['bricks_theme_style']['enabled'] = ! empty( $style_input['enabled'] );
			}
			if ( array_key_exists( 'id', $style_input ) ) {
				$id = sanitize_key( (string) $style_input['id'] );
				$next['bricks_theme_style']['id'] = '' !== $id ? substr( $id, 0, 80 ) : (string) ( $defaults['bricks_theme_style']['id'] ?? 'kiwe-global-design' );
			}
			if ( array_key_exists( 'label', $style_input ) ) {
				$label = sanitize_text_field( (string) $style_input['label'] );
				$next['bricks_theme_style']['label'] = '' !== $label ? substr( $label, 0, 100 ) : (string) ( $defaults['bricks_theme_style']['label'] ?? 'Kiwe Universal Design Tokens' );
			}
		}

		return $next;
	}

	private function sanitize_template_settings( array $settings ): array {
		return $this->sanitize_nested_payload( $settings );
	}

	private function sanitize_nested_payload( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 6 ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $nested ) {
				$clean_key = is_int( $key ) ? $key : $this->safe_payload_key( (string) $key );
				if ( '' === (string) $clean_key || preg_match( '/script|code|php|password|secret|token|key|license|nonce/i', (string) $clean_key ) ) {
					continue;
				}
				$out[ $clean_key ] = $this->sanitize_nested_payload( $nested, $depth + 1 );
			}

			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			$string = (string) $value;
			if ( strlen( $string ) > 4000 ) {
				$string = substr( $string, 0, 4000 );
			}
			if ( preg_match( '/<\\s*script|javascript:|on[a-z]+\\s*=|data:text\\/html/i', $string ) ) {
				return '';
			}

			return sanitize_text_field( $string );
		}

		return null;
	}

	private function safe_payload_key( string $key ): string {
		$key = trim( $key );
		if ( '' === $key || strlen( $key ) > 96 || ! preg_match( '/^[a-zA-Z0-9_.:-]+$/', $key ) ) {
			return '';
		}

		return $key;
	}

	private function safe_path_segments( string $path ): array {
		$parts = array_values( array_filter( array_map( 'trim', explode( '.', $path ) ), static fn( string $part ): bool => '' !== $part ) );
		if ( [] === $parts || count( $parts ) > 6 ) {
			return [];
		}
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $part ) || preg_match( '/script|code|php|password|secret|token|key|license|nonce/i', $part ) ) {
				return [];
			}
		}

		return $parts;
	}

	private function set_nested_value( array &$target, array $segments, mixed $value ): void {
		$cursor = &$target;
		$last   = array_pop( $segments );
		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}
			$cursor = &$cursor[ $segment ];
		}
		if ( null !== $last ) {
			$cursor[ $last ] = $value;
		}
	}

	private function sanitize_staging_html( string $html, string $css = '' ): string {
		$style_css = '';
		$html      = preg_replace_callback(
			'/<style\\b[^>]*>(.*?)<\\/style>/is',
			function ( array $match ) use ( &$style_css ): string {
				$style_css .= "\n" . (string) ( $match[1] ?? '' );
				return '';
			},
			$html
		);
		$html      = is_string( $html ) ? $html : '';
		$style_css = $this->sanitize_staging_css( $style_css . "\n" . $css );
		$content   = wp_kses_post( $html );
		if ( '' !== trim( $style_css ) ) {
			$content = '<style>' . $style_css . '</style>' . "\n" . $content;
		}

		return $content;
	}

	private function sanitize_staging_css( string $css ): string {
		if ( '' === trim( $css ) ) {
			return '';
		}
		$css = substr( $css, 0, self::MAX_CSS_BYTES );
		$css = preg_replace( '/\\/\\*.*?\\*\\//s', '', $css );
		$css = is_string( $css ) ? $css : '';
		if ( preg_match( '/<|@import|expression\\s*\\(|javascript:|vbscript:|data:text\\/html|(^|[;{\\s])behavior\\s*:|-moz-binding/i', $css ) ) {
			return '';
		}

		return trim( $css );
	}

	private function is_likely_staging(): bool {
		$env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';

		return in_array( $env, [ 'local', 'development', 'staging' ], true ) || (bool) preg_match( '/(^|[.-])(staging|stage|dev|test|sandbox|hostingersite)([.-]|$)/', $host );
	}

	private function failure( string $code, string $message ): array {
		return [
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		];
	}
}
