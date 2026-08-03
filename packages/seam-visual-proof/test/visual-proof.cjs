#!/usr/bin/env node
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');
const { compileCaptureFile } = require('../../seam-compiler-core/tools/compile-capture.cjs');
const { compareCaptures } = require('../lib/compare.cjs');
const { buildRepairPlan } = require('../lib/repair.cjs');
const { attachProof, verifyIntegrity } = require('../lib/attach.cjs');
const { decodePng, encodePng } = require('../lib/png.cjs');
const { summarize } = require('../../seam-capture/tools/summarize-evidence.cjs');

const root = path.resolve(__dirname, '..', '..', '..');
const fixtureFile = path.join(root, 'packages/seam-compiler-core/fixtures/landing-hero/capture.json');
const profileFile = path.join(root, 'packages/seam-bricks-adapter/profiles/bricks-2.3.10.json');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'kiwe-seam-proof-'));
const checks = [];

function check(label, callback) {
	try { callback(); checks.push({ label, ok: true }); }
	catch (error) { checks.push({ label, ok: false, error: error instanceof Error ? error.stack || error.message : String(error) }); }
}

function clone(value) {
	return JSON.parse(JSON.stringify(value));
}

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function image(width, height, changed = 0) {
	const data = Buffer.alloc(width * height * 4);
	for (let pixel = 0; pixel < width * height; pixel += 1) {
		const offset = pixel * 4;
		data[offset] = pixel < changed ? 230 : 30;
		data[offset + 1] = pixel < changed ? 20 : 90;
		data[offset + 2] = pixel < changed ? 180 : 140;
		data[offset + 3] = 255;
	}
	return encodePng({ width, height, data });
}

function writeCapture(directory, capture, changedPixels = 0) {
	fs.mkdirSync(path.join(directory, 'screenshots'), { recursive: true });
	for (const viewport of capture.viewports) {
		const buffer = image(12, 8, changedPixels);
		const relative = `screenshots/${viewport.id}.png`;
		fs.writeFileSync(path.join(directory, relative), buffer);
		viewport.screenshot = { file: relative, bytes: buffer.length, sha256: sha256(buffer) };
	}
	fs.writeFileSync(path.join(directory, 'capture.json'), `${JSON.stringify(capture, null, 2)}\n`);
}

try {
	const original = JSON.parse(fs.readFileSync(fixtureFile, 'utf8'));
	const reference = clone(original);
	reference.stylesheets = [];
	reference.resources = [];
	const exactCandidate = clone(original);
	const failedCandidate = clone(original);
	for (const candidate of [exactCandidate, failedCandidate]) for (const node of candidate.nodes) node.attributes['data-seam-proof-node'] = node.id;
	failedCandidate.source.contentHash = `sha256:${'2'.repeat(64)}`;
	failedCandidate.nodes[0].observations[0].box.x += 9;
	failedCandidate.nodes[0].observations[0].computed.color = 'rgb(255, 0, 0)';
	failedCandidate.nodes[0].role = 'navigation';
	failedCandidate.diagnostics.push('candidate console error');
	const referenceDirectory = path.join(temp, 'reference');
	const exactDirectory = path.join(temp, 'exact');
	const failedDirectory = path.join(temp, 'failed');
	writeCapture(referenceDirectory, reference, 0);
	writeCapture(exactDirectory, exactCandidate, 0);
	writeCapture(failedDirectory, failedCandidate, 8);

	check('dependency-free PNG codec round-trips deterministic RGBA pixels', () => {
		const encoded = image(9, 5, 3);
		const decoded = decodePng(encoded);
		assert.equal(decoded.width, 9);
		assert.equal(decoded.height, 5);
		assert.equal(decoded.data.length, 9 * 5 * 4);
		assert.equal(sha256(encodePng(decoded)), sha256(encoded));
	});

	let exactReport;
	let exactRepair;
	const expectedNodeIds = original.nodes.map((node) => node.id);
	const exactProofDirectory = path.join(temp, 'exact-proof');
	check('identical capture matrices earn only matrix-scoped exact proof', () => {
		const output = exactProofDirectory;
		exactReport = compareCaptures({ referenceCapture: reference, candidateCapture: exactCandidate, referenceDirectory, candidateDirectory: exactDirectory, outputDirectory: output, expectedNodeIds });
		exactRepair = buildRepairPlan(exactReport);
		assert.equal(validateContract('visualProof', exactReport).ok, true);
		assert.equal(validateContract('repairPlan', exactRepair).ok, true);
		assert.equal(exactReport.status, 'passed');
		assert.equal(exactReport.grade, 'matrix-exact');
		assert.equal(exactReport.comparator.anchorBasis, 'bricks-plan');
		assert.equal(exactReport.summary.worstPixelMismatchRatio, 0);
		assert.equal(exactRepair.items.length, 0);
		assert.ok(exactReport.matrix.every((viewport) => fs.existsSync(path.join(output, viewport.screenshots.diff.file))));
		assert.ok(exactReport.limitations.some((item) => item.includes('No universal 100%')));
	});

	check('pixel, geometry, style, accessibility, and diagnostic regressions fail with bounded repair proposals', () => {
		const output = path.join(temp, 'failed-proof');
		const report = compareCaptures({ referenceCapture: reference, candidateCapture: failedCandidate, referenceDirectory, candidateDirectory: failedDirectory, outputDirectory: output, expectedNodeIds });
		const repair = buildRepairPlan(report);
		assert.equal(report.status, 'failed');
		assert.equal(report.grade, 'partial');
		assert.ok(report.summary.worstPixelMismatchRatio > report.thresholds.pixelMismatchRatio);
		assert.ok(report.summary.worstBoxDeltaPx > report.thresholds.maxBoxDeltaPx);
		assert.ok(report.summary.styleMismatchRatio > 0);
		assert.ok(report.summary.accessibilityMismatches > 0);
		assert.equal(report.summary.newDiagnostics, 1);
		for (const category of ['geometry', 'style', 'accessibility', 'diagnostic']) assert.ok(repair.items.some((record) => record.category === category));
		assert.ok(repair.items.every((record) => record.autoApplicable === false));
		assert.equal(repair.mutatesArtifacts, false);
	});

	check('missing viewport evidence blocks rather than downgrades proof', () => {
		const incomplete = clone(exactCandidate);
		incomplete.viewports.pop();
		for (const node of incomplete.nodes) node.observations = node.observations.filter((observation) => observation.viewportId !== reference.viewports.at(-1).id);
		const report = compareCaptures({ referenceCapture: reference, candidateCapture: incomplete, referenceDirectory, candidateDirectory: exactDirectory, outputDirectory: path.join(temp, 'blocked-proof'), expectedNodeIds });
		assert.equal(report.status, 'blocked');
		assert.equal(report.grade, 'unverified');
		assert.equal(report.summary.blocked, 1);
	});

	check('proof attachment validates package integrity and remains content-addressed', () => {
		const packageDirectory = path.join(temp, 'appsite');
		const compiled = compileCaptureFile(fixtureFile, profileFile, packageDirectory, 'Visual proof fixture');
		assert.ok(compiled.template.content.every((element) => element.settings._attributes.some((attribute) => attribute.name === 'data-seam-proof-node')));
		const attached = attachProof(packageDirectory, exactReport, exactRepair, exactProofDirectory);
		assert.equal(attached.artifacts.proof, 'proof/report.json');
		assert.ok(attached.integrity.files['proof/report.json']);
		assert.ok(attached.integrity.files['proof/repair-plan.json']);
		assert.ok(Object.keys(attached.integrity.files).some((relative) => relative.startsWith('proof/diff/')));
		verifyIntegrity(packageDirectory, attached);
		const storedReport = JSON.parse(fs.readFileSync(path.join(packageDirectory, 'proof/report.json'), 'utf8'));
		assert.equal(storedReport.repairPlan, 'proof/repair-plan.json');
		fs.copyFileSync(path.join(referenceDirectory, 'capture.json'), path.join(referenceDirectory, 'seam-capture.json'));
		const evidence = summarize(referenceDirectory, packageDirectory);
		assert.equal(evidence.validation.visualProof.status, 'passed');
		assert.equal(evidence.validation.visualProof.grade, 'matrix-exact');
		assert.throws(() => attachProof(packageDirectory, exactReport, exactRepair, exactProofDirectory), /already has proof/);
	});

	check('proof attachment refuses a tampered AppSite package', () => {
		const packageDirectory = path.join(temp, 'tampered-appsite');
		compileCaptureFile(fixtureFile, profileFile, packageDirectory, 'Tampered proof fixture');
		fs.appendFileSync(path.join(packageDirectory, 'bricks/templates/content.json'), ' ');
		assert.throws(() => attachProof(packageDirectory, exactReport, exactRepair, exactProofDirectory), /integrity mismatch/);
	});

	check('AppSite attachment rejects capture-only anchor proof', () => {
		const packageDirectory = path.join(temp, 'capture-anchor-appsite');
		compileCaptureFile(fixtureFile, profileFile, packageDirectory, 'Capture anchor proof fixture');
		const report = compareCaptures({ referenceCapture: reference, candidateCapture: reference, referenceDirectory, candidateDirectory: referenceDirectory, outputDirectory: path.join(temp, 'capture-anchor-proof') });
		assert.equal(report.comparator.anchorBasis, 'capture-all');
		assert.throws(() => attachProof(packageDirectory, report, buildRepairPlan(report), path.join(temp, 'capture-anchor-proof')), /Bricks-plan anchors/);
	});

	check('diff tampering aborts attachment before package mutation', () => {
		const packageDirectory = path.join(temp, 'diff-tamper-appsite');
		compileCaptureFile(fixtureFile, profileFile, packageDirectory, 'Diff tamper fixture');
		const proofDirectory = path.join(temp, 'diff-tamper-proof');
		const report = compareCaptures({ referenceCapture: reference, candidateCapture: exactCandidate, referenceDirectory, candidateDirectory: exactDirectory, outputDirectory: proofDirectory, expectedNodeIds });
		const repair = buildRepairPlan(report);
		fs.appendFileSync(path.join(proofDirectory, report.matrix[0].screenshots.diff.file), 'tampered');
		assert.throws(() => attachProof(packageDirectory, report, repair, proofDirectory), /diff integrity mismatch/i);
		const manifest = JSON.parse(fs.readFileSync(path.join(packageDirectory, 'appsite-package.json'), 'utf8'));
		assert.equal(manifest.artifacts.proof, null);
		assert.equal(fs.existsSync(path.join(packageDirectory, 'proof')), false);
	});
} finally {
	fs.rmSync(temp, { recursive: true, force: true });
}

for (const result of checks) console.log(`${result.ok ? 'PASS' : 'FAIL'} ${result.label}`);
const failed = checks.filter((result) => !result.ok);
if (failed.length) {
	for (const result of failed) console.error(`\n[${result.label}]\n${result.error}`);
	process.exit(1);
}
console.log(`\n${checks.length}/${checks.length} SEAM visual-proof contracts passed.`);
