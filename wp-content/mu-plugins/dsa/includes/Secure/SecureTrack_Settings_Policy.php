<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Settings_Policy {
	public static function sanitize_kiwe_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$roles = isset( $input['auto_logout_roles'] ) && is_array( $input['auto_logout_roles'] ) ? $input['auto_logout_roles'] : [];
		$valid_roles = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : [];
		$roles = array_values(
			array_intersect(
				array_map( 'sanitize_key', $roles ),
				$valid_roles
			)
		);

		return [
			'enabled'             => ! empty( $input['enabled'] ),
			'protection_profile'  => in_array( sanitize_key( (string) ( $input['protection_profile'] ?? 'monitor' ) ), [ 'monitor', 'recommended' ], true ) ? sanitize_key( (string) $input['protection_profile'] ) : 'monitor',
			'auto_logout_enabled' => ! empty( $input['auto_logout_enabled'] ),
			'auto_logout_minutes' => max( 1, min( 1440, absint( $input['auto_logout_minutes'] ?? ( $current['auto_logout_minutes'] ?? 30 ) ) ) ),
			'auto_logout_roles'   => $roles,
			'trusted_proxy_cidrs' => self::sanitize_cidr_lines( $input['trusted_proxy_cidrs'] ?? ( $current['trusted_proxy_cidrs'] ?? '' ) ),
			'schema_integrity_guard_enabled' => ! empty( $input['schema_integrity_guard_enabled'] ),
			'seo_spam_410_enabled' => ! empty( $input['seo_spam_410_enabled'] ),
			'harden_public_rest_users' => ! empty( $input['harden_public_rest_users'] ),
			'security_headers_enabled' => ! empty( $input['security_headers_enabled'] ),
			'image_resource_guard_enabled' => ! empty( $input['image_resource_guard_enabled'] ),
			'image_big_size_threshold' => max( 1600, min( 4096, absint( $input['image_big_size_threshold'] ?? ( $current['image_big_size_threshold'] ?? 2560 ) ) ) ),
			'image_max_dimension' => max( 4096, min( 20000, absint( $input['image_max_dimension'] ?? ( $current['image_max_dimension'] ?? 12000 ) ) ) ),
			'image_max_megapixels' => max( 16, min( 120, absint( $input['image_max_megapixels'] ?? ( $current['image_max_megapixels'] ?? 40 ) ) ) ),
		];
	}

	private static function sanitize_cidr_lines( $value ): string {
		$lines = preg_split( '/[\r\n,]+/', (string) $value ) ?: [];
		$valid = [];
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) continue;
			$parts = explode( '/', $line, 2 );
			$ip = filter_var( $parts[0], FILTER_VALIDATE_IP );
			if ( false === $ip ) continue;
			$is_ipv6 = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
			$max = $is_ipv6 ? 128 : 32;
			$minimum = $is_ipv6 ? 32 : 8;
			$bits = isset( $parts[1] ) ? (int) $parts[1] : $max;
			if ( $bits < $minimum || $bits > $max ) continue;
			$valid[] = $ip . '/' . $bits;
		}
		return implode( "\n", array_values( array_unique( $valid ) ) );
	}


	/** Partial native policy save: retain unrelated keys, recovery, AI credentials and timing policy. */
	public static function update_app_policy( array $changes ) {
		if ( ! \DSA\Apps\Required_App_Service::allowed( 'security' ) ) return new \WP_Error( 'kiwe_security_forbidden', __( 'Security policy is unavailable.', 'dsa' ) );
		$allowed = \DSA\Access\System_Control_Catalogue::fields( 'security', \DSA\Apps\Required_App_Service::technical() );
		if ( array_diff_key( $changes, $allowed ) ) return new \WP_Error( 'kiwe_security_field', __( 'Choose an available security control.', 'dsa' ) );
		$stored = (array) get_option( 'stp_settings', [] ); $current = self::notification_policy(); $next = $stored;
		foreach ( $changes as $key=>$value ) $next[$key] = is_bool( $value ) ? (int) $value : $value;
		$effective = array_replace( $current, $next );
		if ( (int) ( $effective['yellow_threshold'] ?? 25 ) >= (int) ( $effective['red_threshold'] ?? 60 ) ) return new \WP_Error( 'kiwe_security_threshold', __( 'The warning score must be lower than the critical score.', 'dsa' ) );
		foreach ( [ 'country_blocklist','login_allowed_countries' ] as $key ) if ( array_key_exists( $key, $changes ) ) {
			$codes = self::normalize_country_codes( $changes[$key] );
			$next[$key] = implode( ',', $codes );
		}
		update_option( 'stp_settings', $next, false );
		wp_cache_delete( 'settings', 'securetrack_pro' );
		if ( function_exists( 'stp_cfg' ) ) stp_cfg( true );
		if ( (array) get_option( 'stp_settings', [] ) !== $next ) return new \WP_Error( 'kiwe_security_readback', __( 'Security settings could not be confirmed. Reload and review.', 'dsa' ) );
		return true;
	}
	public static function refresh_runtime_cache(): void {
		wp_cache_delete( 'settings', 'securetrack_pro' );
		if ( function_exists( 'stp_cfg' ) ) stp_cfg( true );
	}
	public static function notification_policy(): array {
		if ( function_exists( 'stp_cfg' ) ) return (array) stp_cfg();
		return array_replace( (array) get_option( 'stp_settings', [] ), [ 'alert_on_red'=>1 ] );
	}

	public static function update_notification_policy( array $input ): array {
		$stored = (array) get_option( 'stp_settings', [] );
		$mode = sanitize_key( (string) ( $input['alert_delivery_policy'] ?? 'actionable' ) );
		// Legacy pause state stays recoverable; runtime notices use personal topic choices.
		// alert_email is retained only as a backwards-compatible data field.
		// Recipients and delivery lanes now belong to Kiwe Notifications.
		$email = sanitize_email( (string) ( $input['alert_email'] ?? ( $stored['alert_email'] ?? '' ) ) );
		if ( is_email( $email ) ) $stored['alert_email'] = $email;
		$stored['alert_delivery_policy'] = in_array( $mode, [ 'actionable', 'yellow_and_actionable' ], true ) ? $mode : 'actionable';
		$stored['alert_repeat_window_mins'] = max( 1, min( 1440, (int) ( $input['alert_repeat_window_mins'] ?? 15 ) ) );
		$stored['alert_hourly_limit'] = max( 1, min( 100, (int) ( $input['alert_hourly_limit'] ?? 8 ) ) );
		update_option( 'stp_settings', $stored, false );
		wp_cache_delete( 'settings', 'securetrack_pro' );
		if ( function_exists( 'stp_cfg' ) ) stp_cfg( true );
		return [ 'ok' => true, 'policy' => $stored ];
	}

	public static function sync_from_kiwe( array $secure ): void {
		$settings = (array) get_option( 'stp_settings', [] );
		$engine_enabled = ! empty( $secure['enabled'] );
		$auto_logout_enabled = $engine_enabled && ! empty( $secure['auto_logout_enabled'] );
		$roles = self::normalize_roles( $secure['auto_logout_roles'] ?? [] );
		$profile = sanitize_key( (string) ( $secure['protection_profile'] ?? 'monitor' ) );
		$profile = in_array( $profile, [ 'monitor', 'recommended' ], true ) ? $profile : 'monitor';

		if ( ! $engine_enabled ) {
			$settings = self::disable_enforcement_settings( $settings );
		}

		$settings['idle_timeout_mins'] = $auto_logout_enabled && $roles ? max( 1, min( 1440, absint( $secure['auto_logout_minutes'] ?? 30 ) ) ) : 0;
		$settings['idle_timeout_roles'] = $auto_logout_enabled ? $roles : [];

		if ( $engine_enabled && 'recommended' === $profile ) {
			$settings['emergency_safe_mode'] = 0;
			$settings['endpoint_rate_limits'] = 1;
			$settings['block_brute_force'] = 1;
			$settings['adaptive_waf'] = 1;
			$settings['honeypot_enabled'] = 1;
			$settings['tarpit_enabled'] = 0;
			$settings['harden_xmlrpc'] = 1;
			$settings['harden_rest_users'] = 1;
			$settings['harden_file_editor'] = 1;
			$settings['harden_wp_generator'] = 1;
			$settings['harden_security_headers'] = 1;
			$settings['track_admin_activity'] = 1;
			$settings['rl_login_per_min'] = 20;
			$settings['rl_xmlrpc_per_min'] = 10;
			$settings['rl_rest_per_min'] = 300;
			$settings['rl_admin_per_min'] = 600;
			$settings['rl_frontend_per_min'] = 1200;
		}

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

		if ( get_option( 'stp_ai_broker_secret_cleanup_v1' ) !== 'done' ) {
			$stored_settings['v2_ai_key'] = '';
			$stored_settings['v2_ai_key_enc'] = '';
			update_option( 'stp_ai_broker_secret_cleanup_v1', 'done', false );
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'stp_settings', $stored_settings, false );
		}

		return $stored_settings;
	}

	public static function normalize_runtime_config( array $config ): array {
		// Do not pause the native security inbox. Severity and flood limits still apply.
		$config['alert_on_red'] = 1;
		$config['break_glass_slug'] = stp_sanitize_break_glass_slug( $config['break_glass_slug'] ?? '' );
		$config['alert_delivery_policy'] = sanitize_key( $config['alert_delivery_policy'] ?? 'actionable' );
		if ( ! in_array( $config['alert_delivery_policy'], [ 'actionable', 'yellow_and_actionable' ], true ) ) {
			$config['alert_delivery_policy'] = 'actionable';
		}
		$config['v2_ai_provider'] = sanitize_key( $config['v2_ai_provider'] ?? 'none' );
		if ( ! in_array( $config['v2_ai_provider'], [ 'none', 'wordpress_ai_client', 'openai_compatible', 'gemini', 'groq', 'xai' ], true ) ) {
			$config['v2_ai_provider'] = 'none';
		}

		$config['v2_ai_model'] = preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) ( $config['v2_ai_model'] ?? 'gemini-3.7-flash' ) );
		$config['v2_ai_model'] = preg_replace( '#^models/#', '', $config['v2_ai_model'] );
		if ( $config['v2_ai_model'] === '' && in_array( $config['v2_ai_provider'], [ 'gemini', 'groq', 'xai' ], true ) ) {
			$config['v2_ai_model'] = 'gemini-3.7-flash';
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
