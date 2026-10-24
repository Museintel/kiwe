<?php
namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Presentation only: source services own eligibility, links and completion. */
final class Workspace_Notice_UI {
	/** Capture the same WordPress/Kiwe alerts that wp-admin would print, once per document. */
	public static function capture_admin_notices(): string {
		ob_start();
		do_action( 'admin_notices' );
		return (string) ob_get_clean();
	}

	public static function notice_count( string $markup ): int {
		if ( '' === trim( $markup ) ) return 0;
		return preg_match_all( '/<(?:div|section|p)\b[^>]*\bclass\s*=\s*["\'][^"\']*\b(?:notice|update-nag|kiwe-workspace-notice)\b/i', $markup );
	}

	public static function enqueue(): void {
		Workspace_Design_Service::enqueue();
		wp_enqueue_style( 'kiwe-workspace-notices', DSA_URL . 'assets/css/workspace-notices.css', [ 'kiwe-app-primitives' ], DSA_VERSION );
	}

	public static function render( string $priority, string $title, string $message, string $url, string $action, array $steps = [] ): void {
		$priority = 'warning' === $priority ? 'warning' : 'info';
		echo '<section class="notice notice-' . esc_attr( $priority ) . ' kiwe-workspace-notice" aria-label="' . esc_attr( $title ) . '"><div class="kiwe-workspace-notice__main"><div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $message ) . '</p></div><a class="kiwe-app-link" href="' . esc_url( $url ) . '">' . esc_html( $action ) . '</a></div>';
		if ( $steps ) {
			echo '<details><summary>' . esc_html( sprintf( _n( 'Review %d remaining step', 'Review %d remaining steps', count( $steps ), 'dsa' ), count( $steps ) ) ) . '</summary><ul>';
			foreach ( $steps as $step ) echo '<li>' . esc_html( $step ) . '</li>';
			echo '</ul><p>' . esc_html__( 'Save your work before continuing. Complete these steps through Kiwe sign-in to protect access from new devices.', 'dsa' ) . '</p></details>';
		}
		echo '</section>';
	}
}
