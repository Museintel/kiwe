const { createHash } = require('node:crypto');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');
const { breakpointFor, solveGeometry } = require('../../seam-geometry/lib/solve.cjs');
const { classifyCapture } = require('./classify-component.cjs');

const NATIVE_STYLE_PROPERTIES = new Set([
	'align-content', 'align-items', 'align-self', 'background-color', 'background-image', 'border-color', 'border-radius', 'border-style', 'border-width',
	'color', 'column-gap', 'cursor', 'font-family', 'font-size', 'font-style', 'font-weight', 'height',
	'filter', 'flex-grow', 'flex-shrink', 'grid-auto-columns', 'grid-auto-flow', 'grid-auto-rows', 'grid-column', 'justify-content', 'letter-spacing', 'line-height', 'margin-bottom', 'margin-left', 'margin-right',
	'margin-top', 'max-height', 'max-width', 'min-height', 'min-width', 'object-fit', 'object-position', 'opacity', 'overflow', 'padding-bottom',
	'padding-left', 'padding-right', 'padding-top', 'row-gap', 'text-align', 'text-decoration',
	'scroll-snap-align', 'scroll-snap-stop', 'scroll-snap-type', 'text-overflow', 'text-transform', 'transform', 'transition', 'visibility', 'white-space', 'width', 'grid-template-columns', 'grid-template-rows', 'aspect-ratio',
	'isolation', 'justify-items', 'position', 'top', 'right', 'bottom', 'left', 'z-index'
]);

const LAYOUT_DECLARATIONS = new Set(['display', 'flex-direction', 'flex-wrap', 'gap']);
const SUPPORTED_SHORTHANDS = new Set(['background', 'border', 'border-color', 'border-radius', 'border-style', 'border-width', 'font', 'margin', 'padding']);

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

function authoredValue(node, observation, property) {
	const candidates = (observation.matchedRules || []).map((rule, index) => {
		const raw = rule.declarations?.[property];
		if (!raw) return null;
		const specificity = Array.isArray(rule.specificity) && rule.specificity.length === 3 ? rule.specificity.map(Number) : [0, 0, 0];
		return { raw, important: /\s*!important\s*$/i.test(raw), specificity, order: Number.isInteger(rule.order) ? rule.order : index };
	}).filter(Boolean);
	if (!candidates.length) return null;
	candidates.sort((left, right) => Number(right.important) - Number(left.important)
		|| right.specificity[0] - left.specificity[0]
		|| right.specificity[1] - left.specificity[1]
		|| right.specificity[2] - left.specificity[2]
		|| right.order - left.order);
	return candidates[0].raw.replace(/\s*!important\s*$/i, '');
}

function authoredDeclarations(node, observation) {
	const declarations = {};
	const properties = new Set((observation.matchedRules || []).flatMap((rule) => Object.keys(rule.declarations || {})));
	for (const property of properties) {
		const value = authoredValue(node, observation, property);
		if (value) declarations[property] = value;
	}
	return declarations;
}

function isExpandedDeclarationOwned(property, declarations) {
	if (property === 'box-sizing') return true;
	if (/^border-(?:top|right|bottom|left)-(?:color|style|width)$/.test(property) && (declarations.border || declarations['border-color'] || declarations['border-style'] || declarations['border-width'])) return true;
	if (/^border-(?:top-left|top-right|bottom-right|bottom-left)-radius$/.test(property) && declarations['border-radius']) return true;
	if (property.startsWith('border-image-') && declarations.border) return true;
	if (property.startsWith('transition-') && declarations.transition) return true;
	if ((property === 'overflow-x' || property === 'overflow-y') && declarations.overflow) return true;
	if ((property === 'white-space-collapse' || property === 'text-wrap-mode') && declarations['white-space']) return true;
	if ((property === 'grid-column-start' || property === 'grid-column-end') && declarations['grid-column']) return true;
	if (property.startsWith('font-') && declarations.font) return true;
	if (property.startsWith('text-decoration-') && declarations['text-decoration']) return true;
	if (property.startsWith('background-') && declarations.background && ['initial', 'normal', 'none'].includes(declarations[property])) return true;
	return false;
}

function normalizeStyle(node, observation, geometryNode) {
	const native = {};
	const tokenBindings = {};
	const residuals = [];
	const enhancedEvidence = Array.isArray(observation.matchedRules);
	const declarations = authoredDeclarations(node, observation);
	if (enhancedEvidence) {
		for (const property of NATIVE_STYLE_PROPERTIES) {
			if (['width', 'max-width', 'height', 'min-height'].includes(property)) continue;
			const value = authoredValue(node, observation, property);
			if (value) native[property] = value;
		}
		// CSSOM often retains only an authored `background` shorthand while its
		// longhands are empty. Bricks needs the decomposed render values for its
		// native background and gradient controls, so use the browser-computed
		// longhands only when that shorthand actually owns the background.
		if (authoredValue(node, observation, 'background')) {
			if (!native['background-color'] && observation.computed['background-color']) native['background-color'] = observation.computed['background-color'];
			if (!native['background-image'] && observation.computed['background-image'] && observation.computed['background-image'] !== 'none') native['background-image'] = observation.computed['background-image'];
		}
		// Bricks exposes a native overflow control, while authored horizontal
		// scrollers commonly declare only overflow-x. Preserve their effective
		// computed shorthand so template insertion cannot turn a rail into page
		// overflow.
		if (!native.overflow && (authoredValue(node, observation, 'overflow-x') || authoredValue(node, observation, 'overflow-y'))) {
			const computedOverflow = observation.computed.overflow;
			if (computedOverflow && computedOverflow !== 'visible') native.overflow = computedOverflow;
		}
		for (const [property, rawValue] of Object.entries(declarations)) {
			const value = rawValue.replace(/\s*!important\s*$/i, '');
			if (property.startsWith('--') || NATIVE_STYLE_PROPERTIES.has(property) || LAYOUT_DECLARATIONS.has(property) || SUPPORTED_SHORTHANDS.has(property) || isExpandedDeclarationOwned(property, declarations)) continue;
			if (value && !['initial', 'inherit', 'unset', 'normal', 'none', 'auto', 'static', 'visible', '0s', '0px'].includes(value)) residuals.push(`${property}: ${value}`);
		}
	} else {
		for (const [property, value] of Object.entries(observation.computed)) {
			if (LAYOUT_DECLARATIONS.has(property) || ['width', 'max-width', 'height', 'min-height'].includes(property)) continue;
			if (NATIVE_STYLE_PROPERTIES.has(property)) native[property] = value;
			else if (value && !['normal', 'none', 'auto', 'static', 'visible'].includes(value)) residuals.push(`${property}: ${value}`);
		}
	}
	const authoredWidth = authoredValue(node, observation, 'width');
	const authoredMaxWidth = authoredValue(node, observation, 'max-width');
	const authoredHeight = authoredValue(node, observation, 'height');
	const authoredMinHeight = authoredValue(node, observation, 'min-height');
	if (authoredWidth && authoredWidth !== 'auto') native.width = authoredWidth;
	else if (geometryNode.widthMode === 'clamped') native.width = '100%';
	else if (geometryNode.widthMode === 'fixed' && ['img', 'video', 'iframe', 'input', 'button'].includes(node.tag)) native.width = `${Math.round(observation.box.width * 1000) / 1000}px`;
	if (authoredMaxWidth && authoredMaxWidth !== 'none') native['max-width'] = authoredMaxWidth;
	else if (geometryNode.widthMode === 'clamped') {
		const largest = Math.max(...geometryNode.evidence.filter((item) => item.visible).map((item) => item.box.width));
		if (Number.isFinite(largest) && largest > 0) native['max-width'] = `${Math.round(largest * 1000) / 1000}px`;
	}
	if (authoredHeight && authoredHeight !== 'auto') native.height = authoredHeight;
	else if (geometryNode.widthMode === 'fixed' && ['img', 'video', 'iframe'].includes(node.tag)) native.height = `${Math.round(observation.box.height * 1000) / 1000}px`;
	if (authoredMinHeight && authoredMinHeight !== '0px' && authoredMinHeight !== 'auto') native['min-height'] = authoredMinHeight;
	for (const [property, value] of Object.entries(native)) {
		const token = String(value).match(/var\((--(?:kiwe|seam)-[a-z0-9-]+)\)/i);
		if (token) tokenBindings[property] = token[1];
	}
	return { native, tokenBindings, residuals };
}

function responsiveStyle(node, geometryNode, base, observation) {
	const normalized = normalizeStyle(node, observation, geometryNode).native;
	const baseNative = normalizeStyle(node, base, geometryNode).native;
	return Object.fromEntries(Object.entries(normalized).filter(([property, value]) => baseNative[property] !== value));
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

function resourceKind(resource) {
	if (['image', 'font', 'video', 'audio'].includes(resource.kind)) return resource.kind;
	if (resource.kind === 'stylesheet') return 'stylesheet';
	if (resource.kind === 'script') return 'script';
	if (/^image\//.test(resource.mime || '')) return 'image';
	if (/^font\//.test(resource.mime || '')) return 'font';
	if (/^video\//.test(resource.mime || '')) return 'video';
	if (/^audio\//.test(resource.mime || '')) return 'audio';
	return 'other';
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
	const geometry = solveGeometry(capture);
	const geometryById = new Map(geometry.nodes.map((node) => [node.id, node]));
	const viewportById = new Map(capture.viewports.map((viewport) => [viewport.id, viewport]));
	const components = classifyCapture(capture);
	const residuals = [];
	const nodes = capture.nodes.map((node) => {
		const observation = node.observations.find((item) => item.visible) || node.observations[0];
		const geometryNode = geometryById.get(node.id);
		const component = components.get(node.id);
		const style = normalizeStyle(node, observation, geometryNode);
		style.responsive = node.observations
			.filter((item) => item !== observation)
			.map((item) => {
				const viewport = viewportById.get(item.viewportId);
				return { viewportId: item.viewportId, viewportWidth: viewport.width, breakpoint: breakpointFor(viewport.width), native: responsiveStyle(node, geometryNode, observation, item) };
			})
			.filter((item) => Object.keys(item.native).length > 0);
		for (const residual of style.residuals) residuals.push(`${node.id}: ${residual}`);
		if (component.adapter === 'review' || ['embed', 'form', 'field'].includes(component.semanticType)) {
			for (const limitation of component.limitations) residuals.push(`${node.id}: component: ${limitation}`);
		}
		return {
			id: node.id,
			parentId: node.parentId,
			kind: kindFor(node),
			tag: node.tag,
			text: node.text,
			attributes: node.attributes,
			component,
			layout: {
				display: observation.computed.display || 'block',
				direction: observation.computed['flex-direction'] || 'row',
				wrap: observation.computed['flex-wrap'] || 'nowrap',
				gap: observation.computed.gap || '0px',
				widthMode: geometryNode.widthMode,
				responsive: node.observations.map((item) => {
					const viewport = viewportById.get(item.viewportId);
					return {
						viewportId: item.viewportId, viewportWidth: viewport.width, breakpoint: breakpointFor(viewport.width), visible: item.visible,
						display: item.computed.display || 'block', direction: item.computed['flex-direction'] || 'row',
						wrap: item.computed['flex-wrap'] || 'nowrap', gap: item.computed.gap || '0px', width: item.box.width, height: item.box.height
					};
				})
			},
			style,
			provenance: {
				captureNodeIds: [node.id],
				viewportIds: node.observations.map((item) => item.viewportId),
				selector: node.provenance.selector
			}
		};
	});

	const variables = new Map();
	for (const node of capture.nodes) for (const observation of node.observations) {
		for (const [name, value] of Object.entries(observation.customProperties || {})) {
			if (/^--(?:kiwe|seam)-/i.test(name) || !/^--[a-z]/i.test(name)) continue;
			if (!variables.has(name) && value) variables.set(name, value);
		}
	}
	const pageIr = {
		schema: 'seam.page-ir.v1', sourceHash: capture.source.contentHash,
		variables: [...variables].sort(([left], [right]) => left.localeCompare(right)).map(([name, value]) => ({ name, value })),
		nodes, residuals: [...new Set(residuals)]
	};
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
	for (const resource of capture.resources || []) {
		if (resource.kind === 'document') continue;
		const id = deterministicId('asset', resource.url);
		const existing = assetsById.get(id);
		const usedBy = capture.nodes
			.filter((node) => [node.attributes.src, node.attributes.poster, node.attributes.href].includes(resource.url))
			.map((node) => node.id);
		if (existing) {
			existing.contentHash = resource.sha256 ? `sha256:${resource.sha256}` : existing.contentHash;
			existing.mime = resource.mime || existing.mime;
			existing.bytes = resource.bytes ?? existing.bytes;
			existing.policy = resource.blocked ? 'blocked' : existing.policy;
			continue;
		}
		assetsById.set(id, {
			id, kind: resourceKind(resource), source: resource.url,
			contentHash: resource.sha256 ? `sha256:${resource.sha256}` : null, mime: resource.mime, bytes: resource.bytes,
			policy: resource.blocked ? 'blocked' : /^data:/i.test(resource.url) ? 'inline' : /^https?:/i.test(resource.url) ? 'review' : 'import',
			usedBy: usedBy.length ? usedBy : ['document']
		});
	}
	const assetManifest = { schema: 'seam.asset-manifest.v1', sourceHash: capture.source.contentHash, assets: [...assetsById.values()] };

	assertContract('pageIr', pageIr);
	assertContract('behaviorIr', behaviorIr);
	assertContract('assetManifest', assetManifest);
	assertContract('geometry', geometry);
	return { pageIr, behaviorIr, assetManifest, geometry };
}

module.exports = { assertContract, deterministicId, normalizeCapture };
