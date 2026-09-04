#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
const bridge = read('wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php');
const roles = read('wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php');
const purchase = read('wp-content/mu-plugins/dsa/includes/Commerce/Purchase_Identity_Gate.php');
const savedController = read('wp-content/mu-plugins/dsa/includes/Rest/Saved_Items_Controller.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const failures = [];

function check(label, condition) {
	if (condition) {
		console.log(`PASS ${label}`);
		return;
	}
	failures.push(label);
	console.error(`FAIL ${label}`);
}

check('PhoneKey defaults new accounts to WordPress Subscriber even when WooCommerce exists',
	/function pk_default_role[\s\S]*?get_role\( 'subscriber' \)/.test(core)
	&& !/function pk_default_role[\s\S]*?get_role\( 'customer' \)/.test(core)
	&& /function pk_apply_subscriber_signup_default_once[\s\S]*?'customer'[\s\S]*?pk_default_role/.test(core)
);
check('first low-risk signup can complete without an extra click but existing accounts cannot',
	core.includes("'low_friction_signup'  => 1")
	&& core.includes("'autoContinue' => $is_new && $grace_available")
	&& core.includes("if ( ! $verified && ! $is_new )")
	&& surface.includes("phoneKeyPost( 'continue-later', { token: phonekeyState.token } ).then( phoneKeyDone )")
);
check('Editor creation is separated from editing and publishing existing posts',
	roles.includes("$capabilities['create_posts'] = 'create_posts';")
	&& roles.includes("in_array( 'editor', $roles, true )")
	&& roles.includes("unset( $allcaps['create_posts'] );")
	&& !roles.includes("unset( $allcaps['publish_posts'] );")
);
check('Authors and compatible native editorial roles retain post creation',
	roles.includes("if ( ! empty( $allcaps['edit_posts'] ) )")
	&& roles.includes("$allcaps['create_posts'] = true;")
);
check('Editor and Author admin routes are limited to the publishing lane',
	roles.includes('limit_editorial_menu')
	&& roles.includes('guard_editorial_admin_route')
	&& roles.includes("remove_submenu_page( 'edit.php', 'post-new.php' )")
	&& roles.includes("'profile.php'")
	&& roles.includes("'upload.php'")
);
check('every role with WordPress editorial admin access enters strict PhoneKey enrollment',
	/function pk_role_is_high_privilege_role[\s\S]*?edit_posts/.test(core)
	&& core.includes('function pk_apply_editorial_access_policy_once')
	&& core.includes('function pk_flag_all_privileged_accounts_once')
);
check('anonymous saved-item mutations fail closed and the client opens PhoneKey',
	/saved_items_login_required/.test(savedController)
	&& /toggleSavedItem[\s\S]*?rememberPendingSavedIntent[\s\S]*?openOverlay\( 'profile'/.test(surface)
);
check('saved intent is replayed only after WordPress reports an authenticated user',
	surface.includes('function consumePendingSavedIntent')
	&& /consumePendingSavedIntent[\s\S]*?phonekey\.user[\s\S]*?loggedIn[\s\S]*?mutateSavedItem\( 'add'/.test(surface)
);
check('WooCommerce purchase requires a signed-in account and one verified PhoneKey factor',
	purchase.includes("'signup_required'")
	&& purchase.includes("'verification_required'")
	&& purchase.includes("apply_filters( 'dsa_phonekey_account_identity_verified'")
	&& core.includes("add_filter( 'dsa_phonekey_account_identity_verified'")
	&& core.includes('pk_account_verified( absint( $user_id ) )')
);
check('classic and Checkout Block order placement both fail closed server-side',
	purchase.includes("'woocommerce_checkout_process'")
	&& purchase.includes("'woocommerce_store_api_checkout_update_order_meta'")
	&& purchase.includes('throw new \\Exception')
);
check('direct checkout entry cannot bypass the PhoneKey identity boundary',
	purchase.includes("add_action( 'template_redirect'")
	&& purchase.includes('guard_checkout_entry')
	&& purchase.includes("'dsa-checkout-intent'")
	&& surface.includes('consumeCheckoutEntryIntent')
	&& surface.includes("url.searchParams.get( 'dsa-checkout-intent' )")
);
check('checkout contracts stay closed until identity is allowed',
	read('wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Service.php').includes("apply_filters( 'dsa_purchase_identity_gate_state'")
	&& read('wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Service.php').includes('$this->identity_allowed()')
);
check('Subscriber alone becomes WooCommerce Customer after either checkout path',
	purchase.includes("'woocommerce_checkout_order_processed'")
	&& purchase.includes("'woocommerce_store_api_checkout_order_processed'")
	&& purchase.includes('transition_subscriber_to_customer')
	&& purchase.includes("$user->set_role( 'customer' )")
	&& purchase.includes("'dsa_customer_since_order'")
);
check('purchase lifecycle never replaces a privileged or multi-role account',
	purchase.includes("array_intersect( [ 'subscriber', 'kiwe_user' ], (array) $user->roles )")
	&& purchase.includes("array_diff( (array) $user->roles, [ 'subscriber', 'kiwe_user' ] )")
);
check('purchase attempts open PhoneKey for guests and Profile for unverified subscribers',
	surface.includes('function openPurchaseIdentityGate')
	&& surface.includes("openOverlay( 'profile', 'Sign in to purchase' )")
	&& surface.includes("openOverlay( 'profile', 'Verify to purchase' )")
	&& surface.includes('function bindPurchaseIdentityGate')
);
check('purchase state is published through the existing PhoneKey bridge',
	bridge.includes("'purchaseGateEnabled'")
	&& bridge.includes("'purchaseAllowed'")
	&& bridge.includes("'purchaseGateState'")
);
check('WordPress Users reflects PhoneKey phone and verification state',
	core.includes("'manage_users_columns'")
	&& core.includes("'phonekey_phone'")
	&& core.includes("'phonekey_security'")
	&& core.includes('pk_security_completion( (int) $user_id )')
);
check('Shop Manager receives only the product authoring capability lane',
	roles.includes("in_array( 'shop_manager', $roles, true )")
	&& roles.includes("'edit_products'")
	&& roles.includes("'create_products'")
	&& roles.includes("'publish_products'")
	&& roles.includes('array_fill_keys( $allowed, true )')
	&& roles.includes('unset( $allcaps[ $capability ] )')
);
check('Shop Manager sees product routes only while Administrator and Super Admin remain unchanged',
	roles.includes("? [ 'edit.php?post_type=product' ]")
	&& roles.includes('guard_shop_manager_route')
	&& roles.includes("'product' === sanitize_key")
	&& roles.includes("in_array( 'administrator', $roles, true )")
	&& roles.includes('is_super_admin( $user->ID )')
);
check('ownership survives Key being disabled; commerce stays gated',
	plugin.includes('new WordPress_Role_Access_Service()')
	&& plugin.includes('new Purchase_Identity_Gate()')
	&& /role_access->register\(\)[\s\S]*?if \( \$phonekey_enabled \)[\s\S]*?register_if_available/.test(plugin)
	&& purchase.includes("class_exists( 'WooCommerce' )")
	&& purchase.includes('! function_exists( \'WC\' )')
);

if (failures.length) {
	console.error(`\nPhoneKey role and commerce contracts failed (${failures.length}).`);
	process.exit(1);
}

console.log('\nPhoneKey role and commerce contracts passed.');
