<?php

namespace DSA\Diagnostics;

use DSA\Settings;
use DSA\Notifications\Push_Service;
use DSA\Trust\Trust_Service;
use DSA\Utilities\Atomic_Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Production_Readiness_Service {
	private $settings;
	private $trust;
	private $push;

	public function __construct( Settings $settings, Trust_Service $trust, ?Push_Service $push = null ) {
		$this->settings = $settings;
		$this->trust    = $trust;
		$this->push     = $push;
	}

	public function report(): array {
		$settings = $this->settings->all();
		$checks   = array_merge(
			$this->runtime_checks( $settings ),
			$this->surface_checks( $settings ),
			$this->commerce_checks( $settings ),
			$this->security_checks(),
			$this->data_checks( $settings )
		);

		$counts = [
			'critical' => 0,
			'warning'  => 0,
			'pass'     => 0,
		];

		foreach ( $checks as $check ) {
			$status = $check['status'] ?? 'warning';
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}

		$score = max( 0, min( 100, 100 - ( $counts['critical'] * 20 ) - ( $counts['warning'] * 6 ) ) );
		$ready = 0 === $counts['critical'] && $counts['warning'] <= 3;
		$summary = $counts['critical'] > 0
			? __( 'Critical blockers must be fixed before production deployment.', 'dsa' )
			: __( 'No critical blockers. Production proof and warning remediation are still required.', 'dsa' );

		if ( $ready && $counts['warning'] > 0 ) {
			$summary = __( 'No critical blockers. Resolve the remaining warnings before broad production deployment.', 'dsa' );
		} elseif ( $ready ) {
			$summary = __( 'Ready for a controlled production deployment after live Woo/browser smoke testing.', 'dsa' );
		}

		return [
			'version'   => DSA_VERSION,
			'generated' => current_time( 'mysql' ),
			'ready'     => $ready,
			'score'     => $score,
			'counts'    => $counts,
			'summary'   => $summary,
			'checks'    => $checks,
		];
	}

	private function runtime_checks( array $settings ): array {
		$uploads = wp_upload_dir();
		$wp_version = get_bloginfo( 'version' );
		$persistent_cache = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$diagnostics = isset( $settings['diagnostics'] ) && is_array( $settings['diagnostics'] ) ? $settings['diagnostics'] : [];
		$profiling = ( defined( 'DSA_PROFILE_CACHE' ) && true === DSA_PROFILE_CACHE )
			|| ( defined( 'DSA_PROFILE_RUNTIME' ) && true === DSA_PROFILE_RUNTIME )
			|| ( ! empty( $diagnostics['enabled'] ) && ! empty( $diagnostics['performance_profile'] ) );
		$last_fatal = get_option( 'kiwe_mu_last_fatal', [] );
		$recent_fatal = is_array( $last_fatal )
			&& ! empty( $last_fatal['time'] )
			&& ( time() - absint( $last_fatal['time'] ) ) < WEEK_IN_SECONDS;
		$asset_build = class_exists( '\\DSA\\Delivery\\Asset_Build_Service' ) ? \DSA\Delivery\Asset_Build_Service::status() : [];
		$asset_pilot = ! empty( $diagnostics['asset_build_pilot'] );
		$asset_apply = $asset_pilot && ! empty( $diagnostics['asset_build_apply'] );
		$rate_store = Atomic_Rate_Limiter::diagnostics();

		return [
			$this->check(
				'php_version',
				__( 'PHP runtime', 'dsa' ),
				PHP_VERSION_ID >= 80200 ? 'pass' : ( PHP_VERSION_ID >= 70400 ? 'warning' : 'critical' ),
				sprintf( __( 'Running PHP %s.', 'dsa' ), PHP_VERSION ),
				PHP_VERSION_ID >= 80200
					? __( 'Matches the DSA production target.', 'dsa' )
					: __( 'Use PHP 8.2+ on Hostinger before production testing.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'wp_version',
				__( 'WordPress runtime', 'dsa' ),
				version_compare( $wp_version, '6.4', '>=' ) ? 'pass' : 'warning',
				sprintf( __( 'Running WordPress %s.', 'dsa' ), $wp_version ),
				__( 'Use the newest stable WordPress build available on the host.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'uploads_writable',
				__( 'Uploads directory', 'dsa' ),
				empty( $uploads['error'] ) ? 'pass' : 'critical',
				empty( $uploads['error'] ) ? __( 'Uploads directory is writable.', 'dsa' ) : (string) $uploads['error'],
				__( 'Logo uploads, media, and future profile assets need a writable uploads directory.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'rest_available',
				__( 'WordPress REST API', 'dsa' ),
				function_exists( 'rest_url' ) ? 'pass' : 'critical',
				function_exists( 'rest_url' ) ? rest_url( 'dsa/v1/manifest' ) : __( 'REST helpers are missing.', 'dsa' ),
				__( 'DSA surface, admin editors, rewards, metrics, and permission journeys require REST.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'wp_debug',
				__( 'Debug visibility', 'dsa' ),
				( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'warning' : 'pass',
				( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'WP_DEBUG is enabled.', 'dsa' ) : __( 'WP_DEBUG is not publicly noisy.', 'dsa' ),
				__( 'Use debug logging during QA, then disable public display before production.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'cache_backend',
				__( 'Cache backend', 'dsa' ),
				'pass',
				$persistent_cache ? __( 'A persistent WordPress object cache is active.', 'dsa' ) : __( 'No persistent object cache is active; transients use the database.', 'dsa' ),
				__( 'Both modes are supported. For proof, collect the same DSA_PROFILE_CACHE journey once without persistent cache and once with Redis or Memcached.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'rate_limit_store',
				__( 'Bounded request limiter', 'dsa' ),
				! empty( $rate_store['tableReady'] ) && ! empty( $rate_store['cleanupScheduled'] ) ? 'pass' : 'warning',
				sprintf( __( 'Backend: %1$s. Cleanup scheduled: %2$s.', 'dsa' ), sanitize_text_field( (string) ( $rate_store['backend'] ?? 'unknown' ) ), ! empty( $rate_store['cleanupScheduled'] ) ? __( 'yes', 'dsa' ) : __( 'no', 'dsa' ) ),
				__( 'Kiwe uses atomic object-cache increments when available and a bounded SQL bucket table on ordinary shared hosts.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'production_profiling',
				__( 'Runtime profiling', 'dsa' ),
				$profiling ? 'warning' : 'pass',
				$profiling ? __( 'A Kiwe profiling constant is active and writes request summaries to the debug log.', 'dsa' ) : __( 'Kiwe runtime profiling is off by default.', 'dsa' ),
				__( 'Enable profiling only for a bounded test trace, then turn it off before normal production traffic.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'mu_fatal_recovery',
				__( 'MU fatal recovery', 'dsa' ),
				$recent_fatal ? 'warning' : 'pass',
				$recent_fatal
					? sprintf( __( 'Kiwe recorded a package fatal within the last seven days: %s', 'dsa' ), sanitize_text_field( (string) ( $last_fatal['message'] ?? 'Unknown error' ) ) )
					: __( 'No recent Kiwe package fatal is recorded.', 'dsa' ),
				__( 'The MU loader fails open and records only Kiwe-owned fatals. Replace incomplete uploads atomically and re-run readiness after recovery.', 'dsa' ),
				'runtime'
			),
			$this->check(
				'asset_build_pilot',
				__( 'S18 generated asset delivery', 'dsa' ),
				$asset_pilot && 'failed' === ( $asset_build['state'] ?? '' ) ? 'critical' : ( $asset_apply ? 'warning' : 'pass' ),
				$asset_pilot
					? sprintf( __( 'Build state: %1$s. Generated delivery: %2$s.', 'dsa' ), (string) ( $asset_build['state'] ?? 'not-built' ), $asset_apply ? __( 'enabled', 'dsa' ) : __( 'not applied', 'dsa' ) )
					: __( 'The S18 asset build pilot is disabled.', 'dsa' ),
				__( 'Apply generated delivery only after the build reports ready and the packaged stylesheet fallback has been verified on staging.', 'dsa' ),
				'runtime'
			),
		];
	}

	private function surface_checks( array $settings ): array {
		$manifest = $this->settings->manifest();
		$excluded = isset( $manifest['routes']['excluded'] ) && is_array( $manifest['routes']['excluded'] ) ? $manifest['routes']['excluded'] : [];
		$required_exclusions = [ '/cart*', '/checkout*', '/my-account*', '/wp-json/*', '/*?add-to-cart=*' ];
		$missing = array_values( array_diff( $required_exclusions, $excluded ) );
		$visual = isset( $settings['visual_effects'] ) && is_array( $settings['visual_effects'] ) ? $settings['visual_effects'] : [];
		$messages = isset( $visual['transition_messages'] ) && is_array( $visual['transition_messages'] ) ? array_filter( $visual['transition_messages'] ) : [];

		return [
			$this->check(
				'surface_enabled',
				__( 'Surface shell', 'dsa' ),
				! empty( $settings['enabled'] ) ? 'pass' : 'critical',
				! empty( $settings['enabled'] ) ? __( 'Surface is enabled.', 'dsa' ) : __( 'Surface is disabled.', 'dsa' ),
				__( 'Enable the Surface before production smoke testing.', 'dsa' ),
				'surface'
			),
			$this->check(
				'protected_routes',
				__( 'Protected route exclusions', 'dsa' ),
				empty( $missing ) ? 'pass' : 'critical',
				empty( $missing ) ? __( 'Cart, checkout, account, REST, and add-to-cart routes are excluded.', 'dsa' ) : sprintf( __( 'Missing exclusions: %s', 'dsa' ), implode( ', ', $missing ) ),
				__( 'Protected flow pages must never be partially swapped by the surface shell.', 'dsa' ),
				'surface'
			),
			$this->check(
				'fragment_navigation',
				__( 'Legacy fragment navigation', 'dsa' ),
				empty( $settings['fragment_navigation'] ) ? 'pass' : 'warning',
				empty( $settings['fragment_navigation'] ) ? __( 'The unsafe legacy partial-page renderer is removed and remains disabled.', 'dsa' ) : __( 'A historical fragment-navigation value is present and will be normalized off.', 'dsa' ),
				__( 'Production uses full-document navigation with the transition Surface. Controlled editorial morphing is a separate Developer-gated pipeline and remains off until its live S16 compatibility matrix passes.', 'dsa' ),
				'surface'
			),
			$this->check(
				'editorial_morph_navigation',
				__( 'Controlled editorial morphing', 'dsa' ),
				! empty( $visual['editorial_morph_navigation'] ) ? 'warning' : 'pass',
				! empty( $visual['editorial_morph_navigation'] ) ? __( 'Experimental static-editorial morphing is enabled.', 'dsa' ) : __( 'Experimental morph application is off; native full-document transitions remain available.', 'dsa' ),
				__( 'Keep this off for broad production until the S16 browser matrix proves theme, Bricks, plugin, CSP, bfcache, accessibility, and analytics lifecycle compatibility on the target stack.', 'dsa' ),
				'surface'
			),
			$this->check(
				'initial_home_surface',
				__( 'First-session Home surface', 'dsa' ),
				! empty( $visual['initial_preloader_enabled'] ) ? 'warning' : 'pass',
				! empty( $visual['initial_preloader_enabled'] ) ? __( 'The full-screen first-session Home experience is enabled.', 'dsa' ) : __( 'The first-session Home experience is opt-in and currently disabled.', 'dsa' ),
				__( 'Enable it deliberately for an appsite journey. Keep it disabled on ad-supported or search-sensitive editorial sites unless policy and crawler tests approve it.', 'dsa' ),
				'surface'
			),
			$this->check(
				'transition_messages',
				__( 'Transition messages', 'dsa' ),
				! empty( $messages ) ? 'pass' : 'warning',
				! empty( $messages ) ? sprintf( __( '%d transition message(s) configured.', 'dsa' ), count( $messages ) ) : __( 'No transition messages configured.', 'dsa' ),
				__( 'Add at least one message so fast and slow page transitions have intentional content.', 'dsa' ),
				'surface'
			),
		];
	}

	private function commerce_checks( array $settings ): array {
		$games = isset( $settings['games'] ) && is_array( $settings['games'] ) ? $settings['games'] : [];
		$link_hub = isset( $settings['link_hub'] ) && is_array( $settings['link_hub'] ) ? $settings['link_hub'] : [];
		$trust = $this->trust->summary( $link_hub );
		$woo_active = function_exists( 'WC' );
		$coupon_enabled = ! empty( $games['coupon_enabled'] );

		return [
			$this->check(
				'woocommerce',
				__( 'WooCommerce runtime', 'dsa' ),
				$woo_active ? 'pass' : ( $coupon_enabled ? 'critical' : 'warning' ),
				$woo_active ? __( 'WooCommerce functions are available.', 'dsa' ) : __( 'WooCommerce is not active in this runtime.', 'dsa' ),
				$coupon_enabled ? __( 'Reward coupons require WooCommerce before launch.', 'dsa' ) : __( 'Commerce trust badges use manual fallbacks when WooCommerce is missing.', 'dsa' ),
				'commerce'
			),
			$this->check(
				'reward_coupons',
				__( 'Reward coupon issuing', 'dsa' ),
				! $coupon_enabled || ( post_type_exists( 'shop_coupon' ) && class_exists( 'WC_Coupon' ) ) ? 'pass' : 'critical',
				$coupon_enabled ? __( 'Reward coupons are enabled.', 'dsa' ) : __( 'Reward coupons are disabled by default.', 'dsa' ),
				__( 'Before production rewards, complete a live issue/use/expiry test.', 'dsa' ),
				'commerce'
			),
			$this->check(
				'payment_trust',
				__( 'Payment trust label', 'dsa' ),
				! empty( $trust['payment']['providers'] ) || ! empty( $link_hub['payment_provider'] ) ? 'pass' : 'warning',
				! empty( $trust['payment']['providers'] ) ? implode( ', ', $trust['payment']['providers'] ) : __( 'No payment provider detected or configured.', 'dsa' ),
				__( 'Set a manual payment provider if WooCommerce cannot expose gateway names on this host.', 'dsa' ),
				'commerce'
			),
			$this->check(
				'link_hub_shop',
				__( 'Links shop target', 'dsa' ),
				! empty( $link_hub['shop_url'] ) || ( $woo_active && function_exists( 'wc_get_page_permalink' ) && wc_get_page_permalink( 'shop' ) ) ? 'pass' : 'warning',
				__( 'Shop link can use manual URL or WooCommerce shop page.', 'dsa' ),
				__( 'Set a manual shop URL if WooCommerce shop permalink is unavailable.', 'dsa' ),
				'commerce'
			),
		];
	}

	private function security_checks(): array {
		$secure_active = defined( 'STP_VER' ) || function_exists( 'stp_cfg' );
		$stp_cfg = function_exists( 'stp_cfg' ) ? stp_cfg() : [];
		$enforcement_paused = function_exists( 'stp_enforcement_paused' ) && stp_enforcement_paused();
		$rate_limits_on = ! empty( $stp_cfg['endpoint_rate_limits'] );
		$tables_ready = ! function_exists( 'stp_tables_ready' ) || stp_tables_ready();
		$ip_resolution = class_exists( '\\DSA\\Secure\\SecureTrack_Ip_Service' ) ? \DSA\Secure\SecureTrack_Ip_Service::resolution_details() : [];
		$forwarded_ignored = ! empty( $ip_resolution['forwarded_ignored'] );
		$recovery_ready = ! $secure_active || ( function_exists( 'stp_break_glass_slug' ) && strlen( (string) stp_break_glass_slug() ) >= 24 );
		$secret_store = class_exists( '\\DSA\\Security\\Secret_Store' ) ? \DSA\Security\Secret_Store::diagnostics() : [];
		$phonekey_crypto = function_exists( 'pk_crypto_diagnostics' ) ? pk_crypto_diagnostics() : [];

		return [
			$this->check(
				'https',
				__( 'HTTPS / SSL', 'dsa' ),
				is_ssl() ? 'pass' : 'critical',
				is_ssl() ? __( 'Current request is HTTPS.', 'dsa' ) : __( 'Current request is not HTTPS.', 'dsa' ),
				__( 'PhoneKey, profile editing, rewards, app install prompts, and checkout trust require HTTPS.', 'dsa' ),
				'security'
			),
			$this->check(
				'secret_store',
				__( 'Versioned secret storage', 'dsa' ),
				! empty( $secret_store['ready'] ) ? 'pass' : 'critical',
				! empty( $secret_store['ready'] )
					? sprintf( __( 'Secret store v%1$d is ready with key ID %2$s.', 'dsa' ), (int) ( $secret_store['version'] ?? 0 ), (string) ( $secret_store['keyId'] ?? '' ) )
					: __( 'No authenticated secret-encryption primitive is available.', 'dsa' ),
				__( 'Enable Sodium or OpenSSL AES-256-GCM. Never remove or rotate WordPress salts without retaining a recovery key and completing re-enrollment.', 'dsa' ),
				'security'
			),
			$this->check(
				'phonekey_crypto_key',
				__( 'PhoneKey identity key continuity', 'dsa' ),
				empty( $phonekey_crypto['hmac_key_mismatch'] ) ? 'pass' : 'critical',
				empty( $phonekey_crypto['hmac_key_mismatch'] ) ? __( 'PhoneKey identity key continuity is intact.', 'dsa' ) : __( 'PhoneKey detected a WordPress salt-derived identity-key change.', 'dsa' ),
				__( 'Restore the prior WordPress authentication salts before accepting PhoneKey identity changes. Encrypted-secret recovery keys do not repair changed identity HMACs.', 'dsa' ),
				'security'
			),
			$this->check(
				'securetrack_loaded',
				__( 'Kiwe Secure', 'dsa' ),
				$secure_active ? 'pass' : 'warning',
				$secure_active ? sprintf( __( 'SecureTrack loaded%s.', 'dsa' ), defined( 'STP_VER' ) ? ' ' . STP_VER : '' ) : __( 'SecureTrack is not loaded.', 'dsa' ),
				__( 'Admin Secure dock and security audit features need SecureTrack available.', 'dsa' ),
				'security'
			),
			$this->check(
				'securetrack_tables',
				__( 'SecureTrack tables', 'dsa' ),
				$tables_ready ? 'pass' : 'critical',
				$tables_ready ? __( 'SecureTrack schema is ready or not required.', 'dsa' ) : __( 'SecureTrack schema is not ready.', 'dsa' ),
				__( 'Use Kiwe > Secure > Settings > Repair Database before production.', 'dsa' ),
				'security'
			),
			$this->check(
				'securetrack_mode',
				__( 'SecureTrack enforcement mode', 'dsa' ),
				$enforcement_paused ? 'warning' : 'pass',
				$enforcement_paused ? __( 'Emergency monitor-only mode is active.', 'dsa' ) : __( 'Enforcement is active.', 'dsa' ),
				__( 'Use monitor-only during false-positive QA, then resume enforcement when ready.', 'dsa' ),
				'security'
			),
			$this->check(
				'endpoint_rate_limits',
				__( 'Endpoint rate limits', 'dsa' ),
				$rate_limits_on ? 'warning' : 'pass',
				$rate_limits_on ? __( 'Advanced endpoint rate limits are enabled.', 'dsa' ) : __( 'Advanced endpoint rate limits are disabled by default.', 'dsa' ),
				__( 'Keep endpoint limits off until tuned for Hostinger/CDN/proxy behavior.', 'dsa' ),
				'security'
			),
			$this->check(
				'securetrack_proxy',
				__( 'SecureTrack client IP resolution', 'dsa' ),
				$forwarded_ignored ? 'warning' : 'pass',
				$forwarded_ignored
					? __( 'Forwarded IP headers are present but the direct peer is not a trusted proxy, so SecureTrack correctly ignores them.', 'dsa' )
					: sprintf( __( 'Resolved from %1$s as %2$s.', 'dsa' ), sanitize_key( (string) ( $ip_resolution['source'] ?? 'remote_addr' ) ), (string) ( $ip_resolution['resolved'] ?? '' ) ),
				__( 'If this site is behind a Hostinger or custom proxy, add only the host-confirmed CIDRs in Kiwe > Secure. Never trust forwarded headers globally.', 'dsa' ),
				'security'
			),
			$this->check(
				'securetrack_recovery',
				__( 'SecureTrack enforcement recovery', 'dsa' ),
				$recovery_ready ? 'pass' : 'critical',
				$recovery_ready ? __( 'Monitor-only recovery and a private break-glass route are available.', 'dsa' ) : __( 'SecureTrack recovery route is not ready.', 'dsa' ),
				__( 'Record the private recovery URL outside WordPress and test monitor-only mode before enabling enforcement.', 'dsa' ),
				'security'
			),
			$this->check(
				'file_editor',
				__( 'Dashboard file editor', 'dsa' ),
				( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ? 'pass' : 'warning',
				( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ? __( 'DISALLOW_FILE_EDIT is active.', 'dsa' ) : __( 'WordPress file editor may be available.', 'dsa' ),
				__( 'Disable dashboard file editing in production wp-config.php or SecureTrack.', 'dsa' ),
				'security'
			),
		];
	}

	private function data_checks( array $settings ): array {
		$schema = isset( $settings['schema_geo'] ) && is_array( $settings['schema_geo'] ) ? $settings['schema_geo'] : [];
		$metrics = isset( $settings['metrics'] ) && is_array( $settings['metrics'] ) ? $settings['metrics'] : [];
		$permissions = isset( $settings['permissions'] ) && is_array( $settings['permissions'] ) ? $settings['permissions'] : [];
		$link_hub = isset( $settings['link_hub'] ) && is_array( $settings['link_hub'] ) ? $settings['link_hub'] : [];
		$google_reviews = ( $link_hub['review_source'] ?? 'manual' ) === 'google';
		$pwa_enabled = ! empty( $permissions['pwa_enabled'] );
		$offline_editorial = ! empty( $permissions['offline_editorial_enabled'] );
		$pwa_icon_ready = (bool) get_site_icon_url( 512 );
		$push = $this->push ? $this->push->diagnostics() : [];
		$push_enabled = ! empty( $permissions['notifications_enabled'] );

		return [
			$this->check(
				'schema_geo',
				__( 'Schema/GEO output', 'dsa' ),
				! empty( $schema['enabled'] ) ? 'pass' : 'warning',
				! empty( $schema['enabled'] ) ? __( 'Schema/GEO engine is enabled.', 'dsa' ) : __( 'Schema/GEO engine is disabled.', 'dsa' ),
				__( 'Keep enabled for high-confidence structured data and admin-governed GEO hints.', 'dsa' ),
				'data'
			),
			$this->check(
				'metrics',
				__( 'Interstice metrics', 'dsa' ),
				! empty( $metrics['enabled'] ) ? 'pass' : 'warning',
				! empty( $metrics['enabled'] ) ? __( 'Aggregate DSA metrics are enabled.', 'dsa' ) : __( 'Aggregate DSA metrics are disabled.', 'dsa' ),
				__( 'Metrics prove transition, dock, PWA, and reward engagement without storing personal timelines.', 'dsa' ),
				'data'
			),
			$this->check(
				'permissions',
				__( 'Permission Journey Manager', 'dsa' ),
				! empty( $permissions['enabled'] ) && ! empty( $permissions['pwa_enabled'] ) ? 'pass' : 'warning',
				! empty( $permissions['enabled'] ) ? __( 'Permission journey rails are enabled.', 'dsa' ) : __( 'Permission journeys are disabled.', 'dsa' ),
				__( 'PWA install asks should stay earned-state gated before production.', 'dsa' ),
				'data'
			),
			$this->check(
				'pwa_site_icon',
				__( 'PWA Site Icon', 'dsa' ),
				! $pwa_enabled ? 'warning' : ( $pwa_icon_ready ? 'pass' : 'critical' ),
				! $pwa_enabled ? __( 'PWA installation is disabled.', 'dsa' ) : ( $pwa_icon_ready ? __( 'A 512px WordPress Site Icon is available for the app manifest.', 'dsa' ) : __( 'The app manifest has no reliable 512px Site Icon.', 'dsa' ) ),
				__( 'Set a square WordPress Site Icon of at least 512 by 512 pixels. Chromium will not expose its native install prompt without compliant manifest icons.', 'dsa' ),
				'data'
			),
			$this->check(
				'offline_editorial',
				__( 'Offline public editorial cache', 'dsa' ),
				$offline_editorial ? 'warning' : 'pass',
				$offline_editorial ? __( 'The S17 public WordPress editorial cache pilot is enabled.', 'dsa' ) : __( 'Offline editorial caching is off by default.', 'dsa' ),
				__( 'Before broad production, prove online refresh, offline replay, cache eviction, media limits, logout/account/cart isolation, and service-worker upgrade cleanup. Bricks remains network-only.', 'dsa' ),
				'data'
			),
			$this->check(
				'push_crypto',
				__( 'Push VAPID and payload encryption', 'dsa' ),
				! $push_enabled ? 'warning' : ( ! empty( $push['ready'] ) ? 'pass' : 'critical' ),
				! $push_enabled ? __( 'Browser notifications are not enabled.', 'dsa' ) : ( ! empty( $push['ready'] ) ? __( 'Site VAPID keys and OpenSSL P-256/AES-GCM support are ready.', 'dsa' ) : __( 'Push cryptography is unavailable.', 'dsa' ) ),
				__( 'Run a real subscribed-device send before production; local crypto readiness does not prove vendor delivery.', 'dsa' ),
				'data'
			),
			$this->check(
				'push_cron',
				__( 'Push cleanup and WordPress cron', 'dsa' ),
				! $push_enabled ? 'warning' : ( ! empty( $push['cronScheduled'] ) ? ( ! empty( $push['wpCronDisabled'] ) ? 'warning' : 'pass' ) : 'critical' ),
				! empty( $push['cronScheduled'] ) ? __( 'Stale-subscription cleanup is scheduled.', 'dsa' ) : __( 'Push cleanup is not scheduled.', 'dsa' ),
				! empty( $push['wpCronDisabled'] ) ? __( 'DISABLE_WP_CRON is set; confirm a real server cron calls wp-cron.php.', 'dsa' ) : __( 'Low-traffic sites should use a real server cron so order/comment delivery and cleanup are not traffic-dependent.', 'dsa' ),
				'data'
			),
			$this->check(
				'push_crypto_reenrollment',
				__( 'Push cryptographic re-enrollment', 'dsa' ),
				empty( $push['reenrollRequired'] ) ? 'pass' : 'warning',
				empty( $push['reenrollRequired'] ) ? __( 'No devices are waiting for cryptographic re-enrollment.', 'dsa' ) : sprintf( __( '%d device(s) must revisit and replace a subscription after key recovery or rotation.', 'dsa' ), (int) $push['reenrollRequired'] ),
				__( 'Keep browser-notification journeys available so returning devices can replace subscriptions bound to an old VAPID key.', 'dsa' ),
				'data'
			),
			$this->check(
				'future_permissions',
				__( 'Future permissions', 'dsa' ),
				empty( $permissions['location_enabled'] ) && empty( $permissions['camera_enabled'] ) ? 'pass' : 'warning',
				__( 'Location and camera journeys remain roadmap-gated; browser notifications have their own production checks.', 'dsa' ),
				__( 'Keep location and camera asks off until dedicated journeys are implemented and tested.', 'dsa' ),
				'data'
			),
			$this->check(
				'google_reviews',
				__( 'Google review source', 'dsa' ),
				! $google_reviews || ( ! empty( $link_hub['google_place_id'] ) && ! empty( $link_hub['google_api_key'] ) ) ? 'pass' : 'warning',
				$google_reviews ? __( 'Google reviews selected.', 'dsa' ) : __( 'Manual testimonials selected.', 'dsa' ),
				__( 'When Google reviews are selected, configure both Place ID and API key or use manual testimonials.', 'dsa' ),
				'data'
			),
		];
	}

	private function check( string $id, string $label, string $status, string $detail, string $action, string $group ): array {
		if ( ! in_array( $status, [ 'critical', 'warning', 'pass' ], true ) ) {
			$status = 'warning';
		}

		return [
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'action' => $action,
			'group'  => $group,
		];
	}
}
