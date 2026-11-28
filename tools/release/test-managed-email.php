<?php
declare(strict_types=1);

namespace DSA {
	final class Settings {
		public function all(): array { return [ 'email' => $GLOBALS['email'] ]; }
		public function defaults(): array { return $this->all(); }
		public function update( array $values ): void { $GLOBALS['email'] = $values['email']; }
		public function update_selected( array $values ): void { $this->update( $values ); }
	}
}

namespace {
	use DSA\Communications\Email_Service;
	use DSA\Communications\Managed_Email_Transport as Managed;

	define( 'ABSPATH', __DIR__ );
	define( 'DSA_OPTION_SETTINGS', 'native_settings' );
	$email = [ 'enabled'=>false, 'transport'=>'wordpress', 'from_email'=>'', 'smtp'=>[ 'host'=>'' ] ];
	$plugins = [];
	$caps = [];
	$calls = [];
	$default = true;
	$status = 200;
	$sender = 'fixture@kiwelaunch.com';
	$campaigns = false;
	class WP_Error { public function __construct( $code, private string $message ) {} public function get_error_message(): string { return $this->message; } }
	function __( $value, $domain = '' ) { return $value; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function get_option( $key, $fallback = null ) { return 'active_plugins' === $key ? $GLOBALS['plugins'] : []; }
	function update_option( $key, $value ) { $GLOBALS['failure'] = $value; }
	function delete_option( $key ) {}
	function get_current_blog_id() { return 1; }
	function home_url() { return 'https://example.invalid/'; }
	function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
	function pk_settings() { return []; }
	function pk_website_setup_is_default( $settings ) { return $GLOBALS['default']; }
	function pk_default_whatsapp_gateway_url() { return 'https://key.kiwelaunch.com/v1/otp'; }
	function pk_gateway_signed_headers( $settings, $body ) { return [ 'X-Kiwe-Key-Signature'=>'fixture' ]; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_parse_args( $values, $defaults ) { return array_replace( $defaults, $values ); }
	function wp_safe_remote_post( $url, $args ) {
		$GLOBALS['calls'][] = [ $url, $args ];
		return [ 'status'=>$GLOBALS['status'], 'body'=>json_encode( [
			'ok'=>true, 'fromEmail'=>$GLOBALS['sender'], 'campaigns'=>$GLOBALS['campaigns'],
		] ) ];
	}
	function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	function wp_generate_password( $length, ...$unused ) { return str_repeat( 'a', $length ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function sanitize_text_field( $value ) { return strip_tags( $value ); }
	function current_time( $kind ) { return 'fixture'; }
	function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
	function proof( bool $ok, string $label ): void { if ( ! $ok ) throw new RuntimeException( $label ); }
	$core = dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Communications/';
	require $core . 'Managed_Email_Transport.php';
	require $core . 'Email_Service.php';
	$service = new Email_Service( new \DSA\Settings() );

	$plugins = [ 'wp-mail-smtp/wp_mail_smtp.php' ];
	Managed::enroll_default();
	proof( 'wordpress' === $email['transport'], 'Keep the active SMTP plugin during rehearsal.' );
	$plugins = [];
	$default = false;
	Managed::enroll_default();
	proof( 'wordpress' === $email['transport'], 'A nonstandard Key gateway must never receive a managed mail request.' );
	$default = true;
	Managed::enroll_default();
	proof( $email['enabled'] && 'managed' === $email['transport'] && $sender === $email['from_email'], 'Verified fresh enrollment turns on the real managed sender.' );
	$payload = [ 'to'=>'buyer@example.invalid', 'subject'=>'Verification', 'message'=>'A test account email' ];
	$status = 202;
	proof( true === $service->send_managed( null, $payload ), 'A native account message uses the managed transport.' );
	[ $url, $request ] = end( $calls );
	proof( 'https://key.kiwelaunch.com/v1/email' === $url && 'https://example.invalid' === $request['headers']['Origin']
		&& json_decode( $request['body'], true )['text'] === $payload['message'] && 0 === $request['redirection'], 'Use the exact fixed Key route and site origin.' );
	$unsubscribe = 'https://example.invalid/stop';
	$campaign = $payload + [ 'headers'=>[
		'Content-Type: text/html; charset=UTF-8',
		'List-Unsubscribe: <' . $unsubscribe . '>',
		'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
	] ];
	$before = count( $calls );
	proof( null === $service->send_managed( null, $campaign ) && $before === count( $calls ), 'A provider without header support leaves the campaign to native mail.' );
	$email = [ 'enabled'=>false, 'transport'=>'wordpress', 'from_email'=>'', 'smtp'=>[ 'host'=>'' ] ];
	$campaigns = true;
	$status = 200;
	Managed::enroll_default();
	proof( ! empty( $email['managed_campaigns'] ), 'Enroll campaign mail only after Key confirms support.' );
	$status = 202;
	proof( true === $service->send_managed( null, $campaign ), 'A campaign sends through the enrolled managed transport.' );
	$sent = json_decode( end( $calls )[1]['body'], true );
	proof( $unsubscribe === $sent['unsubscribe'] && $payload['message'] === $sent['html'], 'Managed mail preserves the approved campaign URL and HTML.' );
	$before = count( $calls );
	proof( null === $service->send_managed( null, $payload + [ 'headers'=>[ 'List-Unsubscribe: <' . $unsubscribe . '>' ] ] )
		&& $before === count( $calls ), 'A partial unsubscribe header pair stays with native mail.' );
	$status = 429;
	proof( false === $service->send_managed( null, $payload ) && ! empty( $GLOBALS['failure']['message'] ), 'A failed provider call cannot claim delivery.' );
	$email = [ 'enabled'=>true, 'transport'=>'wordpress', 'sender_choice'=>true, 'from_email'=>'', 'smtp'=>[ 'host'=>'' ] ];
	Managed::enroll_default();
	proof( 'wordpress' === $email['transport'], 'An explicit local transport choice survives re-enrollment.' );
	$caps = [ 'kiwe_manage_notification_policy' ];
	proof( ! Email_Service::can_manage(), 'Notification-only staff cannot change the sender.' );
	$caps[] = 'kiwe_manage_team';
	proof( Email_Service::can_manage(), 'A client Administrator can configure the sender.' );
	echo "14 managed email assertions passed. HTTP transport simulated.\n";
}
