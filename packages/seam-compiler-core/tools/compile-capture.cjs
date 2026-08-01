#!/usr/bin/env node
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { normalizeCapture, assertContract } = require('../lib/normalize-capture.cjs');
const { buildFrameworkProfile, KIWE_VERSION } = require('../lib/framework-profile.cjs');
const { compileBricksPlan } = require('../../seam-bricks-adapter/lib/compile-plan.cjs');
const { serializeBricksTemplate } = require('../../seam-bricks-adapter/lib/serialize-template.cjs');

function hash(content) {
	return crypto.createHash('sha256').update(content).digest('hex');
}

function safeName(value) {
	return String(value || 'seam-page').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'seam-page';
}

function writeJson(root, relative, value, integrity) {
	const serialized = `${JSON.stringify(value, null, 2)}\n`;
	const destination = path.join(root, relative);
	fs.mkdirSync(path.dirname(destination), { recursive: true });
	fs.writeFileSync(destination, serialized);
	if (integrity) integrity[relative.replace(/\\/g, '/')] = hash(serialized);
}

function compileCaptureFile(captureFile, capabilityFile, outputDirectory, title = 'SEAM compiled page') {
	const capture = JSON.parse(fs.readFileSync(path.resolve(captureFile), 'utf8'));
	const capabilityProfile = JSON.parse(fs.readFileSync(path.resolve(capabilityFile), 'utf8'));
	const output = path.resolve(outputDirectory);
	const { pageIr, behaviorIr, assetManifest } = normalizeCapture(capture);
	const bricksPlan = compileBricksPlan(pageIr, capabilityProfile);
	const template = serializeBricksTemplate(bricksPlan, title);
	const frameworkProfile = buildFrameworkProfile(title);
	const geometry = {
		schema: 'seam.geometry-evidence.v1', solver: { name: 'SEAM Page Geometry Solver', version: '0.1.0', status: 'm1-constraint-scaffold' },
		sourceHash: pageIr.sourceHash,
		nodes: pageIr.nodes.map((node) => ({
			id: node.id, display: node.layout.display, direction: node.layout.direction, wrap: node.layout.wrap,
			gap: node.layout.gap, widthMode: node.layout.widthMode, viewportIds: node.provenance.viewportIds
		})),
		limitations: ['M1 records normalized layout evidence; multi-viewport responsive constraint solving begins in M2.']
	};
	const integrity = {};

	writeJson(output, 'capture/seam-capture.json', capture, integrity);
	writeJson(output, 'ir/page-ir.json', pageIr, integrity);
	writeJson(output, 'ir/behavior-ir.json', behaviorIr, integrity);
	writeJson(output, 'assets/asset-manifest.json', assetManifest, integrity);
	writeJson(output, 'framework/kiwe-framework-profile.json', frameworkProfile, integrity);
	writeJson(output, 'geometry/page-geometry.json', geometry, integrity);
	writeJson(output, 'bricks/bricks-plan.json', bricksPlan, integrity);
	writeJson(output, 'bricks/templates/content.json', template, integrity);

	const appsitePackage = {
		schema: 'kiwe.appsite-package.v1',
		packageId: safeName(title),
		sourceHash: capture.source.contentHash,
		compiler: { name: 'SEAM Compiler', version: '0.1.0', deterministic: true, aiDirectJson: false },
		contracts: ['seam.capture.v1', 'seam.page-ir.v1', 'seam.behavior-ir.v1', 'seam.asset-manifest.v1', 'seam.bricks-plan.v1', 'kiwe.appsite-package.v1'],
		artifacts: {
			capture: 'capture/seam-capture.json', pageIr: 'ir/page-ir.json', behaviorIr: 'ir/behavior-ir.json',
			assets: 'assets/asset-manifest.json', frameworkProfile: 'framework/kiwe-framework-profile.json',
			geometry: 'geometry/page-geometry.json', bindings: null, bricksPlan: 'bricks/bricks-plan.json',
			bricksTemplates: ['bricks/templates/content.json'], proof: null
		},
		compatibility: { kiwe: `^${KIWE_VERSION}`, bricks: capabilityProfile.bricksVersion, wordpress: '7.x', php: '8.2-8.4' },
		integrity: { algorithm: 'sha256', files: integrity }
	};
	assertContract('appsitePackage', appsitePackage);
	writeJson(output, 'appsite-package.json', appsitePackage);
	return { appsitePackage, pageIr, behaviorIr, assetManifest, frameworkProfile, geometry, bricksPlan, template };
}

if (require.main === module) {
	const [, , capture, capability, output, ...titleParts] = process.argv;
	if (!capture || !capability || !output) {
		console.error('Usage: node compile-capture.cjs <capture.json> <bricks-capability.json> <output-directory> [title]');
		process.exit(2);
	}
	try {
		const result = compileCaptureFile(capture, capability, output, titleParts.join(' ') || 'SEAM compiled page');
		console.log(`Compiled ${result.bricksPlan.elements.length} native Bricks elements with ${result.bricksPlan.metrics.nativeCoverage}% native ownership.`);
		console.log(`AppSite package: ${path.resolve(output, 'appsite-package.json')}`);
	} catch (error) {
		console.error(error instanceof Error ? error.stack || error.message : String(error));
		process.exit(1);
	}
}

module.exports = { compileCaptureFile };
