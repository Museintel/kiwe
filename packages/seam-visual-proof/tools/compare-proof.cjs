#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const { assertContract } = require('../../seam-compiler-core/lib/normalize-capture.cjs');
const { compareCaptures } = require('../lib/compare.cjs');
const { buildRepairPlan } = require('../lib/repair.cjs');

function read(file) {
	return JSON.parse(fs.readFileSync(path.resolve(file), 'utf8'));
}

function write(file, value) {
	fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`);
}

if (require.main === module) {
	const [, , referenceFile, candidateFile, outputInput, ...options] = process.argv;
	const planIndex = options.indexOf('--bricks-plan');
	const planFile = planIndex === -1 ? null : options[planIndex + 1];
	if (!referenceFile || !candidateFile || !outputInput) {
		console.error('Usage: node compare-proof.cjs <reference-capture.json> <candidate-capture.json> <empty-output-directory> [--bricks-plan bricks-plan.json]');
		process.exit(2);
	}
	try {
		const output = path.resolve(outputInput);
		if (fs.existsSync(output) && fs.readdirSync(output).length) throw new Error(`Visual proof output directory must be empty: ${output}`);
		fs.mkdirSync(output, { recursive: true });
		if (planIndex !== -1 && !planFile) throw new Error('--bricks-plan requires a JSON file path.');
		const bricksPlan = planFile ? read(planFile) : null;
		if (bricksPlan) assertContract('bricksPlan', bricksPlan);
		const report = compareCaptures({
			referenceCapture: read(referenceFile), candidateCapture: read(candidateFile),
			referenceDirectory: path.dirname(path.resolve(referenceFile)), candidateDirectory: path.dirname(path.resolve(candidateFile)), outputDirectory: output,
			expectedNodeIds: bricksPlan ? bricksPlan.elements.map((element) => element.provenance.pageNodeId) : null
		});
		const repairPlan = buildRepairPlan(report);
		report.repairPlan = 'repair-plan.json';
		assertContract('visualProof', report);
		write(path.join(output, 'report.json'), report);
		write(path.join(output, 'repair-plan.json'), repairPlan);
		console.log(`Visual proof ${report.status} (${report.grade}): ${report.summary.passed}/${report.summary.viewports} viewport states passed.`);
		console.log(`Report: ${path.join(output, 'report.json')}`);
		process.exitCode = report.status === 'passed' ? 0 : 1;
	} catch (error) {
		console.error(error instanceof Error ? error.stack || error.message : String(error));
		process.exit(1);
	}
}
