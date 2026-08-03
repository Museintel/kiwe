#!/usr/bin/env node
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');
const { compileAiDirectJson } = require('../lib/ai-direct-json.cjs');
const { classifyComponent } = require('../lib/classify-component.cjs');
const { compileCaptureFile } = require('../tools/compile-capture.cjs');
const { serializeBricksTemplate } = require('../../seam-bricks-adapter/lib/serialize-template.cjs');
const { createNativeVariableRegistry } = require('../../seam-bricks-adapter/lib/compile-plan.cjs');
const { youtubeId, vimeoId } = require('../../seam-bricks-adapter/lib/component-adapters.cjs');
const { extractCapabilities } = require('../../seam-bricks-adapter/tools/extract-bricks-capabilities.cjs');
const { buildAssetImportPlan, buildBindingPlan, buildDeploymentPlan, sanitizeSiteGraph } = require('../lib/sitegraph-deployment.cjs');

const root = path.resolve(__dirname, '..', '..', '..');
const profileFile = path.join(root, 'packages/seam-bricks-adapter/profiles/bricks-2.3.10.json');
const profile = JSON.parse(fs.readFileSync(profileFile, 'utf8'));
const fixtures = ['landing-hero', 'editorial-card', 'semantic-native'];
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'kiwe-seam-m1-'));
const checks = [];

function check(label, callback) {
	try {
		callback();
		checks.push({ label, ok: true });
	} catch (error) {
		checks.push({ label, ok: false, error: error instanceof Error ? error.stack || error.message : String(error) });
	}
}

function sha256(content) {
	return crypto.createHash('sha256').update(content).digest('hex');
}

try {
	check('generated TypeScript and PHP contract declarations are current', () => {
		const result = spawnSync(process.execPath, ['packages/seam-contracts/tools/generate-types.cjs', '--check'], { cwd: root, encoding: 'utf8' });
		assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
	});

	check('contract validation is strict and rejects unknown capture authority', () => {
		const capture = JSON.parse(fs.readFileSync(path.join(root, 'packages/seam-compiler-core/fixtures/landing-hero/capture.json'), 'utf8'));
		assert.equal(validateContract('capture', capture).ok, true);
		assert.equal(validateContract('capture', { ...capture, aiGeneratedBricksJson: true }).ok, false);
	});

	check('capability extractor resolves inherited controls without executing Bricks PHP', () => {
		const mini = path.join(temp, 'bricks-mini');
		fs.mkdirSync(path.join(mini, 'includes/elements'), { recursive: true });
		fs.mkdirSync(path.join(mini, 'includes/settings'), { recursive: true });
		fs.writeFileSync(path.join(mini, 'functions.php'), "<?php define( 'BRICKS_VERSION', '9.9.9' );\n");
		fs.writeFileSync(path.join(mini, 'includes/elements/base.php'), "<?php abstract class Element { public function controls(){ $this->controls['_background'] = []; } }\n");
		fs.writeFileSync(path.join(mini, 'includes/elements/container.php'), "<?php class Element_Container extends Element { public $name = 'container'; public function controls(){ $this->controls['_direction'] = []; $controls['ariaLabel'] = []; $this->controls = array_merge( $this->controls, $controls ); } }\n");
		fs.writeFileSync(path.join(mini, 'includes/elements/section.php'), "<?php class Element_Section extends Element_Container { public $name = 'section'; public $nestable = true; }\n");
		fs.writeFileSync(path.join(mini, 'includes/settings/settings-page.php'), "<?php $this->controls['scrollSnapType'] = []; $this->controls['scrollSnapSelector'] = [];\n");
		const extracted = extractCapabilities(mini);
		assert.equal(extracted.bricksVersion, '9.9.9');
		assert.deepEqual(extracted.elements.find((element) => element.name === 'section').controls, ['_background', '_direction', '_scrollSnapType', 'ariaLabel']);
	});

	check('Bricks 2.3.10 capability profile is deterministic and inheritance-aware', () => {
		assert.equal(profile.schema, 'seam.bricks-capability-profile.v1');
		assert.equal(profile.bricksVersion, '2.3.10');
		assert.ok(profile.elements.length >= 80);
		const catalog = JSON.parse(fs.readFileSync(path.join(root, 'packages/seam-contracts/generated/kiwe-token-catalog.json'), 'utf8'));
		const packageEntry = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/dsa.php'), 'utf8');
		const packageVersion = packageEntry.match(/define\(\s*'DSA_VERSION'\s*,\s*'([^']+)'\s*\)/)?.[1];
		assert.equal(catalog.pluginVersion, packageVersion);
		assert.equal(Object.keys(catalog.tokens).length, 108);
		const section = profile.elements.find((element) => element.name === 'section');
		assert.ok(section.controls.includes('_direction'));
		assert.ok(section.controls.includes('_background'));
		const { profileHash, ...core } = profile;
		assert.equal(profileHash, `sha256:${sha256(JSON.stringify(core))}`);
	});

	check('source CSS variables become valid collision-safe Framework project variables', () => {
		const registry = createNativeVariableRegistry({
			sourceHash: `sha256:${'0'.repeat(64)}`,
			variables: [
				{ name: '--aqua', value: '#0ff' }, { name: '--Foo', value: '1rem' },
				{ name: '--foo', value: '2rem' }, { name: '--seam-accent', value: '#f00' }
			]
		});
		const names = registry.records.map((variable) => variable.name);
		assert.equal(new Set(names).size, names.length);
		assert.ok(names.every((name) => /^[a-z][a-z0-9]*-[a-z0-9][a-z0-9_-]{0,80}$/.test(name)));
		assert.ok(names.every((name) => !/^(?:kiwe|seam)-/.test(name)));
		assert.notEqual(registry.tokenFor('var(--Foo)'), registry.tokenFor('var(--foo)'));
		assert.equal(registry.tokenFor('var(--aqua)'), 'var(--appsite-aqua)');
	});

	check('semantic classifier selects equivalent media adapters and rejects lossy compound media', () => {
		assert.equal(classifyComponent({ tag: 'video', attributes: {}, role: null }, { children: [{ tag: 'source' }] }).adapter, 'direct-media');
		assert.equal(classifyComponent({ tag: 'video', attributes: {}, role: null }, { children: [{ tag: 'source' }, { tag: 'track' }] }).adapter, 'review');
		assert.equal(classifyComponent({ tag: 'iframe', attributes: { src: 'https://example.com/embed' }, role: null }).adapter, 'review');
		assert.equal(youtubeId('https://www.youtube-nocookie.com/embed/5DGo0AYOJ7s'), '5DGo0AYOJ7s');
		assert.equal(vimeoId('https://player.vimeo.com/video/1234567'), '1234567');
	});

	for (const fixture of fixtures) {
		check(`${fixture} compiles through all typed contracts`, () => {
			const capture = path.join(root, `packages/seam-compiler-core/fixtures/${fixture}/capture.json`);
			const firstDirectory = path.join(temp, `${fixture}-first`);
			const secondDirectory = path.join(temp, `${fixture}-second`);
			const siteGraphFile = fixture === 'semantic-native' ? path.join(root, 'packages/seam-compiler-core/fixtures/semantic-native/site-graph.json') : null;
			const first = compileCaptureFile(capture, profileFile, firstDirectory, fixture, siteGraphFile);
			const second = compileCaptureFile(capture, profileFile, secondDirectory, fixture, siteGraphFile);
			for (const [contract, value] of [
				['capture', JSON.parse(fs.readFileSync(capture, 'utf8'))], ['pageIr', first.pageIr],
				['behaviorIr', first.behaviorIr], ['assetManifest', first.assetManifest],
				['assetImportPlan', first.assetImportPlan],
				['geometry', first.geometry], ['bricksPlan', first.bricksPlan], ['appsitePackage', first.appsitePackage]
			]) {
				const validation = validateContract(contract, value);
				assert.equal(validation.ok, true, JSON.stringify(validation.errors));
			}
			assert.deepEqual(first, second);
			assert.equal(first.bricksPlan.aiGenerated, false);
			assert.equal(typeof first.bricksPlan.customCss, 'string');
			assert.equal(first.bricksPlan.metrics.customCssDeclarations, 0);
			assert.ok(first.bricksPlan.elements.length <= first.pageIr.nodes.length);
			const capturedByPlan = new Set(first.bricksPlan.elements.flatMap((element) => element.provenance.captureNodeIds));
			assert.deepEqual([...capturedByPlan].sort(), first.pageIr.nodes.flatMap((node) => node.provenance.captureNodeIds).sort());
			assert.ok(first.bricksPlan.elements.every((element) => element.provenance.captureNodeIds.length > 0));
			assert.ok(first.template.content.every((element) => element.name !== 'code'));
			assert.ok(first.template.content.every((element) => !Object.keys(element.settings).some((key) => key.startsWith('_cssCustom'))));
			assert.ok(first.template.content.every((element) => element.settings._attributes.some((attribute) => attribute.name === 'data-seam-proof-node')));
			assert.equal(first.template.generator.aiDirectJson, false);
			assert.equal(first.appsitePackage.artifacts.frameworkProfile, 'framework/kiwe-framework-profile.json');
			assert.equal(first.frameworkProfile.settings.tokens.project.enabled, first.bricksPlan.variables.length > 0);
			assert.equal(first.frameworkProfile.settings.tokens.project.id, `seam-${fixture}`);
			assert.equal(first.frameworkProfile.settings.tokens.project.label, `${fixture} SEAM Project`);
			assert.equal(first.frameworkProfile.settings.tokens.project.variables.length, first.bricksPlan.variables.length);
			assert.equal(first.appsitePackage.artifacts.geometry, 'geometry/page-geometry.json');
			assert.equal(first.appsitePackage.artifacts.assetImportPlan, 'assets/import-plan.json');
			for (const [relative, expectedHash] of Object.entries(first.appsitePackage.integrity.files)) {
				assert.equal(path.isAbsolute(relative), false);
				assert.equal(sha256(fs.readFileSync(path.join(firstDirectory, relative))), expectedHash, `Integrity mismatch for ${relative}`);
			}
			assert.equal(first.appsitePackage.artifacts.siteGraph === null, !siteGraphFile);
			assert.equal(first.appsitePackage.artifacts.bindings === null, !siteGraphFile);
			assert.equal(first.appsitePackage.artifacts.deploymentPlan === null, !siteGraphFile);
			assert.equal(first.geometry.solver.version, '0.2.0');
			assert.equal(first.bricksPlan.ownership.policy, 'element-native-single-owner');
			assert.deepEqual(first.bricksPlan.ownership.conflicts, []);
			assert.equal(first.bricksPlan.ownership.elementNativeControls, first.bricksPlan.elements.reduce((count, element) => count + element.provenance.ownedControls.length, 0));
			assert.equal(first.bricksPlan.ownership.customCssDeclarations, first.bricksPlan.metrics.customCssDeclarations);
			if (fixture === 'semantic-native') {
				for (const [contract, value] of [['siteGraphSnapshot', first.siteGraphSnapshot], ['bindings', first.bindings], ['deploymentPlan', first.deploymentPlan]]) {
					const validation = validateContract(contract, value);
					assert.equal(validation.ok, true, JSON.stringify(validation.errors));
				}
				assert.equal(first.siteGraphSnapshot.authority.mayMutateWordPress, false);
				assert.equal(first.siteGraphSnapshot.bricks.trustedAdapterLikelyAvailable, true);
				assert.equal(first.bindings.queries[0].id, 'feature-posts');
				assert.equal(first.bindings.dynamicFields[0].tag, '{post_title}');
				assert.equal(first.bindings.launchers[0].value, 'search');
				assert.equal(first.bindings.menuContext[0].id, 'native-adapters');
				assert.deepEqual(first.bindings.requiresHumanReview, []);
				assert.equal(first.assetImportPlan.summary.reviewRequired, 3);
				assert.equal(first.deploymentPlan.target.mutatesWordPress, false);
				assert.equal(first.deploymentPlan.operations.find((item) => item.id === 'assets:content-addressed-import').status, 'blocked');
				const list = first.bricksPlan.elements.find((element) => element.type === 'list');
				const video = first.bricksPlan.elements.find((element) => element.type === 'video');
				const audio = first.bricksPlan.elements.find((element) => element.type === 'audio');
				const svg = first.bricksPlan.elements.find((element) => element.type === 'svg');
				const navigation = first.bricksPlan.elements.find((element) => element.provenance.pageNodeId === 'nav');
				assert.equal(list.settings.items.length, 2);
				assert.equal(list.settings.items[1].link.url, '/editable');
				assert.equal(video.settings.videoType, 'file');
				assert.equal(video.settings.fileUrl, 'media/demo.mp4');
				assert.equal(video.settings.fileControls, true);
				assert.equal(audio.settings.source, 'external');
				assert.equal(audio.settings.external, 'media/demo.mp3');
				assert.match(svg.settings.code, /<path[^>]+d="M4 12l5 5L20 6"/);
				assert.doesNotMatch(svg.settings.code, /onclick|alert\(1\)/i);
				assert.equal(navigation.type, 'section');
				assert.equal(navigation.settings.tag, 'nav');
				assert.equal(first.bricksPlan.metrics.aggregatedNodeCount, 5);
				assert.equal(first.bricksPlan.metrics.reviewComponentCount, 2);
				assert.equal(first.bricksPlan.metrics.semanticCoverage, 89.5);
				assert.ok(first.bricksPlan.residuals.some((item) => item.includes('form submission authority')));
				assert.ok(first.behaviorIr.residuals.some((item) => item.includes('safe-icon-path')));
			}
			if (siteGraphFile) {
				const bindingsValidation = spawnSync(process.execPath, ['kiwe-ai-toolkit/tools/validate-bindings.cjs', path.join(firstDirectory, 'bindings/kiwe-bindings.json'), '--site-graph', siteGraphFile], { cwd: root, encoding: 'utf8' });
				assert.equal(bindingsValidation.status, 0, `${bindingsValidation.stdout}\n${bindingsValidation.stderr}`);
				const applyPlan = spawnSync(process.execPath, ['kiwe-ai-toolkit/tools/prepare-apply-plan.cjs', path.join(firstDirectory, 'bindings/kiwe-bindings.json'), '--site-graph', siteGraphFile], { cwd: root, encoding: 'utf8' });
				assert.equal(applyPlan.status, 0, `${applyPlan.stdout}\n${applyPlan.stderr}`);
				assert.equal(JSON.parse(applyPlan.stdout).plan.target.mutatesWordPress, false);
			}
			const frameworkValidation = spawnSync(
				process.execPath,
				['kiwe-ai-toolkit/tools/validate-framework-profile.cjs', path.join(firstDirectory, 'framework')],
				{ cwd: root, encoding: 'utf8' }
			);
			assert.equal(frameworkValidation.status, 0, `${frameworkValidation.stdout}\n${frameworkValidation.stderr}`);
			const validationResult = spawnSync(
				process.execPath,
				['kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs', path.join(firstDirectory, 'bricks/templates/content.json')],
				{ cwd: root, encoding: 'utf8' }
			);
			assert.equal(validationResult.status, 0, `${validationResult.stdout}\n${validationResult.stderr}`);
		});
	}

	check('SiteGraph and deployment planners reject invented authority and unresolved assets', () => {
		const capture = JSON.parse(fs.readFileSync(path.join(root, 'packages/seam-compiler-core/fixtures/semantic-native/capture.json'), 'utf8'));
		const siteGraph = JSON.parse(fs.readFileSync(path.join(root, 'packages/seam-compiler-core/fixtures/semantic-native/site-graph.json'), 'utf8'));
		const snapshot = sanitizeSiteGraph(siteGraph);
		const refreshedSnapshot = sanitizeSiteGraph({ ...siteGraph, generatedAt: '2099-01-01T00:00:00Z' });
		assert.equal(refreshedSnapshot.sourceHash, snapshot.sourceHash, 'Volatile SiteGraph generation timestamps must not invalidate an unchanged capability snapshot.');
		const bindings = buildBindingPlan(capture, snapshot);
		const manifest = { schema: 'seam.asset-manifest.v1', sourceHash: capture.source.contentHash, assets: [{ id: 'unknown-image', kind: 'image', source: 'image.webp', contentHash: null, mime: null, bytes: null, policy: 'import', usedBy: ['main'] }] };
		const assets = buildAssetImportPlan(manifest);
		const deployment = buildDeploymentPlan(capture.source.contentHash, snapshot, assets, bindings);
		assert.equal(assets.operations[0].action, 'review');
		assert.equal(assets.operations[0].status, 'review-required');
		assert.equal(deployment.operations[0].status, 'blocked');
		assert.equal(deployment.target.authority, 'admin-approved-kiwe-staging-executor');
		assert.throws(() => sanitizeSiteGraph({ schema: 'invented.sitegraph' }), /kiwe\.site-graph\.v1/);
	});

	check('compiler refuses to mix a new package with stale output artifacts', () => {
		const output = path.join(temp, 'non-empty-output');
		fs.mkdirSync(output, { recursive: true });
		fs.writeFileSync(path.join(output, 'user-owned.txt'), 'preserve');
		assert.throws(() => compileCaptureFile(path.join(root, 'packages/seam-compiler-core/fixtures/landing-hero/capture.json'), profileFile, output, 'must-fail'), (error) => error && error.code === 'SEAM_OUTPUT_NOT_EMPTY');
		assert.equal(fs.readFileSync(path.join(output, 'user-owned.txt'), 'utf8'), 'preserve');
	});

	check('AI-direct Bricks JSON is an explicit unsupported path', () => {
		assert.throws(compileAiDirectJson, (error) => error && error.code === 'SEAM_AI_DIRECT_JSON_UNSUPPORTED');
		const validPlan = compileCaptureFile(
			path.join(root, 'packages/seam-compiler-core/fixtures/landing-hero/capture.json'),
			profileFile,
			path.join(temp, 'authority'),
			'authority'
		).bricksPlan;
		assert.throws(() => serializeBricksTemplate({ ...validPlan, aiGenerated: true }), /validation failed/);
	});

	check('legacy browser converter is quarantined from the supported compiler', () => {
		const source = fs.readFileSync(path.join(root, 'packages/seam-bricks-adapter/scaffold/legacy-browser-converter.ts'), 'utf8');
		assert.match(source, /LEGACY_BROWSER_CONVERTER_STATUS = "unsupported-production-scaffold"/);
		assert.match(source, /convertHtmlToBricks/);
		assert.match(source, /global_classes/);
		assert.match(source, /_interactions/);
		assert.match(source, /_conditions/);
		assert.match(source, /hasLoop/);
		for (const supported of [
			'packages/seam-compiler-core/lib/normalize-capture.cjs',
			'packages/seam-bricks-adapter/lib/compile-plan.cjs',
			'packages/seam-bricks-adapter/lib/serialize-template.cjs'
		]) {
			assert.doesNotMatch(fs.readFileSync(path.join(root, supported), 'utf8'), /legacy-browser-converter|convertHtmlToBricks/);
		}
	});

	check('supplied National Chikki homepage has canonical M2 capture and M3 compilation evidence', () => {
		const directory = path.join(root, 'packages/seam-compiler-core/fixtures/golden/national-chikki');
		const manifest = JSON.parse(fs.readFileSync(path.join(directory, 'fixture.json'), 'utf8'));
		const evidence = JSON.parse(fs.readFileSync(path.join(directory, manifest.evidence), 'utf8'));
		const source = fs.readFileSync(path.join(directory, 'source.html'));
		assert.equal(manifest.captureStatus, 'captured-m2');
		assert.equal(manifest.compilationStatus, 'compiled-m3');
		assert.equal(source.length, manifest.repositoryNormalization.bytes);
		assert.equal(sha256(source), manifest.repositoryNormalization.sha256);
		assert.equal(evidence.sourceHash, `sha256:${manifest.repositoryNormalization.sha256}`);
		assert.equal(evidence.capture.nodes, 279);
		assert.equal(evidence.capture.viewports.length, 7);
		assert.equal(evidence.capture.diagnostics.length, 0);
		assert.equal(evidence.geometry.nodes, 279);
		assert.equal(evidence.compilation.elements, 267);
		assert.equal(evidence.compilation.codeElements, 0);
		assert.equal(evidence.compilation.nativeSvgElements, 12);
		assert.equal(evidence.compilation.metrics.sourceNodeCount, 279);
		assert.equal(evidence.compilation.metrics.aggregatedNodeCount, 12);
		assert.equal(evidence.compilation.metrics.semanticCoverage, 100);
		assert.equal(evidence.compilation.ownership.policy, 'element-native-single-owner');
		assert.equal(evidence.compilation.metrics.nativeCoverage, 99.9);
		assert.equal(evidence.compilation.metrics.customCssDeclarations, 3);
		assert.ok(Object.values(evidence.validation.contracts).every((result) => result.ok));
		assert.equal(evidence.validation.bricksConversion.ok, true);
		assert.equal(evidence.validation.frameworkProfile.ok, true);
		assert.match(source.toString('utf8'), /Lonavala in your pocket/i);
	});
} finally {
	fs.rmSync(temp, { recursive: true, force: true });
}

for (const item of checks) console.log(`${item.ok ? 'PASS' : 'FAIL'} ${item.label}`);
const failed = checks.filter((item) => !item.ok);
if (failed.length) {
	for (const item of failed) console.error(`\n[${item.label}]\n${item.error}`);
	process.exit(1);
}
console.log(`\n${checks.length}/${checks.length} SEAM compiler foundation contracts passed.`);
