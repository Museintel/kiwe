<?php
namespace DSA\Discussion;

use DSA\PhoneKey\PhoneKey_Bridge;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Public discussion names are native profile/comment values, never contact fallbacks. */
final class Public_Identity {
	private const CHOSEN = '_kiwe_discussion_name_choice_v1';
	public static function clean( string $name ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_check_invalid_utf8( wp_strip_all_tags( $name ) ) ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );
		if ( $length < 2 || $length > 60 || preg_match( '/[@<>\x00-\x1f\x7f]|https?:|www\./iu', $name ) || preg_match( '/(?:\d[\s().+\-]*){7,}/u', $name ) ) return '';
		return $name;
	}
	private static function contact_like( string $name, string $email, ?\WP_User $user = null ): bool {
		$fold = static fn( $value ) => function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $value ), 'UTF-8' ) : strtolower( trim( (string) $value ) );
		$forbidden = [ 'Phone user', 'Reader' ];
		if ( $email ) $forbidden[] = explode( '@', $email )[0];
		if ( $user ) { $forbidden[] = $user->user_login; if ( $user->user_email ) $forbidden[] = explode( '@', $user->user_email )[0]; }
		return in_array( $fold( $name ), array_map( $fold, $forbidden ), true );
	}
	public static function current_name(): string {
		$user = get_userdata( get_current_user_id() ); if ( ! $user ) return '';
		$name = self::clean( (string) $user->display_name ); if ( ! $name ) return '';
		$chosen = get_user_meta( $user->ID, self::CHOSEN, true );
		if ( is_string( $chosen ) && hash_equals( self::fingerprint( $name ), $chosen ) ) return $name;
		return self::contact_like( $name, (string) $user->user_email, $user ) ? '' : $name;
	}
	private static function fingerprint( string $name ): string { return hash_hmac( 'sha256', $name, wp_salt( 'nonce' ) ); }
	public static function remember_current_name( string $name ): bool {
		$id = get_current_user_id(); $name = self::clean( $name ); if ( ! $id || ! $name ) return false;
		$user = get_userdata( $id ); if ( ! $user ) return false;
		if ( $user->display_name !== $name && is_wp_error( wp_update_user( [ 'ID'=>$id, 'display_name'=>$name ] ) ) ) return false;
		$saved = get_userdata( $id ); if ( ! $saved || $saved->display_name !== $name ) return false;
		// Only a consent fingerprint is adjacent metadata; display_name stays the native value owner.
		update_user_meta( $id, self::CHOSEN, self::fingerprint( $name ) );
		return hash_equals( self::fingerprint( $name ), (string) get_user_meta( $id, self::CHOSEN, true ) );
	}
	public static function erase_name_choice( int $id ): bool {
		if ( $id < 1 ) return true;
		delete_user_meta( $id, self::CHOSEN ); return ! metadata_exists( 'user', $id, self::CHOSEN );
	}
	public static function verified( int $id ): bool { return $id > 0 && PhoneKey_Bridge::account_verified( $id ); }
	public static function actor(): array {
		$id = get_current_user_id(); $security = $id ? PhoneKey_Bridge::security_completion( $id ) : null;
		$verified = $id > 0 && ( is_array( $security ) && array_key_exists( 'identityVerified', $security ) ? (bool) $security['identityVerified'] : self::verified( $id ) );
		return [ 'signedIn'=>$id > 0, 'name'=>self::current_name(), 'verified'=>$verified, 'assurance'=>$id ? ( $verified ? ( ( $security['state'] ?? '' ) === 'verified' ? 'verified' : 'partial' ) : 'subscriber' ) : 'visitor' ];
	}
	public static function comment_name( \WP_Comment $comment ): string {
		$name = self::clean( (string) $comment->comment_author );
		if ( $name && '1' === (string) get_comment_meta( $comment->comment_ID, '_kiwe_discussion_name_explicit_v1', true ) ) return $name;
		$user = $comment->user_id ? get_userdata( (int) $comment->user_id ) : null;
		return $name && ! self::contact_like( $name, (string) $comment->comment_author_email, $user ?: null ) ? $name : __( 'Reader', 'dsa' );
	}
	public static function team_member( \WP_Comment $comment ): bool { return $comment->user_id > 0 && user_can( (int) $comment->user_id, 'moderate_comments' ); }
}
