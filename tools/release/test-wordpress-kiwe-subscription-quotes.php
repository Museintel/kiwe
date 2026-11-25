<?php

declare(strict_types=1);

// Uses native WooCommerce products and orders in the isolated Launch checkout lab.
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('KIWE_CHECKOUT_LAB');
$planFile = getenv('KIWE_LAUNCH_PLAN_JSON');
if (!is_string($root) || !is_file($root . '/lab.json') || !is_string($planFile) || !is_file($planFile)) {
    throw new RuntimeException('A checkout lab and the canonical Kiwe Client plan are required.');
}
require $root . '/site/wp-load.php';

use DSA\Access\Commerce_App_Service as Products;
use DSA\Access\Product_Variation_Workspace_Service as Variations;
use DSA\Commerce\MCP_Store_Provider;
use DSA\Commerce\Subscription_Product_Service as Billing;
use DSA\Commerce\Subscription_Quote_Service as Quotes;

if (wp_get_environment_type() !== 'local' || DB_NAME !== 'kiwe_checkout_lab'
    || !str_starts_with(home_url('/'), 'http://127.0.0.1:')) exit(2);

$plan = json_decode(file_get_contents($planFile), true, flags: JSON_THROW_ON_ERROR);
$owner = get_user_by('login', 'checkout-owner');
if (!$owner || $plan['id'] !== 'kiwe-client-managed-v1' || $plan['currency'] !== 'INR') exit(3);
wp_set_current_user($owner->ID);
$operation = MCP_Store_Provider::operations()['store.subscription.quote'];
$makeProduct = static function (string $offer, array $prices) use ($plan): WC_Product {
    $sku = 'kiwe-launch-fixture-' . $offer;
    $existing = wc_get_product_id_by_sku($sku);
    $product = $existing ? wc_get_product($existing) : new WC_Product_Simple();
    $product->set_name('Kiwe Client fixture ' . $offer);
    $product->set_sku($sku);
    $product->set_virtual(true);
    $product->set_status('draft');
    $product->set_regular_price(wc_format_decimal($prices['upfrontMinor'] / 100, 2));
    $product->update_meta_data(Quotes::PRODUCT_META, [
        'renewalMinor' => $prices['renewalMinor'],
        'intervalMonths' => $plan['renewalIntervalMonths'],
        'firstTermMonths' => $plan['firstTermMonths'],
    ]);
    $product->save();
    return $product;
};
$createOrder = static function (array $products, bool $coupon = false): WC_Order {
    $order = wc_create_order(['status'=>'pending', 'created_via'=>'kiwe-quote-fixture']);
    if (is_wp_error($order)) throw new RuntimeException('Could not create the native fixture order.');
    $order->set_currency('INR');
    foreach ($products as $product) $order->add_product($product, 1);
    if ($coupon && is_wp_error($order->apply_coupon('kiwe-pilot-ten'))) throw new RuntimeException('Native coupon was unavailable.');
    $order->calculate_totals();
    $order->save();
    return $order;
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$writeProduct = static function (int $id, array $fields): void {
    $save = MCP_Store_Provider::operations()['store.product.save'];
    $input = ['id'=>$id, 'fieldsJson'=>wp_json_encode($fields)];
    $preview = $save['prepare']($input);
    $saved = json_decode($save['execute']($input,$preview['nativeExpected'])['resultJson'], true, flags:JSON_THROW_ON_ERROR);
    if (empty($saved['confirmed']) || $saved['id'] !== $id) throw new RuntimeException('The MCP product write was not confirmed.');
};

$direct = $makeProduct('direct', $plan['direct']);
$referred = $makeProduct('referred', $plan['referred']);
$simple = wc_get_product((int) get_option('kiwe_lab_checkout_product'));
$variation = wc_get_product(wc_get_product_id_by_sku('kiwe-pilot-variation-single'));
$assert($simple && $variation && $variation->is_type('variation'), 'Ordinary simple and variable fixtures are required.');
$products = new Products();
$option = get_option(Billing::OPTION, null);
update_option(Billing::OPTION,'1',false);
try {
    $record = $products->product_record($direct->get_id());
    $assert(!is_wp_error($record) && $record['subscriptionTerms']['renewalMinor'] === $plan['direct']['renewalMinor'], 'The native editor returns current recurring terms.');
    $writeProduct($direct->get_id(), ['subscriptionTerms'=>null]);
    $assert(Billing::terms(wc_get_product($direct->get_id())) === null, 'MCP can return a native product to one-time billing.');
    $writeProduct($direct->get_id(), ['subscriptionTerms'=>$record['subscriptionTerms']]);
    $restored = $products->product_record($direct->get_id());
    $assert($restored['subscriptionTerms'] === $record['subscriptionTerms'], 'MCP and the native product editor use the same Woo metadata.');
    $assert(is_wp_error($products->save_product_record($direct->get_id(),[
        'expected'=>$restored['expected'], 'name'=>$restored['name'],'price'=>$restored['price'],
        'categories'=>$restored['categories'],'status'=>'publish',
    ])), 'A recurring draft cannot use the ordinary publish path in this pilot.');
    $assert(!(new Billing())->purchasable(true, wc_get_product($direct->get_id())), 'An ordinary checkout cannot accept a recurring draft as a one-time payment.');
    $assert(is_wp_error(Billing::validate(['renewalMinor'=>1,'firstTermMonths'=>0,'intervalMonths'=>1], true, false)), 'Invalid renewal terms are rejected.');

    $variants = new Variations();
    $parent = $variation->get_parent_id();
    $variant = $variants->variation($parent,$variation->get_id());
    $assert(!is_wp_error($variant) && array_key_exists('subscriptionTerms',$variant), 'The native variation exposes its own billing control.');
    $variationFields = [
        'attributes','enabled','price','salePrice','startsAt','endsAt','sku','manageStock','stock','stockStatus','backorders',
        'imageId','description','virtual','weight','length','width','height','shippingClassId','taxClass','position',
    ];
    $original = array_intersect_key($variant, array_flip($variationFields));
    $payload = array_replace($original, ['enabled'=>false,'subscriptionTerms'=>[
        'renewalMinor'=>12900,'intervalMonths'=>1,'firstTermMonths'=>1,
    ],'expected'=>$variant['expected'],'confirmed'=>true]);
    try {
        $result = $variants->save_variation($parent,$variation->get_id(),$payload);
        if (is_wp_error($result)) throw new RuntimeException('Native variation write failed: '.$result->get_error_message());
        $assert($result['changed'] && $result['variation']['subscriptionTerms'] === $payload['subscriptionTerms']
            && !$result['variation']['enabled'], 'An individual variation persists its recurring terms and disabled state.');
        $blocked = $variants->save_variation($parent,$variation->get_id(),array_replace($payload,[
            'enabled'=>true,'expected'=>$result['variation']['expected'],
        ]));
        $assert(is_wp_error($blocked),'A recurring variation remains disabled until the new billing flow is available.');
        $clear = $variants->save_variation($parent,$variation->get_id(),array_replace($original,[
            'subscriptionTerms'=>null,'expected'=>$result['variation']['expected'],'confirmed'=>true,
        ]));
        $assert(!is_wp_error($clear) && $clear['variation']['subscriptionTerms'] === null,
            'Restoring one-time billing restores the original variation behavior.');
    } finally {
        $recovery = wc_get_product($variation->get_id());
        if ($recovery && Billing::terms($recovery) !== null) {
            Billing::write($recovery, null);
            $recovery->set_status($original['enabled']?'publish':'private');
            $recovery->save();
            WC_Product_Variable::sync($parent);
        }
    }
} finally {
    if (null === $option) delete_option(Billing::OPTION);
    else update_option(Billing::OPTION,$option,false);
}
$results = [];
foreach (['direct'=>[$direct], 'referred'=>[$referred], 'mixed'=>[$direct, $simple, $variation]] as $key => $products) {
    $order = $createOrder($products, 'mixed' === $key);
    $args = ['orderId'=>$order->get_id()];
    $assert($operation['discoverPermission']() && $operation['permission']($args), 'The order owner can use the MCP quote.');
    $quote = $operation['execute']($args);
    $prices = 'referred' === $key ? $plan['referred'] : $plan['direct'];
    $ordinary = 'mixed' === $key ? 2 : 0;
    $assert($quote['firstChargeMinor'] === $prices['upfrontMinor'] + ($ordinary ? 33800 : 0), 'Woo supplies the first charge after the coupon.');
    $assert($quote['renewalMinor'] === $prices['renewalMinor'] && $quote['intervalMonths'] === 1 && $quote['firstTermMonths'] === 12
        && $quote['recurringLines'] === 1 && $quote['oneTimeLines'] === $ordinary, 'Only the recurring line contributes to renewals.');
    $results[$key] = $quote;
}

wp_set_current_user(0);
$assert(!$operation['discoverPermission']() && !$operation['permission']($args), 'Guests cannot use order quotes.');
$denied = false;
try { $operation['execute']($args); } catch (RuntimeException) { $denied = true; }
$assert($denied, 'Unprivileged calls fail at the native order boundary.');

echo wp_json_encode(['verified'=>true, 'nativeProductEditor'=>true,'nativeVariationEditor'=>true,'mcpProductEdit'=>true,
    'paidExtensionActive'=>class_exists('WC_Subscriptions', false), 'quotes'=>$results]), PHP_EOL;
