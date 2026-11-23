import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { parse, siteLinkInput, StartError } from './projects.mjs';
import { requestSite, siteUrl } from './site-network.mjs';

export const siteCallbackPath = '/projects/site-return';
const ownershipScope = 'mcp.site.connect';
const protocolVersion = '2026-07-28';
const opaque = () => randomBytes(32).toString('base64url');
const pkce = (value) => createHash('sha256').update(value).digest('base64url');

function unavailable() {
  return new StartError(502, 'site_verification_unavailable', 'The website could not be verified. Check that Kiwe MCP is available on the selected domain.');
}

function confirmationRequired() {
  return new StartError(403, 'site_approval_required', 'Use the WordPress account authorized to connect this website and try again.');
}

function envelope(response, id) {
  if (!response.type?.includes('text/event-stream')) return JSON.parse(response.body);
  for (const message of response.body.split(/\r?\n\r?\n/)) {
    const lines = message.split(/\r?\n/).filter((line) => line.startsWith('data:'));
    if (!lines.length) continue;
    const packet = JSON.parse(lines.map((line) => line.slice(5).trimStart()).join('\n'));
    if (packet.id === id) return packet;
  }
  return null;
}

function form(fields) {
  return {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(fields).toString(),
  };
}

export function createWordPressLink(send = requestSite) {
  const json = async (origin, target, options) => JSON.parse((await send(origin, target, options)).body);
  return {
    async begin(domain, redirect, state, verifier) {
      const origin = 'https://' + domain;
      try {
        const [server, resource] = await Promise.all([
          json(origin, origin + '/.well-known/oauth-authorization-server'),
          json(origin, origin + '/.well-known/oauth-protected-resource'),
        ]);
        const issuer = siteUrl(origin, server.issuer);
        const mcp = siteUrl(origin, resource.resource);
        if (issuer.pathname !== '/' || issuer.search || !resource.authorization_servers?.includes(server.issuer)
            || !server.scopes_supported?.includes(ownershipScope) || !resource.scopes_supported?.includes(ownershipScope)) {
          throw new StartError(409, 'site_link_unavailable', 'Enable the Kiwe MCP App version that supports site linking on this domain.');
        }
        const registration = siteUrl(origin, server.registration_endpoint);
        const authorize = siteUrl(origin, server.authorization_endpoint);
        const token = siteUrl(origin, server.token_endpoint);
        const revoke = siteUrl(origin, server.revocation_endpoint);
        const client = await json(origin, registration, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            client_name: 'Kiwe Start website connection',
            redirect_uris: [redirect],
            response_types: ['code'],
            grant_types: ['authorization_code'],
            token_endpoint_auth_method: 'none',
          }),
        });
        if (!/^cl_[a-f0-9]{32}$/.test(client.client_id ?? '')) throw unavailable();
        const fields = {
          client_id: client.client_id,
          redirect_uri: redirect,
          response_type: 'code',
          code_challenge: pkce(verifier),
          code_challenge_method: 'S256',
          scope: 'mcp.read ' + ownershipScope,
          state,
          resource: mcp.href,
        };
        for (const [name, value] of Object.entries(fields)) authorize.searchParams.set(name, value);
        return {
          issuer: server.issuer, origin, redirect, resource: mcp.href,
          clientId: client.client_id, tokenEndpoint: token.href, revokeEndpoint: revoke.href,
          authorizationUrl: authorize.href, verifier,
        };
      } catch (error) {
        if (error instanceof StartError) throw error;
        throw unavailable();
      }
    },

    async verify(connection, code) {
      const { origin, redirect, resource, clientId, tokenEndpoint, revokeEndpoint, verifier, issuer } = connection;
      let token;
      try {
        const grant = await json(origin, tokenEndpoint, form({
          client_id: clientId,
          grant_type: 'authorization_code',
          code,
          code_verifier: verifier,
          redirect_uri: redirect,
          resource,
        }));
        if (!/^kiwe_mcp_access_g_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/.test(grant.access_token ?? '')) {
          throw confirmationRequired();
        }
        token = grant.access_token;
        if (!String(grant.scope ?? '').split(/\s+/).includes(ownershipScope)) throw confirmationRequired();
        const id = randomUUID();
        const name = 'kiwe-mcp-read';
        const response = await send(origin, resource, {
          method: 'POST',
          headers: {
            Accept: 'application/json, text/event-stream',
            Authorization: 'Bearer ' + token,
            'Content-Type': 'application/json',
            'MCP-Protocol-Version': protocolVersion,
            'Mcp-Method': 'tools/call',
            'Mcp-Name': name,
          },
          body: JSON.stringify({
            jsonrpc: '2.0', id, method: 'tools/call',
            params: {
              name,
              arguments: { operation: 'mcp.site.connect.read', arguments: {} },
              _meta: {
                'io.modelcontextprotocol/protocolVersion': protocolVersion,
                'io.modelcontextprotocol/clientCapabilities': {},
                'io.modelcontextprotocol/clientInfo': { name: 'Kiwe Start', version: '1.0.0' },
              },
            },
          }),
        });
        const packet = envelope(response, id);
        if (packet?.id !== id || packet.error || packet.result?.isError || !packet.result) {
          throw confirmationRequired();
        }
        const value = packet.result.structuredContent ?? JSON.parse(packet.result.content?.[0]?.text ?? 'null');
        const version = /^[0-9][0-9A-Za-z.+-]{0,59}$/;
        if (!value || value.website !== issuer || !version.test(value.coreVersion ?? '')
            || !version.test(value.wordpressVersion ?? '')) throw confirmationRequired();
        return { url: origin, coreVersion: value.coreVersion, wordpressVersion: value.wordpressVersion };
      } catch (error) {
        if (error instanceof StartError) throw error;
        throw confirmationRequired();
      } finally {
        if (token) {
          // This grant is short-lived and never written to the Start database.
          try {
            await send(origin, revokeEndpoint, form({ client_id: clientId, token, token_type_hint: 'access_token' }));
          } catch {}
        }
      }
    },
  };
}

export function createSiteConnections(projects, startOrigin, wordpress = createWordPressLink()) {
  const inFlight = new Map();
  const redirect = startOrigin + siteCallbackPath;
  return {
    async begin(accountId, input) {
      const details = parse(siteLinkInput, input);
      const project = projects.siteLinkTarget(accountId, details);
      const existing = projects.pendingSiteLink(project.id, project.revision);
      if (existing) return { authorizationUrl: existing.authorizationUrl };

      const key = project.id + ':' + project.revision;
      if (inFlight.has(key)) return inFlight.get(key);
      const prepare = (async () => {
        const state = opaque();
        const connection = await wordpress.begin(project.domain, redirect, state, opaque());
        const current = projects.saveSiteLink(accountId, details, state, connection);
        return { authorizationUrl: current.authorizationUrl };
      })();
      inFlight.set(key, prepare);
      try {
        return await prepare;
      } finally {
        inFlight.delete(key);
      }
    },

    async complete(parameters) {
      const { state, iss, code, error } = parameters;
      if (!/^[A-Za-z0-9_-]{43}$/.test(state ?? '') || typeof iss !== 'string' || iss.length > 2048) {
        throw new StartError(410, 'site_link_expired', 'Open a new site authorization link in Kiwe Start.');
      }
      const flow = projects.takeSiteLink(state, iss);
      if (error || !/^kiwe_mcp_code_c_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/.test(code ?? '')) {
        throw confirmationRequired();
      }
      const proof = await wordpress.verify(flow.connection, code);
      return projects.confirmSiteLink(flow, proof);
    },
  };
}
