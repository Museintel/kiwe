<?php
namespace DSA\Commerce;

use DSA\Discussion\Public_Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Store owns rating/purchase rules; Core never loads this on a non-Store target. */
final class Discussion_Review_Adapter {
	private const POLICY = [ 'enabled'=>[ 'woocommerce_enable_reviews','yes' ], 'stars'=>[ 'woocommerce_enable_review_rating','yes' ], 'required'=>[ 'woocommerce_review_rating_required','yes' ], 'buyersOnly'=>[ 'woocommerce_review_rating_verification_required','no' ], 'buyerLabel'=>[ 'woocommerce_review_rating_verification_label','yes' ] ];
	public static function descriptor( int $id ): array {
		$product = wc_get_product( $id );
		return [ 'enabled'=>'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ) && $product && $product->get_reviews_allowed(), 'stars'=>(bool) wc_review_ratings_enabled(), 'starsRequired'=>(bool) wc_review_ratings_enabled() && wc_review_ratings_required(), 'buyerOnly'=>'yes' === get_option( 'woocommerce_review_rating_verification_required' ), 'average'=>$product ? (float) $product->get_average_rating() : 0, 'ratingCount'=>$product ? (int) $product->get_rating_count() : 0 ];
	}
	public static function validate( int $id, int $parent, int $rating ) {
		$rules = self::descriptor( $id );
		if ( ! $rules['enabled'] ) return new \WP_Error( 'kiwe_review_closed', __( 'Reviews are closed for this product.', 'dsa' ), [ 'status'=>403 ] );
		if ( $parent && $rating ) return new \WP_Error( 'kiwe_review_reply_rating', __( 'Replies do not add another product rating.', 'dsa' ), [ 'status'=>400 ] );
		if ( ! $parent && ( ( ! $rules['stars'] && $rating ) || ( $rules['starsRequired'] && ! $rating ) ) ) return new \WP_Error( 'kiwe_review_rating', $rules['stars'] ? __( 'Choose a rating from one to five.', 'dsa' ) : __( 'This product accepts reviews without star ratings.', 'dsa' ), [ 'status'=>400 ] );
		// Match Woo's frontend owner-only policy before its wp_die callback can run.
		if ( ! $parent && $rules['buyerOnly'] && ! self::current_buyer( $id ) ) return new \WP_Error( 'kiwe_review_buyer_required', __( 'Sign in with a verified account that purchased this product to leave a review.', 'dsa' ), [ 'status'=>403 ] );
		return true;
	}
	public static function current_buyer( int $id ): bool {
		$user = get_current_user_id();
		return $user > 0 && Public_Identity::verified( $user ) && (bool) wc_customer_bought_product( '', $user, $id );
	}
	public static function verified_badge( \WP_Comment $comment, array $verified_accounts ): bool {
		return 'yes' === get_option( 'woocommerce_review_rating_verification_label', 'yes' ) && ! $comment->comment_parent && $comment->user_id > 0 && ! empty( $verified_accounts[(int) $comment->user_id] ) && '1' === (string) get_comment_meta( $comment->comment_ID, 'verified', true );
	}
	public static function rating( \WP_Comment $comment ): int { return ! $comment->comment_parent && wc_review_ratings_enabled() ? max( 0, min( 5, (int) get_comment_meta( $comment->comment_ID, 'rating', true ) ) ) : 0; }
	public static function after_rollback( int $id ): void { wc_delete_product_transients( $id ); }
	private static function policy_raw(): array { $out = []; foreach ( self::POLICY as $field=>$definition ) $out[$field] = get_option( $definition[0], $definition[1] ); return $out; }
	public static function policy() {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! ( current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' ) ) || \DSA\Access\Team_Workspace_Service::suspended( get_current_user_id() ) ) return new \WP_Error( 'kiwe_review_policy_denied', __( 'Store review settings are unavailable to this account.', 'dsa' ), [ 'status'=>403 ] );
		$raw = self::policy_raw(); foreach ( $raw as $value ) if ( ! in_array( $value, [ 'yes','no' ], true ) ) return new \WP_Error( 'kiwe_review_policy_value', __( 'A native review setting needs the store owner’s review.', 'dsa' ), [ 'status'=>409 ] );
		return [ 'values'=>array_map( static fn( $v ) => 'yes' === $v, $raw ), 'canEdit'=>current_user_can( 'manage_woocommerce' ), 'expected'=>hash( 'sha256', wp_json_encode( $raw ) ) ];
	}
	public static function save_policy( array $input ) {
		$before = self::policy(); if ( is_wp_error( $before ) ) return $before;
		if ( ! $before['canEdit'] ) return new \WP_Error( 'kiwe_review_policy_denied', __( 'The store settings owner manages review policy.', 'dsa' ), [ 'status'=>403 ] );
		if ( array_diff( array_keys( $input ), [ 'field','value','expected' ] ) || ! is_string( $input['field'] ?? null ) || ! isset( self::POLICY[$input['field']] ) || ! is_bool( $input['value'] ?? null ) || ! is_string( $input['expected'] ?? null ) ) return new \WP_Error( 'kiwe_review_policy_input', __( 'Choose one product-review setting.', 'dsa' ), [ 'status'=>400 ] );
		if ( ! hash_equals( $before['expected'], $input['expected'] ) ) return new \WP_Error( 'kiwe_review_policy_stale', __( 'Review settings changed elsewhere. Reload before saving.', 'dsa' ), [ 'status'=>409 ] );
		$option = self::POLICY[$input['field']][0]; $value = $input['value'] ? 'yes' : 'no'; update_option( $option, $value, false );
		if ( get_option( $option ) !== $value ) return new \WP_Error( 'kiwe_review_policy_write', __( 'WooCommerce did not confirm the review setting. Reload before retrying.', 'dsa' ), [ 'status'=>503 ] );
		return self::policy();
	}
	/** Only authorized private Feedback reads can request account-owned paid-order context. */
	public static function customer_context( array $users, array $verified ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! current_user_can( 'edit_others_shop_orders' ) || count( $users ) > 20 ) return [];
		$out = [];
		foreach ( array_unique( array_map( 'absint', $users ) ) as $id ) {
			if ( ! $id || empty( $verified[$id] ) || ! get_userdata( $id ) ) continue;
			try {
				$orders = wc_get_orders( [ 'customer_id'=>$id, 'status'=>wc_get_is_paid_statuses(), 'limit'=>1, 'orderby'=>'date', 'order'=>'DESC', 'paginate'=>true ] );
				if ( is_object( $orders ) && isset( $orders->total, $orders->orders ) ) {
					$last = $orders->orders[0] ?? null; $date = $last ? $last->get_date_created() : null;
					$out[$id] = [ 'purchases'=>max( 0, (int) $orders->total ), 'lastPurchaseAt'=>$date ? $date->getTimestamp() : 0 ];
				}
			} catch ( \Throwable $error ) { /* Unavailable commerce history is never presented as zero purchases. */ }
		}
		return $out;
	}
}
