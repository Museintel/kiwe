const { createHash } = require('node:crypto');
const { assertContract } = require('../../seam-compiler-core/lib/normalize-capture.cjs');
const { planComponentAdapters } = require('./component-adapters.cjs');

const KIND_TO_ELEMENT = {
	section: 'section', container: 'div', heading: 'heading', text: 'text-basic', link: 'text-link',
	button: 'button', image: 'image', video: 'video', list: 'block', 'list-item': 'block',
	form: 'form', input: 'block', table: 'block', generic: 'div'
};

const CUSTOM_CSS_EXCEPTIONS = new Set(['backdrop-filter', 'overflow-x', 'overflow-y', 'scroll-behavior']);

function shortId(value) {
	const prefix = createHash('sha256').update(String(value)).digest('hex').slice(0, 12);
	return BigInt(`0x${prefix}`).toString(36).padStart(6, '0').slice(0, 6);
}

function color(value) {
	return { raw: value };
}

function box(value) {
	const normalized = value === '0px' || value === '0rem' || value === '0em' ? '0' : value;
	return { top: normalized, right: normalized, bottom: normalized, left: normalized };
}

function splitTopLevel(value, separator = ',') {
	const parts = [];
	let depth = 0;
	let start = 0;
	for (let index = 0; index < value.length; index += 1) {
		if (value[index] === '(') depth += 1;
		else if (value[index] === ')') depth -= 1;
		else if (value[index] === separator && depth === 0) {
			parts.push(value.slice(start, index).trim());
			start = index + 1;
		}
	}
	parts.push(value.slice(start).trim());
	return parts.filter(Boolean);
}

function cssFunctions(value) {
	const functions = [];
	let index = 0;
	while (index < value.length) {
		const match = value.slice(index).match(/^\s*([a-z-]+)\(/i);
		if (!match) break;
		const name = match[1];
		const open = index + match[0].lastIndexOf('(');
		let depth = 1;
		let close = open + 1;
		while (close < value.length && depth) {
			if (value[close] === '(') depth += 1;
			else if (value[close] === ')') depth -= 1;
			close += 1;
		}
		if (depth) return [];
		functions.push({ name, value: value.slice(open + 1, close - 1).trim() });
		index = close;
	}
	return functions;
}

function parseGradient(value) {
	const match = String(value || '').match(/^(repeating-)?(linear|radial|conic)-gradient\((.*)\)$/i);
	if (!match) return null;
	const parts = splitTopLevel(match[3]);
	const gradient = { gradientType: match[2].toLowerCase(), colors: [] };
	if (match[1]) gradient.repeat = true;
	if (gradient.gradientType === 'linear' && /^-?[\d.]+deg$/i.test(parts[0])) gradient.angle = Number(parts.shift().slice(0, -3));
	for (const part of parts) {
		const colorMatch = part.match(/^(.*?)(?:\s+(-?[\d.]+%))?$/);
		const item = { color: color(colorMatch[1].trim()) };
		if (colorMatch[2]) item.stop = Number(colorMatch[2].slice(0, -1));
		gradient.colors.push(item);
	}
	return gradient.colors.length ? gradient : null;
}

function parseTransform(value) {
	const result = {};
	for (const item of cssFunctions(String(value || ''))) {
		let name = item.name;
		let parsed = item.value;
		if (name === 'rotate') name = 'rotateZ';
		if (['rotateX', 'rotateY', 'rotateZ', 'skewX', 'skewY'].includes(name) && /^-?[\d.]+deg$/i.test(parsed)) parsed = Number(parsed.slice(0, -3));
		if (['translateX', 'translateY', 'perspective', 'scale', 'scaleX', 'scaleY'].includes(name) || ['rotateX', 'rotateY', 'rotateZ', 'skewX', 'skewY'].includes(name)) result[name] = parsed;
	}
	return Object.keys(result).length ? result : null;
}

function parseFilters(value) {
	const result = {};
	for (const item of cssFunctions(String(value || ''))) {
		let parsed = item.value;
		if (item.name === 'blur' && /^-?[\d.]+px$/.test(parsed)) parsed = Number(parsed.slice(0, -2));
		else if (['brightness', 'contrast', 'invert', 'opacity', 'saturate', 'sepia'].includes(item.name) && /^-?[\d.]+%$/.test(parsed)) parsed = Number(parsed.slice(0, -1));
		else if (item.name === 'hue-rotate' && /^-?[\d.]+deg$/.test(parsed)) parsed = Number(parsed.slice(0, -3));
		result[item.name] = parsed;
	}
	return Object.keys(result).length ? result : null;
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
			if (node.layout.direction && (node.hasChildren || ['container', 'section'].includes(node.kind) || node.layout.direction !== 'row')) set('_direction', node.layout.direction);
			if (node.layout.wrap && (node.hasChildren || node.layout.wrap !== 'nowrap')) set('_flexWrap', node.layout.wrap);
		}
		if (node.hasChildren && node.layout.gap && node.layout.gap !== '0px') {
			set('_columnGap', node.layout.gap);
			set('_rowGap', node.layout.gap);
		}
	}
	set('_gridTemplateColumns', source['grid-template-columns']);
	set('_gridTemplateRows', source['grid-template-rows']);
	set('_gridAutoColumns', source['grid-auto-columns']);
	set('_gridAutoRows', source['grid-auto-rows']);
	set('_gridAutoFlow', source['grid-auto-flow']);
	if (
		node.layout.display === 'grid'
		&& (node.hasChildren || ['container', 'section'].includes(node.kind))
		&& !source['grid-template-columns']
		&& !source['grid-auto-columns']
	) set('_gridAutoColumns', 'auto');
	set('_gridItemColumnSpan', source['grid-column']);
	set('_justifyContent', source['justify-content']);
	set('_alignItems', source['align-items']);
	set('_alignSelf', source['align-self']);
	set('_alignContentGrid', source['align-content']);
	set('_justifyItemsGrid', source['justify-items']);
	set('_width', source.width);
	set('_widthMin', source['min-width']);
	set('_widthMax', source['max-width']);
	set('_height', source.height);
	set('_heightMin', source['min-height']);
	set('_heightMax', source['max-height']);
	set('_aspectRatio', source['aspect-ratio']);
	set('_position', source.position);
	set('_top', source.top);
	set('_right', source.right);
	set('_bottom', source.bottom);
	set('_left', source.left);
	set('_zIndex', source['z-index']);
	set('_overflow', source.overflow);
	set('_visibility', source.visibility);
	set('_cursor', source.cursor);
	set('_isolation', source.isolation);
	set('_scrollSnapType', source['scroll-snap-type']);
	set('_scrollSnapAlign', source['scroll-snap-align']);
	set('_scrollSnapStop', source['scroll-snap-stop']);
	set('_cssTransition', source.transition);
	set('_flexGrow', source['flex-grow']);
	set('_flexShrink', source['flex-shrink']);
	set('_transform', parseTransform(source.transform));
	set('_cssFilters', parseFilters(source.filter));
	set('_opacity', source.opacity);
	set('_objectFit', source['object-fit']);
	set('_objectPosition', source['object-position']);

	const padding = ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'].map((key) => source[key]);
	if (padding.some(Boolean)) set('_padding', { top: padding[0] || '0', right: padding[1] || '0', bottom: padding[2] || '0', left: padding[3] || '0' });
	const margin = ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'].map((key) => source[key]);
	if (margin.some(Boolean)) set('_margin', { top: margin[0] || '0', right: margin[1] || '0', bottom: margin[2] || '0', left: margin[3] || '0' });

	const typography = {};
	for (const key of ['font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'line-height', 'text-align', 'text-decoration', 'text-overflow', 'text-transform', 'white-space']) {
		if (!source[key]) continue;
		if (
			(key === 'font-family' && /^var\(--(?:kiwe|seam)-/i.test(source[key]))
			|| (node.kind === 'heading' && key === 'font-size' && /^var\(--kiwe-type-h[1-6]\)$/i.test(source[key]))
		) frameworkOwned.push(`${key}: ${source[key]}`);
		else typography[key] = source[key];
	}
	if (source.color) typography.color = color(source.color);
	if (Object.keys(typography).length) set('_typography', typography);
	const background = {};
	if (source['background-color'] && !/^rgba?\(0, 0, 0, 0\)$/.test(source['background-color']) && source['background-color'] !== 'transparent') background.color = color(source['background-color']);
	const backgroundUrl = source['background-image']?.match(/^url\(["']?(.*?)["']?\)$/i)?.[1];
	if (backgroundUrl) background.image = { url: backgroundUrl, full: backgroundUrl, external: backgroundUrl };
	if (Object.keys(background).length) set('_background', background);
	set('_gradient', parseGradient(source['background-image']));

	const border = {};
	if (source['border-width'] && source['border-width'] !== '0px') border.width = box(source['border-width']);
	if (source['border-style'] && source['border-style'] !== 'none') border.style = source['border-style'];
	if (source['border-color'] && source['border-color'] !== 'transparent') border.color = color(source['border-color']);
	if (source['border-radius'] && source['border-radius'] !== '0px') border.radius = box(source['border-radius']);
	if (Object.keys(border).length) set('_border', border);

	return { settings, owned, frameworkOwned };
}

function responsiveSettings(node, baseSettings) {
	const layoutStates = (node.layout.responsive || [])
		.filter((state) => state.breakpoint)
		.sort((left, right) => right.viewportWidth - left.viewportWidth);
	const representative = new Map();
	for (const state of layoutStates) if (!representative.has(state.breakpoint)) representative.set(state.breakpoint, state);
	const styleByViewport = new Map((node.style.responsive || []).map((state) => [state.viewportId, state.native]));
	const settings = {};
	const owned = [];
	const frameworkOwned = [];
	for (const state of representative.values()) {
		const responsiveNode = {
			...node,
			layout: { ...node.layout, display: state.display, direction: state.direction, wrap: state.wrap, gap: state.gap },
			style: { ...node.style, native: { ...node.style.native, ...(styleByViewport.get(state.viewportId) || {}) } }
		};
		const mapped = mapStyle(responsiveNode);
		frameworkOwned.push(...mapped.frameworkOwned.map((item) => `${state.breakpoint}: ${item}`));
		for (const [key, value] of Object.entries(mapped.settings)) {
			if (JSON.stringify(value) === JSON.stringify(baseSettings[key])) continue;
			const responsiveKey = `${key}:${state.breakpoint}`;
			settings[responsiveKey] = value;
			owned.push(responsiveKey);
		}
	}
	return { settings, owned, frameworkOwned };
}

function contentSettings(node, type) {
	const settings = {};
	if (['heading', 'text-basic', 'text-link', 'button'].includes(type)) settings.text = node.text;
	if (type === 'heading') settings.tag = node.tag;
	if (type === 'text-basic' && node.tag !== 'p') settings.tag = node.tag;
	if (['text-link', 'div', 'block'].includes(type) && node.attributes.href) settings.link = { type: 'external', url: node.attributes.href };
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

function createNativeVariableRegistry(pageIr) {
	const names = new Set();
	const sourceToName = new Map();
	const records = (pageIr.variables || []).map((variable) => {
		const sourceName = String(variable.name || '').replace(/^--/, '');
		const safeSourceName = sourceName.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^[^a-z]+|[-_]+$/g, '').slice(0, 64) || 'variable';
		let name = /^[a-z][a-z0-9]*-[a-z0-9][a-z0-9_-]{0,80}$/.test(safeSourceName) && !/^(?:kiwe|seam)-/.test(safeSourceName)
			? safeSourceName
			: `appsite-${safeSourceName}`;
		if (names.has(name)) name = `${name.slice(0, 69)}-${createHash('sha256').update(sourceName).digest('hex').slice(0, 10)}`;
		names.add(name);
		sourceToName.set(sourceName, name);
		return { id: shortId(`variable:${pageIr.sourceHash}:${variable.name}`), name, value: variable.value };
	});
	const valueToName = new Map(records.map((record) => [String(record.value).trim().replace(/\s+/g, ' '), record.name]));
	function tokenFor(value) {
		if (typeof value !== 'string') return value;
		const rewritten = value.replace(/var\(\s*--([a-z0-9_-]+)/gi, (match, sourceName) =>
			`var(--${sourceToName.get(sourceName) || sourceName}`
		);
		if (/var\(\s*--[a-z0-9_-]+/i.test(rewritten)) return rewritten;
		const normalized = rewritten.trim().replace(/\s+/g, ' ');
		if (/^-?0(?:\.0+)?(?:px|rem|em|%|vw|vh|vmin|vmax|fr|ch|ex|s|ms|deg)?$/i.test(normalized)) return '0';
		const colorLiteral = /#[a-f0-9]{3,8}\b|\b(?:rgb|hsl)a?\(/i.test(normalized);
		const dimensionLiteral = /(?:^|[\s,(])-?\.?\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh|vmin|vmax|fr|ch|ex|s|ms)\b/i.test(normalized);
		if (!colorLiteral && !dimensionLiteral) return value;
		let name = valueToName.get(normalized);
		if (!name) {
			const domain = colorLiteral && !dimensionLiteral ? 'color' : 'value';
			const digest = createHash('sha256').update(normalized).digest('hex').slice(0, 10);
			name = `appsite-auto-${domain}-${digest}`;
			let suffix = 2;
			while (names.has(name)) name = `appsite-auto-${domain}-${digest}-${suffix++}`;
			names.add(name);
			valueToName.set(normalized, name);
			records.push({ id: shortId(`variable:${pageIr.sourceHash}:--${name}`), name, value: normalized });
		}
		return `var(--${name})`;
	}
	function tokenize(value, key = '') {
		if (Array.isArray(value)) return value.map((item) => tokenize(item, key));
		if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([childKey, child]) => [childKey, tokenize(child, childKey)]));
		if (['url', 'full', 'external', 'filename'].includes(key)) return value;
		return tokenFor(value);
	}
	return { records, tokenFor, tokenize };
}

function compileCustomCss(residuals, elements, variables = { tokenFor: (value) => value }) {
	const elementIdByNode = new Map(elements.map((element) => [element.provenance.pageNodeId, element.id]));
	const declarationsByElement = new Map();
	for (const residual of residuals) {
		const match = residual.match(/^([^:]+):\s*([a-z-]+):\s*(.+)$/i);
		if (!match || !CUSTOM_CSS_EXCEPTIONS.has(match[2]) || !elementIdByNode.has(match[1])) continue;
		if (!declarationsByElement.has(match[1])) declarationsByElement.set(match[1], []);
		declarationsByElement.get(match[1]).push(`${match[2]}: ${variables.tokenFor(match[3])};`);
	}
	const rules = [...declarationsByElement].map(([nodeId, declarations]) =>
		`#brxe-${elementIdByNode.get(nodeId)} { ${declarations.join(' ')} }`
	);
	return { css: rules.join('\n'), declarations: [...declarationsByElement.values()].reduce((count, items) => count + items.length, 0) };
}

function compileBricksPlan(pageIr, capabilityProfile) {
	assertContract('pageIr', pageIr);
	if (!capabilityProfile || capabilityProfile.schema !== 'seam.bricks-capability-profile.v1') throw new Error('A SEAM Bricks capability profile is required.');
	const available = new Map(capabilityProfile.elements.map((element) => [element.name, element]));
	const { adapters, consumedBy } = planComponentAdapters(pageIr);
	const activeNodes = pageIr.nodes.filter((node) => !consumedBy.has(node.id));
	const ids = new Map(activeNodes.map((node) => [node.id, shortId(`element:${pageIr.sourceHash}:${node.id}`)]));
	if (new Set(ids.values()).size !== ids.size) {
		const error = new Error('Deterministic Bricks element ID collision; compilation stopped before serialization.');
		error.code = 'SEAM_BRICKS_ID_COLLISION';
		throw error;
	}
	const children = new Map(activeNodes.map((node) => [node.id, []]));
	for (const node of activeNodes) if (node.parentId && children.has(node.parentId)) children.get(node.parentId).push(ids.get(node.id));
	const residuals = [...pageIr.residuals];
	const variables = createNativeVariableRegistry(pageIr);
	const adapterReviewNodeIds = new Set(pageIr.nodes.filter((node) => node.component.adapter === 'review').map((node) => node.id));
	let ownedControlCount = 0;

	const elements = activeNodes.map((node) => {
		const nodeChildren = children.get(node.id) || [];
		const adapter = adapters.get(node.id);
		const failedSpecializedAdapter = !adapter && ['aggregate-svg', 'direct-media'].includes(node.component.adapter);
		if (failedSpecializedAdapter) {
			adapterReviewNodeIds.add(node.id);
			residuals.push(`${node.id}: component: ${node.component.adapter} preconditions were not met; semantic fallback requires review.`);
		}
		let preferred = adapter?.type || node.component?.preferredElement || KIND_TO_ELEMENT[node.kind] || 'div';
		if (!adapter && nodeChildren.length && available.get(preferred)?.nestable === false && available.has('div')) preferred = 'div';
		const type = available.has(preferred) ? preferred : available.has('div') ? 'div' : preferred;
		if (type !== preferred) residuals.push(`${node.id}: Bricks element ${preferred} unavailable; used ${type}.`);
		const styleNode = { ...node, hasChildren: nodeChildren.length > 0 };
		const { settings: styleSettings, owned, frameworkOwned } = mapStyle(styleNode);
		const responsive = responsiveSettings(styleNode, styleSettings);
		Object.assign(styleSettings, responsive.settings);
		owned.push(...responsive.owned);
		frameworkOwned.push(...responsive.frameworkOwned);
		for (const item of frameworkOwned) residuals.push(`${node.id}: ${item} remains Framework-owned and is not written into Bricks typography controls.`);
		const elementControls = new Set([...(capabilityProfile.baseControls || []), ...(available.get(type)?.controls || [])]);
		const provenStyle = {};
		for (const [key, value] of Object.entries(styleSettings)) {
			const baseControl = key.split(':')[0];
			if (elementControls.has(baseControl)) provenStyle[key] = value;
			else if (baseControl === '_justifyItemsGrid' && nodeChildren.length === 0 && elementControls.has('_justifyContent')) {
				const suffix = key.slice(baseControl.length);
				provenStyle[`_justifyContent${suffix}`] = value;
			}
			else residuals.push(`${node.id}: control ${key} is absent from Bricks ${capabilityProfile.bricksVersion} capability profile.`);
		}
		const settings = { ...contentSettings(node, type), ...(adapter?.settings || {}), ...variables.tokenize(provenStyle) };
		const ownedControls = [...new Set([...Object.keys(settings), ...owned.filter((key) => Object.prototype.hasOwnProperty.call(provenStyle, key))])].sort();
		ownedControlCount += ownedControls.length;
		return {
			id: ids.get(node.id), type, parentId: node.parentId ? ids.get(node.parentId) : null,
			children: nodeChildren, settings,
			provenance: {
				pageNodeId: node.id,
				captureNodeIds: adapter?.captureNodeIds || node.provenance.captureNodeIds,
				selector: node.provenance.selector,
				ownedControls,
				component: {
					semanticType: node.component.semanticType,
					adapter: failedSpecializedAdapter ? 'review-fallback' : node.component.adapter,
					confidence: node.component.confidence,
					evidence: node.component.evidence
				}
			}
		};
	});

	const customCss = compileCustomCss(residuals, elements, variables);
	const reviewComponentCount = adapterReviewNodeIds.size;
	const plan = {
		schema: 'seam.bricks-plan.v1', compiler: 'seam-compiler', aiGenerated: false, sourceHash: pageIr.sourceHash,
		target: { bricksVersion: capabilityProfile.bricksVersion, capabilityProfileHash: capabilityProfile.profileHash },
		elements, globalClasses: [], variables: variables.records, customCss: customCss.css, residuals,
		ownership: {
			policy: 'element-native-single-owner', elementNativeControls: ownedControlCount,
			frameworkVariables: variables.records.length, customCssDeclarations: customCss.declarations, conflicts: []
		},
		metrics: {
			nativeCoverage: Math.round((ownedControlCount / Math.max(ownedControlCount + residuals.length, 1)) * 1000) / 10,
			residualCount: residuals.length,
			customCssDeclarations: customCss.declarations,
			sourceNodeCount: pageIr.nodes.length,
			nativeElementCount: elements.length,
			aggregatedNodeCount: pageIr.nodes.length - elements.length,
			semanticCoverage: Math.round(((pageIr.nodes.length - reviewComponentCount) / pageIr.nodes.length) * 1000) / 10,
			reviewComponentCount
		}
	};
	assertContract('bricksPlan', plan);
	return plan;
}

module.exports = { compileBricksPlan, compileCustomCss, createNativeVariableRegistry, cssFunctions, mapStyle, parseFilters, parseGradient, parseTransform, responsiveSettings, shortId, splitTopLevel };
