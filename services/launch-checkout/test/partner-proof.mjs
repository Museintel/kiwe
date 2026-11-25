import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import path from 'node:path';

export async function verifyPartners({ origin, data, partner, anotherPartner, buyer, start, paid, buyerId, partnerId, call }) {
  const tools = async (name, args = {}) => {
    const result = await call('kiwe_launch_' + name, args);
    assert.equal(Boolean(result.isError), false, result.content?.[0]?.text);
    return result.structuredContent;
  };
  const denied = async (name, args, reason) => {
    const result = await call('kiwe_launch_' + name, args);
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, reason);
  };
  const summary = async () => (await tools('partner_summary', { accountId: partnerId })).balance;
  const partnerUrl = origin + '/checkout/?tab=partner';
  const clients = partner.locator('#partner-clients');
  await partner.goto(partnerUrl);
  await clients.getByText('Site setup pending', { exact: true }).waitFor();
  assert.equal(await partner.getByRole('button', { name: 'Withdraw', exact: true }).isDisabled(), true);
  assert.equal((await summary()).available, 0);
  await denied('partner_summary', { accountId: buyerId }, /Join the Partner Program/);

  const endpoint = start.origin + '/projects/api/purchase-delivery';
  const claimant = { entitlementId: paid.purchaseId, accountId: buyerId };
  const lookup = (input, token) => fetch(endpoint, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
    body: JSON.stringify(input),
  });
  assert.equal((await lookup(claimant, 'untrusted')).status, 401);
  assert.equal((await lookup({ ...claimant, accountId: partnerId }, start.purchaseToken)).status, 404);
  assert.deepEqual(await (await lookup(claimant, start.purchaseToken)).json(), { projectId: paid.projectId, readyAt: null });
  assert.deepEqual(await tools('reconcile_partner_deliveries'), { checked: 1, ready: 0, retry: 0 });
  assert.deepEqual(await tools('reconcile_partner_deliveries'), { checked: 0, ready: 0, retry: 0 });

  const project = start.projects.getProject(buyerId, { projectId: paid.projectId });
  if (project.domainSetup?.mode === 'register') {
    const confirmation = await fetch(start.origin + '/projects/api/domain-registration', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + start.purchaseToken },
      body: JSON.stringify({ ...claimant, purchaseId: paid.payment.id, domain: project.domain }),
    });
    assert.equal(confirmation.status, 200, await confirmation.clone().text());
  }

  // Age the payment in the disposable checkout database. Four billing months have elapsed.
  const first = new Date();
  first.setUTCDate(1);
  first.setUTCHours(0, 0, 0, 0);
  first.setUTCMonth(first.getUTCMonth() - 4);
  const end = new Date(first);
  end.setUTCFullYear(end.getUTCFullYear() + 1);
  const readyAt = first.getTime() / 1000 + 40 * 86400;
  start.confirmManagedSite(buyerId, paid.projectId, readyAt);
  const db = new DatabaseSync(path.join(data, 'checkout.sqlite'));
  try {
    assert.equal(db.prepare('UPDATE launch_payments SET confirmed_at = ?, first_term_end = ? WHERE id = ?')
      .run(first.getTime() / 1000, end.getTime() / 1000, paid.purchaseId).changes, 1);
    assert.equal(db.prepare('UPDATE launch_partner_services SET check_after = 0 WHERE purchase_id = ?')
      .run(paid.purchaseId).changes, 1);
  } finally {
    db.close();
  }
  assert.deepEqual(await tools('reconcile_partner_deliveries'), { checked: 1, ready: 1, retry: 0 });
  assert.deepEqual(await (await lookup(claimant, start.purchaseToken)).json(), { projectId: paid.projectId, readyAt });
  const expected = 29166;
  assert.deepEqual(await summary(), { earned: expected, available: expected, pending: 0, paid: 0, held: 0, adjustmentDue: 0 });
  await partner.reload();
  await clients.getByText('Site setup verified', { exact: true }).waitFor();
  await clients.getByText('₹291.66 earned', { exact: true }).waitFor();
  const button = partner.getByRole('button', { name: 'Withdraw ₹291.66', exact: true });
  const proof = process.env.KIWE_LAUNCH_PROOF_DIR;
  for (const width of [390, 1440]) {
    await partner.setViewportSize({ width, height: 900 });
    assert.equal(await partner.evaluate(() => document.documentElement.scrollWidth), width);
    if (proof) await partner.screenshot({ path: path.join(proof, 'partner-earnings-' + width + '.png'), fullPage: true });
  }

  const form = partner.locator('form:has(input[name="action"][value="request-withdrawal"])');
  const withdrawalId = () => form.locator('input[name=withdrawal]').inputValue();
  const firstId = await withdrawalId();
  const csrf = await form.locator('input[name=csrf]').inputValue();
  const submit = (page, fields) => page.request.post(origin + '/checkout/', {
    form: fields, headers: { Origin: origin }, maxRedirects: 0,
  });
  await button.click();
  await partner.getByText('Your withdrawal request is ready for review.', { exact: true }).waitFor();
  for (const id of [firstId, 'wd_' + randomBytes(16).toString('hex')]) {
    assert.equal((await submit(partner, { csrf, action: 'request-withdrawal', withdrawal: id, amount_minor: String(expected) })).status(), 303);
  }
  const pending = (await tools('withdrawals')).withdrawals;
  assert.equal(pending.length, 1, 'Duplicate submissions and another tab reserve the same balance only once');
  assert.equal(pending[0].id, firstId);
  assert.equal(pending[0].partner_id, partnerId);
  assert.deepEqual(await summary(), { earned: expected, available: 0, pending: expected, paid: 0, held: 0, adjustmentDue: 0 });

  await buyer.goto(partnerUrl);
  await buyer.getByRole('button', { name: 'Join the Partner Program' }).waitFor();
  assert.equal(await buyer.locator('#partner-clients').count(), 0);
  await anotherPartner.goto(partnerUrl);
  await anotherPartner.getByRole('button', { name: 'Join the Partner Program' }).click();
  await anotherPartner.getByText('Your partner account is ready', { exact: false }).waitFor();
  assert.equal(await anotherPartner.getByText('referred@example.invalid', { exact: true }).count(), 0);
  const otherCsrf = await anotherPartner.locator('#checkout-link input[name=csrf]').inputValue();
  await submit(anotherPartner, { csrf: otherCsrf, action: 'cancel-withdrawal', withdrawal: firstId });
  await anotherPartner.reload();
  await anotherPartner.getByRole('alert').getByText('This withdrawal is unavailable.', { exact: true }).waitFor();

  const now = Math.floor(Date.now() / 1000);
  const dispute = { id: 'disp_PartnerFixture', currency: 'INR', payment_id: paid.payment.id, amount: 360000, amount_deducted: 0 };
  const disputeEvent = (phase, suffix, at) => paid.event('payment.dispute.' + phase,
    { dispute: { entity: dispute } }, 'partner-dispute-' + suffix, { at });
  assert.equal(await disputeEvent('created', 'initial', now), 200);
  assert.equal((await summary()).held, 15000);
  await denied('review_withdrawal', { withdrawalId: firstId, amountMinor: expected, decision: 'approve' }, /exceeds the earnings/);
  assert.equal(await disputeEvent('won', 'resolved', now + 1), 200);
  assert.equal(await disputeEvent('action_required', 'stale', now), 200);
  assert.equal((await summary()).held, 0);

  await partner.reload();
  await partner.getByRole('button', { name: 'Cancel withdrawal ' + firstId }).click();
  await partner.getByText('Your withdrawal request was cancelled.', { exact: true }).waitFor();
  assert.equal((await summary()).available, expected);
  const nextId = await withdrawalId();
  await button.click();
  await partner.getByText('Your withdrawal request is ready for review.', { exact: true }).waitFor();
  const payout = { withdrawalId: nextId, amountMinor: expected, reference: 'UTR-SYNTHETIC-4001' };
  await denied('record_payout', payout, /Only an approved withdrawal/);
  assert.equal((await tools('review_withdrawal', { withdrawalId: nextId, amountMinor: expected, decision: 'approve' })).withdrawal.status, 'approved');
  await denied('record_payout', { ...payout, amountMinor: expected + 1 }, /differs from the approved/);
  assert.equal((await tools('record_payout', payout)).withdrawal.status, 'paid');
  assert.equal((await tools('record_payout', payout)).withdrawal.status, 'paid');
  const settled = await tools('withdrawals', { state: 'paid' });
  assert.equal(settled.withdrawals[0].payout_reference, payout.reference);
  assert.equal(settled.nextPage, null);
  assert.ok(!JSON.stringify(settled).includes('@example.invalid'));

  const refund = { id: 'rfnd_PartnerAnnual', payment_id: paid.payment.id, amount: 360000, currency: 'INR', status: 'processed' };
  assert.equal(await paid.event('refund.processed', { refund: { entity: refund } }, 'partner-refund'), 200);
  assert.deepEqual(await summary(), { earned: 15000, available: 0, pending: 0, paid: expected, held: 0, adjustmentDue: 14166 });
  await partner.reload();
  await partner.getByText('₹291.66 · Paid', { exact: false }).waitFor();
  await partner.getByText('Adjustment from future earnings', { exact: true }).waitFor();
}
