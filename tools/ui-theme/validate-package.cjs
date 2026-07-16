#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..', '..');
const packageDir = path.resolve(process.argv[2] || '');
const schemaPath = path.join(repo, 'wp-content/mu-plugins/dsa/ui-system/theme-manifest.schema.json');
const payloadsPath = path.join(repo, 'wp-content/mu-plugins/dsa/ui-system/screen-payloads.json');
const seamVocabularyPath = path.join(repo, 'wp-content/mu-plugins/dsa/ui-system/seam-vocabulary.json');
const seamClassVocabularyPath = path.join(repo, 'wp-content/mu-plugins/dsa/ui-system/seam-class-vocabulary.json');
const seamCssPath = path.join(repo, 'wp-content/mu-plugins/dsa/assets/css/seam.css');

const allowedExtensions = new Set(['.json', '.md', '.css', '.svg', '.png', '.webp', '.avif']);
const forbiddenExtensions = new Set(['.php', '.phtml', '.phar', '.js', '.mjs', '.cjs', '.ts', '.jsx', '.tsx', '.html', '.htm', '.wasm']);
const forbiddenCssPatterns = [
	{ label: 'remote @import', pattern: /@import\s+(?:url\()?['"]?https?:\/\//i },
	{ label: 'remote url()', pattern: /url\(\s*['"]?https?:\/\//i },
	{ label: 'data url', pattern: /url\(\s*['"]?data:/i },
	{ label: 'javascript url', pattern: /url\(\s*['"]?javascript:/i },
	{ label: 'fixed full-screen overlay', pattern: /position\s*:\s*fixed[\s\S]{0,240}(?:inset\s*:\s*0|width\s*:\s*100vw|height\s*:\s*100vh)/i },
];

const errors = [];
const warnings = [];
let seamContract = null;

function fail(message) {
	errors.push(message);
}

function warn(message) {
	warnings.push(message);
}

function readJson(file) {
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'));
	} catch (error) {
		fail(`${path.relative(repo, file)} is not valid JSON: ${error.message}`);
		return {};
	}
}

function seamValueMap(vocabulary) {
	const out = {};
	for (const key of ['role', 'flow', 'tone', 'scene', 'state', 'motion', 'shape', 'flow-density', 'gap', 'align', 'justify', 'theme']) {
		out[key] = new Set(Array.isArray(vocabulary[key]) ? vocabulary[key] : []);
	}
	return out;
}

function seamClassSet(vocabulary, classVocabulary = null) {
	const classes = new Set();
	if (fs.existsSync(seamCssPath)) {
		const css = fs.readFileSync(seamCssPath, 'utf8');
		for (const match of css.matchAll(/\.seam-[a-z0-9_-]+/gi)) {
			classes.add(match[0].slice(1).toLowerCase());
		}
	}
	for (const role of vocabulary.role || []) classes.add(`seam-${role}`);
	for (const flow of vocabulary.flow || []) classes.add(`seam-${flow}`);
	for (const tone of vocabulary.tone || []) classes.add(`seam-tone-${tone}`);
	for (const scene of vocabulary.scene || []) classes.add(`seam-scene-${scene}`);
	for (const state of vocabulary.state || []) {
		classes.add(`seam-is-${state}`);
		if (state === 'print-hidden') classes.add('seam-print-hidden');
	}
	for (const motion of vocabulary.motion || []) classes.add(`seam-${motion}`);
	for (const shape of vocabulary.shape || []) classes.add(`seam-shape-${shape}`);
	for (const density of vocabulary['flow-density'] || []) classes.add(`seam-flow-density-${density}`);
	for (const gap of vocabulary.gap || []) classes.add(`seam-gap-${gap}`);
	for (const align of vocabulary.align || []) classes.add(`seam-align-${align}`);
	for (const justify of vocabulary.justify || []) classes.add(`seam-justify-${justify}`);
	for (const [prefix, values] of Object.entries(vocabulary.bodyClasses || {})) {
		for (const value of values || []) classes.add(`${prefix}-${value}`);
	}
	for (const group of Object.values((classVocabulary && classVocabulary.groups) || {})) {
		for (const className of group.classes || []) classes.add(String(className).toLowerCase());
	}
	return classes;
}

function validateSeamAdoptionMap(vocabulary, classes) {
	const adoption = vocabulary.appShellAdoption || {};
	const publicAdopted = adoption.publicAdopted || {};
	for (const [role, rule] of Object.entries(publicAdopted)) {
		if (!Array.isArray(vocabulary.role) || !vocabulary.role.includes(role)) {
			fail(`seam-vocabulary.json appShellAdoption.publicAdopted contains unknown role: ${role}`);
		}
		for (const className of rule.classes || []) {
			if (!classes.has(String(className).toLowerCase())) {
				fail(`seam-vocabulary.json appShellAdoption for ${role} references unknown Seam class: ${className}`);
			}
		}
	}
	for (const role of Object.keys(adoption.shadowOnly || {})) {
		if (!Array.isArray(vocabulary.role) || !vocabulary.role.includes(role)) {
			fail(`seam-vocabulary.json appShellAdoption.shadowOnly contains unknown role: ${role}`);
		}
	}
}

function loadSeamContract() {
	if (seamContract) return seamContract;
	const vocabulary = readJson(seamVocabularyPath);
	const classVocabulary = fs.existsSync(seamClassVocabularyPath) ? readJson(seamClassVocabularyPath) : null;
	const classes = seamClassSet(vocabulary, classVocabulary);
	validateSeamAdoptionMap(vocabulary, classes);
	seamContract = {
		vocabulary,
		values: seamValueMap(vocabulary),
		classes,
		protectedShadowAttributes: new Set(((vocabulary.protectedShadowAttributes || {}).attributes || []).map(String)),
	};
	return seamContract;
}

function isInside(parent, child) {
	const relative = path.relative(parent, child);
	return relative && !relative.startsWith('..') && !path.isAbsolute(relative);
}

function listFiles(dir) {
	const out = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...listFiles(full));
		} else if (entry.isFile()) {
			out.push(full);
		}
	}
	return out;
}

function valueType(value) {
	if (Array.isArray(value)) return 'array';
	if (value === null) return 'null';
	return typeof value;
}

function validateSimpleSchema(schema, manifest) {
	for (const key of schema.required || []) {
		if (!(key in manifest)) fail(`theme.json missing required key: ${key}`);
	}

	const allowed = new Set(Object.keys(schema.properties || {}));
	for (const key of Object.keys(manifest)) {
		if (!allowed.has(key)) fail(`theme.json has unsupported key: ${key}`);
	}

	for (const [key, rule] of Object.entries(schema.properties || {})) {
		if (!(key in manifest)) continue;
		const value = manifest[key];
		if (rule.type && valueType(value) !== rule.type) fail(`${key} must be ${rule.type}`);
		if (rule.const !== undefined && value !== rule.const) fail(`${key} must be ${rule.const}`);
		if (rule.enum && !rule.enum.includes(value)) fail(`${key} has unsupported value: ${value}`);
		if (rule.maxLength && typeof value === 'string' && value.length > rule.maxLength) fail(`${key} exceeds ${rule.maxLength} characters`);
		if (rule.minLength && typeof value === 'string' && value.length < rule.minLength) fail(`${key} must be at least ${rule.minLength} characters`);
		if (rule.pattern && typeof value === 'string' && !(new RegExp(rule.pattern)).test(value)) fail(`${key} fails required pattern`);
		if (rule.type === 'array' && Array.isArray(value)) {
			if (rule.maxItems && value.length > rule.maxItems) fail(`${key} exceeds ${rule.maxItems} items`);
			if (rule.uniqueItems && new Set(value).size !== value.length) fail(`${key} contains duplicate values`);
			const itemRule = rule.items || {};
			for (const item of value) {
				if (itemRule.type && valueType(item) !== itemRule.type) fail(`${key} contains a non-${itemRule.type} item`);
				if (itemRule.enum && !itemRule.enum.includes(item)) fail(`${key} contains unsupported item: ${item}`);
				if (itemRule.pattern && typeof item === 'string' && !(new RegExp(itemRule.pattern)).test(item)) fail(`${key} item fails required pattern: ${item}`);
			}
		}
	}
}

function unquote(value) {
	return String(value || '').trim().replace(/^['"]|['"]$/g, '');
}

function validateSeamUsage(relative, body, kind) {
	const seam = loadSeamContract();
	if (!seam || !seam.vocabulary) return;

	for (const match of body.matchAll(/\.((?:seam-)[a-z0-9_-]+)/gi)) {
		const className = match[1].toLowerCase();
		if (!seam.classes.has(className)) {
			fail(`${relative} uses unknown Seam class: .${className}`);
		}
	}

	for (const match of body.matchAll(/\[data-(role|flow|tone|scene|state|motion|shape|flow-density|gap|align|justify|theme)(?:\s*[*^$|~]?=\s*(['"]?)([^'"\]\s]+)\2)?/gi)) {
		const attr = match[1].toLowerCase();
		const raw = unquote(match[3] || '');
		if (!raw) continue;
		const legal = seam.values[attr] || new Set();
		for (const value of raw.split(/\s+/).filter(Boolean)) {
			if (legal.size && !legal.has(value)) {
				fail(`${relative} uses invalid data-${attr} value: ${value}`);
			}
		}
	}

	for (const match of body.matchAll(/\bdata-seam-[a-z0-9_-]+\b/gi)) {
		const attr = match[0].toLowerCase();
		if (kind === 'css') {
			fail(`${relative} uses protected Kiwe shadow attribute ${attr}; importable themes must use public Seam classes/attributes or data-dsa-* selectors`);
		} else if (!seam.protectedShadowAttributes.has(attr)) {
			warn(`${relative} mentions unknown protected Kiwe shadow attribute: ${attr}`);
		} else {
			warn(`${relative} mentions protected Kiwe shadow attribute ${attr}; ensure it is documentation only, not importable theme markup`);
		}
	}
}

function safeRelativeFile(root, relativePath, label) {
	if (typeof relativePath !== 'string' || relativePath.includes('\\') || relativePath.includes('\0')) {
		fail(`${label} has unsafe path: ${relativePath}`);
		return '';
	}
	const full = path.resolve(root, relativePath);
	if (!isInside(root, full)) {
		fail(`${label} escapes package root: ${relativePath}`);
		return '';
	}
	return full;
}

function validatePackage() {
	if (!process.argv[2]) {
		fail('Usage: node tools/ui-theme/validate-package.cjs <theme-folder>');
		return;
	}
	if (!fs.existsSync(packageDir) || !fs.statSync(packageDir).isDirectory()) {
		fail(`Theme folder does not exist: ${packageDir}`);
		return;
	}

	const schema = readJson(schemaPath);
	const payloads = readJson(payloadsPath);
	const manifestPath = path.join(packageDir, 'theme.json');
	if (!fs.existsSync(manifestPath)) {
		fail('Theme package must contain theme.json at its root');
		return;
	}

	const manifest = readJson(manifestPath);
	validateSimpleSchema(schema, manifest);

	const knownScreens = new Set(Object.keys((payloads && payloads.screens) || {}));
	for (const screen of manifest.screens || []) {
		if (!knownScreens.has(screen)) fail(`Unknown screen in theme.json: ${screen}`);
	}

	const listed = new Set(['theme.json']);
	let cssBytes = 0;
	for (const css of manifest.css || []) {
		const full = safeRelativeFile(packageDir, css, 'css');
		if (!full) continue;
		listed.add(css);
		if (!fs.existsSync(full)) {
			fail(`CSS file listed but missing: ${css}`);
			continue;
		}
		cssBytes += fs.statSync(full).size;
		const body = fs.readFileSync(full, 'utf8');
		for (const check of forbiddenCssPatterns) {
			if (check.pattern.test(body)) fail(`CSS file ${css} contains forbidden ${check.label}`);
		}
		validateSeamUsage(css, body, 'css');
		if (!/(--kiwe-|--dsa-|data-dsa-|dsa-visual-|kiwe-)/.test(body)) {
			warn(`CSS file ${css} does not appear to use Kiwe tokens or scoped selectors`);
		}
	}

	for (const asset of manifest.assets || []) {
		const full = safeRelativeFile(packageDir, asset, 'asset');
		if (!full) continue;
		listed.add(asset);
		if (!fs.existsSync(full)) fail(`Asset listed but missing: ${asset}`);
	}

	const cssKb = Math.ceil(cssBytes / 1024);
	const cssBudget = Number((manifest.budgets || {}).cssKb || 40);
	if (cssKb > cssBudget) fail(`CSS size ${cssKb} KB exceeds declared budget ${cssBudget} KB`);
	if (cssKb > 80) fail(`CSS size ${cssKb} KB exceeds hard ceiling 80 KB`);
	if (Number((manifest.budgets || {}).jsKb || 0) > 0) warn('Visitor-facing theme JS is not accepted for the initial marketplace boundary');

	for (const file of listFiles(packageDir)) {
		const relative = path.relative(packageDir, file).replace(/\\/g, '/');
		const ext = path.extname(file).toLowerCase();
		if (forbiddenExtensions.has(ext)) fail(`Forbidden executable or document file: ${relative}`);
		if (!allowedExtensions.has(ext)) fail(`Unsupported file type: ${relative}`);
		if (relative !== 'README.md' && relative !== 'theme.json' && !listed.has(relative)) {
			fail(`Unlisted package file: ${relative}`);
		}
		if (ext === '.md') {
			validateSeamUsage(relative, fs.readFileSync(file, 'utf8'), 'markdown');
		}
	}
}

validatePackage();

for (const message of warnings) {
	console.warn(`WARN ${message}`);
}
if (errors.length) {
	for (const message of errors) {
		console.error(`FAIL ${message}`);
	}
	process.exit(1);
}

console.log(`Theme package OK: ${path.relative(repo, packageDir) || packageDir}`);
