import { createHash, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { z } from 'zod';
import { clientPlan } from './client-plan.mjs';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  agencyHomeInput, agencySiteInput, agencySpaceOutput, agencyVerificationInput, clientInvitationInput,
  StartError, browserLifetime, claimInput, domainInput, parse, pilotBrowserLifetime, projectScope, projectsOutput, singleProjectOutput, siteLinkInput, ticketInput,
} from './projects.mjs';
import { browserLoginInput, browserLoginLifetime, browserLoginPath, browserLoginReturn } from './browser-login.mjs';
import { agencyPage, homepagePage, websitePage, clientProjectPage, pilotMcpPage, projectPage, projectsPage, signInPage, siteAuthorizationPage, siteReturnPage, stylesheet } from './web.mjs';
import { createSiteConnections, siteCallbackPath } from './wordpress-link.mjs';
import { createManagedHosting } from './managed-hosting.mjs';
import { createKeyConnections } from './key-connection.mjs';
import { createAgencySpaces } from './agency-space.mjs';
import {
  createHomepageWorkflow, homeRequestInput, homeRevisionInput, homeSubmitInput, homepageReturn,
  documentRequestInput, documentRevisionInput, documentSubmitInput,
} from './homepage.mjs';
import { previewSource, seamSource } from './homepage-source.mjs';
import { homepageArchive } from './homepage-archive.mjs';
import { bricksInput, bricksReturn, createBricksImport } from './bricks-import.mjs';
import { createHomepagePublication, publicationInput, publicationReturn } from './homepage-publication.mjs';
import { createWebsiteInventory, inventoryReturn, websiteChecklist } from './website-inventory.mjs';
import { documentId, documentPath, websiteDocuments } from './document-targets.mjs';

const jsonType = 'application/json';
const formType = 'application/x-www-form-urlencoded';
const mcpPath = '/projects/mcp';
const metadataPath = '/.well-known/oauth-protected-resource' + mcpPath;
const maxBodySize = 16 * 1024;
const browserPolicy = "default-src 'none'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'";

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

function mcpTools(projects, siteConnections, managedHosting, keyConnections, agencySpaces, homepage, bricks, publication, website, accountId, pilot) {
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
  server.registerTool('kiwe_start_client_plan', {
    description: 'Read the Kiwe Client price, billing schedule, eligible domain extension and referral terms.',
    annotations: { readOnlyHint: true },
  }, () => result('Kiwe Client plan', clientPlan));
  server.registerTool('kiwe_start_projects', {
    description: 'List projects owned by or assigned to the signed-in account with their domain and setup step.',
    outputSchema: projectsOutput,
    annotations: { readOnlyHint: true },
  }, () => result('Your Start projects', { projects: projects.listProjects(accountId) }));
  server.registerTool('kiwe_start_project', {
    description: 'Read an accessible project, its next setup step and the native Design Context link for a verified WordPress site.',
    inputSchema: claimInput,
    outputSchema: singleProjectOutput,
    annotations: { readOnlyHint: true },
  }, (input) => run(() => result('Project setup', { project: projects.readProject(accountId, input) })));
  if (!pilot && projects.canManageClientAccess(accountId)) {
    server.registerTool('kiwe_start_invite_client', {
      description: 'Create a private invitation for a client with a verified email on the selected agency website.',
      inputSchema: clientInvitationInput,
      annotations: { destructiveHint: false, idempotentHint: false },
    }, (input) => run(() => result('Private client invitation', projects.inviteClient(accountId, input))));
    server.registerTool('kiwe_start_revoke_client', {
      description: 'Revoke the client’s Start access and any pending invitation for the selected agency website.',
      inputSchema: claimInput,
      annotations: { destructiveHint: true, idempotentHint: true },
    }, (input) => run(() => result('Client access revoked', projects.revokeClient(accountId, input))));
  }
  server.registerTool('kiwe_start_set_domain', {
    description: 'Save the domain and hosting choice confirmed by the owner. Use included for eligible Kiwe hosting or existing for an existing WordPress site.',
    inputSchema: domainInput,
    outputSchema: singleProjectOutput,
    annotations: { destructiveHint: false, idempotentHint: true },
  }, (input) => run(() => result('Domain saved', { project: projects.setDomain(accountId, input) })));
  server.registerTool('kiwe_start_connect_site', {
    description: 'Open native authorization for the selected existing or ready managed WordPress site with Kiwe MCP installed. The website owner approves the link.',
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
  if (keyConnections.available) {
    server.registerTool('kiwe_start_connect_key', {
      description: 'Resume Key setup for a verified WordPress site. The approved Key account owns the sender. Open the returned Key link for native approval or pairing.',
      inputSchema: siteLinkInput,
      outputSchema: singleProjectOutput.extend({ approvalUrl: z.url() }),
      annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Key connection status', await keyConnections.resume(accountId, input))));
  }
  server.registerTool('kiwe_start_agency_workspace', {
    description: 'Read the purchaser’s agency workspace and any pending proof for its own home website.',
    outputSchema: agencySpaceOutput,
    annotations: { readOnlyHint: true },
  }, () => run(() => result('Agency workspace', projects.getAgencySpace(accountId))));
  if (projects.agencySites(accountId)) {
    server.registerTool('kiwe_start_add_agency_site', {
      description: 'Add an agency site project for a confirmed existing WordPress domain. Read the site allowance with kiwe_start_agency_workspace. WordPress ownership still needs approval.',
      inputSchema: agencySiteInput,
      outputSchema: singleProjectOutput,
      annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: false },
    }, (input) => run(() => result('Agency site project', { project: projects.addAgencySite(accountId, input) })));
  }
  server.registerTool('kiwe_start_set_agency_home', {
    description: 'Use the agency owner’s confirmed business name and own website domain. Return the proof file if website ownership needs verification.',
    inputSchema: agencyHomeInput,
    outputSchema: agencySpaceOutput,
    annotations: { destructiveHint: false, idempotentHint: true },
  }, (input) => run(() => result('Agency website setup', projects.prepareAgencyHome(accountId, input))));
  server.registerTool('kiwe_start_verify_agency_home', {
    description: 'Verify the approved agency home after the exact proof file is published. Check the latest workspace for its attempt ID.',
    inputSchema: agencyVerificationInput,
    outputSchema: agencySpaceOutput,
    annotations: { destructiveHint: false, openWorldHint: true },
  }, (input) => run(async () => result('Agency website verified', await agencySpaces.verify(accountId, input))));
  server.registerTool('kiwe_start_homepage', {
    description: 'Read this one homepage request, the scoped public Design Context, and its current owner review link.',
    inputSchema: claimInput,
    annotations: { readOnlyHint: true },
  }, (input) => run(() => result('Homepage draft', homepage.status(accountId, input))));
  server.registerTool('kiwe_start_open_homepage', {
    description: 'Authorize a fresh read of the site’s native homepage facts and start one homepage draft.',
    inputSchema: homeRequestInput,
    annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
  }, (input) => run(async () => result('Authorize the homepage brief in WordPress', {
    authorizationUrl: await homepage.begin(accountId, input),
  })));
  server.registerTool('kiwe_start_submit_homepage_design', {
    description: 'Choose the layout, type, palette and proven hero image for the latest homepage request. Use its actual business facts as data and return the owner review link.',
    inputSchema: homeSubmitInput,
    annotations: { destructiveHint: false, idempotentHint: true },
  }, (input) => run(() => result('Homepage preview is ready for owner review', homepage.submit(accountId, input))));
  server.registerTool('kiwe_start_revise_homepage', {
    description: 'Start one further homepage revision using the exact feedback requested by the owner. Use the fresh WordPress authorization tool to update its business facts.',
    inputSchema: homeRevisionInput,
    annotations: { destructiveHint: false },
  }, (input) => run(async () => result('Homepage revision opened', await homepage.revise(accountId, input))));
  server.registerTool('kiwe_start_document', {
    description: 'Read one page or template from the website checklist, with its scoped public brief, approved homepage design direction and review link.',
    inputSchema: claimInput.extend({ target: documentId }),
    annotations: { readOnlyHint: true },
  }, (input) => run(() => result('Document draft', homepage.status(accountId, input))));
  server.registerTool('kiwe_start_open_document', {
    description: 'Open the owner’s WordPress consent to generate one page or template listed in Pages and Templates.',
    inputSchema: documentRequestInput,
    annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
  }, (input) => run(async () => result('Authorize the document brief in WordPress', {
    authorizationUrl: await homepage.begin(accountId, input),
  })));
  server.registerTool('kiwe_start_submit_document_design', {
    description: 'Submit a layout, short heading and supported optional sections for the selected non-homepage request. Open the human’s Start review link.',
    inputSchema: documentSubmitInput,
    annotations: { destructiveHint: false, idempotentHint: true },
  }, (input) => run(() => result('Document preview is ready for owner review', homepage.submit(accountId, input))));
  server.registerTool('kiwe_start_revise_document', {
    description: 'Revise one requested document using the human’s feedback. Set regenerate to start with a new layout from the same approved homepage direction.',
    inputSchema: documentRevisionInput,
    annotations: { destructiveHint: false },
  }, (input) => run(async () => result('Document revision opened', await homepage.revise(accountId, input))));
  server.registerTool('kiwe_start_website', {
    description: 'Read the website’s Pages and Templates workspace, including each document’s review, conversion and publication status.',
    inputSchema: claimInput,
    annotations: { readOnlyHint: true },
  }, (input) => run(() => result('Pages and Templates', website.state(accountId, input))));
  server.registerTool('kiwe_start_refresh_website', {
    description: 'Open native WordPress approval to refresh the current site’s published pages and Bricks template inventory.',
    inputSchema: siteLinkInput,
    annotations: { destructiveHint: false, idempotentHint: true },
  }, (input) => run(async () => result('Approve website read in WordPress', {
    authorizationUrl: await website.begin(accountId, input),
  })));
  if (bricks.available) {
    server.registerTool('kiwe_start_convert_homepage', {
      description: 'Start conversion of the current, owner-approved homepage to a private native Bricks draft. Return the WordPress authorization or saved review.',
      inputSchema: bricksInput,
      annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Bricks draft conversion', await bricks.begin(accountId, input))));
    server.registerTool('kiwe_start_convert_document', {
      description: 'Convert the exact approved document source to one private Bricks draft. Return the WordPress authorization or saved review.',
      inputSchema: bricksInput.extend({ target: documentId }),
      annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Bricks draft conversion', await bricks.begin(accountId, input))));
    server.registerTool('kiwe_start_resume_bricks', {
      description: 'Check and continue the same page or template import after the native WordPress approval. Read its exact draft link when ready.',
      inputSchema: bricksInput,
      annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Bricks draft status', { conversion: await bricks.resume(accountId, input) })));
    server.registerTool('kiwe_start_prepare_homepage_publication', {
      description: 'Open WordPress owner authorization to publish the exact approved Bricks draft on the assigned homepage or restore the saved previous layout.',
      inputSchema: publicationInput,
      annotations: { destructiveHint: true, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Homepage publication review', await publication.begin(accountId, input))));
    server.registerTool('kiwe_start_prepare_document_publication', {
      description: 'Request the owner’s WordPress review to publish this exact document on its native route or restore its previous layout.',
      inputSchema: publicationInput.extend({ target: documentId }),
      annotations: { destructiveHint: true, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Document publication review', await publication.begin(accountId, input))));
    server.registerTool('kiwe_start_complete_document_publication', {
      description: 'Check the selected document’s native publication or recovery review. Apply it only after the WordPress owner approves.',
      inputSchema: publicationInput.extend({ target: documentId }),
      annotations: { destructiveHint: true, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Document publication', { publication: await publication.resume(accountId, input) })));
    server.registerTool('kiwe_start_complete_homepage_publication', {
      description: 'Check the exact native publication or recovery review. Apply it only after the owner has approved it in WordPress.',
      inputSchema: publicationInput,
      annotations: { destructiveHint: true, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Homepage publication', { publication: await publication.resume(accountId, input) })));
  }
  return server;
}

export function createStartServer({ projects, startOrigin, checkoutOrigin, issuer, purchaseToken, authorizeMcp, browserLogin, publicGuide, wordpressLink, hostingProvider, keyProvider, agencyReader, workflowClock, homepageWordPress, homepageProvider, inventoryWordPress, bricksWordPress, bricksCompiler, publicationWordPress }) {
  const pilotAccount = browserLogin?.pilotAccount;
  const pilot = Boolean(pilotAccount);
  const keyConnections = createKeyConnections(projects, keyProvider);
  const agencySpaces = createAgencySpaces(projects, agencyReader);
  const homepage = createHomepageWorkflow(projects, startOrigin, homepageWordPress, homepageProvider, workflowClock);
  const bricks = createBricksImport(projects, homepage, startOrigin, bricksCompiler, bricksWordPress, workflowClock);
  const publication = createHomepagePublication(projects, startOrigin, publicationWordPress, workflowClock);
  const inventory = createWebsiteInventory(projects, startOrigin, inventoryWordPress, workflowClock);
  const website = {
    ...inventory,
    state(accountId, input) {
      const native = inventory.status(accountId, input);
      const project = projects.getProject(accountId, input);
      const home = project.key?.siteAuthenticated && project.key.state !== 'inactive' ? {
        ...homepage.status(accountId, input), homeApproved: Boolean(projects.getHomepageDirection(accountId, project.id)),
      } : null;
      const conversion = home?.draft?.status === 'approved' ? bricks.status(accountId, project.id, home.draft.id) : null;
      const published = conversion?.status === 'draft_ready' ? publication.status(accountId, project.id, home.draft.id) : null;
      const documents = new Map();
      if (native.inventory?.bricks && home) {
        const ids = websiteDocuments.filter(item => item.id !== 'home' && (item.type !== 'WooCommerce' || native.inventory.commerce))
          .map(item => item.id).concat(native.inventory.otherPages.map(item => 'page-' + item.id));
        for (const id of ids) {
          const draft = projects.getHomepage(accountId, project.id, undefined, id);
          if (!draft) continue;
          const compiled = draft.approved_at !== null ? bricks.status(accountId, project.id, draft.id, id) : null;
          const publicationState = compiled?.status === 'draft_ready' ? publication.status(accountId, project.id, draft.id, id) : null;
          documents.set(id, {
            draft: { id: draft.id, status: draft.approved_at !== null ? 'approved' : draft.source ? 'review' : 'waiting_for_design' },
            conversion: compiled, publication: publicationState,
          });
        }
      }
      const items = websiteChecklist(project, native.inventory, home, conversion, bricks.available, published, documents);
      return { ...native, items, homepage: home?.draft ?? null, conversion, publication: published };
    },
  };
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
  const loginCookie = secure ? '__Host-kiwe-start-login' : 'kiwe-start-login-lab';
  const requestCookie = (request, name) => request.headers.cookie?.split(/;\s*/).find(
    (part) => part.startsWith(name + '='),
  )?.slice(name.length + 1);
  const sessionToken = (request) => requestCookie(request, cookieName);
  const accountForBrowser = (request) => {
    const accountId = projects.accountForSession(sessionToken(request));
    return pilot && accountId !== pilotAccount ? null : accountId;
  };
  const setCookie = (response, name, value, maxAge) => response.appendHeader(
    'Set-Cookie',
    `${name}=${value}; Path=/; Max-Age=${maxAge}; HttpOnly; SameSite=Lax${secure ? '; Secure' : ''}`,
  );
  function sendSignIn(response, status, message) {
    if (browserLogin) response.setHeader('Content-Security-Policy', browserPolicy + "; form-action 'self' " + browserLogin.authorizationOrigin);
    return send(response, status, signInPage(checkoutOrigin, message, Boolean(browserLogin), pilot));
  }

  async function route(request, response) {
    const url = new URL(request.url, startOrigin);
    const { pathname } = url;
    const method = request.method;
    const endpoint = method + ' ' + pathname;
    if (endpoint === 'GET /projects/api/client-plan') return json(response, 200, clientPlan);
    if (method === 'GET' && [browserLoginPath, '/login', '/login/index.html'].includes(pathname)) {
      if (accountForBrowser(request)) return redirect(response, '/projects');
      return sendSignIn(response, browserLogin ? 200 : 503);
    }
    if (endpoint === 'POST /projects/sign-in' && browserLogin) {
      checkOrigin(request, startOrigin);
      try {
        const { inviteCode } = parse(browserLoginInput, await readBody(request, formType));
        const attempt = await browserLogin.begin(inviteCode);
        projects.saveBrowserLogin(attempt, browserLoginLifetime * 1000);
        setCookie(response, loginCookie, attempt.binding, browserLoginLifetime);
        return redirect(response, attempt.authorization.href);
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return sendSignIn(response, error.status, error.message);
      }
    }
    if (endpoint === 'GET ' + browserLoginReturn && browserLogin) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      try {
        const states = url.searchParams.getAll('state');
        const [state] = states;
        const binding = requestCookie(request, loginCookie);
        if (states.length !== 1 || !ticketInput.shape.ticket.safeParse(state).success
            || !ticketInput.shape.ticket.safeParse(binding).success) {
          throw new StartError(410, 'login_expired', 'Start a new sign-in on this device.');
        }
        const pending = projects.takeBrowserLogin(binding, state);
        setCookie(response, loginCookie, '', 0);
        const account = await browserLogin.complete(url.search, state, pending);
        if (pilot) {
          if (account.accountId !== pilotAccount) throw new StartError(403, 'pilot_owner_required', 'Use the invited Key account.');
          projects.acceptPurchase({
            entitlementId: 'kiwe-agency-pilot', purchaseId: 'kiwe-agency-pilot', accountId: pilotAccount,
            accountType: 'agency', planId: 'kiwe-agency-monthly', hostingIncluded: false,
          }, { preserveLegacy: true });
        }
        const lifetime = pilot ? pilotBrowserLifetime : browserLifetime;
        const session = projects.signIn(account, pending.invitationDigest, sessionToken(request), lifetime);
        setCookie(response, cookieName, session.token, lifetime / 1000);
        return redirect(response, session.projectId ? '/projects/' + session.projectId : '/projects');
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return sendSignIn(response, error.status, error.message);
      }
    }
    if (pilot && pathname === '/projects/mcp-access' && (method === 'GET' || method === 'POST')) {
      const session = sessionToken(request);
      if (projects.accountForSession(session) !== pilotAccount) return sendSignIn(response, 401);
      if (method === 'POST') {
        checkOrigin(request, startOrigin);
        parse(z.strictObject({}), await readBody(request, formType));
        response.setHeader('Referrer-Policy', 'no-referrer');
        return send(response, 201, pilotMcpPage(projects.issuePilotMcp(session, pilotAccount)));
      }
      return send(response, 200, pilotMcpPage());
    }
    if (!pilot && endpoint === 'POST /projects/api/purchases') {
      purchaseWrite(request);
      const result = projects.acceptPurchase(await readBody(request, jsonType));
      return json(response, result.created ? 201 : 200, result);
    }
    if (!pilot && endpoint === 'POST /projects/api/handoffs') {
      purchaseWrite(request);
      const handoff = projects.issueHandoff(await readBody(request, jsonType));
      return json(response, 201, { ...handoff, action: startOrigin + '/projects/open' });
    }
    if (!pilot && endpoint === 'POST /projects/api/client-handoffs') {
      purchaseWrite(request);
      const handoff = projects.issueClientHandoff(await readBody(request, jsonType));
      return json(response, 201, { ...handoff, action: startOrigin + '/projects/open' });
    }
    if (!pilot && endpoint === 'GET ' + metadataPath) {
      return json(response, 200, {
        resource, authorization_servers: [issuer], scopes_supported: [projectScope],
        bearer_methods_supported: ['header'],
      });
    }
    if (pathname === mcpPath) {
      if (request.headers.origin !== undefined) checkOrigin(request, startOrigin);
      const token = bearer(request);
      const accountId = pilot ? projects.accountForPilotMcp(token, pilotAccount) : await authorizeMcp(token);
      if (!accountId) {
        response.setHeader('WWW-Authenticate', pilot ? 'Bearer realm="Kiwe Start pilot"' : `Bearer resource_metadata="${metadataUrl}", scope="${projectScope}"`);
        return json(response, 401, { error: 'authorization_required' });
      }
      if (method !== 'POST') {
        response.setHeader('Allow', 'POST');
        return json(response, 405, { error: 'method_not_allowed' });
      }
      const payload = await readBody(request, jsonType);
      const server = mcpTools(projects, siteConnections, managedHosting, keyConnections, agencySpaces, homepage, bricks, publication, website, accountId, pilot);
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
        if (accountForBrowser(request) === project.accountId) {
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
    if (!pilot && endpoint === 'POST /projects/open') {
      checkOrigin(request, checkoutOrigin);
      const session = projects.redeemHandoff(await readBody(request, formType));
      projects.endSession(sessionToken(request));
      setCookie(response, cookieName, session.token, browserLifetime / 1000);
      return redirect(response, '/projects/' + session.projectId);
    }
    if (endpoint === 'POST /projects/sign-out') {
      checkOrigin(request, startOrigin);
      projects.endSession(sessionToken(request));
      setCookie(response, cookieName, '', 0);
      setCookie(response, loginCookie, '', 0);
      return redirect(response, browserLogin ? browserLoginPath : '/projects');
    }
    if (endpoint === 'GET ' + publicationReturn) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      try {
        const opened = await publication.complete(Object.fromEntries(url.searchParams));
        if (accountForBrowser(request) === opened.accountId) {
          return redirect(response, documentPath(opened.projectId, opened.target) + '#publication');
        }
        return send(response, 200, siteReturnPage('The publication review was saved.', true));
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return send(response, error.status, siteReturnPage(error.message, false));
      }
    }
    if (endpoint === 'GET ' + inventoryReturn || endpoint === 'GET ' + bricksReturn) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      const conversion = pathname === bricksReturn;
      try {
        const state = await (conversion ? bricks : website).complete(Object.fromEntries(url.searchParams));
        if (accountForBrowser(request) === state.accountId) {
          return redirect(response, conversion ? documentPath(state.projectId, state.target) : '/projects/' + state.projectId + '/pages');
        }
        return send(response, 200, siteReturnPage(conversion ? 'The Bricks review is saved. Reopen your Start project.'
          : 'The website inventory is saved. Reopen your Start project.', true));
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return send(response, error.status, siteReturnPage(error.message, false));
      }
    }
    const pagesRoute = pathname.match(/^\/projects\/([a-f0-9-]{36})\/pages(?:\/(refresh))?$/);
    if (pagesRoute) {
      const [, projectId, action] = pagesRoute;
      const accountId = accountForBrowser(request);
      if (!accountId) return sendSignIn(response, 401);
      const project = projects.getProject(accountId, { projectId });
      const page = (error) => websitePage(project, website.state(accountId, { projectId }), error);
      if (method === 'GET' && !action) return send(response, 200, page());
      if (method === 'POST' && action === 'refresh') {
        checkOrigin(request, startOrigin);
        try {
          const input = await readBody(request, formType);
          const authorizationUrl = await website.begin(accountId, { projectId, revision: Number(input.revision) });
          return send(response, 200, siteAuthorizationPage(project, authorizationUrl, 'let Kiwe Start read your published pages and templates'));
        } catch (error) {
          if (!(error instanceof StartError)) throw error;
          return send(response, error.status, page(error.message));
        }
      }
    }
    if (endpoint === 'GET ' + homepageReturn) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      try {
        const opened = await homepage.complete(Object.fromEntries(url.searchParams));
        if (accountForBrowser(request) === opened.accountId) {
          return redirect(response, documentPath(opened.projectId, opened.target));
        }
        return send(response, 200, siteReturnPage('The design request is saved. Return to your Start project.', true));
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return send(response, error.status, siteReturnPage(error.message, false));
      }
    }
    const homeRoute = pathname.match(/^\/projects\/([a-f0-9-]{36})\/(?:homepage|pages\/([a-z][a-z0-9-]{0,30}))(?:\/(request|revise|generate|approve|preview|source|convert|continue-import|publication|finish-publication))?$/);
    if (homeRoute && homeRoute[2] !== 'refresh') {
      const [, projectId, target, action] = homeRoute;
      const accountId = accountForBrowser(request)
        ?? (action === 'source' && method === 'GET' ? await authorizeMcp(bearer(request)) : null);
      if (!accountId) return sendSignIn(response, 401);
      const project = projects.getProject(accountId, { projectId });
      const location = documentPath(projectId, target);
      const page = (error) => {
        const state = homepage.status(accountId, { projectId, target });
        const conversion = state.draft?.status === 'approved' ? bricks.status(accountId, projectId, state.draft.id, target) : null;
        const published = conversion?.status === 'draft_ready' ? publication.status(accountId, project.id, state.draft.id, target) : null;
        return homepagePage(project, state, { error, generator: homepage.generatorConfigured, compiler: bricks.available, conversion, publication: published });
      };
      if (method === 'GET' && !action) {
        response.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        return send(response, 200, page());
      }
      if (method === 'GET' && action === 'preview') {
        const draftId = url.searchParams.get('draft') ?? '';
        if (!/^[a-f0-9-]{36}$/.test(draftId)) throw new StartError(400, 'homepage_preview_required', 'Open the homepage preview from its review.');
        const { files } = homepage.source(accountId, projectId, draftId, false, target);
        const mediaOrigin = new URL(project.site.url).origin;
        const hash = (contents) => "'sha256-" + createHash('sha256').update(contents).digest('base64') + "'";
        response.setHeader('Content-Security-Policy', "sandbox allow-scripts; default-src 'none'; script-src " + hash(files['preview.js'])
          + "; style-src " + hash(files['styles.css']) + "; img-src " + mediaOrigin + "; form-action 'none'; base-uri 'none'; frame-ancestors 'self'");
        response.setHeader('X-Robots-Tag', 'noindex, nofollow');
        response.setHeader('Referrer-Policy', 'no-referrer');
        return send(response, 200, previewSource(files));
      }
      if (method === 'GET' && action === 'source') {
        const draftId = url.searchParams.get('draft') ?? undefined;
        if (draftId !== undefined && !/^[a-f0-9-]{36}$/.test(draftId)) throw new StartError(400, 'homepage_source_unavailable', 'Choose an approved homepage revision.');
        const source = homepage.source(accountId, projectId, draftId, true, target);
        const forSeam = url.searchParams.get('format') === 'seam';
        const download = forSeam ? seamSource(source) : source;
        response.setHeader('Content-Disposition', 'attachment; filename="' + (target ?? 'homepage') + (forSeam ? '-for-seam.zip"' : '-source.zip"'));
        response.setHeader('X-Kiwe-Source-SHA256', download.sha256);
        response.setHeader('X-Kiwe-Approved-Source-SHA256', source.sha256);
        return send(response, 200, homepageArchive(download.files), 'application/zip');
      }
      if (method === 'POST' && action && !['preview', 'source'].includes(action)) {
        checkOrigin(request, startOrigin);
        try {
          const fields = await readBody(request, formType);
          if (action === 'request') {
            const link = await homepage.begin(accountId, { ...fields, projectId, target, revision: Number(fields.revision) });
            return send(response, 200, siteAuthorizationPage(project, link, 'let Kiwe Start read the saved Design Context for this design', location));
          }
          if (action === 'revise') await homepage.revise(accountId, {
            ...fields, projectId, target, revision: Number(fields.revision), regenerate: fields.regenerate === '1',
          });
          if (action === 'approve') homepage.approve(accountId, { ...fields, projectId, target });
          if (action === 'generate') await homepage.generate(accountId, { projectId, target });
          if (action === 'convert') {
            const started = await bricks.begin(accountId, { ...fields, projectId, target, revision: Number(fields.revision) });
            if (started.authorizationUrl) {
              return send(response, 200, siteAuthorizationPage(project, started.authorizationUrl, 'let Kiwe Start prepare the private Bricks draft review', location));
            }
          }
          if (action === 'continue-import') {
            await bricks.resume(accountId, { ...fields, projectId, target, revision: Number(fields.revision) });
          }
          if (action === 'publication' || action === 'finish-publication') {
            const input = { ...fields, projectId, target, revision: Number(fields.revision) };
            if (action === 'publication') {
              const result = await publication.begin(accountId, input);
              if (result.authorizationUrl) {
                const purpose = input.action === 'restore'
                  ? 'let Kiwe Start prepare the review to restore the previous design'
                  : 'let Kiwe Start prepare the review to publish this design';
                return send(response, 200, siteAuthorizationPage(project, result.authorizationUrl, purpose, location + '#publication'));
              }
            } else await publication.resume(accountId, input);
            return redirect(response, location + '#publication');
          }
          return redirect(response, location);
        } catch (error) {
          if (!(error instanceof StartError)) throw error;
          response.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
          return send(response, error.status, page(error.message));
        }
      }
    }
    const contextRoute = method === 'GET' && pathname.match(/^\/projects\/([a-f0-9-]{36})\/context$/);
    if (contextRoute) {
      const accountId = accountForBrowser(request);
      if (!accountId) return sendSignIn(response, 401);
      const [, projectId] = contextRoute;
      const project = projects.readProject(accountId, { projectId });
      return redirect(response, project.site?.designContextUrl ?? '/projects/' + project.id);
    }
    const agencyRoute = pathname === '/projects/agency' || pathname === '/projects/agency/verify';
    if (agencyRoute) {
      const accountId = accountForBrowser(request);
      if (!accountId) return sendSignIn(response, 401);
      const agency = projects.getAgencySpace(accountId);
      if (endpoint === 'GET /projects/agency') return send(response, 200, agencyPage(agency));
      if (method === 'POST') {
        checkOrigin(request, startOrigin);
        try {
          const input = await readBody(request, formType);
          if (pathname.endsWith('/verify')) await agencySpaces.verify(accountId, input);
          else projects.prepareAgencyHome(accountId, input);
          return redirect(response, '/projects/agency');
        } catch (error) {
          if (!(error instanceof StartError)) throw error;
          return send(response, error.status, agencyPage(projects.getAgencySpace(accountId), error.message));
        }
      }
    }
    if (endpoint === 'POST /projects/agency/sites') {
      const accountId = accountForBrowser(request);
      if (!accountId) return sendSignIn(response, 401);
      checkOrigin(request, startOrigin);
      try {
        const project = projects.addAgencySite(accountId, await readBody(request, formType));
        return redirect(response, '/projects/' + project.id);
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return send(response, error.status, projectsPage(projects.listProjects(accountId), projects.agencySites(accountId), error.message, pilot));
      }
    }
    const match = pathname.match(/^\/projects\/([a-f0-9-]{36})(?:\/(domain|connect|hosting|key|invite-client|revoke-client))?$/);
    const listRoute = endpoint === 'GET /projects' || endpoint === 'GET /projects/';
    if (listRoute || match) {
      const accountId = accountForBrowser(request);
      if (!accountId) return sendSignIn(response, 401);
      if (listRoute) return send(response, 200, projectsPage(projects.listProjects(accountId), projects.agencySites(accountId), undefined, pilot));
      const [, projectId, action] = match;
      const ref = { projectId };
      if (method === 'GET' && !action) {
        const project = projects.readProject(accountId, ref);
        if (project.access === 'client') return send(response, 200, clientProjectPage(project));
        return send(response, 200, showOwnerProject(project));
      }
      function showOwnerProject(project, options = {}) {
        if (keyProvider?.origin) {
          response.setHeader('Content-Security-Policy', browserPolicy + "; form-action 'self' " + keyProvider.origin);
        }
        const clientAccess = !pilot && project.accountType === 'agency' && project.site
          ? projects.clientAccess(accountId, ref) : null;
        return projectPage(project, { managed: managedHosting.available, keyEnabled: keyConnections.available, clientAccess, ...options });
      }
      const project = projects.getProject(accountId, ref);
      if (method === 'POST' && action) {
        checkOrigin(request, startOrigin);
        try {
          const { revision, ...form } = await readBody(request, formType);
          const input = { ...form, projectId, revision: Number(revision) };
          if (action === 'invite-client') {
            if (pilot) throw new StartError(403, 'pilot_owner_only', 'Client invitations open with customer sign-in.');
            const invitation = projects.inviteClient(accountId, input);
            response.setHeader('Referrer-Policy', 'no-referrer');
            return send(response, 201, showOwnerProject(project, { invitation }));
          }
          if (action === 'revoke-client') {
            projects.revokeClient(accountId, ref);
            return redirect(response, '/projects/' + projectId);
          }
          if (action === 'connect') {
            const pending = await siteConnections.begin(accountId, input);
            return send(response, 200, siteAuthorizationPage(project, pending.authorizationUrl));
          }
          if (action === 'key') {
            const key = await keyConnections.resume(accountId, input);
            return redirect(response, key.project.key.state === 'awaiting_approval' ? key.approvalUrl : '/projects/' + projectId);
          }
          if (action === 'hosting') await managedHosting.step(accountId, input);
          else projects.setDomain(accountId, input);
          return redirect(response, '/projects/' + projectId);
        } catch (error) {
          if (!(error instanceof StartError)) throw error;
          const latest = projects.getProject(accountId, { projectId });
          return send(response, error.status, showOwnerProject(latest, { error: error.message }));
        }
      }
    }
    if (publicGuide && await publicGuide(request, response, pathname)) return;
    return json(response, 404, { error: 'not_found' });
  }

  return createServer({ requestTimeout: 15000, headersTimeout: 10000 }, (request, response) => {
    response.setHeader('Cache-Control', 'no-store');
    response.setHeader('Referrer-Policy', 'same-origin');
    response.setHeader('X-Content-Type-Options', 'nosniff');
    response.setHeader('Content-Security-Policy', browserPolicy + "; form-action 'self'");
    void route(request, response).catch((error) => {
      if (response.writableEnded) return;
      if (response.headersSent) return response.destroy();
      if (error instanceof StartError) {
        if (request.url === '/projects/open') {
          return sendSignIn(response, error.status, error.message);
        }
        return json(response, error.status, { error: error.code, message: error.message });
      }
      return json(response, 500, { error: 'service_unavailable' });
    });
  });
}
