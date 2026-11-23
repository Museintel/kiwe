import { lookup } from 'node:dns/promises';
import { request } from 'node:https';
import { BlockList, isIP } from 'node:net';

const blocked = new BlockList();
for (const [address, bits] of [
  ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
  ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
  ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
]) blocked.addSubnet(address, bits);
for (const [address, bits] of [['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20]]) {
  blocked.addSubnet(address, bits, 'ipv6');
}
const globalIpv6 = new BlockList();
globalIpv6.addSubnet('2000::', 3, 'ipv6');

export function publicAddress(address) {
  const family = isIP(address);
  if (family === 4) return !blocked.check(address);
  return family === 6 && globalIpv6.check(address, 'ipv6') && !blocked.check(address, 'ipv6');
}

export function siteUrl(origin, value) {
  const url = new URL(value);
  if (url.protocol !== 'https:' || url.origin !== origin || url.username || url.password || url.hash
      || isIP(url.hostname) || url.href.length > 2048) throw new Error('Invalid website endpoint.');
  return url;
}

const maxResponse = 96 * 1024;

/** Use only public DNS results and keep the checked address pinned through the TLS request. */
export async function requestSite(origin, target, { method = 'GET', headers = {}, body, timeout = 7000 } = {}) {
  const url = siteUrl(origin, target);
  const addresses = await lookup(url.hostname, { all: true });
  if (!addresses.length || addresses.some(({ address }) => !publicAddress(address))) {
    throw new Error('The website must resolve to public addresses.');
  }
  const [chosen] = addresses;

  return new Promise((resolve, reject) => {
    const req = request(url, {
      method,
      agent: false,
      lookup: (_host, options, callback) => options.all
        ? callback(null, [chosen])
        : callback(null, chosen.address, chosen.family),
      signal: AbortSignal.timeout(timeout),
      headers: {
        Accept: 'application/json',
        'Accept-Encoding': 'identity',
        ...headers,
        ...(body ? { 'Content-Length': Buffer.byteLength(body) } : {}),
      },
    }, (res) => {
      if (res.statusCode < 200 || res.statusCode > 299) {
        res.resume();
        reject(new Error('The website rejected the request.'));
        return;
      }
      const bytes = [];
      let size = 0;
      res.on('data', (chunk) => {
        size += chunk.length;
        if (size > maxResponse) {
          res.destroy(new Error('The website response is too large.'));
          return;
        }
        bytes.push(chunk);
      });
      res.on('error', reject);
      res.on('end', () => resolve({ body: Buffer.concat(bytes).toString('utf8'), type: res.headers['content-type'] ?? '' }));
    });
    req.on('error', reject);
    req.end(body);
  });
}
