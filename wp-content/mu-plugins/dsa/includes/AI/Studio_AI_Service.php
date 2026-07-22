<?php

namespace DSA\AI;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Studio_AI_Service {
	private const LANES = [ 'website', 'theme', 'combined', 'dynamic', 'audit', 'staging', 'security' ];
	private const OPERATING_MODES = [ 'native', 'browser_companion', 'browser_only' ];

	public function __construct(
		private Settings $settings,
		private Site_Graph_Service $site_graph,
		private AI_Companion_Service $companion,
		private AI_Provider_Service $provider
	) {}

	public function status( array $auth = [] ): array {
		$settings = $this->settings();
		$mode = $this->operating_mode();

		return [
			'ok'             => true,
			'schema'         => 'kiwe.studio-ai.status.v1',
			'enabled'        => ! empty( $settings['studio_enabled'] ),
			'operatingMode'  => $mode,
			'modes'          => [
				'native'            => __( 'Kiwe uses the configured provider/key for bounded drafting when the API key also has native_ai scope.', 'dsa' ),
				'browser_companion' => __( 'Browser AI uses Kiwe Companion packets and deterministic audits; Kiwe does not call a model.', 'dsa' ),
				'browser_only'      => __( 'Kiwe provides public toolkit/docs only; no internal Companion or native model support is used.', 'dsa' ),
			],
			'provider'       => $this->provider->status(),
			'tokenSaver'     => [
				'enabled'              => ! empty( $settings['token_saver_enabled'] ),
				'preferCompanion'      => ! empty( $settings['prefer_companion_context'] ),
				'maxContextCards'      => max( 4, absint( $settings['max_context_cards'] ?? 12 ) ),
				'maxNativeContextBytes'=> max( 10000, absint( $settings['max_native_context_bytes'] ?? 60000 ) ),
				'maxNativeTokens'      => max( 200, absint( $settings['max_native_tokens'] ?? 1200 ) ),
			],
			'routes'         => [
				'status' => '/wp-json/dsa/v1/ai/studio/status',
				'start'  => '/wp-json/dsa/v1/ai/studio/start',
				'draft'  => '/wp-json/dsa/v1/ai/studio/draft',
				'review' => '/wp-json/dsa/v1/ai/studio/review',
			],
			'aiKeyScopes'    => [
				'studio_ai' => 'required for Studio packets and review',
				'native_ai' => 'add this only when the key may spend provider tokens',
			],
			'capabilities'   => [
				'website'      => true,
				'appshellTheme'=> true,
				'combined'     => true,
				'dynamicData'  => true,
				'audit'        => true,
				'stagingPlan'  => true,
				'nativeDraft'  => 'native' === $mode && ! empty( $settings['allow_native_generation'] ) && $this->has_scope( $auth, 'native_ai' ),
			],
			'boundaries'     => $this->boundaries(),
		];
	}

	public function start_project( array $args, array $auth = [] ): array {
		$settings = $this->settings();
		if ( empty( $settings['studio_enabled'] ) ) {
			return $this->disabled_response();
		}

		$lane = $this->lane( (string) ( $args['mode'] ?? $args['lane'] ?? 'combined' ) );
		$brief = $this->clean_text( (string) ( $args['brief'] ?? $args['prompt'] ?? '' ), 16000 );
		$context = $this->context_packet( $lane, $args, $auth );

		return [
			'ok'            => true,
			'schema'        => 'kiwe.studio-ai.project.v1',
			'operatingMode' => $this->operating_mode(),
			'lane'          => $lane,
			'brief'         => $brief,
			'workflow'      => $this->workflow( $lane ),
			'contextPacket' => $context,
			'browserPrompt' => $this->browser_prompt( $lane, $brief, $context ),
			'next'          => [
				'browserAi' => 'Use browserPrompt plus contextPacket. Do not read the full repository.',
				'nativeAi'  => 'Call /ai/studio/draft only if Kiwe > AI native generation is enabled and this API key has native_ai scope.',
				'audit'     => 'After first output, call /ai/studio/review or /ai/audit-companion/review, then fix the deterministic mustFix list before spending another broad model pass.',
			],
			'boundaries'    => $this->boundaries(),
		];
	}

	public function draft( array $args, array $auth = [] ): array {
		$settings = $this->settings();
		if ( empty( $settings['studio_enabled'] ) ) {
			return $this->disabled_response();
		}

		$lane = $this->lane( (string) ( $args['mode'] ?? $args['lane'] ?? 'combined' ) );
		$brief = $this->clean_text( (string) ( $args['brief'] ?? $args['prompt'] ?? '' ), 16000 );
		$context = $this->context_packet( $lane, $args, $auth );
		$envelope = $this->provider->build_prompt( $context, $brief, $lane );
		$native_requested = ! array_key_exists( 'callNative', $args ) || ! empty( $args['callNative'] );

		$native_allowed = 'native' === $this->operating_mode()
			&& ! empty( $settings['allow_native_generation'] )
			&& $native_requested
			&& $this->has_scope( $auth, 'native_ai' );

		$native = $native_allowed
			? $this->provider->generate( $envelope )
			: [
				'ok'        => true,
				'called'    => false,
				'reason'    => $native_requested ? $this->native_block_reason( $auth ) : 'native_call_not_requested',
				'envelope'  => [
					'systemBytes' => strlen( $envelope['system'] ),
					'userBytes'   => strlen( $envelope['user'] ),
					'userPreview' => $this->clean_text( $envelope['user'], 900 ),
				],
				'estimates' => [
					'promptBytes'     => strlen( $envelope['system'] ) + strlen( $envelope['user'] ),
					'estimatedTokens' => (int) ceil( ( strlen( $envelope['system'] ) + strlen( $envelope['user'] ) ) / 4 ),
				],
			];

		return [
			'ok'            => true,
			'schema'        => 'kiwe.studio-ai.draft.v1',
			'operatingMode' => $this->operating_mode(),
			'lane'          => $lane,
			'contextPacket' => $context,
			'browserPrompt' => $this->browser_prompt( $lane, $brief, $context ),
			'native'        => $native,
			'postDraftGate' => [
				'runCompanionReview' => '/wp-json/dsa/v1/ai/studio/review',
				'thenStageApply'     => 'Only after deterministic review and explicit staging approval.',
			],
		];
	}

	public function review( array $args, array $auth = [] ): array {
		$settings = $this->settings();
		if ( empty( $settings['studio_enabled'] ) ) {
			return $this->disabled_response();
		}

		$review = $this->companion->audit_review( $args, $auth );

		return [
			'ok'            => ! empty( $review['ok'] ),
			'schema'        => 'kiwe.studio-ai.review.v1',
			'operatingMode' => $this->operating_mode(),
			'deterministic' => $review,
			'native'        => [
				'called' => false,
				'reason' => 'Studio review keeps model use off by default; deterministic audit is the authority gate.',
			],
			'next'          => [
				'ifFailed' => 'Revise output against deterministic mustFix items and re-run review.',
				'ifPassed' => 'Use trusted staging executor for any WordPress/Bricks/Kiwe mutation.',
			],
		];
	}

	private function context_packet( string $lane, array $args, array $auth ): array {
		$settings = $this->settings();
		$site_graph = $this->site_graph->graph( [ 'sampleLimit' => isset( $args['sampleLimit'] ) ? absint( $args['sampleLimit'] ) : 6 ] );
		$encoded_graph = wp_json_encode( $site_graph );
		$encoded_graph = false === $encoded_graph ? '' : (string) $encoded_graph;
		$context = [
			'schema'        => 'kiwe.studio-context-packet.v1',
			'lane'          => $lane,
			'generatedAt'   => gmdate( 'c' ),
			'siteGraphHash' => hash( 'sha256', $encoded_graph ),
			'site'          => $site_graph['site'] ?? [],
			'wordpress'     => $this->pick( $site_graph['wordpress'] ?? [], [ 'version', 'posts', 'pages', 'menus', 'media' ] ),
			'woocommerce'   => $this->pick( $site_graph['woocommerce'] ?? [], [ 'available', 'products', 'productCategories', 'currency' ] ),
			'bricks'        => $site_graph['bricks'] ?? [],
			'bricksBuilder' => ( new Bricks_AI_Intelligence_Service( $this->settings ) )->planning_packet(
				[
					'brief'    => (string) ( $args['brief'] ?? $args['prompt'] ?? '' ),
					'postId'   => isset( $args['postId'] ) ? absint( $args['postId'] ) : 0,
					'elements' => isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : [],
				]
			),
			'kiwe'          => $site_graph['kiwe'] ?? [],
			'toolkit'       => $this->toolkit( $lane ),
			'boundaries'    => $this->boundaries(),
		];

		if ( 'browser_only' !== $this->operating_mode() && ! empty( $settings['prefer_companion_context'] ) && ! empty( $settings['companion_enabled'] ) ) {
			$companion = $this->companion->context(
				[
					'mode'               => $lane,
					'maxCards'           => max( 4, absint( $settings['max_context_cards'] ?? 12 ) ),
					'includeSecureTrack' => ! empty( $args['includeSecureTrack'] ),
				],
				$auth
			);
			$context['companion'] = [
				'ok'      => ! empty( $companion['ok'] ),
				'cards'   => $companion['cards'] ?? [],
				'memory'  => $companion['memory'] ?? [],
				'errors'  => empty( $companion['ok'] ) ? ( $companion['error'] ?? [] ) : [],
			];
		}

		return $this->trim_context( $context );
	}

	private function browser_prompt( string $lane, string $brief, array $context ): string {
		$url = (string) ( $context['toolkit']['combinedLiteRaw'] ?? 'https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md' );
		$audit = (string) ( $context['toolkit']['auditLiteRaw'] ?? 'https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md' );

		return trim(
			"Read only these Kiwe toolkit files:\n"
			. $url . "\n"
			. $audit . "\n\n"
			. "Use the supplied Kiwe Studio context packet, not the whole repository.\n"
			. "Mode: {$lane}\n"
			. "Brief: {$brief}\n\n"
			. "Create the Kiwe handoff/output required by the toolkit, keep Seam semantic/headless, keep Kiwe-owned capabilities out of page code, and self-audit before final output. If a Kiwe AI key is available, submit files to /ai/audit-companion/review and fix mustFix items first."
		);
	}

	private function workflow( string $lane ): array {
		return [
			[
				'id'    => 'context',
				'label' => 'Read Kiwe Studio packet',
				'why'   => 'Saves tokens and prevents repository wandering.',
			],
			[
				'id'    => 'draft',
				'label' => 'Draft ' . $lane . ' output',
				'why'   => 'Use Seam/Kiwe contracts and live Site Graph summaries.',
			],
			[
				'id'    => 'audit',
				'label' => 'Run deterministic audit',
				'why'   => 'Catches geometry, selector, settings, authority and preview mistakes before staging.',
			],
			[
				'id'    => 'stage',
				'label' => 'Stage only with explicit approval',
				'why'   => 'Keeps WordPress, Bricks, WooCommerce and Kiwe mutations controlled.',
			],
		];
	}

	private function toolkit( string $lane ): array {
		return [
			'combinedLiteRaw' => 'https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md',
			'auditLiteRaw'   => 'https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md',
			'repo'           => 'https://github.com/Museintel/kiwe',
			'preferredLane'  => $lane,
			'routeHint'      => 'Browser AI should read lite contexts first; IDE/GitHub tools can call /wp-json/dsa/v1/ai/studio/start when a staging site API key is available.',
		];
	}

	private function boundaries(): array {
		return [
			'readOnlyUntilStaged'    => true,
			'kiweOwnsAppShell'       => true,
			'wordpressOwnsContent'   => true,
			'bricksOwnsBuilderData'  => true,
			'woocommerceOwnsMoney'   => true,
			'noCartCheckoutAuthRun'  => true,
			'noSecretExposure'       => true,
			'modelCallsOptional'     => true,
		];
	}

	private function native_block_reason( array $auth ): string {
		$settings = $this->settings();
		if ( 'native' !== $this->operating_mode() ) {
			return 'operating_mode_is_' . $this->operating_mode();
		}
		if ( empty( $settings['allow_native_generation'] ) ) {
			return 'native_generation_disabled_in_kiwe_ai_settings';
		}
		if ( ! $this->has_scope( $auth, 'native_ai' ) ) {
			return 'api_key_missing_native_ai_scope';
		}

		return 'native_generation_not_available';
	}

	private function disabled_response(): array {
		return [
			'ok'         => false,
			'httpStatus' => 403,
			'code'       => 'studio_ai_disabled',
			'message'    => 'Kiwe Studio AI workflows are disabled in Kiwe > AI.',
		];
	}

	private function has_scope( array $auth, string $scope ): bool {
		$record = isset( $auth['record'] ) && is_array( $auth['record'] ) ? $auth['record'] : [];
		$scopes = isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? array_map( 'sanitize_key', $record['scopes'] ) : [];

		return in_array( 'admin', $scopes, true ) || in_array( 'all', $scopes, true ) || in_array( sanitize_key( $scope ), $scopes, true );
	}

	private function lane( string $lane ): string {
		$lane = sanitize_key( $lane );
		return in_array( $lane, self::LANES, true ) ? $lane : 'combined';
	}

	private function operating_mode(): string {
		$mode = sanitize_key( (string) ( $this->settings()['studio_mode'] ?? 'browser_companion' ) );
		return in_array( $mode, self::OPERATING_MODES, true ) ? $mode : 'browser_companion';
	}

	private function settings(): array {
		$defaults = $this->settings->defaults()['ai'] ?? [];
		$current = $this->settings->get( 'ai', [] );

		return array_replace_recursive( is_array( $defaults ) ? $defaults : [], is_array( $current ) ? $current : [] );
	}

	private function pick( array $source, array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $source ) ) {
				$out[ $key ] = $source[ $key ];
			}
		}

		return $out;
	}

	private function trim_context( array $context ): array {
		$settings = $this->settings();
		$limit = max( 10000, absint( $settings['max_native_context_bytes'] ?? 60000 ) );
		$target = max( 10000, min( $limit, (int) floor( $limit * 0.62 ) ) );
		$encoded = wp_json_encode( $context );
		if ( strlen( (string) $encoded ) <= $target ) {
			return $context;
		}

		if ( isset( $context['companion']['cards'] ) && is_array( $context['companion']['cards'] ) ) {
			$context['companion']['cards'] = array_slice( $context['companion']['cards'], 0, max( 4, absint( $settings['max_context_cards'] ?? 12 ) ) );
		}
		unset( $context['wordpress']['posts'], $context['wordpress']['pages'], $context['woocommerce']['products'] );
		$context['trimmed'] = true;
		$context['trimReason'] = 'native_context_budget';
		$context['nativeContextTargetBytes'] = $target;

		if ( ! $this->context_over_limit( $context, $target ) ) {
			return $context;
		}

		if ( isset( $context['bricksBuilder'] ) && is_array( $context['bricksBuilder'] ) ) {
			$context['bricksBuilder'] = $this->compact_bricks_builder_packet( $context['bricksBuilder'] );
		}

		if ( ! $this->context_over_limit( $context, $target ) ) {
			return $context;
		}

		unset( $context['siteGraphHash'] );
		if ( isset( $context['companion']['memory'] ) ) {
			unset( $context['companion']['memory'] );
		}

		if ( ! $this->context_over_limit( $context, $target ) ) {
			return $context;
		}

		$context['companion'] = isset( $context['companion'] ) && is_array( $context['companion'] )
			? $this->pick( $context['companion'], [ 'ok', 'cards', 'errors' ] )
			: [];

		return $context;
	}

	private function context_over_limit( array $context, int $limit ): bool {
		$encoded = wp_json_encode( $context );
		return strlen( (string) $encoded ) > $limit;
	}

	private function compact_bricks_builder_packet( array $packet ): array {
		$context = isset( $packet['context'] ) && is_array( $packet['context'] ) ? $packet['context'] : [];
		$elements = isset( $context['elements']['items'] ) && is_array( $context['elements']['items'] )
			? array_slice( $context['elements']['items'], 0, 40 )
			: [];
		$element_names = array_values(
			array_filter(
				array_map(
					static fn( $item ): string => is_array( $item ) ? sanitize_key( (string) ( $item['name'] ?? '' ) ) : '',
					$elements
				)
			)
		);
		$requested_schemas = isset( $context['elementSchemas'] ) && is_array( $context['elementSchemas'] )
			? array_keys( $context['elementSchemas'] )
			: [];

		$compact_context = [
			'ok'              => ! empty( $context['ok'] ),
			'schema'          => (string) ( $context['schema'] ?? 'kiwe.bricks-ai-intelligence.v1' ),
			'bricks'          => isset( $context['bricks'] ) && is_array( $context['bricks'] ) ? $context['bricks'] : [],
			'elements'        => [
				'total' => (int) ( $context['elements']['total'] ?? count( $elements ) ),
				'source' => (string) ( $context['elements']['source'] ?? '' ),
				'items' => $elements,
			],
			'requestedElementSchemas' => array_slice( array_values( array_map( 'sanitize_key', $requested_schemas ) ), 0, 24 ),
			'queryLoops'      => isset( $context['queryLoops'] ) && is_array( $context['queryLoops'] )
				? $this->compact_list_packet( $context['queryLoops'], 24 )
				: [],
			'dynamicDataTags' => isset( $context['dynamicDataTags'] ) && is_array( $context['dynamicDataTags'] )
				? $this->compact_list_packet( $context['dynamicDataTags'], 40 )
				: [],
			'interactions'    => isset( $context['interactions'] ) && is_array( $context['interactions'] )
				? $this->compact_list_packet( $context['interactions'], 20 )
				: [],
			'conditions'      => isset( $context['conditions'] ) && is_array( $context['conditions'] )
				? $this->compact_list_packet( $context['conditions'], 20 )
				: [],
			'seam'            => isset( $context['seam'] ) && is_array( $context['seam'] ) ? $context['seam'] : [],
			'kiwe'            => isset( $context['kiwe'] ) && is_array( $context['kiwe'] ) ? $context['kiwe'] : [],
			'toolUseRules'    => isset( $context['toolUseRules'] ) && is_array( $context['toolUseRules'] )
				? array_slice( $context['toolUseRules'], 0, 12 )
				: [],
			'compact'         => true,
			'compactReason'   => 'native_context_budget',
		];

		return [
			'ok'      => ! empty( $packet['ok'] ),
			'schema'  => (string) ( $packet['schema'] ?? 'kiwe.bricks-ai-plan.v1' ),
			'brief'   => $this->clean_text( (string) ( $packet['brief'] ?? '' ), 1200 ),
			'intent'  => isset( $packet['intent'] ) && is_array( $packet['intent'] ) ? $packet['intent'] : [],
			'context' => $compact_context,
			'plan'    => isset( $packet['plan'] ) && is_array( $packet['plan'] ) ? $packet['plan'] : [],
			'compact' => true,
		];
	}

	private function compact_list_packet( array $packet, int $limit ): array {
		if ( isset( $packet['items'] ) && is_array( $packet['items'] ) ) {
			$packet['items'] = array_slice( $packet['items'], 0, max( 1, $limit ) );
		}
		unset( $packet['schemas'], $packet['raw'], $packet['controls'] );

		return $packet;
	}

	private function clean_text( string $value, int $limit ): string {
		$value = trim( function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : wp_strip_all_tags( $value ) );
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, max( 0, $limit - 28 ) ) . "\n...[trimmed by Kiwe]...";
	}
}
