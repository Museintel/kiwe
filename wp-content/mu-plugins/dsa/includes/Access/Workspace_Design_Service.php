<?php
namespace DSA\Access;

use DSA\Design\Seam_Token_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Small admin projection of the existing DSA/Seam token registry; no second token store. */
final class Workspace_Design_Service {
	public static function stylesheet(): string {
		$names = [ 'color-brand','color-accent','color-surface','color-surface-raised','color-surface-sunken','color-text','color-text-muted','color-text-inverse','color-border','color-danger','font-body','font-display','type-body','type-sm','type-h5','type-h3','leading-normal','space-xs','space-sm','space-md','space-lg','radius-md','radius-lg','radius-xl','radius-full' ];
		$variables = Seam_Token_Service::css_variables();
		$css = ':where(.kiwe-workspace-admin,.kiwe-tasks,.kiwe-workspace-notice,.kiwe-onboarding){';
		foreach ( $names as $name ) $css .= '--kiwe-' . $name . ':' . $variables[ '--kiwe-' . $name ] . ';';
		return $css . '}';
	}
	public static function enqueue(): void {
		if ( wp_style_is( 'kiwe-app-primitives', 'enqueued' ) ) return;
		wp_enqueue_style( 'kiwe-workspace-tokens', DSA_URL . 'assets/css/workspace-tokens.css', [], DSA_VERSION );
		wp_enqueue_style( 'kiwe-app-primitives', DSA_URL . 'assets/css/app-primitives.css', [ 'kiwe-workspace-tokens' ], DSA_VERSION );
		wp_add_inline_style( 'kiwe-app-primitives', self::stylesheet() );
	}

	/** Private context overlay of the canonical registry; no settings or second token store. */
	public static function workspace_stylesheet(): string {
		$variables = Seam_Token_Service::css_variables( [
			'color-brand'=>'#20352b', 'color-text'=>'#20352b', 'color-surface'=>'#f8f7f3',
			'color-border'=>'#d9dfd2', 'color-surface-raised'=>'#ffffff',
			'color-text-muted'=>'#536358', 'color-text-inverse'=>'#ffffff',
			'font-body'=>'system-ui,-apple-system,"Segoe UI",sans-serif',
			'font-display'=>'system-ui,-apple-system,"Segoe UI",sans-serif',
		] );
		$css = Seam_Token_Service::workspace_layout_stylesheet() . ':where(.kiwe-client-shell,.kiwe-screen){';
		foreach ( $variables as $name=>$value ) $css .= $name . ':' . $value . ';';
		// The PHP Home's established paper/ink differ intentionally from task screens.
		return $css . '}.kiwe-client-shell{--kiwe-color-brand:#212823;--kiwe-color-text:#212823;--kiwe-color-surface:#f7f6f2;--kiwe-color-border:#d9ddcd}';
	}

	/** Native documents print selected handles; an explicit dependency keeps the foundation present. */
	public static function app_dependencies( array $dependencies = [] ): array {
		wp_enqueue_style( 'kiwe-workspace-foundation', DSA_URL . 'assets/css/workspace-foundation.css', [], DSA_VERSION );
		return array_values( array_unique( array_merge( $dependencies, [ 'kiwe-workspace-foundation' ] ) ) );
	}
}
