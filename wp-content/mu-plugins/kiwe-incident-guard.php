<?php
/**
 * Plugin Name: Kiwe Incident Guard
 * Description: Low-memory SEO-spam, upload-resource, and public attack-surface guard for Kiwe.
 * Version: 8.0.0-rc.25
 * Requires PHP: 8.2
 * Author: Kiwelauch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kiwe_incident_guard = __DIR__ . '/dsa/includes/Secure/Incident_Response_Service.php';
if ( is_readable( $kiwe_incident_guard ) ) {
	require_once $kiwe_incident_guard;
	\DSA\Secure\Incident_Response_Service::register_early();
}
