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

function managedPlan(id, name, sku, direct, referred, commission, modes, capabilities) {
  return Object.freeze({
    ...clientPlan, id, name, sku, direct: terms(direct), referred: terms(referred),
    monthlyPartnerCommissionMinor: commission,
    siteSetupModes: Object.freeze(modes), capabilities: Object.freeze(capabilities),
  });
}

const storeModes = ['ecommerce', 'membership', 'both'];
const publishing = ['publishing'];
const ecommerce = [...publishing, 'commerce'];
const operations = [...ecommerce, 'wholesale'];

export const websitePlans = Object.freeze([
  managedPlan('kiwe-portfolio-v1', 'Kiwe Portfolio', 'KIWE-PORTFOLIO-FIRST-12-MONTHS', 47900, 42900, 2500, ['services'], publishing),
  managedPlan('kiwe-ecommerce-v1', 'Kiwe Ecommerce', 'KIWE-ECOMMERCE-FIRST-12-MONTHS', 79900, 69900, 10000, [...storeModes], ecommerce),
  managedPlan('kiwe-operations-v1', 'Kiwe Operations', 'KIWE-OPERATIONS-FIRST-12-MONTHS', 109900, 99900, 10000, [...storeModes], operations),
  managedPlan('kiwe-premium-v1', 'Kiwe Premium', 'KIWE-PREMIUM-FIRST-12-MONTHS', 149900, 139900, 10000, ['services', ...storeModes], [...operations, 'marketing']),
]);

export const freelancerPass = Object.freeze({
  id: 'kiwe-freelancer-annual-v1', name: 'Kiwe Freelancer Pass', sku: 'KIWE-FREELANCER-ANNUAL',
  currency: 'INR', taxIncluded: true, hostingIncluded: false, priceMinor: 149900, intervalMonths: 12,
});

export const launchCatalogue = Object.freeze({ websitePlans, freelancerPass });
const managedPlansById = new Map([clientPlan, ...websitePlans].map(plan => [plan.id, plan]));

export function websitePlanFor(id) {
  return managedPlansById.get(id);
}
