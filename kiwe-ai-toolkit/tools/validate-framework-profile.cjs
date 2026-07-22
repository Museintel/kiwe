#!/usr/bin/env node

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/validate-framework-profile.cjs <profile-json-or-handoff-dir> [--optional]');
  process.exit(0);
}

const path = require('node:path');
const { pathToFileURL } = require('node:url');

const target = process.argv[2] || '.';
const optional = process.argv.includes('--optional');

import(pathToFileURL(path.resolve(__dirname, '..', 'lib', 'framework-profile-validator.js')).href)
  .then(({ validateFrameworkProfile }) => {
    const result = validateFrameworkProfile(target, { optional });
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    process.exitCode = result.ok ? 0 : 1;
  })
  .catch((error) => {
    console.error(error && error.message ? error.message : String(error));
    process.exitCode = 1;
  });
