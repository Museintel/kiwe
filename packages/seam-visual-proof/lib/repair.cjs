const crypto = require('node:crypto');
const { assertContract } = require('../../seam-compiler-core/lib/normalize-capture.cjs');

function idFor(viewportId, nodeId, category, reason) {
	return `repair-${crypto.createHash('sha256').update(`${viewportId || ''}|${nodeId || ''}|${category}|${reason}`).digest('hex').slice(0, 12)}`;
}

function item(viewportId, nodeId, category, priority, reason, evidence, action) {
	return { id: idFor(viewportId, nodeId, category, reason), viewportId, nodeId, category, priority, reason, evidence, action, autoApplicable: false };
}

function buildRepairPlan(report) {
	assertContract('visualProof', report);
	const items = [];
	for (const viewport of report.matrix) {
		if (viewport.status === 'blocked') {
			items.push(item(viewport.id, null, 'capture', 'blocker', viewport.screenshots.error || 'Viewport proof is blocked.', viewport.screenshots, 'Recapture reference and candidate with the same controlled viewport/state and verified PNG evidence.'));
			continue;
		}
		for (const nodeId of viewport.geometry.missingCandidate || []) {
			items.push(item(viewport.id, nodeId, 'anchor', 'blocker', 'A reference node has no candidate proof anchor.', { nodeId }, 'Restore the native element mapping and data-seam-proof-node provenance, then recompile from Page IR.'));
		}
		for (const outlier of viewport.geometry.outliers || []) {
			items.push(item(viewport.id, outlier.nodeId, 'geometry', 'high', 'Rendered box delta exceeds the declared geometry threshold.', outlier.delta, 'Revisit the node width, spacing, layout, and responsive native controls in the Bricks plan; recompile from Page IR.'));
		}
		for (const outlier of (viewport.styles.outliers || []).slice(0, 40)) {
			items.push(item(viewport.id, outlier.nodeId, 'style', 'medium', `Computed ${outlier.property} differs.`, { property: outlier.property, reference: outlier.reference, candidate: outlier.candidate }, 'Correct the responsible native Bricks control or Framework variable owner; do not patch generated JSON.'));
		}
		for (const outlier of viewport.accessibility.outliers || []) {
			items.push(item(viewport.id, outlier.nodeId, 'accessibility', 'high', 'Semantic role or accessible name differs.', { fields: outlier.fields }, 'Restore the semantic tag/ARIA/native element mapping in the compiler adapter and recompile.'));
		}
		if (viewport.checks.pixels === 'failed' && viewport.geometry.outliers.length === 0 && viewport.styles.outliers.length === 0) {
			items.push(item(viewport.id, null, 'pixel', 'medium', 'Pixel mismatch exceeds threshold without a localized geometry/style mismatch.', { mismatchRatio: viewport.pixels.mismatchRatio, diff: viewport.screenshots.diff }, 'Inspect the diff for fonts, raster assets, pseudo-elements, antialiasing, or uncontrolled dynamic state before proposing a bounded compiler rule.'));
		}
		for (const diagnostic of viewport.diagnostics.new || []) {
			items.push(item(viewport.id, null, 'diagnostic', 'high', 'Candidate capture introduced a diagnostic.', { diagnostic }, 'Resolve the candidate console/network/capture diagnostic and rerun the proof matrix.'));
		}
	}
	const deduplicated = [...new Map(items.map((record) => [record.id, record])).values()].sort((left, right) => left.id.localeCompare(right.id));
	const plan = {
		schema: 'seam.repair-plan.v1', sourceHash: report.sourceHash, targetHash: report.targetHash,
		mode: 'deterministic-proposal', authority: 'recompile-from-ir-after-review', mutatesArtifacts: false, items: deduplicated,
		limitations: [
			'This plan proposes bounded repair locations and never edits Bricks JSON, WordPress, or compiler IR.',
			'Every accepted repair must change a canonical compiler rule or IR input, then rerun the complete proof matrix.',
			'AI assistance may inspect one bounded evidence item but cannot author or apply Bricks JSON.'
		]
	};
	assertContract('repairPlan', plan);
	return plan;
}

module.exports = { buildRepairPlan };
