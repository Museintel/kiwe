<?php

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BRICKS_DB_TEMPLATE_TYPE', '_bricks_template_type' );
	define( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' );
	define( 'BRICKS_DB_PAGE_HEADER', '_bricks_page_header_2' );
	define( 'BRICKS_DB_PAGE_FOOTER', '_bricks_page_footer_2' );

	class WP_Post {}

	$GLOBALS['kiwe_popup_types'] = [ 101 => 'popup', 202 => 'header' ];
	$GLOBALS['kiwe_popup_data']  = [
		101 => [
			[
				'id'       => 'popup-root',
				'settings' => [ '_attributes' => [ [ 'name' => 'data-seam-popup-ref', 'value' => 'header-abc123:cart-dialog' ] ] ],
			],
		],
		202 => [
			[
				'id'       => 'cart-trigger',
				'settings' => [ '_interactions' => [ [ 'target' => 'popup', 'templateId' => 'seam-popup:header-abc123:cart-dialog' ] ] ],
			],
		],
	];
	$GLOBALS['kiwe_popup_updates'] = [];
	$GLOBALS['kiwe_popup_options'] = [];

	function get_posts( array $args ): array { return [ 101, 202 ]; }
	function get_post_meta( int $post_id, string $key, bool $single ) { return $GLOBALS['kiwe_popup_types'][ $post_id ] ?? ''; }
	function update_post_meta( int $post_id, string $key, $value ): void { $GLOBALS['kiwe_popup_updates'][ $post_id ] = $value; }
	function update_option( string $key, $value, bool $autoload ): void { $GLOBALS['kiwe_popup_options'][ $key ] = $value; }
	function wp_slash( $value ) { return $value; }
}

namespace Bricks {
	final class Database {
		public static function get_data( int $post_id, string $area = '' ): array { return $GLOBALS['kiwe_popup_data'][ $post_id ] ?? []; }
		public static function get_bricks_data_key( string $area = '' ): string { return 'header' === $area ? BRICKS_DB_PAGE_HEADER : ( 'footer' === $area ? BRICKS_DB_PAGE_FOOTER : BRICKS_DB_PAGE_CONTENT ); }
	}
}

namespace DSA\Bricks {
	require_once dirname( __DIR__ ) . '/wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php';
	$service = ( new \ReflectionClass( Bricks_Integration::class ) )->newInstanceWithoutConstructor();
	$service->resolve_compiler_popup_references();
	$resolved = $GLOBALS['kiwe_popup_updates'][202][0]['settings']['_interactions'][0]['templateId'] ?? null;
	$report   = $GLOBALS['kiwe_popup_options']['dsa_bricks_compiler_popup_resolution'] ?? [];
	if ( 101 !== $resolved || 1 !== (int) ( $report['resolved'] ?? 0 ) || 0 !== (int) ( $report['pending'] ?? -1 ) ) {
		fwrite( STDERR, "Popup resolver contract failed.\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "popup resolver ok: placeholder -> template 101\n" );
}
