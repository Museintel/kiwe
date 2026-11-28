<?php

declare(strict_types=1);

/**
 * Installed as a Launch-only MU plugin. The private service remains outside the document root.
 */
if (!defined('ABSPATH') || !defined('KIWE_LAUNCH_NATIVE_CHECKOUT_ROOT')) return;

final class Kiwe_Launch_Woo_Checkout
{
    private const INTENT = '_kiwe_launch_website_intent';
    private const PURCHASE = '_kiwe_launch_website_purchase';
    private const JOB = 'kiwe_launch_reconcile_website_order';
    private const GROUP = 'kiwe-launch';
    private const ACCOUNT = '/launch-account/';
    private const PASS = 'KIWE-FREELANCER-ANNUAL';

    private ?array $state = null;
    private ?array $intent = null;
    private array $passSavings = [];

    public function register(): void
    {
        add_action('woocommerce_before_calculate_totals', [$this, 'applyWebsitePrice'], 40);
        add_filter('woocommerce_get_item_data', [$this, 'showWebsiteSavings'], 10, 2);
        add_action('woocommerce_before_checkout_billing_form', [$this, 'fields']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 20, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'bind'], 10, 3);
        add_action('woocommerce_before_pay_action', [$this, 'requireBuyer']);
        add_action('woocommerce_receipt_razorpay', [$this, 'requireReceiptBuyer'], 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'requireClassicCheckout']);
        add_filter('woocommerce_cancel_unpaid_order', [$this, 'cancelUnpaid'], 10, 2);
        add_action('woocommerce_payment_complete', [$this, 'schedule']);
        add_action(self::JOB, [$this, 'reconcileJob'], 10, 2);
        add_action('woocommerce_thankyou', [$this, 'accountLink']);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('kiwe-launch reconcile-order', function (array $args): void {
                $id = filter_var($args[0] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) WP_CLI::error('Enter the paid WooCommerce order ID.');
                try {
                    WP_CLI::line($this->reconcile($id));
                } catch (Throwable) {
                    WP_CLI::error('The order could not be reconciled. Review the buyer, order and provider records.');
                }
            });
        }
    }

    private function service(): array
    {
        if ($this->state) return $this->state;
        $root = KIWE_LAUNCH_NATIVE_CHECKOUT_ROOT;
        require_once $root . '/boot.php';
        require_once $root . '/web.php';
        $state = kiwe_checkout_boot($root);
        if (!$state || !$state['billing'] || !$state['nativeWoo']) {
            throw new RuntimeException('Kiwe Launch checkout is unavailable.');
        }
        if (untrailingslashit(home_url()) !== $state['origin']) {
            throw new RuntimeException('Kiwe Launch checkout origin does not match WordPress.');
        }
        return $this->state = $state;
    }

    private function buyer(): ?array
    {
        $state = $this->service();
        if (!kiwe_checkout_session($state, true, true)) return null;
        return kiwe_checkout_current_account($state['checkout']);
    }

    private function launchSku(string $sku): bool
    {
        return $sku === self::PASS || preg_match('/^KIWE-[A-Z]+-FIRST-12-MONTHS$/D', $sku) === 1;
    }

    private function cartSku(): ?string
    {
        foreach (WC()->cart?->get_cart() ?? [] as $item) {
            $sku = $item['data']->get_sku();
            if ($this->launchSku($sku)) return $sku;
        }
        return null;
    }

    private function nativePlan(string $sku): ?array
    {
        foreach ($this->service()['nativeWoo']['plans'] as $plan) {
            if ($plan['sku'] === $sku) return $plan;
        }
        return null;
    }

    private function websitePrice(array $plan, ?array $buyer): array
    {
        $pass = $buyer && $plan['hostingIncluded'] ? $this->service()['billing']->freelancerPass($buyer['id']) : null;
        $active = $pass['active'] ?? false;
        return ['minor' => $plan[$active ? 'referred' : 'direct']['upfrontMinor'], 'source' => $active ? 'pass' : 'direct'];
    }

    public function applyWebsitePrice(WC_Cart $cart): void
    {
        $this->passSavings = [];
        if ($cart->get_cart_contents_count() !== 1 || get_woocommerce_currency() !== 'INR' || wc_get_price_decimals() !== 2) return;
        $items = $cart->get_cart();
        $key = array_key_first($items);
        $product = $items[$key]['data'] ?? null;
        $sku = $product?->get_sku() ?? '';
        if (!$this->launchSku($sku) || $sku === self::PASS || !$product->is_virtual()) return;
        try {
            $plan = $this->nativePlan($sku);
            if (!$plan || wc_add_number_precision((float) $product->get_regular_price('edit')) !== (float) $plan['direct']['upfrontMinor']) return;
            $price = $this->websitePrice($plan, $this->buyer());
            $product = clone $product;
            $product->set_price((string) ($price['minor'] / 100));
            $items[$key]['data'] = $product;
            $cart->set_cart_contents($items);
            if ($price['source'] === 'pass') {
                $this->passSavings[$product->get_id()] = $plan['direct']['upfrontMinor'] - $price['minor'];
            }
        } catch (Throwable) {
            // Checkout rechecks the price before it can hand off to Razorpay.
        }
    }

    public function showWebsiteSavings(array $data, array $item): array
    {
        $savings = $this->passSavings[$item['product_id'] ?? 0] ?? null;
        if ($savings) {
            $display = wc_price($savings / 100);
            $data[] = ['key' => 'Freelancer Pass savings', 'value' => wp_strip_all_tags($display), 'display' => $display];
        }
        return $data;
    }

    private function launchOrder(WC_Order $order): bool
    {
        if ($order->get_meta(self::INTENT, true, 'edit')) return true;
        foreach ($order->get_items() as $line) {
            $product = $line->get_product();
            if ($product && $this->launchSku($product->get_sku())) return true;
        }
        return false;
    }

    public function fields(WC_Checkout $checkout): void
    {
        $sku = $this->cartSku();
        if (!$sku) return;
        $accountLink = esc_url(self::ACCOUNT);
        if ($sku === self::PASS) {
            echo '<p>Use the email you verified in <a href="' . $accountLink . '">your Kiwe Launch account</a>. You can approve annual renewal after payment.</p>';
            return;
        }
        echo '<fieldset class="kiwe-launch-site-details"><legend>Your website domain</legend>';
        echo '<p>Use the email you verified in <a href="' . $accountLink . '">your Kiwe Launch account</a>.</p>';
        $total = (int) wc_add_number_precision((float) WC()->cart->get_total('edit'));
        echo '<input type="hidden" name="kiwe_launch_review_minor" value="' . esc_attr((string) $total) . '">';
        woocommerce_form_field('kiwe_launch_domain', [
            'type' => 'text', 'label' => 'Domain name', 'required' => true, 'placeholder' => 'example.in',
            'custom_attributes' => ['maxlength' => 253, 'autocapitalize' => 'none', 'spellcheck' => 'false'],
        ], $checkout->get_value('kiwe_launch_domain'));
        woocommerce_form_field('kiwe_launch_domain_mode', [
            'type' => 'select', 'label' => 'Domain setup', 'required' => true,
            'options' => ['' => 'Choose an option', 'owned' => 'I own this domain', 'register' => 'Register an eligible .in domain'],
        ], $checkout->get_value('kiwe_launch_domain_mode'));
        echo '</fieldset>';
    }

    public function validate(array $data, WP_Error $errors): void
    {
        $this->intent = null;
        $sku = $this->cartSku();
        if (!$sku || $errors->has_errors()) return;
        try {
            $this->validateIntent($sku, $data);
        } catch (KiweCheckoutError $error) {
            $errors->add('kiwe_launch_checkout', $error->getMessage());
        } catch (Throwable) {
            $errors->add('kiwe_launch_checkout', 'Kiwe Launch checkout is temporarily unavailable. Try again shortly.');
        }
    }

    private function validateIntent(string $sku, array $data): void
    {
        $state = $this->service();
        $account = $this->buyer();
        if (!$account) throw new KiweCheckoutError('Sign in to your verified Kiwe Launch account before paying.');
        if ($account['email'] !== kiwe_checkout_email($data['billing_email'] ?? null)) {
            throw new KiweCheckoutError('Use the verified email from your Kiwe Launch account.');
        }
        $plan = $this->nativePlan($sku);
        if (!$plan || ($data['payment_method'] ?? '') !== 'razorpay' || WC()->cart->get_cart_contents_count() !== 1
            || get_woocommerce_currency() !== 'INR' || wc_get_price_decimals() !== 2) {
            throw new KiweCheckoutError('Use Razorpay to buy one Kiwe Launch plan at its published first-year price.');
        }
        $price = $this->websitePrice($plan, $account);
        if (wc_add_number_precision((float) WC()->cart->get_total('edit')) !== (float) $price['minor']
            || (float) WC()->cart->get_discount_total() > 0) {
            throw new KiweCheckoutError('Review the updated Kiwe Launch price and remove any other discounts before paying.');
        }

        $intent = ['accountId' => $account['id'], 'planId' => $plan['id'], 'sku' => $sku,
            'amountMinor' => $price['minor'], 'email' => $account['email']];
        if ($sku === self::PASS) {
            $resumeId = $state['billing']->passOrder($account['id']);
            if ($resumeId !== null) {
                $resume = wc_get_order($resumeId);
                if (!$resume || $resume->get_customer_id() !== get_current_user_id()
                    || !$resume->has_status(['pending', 'failed']) || !$this->matchesOrder($resume, $intent)) {
                    throw new KiweCheckoutError('Review your Freelancer Pass in your Kiwe Launch account.');
                }
                WC()->session->set('order_awaiting_payment', $resumeId);
            }
            $this->intent = $intent;
            return;
        }
        if (filter_var($_POST['kiwe_launch_review_minor'] ?? '', FILTER_VALIDATE_INT) !== $price['minor']) {
            throw new KiweCheckoutError('Your website price changed. Reload checkout and review the total before paying.');
        }
        if ($price['source'] === 'pass') $intent['pricingSource'] = 'pass';
        $domain = kiwe_checkout_domain(wp_unslash($_POST['kiwe_launch_domain'] ?? ''));
        $mode = $_POST['kiwe_launch_domain_mode'] ?? null;
        if (!in_array($mode, ['owned', 'register'], true)) throw new KiweCheckoutError('Choose how to connect your domain.');
        $selection = ['mode' => $mode, 'name' => $domain];
        if ($mode === 'register') {
            $checkout = $state['checkout'];
            $checkout->limit('quote-network', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 36, 3600);
            $checkout->limit('quote-account', $account['id'], 12, 3600);
            if (($checkout->domainAvailability($domain)['status'] ?? '') !== 'eligible') {
                throw new KiweCheckoutError('Choose an available standard .in domain or a domain you already own.');
            }
            $selection['category'] = 'standard';
        }
        $this->intent = $intent + ['domain' => $selection];
    }

    public function bind(int $id, array $data, WC_Order $order): void
    {
        if (!$this->launchOrder($order)) return;
        if (!$this->intent || !$this->matchesOrder($order, $this->intent)) {
            throw new Exception('Recheck your plan and verified Kiwe Launch account before paying.');
        }
        $payload = json_encode(['orderId' => $id, ...$this->intent], JSON_THROW_ON_ERROR);
        $saved = $order->get_meta(self::INTENT, true, 'edit');
        if ($saved && ($this->readIntent($order) ?? []) !== json_decode($payload, true, flags: JSON_THROW_ON_ERROR)) {
            WC()->session->set('order_awaiting_payment', 0);
            throw new Exception('Your checkout details changed. Submit checkout again to create a new order.');
        }
        if (!$saved && $this->providerOrderId($order)) throw new Exception('Start a new checkout for this plan.');
        if ($this->intent['sku'] === self::PASS) {
            $this->service()['billing']->reservePassOrder($this->intent['accountId'], $id);
        }
        if (($this->intent['pricingSource'] ?? '') === 'pass') {
            $this->service()['billing']->approvePassWebsitePrice(
                $this->intent['accountId'], $id, $this->intent['planId'], $this->intent['domain']
            );
        }
        if ($saved) return;
        $order->update_meta_data(self::INTENT, [
            'payload' => $payload,
            'signature' => hash_hmac('sha256', $payload, $this->service()['nativeWoo']['key']),
        ]);
        $order->save_meta_data();
    }

    private function readIntent(WC_Order $order): ?array
    {
        $saved = $order->get_meta(self::INTENT, true, 'edit');
        if (!is_array($saved) || !is_string($saved['payload'] ?? null) || !is_string($saved['signature'] ?? null)
            || !hash_equals(hash_hmac('sha256', $saved['payload'], $this->service()['nativeWoo']['key']), $saved['signature'])) return null;
        $intent = json_decode($saved['payload'], true, flags: JSON_THROW_ON_ERROR);
        return ($intent['orderId'] ?? null) === $order->get_id() ? $intent : null;
    }

    private function matchesOrder(WC_Order $order, array $intent): bool
    {
        $items = array_values($order->get_items());
        $item = $items[0] ?? null;
        $product = $item?->get_product();
        return count($items) === 1 && $item->get_quantity() === 1 && $product && $product->is_virtual()
            && ($intent['sku'] !== self::PASS || !$product->managing_stock())
            && $product->get_sku() === $intent['sku'] && $order->get_currency() === 'INR'
            && $order->get_payment_method() === 'razorpay'
            && round((float) $order->get_total() * 100) === (float) $intent['amountMinor']
            && (float) $order->get_discount_total() === 0.0
            && strtolower($order->get_billing_email()) === $intent['email'];
    }

    private function providerOrderId(WC_Order $order): ?string
    {
        return kiwe_billing_id($order->get_meta('razorpay_order_id' . $order->get_id(), true, 'edit'), 'order');
    }

    public function requireBuyer(WC_Order $order): void
    {
        if (!$this->launchOrder($order)) return;
        $buyer = $this->buyer();
        $intent = $this->readIntent($order);
        if (!$buyer || !$intent || $buyer['id'] !== $intent['accountId'] || !$this->matchesOrder($order, $intent)
            || ($intent['sku'] === self::PASS && $this->service()['billing']->passOrder($buyer['id']) !== $order->get_id())
            || (isset($_POST['payment_method']) && $_POST['payment_method'] !== 'razorpay')) {
            throw new Exception('Open this order from the verified Kiwe Launch buyer account.');
        }
        if (($intent['pricingSource'] ?? '') === 'pass' && !($this->service()['billing']->freelancerPass($buyer['id'])['active'] ?? false)) {
            throw new Exception('Your Freelancer Pass coverage changed. Return to checkout to review the updated price.');
        }
    }

    public function requireReceiptBuyer(int $id): void
    {
        $order = wc_get_order($id);
        try {
            if ($order) $this->requireBuyer($order);
        } catch (Throwable) {
            wp_die('Sign in to the verified Kiwe Launch buyer account and reopen this order.', 'Payment unavailable', ['response' => 403]);
        }
    }

    public function requireClassicCheckout(WC_Order $order): void
    {
        if ($this->launchOrder($order)) {
            throw new Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'kiwe_launch_classic_checkout', 'Open the Kiwe Launch checkout to pay for this plan.', 409
            );
        }
    }

    public function cancelUnpaid(bool $cancel, WC_Order $order): bool
    {
        if (!$cancel || !$order->get_meta(self::INTENT, true, 'edit')) return $cancel;
        try {
            $intent = $this->readIntent($order);
            if (($intent['sku'] ?? '') === self::PASS && $this->matchesOrder($order, $intent)
                && $this->service()['billing']->passOrder($intent['accountId']) === $order->get_id()) return false;
        } catch (Throwable) {
            // Preserve WooCommerce's normal cancellation policy if the billing service is unavailable.
        }
        return $cancel;
    }

    public function schedule(int $id): void
    {
        $order = wc_get_order($id);
        if ($order && $order->get_meta(self::INTENT, true, 'edit') && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::JOB, [$id, 0], self::GROUP, true);
        }
    }

    public function reconcileJob(int $id, int $attempt): void
    {
        try {
            $this->reconcile($id);
        } catch (Throwable) {
            if ($attempt < 5) {
                as_schedule_single_action(time() + 60 * (2 ** $attempt), self::JOB, [$id, $attempt + 1], self::GROUP, true);
            } else {
                throw new RuntimeException('Review the WooCommerce order and run kiwe-launch reconcile-order.');
            }
        }
    }

    public function reconcile(int $id): string
    {
        $state = $this->service();
        $order = wc_get_order($id);
        $intent = $order ? $this->readIntent($order) : null;
        if (!$intent || !$order->is_paid() || !$order->get_date_paid() || !$this->matchesOrder($order, $intent)) {
            throw new RuntimeException('Missing a verified paid Kiwe Launch order.');
        }
        unset($intent['email'], $intent['amountMinor']);
        $purchase = $state['billing']->importWooPurchase($intent + [
            'providerOrderId' => $this->providerOrderId($order),
            'paymentId' => $order->get_transaction_id(),
            'paidAt' => $order->get_date_paid()->getTimestamp(),
        ]);
        if ($intent['sku'] !== self::PASS) $state['billing']->deliver($intent['accountId'], $purchase);
        $order->update_meta_data(self::PURCHASE, $purchase);
        $order->save_meta_data();
        return $purchase;
    }

    public function accountLink(int $id): void
    {
        $order = wc_get_order($id);
        if ($order && $order->get_meta(self::INTENT, true, 'edit')) {
            echo '<p><a href="' . esc_url(self::ACCOUNT) . '">Open your Kiwe Launch account</a></p>';
        }
    }
}

(new Kiwe_Launch_Woo_Checkout())->register();
