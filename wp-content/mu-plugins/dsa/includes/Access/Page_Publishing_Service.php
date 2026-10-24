<?php

namespace DSA\Access;

use DSA\Onboarding\SEO_Context_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native page publication and recovery authority for the client Pages app. */
final class Page_Publishing_Service {
	public const CAP_PUBLISH = 'kiwe_manage_page_publishing';
	public const CAP_RESTORE = 'kiwe_restore_page_versions';
	private const STATUSES = [ 'draft', 'pending', 'publish', 'private', 'future' ];

	private function page( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && 'page' === $post->post_type && ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ? $post : null;
	}

	public function can_manage_publishing( int $id ): bool {
		return (bool) $this->page( $id ) && ( current_user_can( 'manage_options' ) || current_user_can( self::CAP_PUBLISH ) );
	}

	public function can_restore( int $id ): bool {
		return (bool) $this->page( $id ) && ( current_user_can( 'manage_options' ) || current_user_can( self::CAP_RESTORE ) );
	}

	public function can_publish(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( self::CAP_PUBLISH );
	}

	public static function critical_reason( int $id ): string {
		if ( $id === (int) get_option( 'page_on_front' ) ) return 'This is the website home page. Keep it published and ask the designer before changing that assignment.';
		if ( $id === (int) get_option( 'page_for_posts' ) ) return 'This is the website posts page. Keep it published and ask the designer before changing that assignment.';
		if ( function_exists( 'WC' ) ) foreach ( [ 'woocommerce_shop_page_id','woocommerce_cart_page_id','woocommerce_checkout_page_id','woocommerce_myaccount_page_id','woocommerce_terms_page_id' ] as $key ) if ( $id === (int) get_option( $key, 0 ) && $id>0 ) return 'This page is used by store settings. Reassign its original purpose before changing its publication or moving it to Trash.';
		return '';
	}

	private static function state( \WP_Post $post ): array {
		return [
			(int) $post->ID, (string) $post->post_title, (string) $post->post_content, (string) $post->post_excerpt,
			(string) $post->post_status, (string) $post->post_date, (string) $post->post_date_gmt, (string) $post->post_modified_gmt,
			(int) $post->post_parent, (int) $post->menu_order, (int) get_post_thumbnail_id( $post->ID ),
			(string) get_page_template_slug( $post->ID ), SEO_Context_Service::page_noindex( (int) $post->ID ),
		];
	}

	public static function fingerprint( \WP_Post $post ): string {
		return hash( 'sha256', wp_json_encode( self::state( $post ) ) );
	}

	private static function revision_fingerprint( \WP_Post $revision ): string {
		return Task_Workspace_UI::revision_fingerprint( $revision );
	}

	private static function revision_summary( \WP_Post $revision ): array {
		return [
			'id'=>(int) $revision->ID, 'title'=>sanitize_text_field( (string) $revision->post_title ),
			'excerpt'=>wp_html_excerpt( wp_strip_all_tags( (string) $revision->post_content ), 140, '…' ),
			'readerExcerpt'=>wp_html_excerpt( wp_strip_all_tags( (string) $revision->post_excerpt ), 400, '…' ),
			'modifiedGmt'=>(string) $revision->post_modified_gmt, 'expected'=>self::revision_fingerprint( $revision ),
		];
	}

	private function own_autosave( int $id ) {
		$autosave = wp_get_post_autosave( $id, get_current_user_id() );
		return $autosave instanceof \WP_Post && (int) $autosave->post_parent === $id && (int) $autosave->post_author === get_current_user_id() && (int) wp_is_post_autosave( $autosave ) === $id ? $autosave : null;
	}

	private function revision( int $id, int $revision_id ) {
		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision || (int) $revision->post_parent !== $id ) return new \WP_Error( 'kiwe_page_revision', 'That saved page version is unavailable.', [ 'status'=>404 ] );
		if ( wp_is_post_autosave( $revision ) ) {
			$own = $this->own_autosave( $id );
			if ( ! $own || (int) $own->ID !== $revision_id ) return new \WP_Error( 'kiwe_page_revision', 'That saved page version is unavailable.', [ 'status'=>404 ] );
		}
		return $revision;
	}

	/** Bounded native version summaries. Autosaves remain private to their WordPress author. */
	public function revisions( int $id ) {
		if ( ! $this->can_restore( $id ) ) return new \WP_Error( 'kiwe_page_forbidden', 'Saved versions are unavailable for this page.', [ 'status'=>403 ] );
		$post = get_post( $id );
		$rows = wp_get_post_revisions( $id, [ 'posts_per_page'=>9, 'orderby'=>'date', 'order'=>'DESC', 'check_enabled'=>false ] );
		$items = [];
		foreach ( is_array( $rows ) ? $rows : [] as $revision ) {
			if ( ! $revision instanceof \WP_Post || (int) $revision->post_parent !== $id || wp_is_post_autosave( $revision ) ) continue;
			$items[] = self::revision_summary( $revision );
			if ( 8 === count( $items ) ) break;
		}
		$autosave = $this->own_autosave( $id );
		$recovery = null;
		if ( $autosave && ! Task_Workspace_UI::revision_text_matches( $autosave, $post ) ) $recovery = self::revision_summary( $autosave );
		return [ 'pageId'=>$id, 'expected'=>self::fingerprint( $post ), 'autosave'=>$recovery, 'revisions'=>$items ];
	}

	/** Change only native visibility and publication time; layout and relationships are preserved. */
	public function save( int $id, array $input ) {
		$keys = array_keys( $input ); sort( $keys );
		if ( [ 'expected', 'scheduledLocal', 'status' ] !== $keys || ! is_string( $input['expected'] ?? null ) || ! is_string( $input['scheduledLocal'] ?? null ) || ! is_string( $input['status'] ?? null ) || ! in_array( $input['status'], self::STATUSES, true ) ) return new \WP_Error( 'kiwe_page_publishing_input', 'Choose a valid page visibility and time.', [ 'status'=>400 ] );
		if ( ! $this->can_manage_publishing( $id ) ) return new \WP_Error( 'kiwe_page_forbidden', 'Publishing is unavailable for this page.', [ 'status'=>403 ] );
		$post = get_post( $id );
		if ( ! hash_equals( self::fingerprint( $post ), $input['expected'] ) ) return new \WP_Error( 'kiwe_page_stale', 'This page changed elsewhere. Reload before changing publication.', [ 'status'=>409 ] );
		$status = $input['status'];
		if ( in_array( $status, [ 'publish','private','future' ], true ) && ! $this->can_publish() ) return new \WP_Error( 'kiwe_page_publish', 'Your role can submit this page for review but cannot publish or schedule it.', [ 'status'=>403 ] );
		$critical = self::critical_reason( $id );
		if ( $critical && 'publish' !== $status ) return new \WP_Error( 'kiwe_page_critical', $critical, [ 'status'=>409 ] );
		$scheduled = null;
		if ( 'future' === $status ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $input['scheduledLocal'] ) ) return new \WP_Error( 'kiwe_page_schedule', 'Choose a date and time in the website timezone.', [ 'status'=>400 ] );
			$scheduled = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $input['scheduledLocal'], wp_timezone() );
			if ( ! $scheduled || $scheduled->format( 'Y-m-d\TH:i' ) !== $input['scheduledLocal'] || $scheduled->getTimestamp() < time() + 300 ) return new \WP_Error( 'kiwe_page_schedule', 'Choose a valid time at least five minutes from now.', [ 'status'=>400 ] );
		} elseif ( '' !== $input['scheduledLocal'] ) return new \WP_Error( 'kiwe_page_schedule', 'A scheduled time is only used when you choose Schedule.', [ 'status'=>400 ] );
		if ( $status === $post->post_status && ( 'future' !== $status || substr( str_replace( ' ', 'T', (string) $post->post_date ), 0, 16 ) === $input['scheduledLocal'] ) ) return true;
		$before = self::state( $post );
		$fields = [ 'ID'=>$id, 'post_status'=>$status ];
		if ( $scheduled ) {
			// Core preserves a draft/pending date unless an intentional date edit is explicit.
			$fields['edit_date'] = true;
			$fields['post_date'] = $scheduled->format( 'Y-m-d H:i:s' );
			$fields['post_date_gmt'] = $scheduled->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} elseif ( 'future' === $post->post_status ) {
			$fields['edit_date'] = true;
			$fields['post_date'] = current_time( 'mysql' );
			$fields['post_date_gmt'] = current_time( 'mysql', true );
		}
		$saved = wp_update_post( wp_slash( $fields ), true );
		if ( is_wp_error( $saved ) || (int) $saved !== $id ) return new \WP_Error( 'kiwe_page_publishing_write', 'WordPress could not save that publication change.', [ 'status'=>500 ] );
		$after = get_post( $id ); $state = self::state( $after );
		foreach ( [ 0,1,2,3,8,9,10,11,12 ] as $index ) if ( $before[$index] !== $state[$index] ) return new \WP_Error( 'kiwe_page_publishing_unconfirmed', 'The page changed during publication. Reload and review it.', [ 'status'=>409 ] );
		if ( $state[4] !== $status || ( $scheduled && substr( str_replace( ' ', 'T', $state[5] ), 0, 16 ) !== $scheduled->format( 'Y-m-d\TH:i' ) ) ) return new \WP_Error( 'kiwe_page_publishing_unconfirmed', 'The publication change could not be confirmed. Reload and review it.', [ 'status'=>409 ] );
		return true;
	}

	/** Discard only this writer's reviewed WordPress recovery copy. */
	public function discard_autosave( int $id, int $revision_id, array $input ) {
		$keys = array_keys( $input ); sort( $keys );
		if ( [ 'expected','recoveryExpected' ] !== $keys || ! is_string( $input['expected'] ?? null ) || ! is_string( $input['recoveryExpected'] ?? null ) ) return new \WP_Error( 'kiwe_page_revision_input', 'Reload the recovery copy before discarding it.', [ 'status'=>400 ] );
		if ( ! $this->can_restore( $id ) ) return new \WP_Error( 'kiwe_page_forbidden', 'Recovery is unavailable for this page.', [ 'status'=>403 ] );
		$post = get_post( $id );
		if ( ! hash_equals( self::fingerprint( $post ), $input['expected'] ) ) return new \WP_Error( 'kiwe_page_stale', 'This page changed elsewhere. Reload before discarding recovery.', [ 'status'=>409 ] );
		$autosave = $this->own_autosave( $id );
		if ( ! $autosave || (int) $autosave->ID !== $revision_id || ! hash_equals( self::revision_fingerprint( $autosave ), $input['recoveryExpected'] ) ) return new \WP_Error( 'kiwe_page_revision_stale', 'That recovery copy changed or is unavailable.', [ 'status'=>409 ] );
		if ( ! wp_delete_post_revision( $revision_id ) || $this->own_autosave( $id ) || ! hash_equals( self::fingerprint( $post ), self::fingerprint( get_post( $id ) ) ) ) return new \WP_Error( 'kiwe_page_recovery_delete', 'WordPress could not confirm the recovery copy was discarded.', [ 'status'=>409 ] );
		return true;
	}

	/** Restore native revision text; publication, hierarchy, template, image and indexing stay unchanged. */
	public function restore( int $id, int $revision_id, array $input ) {
		$keys = array_keys( $input ); sort( $keys );
		if ( [ 'expected', 'revisionExpected' ] !== $keys || ! is_string( $input['expected'] ?? null ) || ! is_string( $input['revisionExpected'] ?? null ) ) return new \WP_Error( 'kiwe_page_revision_input', 'Reload the saved page versions before restoring.', [ 'status'=>400 ] );
		if ( ! $this->can_restore( $id ) ) return new \WP_Error( 'kiwe_page_forbidden', 'Saved versions are unavailable for this page.', [ 'status'=>403 ] );
		$post = get_post( $id );
		if ( ! hash_equals( self::fingerprint( $post ), $input['expected'] ) ) return new \WP_Error( 'kiwe_page_stale', 'This page changed elsewhere. Reload before restoring.', [ 'status'=>409 ] );
		$revision = $this->revision( $id, $revision_id ); if ( is_wp_error( $revision ) ) return $revision;
		if ( ! hash_equals( self::revision_fingerprint( $revision ), $input['revisionExpected'] ) ) return new \WP_Error( 'kiwe_page_revision_stale', 'This saved page version changed. Reload before restoring.', [ 'status'=>409 ] );
		$before = self::state( $post );
		$restored = Task_Workspace_UI::restore_revision_writing( $post, $revision_id );
		if ( is_wp_error( $restored ) || (int) $restored !== $id ) return new \WP_Error( 'kiwe_page_restore', 'WordPress could not confirm the page restore. Reload before retrying.', [ 'status'=>409 ] );
		$after = get_post( $id ); $state = self::state( $after );
		foreach ( [ 4,5,6,8,9,10,11,12 ] as $index ) if ( $before[$index] !== $state[$index] ) return new \WP_Error( 'kiwe_page_restore_unconfirmed', 'The restore changed a protected page setting. Reload and review the page.', [ 'status'=>409 ] );
		if ( ! Task_Workspace_UI::revision_text_matches( $revision, $after ) ) return new \WP_Error( 'kiwe_page_restore_unconfirmed', 'The restored page could not be confirmed. Reload and review it.', [ 'status'=>409 ] );
		return true;
	}
}
