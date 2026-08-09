#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const { CANONICAL_VIEWPORTS, capturePage } = require('../lib/capture.cjs');

function parseArguments(argv) {
	const positional = [];
	const flags = new Set();
	let widths = null;
	for (let index = 0; index < argv.length; index += 1) {
		if (argv[index] === '--viewports') widths = argv[++index].split(',').map(Number);
		else if (argv[index].startsWith('--')) flags.add(argv[index]);
		else positional.push(argv[index]);
	}
	return { positional, flags, widths };
}

async function main() {
	const { positional: [input, output], flags, widths } = parseArguments(process.argv.slice(2));
	if (!input || !output) {
		console.error('Usage: node capture-page.cjs <html-file|url> <output-directory> [--viewports 320,478,768,991,1280,1440] [--allow-remote-assets] [--no-scripts] [--proof-mode]');
		process.exit(2);
	}
	const viewports = widths ? widths.map((width) => {
		const canonical = CANONICAL_VIEWPORTS.find((item) => item.width === width);
		return canonical ? { ...canonical } : { id: `viewport-${width}`, width, height: Math.max(720, Math.round(width * 0.75)), theme: 'light', state: 'default' };
	}) : CANONICAL_VIEWPORTS.map((item) => ({ ...item }));
	const capture = await capturePage({
		input, outputDirectory: output, viewports,
		allowRemoteAssets: flags.has('--allow-remote-assets'), scriptsExecuted: !flags.has('--no-scripts'),
		deterministicClock: !flags.has('--no-deterministic-clock'), proofMode: flags.has('--proof-mode')
	});
	const destination = path.resolve(output, 'seam-capture.json');
	fs.writeFileSync(destination, `${JSON.stringify(capture, null, 2)}\n`);
	console.log(`Captured ${capture.nodes.length} DOM nodes across ${capture.viewports.length} viewports.`);
	console.log(`Evidence: ${destination}`);
}

main().catch((error) => {
	console.error(error instanceof Error ? error.stack || error.message : String(error));
	process.exit(1);
});
