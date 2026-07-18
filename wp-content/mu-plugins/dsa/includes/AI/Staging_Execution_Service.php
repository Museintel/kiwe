<?php

namespace DSA\AI;

use DSA\Settings;
use DSA\Theme\Theme_Package_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Staging_Execution_Service {
	private const MAX_HTML_BYTES = 450000;
	private const MAX_CSS_BYTES  = 180000;
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
			'mutatesWooCommerce'      => false,
			'runsCheckoutCartAuth'    => false,
			'rawBricksMetaWritten'    => false,
			'blockers'                => $blockers,
			'operationsRequested'     => count( $operations ),
			'results'                 => $results,
			'postExecutionChecklist'  => [
				'Fetch /ai/site-inspection and confirm created IDs.',
				'Open created staging page URLs on desktop/tablet/mobile.',
				'Open Kiwe dock screens and header launchers on created pages.',
				'If Bricks HTML-to-Bricks import is needed, import the stored bricksPasteHtml in Bricks and then re-run inspection.',
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
			$type = (string) ( $operation['type'] ?? '' );
			if ( in_array( $type, [ 'woocommerce.mutate', 'cart.run', 'checkout.run', 'auth.run', 'bricks.raw-meta-write' ], true ) ) {
				$blockers[] = sprintf( 'Operation type %s is forbidden in staging executor.', $type );
			}
		}

		return array_values( array_unique( $blockers ) );
	}

	private function execute_operation( array $operation, string $execution_id ): array {
		$type = strtolower( preg_replace( '/[^a-z0-9._-]+/', '', (string) ( $operation['type'] ?? '' ) ) );

		if ( 'wordpress.page.upsert' === $type ) {
			return $this->upsert_post( $operation, 'page', $execution_id );
		}

		if ( 'wordpress.post.upsert' === $type ) {
			return $this->upsert_post( $operation, 'post', $execution_id );
		}

		if ( 'bricks.template.create' === $type || 'bricks.template.upsert' === $type ) {
			return $this->upsert_bricks_template( $operation, $execution_id );
		}

		if ( 'bricks.settings.patch' === $type ) {
			return $this->patch_bricks_settings( $operation, $execution_id );
		}

		if ( 'kiwe.theme-package.install-activate' === $type ) {
			return $this->install_activate_theme( $operation );
		}

		return $this->failure( 'unsupported_operation', sprintf( 'Unsupported staging operation type: %s', $type ) );
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

		return $result;
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
				$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
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
		if ( preg_match( '/<|>|@import|expression\\s*\\(|javascript:|vbscript:|data:text\\/html|behavior\\s*:|-moz-binding/i', $css ) ) {
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
