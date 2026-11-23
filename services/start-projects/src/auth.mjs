import { jwtVerify } from 'jose';
import { accountInput, projectScope } from './projects.mjs';

export function createMcpAuthorization({ issuer, resource, keys }) {
  return async (token) => {
    if (!token) return null;
    try {
      const { payload } = await jwtVerify(token, keys, {
        issuer,
        audience: resource,
        algorithms: ['ES256', 'EdDSA', 'RS256'],
        requiredClaims: ['sub', 'exp'],
      });
      const scopes = typeof payload.scope === 'string' ? payload.scope.split(/\s+/) : [];
      const account = accountInput.safeParse(payload.sub);
      return account.success && scopes.includes(projectScope) ? account.data : null;
    } catch {
      return null;
    }
  };
}
