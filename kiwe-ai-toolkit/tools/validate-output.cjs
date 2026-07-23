#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

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

if (mode === 'combined') {
  const privatePreviewClasses = [
    'dsa-screen-head',
    'dsa-screen-body',
    'dsa-profile-card',
    'dsa-score-card',
    'dsa-links-identity',
    'dsa-account-rows',
    'dsa-link-list',
    'dsa-install-steps',
    'dsa-game-frame',
    'kiwe-preview-panel',
    'kiwe-preview-panel-heading',
    'kiwe-preview-alpha',
    'kiwe-preview-fbt',
    'kiwe-preview-score',
    'kiwe-preview-empty',
    'kiwe-preview-muted'
  ];
  const combinedFiles = [
    'combined-preview/index.html',
    'combined-preview/assets/combined-preview.css'
  ].filter((rel) => fs.existsSync(path.join(root, rel)));
  const leaked = [];
  for (const rel of combinedFiles) {
    const text = fs.readFileSync(path.join(root, rel), 'utf8');
    for (const name of privatePreviewClasses) {
      const pattern = new RegExp(`(^|[^-_a-zA-Z0-9])${name}([^-_a-zA-Z0-9]|$)`);
      if (pattern.test(text)) leaked.push(`${rel}:${name}`);
    }
  }
  if (leaked.length) {
    console.error(`Kiwe handoff validation failed for ${root}`);
    console.error('Primary combined preview uses private AppShell fixture structure that Kiwe core does not render live:');
    for (const item of leaked) console.error(`Private fixture: ${item}`);
    console.error('Use documented live DSA roots/internals in the primary combined preview. Keep custom mock wrappers only in optional technical fixtures, clearly labelled preview-only.');
    process.exit(1);
  }
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

const frameworkProfilePresent = fs.existsSync(path.join(root, 'framework', 'kiwe-framework-profile.json')) || fs.existsSync(path.join(root, 'kiwe-framework-profile.json'));
if (frameworkProfilePresent) {
  try {
    execFileSync(process.execPath, [path.join(__dirname, 'validate-framework-profile.cjs'), root], {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe']
    });
  } catch (error) {
    console.error(`Kiwe handoff validation failed for ${root}`);
    const stdout = error && error.stdout ? String(error.stdout).trim() : '';
    const stderr = error && error.stderr ? String(error.stderr).trim() : '';
    if (stdout) console.error(stdout);
    if (stderr) console.error(stderr);
    process.exit(1);
  }
}

console.log(`Kiwe handoff OK: ${root} (${mode})`);
