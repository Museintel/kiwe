#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

function usage() {
  console.log('Usage: node tools/validate-output.cjs <handoff-dir> --mode <website|theme|combined>');
}

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  usage();
  process.exit(0);
}

const target = process.argv[2] || '.';
const modeIndex = process.argv.indexOf('--mode');
const mode = modeIndex >= 0 ? process.argv[modeIndex + 1] : 'website';
const validModes = new Set(['website', 'theme', 'combined']);
if (!validModes.has(mode)) {
  console.error(`Unknown mode: ${mode}`);
  usage();
  process.exit(1);
}

const root = path.resolve(target);
const required = ['README.md'];
if (mode === 'website' || mode === 'combined') {
  required.push('website/bricks-paste.html', 'website/bricks-notes.md');
}
if (mode === 'theme') {
  required.push('appshell-theme/preview/index.html', 'appshell-theme/preview/PLACEHOLDERS.md');
}
if (mode === 'combined') {
  required.push('combined-preview/index.html');
}

const missing = required.filter((rel) => !fs.existsSync(path.join(root, rel)));
if (missing.length) {
  console.error(`Kiwe handoff validation failed for ${root}`);
  for (const rel of missing) console.error(`Missing: ${rel}`);
  process.exit(1);
}

if (mode === 'theme' || mode === 'combined') {
  const importRoot = path.join(root, 'appshell-theme', 'import');
  if (fs.existsSync(importRoot)) {
    const themeDirs = fs.readdirSync(importRoot, { withFileTypes: true }).filter((entry) => entry.isDirectory());
    for (const entry of themeDirs) {
      for (const rel of [
        `appshell-theme/import/${entry.name}/theme.json`,
        `appshell-theme/import/${entry.name}/css/theme.css`,
        `appshell-theme/import/${entry.name}/theme-package.json`
      ]) {
        if (!fs.existsSync(path.join(root, rel))) {
          console.error(`Kiwe handoff validation failed for ${root}`);
          console.error(`Missing: ${rel}`);
          process.exit(1);
        }
      }
    }
  }
}

console.log(`Kiwe handoff OK: ${root} (${mode})`);
