<?php

namespace DSA\AI;

use DSA\Access\AI_Workspace_Service;
use DSA\Apps\MCP_Provider_Contract as Contract;
use DSA\MCP\Authoring_Service as Native;
use DSA\MCP\Credentials;
use DSA\Security\Secret_Store;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/** The AI App exposes its native settings while credential entry stays on the site. */
final class MCP_AI_Provider {

	private const CONTROLS = [ 'allowNativeGeneration', 'seoAllowed', 'securetrackAllowed', 'requestLimits', 'tokenLimits' ];

	public static function packet(): array {
		$object = static fn( array $properties, array $required = [] ): array => [
			'type'=>'object', 'properties'=>$properties, 'required'=>$required, 'additionalProperties'=>false,
		];
		$boolean = [ 'type'=>'boolean' ];
		$limit = [ 'type'=>'integer', 'minimum'=>0 ];
		$windows = [ 'minute', 'day', 'month' ];
		$limits = $object( array_fill_keys( $windows, $limit ), $windows );
		$common = [
			'accessVersion'=>1, 'outputSchema'=>$object( [ 'resultJson'=>[ 'type'=>'string', 'maxLength'=>6000 ] ], [ 'resultJson' ] ),
			'discoverPermission'=>[ self::class, 'allowed' ], 'permission'=>[ self::class, 'allowed' ],
		];
		return [
			'schema'=>Contract::SCHEMA, 'api'=>Contract::API, 'app'=>'ai',
			'setup'=>[ 'state'=>'ready', 'needs'=>[], 'providerConsentRequired'=>false ],
			'operations'=>[
				'ai.configuration.read'=>$common + [
					'scope'=>'ai.configuration.read', 'effect'=>'read', 'approval'=>'none', 'inputSchema'=>$object( [] ),
					'label'=>'Read the website AI setup and open protected credential settings',
					'execute'=>static fn() => self::result( self::current()['ai'] ),
				],
				'ai.configuration.change'=>$common + [
					'scope'=>'ai.configuration.write', 'effect'=>'write', 'approval'=>'required',
					'label'=>'Review website AI provider, model, assistance and allowances',
					'inputSchema'=>$object( [
						'provider'=>[ 'type'=>'string', 'maxLength'=>40, 'enum'=>AI_Configuration_Service::PROVIDERS ],
						'model'=>[ 'type'=>'string', 'maxLength'=>160, 'description'=>'Provider model identifier. Enter credentials in protected AI settings.' ],
						'allowNativeGeneration'=>$boolean, 'seoAllowed'=>$boolean, 'securetrackAllowed'=>$boolean,
						'requestLimits'=>$limits, 'tokenLimits'=>$limits,
					] ),
					'prepare'=>[ self::class, 'prepare' ],
					'current'=>static fn( array $input ): string => self::proposal( $input )['expected'],
					'execute'=>[ self::class, 'apply' ],
				],
			],
		];
	}

	public static function allowed(): bool {
		return AI_Configuration_Service::owner_allowed() && Credentials::actor_allowed( get_current_user_id() );
	}

	private static function current(): array {
		if ( ! self::allowed() ) throw new \RuntimeException( 'Your current role cannot manage website AI settings.' );
		$raw = get_option( DSA_OPTION_SETTINGS, null );
		if ( ! is_array( $raw ) ) throw new \RuntimeException( 'Website settings are unavailable. Refresh and review.' );
		return [ 'ai'=>is_array( $raw['ai'] ?? null ) ? $raw['ai'] : [], 'settings'=>new Settings() ];
	}

	private static function model_safe( string $model, string $sealed ): bool {
		if ( str_contains( $model, '://' ) || preg_match( '~(?:AQ\.[A-Za-z0-9_-]{20,}|AIza[A-Za-z0-9_-]{24,}|(?:sk-|gsk_|xai-)[A-Za-z0-9_-]{18,})~', $model ) ) return false;
		$credential = Secret_Store::decrypt( $sealed );
		return '' === $credential || ! str_contains( $model, $credential );
	}

	private static function result( array $ai ): array {
		$settings = new Settings();
		$view = AI_Configuration_Service::owner_snapshot( $ai, $settings );
		$image = ( new AI_Provider_Service( $settings ) )->image_status();
		$model = $view['provider']['model'];
		return [ 'resultJson'=>Native::json( array_intersect_key( $view, array_flip( self::CONTROLS ) ) + [
			'provider'=>$view['provider']['id'], 'model'=>self::model_safe( $model, (string) ( $ai['native_api_key'] ?? '' ) ) ? $model : '',
			'providerConfigured'=>$view['provider']['configured'], 'credentialStored'=>$view['keyStored'],
			'featuredImageReady'=>$image['ready'], 'featuredImageModel'=>$image['model'],
			'featuredImageRequiresPaidProject'=>$image['requiresPaidProject'] ?? true, 'featuredImageQuotaVerified'=>$image['providerQuotaVerified'] ?? false,
			'protectedSetupUrl'=>AI_Workspace_Service::url(),
			'next'=>'Use the protected setup link to add, replace or remove a provider credential. Then read the setup again before enabling requests.',
		] ) ];
	}

	private static function proposal( array $input ): array {
		if ( ! $input || array_diff( array_keys( $input ), [ ...self::CONTROLS, 'provider', 'model' ] ) ) throw new \RuntimeException( 'Choose the supported AI settings. Enter credentials in the protected AI screen.' );
		$state = self::current();
		$ai = $state['ai'];
		$current = AI_Configuration_Service::owner_snapshot( $ai, $state['settings'] );
		$before = [];
		$changes = array_intersect_key( $input, array_flip( self::CONTROLS ) );
		$configuration = [];
		if ( array_key_exists( 'provider', $input ) ) {
			$before['provider'] = $current['provider']['id'];
			$configuration['native_provider'] = $input['provider'];
			if ( $input['provider'] !== $before['provider'] && $current['keyStored'] ) throw new \RuntimeException( 'Use protected AI settings to change the provider when a key is already stored.' );
			if ( $input['provider'] !== $before['provider'] && ( $input['allowNativeGeneration'] ?? $current['allowNativeGeneration'] ) ) throw new \RuntimeException( 'Pause new AI requests before changing the provider.' );
		}
		if ( array_key_exists( 'model', $input ) ) {
			$sealed = (string) ( $ai['native_api_key'] ?? '' );
			if ( ! is_string( $input['model'] ) || ! self::model_safe( $input['model'], $sealed ) ) throw new \RuntimeException( 'Enter a model identifier. Use protected AI settings for provider credentials.' );
			$old_model = (string) ( $ai['native_model'] ?? '' );
			$before['model'] = self::model_safe( $old_model, $sealed ) ? $old_model : '';
			$configuration['native_model'] = $input['model'];
		}
		if ( ! empty( $input['allowNativeGeneration'] ) && ( 'none' === $current['provider']['id'] || ! $current['provider']['configured'] ) ) throw new \RuntimeException( 'Complete the protected provider setup before enabling AI requests.' );
		if ( $configuration ) $changes['configuration'] = $configuration;
		$next = Native::native( AI_Configuration_Service::patch_owner( $ai, $changes ) );
		$after = $input;
		if ( isset( $changes['requestLimits'] ) ) $after['requestLimits'] = $next['request_allowance'];
		if ( isset( $changes['tokenLimits'] ) ) $after['tokenLimits'] = $next['token_allowance'];
		return [ 'expected'=>$current['expected'], 'before'=>$before + array_intersect_key( $current, $input ), 'after'=>$after, 'changes'=>$changes ];
	}

	public static function prepare( array $input ): array {
		$plan = self::proposal( $input );
		return Native::plan( 'Change website AI settings', $plan['expected'], $plan['before'], $plan['after'], true );
	}

	public static function apply( array $input, string $expected ): array {
		$plan = self::proposal( $input );
		if ( ! hash_equals( $plan['expected'], $expected ) ) throw new \RuntimeException( 'Website AI settings changed. Review a fresh proposal.' );
		Native::native( AI_Configuration_Service::save_owner( $plan['changes'], $expected ) );
		return self::result( self::current()['ai'] );
	}
}
