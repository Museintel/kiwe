#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const { attachProof } = require('../lib/attach.cjs');

function read(file) {
	return JSON.parse(fs.readFileSync(path.resolve(file), 'utf8'));
}

const [, , packageDirectory, reportFile, repairFile] = process.argv;
if (!packageDirectory || !reportFile) {
	console.error('Usage: node attach-proof.cjs <appsite-package-directory> <report.json> [repair-plan.json]');
	process.exit(2);
}
try {
	const result = attachProof(packageDirectory, read(reportFile), repairFile ? read(repairFile) : null, path.dirname(path.resolve(reportFile)));
	console.log(`Attached ${result.artifacts.proof} to ${path.resolve(packageDirectory, 'appsite-package.json')}`);
} catch (error) {
	console.error(error instanceof Error ? error.stack || error.message : String(error));
	process.exit(1);
}
