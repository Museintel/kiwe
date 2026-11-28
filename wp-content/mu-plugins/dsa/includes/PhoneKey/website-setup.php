<?php
/**
 * Connect a Key.kiwe tenant with a short-lived, owner-issued website setup code.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pk_website_setup_allowed() {
	if ( ! current_user_can( pk_admin_cap() ) ) return false;
	return ( ! is_multisite() && \DSA\Access\Site_Owner_Service::owner_id() < 1 )
		|| \DSA\Access\Site_Owner_Service::can_manage_dashboard();
}

function pk_website_setup_is_default( array $settings ) {
	return 'phonekey_gateway' === ( $settings['whatsapp_mode'] ?? '' )
		&& pk_default_whatsapp_gateway_url() === ( $settings['whatsapp_webhook'] ?? '' );
}

function pk_website_setup_is_configured( array $settings ) {
	return ! empty( $settings['whatsapp_key_id'] ) || ! empty( $settings['whatsapp_secret'] );
}

function pk_website_setup_gateway( $path, $body, array $headers ) {
	$base = substr( pk_default_whatsapp_gateway_url(), 0, -strlen( '/v1/otp' ) );
	$response = wp_safe_remote_post( $base . $path, array(
		'timeout'             => 5,
		'redirection'         => 0,
		'limit_response_size' => 4096,
		'headers'             => $headers,
		'body'                => $body,
	) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return null;
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : null;
}

function pk_website_setup_verify( array $settings, $origin ) {
	if ( ! pk_website_setup_is_default( $settings ) ) return false;
	$body    = wp_json_encode( array( 'origin' => $origin ) );
	$headers = pk_gateway_signed_headers( $settings, $body );
	if ( empty( $headers ) ) return false;
	$headers['Origin'] = $origin;
	$result = pk_website_setup_gateway( '/v1/connection', $body, $headers );
	return true === ( $result['ok'] ?? false ) && $origin === ( $result['origin'] ?? '' );
}

function pk_website_setup_connect( $code ) {
	$origin   = untrailingslashit( home_url() );
	$settings = pk_settings();
	if ( 0 !== strpos( $origin, 'https://' ) || ! pk_website_setup_is_default( $settings ) ) return 'existing_provider';
	if ( '' === $code ) {
		if ( ! pk_website_setup_verify( $settings, $origin ) ) return 'check_failed';
		\DSA\Communications\Managed_Email_Transport::enroll_default();
		return 'verified';
	}
	if ( pk_website_setup_is_configured( $settings ) ) return 'existing_provider';
	if ( ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $code ) ) return 'code_unavailable';

	$body   = wp_json_encode( array( 'origin' => $origin, 'code' => $code ) );
	$result = pk_website_setup_gateway( '/v1/website-setup', $body, array( 'Content-Type' => 'application/json', 'Origin' => $origin ) );
	if ( $origin !== ( $result['origin'] ?? null )
		|| ! is_string( $result['keyId'] ?? null ) || ! preg_match( '/\A[a-z0-9._-]{3,64}\z/', $result['keyId'] )
		|| ! is_string( $result['secret'] ?? null ) || strlen( $result['secret'] ) < 32 || strlen( $result['secret'] ) > 512 ) {
		return 'code_unavailable';
	}
	if ( ! class_exists( '\DSA\Security\Secret_Store' ) ) return 'save_failed';
	$secret = \DSA\Security\Secret_Store::encrypt( $result['secret'] );
	if ( '' === $secret ) return 'save_failed';

	// Check the current native options again after the network round trip.
	$stored  = get_option( 'pk_settings_v3', array() );
	$current = pk_settings();
	if ( ! is_array( $stored ) || ! pk_website_setup_is_default( $current ) || pk_website_setup_is_configured( $current ) ) {
		return 'existing_provider';
	}
	$stored['whatsapp_key_id'] = $result['keyId'];
	$stored['whatsapp_secret'] = $secret;
	if ( ! update_option( 'pk_settings_v3', $stored ) ) return 'save_failed';
	if ( ! pk_website_setup_verify( pk_settings(), $origin ) ) return 'check_failed';
	\DSA\Communications\Managed_Email_Transport::enroll_default();
	return 'verified';
}

add_action( 'admin_post_pk_website_setup', static function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! pk_website_setup_allowed() ) pk_die( 'Unauthorized', 403 );
	check_admin_referer( 'pk_website_setup' );
	$code = $_POST['website_setup_code'] ?? '';
	$code = is_string( $code ) ? trim( wp_unslash( $code ) ) : '-';
	$result = pk_website_setup_connect( $code );
	wp_safe_redirect( add_query_arg( array( 'page' => 'kiwe-auth', 'key_setup' => $result ), admin_url( 'admin.php' ) ), 303 );
	exit;
} );

function pk_render_website_setup( array $settings ) {
	if ( ! pk_website_setup_allowed() || ! pk_website_setup_is_default( $settings ) ) return;
	$messages = array(
		'verified'          => __( 'Key verified this WordPress site. Return to Kiwe Start to continue.', 'dsa' ),
		'existing_provider' => __( 'The existing WhatsApp settings were kept. Use Verify Key connection to check an existing Key tenant.', 'dsa' ),
		'code_unavailable'  => __( 'This setup code could not be used. Open Key and request a fresh code for this website.', 'dsa' ),
		'save_failed'       => __( 'WordPress could not save the Key connection. Check the site settings and try a new code.', 'dsa' ),
		'check_failed'      => __( 'WordPress could not verify Key. Check that your connection is active in Key and try Verify Key connection.', 'dsa' ),
	);
	$result = sanitize_key( $_GET['key_setup'] ?? '' );
	$configured = pk_website_setup_is_configured( $settings );
	?>
	<section class="pk-card">
		<h2><?php esc_html_e( 'Connect Key.kiwe', 'dsa' ); ?></h2>
		<?php if ( isset( $messages[ $result ] ) ) : ?>
			<p role="status"><?php echo esc_html( $messages[ $result ] ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pk_website_setup">
			<?php wp_nonce_field( 'pk_website_setup' ); ?>
			<?php if ( ! $configured ) : ?>
				<p><label for="website-setup-code"><?php esc_html_e( 'One-time code from Key', 'dsa' ); ?></label></p>
				<p><input class="regular-text" id="website-setup-code" type="password" name="website_setup_code" autocomplete="off" spellcheck="false" minlength="43" maxlength="43" required></p>
			<?php endif; ?>
			<button type="submit" class="button button-secondary"><?php
				echo esc_html( $configured ? __( 'Verify Key connection', 'dsa' ) : __( 'Connect this website', 'dsa' ) );
			?></button>
		</form>
	</section>
	<?php
}
