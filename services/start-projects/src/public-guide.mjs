import { createReadStream } from 'node:fs';
import { realpath, stat } from 'node:fs/promises';
import path from 'node:path';
import { pipeline } from 'node:stream/promises';

const types = new Map([
  ['.html', 'text/html; charset=utf-8'],
  ['.md', 'text/markdown; charset=utf-8'],
  ['.txt', 'text/plain; charset=utf-8'],
  ['.json', 'application/json; charset=utf-8'],
  ['.css', 'text/css; charset=utf-8'],
  ['.js', 'text/javascript; charset=utf-8'],
  ['.xml', 'application/xml; charset=utf-8'],
  ['.svg', 'image/svg+xml'],
  ['.png', 'image/png'],
  ['.webp', 'image/webp'],
  ['.zip', 'application/zip'],
]);

export async function createPublicGuide(directory) {
  const root = await realpath(directory);
  const index = await stat(path.join(root, 'index.html'));
  if (!index.isFile()) throw new Error('The public Kiwe guide must have an index.');

  return async (request, response, pathname) => {
    if (!['GET', 'HEAD'].includes(request.method) || /^\/projects(?:\/|$)/.test(pathname)) return false;
    const parts = pathname.split('/').filter(Boolean);
    if (parts.some(part => !/^[a-z0-9][a-z0-9._-]*$/i.test(part))) return false;
    if (!parts.length || pathname.endsWith('/') || !types.has(path.extname(parts.at(-1)).toLowerCase())) parts.push('index.html');
    const type = types.get(path.extname(parts.at(-1)).toLowerCase());
    if (!type) return false;
    const target = path.join(root, ...parts);
    try {
      if (await realpath(target) !== target) return false;
      const info = await stat(target);
      if (!info.isFile()) return false;
      response.setHeader('Cache-Control', 'public, max-age=300');
      response.setHeader('Referrer-Policy', 'no-referrer');
      response.setHeader('Access-Control-Allow-Origin', '*');
      response.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
      response.writeHead(200, { 'Content-Type': type, 'Content-Length': info.size });
      if (request.method === 'HEAD') response.end();
      else await pipeline(createReadStream(target), response);
      return true;
    } catch (error) {
      if (!response.headersSent && ['ENOENT', 'ENOTDIR'].includes(error.code)) return false;
      throw error;
    }
  };
}
