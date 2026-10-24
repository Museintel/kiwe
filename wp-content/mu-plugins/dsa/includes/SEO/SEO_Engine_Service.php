<?php
namespace DSA\SEO;

use DSA\Apps\SEO_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Constructed only after the local SEO app is admitted and the native provider is known. */
final class SEO_Engine_Service {
	public function register( \DSA\AI\AI_Broker_Service $broker, \DSA\Onboarding\Design_Context_Profile_Service $profiles, \DSA\Settings $settings, \DSA\Element_Registry $registry ): void {
		if ( ! SEO_App_Service::enabled() ) return;
		( new SEO_Output_Service( $profiles ) )->register();
		( new SEO_Refinement_Service( $broker, $profiles ) )->register();
		( new SEO_Metadata_Migration_Service() )->register();
		( new SEO_Structured_Import_Service() )->register();
		if ( $settings->get( 'enabled', true ) && ! empty( $settings->get( 'schema_geo', [] )['enabled'] ) ) ( new \DSA\Schema\Schema_Geo_Service( $settings, $registry ) )->register();
	}
}
