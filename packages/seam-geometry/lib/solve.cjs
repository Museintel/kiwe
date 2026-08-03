const { validateContract } = require('../../seam-contracts/lib/validator.cjs');

const RESPONSIVE_PROPERTIES = [
	'display', 'position', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'gap',
	'grid-template-columns', 'grid-template-rows', 'width', 'min-width', 'max-width', 'height', 'min-height',
	'max-height', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top',
	'margin-right', 'margin-bottom', 'margin-left', 'font-size', 'line-height'
];

function breakpointFor(width) {
	if (width <= 478) return 'mobile_portrait';
	if (width <= 767) return 'mobile_landscape';
	if (width <= 991) return 'tablet_portrait';
	return null;
}

function layoutModel(display) {
	if (display === 'none') return 'none';
	if (display === 'contents') return 'contents';
	if (display.includes('flex')) return 'flex';
	if (display.includes('grid')) return 'grid';
	if (display.includes('inline')) return 'inline';
	if (display === 'block' || display === 'flow-root' || display === 'table' || display === 'list-item') return 'block';
	return 'other';
}

function positionMode(position) {
	if (['static', ''].includes(position)) return 'flow';
	if (['relative', 'absolute', 'fixed', 'sticky'].includes(position)) return position;
	return 'other';
}

function inferWidthMode(observations, viewportMap) {
	const visible = observations.filter((item) => item.visible && item.box.width > 0);
	if (visible.length < 2) return 'unknown';
	if (visible.every((item) => ['inline', 'inline-block', 'inline-flex', 'inline-grid'].includes(item.computed.display))) return 'intrinsic';
	const widths = visible.map((item) => item.box.width);
	const delta = Math.max(...widths) - Math.min(...widths);
	if (delta <= 2) return 'fixed';
	const sorted = [...visible].sort((left, right) => viewportMap.get(left.viewportId).width - viewportMap.get(right.viewportId).width);
	const largest = sorted.slice(-2);
	if (largest.length === 2 && Math.abs(largest[0].box.width - largest[1].box.width) <= 2 && sorted[0].box.width < largest[0].box.width - 2) return 'clamped';
	const ratios = visible.map((item) => item.box.width / viewportMap.get(item.viewportId).width);
	if (Math.max(...ratios) - Math.min(...ratios) <= 0.08) return 'fluid';
	if (visible.some((item) => !['none', 'auto'].includes(item.computed['max-width'] || 'none'))) return 'clamped';
	const smallestRatio = sorted[0].box.width / viewportMap.get(sorted[0].viewportId).width;
	const largestRatio = sorted.at(-1).box.width / viewportMap.get(sorted.at(-1).viewportId).width;
	if (smallestRatio >= 0.9 && largestRatio <= 0.8) return 'clamped';
	return 'fluid';
}

function responsiveDelta(base, observation) {
	const properties = {};
	for (const property of RESPONSIVE_PROPERTIES) {
		const before = base.computed[property] || '';
		const after = observation.computed[property] || '';
		if (before !== after) properties[property] = after;
	}
	if (base.visible !== observation.visible) properties.visibility = observation.visible ? 'visible' : 'hidden';
	return properties;
}

function solveGeometry(capture) {
	const captureValidation = validateContract('capture', capture);
	if (!captureValidation.ok) throw new Error(`Cannot solve invalid capture: ${captureValidation.errors.map((item) => item.message).join(' ')}`);
	const viewportMap = new Map(capture.viewports.map((viewport) => [viewport.id, viewport]));
	const viewports = capture.viewports.map((viewport) => ({
		id: viewport.id, width: viewport.width, height: viewport.height, breakpoint: breakpointFor(viewport.width)
	}));
	const widthModes = {};
	let responsiveNodes = 0;
	let visibilityChangingNodes = 0;

	const nodes = capture.nodes.map((node) => {
		const base = node.observations.find((item) => item.visible) || node.observations[0];
		const changes = [];
		for (const observation of node.observations) {
			if (observation === base) continue;
			const properties = responsiveDelta(base, observation);
			if (Object.keys(properties).length) {
				changes.push({
					viewportId: observation.viewportId,
					breakpoint: breakpointFor(viewportMap.get(observation.viewportId).width),
					properties
				});
			}
		}
		const visibilityChanges = new Set(node.observations.map((item) => item.visible)).size > 1;
		if (changes.length) responsiveNodes += 1;
		if (visibilityChanges) visibilityChangingNodes += 1;
		const widthMode = inferWidthMode(node.observations, viewportMap);
		widthModes[widthMode] = (widthModes[widthMode] || 0) + 1;
		return {
			id: node.id,
			widthMode,
			layoutModel: layoutModel(base.computed.display || 'block'),
			positionMode: positionMode(base.computed.position || 'static'),
			visibilityChanges,
			responsiveChanges: changes,
			evidence: node.observations.map((item) => ({ viewportId: item.viewportId, visible: item.visible, box: item.box }))
		};
	});

	const geometry = {
		schema: 'seam.geometry.v1', sourceHash: capture.source.contentHash,
		solver: { name: 'SEAM Page Geometry Solver', version: '0.2.0' },
		viewports,
		nodes,
		summary: { nodes: nodes.length, responsiveNodes, visibilityChangingNodes, widthModes },
		limitations: [
			'Constraint inference is evidence-based and reports ambiguity; it does not claim author CSS intent.',
			'Container-query and interaction-state matrices require explicit capture states beyond default viewport evidence.'
		]
	};
	const result = validateContract('geometry', geometry);
	if (!result.ok) throw new Error(`Geometry contract validation failed: ${result.errors.map((item) => item.message).join(' ')}`);
	return geometry;
}

module.exports = { breakpointFor, inferWidthMode, solveGeometry };
