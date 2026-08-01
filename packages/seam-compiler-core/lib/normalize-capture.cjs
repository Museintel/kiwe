const { createHash } = require('node:crypto');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');

const NATIVE_STYLE_PROPERTIES = new Set([
	'align-items', 'background-color', 'border-color', 'border-radius', 'border-style', 'border-width',
	'color', 'column-gap', 'font-family', 'font-size', 'font-style', 'font-weight', 'height',
	'justify-content', 'letter-spacing', 'line-height', 'margin-bottom', 'margin-left', 'margin-right',
	'margin-top', 'max-width', 'min-height', 'object-fit', 'object-position', 'opacity', 'padding-bottom',
	'padding-left', 'padding-right', 'padding-top', 'row-gap', 'text-align', 'text-decoration',
	'text-transform', 'width', 'grid-template-columns', 'grid-template-rows'
]);

function deterministicId(prefix, value) {
	return `${prefix}-${createHash('sha256').update(String(value)).digest('hex').slice(0, 12)}`;
}

function kindFor(node) {
	const tag = node.tag;
	if (['main', 'section', 'article', 'aside', 'header', 'footer', 'nav'].includes(tag)) return 'section';
	if (['div', 'figure', 'picture'].includes(tag)) return 'container';
	if (/^h[1-6]$/.test(tag)) return 'heading';
	if (['p', 'span', 'strong', 'em', 'small', 'label', 'time', 'figcaption'].includes(tag)) return 'text';
	if (tag === 'a') return 'link';
	if (tag === 'button') return 'button';
	if (tag === 'img') return 'image';
	if (['video', 'iframe'].includes(tag)) return 'video';
	if (['ul', 'ol'].includes(tag)) return 'list';
	if (tag === 'li') return 'list-item';
	if (tag === 'form') return 'form';
	if (['input', 'select', 'textarea'].includes(tag)) return 'input';
	if (tag === 'table') return 'table';
	return 'generic';
}

function widthMode(observations) {
	const visible = observations.filter((item) => item.visible && item.box.width > 0);
	if (visible.length < 2) return 'unknown';
	const widths = visible.map((item) => item.box.width);
	const delta = Math.max(...widths) - Math.min(...widths);
	if (delta <= 1) return 'fixed';
	const displays = new Set(visible.map((item) => item.computed.display));
	if ([...displays].every((display) => ['inline', 'inline-block'].includes(display))) return 'intrinsic';
	return 'fluid';
}

function normalizeStyle(observation) {
	const native = {};
	const tokenBindings = {};
	const residuals = [];
	for (const [property, value] of Object.entries(observation.computed)) {
		if (['display', 'flex-direction', 'flex-wrap', 'gap'].includes(property)) continue;
		if (NATIVE_STYLE_PROPERTIES.has(property)) {
			native[property] = value;
			const token = String(value).match(/var\((--(?:kiwe|seam)-[a-z0-9-]+)\)/i);
			if (token) tokenBindings[property] = token[1];
		} else if (value && !['normal', 'none', 'auto', 'static', 'visible'].includes(value)) {
			residuals.push(`${property}: ${value}`);
		}
	}
	return { native, tokenBindings, residuals };
}

function behaviorFor(node) {
	const behaviors = [];
	const attributes = node.attributes || {};
	if (attributes['data-dsa-open-module']) {
		behaviors.push({
			id: deterministicId('behavior', `${node.id}:kiwe-open`), sourceNodeId: node.id, event: 'click',
			intent: `open Kiwe module ${attributes['data-dsa-open-module']}`, authority: 'kiwe-capability',
			targetNodeIds: [], confidence: 1, evidence: ['data-dsa-open-module']
		});
	} else if (attributes.href) {
		behaviors.push({
			id: deterministicId('behavior', `${node.id}:navigate`), sourceNodeId: node.id, event: 'click',
			intent: 'navigate', authority: 'browser', targetNodeIds: [], confidence: 1, evidence: ['href']
		});
	}
	for (const name of Object.keys(attributes).filter((name) => name.startsWith('on'))) {
		behaviors.push({
			id: deterministicId('behavior', `${node.id}:${name}`), sourceNodeId: node.id, event: name.slice(2),
			intent: 'unclassified inline script', authority: 'unsupported', targetNodeIds: [], confidence: 1, evidence: [name]
		});
	}
	return behaviors;
}

function assetsFor(node) {
	const candidates = [];
	if (node.attributes.src) candidates.push(node.attributes.src);
	if (node.attributes.poster) candidates.push(node.attributes.poster);
	return candidates.map((source) => ({
		id: deterministicId('asset', source),
		kind: node.tag === 'img' || node.attributes.poster === source ? 'image' : node.tag === 'video' ? 'video' : 'other',
		source,
		contentHash: null,
		mime: null,
		bytes: null,
		policy: source.startsWith('data:') ? 'inline' : /^https?:/i.test(source) ? 'review' : 'import',
		usedBy: [node.id]
	}));
}

function assertContract(name, value) {
	const result = validateContract(name, value);
	if (!result.ok) {
		const error = new Error(`${result.schema} validation failed: ${result.errors.map((item) => item.message).join(' ')}`);
		error.code = 'SEAM_CONTRACT_INVALID';
		error.findings = result.errors;
		throw error;
	}
}

function normalizeCapture(capture) {
	assertContract('capture', capture);
	const residuals = [];
	const nodes = capture.nodes.map((node) => {
		const observation = node.observations.find((item) => item.visible) || node.observations[0];
		const style = normalizeStyle(observation);
		for (const residual of style.residuals) residuals.push(`${node.id}: ${residual}`);
		return {
			id: node.id,
			parentId: node.parentId,
			kind: kindFor(node),
			tag: node.tag,
			text: node.text,
			attributes: node.attributes,
			layout: {
				display: observation.computed.display || 'block',
				direction: observation.computed['flex-direction'] || 'row',
				wrap: observation.computed['flex-wrap'] || 'nowrap',
				gap: observation.computed.gap || '0px',
				widthMode: widthMode(node.observations)
			},
			style,
			provenance: {
				captureNodeIds: [node.id],
				viewportIds: node.observations.map((item) => item.viewportId),
				selector: node.provenance.selector
			}
		};
	});

	const pageIr = { schema: 'seam.page-ir.v1', sourceHash: capture.source.contentHash, nodes, residuals };
	const behaviors = capture.nodes.flatMap(behaviorFor);
	const behaviorIr = {
		schema: 'seam.behavior-ir.v1', sourceHash: capture.source.contentHash, behaviors,
		residuals: behaviors.filter((item) => item.authority === 'unsupported').map((item) => `${item.sourceNodeId}: ${item.intent}`)
	};
	const assetsById = new Map();
	for (const asset of capture.nodes.flatMap(assetsFor)) {
		const existing = assetsById.get(asset.id);
		if (existing) existing.usedBy.push(...asset.usedBy);
		else assetsById.set(asset.id, asset);
	}
	const assetManifest = { schema: 'seam.asset-manifest.v1', sourceHash: capture.source.contentHash, assets: [...assetsById.values()] };

	assertContract('pageIr', pageIr);
	assertContract('behaviorIr', behaviorIr);
	assertContract('assetManifest', assetManifest);
	return { pageIr, behaviorIr, assetManifest };
}

module.exports = { assertContract, deterministicId, normalizeCapture };
