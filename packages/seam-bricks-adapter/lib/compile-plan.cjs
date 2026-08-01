const { createHash } = require('node:crypto');
const { assertContract } = require('../../seam-compiler-core/lib/normalize-capture.cjs');

const KIND_TO_ELEMENT = {
	section: 'section', container: 'div', heading: 'heading', text: 'text-basic', link: 'text-link',
	button: 'button', image: 'image', video: 'video', list: 'block', 'list-item': 'block',
	form: 'form', input: 'block', table: 'block', generic: 'div'
};

function shortId(value) {
	const prefix = createHash('sha256').update(String(value)).digest('hex').slice(0, 12);
	return BigInt(`0x${prefix}`).toString(36).padStart(6, '0').slice(0, 6);
}

function color(value) {
	return { raw: value };
}

function box(value) {
	return { top: value, right: value, bottom: value, left: value };
}

function mapStyle(node) {
	const source = node.style.native;
	const settings = {};
	const owned = [];
	const frameworkOwned = [];
	const set = (key, value) => {
		if (value === undefined || value === null || value === '') return;
		settings[key] = value;
		owned.push(key);
	};

	set('_display', node.layout.display);
	if (['flex', 'grid'].includes(node.layout.display)) {
		if (node.layout.display === 'flex') {
			set('_direction', node.layout.direction);
			set('_flexWrap', node.layout.wrap);
		}
		if (node.layout.gap && node.layout.gap !== '0px') {
			set('_columnGap', node.layout.gap);
			set('_rowGap', node.layout.gap);
		}
	}
	set('_gridTemplateColumns', source['grid-template-columns']);
	set('_gridTemplateRows', source['grid-template-rows']);
	set('_justifyContent', source['justify-content']);
	set('_alignItems', source['align-items']);
	set('_width', source.width);
	set('_widthMax', source['max-width']);
	set('_height', source.height);
	set('_heightMin', source['min-height']);
	set('_opacity', source.opacity);
	set('_objectFit', source['object-fit']);
	set('_objectPosition', source['object-position']);

	const padding = ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'].map((key) => source[key]);
	if (padding.some(Boolean)) set('_padding', { top: padding[0] || '0', right: padding[1] || '0', bottom: padding[2] || '0', left: padding[3] || '0' });
	const margin = ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'].map((key) => source[key]);
	if (margin.some(Boolean)) set('_margin', { top: margin[0] || '0', right: margin[1] || '0', bottom: margin[2] || '0', left: margin[3] || '0' });

	const typography = {};
	for (const key of ['font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'line-height', 'text-align', 'text-decoration', 'text-transform']) {
		if (!source[key]) continue;
		if (
			(key === 'font-family' && /^var\(--(?:kiwe|seam)-/i.test(source[key]))
			|| (node.kind === 'heading' && key === 'font-size' && /^var\(--kiwe-type-h[1-6]\)$/i.test(source[key]))
		) frameworkOwned.push(`${key}: ${source[key]}`);
		else typography[key] = source[key];
	}
	if (source.color) typography.color = color(source.color);
	if (Object.keys(typography).length) set('_typography', typography);
	if (source['background-color']) set('_background', { color: color(source['background-color']) });

	const border = {};
	if (source['border-width']) border.width = box(source['border-width']);
	if (source['border-style']) border.style = source['border-style'];
	if (source['border-color']) border.color = color(source['border-color']);
	if (source['border-radius']) border.radius = box(source['border-radius']);
	if (Object.keys(border).length) set('_border', border);

	return { settings, owned, frameworkOwned };
}

function contentSettings(node, type) {
	const settings = {};
	if (['heading', 'text-basic', 'text-link', 'button'].includes(type)) settings.text = node.text;
	if (type === 'heading') settings.tag = node.tag;
	if (type === 'text-basic' && node.tag !== 'p') settings.tag = node.tag;
	if (type === 'text-link' && node.attributes.href) settings.link = { type: 'external', url: node.attributes.href };
	if (type === 'button' && node.attributes.href) settings.link = { type: 'external', url: node.attributes.href };
	if (type === 'image') {
		const src = node.attributes.src || '';
		settings.image = { url: src, full: src, size: 'full', filename: src.split('/').pop()?.split('?')[0] || 'image' };
		if (node.attributes.alt) settings.altText = node.attributes.alt;
	}
	if (['section', 'div', 'block'].includes(type) && !['div', 'section'].includes(node.tag)) settings.tag = node.tag;
	const reserved = new Set(['src', 'alt', 'href', 'class', 'id', 'style']);
	const attributes = Object.entries(node.attributes)
		.filter(([name]) => !reserved.has(name) && !name.startsWith('on'))
		.map(([name, value], index) => ({ id: shortId(`${node.id}:${name}:${index}`), name, value }));
	if (attributes.length) settings._attributes = attributes;
	if (node.attributes.id) settings._cssId = node.attributes.id;
	return settings;
}

function compileBricksPlan(pageIr, capabilityProfile) {
	assertContract('pageIr', pageIr);
	if (!capabilityProfile || capabilityProfile.schema !== 'seam.bricks-capability-profile.v1') throw new Error('A SEAM Bricks capability profile is required.');
	const available = new Map(capabilityProfile.elements.map((element) => [element.name, element]));
	const ids = new Map(pageIr.nodes.map((node) => [node.id, shortId(`element:${pageIr.sourceHash}:${node.id}`)]));
	if (new Set(ids.values()).size !== ids.size) {
		const error = new Error('Deterministic Bricks element ID collision; compilation stopped before serialization.');
		error.code = 'SEAM_BRICKS_ID_COLLISION';
		throw error;
	}
	const children = new Map(pageIr.nodes.map((node) => [node.id, []]));
	for (const node of pageIr.nodes) if (node.parentId && children.has(node.parentId)) children.get(node.parentId).push(ids.get(node.id));
	const residuals = [...pageIr.residuals];
	let nativeOwners = 0;

	const elements = pageIr.nodes.map((node) => {
		const preferred = KIND_TO_ELEMENT[node.kind] || 'div';
		const type = available.has(preferred) ? preferred : available.has('div') ? 'div' : preferred;
		if (type !== preferred) residuals.push(`${node.id}: Bricks element ${preferred} unavailable; used ${type}.`);
		const { settings: styleSettings, owned, frameworkOwned } = mapStyle(node);
		for (const item of frameworkOwned) residuals.push(`${node.id}: ${item} remains Framework-owned and is not written into Bricks typography controls.`);
		const elementControls = new Set([...(capabilityProfile.baseControls || []), ...(available.get(type)?.controls || [])]);
		const provenStyle = {};
		for (const [key, value] of Object.entries(styleSettings)) {
			if (elementControls.has(key)) provenStyle[key] = value;
			else residuals.push(`${node.id}: control ${key} is absent from Bricks ${capabilityProfile.bricksVersion} capability profile.`);
		}
		const settings = { ...contentSettings(node, type), ...provenStyle };
		const ownedControls = [...new Set([...Object.keys(settings), ...owned.filter((key) => Object.prototype.hasOwnProperty.call(provenStyle, key))])].sort();
		if (ownedControls.length) nativeOwners += 1;
		return {
			id: ids.get(node.id), type, parentId: node.parentId ? ids.get(node.parentId) : null,
			children: children.get(node.id) || [], settings,
			provenance: { pageNodeId: node.id, captureNodeIds: node.provenance.captureNodeIds, selector: node.provenance.selector, ownedControls }
		};
	});

	const plan = {
		schema: 'seam.bricks-plan.v1', compiler: 'seam-compiler', aiGenerated: false, sourceHash: pageIr.sourceHash,
		target: { bricksVersion: capabilityProfile.bricksVersion, capabilityProfileHash: capabilityProfile.profileHash },
		elements, globalClasses: [], variables: [], residuals,
		metrics: { nativeCoverage: Math.round((nativeOwners / Math.max(elements.length, 1)) * 1000) / 10, residualCount: residuals.length }
	};
	assertContract('bricksPlan', plan);
	return plan;
}

module.exports = { compileBricksPlan, mapStyle, shortId };
