const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { assertContract } = require('../../seam-compiler-core/lib/normalize-capture.cjs');
const { decodePng, encodePng } = require('./png.cjs');

const STYLE_PROPERTIES = [
	'display', 'position', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'gap',
	'grid-template-columns', 'width', 'height', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
	'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'font-family', 'font-size', 'font-style',
	'font-weight', 'line-height', 'letter-spacing', 'text-align', 'text-decoration', 'text-transform',
	'color', 'background-color', 'background-image', 'border-width', 'border-style', 'border-color',
	'border-radius', 'opacity', 'visibility', 'transform', 'box-shadow', 'object-fit', 'object-position'
];

const DEFAULT_THRESHOLDS = Object.freeze({
	pixelDelta: 0.1,
	pixelMismatchRatio: 0.02,
	maxBoxDeltaPx: 4,
	styleMismatchRatio: 0.02,
	minAnchorCoverage: 0.95
});

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function round(value, places = 6) {
	const factor = 10 ** places;
	return Math.round((Number(value) || 0) * factor) / factor;
}

function safeFile(root, relative) {
	const base = path.resolve(root);
	const resolved = path.resolve(base, String(relative || ''));
	if (resolved !== base && !resolved.startsWith(`${base}${path.sep}`)) throw new Error(`Screenshot path leaves capture directory: ${relative}`);
	return resolved;
}

function screenshot(capture, directory, viewport) {
	if (!viewport.screenshot || !viewport.screenshot.file) throw new Error(`Viewport ${viewport.id} has no screenshot evidence.`);
	const file = safeFile(directory, viewport.screenshot.file);
	const buffer = fs.readFileSync(file);
	const digest = sha256(buffer);
	if (viewport.screenshot.bytes !== buffer.length || viewport.screenshot.sha256 !== digest) throw new Error(`Screenshot integrity mismatch for ${viewport.id}.`);
	return { file: viewport.screenshot.file.replace(/\\/g, '/'), bytes: buffer.length, sha256: digest, image: decodePng(buffer) };
}

function composite(image, x, y) {
	if (x < 0 || y < 0 || x >= image.width || y >= image.height) return [255, 255, 255];
	const offset = (y * image.width + x) * 4;
	const alpha = image.data[offset + 3] / 255;
	return [0, 1, 2].map((channel) => Math.round(image.data[offset + channel] * alpha + 255 * (1 - alpha)));
}

function yiqDelta(left, right) {
	const red = left[0] - right[0];
	const green = left[1] - right[1];
	const blue = left[2] - right[2];
	return Math.min(1, Math.sqrt(0.5053 * red * red + 0.299 * green * green + 0.1957 * blue * blue) / 255);
}

function comparePixels(reference, candidate, threshold) {
	const width = Math.max(reference.width, candidate.width);
	const height = Math.max(reference.height, candidate.height);
	const comparedPixels = width * height;
	const diff = Buffer.alloc(comparedPixels * 4);
	let differingPixels = 0;
	let totalDelta = 0;
	let maxDelta = 0;
	for (let y = 0; y < height; y += 1) for (let x = 0; x < width; x += 1) {
		const left = composite(reference, x, y);
		const right = composite(candidate, x, y);
		const delta = yiqDelta(left, right);
		totalDelta += delta;
		maxDelta = Math.max(maxDelta, delta);
		const different = delta > threshold || x >= reference.width || y >= reference.height || x >= candidate.width || y >= candidate.height;
		if (different) differingPixels += 1;
		const offset = (y * width + x) * 4;
		if (different) { diff[offset] = 255; diff[offset + 1] = 0; diff[offset + 2] = 180; diff[offset + 3] = 255; }
		else {
			const grey = Math.round((left[0] * 0.299 + left[1] * 0.587 + left[2] * 0.114) * 0.35 + 166);
			diff[offset] = diff[offset + 1] = diff[offset + 2] = grey; diff[offset + 3] = 255;
		}
	}
	return {
		metrics: {
			width, height, dimensionsMatch: reference.width === candidate.width && reference.height === candidate.height,
			comparedPixels, differingPixels, mismatchRatio: round(differingPixels / comparedPixels),
			meanYiqDelta: round(totalDelta / comparedPixels), maxYiqDelta: round(maxDelta)
		},
		diff: encodePng({ width, height, data: diff })
	};
}

function observation(node, viewportId) {
	return (node.observations || []).find((item) => item.viewportId === viewportId) || null;
}

function candidateIndex(capture, requireProofAttribute = false) {
	const index = new Map();
	const duplicates = new Set();
	for (const node of capture.nodes) {
		const proofNode = node.attributes?.['data-seam-proof-node'];
		if (requireProofAttribute && !proofNode) continue;
		const key = String(proofNode || node.id);
		if (index.has(key)) duplicates.add(key);
		else index.set(key, node);
	}
	return { index, duplicates };
}

function compareEvidence(reference, candidate, viewportId, thresholds, expectedNodeIds = null) {
	const expected = expectedNodeIds ? new Set(expectedNodeIds) : null;
	const candidateNodes = candidateIndex(candidate, Boolean(expected));
	const missingCandidate = [];
	const boxOutliers = [];
	const styleOutliers = [];
	const accessibilityOutliers = [];
	let referenceAnchors = 0;
	let matchedAnchors = 0;
	let visibilityMismatches = 0;
	let boxDeltaTotal = 0;
	let boxComparisons = 0;
	let maxBoxDeltaPx = 0;
	let styleComparisons = 0;
	let styleMismatches = 0;
	let accessibilityMismatches = 0;

	for (const referenceNode of reference.nodes) {
		if (expected && !expected.has(referenceNode.id)) continue;
		const referenceObservation = observation(referenceNode, viewportId);
		if (!referenceObservation) continue;
		referenceAnchors += 1;
		const candidateNode = candidateNodes.index.get(referenceNode.id);
		const candidateObservation = candidateNode && observation(candidateNode, viewportId);
		if (!candidateNode || !candidateObservation) {
			missingCandidate.push(referenceNode.id);
			if (referenceNode.role || referenceNode.accessibleName) {
				accessibilityMismatches += 1;
				if (accessibilityOutliers.length < 100) accessibilityOutliers.push({ nodeId: referenceNode.id, fields: ['missingSemanticAnchor'] });
			}
			continue;
		}
		matchedAnchors += 1;
		if (referenceObservation.visible !== candidateObservation.visible) visibilityMismatches += 1;
		if (referenceObservation.visible && candidateObservation.visible) {
			const delta = Object.fromEntries(['x', 'y', 'width', 'height'].map((key) => [key, round(Math.abs(referenceObservation.box[key] - candidateObservation.box[key]), 3)]));
			const maximum = Math.max(...Object.values(delta));
			boxDeltaTotal += Object.values(delta).reduce((sum, value) => sum + value, 0) / 4;
			boxComparisons += 1;
			maxBoxDeltaPx = Math.max(maxBoxDeltaPx, maximum);
			if (maximum > thresholds.maxBoxDeltaPx) boxOutliers.push({ nodeId: referenceNode.id, delta });
		}
		for (const property of STYLE_PROPERTIES) {
			const left = String(referenceObservation.computed[property] || '');
			const right = String(candidateObservation.computed[property] || '');
			if (!left && !right) continue;
			styleComparisons += 1;
			if (left !== right) {
				styleMismatches += 1;
				if (styleOutliers.length < 100) styleOutliers.push({ nodeId: referenceNode.id, property, reference: left, candidate: right });
			}
		}
		const accessibilityDifferences = [];
		if ((referenceNode.role || null) !== (candidateNode.role || null)) accessibilityDifferences.push('role');
		if (String(referenceNode.accessibleName || '') !== String(candidateNode.accessibleName || '')) accessibilityDifferences.push('accessibleName');
		if (accessibilityDifferences.length) {
			accessibilityMismatches += 1;
			if (accessibilityOutliers.length < 100) accessibilityOutliers.push({ nodeId: referenceNode.id, fields: accessibilityDifferences });
		}
	}
	if (expected) {
		const mappedCandidates = new Set(candidateNodes.index.values());
		for (const candidateNode of candidate.nodes) {
			if (mappedCandidates.has(candidateNode)) continue;
			const candidateObservation = observation(candidateNode, viewportId);
			if (!candidateObservation?.visible || (!candidateNode.role && !candidateNode.accessibleName)) continue;
			accessibilityMismatches += 1;
			if (accessibilityOutliers.length < 100) accessibilityOutliers.push({ nodeId: candidateNode.id, fields: ['unexpectedSemanticNode'], role: candidateNode.role || null, accessibleName: candidateNode.accessibleName || '' });
		}
	}
	const coverage = referenceAnchors ? matchedAnchors / referenceAnchors : 0;
	const styleMismatchRatio = styleComparisons ? styleMismatches / styleComparisons : 0;
	return {
		geometry: {
			referenceAnchors, matchedAnchors, coverage: round(coverage), missingCandidate: missingCandidate.slice(0, 100),
			duplicateCandidateAnchors: [...candidateNodes.duplicates].sort().slice(0, 100), visibilityMismatches,
			boxComparisons, meanBoxDeltaPx: round(boxComparisons ? boxDeltaTotal / boxComparisons : 0), maxBoxDeltaPx: round(maxBoxDeltaPx), outliers: boxOutliers.slice(0, 100)
		},
		styles: { comparisons: styleComparisons, mismatches: styleMismatches, mismatchRatio: round(styleMismatchRatio), outliers: styleOutliers },
		accessibility: { matchedAnchors, mismatches: accessibilityMismatches, outliers: accessibilityOutliers }
	};
}

function blockedViewport(referenceViewport, message) {
	return {
		id: referenceViewport.id, width: referenceViewport.width, height: referenceViewport.height, theme: referenceViewport.theme, state: referenceViewport.state, status: 'blocked',
		screenshots: { error: message }, pixels: {}, geometry: {}, styles: {}, accessibility: {}, diagnostics: { reference: [], candidate: [], new: [] },
		checks: { pixels: 'blocked', geometry: 'blocked', styles: 'blocked', accessibility: 'blocked', diagnostics: 'blocked' }
	};
}

function compareCaptures({ referenceCapture, candidateCapture, referenceDirectory, candidateDirectory, outputDirectory, thresholds = {}, expectedNodeIds = null }) {
	assertContract('capture', referenceCapture);
	assertContract('capture', candidateCapture);
	const limits = { ...DEFAULT_THRESHOLDS, ...thresholds };
	const anchors = expectedNodeIds ? [...new Set(expectedNodeIds.map(String))].sort() : referenceCapture.nodes.map((node) => node.id).sort();
	if (!anchors.length) throw new Error('Visual proof requires at least one expected anchor.');
	const referenceNodeIds = new Set(referenceCapture.nodes.map((node) => node.id));
	const unknownAnchors = anchors.filter((nodeId) => !referenceNodeIds.has(nodeId));
	if (unknownAnchors.length) throw new Error(`Bricks plan references ${unknownAnchors.length} node(s) absent from the reference capture.`);
	const candidateViewports = new Map(candidateCapture.viewports.map((viewport) => [viewport.id, viewport]));
	const newDiagnostics = candidateCapture.diagnostics.filter((item) => !referenceCapture.diagnostics.includes(item));
	const matrix = [];
	if (outputDirectory) fs.mkdirSync(path.join(outputDirectory, 'diff'), { recursive: true });

	for (const referenceViewport of referenceCapture.viewports) {
		const candidateViewport = candidateViewports.get(referenceViewport.id);
		if (!candidateViewport) { matrix.push(blockedViewport(referenceViewport, 'Candidate capture is missing this viewport.')); continue; }
		if (['width', 'height', 'theme', 'state'].some((key) => candidateViewport[key] !== referenceViewport[key])) { matrix.push(blockedViewport(referenceViewport, 'Candidate viewport width, height, theme, or state does not match the reference.')); continue; }
		try {
			const referenceShot = screenshot(referenceCapture, referenceDirectory, referenceViewport);
			const candidateShot = screenshot(candidateCapture, candidateDirectory, candidateViewport);
			const pixelResult = comparePixels(referenceShot.image, candidateShot.image, limits.pixelDelta);
			const diffRelative = `diff/${referenceViewport.id}.png`;
			const diffHash = sha256(pixelResult.diff);
			if (outputDirectory) fs.writeFileSync(safeFile(outputDirectory, diffRelative), pixelResult.diff);
			const evidence = compareEvidence(referenceCapture, candidateCapture, referenceViewport.id, limits, expectedNodeIds ? anchors : null);
			const pixelsCheck = pixelResult.metrics.dimensionsMatch && pixelResult.metrics.mismatchRatio <= limits.pixelMismatchRatio ? 'passed' : 'failed';
			const geometryCheck = evidence.geometry.coverage >= limits.minAnchorCoverage && evidence.geometry.maxBoxDeltaPx <= limits.maxBoxDeltaPx && evidence.geometry.visibilityMismatches === 0 && evidence.geometry.duplicateCandidateAnchors.length === 0 ? 'passed' : 'failed';
			const styleCheck = evidence.styles.mismatchRatio <= limits.styleMismatchRatio ? 'passed' : 'failed';
			const accessibilityCheck = evidence.accessibility.mismatches === 0 ? 'passed' : 'failed';
			const diagnosticsCheck = newDiagnostics.length === 0 ? 'passed' : 'failed';
			const checks = { pixels: pixelsCheck, geometry: geometryCheck, styles: styleCheck, accessibility: accessibilityCheck, diagnostics: diagnosticsCheck };
			const status = Object.values(checks).every((value) => value === 'passed') ? 'passed' : 'failed';
			matrix.push({
				id: referenceViewport.id, width: referenceViewport.width, height: referenceViewport.height, theme: referenceViewport.theme, state: referenceViewport.state, status,
				screenshots: {
					reference: { file: referenceShot.file, bytes: referenceShot.bytes, sha256: referenceShot.sha256, width: referenceShot.image.width, height: referenceShot.image.height },
					candidate: { file: candidateShot.file, bytes: candidateShot.bytes, sha256: candidateShot.sha256, width: candidateShot.image.width, height: candidateShot.image.height },
					diff: { file: diffRelative, bytes: pixelResult.diff.length, sha256: diffHash }
				},
				pixels: pixelResult.metrics, geometry: evidence.geometry, styles: evidence.styles, accessibility: evidence.accessibility,
				diagnostics: { reference: referenceCapture.diagnostics, candidate: candidateCapture.diagnostics, new: newDiagnostics }, checks
			});
		} catch (error) {
			matrix.push(blockedViewport(referenceViewport, error instanceof Error ? error.message : String(error)));
		}
	}
	const comparable = matrix.filter((item) => item.status !== 'blocked');
	const summary = {
		viewports: matrix.length, passed: matrix.filter((item) => item.status === 'passed').length,
		failed: matrix.filter((item) => item.status === 'failed').length, blocked: matrix.filter((item) => item.status === 'blocked').length,
		worstPixelMismatchRatio: round(Math.max(0, ...comparable.map((item) => item.pixels.mismatchRatio || 0))),
		worstBoxDeltaPx: round(Math.max(0, ...comparable.map((item) => item.geometry.maxBoxDeltaPx || 0))),
		anchorCoverage: round(comparable.length ? comparable.reduce((sum, item) => sum + item.geometry.coverage, 0) / comparable.length : 0),
		styleMismatchRatio: round(comparable.reduce((sum, item) => sum + (item.styles.mismatches || 0), 0) / Math.max(1, comparable.reduce((sum, item) => sum + (item.styles.comparisons || 0), 0))),
		accessibilityMismatches: comparable.reduce((sum, item) => sum + (item.accessibility.mismatches || 0), 0), newDiagnostics: newDiagnostics.length
	};
	const status = summary.blocked ? 'blocked' : summary.failed ? 'failed' : 'passed';
	const exact = status === 'passed' && summary.worstPixelMismatchRatio === 0 && summary.worstBoxDeltaPx === 0 && summary.styleMismatchRatio === 0 && summary.accessibilityMismatches === 0;
	const report = {
		schema: 'seam.visual-proof.v1', sourceHash: referenceCapture.source.contentHash, targetHash: candidateCapture.source.contentHash,
		comparator: {
			name: 'SEAM Visual Proof', version: '0.1.0', deterministic: true,
			anchorBasis: expectedNodeIds ? 'bricks-plan' : 'capture-all', expectedAnchors: anchors.length,
			anchorSetHash: `sha256:${sha256(JSON.stringify(anchors))}`,
			referenceCaptureHash: `sha256:${sha256(JSON.stringify(referenceCapture))}`,
			candidateCaptureHash: `sha256:${sha256(JSON.stringify(candidateCapture))}`
		}, thresholds: limits, status,
		grade: status === 'blocked' ? 'unverified' : exact ? 'matrix-exact' : status === 'passed' ? 'matrix-high' : 'partial', matrix, summary,
		limitations: [
			'Grades apply only to the captured viewport, theme, and interaction-state matrix.',
			'YIQ pixel distance is deterministic but does not replace human review for brand-critical imagery.',
			'Font, browser, operating-system, animation, network, and dynamic-content conditions must be controlled by capture.',
			'No universal 100% fidelity claim is authorized by this report.'
		],
		repairPlan: null
	};
	assertContract('visualProof', report);
	return report;
}

module.exports = { DEFAULT_THRESHOLDS, STYLE_PROPERTIES, compareCaptures, compareEvidence, comparePixels, sha256 };
