'use strict';
const http = require('node:http');
const path = require('node:path');
const { gzipSync } = require('node:zlib');
const { build } = require('esbuild');
const port = Number(process.env.KIWE_PREVIEW_PORT || 8774);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw Error('Invalid preview port');
async function start() {
  const result = await build({ absWorkingDir: __dirname, entryPoints: ['app.jsx'], bundle: true, splitting: true, format: 'esm', platform: 'browser', target: ['es2022'], minify: true, write: false, outdir: 'build', define: { 'process.env.NODE_ENV': '"production"' }, metafile: true });
  const assets = new Map(result.outputFiles.map(file => ['/' + path.basename(file.path), file.contents]));
  const html = Buffer.from('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kiwe · App workspace direction</title><link rel="stylesheet" href="/app.css"></head><body><div id="root"></div><script type="module" src="/app.js"></script></body></html>');
  const server = http.createServer((req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    if (req.method !== 'GET' && req.method !== 'HEAD') { res.writeHead(405); res.end(); return; }
    const url = new URL(req.url, 'http://127.0.0.1');
    const body = url.pathname === '/' ? html : assets.get(url.pathname);
    if (!body) { res.writeHead(404); res.end('Not found'); return; }
    res.setHeader('Content-Type', url.pathname === '/' ? 'text/html; charset=utf-8' : url.pathname.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8');
    res.end(req.method === 'HEAD' ? undefined : body);
  });
  server.on('error', error => { console.error(error.message); process.exitCode = 1; });
  server.listen(port, '127.0.0.1', () => console.log(JSON.stringify({ url: `http://127.0.0.1:${port}/`, sampleOnly: true, assets: [...assets].map(([name, data]) => ({ name, bytes: data.length, gzipBytes: gzipSync(data).length })) })));
}
start().catch(error => { console.error(error.message); process.exitCode = 1; });
