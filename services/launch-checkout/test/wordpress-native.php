<?php

declare(strict_types=1);

[$script, $root, $site] = $argv;
if (PHP_SAPI !== 'cli' || !str_starts_with(basename($root), 'kiwe-launch-woo-native-')
    || basename(dirname($site)) !== 'wordpress-checkout-lab') exit(1);
define('WPMU_PLUGIN_DIR', $root . '/mu-plugins');
require $site . '/wp-includes/plugin.php';
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn () => new WP_Error('fixture', 'External HTTP disabled for the isolated test.'));
add_filter('pre_option_active_plugins', static fn () => ['woocommerce/woocommerce.php']);
add_filter('pre_option_template', static fn () => 'twentytwentyfive');
add_filter('pre_option_stylesheet', static fn () => 'twentytwentyfive');
require $site . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || DB_NAME !== 'kiwe_checkout_lab') exit(2);
define('KIWE_LAUNCH_NATIVE_CHECKOUT_ROOT', $root);
require __DIR__ . '/../wordpress.php';
require $root . '/boot.php';
require $root . '/web.php';

function nativeProof(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
}

function nativeBuyerSession(array $state, array $account): void
{
    session_id('');
    unset($_COOKIE['kiwe_launch_account']);
    nativeProof(kiwe_checkout_session($state, true), 'Open the fixture buyer session.');
    kiwe_checkout_establish_session($account);
    $_COOKIE['kiwe_launch_account'] = session_id();
    session_write_close();
}

function nativeFixtureOrder(WC_Product $product, string $email): WC_Order
{
    $order = wc_create_order(['created_via' => 'checkout']);
    $order->add_product($product, 1);
    $order->set_billing_email($email);
    $order->set_payment_method('razorpay');
    $order->set_currency('INR');
    $order->calculate_totals();
    return $order;
}

function nativeFixtureCapture(string $root, WC_Order $order, int $amount): string
{
    $id = $order->get_id();
    $providerOrder = 'order_WooNative' . $id;
    $providerPayment = 'pay_WooNative' . $id;
    $order->update_meta_data('razorpay_order_id' . $id, $providerOrder);
    $order->save_meta_data();
    $file = $root . '/data/gateway.json';
    $provider = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : ['links' => [], 'subscriptions' => []];
    $provider['orders'][$providerOrder] = ['id' => $providerOrder, 'receipt' => (string) $id,
        'notes' => ['woocommerce_order_id' => (string) $id], 'currency' => 'INR', 'status' => 'paid',
        'amount' => $amount, 'amount_paid' => $amount, 'amount_due' => 0];
    $provider['payments'][$providerPayment] = ['id' => $providerPayment, 'order_id' => $providerOrder,
        'status' => 'captured', 'currency' => 'INR', 'created_at' => time(), 'amount' => $amount, 'amount_refunded' => 0];
    file_put_contents($file, json_encode($provider, JSON_THROW_ON_ERROR));
    $order->payment_complete($providerPayment);
    nativeProof(as_has_scheduled_action('kiwe_launch_reconcile_website_order', [$id, 0], 'kiwe-launch'),
        'Payment must enqueue a protected receipt check.');
    do_action('kiwe_launch_reconcile_website_order', $id, 0);
    $purchase = wc_get_order($id)->get_meta('_kiwe_launch_website_purchase');
    do_action('kiwe_launch_reconcile_website_order', $id, 0);
    nativeProof($purchase && wc_get_order($id)->get_meta('_kiwe_launch_website_purchase') === $purchase,
        'Repeat the exact paid check without another purchase.');
    return $purchase;
}

add_filter('pre_option_woocommerce_currency', static fn () => 'INR');
add_filter('pre_option_woocommerce_price_num_decimals', static fn () => 2);
add_filter('pre_option_woocommerce_calc_taxes', static fn () => 'no');

$state = kiwe_checkout_boot($root);
$checkout = $state['checkout'];
$buyer = 'launch:' . str_repeat('a', 32);
$other = 'launch:' . str_repeat('b', 32);
$account = ['id' => $buyer, 'email' => 'native-buyer@example.invalid', 'version' => 1];
$otherAccount = ['id' => $other, 'email' => 'native-other@example.invalid', 'version' => 1];
$db = new PDO('sqlite:' . $root . '/data/checkout.sqlite');
$insert = $db->prepare('INSERT INTO checkout_accounts (id, email, password_hash, verified_at) VALUES (?, ?, ?, ?)');
foreach ([$account, $otherAccount] as $person) $insert->execute([$person['id'], $person['email'], 'synthetic', time()]);
$plan = $state['nativeWoo']['plans']['kiwe-portfolio-v1'];
$amount = $plan['direct']['upfrontMinor'];
$product = new WC_Product_Simple();
$passProduct = new WC_Product_Simple();
$orders = [];
$cleanup = false;
try {
    nativeProof(!wc_get_product_id_by_sku($plan['sku']), 'The fixture SKU must be free.');
    $product->set_props(['name' => 'Synthetic Kiwe Portfolio', 'sku' => $plan['sku'], 'virtual' => true,
        'regular_price' => (string) ($amount / 100), 'status' => 'publish']);
    $product->save();
    wc_load_cart();
    WC()->cart->add_to_cart($product->get_id());
    WC()->cart->calculate_totals();
    nativeProof((float) WC()->cart->get_total('edit') === (float) ($amount / 100), 'Native cart price must match the catalogue.');

    $data = ['billing_email' => $account['email'], 'payment_method' => 'razorpay'];
    $_POST = ['kiwe_launch_domain' => 'buyer.example.invalid', 'kiwe_launch_domain_mode' => 'owned', 'kiwe_launch_review_minor' => $amount];
    $guest = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $data, $guest);
    nativeProof(str_contains($guest->get_error_message(), 'Sign in'), 'A Woo guest without a verified Launch session must be denied.');
    nativeBuyerSession($state, $otherAccount);
    $wrong = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $data, $wrong);
    nativeProof(str_contains($wrong->get_error_message(), 'verified email'), 'Woo billing email cannot impersonate a Launch buyer.');
    nativeBuyerSession($state, $account);
    $valid = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $data, $valid);
    nativeProof(!$valid->has_errors(), 'Allow the verified buyer. ' . $valid->get_error_message());
    nativeProof(!is_user_logged_in(), 'Launch sign-in must not create a WordPress identity.');

    $orders[] = $order = nativeFixtureOrder($product, $account['email']);
    $id = $order->get_id();
    do_action('woocommerce_checkout_order_processed', $id, $data, $order);
    $order = wc_get_order($id);
    nativeProof((bool) $order->get_meta('_kiwe_launch_website_intent'), 'Persist the signed Woo record.');
    do_action('woocommerce_before_pay_action', $order);
    nativeBuyerSession($state, $otherAccount);
    $denied = false;
    try {
        do_action('woocommerce_before_pay_action', $order);
    } catch (Exception $error) {
        $denied = str_contains($error->getMessage(), 'verified Kiwe Launch');
    }
    nativeProof($denied, 'A separate buyer must not reopen the order for payment.');
    $blocked = false;
    try {
        do_action('woocommerce_store_api_checkout_order_processed', $order);
    } catch (Automattic\WooCommerce\StoreApi\Exceptions\RouteException) {
        $blocked = true;
    }
    nativeProof($blocked, 'Block native API payment outside the reviewed classic flow.');

    $purchase = nativeFixtureCapture($root, $order, $amount);
    $status = $state['billing']->status($buyer, $purchase);
    nativeProof($status['projectId'] && $status['quote']['domain']['name'] === 'buyer.example.invalid'
        && $state['billing']->status($other, $purchase) === null, 'Delivery must use the order’s saved buyer and domain.');

    $passPlan = $state['nativeWoo']['plans']['kiwe-freelancer-annual-v1'];
    $passProduct->set_props(['name' => 'Synthetic Freelancer Pass', 'virtual' => true,
        'regular_price' => (string) ($passPlan['priceMinor'] / 100), 'status' => 'publish']);
    $passProduct->save();
    $passProductId = $passProduct->get_id();
    add_filter('woocommerce_product_get_sku', static fn ($sku, WC_Product $item) => $item->get_id() === $passProductId ? $passPlan['sku'] : $sku, 10, 2);
    nativeBuyerSession($state, $account);
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($passProductId);
    WC()->cart->calculate_totals();
    $_POST = [];
    $validPass = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $data, $validPass);
    nativeProof(!$validPass->has_errors(), 'A verified pass buyer needs no domain. ' . $validPass->get_error_message());
    $orders[] = $passOrder = nativeFixtureOrder($passProduct, $account['email']);
    do_action('woocommerce_checkout_order_processed', $passOrder->get_id(), $data, $passOrder);
    nativeProof(!apply_filters('woocommerce_cancel_unpaid_order', true, $passOrder)
        && apply_filters('woocommerce_cancel_unpaid_order', true, $order), 'Only the reserved virtual pass is kept payable after a stock-hold timeout.');
    $orders[] = $duplicate = nativeFixtureOrder($passProduct, $account['email']);
    $blocked = false;
    try {
        do_action('woocommerce_checkout_order_processed', $duplicate->get_id(), $data, $duplicate);
    } catch (KiweCheckoutError $error) {
        $blocked = $error->status === 409;
    }
    nativeProof($blocked && !$duplicate->get_meta('_kiwe_launch_website_intent'), 'Only one checkout order can pay for this account pass.');
    $passId = nativeFixtureCapture($root, $passOrder, $passPlan['priceMinor']);
    $pass = $state['billing']->freelancerPass($buyer);
    nativeProof($pass['active'] && $pass['purchaseId'] === $passId && !$state['billing']->status($buyer, $passId)['projectId'],
        'The annual pass activates for the buyer with no Start project.');
    $db->prepare('INSERT INTO checkout_referrals (id, partner_id, digest, client_email, whatsapp, domain, created_at, expires_at, buyer_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['ref:fixture', $other, str_repeat('7', 64), $account['email'],
        '+919000000000', 'pass-discount.example.invalid', time(), time() + 86400, $buyer]);

    $eligible = $plan['referred']['upfrontMinor'];
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($product->get_id());
    WC()->cart->calculate_totals();
    $cartItems = WC()->cart->get_cart();
    $line = reset($cartItems);
    $lineData = apply_filters('woocommerce_get_item_data', [], $line);
    nativeProof(count($lineData) === 1 && $lineData[0]['key'] === 'Freelancer Pass savings' && !WC()->cart->get_applied_coupons()
        && wc_add_number_precision((float) WC()->cart->get_total('edit')) === (float) $eligible,
        'Pass pricing must be visible in WooCommerce with no coupon code.');
    nativeProof((float) wc_get_product($product->get_id())->get_price() === (float) ($amount / 100),
        'The native product price must stay at the public catalogue rate.');
    ob_start();
    do_action('woocommerce_before_checkout_billing_form', WC()->checkout());
    $rendered = ob_get_clean();
    nativeProof(str_contains($rendered, 'name="kiwe_launch_review_minor" value="' . $eligible . '"'),
        'The native checkout form must post its displayed eligible total.');

    nativeBuyerSession($state, $otherAccount);
    WC()->cart->calculate_totals();
    nativeProof(wc_add_number_precision((float) WC()->cart->get_total('edit')) === (float) $amount,
        'A different verified account must not inherit the discounted cart.');
    nativeBuyerSession($state, $account);
    WC()->cart->calculate_totals();
    $_POST = ['kiwe_launch_domain' => 'pass-discount.example.invalid', 'kiwe_launch_domain_mode' => 'owned', 'kiwe_launch_review_minor' => $amount];
    $stale = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $data, $stale);
    nativeProof(str_contains($stale->get_error_message(), 'price changed'), 'A stale displayed price must require checkout review.');
    $_POST['kiwe_launch_review_minor'] = $eligible;
    $approved = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $data, $approved);
    nativeProof(!$approved->has_errors(), 'Permit the selected website at the verified pass price.');
    $cartItems = WC()->cart->get_cart();
    $discounted = reset($cartItems)['data'];
    $orders[] = $discountOrder = nativeFixtureOrder($discounted, $account['email']);
    do_action('woocommerce_checkout_order_processed', $discountOrder->get_id(), $data, $discountOrder);
    do_action('woocommerce_before_pay_action', $discountOrder);
    $denied = false;
    try {
        $state['billing']->approvePassWebsitePrice($buyer, $discountOrder->get_id(), $plan['id'],
            ['mode' => 'owned', 'name' => 'another.example.invalid']);
    } catch (KiweCheckoutError $error) {
        $denied = $error->status === 409;
    }
    nativeProof($denied, 'Changes to the approved domain require another quote.');

    $billing = $state['billing'];
    $billing->approveNativePartner($buyer);
    $domain = 'verified-referral.example.invalid';
    $share = $checkout->newReferral($account + ['partner' => true], [
        'email' => $otherAccount['email'], 'whatsapp' => '9876500111', 'domain' => $domain, 'consent' => 'yes',
    ], true);
    parse_str(parse_url($share, PHP_URL_FRAGMENT), $token);
    $referralDigest = kiwe_checkout_token($token['ref']);
    nativeProof(!$checkout->acceptReferral($otherAccount, $referralDigest) && $checkout->acceptReferral($otherAccount, $referralDigest, true),
        'The native referral requires the verified recipient in the Launch account flow.');
    $verifiedReferral = $billing->websiteReferral($other);
    nativeProof($verifiedReferral['domain'] === $domain, 'Use the exact invited domain.');
    nativeBuyerSession($state, $otherAccount);
    WC()->cart->empty_cart();
    $_POST = [];
    WC()->cart->add_to_cart($product->get_id());
    do_action('woocommerce_checkout_update_order_review', 'kiwe_launch_domain=another.example.invalid');
    WC()->cart->calculate_totals();
    nativeProof(wc_add_number_precision((float) WC()->cart->get_total('edit')) === (float) $amount,
        'The referral cannot discount a different domain.');
    do_action('woocommerce_checkout_update_order_review', 'kiwe_launch_domain=' . $domain);
    WC()->cart->calculate_totals();
    $cartItems = WC()->cart->get_cart();
    $referredLine = reset($cartItems);
    $itemData = apply_filters('woocommerce_get_item_data', [], $referredLine);
    nativeProof($itemData[0]['key'] === 'Referral savings' && !WC()->cart->get_applied_coupons()
        && wc_add_number_precision((float) WC()->cart->get_total('edit')) === (float) $eligible,
        'The exact referred site gets one discount and no coupon code.');
    $referralCheckout = ['billing_email' => $otherAccount['email'], 'payment_method' => 'razorpay'];
    $_POST = ['kiwe_launch_domain' => $domain, 'kiwe_launch_domain_mode' => 'owned',
        'kiwe_launch_review_minor' => $amount, 'kiwe_launch_review_fragment_minor' => $eligible];
    $referralErrors = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $referralCheckout, $referralErrors);
    nativeProof(!$referralErrors->has_errors(), 'Confirm the matching native referral checkout. ' . $referralErrors->get_error_message());
    $orders[] = $referredOrder = nativeFixtureOrder($referredLine['data'], $otherAccount['email']);
    do_action('woocommerce_checkout_order_processed', $referredOrder->get_id(), $referralCheckout, $referredOrder);
    do_action('woocommerce_before_pay_action', $referredOrder);
    nativeProof(!apply_filters('woocommerce_cancel_unpaid_order', true, $referredOrder), 'Keep the reserved virtual referral order available for retry.');
    $duplicateRefused = false;
    try {
        $billing->approveReferralWebsitePrice($other, $referredOrder->get_id() + 100000, $plan['id'],
            ['mode' => 'owned', 'name' => $domain], $verifiedReferral['id']);
    } catch (KiweCheckoutError $error) {
        $duplicateRefused = $error->status === 409;
    }
    nativeProof($duplicateRefused, 'A referral cannot reserve a second payable website order.');

    $referralPurchase = nativeFixtureCapture($root, $referredOrder, $eligible);
    $receipt = $billing->status($other, $referralPurchase);
    $expectedSource = $receipt['renewalStart'] < $billing->freelancerPass($buyer)['paidThrough'] ? 'referral' : 'direct';
    nativeProof(!$billing->websiteReferral($other) && $receipt['projectId'] && $receipt['quote']['referralId'] === $verifiedReferral['id']
        && !isset($receipt['quote']['pricingSource']) && $receipt['quote']['partnerCommissionMinor'] === 2500
        && $receipt['renewalAvailable'] && $receipt['renewalPriceSource'] === $expectedSource,
        'The paid referral reaches Start once with the approved Portfolio commission rate.');
    $ledger = $billing->partners();
    $partnerDashboard = $ledger->dashboard($buyer, 1);
    nativeProof($partnerDashboard['clients'][0]['domain'] === $domain && $partnerDashboard['balance']['earned'] === 0,
        'A paid referral is visible to its partner and accrues only after verified site delivery.');
    // Advance the isolated ledger to two completed delivery months.
    $earlier = (new DateTimeImmutable('first day of this month', new DateTimeZone('Asia/Kolkata')))->setTime(0, 0)->modify('-2 months')->getTimestamp();
    $db->prepare('UPDATE launch_payments SET confirmed_at = ?, first_term_end = ? WHERE id = ?')
        ->execute([$earlier, kiwe_billing_month_end($earlier, 12), $referralPurchase]);
    $db->prepare('INSERT INTO launch_partner_services (purchase_id, ready_at) VALUES (?, ?)')->execute([$referralPurchase, $earlier + 1]);
    nativeProof($ledger->summary($buyer)['earned'] === 5000 && $ledger->summary($buyer)['available'] === 5000,
        'Two eligible Portfolio months earn exactly ₹50 in the existing partner ledger.');
    $referredRefund = json_encode(['account_id' => 'acc_KiweTestFixture', 'event' => 'refund.processed', 'created_at' => time(),
        'payload' => ['refund' => ['entity' => ['id' => 'rfnd_NativeReferralFixture', 'payment_id' => $referredOrder->get_transaction_id(),
            'status' => 'processed', 'currency' => 'INR', 'amount' => intdiv($eligible, 2)]]]], JSON_THROW_ON_ERROR);
    $state['billing']->ingest($referredRefund, hash_hmac('sha256', $referredRefund, 'Kiwe checkout isolated webhook fixture secret'), 'native-referral-refund');
    nativeProof($ledger->summary($buyer)['earned'] === 2500, 'A 50% client refund reverses the matching share of earned commission.');

    nativeBuyerSession($state, $account);
    $amountRefunded = $passPlan['priceMinor'];
    $refund = json_encode(['account_id' => 'acc_KiweTestFixture', 'event' => 'refund.processed', 'created_at' => time(),
        'payload' => ['refund' => ['entity' => ['id' => 'rfnd_NativePassFixture', 'payment_id' => $passOrder->get_transaction_id(),
            'status' => 'processed', 'currency' => 'INR', 'amount' => $amountRefunded]]]], JSON_THROW_ON_ERROR);
    $state['billing']->ingest($refund, hash_hmac('sha256', $refund, 'Kiwe checkout isolated webhook fixture secret'), 'native-pass-refund');
    nativeProof(!$billing->canIssueNativeReferrals($buyer) && $ledger->summary($buyer)['earned'] === 2500,
        'Loss of Pass coverage closes new referral issuance and preserves previously earned commission.');
    $denied = false;
    try {
        do_action('woocommerce_before_pay_action', $discountOrder);
    } catch (Exception $error) {
        $denied = str_contains($error->getMessage(), 'coverage changed');
    }
    nativeProof($denied, 'An ended pass blocks reopening a discounted order for payment.');
    $_POST = ['kiwe_launch_domain' => 'pass-discount.example.invalid'];
    WC()->cart->calculate_totals();
    nativeProof(wc_add_number_precision((float) WC()->cart->get_total('edit')) === (float) $amount,
        'A refunded pass must no longer discount the cart.');
    $discountId = nativeFixtureCapture($root, $discountOrder, $eligible);
    $discount = $state['billing']->status($buyer, $discountId);
    nativeProof($discount['projectId'] && $discount['quote']['pricingSource'] === 'pass' && $discount['quote']['referralId'] === null
        && !$discount['renewalPassPrice'] && $discount['renewalAmount'] === $plan['direct']['renewalMinor']
        && $discount['quote']['upfrontMinor'] === $eligible,
        'An already approved capture reaches the correct Start project at its frozen price.');
} finally {
    $cleanup = true;
    foreach ($orders as $order) {
        $id = $order->get_id();
        $actions = ActionScheduler::store();
        foreach (range(0, 5) as $attempt) {
            $query = ['hook' => 'kiwe_launch_reconcile_website_order', 'group' => 'kiwe-launch', 'args' => [$id, $attempt]];
            foreach ($actions->query_actions($query) as $scheduled) $actions->delete_action($scheduled);
        }
        wc_get_order($id)->delete(true);
        $cleanup = $cleanup && !wc_get_order($id);
    }
    foreach ([$product, $passProduct] as $item) {
        $productId = $item->get_id();
        if ($productId) $item->delete(true);
        $cleanup = $cleanup && (!$productId || !wc_get_product($productId));
    }
}
echo json_encode(['passed' => true, 'cleanup' => $cleanup, 'buyer' => $buyer, 'other' => $other], JSON_THROW_ON_ERROR), PHP_EOL;
