<?php

namespace DSA\Link_Hub;

use DSA\Onboarding\Design_Context_Enhancement_Service;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Public business destinations only. This service neither identifies visitors nor sends messages. */
final class Channel_Service {

	public static function social_labels(): array {
		return [ 'facebook'=>'Facebook', 'instagram'=>'Instagram', 'x'=>'X', 'youtube'=>'YouTube', 'pinterest'=>'Pinterest', 'linkedin'=>'LinkedIn' ];
	}

	/** Static discovery is separate from admission of an actual visitor journey. */
	public static function contract(): array {
		return [
			'schema'=>'kiwe.links.actions.v1',
			'authority'=>'kiwe-design-context-and-native-site-identity',
			'implemented'=>[ 'profile', 'direct-contact', 'enquiry', 'contextual-share' ],
			'pending'=>[],
			'attributes'=>[
				'profile'=>'data-kiwe-social', 'directContact'=>'data-kiwe-contact',
				'enquiry'=>[ 'name'=>'data-kiwe-chat', 'available'=>true ],
				'share'=>[ 'name'=>'data-kiwe-share', 'available'=>true ],
				'subject'=>'data-kiwe-subject-id',
			],
			'channels'=>[
				'whatsapp'=>[ 'contactField'=>'phone', 'prefill'=>'text', 'requires'=>'configured-business-number' ],
				'email'=>[ 'contactField'=>'email', 'prefill'=>'subject-and-body', 'requires'=>'configured-business-email' ],
				'x'=>[ 'contactField'=>'contact', 'prefill'=>'text', 'requires'=>'qualified-numeric-recipient-or-message-link' ],
				'instagram'=>[ 'contactField'=>'contact', 'prefill'=>'unqualified', 'requires'=>'qualified-platform-and-account-messaging-link' ],
				'facebook'=>[ 'contactField'=>'contact', 'prefill'=>'unqualified', 'requires'=>'qualified-platform-and-account-messaging-link' ],
			],
			'privacy'=>[ 'visitorIdentityIncluded'=>false, 'marketingConsentImplied'=>false, 'handoffProvesSending'=>false ],
			'formContact'=>\DSA\Identity\Form_Contact_Service::contract(),
			'composer'=>[ 'openingMessage'=>'server-public-post-product-title', 'editable'=>true, 'retainedDraftWins'=>true ],
			'icons'=>[ 'chat'=>'{kiwe_icon_chat}', 'share'=>'{kiwe_icon_share}' ],
		];
	}

	/** Resolve the same existing public owners used by page contact attributes. No provider requests. */
	public static function site_actions(): array {
		$profile = ( new Design_Context_Enhancement_Service() )->resolved_profile();
		$contact = is_array( $profile['contact'] ?? null ) ? $profile['contact'] : [];
		$phone = preg_replace( '/[^0-9+]/', '', Site_Identity_Service::store_phone() );
		$email = sanitize_email( Site_Identity_Service::store_email() );
		$whatsapp = preg_replace( '/\D+/', '', is_string( $contact['whatsapp'] ?? null ) ? $contact['whatsapp'] : '' );
		$socials = [];
		foreach ( self::social_labels() as $id=>$label ) {
			$url = self::public_url( $contact['socialLinks'][$id] ?? '' );
			if ( '' !== $url ) $socials[$id] = $url;
		}
		$messaging = [];
		foreach ( [ 'x','instagram','facebook' ] as $id ) {
			$explicit = $contact['messagingLinks'][$id] ?? '';
			$url = '' !== $explicit ? self::messaging_url( $id, $explicit ) : self::profile_message_url( $id, $socials[$id] ?? '' );
			if ( $url ) $messaging[$id] = [ 'id'=>$id, 'label'=>self::social_labels()[$id], 'url'=>$url, 'kind'=>'messaging' ];
		}
		return [
			'phone'=>$phone ? esc_url_raw( 'tel:' . $phone ) : '',
			'email'=>$email ? esc_url_raw( 'mailto:' . $email ) : '',
			'whatsapp'=>$whatsapp ? esc_url_raw( 'https://wa.me/' . $whatsapp ) : '',
			'socials'=>$socials,
			'messaging'=>$messaging,
		];
	}

	/** No new settings: deliberately missing/invalid public contacts produce no row. */
	public static function contact_rows( array $actions ): array {
		$out = [];
		$whatsapp = $actions['whatsapp'] ?? '';
		if ( is_string( $whatsapp ) && preg_match( '~^https://wa\.me/[1-9][0-9]{5,14}$~D', $whatsapp ) ) {
			$out[] = [ 'id'=>'whatsapp', 'label'=>'WhatsApp', 'url'=>$whatsapp, 'kind'=>'contact' ];
		}
		$email = $actions['email'] ?? '';
		if ( is_string( $email ) && str_starts_with( $email, 'mailto:' ) ) {
			$address = substr( $email, 7 );
			if ( strlen( $address ) <= 254 && ! preg_match( '/[\x00-\x20\x7f?&#,%]/', $address ) && is_email( $address ) ) {
				$out[] = [ 'id'=>'email', 'label'=>'Email', 'url'=>$email, 'kind'=>'contact' ];
			}
		}
		return $out;
	}

	public static function site_contact_rows(): array {
		return self::contact_rows( self::site_actions() );
	}

	/** Strict native profile syntax supplies the documented account identifier; no lookup or prefill. */
	public static function profile_message_url( string $channel, $value ): string {
		$url = self::public_url( $value ); $p = $url ? parse_url( $url ) : [];
		if ( 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) return '';
		$host = strtolower( $p['host'] ?? '' ); $path = $p['path'] ?? '';
		if ( 'instagram' === $channel && in_array( $host, [ 'instagram.com','www.instagram.com' ], true ) && ! isset( $p['query'] ) && preg_match( '~^/([A-Za-z0-9_][A-Za-z0-9._]{0,29})/?$~D', $path, $m ) && ! in_array( strtolower( $m[1] ), [ 'p','reel','reels','stories','explore','direct','accounts','about','developer','legal','privacy','challenge' ], true ) ) return self::messaging_url( $channel, 'https://ig.me/m/' . $m[1] );
		if ( 'facebook' !== $channel || ! in_array( $host, [ 'facebook.com','www.facebook.com','m.facebook.com' ], true ) ) return '';
		if ( '/profile.php' === $path && preg_match( '/^id=([1-9][0-9]{0,19})$/D', $p['query'] ?? '', $m ) ) return self::messaging_url( $channel, 'https://m.me/' . $m[1] );
		if ( ! isset( $p['query'] ) && preg_match( '~^/([A-Za-z0-9_][A-Za-z0-9._-]{0,99})/?$~D', $path, $m ) && ! in_array( strtolower( $m[1] ), [ 'sharer.php','profile.php','share','sharer','dialog','pages','groups','watch','reel','reels','stories','events','login','help','settings','marketplace','gaming' ], true ) ) return self::messaging_url( $channel, 'https://m.me/' . $m[1] );
		return '';
	}

	/** Explicit message links override derived canonical profile identifiers. No Meta prefill claim. */
	public static function messaging_url( string $channel, $value ): string {
		$url = self::public_url( $value );
		$p = $url ? parse_url( $url ) : [];
		if ( 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) return '';
		$host = strtolower( $p['host'] ?? '' ); $path = $p['path'] ?? '';
		if ( 'x' === $channel && in_array( $host, [ 'x.com','twitter.com' ], true ) && '/messages/compose' === $path && preg_match( '/^recipient_id=([1-9][0-9]{0,19})$/D', $p['query'] ?? '', $m ) ) return 'https://x.com/messages/compose?recipient_id=' . $m[1];
		if ( isset( $p['query'] ) ) return '';
		if ( 'facebook' === $channel && 'm.me' === $host && preg_match( '~^/[A-Za-z0-9_][A-Za-z0-9._-]{0,99}/?$~D', $path ) ) return $url;
		if ( 'instagram' === $channel && 'ig.me' === $host && preg_match( '~^/m/[A-Za-z0-9_][A-Za-z0-9._]{0,29}/?$~D', $path ) ) return $url;
		return '';
	}

	public static function enquiry_rows(): array {
		$actions = self::site_actions();
		return array_merge( self::contact_rows( $actions ), array_values( $actions['messaging'] ) );
	}

	/** Read-only authoring evidence, with no enquiries, visitor identities or provider calls. */
	public static function discovery(): array {
		return self::contract() + [ 'configuredEnquiries'=>array_column( self::enquiry_rows(), 'id' ), 'subjectBinding'=>'{post_id}', 'subjectTypes'=>[ 'post','product' ], 'fallbackSubject'=>'business-website', 'qualification'=>'Installed target capability; not proof that a visitor sent a message or an external app is available.' ];
	}

	/** Preserve the legacy saved social directory, without treating those URLs as DM destinations. */
	public static function social_rows( array $config ): array {
		$raw = is_array( $config['social_links'] ?? null ) ? $config['social_links'] : [];
		$out = [];
		foreach ( self::social_labels() as $id=>$label ) {
			$url = self::public_url( $raw[$id] ?? '' );
			if ( '' !== $url ) $out[] = [ 'id'=>$id, 'label'=>$label, 'url'=>$url ];
		}
		return $out;
	}

	private static function public_url( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) return '';
		$url = esc_url_raw( $value, [ 'http', 'https' ] );
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) return '';
		return $url;
	}
}
