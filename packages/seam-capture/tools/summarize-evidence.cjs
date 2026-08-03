#!/usr/bin/env node
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');

const root = path.resolve(__dirname, '..', '..', '..');

function readJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function fileProof(file) {
	const content = fs.readFileSync(file);
	return { bytes: content.length, sha256: crypto.createHash('sha256').update(content).digest('hex') };
}

function safeArtifact(rootDirectory, relative) {
	const root = path.resolve(rootDirectory);
	const file = path.resolve(root, String(relative || ''));
	if (file !== root && !file.startsWith(`${root}${path.sep}`)) throw new Error(`Artifact path leaves evidence root: ${relative}`);
	return file;
}

function validator(script, target) {
	const result = spawnSync(process.execPath, [script, target], { cwd: root, encoding: 'utf8' });
	let report;
	try { report = JSON.parse(result.stdout); } catch { throw new Error(`${script} did not return JSON: ${result.stdout}\n${result.stderr}`); }
	return {
		ok: result.status === 0 && report.ok === true,
		summary: report.summary || null,
		counts: report.counts || null,
		errors: Array.isArray(report.errors) ? report.errors.length : 0,
		warnings: Array.isArray(report.warnings) ? report.warnings.length : 0
	};
}

function summarize(captureDirectory, compileDirectory) {
	const captureFile = path.join(captureDirectory, 'seam-capture.json');
	const files = {
		pageIr: 'ir/page-ir.json', behaviorIr: 'ir/behavior-ir.json', assets: 'assets/asset-manifest.json',
		assetImportPlan: 'assets/import-plan.json',
		frameworkProfile: 'framework/kiwe-framework-profile.json', geometry: 'geometry/page-geometry.json',
		bricksPlan: 'bricks/bricks-plan.json', bricksTemplate: 'bricks/templates/content.json',
		appsitePackage: 'appsite-package.json'
	};
	const capture = readJson(captureFile);
	const artifacts = Object.fromEntries(Object.entries(files).map(([name, relative]) => [name, readJson(safeArtifact(compileDirectory, relative))]));
	const contractInputs = {
		capture, pageIr: artifacts.pageIr, behaviorIr: artifacts.behaviorIr, assetManifest: artifacts.assets,
		assetImportPlan: artifacts.assetImportPlan,
		geometry: artifacts.geometry, bricksPlan: artifacts.bricksPlan, appsitePackage: artifacts.appsitePackage
	};
	for (const [name, contract, artifactKey] of [['siteGraphSnapshot', 'siteGraphSnapshot', 'siteGraph'], ['bindings', 'bindings', 'bindings'], ['deploymentPlan', 'deploymentPlan', 'deploymentPlan']]) {
		const relative = artifacts.appsitePackage.artifacts[artifactKey];
		if (!relative) continue;
		files[name] = relative;
		artifacts[name] = readJson(safeArtifact(compileDirectory, relative));
		contractInputs[contract] = artifacts[name];
	}
	if (artifacts.appsitePackage.artifacts.proof) {
		files.visualProof = artifacts.appsitePackage.artifacts.proof;
		artifacts.visualProof = readJson(safeArtifact(compileDirectory, files.visualProof));
		contractInputs.visualProof = artifacts.visualProof;
		if (artifacts.visualProof.repairPlan) {
			files.repairPlan = artifacts.visualProof.repairPlan;
			artifacts.repairPlan = readJson(safeArtifact(compileDirectory, files.repairPlan));
			contractInputs.repairPlan = artifacts.repairPlan;
		}
	}
	for (const [relative, expected] of Object.entries(artifacts.appsitePackage.integrity.files)) {
		const proof = fileProof(safeArtifact(compileDirectory, relative));
		if (proof.sha256 !== expected) throw new Error(`AppSite package integrity mismatch: ${relative}`);
	}
	const contractValidation = Object.fromEntries(Object.entries(contractInputs).map(([name, value]) => {
		const result = validateContract(name, value);
		return [name, { ok: result.ok, errors: result.errors.length }];
	}));
	const screenshotProof = capture.viewports.map((viewport) => {
		const proof = fileProof(safeArtifact(captureDirectory, viewport.screenshot.file));
		if (proof.bytes !== viewport.screenshot.bytes || proof.sha256 !== viewport.screenshot.sha256) {
			throw new Error(`Screenshot integrity mismatch: ${viewport.screenshot.file}`);
		}
		return { id: viewport.id, width: viewport.width, height: viewport.height, file: viewport.screenshot.file, ...proof };
	});
	const artifactProof = {
		capture: { file: 'seam-capture.json', ...fileProof(captureFile) },
		...Object.fromEntries(Object.entries(files).map(([name, relative]) => [name, { file: relative, ...fileProof(safeArtifact(compileDirectory, relative)) }]))
	};
	const typeCounts = artifacts.bricksPlan.elements.reduce((counts, element) => {
		counts[element.type] = (counts[element.type] || 0) + 1;
		return counts;
	}, {});
	const adapterCounts = artifacts.bricksPlan.elements.reduce((counts, element) => {
		const adapter = element.provenance.component.adapter;
		counts[adapter] = (counts[adapter] || 0) + 1;
		return counts;
	}, {});
	const bricksValidation = validator('kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs', safeArtifact(compileDirectory, files.bricksTemplate));
	const frameworkValidation = validator('kiwe-ai-toolkit/tools/validate-framework-profile.cjs', path.join(compileDirectory, 'framework'));
	if (!Object.values(contractValidation).every((result) => result.ok) || !bricksValidation.ok || !frameworkValidation.ok || (artifacts.visualProof && artifacts.visualProof.status !== 'passed')) {
		throw new Error('Refusing to publish golden evidence for an invalid compilation.');
	}
	return {
		schema: 'seam.golden-evidence.v1', fixture: 'national-chikki-homepage', sourceHash: capture.source.contentHash,
		storage: {
			policy: 'content-addressed-external-artifacts', rawCaptureCheckedIn: false,
			note: 'The compact manifest is versioned; raw capture, screenshots, and compiled package are retained by their SHA-256 proofs.'
		},
		capture: {
			artifact: artifactProof.capture, engine: capture.capture, nodes: capture.nodes.length,
			viewports: screenshotProof, stylesheets: capture.stylesheets.length,
			stylesheetRules: capture.stylesheets.reduce((count, stylesheet) => count + (stylesheet.ruleCount || 0), 0),
			resources: {
				total: capture.resources.length,
				blocked: capture.resources.filter((resource) => resource.blocked).length,
				hashed: capture.resources.filter((resource) => resource.sha256).length
			},
			diagnostics: capture.diagnostics
		},
		geometry: { artifact: artifactProof.geometry, ...artifacts.geometry.summary },
		compilation: {
			compiler: artifacts.appsitePackage.compiler, target: artifacts.bricksPlan.target,
			elements: artifacts.bricksPlan.elements.length, elementTypes: Object.fromEntries(Object.entries(typeCounts).sort()),
			responsiveElements: artifacts.bricksPlan.elements.filter((element) => Object.keys(element.settings).some((key) => key.includes(':'))).length,
			behaviorIntents: artifacts.behaviorIr.behaviors.length, assets: artifacts.assets.assets.length,
			projectVariables: artifacts.bricksPlan.variables.length,
			codeElements: artifacts.bricksTemplate.content.filter((element) => element.name === 'code').length,
			nativeSvgElements: artifacts.bricksTemplate.content.filter((element) => element.name === 'svg').length,
			componentAdapters: Object.fromEntries(Object.entries(adapterCounts).sort()),
			ownership: artifacts.bricksPlan.ownership,
			metrics: artifacts.bricksPlan.metrics, residuals: artifacts.bricksPlan.residuals,
			customCss: artifacts.bricksPlan.customCss,
			artifacts: artifactProof
		},
		validation: {
			contracts: contractValidation, bricksConversion: bricksValidation, frameworkProfile: frameworkValidation,
			visualProof: artifacts.visualProof ? { status: artifacts.visualProof.status, grade: artifacts.visualProof.grade, summary: artifacts.visualProof.summary } : null
		}
	};
}

if (require.main === module) {
	const [, , captureInput, compileInput, outputInput] = process.argv;
	if (!captureInput || !compileInput || !outputInput) {
		console.error('Usage: node summarize-evidence.cjs <capture-directory> <compile-directory> <output.json>');
		process.exit(2);
	}
	try {
		const output = path.resolve(outputInput);
		const evidence = summarize(path.resolve(captureInput), path.resolve(compileInput));
		fs.mkdirSync(path.dirname(output), { recursive: true });
		fs.writeFileSync(output, `${JSON.stringify(evidence, null, 2)}\n`);
		console.log(`Wrote golden evidence for ${evidence.compilation.elements} Bricks elements to ${output}`);
	} catch (error) {
		console.error(error instanceof Error ? error.stack || error.message : String(error));
		process.exit(1);
	}
}

module.exports = { fileProof, summarize };
