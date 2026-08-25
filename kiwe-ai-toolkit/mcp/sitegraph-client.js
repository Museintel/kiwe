#!/usr/bin/env node

import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';

const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
const REQUEST_TIMEOUT_MS = 30_000;

export const SITEGRAPH_TOOLS = Object.freeze({
  kiwe_sitegraph_status: {
    description: 'Verify a short-lived Kiwe SiteGraph task connection and return its non-secret capability status.',
    method: 'GET', path: '/status', inputSchema: { type: 'object', properties: {} }
  },
  kiwe_sitegraph_get_graph: {
    description: 'Read the one public-only target SiteGraph containing owner Design Context, approved design material, content, commerce and builder capabilities. The task capsule enforces its resource and row budgets.',
    method: 'GET', path: '/site-graph',
    inputSchema: {
      type: 'object',
      properties: {
        productLimit: { type: 'integer', minimum: 0, maximum: 100, default: 24 },
        mediaLimit: { type: 'integer', minimum: 0, maximum: 100, default: 48 },
        contentLimit: { type: 'integer', minimum: 0, maximum: 100, default: 12 },
        customContentLimit: { type: 'integer', minimum: 0, maximum: 100, default: 24 },
        termLimit: { type: 'integer', minimum: 0, maximum: 300, default: 100 },
        mediaSearch: { type: 'string' },
        resources: { type: 'array', items: { type: 'string' } }
      }
    }
  },
  kiwe_sitegraph_get_data_schema: {
    description: 'Read the SiteGraph Data resource and field schema allowed by this task capsule.',
    method: 'GET', path: '/site-graph-data/schema', inputSchema: { type: 'object', properties: {} }
  },
  kiwe_sitegraph_query: {
    description: 'Query public target-site content within the capsule resource, field and row budgets.',
    method: 'POST', path: '/site-graph-data',
    inputSchema: {
      type: 'object',
      properties: {
        resource: { type: 'string', enum: ['site', 'menus', 'posts', 'pages', 'products', 'terms', 'media'] },
        limit: { type: 'integer', minimum: 1, maximum: 100 },
        taxonomy: { type: 'string' }, term: { type: ['string', 'integer'] }, search: { type: 'string' }, fields: { type: 'array', items: { type: 'string' } },
        queries: { type: 'object', additionalProperties: { type: 'object' } }
      }
    }
  },
  kiwe_bricks_context: {
    description: 'Read verified Bricks, WooCommerce and Kiwe dynamic-tag/query-loop capabilities for planning; this does not save content.',
    method: 'POST', path: '/bricks/context', inputSchema: { type: 'object', properties: { intent: { type: 'string' }, sourceSummary: { type: 'string' } } }
  },
  kiwe_validate_bindings: {
    description: 'Validate a kiwe.bricks-bindings.v1 plan without applying it.',
    method: 'POST', path: '/validate-bindings', inputSchema: { type: 'object', properties: { bindings: { type: 'object' }, sampleLimit: { type: 'integer', minimum: 0, maximum: 24 } }, required: ['bindings'] }
  },
  kiwe_validate_bricks_conversion: {
    description: 'Validate a deterministic Bricks conversion package without importing or saving it.',
    method: 'POST', path: '/validate-bricks-conversion', inputSchema: { type: 'object', properties: { conversion: { type: 'object' }, sampleLimit: { type: 'integer', minimum: 0, maximum: 24 } }, required: ['conversion'] }
  },
  kiwe_validate_accessibility: {
    description: 'Validate an accessibility artifact without changing its design, Bricks data or runtime.',
    method: 'POST', path: '/validate-accessibility', inputSchema: { type: 'object', additionalProperties: true }
  },
  kiwe_seamflow_status: {
    description: 'Read the deterministic SeamFlow contract and supported operations.',
    method: 'GET', path: '/seamflow/status', inputSchema: { type: 'object', properties: {} }
  },
  kiwe_seamflow_classify: {
    description: 'Classify supplied project evidence into the smallest SeamFlow lane without mutating WordPress.',
    method: 'POST', path: '/seamflow/classify', inputSchema: { type: 'object', additionalProperties: true }
  },
  kiwe_seamflow_convert_bricks: {
    description: 'Run the deterministic server-side Bricks conversion contract. This returns artifacts and never imports or publishes them.',
    method: 'POST', path: '/seamflow/convert-bricks', inputSchema: { type: 'object', additionalProperties: true }
  }
});

export function normalizeSiteGraphBaseUrl(value) {
  const input = String(value || '').trim().replace(/\/$/, '');
  if (!input) throw new Error('KIWE_SITEGRAPH_BASE_URL is required.');
  const url = new URL(input);
  const local = ['localhost', '127.0.0.1', '::1'].includes(url.hostname);
  if (url.protocol !== 'https:' && !(local && url.protocol === 'http:')) {
    throw new Error('Kiwe SiteGraph requires HTTPS except for a local development host.');
  }
  if (url.username || url.password || url.search || url.hash) throw new Error('Kiwe SiteGraph base URL must not contain credentials, a query string or a fragment.');
  if (!url.pathname.endsWith('/wp-json/dsa/v1/ai')) throw new Error('KIWE_SITEGRAPH_BASE_URL must end with /wp-json/dsa/v1/ai.');
  return url.toString().replace(/\/$/, '');
}

export function validateTaskToken(value) {
  const token = String(value || '').trim();
  if (!/^kiwe_task_[A-Za-z0-9_]{20,}$/.test(token)) {
    throw new Error('KIWE_SITEGRAPH_TASK_TOKEN must be a short-lived kiwe_task_* capsule, not a permanent key.');
  }
  return token;
}

function redact(text, secret) {
  return String(text || '').split(secret).join('[REDACTED]');
}

export function createSiteGraphHttpClient({ baseUrl, token, fetchImpl = globalThis.fetch } = {}) {
  const base = normalizeSiteGraphBaseUrl(baseUrl);
  const secret = validateTaskToken(token);
  if (typeof fetchImpl !== 'function') throw new Error('A Fetch API implementation is required.');

  return async function call(name, args = {}) {
    const tool = SITEGRAPH_TOOLS[name];
    if (!tool) throw new Error(`Unknown or forbidden Kiwe SiteGraph tool: ${name}`);
    const url = new URL(`${base}${tool.path}`);
    const options = {
      method: tool.method,
      redirect: 'manual',
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      headers: { Authorization: `Bearer ${secret}`, Accept: 'application/json' }
    };
    if (tool.method === 'GET') {
      for (const [key, value] of Object.entries(args || {})) {
        if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
      }
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(args || {});
    }

    let response;
    try {
      response = await fetchImpl(url, options);
    } catch (error) {
      throw new Error(redact(`Kiwe SiteGraph request failed: ${error?.message || error}`, secret));
    }
    if (response.status >= 300 && response.status < 400) throw new Error('Kiwe SiteGraph refused a redirect so the bearer credential cannot leak to another origin.');
    const declaredLength = Number(response.headers?.get?.('content-length') || 0);
    if (declaredLength > MAX_RESPONSE_BYTES) throw new Error('Kiwe SiteGraph response exceeded the local MCP output budget.');
    const text = await response.text();
    if (Buffer.byteLength(text, 'utf8') > MAX_RESPONSE_BYTES) throw new Error('Kiwe SiteGraph response exceeded the local MCP output budget.');
    let data;
    try { data = text ? JSON.parse(text) : {}; }
    catch { throw new Error('Kiwe SiteGraph returned a non-JSON response.'); }
    if (!response.ok) {
      const message = data?.error?.message || data?.message || `HTTP ${response.status}`;
      throw new Error(redact(`Kiwe SiteGraph rejected the request: ${message}`, secret));
    }
    return data;
  };
}

export function createSiteGraphMcpServer(options = {}) {
  const call = createSiteGraphHttpClient(options);
  const server = new Server({ name: 'kiwe-sitegraph', version: '1.0.0' }, { capabilities: { tools: {} } });
  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: Object.entries(SITEGRAPH_TOOLS).map(([name, tool]) => ({ name, description: tool.description, inputSchema: tool.inputSchema }))
  }));
  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const result = await call(request.params.name, request.params.arguments || {});
    return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }], structuredContent: result };
  });
  return server;
}

async function main() {
  const server = createSiteGraphMcpServer({
    baseUrl: process.env.KIWE_SITEGRAPH_BASE_URL,
    token: process.env.KIWE_SITEGRAPH_TASK_TOKEN
  });
  await server.connect(new StdioServerTransport());
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';
if (invokedPath && invokedPath === path.resolve(fileURLToPath(import.meta.url))) {
  main().catch((error) => {
    process.stderr.write(`Kiwe SiteGraph MCP could not start: ${String(error?.message || error)}\n`);
    process.exit(1);
  });
}
