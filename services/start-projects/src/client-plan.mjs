const firstTermMonths = 12;

function terms(monthlyMinor) {
  return Object.freeze({
    upfrontMinor: firstTermMonths * monthlyMinor,
    renewalMinor: monthlyMinor,
  });
}

export const clientPlan = Object.freeze({
  id: 'kiwe-client-managed-v1',
  name: 'Kiwe Client',
  currency: 'INR',
  taxIncluded: true,
  firstTermMonths,
  renewalIntervalMonths: 1,
  hostingIncluded: true,
  domain: Object.freeze({ includedExtension: 'in', standardOnly: true, bringOwnSupported: true }),
  direct: terms(70000),
  referred: terms(60000),
  monthlyPartnerCommissionMinor: 10000,
});
