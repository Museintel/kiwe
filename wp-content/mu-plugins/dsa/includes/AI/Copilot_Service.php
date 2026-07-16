<?php

namespace DSA\AI;

use DSA\Element_Registry;
use DSA\Settings;
use DSA\Trust\Trust_Service;
use DSA\WP7\Native_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Copilot_Service {
	public function __construct(
		private Settings $settings,
		private Element_Registry $registry,
		private Trust_Service $trust,
		private Native_Service $native
	) {}

	public function report(): array {
		$settings = $this->settings->all();
		$link_hub = isset( $settings['link_hub'] ) && is_array( $settings['link_hub'] ) ? $settings['link_hub'] : [];
		$visual   = isset( $settings['visual_effects'] ) && is_array( $settings['visual_effects'] ) ? $settings['visual_effects'] : [];
		$trust    = $this->trust->summary( $link_hub );
		$native   = $this->native->summary();
		$registry = $this->registry->to_array();

		return [
			'available' => current_user_can( 'manage_options' ),
			'mode'      => ( $native['featureFlags']['ai_client'] ?? false ) ? 'native-ready' : 'deterministic',
			'generatedAt' => gmdate( 'c' ),
			'sections'  => [
				$this->trust_section( $trust ),
				$this->transition_section( $visual ),
				$this->secure_section( $trust ),
				$this->geo_section( $registry ),
			],
			'actions'   => [
				[
					'id'    => 'audit-trust',
					'label' => __( 'Audit trust', 'dsa' ),
					'ability' => 'dsa/audit-trust',
				],
				[
					'id'    => 'transition-copy',
					'label' => __( 'Suggest transition copy', 'dsa' ),
					'ability' => 'dsa/create-transition-message',
				],
				[
					'id'    => 'secure-explain',
					'label' => __( 'Explain SecureTrack', 'dsa' ),
					'ability' => 'dsa/scan-security',
				],
				[
					'id'    => 'geo-audit',
					'label' => __( 'Audit GEO readiness', 'dsa' ),
					'ability' => 'dsa/summarize-route',
				],
			],
			'native'    => [
				'aiClient'  => ! empty( $native['featureFlags']['ai_client'] ),
				'abilities' => ! empty( $native['featureFlags']['abilities'] ),
			],
		];
	}

	private function trust_section( array $trust ): array {
		$items = [];

		$items[] = [
			'label'  => __( 'SSL', 'dsa' ),
			'status' => ! empty( $trust['ssl']['active'] ) ? 'good' : 'warning',
			'text'   => ! empty( $trust['ssl']['active'] )
				? sprintf( __( 'SSL is active%s.', 'dsa' ), ! empty( $trust['ssl']['provider'] ) ? ' via ' . $trust['ssl']['provider'] : '' )
				: __( 'SSL is not detected on this request. Protected routes should not advertise checkout trust until HTTPS is active.', 'dsa' ),
		];

		$items[] = [
			'label'  => __( 'Payment', 'dsa' ),
			'status' => ! empty( $trust['payment']['active'] ) ? 'good' : 'warning',
			'text'   => ! empty( $trust['payment']['active'] )
				? sprintf( __( 'Payment trust can mention %s.', 'dsa' ), $trust['payment']['label'] ?? __( 'the active gateway', 'dsa' ) )
				: __( 'No WooCommerce payment gateway or manual provider is visible yet.', 'dsa' ),
		];

		$items[] = [
			'label'  => __( 'PhoneKey', 'dsa' ),
			'status' => ! empty( $trust['phonekey']['active'] ) ? 'good' : 'warning',
			'text'   => ! empty( $trust['phonekey']['active'] )
				? __( 'Kiwe Key can support secure login and future step-up flows.', 'dsa' )
				: __( 'PhoneKey is not active, so profile trust should remain conservative.', 'dsa' ),
		];

		return [
			'id'    => 'trust',
			'title' => __( 'Trust Audit', 'dsa' ),
			'lead'  => __( 'Deterministic signals only. AI may explain these, but cannot invent them.', 'dsa' ),
			'items' => $items,
		];
	}

	private function transition_section( array $visual ): array {
		$messages = isset( $visual['transition_messages'] ) && is_array( $visual['transition_messages'] ) ? $visual['transition_messages'] : [];
		$count    = count(
			array_filter(
				$messages,
				static function ( $message ): bool {
					return is_array( $message ) && ( ! empty( $message['title'] ) || ! empty( $message['message'] ) );
				}
			)
		);
		$site = get_bloginfo( 'name' ) ?: __( 'this appsite', 'dsa' );

		return [
			'id'    => 'transition',
			'title' => __( 'Transition Copy', 'dsa' ),
			'lead'  => sprintf( __( '%d transition message(s) configured.', 'dsa' ), $count ),
			'items' => [
				[
					'label'  => __( 'Suggested trust message', 'dsa' ),
					'status' => 'info',
					'text'   => sprintf( __( '%s is getting your next screen ready.', 'dsa' ), $site ),
				],
				[
					'label'  => __( 'Suggested confidence message', 'dsa' ),
					'status' => 'info',
					'text'   => __( 'Your checkout and account routes stay protected while Kiwe handles the surface.', 'dsa' ),
				],
			],
		];
	}

	private function secure_section( array $trust ): array {
		$active = ! empty( $trust['secure']['active'] );
		$config = function_exists( 'stp_cfg' ) ? stp_cfg() : [];
		$config = is_array( $config ) ? $config : [];

		return [
			'id'    => 'secure',
			'title' => __( 'SecureTrack Explanation', 'dsa' ),
			'lead'  => $active ? __( 'SecureTrack is active and can support admin-only security intelligence.', 'dsa' ) : __( 'SecureTrack is not active or not loaded yet.', 'dsa' ),
			'items' => [
				[
					'label'  => __( 'Rate limiting', 'dsa' ),
					'status' => ! empty( $config['rate_limit_enabled'] ) ? 'info' : 'good',
					'text'   => ! empty( $config['rate_limit_enabled'] )
						? __( 'Rate limiting is enabled. Keep the emergency bypass and false-positive review visible before production.', 'dsa' )
						: __( 'Rate limiting appears disabled or unavailable, which is safer by default until tuned per host.', 'dsa' ),
				],
				[
					'label'  => __( 'Admin dock', 'dsa' ),
					'status' => $active ? 'good' : 'warning',
					'text'   => $active ? __( 'The Secure dock module is limited to administrators.', 'dsa' ) : __( 'Secure module stays hidden until SecureTrack is available.', 'dsa' ),
				],
			],
		];
	}

	private function geo_section( array $registry ): array {
		$elements = isset( $registry['elements'] ) && is_array( $registry['elements'] ) ? $registry['elements'] : [];
		$counts = [
			'heading' => 0,
			'image'   => 0,
			'form'    => 0,
			'link'    => 0,
		];

		foreach ( $elements as $element ) {
			$type = $element['type'] ?? '';

			if ( isset( $counts[ $type ] ) ) {
				$counts[ $type ]++;
			}
		}

		return [
			'id'    => 'geo',
			'title' => __( 'GEO Readiness', 'dsa' ),
			'lead'  => sprintf( __( 'Registry currently sees %d element(s) on this render.', 'dsa' ), (int) ( $registry['count'] ?? count( $elements ) ) ),
			'items' => [
				[
					'label'  => __( 'Headings', 'dsa' ),
					'status' => $counts['heading'] > 0 ? 'good' : 'warning',
					'text'   => $counts['heading'] > 0 ? __( 'Headings are available for route summaries.', 'dsa' ) : __( 'Add clear headings so AI answer engines can understand the page.', 'dsa' ),
				],
				[
					'label'  => __( 'Media context', 'dsa' ),
					'status' => $counts['image'] > 0 ? 'good' : 'info',
					'text'   => $counts['image'] > 0 ? __( 'Images can support product/place/entity context when alt text is strong.', 'dsa' ) : __( 'No image context is visible in the registry yet.', 'dsa' ),
				],
				[
					'label'  => __( 'Action clarity', 'dsa' ),
					'status' => ( $counts['form'] + $counts['link'] ) > 0 ? 'good' : 'info',
					'text'   => __( 'Clear links and forms help DSA explain what a visitor can do next.', 'dsa' ),
				],
			],
		];
	}
}
