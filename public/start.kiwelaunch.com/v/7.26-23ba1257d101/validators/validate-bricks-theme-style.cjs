#!/usr/bin/env node
import('../lib/bricks-theme-style-validator.js').then(({ validateBricksThemeStyle }) => {
  const args = process.argv.slice(2);
  if (args.includes('--help') || args.includes('-h')) {
    process.stdout.write(`Usage: node tools/validate-bricks-theme-style.cjs <bricks-theme-style-json-or-dir> [--optional]

Validates Kiwe's lean /brickstheme output:

  bricks-theme-style.json

This is a native Bricks Theme Styles import JSON with root shape:

  { "label": "...", "settings": { ... } }

Optional root "id" is allowed for compatibility with Bricks-exported theme styles.

It is not a Kiwe Framework profile, not a Bricks template/page JSON, and not a DSA/AppShell theme package.
`);
    return;
  }

  const target = args.find((arg) => !arg.startsWith('--')) || '.';
  const result = validateBricksThemeStyle(target, { optional: args.includes('--optional') });
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  process.exitCode = result.ok ? 0 : 1;
}).catch((error) => {
  console.error(error && error.message ? error.message : String(error));
  process.exitCode = 1;
});
