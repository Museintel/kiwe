#!/usr/bin/env node
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { capturePage } = require('../lib/capture.cjs');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');
const { normalizeCapture } = require('../../seam-compiler-core/lib/normalize-capture.cjs');
const { compileBricksPlan } = require('../../seam-bricks-adapter/lib/compile-plan.cjs');

const root = path.resolve(__dirname, '..', '..', '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'kiwe-seam-capture-'));
const source = path.join(temp, 'responsive.html');
const pixel = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="40" height="20"%3E%3Crect width="40" height="20" fill="%23921d21"/%3E%3C/svg%3E';
fs.writeFileSync(source, `<!doctype html><html><head><meta charset="utf-8"><style>
:root{--seam-accent:#921d21}*{box-sizing:border-box}body{margin:0;background:#fff;color:#171717}
main{display:grid;grid-template-columns:1fr 1fr;gap:20px;width:min(100%,720px);margin:auto;padding:24px}
h1{font:700 40px/1.1 Georgia,serif;color:var(--seam-accent)}img{display:block;width:40px;height:20px}
@media(max-width:600px){main{display:flex;flex-direction:column;gap:8px;padding:12px}h1{font-size:28px}}
</style></head><body><main id="content"><section><h1>Rendered evidence</h1><p>Native responsive capture.</p></section><img alt="pixel" src="${pixel}"><img alt="blocked" src="https://example.invalid/blocked.png"></main></body></html>`);

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
		assert.ok(capture.resources.some((resource) => resource.url === 'https://example.invalid/blocked.png' && resource.blocked));
		assert.ok(capture.resources.some((resource) => resource.url.startsWith('data:image/svg+xml') && resource.sha256));
		const content = capture.nodes.find((node) => node.attributes.id === 'content');
		assert.ok(content);
		assert.equal(content.observations[0].computed.display, 'grid');
		assert.equal(content.observations[1].computed.display, 'flex');
		assert.ok(content.observations[1].matchedRules.some((rule) => rule.media.includes('max-width')));

		const normalized = normalizeCapture(capture);
		assert.equal(validateContract('geometry', normalized.geometry).ok, true);
		assert.equal(normalized.geometry.nodes.find((node) => node.id === content.id).widthMode, 'clamped');
		assert.ok(normalized.geometry.summary.responsiveNodes > 0);
		const pageNode = normalized.pageIr.nodes.find((node) => node.id === content.id);
		assert.ok(pageNode.layout.responsive.some((state) => state.breakpoint === 'mobile_portrait' && state.display === 'flex'));

		const profile = JSON.parse(fs.readFileSync(path.join(root, 'packages/seam-bricks-adapter/profiles/bricks-2.3.10.json'), 'utf8'));
		const plan = compileBricksPlan(normalized.pageIr, profile);
		const element = plan.elements.find((candidate) => candidate.provenance.pageNodeId === content.id);
		assert.equal(element.settings['_display:mobile_portrait'], 'flex');
		assert.equal(plan.aiGenerated, false);
		console.log(`PASS rendered capture: ${capture.nodes.length} nodes, ${normalized.geometry.summary.responsiveNodes} responsive, ${capture.resources.length} resources.`);
	} finally {
		fs.rmSync(temp, { recursive: true, force: true });
	}
}

main().catch((error) => {
	console.error(error instanceof Error ? error.stack || error.message : String(error));
	process.exit(1);
});
