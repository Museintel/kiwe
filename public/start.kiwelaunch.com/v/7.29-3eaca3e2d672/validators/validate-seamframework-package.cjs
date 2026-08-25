#!/usr/bin/env node
const path = require('node:path');
const { pathToFileURL } = require('node:url');

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/validate-seamframework-package.cjs <framework-handoff-dir>');
  process.exit(0);
}

const target = process.argv[2] || '.';
import(pathToFileURL(path.resolve(__dirname, '..', 'lib', 'seam-framework-package-validator.js')).href)
  .then(({ validateSeamFrameworkPackage }) => {
    const result = validateSeamFrameworkPackage(target);
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    process.exitCode = result.ok ? 0 : 1;
  })
  .catch((error) => {
    console.error(error && error.message ? error.message : String(error));
    process.exitCode = 1;
  });
