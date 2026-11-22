<?php
namespace DSA\Link_Hub;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Public-only sharing. No recipient business, enquiry, account, consent or sending side effects. */
final class Share_Service {
	public static function document( int $id ): array {
		$subject = Public_Subject_Service::resolve( $id );
		return self::for_subject( $subject );
	}

	/** Receives only the native public-subject owner's already-resolved projection. */
	public static function for_subject( array $subject ): array {
		$text = $subject['title'] . "\n" . $subject['url'];
		preg_match( '/\A.{0,100}/us', $subject['title'], $x_title );
		$links = [
			'whatsapp'=>'https://wa.me/?text=' . rawurlencode( $text ),
			'email'=>'mailto:?subject=' . rawurlencode( $subject['title'] ) . '&body=' . rawurlencode( str_replace( "\n", "\r\n", $text ) ),
			'x'=>'https://x.com/intent/tweet?text=' . rawurlencode( $x_title[0] ?? '' ) . '&url=' . rawurlencode( $subject['url'] ),
			'facebook'=>'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $subject['url'] ),
			'linkedin'=>'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $subject['url'] ),
			'pinterest'=>'https://www.pinterest.com/pin/create/button/?url=' . rawurlencode( $subject['url'] ) . '&description=' . rawurlencode( $subject['title'] ),
		];
		return [ 'schema'=>'kiwe.public-share.v1', 'subject'=>$subject, 'text'=>$text, 'links'=>$subject['url'] ? $links : [], 'nativeChooser'=>true, 'copyLink'=>true, 'sent'=>false ];
	}
}
