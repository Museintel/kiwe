'use strict';
// Inventory declarations, not customer data. Dynamic controls still require service review.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const relative = [
  'wp-content/mu-plugins/dsa/includes/Onboarding/Onboarding_Service.php',
  'wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Refinement_Service.php',
];
const declarations = new Map();
const sources = relative.map(file => {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  for (const match of source.matchAll(/context(?:\[[A-Za-z0-9_-]*\])+/g)) {
    const field = match[0];
    if (!declarations.has(field)) declarations.set(field, { field, group: field.match(/^context\[([^\]]+)\]/)?.[1], source: file, line: source.slice(0, match.index).split('\n').length });
  }
  return { file, sha256: crypto.createHash('sha256').update(source).digest('hex') };
});
const report = { schema: 'kiwe.context-ui-declarations.v1', scope: 'Static context form declarations only; not complete native/plugin field coverage or connected React controls.', sources, count: declarations.size, groups: [...new Set([...declarations.values()].map(item => item.group))].sort(), declarations: [...declarations.values()].sort((a, b) => a.field.localeCompare(b.field)) };
const output = process.argv[2];
if (output) { fs.writeFileSync(path.resolve(output), JSON.stringify(report, null, 2) + '\n'); console.log(JSON.stringify({ count: report.count, groups: report.groups, output: path.resolve(output) })); }
else console.log(JSON.stringify(report, null, 2));
