#!/usr/bin/env node
const { mergeCaptures } = require('../lib/merge.cjs');

const [, , outputDirectory, ...captureFiles] = process.argv;
if (!outputDirectory || captureFiles.length < 2) {
	console.error('Usage: node merge-captures.cjs <output-directory> <capture-1.json> <capture-2.json> [...]');
	process.exit(2);
}

try {
	const result = mergeCaptures({ captureFiles, outputDirectory });
	console.log(`Merged ${captureFiles.length} captures into ${result.capture.viewports.length} viewport states.`);
	console.log(`Evidence: ${result.destination}`);
} catch (error) {
	console.error(error instanceof Error ? error.stack || error.message : String(error));
	process.exit(1);
}
