<?php
$e = 'kiwe_checkout_escape';
$money = static fn (int $minor): string => '₹' . number_format($minor / 100, $minor % 100 ? 2 : 0);
$mode = $formInput['mode'] ?? 'register';
$passPrice = ($price['pricingSource'] ?? '') === 'pass';
$partnerPrice = $billingState ? $billingState['quote']['referralId'] : $referral;
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/launch/site.css?v=2">
  <link rel="stylesheet" href="/assets/launch/checkout.css">
  <script src="/assets/launch/checkout.js" defer></script>
  <title>Kiwe Launch checkout</title>
</head>
<body>
  <a class="skip" href="#main">Skip to main content</a>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="/" aria-label="Kiwe Launch home"><span class="brand-mark" aria-hidden="true"></span>kiwe<span class="brand-sub">launch</span></a>
      <a class="login" href="/#plan">Kiwe Client plan</a>
      <?php if ($account): ?>
        <form action="<?= $e($route) ?>" method="post" class="checkout-signout"><?= kiwe_checkout_post_fields('sign-out') ?><button class="button button-small button-outline">Sign out</button></form>
      <?php else: ?>
        <a class="button button-small button-outline checkout-signout" href="#sign-in">Sign in</a>
      <?php endif; ?>
    </div>
  </header>
  <main class="wrap checkout" id="main">
    <div class="checkout-heading">
      <p class="eyebrow">Kiwe Launch</p>
      <h1><?= $partnerTab ? 'Partner area' : ($native ? 'Your Kiwe Launch account' : 'Set up your Appsite.') ?></h1>
      <p><?= $partnerTab ? 'Refer businesses to Kiwe and track your earnings.' : ($native ? 'Verify your email to buy a website plan and manage billing.' : 'Verify your account, choose a domain and save your setup.') ?></p>
    </div>
    <?php if ($flash): ?>
      <p class="form-status checkout-notice<?= $flash['error'] ? ' error' : '' ?>" role="<?= $flash['error'] ? 'alert' : 'status' ?>"><?= $e($flash['message']) ?></p>
    <?php endif; ?>
    <form action="<?= $e($route) ?>" method="post" id="checkout-link" hidden>
      <?= kiwe_checkout_post_fields('verification-link') ?>
      <input type="hidden" name="code">
    </form>
    <div class="checkout-grid">
      <section class="checkout-card">
        <?php if ($account && $partnerTab): ?>
          <div class="checkout-card-head"><h2>Refer a client</h2><a class="text-link" href="<?= $e($route) ?>?tab=client">Client setup</a></div>
          <?php if ($account['partner']): ?>
            <?php if ($share): ?>
              <div class="share-card">
                <label for="private-share">Private referral link</label>
                <input id="private-share" value="<?= $e($share) ?>" readonly>
                <button type="button" class="button button-small button-outline" data-copy-referral>Copy link</button>
                <p>Copy the link before leaving this page. It’s valid for 30 days.</p>
              </div>
            <?php endif; ?>
            <?php if ($canIssuePartnerReferrals): ?>
            <form action="<?= $e($route) ?>" method="post" class="checkout-form">
              <?= kiwe_checkout_post_fields('make-referral') ?>
              <label>Client email<input name="email" type="email" required maxlength="254"></label>
              <div class="field-pair">
                <label>Client WhatsApp number<input name="whatsapp" type="tel" required maxlength="25"></label>
                <label>Preferred domain<input name="domain" required maxlength="254" placeholder="example.in" autocapitalize="none"></label>
              </div>
              <label class="checkout-check"><input name="consent" value="yes" type="checkbox" required><span>My client agreed that I can share these details for the referral.</span></label>
              <button class="button">Create referral link</button>
            </form>
            <?php else: ?>
              <p>Activate your Freelancer Pass to create a new referral.</p>
            <?php endif; ?>
            <?php if ($partnerReferrals): ?>
              <h3 class="checkout-subhead">Your referral links</h3>
              <ul class="checkout-referrals">
                <?php foreach ($partnerReferrals as $entry):
                    $status = $entry['revoked_at'] ? 'Revoked' : ($entry['claimed'] ? 'Email verified' : ($entry['expires_at'] < time() ? 'Expired' : 'Awaiting client'));
                ?>
                <li><div><strong><?= $e($entry['domain']) ?></strong><span><?= $e($entry['client_email']) ?></span><small><?= $status ?></small></div>
                  <?php if ($status === 'Awaiting client'): ?>
                    <form action="<?= $e($route) ?>" method="post">
                      <?= kiwe_checkout_post_fields('revoke-referral') ?><input type="hidden" name="id" value="<?= $e($entry['id']) ?>">
                      <button class="button button-small button-outline" aria-label="Revoke referral for <?= $e($entry['domain']) ?>">Revoke link</button>
                    </form>
                  <?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($partnerDashboard): ?>
              <div class="partner-clients" id="partner-clients">
                <h3 class="checkout-subhead">Your enrolled clients</h3>
                <?php if (!$partnerDashboard['clients']): ?>
                  <p><?= $clientPage === 1 ? 'Clients appear here after their first payment is confirmed.' : 'There are no more clients on this page.' ?></p>
                <?php else: ?>
                  <ul class="checkout-referrals">
                    <?php foreach ($partnerDashboard['clients'] as $client): ?>
                      <li><div><strong><?= $e($client['domain']) ?></strong><span><?= $e($client['client_email']) ?></span>
                        <small><?= $client['refunded'] >= $client['amount'] ? 'First-year payment refunded' : ($client['ready_at'] ? 'Site setup verified' : 'Site setup pending') ?></small></div>
                        <strong><?= $money($client['earned']) ?> earned</strong></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                <?php if ($clientPage > 1 || $partnerDashboard['more']): ?>
                  <nav class="partner-pages" aria-label="Enrolled clients">
                    <?php if ($clientPage > 1): ?><a class="text-link" href="<?= $e($route) ?>?tab=partner&amp;clients_page=<?= $clientPage - 1 ?>#partner-clients">Previous clients</a><?php endif; ?>
                    <?php if ($partnerDashboard['more']): ?><a class="text-link" href="<?= $e($route) ?>?tab=partner&amp;clients_page=<?= $clientPage + 1 ?>#partner-clients">Next clients</a><?php endif; ?>
                  </nav>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <p>Join with your verified email to create referral links for your clients.</p>
            <form action="<?= $e($route) ?>" method="post" class="checkout-form">
              <?= kiwe_checkout_post_fields('join-partner') ?>
              <button class="button">Join the Partner Program</button>
            </form>
          <?php endif; ?>
        <?php elseif ($account): ?>
          <div class="checkout-card-head"><h2>Your setup</h2>
            <?php if (!$native || $nativePartner): ?><a class="text-link" href="<?= $e($route) ?>?tab=partner"><?= $account['partner'] ? 'Partner area' : 'Become a partner' ?></a><?php endif; ?>
          </div>
          <p>Verified email <strong><?= $e($account['email']) ?></strong></p>
          <?php if ($pass): ?>
            <p><a href="<?= $e($route) ?>?purchase=<?= $e($pass['purchaseId']) ?>"<?= $passSelected ? ' aria-current="page"' : '' ?>>Freelancer Pass</a>
              · <?= $pass['active'] ? 'Active' : 'Inactive' ?></p>
          <?php elseif ($passPending): ?>
            <p>Freelancer Pass payment is awaiting confirmation.</p>
          <?php endif; ?>
          <?php if ($websitePurchases): ?>
            <h3>Your website purchases</h3>
            <ul>
              <?php foreach ($websitePurchases as $website): ?>
                <li><a href="<?= $e($route) ?>?purchase=<?= $e($website['id']) ?>"<?= $purchaseId === $website['id'] ? ' aria-current="page"' : '' ?>><?= $e($website['domain']) ?></a></li>
              <?php endforeach; ?>
            </ul>
            <?php if ($purchaseId): ?><p><a href="<?= $e($route) ?>?tab=client"><?= $native ? 'Back to your account' : 'Back to checkout' ?></a></p><?php endif; ?>
          <?php endif; ?>
          <?php if ($referral && !$purchaseId && !$native): ?><p class="checkout-note">Your partner price is applied.</p><?php endif; ?>
          <?php if ($nativeReferral): ?>
            <p class="checkout-note">Referral linked to <strong><?= $e($nativeReferral['domain']) ?></strong>.</p>
            <p><a class="text-link" href="/#plan">Compare website plans</a></p>
          <?php endif; ?>
          <?php if ($passSelected): ?>
            <?php if ($pass['active']): ?>
              <p>Freelancer Pass access through <?= $e((new DateTimeImmutable('@' . $pass['paidThrough']))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y')) ?>.</p>
            <?php endif; ?>
            <p><a href="<?= $e($route) ?>">Back to your account</a></p>
          <?php elseif ($billingState): ?>
            <p class="checkout-note">Domain on this payment <strong><?= $e($billingState['quote']['domain']['name']) ?></strong></p>
          <?php elseif ($native): ?>
            <p><a class="button" href="/checkout/">Return to checkout</a></p>
          <?php else: ?>
          <form action="<?= $e($route) ?>" method="post" class="checkout-form">
            <?= kiwe_checkout_post_fields('save-draft') ?>
            <input type="hidden" name="revision" value="<?= $e($draft['revision'] ?? 0) ?>">
            <label>Domain name<input name="domain" value="<?= $value('domain') ?>" required maxlength="254" placeholder="example.in" autocapitalize="none" spellcheck="false"></label>
            <fieldset>
              <legend>How you’ll use the domain</legend>
              <label class="checkout-check"><input type="radio" name="mode" value="register"<?= $mode !== 'owned' ? ' checked' : '' ?>><span>Check an eligible standard .in domain for me</span></label>
              <label class="checkout-check"><input type="radio" name="mode" value="owned"<?= $mode === 'owned' ? ' checked' : '' ?>><span>Connect a domain I already own</span></label>
              <label class="checkout-check" id="ownership"><input type="checkbox" name="owns_domain" value="yes"<?= $value('owns_domain') === 'yes' ? ' checked' : '' ?>><span>I own the domain entered above</span></label>
            </fieldset>
            <label>Full name<input name="name" required maxlength="100" autocomplete="name" value="<?= $value('name') ?>"></label>
            <div class="field-pair">
              <label>Phone number<input name="phone" type="tel" required maxlength="25" autocomplete="tel" value="<?= $value('phone') ?>"></label>
              <label>WhatsApp number <span class="optional">(optional)</span><input name="whatsapp" type="tel" maxlength="25" value="<?= $value('whatsapp') ?>"></label>
            </div>
            <fieldset>
              <legend>Billing address</legend>
              <label>Street address<input name="billing_address" required maxlength="180" autocomplete="billing address-line1" value="<?= $value('billing_address') ?>"></label>
              <div class="field-pair">
                <label>City<input name="city" required maxlength="100" autocomplete="billing address-level2" value="<?= $value('city') ?>"></label>
                <label>State or region<input name="region" required maxlength="100" autocomplete="billing address-level1" value="<?= $value('region') ?>"></label>
                <label>Postal code<input name="postcode" required maxlength="16" autocomplete="billing postal-code" value="<?= $value('postcode') ?>"></label>
                <label>Country<input name="country" required maxlength="80" autocomplete="billing country-name" value="<?= $value('country') ?>"></label>
              </div>
            </fieldset>
            <button class="button">Check domain and save details</button>
          </form>
          <?php endif; ?>
        <?php elseif ($link): ?>
          <h2><?= $link['purpose'] === 'reset' ? 'Choose a new password' : 'Create your password' ?></h2>
          <p>Verified email <strong><?= $e($link['email']) ?></strong></p>
          <form class="checkout-form" action="<?= $e($route) ?>" method="post">
            <?= kiwe_checkout_post_fields('password') ?>
            <input type="email" name="username" value="<?= $e($link['email']) ?>" autocomplete="username" hidden>
            <label>Password<input name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="128"></label>
            <label>Re-enter password<input name="repeat_password" type="password" autocomplete="new-password" required minlength="12" maxlength="128"></label>
            <button class="button">Save password and continue</button>
          </form>
        <?php else: ?>
          <h2>Create your account</h2>
          <p>We’ll send a secure link so you can verify your email and create a password.</p>
          <form class="checkout-form" action="<?= $e($route) ?>" method="post">
            <?= kiwe_checkout_post_fields('signup') ?>
            <label id="signup-label">Your email<input name="email" type="email" autocomplete="email" maxlength="254" required></label>
            <button class="button">Send my verification link</button>
          </form>
          <details class="checkout-details" id="sign-in">
            <summary>Sign in to an existing account</summary>
            <form class="checkout-form" action="<?= $e($route) ?>" method="post">
              <?= kiwe_checkout_post_fields('sign-in') ?>
              <label>Your email<input name="email" type="email" autocomplete="username" required maxlength="254"></label>
              <label>Password<input name="password" type="password" autocomplete="current-password" required maxlength="128"></label>
              <button class="button">Sign in</button>
            </form>
          </details>
          <details class="checkout-details" id="recover">
            <summary>Reset your password</summary>
            <form class="checkout-form" action="<?= $e($route) ?>" method="post">
              <?= kiwe_checkout_post_fields('reset') ?>
              <label>Your email<input name="email" type="email" autocomplete="email" required maxlength="254"></label>
              <button class="button">Send my reset link</button>
            </form>
          </details>
          <details class="checkout-details">
            <summary>Enter a code from your email</summary>
            <form class="checkout-form" action="<?= $e($route) ?>" method="post">
              <?= kiwe_checkout_post_fields('verification-link') ?>
              <label>Email code<input name="code" autocomplete="off" required maxlength="72" spellcheck="false" autocapitalize="none"></label>
              <button class="button button-outline">Verify code</button>
            </form>
          </details>
        <?php endif; ?>
      </section>
      <aside class="checkout-summary">
        <?php if ($passSelected): ?>
          <h2>Freelancer Pass</h2>
          <p><?= $money($price['renewalMinor']) ?> per year.</p>
          <?php require $root . '/billing-view.php'; ?>
        <?php elseif ($nativeReferral && !$billingState): ?>
          <h2>Eligible first-year pricing</h2>
          <ul>
            <?php foreach ($state['nativeWoo']['plans'] as $referralPlan): ?>
              <?php if ($referralPlan['hostingIncluded']): ?>
                <li><?= $e($referralPlan['name']) ?> <strong><?= $money($referralPlan['referred']['upfrontMinor']) ?></strong></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($native && !$billingState && !$partnerTab): ?>
          <h2>Website purchases</h2>
          <p>After payment is verified, select the website in your account to open Kiwe Start or manage its renewal.</p>
        <?php elseif ($partnerTab): ?>
          <?php if ($partnerDashboard): ?>
            <?php require $root . '/partner-view.php'; ?>
          <?php else: ?>
            <p class="eyebrow">Kiwe Partner Program</p>
            <h2>Give your client the partner price.</h2>
            <p><strong><?= $money($checkout->plan['referred']['upfrontMinor']) ?></strong> for their first 12 months, then <?= $money($checkout->plan['referred']['renewalMinor']) ?>/month with a verified referral.</p>
            <p>Your client owns their account and Appsite. You can see the status of the links you create.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="eyebrow"><?= $passPrice ? 'Freelancer Pass price' : ($partnerPrice ? 'Verified partner price' : 'Kiwe Client') ?></p>
          <h2><?= $money($price['upfrontMinor']) ?></h2>
          <?php if ($passPrice): ?>
            <p>For your first 12 months with the Freelancer Pass. Taxes included.</p>
          <?php elseif ($billingState && $native && $partnerPrice): ?>
            <p>For your first 12 months at the verified referral price. Taxes included.</p>
          <?php elseif ($billingState && $billingState['renewalPassPrice']): ?>
            <p>For your first 12 months. Taxes included.</p>
          <?php else: ?>
            <p>For your first 12 months, then <strong><?= $money($price['renewalMinor']) ?>/month</strong>. Taxes included.</p>
          <?php endif; ?>
          <p>Managed hosting and renewals for one eligible standard .in domain while your plan stays active.</p>
          <?php if ($billingState): ?>
            <?php require $root . '/billing-view.php'; ?>
          <?php elseif ($draft): ?>
            <div class="checkout-receipt">
              <h3><?= $quoteActive ? 'Saved checkout' : 'Refresh your checkout' ?></h3>
              <p><strong><?= $e($draft['quote']['domain']['name']) ?></strong></p>
              <?php if (!$quoteActive): ?>
                <p>Save your details again to refresh the price and domain selection.</p>
              <?php elseif ($draft['quote']['domain']['mode'] === 'owned'): ?>
                <p>You’ll connect your existing domain after hosting is set up.</p>
              <?php else: ?>
                <p>Hostinger confirmed availability when you saved. Registration is subject to a fresh check.</p>
                <?php if ($draft['quote']['registryIdentityRequired']): ?><p>The registry requires identity verification at registration.</p><?php endif; ?>
              <?php endif; ?>
              <?php if ($quoteActive && $billing): ?>
                <form method="post" action="<?= $e($route) ?>" class="billing-actions">
                  <?= kiwe_checkout_post_fields('pay-first-year') ?>
                  <input type="hidden" name="revision" value="<?= $e($draft['revision']) ?>">
                  <label><input type="checkbox" name="payment_consent" value="yes" required> I agree to pay <?= $money($price['upfrontMinor']) ?> for the first 12 months.</label>
                  <button class="button button-lime">Pay first year with Razorpay</button>
                </form>
              <?php elseif (!$billing): ?>
                <p>Payment opens after the checkout pilot.</p>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </aside>
    </div>
  </main>
  <footer class="site-footer"><div class="wrap footer-inner"><a href="/">Kiwe Launch</a><a href="/privacy/">Privacy</a></div></footer>
</body>
</html>
