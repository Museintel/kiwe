import { StartError } from './projects.mjs';
import { requestSite } from './site-network.mjs';

export function createAgencySpaces(projects, read = requestSite) {
  return {
    async verify(accountId, input) {
      const attempt = projects.agencyAttempt(accountId, input);
      try {
        const proof = await read(attempt.website, attempt.proofUrl, { headers: { Accept: 'text/plain' } });
        if (!/^text\/plain(?:;|$)/i.test(proof.type) || proof.body.trim() !== attempt.proofText) {
          throw new Error('The published proof differs.');
        }
      } catch {
        throw new StartError(409, 'agency_proof_unavailable', 'Publish the exact proof text at the address shown, then try again.');
      }
      return projects.confirmAgencyHome(accountId, input);
    },
  };
}
