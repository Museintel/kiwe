#!/usr/bin/env node
'use strict';
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const guest = read('wp-content/mu-plugins/dsa/includes/Access/Guest_Contribution_Service.php');
const access = read('wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php');
const prefs = read('wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php');
const center = read('wp-content/mu-plugins/dsa/includes/Notifications/Notification_Center_Service.php');
const events = read('wp-content/mu-plugins/dsa/includes/Notifications/Admin_Event_Notification_Service.php');
const channels = read('wp-content/mu-plugins/dsa/includes/Communications/Channel_Service.php');
const secure = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
const profile = read('wp-content/mu-plugins/dsa/assets/js/modules/profile-panel.js');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
let passed = 0;
function check(value, label) { if (!value) throw new Error(`FAIL ${label}`); passed++; console.log(`PASS ${label}`); }
check(guest.includes("PhoneKey\\PhoneKey_Bridge::account_verified"), 'Guest applications require verified identity');
check(guest.includes("SETTINGS_OPTION = 'kiwe_guest_contribution_settings'") && guest.includes("'posts_enabled'") && guest.includes("'products_enabled'"), 'Guest post and product applications are explicitly enabled by the site owner');
check(guest.includes("'productsEnabled'   => $products") && guest.includes("$commerce && ! empty( $stored['products_enabled'] )"), 'product applications remain dormant without WooCommerce');
check(guest.includes('has_admin_area_access') && guest.includes("'eligible'          => $user_id > 0 && $verified && ! $has_admin_access"), 'application eligibility excludes users with administrator-area access');
check(guest.includes("Origin_Checker::mutation_allowed"), 'profile application is same-site mutation protected');
check(guest.includes("'status'    => 'pending'"), 'applications cannot self-approve');
check(guest.includes("current_user_can( 'kiwe_manage_guest_applications' )"), 'decisions require dedicated administrator capability');
check(guest.includes("'immutableForAuthor' => true"), 'Guest submissions are marked immutable');
check(guest.includes("'post_type'    => $type"), 'Guest workspace supports isolated article and Woo product proposals');
check(access.includes("if ( 'contributor' === $role )") && access.includes("'kiwe_guest_submit'"), 'Contributor is reduced to protected Guest capabilities');
check(access.includes("kiwe_guest_workspace_only"), 'native WordPress REST mutation is denied to Guests');
check(center.includes("add_menu_page( __( 'Notifications'"), 'Notifications is a first-class WordPress menu');
check(center.includes("disabled( ! $available )"), 'unconfigured delivery lanes cannot be selected');
check(prefs.includes("admin_guest_application") && prefs.includes("guest_post_status"), 'role-aware Guest topics use the canonical preference catalog');
check(prefs.includes("'admin_new_order' === $topic") && prefs.includes("user_can( $user_id, 'kiwe_manage_notification_policy' )") && prefs.includes("current_user_can( 'kiwe_manage_notification_policy' )"), 'simplified client Administrators retain owner-relevant notification topics');
check(prefs.includes("'securetrack_incident'") && prefs.includes("! user_can( $user_id, 'kiwe_manage_notification_policy' )"), 'SecureTrack is a protected administrator notification topic');
check(events.includes("add_action( 'kiwe_notification_event'") && events.includes("foreach ( [ 'whatsapp', 'email', 'sms' ] as $channel )"), 'one shared event ingress owns all three external delivery channels');
check(channels.includes("'securetrack_incident'") && secure.includes("'kiwe_notification_event'") && !secure.slice(secure.indexOf('function stp_alert('), secure.indexOf('/** Count recent failed logins')).includes('wp_mail('), 'SecureTrack uses the shared gateway service instead of direct email');
check(profile.includes('data-dsa-guest-apply') && profile.includes('guest.postsAvailable') && profile.includes('guest.productsAvailable') && surface.includes("'/account/guest-application'"), 'Profile DSA exposes only enabled application journeys');
check(
	profile.includes("guest.status === 'approved' ) return '<span class=\"dsa-guest-status is-approved\"><span aria-hidden=\"true\">&#10003;</span> Guest</span>'")
	&& profile.indexOf("guest.status === 'approved'") < profile.indexOf('data-dsa-guest-apply'),
	'approved Guest state is one read-only badge and cannot also render the application action'
);
console.log(`${passed} Guest and notification contracts passed.`);
