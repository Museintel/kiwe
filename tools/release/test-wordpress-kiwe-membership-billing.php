<?php

declare(strict_types=1);

// Native Woo records, REST dispatch, and documented Razorpay responses in the isolated checkout lab.
if (PHP_SAPI !== 'cli' || !($root = getenv('KIWE_CHECKOUT_LAB')) || !is_file($root . '/lab.json')) exit(1);
define('KIWE_RAZORPAY_SUBSCRIPTIONS_TEST', true);
define('KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT', 'acc_KiweLocalMembership');
define('KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET', str_repeat('membership-fixture-only-', 2));
if ('1' === getenv('KIWE_MEMBERSHIP_CPT_FIXTURE')) {
    $GLOBALS['wp_filter']['pre_option_woocommerce_custom_orders_table_enabled'][10][] = ['function'=>static fn ()=>'no','accepted_args'=>0];
}
require $root . '/site/wp-load.php';

use DSA\Access\Team_Workspace_Service as Users;
use DSA\Commerce\MCP_Store_Provider;
use DSA\Commerce\Membership_Account_Service as MyMembership;
use DSA\Commerce\Membership_Content_Service as MemberContent;
use DSA\Commerce\Membership_Directory as Membership;
use DSA\Commerce\Subscription_Billing_Service as Billing;
use DSA\Commerce\Subscription_Product_Service as Products;
use DSA\Commerce\Subscription_Renewal_Orders as Receipts;
use DSA\Notifications\Admin_Event_Notification_Service as Inbox;
use DSA\Notifications\Notification_Preference_Service as NoticeSettings;

if (wp_get_environment_type() !== 'local' || DB_NAME !== 'kiwe_checkout_lab' || !str_starts_with(home_url('/'), 'http://127.0.0.1:')) exit(2);
$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
$passed = [];
$assert = static function (mixed $ok, string $description) use (&$passed): void {
    if (!$ok) throw new RuntimeException($description);
    $passed[] = $description;
};
add_filter('pre_wp_mail', '__return_true');
$previous = get_option('woocommerce_razorpay_settings');
update_option('woocommerce_razorpay_settings', ['key_id'=>'rzp_test_LocalFixture', 'key_secret'=>'fake-key-for-isolated-transport'], false);
$service = new Billing();
$service->register();
$fixture = strtolower(wp_generate_password(8, false, false));
$makeUser = static function (string $kind, string $role) use ($fixture): int {
    $id = wp_insert_user([
        'user_login'=>$kind . '_' . $fixture, 'user_pass'=>wp_generate_password(32),
        'user_email'=>$kind . '.' . $fixture . '@example.invalid', 'display_name'=>ucfirst($kind) . ' ' . $fixture, 'role'=>$role,
    ]);
    if (is_wp_error($id)) throw new RuntimeException('The isolated WordPress user could not be created.');
    return $id;
};
$member = $makeUser('member', 'customer');
$other = $makeUser('other', 'customer');
$staff = $makeUser('staff', 'administrator');
$editor = $makeUser('editor', 'editor');
foreach ([$staff, $editor] as $id) {
    $user = new WP_User($id);
    foreach (['kiwe_manage_team','list_users','edit_users','kiwe_use_notifications'] as $capability) $user->add_cap($capability);
}
foreach ([$member, $other] as $id) (new WP_User($id))->add_cap('kiwe_use_notifications');
wp_set_current_user($staff);

$product = new WC_Product_Simple();
$product->set_name('Membership ' . $fixture);
$product->set_virtual(true);
$product->set_tax_status('none');
$product->set_regular_price('8400');
$product->set_status('draft');
Products::write($product, ['renewalMinor'=>70000, 'intervalMonths'=>1, 'firstTermMonths'=>12, 'membershipGroup'=>'cardio']);
$product->save();
$membershipProductId = $product->get_id();
$ordinary = new WC_Product_Simple();
$ordinary->set_name('One-time item ' . $fixture);
$ordinary->set_virtual(true);
$ordinary->set_regular_price('338');
$ordinary->set_status('draft');
$ordinary->save();
$order = wc_create_order(['status'=>'pending', 'customer_id'=>$member, 'created_via'=>'kiwe-billing-fixture']);
$membershipOrderId = $order->get_id();
$order->set_currency('INR');
$order->add_product($product, 1);
$order->add_product($ordinary, 1);
$order->calculate_totals();
$order->save();

$planId = 'plan_MemberFixture';
$subscriptionId = 'sub_MemberFixture' . $membershipOrderId;
$provider = ['plans/'.$planId=>['id'=>$planId,'period'=>'monthly','interval'=>1,'item'=>['amount'=>70000,'currency'=>'INR']]];
$accepted = [];
$networkWrites = 0;
$cancelWrites = 0;
$uncertain = true;
$loseCancellation = true;
add_filter('pre_http_request', static function ($reply, $args, $url) use (&$provider, &$accepted, &$networkWrites, &$cancelWrites, &$uncertain, &$loseCancellation, $subscriptionId) {
    if (!str_starts_with($url, 'https://api.razorpay.com/v1/')) return new WP_Error('isolated_fixture', 'The membership fixture allows only its simulated provider.');
    $key = parse_url($url, PHP_URL_PATH);
    $resource = substr($key, 4);
    if ('subscriptions' === $resource && 'POST' === $args['method']) {
        ++$networkWrites;
        $accepted = json_decode($args['body'], true, flags: JSON_THROW_ON_ERROR);
        $createdId = 'plan_FixtureUpgrade' === $accepted['plan_id'] ? 'sub_switch'.$accepted['notes']['kiwe_order'] : $subscriptionId;
        $provider['subscriptions/'.$createdId] = $accepted + ['id'=>$createdId, 'status'=>'created','short_url'=>'https://rzp.io/i/kiwefixture'];
        if ($uncertain) return new WP_Error('lost_fixture_response', 'Provider accepted the request before the response was lost.');
        $resource .= '/' . $createdId;
    }
    if (('subscriptions/'.$subscriptionId.'/cancel') === $resource && 'POST' === $args['method']) {
        ++$cancelWrites;
        if (['cancel_at_cycle_end'=>false] !== json_decode($args['body'], true)) throw new RuntimeException('The fixture must stop future billing.');
        $resource = 'subscriptions/'.$subscriptionId;
        $provider[$resource]['status'] = 'cancelled';
        if ($loseCancellation) return new WP_Error('lost_cancel_response', 'The fixture lost the cancellation response.');
    }
    if (str_ends_with($resource, '/cancel') && ('subscriptions/'.$subscriptionId.'/cancel') !== substr($key,4) && 'POST' === $args['method']) {
        ++$cancelWrites;
        $resource = substr($resource,0,-7);
        if (isset($provider[$resource])) $provider[$resource]['status'] = 'cancelled';
    }
    foreach (['pause'=>'paused','resume'=>'active'] as $action=>$state) {
        if (('subscriptions/'.$subscriptionId.'/'.$action) !== $resource || 'POST' !== $args['method']) continue;
        if ([$action.'_at'=>'now'] !== json_decode($args['body'], true)) throw new RuntimeException('Unexpected pause or resume timing.');
        $resource = 'subscriptions/'.$subscriptionId;
        $provider[$resource]['status'] = $state;
    }
    return isset($provider[$resource])
        ? ['response'=>['code'=>200], 'body'=>wp_json_encode($provider[$resource])]
        : ['response'=>['code'=>404], 'body'=>'{}'];
}, 10, 3);

$memberPage = 0;
try {
    $ops = MCP_Store_Provider::operations();
    $change = $ops['store.subscription.change'];
    $members = $ops['store.members.list'];
    $write = static function (array $op, array $input) {
        if (!$op['permission']($input)) throw new RuntimeException('The fixture operation was not authorized.');
        return $op['execute']($input, $op['prepare']($input)['nativeExpected']);
    };
    $input = ['orderId'=>$membershipOrderId, 'action'=>'enroll', 'recordId'=>$planId];
    $assert($change['discoverPermission']() && 'required' === $change['approval'], 'Only configured, authorized MCP enrollment is available and it requires review.');
    try {
        $write($change, $input);
        throw new LogicException('The lost response was not simulated.');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() !== '', 'An uncertain create leaves the order reviewable.');
    }
    $assert($write($change, $input)['state'] === 'provider-review' && $networkWrites === 1, 'Retry after a lost response sends no second provider request.');
    $agreement = wc_get_order($membershipOrderId)->get_meta(Receipts::AGREEMENT);
    $assert($accepted['addons'][0]['item']['amount'] === 873800 && $accepted['customer_notify'] === false
        && $accepted['start_at'] === $agreement['firstRenewalAt'] && $accepted['total_count'] === 12,
        'The combined native Woo total is charged upfront and the approval contains twelve scheduled renewal cycles.');
    $assert($write($change, ['orderId'=>$membershipOrderId, 'action'=>'recover','recordId'=>$subscriptionId])['state'] === 'awaiting-approval', 'Provider readback recovers the exact recorded approval.');
    $assert(!apply_filters('woocommerce_order_needs_payment', true, wc_get_order($membershipOrderId)), 'An enrolled Woo order rejects the ordinary order-pay path.');
    $assert(!(new Users())->membership_listing(1, $fixture)['people'], 'An authorization request alone never creates a member.');
    $product->set_name('Current catalog name');
    Products::write($product, ['renewalMinor'=>90000, 'intervalMonths'=>1, 'firstTermMonths'=>12]);
    $product->save();

    $webhook = static function (string $event, array $payload, string $account = KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT, bool $valid = true): WP_REST_Response {
        $raw = wp_json_encode(['event'=>$event, 'account_id'=>$account,'payload'=>$payload]);
        $request = new WP_REST_Request('POST', '/dsa/v1' . Billing::ROUTE);
        $request->set_body($raw);
        $request->set_header('content-type','application/json');
        $request->set_header('x-razorpay-signature', $valid ? hash_hmac('sha256',$raw,KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET) : str_repeat('0', 64));
        return rest_do_request($request);
    };
    $furnish = static function (string $suffix, int $amount, ?int $start, ?int $end) use (&$provider, $subscriptionId, $agreement): string {
        $pay = 'pay_' . $suffix;
        $invoice = 'inv_' . $suffix;
        $provider['payments/'.$pay] = [
            'id'=>$pay, 'invoice_id'=>$invoice, 'order_id'=>'order_'.$suffix, 'currency'=>'INR',
            'status'=>'captured','amount'=>$amount,'amount_refunded'=>0,
        ];
        $provider['invoices/'.$invoice] = [
            'id'=>$invoice,'payment_id'=>$pay,'subscription_id'=>$subscriptionId,'order_id'=>'order_'.$suffix,
            'status'=>'paid','currency'=>'INR','amount'=>$amount,'amount_paid'=>$amount,
            'billing_start'=>$start,'billing_end'=>$end,'paid_at'=>$agreement['createdAt'],
        ];
        return $pay;
    };
    $payload = static fn (string $payment): array => ['payment'=>['entity'=>['id'=>$payment]]];
    $first = $furnish('first'.$membershipOrderId, 873800, null, null);
    wp_set_current_user(0);
    $assert($webhook('payment.captured', $payload($first), KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT, false)->get_status() === 401, 'An unsigned callback cannot change the order.');
    $assert($webhook('payment.captured', $payload($first), 'acc_AnotherStore')->get_status() === 403, 'A different merchant cannot update this site.');
    $assert($webhook('subscription.authenticated', ['payment'=>['entity'=>['id'=>$first]]])->get_status() === 200
        && !wc_get_order($membershipOrderId)->is_paid(), 'Mandate authentication alone does not grant paid access.');
    $assert($webhook('payment.captured',$payload($first))->get_status() === 200 && wc_get_order($membershipOrderId)->get_transaction_id() === $first, 'A provider-confirmed first charge pays the existing native order.');
    $assert($webhook('subscription.charged',$payload($first))->get_status() === 200, 'Overlapping provider events safely replay the initial charge.');

    $start = $agreement['firstRenewalAt'];
    $end = $start + 28 * DAY_IN_SECONDS;
    $late = $furnish('late'.$membershipOrderId, 70000, $end, $end + 30 * DAY_IN_SECONDS);
    $renewal = $furnish('renewal'.$membershipOrderId, 70000, $start, $end);
    $wrong = $furnish('wrong'.$membershipOrderId, 90000, $start, $end);
    $assert($webhook('subscription.charged',$payload($wrong))->get_status() === 409, 'An invoice for the current catalog price cannot replace the consented renewal price.');
    $assert($webhook('subscription.charged',$payload($late))->get_status() === 200
        && $webhook('subscription.charged',$payload($renewal))->get_status() === 200
        && $webhook('payment.captured',$payload($renewal))->get_status() === 200, 'Out-of-order payments and duplicate events each record the correct verified period.');
    $orders = wc_get_orders(['parent'=>$membershipOrderId,'created_via'=>'kiwe-subscription','limit'=>5]);
    $assert(count($orders) === 2, 'WooCommerce stores exactly one renewal order per paid invoice.');
    foreach ($orders as $receipt) {
        $items = array_values($receipt->get_items());
        $assert($receipt->is_paid() && $receipt->get_total() === '700.00' && count($items) === 1 && $items[0]->get_name() === 'Membership '.$fixture,
            'Renewals keep the original member line and exclude the one-time purchase.');
    }
    $overlap = $furnish('overlap'.$membershipOrderId, 70000, $start + DAY_IN_SECONDS, $end);
    $assert($webhook('subscription.charged',$payload($overlap))->get_status() === 409, 'A different invoice cannot cover an already paid period.');
    $assert(Receipts::paid_period(wc_get_order($membershipOrderId), $start)['active'], 'The verified renewal covers its own service window.');
    $assert(Receipts::paid_period(wc_get_order($membershipOrderId), $end + 31 * DAY_IN_SECONDS)['status'] === 'expired', 'Expired access is derived from paid dates without depending on scheduled jobs.');

    $inbox = static function (int $user) use ($membershipOrderId): array {
        wp_set_current_user($user);
        $p = new NoticeSettings(new DSA\Settings(), new DSA\Trust\Trust_Service());
        $service = new Inbox($p, new DSA\Notifications\Push_Service(new DSA\Settings()));
        $filter = static fn ($item)=>str_starts_with($item['id'] ?? '', 'membership-'.$membershipOrderId.'-');
        $front = array_values(array_filter($service->pull_current_user(), $filter));
        $back = array_values(array_filter($service->workspace_inbox(array_column($p->workspace_snapshot()['config']['topics'],'id')), $filter));
        if (array_column($front,'id') !== array_column($back,'id')) throw new RuntimeException('Frontend DSA and the dashboard disagree on visible membership notifications.');
        return $front;
    };
    $customerNotices = $inbox($member);
    $staffNotices = $inbox($staff);
    $assert(count($customerNotices) === 3 && count($staffNotices) === 3 && !$inbox($other) && !$inbox($editor),
        'Each paid period generates one inbox update for the member and permitted staff only.');
    foreach ([$customerNotices,$staffNotices] as $notices) {
        $titles = array_column($notices,'title');
        $assert(count(array_filter($titles, static fn ($title) => str_ends_with($title,'Membership '.$fixture))) === 3
            && count(array_filter($titles, static fn ($title) => str_starts_with($title,'Membership automatically renewed'))) === 2,
            'The inbox uses the purchased plan name and identifies each automatic renewal.');
    }
    $profile = new WP_REST_Request('GET', '/dsa/v1/account/orders');
    $profile->set_query_params(['memberships'=>'1']);
    wp_set_current_user($member);
    $assert(Receipts::membership(wc_get_order($membershipOrderId))['status'] === 'active' && !$members['discoverPermission'](), 'A customer can read their own membership and cannot discover the staff directory.');
    $profileData = rest_do_request($profile);
    $activeCard = $profileData->get_data()['activeMemberships'][0] ?? [];
    $assert(($activeCard['name'] ?? '') === 'Membership '.$fixture && $activeCard['orderId'] === $membershipOrderId
        && $profileData->get_headers()['Cache-Control'] === 'private, no-store', 'The private Profile rail uses the purchased plan and the original Woo order.');
    $assert(!$ops['store.subscription.orders']['permission']([]), 'A shopper cannot query the staff Orders tab through MCP.');
    wp_set_current_user($other);
    $assert(!Receipts::membership(wc_get_order($membershipOrderId)) && !$members['permission']([]), 'Another customer cannot read that membership or the staff roster.');
    $assert(empty(rest_do_request($profile)->get_data()['activeMemberships']), 'An unrelated shopper receives no membership cards.');
    wp_set_current_user($staff);
    $subscriptions = array_column(json_decode($ops['store.subscription.orders']['execute']([])['resultJson'], true)['orders'], null, 'id');
    $assert(($subscriptions[$membershipOrderId]['subscriptionLabel'] ?? '') === 'New subscription', 'The Orders tab marks the original native subscription order.');
    foreach ($orders as $receipt) {
        $assert(($subscriptions[$receipt->get_id()]['subscriptionLabel'] ?? '') === 'Subscription renewal', 'A renewal is a separate native order in the same tab.');
    }
    $list = json_decode($members['execute'](['search'=>$fixture])['resultJson'], true);
    $assert(count($list['people']) === 1 && $list['people'][0]['id'] === $member && $list['people'][0]['status'] === 'active',
        'The Members view and MCP show the same active purchaser while leaving ordinary customers out.');
    $userCard = (new Users())->member($member);
    $assert(($userCard['membership']['status'] ?? '') === 'active', 'The ordinary Daily Users card shows the same verified membership badge.');

    $content = $ops['store.membership.page.access'];
    $self = $ops['store.membership.self'];
    $cancel = $ops['store.membership.cancel'];
    $memberPage = wp_insert_post([
        'post_type'=>'page','post_status'=>'private','post_title'=>'Member fixture '.$fixture,
        'post_content'=>'Paid access fixture '.$fixture,'post_author'=>$staff,
    ]);
    $assert(is_int($memberPage) && $memberPage > 0 && $content['permission'](['pageId'=>$memberPage]), 'A permitted editor and billing manager can configure a native private page.');
    $old = MemberContent::settings($memberPage)['expected'];
    $configured = ['pageId'=>$memberPage, 'memberOnly'=>true];
    $assert('required' === $content['approval'] && json_decode($write($content, $configured)['resultJson'], true)['memberOnly'], 'Reviewed MCP changes the same member access setting as the native page editor.');
    $stale = false;
    try { MemberContent::change($memberPage, false, $old); } catch (DomainException $error) { $stale = true; }
    $assert($stale, 'An old membership page review cannot remove its current access policy.');
    wp_update_post(['ID'=>$memberPage, 'post_status'=>'publish']);
    $assert('private' === get_post_status($memberPage), 'A marked private page stays private when the ordinary page writer runs.');
    $requestPage = static function (int $page): WP_REST_Response {
        return rest_do_request(new WP_REST_Request('GET', '/wp/v2/pages/' . $page));
    };
    $getPage = static fn (int $pageId) => (new WP_Query(['page_id'=>$pageId]))->post?->ID ?? 0;
    foreach ([0, $other] as $viewer) {
        wp_set_current_user($viewer);
        $assert($getPage($memberPage) === 0 && $requestPage($memberPage)->get_status() >= 400
            && !$cancel['permission'](['orderId'=>$membershipOrderId]), 'A guest or unrelated shopper cannot open the private page or cancel another order.');
    }
    wp_set_current_user($member);
    $readback = json_decode($self['execute']([])['resultJson'], true);
    $assert(Membership::has_paid_access($member), 'The native paid ledger grants this member access.');
    $assert($getPage($memberPage) === $memberPage, 'A paid member can query the native private page.');
    $assert($requestPage($memberPage)->get_status() === 200, 'A paid member can read the page through WordPress REST.');
    $assert(!current_user_can('edit_post', $memberPage) && !$content['permission']($configured), 'Membership grants no authoring access.');
    $assert(($readback['memberships'][0]['orderId'] ?? 0) === $membershipOrderId, 'The member lists their own original order and current membership.');
    update_user_meta($member, Users::SUSPENDED_META, '1');
    $assert($getPage($memberPage) === 0 && !MyMembership::available(), 'Suspension revokes member page and self-service access.');
    delete_user_meta($member, Users::SUSPENDED_META);

    $provider['subscriptions/'.$subscriptionId]['status'] = 'active';
    $eventSubject = ['subscription'=>['entity'=>['id'=>$subscriptionId]]];
    $assert($webhook('subscription.resumed',$eventSubject)->get_status() === 200, 'A signed lifecycle event refreshes the active provider state.');
    $pauseControl = $ops['store.membership.renewals'];
    $pauseInput = ['orderId'=>$membershipOrderId,'action'=>'pause'];
    wp_set_current_user($other);
    $assert(!$pauseControl['permission']($pauseInput), 'Another customer cannot pause the buyer’s subscription.');
    wp_set_current_user($member);
    $paused = json_decode($write($pauseControl,$pauseInput)['resultJson'],true);
    $assert($paused['status'] === 'paused' && $paused['active'] && $paused['canResume'], 'Pause stops future billing while keeping the paid membership visible.');
    $resumed = json_decode($write($pauseControl,['orderId'=>$membershipOrderId,'action'=>'resume'])['resultJson'],true);
    $assert($resumed['status'] === 'active' && $resumed['active'] && $resumed['canPause'], 'A confirmed resume preserves the paid period and re-enables future billing.');
    DSA\Commerce\Subscription_Quote_Service::assert_groups_available($member, [['productId'=>$ordinary->get_id(),'membershipGroup'=>'full-gym']]);
    $assert(true, 'A membership in a separate group can coexist.');
    foreach ([['productId'=>$ordinary->get_id(),'membershipGroup'=>'cardio'],['productId'=>$membershipProductId,'membershipGroup'=>'renamed']] as $candidate) {
        try {
            DSA\Commerce\Subscription_Quote_Service::assert_groups_available($member, [$candidate]);
            throw new LogicException('The duplicate plan was accepted.');
        } catch (DomainException $error) {
            $assert(true, 'An overlapping membership is blocked by its group or its native product family.');
        }
    }

    $stop = ['orderId'=>$membershipOrderId];
    $review = $cancel['prepare']($stop);
    $assert('required' === $cancel['approval'] && $cancel['permission']($stop), 'Stopping the shopper’s own renewals requires approval.');
    $provider['subscriptions/'.$subscriptionId]['status'] = 'active';
    $interrupted = false;
    try { $cancel['execute']($stop, $review['nativeExpected']); } catch (RuntimeException $error) { $interrupted = true; }
    $assert($interrupted && $cancelWrites === 1 && Receipts::paid_period(wc_get_order($membershipOrderId))['status'] === 'active',
        'A lost cancellation reply leaves paid access and the stored agreement available for recovery.');
    $recovered = json_decode($write($cancel, $stop)['resultJson'], true);
    $assert($recovered['active'] && 'cancelled' === $recovered['status'] && $cancelWrites === 1
        && $getPage($memberPage) === $memberPage && !$cancel['permission']($stop), 'The next request reads back cancellation, retains paid access and sends no second provider write.');
    $stillCovered = false;
    try {
        DSA\Commerce\Subscription_Quote_Service::assert_groups_available($member, [['productId'=>$membershipProductId]]);
    } catch (DomainException $error) {
        $stillCovered = true;
    }
    $assert($stillCovered, 'A cancelled membership with a paid term still blocks replacement billing.');

    wp_set_current_user(0);
    $cancelEvent = ['subscription'=>['entity'=>['id'=>$subscriptionId]]];
    $assert($webhook('subscription.cancelled',$cancelEvent)->get_status() === 200
        && $webhook('subscription.cancelled',$cancelEvent)->get_status() === 200, 'A confirmed cancellation is safe to reconcile twice.');
    $assert(Receipts::paid_period(wc_get_order($membershipOrderId))['status'] === 'cancelled' && Receipts::paid_period(wc_get_order($membershipOrderId))['active'],
        'The cancelled membership is marked red while its existing paid term remains usable.');
    foreach ([$member, $staff] as $recipient) {
        $notices = $inbox($recipient);
        $titles = array_column($notices, 'title');
        $assert(count($notices) === 4 && in_array('Membership cancelled: Membership ' . $fixture, $titles, true),
            'Cancellation names the purchased plan once in each authorized inbox.');
    }
    $staffUser = new WP_User($staff);
    $staffUser->add_cap('edit_users', false);
    wp_set_current_user(0);
    wp_set_current_user($staff);
    $assert(!$members['discoverPermission']() && !$inbox($staff), 'Removing staff access immediately hides both the Members view and previously stored private notices.');
    $staffUser->add_cap('edit_users');
    wp_set_current_user(0);
    $provider['payments/'.$first]['amount_refunded'] = 873800;
    $provider['payments/'.$first]['status'] = 'refunded';
    $refund = ['refund'=>['entity'=>['payment_id'=>$first]]];
    $assert($webhook('refund.processed',$refund)->get_status() === 200 && $webhook('refund.processed',$refund)->get_status() === 200,
        'The provider refund can be delivered repeatedly.');
    $refunded = wc_get_order($membershipOrderId);
    $assert(count($refunded->get_refunds()) === 1 && (float) $refunded->get_total_refunded() === 8738.0
        && !Receipts::paid_period($refunded)['active'], 'One native refund removes access for that refunded period without charging the provider.');
    wp_set_current_user($member);
    $assert(!Membership::has_paid_access($member) && $getPage($memberPage) === 0 && $requestPage($memberPage)->get_status() >= 400,
        'A refund that removes the current paid period revokes the native page and REST grants.');
    wp_set_current_user($staff);
    $allOrders = array_column((new DSA\Access\Commerce_App_Service())->workspace('orders')['orders'], null, 'id');
    $assert(isset($allOrders[$membershipOrderId]) && !isset($allOrders[$refunded->get_refunds()[0]->get_id()]),
        'Daily Orders remains available after a refund and includes the actual refunded order.');

    define('KIWE_RAZORPAY_CHECKOUT_TEST_PLANS', ['54000:6'=>'plan_FixtureUpgrade']);
    $provider['plans/plan_FixtureUpgrade'] = ['id'=>'plan_FixtureUpgrade','period'=>'monthly','interval'=>6,'item'=>['amount'=>54000,'currency'=>'INR']];
    $family = new WC_Product_Variable();
    $family->set_name('Variable switch fixture '.$fixture);
    $family->set_status('publish');
    $family->save();
    $terms = [];
    foreach ([3=>300,6=>540] as $months=>$price) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($family->get_id());
        $variation->set_status('publish');
        $variation->set_virtual(true);
        $variation->set_tax_status('none');
        $variation->set_regular_price((string)$price);
        $variation->set_price((string)$price);
        Products::write($variation,['renewalMinor'=>$price*100,'intervalMonths'=>$months,'firstTermMonths'=>$months,'membershipGroup'=>'switch-'.$fixture]);
        $variation->save();
        $terms[$months] = $variation;
    }
    WC_Product_Variable::sync($family);
    $old = wc_create_order(['status'=>'pending','customer_id'=>$member,'created_via'=>'kiwe-billing-fixture']);
    $old->set_currency('INR');
    $old->set_payment_method(Receipts::GATEWAY);
    $old->add_product($terms[3],1);
    $old->calculate_totals();
    $oldId = $old->get_id();
    $startsAt = time() + 70 * DAY_IN_SECONDS;
    $sourceSubscription = 'sub_switchSource'.$oldId;
    $sourceAgreement = DSA\Commerce\Subscription_Quote_Service::snapshot($old) + [
        'orderId'=>$oldId,'customerId'=>$member,'planId'=>'plan_SwitchSource','cycles'=>12,'reference'=>'switch-'.$fixture,
        'createdAt'=>time()-DAY_IN_SECONDS,'firstRenewalAt'=>$startsAt,
        'subscriptionId'=>$sourceSubscription,'providerStatus'=>'active',
    ];
    $old->update_meta_data(Receipts::AGREEMENT,$sourceAgreement);
    $old->update_meta_data(Receipts::CHARGE,[
        'invoiceId'=>'inv_switchSource'.$oldId,'paymentId'=>'pay_switchSource'.$oldId,'initial'=>true,'amountMinor'=>30000,
        'refundedMinor'=>0,'startsAt'=>time()-DAY_IN_SECONDS,'endsAt'=>$startsAt,
    ]);
    $old->save();
    $old->payment_complete('pay_switchSource'.$oldId);
    $provider['subscriptions/'.$sourceSubscription] = [
        'id'=>$sourceSubscription,'status'=>'active','notes'=>['kiwe_order'=>(string)$oldId,'kiwe_agreement'=>'switch-'.$fixture],
        'plan_id'=>'plan_SwitchSource','start_at'=>$startsAt,'total_count'=>12,'quantity'=>1,
    ];
    $switch = $ops['store.membership.switch'];
    $switchInput = ['orderId'=>$oldId,'variationId'=>$terms[6]->get_id()];
    wp_set_current_user($other);
    $assert(!$switch['permission']($switchInput),'Another buyer cannot replace a membership.');
    wp_set_current_user($member);
    $assert('required' === $switch['approval'] && $switch['permission']($switchInput),'A longer published term needs the buyer’s own exact review.');
    $expected = $switch['prepare']($switchInput)['nativeExpected'];
    $beforeCalls = [$networkWrites,$cancelWrites];
    try {
        Billing::switch_own_plan($oldId,$terms[6]->get_id(),str_repeat('0',64));
        throw new LogicException('A stale switch was accepted.');
    } catch (DomainException $error) {
        $assert($beforeCalls === [$networkWrites,$cancelWrites],'An invalid review changes no provider state.');
    }
    $uncertain = false;
    $url = Billing::switch_own_plan($oldId,$terms[6]->get_id(),$expected);
    $newId = (int) $accepted['notes']['kiwe_order'];
    $new = wc_get_order($newId);
    $newAgreement = $new->get_meta(Receipts::AGREEMENT);
    $assert($newId !== $oldId && $newAgreement['switchFrom'] === $oldId && $newAgreement['startsAt'] === $startsAt
        && $new->get_view_order_url() === $url && $accepted['addons'][0]['item']['amount'] === 54000,
        'A reviewed switch records the next term on a new native prepaid order.');
    $assert(Billing::switch_own_plan($oldId,$terms[6]->get_id(),$expected) === $url
        && [$networkWrites,$cancelWrites] === [1+$beforeCalls[0],1+$beforeCalls[1]],
        'A repeated switch resumes the same replacement and cancels the old mandate only once.');
    $assert(Receipts::paid_period(wc_get_order($oldId))['active'] && !$new->is_paid(),
        'Cancelling the old mandate preserves current service while the next order awaits payment.');
    $newPayment = $furnish('switch'.$newId, 54000, null, null);
    $provider['invoices/inv_switch'.$newId]['subscription_id'] = 'sub_switch'.$newId;
    $provider['invoices/inv_switch'.$newId]['paid_at'] = $newAgreement['createdAt'];
    $newSubscription = 'sub_switch'.$newId;
    $signature = hash_hmac('sha256', $newPayment.'|'.$newSubscription, 'fake-key-for-isolated-transport');
    wp_set_current_user($other);
    $assert(!Billing::confirm_checkout_payment($new,$newPayment,$newSubscription,$signature),'The return cannot settle another buyer’s order.');
    wp_set_current_user($member);
    $assert(!Billing::confirm_checkout_payment($new,$newPayment,$newSubscription,str_repeat('0',64)),'The return rejects an invalid Razorpay signature.');
    $assert(Billing::confirm_checkout_payment($new,$newPayment,$newSubscription,$signature)
        && wc_get_order($newId)->is_paid(),'A verified Razorpay return settles the original Woo payment.');
    wp_set_current_user(0);
    $assert($webhook('payment.captured',$payload($newPayment))->get_status() === 200,'The webhook safely repeats the capture after the buyer return.');
    wp_set_current_user($member);
    $new = wc_get_order($newId);
    $future = Receipts::paid_period($new);
    $assert(!$future['active'] && $future['status'] === 'scheduled' && $future['startsAt'] === $startsAt
        && Receipts::paid_period($new,$startsAt)['active'] && !Receipts::paid_period(wc_get_order($oldId),$startsAt)['active'],
        'Paid access transfers at the saved boundary without overlapping the original term.');
    $memberView = new WP_REST_Request('GET','/dsa/v1/account/memberships');
    $memberView->set_query_params(['orderId'=>$oldId]);
    $assert(rest_do_request($memberView)->get_data()['membership']['orderId'] === $oldId,'The Profile detail reads the buyer’s membership without a Woo account page.');
    wp_set_current_user($other);
    $assert(rest_do_request($memberView)->get_status() === 404,'Profile cannot open another buyer’s membership detail.');

    echo wp_json_encode(['verified'=>true,'hpos'=>$hpos,'checks'=>count($passed),'fixtureSearch'=>$fixture,'members'=>count($list['people']),
        'staffId'=>$staff,'editorId'=>$editor,'memberId'=>$member,'orderId'=>$membershipOrderId]), PHP_EOL;
} finally {
    if ($memberPage) wp_delete_post($memberPage, true);
    if (false === $previous) delete_option('woocommerce_razorpay_settings');
    else update_option('woocommerce_razorpay_settings', $previous, false);
}
