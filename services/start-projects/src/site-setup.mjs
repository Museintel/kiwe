import { z } from 'zod';

export const siteSetups = Object.freeze({
  services: { label: 'Services or portfolio', store: false },
  ecommerce: { label: 'Online store', store: true },
  membership: { label: 'Paid memberships', store: true },
  both: { label: 'Online store and memberships', store: true },
});

export const siteSetupMode = z.enum(Object.keys(siteSetups));
