function descendants(nodeId, childrenByParent) {
	const output = [];
	const visit = (id) => {
		for (const child of childrenByParent.get(id) || []) {
			output.push(child);
			visit(child.id);
		}
	};
	visit(nodeId);
	return output;
}

function subtreeText(node, childrenByParent) {
	return [node, ...descendants(node.id, childrenByParent)].map((item) => item.text).filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
}

function aggregateList(node, childrenByParent) {
	if (node.tag !== 'ul' || node.component?.adapter !== 'aggregate-list') return null;
	const items = childrenByParent.get(node.id) || [];
	if (!items.length || items.some((item) => item.tag !== 'li')) return null;
	const allowed = new Set(['a', 'span', 'strong', 'em', 'small', 'time']);
	const output = [];
	const consumed = [];
	for (const item of items) {
		const nested = descendants(item.id, childrenByParent);
		if (nested.some((child) => !allowed.has(child.tag))) return null;
		if ([item, ...nested].some((child) => child.style?.residuals?.length || Object.keys(child.style?.native || {}).length)) return null;
		const links = nested.filter((child) => child.tag === 'a' && child.attributes.href);
		if (links.length > 1) return null;
		const title = subtreeText(item, childrenByParent);
		if (!title) return null;
		const record = { title };
		if (links[0]) record.link = { type: 'external', url: links[0].attributes.href };
		output.push(record);
		consumed.push(item, ...nested);
	}
	return { type: 'list', consumed, settings: { items: output, separatorDisable: true } };
}

const SVG_TAGS = new Set(['svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'use', 'symbol', 'title', 'desc', 'defs', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'stop']);
const SVG_CANONICAL_TAGS = { clippath: 'clipPath', lineargradient: 'linearGradient', radialgradient: 'radialGradient' };

function escapeMarkup(value, attribute = false) {
	return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(attribute ? /"/g : /$^/, '&quot;');
}

function safeSvgAttributes(attributes) {
	return Object.entries(attributes || {}).filter(([name, value]) => {
		if (/^on/i.test(name) || ['nonce', 'integrity'].includes(name.toLowerCase())) return false;
		if (['href', 'xlink:href'].includes(name.toLowerCase()) && /^\s*(?:javascript:|data:text\/html)/i.test(value)) return false;
		if (name.toLowerCase() === 'style' && /(?:expression\s*\(|javascript:)/i.test(value)) return false;
		return /^[a-z_:][a-z0-9_.:-]*$/i.test(name);
	}).map(([name, value]) => ` ${name}="${escapeMarkup(value, true)}"`).join('');
}

function serializeSvgNode(node, childrenByParent) {
	const tag = String(node.tag || '').toLowerCase();
	if (!SVG_TAGS.has(tag)) return '';
	const outputTag = SVG_CANONICAL_TAGS[tag] || tag;
	const children = (childrenByParent.get(node.id) || []).map((child) => serializeSvgNode(child, childrenByParent)).join('');
	const text = ['title', 'desc'].includes(tag) ? escapeMarkup(node.text) : '';
	return `<${outputTag}${safeSvgAttributes(node.attributes)}>${text}${children}</${outputTag}>`;
}

function aggregateSvg(node, childrenByParent) {
	if (node.tag !== 'svg' || node.component?.adapter !== 'aggregate-svg') return null;
	const nested = descendants(node.id, childrenByParent);
	if (nested.some((child) => !SVG_TAGS.has(String(child.tag || '').toLowerCase()))) return null;
	const code = serializeSvgNode(node, childrenByParent);
	if (!code) return null;
	return { type: 'svg', consumed: nested, settings: { source: 'code', code } };
}

function mediaSource(node, childrenByParent) {
	return node.attributes.src || descendants(node.id, childrenByParent).find((child) => child.tag === 'source' && child.attributes.src)?.attributes.src || '';
}

function youtubeId(source) {
	return String(source || '').match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:embed\/|shorts\/|watch\?(?:[^#]*&)?v=))([a-zA-Z0-9_-]{6,})/)?.[1] || '';
}

function vimeoId(source) {
	return String(source || '').match(/vimeo\.com\/(?:video\/)?([0-9]+)/)?.[1] || '';
}

function booleanAttribute(attributes, name) {
	return Object.prototype.hasOwnProperty.call(attributes || {}, name);
}

function mediaAdapter(node, childrenByParent) {
	if (node.component?.adapter !== 'direct-media') return null;
	const source = mediaSource(node, childrenByParent);
	if (!source) return null;
	const nested = descendants(node.id, childrenByParent);
	if (nested.some((child) => child.tag !== 'source')) return null;
	if (node.component.semanticType === 'audio') {
		const settings = { source: 'external', external: source };
		if (booleanAttribute(node.attributes, 'autoplay')) settings.autoplay = true;
		if (booleanAttribute(node.attributes, 'loop')) settings.loop = true;
		if (node.attributes.preload && node.attributes.preload !== 'none') settings.preload = node.attributes.preload;
		return { type: 'audio', consumed: nested, settings };
	}
	const youtube = youtubeId(source);
	const vimeo = vimeoId(source);
	const settings = {};
	if (youtube) {
		settings.videoType = 'youtube';
		settings.youTubeId = youtube;
		settings.youtubeControls = true;
		if (node.attributes.title) settings.iframeTitle = node.attributes.title;
	} else if (vimeo) {
		settings.videoType = 'vimeo';
		settings.vimeoId = vimeo;
		if (node.attributes.title) settings.iframeTitle = node.attributes.title;
	} else {
		settings.videoType = 'file';
		settings.fileUrl = source;
		if (booleanAttribute(node.attributes, 'controls')) settings.fileControls = true;
		if (booleanAttribute(node.attributes, 'autoplay')) settings.fileAutoplay = true;
		if (booleanAttribute(node.attributes, 'loop')) settings.fileLoop = true;
		if (booleanAttribute(node.attributes, 'muted')) settings.fileMute = true;
		if (booleanAttribute(node.attributes, 'playsinline')) settings.fileInline = true;
		if (node.attributes.preload) settings.filePreload = node.attributes.preload;
		if (node.attributes.poster) settings.videoPoster = { url: node.attributes.poster, full: node.attributes.poster, size: 'full' };
	}
	return { type: 'video', consumed: nested, settings };
}

function planComponentAdapters(pageIr) {
	const childrenByParent = new Map(pageIr.nodes.map((node) => [node.id, []]));
	for (const node of pageIr.nodes) if (node.parentId && childrenByParent.has(node.parentId)) childrenByParent.get(node.parentId).push(node);
	const adapters = new Map();
	const consumedBy = new Map();
	for (const node of pageIr.nodes) {
		if (consumedBy.has(node.id)) continue;
		const adapter = aggregateList(node, childrenByParent) || aggregateSvg(node, childrenByParent) || mediaAdapter(node, childrenByParent);
		if (!adapter) continue;
		adapter.captureNodeIds = [node.id, ...adapter.consumed.map((item) => item.id)];
		adapters.set(node.id, adapter);
		for (const consumed of adapter.consumed) consumedBy.set(consumed.id, node.id);
	}
	return { adapters, consumedBy };
}

module.exports = { aggregateList, aggregateSvg, descendants, mediaAdapter, planComponentAdapters, safeSvgAttributes, serializeSvgNode, subtreeText, vimeoId, youtubeId };
