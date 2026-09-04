<?php
/**
 * Kiwe Auth - Passkey-first auth for WordPress.
 *
 * Greenfield v3 snippet:
 * - Native popup. No Bricks dependency.
 * - WebAuthn/passkeys are the primary credential.
 * - Old Argon2id/PIN/Ed25519 endpoints are intentionally gone.
 * - Email/phone verification uses short lived tokens, never frontend user IDs.
 * - TOTP, backup codes, trusted devices, role policies, and SecureTrack hooks.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'PK_STAGE3_LOADED' ) ) return;
define( 'PK_STAGE3_LOADED', true );

if ( ! defined( 'PK_VER' ) )              define( 'PK_VER', '3.0.0' );
if ( ! defined( 'PK_NS' ) )               define( 'PK_NS', 'phonekey/v3' );
if ( ! defined( 'PK_DEVICE_COOKIE' ) )    define( 'PK_DEVICE_COOKIE', 'pk_device' );
if ( ! defined( 'PK_VISITOR_COOKIE' ) )   define( 'PK_VISITOR_COOKIE', 'pkv' );
if ( ! defined( 'PK_FLOW_TTL' ) )         define( 'PK_FLOW_TTL', 900 );
if ( ! defined( 'PK_CHALLENGE_TTL' ) )    define( 'PK_CHALLENGE_TTL', 300 );
if ( ! defined( 'PK_OTP_TTL' ) )          define( 'PK_OTP_TTL', 600 );
if ( ! defined( 'PK_RECOVERY_TTL' ) )     define( 'PK_RECOVERY_TTL', 900 );

/* ============================================================
   Tables
   ============================================================ */

function pk_t( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'pk_' . sanitize_key( $name );
}

function pk_create_tables() {
	global $wpdb;
	$cs = $wpdb->get_charset_collate();

	$sql = "
CREATE TABLE " . pk_t( 'challenges' ) . " (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	purpose VARCHAR(40) NOT NULL,
	token_hash VARCHAR(96) NOT NULL,
	challenge VARCHAR(255) NOT NULL DEFAULT '',
	user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	anchor_hash VARCHAR(96) NOT NULL DEFAULT '',
	anchor_type VARCHAR(16) NOT NULL DEFAULT '',
	ip_hash VARCHAR(96) NOT NULL DEFAULT '',
	meta LONGTEXT NULL,
	used TINYINT(1) NOT NULL DEFAULT 0,
	created_at DATETIME NOT NULL,
	expires_at DATETIME NOT NULL,
	used_at DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY token_hash (token_hash),
	KEY purpose_user (purpose,user_id,used),
	KEY anchor_lookup (anchor_hash,anchor_type,used),
	KEY expires_at (expires_at)
) $cs;

CREATE TABLE " . pk_t( 'credentials' ) . " (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT(20) UNSIGNED NOT NULL,
	credential_id VARCHAR(768) NOT NULL,
	public_key_cose LONGTEXT NOT NULL,
	alg INT NOT NULL DEFAULT 0,
	sign_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	aaguid VARCHAR(64) NOT NULL DEFAULT '',
	transports TEXT NULL,
	label VARCHAR(190) NOT NULL DEFAULT '',
	created_at DATETIME NOT NULL,
	last_used_at DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY credential_id (credential_id(191)),
	KEY user_id (user_id)
) $cs;

CREATE TABLE " . pk_t( 'factors' ) . " (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT(20) UNSIGNED NOT NULL,
	factor_type VARCHAR(24) NOT NULL,
	status VARCHAR(24) NOT NULL DEFAULT 'pending',
	factor_hash VARCHAR(96) NOT NULL DEFAULT '',
	factor_value LONGTEXT NULL,
	meta LONGTEXT NULL,
	verified_at DATETIME NULL,
	created_at DATETIME NOT NULL,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY user_factor_hash (user_id,factor_type,factor_hash),
	KEY user_factor (user_id,factor_type,status)
) $cs;

CREATE TABLE " . pk_t( 'trusted_devices' ) . " (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT(20) UNSIGNED NOT NULL,
	token_hash VARCHAR(96) NOT NULL,
	ua_hash VARCHAR(96) NOT NULL DEFAULT '',
	role_scope VARCHAR(80) NOT NULL DEFAULT '',
	created_at DATETIME NOT NULL,
	expires_at DATETIME NOT NULL,
	last_seen_at DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY token_hash (token_hash),
	KEY user_id (user_id),
	KEY expires_at (expires_at)
) $cs;

CREATE TABLE " . pk_t( 'visits' ) . " (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	visitor_hash VARCHAR(96) NOT NULL,
	anchor_hash VARCHAR(96) NOT NULL DEFAULT '',
	user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	visit_count INT UNSIGNED NOT NULL DEFAULT 0,
	first_seen_at DATETIME NOT NULL,
	last_seen_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY visitor_anchor (visitor_hash,anchor_hash),
	KEY user_id (user_id)
) $cs;

CREATE TABLE " . pk_t( 'activity' ) . " (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	event VARCHAR(60) NOT NULL,
	severity VARCHAR(16) NOT NULL DEFAULT 'info',
	anchor_hash VARCHAR(96) NOT NULL DEFAULT '',
	anchor_type VARCHAR(16) NOT NULL DEFAULT '',
	ip_hash VARCHAR(96) NOT NULL DEFAULT '',
	meta LONGTEXT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	KEY user_id (user_id),
	KEY event (event),
	KEY created_at (created_at)
) $cs;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

add_action( 'init', function () {
	if ( get_option( 'pk_db_ver' ) !== PK_VER ) {
		pk_create_tables();
		update_option( 'pk_db_ver', PK_VER, false );
	}
	pk_apply_policy_defaults_once();
	pk_apply_subscriber_signup_default_once();
	pk_apply_editorial_access_policy_once();
	pk_apply_session_timeout_default_off_once();
	pk_ensure_visitor_cookie();
	pk_enforce_session_timeout();
}, 1 );

add_action( 'wp_login', function ( $user_login, $user ) {
	if ( $user && ! empty( $user->ID ) ) {
		update_user_meta( (int) $user->ID, 'pk_session_started_at', time() );
	}
}, 10, 2 );

/* ============================================================
   Settings
   ============================================================ */

function pk_admin_cap() {
	return 'manage_options';
}

function pk_default_role() {
	return get_role( 'subscriber' ) ? 'subscriber' : get_option( 'default_role', 'subscriber' );
}

function pk_role_is_high_privilege_role( $role ) {
	$r = get_role( $role );
	if ( ! $r ) return in_array( $role, array( 'administrator', 'editor', 'author', 'contributor', 'shop_manager' ), true );
	return ! empty( $r->capabilities['manage_options'] )
		|| ! empty( $r->capabilities['manage_woocommerce'] )
		|| ! empty( $r->capabilities['edit_posts'] )
		|| ! empty( $r->capabilities['edit_others_posts'] )
		|| ! empty( $r->capabilities['edit_pages'] );
}

function pk_default_whatsapp_gateway_url() {
	return 'https://key.kiwelaunch.com/v1/otp';
}

function pk_default_settings() {
	$roles = wp_roles() ? array_keys( wp_roles()->roles ) : array( 'subscriber', 'administrator' );
	$matrix = array();
	foreach ( $roles as $role ) {
		$high = pk_role_is_high_privilege_role( $role );
		$matrix[ $role ] = array(
			'verification' => $high ? 'verify_now' : 'verify_later',
			'login_mode'   => 'passkey',
			'step_up'      => $high ? 'totp_optional' : 'none',
			'timeout'      => 0,
			'device_days'  => $high ? 7 : 90,
		);
	}
	return array(
		'popup_display'        => 'logged_out',
		'popup_dismissal'      => 'outside_close',
		'mandatory_scope'      => 'all_public',
		'mandatory_patterns'   => '',
		'identifier_mode'      => 'email_or_phone',
		'app_identifier_mode'  => 'email_or_phone',
		'app_verification_timing' => 'verify_now',
		'default_country_code' => '+91',
		'signup_role'          => pk_default_role(),
		'low_friction_signup'  => 1,
		'purchase_verified_contact' => 1,
		'verification_timing'  => 'verify_later',
		'progressive_visits'   => 2,
		'protect_wp_admin'     => 0,
		'replace_wp_login'     => 0,
		'email_delivery'       => 'magic_link',
		'phone_delivery'       => 'otp',
		'sms_provider'         => '',
		'sms_webhook'          => '',
		'whatsapp_provider'    => '',
		'whatsapp_webhook'     => pk_default_whatsapp_gateway_url(),
		'whatsapp_mode'        => 'phonekey_gateway',
		'whatsapp_key_id'      => '',
		'whatsapp_secret'      => '',
		'whatsapp_email_fallback' => 1,
		'return_message'       => 'Hey {name}, welcome back. You have not verified your account yet.',
		'return_seconds'       => 8,
		'return_action'        => 'prompt',
		'trusted_devices'      => 1,
		'ip_trust_threshold'   => 5,
		'ip_trust_ttl'         => 45,
		'ip_trust_roles'       => array( 'subscriber', 'customer', 'administrator', 'editor', 'shop_manager' ),
		'admin_reauth_minutes' => 0,
		'otp_target_limit'     => 5,
		'otp_target_window_min' => 60,
		'role_matrix'          => $matrix,
	);
}

function pk_settings() {
	$stored = get_option( 'pk_settings_v3', array() );
	if ( ! is_array( $stored ) ) $stored = array();
	$settings = array_replace_recursive( pk_default_settings(), $stored );
	if ( 'https://phonekey.kiwelaunch.com/v1/otp' === ( $settings['whatsapp_webhook'] ?? '' ) ) {
		$settings['whatsapp_webhook'] = pk_default_whatsapp_gateway_url();
	}
	if ( 'phonekey_gateway' === ( $settings['whatsapp_mode'] ?? 'phonekey_gateway' ) && empty( $settings['whatsapp_webhook'] ) ) {
		$settings['whatsapp_webhook'] = pk_default_whatsapp_gateway_url();
	}
	return $settings;
}

add_filter( 'dsa_phonekey_purchase_verified_contact_enabled', function ( $enabled ) {
	$settings = pk_settings();
	return ! array_key_exists( 'purchase_verified_contact', $settings ) || ! empty( $settings['purchase_verified_contact'] );
} );

add_filter( 'dsa_phonekey_account_identity_verified', function ( $verified, $user_id ) {
	return pk_account_verified( absint( $user_id ) );
}, 10, 2 );

function pk_admin_phone_verified( $user_id ) {
	return pk_factor_verified( $user_id, 'phone' );
}

function pk_admin_phone_bound( $user_id ) {
	return (bool) pk_factor( (int) $user_id, 'phone' );
}

function pk_user_phone( $user_id ) {
	$factor = pk_factor( (int) $user_id, 'phone' );
	if ( ! $factor ) return '';
	$meta = pk_meta_decode( $factor['meta'] ?? '' );
	$phone = pk_normalize_phone( $meta['phone'] ?? '' );
	if ( ! $phone && ! empty( $factor['factor_value'] ) ) {
		$phone = pk_normalize_phone( pk_decrypt( (string) $factor['factor_value'] ) );
	}
	return $phone;
}

function pk_admin_enrollment_complete( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) return false;
	return pk_credential_count( $user_id ) > 0
		&& pk_admin_phone_bound( $user_id )
		&& pk_account_verified( $user_id )
		&& (bool) get_user_meta( $user_id, 'pk_admin_password_bound_at', true );
}

function pk_flag_admin_enrollment( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id || ! pk_is_privileged( $user_id ) ) return;
	if ( pk_admin_enrollment_complete( $user_id ) ) {
		delete_user_meta( $user_id, 'pk_admin_enrollment_required' );
		return;
	}
	update_user_meta( $user_id, 'pk_admin_enrollment_required', 1 );
}

function pk_admin_enrollment_required( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id || ! pk_is_privileged( $user_id ) ) return false;
	if ( pk_admin_enrollment_complete( $user_id ) ) return false;
	return (bool) get_user_meta( $user_id, 'pk_admin_enrollment_required', true ) || pk_is_privileged( $user_id );
}

function pk_flag_all_admins_once() {
	if ( get_option( 'pk_admin_enrollment_flagged' ) === 'done' ) return;
	$admins = get_users( array(
		'capability__in' => array( 'manage_options' ),
		'fields'         => 'ID',
		'number'         => 500,
	) );
	if ( empty( $admins ) ) {
		$admins = get_users( array( 'role__in' => array( 'administrator' ), 'fields' => 'ID', 'number' => 500 ) );
	}
	foreach ( (array) $admins as $admin_id ) {
		pk_flag_admin_enrollment( (int) $admin_id );
	}
	update_option( 'pk_admin_enrollment_flagged', 'done', false );
}
add_action( 'init', 'pk_flag_all_admins_once', 2 );

function pk_flag_all_privileged_accounts_once() {
	if ( 'done' === get_option( 'pk_privileged_editorial_accounts_flagged_v1' ) ) return;
	$roles = array();
	foreach ( wp_roles()->roles as $role => $info ) {
		if ( pk_role_is_high_privilege_role( $role ) ) $roles[] = $role;
	}
	$user_ids = $roles ? get_users( array( 'role__in' => $roles, 'fields' => 'ID', 'number' => 1000 ) ) : array();
	foreach ( (array) $user_ids as $user_id ) {
		pk_flag_admin_enrollment( (int) $user_id );
	}
	update_option( 'pk_privileged_editorial_accounts_flagged_v1', 'done', false );
}
add_action( 'init', 'pk_flag_all_privileged_accounts_once', 3 );

function pk_revoke_role_assurance( $user_id, $event, $role ) {
	global $wpdb;
	$user_id = absint( $user_id );
	if ( ! $user_id ) return;
	delete_user_meta( $user_id, 'pk_admin_password_bound_at' );
	delete_user_meta( $user_id, 'pk_last_high_assurance_login' );
	delete_user_meta( $user_id, 'pk_trusted_ip_hashes' );
	$wpdb->delete( pk_t( 'trusted_devices' ), array( 'user_id' => $user_id ) );
	if ( pk_is_privileged( $user_id ) ) {
		update_user_meta( $user_id, 'pk_admin_enrollment_required', 1 );
		update_user_meta( $user_id, 'pk_force_reenroll', 1 );
	} else {
		delete_user_meta( $user_id, 'pk_admin_enrollment_required' );
		delete_user_meta( $user_id, 'pk_force_reenroll' );
	}
	if ( class_exists( 'WP_Session_Tokens' ) ) {
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
	}
	pk_log( $event, $user_id, array( 'role' => sanitize_key( $role ) ), 'warning' );
}

add_action( 'set_user_role', function ( $user_id, $role, $old_roles ) {
	$was_privileged = false;
	foreach ( (array) $old_roles as $old_role ) {
		if ( pk_role_is_high_privilege_role( $old_role ) ) $was_privileged = true;
	}
	$is_privileged = pk_role_is_high_privilege_role( $role );
	$old_roles = array_values( array_unique( array_map( 'sanitize_key', (array) $old_roles ) ) );
	$role_changed = 1 !== count( $old_roles ) || ! in_array( sanitize_key( $role ), $old_roles, true );
	if ( $role_changed && ( $is_privileged || $was_privileged ) ) {
		$event = $is_privileged && ! $was_privileged
			? 'privilege_elevation_requires_stepup'
			: ( $was_privileged && ! $is_privileged
				? 'privilege_revocation_cleared_assurance'
				: 'privileged_role_scope_changed_requires_stepup' );
		pk_revoke_role_assurance( $user_id, $event, $role );
	}
	pk_flag_admin_enrollment( (int) $user_id );
}, 10, 3 );
add_action( 'add_user_role', function ( $user_id, $role ) {
	if ( pk_role_is_high_privilege_role( $role ) ) {
		pk_revoke_role_assurance( $user_id, 'privilege_elevation_requires_stepup', $role );
	}
	pk_flag_admin_enrollment( (int) $user_id );
}, 10, 2 );
add_action( 'remove_user_role', function ( $user_id, $role ) {
	if ( ! pk_role_is_high_privilege_role( $role ) ) return;
	$user = get_userdata( (int) $user_id );
	foreach ( (array) ( $user ? $user->roles : array() ) as $remaining_role ) {
		if ( pk_role_is_high_privilege_role( $remaining_role ) ) {
			pk_revoke_role_assurance( $user_id, 'privileged_role_scope_changed_requires_stepup', $remaining_role );
			pk_flag_admin_enrollment( (int) $user_id );
			return;
		}
	}
	pk_revoke_role_assurance( $user_id, 'privilege_revocation_cleared_assurance', $role );
	pk_flag_admin_enrollment( (int) $user_id );
}, 10, 2 );
add_action( 'user_register', function ( $user_id ) { pk_flag_admin_enrollment( (int) $user_id ); }, 20, 1 );

add_filter( 'manage_users_columns', function ( $columns ) {
	$columns['phonekey_phone'] = __( 'Phone', 'phonekey' );
	$columns['phonekey_security'] = __( 'Key.kiwe', 'phonekey' );
	return $columns;
} );

add_filter( 'manage_users_custom_column', function ( $value, $column, $user_id ) {
	if ( 'phonekey_phone' === $column ) {
		$phone = pk_user_phone( (int) $user_id );
		return $phone ? esc_html( $phone ) : '&mdash;';
	}
	if ( 'phonekey_security' === $column ) {
		$security = pk_security_completion( (int) $user_id );
		$state = sanitize_key( $security['state'] ?? 'unverified' );
		$labels = array( 'verified' => __( 'Verified', 'phonekey' ), 'partial' => __( 'Partial', 'phonekey' ), 'unverified' => __( 'Unverified', 'phonekey' ) );
		return esc_html( $labels[ $state ] ?? $labels['unverified'] );
	}
	return $value;
}, 10, 3 );

add_action( 'admin_notices', function () {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! pk_is_privileged( $user_id ) ) return;
	if ( pk_admin_enrollment_complete( $user_id ) ) return;

	$needs_passkey = pk_credential_count( $user_id ) < 1;
	$needs_phone   = ! pk_admin_phone_bound( $user_id );
	$needs_verified_factor = ! pk_account_verified( $user_id );
	$needs_password_binding = ! get_user_meta( $user_id, 'pk_admin_password_bound_at', true );
	$parts = array();
	if ( $needs_password_binding ) $parts[] = esc_html__( 'confirm the WordPress password', 'phonekey' );
	if ( $needs_passkey ) $parts[] = esc_html__( 'set up a passkey', 'phonekey' );
	if ( $needs_phone ) $parts[] = esc_html__( 'bind a phone number', 'phonekey' );
	if ( $needs_verified_factor ) $parts[] = esc_html__( 'verify an email address or phone number', 'phonekey' );
	if ( empty( $parts ) ) return;

	echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Kiwe Auth:', 'phonekey' ) . '</strong> '
		. esc_html( sprintf(
			__( 'Your admin account still needs to %s. Complete secure setup from the Kiwe sign-in to protect this account on new devices.', 'phonekey' ),
			implode( esc_html__( ' and ', 'phonekey' ), $parts )
		) )
		. '</p></div>';
} );

function pk_apply_policy_defaults_once() {
	if ( get_option( 'pk_stage3_policy_defaults' ) === 'high_privilege_passkey_first' ) return;
	$settings = pk_settings();
	foreach ( wp_roles()->roles as $role => $info ) {
		if ( empty( $settings['role_matrix'][ $role ] ) ) $settings['role_matrix'][ $role ] = array();
		if ( pk_role_is_high_privilege_role( $role ) ) {
			$settings['role_matrix'][ $role ]['verification'] = 'verify_now';
			$settings['role_matrix'][ $role ]['login_mode'] = 'passkey';
			if ( empty( $settings['role_matrix'][ $role ]['step_up'] ) || 'totp_required' === $settings['role_matrix'][ $role ]['step_up'] ) {
				$settings['role_matrix'][ $role ]['step_up'] = 'totp_optional';
			}
			if ( ! array_key_exists( 'timeout', $settings['role_matrix'][ $role ] ) ) $settings['role_matrix'][ $role ]['timeout'] = 0;
			if ( empty( $settings['role_matrix'][ $role ]['device_days'] ) ) $settings['role_matrix'][ $role ]['device_days'] = 7;
		} elseif ( empty( $settings['role_matrix'][ $role ]['step_up'] ) || 'totp_optional' === $settings['role_matrix'][ $role ]['step_up'] ) {
			$settings['role_matrix'][ $role ]['login_mode'] = 'passkey';
			$settings['role_matrix'][ $role ]['step_up'] = 'none';
		}
	}
	update_option( 'pk_settings_v3', $settings, false );
	update_option( 'pk_stage3_policy_defaults', 'high_privilege_passkey_first', false );
}

function pk_apply_session_timeout_default_off_once() {
	if ( get_option( 'pk_session_timeout_default_off_v1' ) === 'done' ) return;
	$settings = pk_settings();
	$changed = false;

	if ( isset( $settings['admin_reauth_minutes'] ) && 30 === absint( $settings['admin_reauth_minutes'] ) ) {
		$settings['admin_reauth_minutes'] = 0;
		$changed = true;
	}

	foreach ( wp_roles()->roles as $role => $info ) {
		if ( ! pk_role_is_high_privilege_role( $role ) ) continue;
		if ( isset( $settings['role_matrix'][ $role ]['timeout'] ) && 30 === absint( $settings['role_matrix'][ $role ]['timeout'] ) ) {
			$settings['role_matrix'][ $role ]['timeout'] = 0;
			$changed = true;
		}
	}

	if ( $changed ) {
		update_option( 'pk_settings_v3', $settings, false );
	}
	update_option( 'pk_session_timeout_default_off_v1', 'done', false );
}

function pk_apply_subscriber_signup_default_once() {
	if ( 'done' === get_option( 'pk_subscriber_signup_default_v1' ) ) return;
	$settings = pk_settings();
	if ( empty( $settings['signup_role'] ) || 'customer' === $settings['signup_role'] ) {
		$settings['signup_role'] = pk_default_role();
		update_option( 'pk_settings_v3', $settings, false );
	}
	update_option( 'pk_subscriber_signup_default_v1', 'done', false );
}

function pk_apply_editorial_access_policy_once() {
	if ( 'done' === get_option( 'pk_editorial_access_policy_v1' ) ) return;
	$settings = pk_settings();
	foreach ( wp_roles()->roles as $role => $info ) {
		if ( ! pk_role_is_high_privilege_role( $role ) ) continue;
		$settings['role_matrix'][ $role ] = array_replace(
			(array) ( $settings['role_matrix'][ $role ] ?? array() ),
			array(
				'verification' => 'verify_now',
				'login_mode'   => 'passkey',
				'step_up'      => 'totp_optional',
				'timeout'      => 0,
				'device_days'  => 7,
			)
		);
	}
	update_option( 'pk_settings_v3', $settings, false );
	update_option( 'pk_editorial_access_policy_v1', 'done', false );
}

function pk_save_settings_array( $raw ) {
	$defaults = pk_default_settings();
	$current  = pk_settings();
	$out = array();
	$out['popup_display']        = in_array( $raw['popup_display'] ?? '', array( 'disabled', 'logged_out', 'new_visitors', 'every_visit' ), true ) ? $raw['popup_display'] : $defaults['popup_display'];
	$out['popup_dismissal']      = in_array( $raw['popup_dismissal'] ?? '', array( 'outside_close', 'close_only', 'mandatory' ), true ) ? $raw['popup_dismissal'] : $defaults['popup_dismissal'];
	$out['mandatory_scope']      = in_array( $raw['mandatory_scope'] ?? '', array( 'all_public', 'patterns' ), true ) ? $raw['mandatory_scope'] : $defaults['mandatory_scope'];
	$out['mandatory_patterns']   = sanitize_textarea_field( $raw['mandatory_patterns'] ?? '' );
	$out['identifier_mode']      = in_array( $raw['identifier_mode'] ?? '', array( 'email', 'phone', 'email_or_phone', 'email_and_phone' ), true ) ? $raw['identifier_mode'] : $defaults['identifier_mode'];
	$out['app_identifier_mode']  = in_array( $raw['app_identifier_mode'] ?? '', array( 'email', 'phone', 'email_or_phone', 'email_and_phone' ), true ) ? $raw['app_identifier_mode'] : $defaults['app_identifier_mode'];
	$out['app_verification_timing'] = in_array( $raw['app_verification_timing'] ?? '', array( 'verify_now', 'verify_later', 'progressive' ), true ) ? $raw['app_verification_timing'] : $defaults['app_verification_timing'];
	$out['default_country_code'] = preg_replace( '/[^0-9+]/', '', $raw['default_country_code'] ?? '+91' ) ?: '+91';
	$out['signup_role']          = sanitize_key( $raw['signup_role'] ?? pk_default_role() );
	if ( pk_role_is_high_privilege_role( $out['signup_role'] ) ) {
		$out['signup_role'] = pk_default_role();
	}
	$out['low_friction_signup']  = ! empty( $raw['low_friction_signup'] ) ? 1 : 0;
	$out['purchase_verified_contact'] = ! empty( $raw['purchase_verified_contact'] ) ? 1 : 0;
	$out['verification_timing']  = in_array( $raw['verification_timing'] ?? '', array( 'verify_now', 'verify_later', 'progressive' ), true ) ? $raw['verification_timing'] : $defaults['verification_timing'];
	$out['progressive_visits']   = max( 1, min( 20, absint( $raw['progressive_visits'] ?? 2 ) ) );
	$out['protect_wp_admin']     = ! empty( $raw['protect_wp_admin'] ) ? 1 : 0;
	$out['replace_wp_login']     = ! empty( $raw['replace_wp_login'] ) ? 1 : 0;
	$out['email_delivery']       = in_array( $raw['email_delivery'] ?? '', array( 'otp', 'magic_link' ), true ) ? $raw['email_delivery'] : 'magic_link';
	$out['phone_delivery']       = 'otp';
	$out['sms_provider']         = sanitize_text_field( $raw['sms_provider'] ?? '' );
	$out['sms_webhook']          = esc_url_raw( $raw['sms_webhook'] ?? '' );
	$out['whatsapp_provider']    = sanitize_text_field( $raw['whatsapp_provider'] ?? '' );
	$out['whatsapp_webhook']     = esc_url_raw( $raw['whatsapp_webhook'] ?? '' );
	$out['whatsapp_mode']        = in_array( $raw['whatsapp_mode'] ?? '', array( 'phonekey_gateway', 'generic_webhook' ), true ) ? $raw['whatsapp_mode'] : 'phonekey_gateway';
	$out['whatsapp_key_id']      = preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) ( $raw['whatsapp_key_id'] ?? '' ) );
	$out['whatsapp_email_fallback'] = ! empty( $raw['whatsapp_email_fallback'] ) ? 1 : 0;
	$secret_input = trim( (string) ( $raw['whatsapp_secret'] ?? '' ) );
	$out['whatsapp_secret'] = (string) ( $current['whatsapp_secret'] ?? '' );
	if ( '' !== $secret_input && class_exists( '\\DSA\\Security\\Secret_Store' ) ) {
		$encrypted = \DSA\Security\Secret_Store::encrypt( $secret_input );
		if ( '' !== $encrypted ) $out['whatsapp_secret'] = $encrypted;
	}
	$out['return_message']       = sanitize_text_field( $raw['return_message'] ?? $defaults['return_message'] );
	$out['return_seconds']       = max( 1, min( 60, absint( $raw['return_seconds'] ?? 8 ) ) );
	$out['return_action']        = in_array( $raw['return_action'] ?? '', array( 'none', 'prompt', 'progressive' ), true ) ? $raw['return_action'] : 'prompt';
	$out['trusted_devices']      = ! empty( $raw['trusted_devices'] ) ? 1 : 0;
	$out['ip_trust_threshold']   = max( 1, min( 50, absint( $raw['ip_trust_threshold'] ?? 5 ) ) );
	$out['ip_trust_ttl']         = max( 1, min( 365, absint( $raw['ip_trust_ttl'] ?? 45 ) ) );
	$out['admin_reauth_minutes'] = max( 0, min( 1440, absint( $raw['admin_reauth_minutes'] ?? 0 ) ) );
	$out['otp_target_limit']     = max( 1, min( 50, absint( $raw['otp_target_limit'] ?? 5 ) ) );
	$out['otp_target_window_min'] = max( 1, min( 1440, absint( $raw['otp_target_window_min'] ?? 60 ) ) );
	$out['ip_trust_roles']       = array_map( 'sanitize_key', (array) ( $raw['ip_trust_roles'] ?? ( $current['ip_trust_roles'] ?? $defaults['ip_trust_roles'] ) ) );

	$out['role_matrix'] = array();
	$roles = wp_roles() ? wp_roles()->roles : array();
	foreach ( $roles as $role => $info ) {
		$r = (array) ( $raw['role_matrix'][ $role ] ?? array() );
		$out['role_matrix'][ $role ] = array(
			'verification' => in_array( $r['verification'] ?? '', array( 'verify_now', 'verify_later', 'progressive' ), true ) ? $r['verification'] : $out['verification_timing'],
			'login_mode'   => in_array( $r['login_mode'] ?? '', array( 'none', 'passkey' ), true ) ? $r['login_mode'] : 'passkey',
			'step_up'      => in_array( $r['step_up'] ?? '', array( 'none', 'totp_optional', 'totp_required' ), true ) ? $r['step_up'] : 'none',
			'timeout'      => max( 0, min( 1440, absint( $r['timeout'] ?? 0 ) ) ),
			'device_days'  => max( 1, min( 365, absint( $r['device_days'] ?? 30 ) ) ),
		);
	}

	update_option( 'pk_settings_v3', $out, false );
	return $out;
}

/* ============================================================
   Crypto, encoding, and helpers
   ============================================================ */

function pk_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

function pk_secret() {
	return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . home_url(), true );
}

function pk_starts_with( $haystack, $needle ) {
	return 0 === strpos( (string) $haystack, (string) $needle );
}

function pk_hmac( $value ) {
	return hash_hmac( 'sha256', (string) $value, pk_secret() );
}

function pk_b64u( $raw ) {
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

function pk_b64u_dec( $s ) {
	$s = strtr( (string) $s, '-_', '+/' );
	$pad = strlen( $s ) % 4;
	if ( $pad ) $s .= str_repeat( '=', 4 - $pad );
	$d = base64_decode( $s, true );
	return false === $d ? '' : $d;
}

function pk_rand_token( $bytes = 32 ) {
	try {
		return pk_b64u( random_bytes( $bytes ) );
	} catch ( Throwable $e ) {
		return pk_b64u( wp_generate_password( $bytes, true, true ) );
	}
}

function pk_ip() {
	if ( class_exists( '\\DSA\\Secure\\SecureTrack_Ip_Service' ) ) return (string) \DSA\Secure\SecureTrack_Ip_Service::get_ip();
	if ( function_exists( 'stp_get_ip' ) ) return (string) stp_get_ip();
	return pk_resolve_client_ip();
}

function pk_request_uri() {
	$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
	return '/' . ltrim( $uri, '/' );
}

function pk_request_url() {
	return home_url( pk_request_uri() );
}

function pk_secure_auth_cookie() {
	if ( is_ssl() ) return true;
	$proto = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) );
	if ( 'https' === $proto ) return true;
	$cf_visitor = (string) wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ?? '' );
	if ( false !== stripos( $cf_visitor, '"scheme":"https"' ) ) return true;
	return false;
}

function pk_die( $message, $status = 403 ) {
	wp_die( $message, '', array( 'response' => (int) $status ) );
}

function pk_resolve_client_ip() {
	$remote = pk_normalize_ip( $_SERVER['REMOTE_ADDR'] ?? '' );

	if ( $remote && pk_ip_in_cidrs( $remote, pk_cloudflare_cidrs() ) ) {
		$cf = pk_normalize_ip( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
		if ( $cf ) return $cf;
	}

	$trusted_proxies = (array) apply_filters( 'pk_trusted_proxy_cidrs', pk_cloudflare_cidrs() );
	if ( $remote && pk_ip_in_cidrs( $remote, $trusted_proxies ) ) {
		$xff = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) );
		if ( '' !== $xff ) {
			$parts = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
			foreach ( $parts as $part ) {
				$ip = pk_normalize_ip( $part );
				if ( $ip && ! pk_ip_in_cidrs( $ip, $trusted_proxies ) && ! pk_ip_in_cidrs( $ip, pk_cloudflare_cidrs() ) ) return $ip;
			}
		}

		$real = pk_normalize_ip( $_SERVER['HTTP_X_REAL_IP'] ?? '' );
		if ( $real ) return $real;
	}

	return $remote ?: '0.0.0.0';
}

function pk_normalize_ip( $ip ) {
	$ip = trim( sanitize_text_field( wp_unslash( (string) $ip ) ) );
	if ( strpos( $ip, ':' ) !== false && preg_match( '/^\[?([0-9a-fA-F:.]+)\]?(?::\d+)?$/', $ip, $m ) ) $ip = $m[1];
	if ( strpos( $ip, ':' ) === false && preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $ip, $m ) ) $ip = $m[1];
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) return '';
	$packed = @inet_pton( $ip );
	if ( false === $packed ) return $ip;
	$normalized = @inet_ntop( $packed );
	return $normalized ?: $ip;
}

function pk_ip_in_cidrs( $ip, $cidrs ) {
	$ip = pk_normalize_ip( $ip );
	if ( ! $ip ) return false;
	$ip_bin = @inet_pton( $ip );
	if ( false === $ip_bin ) return false;

	foreach ( (array) $cidrs as $cidr ) {
		$cidr = trim( (string) $cidr );
		if ( '' === $cidr ) continue;
		if ( false === strpos( $cidr, '/' ) ) $cidr .= filter_var( $cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '/128' : '/32';
		list( $net, $bits ) = explode( '/', $cidr, 2 );
		$net_bin = @inet_pton( pk_normalize_ip( $net ) );
		if ( false === $net_bin || strlen( $net_bin ) !== strlen( $ip_bin ) ) continue;
		$bits = max( 0, min( strlen( $ip_bin ) * 8, (int) $bits ) );
		$bytes = intdiv( $bits, 8 );
		$rem = $bits % 8;
		if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) continue;
		if ( $rem ) {
			$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;
			if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $net_bin[ $bytes ] ) & $mask ) ) continue;
		}
		return true;
	}

	return false;
}

function pk_cloudflare_cidrs() {
	return array(
		'173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22','141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20','197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13','104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
		'2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32','2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
	);
}

function pk_ua() {
	return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 );
}

function pk_encrypt( $plain ) {
	if ( '' === (string) $plain ) return '';
	if ( class_exists( '\\DSA\\Security\\Secret_Store' ) ) {
		return \DSA\Security\Secret_Store::encrypt( (string) $plain );
	}
	return '';
}

function pk_decrypt( $stored ) {
	$stored = (string) $stored;
	if ( class_exists( '\\DSA\\Security\\Secret_Store' ) && \DSA\Security\Secret_Store::is_encrypted( $stored ) ) {
		return \DSA\Security\Secret_Store::decrypt( $stored );
	}
	if ( pk_starts_with( $stored, 'gcm:' ) && function_exists( 'openssl_decrypt' ) ) {
		$raw = pk_b64u_dec( substr( $stored, 4 ) );
		if ( strlen( $raw ) < 28 ) return '';
		$iv = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$p = openssl_decrypt( $cipher, 'aes-256-gcm', pk_secret(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $p ? '' : $p;
	}
	if ( pk_starts_with( $stored, 'b64:' ) ) return pk_b64u_dec( substr( $stored, 4 ) );
	return '';
}

function pk_crypto_diagnostics() {
	global $wpdb;
	$store = class_exists( '\\DSA\\Security\\Secret_Store' ) ? \DSA\Security\Secret_Store::diagnostics() : array( 'ready' => false, 'version' => 0, 'keyId' => '' );
	$legacy = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . pk_t( 'factors' ) . " WHERE factor_value LIKE 'b64:%' OR factor_value LIKE 'gcm:%'" );
	return array(
		'ready'         => ! empty( $store['ready'] ),
		'version'       => (int) ( $store['version'] ?? 0 ),
		'key_id'        => sanitize_text_field( (string) ( $store['keyId'] ?? '' ) ),
		'legacy_factors'=> $legacy,
		'hmac_key_id'   => substr( hash( 'sha256', pk_secret() ), 0, 12 ),
		'hmac_key_mismatch' => (bool) get_option( 'pk_crypto_key_mismatch', false ),
	);
}

add_action( 'init', function () {
	$current = substr( hash( 'sha256', pk_secret() ), 0, 12 );
	$stored  = sanitize_text_field( (string) get_option( 'pk_crypto_key_id', '' ) );
	if ( '' === $stored ) {
		update_option( 'pk_crypto_key_id', $current, false );
		return;
	}
	if ( ! hash_equals( $stored, $current ) ) {
		update_option( 'pk_crypto_key_mismatch', array( 'expected' => $stored, 'current' => $current, 'detected_at' => time() ), false );
	}
}, 2 );

function pk_json( $data ) {
	return wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
}

function pk_meta_decode( $json ) {
	$d = json_decode( (string) $json, true );
	return is_array( $d ) ? $d : array();
}

function pk_log( $event, $user_id = 0, $meta = array(), $severity = 'info' ) {
	global $wpdb;
	$anchor = isset( $meta['anchor'] ) ? (string) $meta['anchor'] : '';
	$anchor_type = isset( $meta['anchor_type'] ) ? sanitize_key( $meta['anchor_type'] ) : '';
	$anchor_hash = $anchor ? pk_hmac( strtolower( $anchor ) ) : '';
	$wpdb->insert( pk_t( 'activity' ), array(
		'user_id'     => absint( $user_id ),
		'event'       => sanitize_key( $event ),
		'severity'    => sanitize_key( $severity ),
		'anchor_hash' => $anchor_hash,
		'anchor_type' => $anchor_type,
		'ip_hash'     => pk_hmac( pk_ip() ),
		'meta'        => pk_json( $meta ),
		'created_at'  => pk_now(),
	) );
	if ( function_exists( 'stp_log' ) ) {
		$stp_type = in_array( $event, array( 'passkey_failure', 'totp_failure', 'otp_failure', 'recovery_blocked' ), true ) ? 'pk_auth_fail' : 'pk_auth';
		stp_log( $stp_type, array(
			'sub'   => sanitize_key( $event ),
			'url'   => pk_request_url(),
			'extra' => array_merge( array( 'phonekey_stage' => 3, 'severity' => $severity ), $meta ),
		) );
	}
}

function pk_rate_limit( $key, $limit = 30, $window = 300 ) {
	global $wpdb;

	$bucket = 'pk_rl_' . md5( $key . '|' . pk_ip() . '|' . floor( time() / max( 1, (int) $window ) ) );
	$option = '_dsa_' . $bucket;
	$limit = max( 1, (int) $limit );

	if ( add_option( $option, 1, '', false ) ) {
		return true;
	}

	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", $option ) );
	$count = (int) get_option( $option, 1 );

	if ( wp_rand( 1, 200 ) === 1 ) {
		$prefix = $wpdb->esc_like( '_dsa_pk_rl_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id < %d", $prefix, max( 0, (int) $wpdb->get_var( "SELECT MAX(option_id) FROM {$wpdb->options}" ) - 10000 ) ) );
	}

	return $count <= $limit;
}

function pk_normalize_phone( $raw ) {
	$d = preg_replace( '/[^0-9+]/', '', (string) $raw );
	if ( $d && '+' !== $d[0] ) {
		$d = pk_settings()['default_country_code'] . ltrim( $d, '0' );
	}
	return $d;
}

function pk_normalize_email( $raw ) {
	return strtolower( sanitize_email( trim( (string) $raw ) ) );
}

function pk_detect_identifier( $identifier ) {
	$identifier = trim( (string) $identifier );
	if ( is_email( $identifier ) ) return array( 'type' => 'email', 'value' => pk_normalize_email( $identifier ) );
	$phone = pk_normalize_phone( $identifier );
	$digits = preg_replace( '/[^0-9]/', '', $phone );
	if ( strlen( $digits ) >= 7 ) return array( 'type' => 'phone', 'value' => $phone );
	return array( 'type' => '', 'value' => '' );
}

function pk_identifier_allowed( $type, $app_context = false ) {
	$settings = pk_settings();
	$mode = $app_context ? ( $settings['app_identifier_mode'] ?? $settings['identifier_mode'] ) : $settings['identifier_mode'];
	return ( 'email_or_phone' === $mode ) || ( 'email_and_phone' === $mode && 'email' === $type ) || ( 'email' === $mode && 'email' === $type ) || ( 'phone' === $mode && 'phone' === $type );
}

function pk_canonical_user_id( $user_id ) {
	$current = absint( $user_id );
	$seen = array();
	for ( $depth = 0; $current && $depth < 5; $depth++ ) {
		if ( isset( $seen[ $current ] ) ) return 0;
		$seen[ $current ] = true;
		$next = absint( get_user_meta( $current, 'pk_merged_into', true ) );
		if ( ! $next ) return $current;
		$current = $next;
	}
	return $current;
}

function pk_find_user_raw( $identifier, $type ) {
	if ( 'email' === $type ) {
		$u = get_user_by( 'email', pk_normalize_email( $identifier ) );
		if ( $u ) return (int) $u->ID;
	}
	$key = 'phone' === $type ? 'pk_phone_hash' : 'pk_email_hash';
	$users = get_users( array(
		'meta_key'   => $key,
		'meta_value' => pk_hmac( 'phone' === $type ? pk_normalize_phone( $identifier ) : pk_normalize_email( $identifier ) ),
		'number'     => 1,
		'fields'     => 'ID',
	) );
	return $users ? (int) $users[0] : 0;
}

/**
 * Resolve the WordPress user that owns a pending core profile-email change.
 *
 * WordPress keeps the requested address in the owner's `_new_email` usermeta
 * until its confirmation link is consumed. Key.kiwe must see that reservation
 * before it considers creating a new account for the same address.
 */
function pk_pending_wordpress_email_owner( $email ) {
	global $wpdb;

	$email = pk_normalize_email( $email );
	if ( ! is_email( $email ) ) return 0;

	$candidate_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value LIKE %s ORDER BY umeta_id DESC LIMIT 10",
		'_new_email',
		'%' . $wpdb->esc_like( $email ) . '%'
	) );

	foreach ( array_unique( array_map( 'absint', (array) $candidate_ids ) ) as $candidate_id ) {
		$pending = get_user_meta( $candidate_id, '_new_email', true );
		$pending_email = is_array( $pending ) ? pk_normalize_email( $pending['newemail'] ?? '' ) : '';
		if ( $pending_email && hash_equals( $email, $pending_email ) ) {
			return pk_canonical_user_id( $candidate_id );
		}
	}

	return 0;
}

function pk_find_user( $identifier, $type ) {
	$user_id = pk_find_user_raw( $identifier, $type );
	return $user_id ? pk_canonical_user_id( $user_id ) : 0;
}

function pk_user_roles( $user_id ) {
	$u = get_userdata( $user_id );
	return $u ? (array) $u->roles : array();
}

function pk_primary_role( $user_id ) {
	$roles = pk_user_roles( $user_id );
	return $roles ? reset( $roles ) : pk_default_role();
}

function pk_role_policy( $user_id ) {
	$s = pk_settings();
	$roles = pk_user_roles( $user_id );
	$fallback = array(
		'verification' => $s['verification_timing'],
		'login_mode'   => 'passkey',
		'step_up'      => 'none',
		'timeout'      => 0,
		'device_days'  => 30,
	);
	if ( empty( $roles ) ) return $fallback;

	$verification_rank = array( 'verify_later' => 1, 'progressive' => 2, 'verify_now' => 3 );
	$step_rank = array( 'none' => 1, 'totp_optional' => 2, 'totp_required' => 3 );
	$policy = $fallback;
	$positive_timeouts = array();
	$device_days = array();

	foreach ( $roles as $role ) {
		$candidate = (array) ( $s['role_matrix'][ $role ] ?? $fallback );
		if ( ( $verification_rank[ $candidate['verification'] ?? '' ] ?? 0 ) > ( $verification_rank[ $policy['verification'] ] ?? 0 ) ) $policy['verification'] = $candidate['verification'];
		if ( 'passkey' === ( $candidate['login_mode'] ?? '' ) ) $policy['login_mode'] = 'passkey';
		if ( ( $step_rank[ $candidate['step_up'] ?? '' ] ?? 0 ) > ( $step_rank[ $policy['step_up'] ] ?? 0 ) ) $policy['step_up'] = $candidate['step_up'];
		if ( absint( $candidate['timeout'] ?? 0 ) > 0 ) $positive_timeouts[] = absint( $candidate['timeout'] );
		$device_days[] = max( 1, absint( $candidate['device_days'] ?? 30 ) );
	}

	$policy['timeout'] = $positive_timeouts ? min( $positive_timeouts ) : 0;
	$policy['device_days'] = $device_days ? min( $device_days ) : 30;
	return $policy;
}

function pk_is_privileged( $user_id ) {
	$u = get_userdata( $user_id );
	if ( ! $u ) return false;
	foreach ( (array) $u->roles as $role ) {
		if ( pk_role_is_high_privilege_role( $role ) ) return true;
	}
	return user_can( $u, 'manage_options' ) || user_can( $u, 'manage_woocommerce' ) || user_can( $u, 'edit_posts' ) || user_can( $u, 'edit_others_posts' ) || user_can( $u, 'edit_pages' );
}

function pk_phone_provider_ready() {
	$s = pk_settings();
	if ( ! empty( $s['sms_webhook'] ) ) return true;
	if ( empty( $s['whatsapp_webhook'] ) ) return false;
	if ( 'phonekey_gateway' !== ( $s['whatsapp_mode'] ?? 'generic_webhook' ) ) return true;
	return ! empty( $s['whatsapp_key_id'] ) && '' !== pk_whatsapp_secret( $s );
}

function pk_whatsapp_secret( $settings = null ) {
	$settings = is_array( $settings ) ? $settings : pk_settings();
	$stored = (string) ( $settings['whatsapp_secret'] ?? '' );
	if ( '' === $stored || ! class_exists( '\\DSA\\Security\\Secret_Store' ) ) return '';
	return \DSA\Security\Secret_Store::decrypt( $stored );
}

function pk_create_user_for_identifier( $identifier, $type ) {
	$s = pk_settings();
	$role = get_role( $s['signup_role'] ) ? $s['signup_role'] : pk_default_role();
	if ( pk_role_is_high_privilege_role( $role ) ) {
		$role = pk_default_role();
	}
	$hash = substr( pk_hmac( $identifier ), 0, 18 );
	$email_parts = explode( '@', $identifier );
	$user_login = 'email' === $type ? sanitize_user( $email_parts[0] . '_' . substr( $hash, 0, 6 ), true ) : 'phone_' . substr( $hash, 0, 12 );
	if ( username_exists( $user_login ) ) $user_login .= '_' . wp_rand( 100, 999 );
	$user_id = wp_insert_user( array(
		'user_login'   => $user_login,
		'user_pass'    => wp_generate_password( 32, true, true ),
		'user_email'   => 'email' === $type ? $identifier : '',
		'display_name' => 'email' === $type ? $email_parts[0] : 'Phone user',
		'role'         => $role,
	) );
	if ( is_wp_error( $user_id ) ) return 0;
	update_user_meta( $user_id, 'pk_created_by_phonekey', current_time( 'mysql' ) );
	update_user_meta( $user_id, 'pk_anchor_type', $type );
	if ( 'email' === $type ) {
		update_user_meta( $user_id, 'pk_email_hash', pk_hmac( $identifier ) );
		pk_upsert_factor( $user_id, 'email', 'pending', pk_hmac( $identifier ), array(), $identifier );
	} else {
		update_user_meta( $user_id, 'pk_phone_hash', pk_hmac( $identifier ) );
		update_user_meta( $user_id, 'pk_phone_last4', substr( preg_replace( '/[^0-9]/', '', $identifier ), -4 ) );
		pk_upsert_factor( $user_id, 'phone', 'pending', pk_hmac( $identifier ), array( 'last4' => substr( preg_replace( '/[^0-9]/', '', $identifier ), -4 ) ), $identifier );
	}
	pk_log( 'account_created', $user_id, array( 'anchor_type' => $type ), 'info' );
	return (int) $user_id;
}

/* ============================================================
   Factors and verification
   ============================================================ */

function pk_factor( $user_id, $type, $hash = '' ) {
	global $wpdb;
	if ( $hash ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . pk_t( 'factors' ) . " WHERE user_id=%d AND factor_type=%s AND factor_hash=%s LIMIT 1", $user_id, $type, $hash ), ARRAY_A );
	}
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . pk_t( 'factors' ) . " WHERE user_id=%d AND factor_type=%s ORDER BY id DESC LIMIT 1", $user_id, $type ), ARRAY_A );
}

function pk_upsert_factor( $user_id, $type, $status, $hash = '', $meta = array(), $secret = '' ) {
	global $wpdb;
	$existing = pk_factor( $user_id, $type, $hash );
	$row = array(
		'user_id'      => absint( $user_id ),
		'factor_type'  => sanitize_key( $type ),
		'status'       => sanitize_key( $status ),
		'factor_hash'  => (string) $hash,
		'factor_value' => $secret ? pk_encrypt( $secret ) : ( $existing['factor_value'] ?? '' ),
		'meta'         => pk_json( $meta ),
		'verified_at'  => 'verified' === $status ? pk_now() : null,
		'updated_at'   => pk_now(),
	);
	if ( $existing ) {
		$wpdb->update( pk_t( 'factors' ), $row, array( 'id' => (int) $existing['id'] ) );
		return (int) $existing['id'];
	}
	$row['created_at'] = pk_now();
	$wpdb->insert( pk_t( 'factors' ), $row );
	return (int) $wpdb->insert_id;
}

function pk_factor_verified( $user_id, $type ) {
	$f = pk_factor( $user_id, $type );
	return $f && 'verified' === $f['status'];
}

function pk_account_verified( $user_id ) {
	return pk_factor_verified( $user_id, 'email' ) || pk_factor_verified( $user_id, 'phone' ) || (bool) get_user_meta( $user_id, 'pk_verified_at', true );
}

/**
 * Describe progressive Key.kiwe completion without weakening the identity proof
 * used by login policy. A verified email or phone proves the account holder;
 * the public "Verified" badge is reserved for complete recovery + passkey setup.
 */
function pk_security_completion( $user_id ) {
	$user_id = absint( $user_id );
	$email_verified = $user_id && pk_factor_verified( $user_id, 'email' );
	$phone_verified = $user_id && pk_factor_verified( $user_id, 'phone' );
	$passkey_enrolled = $user_id && pk_credential_count( $user_id ) > 0;
	$identity_verified = $email_verified || $phone_verified || ( $user_id && (bool) get_user_meta( $user_id, 'pk_verified_at', true ) );
	$settings = pk_settings();
	$identifier_mode = sanitize_key( $settings['identifier_mode'] ?? 'email_or_phone' );
	$requires_email = pk_is_privileged( $user_id ) || in_array( $identifier_mode, array( 'email', 'email_or_phone', 'email_and_phone' ), true );
	$requires_phone = pk_is_privileged( $user_id ) || in_array( $identifier_mode, array( 'phone', 'email_or_phone', 'email_and_phone' ), true );
	$complete = $passkey_enrolled
		&& ( ! $requires_email || $email_verified )
		&& ( ! $requires_phone || $phone_verified );

	return array(
		'state'              => $complete ? 'verified' : ( $identity_verified ? 'partial' : 'unverified' ),
		'complete'           => (bool) $complete,
		'identityVerified'   => (bool) $identity_verified,
		'emailVerified'      => (bool) $email_verified,
		'phoneVerified'      => (bool) $phone_verified,
		'passkeyEnrolled'    => (bool) $passkey_enrolled,
		'missingCounterpart' => pk_missing_counterpart_type( $user_id ),
	);
}

function pk_mark_factor_verified( $user_id, $type, $identifier = '' ) {
	$hash = $identifier ? pk_hmac( $identifier ) : '';
	$existing = pk_factor( $user_id, $type, $hash );
	$legacy_meta = $existing ? pk_meta_decode( $existing['meta'] ?? '' ) : array();

	if ( ! $identifier && isset( $legacy_meta[ $type ] ) ) {
		$identifier = sanitize_text_field( (string) $legacy_meta[ $type ] );
	}

	if ( ! $hash ) {
		$existing = $existing ?: pk_factor( $user_id, $type );
		$hash = $existing['factor_hash'] ?? '';
	}
	$meta = array( 'verified_by' => 'token' );
	if ( 'phone' === $type && $identifier ) {
		$meta['last4'] = substr( preg_replace( '/[^0-9]/', '', $identifier ), -4 );
	}
	pk_upsert_factor( $user_id, $type, 'verified', $hash, $meta, $identifier );
	update_user_meta( $user_id, 'pk_verified_at', current_time( 'mysql' ) );
	pk_log( $type . '_verified', $user_id, array( 'anchor_type' => $type ), 'success' );
	do_action( 'kiwe_identity_factor_verified', (int) $user_id );
}

function pk_issue_token( $purpose, $user_id = 0, $ttl = PK_CHALLENGE_TTL, $meta = array(), $challenge = '' ) {
	global $wpdb;
	$token = pk_rand_token( 32 );
	$wpdb->insert( pk_t( 'challenges' ), array(
		'purpose'     => sanitize_key( $purpose ),
		'token_hash'  => pk_hmac( $token ),
		'challenge'   => $challenge,
		'user_id'     => absint( $user_id ),
		'anchor_hash' => $meta['anchor_hash'] ?? '',
		'anchor_type' => $meta['anchor_type'] ?? '',
		'ip_hash'     => pk_hmac( pk_ip() ),
		'meta'        => pk_json( $meta ),
		'used'        => 0,
		'created_at'  => pk_now(),
		'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + absint( $ttl ) ),
	) );
	return $token;
}

function pk_token_row( $purpose, $token, $consume = false ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . pk_t( 'challenges' ) . " WHERE purpose=%s AND token_hash=%s AND used=0 AND expires_at>%s LIMIT 1", sanitize_key( $purpose ), pk_hmac( $token ), pk_now() ), ARRAY_A );
	if ( ! $row ) return null;
	if ( $consume ) {
		$wpdb->update( pk_t( 'challenges' ), array( 'used' => 1, 'used_at' => pk_now() ), array( 'id' => (int) $row['id'] ) );
	}
	$row['meta_arr'] = pk_meta_decode( $row['meta'] ?? '' );
	return $row;
}

function pk_otp_target_allowed( $identifier ) {
	global $wpdb;
	$identifier = strtolower( trim( (string) $identifier ) );
	if ( '' === $identifier ) return true;

	$s = pk_settings();
	$limit  = max( 1, min( 50, (int) ( $s['otp_target_limit'] ?? 5 ) ) );
	$window = max( 1, min( 1440, (int) ( $s['otp_target_window_min'] ?? 60 ) ) ) * MINUTE_IN_SECONDS;
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . pk_t( 'challenges' ) . " WHERE purpose IN ('email_otp','phone_otp') AND anchor_hash=%s AND created_at>=%s",
		pk_hmac( $identifier ),
		gmdate( 'Y-m-d H:i:s', time() - $window )
	) );
	return $count < $limit;
}

function pk_recent_otp_send_exists( $user_id, $purpose ) {
	return pk_otp_resend_after( $user_id, $purpose ) > 0;
}

function pk_otp_resend_after( $user_id, $purpose ) {
	global $wpdb;
	$created_at = $wpdb->get_var( $wpdb->prepare(
		"SELECT created_at FROM " . pk_t( 'challenges' ) . " WHERE purpose=%s AND user_id=%d AND used=0 AND expires_at>%s AND created_at>=%s ORDER BY id DESC LIMIT 1",
		sanitize_key( $purpose ),
		absint( $user_id ),
		pk_now(),
		gmdate( 'Y-m-d H:i:s', time() - 60 )
	) );
	if ( ! $created_at ) return 0;
	return max( 1, 60 - max( 0, time() - (int) strtotime( $created_at . ' UTC' ) ) );
}

function pk_send_email_otp_or_link( $user_id, $email, $flow_token = '', $force_method = '' ) {
	$s = pk_settings();
	$method = $force_method ?: $s['email_delivery'];
	if ( 'otp' === $method ) {
		$code = (string) wp_rand( 100000, 999999 );
		$token = pk_issue_token( 'email_otp', $user_id, PK_OTP_TTL, array(
			'otp_hash'    => pk_hmac( $code ),
			'flow_token'  => $flow_token,
			'anchor_hash' => pk_hmac( $email ),
			'anchor_type' => 'email',
		) );
		$accepted = wp_mail( $email, sprintf( '[%s] Your Kiwe Auth code', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), "Your verification code is: {$code}\n\nIt expires in 10 minutes." );
		update_option( 'pk_last_mail_status', array( 'accepted' => $accepted ? 1 : 0, 'method' => 'otp', 'at' => time() ), false );
		pk_log( $accepted ? 'email_otp_accepted' : 'email_otp_failed', $user_id, array( 'anchor_type' => 'email' ), $accepted ? 'info' : 'warning' );
		return array( 'method' => 'otp', 'token' => $token, 'accepted' => $accepted );
	}
	$token = pk_issue_token( 'email_magic', $user_id, 24 * HOUR_IN_SECONDS, array(
		'flow_token'  => $flow_token,
		'anchor_hash' => pk_hmac( $email ),
		'anchor_type' => 'email',
	) );
	$url = add_query_arg( array( 'pkv3' => 'magic', 'token' => rawurlencode( $token ) ), home_url( '/' ) );
	$accepted = wp_mail( $email, sprintf( '[%s] Verify your account', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), "Verify your account here:\n\n{$url}\n\nThis link expires in 24 hours." );
	update_option( 'pk_last_mail_status', array( 'accepted' => $accepted ? 1 : 0, 'method' => 'magic_link', 'at' => time() ), false );
	pk_log( $accepted ? 'email_magic_accepted' : 'email_magic_failed', $user_id, array( 'anchor_type' => 'email' ), $accepted ? 'info' : 'warning' );
	return array( 'method' => 'magic_link', 'token' => '', 'accepted' => $accepted );
}

function pk_begin_account_email_change( $user_id, $email ) {
	$user_id = absint( $user_id );
	$email   = strtolower( sanitize_email( $email ) );
	$user    = $user_id ? get_userdata( $user_id ) : null;

	if ( ! $user || ! is_email( $email ) ) {
		return new WP_Error( 'pk_invalid_email_change', 'Enter a valid email address.', array( 'status' => 400 ) );
	}
	if ( email_exists( $email ) && (int) email_exists( $email ) !== $user_id ) {
		return new WP_Error( 'pk_email_change_exists', 'That email address is already used by another account.', array( 'status' => 409 ) );
	}
	if ( ! pk_otp_target_allowed( $email ) || ! pk_rate_limit( 'account_email_change|' . $user_id, 4, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'pk_email_change_limited', 'Too many verification codes were requested. Try again later.', array( 'status' => 429 ) );
	}

	$code  = (string) wp_rand( 100000, 999999 );
	$token = pk_issue_token( 'account_email_change', $user_id, PK_OTP_TTL, array(
		'otp_hash'        => pk_hmac( $code ),
		'anchor_hash'     => pk_hmac( $email ),
		'anchor_type'     => 'email',
		'previous_hash'   => pk_hmac( strtolower( (string) $user->user_email ) ),
		'requested_email' => pk_encrypt( $email ),
	) );
	$accepted = wp_mail(
		$email,
		sprintf( '[%s] Confirm your new email address', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
		"Your verification code is: {$code}\n\nIt expires in 10 minutes. Your account email will not change until this code is accepted."
	);

	pk_log( $accepted ? 'account_email_change_accepted' : 'account_email_change_delivery_failed', $user_id, array( 'anchor_type' => 'email' ), $accepted ? 'info' : 'warning' );

	return array(
		'token'    => $token,
		'accepted' => (bool) $accepted,
		'expires'  => PK_OTP_TTL,
	);
}

function pk_complete_account_email_change( $user_id, $token, $code ) {
	global $wpdb;

	$user_id = absint( $user_id );
	$code    = preg_replace( '/[^0-9]/', '', (string) $code );
	$row     = pk_token_row( 'account_email_change', sanitize_text_field( $token ), false );

	if ( ! $row || (int) $row['user_id'] !== $user_id ) {
		return new WP_Error( 'pk_email_change_expired', 'This email verification session expired.', array( 'status' => 403 ) );
	}
	if ( 6 !== strlen( $code ) || ! pk_rate_limit( 'account_email_change_verify|' . $user_id, 8, 10 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'pk_email_change_attempts', 'Too many code attempts. Request a new code shortly.', array( 'status' => 429 ) );
	}

	$meta = $row['meta_arr'];
	if ( ! hash_equals( (string) ( $meta['otp_hash'] ?? '' ), pk_hmac( $code ) ) ) {
		pk_log( 'account_email_change_failure', $user_id, array( 'reason' => 'bad_code' ), 'warning' );
		return new WP_Error( 'pk_email_change_code', 'That code was not accepted.', array( 'status' => 403 ) );
	}

	$email = strtolower( sanitize_email( pk_decrypt( (string) ( $meta['requested_email'] ?? '' ) ) ) );
	if ( ! is_email( $email ) || ( email_exists( $email ) && (int) email_exists( $email ) !== $user_id ) ) {
		return new WP_Error( 'pk_email_change_conflict', 'That email address is no longer available.', array( 'status' => 409 ) );
	}
	$current = get_userdata( $user_id );
	if ( ! $current || ! hash_equals( (string) ( $meta['previous_hash'] ?? '' ), pk_hmac( strtolower( (string) $current->user_email ) ) ) ) {
		return new WP_Error( 'pk_email_change_stale', 'The account email changed after this code was requested. Start again.', array( 'status' => 409 ) );
	}

	$consumed = $wpdb->query( $wpdb->prepare(
		"UPDATE " . pk_t( 'challenges' ) . " SET used=1, used_at=%s WHERE id=%d AND used=0",
		pk_now(),
		(int) $row['id']
	) );
	if ( 1 !== $consumed ) {
		return new WP_Error( 'pk_email_change_consumed', 'This verification code was already used.', array( 'status' => 409 ) );
	}

	$updated = wp_update_user( array( 'ID' => $user_id, 'user_email' => $email ) );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	$new_hash = pk_hmac( $email );
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM " . pk_t( 'factors' ) . " WHERE user_id=%d AND factor_type='email' AND factor_hash<>%s",
		$user_id,
		$new_hash
	) );
	pk_mark_factor_verified( $user_id, 'email', $email );

	// Recovery-anchor changes revoke remembered trust, never the user's passkeys.
	$wpdb->delete( pk_t( 'trusted_devices' ), array( 'user_id' => $user_id ) );
	delete_user_meta( $user_id, 'pk_trusted_ip_hashes' );
	delete_user_meta( $user_id, 'pk_session_started_at' );
	if ( function_exists( 'wp_destroy_other_sessions' ) ) {
		wp_destroy_other_sessions();
	}
	$wpdb->query( $wpdb->prepare(
		"UPDATE " . pk_t( 'challenges' ) . " SET used=1, used_at=%s WHERE user_id=%d AND used=0 AND purpose IN ('account_email_change','email_otp','email_magic','recovery','stepup')",
		pk_now(),
		$user_id
	) );
	pk_log( 'account_email_changed', $user_id, array( 'anchor_type' => 'email', 'trust_revoked' => 1 ), 'success' );

	return array( 'email' => $email );
}

function pk_sync_wordpress_profile_email_change( $user_id, $old_user_data ) {
	global $wpdb;

	$user_id = absint( $user_id );
	$current = $user_id ? get_userdata( $user_id ) : null;
	$before  = $old_user_data instanceof WP_User ? pk_normalize_email( $old_user_data->user_email ) : '';
	$after   = $current ? pk_normalize_email( $current->user_email ) : '';
	if ( ! $current || ! is_email( $after ) || $before === $after ) return;

	$new_hash = pk_hmac( $after );
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM " . pk_t( 'factors' ) . " WHERE user_id=%d AND factor_type='email' AND factor_hash<>%s",
		$user_id,
		$new_hash
	) );
	pk_mark_factor_verified( $user_id, 'email', $after );
	$wpdb->delete( pk_t( 'trusted_devices' ), array( 'user_id' => $user_id ) );
	delete_user_meta( $user_id, 'pk_trusted_ip_hashes' );
	delete_user_meta( $user_id, 'pk_session_started_at' );
	$wpdb->query( $wpdb->prepare(
		"UPDATE " . pk_t( 'challenges' ) . " SET used=1, used_at=%s WHERE user_id=%d AND used=0 AND purpose IN ('account_email_change','email_otp','email_magic','recovery','stepup')",
		pk_now(),
		$user_id
	) );
	pk_log( 'wordpress_account_email_changed', $user_id, array( 'trust_revoked' => 1 ), 'success' );
}
add_action( 'profile_update', 'pk_sync_wordpress_profile_email_change', 20, 2 );

function pk_sync_wordpress_password_reset( $user ) {
	global $wpdb;
	if ( ! $user instanceof WP_User || ! $user->ID ) return;

	$user_id = (int) $user->ID;
	$wpdb->delete( pk_t( 'trusted_devices' ), array( 'user_id' => $user_id ) );
	delete_user_meta( $user_id, 'pk_trusted_ip_hashes' );
	delete_user_meta( $user_id, 'pk_session_started_at' );
	delete_user_meta( $user_id, 'pk_admin_password_bound_at' );
	if ( pk_is_privileged( $user_id ) ) update_user_meta( $user_id, 'pk_force_reenroll', 1 );
	$wpdb->query( $wpdb->prepare(
		"UPDATE " . pk_t( 'challenges' ) . " SET used=1, used_at=%s WHERE user_id=%d AND used=0",
		pk_now(),
		$user_id
	) );
	pk_log( 'wordpress_password_reset', $user_id, array( 'trust_revoked' => 1, 'strict_reenroll' => pk_is_privileged( $user_id ) ? 1 : 0 ), 'success' );
}
add_action( 'after_password_reset', 'pk_sync_wordpress_password_reset', 20, 1 );

function pk_send_phone_fallback_email( $user_id, $code ) {
	$user = get_userdata( $user_id );
	$email = $user && is_email( $user->user_email ) ? $user->user_email : '';
	if ( ! $email ) {
		$billing_email = sanitize_email( (string) get_user_meta( $user_id, 'billing_email', true ) );
		$email = is_email( $billing_email ) ? $billing_email : '';
	}
	if ( ! $email ) {
		pk_log( 'phone_otp_email_fallback_unavailable', $user_id, array( 'reason' => 'account_email_missing' ), 'warning' );
		return false;
	}
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$accepted = wp_mail(
		$email,
		sprintf( '[%s] Key.kiwe verification code', $site ),
		sprintf( "Your Key.kiwe verification code is %s. It expires in 10 minutes.\n\nWhatsApp delivery was unavailable, so Kiwe used the configured email fallback. Never share this code.", $code )
	);
	pk_log( $accepted ? 'phone_otp_email_fallback_accepted' : 'phone_otp_email_fallback_failed', $user_id, array( 'provider' => 'wp_mail' ), $accepted ? 'info' : 'warning' );
	return (bool) $accepted;
}

function pk_gateway_signed_headers( array $settings, $body ) {
	$secret = pk_whatsapp_secret( $settings );
	if ( '' === $secret || empty( $settings['whatsapp_key_id'] ) ) return array();
	$timestamp = (string) round( microtime( true ) * 1000 );
	$nonce = wp_generate_password( 32, false, false );
	return array(
		'Content-Type'             => 'application/json',
		'X-Kiwe-Key-Id'        => (string) $settings['whatsapp_key_id'],
		'X-Kiwe-Key-Timestamp' => $timestamp,
		'X-Kiwe-Key-Nonce'     => $nonce,
		'X-Kiwe-Key-Signature' => hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $secret ),
	);
}

function pk_report_gateway_event( array $settings, $gateway_url, $phone, $request_id, $event ) {
	if ( ! in_array( $event, array( 'email_fallback_accepted', 'email_fallback_failed' ), true ) ) return;
	$event_url = preg_replace( '#/v1/otp/?$#', '/v1/event', (string) $gateway_url );
	if ( ! $event_url || $event_url === $gateway_url ) return;
	$body = wp_json_encode( array(
		'phone'     => $phone,
		'origin'    => untrailingslashit( home_url() ),
		'requestId' => $request_id,
		'event'     => $event,
	) );
	$headers = pk_gateway_signed_headers( $settings, $body );
	if ( empty( $headers ) ) return;
	wp_remote_post( $event_url, array(
		'timeout'     => 3,
		'redirection' => 0,
		'headers'     => $headers,
		'body'        => $body,
	) );
}

function pk_whatsapp_notification_ready() {
	$s = pk_settings();
	return 'phonekey_gateway' === ( $s['whatsapp_mode'] ?? '' )
		&& ! empty( $s['whatsapp_webhook'] )
		&& ! empty( $s['whatsapp_key_id'] )
		&& '' !== pk_whatsapp_secret( $s );
}

function pk_send_whatsapp_message( $phone, $message, $purpose = 'notification_campaign', array $context = array() ) {
	$s = pk_settings();
	if ( ! pk_whatsapp_notification_ready() ) return new WP_Error( 'phonekey_whatsapp_unavailable', 'Key.kiwe WhatsApp is not configured.' );
	$allowed = array( 'notification_campaign', 'abandoned_cart_reminder', 'abandoned_cart_automation', 'order_status', 'admin_new_order', 'admin_new_comment', 'admin_visitor_summary', 'admin_live_visitor', 'admin_guest_application', 'admin_guest_submission', 'guest_post_status', 'editorial_post_status' );
	$purpose = sanitize_key( $purpose );
	$phone = pk_normalize_phone( $phone );
	$message = trim( wp_strip_all_tags( (string) $message ) );
	if ( ! in_array( $purpose, $allowed, true ) || strlen( preg_replace( '/\D/', '', $phone ) ) < 7 || '' === $message ) {
		return new WP_Error( 'phonekey_whatsapp_invalid', 'Key.kiwe rejected an invalid WhatsApp notification.' );
	}
	if ( function_exists( 'mb_substr' ) ) $message = mb_substr( $message, 0, 1600 );
	else $message = substr( $message, 0, 1600 );
	$request_id = pk_hmac( $purpose . '|' . $phone . '|' . $message . '|' . wp_generate_uuid4() );
	$payload = array(
		'phone'     => $phone,
		'message'   => $message,
		'purpose'   => $purpose,
		'site'      => get_bloginfo( 'name' ),
		'origin'    => untrailingslashit( home_url() ),
		'requestId' => $request_id,
	);
	$body = wp_json_encode( $payload );
	$headers = pk_gateway_signed_headers( $s, $body );
	$url = preg_replace( '#/v1/otp/?$#', '/v1/message', (string) $s['whatsapp_webhook'] );
	if ( empty( $headers ) || ! $url || $url === $s['whatsapp_webhook'] ) return new WP_Error( 'phonekey_whatsapp_contract', 'Key.kiwe WhatsApp gateway settings are incomplete.', array( 'request_id' => $request_id ) );
	$response = wp_safe_remote_post( $url, array(
		'timeout'     => 10,
		'redirection' => 0,
		'headers'     => $headers,
		'body'        => $body,
	) );
	$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$result = is_wp_error( $response ) ? array() : json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$accepted = $status >= 200 && $status < 300 && is_array( $result ) && ! empty( $result['ok'] );
	if ( ! $accepted ) {
		return new WP_Error( 'phonekey_whatsapp_delivery', 'Key.kiwe WhatsApp did not accept the notification.', array( 'request_id' => $request_id, 'status' => $status ) );
	}
	return array( 'ok' => true, 'request_id' => $request_id, 'provider' => 'phonekey_whatsapp' );
}

function pk_report_whatsapp_fallback_event( $phone, $request_id, $accepted ) {
	$s = pk_settings();
	pk_report_gateway_event( $s, (string) ( $s['whatsapp_webhook'] ?? '' ), $phone, $request_id, $accepted ? 'email_fallback_accepted' : 'email_fallback_failed' );
}

function pk_send_phone_otp( $user_id, $phone, $flow_token = '' ) {
	$s = pk_settings();
	$url = ! empty( $s['whatsapp_webhook'] ) ? $s['whatsapp_webhook'] : $s['sms_webhook'];
	if ( ! $url ) return array( 'ok' => false, 'message' => 'Phone delivery is not configured.' );
	$code = (string) wp_rand( 100000, 999999 );
	pk_issue_token( 'phone_otp', $user_id, PK_OTP_TTL, array(
		'otp_hash'    => pk_hmac( $code ),
		'flow_token'  => $flow_token,
		'anchor_hash' => pk_hmac( $phone ),
		'anchor_type' => 'phone',
	) );
	$request_id = pk_hmac( $flow_token . '|' . $phone . '|' . $code );
	$payload = array(
		'phone' => $phone,
		'code' => $code,
		'site' => get_bloginfo( 'name' ),
		'origin' => untrailingslashit( home_url() ),
		'purpose' => 'phonekey_verify',
		'requestId' => $request_id,
	);
	$body = wp_json_encode( $payload );
	$headers = array( 'Content-Type' => 'application/json' );
	if ( ! empty( $s['whatsapp_webhook'] ) && 'phonekey_gateway' === ( $s['whatsapp_mode'] ?? 'generic_webhook' ) ) {
		$headers = pk_gateway_signed_headers( $s, $body );
		if ( empty( $headers ) ) {
			$fallback = ! empty( $s['whatsapp_email_fallback'] ) && pk_send_phone_fallback_email( $user_id, $code );
			return array( 'ok' => $fallback, 'provider' => $fallback ? 'email_fallback' : 'none', 'message' => $fallback ? 'WhatsApp was unavailable; the code was sent by email.' : 'WhatsApp credentials are incomplete and no email fallback is available.' );
		}
	}
	$res = wp_remote_post( $url, array(
		'timeout' => 8,
		'redirection' => 0,
		'headers' => $headers,
		'body' => $body,
	) );
	$status = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$response_body = is_wp_error( $res ) ? array() : json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$accepted = $status >= 200 && $status < 300 && ( ! is_array( $response_body ) || ! array_key_exists( 'ok', $response_body ) || ! empty( $response_body['ok'] ) );
	if ( ! $accepted ) {
		pk_log( 'phone_otp_send_failed', $user_id, array( 'status' => $status, 'provider' => ! empty( $s['whatsapp_webhook'] ) ? 'whatsapp' : 'sms' ), 'warning' );
		$fallback = ! empty( $s['whatsapp_email_fallback'] ) && pk_send_phone_fallback_email( $user_id, $code );
		if ( 'phonekey_gateway' === ( $s['whatsapp_mode'] ?? 'generic_webhook' ) ) {
			pk_report_gateway_event( $s, $url, $phone, $request_id, $fallback ? 'email_fallback_accepted' : 'email_fallback_failed' );
		}
		return array( 'ok' => $fallback, 'provider' => $fallback ? 'email_fallback' : 'none', 'message' => $fallback ? 'WhatsApp was unavailable; the code was sent by email.' : 'Phone delivery failed and no email fallback is available.' );
	}
	pk_log( 'phone_otp_sent', $user_id, array( 'provider' => ! empty( $s['whatsapp_webhook'] ) ? 'whatsapp' : 'sms' ), 'info' );
	return array( 'ok' => true, 'provider' => ! empty( $s['whatsapp_webhook'] ) ? 'whatsapp' : 'sms' );
}

add_action( 'template_redirect', function () {
	if ( ( $_GET['pkv3'] ?? '' ) !== 'magic' ) return;
	$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
	$row = pk_token_row( 'email_magic', $token, true );
	if ( ! $row ) pk_die( esc_html__( 'This verification link has expired. Please request a new one.', 'phonekey' ), 403 );
	$user_id = (int) $row['user_id'];
	$user = get_userdata( $user_id );
	if ( ! $user ) pk_die( esc_html__( 'Invalid verification link.', 'phonekey' ), 403 );
	pk_mark_factor_verified( $user_id, 'email', $user->user_email );
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, pk_secure_auth_cookie() );
	pk_after_high_assurance_login( $user_id, 'email_magic', array( 'magic_link' => true ) );
	do_action( 'dsa_phonekey_authenticated', $user_id, 'email_magic' );
	wp_safe_redirect( pk_redirect_url() );
	exit;
} );

/* ============================================================
   WebAuthn CBOR/COSE
   ============================================================ */

/*
 * Minimal embedded CBOR decoder classes adapted for snippet use from
 * spomky-labs/cbor-php concepts (MIT). Supports the CBOR types used by
 * WebAuthn attestation objects and COSE keys.
 */
class PK_Spomky_Cbor_ByteStream {
	private $data;
	private $pos = 0;
	public function __construct( $data ) { $this->data = (string) $data; }
	public function eof() { return $this->pos >= strlen( $this->data ); }
	public function getPosition() { return $this->pos; }
	public function setPosition( $pos ) { $this->pos = (int) $pos; }
	public function readByte() {
		if ( $this->eof() ) throw new Exception( 'CBOR EOF' );
		return ord( $this->data[ $this->pos++ ] );
	}
	public function readBytes( $length ) {
		$length = (int) $length;
		$out = substr( $this->data, $this->pos, $length );
		if ( strlen( $out ) !== $length ) throw new Exception( 'CBOR short read' );
		$this->pos += $length;
		return $out;
	}
}

class PK_Spomky_Cbor_Decoder {
	public static function decode( $bytes ) {
		$stream = new PK_Spomky_Cbor_ByteStream( $bytes );
		return self::readItem( $stream );
	}
	private static function readLength( PK_Spomky_Cbor_ByteStream $stream, $additional ) {
		if ( $additional < 24 ) return $additional;
		if ( 24 === $additional ) return $stream->readByte();
		if ( 25 === $additional ) { $u = unpack( 'n', $stream->readBytes( 2 ) ); return $u[1]; }
		if ( 26 === $additional ) { $u = unpack( 'N', $stream->readBytes( 4 ) ); return $u[1]; }
		if ( 27 === $additional ) {
			$u = unpack( 'Nhi/Nlo', $stream->readBytes( 8 ) );
			return ( $u['hi'] * 4294967296 ) + $u['lo'];
		}
		if ( 31 === $additional ) return -1;
		throw new Exception( 'Invalid CBOR length' );
	}
	private static function readItem( PK_Spomky_Cbor_ByteStream $stream ) {
		$initial = $stream->readByte();
		$major = $initial >> 5;
		$additional = $initial & 31;
		if ( 7 === $major ) return self::readSimple( $stream, $additional );
		$length = self::readLength( $stream, $additional );

		if ( 0 === $major ) return $length;
		if ( 1 === $major ) return -1 - $length;
		if ( 2 === $major ) return self::readByteString( $stream, $length );
		if ( 3 === $major ) return self::readTextString( $stream, $length );
		if ( 4 === $major ) return self::readArray( $stream, $length );
		if ( 5 === $major ) return self::readMap( $stream, $length );
		if ( 6 === $major ) return self::readItem( $stream );
		throw new Exception( 'Unsupported CBOR major type' );
	}
	private static function readByteString( PK_Spomky_Cbor_ByteStream $stream, $length ) {
		if ( $length >= 0 ) return $stream->readBytes( $length );
		$out = '';
		while ( true ) {
			$peek = $stream->readByte();
			if ( 0xff === $peek ) break;
			if ( 2 !== ( $peek >> 5 ) ) throw new Exception( 'Invalid indefinite CBOR byte string' );
			$out .= $stream->readBytes( self::readLength( $stream, $peek & 31 ) );
		}
		return $out;
	}
	private static function readTextString( PK_Spomky_Cbor_ByteStream $stream, $length ) {
		if ( $length >= 0 ) return $stream->readBytes( $length );
		$out = '';
		while ( true ) {
			$peek = $stream->readByte();
			if ( 0xff === $peek ) break;
			if ( 3 !== ( $peek >> 5 ) ) throw new Exception( 'Invalid indefinite CBOR text string' );
			$out .= $stream->readBytes( self::readLength( $stream, $peek & 31 ) );
		}
		return $out;
	}
	private static function readArray( PK_Spomky_Cbor_ByteStream $stream, $length ) {
		$out = array();
		if ( $length >= 0 ) {
			for ( $i = 0; $i < $length; $i++ ) $out[] = self::readItem( $stream );
			return $out;
		}
		while ( true ) {
			if ( $stream->eof() ) throw new Exception( 'Unclosed CBOR array' );
			$pos = $stream->getPosition();
			if ( 0xff === $stream->readByte() ) break;
			$stream->setPosition( $pos );
			$out[] = self::readItem( $stream );
		}
		return $out;
	}
	private static function readMap( PK_Spomky_Cbor_ByteStream $stream, $length ) {
		$out = array();
		if ( $length >= 0 ) {
			for ( $i = 0; $i < $length; $i++ ) $out[ self::readItem( $stream ) ] = self::readItem( $stream );
			return $out;
		}
		while ( true ) {
			if ( $stream->eof() ) throw new Exception( 'Unclosed CBOR map' );
			$pos = $stream->getPosition();
			if ( 0xff === $stream->readByte() ) break;
			$stream->setPosition( $pos );
			$out[ self::readItem( $stream ) ] = self::readItem( $stream );
		}
		return $out;
	}
	private static function readSimple( PK_Spomky_Cbor_ByteStream $stream, $additional ) {
		if ( 20 === $additional ) return false;
		if ( 21 === $additional ) return true;
		if ( 22 === $additional ) return null;
		if ( 23 === $additional ) return null;
		if ( 24 === $additional ) return $stream->readByte();
		if ( 25 === $additional ) return null;
		if ( 26 === $additional ) { $u = unpack( 'G', $stream->readBytes( 4 ) ); return $u[1]; }
		if ( 27 === $additional ) { $u = unpack( 'E', $stream->readBytes( 8 ) ); return $u[1]; }
		throw new Exception( 'Unsupported CBOR simple value' );
	}
}

function pk_der_len( $len ) {
	if ( $len < 128 ) return chr( $len );
	$out = '';
	while ( $len > 0 ) { $out = chr( $len & 255 ) . $out; $len >>= 8; }
	return chr( 128 | strlen( $out ) ) . $out;
}

function pk_der( $tag, $body ) {
	return chr( $tag ) . pk_der_len( strlen( $body ) ) . $body;
}

function pk_der_oid( $oid ) {
	$parts = array_map( 'intval', explode( '.', $oid ) );
	$body = chr( $parts[0] * 40 + $parts[1] );
	for ( $i = 2; $i < count( $parts ); $i++ ) {
		$v = $parts[ $i ];
		$stack = array( $v & 127 );
		$v >>= 7;
		while ( $v ) { array_unshift( $stack, 128 | ( $v & 127 ) ); $v >>= 7; }
		foreach ( $stack as $b ) $body .= chr( $b );
	}
	return pk_der( 0x06, $body );
}

function pk_der_int( $bin ) {
	$bin = ltrim( $bin, "\x00" );
	if ( '' === $bin ) $bin = "\x00";
	if ( ord( $bin[0] ) & 0x80 ) $bin = "\x00" . $bin;
	return pk_der( 0x02, $bin );
}

function pk_cose_to_pem( $cose_b64 ) {
	$key = PK_Spomky_Cbor_Decoder::decode( pk_b64u_dec( $cose_b64 ) );
	if ( ! is_array( $key ) || ! isset( $key[1] ) ) return '';
	$kty = (int) $key[1];
	if ( 1 === $kty && (int) ( $key[3] ?? 0 ) === -8 && isset( $key[-2] ) ) {
		$x = $key[-2];
		if ( strlen( $x ) !== 32 ) return '';
		$alg = pk_der( 0x30, pk_der_oid( '1.3.101.112' ) );
		$spki = pk_der( 0x30, $alg . pk_der( 0x03, "\x00" . $x ) );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}
	if ( 2 === $kty && isset( $key[-2], $key[-3] ) ) {
		$x = $key[-2]; $y = $key[-3];
		if ( strlen( $x ) !== 32 || strlen( $y ) !== 32 ) return '';
		$alg = pk_der( 0x30, pk_der_oid( '1.2.840.10045.2.1' ) . pk_der_oid( '1.2.840.10045.3.1.7' ) );
		$bit = pk_der( 0x03, "\x00" . "\x04" . $x . $y );
		$spki = pk_der( 0x30, $alg . $bit );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}
	if ( 3 === $kty && isset( $key[-1], $key[-2] ) ) {
		$n = $key[-1]; $e = $key[-2];
		$rsa = pk_der( 0x30, pk_der_int( $n ) . pk_der_int( $e ) );
		$alg = pk_der( 0x30, pk_der_oid( '1.2.840.113549.1.1.1' ) . pk_der( 0x05, '' ) );
		$spki = pk_der( 0x30, $alg . pk_der( 0x03, "\x00" . $rsa ) );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}
	return '';
}

function pk_expected_origin() {
	$p = wp_parse_url( home_url( '/' ) );
	return ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
}

function pk_rp_id() {
	$p = wp_parse_url( home_url( '/' ) );
	return $p['host'] ?? ( $_SERVER['HTTP_HOST'] ?? '' );
}

function pk_parse_registration( $attestation_b64 ) {
	$att = PK_Spomky_Cbor_Decoder::decode( pk_b64u_dec( $attestation_b64 ) );
	if ( ! is_array( $att ) || empty( $att['authData'] ) ) throw new Exception( 'Bad attestation object' );
	$auth = $att['authData'];
	if ( strlen( $auth ) < 55 ) throw new Exception( 'Bad authenticator data' );
	$rp_hash = substr( $auth, 0, 32 );
	if ( ! hash_equals( hash( 'sha256', pk_rp_id(), true ), $rp_hash ) ) throw new Exception( 'RP hash mismatch' );
	$flags = ord( $auth[32] );
	if ( ! ( $flags & 0x01 ) ) throw new Exception( 'User presence missing' );
	if ( ! ( $flags & 0x40 ) ) throw new Exception( 'Attested credential missing' );
	$sign = unpack( 'N', substr( $auth, 33, 4 ) )[1];
	$pos = 37;
	$aaguid = bin2hex( substr( $auth, $pos, 16 ) ); $pos += 16;
	$len = unpack( 'n', substr( $auth, $pos, 2 ) )[1]; $pos += 2;
	$cred_id = substr( $auth, $pos, $len ); $pos += $len;
	$cose = substr( $auth, $pos );
	$cose_map = PK_Spomky_Cbor_Decoder::decode( $cose );
	$alg = isset( $cose_map[3] ) ? (int) $cose_map[3] : 0;
	return array(
		'credential_id' => pk_b64u( $cred_id ),
		'public_cose'   => pk_b64u( $cose ),
		'alg'           => $alg,
		'sign_count'    => $sign,
		'aaguid'        => $aaguid,
		'flags'         => $flags,
	);
}

function pk_verify_client_data( $client_b64, $type, $challenge ) {
	$json = pk_b64u_dec( $client_b64 );
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) throw new Exception( 'Bad client data' );
	if ( ( $data['type'] ?? '' ) !== $type ) throw new Exception( 'Wrong WebAuthn type' );
	if ( ! hash_equals( (string) $challenge, (string) ( $data['challenge'] ?? '' ) ) ) throw new Exception( 'Challenge mismatch' );
	if ( ! hash_equals( pk_expected_origin(), (string) ( $data['origin'] ?? '' ) ) ) throw new Exception( 'Origin mismatch' );
	return $json;
}

function pk_webauthn_user_id( $user_id ) {
	$raw = get_user_meta( $user_id, 'pk_webauthn_user_handle', true );
	if ( ! $raw ) {
		$raw = pk_rand_token( 32 );
		update_user_meta( $user_id, 'pk_webauthn_user_handle', $raw );
	}
	return $raw;
}

function pk_credential_count( $user_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . pk_t( 'credentials' ) . " WHERE user_id=%d", $user_id ) );
}

function pk_credentials_for_user( $user_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . pk_t( 'credentials' ) . " WHERE user_id=%d ORDER BY last_used_at DESC,id DESC", $user_id ), ARRAY_A );
}

/* ============================================================
   TOTP and backup codes
   ============================================================ */

function pk_base32_chars() { return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; }

function pk_base32_encode( $bytes ) {
	$alphabet = pk_base32_chars();
	$bits = '';
	for ( $i = 0; $i < strlen( $bytes ); $i++ ) $bits .= str_pad( decbin( ord( $bytes[$i] ) ), 8, '0', STR_PAD_LEFT );
	$out = '';
	foreach ( str_split( $bits, 5 ) as $chunk ) {
		$chunk = str_pad( $chunk, 5, '0', STR_PAD_RIGHT );
		$out .= $alphabet[ bindec( $chunk ) ];
	}
	return $out;
}

function pk_base32_decode( $s ) {
	$s = strtoupper( preg_replace( '/[^A-Z2-7]/', '', (string) $s ) );
	$alphabet = pk_base32_chars();
	$bits = '';
	for ( $i = 0; $i < strlen( $s ); $i++ ) {
		$p = strpos( $alphabet, $s[$i] );
		if ( false === $p ) continue;
		$bits .= str_pad( decbin( $p ), 5, '0', STR_PAD_LEFT );
	}
	$out = '';
	foreach ( str_split( $bits, 8 ) as $byte ) if ( strlen( $byte ) === 8 ) $out .= chr( bindec( $byte ) );
	return $out;
}

function pk_totp_code( $secret, $time_slice = null ) {
	if ( null === $time_slice ) $time_slice = floor( time() / 30 );
	$key = pk_base32_decode( $secret );
	$counter = pack( 'N*', 0 ) . pack( 'N*', $time_slice );
	$hash = hash_hmac( 'sha1', $counter, $key, true );
	$offset = ord( substr( $hash, -1 ) ) & 0x0f;
	$value = unpack( 'N', substr( $hash, $offset, 4 ) )[1] & 0x7fffffff;
	return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
}

function pk_totp_verify( $secret, $code ) {
	$code = preg_replace( '/[^0-9]/', '', (string) $code );
	if ( strlen( $code ) !== 6 ) return false;
	$now = floor( time() / 30 );
	for ( $i = -1; $i <= 1; $i++ ) if ( hash_equals( pk_totp_code( $secret, $now + $i ), $code ) ) return true;
	return false;
}

function pk_totp_factor( $user_id ) {
	$f = pk_factor( $user_id, 'totp' );
	if ( ! $f || 'verified' !== ( $f['status'] ?? '' ) || empty( $f['factor_value'] ) ) return null;
	$f['secret'] = pk_decrypt( $f['factor_value'] );
	return $f;
}

function pk_backup_codes_generate( $user_id ) {
	$plain = array();
	$hashes = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$code = strtoupper( substr( pk_rand_token( 8 ), 0, 4 ) . '-' . substr( pk_rand_token( 8 ), 0, 4 ) );
		$plain[] = $code;
		$hashes[] = wp_hash_password( $code );
	}
	pk_upsert_factor( $user_id, 'backup_code', 'verified', 'backup', array( 'hashes' => $hashes, 'remaining' => count( $hashes ) ) );
	update_user_meta( $user_id, 'pk_backup_codes_generated', current_time( 'mysql' ) );
	pk_log( 'backup_codes_generated', $user_id, array(), 'success' );
	return $plain;
}

function pk_backup_code_consume( $user_id, $code ) {
	$f = pk_factor( $user_id, 'backup_code', 'backup' );
	if ( ! $f ) return false;
	$meta = pk_meta_decode( $f['meta'] ?? '' );
	$hashes = (array) ( $meta['hashes'] ?? array() );
	foreach ( $hashes as $i => $hash ) {
		if ( wp_check_password( strtoupper( trim( $code ) ), $hash ) ) {
			unset( $hashes[ $i ] );
			$meta['hashes'] = array_values( $hashes );
			$meta['remaining'] = count( $hashes );
			pk_upsert_factor( $user_id, 'backup_code', 'verified', 'backup', $meta );
			pk_log( 'backup_code_used', $user_id, array( 'remaining' => count( $hashes ) ), 'success' );
			return true;
		}
	}
	return false;
}

/* ============================================================
   Login, trusted device, IP trust, sessions
   ============================================================ */

function pk_redirect_url() {
	$custom = get_option( 'pk_after_login_url', '' );
	if ( $custom ) return esc_url_raw( $custom );
	if ( is_user_logged_in() && pk_is_privileged( get_current_user_id() ) ) {
		return admin_url();
	}
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$url = wc_get_page_permalink( 'myaccount' );
		if ( $url ) return $url;
	}
	return home_url( '/' );
}

function pk_login_return_cookie_name() {
	return 'kiwe_login_return';
}

function pk_validate_login_return_url( $url ) {
	$url = esc_url_raw( (string) $url );
	if ( ! $url ) return '';

	$validated = wp_validate_redirect( $url, '' );
	if ( ! $validated ) return '';

	$target_host = strtolower( (string) wp_parse_url( $validated, PHP_URL_HOST ) );
	$site_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	return $target_host && $site_host && hash_equals( $site_host, $target_host ) ? $validated : '';
}

function pk_remember_login_return( $url ) {
	$url = pk_validate_login_return_url( $url );
	if ( ! $url || headers_sent() ) return false;

	$payload = rtrim( strtr( base64_encode( wp_json_encode( array( 'url' => $url, 'exp' => time() + 15 * MINUTE_IN_SECONDS ) ) ), '+/', '-_' ), '=' );
	$value   = $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	$options = pk_device_cookie_options( time() + 15 * MINUTE_IN_SECONDS );
	setcookie( pk_login_return_cookie_name(), $value, $options );
	$_COOKIE[ pk_login_return_cookie_name() ] = $value;
	return true;
}

function pk_remember_requested_login_return( $fallback_to_request = false ) {
	$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '';
	if ( ! $redirect && $fallback_to_request ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$redirect = $request_uri ? home_url( $request_uri ) : '';
	}
	return pk_remember_login_return( $redirect );
}

function pk_consume_login_return() {
	$name  = pk_login_return_cookie_name();
	$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	if ( ! $value ) return '';

	if ( ! headers_sent() ) {
		setcookie( $name, '', pk_device_cookie_options( time() - HOUR_IN_SECONDS ) );
	}
	unset( $_COOKIE[ $name ] );

	$parts = explode( '.', $value, 2 );
	if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) return '';
	$encoded = strtr( $parts[0], '-_', '+/' );
	$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
	$decoded = json_decode( (string) base64_decode( $encoded, true ), true );
	if ( ! is_array( $decoded ) || (int) ( $decoded['exp'] ?? 0 ) < time() ) return '';
	return pk_validate_login_return_url( $decoded['url'] ?? '' );
}

function pk_device_cookie_options( $expires ) {
	return array(
		'expires'  => $expires,
		'path'     => COOKIEPATH ?: '/',
		'domain'   => COOKIE_DOMAIN ?: '',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	);
}

function pk_set_trusted_device( $user_id ) {
	if ( ! pk_settings()['trusted_devices'] ) return;
	global $wpdb;
	$policy = pk_role_policy( $user_id );
	$days = max( 1, absint( $policy['device_days'] ?? 30 ) );
	$token = pk_rand_token( 32 );
	$expires = time() + DAY_IN_SECONDS * $days;
	setcookie( PK_DEVICE_COOKIE, $token, pk_device_cookie_options( $expires ) );
	$wpdb->insert( pk_t( 'trusted_devices' ), array(
		'user_id'      => $user_id,
		'token_hash'   => pk_hmac( $token ),
		'ua_hash'      => pk_hmac( pk_ua() ),
		'role_scope'   => implode( ',', pk_user_roles( $user_id ) ),
		'created_at'   => pk_now(),
		'expires_at'   => gmdate( 'Y-m-d H:i:s', $expires ),
		'last_seen_at' => pk_now(),
	) );
}

function pk_current_trusted_device( $user_id ) {
	if ( empty( $_COOKIE[ PK_DEVICE_COOKIE ] ) ) return false;
	global $wpdb;
	$token = sanitize_text_field( wp_unslash( $_COOKIE[ PK_DEVICE_COOKIE ] ) );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, role_scope FROM " . pk_t( 'trusted_devices' ) . " WHERE user_id=%d AND token_hash=%s AND expires_at>%s LIMIT 1", $user_id, pk_hmac( $token ), pk_now() ) );
	if ( ! $row ) return false;
	$current_scope = implode( ',', pk_user_roles( $user_id ) );
	if ( ! hash_equals( (string) $row->role_scope, $current_scope ) ) {
		$wpdb->delete( pk_t( 'trusted_devices' ), array( 'id' => (int) $row->id ) );
		pk_log( 'trusted_device_role_scope_revoked', $user_id, array( 'previous_scope' => sanitize_text_field( $row->role_scope ), 'current_scope' => $current_scope ), 'warning' );
		return false;
	}
	$wpdb->update( pk_t( 'trusted_devices' ), array( 'last_seen_at' => pk_now() ), array( 'id' => (int) $row->id ) );
	return true;
}

function pk_after_high_assurance_login( $user_id, $method, $meta = array() ) {
	$ip_hash = pk_hmac( pk_ip() );
	$s = pk_settings();
	$strict_privileged_complete = pk_is_privileged( $user_id ) && pk_admin_enrollment_complete( $user_id );
	$scores = get_user_meta( $user_id, 'pk_ip_trust_scores', true );
	if ( ! is_array( $scores ) ) $scores = array();
	$scores[ $ip_hash ] = array(
		'score'      => min( 999, (int) ( $scores[ $ip_hash ]['score'] ?? 0 ) + 1 ),
		'last_seen'  => time(),
		'expires_at' => time() + DAY_IN_SECONDS * absint( $s['ip_trust_ttl'] ),
	);
	update_user_meta( $user_id, 'pk_ip_trust_scores', $scores );
	$trust_threshold = $strict_privileged_complete ? 1 : absint( $s['ip_trust_threshold'] );
	if ( $scores[ $ip_hash ]['score'] >= $trust_threshold ) {
		$trusted = get_user_meta( $user_id, 'pk_trusted_ip_hashes', true );
		if ( ! is_array( $trusted ) ) $trusted = array();
		$trusted[ $ip_hash ] = array( 'trusted_at' => time(), 'expires_at' => time() + DAY_IN_SECONDS * absint( $s['ip_trust_ttl'] ) );
		update_user_meta( $user_id, 'pk_trusted_ip_hashes', $trusted );
		if ( function_exists( 'stp_touch_profile' ) && array_intersect( pk_user_roles( $user_id ), (array) $s['ip_trust_roles'] ) ) {
			stp_touch_profile( $user_id, pk_ip(), '', (int) current_time( 'G' ), false );
		}
		if ( $strict_privileged_complete && function_exists( 'stp_trust_ip' ) ) {
			$trust_result = stp_trust_ip( pk_ip(), 'Trusted automatically after completed Key.kiwe strict authentication for user #' . (int) $user_id );
			if ( is_wp_error( $trust_result ) ) {
				pk_log( 'ip_trust_sync_failed', $user_id, array( 'method' => $method ), 'warning' );
			}
		}
		pk_log( 'ip_trust_elevated', $user_id, array( 'method' => $method, 'score' => $scores[ $ip_hash ]['score'] ), 'success' );
	}
	if ( ! pk_is_privileged( $user_id ) || $strict_privileged_complete ) {
		pk_set_trusted_device( $user_id );
	}
	update_user_meta( $user_id, 'pk_last_high_assurance_login', current_time( 'mysql' ) );
	pk_log( 'high_assurance_login', $user_id, array_merge( array( 'method' => $method ), $meta ), 'success' );
}

function pk_missing_counterpart_type( $user_id ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user ) return '';
	$has_email = pk_factor_verified( $user_id, 'email' );
	$has_phone = pk_factor_verified( $user_id, 'phone' );
	if ( $has_email && ! $has_phone ) return 'phone';
	if ( $has_phone && ! $has_email ) return 'email';
	return '';
}

function pk_counterpart_prompt_data( $user_id, $redirect, $meta = array() ) {
	$s = pk_settings();
	if ( 'email_or_phone' !== ( $s['identifier_mode'] ?? '' ) || pk_is_privileged( $user_id ) || ! empty( $meta['skip_counterpart_prompt'] ) ) return array();
	if ( empty( $meta['force_counterpart_prompt'] ) && get_user_meta( $user_id, 'pk_counterpart_prompt_skipped_at', true ) ) return array();
	$type = pk_missing_counterpart_type( $user_id );
	if ( ! $type ) return array();
	$token = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, array(
		'mode' => 'counterpart_prompt',
		'counterpart_type' => $type,
		'identity_verified_this_flow' => ! empty( $meta['identity_verified_this_flow'] ) ? 1 : 0,
		'completion_intent' => ! empty( $meta['completion_intent'] ) ? 1 : 0,
	) );
	return array(
		'counterpartRequired' => true,
		'counterpartType' => $type,
		'counterpartToken' => $token,
		'canSkipCounterpart' => true,
		'redirect' => $redirect,
	);
}

function pk_next_security_setup_response( $user_id, $flow_meta = array() ) {
	$user_id = pk_canonical_user_id( $user_id );
	if ( ! $user_id || pk_is_privileged( $user_id ) ) return array();

	$security = pk_security_completion( $user_id );
	$prompt_meta = array(
		'force_counterpart_prompt' => ! empty( $flow_meta['completion_intent'] ) ? 1 : 0,
		'identity_verified_this_flow' => ! empty( $flow_meta['identity_verified_this_flow'] ) ? 1 : 0,
		'completion_intent' => ! empty( $flow_meta['completion_intent'] ) ? 1 : 0,
	);
	$counterpart = pk_counterpart_prompt_data( $user_id, '', $prompt_meta );
	if ( $counterpart ) {
		return array_merge( array(
			'ok' => true,
			'next' => 'security_setup',
			'security' => $security,
		), $counterpart );
	}

	if ( empty( $security['passkeyEnrolled'] ) ) {
		$next_meta = $flow_meta;
		$next_meta['mode'] = 'enroll_passkey';
		return array(
			'ok' => true,
			'next' => 'enroll_passkey',
			'token' => pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $next_meta ),
			'verified' => true,
			'security' => $security,
			'canLater' => true,
		);
	}

	return array();
}

function pk_finish_login( $user_id, $method, $high = true, $meta = array() ) {
	$user_id = pk_canonical_user_id( $user_id );
	if ( ! $user_id ) return array( 'ok' => false, 'message' => 'This account link is invalid.' );
	if ( pk_is_privileged( $user_id ) && ! pk_admin_enrollment_complete( $user_id ) ) {
		pk_flag_admin_enrollment( $user_id );
		pk_log( 'privileged_login_blocked_incomplete', $user_id, array( 'method' => sanitize_key( $method ) ), 'warning' );
		return array(
			'ok' => false,
			'code' => 'privileged_setup_incomplete',
			'message' => 'Complete WordPress password confirmation, recovery verification, phone binding, and passkey setup before administrator access.',
		);
	}
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, pk_secure_auth_cookie() );
	update_user_meta( $user_id, 'pk_session_started_at', time() );
	if ( $high ) pk_after_high_assurance_login( $user_id, $method, $meta );
	else pk_log( 'login_success', $user_id, array( 'method' => $method ), 'success' );
	do_action( 'dsa_phonekey_authenticated', $user_id, $method );
	$redirect = pk_consume_login_return();
	if ( ! $redirect ) $redirect = pk_redirect_url();
	$counterpart = pk_counterpart_prompt_data( $user_id, $redirect, $meta );
	return array_merge( array( 'ok' => true, 'redirect' => $redirect ), $counterpart );
}

function pk_known_device_for_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) return false;
	if ( pk_current_trusted_device( $user_id ) ) return true;

	$ip = pk_ip();
	if ( function_exists( 'stp_user_profile_knows_ip' ) && stp_user_profile_knows_ip( $user_id, $ip ) ) return true;
	if ( function_exists( 'stp_ip_status_is_trusted' ) && stp_ip_status_is_trusted( $ip ) ) return true;

	$trusted = get_user_meta( $user_id, 'pk_trusted_ip_hashes', true );
	if ( is_array( $trusted ) ) {
		$hash = pk_hmac( $ip );
		if ( isset( $trusted[ $hash ] ) ) {
			$expires = (int) ( $trusted[ $hash ]['expires_at'] ?? 0 );
			if ( ! $expires || $expires > time() ) return true;
		}
	}

	return false;
}

function pk_force_factor_for_account( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) return false;

	$is_claimed = pk_account_verified( $user_id ) || pk_is_privileged( $user_id );
	if ( ! $is_claimed ) return false;

	return ! pk_known_device_for_user( $user_id );
}

function pk_step_up_required( $user_id ) {
	$policy = pk_role_policy( $user_id );
	$totp = pk_totp_factor( $user_id );
	if ( ! $totp ) return false;
	if ( 'totp_required' === ( $policy['step_up'] ?? 'none' ) ) return true;
	if ( 'totp_optional' === ( $policy['step_up'] ?? 'none' ) && ! pk_current_trusted_device( $user_id ) ) return true;
	return false;
}

function pk_is_login_request() {
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
	$self   = isset( $_SERVER['PHP_SELF'] ) ? wp_unslash( $_SERVER['PHP_SELF'] ) : '';

	return false !== strpos( $script, 'wp-login.php' ) || false !== strpos( $self, 'wp-login.php' );
}

function pk_break_glass_login_allowed() {
	return function_exists( 'stp_break_glass_session_valid' ) && stp_break_glass_session_valid();
}

function pk_surface_login_url() {
	return add_query_arg( 'kiwe-auth', '1', home_url( '/' ) );
}

function pk_protect_wordpress_login() {
	$s = pk_settings();
	if ( empty( $s['replace_wp_login'] ) || pk_break_glass_login_allowed() ) return;
	$action = sanitize_key( $_REQUEST['action'] ?? 'login' );
	$allowed = array( 'logout', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'postpass', 'confirm_admin_email' );
	if ( in_array( $action, $allowed, true ) ) return;
	pk_remember_requested_login_return();
	wp_safe_redirect( pk_surface_login_url() );
	exit;
}
add_action( 'login_init', 'pk_protect_wordpress_login', -5 );

function pk_protect_wordpress_admin() {
	$s = pk_settings();
	if ( empty( $s['protect_wp_admin'] ) || ! is_admin() ) return;
	if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) return;
	$script = basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ) );
	if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true ) ) return;
	if ( pk_break_glass_login_allowed() ) return;
	if ( is_user_logged_in() && pk_is_privileged( get_current_user_id() ) && pk_admin_enrollment_complete( get_current_user_id() ) ) return;
	if ( is_user_logged_in() && pk_is_privileged( get_current_user_id() ) ) {
		$user_id = get_current_user_id();
		pk_flag_admin_enrollment( $user_id );
		$notice_key = 'pk_admin_setup_blocked_' . (int) $user_id;
		if ( ! get_transient( $notice_key ) ) {
			pk_log( 'wordpress_admin_blocked_pending_strict_setup', $user_id, array( 'script' => $script ), 'info' );
			set_transient( $notice_key, 1, 5 * MINUTE_IN_SECONDS );
		}
	}
	pk_remember_requested_login_return( true );
	wp_safe_redirect( pk_surface_login_url() );
	exit;
}
add_action( 'init', 'pk_protect_wordpress_admin', -5 );

function pk_enforce_session_timeout() {
	if ( ! is_user_logged_in() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
	if ( pk_is_login_request() ) return;
	$user_id = get_current_user_id();
	$policy = pk_role_policy( $user_id );
	$timeout = absint( $policy['timeout'] ?? 0 );
	$admin_reauth = absint( pk_settings()['admin_reauth_minutes'] ?? 0 );
	if ( pk_is_privileged( $user_id ) && $admin_reauth > 0 ) $timeout = max( $timeout, $admin_reauth );
	if ( ! $timeout ) return;
	$start = (int) get_user_meta( $user_id, 'pk_session_started_at', true );
	if ( ! $start ) {
		update_user_meta( $user_id, 'pk_session_started_at', time() );
		return;
	}
	if ( time() - $start > $timeout * MINUTE_IN_SECONDS ) {
		pk_log( 'session_timeout', $user_id, array( 'timeout_minutes' => $timeout ), 'info' );
		wp_logout();
		wp_safe_redirect( wp_login_url() );
		exit;
	}
}

/* ============================================================
   REST API v3
   ============================================================ */

function pk_public_rest_allowed( $request = null ) {
	if ( class_exists( '\\DSA\\Utilities\\Origin_Checker' ) ) {
		if ( $request instanceof WP_REST_Request ) {
			return \DSA\Utilities\Origin_Checker::mutation_allowed( $request );
		}
		return false;
	}
	return false;
}

function pk_account_rest_allowed( $request = null ) {
	if ( ! is_user_logged_in() || ! $request instanceof WP_REST_Request ) {
		return false;
	}

	if ( class_exists( '\\DSA\\Utilities\\Origin_Checker' ) ) {
		return \DSA\Utilities\Origin_Checker::mutation_allowed( $request );
	}

	return false;
}

add_action( 'rest_api_init', function () {
	$open = array( 'permission_callback' => 'pk_public_rest_allowed' );
	register_rest_route( PK_NS, '/config', array( 'methods' => 'GET', 'callback' => 'pk_rest_config', 'permission_callback' => '__return_true' ) );
	register_rest_route( PK_NS, '/identify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_identify' ) ) );
	register_rest_route( PK_NS, '/verify-code', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_verify_code' ) ) );
	register_rest_route( PK_NS, '/verify-email', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_verify_email' ) ) );
	register_rest_route( PK_NS, '/verify-phone', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_verify_phone' ) ) );
	register_rest_route( PK_NS, '/resend-otp', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_resend_otp' ) ) );
	register_rest_route( PK_NS, '/send-recovery', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_send_recovery' ) ) );
	register_rest_route( PK_NS, '/admin-password-verify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_admin_password_verify' ) ) );
	register_rest_route( PK_NS, '/continue-later', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_continue_later' ) ) );
	register_rest_route( PK_NS, '/counterpart/start', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_counterpart_start' ) ) );
	register_rest_route( PK_NS, '/counterpart/skip', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_counterpart_skip' ) ) );
	register_rest_route( PK_NS, '/bind-phone', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_bind_phone' ) ) );
	register_rest_route( PK_NS, '/webauthn/register/options', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_webauthn_register_options' ) ) );
	register_rest_route( PK_NS, '/webauthn/register/verify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_webauthn_register_verify' ) ) );
	register_rest_route( PK_NS, '/webauthn/login/options', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_webauthn_login_options' ) ) );
	register_rest_route( PK_NS, '/webauthn/login/verify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_webauthn_login_verify' ) ) );
	register_rest_route( PK_NS, '/totp/login-verify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_totp_login_verify' ) ) );
	register_rest_route( PK_NS, '/backup/login-verify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_backup_login_verify' ) ) );
	register_rest_route( PK_NS, '/totp/recovery-verify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_totp_recovery_verify' ) ) );
	register_rest_route( PK_NS, '/backup/recovery-verify', array_merge( $open, array( 'methods' => 'POST', 'callback' => 'pk_rest_backup_recovery_verify' ) ) );
	register_rest_route( PK_NS, '/session/status', array( 'methods' => 'GET', 'callback' => 'pk_rest_session_status', 'permission_callback' => function () { return is_user_logged_in(); } ) );
	register_rest_route( PK_NS, '/account/factor/start', array( 'methods' => 'POST', 'callback' => 'pk_rest_account_factor_start', 'permission_callback' => 'pk_account_rest_allowed' ) );
	register_rest_route( PK_NS, '/account/totp/start', array( 'methods' => 'POST', 'callback' => 'pk_rest_account_totp_start', 'permission_callback' => 'pk_account_rest_allowed' ) );
	register_rest_route( PK_NS, '/account/totp/verify', array( 'methods' => 'POST', 'callback' => 'pk_rest_account_totp_verify', 'permission_callback' => 'pk_account_rest_allowed' ) );
	register_rest_route( PK_NS, '/account/backup/regenerate', array( 'methods' => 'POST', 'callback' => 'pk_rest_account_backup_regenerate', 'permission_callback' => 'pk_account_rest_allowed' ) );
} );

function pk_rest_config() {
	$s = pk_settings();
	return rest_ensure_response( array(
		'ok'             => true,
		'rpId'           => pk_rp_id(),
		'userLoggedIn'   => is_user_logged_in(),
		'popupDisplay'   => $s['popup_display'],
		'popupDismissal' => $s['popup_dismissal'],
		'identifierMode' => $s['identifier_mode'],
		'appIdentifierMode' => $s['app_identifier_mode'] ?? $s['identifier_mode'],
		'siteName'       => get_bloginfo( 'name' ),
		'phoneReady'     => pk_phone_provider_ready(),
		'returnMessage'  => $s['return_message'],
		'returnSeconds'  => (int) $s['return_seconds'],
	) );
}

function pk_visit_count( $anchor_hash, $user_id = 0 ) {
	global $wpdb;
	$visitor = pk_visitor_hash();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . pk_t( 'visits' ) . " WHERE visitor_hash=%s AND anchor_hash=%s LIMIT 1", $visitor, $anchor_hash ), ARRAY_A );
	if ( $row ) {
		$count = (int) $row['visit_count'] + 1;
		$wpdb->update( pk_t( 'visits' ), array( 'visit_count' => $count, 'last_seen_at' => pk_now(), 'user_id' => $user_id ), array( 'id' => (int) $row['id'] ) );
		return $count;
	}
	$wpdb->insert( pk_t( 'visits' ), array(
		'visitor_hash'  => $visitor,
		'anchor_hash'   => $anchor_hash,
		'user_id'       => absint( $user_id ),
		'visit_count'   => 1,
		'first_seen_at' => pk_now(),
		'last_seen_at'  => pk_now(),
	) );
	return 1;
}

function pk_rest_identify( WP_REST_Request $r ) {
	if ( ! pk_rate_limit( 'identify', 40, 300 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many attempts. Try again shortly.' ), 429 );
	$app_context = (bool) $r->get_param( 'appContext' );
	$completion_intent = 'complete_security' === sanitize_key( (string) $r->get_param( 'intent' ) );
	$s = pk_settings();
	$identifier_mode = $app_context ? ( $s['app_identifier_mode'] ?? $s['identifier_mode'] ) : $s['identifier_mode'];
	$dual_identifier = 'email_and_phone' === $identifier_mode;
	$pending_phone = '';

	if ( $dual_identifier ) {
		$email = pk_normalize_email( $r->get_param( 'email' ) );
		$pending_phone = pk_normalize_phone( sanitize_text_field( $r->get_param( 'phone' ) ) );
		$phone_digits = preg_replace( '/[^0-9]/', '', $pending_phone );
		if ( ! is_email( $email ) || strlen( $phone_digits ) < 7 ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Enter a valid email address and phone number.' ), 400 );
		}
		if ( ! pk_phone_provider_ready() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'phone_delivery_unavailable', 'message' => 'Email + phone sign-in needs a working WhatsApp or SMS provider. Ask the site administrator to configure Key.kiwe.' ), 503 );
		}
		$id = array( 'type' => 'email', 'value' => $email );
	} else {
		$id = pk_detect_identifier( sanitize_text_field( $r->get_param( 'identifier' ) ) );
	}

	if ( ! $id['type'] || ! pk_identifier_allowed( $id['type'], $app_context ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Enter a valid allowed email or phone.' ), 400 );
	$type = $id['type']; $identifier = $id['value'];
	$pending_wordpress_owner = 'email' === $type ? pk_pending_wordpress_email_owner( $identifier ) : 0;
	if ( $pending_wordpress_owner && pk_is_privileged( $pending_wordpress_owner ) ) {
		// A pending administrator email remains attached to the original account.
		// Strict setup still requires its WordPress password/passkey, so control of
		// the requested mailbox alone can never grant privileged access.
		$user_id = $pending_wordpress_owner;
		pk_log( 'wordpress_pending_email_continuity', $user_id, array( 'strict' => 1 ), 'info' );
	} elseif ( $pending_wordpress_owner ) {
		return new WP_REST_Response( array(
			'ok'      => false,
			'code'    => 'wordpress_email_change_pending',
			'message' => 'This address is waiting for confirmation on an existing WordPress account. Sign in with that account’s current email, then finish the email change from its profile.',
		), 409 );
	} else {
		$user_id = pk_find_user( $identifier, $type );
	}
	if ( $dual_identifier ) {
		$phone_user_id = pk_find_user( $pending_phone, 'phone' );
		if ( $phone_user_id && ( ! $user_id || $phone_user_id !== $user_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'identifier_pair_conflict', 'message' => 'That email and phone cannot be used together. Sign in with the existing account or use account recovery.' ), 409 );
		}
	}
	$is_new = false;
	if ( ! $user_id ) {
		$user_id = pk_create_user_for_identifier( $identifier, $type );
		$is_new = true;
	}
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Could not start authentication.' ), 500 );

	$user = get_userdata( $user_id );
	$verified = pk_account_verified( $user_id );
	$creds = pk_credential_count( $user_id );
	$policy = pk_role_policy( $user_id );
	$visit_count = pk_visit_count( pk_hmac( $identifier ), $user_id );
	$timing = $policy['verification'] ?: $s['verification_timing'];
	if ( $app_context && ! pk_is_privileged( $user_id ) ) {
		$app_timing = $s['app_verification_timing'] ?? 'verify_now';
		$timing_rank = array( 'verify_later' => 1, 'progressive' => 2, 'verify_now' => 3 );
		if ( ( $timing_rank[ $app_timing ] ?? 3 ) > ( $timing_rank[ $timing ] ?? 1 ) ) {
			$timing = $app_timing;
		}
	}
	$strict_now = 'verify_now' === $timing || ( 'progressive' === $timing && $visit_count >= absint( $s['progressive_visits'] ) );
	$grace_used = (bool) get_user_meta( $user_id, 'pk_initial_unverified_login_used_at', true );
	$grace_available = 'verify_later' === $timing && $is_new && ! $grace_used && ! pk_is_privileged( $user_id );
	if ( ! $verified && ! $is_new ) {
		$strict_now = true;
	}
	$known_device = pk_known_device_for_user( $user_id );
	$mode = 'enroll_passkey';

	if ( $creds > 0 && $verified ) $mode = 'login_passkey';
	elseif ( $verified && ! $is_new && $creds < 1 ) $mode = 'verify_required';
	elseif ( ! $verified && ! $is_new ) $mode = $strict_now ? 'verify_required' : 'unverified_return';
	elseif ( ! $verified && $strict_now ) $mode = 'verify_required';

	if ( 'phone' === $type && 'verify_required' === $mode && ! pk_phone_provider_ready() ) {
		$mode = 'enroll_passkey';
	}

	if ( pk_is_privileged( $user_id ) && ( $creds < 1 || ! get_user_meta( $user_id, 'pk_admin_password_bound_at', true ) || get_user_meta( $user_id, 'pk_force_reenroll', true ) ) ) $mode = 'privileged_setup';
	elseif ( $creds > 0 && $verified && ! $known_device ) $mode = 'new_device_verify';

	$dual_stage = '';
	if ( $dual_identifier && ! pk_is_privileged( $user_id ) ) {
		$email_verified = pk_factor_verified( $user_id, 'email' );
		$phone_factor = pk_factor( $user_id, 'phone', pk_hmac( $pending_phone ) );
		$phone_verified = $phone_factor && 'verified' === $phone_factor['status'];
		if ( ! $email_verified || ! $phone_verified ) {
			// A fresh email proof is required before attaching or replacing a phone on an existing account.
			$dual_stage = 'email';
			$mode = 'verify_required';
		}
	}

	$flow_meta = array(
		'anchor_hash' => pk_hmac( $identifier ),
		'anchor_type' => $type,
		'identifier'  => $identifier,
		'is_new'      => $is_new ? 1 : 0,
		'can_later'   => $grace_available ? 1 : 0,
		'mode'        => $mode,
		'completion_intent' => $completion_intent ? 1 : 0,
	);
	if ( $pending_wordpress_owner ) $flow_meta['wordpress_pending_email_change'] = 1;
	if ( $dual_identifier ) {
		$flow_meta['dual_identifier'] = 1;
		$flow_meta['dual_stage'] = $dual_stage;
		$flow_meta['pending_phone'] = pk_encrypt( $pending_phone );
	}
	$flow = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $flow_meta );

	$email_accepted = null;
	if ( 'email' === $type && ( $is_new || in_array( $mode, array( 'verify_required', 'unverified_return', 'new_device_verify' ), true ) ) && ( ! $dual_identifier || 'email' === $dual_stage ) && pk_otp_target_allowed( $identifier ) ) {
		$email_delivery = pk_send_email_otp_or_link( $user_id, $identifier, $flow, ( $dual_identifier || 'new_device_verify' === $mode ) ? 'otp' : '' );
		$email_accepted = ! empty( $email_delivery['accepted'] );
	}
	$phone_delivery = null;
	$phone_target = $dual_identifier ? $pending_phone : $identifier;
	$send_phone = ( 'phone' === $type && in_array( $mode, array( 'verify_required', 'new_device_verify' ), true ) ) || ( $dual_identifier && 'phone' === $dual_stage );
	if ( $send_phone && pk_phone_provider_ready() && pk_otp_target_allowed( $phone_target ) ) {
		update_user_meta( $user_id, 'pk_phone_hash', pk_hmac( $phone_target ) );
		update_user_meta( $user_id, 'pk_phone_last4', substr( preg_replace( '/[^0-9]/', '', $phone_target ), -4 ) );
		pk_upsert_factor( $user_id, 'phone', 'pending', pk_hmac( $phone_target ), array( 'last4' => substr( preg_replace( '/[^0-9]/', '', $phone_target ), -4 ) ), $phone_target );
		$phone_delivery = pk_send_phone_otp( $user_id, $phone_target, $flow );
		if ( empty( $phone_delivery['ok'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'phone_delivery_failed', 'message' => $phone_delivery['message'] ?? 'The phone code could not be delivered. Try again shortly.' ), 503 );
		}
	}

	$name = $user ? ( $user->display_name ?: $user->user_login ) : '';
	$backup = pk_factor( $user_id, 'backup_code', 'backup' );
	$response_type = $dual_stage ?: $type;
	$response_identifier = 'phone' === $response_type ? 'phone ending ' . substr( preg_replace( '/[^0-9]/', '', $phone_target ), -4 ) : $identifier;
	$email_delivery_mode = ( $dual_identifier || 'new_device_verify' === $mode ) ? 'otp' : $s['email_delivery'];
	$otp_dispatched = ( is_array( $phone_delivery ) && ! empty( $phone_delivery['ok'] ) )
		|| ( true === $email_accepted && 'otp' === $email_delivery_mode );
	$response = array(
		'ok'           => true,
		'token'        => $flow,
		'mode'         => $mode,
		'displayName'  => $known_device ? $name : '',
		'identifier'   => $response_identifier,
		'identifierType' => $response_type,
		'dualIdentifier' => $dual_identifier,
		'dualStage'     => $dual_stage,
		'knownDevice'   => $known_device,
		'verified'     => $known_device ? $verified : false,
		'hasPasskey'   => $known_device ? ( $creds > 0 ) : false,
		'canLater'     => $grace_available && ! $strict_now,
		'isNew'        => $is_new,
		'autoContinue' => $is_new && $grace_available && ! $strict_now && ! pk_is_privileged( $user_id ) && ! empty( $s['low_friction_signup'] ),
		'phoneReady'   => pk_phone_provider_ready(),
		'phoneDeliveryProvider' => is_array( $phone_delivery ) ? sanitize_key( $phone_delivery['provider'] ?? '' ) : '',
		'phoneDeliveryMessage' => is_array( $phone_delivery ) ? sanitize_text_field( $phone_delivery['message'] ?? '' ) : '',
		'emailDelivery'=> $email_delivery_mode,
		'emailAccepted'=> $email_accepted,
		'resendAfter'  => $otp_dispatched ? 60 : 0,
		'canEmailRecovery' => (bool) ( $user && $user->user_email && pk_factor_verified( $user_id, 'email' ) ),
		'hasTotp'      => $known_device && (bool) pk_totp_factor( $user_id ),
		'hasBackup'    => $known_device && (bool) $backup,
		'returnMessage'=> str_replace( array( '{name}', '{site}' ), array( $known_device ? $name : '', get_bloginfo( 'name' ) ), $s['return_message'] ),
		'security'     => pk_security_completion( $user_id ),
	);
	return rest_ensure_response( $response );
}

function pk_flow_user( $token ) {
	$row = pk_token_row( 'flow', $token, false );
	if ( ! $row ) return array( 0, null, array() );
	return array( (int) $row['user_id'], $row, $row['meta_arr'] );
}

/**
 * Resolve the factor awaiting proof from server-owned flow metadata.
 *
 * The browser may render stale UI after a counterpart or dual-identifier
 * transition. It must never be trusted to choose which challenge is verified.
 */
function pk_flow_verification_type( $meta ) {
	$mode = sanitize_key( $meta['mode'] ?? '' );
	if ( 'counterpart_verify' === $mode ) {
		$type = sanitize_key( $meta['counterpart_type'] ?? '' );
		if ( in_array( $type, array( 'email', 'phone' ), true ) ) return $type;
	}

	$type = sanitize_key( $meta['dual_stage'] ?? '' );
	if ( in_array( $type, array( 'email', 'phone' ), true ) ) return $type;

	$type = sanitize_key( $meta['anchor_type'] ?? '' );
	return in_array( $type, array( 'email', 'phone' ), true ) ? $type : '';
}

function pk_rest_verify_code( WP_REST_Request $r ) {
	$flow_token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id, $flow, $meta ) = pk_flow_user( $flow_token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );

	$type = pk_flow_verification_type( $meta );
	if ( 'phone' === $type ) return pk_rest_verify_phone( $r );
	if ( 'email' === $type ) return pk_rest_verify_email( $r );

	return new WP_REST_Response( array( 'ok' => false, 'message' => 'This verification session has no active code channel.' ), 400 );
}

function pk_flow_admin_password_verified( $meta ) {
	$verified_at = absint( $meta['admin_password_verified_at'] ?? 0 );
	$ip_hash = sanitize_text_field( $meta['admin_password_ip_hash'] ?? '' );
	return $verified_at > ( time() - 5 * MINUTE_IN_SECONDS )
		&& '' !== $ip_hash
		&& hash_equals( $ip_hash, pk_hmac( pk_ip() ) );
}

function pk_flow_device_recovery_verified( $meta ) {
	$verified_at = absint( $meta['device_recovery_verified_at'] ?? 0 );
	$ip_hash = sanitize_text_field( $meta['device_recovery_ip_hash'] ?? '' );
	return $verified_at > ( time() - 5 * MINUTE_IN_SECONDS )
		&& '' !== $ip_hash
		&& hash_equals( $ip_hash, pk_hmac( pk_ip() ) );
}

function pk_rest_verify_email( WP_REST_Request $r ) {
	$flow_token = sanitize_text_field( $r->get_param( 'token' ) );
	$code = preg_replace( '/[^0-9]/', '', (string) $r->get_param( 'code' ) );
	list( $user_id, $flow, $flow_meta ) = pk_flow_user( $flow_token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	if ( 6 !== strlen( $code ) || ! pk_rate_limit( 'otp_verify_email|' . $user_id, 8, 600 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many code attempts. Request a new code shortly.' ), 429 );
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . pk_t( 'challenges' ) . " WHERE purpose='email_otp' AND user_id=%d AND used=0 AND expires_at>%s ORDER BY id DESC LIMIT 1", $user_id, pk_now() ), ARRAY_A );
	if ( ! $row ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'No active email code found. Try sending recovery.' ), 400 );
	$meta = pk_meta_decode( $row['meta'] ?? '' );
	if ( empty( $meta['flow_token'] ) || ! hash_equals( (string) $meta['flow_token'], $flow_token ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This code belongs to a different sign-in session.' ), 403 );
	if ( ! hash_equals( $meta['otp_hash'] ?? '', pk_hmac( $code ) ) ) {
		pk_log( 'otp_failure', $user_id, array( 'factor' => 'email' ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'That code was not accepted.' ), 403 );
	}
	$wpdb->update( pk_t( 'challenges' ), array( 'used' => 1, 'used_at' => pk_now() ), array( 'id' => (int) $row['id'] ) );
	$user = get_userdata( $user_id );
	$verified_email = $user ? $user->user_email : '';
	if ( 'counterpart_verify' === ( $flow_meta['mode'] ?? '' ) && 'email' === ( $flow_meta['counterpart_type'] ?? '' ) ) {
		$verified_email = pk_normalize_email( pk_decrypt( (string) ( $flow_meta['counterpart_identifier'] ?? '' ) ) );
	}
	pk_mark_factor_verified( $user_id, 'email', $verified_email );
	$counterpart_user_id = pk_complete_counterpart_flow( $user_id, $flow_meta, 'email' );
	if ( is_wp_error( $counterpart_user_id ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => $counterpart_user_id->get_error_message() ), 409 );
	if ( $counterpart_user_id ) {
		$next_meta = array_merge( $flow_meta, array( 'identity_verified_this_flow' => 1, 'skip_counterpart_prompt' => 1 ) );
		$next_setup = pk_next_security_setup_response( $counterpart_user_id, $next_meta );
		if ( $next_setup ) return rest_ensure_response( $next_setup );
		return rest_ensure_response( pk_finish_login( $counterpart_user_id, 'counterpart_email_verified', true, array( 'skip_counterpart_prompt' => true ) ) );
	}
	if ( ! empty( $flow_meta['dual_identifier'] ) && ! empty( $flow_meta['pending_phone'] ) ) {
		$phone = pk_normalize_phone( pk_decrypt( (string) $flow_meta['pending_phone'] ) );
		$phone_digits = preg_replace( '/[^0-9]/', '', $phone );
		if ( strlen( $phone_digits ) < 7 ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'The phone step could not be resumed. Start sign-in again.' ), 400 );
		}
		$phone_factor = pk_factor( $user_id, 'phone', pk_hmac( $phone ) );
		if ( ! $phone_factor || 'verified' !== $phone_factor['status'] ) {
			if ( ! pk_phone_provider_ready() ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => 'Email is verified, but phone delivery is unavailable. Ask the site administrator to configure Key.kiwe.' ), 503 );
			}
			$flow_meta['anchor_hash'] = pk_hmac( $phone );
			$flow_meta['anchor_type'] = 'phone';
			$flow_meta['identifier'] = $phone;
			$flow_meta['dual_stage'] = 'phone';
			$flow_meta['mode'] = 'verify_required';
			$phone_flow = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $flow_meta );
			update_user_meta( $user_id, 'pk_phone_hash', pk_hmac( $phone ) );
			update_user_meta( $user_id, 'pk_phone_last4', substr( $phone_digits, -4 ) );
			pk_upsert_factor( $user_id, 'phone', 'pending', pk_hmac( $phone ), array( 'last4' => substr( $phone_digits, -4 ) ), $phone );
			$sent = pk_send_phone_otp( $user_id, $phone, $phone_flow );
			if ( empty( $sent['ok'] ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => $sent['message'] ?? 'Email is verified, but the phone code could not be delivered.' ), 503 );
			}
			global $wpdb;
			$wpdb->update( pk_t( 'challenges' ), array( 'used' => 1, 'used_at' => pk_now() ), array( 'id' => (int) $flow['id'] ) );
			return rest_ensure_response( array(
				'ok' => true,
				'next' => 'verify_phone',
				'token' => $phone_flow,
				'identifier' => 'phone ending ' . substr( $phone_digits, -4 ),
				'identifierType' => 'phone',
				'resendAfter' => 60,
				'phoneDeliveryProvider' => sanitize_key( $sent['provider'] ?? 'phone' ),
				'phoneDeliveryMessage' => sanitize_text_field( $sent['message'] ?? '' ),
			) );
		}
	}
	if ( 'new_device_verify' === ( $flow_meta['mode'] ?? '' ) ) {
		$flow_meta['mode'] = 'device_passkey_enroll';
		$flow_meta['device_recovery_verified_at'] = time();
		$flow_meta['device_recovery_ip_hash'] = pk_hmac( pk_ip() );
		$enroll_token = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $flow_meta );
		pk_log( 'new_device_otp_verified', $user_id, array( 'factor' => 'email' ), 'success' );
		return rest_ensure_response( array( 'ok' => true, 'next' => 'enroll_passkey', 'token' => $enroll_token, 'newDevice' => true ) );
	}
	$flow_meta['identity_verified_this_flow'] = 1;
	$next_setup = pk_next_security_setup_response( $user_id, $flow_meta );
	if ( $next_setup ) return rest_ensure_response( $next_setup );
	return rest_ensure_response( array( 'ok' => true, 'next' => pk_credential_count( $user_id ) ? 'login_passkey' : 'enroll_passkey' ) );
}

function pk_rest_verify_phone( WP_REST_Request $r ) {
	$flow_token = sanitize_text_field( $r->get_param( 'token' ) );
	$code = preg_replace( '/[^0-9]/', '', (string) $r->get_param( 'code' ) );
	list( $user_id, $flow, $flow_meta ) = pk_flow_user( $flow_token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	if ( 6 !== strlen( $code ) || ! pk_rate_limit( 'otp_verify_phone|' . $user_id, 8, 600 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many code attempts. Request a new code shortly.' ), 429 );
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . pk_t( 'challenges' ) . " WHERE purpose='phone_otp' AND user_id=%d AND used=0 AND expires_at>%s ORDER BY id DESC LIMIT 1", $user_id, pk_now() ), ARRAY_A );
	if ( ! $row ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'No active phone code found.' ), 400 );
	$meta = pk_meta_decode( $row['meta'] ?? '' );
	if ( empty( $meta['flow_token'] ) || ! hash_equals( (string) $meta['flow_token'], $flow_token ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This code belongs to a different sign-in session.' ), 403 );
	if ( ! hash_equals( $meta['otp_hash'] ?? '', pk_hmac( $code ) ) ) {
		pk_log( 'otp_failure', $user_id, array( 'factor' => 'phone' ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'That code was not accepted.' ), 403 );
	}
	$wpdb->update( pk_t( 'challenges' ), array( 'used' => 1, 'used_at' => pk_now() ), array( 'id' => (int) $row['id'] ) );
	$f = pk_factor( $user_id, 'phone' );
	$fmeta = $f ? pk_meta_decode( $f['meta'] ?? '' ) : array();
	$verified_phone = (string) ( $fmeta['phone'] ?? '' );
	if ( '' === $verified_phone && $f && ! empty( $f['factor_value'] ) ) {
		$verified_phone = pk_decrypt( (string) $f['factor_value'] );
	}
	if ( 'counterpart_verify' === ( $flow_meta['mode'] ?? '' ) && 'phone' === ( $flow_meta['counterpart_type'] ?? '' ) ) {
		$verified_phone = pk_normalize_phone( pk_decrypt( (string) ( $flow_meta['counterpart_identifier'] ?? '' ) ) );
	}
	pk_mark_factor_verified( $user_id, 'phone', $verified_phone );
	$counterpart_user_id = pk_complete_counterpart_flow( $user_id, $flow_meta, 'phone' );
	if ( is_wp_error( $counterpart_user_id ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => $counterpart_user_id->get_error_message() ), 409 );
	if ( $counterpart_user_id ) {
		$next_meta = array_merge( $flow_meta, array( 'identity_verified_this_flow' => 1, 'skip_counterpart_prompt' => 1 ) );
		$next_setup = pk_next_security_setup_response( $counterpart_user_id, $next_meta );
		if ( $next_setup ) return rest_ensure_response( $next_setup );
		return rest_ensure_response( pk_finish_login( $counterpart_user_id, 'counterpart_phone_verified', true, array( 'skip_counterpart_prompt' => true ) ) );
	}
	if ( '' !== $verified_phone && class_exists( '\\DSA\\Commerce\\COD_Gate_Service' ) ) {
		\DSA\Commerce\COD_Gate_Service::confirm_phone_for_cod( pk_hmac( $verified_phone ) );
	}
	if ( 'new_device_verify' === ( $flow_meta['mode'] ?? '' ) ) {
		$flow_meta['mode'] = 'device_passkey_enroll';
		$flow_meta['device_recovery_verified_at'] = time();
		$flow_meta['device_recovery_ip_hash'] = pk_hmac( pk_ip() );
		$enroll_token = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $flow_meta );
		pk_log( 'new_device_otp_verified', $user_id, array( 'factor' => 'phone' ), 'success' );
		return rest_ensure_response( array( 'ok' => true, 'next' => 'enroll_passkey', 'token' => $enroll_token, 'newDevice' => true ) );
	}
	if ( 'bind_phone_required' === ( $flow_meta['mode'] ?? '' ) && pk_is_privileged( $user_id ) && pk_credential_count( $user_id ) > 0 ) {
		pk_flag_admin_enrollment( $user_id );
		if ( pk_step_up_required( $user_id ) ) {
			$login_token = pk_issue_token( 'stepup', $user_id, PK_FLOW_TTL, array( 'method' => 'admin_phone_bind' ) );
			return rest_ensure_response( array( 'ok' => true, 'requiresTotp' => true, 'loginToken' => $login_token, 'totpEnrolled' => (bool) pk_totp_factor( $user_id ) ) );
		}
		return rest_ensure_response( pk_finish_login( $user_id, 'admin_phone_bind', true ) );
	}
	$flow_meta['identity_verified_this_flow'] = 1;
	$next_setup = pk_next_security_setup_response( $user_id, $flow_meta );
	if ( $next_setup ) return rest_ensure_response( $next_setup );
	return rest_ensure_response( array( 'ok' => true, 'next' => pk_credential_count( $user_id ) ? 'login_passkey' : 'enroll_passkey' ) );
}

function pk_rest_resend_otp( WP_REST_Request $r ) {
	$flow_token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id, $flow, $meta ) = pk_flow_user( $flow_token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );

	$type = pk_flow_verification_type( $meta );
	if ( in_array( $type, array( 'email', 'phone' ), true ) ) {
		$resend_after = pk_otp_resend_after( $user_id, $type . '_otp' );
		if ( $resend_after > 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'resendAfter' => $resend_after, 'message' => 'Please wait before requesting another code.' ), 429 );
		}
	}
	if ( ! pk_rate_limit( 'otp_resend|' . $user_id, 4, 600 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many code requests. Try again in a few minutes.' ), 429 );
	if ( 'email' === $type ) {
		$user = get_userdata( $user_id );
		$email = pk_normalize_email( $meta['identifier'] ?? '' );
		if ( ! $email && $user && $user->user_email ) $email = strtolower( $user->user_email );
		if ( ! $email ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'No email is available for this session.' ), 400 );
		if ( ! pk_otp_target_allowed( $email ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many codes were sent to this address. Try again later.' ), 429 );
		$delivery = pk_send_email_otp_or_link( $user_id, $email, $flow_token, 'otp' );
		if ( empty( $delivery['accepted'] ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'WordPress could not hand this code to its mail transport. Ask the site administrator to check Kiwe Email and SMTP.' ), 503 );
		return rest_ensure_response( array( 'ok' => true, 'method' => 'otp', 'resendAfter' => 60, 'message' => 'A new code was sent if email delivery is available.' ) );
	}

	if ( 'phone' === $type ) {
		if ( ! pk_phone_provider_ready() ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Phone delivery is not configured.' ), 400 );
		$phone = sanitize_text_field( $meta['identifier'] ?? '' );
		if ( ! $phone && ! empty( $meta['pending_phone'] ) ) {
			$phone = pk_normalize_phone( pk_decrypt( (string) $meta['pending_phone'] ) );
		}
		if ( ! $phone ) {
			$f = pk_factor( $user_id, 'phone' );
			$fmeta = $f ? pk_meta_decode( $f['meta'] ?? '' ) : array();
			$phone = sanitize_text_field( $fmeta['phone'] ?? '' );
		}
		if ( ! $phone ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'No phone is available for this session.' ), 400 );
		if ( ! pk_otp_target_allowed( $phone ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many codes were sent to this number. Try again later.' ), 429 );
		$sent = pk_send_phone_otp( $user_id, $phone, $flow_token );
		if ( empty( $sent['ok'] ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => $sent['message'] ?? 'Phone delivery failed.' ), 400 );
		return rest_ensure_response( array( 'ok' => true, 'method' => 'otp', 'resendAfter' => 60, 'provider' => sanitize_key( $sent['provider'] ?? 'phone' ), 'message' => $sent['message'] ?? 'A new code was sent.' ) );
	}

	return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session cannot resend an OTP.' ), 400 );
}

function pk_rest_admin_password_verify( WP_REST_Request $r ) {
	$flow_token = sanitize_text_field( $r->get_param( 'token' ) );
	$password = (string) $r->get_param( 'password' );
	list( $user_id, $flow, $meta ) = pk_flow_user( $flow_token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	if ( ! pk_is_privileged( $user_id ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Password confirmation is only required for privileged accounts.' ), 400 );
	if ( ! pk_rate_limit( 'admin_password|' . $user_id, 6, 600 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many password attempts. Try again shortly.' ), 429 );

	$user = get_userdata( $user_id );
	if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
		pk_log( 'admin_password_failure', $user_id, array( 'mode' => sanitize_key( $meta['mode'] ?? '' ) ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'That password was not accepted.' ), 403 );
	}

	$meta['admin_password_verified_at'] = time();
	$meta['admin_password_ip_hash'] = pk_hmac( pk_ip() );
	update_user_meta( $user_id, 'pk_admin_password_bound_at', current_time( 'mysql' ) );
	delete_user_meta( $user_id, 'pk_force_reenroll' );
	pk_flag_admin_enrollment( $user_id );
	if ( ! pk_account_verified( $user_id ) ) {
		if ( $user->user_email && is_email( $user->user_email ) ) {
			$email_meta = array_merge( $meta, array(
				'mode' => 'privileged_email_verify',
				'anchor_type' => 'email',
				'identifier' => strtolower( $user->user_email ),
			) );
			$verified_flow = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $email_meta );
			$delivery = pk_send_email_otp_or_link( $user_id, strtolower( $user->user_email ), $verified_flow, 'otp' );
			if ( ! empty( $delivery['accepted'] ) ) {
				pk_log( 'admin_password_success', $user_id, array( 'mode' => 'email_verification' ), 'success' );
				return rest_ensure_response( array(
					'ok' => true,
					'token' => $verified_flow,
					'mode' => 'verify_required',
					'next' => 'verify_email',
					'identifierType' => 'email',
					'emailDelivery' => 'otp',
					'resendAfter' => 60,
					'verified' => false,
				) );
			}
		}

		$phone = pk_user_phone( $user_id );
		if ( $phone && pk_phone_provider_ready() && pk_otp_target_allowed( $phone ) ) {
			$phone_meta = array_merge( $meta, array(
				'mode' => 'privileged_phone_verify',
				'anchor_type' => 'phone',
				'identifier' => $phone,
			) );
			$verified_flow = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $phone_meta );
			$delivery = pk_send_phone_otp( $user_id, $phone, $verified_flow );
			if ( ! empty( $delivery['ok'] ) && 'email_fallback' !== ( $delivery['provider'] ?? '' ) ) {
				pk_log( 'admin_password_success', $user_id, array( 'mode' => 'phone_verification' ), 'success' );
				return rest_ensure_response( array(
					'ok' => true,
					'token' => $verified_flow,
					'mode' => 'verify_required',
					'next' => 'verify_phone',
					'identifierType' => 'phone',
					'emailDelivery' => 'otp',
					'phoneDeliveryProvider' => sanitize_key( $delivery['provider'] ?? 'phone' ),
					'phoneDeliveryMessage' => sanitize_text_field( $delivery['message'] ?? '' ),
					'resendAfter' => 60,
					'verified' => false,
				) );
			}
		}

		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Your password is correct, but neither recovery email nor phone verification could be delivered. Ask the site administrator to check Kiwe Email and Key.kiwe.' ), 503 );
	}
	$has_passkey = pk_credential_count( $user_id ) > 0;
	$meta['mode'] = $has_passkey ? 'login_passkey' : 'enroll_passkey';
	$verified_flow = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $meta );
	pk_log( 'admin_password_success', $user_id, array( 'mode' => $has_passkey ? 'passkey_assertion' : 'passkey_enrollment' ), 'success' );
	return rest_ensure_response( array(
		'ok' => true,
		'token' => $verified_flow,
		'mode' => $meta['mode'],
		'next' => $meta['mode'],
		'verified' => pk_account_verified( $user_id ),
	) );
}

function pk_merge_counterpart_accounts( $current_user_id, $owner_user_id, $counterpart_type ) {
	global $wpdb;
	$current_user_id = pk_canonical_user_id( $current_user_id );
	$owner_user_id = pk_canonical_user_id( $owner_user_id );
	if ( ! $current_user_id || ! $owner_user_id ) return new WP_Error( 'pk_merge_invalid', 'The account link could not be completed.' );
	if ( $current_user_id === $owner_user_id ) return $current_user_id;
	if ( pk_is_privileged( $current_user_id ) || pk_is_privileged( $owner_user_id ) ) {
		return new WP_Error( 'pk_merge_privileged', 'Accounts with publishing or administrator privileges cannot be merged automatically. Ask an administrator to review them.' );
	}

	$canonical = 'email' === $counterpart_type ? $owner_user_id : $current_user_id;
	$alias = $canonical === $current_user_id ? $owner_user_id : $current_user_id;
	$factors = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'factors' ) . ' WHERE user_id=%d ORDER BY id ASC', $alias ), ARRAY_A );
	foreach ( (array) $factors as $factor ) {
		$secret = ! empty( $factor['factor_value'] ) ? pk_decrypt( (string) $factor['factor_value'] ) : '';
		pk_upsert_factor(
			$canonical,
			(string) $factor['factor_type'],
			(string) $factor['status'],
			(string) $factor['factor_hash'],
			pk_meta_decode( $factor['meta'] ?? '' ),
			$secret
		);
	}
	$wpdb->update( pk_t( 'credentials' ), array( 'user_id' => $canonical ), array( 'user_id' => $alias ) );
	$wpdb->update( pk_t( 'trusted_devices' ), array( 'user_id' => $canonical ), array( 'user_id' => $alias ) );
	$phone = pk_factor( $canonical, 'phone' );
	if ( $phone ) {
		update_user_meta( $canonical, 'pk_phone_hash', (string) $phone['factor_hash'] );
		$phone_meta = pk_meta_decode( $phone['meta'] ?? '' );
		if ( ! empty( $phone_meta['last4'] ) ) update_user_meta( $canonical, 'pk_phone_last4', sanitize_text_field( $phone_meta['last4'] ) );
	}
	$canonical_user = get_userdata( $canonical );
	if ( $canonical_user && is_email( $canonical_user->user_email ) ) update_user_meta( $canonical, 'pk_email_hash', pk_hmac( strtolower( $canonical_user->user_email ) ) );
	$linked = array_filter( array_map( 'absint', (array) get_user_meta( $canonical, 'pk_linked_user_ids', true ) ) );
	$linked[] = $alias;
	update_user_meta( $canonical, 'pk_linked_user_ids', array_values( array_unique( $linked ) ) );
	update_user_meta( $alias, 'pk_merged_into', $canonical );
	delete_user_meta( $canonical, 'pk_counterpart_prompt_skipped_at' );
	pk_log( 'accounts_linked', $canonical, array( 'alias_user_id' => $alias, 'method' => 'verified_counterpart' ), 'success' );
	return $canonical;
}

function pk_complete_counterpart_flow( $user_id, $flow_meta, $verified_type ) {
	$type = sanitize_key( $flow_meta['counterpart_type'] ?? '' );
	if ( 'counterpart_verify' !== ( $flow_meta['mode'] ?? '' ) || $type !== $verified_type ) return 0;
	$identifier = pk_decrypt( (string) ( $flow_meta['counterpart_identifier'] ?? '' ) );
	$identifier = 'email' === $type ? pk_normalize_email( $identifier ) : pk_normalize_phone( $identifier );
	if ( ! $identifier ) return new WP_Error( 'pk_counterpart_expired', 'The recovery contact session expired. Start again.' );
	$owner = pk_find_user_raw( $identifier, $type );
	$expected_owner = absint( $flow_meta['counterpart_owner_id'] ?? 0 );
	if ( $owner && $owner !== $expected_owner && pk_canonical_user_id( $owner ) !== pk_canonical_user_id( $user_id ) ) {
		return new WP_Error( 'pk_counterpart_changed', 'That recovery contact changed accounts while you were verifying it. Start again.' );
	}
	if ( $owner && pk_canonical_user_id( $owner ) !== pk_canonical_user_id( $user_id ) ) {
		return pk_merge_counterpart_accounts( $user_id, $owner, $type );
	}
	if ( 'email' === $type ) {
		$updated = wp_update_user( array( 'ID' => (int) $user_id, 'user_email' => $identifier ) );
		if ( is_wp_error( $updated ) ) return $updated;
		update_user_meta( $user_id, 'pk_email_hash', pk_hmac( $identifier ) );
	} else {
		update_user_meta( $user_id, 'pk_phone_hash', pk_hmac( $identifier ) );
		update_user_meta( $user_id, 'pk_phone_last4', substr( preg_replace( '/[^0-9]/', '', $identifier ), -4 ) );
	}
	delete_user_meta( $user_id, 'pk_counterpart_prompt_skipped_at' );
	pk_log( 'counterpart_bound', $user_id, array( 'factor' => $type ), 'success' );
	return (int) $user_id;
}

/**
 * Start an OTP proof for an already-resolved WordPress user and factor.
 *
 * Account discovery, creation, linking, and privileged password proof remain
 * the caller's responsibility. This helper only owns the flow token, pending
 * factor, delivery channel, and resend contract.
 */
function pk_start_factor_otp( $user_id, $type, $identifier, $flow_meta = array() ) {
	$user_id = pk_canonical_user_id( absint( $user_id ) );
	$type = sanitize_key( $type );
	$identifier = 'email' === $type ? pk_normalize_email( $identifier ) : pk_normalize_phone( $identifier );
	if ( ! $user_id || ! in_array( $type, array( 'email', 'phone' ), true ) || ! $identifier ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Enter a valid email address or phone number.' ), 400 );
	}

	$next_meta = array_merge( (array) $flow_meta, array(
		'anchor_type' => $type,
		'identifier'  => $identifier,
	) );
	$next_token = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $next_meta );
	$factor_meta = 'phone' === $type
		? array( 'last4' => substr( preg_replace( '/[^0-9]/', '', $identifier ), -4 ) )
		: array();
	pk_upsert_factor( $user_id, $type, 'pending', pk_hmac( $identifier ), $factor_meta, $identifier );

	$response = array(
		'ok'             => true,
		'token'          => $next_token,
		'mode'           => sanitize_key( $next_meta['mode'] ?? 'verify_required' ),
		'next'           => 'verify_' . $type,
		'identifierType' => $type,
		'emailDelivery'  => 'otp',
		'resendAfter'    => 60,
	);

	if ( 'email' === $type ) {
		if ( ! pk_otp_target_allowed( $identifier ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many codes were sent to this address. Try again later.' ), 429 );
		}
		$delivery = pk_send_email_otp_or_link( $user_id, $identifier, $next_token, 'otp' );
		if ( empty( $delivery['accepted'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'The email verification code could not be sent.' ), 503 );
		}
		return $response;
	}

	if ( ! pk_phone_provider_ready() ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Phone verification is not configured. You can add it later.' ), 503 );
	}
	if ( ! pk_otp_target_allowed( $identifier ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many codes were sent to this number. Try again later.' ), 429 );
	}
	$delivery = pk_send_phone_otp( $user_id, $identifier, $next_token );
	if ( empty( $delivery['ok'] ) || 'email_fallback' === ( $delivery['provider'] ?? '' ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'The phone itself could not receive a code. You can add it later.' ), 503 );
	}
	$response['phoneDeliveryProvider'] = sanitize_key( $delivery['provider'] ?? 'phone' );
	$response['phoneDeliveryMessage'] = sanitize_text_field( $delivery['message'] ?? '' );
	return $response;
}

function pk_rest_account_factor_start( WP_REST_Request $r ) {
	$user_id = pk_canonical_user_id( get_current_user_id() );
	$type = sanitize_key( (string) $r->get_param( 'factor' ) );
	$input = sanitize_text_field( (string) $r->get_param( 'identifier' ) );
	$user = $user_id ? get_userdata( $user_id ) : null;

	if ( ! $user || ! in_array( $type, array( 'email', 'phone' ), true ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'This account factor is not available.' ), 400 );
	}
	if ( pk_is_privileged( $user_id ) ) {
		return new WP_REST_Response( array(
			'ok'      => false,
			'code'    => 'privileged_setup_required',
			'message' => 'Privileged accounts must continue through WordPress password confirmation and strict Key.kiwe setup.',
		), 403 );
	}

	if ( 'email' === $type ) {
		$identifier = pk_normalize_email( $input );
		$current_email = pk_normalize_email( $user->user_email );
		if ( ! $identifier || ! $current_email || ! hash_equals( $current_email, $identifier ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Save and confirm this email as your WordPress account email before verifying it.' ), 409 );
		}
	} else {
		$identifier = pk_normalize_phone( $input );
		if ( ! $identifier ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Enter a valid phone number including its country code.' ), 400 );
		}
		$owner = pk_find_user_raw( $identifier, 'phone' );
		if ( $owner && pk_canonical_user_id( $owner ) !== $user_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'That phone number already belongs to another account. Use the full Key.kiwe setup journey to resolve it safely.' ), 409 );
		}
	}

	$exact_factor = pk_factor( $user_id, $type, pk_hmac( $identifier ) );
	if ( $exact_factor && 'verified' === ( $exact_factor['status'] ?? '' ) ) {
		return rest_ensure_response( array( 'ok' => true, 'alreadyVerified' => true, 'identifierType' => $type ) );
	}
	if ( pk_factor_verified( $user_id, $type ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'This account already has a verified ' . $type . '. Use the account-change flow before replacing it.' ), 409 );
	}

	$result = pk_start_factor_otp( $user_id, $type, $identifier, array(
		'mode'              => 'profile_factor_verify',
		'completion_intent' => 1,
	) );
	if ( $result instanceof WP_REST_Response ) return $result;
	pk_log( 'profile_factor_verification_started', $user_id, array( 'factor' => $type ), 'info' );
	return rest_ensure_response( $result );
}

function pk_rest_counterpart_start( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	$input = sanitize_text_field( $r->get_param( 'identifier' ) );
	list( $user_id, $flow, $meta ) = pk_flow_user( $token );
	if ( ! $user_id || 'counterpart_prompt' !== ( $meta['mode'] ?? '' ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This recovery-contact session expired.' ), 403 );
	if ( pk_is_privileged( $user_id ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Privileged account recovery contacts use the strict setup flow.' ), 403 );
	$id = pk_detect_identifier( $input );
	$type = sanitize_key( $meta['counterpart_type'] ?? '' );
	if ( empty( $id['type'] ) || $id['type'] !== $type ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Enter a valid ' . $type . '.' ), 400 );
	$identifier = $id['value'];
	$owner = pk_find_user_raw( $identifier, $type );
	if ( $owner && pk_canonical_user_id( $owner ) !== pk_canonical_user_id( $user_id ) && ( pk_is_privileged( $owner ) || pk_is_privileged( $user_id ) ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'That contact belongs to a privileged account and cannot be merged automatically.' ), 409 );
	}
	$next_meta = array(
		'mode' => 'counterpart_verify',
		'counterpart_type' => $type,
		'counterpart_identifier' => pk_encrypt( $identifier ),
		'counterpart_owner_id' => absint( $owner ),
	);
	$result = pk_start_factor_otp( $user_id, $type, $identifier, $next_meta );
	if ( $result instanceof WP_REST_Response ) return $result;
	$result['next'] = 'verify_counterpart';
	return rest_ensure_response( $result );
}

function pk_rest_counterpart_skip( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id, $flow, $meta ) = pk_flow_user( $token );
	if ( ! $user_id || 'counterpart_prompt' !== ( $meta['mode'] ?? '' ) || pk_is_privileged( $user_id ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This action is not available.' ), 403 );
	update_user_meta( $user_id, 'pk_counterpart_prompt_skipped_at', current_time( 'mysql' ) );
	update_user_meta( $user_id, 'pk_security_setup_skipped_at', current_time( 'mysql' ) );
	pk_log( 'counterpart_prompt_skipped', $user_id, array( 'factor' => sanitize_key( $meta['counterpart_type'] ?? '' ) ), 'info' );
	return rest_ensure_response( pk_finish_login( $user_id, 'counterpart_skipped', false, array( 'skip_counterpart_prompt' => true ) ) );
}

function pk_rest_continue_later( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id, $flow, $meta ) = pk_flow_user( $token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	if ( pk_is_privileged( $user_id ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Privileged accounts must complete secure setup.' ), 403 );
	$authenticated_user = is_user_logged_in() && pk_canonical_user_id( get_current_user_id() ) === pk_canonical_user_id( $user_id );
	$verified_in_flow = ! empty( $meta['identity_verified_this_flow'] );
	if ( pk_account_verified( $user_id ) && ( $verified_in_flow || $authenticated_user ) ) {
		update_user_meta( $user_id, 'pk_security_setup_skipped_at', current_time( 'mysql' ) );
		if ( pk_missing_counterpart_type( $user_id ) ) {
			update_user_meta( $user_id, 'pk_counterpart_prompt_skipped_at', current_time( 'mysql' ) );
		}
		pk_log( 'security_setup_deferred', $user_id, array( 'state' => 'partial' ), 'info' );
		return rest_ensure_response( pk_finish_login( $user_id, 'verified_partial', false, array( 'skip_counterpart_prompt' => true ) ) );
	}
	$grace_used = (bool) get_user_meta( $user_id, 'pk_initial_unverified_login_used_at', true );
	if ( empty( $meta['is_new'] ) || empty( $meta['can_later'] ) || $grace_used ) {
		pk_log( 'continue_later_blocked', $user_id, array( 'reason' => 'first_login_grace_exhausted' ), 'warning' );
		return new WP_REST_Response( array(
			'ok'              => false,
			'factor_required' => true,
			'next'            => 'verify_required',
			'message'         => 'This account has used its one-time setup grace. Verify an email address or phone number to continue.',
		), 403 );
	}
	if ( pk_account_verified( $user_id ) || pk_force_factor_for_account( $user_id ) ) {
		pk_log( 'continue_later_blocked', $user_id, array( 'reason' => 'factor_required' ), 'warning' );
		return new WP_REST_Response( array(
			'ok'              => false,
			'factor_required' => true,
			'next'            => pk_credential_count( $user_id ) ? 'login_passkey' : 'verify_required',
			'message'         => 'Please confirm it is you to continue.',
		), 403 );
	}
	update_user_meta( $user_id, 'pk_initial_unverified_login_used_at', current_time( 'mysql' ) );
	pk_log( 'unverified_continue_later', $user_id, array(), 'info' );
	return rest_ensure_response( pk_finish_login( $user_id, 'lenient_unverified', false ) );
}

function pk_rest_bind_phone( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	$phone = pk_normalize_phone( sanitize_text_field( $r->get_param( 'phone' ) ) );
	list( $user_id, $flow, $flow_meta ) = pk_flow_user( $token );
	if ( ! $user_id || ! $phone ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Could not bind phone.' ), 400 );
	if ( pk_phone_provider_ready() && ! pk_otp_target_allowed( $phone ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many codes were sent to this number. Try again later.' ), 429 );
	$flow_meta['anchor_type'] = 'phone';
	$flow_meta['identifier'] = $phone;
	unset( $flow_meta['dual_stage'] );
	$verification_token = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $flow_meta );
	update_user_meta( $user_id, 'pk_phone_hash', pk_hmac( $phone ) );
	update_user_meta( $user_id, 'pk_phone_last4', substr( preg_replace( '/[^0-9]/', '', $phone ), -4 ) );
	$sent = pk_phone_provider_ready() ? pk_send_phone_otp( $user_id, $phone, $verification_token ) : array( 'ok' => false );
	$phone_proof_dispatched = ! empty( $sent['ok'] ) && 'email_fallback' !== ( $sent['provider'] ?? '' );
	$status = $phone_proof_dispatched ? 'otp_sent' : 'pending';
	pk_upsert_factor( $user_id, 'phone', $status, pk_hmac( $phone ), array( 'phone' => $phone, 'provider_ready' => pk_phone_provider_ready() ? 1 : 0, 'otp_dispatched' => $phone_proof_dispatched ? 1 : 0 ) );
	pk_log( 'phone_bound', $user_id, array( 'provider_ready' => pk_phone_provider_ready() ? 1 : 0, 'otp_dispatched' => $phone_proof_dispatched ? 1 : 0 ), 'info' );
	if ( 'bind_phone_required' === ( $flow_meta['mode'] ?? '' ) && pk_is_privileged( $user_id ) && ! $phone_proof_dispatched && ! pk_account_verified( $user_id ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'The phone number is saved, but this admin account must verify its email or phone before access can continue.' ), 503 );
	}
	if ( 'bind_phone_required' === ( $flow_meta['mode'] ?? '' ) && pk_is_privileged( $user_id ) && ! $phone_proof_dispatched && pk_credential_count( $user_id ) > 0 ) {
		if ( pk_step_up_required( $user_id ) ) {
			$login_token = pk_issue_token( 'stepup', $user_id, PK_FLOW_TTL, array( 'method' => 'admin_phone_pending' ) );
			return rest_ensure_response( array( 'ok' => true, 'requiresTotp' => true, 'loginToken' => $login_token, 'totpEnrolled' => (bool) pk_totp_factor( $user_id ) ) );
		}
		return rest_ensure_response( pk_finish_login( $user_id, 'admin_phone_pending', true, array( 'pending_phone_verification' => true ) ) );
	}
	return rest_ensure_response( array(
		'ok' => true,
		'token' => $verification_token,
		'identifierType' => 'phone',
		'phoneReady' => pk_phone_provider_ready(),
		'otpDispatched' => $phone_proof_dispatched,
		'resendAfter' => $phone_proof_dispatched ? 60 : 0,
	) );
}

function pk_privileged_bind_phone_response( $user_id, $flow_meta = array() ) {
	$user_id = absint( $user_id );
	if ( ! $user_id || ! pk_is_privileged( $user_id ) || pk_admin_phone_bound( $user_id ) ) return array();
	$bind_meta = array_merge( (array) $flow_meta, array( 'mode' => 'bind_phone_required' ) );
	$bind_token = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $bind_meta );
	pk_log( 'admin_phone_required', $user_id, array( 'phone_ready' => pk_phone_provider_ready() ? 1 : 0 ), 'info' );
	return array(
		'ok'                => true,
		'bindPhoneRequired' => true,
		'token'             => $bind_token,
		'phoneReady'        => pk_phone_provider_ready(),
		'message'           => 'Add a phone number to finish securing this privileged WordPress account.',
	);
}

function pk_rest_webauthn_register_options( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id, $flow, $meta ) = pk_flow_user( $token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	$user = get_userdata( $user_id );
	if ( ! $user ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Account not found.' ), 404 );
	if ( pk_is_privileged( $user_id ) && ! pk_flow_admin_password_verified( $meta ) && ! pk_flow_device_recovery_verified( $meta ) ) {
		pk_log( 'admin_passkey_enrollment_blocked', $user_id, array( 'reason' => 'password_required' ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Enter your WordPress admin password before setting up a passkey.' ), 403 );
	}
	$challenge = pk_rand_token( 32 );
	$reg_token = pk_issue_token( 'webauthn_register', $user_id, PK_CHALLENGE_TTL, array( 'flow_token' => $token ), $challenge );
	$exclude = array();
	foreach ( pk_credentials_for_user( $user_id ) as $c ) $exclude[] = array( 'type' => 'public-key', 'id' => $c['credential_id'] );
	return rest_ensure_response( array(
		'ok' => true,
		'token' => $reg_token,
		'publicKey' => array(
			'challenge' => $challenge,
			'rp' => array( 'name' => get_bloginfo( 'name' ), 'id' => pk_rp_id() ),
			'user' => array( 'id' => pk_webauthn_user_id( $user_id ), 'name' => $user->user_email ?: $user->user_login, 'displayName' => $user->display_name ?: $user->user_login ),
			'pubKeyCredParams' => array( array( 'type' => 'public-key', 'alg' => -7 ), array( 'type' => 'public-key', 'alg' => -257 ) ),
			'timeout' => 60000,
			'attestation' => 'none',
			'excludeCredentials' => $exclude,
			'authenticatorSelection' => array( 'residentKey' => 'preferred', 'userVerification' => pk_is_privileged( $user_id ) ? 'required' : 'preferred' ),
		),
	) );
}

function pk_rest_webauthn_register_verify( WP_REST_Request $r ) {
	global $wpdb;
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	$row = pk_token_row( 'webauthn_register', $token, true );
	if ( ! $row ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Passkey setup expired.' ), 403 );
	$user_id = (int) $row['user_id'];
	$source_flow_meta = array();
	$source_flow_token = sanitize_text_field( $row['meta_arr']['flow_token'] ?? '' );
	if ( $source_flow_token ) {
		list( $source_user_id, $source_flow, $source_flow_meta ) = pk_flow_user( $source_flow_token );
		if ( (int) $source_user_id !== $user_id ) $source_flow_meta = array();
	}
	$cred = (array) $r->get_param( 'credential' );
	try {
		$response = (array) ( $cred['response'] ?? array() );
		pk_verify_client_data( (string) ( $response['clientDataJSON'] ?? '' ), 'webauthn.create', $row['challenge'] );
		$parsed = pk_parse_registration( (string) ( $response['attestationObject'] ?? '' ) );
		if ( pk_is_privileged( $user_id ) && ! ( $parsed['flags'] & 0x04 ) ) throw new Exception( 'User verification missing' );
		$transports = isset( $response['transports'] ) ? (array) $response['transports'] : array();
		$wpdb->replace( pk_t( 'credentials' ), array(
			'user_id'         => $user_id,
			'credential_id'   => $parsed['credential_id'],
			'public_key_cose' => $parsed['public_cose'],
			'alg'             => $parsed['alg'],
			'sign_count'      => $parsed['sign_count'],
			'aaguid'          => $parsed['aaguid'],
			'transports'      => pk_json( array_values( array_map( 'sanitize_text_field', $transports ) ) ),
			'label'           => sanitize_text_field( $r->get_param( 'label' ) ?: 'Passkey' ),
			'created_at'      => pk_now(),
			'last_used_at'    => null,
		) );
		update_user_meta( $user_id, 'pk_webauthn_enrolled', current_time( 'mysql' ) );
		pk_log( 'passkey_enrolled', $user_id, array( 'alg' => $parsed['alg'] ), 'success' );
		if ( ! pk_account_verified( $user_id ) && ! pk_is_privileged( $user_id ) ) {
			return rest_ensure_response( pk_finish_login( $user_id, 'passkey_pending', true, array( 'pending_verification' => true ) ) );
		}
		$bind_phone = pk_privileged_bind_phone_response( $user_id, $source_flow_meta );
		if ( $bind_phone ) return rest_ensure_response( $bind_phone );
		if ( pk_is_privileged( $user_id ) && ! pk_admin_enrollment_complete( $user_id ) ) {
			pk_flag_admin_enrollment( $user_id );
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'privileged_setup_incomplete', 'message' => 'Verify a recovery email or phone before administrator access.' ), 403 );
		}
		if ( pk_step_up_required( $user_id ) ) {
			$login_token = pk_issue_token( 'stepup', $user_id, PK_FLOW_TTL, array( 'method' => 'passkey_register' ) );
			return rest_ensure_response( array( 'ok' => true, 'requiresTotp' => true, 'loginToken' => $login_token, 'totpEnrolled' => (bool) pk_totp_factor( $user_id ) ) );
		}
		return rest_ensure_response( pk_finish_login( $user_id, 'passkey_register', true ) );
	} catch ( Throwable $e ) {
		pk_log( 'passkey_failure', $user_id, array( 'stage' => 'register', 'error' => $e->getMessage() ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Passkey setup was not accepted.' ), 403 );
	}
}

function pk_rest_webauthn_login_options( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id, $flow, $flow_meta ) = pk_flow_user( $token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	$creds = pk_credentials_for_user( $user_id );
	if ( ! $creds ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'No passkey is enrolled.' ), 404 );
	$challenge = pk_rand_token( 32 );
	$login_token = pk_issue_token( 'webauthn_login', $user_id, PK_CHALLENGE_TTL, array(
		'flow_token' => $token,
		'completion_intent' => ! empty( $flow_meta['completion_intent'] ) ? 1 : 0,
	), $challenge );
	$allow = array();
	foreach ( $creds as $c ) {
		$allow[] = array( 'type' => 'public-key', 'id' => $c['credential_id'], 'transports' => json_decode( $c['transports'] ?: '[]', true ) ?: array() );
	}
	return rest_ensure_response( array(
		'ok' => true,
		'token' => $login_token,
		'publicKey' => array(
			'challenge' => $challenge,
			'timeout' => 60000,
			'rpId' => pk_rp_id(),
			'allowCredentials' => $allow,
			'userVerification' => pk_is_privileged( $user_id ) ? 'required' : 'preferred',
		),
	) );
}

function pk_rest_webauthn_login_verify( WP_REST_Request $r ) {
	global $wpdb;
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	$row = pk_token_row( 'webauthn_login', $token, true );
	if ( ! $row ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Passkey challenge expired.' ), 403 );
	$user_id = (int) $row['user_id'];
	$source_flow_meta = array();
	$source_flow_token = sanitize_text_field( $row['meta_arr']['flow_token'] ?? '' );
	if ( $source_flow_token ) {
		list( $source_user_id, $source_flow, $source_flow_meta ) = pk_flow_user( $source_flow_token );
		if ( (int) $source_user_id !== $user_id ) $source_flow_meta = array();
	}
	$cred = (array) $r->get_param( 'credential' );
	try {
		$raw_id = (string) ( $cred['rawId'] ?? $cred['id'] ?? '' );
		$db = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . pk_t( 'credentials' ) . " WHERE credential_id=%s AND user_id=%d LIMIT 1", $raw_id, $user_id ), ARRAY_A );
		if ( ! $db ) throw new Exception( 'Unknown credential' );
		$res = (array) ( $cred['response'] ?? array() );
		$client_json = pk_verify_client_data( (string) ( $res['clientDataJSON'] ?? '' ), 'webauthn.get', $row['challenge'] );
		$auth = pk_b64u_dec( (string) ( $res['authenticatorData'] ?? '' ) );
		if ( strlen( $auth ) < 37 ) throw new Exception( 'Bad authenticator data' );
		if ( ! hash_equals( hash( 'sha256', pk_rp_id(), true ), substr( $auth, 0, 32 ) ) ) throw new Exception( 'RP hash mismatch' );
		if ( ! ( ord( $auth[32] ) & 0x01 ) ) throw new Exception( 'User presence missing' );
		if ( pk_is_privileged( $user_id ) && ! ( ord( $auth[32] ) & 0x04 ) ) throw new Exception( 'User verification missing' );
		$count = unpack( 'N', substr( $auth, 33, 4 ) )[1];
		$old = (int) $db['sign_count'];
		if ( $count <= $old && ! ( 0 === $count && 0 === $old ) ) throw new Exception( 'Authenticator counter replay' );
		$signed = $auth . hash( 'sha256', $client_json, true );
		$pem = pk_cose_to_pem( $db['public_key_cose'] );
		if ( ! $pem ) throw new Exception( 'Unsupported public key' );
		$verify_alg = ( (int) $db['alg'] === -8 ) ? 0 : OPENSSL_ALGO_SHA256;
		$ok = openssl_verify( $signed, pk_b64u_dec( (string) ( $res['signature'] ?? '' ) ), $pem, $verify_alg );
		if ( 1 !== $ok ) throw new Exception( 'Bad signature' );
		$wpdb->update( pk_t( 'credentials' ), array( 'sign_count' => max( $old, $count ), 'last_used_at' => pk_now() ), array( 'id' => (int) $db['id'] ) );
		pk_log( 'passkey_success', $user_id, array( 'credential' => (int) $db['id'] ), 'success' );
		$bind_phone = pk_privileged_bind_phone_response( $user_id, $source_flow_meta );
		if ( $bind_phone ) return rest_ensure_response( $bind_phone );
		if ( pk_is_privileged( $user_id ) && ! pk_admin_enrollment_complete( $user_id ) ) {
			pk_flag_admin_enrollment( $user_id );
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'privileged_setup_incomplete', 'message' => 'Complete WordPress password confirmation and recovery verification before administrator access.' ), 403 );
		}
		if ( pk_step_up_required( $user_id ) ) {
			$login_token = pk_issue_token( 'stepup', $user_id, PK_FLOW_TTL, array( 'method' => 'passkey_login' ) );
			return rest_ensure_response( array( 'ok' => true, 'requiresTotp' => true, 'loginToken' => $login_token, 'totpEnrolled' => (bool) pk_totp_factor( $user_id ) ) );
		}
		if ( ! pk_is_privileged( $user_id ) && ! empty( $row['meta_arr']['completion_intent'] ) ) {
			$next_setup = pk_next_security_setup_response( $user_id, array( 'completion_intent' => 1, 'identity_verified_this_flow' => 1 ) );
			if ( $next_setup ) return rest_ensure_response( $next_setup );
		}
		return rest_ensure_response( pk_finish_login( $user_id, 'passkey_login', true ) );
	} catch ( Throwable $e ) {
		pk_log( 'passkey_failure', $user_id, array( 'stage' => 'login', 'error' => $e->getMessage() ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Passkey was not accepted.' ), 403 );
	}
}

function pk_rest_send_recovery( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id, $flow, $meta ) = pk_flow_user( $token );
	$user = $user_id ? get_userdata( $user_id ) : null;
	if ( ! $user ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	if ( ! $user->user_email || ! pk_factor_verified( $user_id, 'email' ) ) {
		pk_log( 'recovery_blocked', $user_id, array( 'reason' => 'no_verified_channel' ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Self-service recovery is not available. Contact the site admin.' ), 403 );
	}
	if ( ! pk_otp_target_allowed( $user->user_email ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many codes were sent to this address. Try again later.' ), 429 );
	$meta['anchor_type'] = 'email';
	$meta['identifier'] = strtolower( $user->user_email );
	unset( $meta['dual_identifier'], $meta['dual_stage'], $meta['pending_phone'], $meta['counterpart_type'], $meta['counterpart_identifier'], $meta['counterpart_owner_id'] );
	$verification_token = pk_issue_token( 'flow', $user_id, PK_FLOW_TTL, $meta );
	$delivery = pk_send_email_otp_or_link( $user_id, $user->user_email, $verification_token, 'otp' );
	if ( empty( $delivery['accepted'] ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'WordPress could not hand the recovery code to its mail transport.' ), 503 );
	pk_log( 'recovery_attempt', $user_id, array( 'channel' => 'email' ), 'info' );
	return rest_ensure_response( array( 'ok' => true, 'token' => $verification_token, 'identifierType' => 'email', 'resendAfter' => 60, 'message' => 'If recovery is available, a code has been sent.' ) );
}

function pk_rest_totp_login_verify( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	$row = pk_token_row( 'stepup', $token, true );
	if ( ! $row ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Step-up session expired.' ), 403 );
	$user_id = (int) $row['user_id'];
	if ( ! pk_rate_limit( 'totp_login|' . $user_id, 8, 600 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many authenticator attempts. Try again shortly.' ), 429 );
	$f = pk_totp_factor( $user_id );
	if ( ! $f ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Authenticator app is not enrolled.' ), 403 );
	if ( ! pk_totp_verify( $f['secret'], $r->get_param( 'code' ) ) ) {
		pk_log( 'totp_failure', $user_id, array(), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Authenticator code was not accepted.' ), 403 );
	}
	pk_log( 'totp_success', $user_id, array(), 'success' );
	return rest_ensure_response( pk_finish_login( $user_id, 'passkey_totp', true ) );
}

function pk_rest_backup_login_verify( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	$row = pk_token_row( 'stepup', $token, true );
	if ( ! $row ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Step-up session expired.' ), 403 );
	$user_id = (int) $row['user_id'];
	if ( ! pk_rate_limit( 'backup_login|' . $user_id, 6, 900 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many backup-code attempts. Try again later.' ), 429 );
	if ( ! pk_backup_code_consume( $user_id, sanitize_text_field( $r->get_param( 'code' ) ) ) ) {
		pk_log( 'backup_code_failure', $user_id, array(), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Backup code was not accepted.' ), 403 );
	}
	return rest_ensure_response( pk_finish_login( $user_id, 'passkey_backup_code', true ) );
}

function pk_rest_totp_recovery_verify( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id ) = pk_flow_user( $token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	if ( ! pk_rate_limit( 'totp_recovery|' . $user_id, 8, 600 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many authenticator attempts. Try again shortly.' ), 429 );
	$f = pk_totp_factor( $user_id );
	if ( ! $f || ! pk_totp_verify( $f['secret'], $r->get_param( 'code' ) ) ) {
		pk_log( 'totp_failure', $user_id, array( 'recovery' => true ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Authenticator code was not accepted.' ), 403 );
	}
	pk_log( 'recovery_totp_success', $user_id, array(), 'success' );
	return rest_ensure_response( pk_finish_login( $user_id, 'totp_recovery', true ) );
}

function pk_rest_backup_recovery_verify( WP_REST_Request $r ) {
	$token = sanitize_text_field( $r->get_param( 'token' ) );
	list( $user_id ) = pk_flow_user( $token );
	if ( ! $user_id ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'This session expired.' ), 403 );
	if ( ! pk_rate_limit( 'backup_recovery|' . $user_id, 6, 900 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many backup-code attempts. Try again later.' ), 429 );
	if ( ! pk_backup_code_consume( $user_id, sanitize_text_field( $r->get_param( 'code' ) ) ) ) {
		pk_log( 'backup_code_failure', $user_id, array( 'recovery' => true ), 'warning' );
		return new WP_REST_Response( array( 'ok' => false, 'message' => 'Backup code was not accepted.' ), 403 );
	}
	return rest_ensure_response( pk_finish_login( $user_id, 'backup_code_recovery', true ) );
}

function pk_rest_session_status() {
	$user_id = get_current_user_id();
	$policy = pk_role_policy( $user_id );
	$timeout = absint( $policy['timeout'] ?? 0 );
	$admin_reauth = absint( pk_settings()['admin_reauth_minutes'] ?? 0 );
	if ( pk_is_privileged( $user_id ) && $admin_reauth > 0 ) $timeout = max( $timeout, $admin_reauth );
	$start = (int) get_user_meta( $user_id, 'pk_session_started_at', true );
	return rest_ensure_response( array( 'ok' => true, 'timeout' => $timeout, 'elapsed' => $start ? time() - $start : 0 ) );
}

function pk_rest_account_totp_start() {
	$user_id = get_current_user_id();
	$secret = pk_base32_encode( random_bytes( 20 ) );
	pk_upsert_factor( $user_id, 'totp', 'pending', 'totp', array(), $secret );
	$user = wp_get_current_user();
	$issuer = rawurlencode( get_bloginfo( 'name' ) );
	$label = rawurlencode( get_bloginfo( 'name' ) . ':' . ( $user->user_email ?: $user->user_login ) );
	$uri = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
	return rest_ensure_response( array( 'ok' => true, 'secret' => $secret, 'otpauth' => $uri ) );
}

function pk_rest_account_totp_verify( WP_REST_Request $r ) {
	$user_id = get_current_user_id();
	if ( ! pk_rate_limit( 'totp_enroll|' . $user_id, 8, 600 ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many authenticator attempts. Try again shortly.' ), 429 );
	$f = pk_totp_factor( $user_id );
	if ( ! $f || ! pk_totp_verify( $f['secret'], $r->get_param( 'code' ) ) ) return new WP_REST_Response( array( 'ok' => false, 'message' => 'Code was not accepted.' ), 403 );
	pk_upsert_factor( $user_id, 'totp', 'verified', 'totp', array( 'verified_by' => 'user' ), $f['secret'] );
	update_user_meta( $user_id, 'pk_totp_enrolled', current_time( 'mysql' ) );
	pk_log( 'totp_enrolled', $user_id, array(), 'success' );
	return rest_ensure_response( array( 'ok' => true ) );
}

function pk_rest_account_backup_regenerate() {
	return rest_ensure_response( array( 'ok' => true, 'codes' => pk_backup_codes_generate( get_current_user_id() ) ) );
}

/* ============================================================
   Visitor identity helpers and account shortcode
   ============================================================ */

function pk_ensure_visitor_cookie() {
	if ( is_admin() || headers_sent() || is_user_logged_in() ) return;
	if ( empty( $_COOKIE[ PK_VISITOR_COOKIE ] ) ) {
		setcookie( PK_VISITOR_COOKIE, pk_rand_token( 18 ), array(
			'expires'  => time() + YEAR_IN_SECONDS,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}
}

function pk_visitor_hash() {
	$v = sanitize_text_field( wp_unslash( $_COOKIE[ PK_VISITOR_COOKIE ] ?? '' ) );
	if ( ! $v ) $v = pk_ip() . '|' . pk_ua();
	return pk_hmac( $v );
}

add_shortcode( 'phonekey_account', function () {
	if ( ! is_user_logged_in() ) return '<p>Please log in to manage Kiwe Auth security.</p>';
	$user_id = get_current_user_id();
	$creds = pk_credential_count( $user_id );
	$totp = pk_totp_factor( $user_id );
	ob_start();
	?>
	<div class="pk-account">
		<h2>Kiwe Auth Security</h2>
		<p>Passkeys: <?php echo esc_html( $creds ); ?> enrolled</p>
		<p>Authenticator app: <?php echo $totp && 'verified' === $totp['status'] ? 'Active' : 'Not active'; ?></p>
		<button type="button" id="pk-totp-start">Set up authenticator app</button>
		<button type="button" id="pk-backup-gen">Regenerate backup codes</button>
		<pre id="pk-account-out" style="white-space:pre-wrap"></pre>
	</div>
	<script>
	(function(){
	var out=document.getElementById('pk-account-out'), rest=<?php echo wp_json_encode( esc_url_raw( get_rest_url( null, PK_NS . '/' ) ) ); ?>, nonce=<?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
	function post(p,d){return fetch(rest+p,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce,'X-Kiwe-Mutation':'1'},credentials:'same-origin',body:JSON.stringify(d||{})}).then(function(r){return r.json();});}
	document.getElementById('pk-totp-start').onclick=function(){post('account/totp/start',{}).then(function(r){out.textContent='Secret: '+r.secret+"\n\nURI:\n"+r.otpauth+"\n\nAdd this URI to your authenticator app, then verify from the popup/login flow.";});};
	document.getElementById('pk-backup-gen').onclick=function(){post('account/backup/regenerate',{}).then(function(r){out.textContent='Save these backup codes now. They are shown once:\n\n'+(r.codes||[]).join("\n");});};
	})();
	</script>
	<?php
	return ob_get_clean();
} );

add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) return;
	echo '<script>(function(){var rest=' . wp_json_encode( esc_url_raw( get_rest_url( null, PK_NS . '/' ) ) ) . ',nonce=' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';setInterval(function(){fetch(rest+"session/status",{headers:{"X-WP-Nonce":nonce},credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(j.timeout&&j.elapsed>j.timeout*60) location.href=' . wp_json_encode( wp_login_url() ) . ';}).catch(function(){});},60000);})();</script>';
}, 100 );

/* ============================================================
   Admin UI and actions
   ============================================================ */

if ( ! defined( 'KIWE_AUTH_ADMIN_UI' ) || ! KIWE_AUTH_ADMIN_UI ) {
	add_action( 'admin_menu', function () {
		add_menu_page( 'Key.kiwe', 'Key.kiwe', pk_admin_cap(), 'phonekey', 'pk_admin_page', 'dashicons-shield-alt', 58 );
	} );
}

add_action( 'admin_post_pk_save_settings', function () {
	if ( ! current_user_can( pk_admin_cap() ) ) pk_die( 'Unauthorized', 403 );
	check_admin_referer( 'pk_save_settings' );
	pk_save_settings_array( wp_unslash( $_POST['pk'] ?? array() ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'kiwe-auth', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
} );

add_action( 'admin_post_pk_admin_action', function () {
	if ( ! current_user_can( pk_admin_cap() ) ) pk_die( 'Unauthorized', 403 );
	check_admin_referer( 'pk_admin_action' );
	$act = sanitize_key( $_GET['pk_act'] ?? '' );
	$user_id = absint( $_GET['user_id'] ?? 0 );
	if ( ! $user_id ) pk_die( 'Missing user', 400 );
	global $wpdb;
	if ( 'verify' === $act ) {
		$user = get_userdata( $user_id );
		if ( $user && $user->user_email ) pk_mark_factor_verified( $user_id, 'email', $user->user_email );
		update_user_meta( $user_id, 'pk_verified_at', current_time( 'mysql' ) );
		pk_log( 'admin_manual_verify', $user_id, array( 'admin_id' => get_current_user_id() ), 'warning' );
	}
	if ( 'revoke_passkeys' === $act ) {
		$wpdb->delete( pk_t( 'credentials' ), array( 'user_id' => $user_id ) );
		delete_user_meta( $user_id, 'pk_webauthn_enrolled' );
		delete_user_meta( $user_id, 'pk_admin_password_bound_at' );
		update_user_meta( $user_id, 'pk_force_reenroll', 1 );
		pk_log( 'admin_revoke_passkeys', $user_id, array( 'admin_id' => get_current_user_id() ), 'warning' );
	}
	if ( 'reset_totp' === $act ) {
		$wpdb->delete( pk_t( 'factors' ), array( 'user_id' => $user_id, 'factor_type' => 'totp' ) );
		delete_user_meta( $user_id, 'pk_totp_enrolled' );
		pk_log( 'admin_reset_totp', $user_id, array( 'admin_id' => get_current_user_id() ), 'warning' );
	}
	if ( 'revoke_devices' === $act ) {
		$wpdb->delete( pk_t( 'trusted_devices' ), array( 'user_id' => $user_id ) );
		pk_log( 'admin_revoke_devices', $user_id, array( 'admin_id' => get_current_user_id() ), 'warning' );
	}
	if ( 'backup_codes' === $act ) {
		pk_backup_codes_generate( $user_id );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'kiwe-auth', 'tab' => 'users', 'done' => $act ), admin_url( 'admin.php' ) ) );
	exit;
} );

function pk_admin_page() {
	if ( ! current_user_can( pk_admin_cap() ) ) pk_die( 'Unauthorized', 403 );
	$s = pk_settings();
	$tab = sanitize_key( $_GET['tab'] ?? 'settings' );
	echo '<div class="wrap pk-admin"><h1>Kiwe Auth</h1>';
	if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Kiwe Auth settings saved.</p></div>';
	pk_admin_notices_inline( $s );
	echo '<h2 class="nav-tab-wrapper"><a class="nav-tab ' . ( 'settings' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=kiwe-auth' ) ) . '">Settings</a><a class="nav-tab ' . ( 'users' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=kiwe-auth&tab=users' ) ) . '">Users</a><a class="nav-tab ' . ( 'activity' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=kiwe-auth&tab=activity' ) ) . '">Activity</a></h2>';
	if ( 'users' === $tab ) pk_admin_users();
	elseif ( 'activity' === $tab ) pk_admin_activity();
	else pk_admin_settings( $s );
	echo '<style>.pk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.pk-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}.pk-card h2{margin-top:0}.pk-matrix th,.pk-matrix td{padding:7px}.pk-status{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef2ff}.pk-good{background:#dcfce7}.pk-warn{background:#fef3c7}.pk-bad{background:#fee2e2}.pk-code{background:#f1f5f9;padding:2px 5px;border-radius:4px}</style>';
	echo '</div>';
}

function pk_admin_notices_inline( $s ) {
	$phone_modes = array( $s['identifier_mode'], $s['app_identifier_mode'] ?? $s['identifier_mode'] );
	if ( array_intersect( $phone_modes, array( 'phone', 'email_or_phone', 'email_and_phone' ) ) && ! pk_phone_provider_ready() ) {
		echo '<div class="notice notice-warning"><p><strong>Kiwe Auth:</strong> Phone identifiers are enabled, but no SMS or WhatsApp webhook is configured. Configure Key.kiwe before allowing phone verification.</p></div>';
	}
	$email_test = get_option( 'dsa_email_last_test', array() );
	if ( ! empty( $s['whatsapp_email_fallback'] ) && empty( $email_test['success'] ) ) {
		echo '<div class="notice notice-warning"><p><strong>Key.kiwe fallback:</strong> Email delivery has not passed a Kiwe test. <a href="' . esc_url( admin_url( 'admin.php?page=kiwe-email&tab=diagnostics' ) ) . '">Configure and test Kiwe Email</a> before client use.</p></div>';
	}
	if ( ! function_exists( 'openssl_verify' ) ) {
		echo '<div class="notice notice-error"><p><strong>Kiwe Auth:</strong> OpenSSL is required for passkey signature verification.</p></div>';
	}
	$mail_status = get_option( 'pk_last_mail_status', array() );
	if ( is_array( $mail_status ) && ! empty( $mail_status['at'] ) ) {
		$accepted = ! empty( $mail_status['accepted'] );
		$class = $accepted ? 'notice-info' : 'notice-error';
		$message = $accepted
			? 'The latest verification email was accepted by WordPress mail. This confirms handoff only; authenticated SMTP and provider delivery still need to be healthy.'
			: 'WordPress could not hand the latest verification email to its mail transport. Check Kiwe > Email and the site SMTP configuration before requiring email verification.';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>Kiwe Auth mail:</strong> ' . esc_html( $message ) . '</p></div>';
	}
}

function pk_admin_settings( $s ) {
	$roles = wp_roles()->roles;
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pk_save_settings">
		<?php wp_nonce_field( 'pk_save_settings' ); ?>
		<div class="pk-grid">
			<div class="pk-card"><h2>Popup Behavior</h2>
				<p><label>Display<br><select name="pk[popup_display]"><?php pk_select_options( array( 'disabled'=>'Disabled', 'logged_out'=>'Logged-out visitors', 'new_visitors'=>'New visitors only', 'every_visit'=>'Every visit' ), $s['popup_display'] ); ?></select></label></p>
				<p><label>Dismissal<br><select name="pk[popup_dismissal]"><?php pk_select_options( array( 'outside_close'=>'Outside click + close', 'close_only'=>'Close button only', 'mandatory'=>'Mandatory gate' ), $s['popup_dismissal'] ); ?></select></label></p>
				<p><label>Mandatory scope<br><select name="pk[mandatory_scope]"><?php pk_select_options( array( 'all_public'=>'All public pages', 'patterns'=>'Only URL patterns below' ), $s['mandatory_scope'] ); ?></select></label></p>
				<p><label>Mandatory URL patterns<br><textarea name="pk[mandatory_patterns]" rows="4" class="large-text"><?php echo esc_textarea( $s['mandatory_patterns'] ); ?></textarea></label></p>
			</div>
			<div class="pk-card"><h2>Identifier And Signup</h2>
				<p><label>Website visitor identifier<br><select name="pk[identifier_mode]"><?php pk_select_options( array( 'email'=>'Email only', 'phone'=>'Phone only', 'email_or_phone'=>'Email or phone (one field)', 'email_and_phone'=>'Email + phone (two fields)' ), $s['identifier_mode'] ); ?></select></label></p>
				<p><label>Installed app visitor identifier<br><select name="pk[app_identifier_mode]"><?php pk_select_options( array( 'email'=>'Email only', 'phone'=>'Phone only', 'email_or_phone'=>'Email or phone (one field)', 'email_and_phone'=>'Email + phone (two fields)' ), $s['app_identifier_mode'] ?? $s['identifier_mode'] ); ?></select></label></p>
				<p class="description">Email + phone visibly collects both identifiers, verifies email first, then sends a WhatsApp or SMS code. Phone only and email-or-phone honor phone-first signup without forcing an email address.</p>
				<p><label>Installed app verification<br><select name="pk[app_verification_timing]"><?php pk_select_options( array( 'verify_now'=>'Verify on first app launch', 'verify_later'=>'Allow setup later', 'progressive'=>'Progressive after visits' ), $s['app_verification_timing'] ?? 'verify_now' ); ?></select></label><br><span class="description">Applies to non-privileged app visitors. Administrator and manager accounts still follow their role policy for password/passkey protection.</span></p>
				<p><label>Default country code<br><input name="pk[default_country_code]" value="<?php echo esc_attr( $s['default_country_code'] ); ?>"></label></p>
				<p><label>New signup role<br><select name="pk[signup_role]"><?php foreach ( $roles as $role => $info ) { if ( pk_role_is_high_privilege_role( $role ) ) continue; echo '<option value="' . esc_attr( $role ) . '" ' . selected( $s['signup_role'], $role, false ) . '>' . esc_html( translate_user_role( $info['name'] ) ) . '</option>'; } ?></select></label></p>
				<p><label><input type="checkbox" name="pk[low_friction_signup]" value="1" <?php checked( ! empty( $s['low_friction_signup'] ) ); ?>> One-step first subscriber signup</label><br><span class="description">A new low-risk account signs in immediately after entering its email or phone. Existing accounts still require proof, so knowing an identifier alone can never take over an account.</span></p>
			</div>
			<div class="pk-card"><h2>Verification</h2>
				<p><label>Signup timing<br><select name="pk[verification_timing]"><?php pk_select_options( array( 'verify_now'=>'Verify now', 'verify_later'=>'Verify later', 'progressive'=>'Progressive after visits' ), $s['verification_timing'] ); ?></select></label></p>
				<p><label><input type="checkbox" name="pk[purchase_verified_contact]" value="1" <?php checked( ! empty( $s['purchase_verified_contact'] ) ); ?>> Require one verified contact before purchase</label><br><span class="description">WooCommerce checkout requires a signed-in account with either a verified email or verified phone. Full email + phone + passkey completion remains recommended but is not required to purchase.</span></p>
				<p><label>Progressive visit threshold<br><input type="number" min="1" max="20" name="pk[progressive_visits]" value="<?php echo esc_attr( $s['progressive_visits'] ); ?>"></label></p>
				<p class="description"><strong>Verify later</strong> lets a new ordinary subscriber defer verification once; their next sign-in requires verification. In <strong>Email or phone</strong> mode, Key.kiwe automatically offers the missing recovery email or phone in the same Profile sheet. Privileged accounts never receive the grace or skip option.</p>
				<p><label>Email delivery<br><select name="pk[email_delivery]"><?php pk_select_options( array( 'magic_link'=>'Magic link', 'otp'=>'OTP code' ), $s['email_delivery'] ); ?></select></label><br><span class="description">Uses <code>wp_mail()</code>. Configure authenticated transactional SMTP in Kiwe &gt; Email; an accepted handoff does not prove inbox delivery.</span></p>
				<p><label>Return message<br><input class="large-text" name="pk[return_message]" value="<?php echo esc_attr( $s['return_message'] ); ?>"></label></p>
			</div>
			<div class="pk-card"><h2>WordPress Access</h2>
				<p><label><input type="checkbox" name="pk[replace_wp_login]" value="1" <?php checked( ! empty( $s['replace_wp_login'] ) ); ?>> Send normal <code>wp-login.php</code> sign-ins to the Key.kiwe Profile sheet</label></p>
				<p><label><input type="checkbox" name="pk[protect_wp_admin]" value="1" <?php checked( ! empty( $s['protect_wp_admin'] ) ); ?>> Protect <code>/wp-admin</code> from visitors and non-privileged accounts</label></p>
				<p class="description">Frontend admin-bar visibility remains in Kiwe &gt; Settings &gt; Dock, so there is one canonical control.</p>
				<p class="description"><strong>Recovery invariant:</strong> enable login/admin protection only after the private SecureTrack break-glass URL has been tested. Password reset and logout actions remain available.</p>
				<p class="description">Header triggers (no dock required): <code>data-dsa-open-module=&quot;profile&quot;</code> and <code>data-dsa-open-module=&quot;search&quot;</code>. Add an <code>aria-label</code> to the same button or icon link.</p>
			</div>
			<div class="pk-card"><h2>Phone Providers</h2>
				<p><label>SMS provider label<br><input class="regular-text" name="pk[sms_provider]" value="<?php echo esc_attr( $s['sms_provider'] ); ?>"></label></p>
				<p><label>SMS webhook URL<br><input class="large-text" name="pk[sms_webhook]" value="<?php echo esc_attr( $s['sms_webhook'] ); ?>"></label></p>
				<p><label>WhatsApp adapter<br><select name="pk[whatsapp_mode]"><?php pk_select_options( array( 'phonekey_gateway'=>'Key.kiwe Gateway (signed)', 'generic_webhook'=>'Legacy generic webhook' ), $s['whatsapp_mode'] ?? 'phonekey_gateway' ); ?></select></label></p>
				<p><label>WhatsApp provider label<br><input class="regular-text" name="pk[whatsapp_provider]" value="<?php echo esc_attr( $s['whatsapp_provider'] ); ?>" placeholder="Kiwe WhatsApp"></label></p>
				<p><label>WhatsApp gateway URL<br><input class="large-text" type="url" name="pk[whatsapp_webhook]" value="<?php echo esc_attr( $s['whatsapp_webhook'] ); ?>" placeholder="https://key.kiwelaunch.com/v1/otp"></label></p>
				<p><label>Gateway tenant key ID<br><input class="regular-text" name="pk[whatsapp_key_id]" value="<?php echo esc_attr( $s['whatsapp_key_id'] ?? '' ); ?>"></label></p>
				<p><label>Gateway signing secret<br><input class="regular-text" type="password" autocomplete="new-password" name="pk[whatsapp_secret]" value="" placeholder="<?php echo ! empty( $s['whatsapp_secret'] ) ? 'Stored securely — leave blank to keep' : 'Paste the tenant secret'; ?>"></label></p>
				<p><label><input type="checkbox" name="pk[whatsapp_email_fallback]" value="1" <?php checked( ! empty( $s['whatsapp_email_fallback'] ) ); ?>> Immediately email the same OTP when WhatsApp is unavailable or rejected</label></p>
				<p class="description">Signed mode uses a short-lived HMAC request, nonce, site allowlist and idempotency key. The secret is encrypted with Kiwe Secret Store and is never rendered back into this page.</p>
			</div>
			<div class="pk-card"><h2>Trusted Devices And IP</h2>
				<p><label><input type="checkbox" name="pk[trusted_devices]" value="1" <?php checked( $s['trusted_devices'] ); ?>> Enable trusted devices</label></p>
				<p><label>IP trust threshold<br><input type="number" min="1" max="50" name="pk[ip_trust_threshold]" value="<?php echo esc_attr( $s['ip_trust_threshold'] ); ?>"></label></p>
				<p><label>Trusted IP TTL days<br><input type="number" min="1" max="365" name="pk[ip_trust_ttl]" value="<?php echo esc_attr( $s['ip_trust_ttl'] ); ?>"></label></p>
				<p><label>Privileged reauth minutes<br><input type="number" min="0" max="1440" name="pk[admin_reauth_minutes]" value="<?php echo esc_attr( $s['admin_reauth_minutes'] ); ?>"></label><br><span class="description">Set to 0 to disable Kiwe-initiated privileged-session logout. WordPress cookies may still expire normally.</span></p>
				<p><label>OTP target limit<br><input type="number" min="1" max="50" name="pk[otp_target_limit]" value="<?php echo esc_attr( $s['otp_target_limit'] ); ?>"></label></p>
				<p><label>OTP target window minutes<br><input type="number" min="1" max="1440" name="pk[otp_target_window_min]" value="<?php echo esc_attr( $s['otp_target_window_min'] ); ?>"></label></p>
				<p class="description">Limits repeated OTP sends to one email or phone, even when requests rotate IPs.</p>
			</div>
		</div>
		<h2>Role Policy Matrix</h2>
		<table class="widefat striped pk-matrix"><thead><tr><th>Role</th><th>Verification</th><th>Login mode</th><th>Step-up factor</th><th>Session timeout</th><th>Trusted device days</th></tr></thead><tbody>
		<?php foreach ( $roles as $role => $info ) : $p = $s['role_matrix'][ $role ] ?? array(); ?>
			<tr>
				<td><strong><?php echo esc_html( translate_user_role( $info['name'] ) ); ?></strong></td>
				<td><select name="pk[role_matrix][<?php echo esc_attr( $role ); ?>][verification]"><?php pk_select_options( array( 'verify_now'=>'Verify now', 'verify_later'=>'Verify later', 'progressive'=>'Progressive' ), $p['verification'] ?? 'verify_later' ); ?></select></td>
				<td><select name="pk[role_matrix][<?php echo esc_attr( $role ); ?>][login_mode]"><?php pk_select_options( array( 'passkey'=>'Passkey', 'none'=>'No extra login' ), $p['login_mode'] ?? 'passkey' ); ?></select></td>
				<td><select name="pk[role_matrix][<?php echo esc_attr( $role ); ?>][step_up]"><?php pk_select_options( array( 'none'=>'None', 'totp_optional'=>'TOTP if enrolled', 'totp_required'=>'TOTP required' ), $p['step_up'] ?? 'none' ); ?></select></td>
				<td><input type="number" min="0" max="1440" name="pk[role_matrix][<?php echo esc_attr( $role ); ?>][timeout]" value="<?php echo esc_attr( $p['timeout'] ?? 0 ); ?>"> min</td>
				<td><input type="number" min="1" max="365" name="pk[role_matrix][<?php echo esc_attr( $role ); ?>][device_days]" value="<?php echo esc_attr( $p['device_days'] ?? 30 ); ?>"></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<p><button class="button button-primary">Save Kiwe Auth Settings</button></p>
	</form>
	<?php
}

function pk_select_options( $opts, $selected ) {
	foreach ( $opts as $k => $label ) echo '<option value="' . esc_attr( $k ) . '" ' . selected( $selected, $k, false ) . '>' . esc_html( $label ) . '</option>';
}

function pk_admin_users() {
	global $wpdb;
	$users = get_users( array( 'number' => 100, 'orderby' => 'ID', 'order' => 'DESC' ) );
	$ids = array_map( 'intval', wp_list_pluck( $users, 'ID' ) );
	$credential_counts = array();
	$totp_status = array();
	$backup_status = array();
	$verified_status = array();
	$trusted_device_counts = array();
	$last_high_assurance = array();

	if ( $ids ) {
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$prepare = array_merge( array( "SELECT user_id, COUNT(*) AS cnt FROM " . pk_t( 'credentials' ) . " WHERE user_id IN ($in) GROUP BY user_id" ), $ids );
		foreach ( $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $prepare ), ARRAY_A ) as $row ) {
			$credential_counts[ (int) $row['user_id'] ] = (int) $row['cnt'];
		}

		$prepare = array_merge( array( "SELECT user_id, factor_type, status FROM " . pk_t( 'factors' ) . " WHERE user_id IN ($in) AND factor_type IN ('totp','backup_code','email','phone') ORDER BY id DESC" ), $ids );
		foreach ( $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $prepare ), ARRAY_A ) as $row ) {
			$uid = (int) $row['user_id'];
			if ( 'totp' === $row['factor_type'] && ! isset( $totp_status[ $uid ] ) ) $totp_status[ $uid ] = $row['status'];
			if ( 'backup_code' === $row['factor_type'] && ! isset( $backup_status[ $uid ] ) ) $backup_status[ $uid ] = $row['status'];
			if ( in_array( $row['factor_type'], array( 'email', 'phone' ), true ) && 'verified' === $row['status'] ) $verified_status[ $uid ] = true;
		}

		$prepare = array_merge( array( "SELECT user_id, COUNT(*) AS cnt FROM " . pk_t( 'trusted_devices' ) . " WHERE user_id IN ($in) AND expires_at>%s GROUP BY user_id" ), $ids, array( pk_now() ) );
		foreach ( $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $prepare ), ARRAY_A ) as $row ) {
			$trusted_device_counts[ (int) $row['user_id'] ] = (int) $row['cnt'];
		}

		$prepare = array_merge( array( "SELECT user_id, meta_key, meta_value FROM $wpdb->usermeta WHERE user_id IN ($in) AND meta_key IN ('pk_last_high_assurance_login','pk_verified_at')" ), $ids );
		foreach ( $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $prepare ), ARRAY_A ) as $row ) {
			$uid = (int) $row['user_id'];
			if ( 'pk_last_high_assurance_login' === $row['meta_key'] ) $last_high_assurance[ $uid ] = $row['meta_value'];
			if ( 'pk_verified_at' === $row['meta_key'] && $row['meta_value'] ) $verified_status[ $uid ] = true;
		}
	}

	echo '<table class="widefat striped"><thead><tr><th>User</th><th>Verified</th><th>Passkeys</th><th>TOTP</th><th>Backup</th><th>Trusted devices</th><th>Last high assurance</th><th>Actions</th></tr></thead><tbody>';
	foreach ( $users as $u ) {
		$creds = $credential_counts[ $u->ID ] ?? 0;
		$totp_active = ( $totp_status[ $u->ID ] ?? '' ) === 'verified';
		$backup = isset( $backup_status[ $u->ID ] );
		$devices = $trusted_device_counts[ $u->ID ] ?? 0;
		$verified = ! empty( $verified_status[ $u->ID ] );
		$base = wp_nonce_url( admin_url( 'admin-post.php?action=pk_admin_action&user_id=' . $u->ID ), 'pk_admin_action' );
		echo '<tr><td><strong>' . esc_html( $u->display_name ?: $u->user_login ) . '</strong><br><small>' . esc_html( implode( ', ', $u->roles ) ) . '</small></td>';
		echo '<td>' . pk_badge( $verified ? 'Verified' : 'Pending', $verified ? 'good' : 'warn' ) . '</td>';
		echo '<td>' . pk_badge( $creds ? $creds . ' passkey' : 'None', $creds ? 'good' : 'bad' ) . '</td>';
		echo '<td>' . pk_badge( $totp_active ? 'Active' : 'Inactive', $totp_active ? 'good' : 'warn' ) . '</td>';
		echo '<td>' . pk_badge( $backup ? 'Generated' : 'None', $backup ? 'good' : 'warn' ) . '</td>';
		echo '<td>' . esc_html( $devices ) . '</td>';
		echo '<td>' . esc_html( $last_high_assurance[ $u->ID ] ?? '-' ) . '</td>';
		echo '<td><a class="button button-small" href="' . esc_url( add_query_arg( 'pk_act', 'verify', $base ) ) . '">Verify manually</a> ';
		echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'pk_act', 'revoke_passkeys', $base ) ) . '">Revoke passkeys</a> ';
		echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'pk_act', 'reset_totp', $base ) ) . '">Reset TOTP</a> ';
		echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'pk_act', 'backup_codes', $base ) ) . '">Regenerate backup</a> ';
		echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'pk_act', 'revoke_devices', $base ) ) . '">Revoke devices</a></td></tr>';
	}
	echo '</tbody></table>';
}

function pk_badge( $text, $state ) {
	$class = 'good' === $state ? 'pk-good' : ( 'bad' === $state ? 'pk-bad' : 'pk-warn' );
	return '<span class="pk-status ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
}

function pk_admin_activity() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT * FROM " . pk_t( 'activity' ) . " ORDER BY id DESC LIMIT 200" );
	echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Event</th><th>User</th><th>Severity</th><th>Meta</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$u = $r->user_id ? get_userdata( $r->user_id ) : null;
		echo '<tr><td>' . esc_html( get_date_from_gmt( $r->created_at ) ) . '</td><td><code>' . esc_html( $r->event ) . '</code></td><td>' . esc_html( $u ? $u->user_login : '-' ) . '</td><td>' . esc_html( $r->severity ) . '</td><td><small>' . esc_html( wp_json_encode( pk_meta_decode( $r->meta ) ) ) . '</small></td></tr>';
	}
	echo '</tbody></table>';
}


