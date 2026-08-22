#!/usr/bin/env node

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/validate-bindings.cjs <handoff-or-bindings-dir-or-json> [--site-graph path/to/site-graph.json] [--optional]');
  process.exit(0);
}

const path = require('node:path');
const { pathToFileURL } = require('node:url');

const target = process.argv[2] || '.';
const siteGraphIndex = process.argv.indexOf('--site-graph');
const siteGraphPath = siteGraphIndex >= 0 ? process.argv[siteGraphIndex + 1] : '';
const optional = process.argv.includes('--optional');

import(pathToFileURL(path.resolve(__dirname, '..', 'lib', 'binding-validator.js')).href)
  .then(({ validateBindings }) => {
    const result = validateBindings(target, { siteGraphPath, optional });
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    process.exitCode = result.ok ? 0 : 1;
  })
  .catch((error) => {
    console.error(error && error.message ? error.message : String(error));
    process.exitCode = 1;
  });
