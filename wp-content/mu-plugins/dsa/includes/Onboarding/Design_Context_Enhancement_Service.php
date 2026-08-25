<?php

namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores an explicitly approved AI enhancement beside owner evidence.
 *
 * The owner profile is never rewritten. Resolved context may use approved
 * editorial suggestions and may fill missing design roles, but factual fields
 * and every owner-selected color remain authoritative.
 */
final class Design_Context_Enhancement_Service {
	public const SCHEMA        = 'kiwe.design-context-enhancement.v1';
	public const OPTION        = 'kiwe_design_context_enhancement_v1';
	private const COLOR_ROLES  = [ 'brand', 'accent', 'hero', 'neutral', 'surface' ];

	public function __construct( private ?Design_Context_Profile_Service $profiles = null ) {
		$this->profiles = $this->profiles ?: new Design_Context_Profile_Service();
	}

	public function approved(): array {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) && self::SCHEMA === ( $value['schema'] ?? '' ) ? $value : [];
	}

	public function owner_context_hash(): string {
		$context = $this->profiles->public_context( false );
		unset( $context['scores'] );
		return hash( 'sha256', (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public function handoff_contract(): array {
		$owner = $this->profiles->public_context( false );
		$locked_colors = [];
		foreach ( is_array( $owner['brand']['colors'] ?? null ) ? $owner['brand']['colors'] : [] as $color ) {
			if ( ! empty( $color['role'] ) && ! empty( $color['ownerSelected'] ) ) $locked_colors[] = 'ownerContext.brand.colors.' . sanitize_key( (string) $color['role'] );
		}
		return [
			'schema' => self::SCHEMA,
			'sourceContextHash' => $this->owner_context_hash(),
			'authority' => [
				'mode' => 'proposal',
				'mayOverwriteOwnerEvidence' => false,
				'requiresAdministratorImport' => true,
				'frameworkOptInRequired' => true,
			],
			'lockedPaths' => array_merge( [
				'ownerContext.identity.siteName', 'ownerContext.identity.logo', 'ownerContext.identity.logoInverse', 'ownerContext.identity.siteIcon',
				'ownerContext.contact', 'ownerContext.localization', 'ownerContext.about', 'ownerContext.services', 'ownerContext.regulatory', 'ownerContext.contentPlan', 'ownerContext.commercePlan',
				'ownerContext.seo.legalName', 'ownerContext.seo.foundedYear', 'ownerContext.seo.allowIndexing',
			], $locked_colors ),
			'writablePaths' => [
				'suggestions.brand.tone', 'suggestions.brand.colors',
				'suggestions.copy.businessDescription', 'suggestions.copy.brandStory', 'suggestions.copy.mission', 'suggestions.copy.vision',
				'suggestions.copy.values', 'suggestions.copy.usp', 'suggestions.copy.audienceSummary',
				'suggestions.seo.homepageDescription', 'suggestions.seo.searchIntent', 'suggestions.seo.contentPriorities',
				'suggestions.design.typographyDirection', 'suggestions.design.imageryDirection', 'suggestions.design.layoutDirection',
			],
			'rules' => [
				'Never invent or replace factual identity, contact, location, commerce, legal, product or content records.',
				'Preserve every owner-selected color. Suggest colors only for unfilled semantic roles.',
				'Do not emit meta keywords or keyword stuffing. Search intent is planning context only.',
				'List uncertain claims in requiresHumanReview instead of presenting them as facts.',
				'A nested Framework profile is optional and remains explicit opt-in; it must not conflict with owner-selected colors.',
			],
			'outputTemplate' => [
				'schema' => self::SCHEMA,
				'sourceContextHash' => $this->owner_context_hash(),
				'authority' => [ 'mayOverwriteOwnerEvidence' => false ],
				'suggestions' => [
					'brand' => [ 'tone' => '', 'colors' => [] ],
					'copy' => [ 'businessDescription' => '', 'brandStory' => '', 'mission' => '', 'vision' => '', 'values' => '', 'usp' => '', 'audienceSummary' => '' ],
					'seo' => [ 'homepageDescription' => '', 'searchIntent' => '', 'contentPriorities' => [] ],
					'design' => [ 'typographyDirection' => '', 'imageryDirection' => '', 'layoutDirection' => '' ],
				],
				'assumptions' => [],
				'requiresHumanReview' => [],
			],
		];
	}

	public function import( array $payload, int $user_id ) {
		if ( self::SCHEMA !== (string) ( $payload['schema'] ?? '' ) ) return new \WP_Error( 'schema', __( 'The file is not a Kiwe Design Context enhancement.', 'dsa' ) );
		if ( array_diff( array_keys( $payload ), [ 'schema','sourceContextHash','authority','suggestions','assumptions','requiresHumanReview','frameworkOptIn','frameworkProfile' ] ) ) return new \WP_Error( 'shape', __( 'The enhancement contains unsupported root fields.', 'dsa' ) );
		if ( $this->owner_context_hash() !== (string) ( $payload['sourceContextHash'] ?? '' ) ) return new \WP_Error( 'stale', __( 'The owner context changed after this proposal was created. Export fresh Design Context and regenerate the enhancement.', 'dsa' ) );
		if ( ! array_key_exists( 'mayOverwriteOwnerEvidence', (array) ( $payload['authority'] ?? [] ) ) || false !== $payload['authority']['mayOverwriteOwnerEvidence'] ) return new \WP_Error( 'authority', __( 'AI enhancements must explicitly declare that they may not overwrite owner evidence.', 'dsa' ) );

		$suggestions = $this->sanitize_suggestions( is_array( $payload['suggestions'] ?? null ) ? $payload['suggestions'] : [] );
		if ( ! array_filter( $suggestions, static fn( $section ): bool => (bool) array_filter( is_array( $section ) ? $section : [] ) ) ) return new \WP_Error( 'empty', __( 'The enhancement contains no usable suggestions.', 'dsa' ) );

		$stored = [
			'schema' => self::SCHEMA,
			'sourceContextHash' => $this->owner_context_hash(),
			'authority' => [ 'mayOverwriteOwnerEvidence'=>false, 'status'=>'approved', 'approvedBy'=>$user_id, 'approvedAt'=>gmdate( 'c' ) ],
			'suggestions' => $suggestions,
			'assumptions' => $this->sanitize_lines( $payload['assumptions'] ?? [], 20, 500 ),
			'requiresHumanReview' => $this->sanitize_lines( $payload['requiresHumanReview'] ?? [], 20, 500 ),
		];
		update_option( self::OPTION, $stored, false );
		return $stored;
	}

	public function resolved_profile( ?array $owner = null ): array {
		$owner = $owner ?: $this->profiles->current();
		$enhancement = $this->approved();
		if ( ! $enhancement || ( $enhancement['sourceContextHash'] ?? '' ) !== $this->owner_context_hash() ) return $owner;
		$s = $enhancement['suggestions'];
		if ( '' !== ( $s['copy']['businessDescription'] ?? '' ) ) $owner['identity']['description'] = $s['copy']['businessDescription'];
		foreach ( [ 'brandStory'=>'story', 'mission'=>'mission', 'vision'=>'vision', 'values'=>'values', 'usp'=>'usp' ] as $suggestion => $owner_key ) {
			if ( empty( $owner['about'][ $owner_key ] ) && '' !== ( $s['copy'][ $suggestion ] ?? '' ) ) $owner['about'][ $owner_key ] = $s['copy'][ $suggestion ];
		}
		if ( '' !== ( $s['seo']['homepageDescription'] ?? '' ) ) $owner['seo']['homepageDescription'] = $s['seo']['homepageDescription'];
		if ( '' !== ( $s['seo']['searchIntent'] ?? '' ) ) $owner['seo']['searchIntent'] = $s['seo']['searchIntent'];
		if ( empty( $owner['brand']['tone'] ) && '' !== ( $s['brand']['tone'] ?? '' ) ) $owner['brand']['tone'] = $s['brand']['tone'];
		$owner_roles = [];
		foreach ( is_array( $owner['brand']['colors'] ?? null ) ? $owner['brand']['colors'] : [] as $color ) $owner_roles[] = (string) ( $color['role'] ?? '' );
		foreach ( is_array( $s['brand']['colors'] ?? null ) ? $s['brand']['colors'] : [] as $color ) {
			if ( ! in_array( $color['role'], $owner_roles, true ) ) $owner['brand']['colors'][] = $color + [ 'ownerSelected'=>false, 'source'=>'approved-ai-enhancement' ];
		}
		$owner['enhancement'] = [
			'schema' => self::SCHEMA,
			'approvedAt' => (string) ( $enhancement['authority']['approvedAt'] ?? '' ),
			'copy' => $s['copy'], 'design' => $s['design'], 'seo' => $s['seo'],
			'assumptions' => $enhancement['assumptions'], 'requiresHumanReview' => $enhancement['requiresHumanReview'],
			'ownerEvidencePreserved' => true,
		];
		return $owner;
	}

	public function resolved_public_context( bool $administrator = false ): array {
		$context = $this->profiles->public_context( $administrator );
		$resolved = $this->resolved_profile( $this->profiles->current() );
		$context['identity']['description'] = $resolved['identity']['description'];
		$context['about'] = $resolved['about'];
		if ( ! $administrator && empty( $context['about']['team']['enabled'] ) ) $context['about']['team']['members'] = [];
		$context['brand'] = $resolved['brand'];
		$context['seo'] = $resolved['seo'];
		$context['enhancement'] = $resolved['enhancement'] ?? [ 'ownerEvidencePreserved'=>true, 'status'=>'none-or-stale' ];
		$context['authority'] = [ 'ownerEvidence'=>'locked', 'enhancement'=>'administrator-approved', 'resolution'=>'owner-facts-win' ];
		return $context;
	}

	public function validate_framework_tokens( array $tokens ) {
		$overrides = is_array( $tokens['overrides'] ?? null ) ? $tokens['overrides'] : [];
		$owner = $this->profiles->current();
		foreach ( is_array( $owner['brand']['colors'] ?? null ) ? $owner['brand']['colors'] : [] as $color ) {
			$role = sanitize_key( (string) ( $color['role'] ?? '' ) );
			$expected = strtolower( (string) sanitize_hex_color( (string) ( $color['hex'] ?? '' ) ) );
			$actual = strtolower( (string) sanitize_hex_color( (string) ( $overrides[ 'color-' . $role ] ?? '' ) ) );
			if ( $expected && $actual && $expected !== $actual ) return new \WP_Error( 'owner_color_conflict', sprintf( __( 'Framework color %s conflicts with the owner-selected color.', 'dsa' ), 'color-' . $role ) );
		}
		return true;
	}

	public function apply_owner_colors_to_framework_tokens( array $tokens ): array {
		$tokens['overrides'] = is_array( $tokens['overrides'] ?? null ) ? $tokens['overrides'] : [];
		foreach ( (array) ( $this->profiles->current()['brand']['colors'] ?? [] ) as $color ) {
			$role = sanitize_key( (string) ( $color['role'] ?? '' ) );
			$hex = strtolower( (string) sanitize_hex_color( (string) ( $color['hex'] ?? '' ) ) );
			if ( in_array( $role, self::COLOR_ROLES, true ) && $hex ) $tokens['overrides'][ 'color-' . $role ] = $hex;
		}
		return $tokens;
	}

	private function sanitize_suggestions( array $input ): array {
		$brand = is_array( $input['brand'] ?? null ) ? $input['brand'] : [];
		$copy = is_array( $input['copy'] ?? null ) ? $input['copy'] : [];
		$seo = is_array( $input['seo'] ?? null ) ? $input['seo'] : [];
		$design = is_array( $input['design'] ?? null ) ? $input['design'] : [];
		$owner_roles = [];
		foreach ( (array) ( $this->profiles->current()['brand']['colors'] ?? [] ) as $color ) $owner_roles[] = (string) ( $color['role'] ?? '' );
		$colors = [];
		foreach ( array_slice( is_array( $brand['colors'] ?? null ) ? $brand['colors'] : [], 0, 5 ) as $color ) {
			if ( ! is_array( $color ) ) continue;
			$role = sanitize_key( (string) ( $color['role'] ?? '' ) ); $hex = strtolower( (string) sanitize_hex_color( (string) ( $color['hex'] ?? '' ) ) );
			if ( ! in_array( $role, self::COLOR_ROLES, true ) || in_array( $role, $owner_roles, true ) || ! $hex ) continue;
			$colors[] = [ 'role'=>$role, 'token'=>'color-'.$role, 'hex'=>$hex, 'name'=>substr( sanitize_text_field( (string) ( $color['name'] ?? 'AI derived' ) ), 0, 80 ), 'rationale'=>substr( sanitize_text_field( (string) ( $color['rationale'] ?? '' ) ), 0, 300 ) ];
		}
		$tone = in_array( $brand['tone'] ?? '', [ 'pastel','vibrant','muted','natural','dark','light','luxury','playful','minimal','' ], true ) ? $brand['tone'] : '';
		return [
			'brand' => [ 'tone'=>$tone, 'colors'=>$colors ],
			'copy' => [
				'businessDescription'=>substr( sanitize_textarea_field( (string) ( $copy['businessDescription'] ?? '' ) ), 0, 2000 ),
				'brandStory'=>substr( sanitize_textarea_field( (string) ( $copy['brandStory'] ?? '' ) ), 0, 3000 ),
				'mission'=>substr( sanitize_textarea_field( (string) ( $copy['mission'] ?? '' ) ), 0, 2000 ),
				'vision'=>substr( sanitize_textarea_field( (string) ( $copy['vision'] ?? '' ) ), 0, 2000 ),
				'values'=>substr( sanitize_textarea_field( (string) ( $copy['values'] ?? '' ) ), 0, 2000 ),
				'usp'=>substr( sanitize_textarea_field( (string) ( $copy['usp'] ?? '' ) ), 0, 2000 ),
				'audienceSummary'=>substr( sanitize_textarea_field( (string) ( $copy['audienceSummary'] ?? '' ) ), 0, 1600 ),
			],
			'seo' => [
				'homepageDescription'=>substr( sanitize_textarea_field( (string) ( $seo['homepageDescription'] ?? '' ) ), 0, 320 ),
				'searchIntent'=>substr( sanitize_text_field( (string) ( $seo['searchIntent'] ?? '' ) ), 0, 240 ),
				'contentPriorities'=>$this->sanitize_lines( $seo['contentPriorities'] ?? [], 12, 200 ),
			],
			'design' => [
				'typographyDirection'=>substr( sanitize_textarea_field( (string) ( $design['typographyDirection'] ?? '' ) ), 0, 1000 ),
				'imageryDirection'=>substr( sanitize_textarea_field( (string) ( $design['imageryDirection'] ?? '' ) ), 0, 1000 ),
				'layoutDirection'=>substr( sanitize_textarea_field( (string) ( $design['layoutDirection'] ?? '' ) ), 0, 1000 ),
			],
		];
	}

	private function sanitize_lines( $input, int $limit, int $length ): array {
		$out = [];
		foreach ( array_slice( is_array( $input ) ? $input : [], 0, $limit ) as $line ) {
			$value = substr( sanitize_text_field( (string) $line ), 0, $length );
			if ( '' !== $value ) $out[] = $value;
		}
		return array_values( array_unique( $out ) );
	}
}
