import {performance} from 'node:perf_hooks';

// Public, read-only response classification. Never sends credentials or publishes a post.
const [site, ...extra] = process.argv.slice(2);
let origin;
try {
  const url = new URL(site);
  if (extra.length || url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') throw new Error();
  origin = url.origin;
} catch {
  console.error('Usage: node tools/maintenance/check-wordpress-rest.mjs https://example.com/');
  process.exit(2);
}
const proof = {schema:'kiwe-public-rest-response/v1', checkedAt:new Date().toISOString(), origin, requests:[], limitation:'Public GET/OPTIONS only. Does not prove authenticated publishing, editor permissions or the cause of an intermittent 503.'};
const safeHeader = value => value && /^[a-zA-Z0-9 .:;/_=+,-]{1,180}$/.test(value) ? value : null;
for (const [method, route] of [['OPTIONS','/wp-json/wp/v2/posts'], ['GET','/wp-json/wp/v2/posts?per_page=1&_fields=id,status']]) {
  const started = performance.now();
  const result = {method, route};
  try {
    const response = await fetch(origin + route, {method, redirect:'manual', signal:AbortSignal.timeout(15000), headers:{'User-Agent':'Kiwe-public-REST-check/1.0','Accept':'application/json'}});
    result.status = response.status;
    result.contentType = safeHeader(response.headers.get('content-type'));
    result.server = safeHeader(response.headers.get('server'));
    result.requestId = safeHeader(response.headers.get('cf-ray') || response.headers.get('x-hcdn-request-id'));
    result.retryAfter = safeHeader(response.headers.get('retry-after'));
    const reader = response.body?.getReader();
    const chunks = [];
    let bytes = 0;
    if (reader) {
      while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        bytes += value.length;
        if (bytes > 131072) { await reader.cancel(); result.bodyLimitExceeded = true; break; }
        chunks.push(Buffer.from(value));
      }
    }
    result.bytesRead = bytes;
    result.validJson = false;
    if (!result.bodyLimitExceeded) {
      try {
        const data = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        result.validJson = true;
        result.jsonShape = Array.isArray(data) ? 'array' : data === null ? 'null' : typeof data;
        if (data && !Array.isArray(data) && typeof data.code === 'string' && /^[a-zA-Z0-9_-]{1,100}$/.test(data.code)) result.errorCode = data.code;
      } catch { /* Record classification only; never print response bodies. */ }
    }
    result.success = response.ok && result.validJson && /\bapplication\/(?:[\w.+-]+\+)?json\b/i.test(result.contentType || '');
  } catch (error) {
    result.success = false;
    result.error = ['TimeoutError','AbortError','TypeError'].includes(error.name) ? error.name : 'RequestError';
  }
  result.milliseconds = Math.round(performance.now() - started);
  proof.requests.push(result);
}
proof.passed = proof.requests.every(result => result.success);
console.log(JSON.stringify(proof, null, 2));
process.exitCode = proof.passed ? 0 : 1;
