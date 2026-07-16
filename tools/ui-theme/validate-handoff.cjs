#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

const repo = path.resolve(__dirname, '..', '..');
const handoffDir = path.resolve(process.argv[2] || '');
const packageValidator = path.join(__dirname, 'validate-package.cjs');

const errors = [];
const warnings = [];

const screenSelectors = {
	profile: ['data-dsa-profile-panel'],
	cart: ['data-dsa-cart-panel', 'data-dsa-cart-fbt-rail'],
	checkout: ['data-dsa-checkout-panel', 'data-dsa-checkout-form'],
	search: ['data-dsa-search-panel', 'data-dsa-search-form', 'data-dsa-search-input', 'data-dsa-search-results'],
	menu: ['dsa-menu-panel'],
	saved: ['data-dsa-saved-panel'],
	links: ['dsa-links-panel'],
	notifications: ['data-dsa-notification-panel'],
	'ios-install': ['data-dsa-ios-install-panel'],
	games: ['data-dsa-game-panel'],
	ai: ['data-dsa-ai-panel'],
};

const forbiddenPreviewPatterns = [
	{ label: 'remote http(s) resource', pattern: /\b(?:src|href)\s*=\s*["']https?:\/\//i },
	{ label: 'remote CSS import', pattern: /@import\s+(?:url\()?['"]?https?:\/\//i },
	{ label: 'remote url()', pattern: /url\(\s*['"]?https?:\/\//i },
	{ label: 'analytics/tracker script', pattern: /\b(?:gtag|google-analytics|googletagmanager|facebook\.net|clarity\.ms|hotjar)\b/i },
	{ label: 'service worker registration', pattern: /serviceWorker\s*\.\s*register/i },
	{ label: 'network fetch', pattern: /\bfetch\s*\(/i },
	{ label: 'XMLHttpRequest', pattern: /\bXMLHttpRequest\b/i },
	{ label: 'payment simulation', pattern: /\b(?:stripe|paypal|razorpay|payment_intent|place_order)\b/i },
];

function fail(message) {
	errors.push(message);
}

function warn(message) {
	warnings.push(message);
}

function relative(file) {
	return path.relative(repo, file).replace(/\\/g, '/');
}

function read(file) {
	return fs.readFileSync(file, 'utf8');
}

function readJson(file) {
	try {
		return JSON.parse(read(file));
	} catch (error) {
		fail(`${relative(file)} is not valid JSON: ${error.message}`);
		return {};
	}
}

function isInside(parent, child) {
	const rel = path.relative(parent, child);
	return rel && !rel.startsWith('..') && !path.isAbsolute(rel);
}

function findImportPackages(root) {
	const importRoot = path.join(root, 'import');
	if (!fs.existsSync(importRoot) || !fs.statSync(importRoot).isDirectory()) {
		fail('Handoff must contain import/<theme-id>/');
		return [];
	}

	return fs.readdirSync(importRoot, { withFileTypes: true })
		.filter((entry) => entry.isDirectory())
		.map((entry) => path.join(importRoot, entry.name))
		.filter((dir) => fs.existsSync(path.join(dir, 'theme.json')));
}

function runImportValidator(themeDir) {
	const result = childProcess.spawnSync(process.execPath, [packageValidator, themeDir], {
		cwd: repo,
		encoding: 'utf8',
	});
	if (result.stdout) process.stdout.write(result.stdout);
	if (result.stderr) process.stderr.write(result.stderr);
	if (result.status !== 0) {
		fail(`Import package failed validation: ${relative(themeDir)}`);
	}
}

function requireString(body, needle, label) {
	if (!body.includes(needle)) {
		fail(`Preview missing ${label}: ${needle}`);
	}
}

function validatePreviewLinks(previewHtml, manifest, packageDir) {
	const cssFiles = Array.isArray(manifest.css) ? manifest.css : [];
	for (const css of cssFiles) {
		const expected = '../import/' + path.basename(packageDir) + '/' + css;
		if (!previewHtml.includes(expected)) {
			fail(`Preview does not link importable CSS: ${expected}`);
		}
	}
}

function validatePreviewModes(previewHtml, manifest) {
	requireString(previewHtml, 'data-dsa-surface', 'Surface root');
	requireString(previewHtml, 'data-dsa-ui-contract="2"', 'UI contract marker');
	requireString(previewHtml, 'data-dsa-dock-presentation', 'dock presentation attribute');
	requireString(previewHtml, 'data-dsa-dock-orientation', 'dock orientation attribute');
	requireString(previewHtml, '--dsa-dock-control-size', 'Geometry Engine control-size variable');
	requireString(previewHtml, '--dsa-dock-only-reserve', 'Geometry Engine dock reserve variable');
	requireString(previewHtml, '--dsa-screen-block-reserve', 'Geometry Engine screen reserve variable');

	const supports = new Set(Array.isArray(manifest.supports) ? manifest.supports : []);
	if (supports.has('split-dock')) requireString(previewHtml, 'dsa-dock-split', 'split dock runtime class');
	if (supports.has('navigation-bar')) requireString(previewHtml, 'navbar', 'navigation bar runtime value');
	if (supports.has('dock-shape-pill')) requireString(previewHtml, 'dsa-dock-shape-pill', 'pill dock shape preview');
	if (supports.has('dock-shape-box')) requireString(previewHtml, 'dsa-dock-shape-box', 'box dock shape preview');
	if (supports.has('dock-shape-square')) requireString(previewHtml, 'dsa-dock-shape-square', 'square dock shape preview');
	if (supports.has('dark')) requireString(previewHtml, 'data-kiwe-theme="dark"', 'dark mode preview state');
}

function validatePreviewScreens(previewHtml, manifest) {
	const screens = Array.isArray(manifest.screens) ? manifest.screens : [];
	for (const screen of screens) {
		for (const selector of screenSelectors[screen] || []) {
			if (!previewHtml.includes(selector)) {
				fail(`Preview for screen "${screen}" missing required selector/string: ${selector}`);
			}
		}
	}

	if (screens.includes('cart')) {
		const fbtRail = previewHtml.includes('data-dsa-cart-fbt-rail') || previewHtml.includes('dsa-cart-fbt__rail');
		if (!fbtRail) fail('Cart preview must demonstrate FBT as a horizontal rail selector');
	}

	if (screens.includes('links')) {
		if (!/site score/i.test(previewHtml)) warn('Links preview should document/demonstrate site score shown and absent states');
		if (!/(score[-_\s]?missing|without[-_\s]?score|no[-_\s]?score|score\s+absent)/i.test(previewHtml)) {
			warn('Links preview should include an absent-score state where no score badge renders');
		}
	}
}

function validatePlaceholderDoc(root) {
	const placeholders = path.join(root, 'preview', 'PLACEHOLDERS.md');
	if (!fs.existsSync(placeholders)) {
		fail('Preview must contain preview/PLACEHOLDERS.md');
		return;
	}
	const body = read(placeholders);
	if (!/preview[-\s]?only/i.test(body)) {
		warn('PLACEHOLDERS.md should explicitly say placeholders are preview-only');
	}
}

function requireReadmePattern(body, pattern, label) {
	if (!pattern.test(body)) {
		fail(`Handoff README missing ${label}`);
	}
}

function validateHandoffReadme(root, manifest) {
	const file = path.join(root, 'README.md');
	if (!fs.existsSync(file)) {
		fail('Handoff must contain README.md at its root');
		return;
	}

	const body = read(file);
	requireReadmePattern(body, /distinctness|visual thesis|differs from legacy|differs from kiwe 2027/i, 'distinctness note / visual thesis');
	requireReadmePattern(body, /selector[-\s]?fit|stable selectors|data-dsa|\.dsa-/i, 'selector-fit checklist');
	requireReadmePattern(body, /validation|validate-package|validate-handoff/i, 'validation instructions');
	requireReadmePattern(body, /limitation|intentional limitation|known limitation/i, 'intentional limitations section');
	requireReadmePattern(body, /core\/plugin|core change|plugin change|runtime change|no core/i, 'core/plugin change separation');
	requireReadmePattern(body, /screens|screen coverage|covered screens/i, 'screen coverage summary');
	requireReadmePattern(body, /dock|sheet|classic|navigation bar|split/i, 'shell mode coverage summary');
	requireReadmePattern(body, /appShellAdoption|Seam adoption|public-adopted|shadow-only/i, 'Seam AppShell adoption map acknowledgement');

	if ((manifest.screens || []).includes('cart') && !/fbt|frequently bought together|data-dsa-cart-fbt-rail/i.test(body)) {
		fail('Handoff README must mention FBT rail coverage when cart is supported');
	}

	if ((manifest.screens || []).includes('links') && !/(score absent|no score|without score|site score)/i.test(body)) {
		fail('Handoff README must mention Links site score shown/absent handling when links is supported');
	}
}

function validatePreview(root, packageDir, manifest) {
	const preview = path.join(root, 'preview', 'index.html');
	if (!fs.existsSync(preview)) {
		fail('Handoff must contain preview/index.html');
		return;
	}
	const previewHtml = read(preview);

	for (const check of forbiddenPreviewPatterns) {
		if (check.pattern.test(previewHtml)) {
			fail(`Preview contains forbidden ${check.label}`);
		}
	}

	if (!/kiwe-preview-toolbar/i.test(previewHtml) || !/kiwe-preview-stage/i.test(previewHtml)) {
		warn('Preview should keep controls outside the app viewport using kiwe-preview-toolbar/stage structure');
	}

	validatePreviewLinks(previewHtml, manifest, packageDir);
	validatePreviewModes(previewHtml, manifest);
	validatePreviewScreens(previewHtml, manifest);
	validatePlaceholderDoc(root);
}

function validateHandoff() {
	if (!process.argv[2]) {
		fail('Usage: node tools/ui-theme/validate-handoff.cjs <theme-handoff-folder>');
		return;
	}
	if (!fs.existsSync(handoffDir) || !fs.statSync(handoffDir).isDirectory()) {
		fail(`Handoff folder does not exist: ${handoffDir}`);
		return;
	}

	if (!fs.existsSync(path.join(handoffDir, 'README.md'))) {
		fail('Handoff must contain README.md at its root');
	}

	const packages = findImportPackages(handoffDir);
	if (!packages.length) return;
	if (packages.length > 1) warn('Handoff contains multiple import packages; validating each import package but preview checks use the first one');

	for (const packageDir of packages) {
		if (!isInside(handoffDir, packageDir)) {
			fail(`Import package escapes handoff root: ${packageDir}`);
			continue;
		}
		runImportValidator(packageDir);
	}

	const primary = packages[0];
	const manifest = readJson(path.join(primary, 'theme.json'));
	validateHandoffReadme(handoffDir, manifest);
	validatePreview(handoffDir, primary, manifest);
}

validateHandoff();

for (const message of warnings) {
	console.warn(`WARN ${message}`);
}
if (errors.length) {
	for (const message of errors) {
		console.error(`FAIL ${message}`);
	}
	process.exit(1);
}

console.log(`Theme handoff OK: ${path.relative(repo, handoffDir) || handoffDir}`);
