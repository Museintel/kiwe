<?php

declare(strict_types=1);

function kiwe_checkout_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kiwe_checkout_post_fields(string $action): string
{
    return '<input type="hidden" name="action" value="' . kiwe_checkout_escape($action) . '">'
        . '<input type="hidden" name="csrf" value="' . kiwe_checkout_escape($_SESSION['csrf']) . '">';
}

function kiwe_checkout_establish_session(array $account): void
{
    $tab = $_SESSION['tab'] ?? '';
    session_regenerate_id(true);
    $_SESSION = [
        'account' => $account['id'], 'version' => $account['version'],
        'csrf' => bin2hex(random_bytes(32)), 'tab' => $tab,
    ];
}

function kiwe_checkout_current_account(KiweCheckout $checkout): ?array
{
    $id = $_SESSION['account'] ?? null;
    $version = $_SESSION['version'] ?? null;
    $account = is_string($id) && is_int($version) ? $checkout->account($id, $version) : null;
    if (!$account && $id) {
        unset($_SESSION['account'], $_SESSION['version']);
    }
    return $account;
}

function kiwe_checkout_action(KiweCheckout $checkout, ?KiweBilling $billing, ?array $account, string $action): string|array
{
    $referral = $_SESSION['referral'] ?? null;
    $address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    switch ($action) {
        case 'signup':
        case 'reset':
            if ($account) throw new KiweCheckoutError('Sign out before using that form.', 409);
            $checkout->emailLink($action, kiwe_checkout_email($_POST['email'] ?? ''), $referral, $address);
            return 'Check your email for the next step. Open the link within 30 minutes.';
        case 'verification-link':
            $digest = kiwe_checkout_token($_POST['code'] ?? '');
            if (!$checkout->linkInfo($digest)) throw new KiweCheckoutError('This link or code is invalid or expired.', 400);
            if ($account) throw new KiweCheckoutError('Sign out before using an email verification link.', 409);
            $_SESSION['verification'] = $digest;
            return 'Email verified. Set your password to continue.';
        case 'referral-link':
            $digest = kiwe_checkout_token($_POST['code'] ?? '');
            if ($account) {
                if (!$checkout->acceptReferral($account, $digest)) {
                    throw new KiweCheckoutError('This referral is unavailable for your email. You can still use checkout.', 409);
                }
                return 'Your partner price is ready.';
            }
            $_SESSION['referral'] = $digest;
            return 'Use the email you shared with your partner. We’ll apply your referral after verification.';
        case 'password':
            if ($account || empty($_SESSION['verification'])) {
                throw new KiweCheckoutError('Open a valid email link to set your password.', 409);
            }
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || $password !== ($_POST['repeat_password'] ?? null)) {
                throw new KiweCheckoutError('Enter the same password in both fields.');
            }
            kiwe_checkout_establish_session($checkout->setPassword($_SESSION['verification'], $password, $referral));
            return 'Your verified Kiwe Launch account is ready.';
        case 'sign-in':
            if ($account) return 'You’re already signed in.';
            $password = $_POST['password'] ?? '';
            if (!is_string($password)) throw new KiweCheckoutError('Check your email and password.');
            kiwe_checkout_establish_session($checkout->signIn(kiwe_checkout_email($_POST['email'] ?? ''), $password, $referral, $address));
            return 'You’re signed in.';
        case 'sign-out':
            session_regenerate_id(true);
            $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
            return 'You’re signed out.';
    }
    if (!$account) throw new KiweCheckoutError('Sign in to continue.', 401);
    switch ($action) {
        case 'join-partner':
            $_SESSION['tab'] = 'partner';
            $checkout->enrolPartner($account['email']);
            return 'Your partner account is ready. Create a link for your first client.';
        case 'save-draft':
            if ($billing?->status($account['id'])) {
                throw new KiweCheckoutError('Review the saved payment before changing these checkout details.', 409);
            }
            $_SESSION['tab'] = '';
            $values = array_intersect_key($_POST, array_flip([
                'domain', 'mode', 'owns_domain', 'name', 'phone', 'whatsapp', 'billing_address', 'city', 'region', 'postcode', 'country',
            ]));
            $_SESSION['draft_input'] = array_map(
                static fn ($value): string => is_string($value) && strlen($value) <= 720 ? $value : '',
                $values,
            );
            $checkout->saveDraft($account, $_POST, $address);
            unset($_SESSION['draft_input']);
            return 'Your checkout details and price are saved.';
        case 'make-referral':
            $_SESSION['tab'] = 'partner';
            $_SESSION['share'] = $checkout->newReferral($account, $_POST);
            return 'Copy this private referral link to share with your client.';
        case 'revoke-referral':
            $_SESSION['tab'] = 'partner';
            $checkout->revokeReferral($account, kiwe_checkout_text($_POST['id'] ?? '', 'referral', 64));
            return 'The unused referral link has been revoked.';
    }
    if ($billing) {
        switch ($action) {
            case 'pay-first-year':
                $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($revision === false || $revision === null || ($_POST['payment_consent'] ?? '') !== 'yes') {
                    throw new KiweCheckoutError('Review and confirm the first-year amount.');
                }
                return ['redirect' => $billing->begin($account, $revision, $address)];
            case 'check-billing':
                $checkout->limit('billing-status-network', $address, 40, 3600);
                $checkout->limit('billing-status', $account['id'], 15, 900);
                $status = $billing->refresh($account['id']);
                if (!$status) return 'This payment link has ended. Refresh your checkout details for a new link.';
                if (!$status['paid']) return 'The first-year payment is awaiting confirmation.';
                $billing->deliver($account['id']);
                return 'Billing status updated.';
            case 'renewal-consent':
                if (($_POST['renewal_consent'] ?? '') !== 'yes') throw new KiweCheckoutError('Confirm the monthly renewal terms.');
                $amount = filter_var($_POST['renewal_amount'] ?? '', FILTER_VALIDATE_INT);
                $starts = filter_var($_POST['renewal_start'] ?? '', FILTER_VALIDATE_INT);
                $cycles = filter_var($_POST['renewal_cycles'] ?? '', FILTER_VALIDATE_INT);
                return ['redirect' => $billing->renew($account['id'], $address, $amount ?: 0, $starts ?: 0, $cycles ?: 0)];
            case 'cancel-renewal':
                $billing->cancelRenewal($account['id']);
                return 'Automatic renewal is cancelled. Your paid term is recorded below.';
            case 'open-start':
                $checkout->limit('start-handoff', $account['id'], 20, 900);
                return ['handoff' => $billing->handoff($account['id'])];
            case 'request-withdrawal':
                $_SESSION['tab'] = 'partner';
                $checkout->limit('partner-withdrawal', $account['id'], 20, 3600);
                $id = kiwe_checkout_text($_POST['withdrawal'] ?? '', 'withdrawal', 40);
                $amount = filter_var($_POST['amount_minor'] ?? '', FILTER_VALIDATE_INT);
                $withdrawal = $billing->partners()->requestWithdrawal($account['id'], $id, $amount ?: 0);
                return $withdrawal['status'] === 'requested' ? 'Your withdrawal request is ready for review.' : 'This withdrawal is already recorded.';
            case 'cancel-withdrawal':
                $_SESSION['tab'] = 'partner';
                $billing->partners()->cancelWithdrawal($account['id'], kiwe_checkout_text($_POST['withdrawal'] ?? '', 'withdrawal', 40));
                return 'Your withdrawal request was cancelled.';
        }
    }
    throw new KiweCheckoutError('This checkout action is unavailable.', 400);
}

function kiwe_checkout_serve(array $state, string $root): void
{
    $checkout = $state['checkout'];
    $billing = $state['billing'];
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        return;
    }
    if (!$state['test'] && $method !== 'POST' && ($_SERVER['HTTP_HOST'] ?? '') === 'www.kiwelaunch.com') {
        header('Location: https://kiwelaunch.com/checkout/', true, 308);
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_cache_limiter('');
    session_save_path($state['sessions']);
    session_name('kiwe_launch_checkout');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/checkout/', 'secure' => !$state['test'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $account = kiwe_checkout_current_account($checkout);
    if ($method === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)
            || ($origin !== '' && $origin !== $state['origin'])
            || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            http_response_code(403);
            echo 'Open the Kiwe Launch checkout again and retry.';
            return;
        }
        try {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 18000) throw new KiweCheckoutError('Your request is too large.', 413);
            $message = kiwe_checkout_action($checkout, $billing, $account, kiwe_checkout_text($_POST['action'] ?? '', 'action', 48));
            if (is_array($message)) {
                if (isset($message['redirect'])) {
                    header('Location: ' . $message['redirect'], true, 303);
                } else {
                    kiwe_checkout_start_handoff($message['handoff'], $billing->startOrigin);
                }
                return;
            }
            $_SESSION['flash'] = ['message' => $message, 'error' => false];
        } catch (KiweCheckoutError $error) {
            $_SESSION['flash'] = ['message' => $error->getMessage(), 'error' => true];
        } catch (Throwable) {
            error_log('Kiwe Launch checkout action failed.');
            $_SESSION['flash'] = ['message' => 'Kiwe could not save this step. Please try again.', 'error' => true];
        }
        header('Location: /checkout/', true, 303);
        return;
    }
    if (isset($_GET['tab']) && in_array($_GET['tab'], ['partner', 'client'], true)) $_SESSION['tab'] = $_GET['tab'];
    $flash = $_SESSION['flash'] ?? null;
    $share = $_SESSION['share'] ?? null;
    $formInput = $_SESSION['draft_input'] ?? [];
    unset($_SESSION['flash'], $_SESSION['share'], $_SESSION['draft_input']);
    $link = $account ? null : $checkout->linkInfo($_SESSION['verification'] ?? null);
    $partnerTab = ($_SESSION['tab'] ?? '') === 'partner';
    $referral = $account && !$partnerTab ? $checkout->referralFor($account['id']) : null;
    $draft = $account && !$partnerTab ? $checkout->draft($account['id']) : null;
    $billingState = $account && !$partnerTab ? $billing?->status($account['id']) : null;
    $partnerReferrals = $partnerTab && ($account['partner'] ?? false) ? $checkout->partnerReferrals($account) : [];
    $clientPage = filter_var($_GET['clients_page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 20) - 1],
    ]) ?: 1;
    $partnerDashboard = $partnerTab && ($account['partner'] ?? false) ? $billing?->partners()->dashboard($account['id'], $clientPage) : null;
    $formInput += ($draft['profile'] ?? []) + [
        'domain' => $draft['quote']['domain']['name'] ?? $referral['domain'] ?? '',
        'mode' => $draft['quote']['domain']['mode'] ?? 'register',
        'phone' => $referral['whatsapp'] ?? '',
        'whatsapp' => $referral['whatsapp'] ?? '',
        'owns_domain' => ($draft['quote']['domain']['mode'] ?? '') === 'owned' ? 'yes' : '',
        'country' => 'India',
    ];
    $price = $billingState['quote'] ?? $checkout->plan[$referral ? 'referred' : 'direct'];
    $quoteActive = $draft && $draft['quote']['expiresAt'] > time() && $draft['quote']['referralId'] === ($referral['id'] ?? null);
    $value = static fn (string $name): string => kiwe_checkout_escape($formInput[$name] ?? '');
    require $root . '/view.php';
}

function kiwe_checkout_start_handoff(array $handoff, string $startOrigin): void
{
    header('Referrer-Policy: origin');
    header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; form-action {$startOrigin}; frame-ancestors 'none'");
    $action = kiwe_checkout_escape($handoff['action']);
    $ticket = kiwe_checkout_escape($handoff['ticket']);
    echo <<<HTML
<!doctype html><html lang="en-IN">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Open Kiwe Start</title><link rel="stylesheet" href="/assets/launch/site.css?v=2">
<script src="/checkout/checkout.js" defer></script>
<main class="section wrap"><h1>Open Kiwe Start</h1>
<form id="start-handoff" method="post" action="$action">
<input name="ticket" type="hidden" value="$ticket"><button class="button">Continue to Kiwe Start</button></form>
</main></html>
HTML;
}
