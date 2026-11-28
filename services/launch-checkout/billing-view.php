<?php
$date = static fn (int $time): string => (new DateTimeImmutable('@' . $time))
    ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y, g:i a') . ' IST';
$mandate = $billingState['mandate'];
$fullRefund = $billingState['refunded'] >= $price['upfrontMinor'];
$statuses = [
    'creating' => 'Checking the approval link',
    'created' => 'Awaiting authorisation',
    'authenticated' => 'Approved for the scheduled start',
    'active' => 'Active',
    'pending' => 'A renewal payment needs attention',
    'halted' => 'Renewal stopped after unsuccessful payment attempts',
    'paused' => 'Paused',
    'cancelled' => 'Cancelled',
    'completed' => 'All scheduled payments completed',
    'expired' => 'Approval link expired',
];
?>
<div class="checkout-receipt">
  <?php if (!$billingState['paid']): ?>
    <h3>First-year payment</h3>
    <?php if ($billingState['linkUrl']): ?>
      <p><a class="button button-lime" href="<?= $e($billingState['linkUrl']) ?>">Open saved payment link</a></p>
    <?php elseif (!$native): ?>
      <p>Check the saved payment link and confirm its status.</p>
      <form method="post" action="<?= $e($route) ?>" class="billing-actions">
        <?= kiwe_checkout_post_fields('pay-first-year') ?>
        <input type="hidden" name="revision" value="<?= $e($draft['revision'] ?? 1) ?>">
        <label><input type="checkbox" name="payment_consent" value="yes" required> I agree to pay <?= $money($price['upfrontMinor']) ?> for the first 12 months.</label>
        <button class="button button-lime">Recover payment link</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <h3><?= $fullRefund ? 'First-year payment refunded' : 'First-year payment confirmed' ?></h3>
    <?php if (!$fullRefund): ?><p>Your first term ends on <?= $date($billingState['firstTermEnd']) ?>.</p><?php endif; ?>
    <?php if ($billingState['paidThrough'] > $billingState['firstTermEnd']): ?>
      <p>Renewal payments cover this site through <?= $date($billingState['paidThrough']) ?>.</p>
    <?php endif; ?>
    <?php if ($billingState['refunded']): ?><p>Refund recorded <?= $money($billingState['refunded']) ?>.</p><?php endif; ?>
    <?php if (!$fullRefund || $billingState['projectId']): ?>
      <form method="post" action="<?= $e($route) ?>" class="billing-actions">
        <?= kiwe_checkout_post_fields('open-start', $purchaseId) ?>
        <button class="button button-lime">Open Kiwe Start</button>
      </form>
    <?php endif; ?>
    <?php if ($mandate): ?>
      <h3>Monthly renewals</h3>
      <p><?= $e($statuses[$mandate['status']] ?? $mandate['status']) ?></p>
      <?php if ($mandate['url'] && !$fullRefund && !$billingState['renewalOnHold']): ?>
        <p><a class="button button-lime" href="<?= $e($mandate['url']) ?>">Finish renewal approval</a></p>
      <?php endif; ?>
      <?php if ($mandate['status'] !== 'creating' && !KiweBilling::terminalMandate($mandate['status'])): ?>
        <form method="post" action="<?= $e($route) ?>" class="billing-actions">
          <?= kiwe_checkout_post_fields('cancel-renewal', $purchaseId) ?>
          <button class="billing-link">Cancel automatic renewal</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!$fullRefund && $billingState['renewalOnHold']): ?>
      <p>Renewal approval is on hold while Launch reviews this payment.</p>
    <?php elseif (!$fullRefund && (!$mandate || KiweBilling::terminalMandate($mandate['status'])) && $billingState['renewalAvailable']): ?>
      <?php if (!$mandate): ?><h3>Monthly renewals</h3><?php endif; ?>
      <form method="post" action="<?= $e($route) ?>" class="billing-actions">
        <?= kiwe_checkout_post_fields('renewal-consent', $purchaseId) ?>
        <input type="hidden" name="renewal_amount" value="<?= $e($price['renewalMinor']) ?>">
        <input type="hidden" name="renewal_start" value="<?= $e($billingState['renewalStart']) ?>">
        <input type="hidden" name="renewal_cycles" value="<?= $e($billing->cycles) ?>">
        <label><input type="checkbox" name="renewal_consent" value="yes" required>
          Approve <?= $money($price['renewalMinor']) ?> each month from <?= $date($billingState['renewalStart']) ?> for up to <?= $billing->cycles ?> payments. You can cancel automatic renewal.</label>
        <p>Razorpay may use a refundable verification charge when you approve.</p>
        <button class="button button-lime">Approve renewal with Razorpay</button>
      </form>
    <?php elseif (!$fullRefund && !$mandate): ?>
      <p>Monthly renewal approval is currently unavailable for this plan.</p>
    <?php endif; ?>
    <?php if ($billingState['renewals']): ?>
      <h3>Recent renewal payments</h3>
      <ul>
      <?php foreach ($billingState['renewals'] as $payment): ?>
        <li><?= $money($payment['amount']) ?> for the period beginning <?= $date($payment['start']) ?><?= $payment['refunded'] ? ' · Refund ' . $money($payment['refunded']) : '' ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="<?= $e($route) ?>" class="billing-actions">
    <?= kiwe_checkout_post_fields('check-billing', $purchaseId) ?>
    <button class="billing-link">Check payment and renewal status</button>
  </form>
</div>
