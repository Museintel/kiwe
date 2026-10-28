<?php
namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Human labels over native Auth/SecureTrack controls. Values never define authority. */
final class System_Control_Catalogue {
	public static function fields( string $app, bool $technical ): array {
		$out = [];
		$add = static function( string $key, string $label, string $type, string $group, array $extra = [] ) use ( &$out ): void { $out[$key] = [ 'key'=>$key, 'label'=>$label, 'type'=>$type, 'group'=>$group ] + $extra; };
		$choice = static fn( array $items ): array => [ 'options'=>$items ];
		$number = static fn( int $min, int $max ): array => [ 'min'=>$min, 'max'=>$max ];
		if ( 'auth' === $app ) {
			$identifiers = $choice( [ 'email'=>'Email', 'phone'=>'Phone', 'email_or_phone'=>'Email or phone', 'email_and_phone'=>'Email and phone' ] );
			$timing = $choice( [ 'verify_now'=>'Verify now', 'verify_later'=>'Verify later', 'progressive'=>'Ask after more visits' ] );
			$add( 'identifier_mode', 'How visitors subscribe or sign in', 'choice', 'Joining your website', $identifiers );
			$add( 'low_friction_signup', 'Let new subscribers start before verification', 'bool', 'Joining your website', [ 'help'=>'Existing accounts still require their original proof. Comments sent without verification can wait for moderation.' ] );
			$add( 'verification_timing', 'When ordinary visitors verify', 'choice', 'Joining your website', $timing );
			$add( 'progressive_visits', 'Visits before a verification reminder', 'int', 'Joining your website', $number( 1, 20 ) );
			$add( 'default_country_code', 'Default phone country code', 'text', 'Joining your website', [ 'max'=>6 ] );
			$add( 'app_identifier_mode', 'How installed-app visitors sign in', 'choice', 'Installed app', $identifiers );
			$add( 'app_verification_timing', 'When installed-app visitors verify', 'choice', 'Installed app', $timing );
			$add( 'email_delivery', 'Email sign-in method', 'choice', 'Verification messages', $choice( [ 'magic_link'=>'Sign-in link', 'otp'=>'One-time code' ] ) );
			$add( 'whatsapp_email_fallback', 'Try the available email route if WhatsApp fails', 'bool', 'Verification messages' );
			$add( 'return_message', 'Verification reminder text', 'text', 'Reminders', [ 'max'=>640, 'help'=>'{name} uses the person’s existing profile name.' ] );
			$add( 'return_seconds', 'Reminder delay (seconds)', 'int', 'Reminders', $number( 1, 60 ) );
			$add( 'return_action', 'Reminder behaviour', 'choice', 'Reminders', $choice( [ 'none'=>'No reminder', 'prompt'=>'Offer verification', 'progressive'=>'Follow the visit policy' ] ) );
			if ( $technical ) {
				$add( 'popup_display', 'Automatic sign-in invitation', 'choice', 'Advanced · Entry policy', $choice( [ 'disabled'=>'Use launch buttons only', 'logged_out'=>'Signed-out visitors', 'new_visitors'=>'New visitors', 'every_visit'=>'Each visit' ] ) );
				$add( 'popup_dismissal', 'How the invitation closes', 'choice', 'Advanced · Entry policy', $choice( [ 'outside_close'=>'Outside click or close', 'close_only'=>'Close control', 'mandatory'=>'Required sign-in gate' ] ) );
				$add( 'mandatory_scope', 'Where required sign-in applies', 'choice', 'Advanced · Entry policy', $choice( [ 'all_public'=>'All public pages', 'patterns'=>'Listed URL patterns' ] ) );
				$add( 'mandatory_patterns', 'Required sign-in URL patterns', 'textarea', 'Advanced · Entry policy', [ 'max'=>4000 ] );
				$add( 'signup_role', 'Role for new subscriptions', 'choice', 'Advanced · Entry policy', $choice( \DSA\PhoneKey\PhoneKey_Bridge::signup_roles() ) );
				$add( 'replace_wp_login', 'Use PhoneKey for normal WordPress sign-in', 'bool', 'Advanced · WordPress access', [ 'help'=>'Retain and test the private recovery entrance before changing sign-in protection.' ] );
				$add( 'protect_wp_admin', 'Protect WordPress administration from visitors', 'bool', 'Advanced · WordPress access' );
				foreach ( [ 'sms_provider'=>'SMS provider name', 'whatsapp_provider'=>'WhatsApp provider name', 'whatsapp_key_id'=>'Key tenant ID' ] as $key=>$label ) $add( $key, $label, 'text', 'Advanced · Delivery connection', [ 'max'=>240 ] );
				foreach ( [ 'sms_webhook'=>'SMS delivery URL', 'whatsapp_webhook'=>'WhatsApp delivery URL' ] as $key=>$label ) $add( $key, $label, 'url', 'Advanced · Delivery connection', [ 'max'=>2048 ] );
				$add( 'whatsapp_mode', 'WhatsApp delivery connection', 'choice', 'Advanced · Delivery connection', $choice( [ 'phonekey_gateway'=>'Key signed connection', 'generic_webhook'=>'Existing generic webhook' ] ) );
				$add( 'whatsapp_secret', 'Key signing secret', 'secret', 'Advanced · Delivery connection', [ 'max'=>2048, 'help'=>'Leave blank to keep the stored encrypted secret.' ] );
				$add( 'trusted_devices', 'Remember trusted devices', 'bool', 'Advanced · Trusted access' );
				$add( 'ip_trust_roles', 'Roles eligible for trusted IP addresses', 'roles', 'Advanced · Trusted access' );
				foreach ( [ 'ip_trust_threshold'=>[ 'Trust score threshold',1,50 ], 'ip_trust_ttl'=>[ 'Trusted IP lifetime (days)',1,365 ], 'admin_reauth_minutes'=>[ 'Staff reauthentication interval (minutes; 0 keeps native expiry)',0,1440 ], 'otp_target_limit'=>[ 'Verification sends allowed per contact',1,50 ], 'otp_target_window_min'=>[ 'Verification send window (minutes)',1,1440 ] ] as $key=>$item ) $add( $key, $item[0], 'int', 'Advanced · Trusted access', $number( $item[1], $item[2] ) );
				foreach ( wp_roles()->roles as $role=>$info ) {
					$group = 'Role policy · ' . translate_user_role( $info['name'] );
					$add( 'role_matrix.' . $role . '.verification', 'Verification', 'choice', $group, $timing );
					$add( 'role_matrix.' . $role . '.login_mode', 'Sign-in protection', 'choice', $group, $choice( [ 'passkey'=>'Passkey', 'none'=>'No extra step' ] ) );
					$add( 'role_matrix.' . $role . '.step_up', 'Additional verification', 'choice', $group, $choice( [ 'none'=>'None', 'totp_optional'=>'Authenticator when enrolled', 'totp_required'=>'Require authenticator' ] ) );
					$add( 'role_matrix.' . $role . '.timeout', 'Session timeout (minutes; 0 keeps native expiry)', 'int', $group, $number( 0, 1440 ) );
					$add( 'role_matrix.' . $role . '.device_days', 'Trusted-device lifetime (days)', 'int', $group, $number( 1, 365 ) );
				}
			}
		} elseif ( 'security' === $app ) {
			$add( 'alert_delivery_policy', 'Which incidents can send alerts', 'choice', 'Security alerts', $choice( [ 'actionable'=>'Critical or actionable', 'yellow_and_actionable'=>'Warnings and critical incidents' ] ) );
			$add( 'alert_repeat_window_mins', 'Minutes before repeating an alert', 'int', 'Security alerts', $number( 1, 1440 ) );
			$add( 'alert_hourly_limit', 'Maximum alerts in an hour', 'int', 'Security alerts', $number( 1, 100 ) );
			if ( $technical ) {
				$add( 'kiwe.protection_profile', 'Apply a protection preset', 'choice', 'Advanced · Security operation', $choice( [ 'monitor'=>'Keep individual protection choices', 'recommended'=>'Apply the native recommended protections' ] ) + [ 'help'=>'The recommended preset updates the native protections. Individual controls below remain authoritative. Use monitoring mode below when active enforcement should pause.' ] );
				$add( 'kiwe.auto_logout_enabled', 'Sign out inactive staff automatically', 'bool', 'Advanced · Staff sessions' );
				$add( 'kiwe.auto_logout_minutes', 'Inactivity before sign-out (minutes)', 'int', 'Advanced · Staff sessions', $number( 1, 1440 ) );
				$add( 'kiwe.auto_logout_roles', 'Roles affected by inactivity sign-out', 'roles', 'Advanced · Staff sessions' );
				foreach ( [ 'schema_integrity_guard_enabled'=>'Keep product schema on real product records', 'seo_spam_410_enabled'=>'Reject unresolved numeric spam routes', 'harden_public_rest_users'=>'Hide public REST user enumeration', 'security_headers_enabled'=>'Use conservative security headers', 'image_resource_guard_enabled'=>'Protect image processing memory' ] as $key=>$label ) $add( 'kiwe.' . $key, $label, 'bool', 'Advanced · Website guards' );
				foreach ( [ 'image_big_size_threshold'=>[ 'Large-image threshold (pixels)',1600,4096 ], 'image_max_dimension'=>[ 'Maximum image side (pixels)',4096,20000 ], 'image_max_megapixels'=>[ 'Maximum image size (megapixels)',16,120 ] ] as $key=>$item ) $add( 'kiwe.' . $key, $item[0], 'int', 'Advanced · Website guards', $number( $item[1], $item[2] ) );
				$add( 'kiwe.trusted_proxy_cidrs', 'Trusted reverse-proxy network ranges', 'textarea', 'Advanced · Website guards', [ 'max'=>8000, 'help'=>'Only include proxy networks you operate or have verified. The native IP validation rules remain in force.' ] );
				foreach ( [ 'emergency_safe_mode'=>'Use monitoring without active enforcement', 'block_brute_force'=>'Block repeated sign-in attacks', 'adaptive_waf'=>'Check suspicious requests', 'honeypot_enabled'=>'Use hidden bot traps', 'harden_xmlrpc'=>'Protect XML-RPC', 'harden_rest_users'=>'Protect public user listings', 'harden_author_archives'=>'Restrict author archives', 'author_public_slugs'=>'Use public author slugs', 'harden_file_editor'=>'Disable WordPress file editing', 'harden_wp_generator'=>'Hide WordPress version output', 'harden_security_headers'=>'Add security response headers', 'security_txt_enabled'=>'Publish the security contact file', 'endpoint_rate_limits'=>'Limit repeated endpoint requests', 'geo_enabled'=>'Enrich security events with location', 'track_admin_activity'=>'Record administration activity', 'exclude_admin'=>'Exclude administrators from ordinary visitor analytics', 'adaptive_learning'=>'Use adaptive security learning', 'behavioral_risk'=>'Evaluate suspicious behaviour', 'attack_graph'=>'Correlate attack activity', 'subnet_intel'=>'Evaluate shared network activity', 'chain_detection'=>'Detect related attack events' ] as $key=>$label ) $add( $key, $label, 'bool', 'Advanced · Protection' );
				foreach ( [ 'red_threshold'=>[ 'Critical-event score',1,100 ], 'yellow_threshold'=>[ 'Warning-event score',1,100 ], 'brute_force_limit'=>[ 'Failed sign-in threshold',3,1000 ], 'waf_block_score'=>[ 'Suspicious-request block score',50,100 ], 'green_trim_days'=>[ 'Routine-event retention (days; 0 retains)',0,3650 ], 'yellow_trim_days'=>[ 'Warning-event retention (days; 0 retains)',0,3650 ], 'chain_window_mins'=>[ 'Related-event window (minutes)',5,1440 ], 'subnet_alert_at'=>[ 'Network alert threshold',2,20 ] ] as $key=>$item ) $add( $key, $item[0], 'int', 'Advanced · Protection', $number( $item[1], $item[2] ) );
				foreach ( [ 'rl_login_per_min'=>'Sign-in requests per minute', 'rl_xmlrpc_per_min'=>'XML-RPC requests per minute', 'rl_rest_per_min'=>'REST requests per minute', 'rl_admin_per_min'=>'Administration requests per minute', 'rl_frontend_per_min'=>'Public-page requests per minute' ] as $key=>$label ) $add( $key, $label, 'int', 'Advanced · Request limits', $number( 5, 2000 ) );
				$add( 'login_country_policy', 'Sign-ins outside allowed countries', 'choice', 'Advanced · Location policy', $choice( [ 'off'=>'No country restriction', 'deny'=>'Deny sign-in', 'ban'=>'Apply the native ban policy' ] ) );
				foreach ( [ 'country_blocklist'=>'Blocked country codes', 'login_allowed_countries'=>'Allowed sign-in country codes' ] as $key=>$label ) $add( $key, $label, 'text', 'Advanced · Location policy', [ 'max'=>1000, 'help'=>'Use two-letter country codes separated by commas.' ] );
				$add( 'csp_enabled', 'Enable the content security policy', 'bool', 'Advanced · Content security' );
				$add( 'csp_report_only', 'Report policy violations without blocking', 'bool', 'Advanced · Content security' );
				$add( 'csp_report_uri', 'Same-site policy report URL', 'url', 'Advanced · Content security', [ 'max'=>2048 ] );
			}
		}
		return $out;
	}
}
