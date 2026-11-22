<?php

namespace DSA\Link_Hub;

use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Enquiry_Controller {

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/links/enquiry', [
			[ 'methods'=>'GET', 'callback'=>[ $this,'prepare' ], 'permission_callback'=>[ $this,'read_permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this,'submit' ], 'permission_callback'=>[ $this,'write_permission' ] ],
		] );
	}

	public function read_permission( \WP_REST_Request $r ): bool { return 'GET' === $r->get_method() && Origin_Checker::is_same_site_request(); }
	public function write_permission( \WP_REST_Request $r ): bool {
		return 'POST' === $r->get_method() && true === Origin_Checker::mutation_allowed( $r ) && (bool) wp_verify_nonce( (string) $r->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}
	private static function response( array $body, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $body, $status, [ 'Cache-Control'=>'private, no-store, max-age=0', 'Vary'=>'Cookie' ] );
	}
	private static function failure( \WP_Error $error ): \WP_REST_Response { return self::response( [ 'ok'=>false, 'code'=>$error->get_error_code(), 'message'=>$error->get_error_message() ], (int) ( $error->get_error_data()['status'] ?? 400 ) ); }
	private static function rate( string $kind, int $limit, int $window ): bool {
		return Atomic_Rate_Limiter::allow( 'link-enquiry|' . get_current_blog_id() . '|' . $kind . '|' . Origin_Checker::client_ip(), $limit, $window, false );
	}
	public function prepare( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! $this->read_permission( $r ) ) return self::failure( Enquiry_Service::error( 'Open Links on this website to start an enquiry.', 403 ) );
		$q = $r->get_query_params(); $id = $q['channel'] ?? '';
		if ( ! is_string( $id ) || ! in_array( $id, Enquiry_Service::CHANNELS, true ) || ! ( $channel = Enquiry_Service::channel( $id ) ) ) return self::failure( Enquiry_Service::error( 'This contact channel is unavailable.', 404 ) );
		$subject_id = $q['subjectId'] ?? 0;
		if ( ! is_scalar( $subject_id ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,17})$/D', (string) $subject_id ) ) return self::failure( Enquiry_Service::error( 'Choose a valid public enquiry context.' ) );
		if ( ! self::rate( 'prepare', 30, 60 ) ) return self::failure( Enquiry_Service::error( 'Please wait a moment before starting another enquiry.', 429 ) );
		return self::response( [ 'ok'=>true, 'channel'=>$id, 'label'=>$channel['label'], 'nonce'=>wp_create_nonce( 'wp_rest' ), 'ticket'=>Enquiry_Service::ticket( $id ), 'draftOwner'=>hash_hmac( 'sha256', 'kiwe-enquiry-draft|' . get_current_blog_id() . '|' . get_current_user_id() . '|' . ( get_current_user_id() ? wp_get_session_token() : '' ), wp_salt( 'nonce' ) ), 'prefill'=>Enquiry_Service::prefill( $id ), 'openingMessage'=>Enquiry_Service::opening_message( (int) $subject_id ) ] );
	}
	public function submit( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! $this->write_permission( $r ) ) return self::failure( Enquiry_Service::error( 'Refresh the enquiry before sending it.', 403 ) );
		if ( strlen( $r->get_body() ) > Enquiry_Service::MAX_BODY ) return self::failure( Enquiry_Service::error( 'This message is too large. Please shorten it.', 413 ) );
		$record = Enquiry_Service::input( $r->get_json_params(), true );
		if ( is_wp_error( $record ) ) return self::failure( $record );
		$channel = Enquiry_Service::channel( $record['channel'] );
		if ( ! $channel ) return self::failure( Enquiry_Service::error( 'This contact channel changed. Choose another in Links.', 409 ) );
		if ( ! self::rate( 'submit', 5, 600 ) ) return self::failure( Enquiry_Service::error( 'Please wait before sending another enquiry. Your message is still here.', 429 ) );
		$saved = Enquiry_Store::save( $record );
		if ( is_wp_error( $saved ) ) return self::failure( $saved );
		// A receipt retry is not another arrival. Existing Forms counters also survive a worker failure.
		if ( $saved['created'] ) do_action( 'kiwe_link_enquiry_saved', $saved['id'] );
		// Identical retries may recover subscriber capture after an interrupted response, never a second lead.
		$own = $saved['record'];
		\DSA\Identity\Form_Contact_Service::capture( [ 'name'=>$own['name'], $own['contactKind']=>$own['contact'] ] );
		return self::response( [ 'ok'=>true, 'saved'=>true, 'handoff'=>Enquiry_Service::handoff( $channel, $saved['record'] ) ] );
	}
}
