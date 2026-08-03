#!/usr/bin/env node
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

function hash(value) {
	return `sha256:${crypto.createHash('sha256').update(value).digest('hex')}`;
}

function values(source, pattern) {
	const result = [];
	let match;
	while ((match = pattern.exec(source))) result.push(match[1]);
	return [...new Set(result)].sort();
}

function extractCapabilities(bricksRoot) {
	const resolved = path.resolve(bricksRoot);
	const functionsFile = path.join(resolved, 'functions.php');
	const elementsDirectory = path.join(resolved, 'includes', 'elements');
	if (!fs.existsSync(functionsFile) || !fs.existsSync(elementsDirectory)) {
		throw new Error('Expected a Bricks root containing functions.php and includes/elements/.');
	}

	const functionsSource = fs.readFileSync(functionsFile, 'utf8');
	const version = functionsSource.match(/define\(\s*'BRICKS_VERSION'\s*,\s*'([^']+)'\s*\)/)?.[1];
	if (!version) throw new Error('Could not read BRICKS_VERSION from functions.php.');

	const files = fs.readdirSync(elementsDirectory).filter((file) => file.endsWith('.php')).sort();
	const sourceParts = [functionsSource];
	const settingsPageFile = path.join(resolved, 'includes', 'settings', 'settings-page.php');
	const settingsPageSource = fs.existsSync(settingsPageFile) ? fs.readFileSync(settingsPageFile, 'utf8') : '';
	if (settingsPageSource) sourceParts.push(`\n/* includes/settings/settings-page.php */\n${settingsPageSource}`);
	const dynamicElementControls = values(settingsPageSource, /\$this->controls\[['"](scrollSnap[^'"]+)['"]\]/g)
		.filter((control) => control !== 'scrollSnapSelector')
		.map((control) => `_${control}`);
	const classRecords = new Map();
	let baseControls = [];
	const warnings = [];

	for (const file of files) {
		const source = fs.readFileSync(path.join(elementsDirectory, file), 'utf8');
		sourceParts.push(`\n/* ${file} */\n${source}`);
		const directControls = values(source, /\$this->controls\[['"]([^'"]+)['"]\]/g);
		const mergedLocalControls = /array_merge\(\s*\$this->controls\s*,\s*\$controls\s*\)/.test(source)
			? values(source, /(?<!->)\$controls\[['"]([^'"]+)['"]\]/g)
			: [];
		const controls = [...new Set([...directControls, ...mergedLocalControls])].sort();
		const classMatch = source.match(/(?:abstract\s+)?class\s+([A-Za-z0-9_]+)(?:\s+extends\s+([A-Za-z0-9_]+))?/);
		if (!classMatch) continue;
		const [, className, parentClass = ''] = classMatch;
		const name = source.match(/public\s+\$name\s*=\s*'([^']+)'/)?.[1] || '';
		const record = { className, parentClass, name, controls, source, file };
		if (file === 'base.php') {
			baseControls = [...new Set([...controls, ...dynamicElementControls])].sort();
			record.controls = baseControls;
		}
		classRecords.set(className, record);
	}

	const resolving = new Set();
	function effectiveControls(record) {
		if (record.effectiveControls) return record.effectiveControls;
		if (resolving.has(record.className)) return record.controls;
		resolving.add(record.className);
		const parent = classRecords.get(record.parentClass);
		const inherited = parent ? effectiveControls(parent) : [];
		record.effectiveControls = [...new Set([...inherited, ...record.controls])].sort();
		resolving.delete(record.className);
		return record.effectiveControls;
	}

	const elements = [];
	for (const record of classRecords.values()) {
		if (!record.name) continue;
		const category = record.source.match(/public\s+\$category\s*=\s*'([^']+)'/)?.[1] || 'inherited';
		const nestable = /public\s+\$nestable\s*=\s*true/.test(record.source) || ['section', 'container', 'block', 'div'].includes(record.name);
		const controls = effectiveControls(record);
		elements.push({
			name: record.name, category, nestable,
			extends: record.parentClass || null,
			declaredControls: record.controls,
			controls,
			sourceFile: `includes/elements/${record.file}`
		});
		if (!controls.length && !nestable) warnings.push(`${record.name}: no literal controls extracted across its inheritance chain`);
	}

	elements.sort((left, right) => left.name.localeCompare(right.name));
	const sourceHash = hash(sourceParts.join(''));
	const core = {
		schema: 'seam.bricks-capability-profile.v1',
		bricksVersion: version,
		sourceHash,
		baseControls,
		elements,
		extractionWarnings: warnings.sort()
	};
	return { ...core, profileHash: hash(JSON.stringify(core)) };
}

if (require.main === module) {
	const [, , source, output] = process.argv;
	if (!source) {
		console.error('Usage: node extract-bricks-capabilities.cjs <bricks-root> [output.json]');
		process.exit(2);
	}
	try {
		const profile = extractCapabilities(source);
		const serialized = `${JSON.stringify(profile, null, 2)}\n`;
		if (output) {
			const destination = path.resolve(process.cwd(), output);
			fs.mkdirSync(path.dirname(destination), { recursive: true });
			fs.writeFileSync(destination, serialized);
			console.log(`Wrote Bricks ${profile.bricksVersion} capability profile with ${profile.elements.length} elements to ${destination}`);
		} else {
			process.stdout.write(serialized);
		}
	} catch (error) {
		console.error(error instanceof Error ? error.message : String(error));
		process.exit(1);
	}
}

module.exports = { extractCapabilities };
