const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { validateCapture } = require('./capture.cjs');

function sha256(buffer) {
	return crypto.createHash('sha256').update(buffer).digest('hex');
}

function readCapture(file) {
	const absolute = path.resolve(file);
	const capture = JSON.parse(fs.readFileSync(absolute, 'utf8'));
	validateCapture(capture);
	return { absolute, directory: path.dirname(absolute), capture };
}

function safeFile(root, relative) {
	const base = path.resolve(root);
	const resolved = path.resolve(base, String(relative || ''));
	if (resolved !== base && !resolved.startsWith(`${base}${path.sep}`)) throw new Error(`Capture file leaves its directory: ${relative}`);
	return resolved;
}

function sameJson(left, right) {
	return JSON.stringify(left) === JSON.stringify(right);
}

function mergeCaptures({ captureFiles, outputDirectory }) {
	if (!Array.isArray(captureFiles) || captureFiles.length < 2) throw new Error('At least two capture files are required.');
	const inputs = captureFiles.map(readCapture);
	const first = inputs[0].capture;
	for (const input of inputs.slice(1)) {
		if (!sameJson(input.capture.source, first.source)) throw new Error('Capture sources do not match.');
		if (!sameJson(input.capture.capture, first.capture)) throw new Error('Capture engine settings do not match.');
	}

	const output = path.resolve(outputDirectory);
	fs.mkdirSync(path.join(output, 'screenshots'), { recursive: true });
	const viewports = [];
	const viewportIds = new Set();
	const nodes = new Map();
	const resources = new Map();
	const diagnostics = new Set();

	for (const input of inputs) {
		for (const diagnostic of input.capture.diagnostics) diagnostics.add(diagnostic);
		for (const viewport of input.capture.viewports) {
			if (viewportIds.has(viewport.id)) throw new Error(`Duplicate viewport in capture merge: ${viewport.id}`);
			viewportIds.add(viewport.id);
			const source = safeFile(input.directory, viewport.screenshot.file);
			const bytes = fs.readFileSync(source);
			if (bytes.length !== viewport.screenshot.bytes || sha256(bytes) !== viewport.screenshot.sha256) throw new Error(`Screenshot integrity mismatch for ${viewport.id}.`);
			const safeId = String(viewport.id).replace(/[^a-zA-Z0-9_.-]+/g, '_');
			const relative = `screenshots/${safeId}.png`;
			fs.copyFileSync(source, path.join(output, relative));
			viewports.push({ ...viewport, screenshot: { ...viewport.screenshot, file: relative } });
		}
		for (const node of input.capture.nodes) {
			const existing = nodes.get(node.id);
			if (!existing) {
				nodes.set(node.id, { ...node, observations: [...node.observations] });
				continue;
			}
			for (const observation of node.observations) {
				if (existing.observations.some((item) => item.viewportId === observation.viewportId)) throw new Error(`Duplicate node observation ${node.id}/${observation.viewportId}.`);
				existing.observations.push(observation);
			}
		}
		for (const resource of input.capture.resources) {
			const key = `${resource.kind}\n${resource.url}`;
			const existing = resources.get(key);
			if (existing?.sha256 && resource.sha256 && existing.sha256 !== resource.sha256) throw new Error(`Resource hash changed across captures: ${resource.url}`);
			if (!existing || (existing.blocked && !resource.blocked)) resources.set(key, resource);
		}
	}

	const merged = {
		...first,
		viewports,
		nodes: [...nodes.values()],
		diagnostics: [...diagnostics],
		resources: [...resources.values()]
	};
	validateCapture(merged);
	const destination = path.join(output, 'seam-capture.json');
	fs.writeFileSync(destination, `${JSON.stringify(merged, null, 2)}\n`);
	return { capture: merged, destination };
}

module.exports = { mergeCaptures };
