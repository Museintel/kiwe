<?php

namespace DSA\Bricks;

use DSA\Access\Page_Publishing_Service;
use DSA\Access\Site_Owner_Service;
use DSA\Security\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Replace the assigned WordPress homepage's native Bricks body after exact review. */
final class Homepage_Publication_Service {
	private const RECOVERY = '_kiwe_homepage_layout_recovery_v1';
	private const PENDING_RECOVERY = '_kiwe_homepage_layout_pending_v1';
	private const MAX_RECOVERY_BYTES = 8388608;

	public static function available(): bool {
		return defined( 'BRICKS_DB_PAGE_CONTENT' )
			&& is_callable( [ \Bricks\Database::class, 'find_template_id' ] )
			&& is_callable( [ \Bricks\Database::class, 'get_all_templates_by_type' ] )
			&& Secret_Store::supports_native_encryption();
	}

	public static function allowed(): bool {
		$owner = Site_Owner_Service::owner_id();
		return self::available() && ! is_multisite() && current_user_can( 'publish_pages' )
			&& ( $owner ? Site_Owner_Service::is_owner() : current_user_can( 'manage_options' ) );
	}

	private function page(): \WP_Post {
		if ( ! self::allowed() ) throw new Compiler_Package_Error( 'Only the WordPress site owner can publish this homepage design.' );
		$id = (int) get_option( 'page_on_front' );
		$post = get_post( $id );
		if ( get_option( 'show_on_front' ) !== 'page' || $id < 1 || ! $post || $post->post_type !== 'page'
			|| $post->post_status !== 'publish' || $post->post_password !== '' || ! current_user_can( 'edit_post', $id )
			|| ! ( new Page_Publishing_Service() )->can_manage_publishing( $id ) ) {
			throw new Compiler_Package_Error( 'Assign a public static homepage in WordPress Reading settings before publishing its Bricks design.' );
		}
		foreach ( [ 'page_for_posts', 'wp_page_for_privacy_policy', 'woocommerce_shop_page_id', 'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id' ] as $setting ) {
			if ( $id === (int) get_option( $setting ) ) {
				throw new Compiler_Package_Error( 'The assigned homepage also serves another WordPress or WooCommerce route. Review the assignment first.' );
			}
		}
		if ( ! \Bricks\Helpers::is_post_type_supported( $id ) ) throw new Compiler_Package_Error( 'Enable Bricks editing for the assigned homepage.' );
		return $post;
	}

	private function layout( int $id ): array {
		$rows = get_post_meta( $id, BRICKS_DB_PAGE_CONTENT, false );
		if ( count( $rows ) > 1 || ( $rows && ! is_array( $rows[0] ) ) ) {
			throw new Compiler_Package_Error( 'The homepage has unexpected native layout storage. Review it in Bricks.' );
		}
		return $rows;
	}

	private function read_recovery( int $id, string $key ): ?array {
		$value = get_post_meta( $id, $key, true );
		if ( $value === '' ) return null;
		if ( ! is_string( $value ) ) throw new Compiler_Package_Error( 'The homepage recovery record needs review.' );
		$data = Secret_Store::decrypt_with_status( $value );
		$record = $data['status'] === 'ok' ? json_decode( $data['value'], true, 64 ) : null;
		if ( ! is_array( $record ) || ( $record['schema'] ?? 0 ) !== 1 || ! is_string( $record['website'] ?? null ) ) {
			throw new Compiler_Package_Error( 'The homepage recovery record could not be verified.' );
		}
		if ( $record['website'] !== home_url( '/' ) ) return null;
		if ( ( $record['page'] ?? 0 ) !== $id || ! is_array( $record['previous'] ?? null ) ) {
			throw new Compiler_Package_Error( 'The homepage recovery record could not be verified.' );
		}
		return $record;
	}

	private function recovery( int $id, array $current ): ?array {
		$pending = $this->read_recovery( $id, self::PENDING_RECOVERY );
		if ( $pending && $pending['publishedHash'] === $this->hash( $current ) ) return $pending;
		return $this->read_recovery( $id, self::RECOVERY );
	}

	private function save_recovery( int $id, string $key, array $record ): void {
		if ( $this->read_recovery( $id, $key ) === $record ) return;
		$json = json_encode( $record, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		if ( strlen( $json ) > self::MAX_RECOVERY_BYTES || json_decode( $json, true, 64, JSON_THROW_ON_ERROR ) !== $record ) {
			throw new Compiler_Package_Error( 'The previous homepage layout cannot be saved for exact recovery.' );
		}
		$sealed = Secret_Store::encrypt( $json );
		if ( '' === $sealed || ! update_post_meta( $id, $key, $sealed ) || $this->read_recovery( $id, $key ) !== $record ) {
			throw new Compiler_Package_Error( 'The previous homepage layout could not be saved for recovery.' );
		}
	}

	private function hash( array $value ): string { return hash( 'sha256', serialize( $value ) ); }

	private function identify( array $value ): array {
		return [ 'job'=>$value['job'], 'archiveSha256'=>$value['archiveSha256'], 'draftId'=>$value['draftId'] ];
	}

	private function receipt( array $value, int $page, string $status ): array {
		return [ 'schema'=>'kiwe.bricks-homepage-publication.v1', 'status'=>$status, 'pageId'=>$page, 'url'=>home_url( '/' ) ]
			+ $this->identify( $value );
	}

	public function status( array $args ): array {
		if ( ! self::allowed() ) throw new Compiler_Package_Error( 'Only the WordPress site owner can view this publication.' );
		$assignment = ( new Homepage_Assignment_Service() )->status( $args );
		if ( $assignment ) {
			$receipt = $this->receipt( $args, $assignment['page'], $assignment['status'] );
			return isset( $assignment['blogUrl'] ) ? $receipt + [ 'blogUrl'=>$assignment['blogUrl'] ] : $receipt;
		}
		$post = $this->page();
		$current = $this->layout( $post->ID );
		$recovery = $this->recovery( $post->ID, $current );
		$status = 'draft_ready';
		if ( $recovery && $this->identify( $recovery ) === $this->identify( $args ) ) {
			$status = match ( $this->hash( $current ) ) {
				$recovery['publishedHash'] => 'published',
				$this->hash( $recovery['previous'] ) => 'restored',
				default => 'review_current_homepage',
			};
		}
		return $this->receipt( $args, $post->ID, $status );
	}

	public function plan( array $args, bool $restore ): array {
		$assignment = new Homepage_Assignment_Service();
		if ( $restore ? $assignment->owns_recovery( $args ) : get_option( 'show_on_front' ) === 'posts' ) {
			return $assignment->plan( $args, $restore );
		}
		$post = $this->page();
		$id = $post->ID;
		$templates = \Bricks\Database::get_all_templates_by_type();
		$overrides = (int) \Bricks\Database::find_template_id( $templates, 'content', 'content', $id, '' );
		if ( $overrides && $overrides !== $id ) {
			throw new Compiler_Package_Error( 'A separate Bricks template controls the homepage. Review its native conditions before publishing page content.' );
		}
		$current = $this->layout( $id );
		$recovery = $this->recovery( $id, $current );
		if ( $restore ) {
			if ( ! $recovery || $this->identify( $recovery ) !== $this->identify( $args )
				|| $this->hash( $current ) !== $recovery['publishedHash'] ) {
				throw new Compiler_Package_Error( 'The current layout has changed or has no matching recovery record.' );
			}
			$next = $recovery['previous'];
		} else {
			$next = [ ( new Compiler_Import_Service() )->homepage_content( $args['job'], $args['archiveSha256'], $args['draftId'] ) ];
			if ( $next === $current ) throw new Compiler_Package_Error( 'This Bricks layout is already on the homepage.' );
		}
		$expected = $this->hash( [
			$this->identify( $args ), Page_Publishing_Service::fingerprint( $post ),
			get_post_meta( $id, BRICKS_DB_PAGE_SETTINGS, true ), $current, $next, $recovery,
			Framework_Style_Service::fingerprint( Framework_Style_Service::snapshot() ),
		] );
		return [ 'post'=>$post, 'current'=>$current, 'next'=>$next, 'recovery'=>$recovery, 'expected'=>$expected ];
	}

	public function apply( array $args, string $expected, bool $restore ): array {
		$plan = $this->plan( $args, $restore );
		if ( ! hash_equals( $plan['expected'], $expected ) ) {
			throw new Compiler_Package_Error( 'The homepage or draft changed. Open a new publication review.' );
		}
		if ( ! empty( $plan['assignment'] ) ) {
			$id = ( new Homepage_Assignment_Service() )->apply( $args, $plan, $restore );
			$receipt = $this->receipt( $args, $id, $restore ? 'restored' : 'published' );
			return $restore ? $receipt : $receipt + [ 'blogUrl'=>get_permalink( (int) get_option( 'page_for_posts' ) ) ];
		}
		$id = $plan['post']->ID;
		$current = $plan['current'];
		$next = $plan['next'];
		if ( $next ) {
			$checked = \Bricks\Helpers::security_check_elements_before_save( wp_slash( $next[0] ), $id, 'content' );
			if ( wp_unslash( $checked ) !== $next[0] ) throw new Compiler_Package_Error( 'Bricks would change the layout when saving it on this page.' );
		}
		$record = $plan['recovery'];
		if ( $record ) $this->save_recovery( $id, self::RECOVERY, $record );
		if ( ! $restore ) {
			$record = $this->identify( $args ) + [
				'schema'=>1, 'page'=>$id, 'website'=>home_url( '/' ), 'previous'=>$current, 'publishedHash'=>$this->hash( $next ),
			];
			$this->save_recovery( $id, self::PENDING_RECOVERY, $record );
			$held = ( new Compiler_Import_Service() )->homepage_content( $args['job'], $args['archiveSha256'], $args['draftId'], true );
			if ( $next !== [ $held ] ) throw new Compiler_Package_Error( 'The reviewed Bricks draft changed.' );
		}
		wp_cache_delete( $id, 'post_meta' );
		if ( $this->page()->ID !== $id || $this->layout( $id ) !== $current ) {
			throw new Compiler_Package_Error( 'The homepage changed during review. Its newer layout is preserved.' );
		}
		if ( ! $next ) {
			delete_post_meta( $id, BRICKS_DB_PAGE_CONTENT, wp_slash( $current[0] ) );
		} elseif ( ! $current ) {
			add_post_meta( $id, BRICKS_DB_PAGE_CONTENT, wp_slash( $next[0] ), true );
		} else {
			update_post_meta( $id, BRICKS_DB_PAGE_CONTENT, wp_slash( $next[0] ), $current[0] );
		}
		if ( $this->layout( $id ) !== $next ) {
			throw new Compiler_Package_Error( 'WordPress did not confirm the homepage layout. Read the native state before retrying.' );
		}
		if ( ! $restore ) $this->save_recovery( $id, self::RECOVERY, $record );
		delete_post_meta( $id, self::PENDING_RECOVERY );
		if ( \Bricks\Database::get_setting( 'cssLoading' ) === 'file' ) {
			\Bricks\Assets_Files::generate_post_css_file( $id, 'content', $next[0] ?? [] );
		}
		clean_post_cache( $id );
		do_action( 'litespeed_purge_post', $id );
		return $this->receipt( $args, $id, $restore ? 'restored' : 'published' );
	}
}
