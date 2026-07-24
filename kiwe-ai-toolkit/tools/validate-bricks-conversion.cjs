#!/usr/bin/env node
const path = require('path');
const { pathToFileURL } = require('url');

function usage() {
	console.log(`Validate a Kiwe Bricks conversion package.

Usage:
  node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs <handoff-or-conversion-json> [--site-graph site-graph.json] [--optional]

Looks for:
  bricks-conversion/kiwe-bricks-conversion.json
  bricks-conversion/BRICKS-CONVERSION-NOTES.md

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
	const siteGraphIndex = args.indexOf('--site-graph');
	const siteGraphPath = siteGraphIndex >= 0 ? args[siteGraphIndex + 1] : '';
	const modulePath = path.resolve(__dirname, '..', 'lib', 'bricks-conversion-validator.js');
	const mod = await import(pathToFileURL(modulePath).href);
	const result = mod.validateBricksConversion(target, {
		siteGraphPath,
		optional: args.includes('--optional')
	});
	console.log(JSON.stringify(result, null, 2));
	process.exitCode = result.ok ? 0 : 1;
}

main().catch((error) => {
	console.error(error && error.message ? error.message : String(error));
	process.exitCode = 1;
});
