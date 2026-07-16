<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Runtime_Guard {
	public static function enforcement_paused(): bool {
		$constant_paused = defined( 'STP_EMERGENCY_SAFE_MODE' ) && STP_EMERGENCY_SAFE_MODE;
		$settings_paused = ! empty( stp_cfg()['emergency_safe_mode'] );

		return (bool) apply_filters( 'stp_emergency_safe_mode', $constant_paused || $settings_paused );
	}

	public static function is_block_check_exempt(): bool {
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$action = sanitize_key( $_REQUEST['action'] ?? '' );

		if ( $action === 'stp_exit' ) {
			return true;
		}

		if ( strpos( $action, 'stp_' ) === 0 && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( stp_break_glass_session_valid() ) {
			if ( strpos( $uri, 'wp-login.php' ) !== false ) {
				return true;
			}
			if ( preg_match( '#/wp-admin/load-(?:styles|scripts)\.php#i', $uri ) ) {
				return true;
			}
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				return true;
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}

		return false;
	}

	public static function is_static_request(): bool {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

		return (bool) preg_match( '/\.(?:css|js|mjs|map|jpg|jpeg|png|gif|webp|avif|svg|ico|woff2?|ttf|eot|mp4|webm|mp3|wav|pdf|zip|rar|7z)(?:$|\?)/i', (string) $path );
	}

	public static function enforce_idle_timeout(): void {
		$is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		if ( ! is_user_logged_in() || $is_ajax || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! SecureTrack_Settings_Policy::kiwe_allows_auto_logout() ) {
			return;
		}

		$config = stp_cfg();
		$mins = max( 0, (int) ( $config['idle_timeout_mins'] ?? 0 ) );
		$roles = array_filter( array_map( 'sanitize_key', (array) ( $config['idle_timeout_roles'] ?? [] ) ) );

		if ( ! $mins || empty( $roles ) ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! array_intersect( $roles, (array) $user->roles ) ) {
			return;
		}

		$uid = get_current_user_id();
		$key = 'stp_last_seen_' . $uid;
		$last = (int) get_user_meta( $uid, $key, true );

		if ( $last && ( time() - $last ) > ( $mins * 60 ) ) {
			delete_user_meta( $uid, $key );
			wp_logout();
			wp_safe_redirect( wp_login_url() . '?stp_idle=1' );
			exit;
		}

		update_user_meta( $uid, $key, time() );
	}

	public static function endpoint_type(): string {
		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#/xmlrpc\.php$#i', $path ) ) {
			return 'xmlrpc';
		}
		if ( preg_match( '#/wp-login\.php$#i', $path ) ) {
			return 'login';
		}
		if ( strpos( $path, '/wp-json/' ) !== false ) {
			return 'rest';
		}
		if ( preg_match( '#/wp-admin/admin-ajax\.php$#i', $path ) ) {
			return 'admin_ajax';
		}
		if ( is_admin() ) {
			return 'admin_page';
		}

		return 'frontend';
	}

	public static function endpoint_rate_limit_guard(): void {
		$config = stp_cfg();
		if ( self::enforcement_paused() || empty( $config['endpoint_rate_limits'] ) || self::is_static_request() || self::is_block_check_exempt() ) {
			return;
		}

		$type = self::endpoint_type();
		if ( is_user_logged_in() && in_array( $type, [ 'admin_page', 'admin_ajax', 'frontend' ], true ) ) {
			return;
		}

		if ( self::is_frontend_pageview( $type ) ) {
			return;
		}

		if ( self::is_kiwe_interactive_endpoint() ) {
			return;
		}

		$limits = [
			'login'      => (int) $config['rl_login_per_min'],
			'xmlrpc'     => (int) $config['rl_xmlrpc_per_min'],
			'rest'       => (int) $config['rl_rest_per_min'],
			'admin_ajax' => (int) $config['rl_admin_per_min'],
			'admin_page' => max( 600, (int) $config['rl_admin_per_min'] * 5 ),
			'frontend'   => (int) $config['rl_frontend_per_min'],
		];
		$limit = max( 5, (int) ( $limits[ $type ] ?? 120 ) );
		$ip = stp_get_ip();

		if ( stp_ip_status_is_trusted( $ip ) && in_array( $type, [ 'frontend', 'rest', 'admin_ajax' ], true ) ) {
			$limit = max( $limit * 4, 360 );
		}

		$limit = (int) apply_filters( 'stp_endpoint_rate_limit', $limit, $type, $ip );
		if ( $limit <= 0 || stp_rate_limit( 'endpoint|' . $type . '|' . $ip, $limit, 60 ) ) {
			return;
		}

		$response = 'Too many requests. SecureTrack temporarily rate limited this endpoint.';
		stp_log(
			'protection_block',
			[
				'sub'   => 'endpoint_rate_limit',
				'url'   => home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) ),
				'extra' => [
					'endpoint'       => $type,
					'limit_per_min'  => $limit,
					'response_code'  => 429,
					'response_shown' => $response,
				],
			]
		);

		status_header( 429 );
		wp_die( '<h1>Too Many Requests</h1><p>' . esc_html( $response ) . '</p>', 'Too Many Requests', [ 'response' => 429 ] );
	}

	private static function is_frontend_pageview( string $type ): bool {
		if ( $type !== 'frontend' ) {
			return false;
		}

		return ! preg_match( '#/(?:wp-login\.php|xmlrpc\.php|wp-json/|wp-admin/admin-ajax\.php)#i', (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
	}

	private static function is_kiwe_interactive_endpoint(): bool {
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH );
		$exempt_paths = (array) apply_filters(
			'stp_endpoint_rate_limit_exempt_paths',
			[
				'/wp-json/dsa/',
				'/wp-json/wc/store/',
			]
		);

		foreach ( $exempt_paths as $needle ) {
			$needle = (string) $needle;
			if ( $needle !== '' && strpos( $path, $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
