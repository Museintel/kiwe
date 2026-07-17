#!/usr/bin/env node

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/prepare-apply-plan.cjs <handoff-or-bindings-dir-or-json> --site-graph path/to/site-graph.json [--write]');
  process.exit(0);
}

const path = require('node:path');
const { pathToFileURL } = require('node:url');

const target = process.argv[2] || '.';
const siteGraphIndex = process.argv.indexOf('--site-graph');
const siteGraphPath = siteGraphIndex >= 0 ? process.argv[siteGraphIndex + 1] : '';
const write = process.argv.includes('--write');

import(pathToFileURL(path.resolve(__dirname, '..', 'lib', 'apply-planner.js')).href)
  .then(({ prepareApplyPlan }) => {
    const result = prepareApplyPlan(target, { siteGraphPath, write });
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    process.exitCode = result.ok ? 0 : 1;
  })
  .catch((error) => {
    console.error(error && error.message ? error.message : String(error));
    process.exitCode = 1;
  });
