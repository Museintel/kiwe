const TEXT_TAGS = new Set(['p', 'span', 'strong', 'em', 'small', 'label', 'time', 'figcaption', 'blockquote', 'cite', 'code', 'pre']);
const LANDMARK_TAGS = new Set(['main', 'header', 'footer', 'section', 'article', 'aside']);
const TABLE_TAGS = new Set(['table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col']);
const FIELD_TAGS = new Set(['input', 'select', 'textarea', 'option', 'optgroup', 'fieldset', 'legend']);

function result(semanticType, adapter, preferredElement, confidence, evidence, limitations = []) {
	return { semanticType, adapter, preferredElement, confidence, evidence, limitations };
}

function supportedVideoEmbed(source) {
	return /(?:youtube(?:-nocookie)?\.com|youtu\.be|vimeo\.com)/i.test(source || '');
}

function classifyComponent(node, context = {}) {
	const tag = String(node.tag || '').toLowerCase();
	const role = String(node.role || '').toLowerCase();
	const children = context.children || [];
	if (context.insideSvg) return result('vector-part', 'svg-child', 'div', 1, [`svg-tag:${tag}`]);
	if (LANDMARK_TAGS.has(tag)) return result('landmark', 'direct', 'section', 1, [`tag:${tag}`]);
	if (tag === 'nav' || role === 'navigation') {
		return result('navigation', 'semantic-layout', 'section', 1, [tag === 'nav' ? 'tag:nav' : 'role:navigation'], [
			'Native nav landmark is preserved without inventing Bricks mobile-menu behavior.'
		]);
	}
	if (['div', 'figure', 'picture', 'details', 'summary', 'dialog'].includes(tag)) return result('layout', 'semantic-layout', 'div', 1, [`tag:${tag}`]);
	if (/^h[1-6]$/.test(tag)) return result('heading', 'direct', 'heading', 1, [`tag:${tag}`]);
	if (TEXT_TAGS.has(tag)) return result('text', 'direct', 'text-basic', 1, [`tag:${tag}`]);
	if (tag === 'a') {
		if (role === 'button') return result('button', 'direct', 'button', 1, ['tag:a', 'role:button']);
		return result('link', 'direct', 'text-link', 1, ['tag:a']);
	}
	if (tag === 'button') return result('button', 'direct', 'button', 1, ['tag:button']);
	if (tag === 'img') return result('image', 'direct', 'image', 1, ['tag:img']);
	if (tag === 'video' || tag === 'audio') {
		const sources = children.filter((child) => child.tag === 'source');
		const unsupportedChildren = children.filter((child) => !['source'].includes(child.tag));
		if (sources.length > 1 || unsupportedChildren.length) return result(tag, 'review', 'div', 1, [`tag:${tag}`, 'compound media subtree'], [
			'Multiple source candidates or caption tracks require a lossless media adapter before aggregation.'
		]);
		return result(tag, 'direct-media', tag, 1, [`tag:${tag}`]);
	}
	if (tag === 'iframe' && supportedVideoEmbed(node.attributes?.src)) return result('video', 'direct-media', 'video', 0.99, ['tag:iframe', 'recognized video provider']);
	if (tag === 'iframe') return result('embed', 'review', 'div', 1, ['tag:iframe'], ['Arbitrary iframe embeds have no behavior-equivalent Bricks 2.3.10 element.']);
	if (tag === 'hr' || role === 'separator') return result('separator', 'direct', 'divider', 0.95, [tag === 'hr' ? 'tag:hr' : 'role:separator']);
	if (tag === 'ul' && children.length && children.every((child) => child.tag === 'li')) {
		return result('list', 'aggregate-list', 'list', 1, ['tag:ul', 'direct children are list items']);
	}
	if (tag === 'ul' || tag === 'ol') return result('list', 'semantic-layout', 'block', 1, [`tag:${tag}`]);
	if (tag === 'li') return result('list-item', 'semantic-layout', 'block', 1, ['tag:li']);
	if (tag === 'form') return result('form', 'review', 'div', 1, ['tag:form'], [
		'Arbitrary form submission authority is preserved for review instead of being changed to Bricks AJAX form actions.'
	]);
	if (FIELD_TAGS.has(tag)) return result('field', 'review', 'block', 1, [`tag:${tag}`], [
		'Standalone HTML fields require a behavior-equivalent form adapter before native Bricks Form aggregation.'
	]);
	if (TABLE_TAGS.has(tag)) return result('table', 'semantic-layout', 'div', 1, [`tag:${tag}`], [
		'Bricks 2.3.10 has no specialized table element; semantic table tags use native layout elements.'
	]);
	if (tag === 'svg') return result('vector', 'aggregate-svg', 'svg', 1, ['tag:svg', 'sanitized vector subtree']);
	if (role === 'img' && tag !== 'img') return result('vector', 'review', 'div', 0.9, ['role:img'], [
		'Unknown vector/image markup requires sanitization proof before specialized conversion.'
	]);
	if (['source', 'track'].includes(tag)) return result('media-source', 'media-child', 'block', 1, [`tag:${tag}`]);
	return result('other', 'semantic-layout', 'div', 0.8, [`tag:${tag || 'unknown'}`]);
}

function classifyCapture(capture) {
	const childrenByParent = new Map(capture.nodes.map((node) => [node.id, []]));
	const nodeById = new Map(capture.nodes.map((node) => [node.id, node]));
	for (const node of capture.nodes) if (node.parentId && childrenByParent.has(node.parentId)) childrenByParent.get(node.parentId).push(node);
	return new Map(capture.nodes.map((node) => {
		let ancestor = node.parentId ? nodeById.get(node.parentId) : null;
		let insideSvg = false;
		while (ancestor) {
			if (ancestor.tag === 'svg') { insideSvg = true; break; }
			ancestor = ancestor.parentId ? nodeById.get(ancestor.parentId) : null;
		}
		return [node.id, classifyComponent(node, { children: childrenByParent.get(node.id) || [], insideSvg })];
	}));
}

module.exports = { classifyCapture, classifyComponent, supportedVideoEmbed };
