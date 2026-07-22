#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..', '..');

const files = [
	{
		file: 'wp-content/mu-plugins/dsa/assets/css/surface.css',
		label: 'DSA Surface runtime',
		requiredMarker: 'DSA runtime primitive bridge',
		tokenAuthoritySelectors: new Set([
			':root',
			'html[data-kiwe-theme="dark"]',
			"html[data-kiwe-theme='dark']",
		]),
	},
	{
		file: 'wp-content/mu-plugins/dsa/assets/css/bricks-studio-ai.css',
		label: 'Kiwe Bricks Studio AI runtime',
		requiredMarker: null,
		tokenAuthoritySelectors: new Set([
			'.kiwe-bricks-studio',
		]),
	},
];

const hardLiteralPattern = /#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\(|\b-?\d*\.?\d+(px|rem|em|ch|vh|vw|dvh|dvw|svh|svw|lvh|lvw|ms|s|%)\b/;

const errors = [];
const notes = [];

function relative(file) {
	return path.relative(repo, file).replace(/\\/g, '/');
}

function normalizeSelector(selector) {
	return String(selector || '')
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/\s+/g, ' ')
		.trim();
}

function stripQuotedStrings(value) {
	return String(value || '').replace(/"[^"]*"|'[^']*'/g, '""');
}

function stripVarReferences(value) {
	return stripQuotedStrings(value)
		.replace(/var\(--[a-zA-Z0-9_-]+\s*,/g, 'var(,')
		.replace(/var\(--[a-zA-Z0-9_-]+\)/g, 'var()');
}

function lineForIndex(source, index) {
	return source.slice(0, index).split(/\r?\n/).length;
}

function checkFile(config) {
	const abs = path.join(repo, config.file);
	const source = fs.readFileSync(abs, 'utf8');
	const rel = relative(abs);

	if (config.requiredMarker && !source.includes(config.requiredMarker)) {
		errors.push(`${rel}: missing required token authority marker "${config.requiredMarker}".`);
	}

	let checked = 0;
	let authorityTokens = 0;
	const blockPattern = /([^{}]+)\{([^{}]*)\}/g;
	let match;
	while ((match = blockPattern.exec(source))) {
		const selector = normalizeSelector(match[1]);
		const body = match[2];
		const tokenAuthority = config.tokenAuthoritySelectors.has(selector);
		const bodyStart = match.index + match[0].indexOf('{') + 1;
		const declarationPattern = /(^|\n)(\s*)([-\w]+)\s*:\s*([^;{}]+);/g;
		let declaration;
		while ((declaration = declarationPattern.exec(body))) {
			const prop = declaration[3];
			const rawValue = declaration[4].trim();
			const line = lineForIndex(source, bodyStart + declaration.index);
			if (tokenAuthority && prop.startsWith('--')) {
				authorityTokens++;
				continue;
			}
			checked++;
			const value = stripVarReferences(rawValue);
			if (hardLiteralPattern.test(value)) {
				errors.push(`${rel}:${line} ${config.label} runtime declaration owns a raw design value: ${selector} { ${prop}: ${rawValue}; }`);
			}
		}
	}

	notes.push(`${config.label}: ${checked} runtime declarations checked, ${authorityTokens} authority tokens allowed.`);
}

for (const config of files) {
	checkFile(config);
}

if (errors.length) {
	console.error('Kiwe runtime token purity audit failed:');
	for (const error of errors.slice(0, 80)) {
		console.error(`- ${error}`);
	}
	if (errors.length > 80) {
		console.error(`- ...and ${errors.length - 80} more.`);
	}
	process.exit(1);
}

for (const note of notes) {
	console.log(note);
}
console.log('Kiwe runtime token purity audit passed.');
