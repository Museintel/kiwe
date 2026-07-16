<?php
/**
 * Plugin Name:  SecureTrack Pro
 * Description:  Local-first WordPress security intelligence — reflex blocking, self-learning
 *               site baselines, optional AI second opinion, risk scoring, IP reputation,
 *               page navigation trails, protected-event ledger, and admin intelligence dashboards.
 * Version:      2.0.15
 * Requires PHP: 8.2
 * Author:       Custom Build
 * License:      GPL-2.0+
 *
 * ═══ INSTALLATION ═══════════════════════════════════════════════════════
 *   Option A (auto-active, no "Activate" click needed):
 *     → Upload to /wp-content/mu-plugins/securetrack-pro.php
 *
 *   Option B (normal plugin):
 *     → Upload to /wp-content/plugins/securetrack-pro/securetrack-pro.php
 *       then activate via Plugins admin page.
 *
 * ═══ DATABASE TABLES CREATED ════════════════════════════════════════════
 *   {prefix}stp_ips       — IP reputation, geo cache, hit counters
 *   {prefix}stp_sessions  — Visitor/user sessions (cookie-stitched)
 *   {prefix}stp_events    — All security events with risk scores
 *   {prefix}stp_profiles  — Per-user behavioral baselines (learning engine)
 *   {prefix}stp_pages     — Per-session page navigation log (aggressively trimmed)
 *
 * ═══ PERFORMANCE DESIGN ═════════════════════════════════════════════════
 *   • Geo lookups run in background via WP-Cron (batch, never on critical path)
 *   • Page-time tracking uses navigator.sendBeacon (non-blocking JS)
 *   • Green & yellow data auto-trimmed on schedule; red data kept forever
 *   • Rate limiter uses an atomic local counter table, with transient fallback
 *   • All hot-path DB columns are indexed
 *   • Bot traffic filtered before any writes
 *   • Admin's own page views excluded (configurable)
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/SecureTrack_Settings_Policy.php';
require_once __DIR__ . '/SecureTrack_Runtime_Guard.php';
require_once __DIR__ . '/SecureTrack_Ip_Service.php';
require_once __DIR__ . '/SecureTrack_Event_Service.php';
require_once __DIR__ . '/SecureTrack_Db_Service.php';

// ════════════════════════════════════════════════════════════════
//  CONSTANTS
// ════════════════════════════════════════════════════════════════

define( 'STP_VER', '2.0.15' );
define( 'STP_FILE', __FILE__ );

function stp_break_glass_default_slug() {
	return 'stp-recovery-' . substr( hash( 'sha256', wp_salt( 'auth' ) . home_url() ), 0, 24 );
}

function stp_break_glass_generate_slug() {
	return 'stp-recovery-' . strtolower( wp_generate_password( 32, false, false ) );
}

function stp_sanitize_break_glass_slug( $slug ) {
	$slug = sanitize_title( trim( (string) $slug, " \t\n\r\0\x0B/" ) );
	$reserved = array( 'wp-admin', 'wp-login', 'wp-login-php', 'wp-json', 'xmlrpc', 'admin', 'login', 'register' );
	if ( $slug === '' || in_array( $slug, $reserved, true ) || strlen( $slug ) < 24 ) return stp_break_glass_generate_slug();
	return substr( $slug, 0, 80 );
}

function stp_break_glass_slug() {
	$cfg = stp_cfg();
	return stp_sanitize_break_glass_slug( $cfg['break_glass_slug'] ?? stp_break_glass_default_slug() );
}

function stp_break_glass_path() {
	return '/' . stp_break_glass_slug() . '/';
}

/**
 * Returns the full prefixed table name.
 */
function stp_t( $name ) {
	return \DSA\Secure\SecureTrack_Db_Service::table_name( (string) $name );
}

function stp_safe_table_name( $table ) {
	return \DSA\Secure\SecureTrack_Db_Service::safe_table_name( $table );
}

function stp_same_site_url( $url ) {
	$url = esc_url_raw( (string) $url );
	if ( $url === '' ) return '';
	$home = wp_parse_url( home_url() );
	$dest = wp_parse_url( $url );
	if ( empty( $dest['host'] ) ) return $url;
	return strtolower( $dest['host'] ) === strtolower( $home['host'] ?? '' ) ? $url : '';
}

function stp_secret_key() {
	return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
}

function stp_encrypt_secret( $plain ) {
	$plain = (string) $plain;
	if ( $plain === '' ) return '';
	return class_exists( '\\DSA\\Security\\Secret_Store' ) ? \DSA\Security\Secret_Store::encrypt( $plain ) : '';
}

function stp_decrypt_secret( $stored ) {
	$stored = (string) $stored;
	if ( $stored === '' ) return '';
	if ( class_exists( '\\DSA\\Security\\Secret_Store' ) && \DSA\Security\Secret_Store::is_encrypted( $stored ) ) {
		return \DSA\Security\Secret_Store::decrypt( $stored );
	}
	if ( strpos( $stored, 'enc:v1:' ) === 0 && function_exists( 'openssl_decrypt' ) ) {
		$json = base64_decode( substr( $stored, 7 ), true );
		$payload = $json === false ? null : json_decode( $json, true );
		if ( is_array( $payload ) ) {
			$iv  = base64_decode( (string) ( $payload['iv'] ?? '' ), true );
			$tag = base64_decode( (string) ( $payload['tag'] ?? '' ), true );
			$ct  = base64_decode( (string) ( $payload['ct'] ?? '' ), true );
			if ( $iv !== false && $tag !== false && $ct !== false ) {
				$plain = openssl_decrypt( $ct, 'aes-256-gcm', stp_secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
				if ( $plain !== false ) return (string) $plain;
			}
		}
		return '';
	}
	if ( strpos( $stored, 'legacy:' ) === 0 ) {
		$plain = base64_decode( substr( $stored, 7 ), true );
		return $plain === false ? '' : (string) $plain;
	}
	return $stored;
}

function stp_update_encrypted_option( $option, $plain ) {
	$encrypted = stp_encrypt_secret( (string) $plain );
	if ( '' === $encrypted ) return false;
	update_option( sanitize_key( $option ), $encrypted, false );
	return true;
}

function stp_safe_webhook_url( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) return '';

	$url = esc_url_raw( $url, array( 'https' ) );
	if ( $url === '' || ! wp_http_validate_url( $url ) ) return '';

	$parts  = wp_parse_url( $url );
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );

	if ( $scheme !== 'https' || $host === '' || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) return '';
	if ( $host === 'localhost' || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) return '';

	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$ips = array( $host );
	} else {
		$ips = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
	}

	if ( empty( $ips ) || ! is_array( $ips ) ) return '';

	foreach ( $ips as $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}
	}

	return $url;
}

function stp_resolve_secret_settings( $settings ) {
	$settings = (array) $settings;
	if ( ! empty( $settings['v2_ai_key_enc'] ) ) {
		$settings['v2_ai_key'] = stp_decrypt_secret( $settings['v2_ai_key_enc'] );
	}
	return $settings;
}

function stp_webhook_url( $for_display = false ) {
	$enc = (string) get_option( 'stp_webhook_url_enc', '' );
	$url = $enc !== '' ? stp_decrypt_secret( $enc ) : (string) get_option( 'stp_webhook_url', '' );
	if ( $enc === '' && $url !== '' ) {
		$migrated = stp_encrypt_secret( $url );
		if ( '' !== $migrated ) {
			update_option( 'stp_webhook_url_enc', $migrated, false );
			update_option( 'stp_webhook_url', '', false );
		}
	} elseif ( $enc !== '' && ( strpos( $enc, 'legacy:' ) === 0 || strpos( $enc, 'enc:v1:' ) === 0 ) && $url !== '' ) {
		$migrated = stp_encrypt_secret( $url );
		if ( '' !== $migrated ) update_option( 'stp_webhook_url_enc', $migrated, false );
	}
	$url = stp_safe_webhook_url( $url );
	if ( ! $for_display ) return $url;
	if ( $url === '' ) return '';
	$p = wp_parse_url( $url );
	if ( empty( $p['host'] ) ) return $url;
	return ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . '/...';
}

function stp_webhook_secret() {
	$enc = (string) get_option( 'stp_webhook_secret_enc', '' );
	$secret = $enc !== '' ? stp_decrypt_secret( $enc ) : (string) get_option( 'stp_webhook_secret', '' );
	if ( $enc === '' && $secret !== '' ) {
		$migrated = stp_encrypt_secret( $secret );
		if ( '' !== $migrated ) {
			update_option( 'stp_webhook_secret_enc', $migrated, false );
			update_option( 'stp_webhook_secret', '', false );
		}
	} elseif ( $enc !== '' && ( strpos( $enc, 'legacy:' ) === 0 || strpos( $enc, 'enc:v1:' ) === 0 ) && $secret !== '' ) {
		$migrated = stp_encrypt_secret( $secret );
		if ( '' !== $migrated ) update_option( 'stp_webhook_secret_enc', $migrated, false );
	}
	return $secret;
}

/**
 * Returns merged plugin settings (cached in request memory and object cache).
 */
function stp_cfg( $refresh = false ) {
	static $c = null;
	if ( $c !== null && ! $refresh ) return $c;
	if ( ! $refresh ) {
		$cached = wp_cache_get( 'settings', 'securetrack_pro' );
		if ( is_array( $cached ) ) {
			$c = stp_resolve_secret_settings( $cached );
			return $c;
		}
	}
	$defaults = array(
		'red_threshold'     => 60,
		'yellow_threshold'  => 25,
		'green_trim_days'   => 30,
		'yellow_trim_days'  => 90,
		'alert_email'       => get_option( 'admin_email' ),
		'alert_on_red'      => 1,
		'geo_enabled'       => 0,
		'country_blocklist' => '',
		'login_country_policy' => 'off',
		'login_allowed_countries' => '',
		'emergency_safe_mode' => 1,
		'track_visitors'    => 0,
		'track_pages'       => 0,
		'block_brute_force' => 0,
		'brute_force_limit' => 10,
		'adaptive_waf'      => 0,
		'waf_block_score'   => 80,
		'honeypot_enabled'  => 0,
		'tarpit_enabled'    => 0,
		'exclude_admin'     => 0,
		'harden_xmlrpc'        => 0,
		'harden_rest_users'    => 0,
		'harden_author_archives'=> 0,
		'author_public_slugs'  => 1,
		'harden_file_editor'   => 0,
		'harden_wp_generator'  => 0,
		'harden_security_headers' => 0,
		'csp_enabled'          => 0,
		'csp_report_only'      => 1,
		'csp_report_uri'       => '',
		'security_txt_enabled' => 0,
		'endpoint_rate_limits' => 0,
		'idle_timeout_roles'   => array(),
		'rl_login_per_min'     => 60,
		'rl_xmlrpc_per_min'    => 30,
		'rl_rest_per_min'      => 300,
		'rl_admin_per_min'     => 600,
		'rl_frontend_per_min'  => 1200,
		'idle_timeout_mins'    => 0,
		'adaptive_learning'    => 0,
		'behavioral_risk'      => 0,
		'attack_graph'         => 0,
		'track_admin_activity' => 0,
		'subnet_intel'         => 0,
		'chain_detection'      => 0,
		'chain_window_mins'    => 60,
		'subnet_alert_at'      => 2,
		'break_glass_slug'     => stp_break_glass_default_slug(),
		'v2_site_brain'        => 1,
		'v2_ai_provider'       => 'none',
		'v2_ai_model'          => 'gemini-2.5-flash',
		'v2_ai_mode'           => 'batch',
		'v2_ai_key'            => '',
		'v2_ai_key_enc'        => '',
		'v2_ai_batch_mins'     => 5,
		'v2_uncertain_low'     => 30,
		'v2_uncertain_high'    => 70,
		'v2_auto_block_local'  => 0,
		'v2_share_patterns'    => 0,
	);
	$stored_settings = (array) get_option( 'stp_settings', array() );
	if ( ! empty( $stored_settings['v2_ai_key'] ) && empty( $stored_settings['v2_ai_key_enc'] ) ) {
		$migrated = stp_encrypt_secret( (string) $stored_settings['v2_ai_key'] );
		if ( '' !== $migrated ) {
			$stored_settings['v2_ai_key_enc'] = $migrated;
			$stored_settings['v2_ai_key'] = '';
			update_option( 'stp_settings', $stored_settings );
		}
	}
	if ( empty( $stored_settings['break_glass_slug'] ) || strlen( (string) $stored_settings['break_glass_slug'] ) < 24 ) {
		$stored_settings['break_glass_slug'] = stp_break_glass_generate_slug();
		update_option( 'stp_settings', $stored_settings );
	}
	$stored_settings = \DSA\Secure\SecureTrack_Settings_Policy::apply_default_off_migrations( $stored_settings );
	$c = \DSA\Secure\SecureTrack_Settings_Policy::normalize_runtime_config( stp_resolve_secret_settings( array_merge( $defaults, $stored_settings ) ) );
	$cache_safe = $c;
	$cache_safe['v2_ai_key'] = '';
	wp_cache_set( 'settings', $cache_safe, 'securetrack_pro', 300 );
	return $c;
}

function stp_enforcement_paused() {
	return \DSA\Secure\SecureTrack_Runtime_Guard::enforcement_paused();
}


// ════════════════════════════════════════════════════════════════
//  HARDENING CONTROLS + ATTACK SURFACE SCANNER
// ════════════════════════════════════════════════════════════════

add_action( 'plugins_loaded', 'stp_apply_hardening', 1 );
add_action( 'init', 'stp_apply_hardening', 1 );

function stp_apply_hardening() {
	static $done = false;
	if ( $done ) return;
	$done = true;
	$c = stp_cfg();
	$paused = stp_enforcement_paused();

	if ( ! $paused && ! empty( $c['harden_xmlrpc'] ) ) {
		add_filter( 'xmlrpc_enabled', '__return_false', 999 );
		add_filter( 'xmlrpc_methods', function( $methods ) { return array(); }, 999 );
	}

	if ( ! $paused && ! empty( $c['harden_rest_users'] ) ) {
		add_filter( 'rest_authentication_errors', 'stp_block_rest_user_enumeration', 999 );
	}

	if ( ! $paused && ! empty( $c['harden_author_archives'] ) ) {
		add_action( 'template_redirect', 'stp_block_author_enumeration', 1 );
	} elseif ( ! $paused && ! empty( $c['author_public_slugs'] ) ) {
		add_filter( 'author_link', 'stp_filter_public_author_link', 10, 3 );
		add_filter( 'request', 'stp_translate_public_author_request', 1 );
		add_action( 'template_redirect', 'stp_guard_public_author_archive', 1 );
	}

	if ( ! empty( $c['harden_file_editor'] ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
		define( 'DISALLOW_FILE_EDIT', true );
	}

	if ( ! empty( $c['harden_wp_generator'] ) ) {
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string', 999 );
	}

	if ( ! empty( $c['harden_security_headers'] ) || ! empty( $c['csp_enabled'] ) ) {
		add_action( 'send_headers', 'stp_send_security_headers', 1 );
	}

	if ( ! $paused && ! empty( $c['idle_timeout_mins'] ) ) {
		add_action( 'init', 'stp_enforce_idle_timeout', 2 );
	}
}

function stp_send_security_headers() {
	if ( headers_sent() ) return;
	$c = stp_cfg();
	if ( ! empty( $c['harden_security_headers'] ) ) {
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
		if ( is_ssl() ) header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
	}
	if ( ! empty( $c['csp_enabled'] ) ) {
		$csp = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
		if ( ! empty( $c['csp_report_uri'] ) ) $csp .= '; report-uri ' . esc_url_raw( $c['csp_report_uri'] );
		header( ( ! empty( $c['csp_report_only'] ) ? 'Content-Security-Policy-Report-Only: ' : 'Content-Security-Policy: ' ) . $csp );
	}
}

function stp_enforce_idle_timeout() {
	\DSA\Secure\SecureTrack_Runtime_Guard::enforce_idle_timeout();
}

function stp_block_rest_user_enumeration( $result ) {
	if ( stp_enforcement_paused() ) return $result;
	if ( ! empty( $result ) ) return $result;
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$has_auth_header = ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] );
	if ( ! is_user_logged_in() && ! $has_auth_header && preg_match( '#/wp-json/wp/v2/users#i', $uri ) ) {
		stp_log( 'rest_abuse', array( 'sub' => 'user_enumeration_blocked', 'url' => home_url( $uri ), 'extra' => array( 'door' => 'REST users endpoint' ) ) );
		return new WP_Error( 'stp_rest_users_blocked', 'User enumeration is blocked.', array( 'status' => 403 ) );
	}
	return $result;
}

function stp_block_author_enumeration() {
	if ( stp_enforcement_paused() ) return;
	if ( is_author() && ! is_user_logged_in() ) {
		stp_log( 'page_view', array( 'sub' => 'author_archive_blocked', 'url' => home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '/' ) ), 'admin_probe' => true ) );
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}

function stp_author_public_slugs_enabled() {
	$c = stp_cfg();
	return empty( $c['harden_author_archives'] ) && ! empty( $c['author_public_slugs'] );
}

function stp_public_author_slug( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) return '';
	$slug = (string) get_user_meta( $user_id, '_stp_public_author_slug', true );
	if ( $slug && preg_match( '/^[a-z0-9-]{8,80}$/', $slug ) ) return $slug;
	$slug = 'writer-' . substr( hash( 'sha256', $user_id . '|' . wp_salt( 'auth' ) . '|' . home_url() ), 0, 14 );
	update_user_meta( $user_id, '_stp_public_author_slug', $slug );
	return $slug;
}

function stp_user_by_public_author_slug( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( ! $slug ) return null;
	$users = get_users( array(
		'meta_key'   => '_stp_public_author_slug',
		'meta_value' => $slug,
		'number'     => 1,
		'fields'     => array( 'ID', 'user_nicename' ),
	) );
	if ( ! empty( $users[0] ) ) return $users[0];

	$users = get_users( array(
		'number' => 500,
		'fields' => array( 'ID', 'user_nicename' ),
	) );
	foreach ( $users as $user ) {
		if ( stp_public_author_slug( $user->ID ) === $slug ) return $user;
	}
	return null;
}

function stp_filter_public_author_link( $link, $author_id, $author_nicename ) {
	if ( ! stp_author_public_slugs_enabled() ) return $link;
	$slug = stp_public_author_slug( $author_id );
	global $wp_rewrite;
	$base = ! empty( $wp_rewrite->author_base ) ? trim( $wp_rewrite->author_base, '/' ) : 'author';
	return $slug ? home_url( user_trailingslashit( $base . '/' . $slug ) ) : $link;
}

function stp_translate_public_author_request( $query_vars ) {
	if ( ! stp_author_public_slugs_enabled() || empty( $query_vars['author_name'] ) ) return $query_vars;
	$user = stp_user_by_public_author_slug( $query_vars['author_name'] );
	if ( $user ) $query_vars['author_name'] = $user->user_nicename;
	return $query_vars;
}

function stp_requested_author_path_slug() {
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$path = trim( (string) $path, '/' );
	if ( ! $path ) return '';
	$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
	global $wp_rewrite;
	$base = ! empty( $wp_rewrite->author_base ) ? trim( $wp_rewrite->author_base, '/' ) : 'author';
	$author_key = array_search( $base, $parts, true );
	if ( $author_key === false || empty( $parts[ $author_key + 1 ] ) ) return '';
	return sanitize_title( rawurldecode( $parts[ $author_key + 1 ] ) );
}

function stp_guard_public_author_archive() {
	if ( stp_enforcement_paused() ) return;
	if ( ! stp_author_public_slugs_enabled() || is_user_logged_in() ) return;
	$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
	if ( isset( $_GET['author'] ) ) {
		stp_log( 'page_view', array( 'sub' => 'author_archive_blocked', 'url' => home_url( $uri ), 'admin_probe' => true, 'extra' => array( 'reason' => 'numeric_author_probe' ) ) );
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
	$requested = stp_requested_author_path_slug();
	if ( $requested && ! is_author() && ! stp_user_by_public_author_slug( $requested ) ) {
		stp_log( 'page_view', array( 'sub' => 'author_archive_blocked', 'url' => home_url( $uri ), 'admin_probe' => true, 'extra' => array( 'reason' => 'guessed_author_slug', 'requested_slug' => $requested ) ) );
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
	if ( ! is_author() ) return;
	$user_id = (int) get_queried_object_id();
	$safe_slug = stp_public_author_slug( $user_id );
	if ( ! $safe_slug || ! $requested || ! hash_equals( $safe_slug, $requested ) ) {
		stp_log( 'page_view', array( 'sub' => 'author_archive_blocked', 'url' => home_url( $uri ), 'admin_probe' => true, 'extra' => array( 'reason' => 'unsafe_author_slug', 'requested_slug' => $requested ) ) );
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}

add_action( 'template_redirect', function () {
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( $path !== '/.well-known/security.txt' && $path !== '/security.txt' ) return;
	if ( empty( stp_cfg()['security_txt_enabled'] ) ) return;
	header( 'Content-Type: text/plain; charset=utf-8' );
	$email = sanitize_email( stp_cfg()['alert_email'] ?? get_option( 'admin_email' ) );
	echo "Contact: mailto:{$email}\n";
	echo 'Preferred-Languages: en' . "\n";
	echo 'Canonical: ' . esc_url_raw( home_url( '/.well-known/security.txt' ) ) . "\n";
	echo 'Policy: ' . esc_url_raw( home_url( '/' ) ) . "\n";
	exit;
}, 0 );

function stp_honeypot_path() {
	return '/stp-honeypot-' . substr( hash( 'sha256', wp_salt( 'auth' ) . home_url() ), 0, 12 );
}

function stp_tarpit_response() {
	if ( stp_enforcement_paused() ) return false;
	if ( empty( stp_cfg()['tarpit_enabled'] ) || headers_sent() ) return false;
	@set_time_limit( 12 );
	header( 'HTTP/1.1 429 Too Many Requests' );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow', true );
	for ( $i = 0; $i < 4; $i++ ) {
		echo '.';
		@ob_flush();
		@flush();
		sleep( 2 );
	}
	exit;
}

function stp_waf_normalize( $value ) {
	$s = strtolower( (string) $value );
	$s = strtr( $s, array(
		'Ｕ' => 'u', 'Ｎ' => 'n', 'Ｉ' => 'i', 'Ｏ' => 'o', 'Ｓ' => 's', 'Ｅ' => 'e', 'Ｌ' => 'l', 'Ｃ' => 'c', 'Ｔ' => 't',
		'ｕ' => 'u', 'ｎ' => 'n', 'ｉ' => 'i', 'ｏ' => 'o', 'ｓ' => 's', 'ｅ' => 'e', 'ｌ' => 'l', 'ｃ' => 'c', 'ｔ' => 't',
		'（' => '(', '）' => ')', '／' => '/', '＊' => '*', '％' => '%', '＝' => '=', '＇' => "'", '＂' => '"', '－' => '-',
	) );
	$s = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s );
	$s = rawurldecode( $s );
	$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = preg_replace( '#/\*!?\d*|\*/|--|#.*$#m', ' ', $s );
	$s = preg_replace( '/\/\*.*?\*\//s', ' ', $s );
	$s = preg_replace( '/\s+/', ' ', $s );
	return trim( $s );
}

function stp_payload_entropy( $s ) {
	$s = (string) $s;
	$len = strlen( $s );
	if ( $len < 20 ) return 0;
	$freq = count_chars( $s, 1 );
	$entropy = 0;
	foreach ( $freq as $count ) {
		$p = $count / $len;
		$entropy -= $p * log( $p, 2 );
	}
	return $entropy;
}

function stp_scan_payload( $payload ) {
	$raw = (string) $payload;
	$n = stp_waf_normalize( $raw );
	$flags = array();
	$score = 0;
	$add = function ( $pts, $key ) use ( &$flags, &$score ) {
		$flags[ $key ] = true;
		$score += $pts;
	};

	if ( preg_match( '/\bunion\s+(?:all\s+)?select\b|\bselect\b.+\bfrom\b|\binformation_schema\b|\bsleep\s*\(|\bbenchmark\s*\(/i', $n ) ) $add( 85, 'semantic_sqli' );
	if ( preg_match( '/\bor\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+|\band\s+[\'"]?\w+[\'"]?\s*=\s*[\'"]?\w+/i', $n ) ) $add( 55, 'boolean_sqli' );
	if ( preg_match( '/<\s*script\b|javascript\s*:|on(?:error|load|mouseover)\s*=|document\s*\.\s*cookie/i', $n ) ) $add( 75, 'semantic_xss' );
	if ( preg_match( '/(?:eval|assert|system|shell_exec|passthru|proc_open|popen)\s*\(|base64_decode\s*\(|array_map\s*\(\s*[\'"]assert/i', $n ) ) $add( 90, 'php_payload' );
	if ( preg_match( '/\$_(?:get|post|request|cookie|server)\b|(?:include|require)(?:_once)?\s*\(/i', $n ) ) $add( 55, 'php_variable_payload' );
	if ( preg_match( '/(?:\.\.\/|\.\.\\\\|%2e%2e|\/etc\/passwd|\/proc\/self\/environ)/i', $raw . ' ' . $n ) ) $add( 75, 'traversal_payload' );
	if ( strlen( $raw ) > 200 && stp_payload_entropy( $raw ) > 5.2 && preg_match( '/[A-Za-z0-9+\/=]{160,}/', $raw ) && ! preg_match( '/\b(?:authorization|bearer|jwt|token|nonce|session|cart|woocommerce|wp_woocommerce_session|wordpress_logged_in|wordpress_sec|application_password)\b/i', $raw ) ) $add( 20, 'encoded_high_entropy' );

	return array( 'score' => min( 100, $score ), 'flags' => $flags, 'normalized' => substr( $n, 0, 500 ) );
}

function stp_request_payload() {
	$parts = array( $_SERVER['REQUEST_URI'] ?? '' );
	foreach ( array( $_GET, $_POST ) as $src ) {
		foreach ( (array) $src as $k => $v ) {
			if ( is_array( $v ) ) $v = wp_json_encode( $v );
			$parts[] = $k . '=' . (string) $v;
		}
	}
	foreach ( (array) $_COOKIE as $k => $v ) {
		if ( preg_match( '/^(?:comment_author_|stp_|woocommerce_items_in_cart|woocommerce_cart_hash)$/i', (string) $k ) ) {
			$parts[] = $k . '=' . substr( sanitize_text_field( is_array( $v ) ? wp_json_encode( $v ) : (string) $v ), 0, 200 );
		}
	}
	if ( isset( $GLOBALS['HTTP_RAW_POST_DATA'] ) && is_string( $GLOBALS['HTTP_RAW_POST_DATA'] ) ) {
		$parts[] = substr( $GLOBALS['HTTP_RAW_POST_DATA'], 0, 250000 );
	}
	$fh = fopen( 'php://input', 'rb' );
	$raw = $fh ? stream_get_contents( $fh, 250000 ) : '';
	if ( $fh ) fclose( $fh );
	if ( is_string( $raw ) && $raw !== '' ) {
		$parts[] = $raw;
		$json = json_decode( $raw, true );
		if ( is_array( $json ) ) {
			$parts[] = wp_json_encode( $json );
		}
	}
	return implode( "\n", $parts );
}

function stp_waf_guard() {
	if ( stp_enforcement_paused() ) return;
	if ( empty( stp_cfg()['adaptive_waf'] ) || stp_is_block_check_exempt() ) return;
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	$ip = stp_get_ip();
	$decision = stp_block_decision( $ip );
	if ( ! empty( $decision['blocked'] ) ) stp_deny_blocked_request( $decision, 'waf_precheck' );
	if ( get_transient( 'stp_preemptive_' . md5( $ip ) ) && preg_match( '#/(?:wp-login\.php|xmlrpc\.php|wp-json/|wp-admin/)#i', (string) $path ) ) {
		stp_log( 'waf_block', array( 'sub' => 'attack_graph_preemptive_limit', 'url' => home_url( $_SERVER['REQUEST_URI'] ?? '/' ), 'waf_score' => 85, 'extra' => array( 'attack_graph' => true ) ) );
		stp_tarpit_response();
		wp_die( 'Temporarily rate limited', 'Rate limited', array( 'response' => 429 ) );
	}
	if ( ! empty( stp_cfg()['honeypot_enabled'] ) && $path === stp_honeypot_path() ) {
		global $wpdb;
		if ( ! stp_tables_ready() && ! stp_repair_database() ) wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) );
		$ip_row = stp_upsert_ip( $ip );
		$wpdb->update( stp_t( 'ips' ), array( 'status' => 'blocked', 'risk_score' => 100, 'blocked_at' => current_time( 'mysql' ), 'notes' => 'Honeypot endpoint hit' ), array( 'id' => (int) $ip_row->id ) );
		stp_log( 'waf_block', array( 'sub' => 'honeypot_hit', 'url' => home_url( stp_honeypot_path() ), 'waf_score' => 100, 'extra' => array( 'honeypot' => true ) ) );
		stp_tarpit_response();
		wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) );
	}
	if ( stp_is_static_request() ) return;
	$scan = stp_scan_payload( stp_request_payload() );
	if ( $scan['score'] >= (int) ( stp_cfg()['waf_block_score'] ?? 80 ) ) {
		stp_log( 'waf_block', array( 'sub' => implode( ',', array_keys( $scan['flags'] ) ), 'url' => home_url( $_SERVER['REQUEST_URI'] ?? '/' ), 'waf_score' => $scan['score'], 'extra' => $scan ) );
		stp_tarpit_response();
		wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) );
	}
}
add_action( 'init', 'stp_waf_guard', 0 );

function stp_surface_item( $key, $label, $open, $setting_key, $why, $fix, $setting_enabled = null, $source = '' ) {
	return array(
		'key'         => $key,
		'label'       => $label,
		'open'        => (bool) $open,
		'setting_key' => $setting_key,
		'why'         => $why,
		'fix'         => $fix,
		'severity'    => $open ? 'critical' : 'safe',
		'setting_enabled' => $setting_enabled === null ? null : (bool) $setting_enabled,
		'source'      => $source,
	);
}

function stp_security_posture() {
	$c = stp_cfg();
	$items = array();
	$xmlrpc_runtime_enabled = apply_filters( 'xmlrpc_enabled', true );
	$xmlrpc_open = $xmlrpc_runtime_enabled && empty( $c['harden_xmlrpc'] );
	$xmlrpc_source = ! empty( $c['harden_xmlrpc'] ) ? 'SecureTrack setting is ON.' : ( ! $xmlrpc_runtime_enabled ? 'Protected by another plugin, theme, or server rule.' : 'SecureTrack setting is OFF.' );
	$author_safe = ! empty( $c['harden_author_archives'] ) || ! empty( $c['author_public_slugs'] );
	$author_setting = ! empty( $c['harden_author_archives'] ) ? 'harden_author_archives' : 'author_public_slugs';
	$author_source = ! empty( $c['harden_author_archives'] ) ? 'SecureTrack redirects anonymous author archives.' : ( ! empty( $c['author_public_slugs'] ) ? 'SecureTrack safe public author slugs are ON.' : 'SecureTrack author protection is OFF.' );
	$file_editor_external = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT && empty( $c['harden_file_editor'] );
	$items[] = stp_surface_item( 'xmlrpc', 'XML-RPC endpoint', $xmlrpc_open, 'harden_xmlrpc', 'Attackers use XML-RPC for brute force, pingback abuse, and remote publishing attempts.', 'Disable XML-RPC unless your mobile app, Jetpack, or a trusted integration requires it.', ! empty( $c['harden_xmlrpc'] ), $xmlrpc_source );
	$items[] = stp_surface_item( 'rest_users', 'REST user listing', empty( $c['harden_rest_users'] ), 'harden_rest_users', 'Public user endpoints help attackers discover usernames before password or session attacks.', 'Block anonymous /wp-json/wp/v2/users requests.', ! empty( $c['harden_rest_users'] ), ! empty( $c['harden_rest_users'] ) ? 'SecureTrack setting is ON.' : 'SecureTrack setting is OFF.' );
	$author_label = ! empty( $c['author_public_slugs'] ) && empty( $c['harden_author_archives'] ) ? 'Author archive login-name exposure' : 'Author archive enumeration';
	$author_fix = ! empty( $c['author_public_slugs'] ) && empty( $c['harden_author_archives'] )
		? 'Author archives remain public for readers, but public author URLs use safe slugs instead of login-linked names.'
		: 'Use safe public author slugs for reader-facing archive pages, or fully redirect anonymous author archive probes.';
	$items[] = stp_surface_item( 'author_archives', $author_label, ! $author_safe, $author_setting, 'Author pages and ?author=1 redirects can leak valid login names.', $author_fix, ! empty( $c[ $author_setting ] ), $author_source );
	$items[] = stp_surface_item( 'file_editor', 'Theme/plugin file editor', ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && empty( $c['harden_file_editor'] ), 'harden_file_editor', 'If an admin session is stolen, the built-in file editor can become a direct webshell dropper.', 'Disable the WordPress dashboard file editor.', ! empty( $c['harden_file_editor'] ), $file_editor_external ? 'Protected by wp-config.php or another security rule.' : ( ! empty( $c['harden_file_editor'] ) ? 'SecureTrack setting is ON.' : 'SecureTrack setting is OFF.' ) );
	$items[] = stp_surface_item( 'wp_generator', 'WordPress version generator tag', empty( $c['harden_wp_generator'] ), 'harden_wp_generator', 'Version disclosure helps attackers choose exploit payloads for old core/plugin stacks.', 'Remove generator/version output from public pages.', ! empty( $c['harden_wp_generator'] ), ! empty( $c['harden_wp_generator'] ) ? 'SecureTrack setting is ON.' : 'SecureTrack setting is OFF.' );
	$items[] = stp_surface_item( 'security_headers', 'Baseline security headers', empty( $c['harden_security_headers'] ), 'harden_security_headers', 'Missing headers can leave browsers more permissive around framing, MIME sniffing, referrer leakage, and transport downgrade.', 'Send safe baseline headers such as HSTS, X-Frame-Options, X-Content-Type-Options, and Referrer-Policy.', ! empty( $c['harden_security_headers'] ), ! empty( $c['harden_security_headers'] ) ? 'SecureTrack setting is ON.' : 'SecureTrack setting is OFF.' );
	$open = 0;
	foreach ( $items as $item ) if ( $item['open'] ) $open++;
	return array( 'items' => $items, 'open' => $open, 'score' => max( 0, 100 - ( $open * 18 ) ) );
}

function stp_security_grade( $score ) {
	$score = max( 0, min( 100, (int) $score ) );
	if ( $score >= 90 ) return 'A';
	if ( $score >= 80 ) return 'B';
	if ( $score >= 70 ) return 'C';
	if ( $score >= 60 ) return 'D';
	return 'F';
}


// ════════════════════════════════════════════════════════════════
//  BOOTSTRAP  (works for both regular plugin & mu-plugin)
// ════════════════════════════════════════════════════════════════

$_stp_is_mu = ( defined( 'WPMU_PLUGIN_DIR' ) && strpos( STP_FILE, WPMU_PLUGIN_DIR ) !== false );

if ( $_stp_is_mu ) {
	// MU-plugins have no activation hook; initialise lazily.
	add_action( 'plugins_loaded', 'stp_maybe_install', 5 );
} else {
	register_activation_hook( STP_FILE, 'stp_install' );
	register_deactivation_hook( STP_FILE, 'stp_deactivate' );
	add_action( 'plugins_loaded', 'stp_maybe_install', 5 );
}

function stp_maybe_install() {
	\DSA\Secure\SecureTrack_Db_Service::maybe_install();
}

function stp_table_exists( $name ) {
	return \DSA\Secure\SecureTrack_Db_Service::table_exists( (string) $name );
}

function stp_column_exists( $table_name, $column ) {
	return \DSA\Secure\SecureTrack_Db_Service::column_exists( (string) $table_name, (string) $column );
}

function stp_index_exists( $table_name, $index ) {
	return \DSA\Secure\SecureTrack_Db_Service::index_exists( (string) $table_name, (string) $index );
}

function stp_safe_schema_fragment( $sql, $kind = 'column' ) {
	return \DSA\Secure\SecureTrack_Db_Service::safe_schema_fragment( $sql, (string) $kind );
}

function stp_ensure_column( $table_name, $column, $definition ) {
	\DSA\Secure\SecureTrack_Db_Service::ensure_column( (string) $table_name, (string) $column, $definition );
}

function stp_ensure_index( $table_name, $index, $definition ) {
	\DSA\Secure\SecureTrack_Db_Service::ensure_index( (string) $table_name, (string) $index, $definition );
}

function stp_upgrade_alert_severity() {
	static $done = false;
	if ( $done || ! stp_table_exists( 'alerts' ) ) return;
	if ( get_option( 'stp_alert_severity_upgrade' ) === STP_VER ) return;
	$done = true;
	global $wpdb;
	$wpdb->query(
		"UPDATE " . stp_t( 'alerts' ) . "
		 SET severity='critical'
		 WHERE severity='high'
		   AND chain_type='high_risk_event'
		   AND (
		     title IN ('High-risk event: waf_block','High-risk event: login_failed','High-risk event: behavior_signal','High-risk event: break_glass_access')
		     OR description LIKE 'Score 100/100%'
		     OR description LIKE 'Score 9_/100%'
		   )"
	);
	update_option( 'stp_alert_severity_upgrade', STP_VER, false );
}

function stp_migrate_schema() {
	stp_ensure_column( 'ips', 'ip_address', "VARCHAR(45) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'ips', 'country', 'VARCHAR(80) DEFAULT NULL' );
	stp_ensure_column( 'ips', 'country_code', 'VARCHAR(4) DEFAULT NULL' );
	stp_ensure_column( 'ips', 'region', 'VARCHAR(100) DEFAULT NULL' );
	stp_ensure_column( 'ips', 'city', 'VARCHAR(100) DEFAULT NULL' );
	stp_ensure_column( 'ips', 'isp', 'VARCHAR(200) DEFAULT NULL' );
	stp_ensure_column( 'ips', 'org', 'VARCHAR(200) DEFAULT NULL' );
	stp_ensure_column( 'ips', 'is_proxy', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ips', 'is_hosting', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ips', 'risk_score', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ips', 'status', "VARCHAR(10) NOT NULL DEFAULT 'unknown'" );
	stp_ensure_column( 'ips', 'failed_logins', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ips', 'total_hits', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ips', 'first_seen', 'DATETIME NULL' );
	stp_ensure_column( 'ips', 'last_seen', 'DATETIME NULL' );
	stp_ensure_column( 'ips', 'blocked_at', 'DATETIME DEFAULT NULL' );
	stp_ensure_column( 'ips', 'geo_fetched', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ips', 'notes', 'TEXT DEFAULT NULL' );

	stp_ensure_column( 'sessions', 'session_token', "VARCHAR(64) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'sessions', 'ip_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
	stp_ensure_column( 'sessions', 'user_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
	stp_ensure_column( 'sessions', 'user_agent', 'TEXT DEFAULT NULL' );
	stp_ensure_column( 'sessions', 'device_type', "VARCHAR(10) NOT NULL DEFAULT 'desktop'" );
	stp_ensure_column( 'sessions', 'referrer', 'VARCHAR(500) DEFAULT NULL' );
	stp_ensure_column( 'sessions', 'started_at', 'DATETIME NULL' );
	stp_ensure_column( 'sessions', 'last_activity', 'DATETIME NULL' );
	stp_ensure_column( 'sessions', 'page_count', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'sessions', 'total_seconds', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'sessions', 'is_bot', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'sessions', 'flag_status', "VARCHAR(10) NOT NULL DEFAULT 'yellow'" );
	stp_ensure_index( 'sessions', 'idx_stp_session_token', 'INDEX idx_stp_session_token (session_token)' );
	stp_ensure_index( 'sessions', 'idx_stp_flag_last', 'INDEX idx_stp_flag_last (flag_status,last_activity)' );

	stp_ensure_column( 'events', 'session_id', 'BIGINT UNSIGNED DEFAULT NULL' );
	stp_ensure_column( 'events', 'ip_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
	stp_ensure_column( 'events', 'user_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
	stp_ensure_column( 'events', 'username', 'VARCHAR(200) DEFAULT NULL' );
	stp_ensure_column( 'events', 'event_type', "VARCHAR(60) NOT NULL DEFAULT 'unknown'" );
	stp_ensure_column( 'events', 'event_sub', 'VARCHAR(60) DEFAULT NULL' );
	stp_ensure_column( 'events', 'obj_type', 'VARCHAR(50) DEFAULT NULL' );
	stp_ensure_column( 'events', 'obj_id', 'BIGINT UNSIGNED DEFAULT NULL' );
	stp_ensure_column( 'events', 'obj_title', 'VARCHAR(500) DEFAULT NULL' );
	stp_ensure_column( 'events', 'url', 'VARCHAR(1000) DEFAULT NULL' );
	stp_ensure_column( 'events', 'extra', 'LONGTEXT DEFAULT NULL' );
	stp_ensure_column( 'events', 'risk_score', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'events', 'risk_reasons', 'VARCHAR(500) DEFAULT NULL' );
	stp_ensure_column( 'events', 'flag_status', "VARCHAR(10) NOT NULL DEFAULT 'yellow'" );
	stp_ensure_column( 'events', 'reviewed', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'events', 'created_at', 'DATETIME NULL' );
	stp_ensure_column( 'events', 'hash_prev', 'VARCHAR(64) DEFAULT NULL' );
	stp_ensure_column( 'events', 'hash_current', 'VARCHAR(64) DEFAULT NULL' );
	stp_ensure_index( 'events', 'idx_stp_event_hash', 'INDEX idx_stp_event_hash (hash_current)' );
	stp_ensure_index( 'events', 'idx_stp_user_created', 'INDEX idx_stp_user_created (user_id,created_at)' );
	stp_ensure_index( 'events', 'idx_stp_flag_created', 'INDEX idx_stp_flag_created (flag_status,created_at)' );
	stp_ensure_index( 'events', 'idx_stp_ip_created', 'INDEX idx_stp_ip_created (ip_id,created_at)' );

	stp_ensure_column( 'profiles', 'trusted_ips', 'LONGTEXT DEFAULT NULL' );
	stp_ensure_column( 'profiles', 'trusted_countries', 'LONGTEXT DEFAULT NULL' );
	stp_ensure_column( 'profiles', 'login_hours', 'LONGTEXT DEFAULT NULL' );
	stp_ensure_column( 'profiles', 'green_count', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'profiles', 'risk_count', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'profiles', 'last_login', 'DATETIME DEFAULT NULL' );
	stp_ensure_column( 'profiles', 'last_ip', 'VARCHAR(45) DEFAULT NULL' );
	stp_ensure_column( 'profiles', 'last_country', 'VARCHAR(80) DEFAULT NULL' );
	stp_ensure_column( 'profiles', 'baseline_done', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'profiles', 'updated_at', 'DATETIME DEFAULT NULL' );

	stp_ensure_column( 'pages', 'session_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
	stp_ensure_column( 'pages', 'url', "VARCHAR(1000) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'pages', 'page_title', 'VARCHAR(500) DEFAULT NULL' );
	stp_ensure_column( 'pages', 'time_spent', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'pages', 'visited_at', 'DATETIME NULL' );
	stp_ensure_index( 'pages', 'idx_stp_pages_session_visited', 'INDEX idx_stp_pages_session_visited (session_id,visited_at)' );

	stp_ensure_column( 'alerts', 'alert_time', 'DATETIME NULL' );
	stp_ensure_column( 'alerts', 'chain_type', "VARCHAR(100) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'alerts', 'severity', "VARCHAR(20) NOT NULL DEFAULT 'high'" );
	stp_ensure_column( 'alerts', 'ip_address', 'VARCHAR(45) DEFAULT NULL' );
	stp_ensure_column( 'alerts', 'subnet_24', 'VARCHAR(20) DEFAULT NULL' );
	stp_ensure_column( 'alerts', 'user_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
	stp_ensure_column( 'alerts', 'username', 'VARCHAR(200) DEFAULT NULL' );
	stp_ensure_column( 'alerts', 'event_id', 'BIGINT UNSIGNED DEFAULT NULL' );
	stp_ensure_column( 'alerts', 'title', "VARCHAR(255) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'alerts', 'description', 'TEXT DEFAULT NULL' );
	stp_ensure_column( 'alerts', 'evidence', 'LONGTEXT DEFAULT NULL' );
	stp_ensure_column( 'alerts', 'is_resolved', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'alerts', 'action_taken', 'VARCHAR(255) DEFAULT NULL' );
	stp_ensure_column( 'alerts', 'resolved_at', 'DATETIME DEFAULT NULL' );
	stp_ensure_index( 'alerts', 'idx_stp_alert_open_time', 'INDEX idx_stp_alert_open_time (is_resolved,alert_time)' );

	stp_ensure_column( 'subnets', 'subnet', "VARCHAR(20) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'subnets', 'ip_count', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'subnets', 'attack_ips', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'subnets', 'total_events', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'subnets', 'attack_events', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'subnets', 'threat_level', "VARCHAR(20) NOT NULL DEFAULT 'low'" );
	stp_ensure_column( 'subnets', 'threat_score', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'subnets', 'is_banned', 'TINYINT(1) NOT NULL DEFAULT 0' );
	stp_ensure_column( 'subnets', 'ban_reason', 'VARCHAR(255) DEFAULT NULL' );
	stp_ensure_column( 'subnets', 'ban_time', 'DATETIME DEFAULT NULL' );
	stp_ensure_column( 'subnets', 'first_seen', 'DATETIME NULL' );
	stp_ensure_column( 'subnets', 'last_seen', 'DATETIME NULL' );
	stp_ensure_index( 'subnets', 'idx_stp_subnet', 'INDEX idx_stp_subnet (subnet)' );

	stp_ensure_column( 'brain', 'feature_key', "VARCHAR(120) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'brain', 'feature_type', "VARCHAR(40) NOT NULL DEFAULT 'site'" );
	stp_ensure_column( 'brain', 'good_count', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'brain', 'risk_count', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'brain', 'last_score', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'brain', 'confidence', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'brain', 'meta', 'LONGTEXT DEFAULT NULL' );
	stp_ensure_column( 'brain', 'first_seen', 'DATETIME NULL' );
	stp_ensure_column( 'brain', 'last_seen', 'DATETIME NULL' );
	stp_ensure_index( 'brain', 'idx_stp_brain_type', 'INDEX idx_stp_brain_type (feature_type)' );
	stp_ensure_index( 'brain', 'idx_stp_brain_conf', 'INDEX idx_stp_brain_conf (confidence)' );

	stp_ensure_column( 'ai_queue', 'event_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ai_queue', 'provider', "VARCHAR(30) NOT NULL DEFAULT 'none'" );
	stp_ensure_column( 'ai_queue', 'local_score', 'SMALLINT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'ai_queue', 'status', "VARCHAR(20) NOT NULL DEFAULT 'pending'" );
	stp_ensure_column( 'ai_queue', 'compact_context', 'LONGTEXT DEFAULT NULL' );
	stp_ensure_column( 'ai_queue', 'ai_score', 'SMALLINT DEFAULT NULL' );
	stp_ensure_column( 'ai_queue', 'ai_label', 'VARCHAR(40) DEFAULT NULL' );
	stp_ensure_column( 'ai_queue', 'ai_reason', 'TEXT DEFAULT NULL' );
	stp_ensure_column( 'ai_queue', 'created_at', 'DATETIME NULL' );
	stp_ensure_column( 'ai_queue', 'reviewed_at', 'DATETIME DEFAULT NULL' );
	stp_ensure_index( 'ai_queue', 'idx_stp_ai_status', 'INDEX idx_stp_ai_status (status)' );
	stp_ensure_index( 'ai_queue', 'idx_stp_ai_event', 'INDEX idx_stp_ai_event (event_id)' );

	stp_ensure_column( 'rate_limits', 'bucket_hash', "VARCHAR(64) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'rate_limits', 'bucket_id', "VARCHAR(64) NOT NULL DEFAULT ''" );
	stp_ensure_column( 'rate_limits', 'counter', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'rate_limits', 'window_start', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_column( 'rate_limits', 'expires_at', 'INT NOT NULL DEFAULT 0' );
	stp_ensure_index( 'rate_limits', 'idx_stp_rate_bucket', 'INDEX idx_stp_rate_bucket (bucket_id)' );
	stp_ensure_index( 'rate_limits', 'idx_stp_rate_exp', 'INDEX idx_stp_rate_exp (expires_at)' );
}

function stp_tables_ready( $refresh = false ) {
	return \DSA\Secure\SecureTrack_Db_Service::tables_ready( (bool) $refresh );
}

function stp_repair_database() {
	return \DSA\Secure\SecureTrack_Db_Service::repair_database();
}

function stp_diag( $key, $value = null ) {
	return \DSA\Secure\SecureTrack_Db_Service::diag( (string) $key, $value );
}

function stp_install() {
	\DSA\Secure\SecureTrack_Db_Service::install();
}

function stp_deactivate() {
	\DSA\Secure\SecureTrack_Db_Service::deactivate();
}


// ════════════════════════════════════════════════════════════════
//  DATABASE SCHEMA
// ════════════════════════════════════════════════════════════════

function stp_create_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cs = $wpdb->get_charset_collate();

	/* ── IP Reputation ─────────────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'ips' ) . " (
  id            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  ip_address    VARCHAR(45)         NOT NULL,
  country       VARCHAR(80)         DEFAULT NULL,
  country_code  VARCHAR(4)          DEFAULT NULL,
  region        VARCHAR(100)        DEFAULT NULL,
  city          VARCHAR(100)        DEFAULT NULL,
  isp           VARCHAR(200)        DEFAULT NULL,
  org           VARCHAR(200)        DEFAULT NULL,
  is_proxy      TINYINT(1)          NOT NULL DEFAULT 0,
  is_hosting    TINYINT(1)          NOT NULL DEFAULT 0,
  risk_score    SMALLINT            NOT NULL DEFAULT 0,
  status        VARCHAR(10)         NOT NULL DEFAULT 'unknown',
  failed_logins SMALLINT            NOT NULL DEFAULT 0,
  total_hits    INT                 NOT NULL DEFAULT 0,
  first_seen    DATETIME            NOT NULL,
  last_seen     DATETIME            NOT NULL,
  blocked_at    DATETIME            DEFAULT NULL,
  geo_fetched   TINYINT(1)          NOT NULL DEFAULT 0,
  notes         TEXT                DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_ip (ip_address),
  KEY          idx_status (status),
  KEY          idx_risk   (risk_score),
  KEY          idx_last   (last_seen)
) $cs;" );

	/* ── Sessions ──────────────────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'sessions' ) . " (
  id             BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  session_token  VARCHAR(64)         NOT NULL,
  ip_id          BIGINT UNSIGNED     NOT NULL,
  user_id        BIGINT UNSIGNED     NOT NULL DEFAULT 0,
  user_agent     TEXT                DEFAULT NULL,
  device_type    VARCHAR(10)         NOT NULL DEFAULT 'desktop',
  referrer       VARCHAR(500)        DEFAULT NULL,
  started_at     DATETIME            NOT NULL,
  last_activity  DATETIME            NOT NULL,
  page_count     SMALLINT            NOT NULL DEFAULT 0,
  total_seconds  INT                 NOT NULL DEFAULT 0,
  is_bot         TINYINT(1)          NOT NULL DEFAULT 0,
  flag_status    VARCHAR(10)         NOT NULL DEFAULT 'yellow',
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_token  (session_token),
  KEY          idx_user  (user_id),
  KEY          idx_ip    (ip_id),
  KEY          idx_flag  (flag_status),
  KEY          idx_flag_last (flag_status,last_activity),
  KEY          idx_start (started_at)
) $cs;" );

	/* ── Events Log ────────────────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'events' ) . " (
  id            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  session_id    BIGINT UNSIGNED     DEFAULT NULL,
  ip_id         BIGINT UNSIGNED     NOT NULL,
  user_id       BIGINT UNSIGNED     NOT NULL DEFAULT 0,
  username      VARCHAR(200)        DEFAULT NULL,
  event_type    VARCHAR(60)         NOT NULL,
  event_sub     VARCHAR(60)         DEFAULT NULL,
  obj_type      VARCHAR(50)         DEFAULT NULL,
  obj_id        BIGINT UNSIGNED     DEFAULT NULL,
  obj_title     VARCHAR(500)        DEFAULT NULL,
  url           VARCHAR(1000)       DEFAULT NULL,
  extra         LONGTEXT            DEFAULT NULL,
  risk_score    SMALLINT            NOT NULL DEFAULT 0,
  risk_reasons  VARCHAR(500)        DEFAULT NULL,
  flag_status   VARCHAR(10)         NOT NULL DEFAULT 'yellow',
  reviewed      TINYINT(1)          NOT NULL DEFAULT 0,
  created_at    DATETIME            NOT NULL,
  hash_prev     VARCHAR(64)         DEFAULT NULL,
  hash_current  VARCHAR(64)         DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY          idx_type    (event_type),
  KEY          idx_user    (user_id),
  KEY          idx_user_created (user_id,created_at),
  KEY          idx_ip      (ip_id),
  KEY          idx_session (session_id),
  KEY          idx_flag    (flag_status),
  KEY          idx_flag_created (flag_status,created_at),
  KEY          idx_ip_created (ip_id,created_at),
  KEY          idx_created (created_at),
  KEY          idx_risk    (risk_score),
  KEY          idx_hash    (hash_current)
) $cs;" );

	/* ── Behavioral Profiles ───────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'profiles' ) . " (
  user_id            BIGINT UNSIGNED     NOT NULL,
  trusted_ips        LONGTEXT            DEFAULT NULL,
  trusted_countries  LONGTEXT            DEFAULT NULL,
  login_hours        LONGTEXT            DEFAULT NULL,
  green_count        INT                 NOT NULL DEFAULT 0,
  risk_count         INT                 NOT NULL DEFAULT 0,
  last_login         DATETIME            DEFAULT NULL,
  last_ip            VARCHAR(45)         DEFAULT NULL,
  last_country       VARCHAR(80)         DEFAULT NULL,
  baseline_done      TINYINT(1)          NOT NULL DEFAULT 0,
  updated_at         DATETIME            DEFAULT NULL,
  PRIMARY KEY  (user_id)
) $cs;" );

	/* ── Page Navigation ───────────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'pages' ) . " (
  id           BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  session_id   BIGINT UNSIGNED     NOT NULL,
  url          VARCHAR(1000)       NOT NULL,
  page_title   VARCHAR(500)        DEFAULT NULL,
  time_spent   SMALLINT            NOT NULL DEFAULT 0,
  visited_at   DATETIME            NOT NULL,
  PRIMARY KEY  (id),
  KEY          idx_session (session_id),
  KEY          idx_session_visited (session_id,visited_at),
  KEY          idx_visited (visited_at)
) $cs;" );

	/* ── Alerts ──────────────────────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'alerts' ) . " (
  id            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  alert_time    DATETIME            NOT NULL,
  chain_type    VARCHAR(100)        NOT NULL DEFAULT '',
  severity      VARCHAR(20)         NOT NULL DEFAULT 'high',
  ip_address    VARCHAR(45)         DEFAULT NULL,
  subnet_24     VARCHAR(20)         DEFAULT NULL,
  user_id       BIGINT UNSIGNED     NOT NULL DEFAULT 0,
  username      VARCHAR(200)        DEFAULT NULL,
  event_id      BIGINT UNSIGNED     DEFAULT NULL,
  title         VARCHAR(255)        NOT NULL DEFAULT '',
  description   TEXT                DEFAULT NULL,
  evidence      LONGTEXT            DEFAULT NULL,
  is_resolved   TINYINT(1)          NOT NULL DEFAULT 0,
  action_taken  VARCHAR(255)        DEFAULT NULL,
  resolved_at   DATETIME            DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY          idx_alert_time (alert_time),
  KEY          idx_alert_open (is_resolved),
  KEY          idx_alert_open_time (is_resolved,alert_time),
  KEY          idx_alert_ip   (ip_address),
  KEY          idx_alert_sub  (subnet_24)
) $cs;" );

	/* ── Subnet Intelligence ─────────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'subnets' ) . " (
  id             BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  subnet         VARCHAR(20)         NOT NULL,
  ip_count       INT                 NOT NULL DEFAULT 0,
  attack_ips     INT                 NOT NULL DEFAULT 0,
  total_events   INT                 NOT NULL DEFAULT 0,
  attack_events  INT                 NOT NULL DEFAULT 0,
  threat_level   VARCHAR(20)         NOT NULL DEFAULT 'low',
  threat_score   INT                 NOT NULL DEFAULT 0,
  is_banned      TINYINT(1)          NOT NULL DEFAULT 0,
  ban_reason     VARCHAR(255)        DEFAULT NULL,
  ban_time       DATETIME            DEFAULT NULL,
  first_seen     DATETIME            NOT NULL,
  last_seen      DATETIME            NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_subnet (subnet),
  KEY          idx_level (threat_level),
  KEY          idx_score (threat_score),
  KEY          idx_ban   (is_banned)
) $cs;" );

	/* ── v2 Local Site Brain ───────────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'brain' ) . " (
  id             BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  feature_key    VARCHAR(120)        NOT NULL,
  feature_type   VARCHAR(40)         NOT NULL DEFAULT 'site',
  good_count     INT                 NOT NULL DEFAULT 0,
  risk_count     INT                 NOT NULL DEFAULT 0,
  last_score     SMALLINT            NOT NULL DEFAULT 0,
  confidence     SMALLINT            NOT NULL DEFAULT 0,
  meta           LONGTEXT            DEFAULT NULL,
  first_seen     DATETIME            NOT NULL,
  last_seen      DATETIME            NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_feature (feature_key),
  KEY          idx_type   (feature_type),
  KEY          idx_conf   (confidence),
  KEY          idx_seen   (last_seen)
) $cs;" );

	/* ── v2 Optional Cloud Second Opinion Queue ─────────────── */
dbDelta( "CREATE TABLE " . stp_t( 'ai_queue' ) . " (
  id               BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  event_id         BIGINT UNSIGNED     NOT NULL DEFAULT 0,
  provider         VARCHAR(30)         NOT NULL DEFAULT 'none',
  local_score      SMALLINT            NOT NULL DEFAULT 0,
  status           VARCHAR(20)         NOT NULL DEFAULT 'pending',
  compact_context  LONGTEXT            DEFAULT NULL,
  ai_score         SMALLINT            DEFAULT NULL,
  ai_label         VARCHAR(40)         DEFAULT NULL,
  ai_reason        TEXT                DEFAULT NULL,
  created_at       DATETIME            NOT NULL,
  reviewed_at      DATETIME            DEFAULT NULL,
  PRIMARY KEY    (id),
  KEY            idx_status (status),
  KEY            idx_event  (event_id),
  KEY            idx_created (created_at)
) $cs;" );

	/* ── Atomic rate limit counters ─────────────────────────── */
	dbDelta( "CREATE TABLE " . stp_t( 'rate_limits' ) . " (
  id             BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  bucket_hash    VARCHAR(64)         NOT NULL,
  bucket_id      VARCHAR(64)         NOT NULL DEFAULT '',
  counter        INT                 NOT NULL DEFAULT 0,
  window_start   INT                 NOT NULL DEFAULT 0,
  expires_at     INT                 NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_bucket (bucket_hash),
  KEY          idx_bucket_id (bucket_id),
  KEY          idx_exp   (expires_at)
) $cs;" );

	stp_migrate_schema();
	update_option( 'stp_db_version', STP_VER );
}


// ════════════════════════════════════════════════════════════════
//  CRON  (geo batch fetch + data trim)
// ════════════════════════════════════════════════════════════════

add_filter( 'cron_schedules', function ( $s ) {
	$s['stp_5min'] = array( 'interval' => 300, 'display' => 'Every 5 Minutes' );
	return $s;
} );

function stp_schedule_crons() {
	if ( ! wp_next_scheduled( 'stp_cron_cleanup' ) )
		wp_schedule_event( time(), 'daily', 'stp_cron_cleanup' );
	if ( ! wp_next_scheduled( 'stp_cron_geo' ) )
		wp_schedule_event( time(), 'stp_5min', 'stp_cron_geo' );
	if ( ! wp_next_scheduled( 'stp_cron_ai_queue' ) )
		wp_schedule_event( time() + 60, 'stp_5min', 'stp_cron_ai_queue' );
}

function stp_verify_crons() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
	if ( get_transient( 'stp_cron_verify' ) ) return;
	set_transient( 'stp_cron_verify', 1, HOUR_IN_SECONDS );
	$missing = array();
	foreach ( array( 'stp_cron_cleanup', 'stp_cron_geo', 'stp_cron_ai_queue' ) as $hook ) {
		if ( ! wp_next_scheduled( $hook ) ) $missing[] = $hook;
	}
	if ( $missing ) {
		stp_schedule_crons();
		stp_diag( 'cron_self_heal', array( 'missing' => $missing, 'time' => current_time( 'mysql' ) ) );
	}
}
add_action( 'admin_init', 'stp_verify_crons', 5 );

add_action( 'stp_cron_cleanup', 'stp_run_cleanup' );
add_action( 'stp_cron_geo',     'stp_run_geo' );
add_action( 'stp_cron_ai_queue', 'stp_run_ai_queue' );

/**
 * Removes aged-out events and green sessions.
 */
function stp_run_cleanup() {
	global $wpdb;
	$c = stp_cfg();

	if ( $c['green_trim_days'] > 0 ) {
		$cut = date( 'Y-m-d H:i:s', strtotime( "-{$c['green_trim_days']} days" ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . stp_t( 'events' ) . " WHERE flag_status='green' AND created_at < %s", $cut ) );
	}
	if ( $c['yellow_trim_days'] > 0 ) {
		$cut = date( 'Y-m-d H:i:s', strtotime( "-{$c['yellow_trim_days']} days" ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . stp_t( 'events' ) . " WHERE flag_status='yellow' AND created_at < %s", $cut ) );
	}
	// Page nav for old green/yellow sessions. Delete by indexed session IDs in small batches.
	$cut14 = date( 'Y-m-d H:i:s', strtotime( '-14 days' ) );
	$green_sessions = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . stp_t( 'sessions' ) . " WHERE flag_status='green' AND last_activity < %s LIMIT 1000", $cut14 ) );
	if ( $green_sessions ) {
		$ids = implode( ',', array_map( 'intval', $green_sessions ) );
		$wpdb->query( "DELETE FROM " . stp_t( 'pages' ) . " WHERE session_id IN ({$ids}) LIMIT 5000" );
	}
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM " . stp_t( 'sessions' ) . " WHERE flag_status='green' AND last_activity < %s LIMIT 1000", $cut14
	) );
	if ( $c['yellow_trim_days'] > 0 ) {
		$cut_yellow_pages = date( 'Y-m-d H:i:s', strtotime( "-{$c['yellow_trim_days']} days" ) );
		$yellow_sessions = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . stp_t( 'sessions' ) . " WHERE flag_status='yellow' AND last_activity < %s LIMIT 1000", $cut_yellow_pages ) );
		if ( $yellow_sessions ) {
			$ids = implode( ',', array_map( 'intval', $yellow_sessions ) );
			$wpdb->query( "DELETE FROM " . stp_t( 'pages' ) . " WHERE session_id IN ({$ids}) LIMIT 5000" );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . stp_t( 'sessions' ) . " WHERE flag_status='yellow' AND last_activity < %s LIMIT 1000", $cut_yellow_pages ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM " . stp_t( 'ai_queue' ) . " WHERE status IN ('reviewed','error','local_only') AND created_at < %s", date( 'Y-m-d H:i:s', strtotime( '-30 days' ) ) ) );
	$wpdb->query( "DELETE FROM " . stp_t( 'ai_queue' ) . " WHERE status='pending' AND id NOT IN (SELECT id FROM (SELECT id FROM " . stp_t( 'ai_queue' ) . " WHERE status='pending' ORDER BY id DESC LIMIT 1000) keepq)" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM " . stp_t( 'rate_limits' ) . " WHERE expires_at < %d LIMIT 2000", time() ) );
}

/**
 * Batch geo-lookup for IPs that haven't been resolved yet.
 * Sends IPs to ipwho.is over HTTPS when geo lookup is enabled.
 */
function stp_run_geo() {
	global $wpdb;
	if ( ! stp_cfg()['geo_enabled'] ) return;

	if ( get_transient( 'stp_geo_backoff' ) ) return;
	$ip_rows = $wpdb->get_results( "SELECT ip_address,country_code,risk_score,status FROM " . stp_t( 'ips' ) . " WHERE geo_fetched=0 LIMIT 25" );
	if ( empty( $ip_rows ) ) return;

	foreach ( $ip_rows as $old ) {
		$ip = (string) ( $old->ip_address ?? '' );
		if ( ! $ip ) continue;
		$res = wp_remote_get(
			'https://ipwho.is/' . rawurlencode( $ip ) . '?fields=success,ip,country,country_code,region,city,connection,flag',
			array( 'timeout' => 10 )
		);
		if ( is_wp_error( $res ) ) { set_transient( 'stp_geo_backoff', 1, 15 * MINUTE_IN_SECONDS ); return; }
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 429 ) { set_transient( 'stp_geo_backoff', 1, HOUR_IN_SECONDS ); return; }
		if ( $code < 200 || $code >= 300 ) { set_transient( 'stp_geo_backoff', 1, 15 * MINUTE_IN_SECONDS ); return; }
		$geo = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $geo ) ) { set_transient( 'stp_geo_backoff', 1, 15 * MINUTE_IN_SECONDS ); return; }

		if ( empty( $geo['success'] ) ) {
			stp_diag( 'geo_provider_skip', array( 'provider' => 'ipwho.is', 'ip' => $ip, 'message' => sanitize_text_field( $geo['message'] ?? 'Geo lookup unavailable.' ), 'time' => current_time( 'mysql' ) ) );
			$wpdb->update( stp_t( 'ips' ), array( 'geo_fetched' => 1 ), array( 'ip_address' => $ip ) );
			continue;
		}
		$conn = is_array( $geo['connection'] ?? null ) ? $geo['connection'] : array();
		$new_country_code = substr( strtoupper( preg_replace( '/[^A-Z]/i', '', (string) ( $geo['country_code'] ?? '' ) ) ), 0, 4 ) ?: null;
		$old_country_code = strtoupper( (string) ( $old->country_code ?? '' ) );
		$sensitive_geo = $old && $old_country_code && $new_country_code && $old_country_code !== $new_country_code && ( in_array( (string) $old->status, array( 'blocked', 'trusted' ), true ) || (int) $old->risk_score >= 60 );
		if ( $sensitive_geo ) {
			$verify_key = 'stp_geo_verify_' . md5( $ip );
			$pending = (string) get_transient( $verify_key );
			if ( $pending !== $new_country_code ) {
				set_transient( $verify_key, $new_country_code, 30 * MINUTE_IN_SECONDS );
				stp_diag( 'geo_verify_pending', array( 'ip' => $ip, 'old' => $old_country_code, 'candidate' => $new_country_code, 'time' => current_time( 'mysql' ) ) );
				continue;
			}
			delete_transient( $verify_key );
		}
		$wpdb->update( stp_t( 'ips' ), array(
			'country'      => substr( sanitize_text_field( $geo['country'] ?? '' ), 0, 80 ) ?: null,
			'country_code' => $new_country_code,
			'region'       => substr( sanitize_text_field( $geo['region'] ?? '' ), 0, 100 ) ?: null,
			'city'         => substr( sanitize_text_field( $geo['city'] ?? '' ), 0, 100 ) ?: null,
			'isp'          => substr( sanitize_text_field( $conn['isp'] ?? '' ), 0, 200 ) ?: null,
			'org'          => substr( sanitize_text_field( $conn['org'] ?? '' ), 0, 200 ) ?: null,
			'geo_fetched'  => 1,
		), array( 'ip_address' => $ip ) );
	}
}


// ════════════════════════════════════════════════════════════════
//  IP UTILITIES
// ════════════════════════════════════════════════════════════════

function stp_get_ip() {
	return \DSA\Secure\SecureTrack_Ip_Service::get_ip();
}

function stp_normalize_ip( $ip ) {
	return \DSA\Secure\SecureTrack_Ip_Service::normalize_ip( $ip );
}

function stp_ip_in_cidrs( $ip, $cidrs ) {
	return \DSA\Secure\SecureTrack_Ip_Service::ip_in_cidrs( $ip, $cidrs );
}

function stp_cloudflare_cidrs() {
	return \DSA\Secure\SecureTrack_Ip_Service::cloudflare_cidrs();
}

function stp_trusted_proxy_cidrs() {
	return \DSA\Secure\SecureTrack_Ip_Service::trusted_proxy_cidrs();
}

/**
 * Upserts an IP record and returns the row object.
 */
function stp_blank_ip_row( $ip_str ) {
	return \DSA\Secure\SecureTrack_Ip_Service::blank_ip_row( $ip_str );
}

function stp_get_ip_row( $ip_str ) {
	return \DSA\Secure\SecureTrack_Ip_Service::get_ip_row( $ip_str );
}

function stp_upsert_ip( $ip_str, $increment_hits = true ) {
	return \DSA\Secure\SecureTrack_Ip_Service::upsert_ip( $ip_str, (bool) $increment_hits );
}



function stp_ipv4_prefix( $ip, $octets = 3 ) {
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) return '';
	$parts = explode( '.', $ip );
	return implode( '.', array_slice( $parts, 0, max( 1, min( 3, (int) $octets ) ) ) ) . '.';
}

function stp_ip_has_nearby_threat( $ip, $minutes = 1440 ) {
	global $wpdb;
	$prefix = stp_ipv4_prefix( $ip, 3 );
	if ( ! $prefix ) return false;
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT i.ip_address)
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE i.ip_address LIKE %s AND i.ip_address<>%s
		   AND (e.flag_status='red' OR e.risk_score>=60 OR e.risk_reasons LIKE %s)
		   AND e.created_at > %s",
		$prefix . '%', $ip, '%Attack path probed%', date( 'Y-m-d H:i:s', strtotime( '-' . absint( $minutes ) . ' minutes' ) )
	) );
	return $count > 0;
}

function stp_user_recent_different_ip( $uid, $ip, $minutes = 1440 ) {
	global $wpdb;
	if ( ! $uid ) return false;
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT i.ip_address)
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE e.user_id=%d AND i.ip_address<>%s AND e.created_at > %s",
		$uid, $ip, date( 'Y-m-d H:i:s', strtotime( '-' . absint( $minutes ) . ' minutes' ) )
	) );
	return $count > 0;
}

function stp_user_profile_knows_ip( $uid, $ip ) {
	if ( ! $uid || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) return false;
	$p = stp_get_profile( $uid );
	return in_array( $ip, (array) ( $p['trusted_ips'] ?? array() ), true );
}

function stp_ip_status_is_trusted( $ip_or_row ) {
	return \DSA\Secure\SecureTrack_Ip_Service::status_is_trusted( $ip_or_row );
}

function stp_user_recent_different_untrusted_ip( $uid, $ip, $minutes = 1440 ) {
	global $wpdb;
	if ( ! $uid ) return false;
	$profile = stp_get_profile( $uid );
	$known_ips = (array) ( $profile['trusted_ips'] ?? array() );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT i.ip_address,i.status
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE e.user_id=%d AND i.ip_address<>%s AND e.created_at > %s
		 ORDER BY e.created_at DESC LIMIT 30",
		$uid, $ip, date( 'Y-m-d H:i:s', strtotime( '-' . absint( $minutes ) . ' minutes' ) )
	) );
	foreach ( (array) $rows as $row ) {
		if ( ( $row->status ?? '' ) === 'trusted' ) continue;
		if ( in_array( $row->ip_address, $known_ips, true ) ) continue;
		return true;
	}
	return false;
}

function stp_is_hard_attack_event( $type, $ctx = array(), $reasons = '' ) {
	if ( in_array( $type, array( 'waf_block', 'login_failed', 'rest_abuse', 'xmlrpc', 'break_glass_access', 'file_edit' ), true ) ) return true;
	if ( ! empty( $ctx['sqli'] ) || ! empty( $ctx['xss'] ) || ! empty( $ctx['traversal'] ) || ! empty( $ctx['attack_path'] ) || ! empty( $ctx['waf_payload'] ) ) return true;
	if ( stripos( (string) $reasons, 'Attack path probed' ) !== false || stripos( (string) $reasons, 'Adaptive WAF' ) !== false ) return true;
	return false;
}

function stp_ip_recent_similar_reason( $ip, $url = '', $type = '', $minutes = 1440 ) {
	global $wpdb;
	$path = $url ? wp_parse_url( $url, PHP_URL_PATH ) : '';
	$like = $path ? '%' . $wpdb->esc_like( $path ) . '%' : '%';
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE i.ip_address=%s AND e.event_type=%s AND e.url LIKE %s AND e.risk_score>=25 AND e.created_at > %s",
		$ip, $type, $like, date( 'Y-m-d H:i:s', strtotime( '-' . absint( $minutes ) . ' minutes' ) )
	) );
	return $count >= 2;
}

function stp_ip_recent_attack_chain( $ip, $minutes = 1440 ) {
	global $wpdb;
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE i.ip_address=%s
		   AND (e.event_type='login_failed' OR e.risk_reasons LIKE %s OR e.risk_score>=60)
		   AND e.created_at > %s",
		$ip, '%Attack path probed%', date( 'Y-m-d H:i:s', strtotime( '-' . absint( $minutes ) . ' minutes' ) )
	) );
	return $count > 0;
}

function stp_subnet24_cidr( $ip ) {
	$prefix = stp_ipv4_prefix( $ip, 3 );
	return $prefix ? $prefix . '0/24' : '';
}

function stp_subnet_like( $subnet ) {
	if ( ! preg_match( '/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.0\/24$/', (string) $subnet, $m ) ) return '';
	foreach ( array( $m[1], $m[2], $m[3] ) as $octet ) {
		if ( (int) $octet < 0 || (int) $octet > 255 ) return '';
	}
	return "{$m[1]}.{$m[2]}.{$m[3]}.%";
}

function stp_country_flag( $code ) {
	$code = strtoupper( preg_replace( '/[^A-Z]/i', '', (string) $code ) );
	if ( strlen( $code ) !== 2 ) return '';
	$flag = '';
	for ( $i = 0; $i < 2; $i++ ) {
		$flag .= html_entity_decode( '&#' . ( 127397 + ord( $code[ $i ] ) ) . ';', ENT_NOQUOTES, 'UTF-8' );
	}
	return $flag;
}

function stp_location_label( $row ) {
	$city = is_object( $row ) ? ( $row->city ?? '' ) : ( $row['city'] ?? '' );
	$country = is_object( $row ) ? ( $row->country ?? '' ) : ( $row['country'] ?? '' );
	$code = is_object( $row ) ? ( $row->country_code ?? '' ) : ( $row['country_code'] ?? '' );
	return trim( stp_country_flag( $code ) . ' ' . implode( ', ', array_filter( array( $city, $country ) ) ) );
}

function stp_ip_chain_url( $ip ) {
	return admin_url( 'admin.php?page=stp-chain&ip=' . rawurlencode( (string) $ip ) );
}

function stp_ip_link( $ip, $class = 'stp-ip' ) {
	$ip = trim( (string) $ip );
	if ( ! $ip ) return '';
	return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( stp_ip_chain_url( $ip ) ) . '"><code>' . esc_html( $ip ) . '</code></a>';
}

function stp_seen_cursor_key( $surface ) {
	return 'stp_seen_' . sanitize_key( $surface ) . '_max_id';
}

function stp_seen_cursor( $surface ) {
	$uid = get_current_user_id();
	if ( ! $uid ) return 0;
	return max( 0, (int) get_user_meta( $uid, stp_seen_cursor_key( $surface ), true ) );
}

function stp_mark_seen_cursor( $surface, $cursor ) {
	$uid = get_current_user_id();
	$cursor = max( 0, (int) $cursor );
	if ( ! $uid || ! $cursor ) return;
	$key = stp_seen_cursor_key( $surface );
	$old = max( 0, (int) get_user_meta( $uid, $key, true ) );
	if ( $cursor > $old ) update_user_meta( $uid, $key, $cursor );
}

function stp_effective_block_label( $row ) {
	$status = is_object( $row ) ? ( $row->status ?? $row->ip_st ?? 'unknown' ) : ( $row['status'] ?? $row['ip_st'] ?? 'unknown' );
	$subnet_banned = (int) ( is_object( $row ) ? ( $row->subnet_banned ?? 0 ) : ( $row['subnet_banned'] ?? 0 ) );
	$country_code = strtoupper( (string) ( is_object( $row ) ? ( $row->country_code ?? '' ) : ( $row['country_code'] ?? '' ) ) );
	$blocked_countries = (array) ( stp_cfg()['country_blocklist_codes'] ?? array() );
	if ( $status === 'blocked' ) return array( 'blocked' => true, 'source' => 'ip_block', 'label' => 'Blocked', 'status' => $status );
	if ( $subnet_banned ) return array( 'blocked' => true, 'source' => 'subnet_ban', 'label' => 'Blocked by /24', 'status' => $status );
	if ( $country_code && in_array( $country_code, $blocked_countries, true ) ) return array( 'blocked' => true, 'source' => 'country_blocklist', 'label' => 'Blocked by country', 'status' => $status );
	return array( 'blocked' => false, 'source' => '', 'label' => ucfirst( $status ?: 'unknown' ), 'status' => $status );
}

function stp_chain_title( $type ) {
	$map = array(
		'visitor_pivot'       => 'Visitor-to-attacker pivot',
		'brute_then_success'  => 'Brute force followed by login success',
		'credential_takeover' => 'Possible account takeover',
		'subnet_coordinated'  => 'Coordinated subnet attack',
		'high_risk_event'     => 'High-risk security event',
		'ai_critical_review'  => 'AI escalated uncertain event',
		'break_glass_access'  => 'Break glass login accessed',
		'break_glass_login'   => 'Break glass login success',
		'login_country_policy'=> 'Login country policy blocked',
	);
	return $map[ $type ] ?? 'Security chain detected';
}

function stp_create_alert( $data ) {
	global $wpdb;
	if ( ! stp_table_exists( 'alerts' ) ) return 0;

	$d = wp_parse_args( $data, array(
		'chain_type'  => 'high_risk_event',
		'severity'    => 'high',
		'ip_address'  => null,
		'subnet_24'   => null,
		'user_id'     => 0,
		'username'    => null,
		'event_id'    => null,
		'title'       => '',
		'description' => '',
		'evidence'    => array(),
	) );

	$recent_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . stp_t( 'alerts' ) . "
		 WHERE chain_type=%s AND COALESCE(ip_address,'')=%s AND COALESCE(subnet_24,'')=%s
		   AND alert_time > %s",
		$d['chain_type'], (string) $d['ip_address'], (string) $d['subnet_24'], date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
	) );
	$quiet_minutes = $recent_count <= 0 ? 0 : ( $recent_count === 1 ? 5 : ( $recent_count === 2 ? 15 : 60 ) );
	$since = date( 'Y-m-d H:i:s', strtotime( '-' . $quiet_minutes . ' minutes' ) );
	$dup = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . stp_t( 'alerts' ) . "
		 WHERE is_resolved=0 AND chain_type=%s
		   AND COALESCE(ip_address,'')=%s AND COALESCE(subnet_24,'')=%s
		   AND alert_time > %s",
		$d['chain_type'], (string) $d['ip_address'], (string) $d['subnet_24'], $since
	) );
	if ( $quiet_minutes > 0 && $dup ) return 0;

	$wpdb->insert( stp_t( 'alerts' ), array(
		'alert_time'   => current_time( 'mysql' ),
		'chain_type'   => sanitize_key( $d['chain_type'] ),
		'severity'     => sanitize_key( $d['severity'] ),
		'ip_address'   => $d['ip_address'] ? sanitize_text_field( $d['ip_address'] ) : null,
		'subnet_24'    => $d['subnet_24'] ? sanitize_text_field( $d['subnet_24'] ) : null,
		'user_id'      => (int) $d['user_id'],
		'username'     => $d['username'] ? substr( sanitize_text_field( $d['username'] ), 0, 200 ) : null,
		'event_id'     => $d['event_id'] ? (int) $d['event_id'] : null,
		'title'        => substr( sanitize_text_field( $d['title'] ?: stp_chain_title( $d['chain_type'] ) ), 0, 255 ),
		'description'  => wp_kses_post( $d['description'] ),
		'evidence'     => wp_json_encode( $d['evidence'] ),
		'is_resolved'  => 0,
	) );

	$alert_id = (int) $wpdb->insert_id;
	if ( $alert_id && ! empty( stp_cfg()['alert_on_red'] ) && in_array( $d['severity'], array( 'high', 'critical' ), true ) ) {
		stp_alert( $d['title'] ?: stp_chain_title( $d['chain_type'] ), wp_strip_all_tags( $d['description'] ) );
	}
	return $alert_id;
}

function stp_create_break_glass_alert( $kind, $event_id, $ip, $uid = 0, $username = '', $extra = array() ) {
	$kind = $kind === 'login_success' ? 'login_success' : 'access';
	$title = $kind === 'login_success' ? 'Break glass login succeeded' : 'Break glass login URL accessed';
	$desc = $kind === 'login_success'
		? 'A user successfully logged in through the private SecureTrack break glass recovery path.'
		: 'The private SecureTrack break glass recovery URL was accessed. This should be rare and intentional.';
	$evidence = array_merge( array(
		'ip' => $ip,
		'url' => home_url( stp_break_glass_path() ),
		'user_id' => (int) $uid,
		'username' => $username,
		'time' => current_time( 'mysql' ),
	), (array) $extra );
	return stp_create_alert( array(
		'chain_type'  => $kind === 'login_success' ? 'break_glass_login' : 'break_glass_access',
		'severity'    => 'critical',
		'ip_address'  => $ip,
		'subnet_24'   => stp_subnet24_cidr( $ip ),
		'user_id'     => (int) $uid,
		'username'    => $username,
		'event_id'    => $event_id ? (int) $event_id : null,
		'title'       => $title,
		'description' => $desc,
		'evidence'    => $evidence,
	) );
}

function stp_detect_chain( $ip, $uid, $type, $risk, $args = array() ) {
	global $wpdb;
	$cfg = stp_cfg();
	if ( empty( $cfg['chain_detection'] ) ) return null;
	$mins  = max( 5, min( 1440, (int) ( $cfg['chain_window_mins'] ?? 60 ) ) );
	$since = date( 'Y-m-d H:i:s', strtotime( '-' . $mins . ' minutes' ) );
	$subnet = stp_subnet24_cidr( $ip );

	if ( $type === 'page_view' && (int) $risk['score'] >= 60 ) {
		$normal = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e
			 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
			 WHERE i.ip_address=%s AND e.event_type='page_view' AND e.risk_score<25 AND e.created_at > %s",
			$ip, $since
		) );
		if ( $normal >= 2 ) {
			return array( 'type' => 'visitor_pivot', 'severity' => 'critical', 'detail' => 'This IP browsed normally, then started probing attack paths in the same session window.' );
		}
	}

	if ( $type === 'login_success' ) {
		$fails = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e
			 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
			 WHERE i.ip_address=%s AND e.event_type='login_failed' AND e.created_at > %s",
			$ip, $since
		) );
		if ( $fails >= 3 ) {
			return array( 'type' => 'brute_then_success', 'severity' => 'critical', 'detail' => "Login succeeded after {$fails} failed attempt(s) from the same IP." );
		}
		if ( $uid ) {
			if ( stp_ip_status_is_trusted( $ip ) || stp_user_profile_knows_ip( $uid, $ip ) ) return null;
			$prev_ip = $wpdb->get_var( $wpdb->prepare(
				"SELECT i.ip_address FROM " . stp_t( 'events' ) . " e
				 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
				 WHERE e.user_id=%d AND e.event_type='login_success' AND i.ip_address<>%s
				 ORDER BY e.created_at DESC LIMIT 1",
				$uid, $ip
			) );
			if ( $prev_ip && stp_subnet24_cidr( $prev_ip ) !== $subnet ) {
				return array( 'type' => 'credential_takeover', 'severity' => 'critical', 'detail' => "Same account logged in from a new network range. Previous IP: {$prev_ip}." );
			}
		}
	}

	if ( $subnet ) {
		$prefix = stp_ipv4_prefix( $ip, 3 );
		$attack_ips = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT i.ip_address) FROM " . stp_t( 'events' ) . " e
			 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
			 WHERE i.ip_address LIKE %s AND (e.flag_status='red' OR e.risk_score>=60 OR e.risk_reasons LIKE %s)
			   AND e.created_at > %s",
			$prefix . '%', '%Attack path probed%', $since
		) );
		if ( $attack_ips >= max( 2, (int) ( $cfg['subnet_alert_at'] ?? 2 ) ) ) {
			return array( 'type' => 'subnet_coordinated', 'severity' => 'critical', 'detail' => "{$attack_ips} attacking IPs from {$subnet} were seen within {$mins} minutes." );
		}
	}

	return null;
}

function stp_attack_graph_update( $ip, $type, $args, $risk ) {
	if ( empty( stp_cfg()['attack_graph'] ) ) return null;
	$key = 'stp_graph_' . md5( $ip );
	$graph = get_transient( $key );
	$graph = is_array( $graph ) ? $graph : array( 'stages' => array(), 'first' => time(), 'last' => time() );
	$sub = (string) ( $args['sub'] ?? '' );
	$url = (string) ( $args['url'] ?? '' );
	$stage = '';

	if ( $type === 'rest_abuse' || stripos( $url, '/wp-json/wp/v2/users' ) !== false ) $stage = 'user_enum';
	elseif ( $type === 'xmlrpc' || stripos( $url, 'xmlrpc.php' ) !== false ) $stage = 'xmlrpc_probe';
	elseif ( $type === 'page_view' && ( stripos( $risk['reasons'] ?? '', 'Attack path probed' ) !== false || ! empty( $args['attack_path'] ) ) ) $stage = 'attack_path';
	elseif ( $type === 'page_view' && stripos( $url, 'wp-login.php' ) !== false ) $stage = 'login_probe';
	elseif ( $type === 'login_failed' ) $stage = 'login_failed';
	elseif ( $type === 'waf_block' ) $stage = 'payload_block';

	if ( ! $stage ) return null;
	$graph['stages'][ $stage ] = time();
	$graph['last'] = time();
	set_transient( $key, $graph, 2 * HOUR_IN_SECONDS );

	$seen = array_keys( array_filter( $graph['stages'] ) );
	$recon_score = 0;
	foreach ( array( 'user_enum', 'xmlrpc_probe', 'attack_path', 'login_probe', 'payload_block', 'login_failed' ) as $s ) {
		if ( in_array( $s, $seen, true ) ) $recon_score += 20;
	}
	if ( $recon_score >= 60 && empty( $graph['alerted'] ) ) {
		$graph['alerted'] = time();
		set_transient( $key, $graph, 2 * HOUR_IN_SECONDS );
		set_transient( 'stp_preemptive_' . md5( $ip ), 1, HOUR_IN_SECONDS );
		return array(
			'type'     => 'attack_graph_prediction',
			'severity' => 'critical',
			'detail'   => 'Pre-login reconnaissance chain detected: ' . implode( ' -> ', $seen ) . '. Temporary preemptive limits enabled for this IP.',
			'evidence' => $graph,
		);
	}
	return null;
}

function stp_behavioral_anomaly( $uid, $ip, $type, $args = array() ) {
	if ( empty( stp_cfg()['behavioral_risk'] ) || ! $uid ) return array( 'score' => 0, 'reasons' => array() );
	global $wpdb;
	$score = 0;
	$reasons = array();
	$since = date( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
	$hour = (int) current_time( 'G' );

	$recent_times = $wpdb->get_col( $wpdb->prepare( "SELECT created_at FROM " . stp_t( 'events' ) . " WHERE user_id=%d AND created_at>%s ORDER BY id DESC LIMIT 1000", $uid, $since ) );
	$total = count( $recent_times );
	if ( $total < 8 ) return array( 'score' => 0, 'reasons' => array() );

	$hour_count = 0;
	foreach ( $recent_times as $created_at ) {
		if ( (int) mysql2date( 'G', $created_at, false ) === $hour ) $hour_count++;
	}
	if ( $hour_count === 0 ) { $score += 20; $reasons[] = 'Self-learning baseline: unseen activity hour'; }
	elseif ( ( $hour_count / max( 1, $total ) ) < 0.04 ) { $score += 12; $reasons[] = 'Self-learning baseline: rare activity hour'; }

	$ip_seen = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id WHERE e.user_id=%d AND i.ip_address=%s AND e.created_at>%s",
		$uid, $ip, $since
	) );
	if ( ! $ip_seen ) { $score += 20; $reasons[] = 'Self-learning baseline: unseen IP for user'; }

	$type_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " WHERE user_id=%d AND event_type=%s AND created_at>%s", $uid, $type, $since ) );
	if ( $type_count === 0 && in_array( $type, array( 'admin_activity', 'plugin_action', 'setting_change', 'user_action', 'file_edit' ), true ) ) {
		$score += 18; $reasons[] = 'Self-learning baseline: rare admin behavior for user';
	}
	if ( ! empty( $args['sensitive_screen'] ) && ( $hour < 6 || $hour > 22 ) ) {
		$score += 15; $reasons[] = 'Behavioral risk: sensitive admin screen at unusual hour';
	}
	return array( 'score' => min( 100, $score ), 'reasons' => $reasons );
}

function stp_update_subnet_intel( $ip, $is_attack ) {
	global $wpdb;
	$cfg = stp_cfg();
	if ( empty( $cfg['subnet_intel'] ) || ! stp_table_exists( 'subnets' ) ) return;
	$subnet = stp_subnet24_cidr( $ip );
	if ( ! $subnet ) return;
	$prefix = stp_ipv4_prefix( $ip, 3 );

	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . stp_t( 'subnets' ) . " (subnet,total_events,attack_events,first_seen,last_seen)
		 VALUES (%s,1,%d,NOW(),NOW())
		 ON DUPLICATE KEY UPDATE total_events=total_events+1,attack_events=attack_events+%d,last_seen=NOW()",
		$subnet, $is_attack ? 1 : 0, $is_attack ? 1 : 0
	) );

	$attack_ips = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT i.ip_address) FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE i.ip_address LIKE %s AND (e.flag_status='red' OR e.risk_score>=60 OR e.risk_reasons LIKE %s)
		   AND e.created_at > %s",
		$prefix . '%', '%Attack path probed%', date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
	) );
	$ip_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT ip_address) FROM " . stp_t( 'ips' ) . " WHERE ip_address LIKE %s",
		$prefix . '%'
	) );
	$attack_events = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE i.ip_address LIKE %s AND (e.flag_status='red' OR e.risk_score>=60 OR e.risk_reasons LIKE %s)
		   AND e.created_at > %s",
		$prefix . '%', '%Attack path probed%', date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
	) );
	$score = min( 100, ( $attack_ips * 30 ) + min( 40, $attack_events * 5 ) );
	$level = $score >= 80 ? 'critical' : ( $score >= 50 ? 'high' : ( $score >= 25 ? 'medium' : 'low' ) );
	$wpdb->update( stp_t( 'subnets' ), array(
		'ip_count'      => $ip_count,
		'attack_ips'    => $attack_ips,
		'attack_events' => $attack_events,
		'threat_score'  => $score,
		'threat_level'  => $level,
		'last_seen'     => current_time( 'mysql' ),
	), array( 'subnet' => $subnet ) );
}

function stp_ban_subnet( $subnet, $reason = 'Manual subnet ban' ) {
	global $wpdb;
	$like = stp_subnet_like( $subnet );
	if ( ! $like ) return 0;
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . stp_t( 'subnets' ) . " (subnet,is_banned,ban_reason,ban_time,threat_level,threat_score,first_seen,last_seen)
		 VALUES (%s,1,%s,NOW(),'critical',100,NOW(),NOW())
		 ON DUPLICATE KEY UPDATE is_banned=1,ban_reason=%s,ban_time=NOW(),threat_level='critical',threat_score=100,last_seen=NOW()",
		$subnet, $reason, $reason
	) );
	$updated = $wpdb->query( $wpdb->prepare(
		"UPDATE " . stp_t( 'ips' ) . " SET status='blocked', blocked_at=NOW(), notes=%s WHERE ip_address LIKE %s",
		'Subnet ban: ' . $reason, $like
	) );
	return (int) $updated;
}

function stp_unban_subnet( $subnet ) {
	global $wpdb;
	$like = stp_subnet_like( $subnet );
	if ( ! $like ) return 0;
	$wpdb->update( stp_t( 'subnets' ), array( 'is_banned' => 0, 'ban_reason' => null, 'ban_time' => null ), array( 'subnet' => $subnet ) );
	return (int) $wpdb->query( $wpdb->prepare(
		"UPDATE " . stp_t( 'ips' ) . " SET status='unknown', blocked_at=NULL WHERE status='blocked' AND ip_address LIKE %s",
		$like
	) );
}

function stp_break_glass_cookie_name() {
	return 'stp_bg';
}

function stp_is_break_glass_request() {
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	return trim( (string) $path, '/' ) === stp_break_glass_slug();
}

function stp_break_glass_session_valid() {
	static $valid = null;
	if ( $valid !== null ) return $valid;
	$tok = isset( $_COOKIE[ stp_break_glass_cookie_name() ] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE[ stp_break_glass_cookie_name() ] ) : '';
	if ( strlen( $tok ) !== 64 ) return $valid = false;
	$data = get_transient( 'stp_bg_' . $tok );
	if ( ! is_array( $data ) ) return $valid = false;
	$ua = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 );
	return $valid = (
		hash_equals( (string) ( $data['ip'] ?? '' ), stp_get_ip() )
		&& hash_equals( (string) ( $data['ua_hash'] ?? '' ), hash( 'sha256', $ua ) )
	);
}

function stp_handle_break_glass_login() {
	if ( ! stp_is_break_glass_request() ) return;
	$ip = stp_get_ip();
	$ua = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 );
	if ( ! stp_rate_limit( 'break_glass|' . $ip, 4, 15 * MINUTE_IN_SECONDS ) || ! stp_rate_limit( 'break_glass_global', 60, HOUR_IN_SECONDS ) ) {
		stp_log( 'break_glass_access', array( 'sub' => 'recovery_slug_rate_limited', 'url' => home_url( stp_break_glass_path() ), 'extra' => array( 'ua' => $ua, 'rate_limited' => true ) ) );
		status_header( 429 );
		wp_die( 'Recovery login temporarily rate limited.', 'Rate limited', array( 'response' => 429 ) );
	}
	$tok = bin2hex( random_bytes( 32 ) );
	set_transient( 'stp_bg_' . $tok, array( 'ip' => $ip, 'ua_hash' => hash( 'sha256', $ua ), 'created' => time() ), 15 * MINUTE_IN_SECONDS );
	if ( ! headers_sent() ) {
		$cookie_opts = array(
			'expires'  => time() + 15 * MINUTE_IN_SECONDS,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		);
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) $cookie_opts['domain'] = COOKIE_DOMAIN;
		setcookie( stp_break_glass_cookie_name(), $tok, $cookie_opts );
	}
	$eid = stp_log( 'break_glass_access', array(
		'sub'   => 'recovery_login_slug_accessed',
		'url'   => home_url( stp_break_glass_path() ),
		'extra' => array( 'ua' => $ua, 'recovery_window_minutes' => 15 ),
	) );
	stp_create_break_glass_alert( 'access', $eid, $ip, 0, '', array( 'ua' => $ua, 'recovery_window_minutes' => 15 ) );
	wp_safe_redirect( add_query_arg( 'stp_break_glass', '1', wp_login_url() ) );
	exit;
}
add_action( 'init', 'stp_handle_break_glass_login', -20 );

function stp_block_decision( $ip = null ) {
	return \DSA\Secure\SecureTrack_Ip_Service::block_decision( $ip );
}

function stp_blocked_request_context() {
	$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
	$url = home_url( $uri );
	$url_flags = stp_scan_url( $url );
	$payload = stp_is_static_request() ? array( 'score' => 0, 'flags' => array(), 'normalized' => '' ) : stp_scan_payload( stp_request_payload() );
	return array(
		'url'                => $url,
		'uri'                => $uri,
		'url_flags'          => array_keys( $url_flags ),
		'payload_score'      => (int) ( $payload['score'] ?? 0 ),
		'payload_flags'      => array_keys( (array) ( $payload['flags'] ?? array() ) ),
		'payload_sample'     => substr( (string) ( $payload['normalized'] ?? '' ), 0, 240 ),
		'waf_threshold'      => (int) ( stp_cfg()['waf_block_score'] ?? 80 ),
		'would_waf_block'    => (int) ( $payload['score'] ?? 0 ) >= (int) ( stp_cfg()['waf_block_score'] ?? 80 ),
	);
}

function stp_deny_blocked_request( $decision, $stage = 'block_gate' ) {
	\DSA\Secure\SecureTrack_Ip_Service::deny_blocked_request( $decision, (string) $stage );
}

function stp_is_block_check_exempt() {
	return \DSA\Secure\SecureTrack_Runtime_Guard::is_block_check_exempt();
}

add_action( 'init', function () {
	if ( stp_enforcement_paused() || stp_is_block_check_exempt() ) return;
	$decision = stp_block_decision();
	if ( ! empty( $decision['blocked'] ) ) stp_deny_blocked_request( $decision, 'block_gate' );
}, 1 );

// ════════════════════════════════════════════════════════════════
//  SESSION MANAGEMENT
// ════════════════════════════════════════════════════════════════

function stp_is_static_request() {
	return \DSA\Secure\SecureTrack_Runtime_Guard::is_static_request();
}

function stp_should_create_session_for_event( $type ) {
	if ( stp_is_static_request() ) return false;
	$session_types = array(
		'page_view', 'login_success', 'login_failed', 'logout',
		'waf_block', 'protection_block', 'behavior_signal',
		'admin_activity', 'post_action', 'product_action', 'order_action', 'coupon_action',
		'user_action', 'plugin_action', 'setting_change', 'file_edit', 'media_upload', 'comment_action',
		'pk_auth', 'pk_auth_fail', 'pk_register',
	);
	return in_array( $type, $session_types, true );
}

function stp_session_token() {
	$key = 'stp_sess';
	if ( ! empty( $_COOKIE[ $key ] ) && preg_match( '/^[a-f0-9]{64}$/', $_COOKIE[ $key ] ) ) {
		return $_COOKIE[ $key ];
	}
	$tok = bin2hex( random_bytes( 32 ) );
	if ( ! headers_sent() ) {
		$opts = array(
			'expires'  => time() + 86400 * 7,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) $opts['domain'] = COOKIE_DOMAIN;
		setcookie( $key, $tok, $opts );
	}
	return $tok;
}

/**
 * Gets or creates the current session row.
 */
function stp_upsert_session( $ip ) {
	global $wpdb;
	$tok = stp_session_token();
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . stp_t( 'sessions' ) . " WHERE session_token=%s", $tok
	) );
	if ( $row ) {
		$wpdb->update( stp_t( 'sessions' ), array( 'last_activity' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
		return $row;
	}
	$ua  = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 );
	$bot = (int) (bool) preg_match( '/bot|crawl|spider|slurp|bingbot|googlebot|ahref|semrush|mj12bot/i', $ua );
	$wpdb->insert( stp_t( 'sessions' ), array(
		'session_token' => $tok,
		'ip_id'         => $ip->id,
		'user_id'       => (int) get_current_user_id(),
		'user_agent'    => $ua,
		'device_type'   => preg_match( '/Mobile|Android|iPhone/i', $ua ) ? 'mobile'
		                 : ( preg_match( '/iPad|Tablet/i', $ua ) ? 'tablet' : 'desktop' ),
		'referrer'      => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ), 0, 500 ),
		'started_at'    => current_time( 'mysql' ),
		'last_activity' => current_time( 'mysql' ),
		'is_bot'        => $bot,
	) );
	return (object) array( 'id' => (int) $wpdb->insert_id, 'session_token' => $tok, 'is_bot' => $bot, 'flag_status' => 'yellow', 'user_id' => (int) get_current_user_id() );
}


// ════════════════════════════════════════════════════════════════
//  SECURITY SCANNER  (URL + content analysis)
// ════════════════════════════════════════════════════════════════

function stp_scan_url( $url ) {
	$u = urldecode( $url );
	$path = (string) wp_parse_url( $u, PHP_URL_PATH );
	$f = array();
	if ( preg_match( '/\b(union\b.+?\bselect\b|select\b.+?\bfrom\b|drop\b.+?\btable\b|insert\b.+?\binto\b|exec\s*\(|1\s*=\s*1|or\s+\d+\s*=\s*\d+)/i', $u ) )
		$f['sqli'] = true;
	if ( preg_match( '/(<script|javascript:|onerror\s*=|onload\s*=|alert\s*\(|document\.cookie|eval\s*\()/i', $u ) )
		$f['xss'] = true;
	if ( preg_match( '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%252e)/i', $u ) )
		$f['traversal'] = true;
	if ( preg_match( '#/(?:xmlrpc\.php|wp-config(?:\.php)?|phpmyadmin|pma|adminer|shell\.php|webshell\.php|c99\.php|r57\.php)(?:$|[/?#])#i', $path )
		|| preg_match( '#/(?:\.env|\.git/|\.ssh/|etc/passwd|etc/shadow)(?:$|[/?#])#i', $path ) ) {
		$f['attack_path'] = true;
	}
	$payload = stp_scan_payload( $u );
	if ( $payload['score'] >= 45 ) {
		$f['waf_payload'] = true;
		$f['waf_score'] = $payload['score'];
		$f['waf_flags'] = array_keys( $payload['flags'] );
	}
	return $f;
}

function stp_scan_content( $html ) {
	$f = array();
	if ( preg_match( '/<\?php|<\?=/i', $html ) )                             $f['has_php']    = true;
	if ( preg_match( '/eval\s*\(|base64_decode\s*\(|str_rot13\s*\(/i', $html ) ) $f['has_php'] = true;
	if ( preg_match( '/<script(?!\s+src)/i', $html ) )                        $f['has_script'] = true;
	if ( preg_match( '/<iframe/i', $html ) )                                  $f['has_iframe'] = true;
	return $f;
}


// ════════════════════════════════════════════════════════════════
//  RISK SCORING ENGINE  (0 – 100)
// ════════════════════════════════════════════════════════════════

function stp_risk( $type, $ctx = array() ) {
	$score   = 0;
	$reasons = array();

	$add = function ( $pts, $why ) use ( &$score, &$reasons ) {
		$score   += $pts;
		$reasons[] = $why;
	};

	switch ( $type ) {

		case 'login_failed':
			$f = (int) ( $ctx['failures'] ?? 1 );
			if ( $f >= 10 )     $add( 50, "Brute force ({$f} attempts)" );
			elseif ( $f >= 5 )  $add( 30, "Repeated failures ({$f})" );
			else                $add( 10, 'Failed login' );
			if ( $ctx['proxy'] ?? false ) $add( 15, 'Proxy IP' );
			if ( $ctx['break_glass'] ?? false ) $add( 60, 'Failed login via break glass recovery URL' );
			if ( $ctx['login_country_denied'] ?? false ) $add( 90, 'Login denied by country policy' );
			break;

		case 'login_success':
			if ( $ctx['new_country']  ?? false ) $add( 35, 'New country: ' . ( $ctx['country'] ?? '?' ) );
			if ( $ctx['new_ip']       ?? false ) $add( 15, 'New IP address' );
			if ( $ctx['odd_hour']     ?? false ) $add( 20, 'Unusual login hour' );
			if ( $ctx['after_fails']  ?? false ) $add( 45, 'Successful login after failures' );
			if ( $ctx['proxy']        ?? false ) $add( 20, 'Proxy/VPN' );
			if ( $ctx['break_glass']  ?? false ) $add( 70, 'Successful login via break glass recovery URL' );
			break;

		case 'page_view':
			if ( $ctx['sqli']         ?? false ) $add( 85, 'SQL injection in URL' );
			if ( $ctx['xss']          ?? false ) $add( 75, 'XSS attempt in URL' );
			if ( $ctx['traversal']    ?? false ) $add( 75, 'Path traversal attempt' );
			if ( $ctx['attack_path']  ?? false ) $add( 75, 'Attack path probed' );
			if ( $ctx['waf_payload']  ?? false ) $add( min( 80, (int) ( $ctx['waf_score'] ?? 45 ) ), 'Adaptive WAF suspicious payload' );
			if ( $ctx['rate_burst']   ?? false ) $add( 25, 'Rate burst (>20 req/min)' );
			if ( $ctx['admin_probe']  ?? false ) $add( 45, 'Admin area probed unauthenticated' );
			break;

		case 'post_action':
		case 'product_action':
		case 'order_action':
		case 'coupon_action':
			if ( $ctx['has_php']      ?? false ) $add( 45, 'PHP in content' );
			if ( $ctx['has_script']   ?? false ) $add( 40, 'Script tag in content' );
			if ( $ctx['has_iframe']   ?? false ) $add( 35, 'iFrame in content' );
			if ( $ctx['odd_hour']     ?? false ) $add( 10, 'Off-hours edit' );
			break;

		case 'admin_activity':
			if ( $ctx['sensitive_screen'] ?? false ) $add( 15, 'Sensitive admin screen opened' );
			if ( $ctx['odd_hour'] ?? false ) $add( 10, 'Off-hours admin activity' );
			break;

		case 'user_action':
			if ( $ctx['escalation']   ?? false ) $add( 55, 'Privilege escalation to admin' );
			if ( $ctx['admin_new']    ?? false ) $add( 35, 'New admin account created' );
			if ( $ctx['pwd_reset']    ?? false ) $add( 20, 'Password reset' );
			break;

		case 'setting_change':
			$add( ( $ctx['critical'] ?? false ) ? 35 : 10,
			      ( ( $ctx['critical'] ?? false ) ? 'Critical' : '' ) . ' site setting changed' );
			break;

		case 'file_edit':
			$add( 45, 'Theme/plugin file editor used' );
			if ( $ctx['php_added'] ?? false ) $add( 30, 'PHP code added to file' );
			break;

		case 'plugin_action':
			$add( 15, 'Plugin ' . ( $ctx['action'] ?? 'modified' ) );
			break;

		case 'media_upload':
			if ( $ctx['bad_ext'] ?? false ) $add( 50, 'Suspicious file type uploaded' );
			break;

		case 'comment_action':
			if ( $ctx['spam_like'] ?? false ) $add( 20, 'Spam-like comment' );
			if ( $ctx['has_link']  ?? false ) $add( 10, 'Comment with link' );
			break;

		case 'xmlrpc':
			$add( 65, 'XML-RPC access' );
			break;

		case 'rest_abuse':
			$add( 25, 'REST API rate abuse' );
			break;

		case 'break_glass_access':
			$add( 100, 'Break glass recovery login URL accessed' );
			break;

		case 'protection_block':
			$add( 5, 'SecureTrack denied a blocked request' );
			break;

		case 'waf_block':
			$add( (int) ( $ctx['waf_score'] ?? 80 ), 'Adaptive WAF blocked payload' );
			break;

		case 'behavior_signal':
			$add( (int) ( $ctx['behavior_score'] ?? 30 ), 'Continuous behavioral authentication signal' );
			break;
	}

	$extra = apply_filters( 'stp_risk_extra', array( $score, $reasons ), $type, $ctx );
	if ( is_array( $extra ) && count( $extra ) >= 2 ) {
		$score = (int) $extra[0];
		$reasons = is_array( $extra[1] ) ? $extra[1] : (array) $extra[1];
	}

	/* Global modifiers */
	$is_trusted_context = ! empty( $ctx['ip_trusted'] ) || ! empty( $ctx['profile_trusted_ip'] );
	$is_hard_attack = stp_is_hard_attack_event( $type, $ctx, implode( '; ', $reasons ) );
	if ( ( $ctx['ip_blocked'] ?? false ) && $type !== 'protection_block' ) $score += 50;
	if ( ( $ctx['ip_proxy'] ?? false ) && ! $is_trusted_context ) $score  = min( 100, $score + 15 );
	if ( ( $ctx['ip_high_risk'] ?? false ) && ! $is_trusted_context ) $score  = min( 100, $score + 20 );
	if ( ( $ctx['nearby_threat'] ?? false ) && ! $is_trusted_context ) { $score = min( 100, $score + 35 ); $reasons[] = 'Nearby IP range has recent threats'; }
	if ( $ctx['attack_chain'] ?? false ) { $score = min( 100, $score + 50 ); $reasons[] = 'Attack chain before successful login'; }
	if ( $ctx['same_user_new_ip'] ?? false ) { $score = min( 100, $score + ( $is_trusted_context ? 8 : 30 ) ); $reasons[] = $is_trusted_context ? 'Same account seen from another IP; current IP is trusted/known' : 'Same account seen from different IP recently'; }
	if ( $ctx['adaptive_repeat'] ?? false ) { $score = min( 100, $score + ( $is_trusted_context && ! $is_hard_attack ? 6 : 20 ) ); $reasons[] = $is_trusted_context && ! $is_hard_attack ? 'Trusted IP repeated similar admin activity' : 'Adaptive learning: repeated similar suspicious behavior'; }
	if ( ! empty( $ctx['behavior_anomaly_score'] ) ) {
		$behavior_score = (int) $ctx['behavior_anomaly_score'];
		if ( $is_trusted_context && ! $is_hard_attack ) $behavior_score = min( $behavior_score, 10 );
		$score = min( 100, $score + $behavior_score );
		$reasons[] = implode( '; ', (array) ( $ctx['behavior_anomaly_reasons'] ?? array( 'Behavioral anomaly' ) ) );
	}
	if ( ! empty( $ctx['v2_brain_score'] ) ) {
		$brain_score = (int) $ctx['v2_brain_score'];
		if ( $is_trusted_context && ! $is_hard_attack ) $brain_score = min( $brain_score, 10 );
		$score = min( 100, $score + $brain_score );
		$reasons[] = implode( '; ', (array) ( $ctx['v2_brain_reasons'] ?? array( 'Site Brain anomaly' ) ) );
	}
	if ( ! empty( $ctx['v2_brain_discount'] ) && $score < 60 ) { $score = max( 0, $score - (int) $ctx['v2_brain_discount'] ); $reasons[] = 'Site Brain: known normal site pattern'; }
	if ( $is_trusted_context && ! $is_hard_attack && $score >= 60 ) {
		$score = min( 59, max( 25, $score - 25 ) );
		$reasons[] = 'Trusted/known IP: downgraded routine anomaly, still audited';
	}

	$score = min( 100, max( 0, $score ) );
	$cfg   = stp_cfg();
	$flag  = $score >= $cfg['red_threshold'] ? 'red' : ( $score >= $cfg['yellow_threshold'] ? 'yellow' : 'green' );
	return array( 'score' => $score, 'reasons' => implode( '; ', $reasons ), 'flag' => $flag );
}


// ════════════════════════════════════════════════════════════════
// Site Brain and AI queue live in their own module.
require_once __DIR__ . '/securetrack-site-brain.php';

//  BEHAVIORAL BASELINE  (learning engine per user)
// ════════════════════════════════════════════════════════════════

function stp_get_profile( $uid ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . stp_t( 'profiles' ) . " WHERE user_id=%d", $uid
	), ARRAY_A );
	if ( ! $row ) {
		$row = array( 'user_id' => $uid, 'trusted_ips' => '[]', 'trusted_countries' => '[]',
		              'login_hours' => '{}', 'green_count' => 0, 'risk_count' => 0,
		              'baseline_done' => 0, 'last_ip' => null, 'last_country' => null );
	}
	$row['trusted_ips']       = json_decode( $row['trusted_ips']       ?? '[]', true ) ?: array();
	$row['trusted_countries'] = json_decode( $row['trusted_countries'] ?? '[]', true ) ?: array();
	$row['login_hours']       = json_decode( $row['login_hours']       ?? '{}', true ) ?: array();
	return $row;
}

/**
 * Adds event data to the user's profile and rebuilds baseline flag.
 */
function stp_touch_profile( $uid, $ip, $country, $hour, $was_risky ) {
	global $wpdb;
	$p = stp_get_profile( $uid );

	if ( ! in_array( $ip, $p['trusted_ips'], true ) )
		$p['trusted_ips'][] = $ip;
	if ( $country && ! in_array( $country, $p['trusted_countries'], true ) )
		$p['trusted_countries'][] = $country;
	$p['login_hours'][ $hour ] = ( $p['login_hours'][ $hour ] ?? 0 ) + 1;

	// Keep enough IP history for mobile, DHCP, office, and travel patterns.
	if ( count( $p['trusted_ips'] ) > 100 )
		$p['trusted_ips'] = array_slice( $p['trusted_ips'], -100 );

	$gc = $was_risky ? $p['green_count']     : $p['green_count'] + 1;
	$rc = $was_risky ? $p['risk_count'] + 1  : $p['risk_count'];

	$wpdb->replace( stp_t( 'profiles' ), array(
		'user_id'           => $uid,
		'trusted_ips'       => json_encode( array_values( $p['trusted_ips'] ) ),
		'trusted_countries' => json_encode( array_values( $p['trusted_countries'] ) ),
		'login_hours'       => json_encode( $p['login_hours'] ),
		'green_count'       => $gc,
		'risk_count'        => $rc,
		'last_login'        => current_time( 'mysql' ),
		'last_ip'           => $ip,
		'last_country'      => $country,
		'baseline_done'     => $gc >= 10 ? 1 : 0,
		'updated_at'        => current_time( 'mysql' ),
	) );
}

/**
 * Compares current session attributes against established baseline;
 * returns array of context flags.
 */
function stp_profile_ctx( $uid, $ip, $country, $hour ) {
	$p = stp_get_profile( $uid );
	if ( ! $p['baseline_done'] ) return array(); // Not enough data yet

	$ctx = array();
	if ( $country && ! in_array( $country, $p['trusted_countries'], true ) ) $ctx['new_country'] = true;
	if ( ! in_array( $ip, $p['trusted_ips'], true ) )                        $ctx['new_ip']      = true;
	if ( ! empty( $p['login_hours'] ) ) {
		$total   = array_sum( $p['login_hours'] );
		$this_hr = $p['login_hours'][ $hour ] ?? 0;
		if ( $total >= 10 && ( $this_hr / $total ) < 0.05 ) $ctx['odd_hour'] = true;
	}
	$ctx['country'] = $country;
	return $ctx;
}


// ════════════════════════════════════════════════════════════════
//  CORE EVENT LOGGER
// ════════════════════════════════════════════════════════════════

function stp_event_hash_payload( $data ) {
	return array(
		'session_id'   => isset( $data['session_id'] ) ? (int) $data['session_id'] : null,
		'ip_id'        => isset( $data['ip_id'] ) ? (int) $data['ip_id'] : 0,
		'user_id'      => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
		'username'     => (string) ( $data['username'] ?? '' ),
		'event_type'   => (string) ( $data['event_type'] ?? '' ),
		'event_sub'    => isset( $data['event_sub'] ) ? (string) $data['event_sub'] : null,
		'obj_type'     => isset( $data['obj_type'] ) ? (string) $data['obj_type'] : null,
		'obj_id'       => isset( $data['obj_id'] ) ? (int) $data['obj_id'] : null,
		'obj_title'    => isset( $data['obj_title'] ) ? (string) $data['obj_title'] : null,
		'url'          => isset( $data['url'] ) ? (string) $data['url'] : null,
		'extra'        => isset( $data['extra'] ) ? (string) $data['extra'] : null,
		'risk_score'   => isset( $data['risk_score'] ) ? (int) $data['risk_score'] : 0,
		'risk_reasons' => isset( $data['risk_reasons'] ) ? (string) $data['risk_reasons'] : null,
		'flag_status'  => (string) ( $data['flag_status'] ?? 'yellow' ),
		'created_at'   => (string) ( $data['created_at'] ?? '' ),
		'hash_prev'    => isset( $data['hash_prev'] ) ? (string) $data['hash_prev'] : null,
	);
}

function stp_event_hash( $data ) {
	return hash_hmac( 'sha256', wp_json_encode( stp_event_hash_payload( $data ) ), wp_salt( 'auth' ) );
}

function stp_audit_chain_status( $limit = 200 ) {
	global $wpdb;
	if ( ! stp_table_exists( 'events' ) || ! stp_column_exists( 'events', 'hash_current' ) ) return 'not ready';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . stp_t( 'events' ) . " ORDER BY id DESC LIMIT %d", max( 1, (int) $limit ) ), ARRAY_A );
	if ( ! $rows ) return 'no events';
	$bad = 0;
	foreach ( $rows as $row ) {
		if ( empty( $row['hash_current'] ) || ! hash_equals( (string) $row['hash_current'], stp_event_hash( $row ) ) ) $bad++;
	}
	return $bad ? "{$bad} mismatched row(s) in latest " . count( $rows ) : 'ok';
}

function stp_log( $type, $args = array() ) {
	return \DSA\Secure\SecureTrack_Event_Service::log( $type, $args );
}

function stp_alert( $subject, $body ) {
	$to = stp_cfg()['alert_email'] ?? '';
	if ( ! $to ) return;
	wp_mail( $to, '[SecureTrack] ' . $subject,
		"SecureTrack Pro — Security Alert\n\n{$body}\n\nTime: " . current_time( 'mysql' ) . "\nSite: " . get_site_url() );
}

/** Count recent failed logins for a given IP within the last hour. */
function stp_count_fails( $ip_str ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE i.ip_address=%s AND e.event_type='login_failed'
		   AND e.created_at > %s",
		$ip_str, date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) )
	) );
}


// ════════════════════════════════════════════════════════════════
//  AUTH HOOKS
// ════════════════════════════════════════════════════════════════

function stp_login_country_guard_decision() {
	$cfg = stp_cfg();
	if ( stp_enforcement_paused() ) return array( 'blocked' => false, 'reason' => 'emergency_safe_mode' );
	$policy = sanitize_key( $cfg['login_country_policy'] ?? 'off' );
	$allowed = (array) ( $cfg['login_allowed_country_codes'] ?? array() );
	if ( $policy === 'off' || empty( $allowed ) ) return array( 'blocked' => false );
	$ip = stp_get_ip();
	$row = stp_get_ip_row( $ip );
	$country = strtoupper( (string) ( $row->country_code ?? '' ) );
	if ( $country === '' ) {
		stp_diag( 'login_country_policy_waiting_geo', array( 'ip' => $ip, 'time' => current_time( 'mysql' ) ) );
		return array( 'blocked' => false, 'reason' => 'geo_unknown', 'ip' => $ip );
	}
	if ( in_array( $country, $allowed, true ) ) return array( 'blocked' => false, 'ip' => $ip, 'country' => $country );
	return array( 'blocked' => true, 'policy' => $policy, 'ip' => $ip, 'country' => $country, 'allowed' => $allowed, 'row' => $row );
}

add_filter( 'authenticate', function( $user, $username, $password ) {
	if ( is_wp_error( $user ) ) return $user;
	if ( $username === '' && $password === '' ) return $user;
	$d = stp_login_country_guard_decision();
	if ( empty( $d['blocked'] ) ) return $user;
	global $wpdb;
	$ip_row = stp_upsert_ip( $d['ip'], false );
	if ( ( $d['policy'] ?? '' ) === 'ban' && ! empty( $ip_row->id ) ) {
		$wpdb->update( stp_t( 'ips' ), array(
			'status' => 'blocked',
			'blocked_at' => current_time( 'mysql' ),
			'risk_score' => 100,
			'notes' => 'Auto-banned by login country policy',
		), array( 'id' => (int) $ip_row->id ) );
	}
	$response = ( $d['policy'] ?? '' ) === 'ban'
		? '403 Access denied; IP auto-banned by login country policy'
		: '403 Access denied by login country policy';
	$protection_event_id = stp_log( 'protection_block', array(
		'sub' => 'login_country_policy',
		'username' => sanitize_user( $username ),
		'url' => home_url( '/wp-login.php' ),
		'login_country_denied' => true,
		'suppress_high_risk_alert' => true,
		'extra' => array(
			'country' => $d['country'],
			'allowed' => $d['allowed'],
			'policy' => $d['policy'],
			'response_code' => 403,
			'response_shown' => $response,
		),
	) );
	$eid = stp_log( 'login_failed', array(
		'username' => sanitize_user( $username ),
		'url' => home_url( '/wp-login.php' ),
		'failures' => 1,
		'login_country_denied' => true,
		'suppress_high_risk_alert' => true,
		'extra' => array( 'country' => $d['country'], 'allowed' => $d['allowed'], 'policy' => $d['policy'] ),
	) );
	stp_create_alert( array(
		'chain_type' => 'login_country_policy',
		'severity' => 'critical',
		'ip_address' => $d['ip'],
		'subnet_24' => stp_subnet24_cidr( $d['ip'] ),
		'event_id' => $protection_event_id ?: $eid,
		'title' => ( $d['policy'] === 'ban' ? 'Login country blocked and IP banned' : 'Login country blocked' ),
		'description' => 'Login attempt denied from ' . $d['country'] . '. Allowed countries: ' . implode( ', ', $d['allowed'] ) . '.',
		'evidence' => array( 'country' => $d['country'], 'allowed' => $d['allowed'], 'policy' => $d['policy'] ),
	) );
	return new WP_Error( 'stp_login_country_blocked', __( 'Access denied by site security policy.', 'securetrack-pro' ) );
}, 5, 3 );

add_action( 'wp_login_failed', function ( $login ) {
	$ip    = stp_get_ip();
	$fails = stp_count_fails( $ip ) + 1;

	stp_log( 'login_failed', array(
		'username' => $login,
		'url'      => stp_break_glass_session_valid() ? home_url( stp_break_glass_path() ) : home_url( '/wp-login.php' ),
		'failures' => $fails,
		'break_glass' => stp_break_glass_session_valid(),
		'extra'    => array( 'tried' => $login, 'break_glass' => stp_break_glass_session_valid() ),
	) );

	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		"UPDATE " . stp_t( 'ips' ) . " SET failed_logins=failed_logins+1 WHERE ip_address=%s", $ip
	) );
} );

add_action( 'wp_login', function ( $login, $user ) {
	$ip     = stp_get_ip();
	$ip_obj = stp_upsert_ip( $ip );
	$is_break_glass = stp_break_glass_session_valid();
	$hour   = (int) current_time( 'G' );
	$ctx    = stp_profile_ctx( $user->ID, $ip, $ip_obj->country, $hour );
	$fails  = stp_count_fails( $ip );
	if ( $fails > 0 )        $ctx['after_fails'] = true;
	if ( $ip_obj->is_proxy ) $ctx['proxy']       = true;

	$eid = stp_log( 'login_success', array_merge( $ctx, array(
		'user_id'  => $user->ID, 'username' => $login,
		'url'      => $is_break_glass ? home_url( stp_break_glass_path() ) : home_url( '/' ),
		'break_glass' => $is_break_glass,
		'extra'    => array( 'roles' => $user->roles, 'email' => $user->user_email, 'break_glass' => $is_break_glass ),
	) ) );
	if ( $is_break_glass ) {
		stp_create_break_glass_alert( 'login_success', $eid, $ip, $user->ID, $login, array( 'roles' => $user->roles ) );
	}

	/* Link session to authenticated user */
	$tok = stp_session_token();
	global $wpdb;
	$wpdb->update( stp_t( 'sessions' ), array( 'user_id' => $user->ID ), array( 'session_token' => $tok ) );

	$was_risky = ! empty( $ctx['new_country'] ) || ! empty( $ctx['odd_hour'] );
	stp_touch_profile( $user->ID, $ip, $ip_obj->country, $hour, $was_risky );
}, 10, 2 );

add_action( 'wp_logout', function () {
	stp_log( 'logout', array( 'url' => home_url( '/' ) ) );
	global $wpdb;
	$tok = isset( $_COOKIE['stp_sess'] ) && preg_match( '/^[a-f0-9]{64}$/', $_COOKIE['stp_sess'] ) ? $_COOKIE['stp_sess'] : '';
	if ( $tok && stp_table_exists( 'sessions' ) ) $wpdb->delete( stp_t( 'sessions' ), array( 'session_token' => $tok ) );
	if ( ! headers_sent() ) {
		$opts = array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' );
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) $opts['domain'] = COOKIE_DOMAIN;
		setcookie( 'stp_sess', '', $opts );
	}
} );


// ════════════════════════════════════════════════════════════════
//  POST / CONTENT HOOKS
// ════════════════════════════════════════════════════════════════

function stp_post_event_type( $post_type ) {
	if ( $post_type === 'product' ) return 'product_action';
	if ( $post_type === 'shop_order' || $post_type === 'shop_order_placehold' ) return 'order_action';
	if ( $post_type === 'shop_coupon' ) return 'coupon_action';
	return 'post_action';
}

function stp_post_extra( $pid, $post ) {
	$extra = array( 'status' => $post->post_status, 'type' => $post->post_type );
	if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $pid );
		if ( $product ) {
			$extra['sku'] = $product->get_sku();
			$extra['price'] = $product->get_price();
			$extra['stock_status'] = $product->get_stock_status();
		}
	}
	if ( $post->post_type === 'shop_order' && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $pid );
		if ( $order ) {
			$extra['order_status'] = $order->get_status();
			$extra['order_total'] = $order->get_total();
			$extra['billing_email'] = $order->get_billing_email();
		}
	}
	return $extra;
}

add_action( 'save_post', function ( $pid, $post, $update ) {
	if ( wp_is_post_revision( $pid ) || wp_is_post_autosave( $pid ) ) return;
	if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) return;

	$cf   = stp_scan_content( $post->post_content ?? '' );
	$hour = (int) current_time( 'G' );
	stp_log( stp_post_event_type( $post->post_type ), array_merge( $cf, array(
		'sub'       => $update ? 'edit' : 'create',
		'obj_type'  => $post->post_type,
		'obj_id'    => $pid,
		'obj_title' => $post->post_title,
		'url'       => get_permalink( $pid ) ?: '',
		'odd_hour'  => ( $hour >= 1 && $hour <= 5 ),
		'extra'     => stp_post_extra( $pid, $post ),
	) ) );
}, 10, 3 );

add_action( 'post_updated', function ( $pid, $post_after, $post_before ) {
	if ( wp_is_post_revision( $pid ) || wp_is_post_autosave( $pid ) ) return;
	if ( ! $post_after || ! in_array( $post_after->post_status, array( 'publish', 'draft', 'pending', 'private', 'trash' ), true ) ) return;
	$changed = array();
	foreach ( array( 'post_title' => 'title', 'post_status' => 'status', 'post_content' => 'content', 'post_excerpt' => 'excerpt' ) as $field => $label ) {
		if ( (string) ( $post_before->$field ?? '' ) !== (string) ( $post_after->$field ?? '' ) ) $changed[] = $label;
	}
	if ( ! $changed ) return;
	$key = 'stp_post_updated_' . $pid . '_' . md5( implode( '|', $changed ) );
	if ( get_transient( $key ) ) return;
	set_transient( $key, 1, 30 );
	$cf = stp_scan_content( $post_after->post_content ?? '' );
	stp_log( stp_post_event_type( $post_after->post_type ), array_merge( $cf, array(
		'sub'       => 'update_' . implode( '_', $changed ),
		'obj_type'  => $post_after->post_type,
		'obj_id'    => $pid,
		'obj_title' => $post_after->post_title,
		'url'       => get_permalink( $pid ) ?: '',
		'extra'     => array( 'changed' => $changed, 'before_status' => $post_before->post_status, 'after_status' => $post_after->post_status ),
	) ) );
}, 10, 3 );

add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
	if ( ! $post || $new_status === $old_status || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) return;
	if ( ! in_array( $post->post_type, array( 'post', 'page', 'product', 'shop_order', 'shop_coupon' ), true ) ) return;
	stp_log( stp_post_event_type( $post->post_type ), array(
		'sub'       => 'status_' . sanitize_key( $old_status ) . '_to_' . sanitize_key( $new_status ),
		'obj_type'  => $post->post_type,
		'obj_id'    => (int) $post->ID,
		'obj_title' => $post->post_title,
		'url'       => get_permalink( $post->ID ) ?: '',
		'extra'     => array( 'old_status' => $old_status, 'new_status' => $new_status ),
	) );
}, 10, 3 );

add_action( 'wp_trash_post', function ( $pid ) {
	$p = get_post( $pid );
	if ( ! $p ) return;
	stp_log( stp_post_event_type( $p->post_type ), array( 'sub' => 'trash', 'obj_type' => $p->post_type, 'obj_id' => $pid, 'obj_title' => $p->post_title ) );
} );

add_action( 'before_delete_post', function ( $pid ) {
	$p = get_post( $pid );
	if ( ! $p || $p->post_status === 'auto-draft' ) return;
	stp_log( stp_post_event_type( $p->post_type ), array( 'sub' => 'delete', 'obj_type' => $p->post_type, 'obj_id' => $pid, 'obj_title' => $p->post_title ) );
} );

add_action( 'woocommerce_order_status_changed', function ( $order_id, $old_status, $new_status, $order ) {
	$title = "Order #{$order_id}";
	$extra = array( 'old_status' => $old_status, 'new_status' => $new_status );
	if ( $order && is_object( $order ) ) {
		$extra['order_total'] = method_exists( $order, 'get_total' ) ? $order->get_total() : null;
		$extra['billing_email'] = method_exists( $order, 'get_billing_email' ) ? $order->get_billing_email() : null;
	}
	stp_log( 'order_action', array(
		'sub'       => 'status_' . sanitize_key( $old_status ) . '_to_' . sanitize_key( $new_status ),
		'obj_type'  => 'shop_order',
		'obj_id'    => (int) $order_id,
		'obj_title' => $title,
		'extra'     => $extra,
	) );
}, 10, 4 );

add_action( 'current_screen', function ( $screen ) {
	$is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
	if ( ! is_admin() || $is_ajax || ! is_user_logged_in() || empty( stp_cfg()['track_admin_activity'] ) ) return;
	if ( ! $screen || strpos( (string) $screen->id, 'securetrack' ) !== false ) return;

	$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
	if ( strpos( $uri, 'page=stp' ) !== false ) return;

	$key = 'stp_admin_seen_' . md5( get_current_user_id() . '|' . $screen->id . '|' . $uri );
	if ( get_transient( $key ) ) return;
	set_transient( $key, 1, 120 );

	$sensitive_ids = array( 'plugins', 'plugin-install', 'themes', 'theme-install', 'users', 'user-edit', 'profile', 'options-general', 'settings_page', 'tools', 'update-core', 'site-health', 'woocommerce_page_wc-settings' );
	$is_sensitive = in_array( $screen->id, $sensitive_ids, true )
		|| strpos( $screen->id, 'woocommerce' ) !== false
		|| strpos( $screen->id, 'users' ) !== false
		|| strpos( $screen->id, 'plugins' ) !== false
		|| strpos( $screen->id, 'theme' ) !== false;

	$obj_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	$obj_title = $screen->base;
	if ( $obj_id ) {
		$p = get_post( $obj_id );
		if ( $p ) $obj_title = $p->post_title ?: "#{$obj_id}";
	}

	stp_log( 'admin_activity', array(
		'sub'              => sanitize_key( $screen->id ),
		'obj_type'         => sanitize_key( $screen->post_type ?: $screen->base ),
		'obj_id'           => $obj_id ?: null,
		'obj_title'        => $obj_title,
		'url'              => home_url( $uri ),
		'sensitive_screen' => $is_sensitive,
		'odd_hour'         => ( (int) current_time( 'G' ) >= 1 && (int) current_time( 'G' ) <= 5 ),
		'extra'            => array( 'base' => $screen->base, 'action' => $screen->action, 'post_type' => $screen->post_type ),
	) );

	if ( ! empty( stp_cfg()['track_pages'] ) ) {
		global $wpdb;
		$ip_obj  = stp_upsert_ip( stp_get_ip() );
		$session = stp_upsert_session( $ip_obj );
		if ( ! empty( $session->id ) && empty( $session->is_bot ) ) {
			$wpdb->insert( stp_t( 'pages' ), array(
				'session_id' => $session->id,
				'url'        => substr( home_url( $uri ), 0, 1000 ),
				'page_title' => substr( $screen->id, 0, 500 ),
				'visited_at' => current_time( 'mysql' ),
			) );
			$wpdb->query( $wpdb->prepare( "UPDATE " . stp_t( 'sessions' ) . " SET page_count=page_count+1 WHERE id=%d", $session->id ) );
		}
	}
} );

add_action( 'woocommerce_new_product', function ( $product_id ) {
	$p = get_post( $product_id );
	stp_log( 'product_action', array(
		'sub'       => 'wc_new_product',
		'obj_type'  => 'product',
		'obj_id'    => (int) $product_id,
		'obj_title' => $p ? $p->post_title : "Product #{$product_id}",
		'url'       => get_permalink( $product_id ) ?: '',
		'extra'     => $p ? stp_post_extra( $product_id, $p ) : array(),
	) );
} );

add_action( 'woocommerce_update_product', function ( $product_id ) {
	$p = get_post( $product_id );
	$key = 'stp_wc_product_update_' . (int) $product_id;
	if ( get_transient( $key ) ) return;
	set_transient( $key, 1, 30 );
	stp_log( 'product_action', array(
		'sub'       => 'wc_update_product',
		'obj_type'  => 'product',
		'obj_id'    => (int) $product_id,
		'obj_title' => $p ? $p->post_title : "Product #{$product_id}",
		'url'       => get_permalink( $product_id ) ?: '',
		'extra'     => $p ? stp_post_extra( $product_id, $p ) : array(),
	) );
} );

add_action( 'woocommerce_new_order', function ( $order_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	stp_log( 'order_action', array(
		'sub'       => 'wc_new_order',
		'obj_type'  => 'shop_order',
		'obj_id'    => (int) $order_id,
		'obj_title' => "Order #{$order_id}",
		'extra'     => $order ? array( 'order_status' => $order->get_status(), 'order_total' => $order->get_total(), 'billing_email' => $order->get_billing_email() ) : array(),
	) );
} );

add_action( 'woocommerce_update_order', function ( $order_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	$key = 'stp_wc_order_update_' . (int) $order_id;
	if ( get_transient( $key ) ) return;
	set_transient( $key, 1, 30 );
	stp_log( 'order_action', array(
		'sub'       => 'wc_update_order',
		'obj_type'  => 'shop_order',
		'obj_id'    => (int) $order_id,
		'obj_title' => "Order #{$order_id}",
		'extra'     => $order ? array( 'order_status' => $order->get_status(), 'order_total' => $order->get_total(), 'billing_email' => $order->get_billing_email() ) : array(),
	) );
} );


// ════════════════════════════════════════════════════════════════
//  USER MANAGEMENT HOOKS
// ════════════════════════════════════════════════════════════════

add_action( 'user_register', function ( $uid ) {
	$u = get_userdata( $uid );
	if ( ! $u ) return;
	stp_log( 'user_action', array(
		'sub' => 'register', 'obj_type' => 'user', 'obj_id' => $uid, 'obj_title' => $u->user_login,
		'admin_new' => in_array( 'administrator', (array) $u->roles, true ),
		'extra'     => array( 'roles' => $u->roles, 'email' => $u->user_email ),
	) );
} );

add_action( 'delete_user', function ( $uid ) {
	$u = get_userdata( $uid );
	stp_log( 'user_action', array( 'sub' => 'delete', 'obj_type' => 'user', 'obj_id' => $uid, 'obj_title' => $u ? $u->user_login : "#{$uid}" ) );
	global $wpdb;
	if ( stp_table_exists( 'profiles' ) ) $wpdb->delete( stp_t( 'profiles' ), array( 'user_id' => (int) $uid ) );
	delete_user_meta( $uid, 'stp_behavior_fp' );
	delete_user_meta( $uid, 'stp_last_seen_' . (int) $uid );
	delete_user_meta( $uid, '_stp_public_author_slug' );
} );

add_action( 'profile_update', function ( $uid, $old ) {
	$new = get_userdata( $uid );
	if ( ! $new ) return;
	$old_roles  = (array) $old->roles;
	$new_roles  = (array) $new->roles;
	$escalation = in_array( 'administrator', $new_roles, true ) && ! in_array( 'administrator', $old_roles, true );
	stp_log( 'user_action', array(
		'sub' => 'update', 'obj_type' => 'user', 'obj_id' => $uid, 'obj_title' => $new->user_login,
		'escalation' => $escalation,
		'extra'      => array( 'old_roles' => $old_roles, 'new_roles' => $new_roles ),
	) );
}, 10, 2 );

add_action( 'after_password_reset', function ( $user ) {
	stp_log( 'user_action', array( 'sub' => 'password_reset', 'obj_type' => 'user', 'obj_id' => $user->ID, 'obj_title' => $user->user_login, 'pwd_reset' => true ) );
} );


// ════════════════════════════════════════════════════════════════
//  PLUGIN / THEME / FILE EDITOR HOOKS
// ════════════════════════════════════════════════════════════════

add_action( 'activated_plugin',   function ( $p ) { stp_log( 'plugin_action', array( 'sub' => 'activate',   'obj_title' => $p, 'action' => 'activated' ) ); } );
add_action( 'deactivated_plugin', function ( $p ) { stp_log( 'plugin_action', array( 'sub' => 'deactivate', 'obj_title' => $p, 'action' => 'deactivated' ) ); } );
add_action( 'switch_theme',       function ( $n ) { stp_log( 'plugin_action', array( 'sub' => 'theme_switch', 'obj_title' => $n ) ); } );
add_action( 'load-theme-editor.php',  function () { stp_log( 'file_edit', array( 'sub' => 'theme_editor' ) ); } );
add_action( 'load-plugin-editor.php', function () { stp_log( 'file_edit', array( 'sub' => 'plugin_editor' ) ); } );


// ════════════════════════════════════════════════════════════════
//  SETTINGS / OPTIONS HOOKS
// ════════════════════════════════════════════════════════════════

add_action( 'updated_option', function ( $opt ) {
	$critical_opts = array( 'siteurl', 'blogname', 'admin_email', 'users_can_register', 'default_role', 'upload_path', 'permalink_structure' );
	if ( strpos( $opt, 'stp_' ) === 0 || strpos( $opt, '_transient' ) === 0 ) return;
	stp_log( 'setting_change', array(
		'obj_title' => $opt,
		'critical'  => in_array( $opt, $critical_opts, true ),
		'extra'     => array( 'option' => $opt ),
	) );
}, 10, 3 );


// ════════════════════════════════════════════════════════════════
//  MEDIA UPLOAD HOOK
// ════════════════════════════════════════════════════════════════

add_action( 'add_attachment', function ( $aid ) {
	$file    = get_post_meta( $aid, '_wp_attached_file', true );
	$ext     = strtolower( pathinfo( $file ?? '', PATHINFO_EXTENSION ) );
	$bad_ext = in_array( $ext, array( 'php', 'php3', 'php4', 'php5', 'phtml', 'js', 'exe', 'sh', 'bat' ), true );
	stp_log( 'media_upload', array(
		'obj_type' => 'attachment', 'obj_id' => $aid, 'obj_title' => basename( $file ?? '' ),
		'bad_ext'  => $bad_ext,
		'extra'    => array( 'ext' => $ext, 'file' => $file ),
	) );
} );

add_action( 'transition_comment_status', function ( $new_status, $old_status, $comment ) {
	if ( ! $comment || $new_status === $old_status ) return;
	$text = (string) ( $comment->comment_content ?? '' );
	stp_log( 'comment_action', array(
		'sub'       => "{$old_status}_to_{$new_status}",
		'obj_type'  => 'comment',
		'obj_id'    => (int) $comment->comment_ID,
		'obj_title' => substr( wp_strip_all_tags( $text ), 0, 120 ),
		'has_link'  => (bool) preg_match( '#https?://#i', $text ),
		'spam_like' => ( $new_status === 'spam' ),
		'extra'     => array( 'post_id' => (int) $comment->comment_post_ID, 'author' => $comment->comment_author ),
	) );
}, 10, 3 );


// ════════════════════════════════════════════════════════════════
//  VISITOR PAGE TRACKING  (template_redirect hook)
// ════════════════════════════════════════════════════════════════

add_action( 'template_redirect', function () {
	stp_diag( 'last_template_redirect', array( 'url' => $_SERVER['REQUEST_URI'] ?? '/', 'time' => current_time( 'mysql' ), 'user_id' => get_current_user_id() ) );
	$cfg = stp_cfg();
	if ( ! $cfg['track_visitors'] ) { stp_diag( 'last_skip_reason', 'Visitor tracking setting is OFF.' ); return; }
	if ( stp_is_static_request() || is_feed() || is_robots() || is_trackback() ) return;

	$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	$url   = ( is_ssl() ? 'https' : 'http' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' );
	$flags = stp_scan_url( $url );
	$is_known_bot = preg_match( '/bot|crawl|spider|slurp|bingbot|googlebot|ahref|semrush|mj12bot/i', $ua );
	$is_attack_probe = ! empty( $flags['attack_path'] ) || ! empty( $flags['sqli'] ) || ! empty( $flags['xss'] ) || ! empty( $flags['traversal'] ) || ( ! empty( $flags['waf_score'] ) && (int) $flags['waf_score'] >= 70 );
	if ( $is_known_bot && ! $is_attack_probe ) { stp_diag( 'last_skip_reason', 'Clean known crawler skipped: ' . substr( $ua, 0, 120 ) ); return; }

	/* Transient-based per-IP rate limiter */
	$ip  = stp_get_ip();
	$rk  = 'stp_r_' . md5( $ip );
	$cnt = (int) get_transient( $rk );
	set_transient( $rk, $cnt + 1, 60 );
	if ( $cnt > 20 ) $flags['rate_burst'] = true;

	/* Admin area probe by unauthenticated visitor */
	if ( strpos( $url, '/wp-admin' ) !== false && ! is_user_logged_in() )
		$flags['admin_probe'] = true;

	stp_log( 'page_view', array_merge( $flags, array(
		'url'   => $url,
		'extra' => array( 'ref' => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ), 0, 200 ) ),
	) ) );

	/* Detailed page navigation table */
	if ( $cfg['track_pages'] ) {
		global $wpdb;
		$ip_obj  = stp_upsert_ip( $ip );
		$session = stp_upsert_session( $ip_obj );
		if ( $session->is_bot ) return;

		$wpdb->insert( stp_t( 'pages' ), array(
			'session_id' => $session->id,
			'url'        => substr( $url, 0, 1000 ),
			'page_title' => '',
			'visited_at' => current_time( 'mysql' ),
		) );
		$cur_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT page_count FROM " . stp_t( 'sessions' ) . " WHERE id=%d", $session->id
		) );
		$wpdb->update( stp_t( 'sessions' ), array( 'page_count' => $cur_count + 1 ), array( 'id' => $session->id ) );
	}
} );

add_action( 'xmlrpc_call', function ( $method ) {
	stp_log( 'xmlrpc', array( 'sub' => $method ) );
} );


// ════════════════════════════════════════════════════════════════
//  FRONTEND JS  —  non-blocking time-on-page beacon
// ════════════════════════════════════════════════════════════════

add_action( 'wp_footer', function () {
	stp_diag( 'last_wp_footer', array( 'url' => $_SERVER['REQUEST_URI'] ?? '/', 'time' => current_time( 'mysql' ) ) );
	if ( ! empty( stp_cfg()['honeypot_enabled'] ) ) {
		echo '<a href="' . esc_url( home_url( stp_honeypot_path() ) ) . '" rel="nofollow" aria-hidden="true" tabindex="-1" style="position:absolute;left:-99999px;top:auto;width:1px;height:1px;overflow:hidden">.</a>' . "\n";
	}
	if ( ! stp_cfg()['track_pages'] ) return;
	$tok  = stp_session_token();
	$exit_nonce = wp_create_nonce( 'stp_exit_' . $tok );
	$ajax = esc_url( admin_url( 'admin-ajax.php' ) );
	echo "<script>
(function(){
  if(window.__stpTimeTrack)return; window.__stpTimeTrack=true;
  var last=Date.now(),u=location.href.split('#')[0],k='" . esc_js( $tok ) . "',n='" . esc_js( $exit_nonce ) . "',sent=false;
  function payload(sec){
    var fd=new FormData();
    fd.append('action','stp_exit');
    fd.append('u',u);fd.append('s',sec);fd.append('k',k);fd.append('n',n);
    return fd;
  }
  function send(force){
    var now=Date.now(),s=Math.round((now-last)/1000);
    if(s<1)return;
    last=now; sent=true;
    var fd=payload(s),ok=false;
    try{ if(navigator.sendBeacon) ok=navigator.sendBeacon('" . $ajax . "',fd); }catch(e){}
    if(!ok&&force&&window.fetch){ try{ fetch('" . $ajax . "',{method:'POST',body:fd,keepalive:true,credentials:'same-origin'}); }catch(e){} }
  }
  setInterval(function(){ if(document.visibilityState==='visible')send(false); },15000);
  window.addEventListener('pagehide',function(){send(true);});
  window.addEventListener('beforeunload',function(){send(true);});
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')send(true);else last=Date.now();});
})();
</script>\n";
} );

add_action( 'admin_footer', function () {
	if ( empty( stp_cfg()['track_pages'] ) || empty( stp_cfg()['track_admin_activity'] ) ) return;
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
	if ( $screen && strpos( (string) $screen->id, 'securetrack' ) !== false ) return;
	if ( strpos( $uri, 'page=stp' ) !== false ) return;
	$tok  = stp_session_token();
	$exit_nonce = wp_create_nonce( 'stp_exit_' . $tok );
	$ajax = esc_url( admin_url( 'admin-ajax.php' ) );
	echo "<script>
(function(){
  if(window.__stpTimeTrack)return; window.__stpTimeTrack=true;
  var last=Date.now(),u=location.href.split('#')[0],k='" . esc_js( $tok ) . "',n='" . esc_js( $exit_nonce ) . "';
  function payload(sec){var fd=new FormData();fd.append('action','stp_exit');fd.append('u',u);fd.append('s',sec);fd.append('k',k);fd.append('n',n);return fd;}
  function send(force){var now=Date.now(),s=Math.round((now-last)/1000);if(s<1)return;last=now;var fd=payload(s),ok=false;try{if(navigator.sendBeacon)ok=navigator.sendBeacon('" . $ajax . "',fd);}catch(e){} if(!ok&&force&&window.fetch){try{fetch('" . $ajax . "',{method:'POST',body:fd,keepalive:true,credentials:'same-origin'});}catch(e){}}}
  setInterval(function(){if(document.visibilityState==='visible')send(false);},15000);
  window.addEventListener('pagehide',function(){send(true);});
  window.addEventListener('beforeunload',function(){send(true);});
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')send(true);else last=Date.now();});
})();
</script>\n";
	if ( ! empty( stp_cfg()['behavioral_risk'] ) && current_user_can( 'manage_options' ) ) {
		echo "<script>
(function(){
  if(window.__stpBehaviorTrack)return; window.__stpBehaviorTrack=true;
  var m=0,c=0,k=0,t=Date.now(),ajax='" . esc_js( admin_url( 'admin-ajax.php' ) ) . "',nonce='" . esc_js( wp_create_nonce( 'stp_behavior' ) ) . "';
  document.addEventListener('mousemove',function(){m++;},{passive:true});
  document.addEventListener('click',function(){c++;},{passive:true});
  document.addEventListener('keydown',function(){k++;},{passive:true});
  function send(){
    var age=Math.max(1,Math.round((Date.now()-t)/1000));
    var fd=new FormData();
    fd.append('action','stp_behavior');fd.append('nonce',nonce);
    fd.append('m',m);fd.append('c',c);fd.append('k',k);fd.append('age',age);
    fd.append('platform','');fd.append('ua',navigator.userAgent||'');
    fd.append('screen',(screen.width||0)+'x'+(screen.height||0));
    if(navigator.sendBeacon) navigator.sendBeacon(ajax,fd); else if(window.fetch) fetch(ajax,{method:'POST',body:fd,keepalive:true,credentials:'same-origin'});
    m=0;c=0;k=0;t=Date.now();
  }
  setInterval(send,30000);
  window.addEventListener('pagehide',send);
})();
</script>\n";
	}
} );

add_action( 'wp_ajax_stp_exit',        'stp_ajax_exit' );
add_action( 'wp_ajax_nopriv_stp_exit', 'stp_ajax_exit' );
add_action( 'wp_ajax_stp_behavior', 'stp_ajax_behavior' );

function stp_ajax_exit() {
	global $wpdb;
	$tok  = preg_replace( '/[^a-f0-9]/', '', $_POST['k'] ?? '' );
	$nonce = sanitize_text_field( wp_unslash( $_POST['n'] ?? '' ) );
	$secs = max( 0, min( 120, (int) ( $_POST['s'] ?? 0 ) ) );
	$url  = esc_url_raw( substr( $_POST['u'] ?? '', 0, 1000 ) );
	if ( ! $tok || $secs < 1 ) { http_response_code( 204 ); exit; }
	if ( ! wp_verify_nonce( $nonce, 'stp_exit_' . $tok ) ) { http_response_code( 204 ); exit; }
	if ( ! stp_rate_limit( 'exit|' . $tok . '|' . stp_get_ip(), 40, 60 ) ) { http_response_code( 204 ); exit; }

	$sid = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM " . stp_t( 'sessions' ) . " WHERE session_token=%s", $tok
	) );
	if ( $sid ) {
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE " . stp_t( 'pages' ) . "
			 SET time_spent=time_spent+%d
			 WHERE session_id=%d AND url=%s
			 ORDER BY visited_at DESC LIMIT 1",
			$secs, $sid, $url
		) );
		if ( ! $updated ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE " . stp_t( 'pages' ) . "
				 SET time_spent=time_spent+%d
				 WHERE session_id=%d
				 ORDER BY visited_at DESC LIMIT 1",
				$secs, $sid
			) );
		}
		$wpdb->query( $wpdb->prepare(
			"UPDATE " . stp_t( 'sessions' ) . " SET total_seconds=total_seconds+%d WHERE id=%d",
			$secs, $sid
		) );
	}
	http_response_code( 204 );
	exit;
}

function stp_ajax_behavior() {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'stp_behavior', 'nonce', false ) ) { http_response_code( 204 ); exit; }
	$uid = get_current_user_id();
	if ( ! stp_rate_limit( 'behavior|' . $uid, 30, 60 ) ) { http_response_code( 204 ); exit; }
	$data = array(
		'platform' => sanitize_text_field( $_POST['platform'] ?? '' ),
		'ua_hash'  => substr( hash( 'sha256', sanitize_text_field( $_POST['ua'] ?? '' ) ), 0, 16 ),
		'screen'   => sanitize_text_field( $_POST['screen'] ?? '' ),
		'm'        => max( 0, (int) ( $_POST['m'] ?? 0 ) ),
		'c'        => max( 0, (int) ( $_POST['c'] ?? 0 ) ),
		'k'        => max( 0, (int) ( $_POST['k'] ?? 0 ) ),
		'age'      => max( 1, (int) ( $_POST['age'] ?? 1 ) ),
	);
	$prev = get_user_meta( $uid, 'stp_behavior_fp', true );
	$score = 0; $reasons = array();
	if ( is_array( $prev ) ) {
		if ( ! empty( $prev['platform'] ) && $prev['platform'] !== $data['platform'] ) { $score += 35; $reasons[] = 'platform changed during admin session'; }
		if ( ! empty( $prev['ua_hash'] ) && $prev['ua_hash'] !== $data['ua_hash'] ) { $score += 35; $reasons[] = 'browser fingerprint changed'; }
		if ( ! empty( $prev['screen'] ) && $prev['screen'] !== $data['screen'] ) { $score += 10; $reasons[] = 'screen size changed'; }
		$prev_rate = (float) ( $prev['activity_rate'] ?? 0 );
		$rate = ( $data['m'] + ( $data['c'] * 5 ) + ( $data['k'] * 4 ) ) / $data['age'];
		if ( $prev_rate > 0 && ( $rate > $prev_rate * 5 || $rate < $prev_rate * 0.1 ) ) { $score += 15; $reasons[] = 'interaction cadence drift'; }
		$data['activity_rate'] = round( $prev_rate ? ( $prev_rate * 0.8 + $rate * 0.2 ) : $rate, 3 );
	} else {
		$data['activity_rate'] = round( ( $data['m'] + ( $data['c'] * 5 ) + ( $data['k'] * 4 ) ) / $data['age'], 3 );
	}
	update_user_meta( $uid, 'stp_behavior_fp', $data );
	if ( $score >= 35 && ! get_transient( 'stp_behavior_alert_' . $uid ) ) {
		set_transient( 'stp_behavior_alert_' . $uid, 1, 15 * MINUTE_IN_SECONDS );
		$user = wp_get_current_user();
		stp_log( 'behavior_signal', array(
			'user_id'        => $uid,
			'username'       => $user ? $user->user_login : '',
			'behavior_score' => min( 100, $score ),
			'url'            => admin_url(),
			'extra'          => array( 'reasons' => $reasons, 'sample' => $data ),
		) );
	}
	http_response_code( 204 );
	exit;
}


// ════════════════════════════════════════════════════════════════
//  AJAX — ADMIN ACTIONS
// ════════════════════════════════════════════════════════════════

function stp_rate_limit( $bucket, $limit = 120, $window = 60 ) {
	global $wpdb;
	$limit = max( 1, (int) $limit );
	$window = max( 10, (int) $window );
	$now = time();
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {
		$slot = (int) ( floor( $now / $window ) * $window );
		$key = 'rl:' . hash( 'sha256', (string) $bucket . '|' . $slot );
		wp_cache_add( $key, 0, 'securetrack_rate', $window + 5 );
		$count = wp_cache_incr( $key, 1, 'securetrack_rate' );
		if ( $count !== false ) return (int) $count <= $limit;
	}
	if ( ! stp_table_exists( 'rate_limits' ) ) {
		$key = 'stp_rl_' . hash( 'sha256', (string) $bucket );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) return false;
		set_transient( $key, $count + 1, $window );
		return true;
	}
	$slot_size = max( 1, min( 10, (int) ceil( $window / 12 ) ) );
	$slot = (int) ( floor( $now / $slot_size ) * $slot_size );
	$bucket_id = hash( 'sha256', (string) $bucket );
	$hash = hash( 'sha256', $bucket_id . '|' . $slot );
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . stp_t( 'rate_limits' ) . " (bucket_hash,bucket_id,counter,window_start,expires_at)
		 VALUES (%s,%s,LAST_INSERT_ID(1),%d,%d)
		 ON DUPLICATE KEY UPDATE
		   counter=LAST_INSERT_ID(counter+1),
		   expires_at=%d",
		$hash, $bucket_id, $slot, $now + $window + 60, $now + $window + 60
	) );
	$current_slot_count = (int) $wpdb->get_var( "SELECT LAST_INSERT_ID()" );
	if ( $current_slot_count === 0 ) $current_slot_count = 1;
	if ( mt_rand( 1, 100 ) === 1 ) $wpdb->query( $wpdb->prepare( "DELETE FROM " . stp_t( 'rate_limits' ) . " WHERE expires_at < %d LIMIT 500", $now ) );
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(counter),0) FROM " . stp_t( 'rate_limits' ) . " WHERE bucket_id=%s AND window_start>%d",
		$bucket_id, $now - $window
	) );
	return max( $count, $current_slot_count ) <= $limit;
}

function stp_check() {
	if ( ! check_ajax_referer( 'stp_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) )
		wp_send_json_error( 'Unauthorized', 403 );
	$action = sanitize_key( $_REQUEST['action'] ?? 'stp_admin' );
	$limit = $action === 'stp_live_feed' ? 90 : 120;
	if ( current_user_can( 'manage_options' ) ) $limit = max( $limit, 600 );
	if ( stp_ip_status_is_trusted( stp_get_ip() ) ) $limit = max( $limit, 360 );
	if ( stp_enforcement_paused() ) return;
	if ( ! stp_rate_limit( 'admin|' . get_current_user_id() . '|' . stp_get_ip() . '|' . $action, $limit, 60 ) )
		wp_send_json_error( 'Rate limited', 429 );
}

function stp_endpoint_type() {
	return \DSA\Secure\SecureTrack_Runtime_Guard::endpoint_type();
}

function stp_endpoint_rate_limit_guard() {
	\DSA\Secure\SecureTrack_Runtime_Guard::endpoint_rate_limit_guard();
}
add_action( 'init', 'stp_endpoint_rate_limit_guard', -10 );

foreach ( array( 'block_ip', 'unblock_ip', 'block_ip_address', 'unblock_ip_address', 'block_nearby_ips', 'trust_ip', 'delete_event', 'delete_ip', 'green_flag', 'get_stats', 'ban_subnet', 'unban_subnet', 'resolve_alert' ) as $_stp_a )
	add_action( 'wp_ajax_stp_' . $_stp_a, 'stp_ajax_' . $_stp_a );

function stp_ajax_block_ip() {
	global $wpdb; stp_check();
	$wpdb->update( stp_t( 'ips' ), array( 'status' => 'blocked', 'blocked_at' => current_time( 'mysql' ) ), array( 'id' => (int) $_POST['id'] ) );
	wp_send_json_success();
}
function stp_ajax_unblock_ip() {
	global $wpdb; stp_check();
	$id = (int) ( $_POST['id'] ?? 0 );
	$wpdb->update( stp_t( 'ips' ), array( 'status' => 'unknown', 'blocked_at' => null, 'notes' => 'Unblocked by admin; still monitored' ), array( 'id' => $id ) );
	wp_send_json_success();
}
function stp_ajax_block_ip_address() {
	global $wpdb; stp_check();
	$ip = stp_normalize_ip( $_POST['ip'] ?? '' );
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) wp_send_json_error( 'Invalid IP address.', 400 );
	$ip_row = stp_upsert_ip( $ip, false );
	$wpdb->update( stp_t( 'ips' ), array( 'status' => 'blocked', 'blocked_at' => current_time( 'mysql' ) ), array( 'id' => (int) $ip_row->id ) );
	wp_send_json_success();
}
function stp_ajax_unblock_ip_address() {
	global $wpdb; stp_check();
	$ip = stp_normalize_ip( $_POST['ip'] ?? '' );
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) wp_send_json_error( 'Invalid IP address.', 400 );
	$ip_row = stp_upsert_ip( $ip, false );
	$wpdb->update( stp_t( 'ips' ), array( 'status' => 'unknown', 'blocked_at' => null, 'notes' => 'Unblocked by admin; still monitored' ), array( 'id' => (int) $ip_row->id ) );
	wp_send_json_success();
}
function stp_ajax_block_nearby_ips() {
	global $wpdb; stp_check();
	$id = (int) ( $_POST['id'] ?? 0 );
	$ip = $wpdb->get_var( $wpdb->prepare( "SELECT ip_address FROM " . stp_t( 'ips' ) . " WHERE id=%d", $id ) );
	$prefix = stp_ipv4_prefix( $ip, 3 );
	if ( ! $prefix ) wp_send_json_error( 'Only IPv4 /24 range blocking is supported for this action.', 400 );
	$updated = $wpdb->query( $wpdb->prepare( "UPDATE " . stp_t( 'ips' ) . " SET status='blocked', blocked_at=NOW() WHERE ip_address LIKE %s", $prefix . '%' ) );
	$subnet = $prefix . '0/24';
	stp_ban_subnet( $subnet, 'Nearby IP quick block' );
	stp_alert( 'Nearby IP Range Blocked', "Blocked {$updated} tracked IP(s) in {$subnet} after correlated suspicious activity." );
	wp_send_json_success( array( 'blocked' => (int) $updated, 'range' => $subnet ) );
}
function stp_ajax_ban_subnet() {
	stp_check();
	$subnet = sanitize_text_field( $_POST['subnet'] ?? '' );
	$reason = sanitize_text_field( $_POST['reason'] ?? 'Manual subnet ban' );
	if ( ! stp_subnet_like( $subnet ) ) wp_send_json_error( 'Invalid IPv4 /24 subnet.', 400 );
	$updated = stp_ban_subnet( $subnet, $reason );
	wp_send_json_success( array( 'subnet' => $subnet, 'blocked' => $updated ) );
}
function stp_ajax_unban_subnet() {
	stp_check();
	$subnet = sanitize_text_field( $_POST['subnet'] ?? '' );
	if ( ! stp_subnet_like( $subnet ) ) wp_send_json_error( 'Invalid IPv4 /24 subnet.', 400 );
	$updated = stp_unban_subnet( $subnet );
	wp_send_json_success( array( 'subnet' => $subnet, 'unblocked' => $updated ) );
}
function stp_ajax_trust_ip() {
	global $wpdb; stp_check();
	$wpdb->update( stp_t( 'ips' ), array( 'status' => 'trusted', 'risk_score' => 0, 'blocked_at' => null ), array( 'id' => (int) $_POST['id'] ) );
	wp_send_json_success();
}
function stp_ajax_delete_event() {
	global $wpdb; stp_check();
	$wpdb->delete( stp_t( 'events' ), array( 'id' => (int) $_POST['id'] ) );
	wp_send_json_success();
}
function stp_ajax_delete_ip() {
	global $wpdb; stp_check();
	$id = (int) $_POST['id'];
	$wpdb->delete( stp_t( 'events' ), array( 'ip_id' => $id ) );
	$wpdb->delete( stp_t( 'ips' ),    array( 'id'    => $id ) );
	wp_send_json_success();
}
function stp_ajax_green_flag() {
	global $wpdb; stp_check();
	$id = (int) $_POST['id'];
	$t  = sanitize_text_field( $_POST['t'] ?? 'e' );
	if ( $t === 'ip' ) {
		$wpdb->update( stp_t( 'ips' ),    array( 'status' => 'trusted', 'risk_score' => 0, 'blocked_at' => null ), array( 'id'    => $id ) );
		$wpdb->update( stp_t( 'events' ), array( 'flag_status' => 'green' ),                  array( 'ip_id' => $id ) );
	} else {
		$wpdb->update( stp_t( 'events' ), array( 'flag_status' => 'green', 'reviewed' => 1 ), array( 'id' => $id ) );
	}
	wp_send_json_success();
}
function stp_ajax_resolve_alert() {
	global $wpdb; stp_check();
	$id = (int) ( $_POST['id'] ?? 0 );
	$action = sanitize_text_field( $_POST['action_taken'] ?? 'acknowledged' );
	$wpdb->update( stp_t( 'alerts' ), array(
		'is_resolved'  => 1,
		'action_taken' => $action,
		'resolved_at'  => current_time( 'mysql' ),
	), array( 'id' => $id ) );
	wp_send_json_success();
}
function stp_ajax_get_stats() {
	global $wpdb; stp_check();
	$d = date( 'Y-m-d' );
	wp_send_json_success( array(
		'events_today'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " WHERE DATE(created_at)='{$d}'" ),
		'red_today'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " WHERE DATE(created_at)='{$d}' AND flag_status='red'" ),
		'blocked_ips'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'ips' )    . " WHERE status='blocked'" ),
		'active_sessions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'sessions' ) . " WHERE last_activity > DATE_SUB(NOW(),INTERVAL 30 MINUTE)" ),
		'open_alerts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'alerts' ) . " WHERE is_resolved=0" ),
	) );
}


// ════════════════════════════════════════════════════════════════
// Core SecureTrack admin screens live in their own module.
require_once __DIR__ . '/securetrack-admin-core.php';

// ════════════════════════════════════════════════════════════════
// Extended SecureTrack admin/live analytics live in their own module.
require_once __DIR__ . '/securetrack-admin-extended.php';
