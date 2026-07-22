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
const seamTokenServicePath = path.join(repo, 'wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php');

const allowedExtensions = new Set(['.json', '.md', '.css', '.svg', '.png', '.webp', '.avif']);
const forbiddenExtensions = new Set(['.php', '.phtml', '.phar', '.js', '.mjs', '.cjs', '.ts', '.jsx', '.tsx', '.html', '.htm', '.wasm']);
const forbiddenCssPatterns = [
	{ label: 'remote @import', pattern: /@import\s+(?:url\()?['"]?https?:\/\//i },
	{ label: 'remote url()', pattern: /url\(\s*['"]?https?:\/\//i },
	{ label: 'data url', pattern: /url\(\s*['"]?data:/i },
	{ label: 'javascript url', pattern: /url\(\s*['"]?javascript:/i },
	{ label: 'fixed full-screen overlay', pattern: /position\s*:\s*fixed[\s\S]{0,240}(?:inset\s*:\s*0|(?:^|[;{]\s*)width\s*:\s*100vw|(?:^|[;{]\s*)height\s*:\s*100vh)/i },
];
const riskyCssPatterns = [
	{
		label: 'viewport-width sizing inside AppShell theme CSS',
		pattern: /\b(?:width|min-width|max-width|inline-size|min-inline-size|max-inline-size)\s*:\s*100vw/i,
		message: 'Use Geometry Engine inline variables, percentages, or max-width:100% instead of 100vw inside sheets/screens; 100vw often causes horizontal overflow in inset sheets.'
	},
	{
		label: 'non-shrinking decorative pseudo-element',
		pattern: /::(?:before|after)\s*\{[\s\S]{0,260}flex\s*:\s*0\s+0\s+auto/i,
		message: 'Decorative ::before/::after flex items should usually use flex:1 1 auto, max-width:100%, or be absolutely clipped; flex:0 0 auto can overflow narrow sheets.'
	},
	{
		label: 'space-between flex header without wrapping',
		pattern: /(?:\.dsa-[^{]*(?:header|panel)|\[data-dsa-[^\]]*header[^\]]*\])[^{]*\{(?=[\s\S]{0,360}display\s*:\s*flex)(?=[\s\S]{0,360}justify-content\s*:\s*space-between)(?![\s\S]{0,360}flex-wrap\s*:)/i,
		message: 'Panel headers that use flex + space-between should allow wrapping or shrinking children at narrow widths; otherwise badges/stripes can create horizontal scroll.'
	}
];

const errors = [];
const warnings = [];
let seamContract = null;

const themeTokenTopLevelKeys = new Set(['enabled', 'profile_label', 'overrides', 'bricks_theme_style']);
const allowedThemeCssTokenAliases = new Set(['radius-panel', 'surface-panel']);
const screenCopyFields = {
	profile: new Set(['label', 'eyebrow', 'title', 'intro', 'accountLabel', 'editLabel', 'ordersTitle', 'ordersText', 'downloadsTitle', 'downloadsText', 'notificationsTitle', 'notificationsText', 'addressesTitle', 'addressesText', 'passwordTitle', 'passwordText', 'signOutLabel', 'recentOrdersTitle']),
	cart: new Set(['label', 'eyebrow', 'title', 'emptyTitle', 'emptyText', 'fbtTitle', 'checkoutLabel', 'checkoutEmptyLabel']),
	checkout: new Set(['label', 'title', 'loadingText', 'unavailableText', 'continueLabel', 'returnLabel', 'shippingToggleLabel', 'accountToggleLabel']),
	search: new Set(['label', 'eyebrow', 'title', 'intro', 'placeholder']),
	menu: new Set(['label', 'eyebrow', 'title', 'intro', 'contextTitle', 'dashboardLabel']),
	saved: new Set(['label', 'eyebrow', 'title', 'intro', 'emptyTitle', 'emptyText', 'wishlistLabel', 'bookmarksLabel', 'summaryWishlistLabel', 'summaryBookmarksLabel', 'summaryTotalLabel']),
	links: new Set(['label', 'eyebrow', 'title', 'intro', 'shopLabel', 'shopMeta', 'cartLabel', 'cartMeta']),
	notifications: new Set(['label', 'eyebrow', 'title', 'intro', 'topicsLegend', 'channelsLegend', 'appText', 'submitLabel', 'emailPlaceholder', 'phonePlaceholder']),
	'ios-install': new Set(['label', 'eyebrow', 'title', 'intro', 'stepOneTitle', 'stepOneText', 'stepTwoTitle', 'stepTwoText', 'stepThreeTitle', 'stepThreeText', 'doneLabel']),
	games: new Set(['label', 'eyebrow', 'startTitle', 'startText', 'mobileStartText', 'chooseText', 'scoreLabel', 'bestLabel']),
	ai: new Set(['label', 'eyebrow', 'title', 'intro', 'emptyTitle', 'emptyText', 'chatPlaceholder'])
};

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

function officialTokenNames() {
	if (!fs.existsSync(seamTokenServicePath)) return new Set();
	const php = fs.readFileSync(seamTokenServicePath, 'utf8');
	const names = new Set();
	for (const match of php.matchAll(/self::token\(\s*'([^']+)'/g)) {
		names.add(match[1]);
	}
	return names;
}

function isPlainObject(value) {
	return value && typeof value === 'object' && !Array.isArray(value);
}

function validateThemePackageTokenSettings(tokens) {
	if (!isPlainObject(tokens)) {
		fail('theme-package.json settings.tokens must be an object containing enabled, profile_label, overrides, and optional bricks_theme_style');
		return;
	}

	for (const key of Object.keys(tokens)) {
		if (/^--|var\(/i.test(key)) {
			fail(`theme-package.json settings.tokens uses CSS variable key "${key}". Use settings.tokens.overrides with official token names such as "color-brand", without --kiwe- or var().`);
		} else if (!themeTokenTopLevelKeys.has(key)) {
			fail(`theme-package.json settings.tokens has unsupported top-level key "${key}". Allowed keys are enabled, profile_label, overrides, and bricks_theme_style`);
		}
	}

	if (!Object.prototype.hasOwnProperty.call(tokens, 'overrides')) {
		fail('theme-package.json settings.tokens must contain an overrides object');
		return;
	}

	if (!isPlainObject(tokens.overrides)) {
		fail('theme-package.json settings.tokens.overrides must be an object keyed by official Kiwe universal token names');
		return;
	}

	const officialTokens = officialTokenNames();
	for (const tokenName of Object.keys(tokens.overrides)) {
		if (/^--|var\(/i.test(tokenName)) {
			fail(`theme-package.json settings.tokens.overrides "${tokenName}" must use the official token name without --kiwe- or var()`);
		} else if (!/^[a-z0-9][a-z0-9_-]{1,80}$/i.test(tokenName)) {
			fail(`theme-package.json settings.tokens.overrides has invalid token name: ${tokenName}`);
		} else if (officialTokens.size && !officialTokens.has(tokenName)) {
			fail(`theme-package.json settings.tokens.overrides contains unknown Kiwe token: ${tokenName}`);
		}
	}

	if (Object.prototype.hasOwnProperty.call(tokens, 'bricks_theme_style') && !isPlainObject(tokens.bricks_theme_style)) {
		fail('theme-package.json settings.tokens.bricks_theme_style must be an object when present');
	}
}

function validateThemePackageScreenSettings(screens) {
	if (!isPlainObject(screens)) {
		fail('theme-package.json settings.screens must be an object keyed by registered DSA screen ids');
		return;
	}

	for (const [screen, config] of Object.entries(screens)) {
		const fields = screenCopyFields[screen];
		if (!fields) {
			fail(`theme-package.json settings.screens contains unsupported screen "${screen}". Use registered DSA screens only: ${Object.keys(screenCopyFields).join(', ')}`);
			continue;
		}
		if (!isPlainObject(config)) {
			fail(`theme-package.json settings.screens.${screen} must be an object of presentation-only copy fields`);
			continue;
		}
		for (const key of Object.keys(config)) {
			if (!fields.has(key)) {
				fail(`theme-package.json settings.screens.${screen}.${key} is not a live Kiwe screen-copy field. Use only supported ${screen} fields: ${Array.from(fields).join(', ')}`);
			}
		}
	}
}

function validateKiweCssTokenReferences(relative, body) {
	const officialTokens = officialTokenNames();
	const seen = new Set();
	for (const match of body.matchAll(/--kiwe-([a-z0-9-]+)/gi)) {
		const raw = String(match[0]);
		const tokenName = String(match[1]);
		if (seen.has(raw)) continue;
		seen.add(raw);
		if (tokenName.startsWith('theme-')) continue;
		if (allowedThemeCssTokenAliases.has(tokenName)) continue;
		if (officialTokens.size && officialTokens.has(tokenName)) continue;
		fail(`${relative} references unsupported Kiwe token variable "${raw}". Use official universal tokens such as --kiwe-color-surface, --kiwe-color-surface-raised, --kiwe-radius-xl, --kiwe-radius-full, --kiwe-shadow-md, and --kiwe-space-md, or documented --kiwe-theme-* aliases.`);
	}
}

function validateNoRuntimeBridgeTokenReferences(relative, body) {
	const seen = new Set();
	for (const match of body.matchAll(/--dsa-runtime-token-\d{4}/gi)) {
		seen.add(match[0]);
	}
	if (seen.size) {
		fail(`${relative} references Kiwe core runtime bridge token(s) ${Array.from(seen).sort().join(', ')}. These generated --dsa-runtime-token-* variables are private migration glue for Kiwe runtime CSS, not public Seam/Theme vocabulary. Use official --kiwe-* variables, documented --kiwe-theme-* aliases, or request promotion to the universal token library.`);
	}
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

function isGeometrySelector(selector) {
	return /\[data-dsa-dock(?:[\]=\s-])|\.dsa-dock(?![-_a-zA-Z0-9])|\.dsa-dock-cluster(?![-_a-zA-Z0-9])|\.dsa-phonekey-dock(?![-_a-zA-Z0-9])|\[data-dsa-screen(?:[\]=\s])|\[data-dsa-screen-backdrop(?:[\]=\s])|\.dsa-panel(?![-_a-zA-Z0-9])|\.dsa-sheet(?![-_a-zA-Z0-9])/i.test(selector);
}

function isDockArrangementSelector(selector) {
	return /\[data-dsa-dock(?:[\]=\s-])|\.dsa-dock(?![-_a-zA-Z0-9])|\.dsa-dock-cluster(?![-_a-zA-Z0-9])|\.dsa-phonekey-dock(?![-_a-zA-Z0-9])|\.dsa-dock__button(?![-_a-zA-Z0-9])|\.dsa-ai-launcher(?![-_a-zA-Z0-9])|\[data-dsa-module(?:[\]=\s])|\[data-dsa-dock-(?:focus|primary|cluster)(?:[\]=\s])/i.test(selector);
}

function validateGeometryOwnership(relative, body) {
	for (const match of body.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const selector = match[1].trim();
		const declarations = match[2];
		if (!isGeometrySelector(selector)) continue;
		const geometryMatches = [];
		if (/(?:^|;)\s*position\s*:\s*(?:fixed|absolute)\b/i.test(declarations)) geometryMatches.push('position');
		if (/(?:^|;)\s*(?:inset|top|right|bottom|left|z-index)\s*:/i.test(declarations)) geometryMatches.push('edge/z-index');
		if (/(?:^|;)\s*(?:width|inline-size|min-width|min-inline-size|max-width|max-inline-size)\s*:\s*100vw\b/i.test(declarations)) geometryMatches.push('100vw sizing');
		if (/(?:^|;)\s*(?:height|block-size|min-height|min-block-size|max-height|max-block-size)\s*:\s*100vh\b/i.test(declarations)) geometryMatches.push('100vh sizing');
		if (geometryMatches.length) {
			fail(`${relative} assigns ${[...new Set(geometryMatches)].join(', ')} to AppShell geometry selector "${selector.slice(0, 140)}". Kiwe Geometry Engine owns dock/screen/sheet/backdrop placement; move this to preview-only CSS or core.`);
		}
	}

	for (const match of body.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const selector = match[1].trim();
		const declarations = match[2];
		if (!isDockArrangementSelector(selector)) continue;
		const dockMatches = [];
		if (/(?:^|;)\s*(?:display|flex(?:-(?:basis|grow|shrink|direction|wrap))?|order|grid(?:-[a-z-]+)?|place-(?:items|content|self)|align-(?:items|content|self)|justify-(?:items|content|self))\s*:/i.test(declarations)) dockMatches.push('dock layout');
		if (/(?:^|;)\s*(?:gap|row-gap|column-gap)\s*:/i.test(declarations)) dockMatches.push('dock gap');
		if (/(?:^|;)\s*(?:margin|margin-inline|margin-block|margin-left|margin-right|margin-top|margin-bottom|padding|padding-inline|padding-block|padding-left|padding-right|padding-top|padding-bottom)\s*:/i.test(declarations)) dockMatches.push('dock spacing');
		if (/(?:^|;)\s*(?:width|inline-size|min-width|min-inline-size|max-width|max-inline-size|height|block-size|min-height|min-block-size|max-height|max-block-size)\s*:/i.test(declarations)) dockMatches.push('dock sizing');
		if (/(?:^|;)\s*(?:transform|translate|scale|rotate)\s*:/i.test(declarations)) dockMatches.push('dock transform');
		if (/(?:^|;)\s*overflow(?:-[xy])?\s*:/i.test(declarations)) dockMatches.push('dock overflow');
		if (dockMatches.length) {
			fail(`${relative} assigns ${[...new Set(dockMatches)].join(', ')} to dock arrangement selector "${selector.slice(0, 140)}". Kiwe Geometry Engine owns dock measurement, split spacing, effect-safe gutters, control sizing, and focus placement; use settings/tokens or preview-only CSS instead.`);
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
	const themePackagePath = path.join(packageDir, 'theme-package.json');
	const hasThemePackage = fs.existsSync(themePackagePath);
	let themePackage = {};
	if (hasThemePackage) {
		themePackage = readJson(themePackagePath);
		if (themePackage.schema !== 'kiwe.theme-package.v1') fail('theme-package.json schema must be kiwe.theme-package.v1');
		if (!themePackage.theme || typeof themePackage.theme !== 'object') fail('theme-package.json must contain a root theme manifest object');
		if (typeof themePackage.css !== 'string' || !themePackage.css.trim()) warn('theme-package.json should contain root css matching css/theme.css for one-file Kiwe admin/API import');
		const packageSettings = themePackage.settings && typeof themePackage.settings === 'object' ? themePackage.settings : {};
		if (manifest.profile === 'marketplace' && !Object.prototype.hasOwnProperty.call(packageSettings, 'tokens')) {
			fail('theme-package.json settings.tokens is required for marketplace AppShell themes so DSA, Seam page CSS, and Bricks global theme style stay synchronized');
		}
		if (Object.prototype.hasOwnProperty.call(packageSettings, 'tokens')) {
			validateThemePackageTokenSettings(packageSettings.tokens);
			const style = isPlainObject(packageSettings.tokens) && isPlainObject(packageSettings.tokens.bricks_theme_style) ? packageSettings.tokens.bricks_theme_style : {};
			if (style.id && !/^[a-z0-9_-]{1,80}$/i.test(String(style.id))) fail(`theme-package.json settings.tokens.bricks_theme_style.id is invalid: ${style.id}`);
		}
		if (Object.prototype.hasOwnProperty.call(packageSettings, 'screens')) {
			validateThemePackageScreenSettings(packageSettings.screens);
		}
	}

	const knownScreens = new Set(Object.keys((payloads && payloads.screens) || {}));
	for (const screen of manifest.screens || []) {
		if (!knownScreens.has(screen)) fail(`Unknown screen in theme.json: ${screen}`);
	}

	const listed = new Set(['theme.json']);
	if (hasThemePackage) listed.add('theme-package.json');
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
		for (const check of riskyCssPatterns) {
			if (check.pattern.test(body)) warn(`CSS file ${css} has risky ${check.label}: ${check.message}`);
		}
		validateSeamUsage(css, body, 'css');
		validateGeometryOwnership(css, body);
		validateKiweCssTokenReferences(css, body);
		validateNoRuntimeBridgeTokenReferences(css, body);
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
