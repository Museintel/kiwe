import { createHash, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { z } from 'zod';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  agencyHomeInput, agencySpaceOutput, agencyVerificationInput,
  StartError, browserLifetime, claimInput, domainInput, projectScope, projectsOutput, singleProjectOutput, siteLinkInput,
} from './projects.mjs';
import { agencyPage, contextPage, contextReviewPage, homepagePage, websitePage, projectPage, projectsPage, signInPage, siteAuthorizationPage, siteReturnPage, stylesheet } from './web.mjs';
import { createSiteConnections, siteCallbackPath } from './wordpress-link.mjs';
import { createManagedHosting } from './managed-hosting.mjs';
import { createKeyConnections } from './key-connection.mjs';
import { createAgencySpaces } from './agency-space.mjs';
import {
  createHomepageWorkflow, homeRequestInput, homeRevisionInput, homeSubmitInput, homepageReturn,
} from './homepage.mjs';
import { previewSource, seamSource } from './homepage-source.mjs';
import { homepageArchive } from './homepage-archive.mjs';
import { bricksInput, bricksReturn, createBricksImport } from './bricks-import.mjs';
import { createHomepagePublication, publicationInput, publicationReturn } from './homepage-publication.mjs';
import { createWebsiteInventory, inventoryReturn, websiteChecklist } from './website-inventory.mjs';
import {
  contextArea, contextInput, contextOutput, contextEditInput, contextProposalOutput, contextReturnPath, createDesignContext,
} from './design-context.mjs';

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

function mcpTools(projects, siteConnections, managedHosting, keyConnections, agencySpaces, context, homepage, bricks, publication, website, accountId) {
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
  server.registerTool('kiwe_start_context', {
    description: 'Read the native, site-specific Design Context section. Show current values and ask the authorized human for any missing public business facts.',
    inputSchema: contextInput,
    outputSchema: contextOutput,
    annotations: { readOnlyHint: true },
  }, (input) => run(async () => result('Design Context', await context.status(accountId, input))));
  server.registerTool('kiwe_start_open_context', {
    description: 'Open the selected WordPress site’s native consent for introductory Design Context editing.',
    inputSchema: siteLinkInput,
    outputSchema: z.strictObject({ authorizationUrl: z.url() }),
    annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
  }, (input) => run(async () => result('Approve Design Context access in WordPress', {
    authorizationUrl: await context.begin(accountId, input),
  })));
  server.registerTool('kiwe_start_prepare_context_change', {
    description: 'Prepare the exact public business fact supplied by the human. Open the review link so they can confirm it in Start and save to the selected WordPress site.',
    inputSchema: contextEditInput,
    outputSchema: contextProposalOutput,
    annotations: { destructiveHint: false },
  }, (input) => run(async () => result('Open the review to confirm this Design Context change', await context.propose(accountId, input))));
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
  server.registerTool('kiwe_start_website', {
    description: 'Read the website’s Pages and Templates workspace, including the current homepage review and native conversion status.',
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
    server.registerTool('kiwe_start_resume_bricks', {
      description: 'Check and continue the same homepage import after the native WordPress approval. Read its exact draft link when ready.',
      inputSchema: bricksInput,
      annotations: { destructiveHint: false, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Bricks draft status', { conversion: await bricks.resume(accountId, input) })));
    server.registerTool('kiwe_start_prepare_homepage_publication', {
      description: 'Open WordPress owner authorization to publish the exact approved Bricks draft on the assigned homepage or restore the saved previous layout.',
      inputSchema: publicationInput,
      annotations: { destructiveHint: true, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Homepage publication review', await publication.begin(accountId, input))));
    server.registerTool('kiwe_start_complete_homepage_publication', {
      description: 'Check the exact native publication or recovery review. Apply it only after the owner has approved it in WordPress.',
      inputSchema: publicationInput,
      annotations: { destructiveHint: true, idempotentHint: true, openWorldHint: true },
    }, (input) => run(async () => result('Homepage publication', { publication: await publication.resume(accountId, input) })));
  }
  return server;
}

export function createStartServer({ projects, startOrigin, checkoutOrigin, issuer, purchaseToken, authorizeMcp, wordpressLink, hostingProvider, keyProvider, agencyReader, contextWordPress, contextClock, homepageWordPress, homepageProvider, inventoryWordPress, bricksWordPress, bricksCompiler, publicationWordPress }) {
  const keyConnections = createKeyConnections(projects, keyProvider);
  const agencySpaces = createAgencySpaces(projects, agencyReader);
  const context = createDesignContext(projects, startOrigin, contextWordPress, contextClock);
  const homepage = createHomepageWorkflow(projects, startOrigin, homepageWordPress, homepageProvider, contextClock);
  const bricks = createBricksImport(projects, homepage, startOrigin, bricksCompiler, bricksWordPress, contextClock);
  const publication = createHomepagePublication(projects, startOrigin, publicationWordPress, contextClock);
  const inventory = createWebsiteInventory(projects, startOrigin, inventoryWordPress, contextClock);
  const website = {
    ...inventory,
    state(accountId, input) {
      const native = inventory.status(accountId, input);
      const project = projects.getProject(accountId, input);
      const home = project.key?.siteAuthenticated && project.key.state !== 'inactive' ? homepage.status(accountId, input) : null;
      const conversion = home?.draft?.status === 'approved' ? bricks.status(accountId, project.id, home.draft.id) : null;
      const published = conversion?.status === 'draft_ready' ? publication.status(accountId, project.id, home.draft.id) : null;
      const items = websiteChecklist(project, native.inventory, home, conversion, bricks.available, published);
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
      const server = mcpTools(projects, siteConnections, managedHosting, keyConnections, agencySpaces, context, homepage, bricks, publication, website, accountId);
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
    if (endpoint === 'GET ' + contextReturnPath) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      try {
        const access = await context.complete(Object.fromEntries(url.searchParams));
        if (projects.accountForSession(sessionToken(request)) === access.accountId) {
          return redirect(response, '/projects/' + access.projectId + '/context');
        }
        return send(response, 200, siteReturnPage('Design Context is connected. Return to your Start project.', true));
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return send(response, error.status, siteReturnPage(error.message, false));
      }
    }
    if (endpoint === 'GET ' + publicationReturn) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      try {
        const opened = await publication.complete(Object.fromEntries(url.searchParams));
        if (projects.accountForSession(sessionToken(request)) === opened.accountId) {
          return redirect(response, '/projects/' + opened.projectId + '/homepage#publication');
        }
        return send(response, 200, siteReturnPage('The homepage publication review was saved.', true));
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
        const section = conversion ? '/homepage' : '/pages';
        if (projects.accountForSession(sessionToken(request)) === state.accountId) {
          return redirect(response, '/projects/' + state.projectId + section);
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
      const accountId = projects.accountForSession(sessionToken(request));
      if (!accountId) return send(response, 401, signInPage(checkoutOrigin));
      const project = projects.getProject(accountId, { projectId });
      const page = (error) => websitePage(project, website.state(accountId, { projectId }), error);
      if (method === 'GET' && !action) return send(response, 200, page());
      if (method === 'POST' && action === 'refresh') {
        checkOrigin(request, startOrigin);
        try {
          const input = await readBody(request, formType);
          return redirect(response, await website.begin(accountId, { projectId, revision: Number(input.revision) }));
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
        if (projects.accountForSession(sessionToken(request)) === opened.accountId) {
          return redirect(response, '/projects/' + opened.projectId + '/homepage');
        }
        return send(response, 200, siteReturnPage('The homepage request is saved. Return to your Start project.', true));
      } catch (error) {
        if (!(error instanceof StartError)) throw error;
        return send(response, error.status, siteReturnPage(error.message, false));
      }
    }
    const homeRoute = pathname.match(/^\/projects\/([a-f0-9-]{36})\/homepage(?:\/(request|revise|generate|approve|preview|source|convert|continue-import|publication|finish-publication))?$/);
    if (homeRoute) {
      const [, projectId, action] = homeRoute;
      const accountId = projects.accountForSession(sessionToken(request))
        ?? (action === 'source' && method === 'GET' ? await authorizeMcp(bearer(request)) : null);
      if (!accountId) return send(response, 401, signInPage(checkoutOrigin));
      const project = projects.getProject(accountId, { projectId });
      const location = '/projects/' + projectId + '/homepage';
      const page = (error) => {
        const state = homepage.status(accountId, { projectId });
        const conversion = state.draft?.status === 'approved' ? bricks.status(accountId, projectId, state.draft.id) : null;
        const published = conversion?.status === 'draft_ready' ? publication.status(accountId, project.id, state.draft.id) : null;
        return homepagePage(project, state, { error, generator: homepage.generatorConfigured, compiler: bricks.available, conversion, publication: published });
      };
      if (method === 'GET' && !action) {
        response.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        return send(response, 200, page());
      }
      if (method === 'GET' && action === 'preview') {
        const draftId = url.searchParams.get('draft') ?? '';
        if (!/^[a-f0-9-]{36}$/.test(draftId)) throw new StartError(400, 'homepage_preview_required', 'Open the homepage preview from its review.');
        const { files } = homepage.source(accountId, projectId, draftId);
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
        const source = homepage.source(accountId, projectId, draftId, true);
        const forSeam = url.searchParams.get('format') === 'seam';
        const download = forSeam ? seamSource(source) : source;
        response.setHeader('Content-Disposition', forSeam ? 'attachment; filename="homepage-for-seam.zip"' : 'attachment; filename="homepage-source.zip"');
        response.setHeader('X-Kiwe-Source-SHA256', download.sha256);
        response.setHeader('X-Kiwe-Approved-Source-SHA256', source.sha256);
        return send(response, 200, homepageArchive(download.files), 'application/zip');
      }
      if (method === 'POST' && action && !['preview', 'source'].includes(action)) {
        checkOrigin(request, startOrigin);
        try {
          const fields = await readBody(request, formType);
          if (action === 'request') {
            const link = await homepage.begin(accountId, { ...fields, projectId, revision: Number(fields.revision) });
            return redirect(response, link);
          }
          if (action === 'revise') await homepage.revise(accountId, { ...fields, projectId, revision: Number(fields.revision) });
          if (action === 'approve') homepage.approve(accountId, { ...fields, projectId });
          if (action === 'generate') await homepage.generate(accountId, { projectId });
          if (action === 'convert') {
            const started = await bricks.begin(accountId, { ...fields, projectId, revision: Number(fields.revision) });
            if (started.authorizationUrl) return redirect(response, started.authorizationUrl);
          }
          if (action === 'continue-import') {
            await bricks.resume(accountId, { ...fields, projectId, revision: Number(fields.revision) });
          }
          if (action === 'publication' || action === 'finish-publication') {
            const input = { ...fields, projectId, revision: Number(fields.revision) };
            if (action === 'publication') {
              const result = await publication.begin(accountId, input);
              if (result.authorizationUrl) return redirect(response, result.authorizationUrl);
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
    const contextRoute = pathname.match(/^\/projects\/([a-f0-9-]{36})\/context(?:\/(authorize|close|review\/([a-f0-9-]{36})))?$/);
    if (contextRoute) {
      const accountId = projects.accountForSession(sessionToken(request));
      if (!accountId) return send(response, 401, signInPage(checkoutOrigin));
      const [, projectId, action, reviewId] = contextRoute;
      const project = projects.getProject(accountId, { projectId });
      const area = contextArea.safeParse(url.searchParams.get('area')).data ?? 'identity';
      const location = '/projects/' + projectId + '/context';
      if (method === 'GET' && reviewId) {
        return send(response, 200, contextReviewPage(project, context.review(accountId, projectId, reviewId), reviewId));
      }
      if (method === 'GET' && !action) {
        try {
          const view = await context.status(accountId, { projectId, revision: project.revision, area });
          return send(response, 200, contextPage(project, area, view));
        } catch (error) {
          if (!(error instanceof StartError)) throw error;
          return send(response, error.status, contextPage(project, area, null, error.message));
        }
      }
      if (method === 'POST') {
        checkOrigin(request, startOrigin);
        try {
          const form = await readBody(request, formType);
          if (reviewId) {
            const savedArea = await context.confirm(accountId, projectId, reviewId);
            return redirect(response, location + '?area=' + savedArea);
          }
          if (action === 'authorize') {
            const approval = await context.begin(accountId, { projectId, revision: Number(form.revision) });
            return redirect(response, approval);
          }
          if (action === 'close') {
            await context.close(accountId, projectId);
            return redirect(response, location + '?area=' + area);
          }
          await context.save(accountId, { ...form, projectId, revision: Number(form.revision) });
          return redirect(response, location + '?area=' + form.area);
        } catch (error) {
          if (!(error instanceof StartError)) throw error;
          return send(response, error.status, contextPage(project, area, null, error.message));
        }
      }
    }
    const agencyRoute = pathname === '/projects/agency' || pathname === '/projects/agency/verify';
    if (agencyRoute) {
      const accountId = projects.accountForSession(sessionToken(request));
      if (!accountId) return send(response, 401, signInPage(checkoutOrigin));
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
    const match = pathname.match(/^\/projects\/([a-f0-9-]{36})(?:\/(domain|connect|hosting|key))?$/);
    const listRoute = endpoint === 'GET /projects' || endpoint === 'GET /projects/';
    if (listRoute || match) {
      const accountId = projects.accountForSession(sessionToken(request));
      if (!accountId) return send(response, 401, signInPage(checkoutOrigin));
      if (listRoute) return send(response, 200, projectsPage(projects.listProjects(accountId)));
      const [, projectId, action] = match;
      const project = projects.getProject(accountId, { projectId });
      if (method === 'GET' && !action) return send(response, 200, projectPage(project, { managed: managedHosting.available, keyEnabled: keyConnections.available }));
      if (method === 'POST' && action) {
        checkOrigin(request, startOrigin);
        try {
          const { revision, ...form } = await readBody(request, formType);
          const input = { ...form, projectId, revision: Number(revision) };
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
          return send(response, error.status, projectPage(latest, { error: error.message, managed: managedHosting.available, keyEnabled: keyConnections.available }));
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
