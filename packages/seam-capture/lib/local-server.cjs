const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const MIMES = {
	'.css': 'text/css; charset=utf-8', '.gif': 'image/gif', '.htm': 'text/html; charset=utf-8',
	'.html': 'text/html; charset=utf-8', '.ico': 'image/x-icon', '.jpeg': 'image/jpeg', '.jpg': 'image/jpeg',
	'.js': 'text/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
	'.mp4': 'video/mp4', '.png': 'image/png', '.svg': 'image/svg+xml', '.webm': 'video/webm', '.webp': 'image/webp',
	'.woff': 'font/woff', '.woff2': 'font/woff2'
};

function insideRoot(root, candidate) {
	const relative = path.relative(root, candidate);
	return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

async function startLocalServer(rootDirectory, entryFile) {
	const root = path.resolve(rootDirectory);
	const entry = path.resolve(entryFile);
	if (!insideRoot(root, entry) || !fs.statSync(entry).isFile()) throw new Error('Capture entry must be a file inside its bundle root.');
	const server = http.createServer((request, response) => {
		try {
			const url = new URL(request.url, 'http://127.0.0.1');
			const decoded = decodeURIComponent(url.pathname).replace(/^\/+/, '');
			let file = path.resolve(root, decoded || path.relative(root, entry));
			if (!insideRoot(root, file)) {
				response.writeHead(403).end('Forbidden');
				return;
			}
			if (fs.existsSync(file) && fs.statSync(file).isDirectory()) file = path.join(file, 'index.html');
			if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
				response.writeHead(404).end('Not found');
				return;
			}
			response.writeHead(200, {
				'Content-Type': MIMES[path.extname(file).toLowerCase()] || 'application/octet-stream',
				'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff'
			});
			fs.createReadStream(file).pipe(response);
		} catch (error) {
			response.writeHead(400).end(error instanceof Error ? error.message : 'Bad request');
		}
	});
	await new Promise((resolve, reject) => {
		server.once('error', reject);
		server.listen(0, '127.0.0.1', resolve);
	});
	const address = server.address();
	const relativeEntry = path.relative(root, entry).split(path.sep).map(encodeURIComponent).join('/');
	return {
		origin: `http://127.0.0.1:${address.port}`,
		url: `http://127.0.0.1:${address.port}/${relativeEntry}`,
		close: () => new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()))
	};
}

module.exports = { startLocalServer };
