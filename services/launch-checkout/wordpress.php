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

    private ?array $state = null;
    private ?array $intent = null;

    public function register(): void
    {
        add_action('woocommerce_before_checkout_billing_form', [$this, 'fields']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 20, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'bind'], 10, 3);
        add_action('woocommerce_before_pay_action', [$this, 'requireBuyer']);
        add_action('woocommerce_receipt_razorpay', [$this, 'requireReceiptBuyer'], 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'requireClassicCheckout']);
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
        return $sku === 'KIWE-FREELANCER-ANNUAL' || preg_match('/^KIWE-[A-Z]+-FIRST-12-MONTHS$/D', $sku) === 1;
    }

    private function cartSku(): ?string
    {
        foreach (WC()->cart?->get_cart() ?? [] as $item) {
            $sku = $item['data']->get_sku();
            if ($this->launchSku($sku)) return $sku;
        }
        return null;
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
        if (!$this->cartSku()) return;
        echo '<fieldset class="kiwe-launch-site-details"><legend>Your website domain</legend>';
        echo '<p>Use the email you verified in <a href="' . esc_url(self::ACCOUNT) . '">your Kiwe Launch account</a>.</p>';
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
        $sku = $this->cartSku();
        if (!$sku || $errors->has_errors()) return;
        try {
            $this->validateIntent($sku, $data);
        } catch (KiweCheckoutError $error) {
            $errors->add('kiwe_launch_checkout', $error->getMessage());
        } catch (Throwable) {
            $errors->add('kiwe_launch_checkout', 'Website checkout is temporarily unavailable. Try again shortly.');
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
        $plan = null;
        foreach ($state['nativeWoo']['plans'] as $candidate) {
            if ($candidate['sku'] === $sku) $plan = $candidate;
        }
        if (!$plan || ($data['payment_method'] ?? '') !== 'razorpay' || WC()->cart->get_cart_contents_count() !== 1
            || get_woocommerce_currency() !== 'INR' || wc_get_price_decimals() !== 2
            || wc_add_number_precision((float) WC()->cart->get_total('edit')) !== (float) $plan['direct']['upfrontMinor']) {
            throw new KiweCheckoutError('Buy one website at its published first-year price with Razorpay.');
        }

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
        $this->intent = [
            'accountId' => $account['id'], 'planId' => $plan['id'], 'sku' => $sku, 'domain' => $selection,
            'amountMinor' => $plan['direct']['upfrontMinor'], 'email' => $account['email'],
        ];
    }

    public function bind(int $id, array $data, WC_Order $order): void
    {
        if (!$this->launchOrder($order)) return;
        if (!$this->intent || !$this->matchesOrder($order, $this->intent)) {
            throw new Exception('Recheck your website selection and verified Kiwe Launch account before paying.');
        }
        $payload = json_encode(['orderId' => $id, ...$this->intent], JSON_THROW_ON_ERROR);
        $saved = $order->get_meta(self::INTENT, true, 'edit');
        if ($saved) {
            if (($this->readIntent($order) ?? []) !== json_decode($payload, true, flags: JSON_THROW_ON_ERROR)) {
                WC()->session->set('order_awaiting_payment', 0);
                throw new Exception('Your website details changed. Submit checkout again to create a new order.');
            }
            return;
        }
        if ($this->providerOrderId($order)) throw new Exception('Start a new checkout for this website.');
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
            && $product->get_sku() === $intent['sku'] && $order->get_currency() === 'INR'
            && $order->get_payment_method() === 'razorpay'
            && round((float) $order->get_total() * 100) === (float) $intent['amountMinor']
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
            || (isset($_POST['payment_method']) && $_POST['payment_method'] !== 'razorpay')) {
            throw new Exception('Open this order from the verified Kiwe Launch buyer account.');
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
                'kiwe_launch_classic_checkout', 'Open the Kiwe Launch website checkout to pay for this plan.', 409
            );
        }
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
            throw new RuntimeException('Missing verified paid website order.');
        }
        unset($intent['email'], $intent['amountMinor']);
        $purchase = $state['billing']->importWooPurchase($intent + [
            'providerOrderId' => $this->providerOrderId($order),
            'paymentId' => $order->get_transaction_id(),
            'paidAt' => $order->get_date_paid()->getTimestamp(),
        ]);
        $state['billing']->deliver($intent['accountId'], $purchase);
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
