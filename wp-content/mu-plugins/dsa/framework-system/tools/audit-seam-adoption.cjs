#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..', '..', '..', '..', '..');
const vocabularyPath = path.join(repo, 'wp-content/mu-plugins/dsa/ui-system/seam-vocabulary.json');
const seamCssPath = path.join(repo, 'wp-content/mu-plugins/dsa/assets/css/seam.css');
const surfaceJsPath = path.join(repo, 'wp-content/mu-plugins/dsa/assets/js/surface.js');

const errors = [];
const warnings = [];
const notes = [];

const riskProperties = [
	'display',
	'inline-size',
	'block-size',
	'width',
	'height',
	'min-block-size',
	'padding',
	'gap',
	'border',
	'border-radius',
	'background',
	'box-shadow',
	'color',
	'object-fit',
	'flex-direction',
	'transition',
	'transform',
];

function fail(message) {
	errors.push(message);
}

function warn(message) {
	warnings.push(message);
}

function note(message) {
	notes.push(message);
}

function read(file) {
	return fs.readFileSync(file, 'utf8');
}

function readJson(file) {
	try {
		return JSON.parse(read(file));
	} catch (error) {
		fail(`${path.relative(repo, file)} is not valid JSON: ${error.message}`);
		return {};
	}
}

function collectRuntimeSeamClasses(source) {
	const classes = new Set();
	for (const match of source.matchAll(/classes\s*:\s*\[([\s\S]*?)\]/g)) {
		for (const item of match[1].matchAll(/['"]((?:seam-)[a-z0-9_-]+)['"]/gi)) {
			classes.add(item[1].toLowerCase());
		}
	}
	return classes;
}

function collectRuntimePublicAttrs(source) {
	const attrs = [];
	for (const match of source.matchAll(/setAttribute\s*\(\s*['"]data-(role|flow|tone|scene|state|motion|shape)['"]/gi)) {
		attrs.push(match[1].toLowerCase());
	}
	return attrs;
}

function classForRole(role) {
	return `seam-${role}`;
}

function selectorBlockForClass(css, className) {
	const escaped = className.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const pattern = new RegExp(`([^{}]*\\.${escaped}[^{}]*)\\{([^{}]*)\\}`, 'gi');
	const blocks = [];
	for (const match of css.matchAll(pattern)) {
		blocks.push({
			selector: match[1].replace(/\s+/g, ' ').trim(),
			body: match[2],
		});
	}
	return blocks;
}

function declarations(body) {
	const out = new Set();
	for (const match of body.matchAll(/([a-z-]+)\s*:/gi)) {
		out.add(match[1].toLowerCase());
	}
	return out;
}

function riskyDeclarations(css, className) {
	const found = new Set();
	for (const block of selectorBlockForClass(css, className)) {
		const declared = declarations(block.body);
		for (const property of riskProperties) {
			if (declared.has(property)) found.add(property);
		}
	}
	return Array.from(found).sort();
}

function isIsolationReason(reason) {
	return /isolation|site css|third-party|protected data-seam|appshell keeps/i.test(String(reason || ''));
}

function validateAdoption() {
	const vocabulary = readJson(vocabularyPath);
	const adoption = vocabulary.appShellAdoption || {};
	const publicAdopted = adoption.publicAdopted || {};
	const shadowOnly = adoption.shadowOnly || {};
	const authorityOnly = new Set(adoption.authorityOnly || []);
	const seamCss = read(seamCssPath);
	const surfaceJs = read(surfaceJsPath);
	const runtimeClasses = collectRuntimeSeamClasses(surfaceJs);
	const runtimePublicAttrs = collectRuntimePublicAttrs(surfaceJs);

	if (runtimePublicAttrs.length) {
		fail(`DSA runtime writes public data-* Seam attributes directly: ${runtimePublicAttrs.join(', ')}. Use protected data-seam-* for AppShell internals.`);
	}

	for (const [role, rule] of Object.entries(publicAdopted)) {
		if (authorityOnly.has(role)) {
			fail(`Role "${role}" cannot be both public-adopted and authority-only.`);
		}
		for (const className of rule.classes || []) {
			const normalized = String(className).toLowerCase();
			if (!runtimeClasses.has(normalized)) {
				warn(`Public-adopted role "${role}" declares ${className}, but DSA runtime does not currently apply it.`);
			}
			const risks = riskyDeclarations(seamCss, normalized);
			if (risks.length) {
				note(`Public-adopted ${className} carries CSS declarations: ${risks.join(', ')}`);
			}
		}
	}

	for (const [role, reason] of Object.entries(shadowOnly)) {
		if (authorityOnly.has(role)) {
			fail(`Role "${role}" cannot be both shadow-only and authority-only.`);
		}
		const publicClass = classForRole(role);
		if (runtimeClasses.has(publicClass)) {
			fail(`Shadow-only role "${role}" is being applied as public class ${publicClass} in DSA runtime.`);
		}
		if (!reason || String(reason).trim().length < 16) {
			fail(`Shadow-only role "${role}" needs a concrete reason in appShellAdoption.shadowOnly.`);
		}
		const risks = riskyDeclarations(seamCss, publicClass);
		if (!risks.length) {
			if (isIsolationReason(reason)) {
				note(`Shadow-only ${publicClass} remains protected for AppShell isolation, not core Seam visual risk.`);
			} else {
				warn(`Shadow-only role "${role}" has no detected high-risk declarations on ${publicClass}; review if it can move toward public adoption.`);
			}
		} else {
			note(`Shadow-only ${publicClass} risk declarations: ${risks.join(', ')}`);
		}
	}
}

validateAdoption();

for (const message of notes) {
	console.log(`NOTE ${message}`);
}
for (const message of warnings) {
	console.warn(`WARN ${message}`);
}
if (errors.length) {
	for (const message of errors) {
		console.error(`FAIL ${message}`);
	}
	process.exit(1);
}

console.log('Seam adoption audit OK');
