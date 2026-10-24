<?php

namespace DSA\Runtime;

if (!defined('ABSPATH')) exit;

/** Batches the small controls already read during boot into WordPress's cache. */
final class Request_Option_Primer {
    public static function prime(array $settings): void {
        if (!function_exists('wp_prime_option_caches')) return;
        $keys = [
            'dsa_package_manifest_proof', 'kiwe_settings_schema',
            'kiwe_access_policy_v1', 'dsa_rate_limit_db_version',
            'kiwe_store_app_enabled_v1', 'kiwe_seo_workspace_enabled_v1', 'kiwe_seo_defaults_v1',
            'kiwe_seo_redirect_store_v1',
            'dsa_clean_conversion_runtime_v1',
        ];
        $activePlugins = get_option('active_plugins', []);
        if (is_array($activePlugins) && in_array('woocommerce/woocommerce.php', $activePlugins, true)) {
            $keys[] = 'kiwe_restored_store_v1';
        }
        if (!empty($settings['phonekey']['enabled'])) {
            $keys = array_merge($keys, [
                'pk_settings_v3', 'pk_db_ver', 'pk_stage3_policy_defaults',
                'pk_subscriber_signup_default_v1', 'pk_editorial_access_policy_v1',
                'pk_session_timeout_default_off_v1', 'pk_admin_enrollment_flagged',
                'pk_privileged_editorial_accounts_flagged_v1', 'pk_crypto_key_id',
            ]);
        }
        if (!empty($settings['secure']['enabled'])) {
            $keys = array_merge($keys, [
                'stp_settings', 'stp_endpoint_rate_limit_default_off_v1',
                'stp_idle_timeout_default_off_v1', 'stp_ai_broker_secret_cleanup_v1',
            ]);
        }
        if (!empty($settings['metrics']['enabled']) || !empty($settings['abandoned_cart']['enabled']) || !empty($settings['commerce']['linked_products_enabled'])) {
            $keys[] = 'dsa_store_analytics_db_version';
        }
        if (!empty($settings['permissions']['enabled'])) {
            if (!empty($settings['permissions']['notifications_enabled'])) {
                $keys[] = 'dsa_push_db_version';
                $keys[] = 'dsa_push_active_jobs';
            }
            if (!empty($settings['permissions']['notification_preferences_enabled'])) $keys[] = 'dsa_notification_preferences_db_version';
        }
        global $wpdb;
        $failed = false;
        try {
            wp_prime_option_caches($keys);
            $failed = !empty($wpdb->last_error);
        } catch (\Throwable $error) {
            // This optimization cannot prevent the existing auth lane loading.
            $failed = true;
        }
        if ($failed) {
            // Core's batch reader also caches absent keys. A failed SELECT must
            // not turn an existing control into a missing/default control.
            foreach ($keys as $key) wp_cache_delete($key, 'options');
            $missing = wp_cache_get('notoptions', 'options');
            if (is_array($missing)) {
                foreach ($keys as $key) unset($missing[$key]);
                wp_cache_set('notoptions', $missing, 'options');
            }
        }
    }
}
