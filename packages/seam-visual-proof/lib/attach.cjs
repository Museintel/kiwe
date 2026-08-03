const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { assertContract } = require('../../seam-compiler-core/lib/normalize-capture.cjs');

function hash(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function safeFile(root, relative) {
	const base = path.resolve(root);
	const resolved = path.resolve(base, relative);
	if (!resolved.startsWith(`${base}${path.sep}`)) throw new Error(`Package artifact path leaves package root: ${relative}`);
	return resolved;
}

function serialized(value) {
	return `${JSON.stringify(value, null, 2)}\n`;
}

function verifyIntegrity(root, packageManifest) {
	for (const [relative, expected] of Object.entries(packageManifest.integrity.files)) {
		const file = safeFile(root, relative);
		if (!fs.existsSync(file) || hash(fs.readFileSync(file)) !== expected) throw new Error(`AppSite integrity mismatch: ${relative}`);
	}
}

function attachProof(packageDirectory, report, repairPlan = null, evidenceDirectory = null) {
	const root = path.resolve(packageDirectory);
	const manifestFile = path.join(root, 'appsite-package.json');
	const appsitePackage = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
	assertContract('appsitePackage', appsitePackage);
	verifyIntegrity(root, appsitePackage);
	if (appsitePackage.artifacts.proof) throw new Error('AppSite package already has proof attached.');
	if (report.sourceHash !== appsitePackage.sourceHash) throw new Error('Visual proof sourceHash does not match the AppSite package.');
	if (report.comparator.anchorBasis !== 'bricks-plan') throw new Error('AppSite proof must use Bricks-plan anchors.');
	const bricksPlan = JSON.parse(fs.readFileSync(safeFile(root, appsitePackage.artifacts.bricksPlan), 'utf8'));
	assertContract('bricksPlan', bricksPlan);
	const planAnchors = [...new Set(bricksPlan.elements.map((element) => String(element.provenance.pageNodeId)))].sort();
	if (report.comparator.expectedAnchors !== planAnchors.length || report.comparator.anchorSetHash !== `sha256:${hash(JSON.stringify(planAnchors))}`) throw new Error('Visual proof anchor set does not match the packaged Bricks plan.');
	if (repairPlan) {
		assertContract('repairPlan', repairPlan);
		if (repairPlan.sourceHash !== report.sourceHash || repairPlan.targetHash !== report.targetHash) throw new Error('Repair plan does not match the visual proof hashes.');
		report = { ...report, repairPlan: 'proof/repair-plan.json' };
	}
	assertContract('visualProof', report);
	const reportContent = serialized(report);
	const diffAssets = [];
	for (const viewport of report.matrix) {
		const diff = viewport.screenshots && viewport.screenshots.diff;
		if (!diff) continue;
		if (!evidenceDirectory) throw new Error('Attaching visual proof with diff images requires the proof evidence directory.');
		const content = fs.readFileSync(safeFile(evidenceDirectory, diff.file));
		if (content.length !== diff.bytes || hash(content) !== diff.sha256) throw new Error(`Visual diff integrity mismatch: ${diff.file}`);
		diffAssets.push({ relative: `proof/${String(diff.file).replace(/\\/g, '/')}`, content, sha256: diff.sha256 });
	}
	fs.mkdirSync(path.join(root, 'proof'), { recursive: true });
	fs.writeFileSync(path.join(root, 'proof', 'report.json'), reportContent);
	appsitePackage.artifacts.proof = 'proof/report.json';
	appsitePackage.integrity.files['proof/report.json'] = hash(reportContent);
	if (repairPlan) {
		const repairContent = serialized(repairPlan);
		fs.writeFileSync(path.join(root, 'proof', 'repair-plan.json'), repairContent);
		appsitePackage.integrity.files['proof/repair-plan.json'] = hash(repairContent);
	}
	for (const asset of diffAssets) {
		const destination = safeFile(root, asset.relative);
		fs.mkdirSync(path.dirname(destination), { recursive: true });
		fs.writeFileSync(destination, asset.content);
		appsitePackage.integrity.files[asset.relative] = asset.sha256;
	}
	for (const contract of ['seam.visual-proof.v1', 'seam.repair-plan.v1']) if (!appsitePackage.contracts.includes(contract)) appsitePackage.contracts.push(contract);
	assertContract('appsitePackage', appsitePackage);
	fs.writeFileSync(manifestFile, serialized(appsitePackage));
	return appsitePackage;
}

module.exports = { attachProof, verifyIntegrity };
