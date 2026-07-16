<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Settings_Policy {
	public static function sync_from_kiwe( array $secure ): void {
		$settings = (array) get_option( 'stp_settings', [] );
		$engine_enabled = ! empty( $secure['enabled'] );
		$auto_logout_enabled = $engine_enabled && ! empty( $secure['auto_logout_enabled'] );
		$roles = self::normalize_roles( $secure['auto_logout_roles'] ?? [] );

		if ( ! $engine_enabled ) {
			$settings = self::disable_enforcement_settings( $settings );
		}

		$settings['idle_timeout_mins'] = $auto_logout_enabled && $roles ? max( 1, min( 1440, absint( $secure['auto_logout_minutes'] ?? 30 ) ) ) : 0;
		$settings['idle_timeout_roles'] = $auto_logout_enabled ? $roles : [];

		update_option( 'stp_settings', $settings, false );

		if ( function_exists( 'stp_cfg' ) ) {
			stp_cfg( true );
		}
	}

	public static function apply_default_off_migrations( array $stored_settings ): array {
		$changed = false;

		if ( get_option( 'stp_endpoint_rate_limit_default_off_v1' ) !== 'done' ) {
			$stored_settings['endpoint_rate_limits'] = 0;
			update_option( 'stp_endpoint_rate_limit_default_off_v1', 'done', false );
			$changed = true;
		}

		if ( get_option( 'stp_idle_timeout_default_off_v1' ) !== 'done' ) {
			$stored_settings['idle_timeout_mins'] = 0;
			$stored_settings['idle_timeout_roles'] = [];
			update_option( 'stp_idle_timeout_default_off_v1', 'done', false );
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'stp_settings', $stored_settings, false );
		}

		return $stored_settings;
	}

	public static function normalize_runtime_config( array $config ): array {
		$config['break_glass_slug'] = stp_sanitize_break_glass_slug( $config['break_glass_slug'] ?? '' );
		$config['v2_ai_provider'] = sanitize_key( $config['v2_ai_provider'] ?? 'none' );
		if ( ! in_array( $config['v2_ai_provider'], [ 'none', 'gemini', 'groq', 'xai' ], true ) ) {
			$config['v2_ai_provider'] = 'none';
		}

		$config['v2_ai_model'] = preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) ( $config['v2_ai_model'] ?? 'gemini-2.5-flash' ) );
		$config['v2_ai_model'] = preg_replace( '#^models/#', '', $config['v2_ai_model'] );
		if ( $config['v2_ai_model'] === '' ) {
			$config['v2_ai_model'] = 'gemini-2.5-flash';
		}

		$config['v2_ai_mode'] = sanitize_key( $config['v2_ai_mode'] ?? 'batch' );
		if ( ! in_array( $config['v2_ai_mode'], [ 'batch', 'always' ], true ) ) {
			$config['v2_ai_mode'] = 'batch';
		}

		$config['v2_ai_batch_mins'] = max( 1, min( 60, (int) ( $config['v2_ai_batch_mins'] ?? 5 ) ) );
		$config['v2_uncertain_low'] = max( 1, min( 95, (int) ( $config['v2_uncertain_low'] ?? 30 ) ) );
		$config['v2_uncertain_high'] = max( $config['v2_uncertain_low'] + 1, min( 99, (int) ( $config['v2_uncertain_high'] ?? 70 ) ) );
		$config['csp_report_uri'] = stp_same_site_url( (string) ( $config['csp_report_uri'] ?? '' ) );
		$config['country_blocklist_codes'] = self::normalize_country_codes( $config['country_blocklist'] ?? '' );
		$config['login_country_policy'] = sanitize_key( $config['login_country_policy'] ?? 'off' );
		if ( ! in_array( $config['login_country_policy'], [ 'off', 'deny', 'ban' ], true ) ) {
			$config['login_country_policy'] = 'off';
		}
		$config['login_allowed_country_codes'] = self::normalize_country_codes( $config['login_allowed_countries'] ?? '' );
		$config['idle_timeout_roles'] = self::normalize_roles( $config['idle_timeout_roles'] ?? [] );

		foreach ( [ 'rl_login_per_min', 'rl_xmlrpc_per_min', 'rl_rest_per_min', 'rl_admin_per_min', 'rl_frontend_per_min' ] as $rl_key ) {
			$config[ $rl_key ] = max( 5, min( 2000, (int) ( $config[ $rl_key ] ?? 60 ) ) );
		}

		return $config;
	}

	public static function kiwe_secure_settings(): array {
		if ( ! defined( 'DSA_OPTION_SETTINGS' ) ) {
			return [];
		}

		$dsa_settings = get_option( DSA_OPTION_SETTINGS, [] );
		if ( ! is_array( $dsa_settings ) || ! isset( $dsa_settings['secure'] ) || ! is_array( $dsa_settings['secure'] ) ) {
			return [];
		}

		return $dsa_settings['secure'];
	}

	public static function kiwe_allows_auto_logout(): bool {
		$secure = self::kiwe_secure_settings();
		return ! empty( $secure['enabled'] ) && ! empty( $secure['auto_logout_enabled'] );
	}

	private static function disable_enforcement_settings( array $settings ): array {
		$settings['emergency_safe_mode'] = 1;
		$settings['endpoint_rate_limits'] = 0;
		$settings['block_brute_force'] = 0;
		$settings['adaptive_waf'] = 0;
		$settings['honeypot_enabled'] = 0;
		$settings['tarpit_enabled'] = 0;
		$settings['login_country_policy'] = 'off';
		$settings['idle_timeout_mins'] = 0;
		$settings['idle_timeout_roles'] = [];

		return $settings;
	}

	private static function normalize_country_codes( $value ): array {
		$codes = preg_split( '/[\s,]+/', strtoupper( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $code ): string {
							$code = preg_replace( '/[^A-Z]/', '', (string) $code );
							return strlen( $code ) === 2 ? $code : '';
						},
						$codes
					)
				)
			)
		);
	}

	private static function normalize_roles( $roles ): array {
		$roles = is_array( $roles ) ? $roles : [];
		$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );

		if ( function_exists( 'wp_roles' ) ) {
			$valid_roles = array_keys( wp_roles()->get_names() );
			$roles = array_values( array_intersect( $roles, $valid_roles ) );
		}

		return $roles;
	}
}
