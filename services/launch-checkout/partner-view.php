<?php
$balance = $partnerDashboard['balance'];
$withdrawalStatuses = ['requested' => 'Awaiting review', 'approved' => 'Approved', 'paid' => 'Paid', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];
$date = static fn (int $time): string => (new DateTimeImmutable('@' . $time))
    ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y');
?>
<section aria-labelledby="partner-earnings">
  <h2 id="partner-earnings">Partner earnings</h2>
  <p>Earn <?= $money($checkout->plan['monthlyPartnerCommissionMinor']) ?> for each completed paid month after your client’s site is set up.</p>
  <dl class="partner-balances">
    <div><dt>Available to withdraw</dt><dd><?= $money($balance['available']) ?></dd></div>
    <div><dt>Net earnings</dt><dd><?= $money($balance['earned']) ?></dd></div>
    <div><dt>Pending withdrawals</dt><dd><?= $money($balance['pending']) ?></dd></div>
    <div><dt>Paid out</dt><dd><?= $money($balance['paid']) ?></dd></div>
    <?php if ($balance['held'] > 0): ?><div><dt>Held for payment disputes</dt><dd><?= $money($balance['held']) ?></dd></div><?php endif; ?>
    <?php if ($balance['adjustmentDue'] > 0): ?><div><dt>Adjustment from future earnings</dt><dd><?= $money($balance['adjustmentDue']) ?></dd></div><?php endif; ?>
  </dl>
  <form action="/checkout/" method="post" class="billing-actions">
    <?= kiwe_checkout_post_fields('request-withdrawal') ?>
    <input type="hidden" name="amount_minor" value="<?= $balance['available'] ?>">
    <input type="hidden" name="withdrawal" value="<?= $balance['available'] > 0 ? 'wd_' . bin2hex(random_bytes(16)) : '' ?>">
    <button class="button button-lime"<?= $balance['available'] > 0 ? '' : ' disabled' ?>>Withdraw<?= $balance['available'] > 0 ? ' ' . $money($balance['available']) : '' ?></button>
  </form>
  <p>Kiwe reviews each withdrawal before payment. Refunds can adjust your balance.</p>
  <?php if ($partnerDashboard['withdrawals']): ?>
    <div class="checkout-receipt">
      <h3>Recent withdrawals</h3>
      <ul class="partner-withdrawals">
        <?php foreach ($partnerDashboard['withdrawals'] as $entry): ?>
          <li>
            <div><strong><?= $money($entry['amount']) ?></strong> · <?= $withdrawalStatuses[$entry['status']] ?>
              <span><?= $date($entry['created_at']) ?></span>
              <small><?= $e($entry['id']) ?></small></div>
            <?php if ($entry['status'] === 'requested'): ?>
              <form action="/checkout/" method="post" class="billing-actions">
                <?= kiwe_checkout_post_fields('cancel-withdrawal') ?>
                <input type="hidden" name="withdrawal" value="<?= $e($entry['id']) ?>">
                <button class="billing-link" aria-label="Cancel withdrawal <?= $e($entry['id']) ?>">Cancel request</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</section>
