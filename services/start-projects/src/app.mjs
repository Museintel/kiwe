import { createHash, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  StartError, browserLifetime, claimInput, domainInput, projectScope, projectsOutput, singleProjectOutput, siteLinkInput,
} from './projects.mjs';
import { projectPage, projectsPage, signInPage, siteAuthorizationPage, siteReturnPage, stylesheet } from './web.mjs';
import { createSiteConnections, siteCallbackPath } from './wordpress-link.mjs';
import { createManagedHosting } from './managed-hosting.mjs';

const jsonType = 'application/json';
const formType = 'application/x-www-form-urlencoded';
const mcpPath = '/projects/mcp';
const metadataPath = '/.well-known/oauth-protected-resource' + mcpPath;
const maxBodySize = 16 * 1024;

function send(response, status, body, type = 'text/html; charset=utf-8') {
  response.writeHead(status, { 'Content-Type': type });
  response.end(body);
}

function json(response, status, value) {
  send(response, status, JSON.stringify(value), jsonType);
}

function redirect(response, location) {
  response.writeHead(303, { Location: location });
  response.end();
}

function bearer(request) {
  return request.headers.authorization?.match(/^Bearer ([^\s]+)$/i)?.[1] ?? '';
}

function sameSecret(actual, expected) {
  if (!actual) return false;
  const hash = (value) => createHash('sha256').update(value, 'utf8').digest();
  return timingSafeEqual(hash(actual), hash(expected));
}

async function readBody(request, type) {
  if (request.headers['content-type']?.split(';')[0].trim().toLowerCase() !== type) {
    throw new StartError(415, 'unsupported_content_type', 'The request format is unsupported.');
  }
  const chunks = [];
  let length = 0;
  for await (const chunk of request.iterator({ destroyOnReturn: false })) {
    length += chunk.length;
    if (length > maxBodySize) {
      request.resume();
      throw new StartError(413, 'request_too_large', 'The request is too large.');
    }
    chunks.push(chunk);
  }
  const body = Buffer.concat(chunks).toString('utf8');
  if (type === formType) {
    const form = new URLSearchParams(body);
    const fields = Object.fromEntries(form);
    if (Object.keys(fields).length !== form.size) {
      throw new StartError(400, 'invalid_details', 'Check the submitted details and try again.');
    }
    return fields;
  }
  try {
    return JSON.parse(body);
  } catch {
    throw new StartError(400, 'invalid_json', 'The request is not valid JSON.');
  }
}

function checkOrigin(request, expected) {
  if (request.headers.origin !== expected) {
    throw new StartError(403, 'invalid_origin', 'Open the project from Kiwe Start or Kiwe Launch.');
  }
}

function mcpTools(projects, siteConnections, managedHosting, accountId) {
  const server = new McpServer({ name: 'Kiwe Start', version: '0.1.0' });
  const result = (label, structuredContent) => ({
    content: [{ type: 'text', text: label }],
    structuredContent,
  });
  const run = async (action) => {
    try {
      return await action();
    } catch (error) {
      if (!(error instanceof StartError)) throw error;
      return { isError: true, content: [{ type: 'text', text: error.message }] };
    }
  };
  server.registerTool('kiwe_start_projects', {
    description: 'List projects owned by the signed-in purchaser with their domain and setup step.',
    outputSchema: projectsOutput,
    annotations: { readOnlyHint: true },
  }, () => result('Your Start projects', { projects: projects.listProjects(accountId) }));
  server.registerTool('kiwe_start_project', {
    description: 'Read a purchased project, its current revision and the next setup step.',
    inputSchema: claimInput,
    outputSchema: singleProjectOutput,
    annotations: { readOnlyHint: true },
  }, (input) => run(() => result('Project setup', { project: projects.getProject(accountId, input) })));
  server.registerTool('kiwe_start_set_domain', {
    description: 'Save the domain and hosting choice confirmed by the owner. Use included for eligible Kiwe hosting or existing for an existing WordPress site.',
    inputSchema: domainInput,
    outputSchema: singleProjectOutput,
    annotations: { destructiveHint: false, idempotentHint: true },
  }, (input) => run(() => result('Domain saved', { project: projects.setDomain(accountId, input) })));
  server.registerTool('kiwe_start_connect_site', {
    description: 'Open the native WordPress authorization for the selected existing site. The website owner approves the link. Read the same project after approval.',
    inputSchema: siteLinkInput,
    annotations: { destructiveHint: false, idempotentHint: true },
  }, (input) => run(async () => result('Open this link to approve the website connection', await siteConnections.begin(accountId, input))));
  if (managedHosting.available) {
    server.registerTool('kiwe_start_provision_site', {
      description: 'Set up or check included Kiwe hosting for the selected project. Confirm the hosting choice with the owner before the first request.',
      inputSchema: siteLinkInput,
      outputSchema: singleProjectOutput,
      annotations: { destructiveHint: false, idempotentHint: true },
    }, (input) => run(async () => result('Kiwe hosting status', { project: await managedHosting.step(accountId, input) })));
  }
  return server;
}

export function createStartServer({ projects, startOrigin, checkoutOrigin, issuer, purchaseToken, authorizeMcp, wordpressLink, hostingProvider }) {
  const managedHosting = createManagedHosting(projects, hostingProvider);
  const siteConnections = createSiteConnections(projects, startOrigin, wordpressLink);
  const secure = new URL(startOrigin).protocol === 'https:';
  const cookieName = secure ? '__Host-kiwe-start' : 'kiwe-start-lab';
  const metadataUrl = startOrigin + metadataPath;
  const resource = startOrigin + mcpPath;
  const purchaseWrite = (request) => {
    if (!sameSecret(bearer(request), purchaseToken)) {
      throw new StartError(401, 'purchase_authority_required', 'A trusted Kiwe Launch connection is required.');
    }
  };
  const sessionToken = (request) => request.headers.cookie?.split(/;\s*/).find(
    (part) => part.startsWith(cookieName + '='),
  )?.slice(cookieName.length + 1);
  const setCookie = (response, value, maxAge) => response.setHeader(
    'Set-Cookie',
    `${cookieName}=${value}; Path=/; Max-Age=${maxAge}; HttpOnly; SameSite=Lax${secure ? '; Secure' : ''}`,
  );

  async function route(request, response) {
    const url = new URL(request.url, startOrigin);
    const { pathname } = url;
    const method = request.method;
    const endpoint = method + ' ' + pathname;
    if (endpoint === 'POST /projects/api/purchases') {
      purchaseWrite(request);
      const result = projects.acceptPurchase(await readBody(request, jsonType));
      return json(response, result.created ? 201 : 200, result);
    }
    if (endpoint === 'POST /projects/api/handoffs') {
      purchaseWrite(request);
      const handoff = projects.issueHandoff(await readBody(request, jsonType));
      return json(response, 201, { ...handoff, action: startOrigin + '/projects/open' });
    }
    if (endpoint === 'GET ' + metadataPath) {
      return json(response, 200, {
        resource, authorization_servers: [issuer], scopes_supported: [projectScope],
        bearer_methods_supported: ['header'],
      });
    }
    if (pathname === mcpPath) {
      if (request.headers.origin !== undefined) checkOrigin(request, startOrigin);
      const accountId = await authorizeMcp(bearer(request));
      if (!accountId) {
        response.setHeader('WWW-Authenticate', `Bearer resource_metadata="${metadataUrl}", scope="${projectScope}"`);
        return json(response, 401, { error: 'authorization_required' });
      }
      if (method !== 'POST') {
        response.setHeader('Allow', 'POST');
        return json(response, 405, { error: 'method_not_allowed' });
      }
      const payload = await readBody(request, jsonType);
      const server = mcpTools(projects, siteConnections, managedHosting, accountId);
      const transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: undefined, enableJsonResponse: true,
      });
      response.once('close', () => { void server.close(); });
      await server.connect(transport);
      await transport.handleRequest(request, response, payload);
      return;
    }
    if (endpoint === 'GET ' + siteCallbackPath) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      try {
        const project = await siteConnections.complete(Object.fromEntries(url.searchParams));
        if (projects.accountForSession(sessionToken(request)) === project.accountId) {
          return redirect(response, '/projects/' + project.id);
        }
        return send(response, 200, siteReturnPage('The verified site is saved on your project.', true));
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return send(response, error.status, siteReturnPage(error.message, false));
      }
    }
    if (endpoint === 'GET /projects/style.css') {
      return send(response, 200, stylesheet, 'text/css; charset=utf-8');
    }
    if (endpoint === 'POST /projects/open') {
      checkOrigin(request, checkoutOrigin);
      const session = projects.redeemHandoff(await readBody(request, formType));
      projects.endSession(sessionToken(request));
      setCookie(response, session.token, browserLifetime / 1000);
      return redirect(response, '/projects/' + session.projectId);
    }
    if (endpoint === 'POST /projects/sign-out') {
      checkOrigin(request, startOrigin);
      projects.endSession(sessionToken(request));
      setCookie(response, '', 0);
      return redirect(response, '/projects');
    }
    const match = pathname.match(/^\/projects\/([a-f0-9-]{36})(?:\/(domain|connect|hosting))?$/);
    const listRoute = endpoint === 'GET /projects' || endpoint === 'GET /projects/';
    if (listRoute || match) {
      const accountId = projects.accountForSession(sessionToken(request));
      if (!accountId) return send(response, 401, signInPage(checkoutOrigin));
      if (listRoute) return send(response, 200, projectsPage(projects.listProjects(accountId)));
      const [, projectId, action] = match;
      const project = projects.getProject(accountId, { projectId });
      if (method === 'GET' && !action) return send(response, 200, projectPage(project, { managed: managedHosting.available }));
      if (method === 'POST' && action) {
        checkOrigin(request, startOrigin);
        try {
          const { revision, ...form } = await readBody(request, formType);
          const input = { ...form, projectId, revision: Number(revision) };
          if (action === 'connect') {
            const pending = await siteConnections.begin(accountId, input);
            return send(response, 200, siteAuthorizationPage(project, pending.authorizationUrl));
          }
          if (action === 'hosting') await managedHosting.step(accountId, input);
          else projects.setDomain(accountId, input);
          return redirect(response, '/projects/' + projectId);
        } catch (error) {
          if (!(error instanceof StartError)) throw error;
          const latest = projects.getProject(accountId, { projectId });
          return send(response, error.status, projectPage(latest, { error: error.message, managed: managedHosting.available }));
        }
      }
    }
    return json(response, 404, { error: 'not_found' });
  }

  return createServer({ requestTimeout: 15000, headersTimeout: 10000 }, (request, response) => {
    response.setHeader('Cache-Control', 'no-store');
    response.setHeader('Referrer-Policy', 'same-origin');
    response.setHeader('X-Content-Type-Options', 'nosniff');
    response.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    void route(request, response).catch((error) => {
      if (response.writableEnded) return;
      if (response.headersSent) return response.destroy();
      if (error instanceof StartError) {
        if (request.url === '/projects/open') {
          return send(response, error.status, signInPage(checkoutOrigin, error.message));
        }
        return json(response, error.status, { error: error.code, message: error.message });
      }
      return json(response, 500, { error: 'service_unavailable' });
    });
  });
}
