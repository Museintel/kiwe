const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const packageRoot = path.join(root, 'wp-content', 'mu-plugins', 'dsa');
const output = path.join(packageRoot, 'package-manifest.json');

function walk(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) return walk(absolute);
    return entry.isFile() ? [absolute] : [];
  });
}

const source = fs.readFileSync(path.join(packageRoot, 'dsa.php'), 'utf8');
const version = source.match(/define\(\s*'DSA_VERSION',\s*'([^']+)'\s*\)/)?.[1];
if (!version) throw new Error('Unable to read DSA_VERSION from package entry point.');

const files = {};
for (const absolute of walk(packageRoot).sort()) {
  if (absolute === output) continue;
  const relative = path.relative(packageRoot, absolute).split(path.sep).join('/');
  const body = fs.readFileSync(absolute);
  files[relative] = {
    bytes: body.length,
    sha256: crypto.createHash('sha256').update(body).digest('hex'),
  };
}

const manifest = {
  schema: 1,
  version,
  algorithm: 'sha256',
  file_count: Object.keys(files).length,
  files,
};

fs.writeFileSync(output, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
process.stdout.write(`Wrote ${path.relative(root, output)} with ${manifest.file_count} files.\n`);
