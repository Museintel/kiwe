import { Resolver } from 'node:dns/promises';
import { z } from 'zod';
import { parse, siteLinkInput, StartError } from './projects.mjs';

export const domainProofInput = siteLinkInput.extend({ action: z.enum(['prepare', 'verify']) });

const resolver = new Resolver({ timeout: 2500, tries: 2 });

export async function checkDomainProof(projects, accountId, input, resolveTxt = (name) => resolver.resolveTxt(name)) {
  const details = parse(domainProofInput, input);
  const project = projects.ownedDomainProof(accountId, details, details.action === 'prepare');
  if (details.action === 'prepare' || project.domainSetup.status === 'ownership_verified') return project;

  const record = project.domainSetup.record;
  if (!record) throw new StartError(409, 'domain_proof_expired', 'Create a new DNS verification record first.');
  let replies;
  try {
    replies = await resolveTxt(record.name);
  } catch (error) {
    if (!['ENOTFOUND', 'ENODATA'].includes(error.code)) {
      throw new StartError(502, 'domain_dns_unavailable', 'DNS is temporarily unavailable. Check the record again in a moment.');
    }
    replies = [];
  }
  if (!replies.some((chunks) => chunks.join('') === record.value)) {
    throw new StartError(409, 'domain_proof_pending', 'The verification record has yet to appear in DNS. Check its name and value, then try again.');
  }
  return projects.confirmOwnedDomain(accountId, details, record.value);
}
