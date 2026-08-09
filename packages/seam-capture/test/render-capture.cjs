#!/usr/bin/env node
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { capturePage } = require('../lib/capture.cjs');
const { mergeCaptures } = require('../lib/merge.cjs');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');
const { normalizeCapture } = require('../../seam-compiler-core/lib/normalize-capture.cjs');
const { compileBricksPlan } = require('../../seam-bricks-adapter/lib/compile-plan.cjs');
const { decodePng } = require('../../seam-visual-proof/lib/png.cjs');

const root = path.resolve(__dirname, '..', '..', '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'kiwe-seam-capture-'));
const source = path.join(temp, 'responsive.html');
const pixel = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="40" height="20"%3E%3Crect width="40" height="20" fill="%23921d21"/%3E%3C/svg%3E';
fs.writeFileSync(source, `<!doctype html><html><head><meta charset="utf-8"><style>
:root{--seam-accent:#921d21}*{box-sizing:border-box}body{margin:0;background:#fff;color:#171717}
main{display:grid;grid-template-columns:1fr 1fr;gap:20px;width:min(100%,720px);margin:auto;padding:24px;overflow-x:auto;scroll-behavior:smooth}
main .card:first-child{min-height:80px;background:linear-gradient(135deg,var(--seam-accent),#b7272e)}.card{min-height:40px}
h1{font:700 40px/1.1 Georgia,serif;color:var(--seam-accent)}p{font-size:.7rem}img{display:block;width:40px;height:20px}
@media(max-width:600px){main{display:flex;flex-direction:column;gap:8px;padding:12px}h1{font-size:28px}}
</style></head><body><main id="content"><section class="card"><h1>Rendered evidence</h1><p>Native responsive capture.</p></section><img alt="pixel" src="${pixel}"><img alt="blocked" src="https://example.invalid/blocked.png"></main></body></html>`);

const viewports = [
	{ id: 'desktop-1280', width: 1280, height: 720, theme: 'light', state: 'default' },
	{ id: 'mobile-478', width: 478, height: 720, theme: 'light', state: 'default' }
];

async function main() {
	try {
		const capture = await capturePage({ input: source, outputDirectory: path.join(temp, 'capture'), viewports });
		assert.equal(validateContract('capture', capture).ok, true);
		assert.equal(capture.source.entry, 'responsive.html');
		assert.equal(capture.viewports.length, 2);
		assert.ok(capture.viewports.every((viewport) => fs.existsSync(path.join(temp, 'capture', viewport.screenshot.file))));
		for (const viewport of capture.viewports) {
			const decoded = decodePng(fs.readFileSync(path.join(temp, 'capture', viewport.screenshot.file)));
			assert.equal(decoded.width, viewport.width);
			assert.ok(decoded.height >= viewport.height);
		}
		assert.ok(capture.resources.some((resource) => resource.url === 'https://example.invalid/blocked.png' && resource.blocked));
		assert.ok(capture.resources.some((resource) => resource.url.startsWith('data:image/svg+xml') && resource.sha256));
		const content = capture.nodes.find((node) => node.attributes.id === 'content');
		assert.ok(content);
		assert.equal(content.observations[0].computed.display, 'grid');
		assert.equal(content.observations[1].computed.display, 'flex');
		assert.ok(content.observations[1].matchedRules.some((rule) => rule.media.includes('max-width')));
		assert.ok(content.observations[0].matchedRules.every((rule) => Array.isArray(rule.specificity) && Number.isInteger(rule.order)));

		const normalized = normalizeCapture(capture);
		assert.equal(validateContract('geometry', normalized.geometry).ok, true);
		assert.equal(normalized.geometry.nodes.find((node) => node.id === content.id).widthMode, 'clamped');
		assert.ok(normalized.geometry.summary.responsiveNodes > 0);
		const pageNode = normalized.pageIr.nodes.find((node) => node.id === content.id);
		assert.ok(pageNode.layout.responsive.some((state) => state.breakpoint === 'mobile_portrait' && state.display === 'flex'));
		assert.equal(pageNode.style.native.overflow, 'auto');
		assert.ok(pageNode.style.residuals.includes('scroll-behavior: smooth'));
		const cascadeCard = normalized.pageIr.nodes.find((node) => node.attributes.class === 'card');
		assert.equal(cascadeCard.style.native['min-height'], '80px');
		assert.match(cascadeCard.style.native['background-image'], /^linear-gradient\(/);
		const paragraph = normalized.pageIr.nodes.find((node) => node.tag === 'p');
		assert.equal(paragraph.style.native['font-size'], '11.2px');

		const profile = JSON.parse(fs.readFileSync(path.join(root, 'packages/seam-bricks-adapter/profiles/bricks-2.3.10.json'), 'utf8'));
		const plan = compileBricksPlan(normalized.pageIr, profile);
		const element = plan.elements.find((candidate) => candidate.provenance.pageNodeId === content.id);
		assert.equal(element.settings['_display:mobile_portrait'], 'flex');
		assert.equal(element.settings._overflow, 'auto');
		assert.ok(plan.customCss.includes('[data-seam-proof-node="' + content.id + '"]'));
		assert.doesNotMatch(plan.customCss, /#brxe-/);
		const cardElement = plan.elements.find((candidate) => candidate.provenance.pageNodeId === cascadeCard.id);
		assert.ok(cardElement.settings._gradient);
		assert.equal(plan.aiGenerated, false);
		const proofCapture = await capturePage({ input: source, outputDirectory: path.join(temp, 'proof-capture'), viewports: [viewports[0]], proofMode: true });
		assert.equal(validateContract('capture', proofCapture).ok, true);
		assert.ok(proofCapture.nodes.every((node) => node.observations.every((observation) => observation.matchedRules.length === 0)));
		assert.ok(proofCapture.nodes.every((node) => node.observations.every((observation) => Object.keys(observation.customProperties).length === 0)));
		const shards = viewports.map((viewport) => {
			const shardDirectory = path.join(temp, `shard-${viewport.id}`);
			fs.mkdirSync(path.join(shardDirectory, 'screenshots'), { recursive: true });
			const screenshot = capture.viewports.find((item) => item.id === viewport.id).screenshot;
			fs.copyFileSync(path.join(temp, 'capture', screenshot.file), path.join(shardDirectory, screenshot.file));
			const shard = {
				...capture,
				viewports: capture.viewports.filter((item) => item.id === viewport.id),
				nodes: capture.nodes.map((node) => ({ ...node, observations: node.observations.filter((item) => item.viewportId === viewport.id) }))
			};
			const file = path.join(shardDirectory, 'seam-capture.json');
			fs.writeFileSync(file, `${JSON.stringify(shard, null, 2)}\n`);
			return file;
		});
		const merged = mergeCaptures({ captureFiles: shards, outputDirectory: path.join(temp, 'merged-capture') });
		assert.equal(validateContract('capture', merged.capture).ok, true);
		assert.deepEqual(merged.capture.viewports.map((viewport) => viewport.id), viewports.map((viewport) => viewport.id));
		assert.ok(merged.capture.nodes.every((node) => node.observations.length === 2));
		console.log(`PASS rendered capture: ${capture.nodes.length} nodes, ${normalized.geometry.summary.responsiveNodes} responsive, ${capture.resources.length} resources.`);
	} finally {
		fs.rmSync(temp, { recursive: true, force: true });
	}
}

main().catch((error) => {
	console.error(error instanceof Error ? error.stack || error.message : String(error));
	process.exit(1);
});
