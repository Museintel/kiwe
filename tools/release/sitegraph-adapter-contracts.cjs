#!/usr/bin/env node

const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const mcpSource = read('kiwe-ai-toolkit/mcp/sitegraph-client.js');
const adapterSource = read('wp-content/mu-plugins/dsa/includes/AI/External_Client_Adapter_Service.php');
const openapiSource = read('wp-content/mu-plugins/dsa/includes/AI/External_Client_OpenAPI_Service.php');
const controllerSource = read('wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php');

const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

(async () => {
  check('task-only OpenAPI filters non-capsule scopes', openapiSource.includes('$task_only') && openapiSource.includes('Task_Capsule_Service::SCOPES'));
  check('task-only OpenAPI and adapter catalog are public discovery routes', controllerSource.includes("'/ai/openapi.task.json'") && controllerSource.includes("'/ai/client-adapters'"));
  check('client configs reference one external secret', adapterSource.includes('KIWE_SITEGRAPH_TASK_TOKEN') && adapterSource.includes("'connection.authentication.token'") && !adapterSource.includes("'value'       =>"));
  check('client configs cover OpenAPI MCP Claude Cursor and Chrome', ['chatgptOpenAPI', "'mcp'", "'claude'", "'cursor'", 'chromeExtension'].every((needle) => adapterSource.includes(needle)));
  check('MCP client excludes staging mutation and runtime tool names', !/kiwe_(?:stage|mutation|publish|checkout|cart|auth)/.test(mcpSource));
  check('MCP client requires HTTPS task capsules and blocks redirects', mcpSource.includes("url.protocol !== 'https:'") && mcpSource.includes('/^kiwe_task_') && mcpSource.includes("redirect: 'manual'") && mcpSource.includes('refused a redirect'));
  check('MCP client caps response size and request time', mcpSource.includes('MAX_RESPONSE_BYTES') && mcpSource.includes('REQUEST_TIMEOUT_MS') && mcpSource.includes('AbortSignal.timeout'));

  const moduleUrl = pathToFileURL(path.join(root, 'kiwe-ai-toolkit/mcp/sitegraph-client.js')).href;
  const clientModule = await import(moduleUrl);
  let captured;
  const token = `kiwe_task_test_${'a'.repeat(48)}`;
  const fakeFetch = async (url, options) => {
    captured = { url: String(url), options };
    return {
      ok: true,
      status: 200,
      headers: { get: () => null },
      text: async () => JSON.stringify({ ok: true, credentialKind: 'task_capsule' })
    };
  };
  const call = clientModule.createSiteGraphHttpClient({
    baseUrl: 'https://example.test/wp-json/dsa/v1/ai', token, fetchImpl: fakeFetch
  });
  const response = await call('kiwe_sitegraph_get_graph', { sampleLimit: 4 });
  check('MCP client sends bearer outside URL and preserves bounded query', response.ok && captured.url.endsWith('/site-graph?sampleLimit=4') && !captured.url.includes(token) && captured.options.headers.Authorization === `Bearer ${token}`);

  let permanentRejected = false;
  try { clientModule.validateTaskToken(`kiwe_ai_${'b'.repeat(48)}`); } catch { permanentRejected = true; }
  check('MCP adapter rejects permanent high-authority keys', permanentRejected);

  let insecureRejected = false;
  try { clientModule.normalizeSiteGraphBaseUrl('http://example.test/wp-json/dsa/v1/ai'); } catch { insecureRejected = true; }
  check('MCP adapter rejects non-local plaintext HTTP', insecureRejected);

  let redirectRejected = false;
  const redirectCall = clientModule.createSiteGraphHttpClient({
    baseUrl: 'https://example.test/wp-json/dsa/v1/ai', token,
    fetchImpl: async () => ({ ok: false, status: 302, headers: { get: () => null }, text: async () => '' })
  });
  try { await redirectCall('kiwe_sitegraph_status'); } catch (error) { redirectRejected = /refused a redirect/.test(String(error.message)); }
  check('MCP adapter refuses credential-bearing redirects', redirectRejected);

  for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
  const failed = checks.filter((item) => !item.pass);
  console.log(`\n${checks.length - failed.length}/${checks.length} SiteGraph adapter contracts passed.`);
  if (failed.length) process.exit(1);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
