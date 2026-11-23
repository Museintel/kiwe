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
  const revoke = async (connection, token) => {
    try {
      await send(connection.origin, connection.revokeEndpoint, form({
        client_id: connection.clientId, token, token_type_hint: 'access_token',
      }));
    } catch {}
  };

  async function call(connection, token, name, args) {
    const id = randomUUID();
    const response = await send(connection.origin, connection.resource, {
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
          arguments: args,
          _meta: {
            'io.modelcontextprotocol/protocolVersion': protocolVersion,
            'io.modelcontextprotocol/clientCapabilities': {},
            'io.modelcontextprotocol/clientInfo': { name: 'Kiwe Start', version: '1.0.0' },
          },
        },
      }),
    });
    const packet = envelope(response, id);
    if (packet?.id !== id || packet.error || packet.result?.isError || !packet.result) throw confirmationRequired();
    return packet.result.structuredContent ?? JSON.parse(packet.result.content?.[0]?.text ?? 'null');
  }

  async function exchange(connection, code, scope) {
    const { origin, redirect, resource, clientId, tokenEndpoint, verifier } = connection;
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
    const granted = String(grant.scope ?? '').split(/\s+/);
    if (!granted.includes(scope) || granted.some(item => !['mcp.read', scope].includes(item)) || grant.refresh_token) {
      await revoke(connection, grant.access_token);
      throw confirmationRequired();
    }
    return { token: grant.access_token, expiresIn: grant.expires_in };
  }

  return {
    async begin(domain, redirect, state, verifier, scope = ownershipScope, name = 'Kiwe Start website connection') {
      const origin = 'https://' + domain;
      try {
        const [server, resource] = await Promise.all([
          json(origin, origin + '/.well-known/oauth-authorization-server'),
          json(origin, origin + '/.well-known/oauth-protected-resource'),
        ]);
        const issuer = siteUrl(origin, server.issuer);
        const mcp = siteUrl(origin, resource.resource);
        if (issuer.pathname !== '/' || issuer.search || !resource.authorization_servers?.includes(server.issuer)
            || !server.scopes_supported?.includes(scope) || !resource.scopes_supported?.includes(scope)) {
          if (scope === 'mcp.bricks.import') {
            throw new StartError(409, 'bricks_import_unavailable', 'Enable Kiwe MCP with native Bricks draft import on this website.');
          }
          if (scope !== ownershipScope) {
            throw new StartError(409, 'context_unavailable', 'Enable the Kiwe MCP App version with Start Design Context on this website.');
          }
          throw new StartError(409, 'site_link_unavailable', 'Enable the Kiwe MCP App version that supports site linking on this domain.');
        }
        const registration = siteUrl(origin, server.registration_endpoint);
        const authorize = siteUrl(origin, server.authorization_endpoint);
        const token = siteUrl(origin, server.token_endpoint);
        const revocation = siteUrl(origin, server.revocation_endpoint);
        const client = await json(origin, registration, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            client_name: name,
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
          scope: 'mcp.read ' + scope,
          state,
          resource: mcp.href,
        };
        for (const [key, value] of Object.entries(fields)) authorize.searchParams.set(key, value);
        return {
          issuer: server.issuer, origin, redirect, resource: mcp.href,
          clientId: client.client_id, tokenEndpoint: token.href, revokeEndpoint: revocation.href,
          authorizationUrl: authorize.href, verifier,
        };
      } catch (error) {
        if (error instanceof StartError) throw error;
        throw unavailable();
      }
    },

    async verify(connection, code) {
      let grant;
      try {
        grant = await exchange(connection, code, ownershipScope);
        const value = await call(connection, grant.token, 'kiwe-mcp-read', {
          operation: 'mcp.site.connect.read', arguments: {},
        });
        const version = /^[0-9][0-9A-Za-z.+-]{0,59}$/;
        if (!value || value.website !== connection.issuer || !version.test(value.coreVersion ?? '')
            || !version.test(value.wordpressVersion ?? '')) throw confirmationRequired();
        return { url: connection.origin, coreVersion: value.coreVersion, wordpressVersion: value.wordpressVersion };
      } catch (error) {
        if (error instanceof StartError) throw error;
        throw confirmationRequired();
      } finally {
        if (grant) await revoke(connection, grant.token);
      }
    },

    exchange,
    call,
    revoke,
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
