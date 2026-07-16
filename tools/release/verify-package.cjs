const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const packageRoot = path.join(root, 'wp-content', 'mu-plugins', 'dsa');
const manifestPath = path.join(packageRoot, 'package-manifest.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const failures = [];

function version(file, pattern, label) {
  const match = fs.readFileSync(file, 'utf8').match(pattern);
  if (!match) failures.push(`${label}: version not found`);
  return match?.[1] || '';
}

const packageVersion = version(path.join(packageRoot, 'dsa.php'), /define\(\s*'DSA_VERSION',\s*'([^']+)'\s*\)/, 'package');
const loaderVersion = version(path.join(root, 'wp-content', 'mu-plugins', 'dsa.php'), /define\(\s*'KIWE_MU_LOADER_VERSION',\s*'([^']+)'\s*\)/, 'loader');
if (manifest.version !== packageVersion || loaderVersion !== packageVersion) failures.push('loader, package, and manifest versions must match');

for (const [relative, expected] of Object.entries(manifest.files || {})) {
  if (relative.includes('..') || path.isAbsolute(relative)) {
    failures.push(`unsafe manifest path: ${relative}`);
    continue;
  }
  const absolute = path.join(packageRoot, relative);
  if (!fs.existsSync(absolute)) {
    failures.push(`missing: ${relative}`);
    continue;
  }
  const body = fs.readFileSync(absolute);
  const hash = crypto.createHash('sha256').update(body).digest('hex');
  if (body.length !== expected.bytes || hash !== expected.sha256) failures.push(`changed: ${relative}`);
}

const actual = [];
function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(absolute);
    else if (entry.isFile() && absolute !== manifestPath) actual.push(path.relative(packageRoot, absolute).split(path.sep).join('/'));
  }
}
walk(packageRoot);
for (const relative of actual) if (!manifest.files[relative]) failures.push(`unlisted: ${relative}`);
if (manifest.file_count !== Object.keys(manifest.files || {}).length) failures.push('file_count does not match inventory');

if (failures.length) {
  process.stderr.write(`${failures.join('\n')}\n`);
  process.exit(1);
}
process.stdout.write(`Package ${manifest.version}: ${manifest.file_count} files verified.\n`);
