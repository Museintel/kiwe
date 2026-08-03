#!/usr/bin/env node
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { normalizeCapture, assertContract } = require('../lib/normalize-capture.cjs');
const { buildFrameworkProfile, KIWE_VERSION } = require('../lib/framework-profile.cjs');
const { buildAssetImportPlan, buildBindingPlan, buildDeploymentPlan, sanitizeSiteGraph } = require('../lib/sitegraph-deployment.cjs');
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

function compileCaptureFile(captureFile, capabilityFile, outputDirectory, title = 'SEAM compiled page', siteGraphFile = null) {
	const capture = JSON.parse(fs.readFileSync(path.resolve(captureFile), 'utf8'));
	const capabilityProfile = JSON.parse(fs.readFileSync(path.resolve(capabilityFile), 'utf8'));
	const output = path.resolve(outputDirectory);
	if (fs.existsSync(output) && fs.readdirSync(output).length > 0) {
		const error = new Error(`SEAM output directory must be empty: ${output}`);
		error.code = 'SEAM_OUTPUT_NOT_EMPTY';
		throw error;
	}
	const { pageIr, behaviorIr, assetManifest, geometry } = normalizeCapture(capture);
	const assetImportPlan = buildAssetImportPlan(assetManifest);
	const bricksPlan = compileBricksPlan(pageIr, capabilityProfile);
	const template = serializeBricksTemplate(bricksPlan, title);
	const frameworkProfile = buildFrameworkProfile(title, bricksPlan.variables);
	const integrity = {};

	writeJson(output, 'capture/seam-capture.json', capture, integrity);
	writeJson(output, 'ir/page-ir.json', pageIr, integrity);
	writeJson(output, 'ir/behavior-ir.json', behaviorIr, integrity);
	writeJson(output, 'assets/asset-manifest.json', assetManifest, integrity);
	writeJson(output, 'assets/import-plan.json', assetImportPlan, integrity);
	writeJson(output, 'framework/kiwe-framework-profile.json', frameworkProfile, integrity);
	writeJson(output, 'geometry/page-geometry.json', geometry, integrity);
	writeJson(output, 'bricks/bricks-plan.json', bricksPlan, integrity);
	writeJson(output, 'bricks/templates/content.json', template, integrity);

	let siteGraphSnapshot = null;
	let bindings = null;
	let deploymentPlan = null;
	if (siteGraphFile) {
		const siteGraph = JSON.parse(fs.readFileSync(path.resolve(siteGraphFile), 'utf8'));
		siteGraphSnapshot = sanitizeSiteGraph(siteGraph);
		bindings = buildBindingPlan(capture, siteGraphSnapshot);
		deploymentPlan = buildDeploymentPlan(capture.source.contentHash, siteGraphSnapshot, assetImportPlan, bindings);
		writeJson(output, 'sitegraph/snapshot.json', siteGraphSnapshot, integrity);
		writeJson(output, 'bindings/kiwe-bindings.json', bindings, integrity);
		writeJson(output, 'deployment/dry-run-plan.json', deploymentPlan, integrity);
	}

	const appsitePackage = {
		schema: 'kiwe.appsite-package.v1',
		packageId: safeName(title),
		sourceHash: capture.source.contentHash,
		compiler: { name: 'SEAM Compiler', version: '0.5.0', deterministic: true, aiDirectJson: false },
		contracts: ['seam.capture.v1', 'seam.page-ir.v1', 'seam.behavior-ir.v1', 'seam.asset-manifest.v1', 'seam.asset-import-plan.v1', 'seam.sitegraph-snapshot.v1', 'kiwe.bricks-bindings.v1', 'seam.deployment-plan.v1', 'seam.visual-proof.v1', 'seam.repair-plan.v1', 'seam.geometry.v1', 'seam.bricks-plan.v1', 'kiwe.appsite-package.v1'],
		artifacts: {
			capture: 'capture/seam-capture.json', pageIr: 'ir/page-ir.json', behaviorIr: 'ir/behavior-ir.json',
			assets: 'assets/asset-manifest.json', assetImportPlan: 'assets/import-plan.json', siteGraph: siteGraphSnapshot ? 'sitegraph/snapshot.json' : null,
			frameworkProfile: 'framework/kiwe-framework-profile.json', geometry: 'geometry/page-geometry.json',
			bindings: bindings ? 'bindings/kiwe-bindings.json' : null, deploymentPlan: deploymentPlan ? 'deployment/dry-run-plan.json' : null, bricksPlan: 'bricks/bricks-plan.json',
			bricksTemplates: ['bricks/templates/content.json'], proof: null
		},
		compatibility: { kiwe: `^${KIWE_VERSION}`, bricks: capabilityProfile.bricksVersion, wordpress: '7.x', php: '8.2-8.4' },
		integrity: { algorithm: 'sha256', files: integrity }
	};
	assertContract('appsitePackage', appsitePackage);
	writeJson(output, 'appsite-package.json', appsitePackage);
	return { appsitePackage, pageIr, behaviorIr, assetManifest, assetImportPlan, siteGraphSnapshot, bindings, deploymentPlan, frameworkProfile, geometry, bricksPlan, template };
}

if (require.main === module) {
	const [, , capture, capability, output, ...argumentsAfterOutput] = process.argv;
	const siteGraphFlag = argumentsAfterOutput.indexOf('--site-graph');
	let siteGraph = null;
	if (siteGraphFlag !== -1) {
		siteGraph = argumentsAfterOutput[siteGraphFlag + 1];
		if (!siteGraph || siteGraph.startsWith('--')) {
			console.error('--site-graph requires a JSON file path.');
			process.exit(2);
		}
		argumentsAfterOutput.splice(siteGraphFlag, 2);
	}
	if (!capture || !capability || !output) {
		console.error('Usage: node compile-capture.cjs <capture.json> <bricks-capability.json> <output-directory> [title] [--site-graph <site-graph.json>]');
		process.exit(2);
	}
	try {
		const result = compileCaptureFile(capture, capability, output, argumentsAfterOutput.join(' ') || 'SEAM compiled page', siteGraph);
		console.log(`Compiled ${result.bricksPlan.elements.length} Bricks elements with ${result.bricksPlan.metrics.nativeCoverage}% native-control coverage.`);
		console.log(`AppSite package: ${path.resolve(output, 'appsite-package.json')}`);
	} catch (error) {
		console.error(error instanceof Error ? error.stack || error.message : String(error));
		process.exit(1);
	}
}

module.exports = { compileCaptureFile };
