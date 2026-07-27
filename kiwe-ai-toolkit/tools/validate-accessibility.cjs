#!/usr/bin/env node
const path = require('path');
const { pathToFileURL } = require('url');

function usage() {
	console.log(`Validate a Kiwe accessibility lane.

Usage:
  node kiwe-ai-toolkit/tools/validate-accessibility.cjs <handoff-or-accessibility-dir> [--optional]

Looks for:
  accessibility/kiwe-accessibility-plan.json
  accessibility/ACCESSIBILITY-NOTES.md

Checks:
  - light and dark mode proof
  - literal text/background contrast pairs
  - Kiwe/Seam token pairing
  - Bricks theme-style/color-palette alignment hints

The validator is deterministic and non-mutating. It does not write to WordPress or Bricks.
`);
}

async function main() {
	const args = process.argv.slice(2);
	if (args.includes('--help') || args.includes('-h')) {
		usage();
		return;
	}
	const target = args[0] && !args[0].startsWith('--') ? args[0] : '.';
	const modulePath = path.resolve(__dirname, '..', 'lib', 'accessibility-validator.js');
	const mod = await import(pathToFileURL(modulePath).href);
	const result = mod.validateAccessibility(target, {
		optional: args.includes('--optional')
	});
	console.log(JSON.stringify(result, null, 2));
	process.exitCode = result.ok ? 0 : 1;
}

main().catch((error) => {
	console.error(error && error.message ? error.message : String(error));
	process.exitCode = 1;
});
