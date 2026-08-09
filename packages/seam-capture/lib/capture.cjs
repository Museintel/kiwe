const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright-core');
const { validateContract } = require('../../seam-contracts/lib/validator.cjs');
const { findBrowser } = require('./browser.cjs');
const { startLocalServer } = require('./local-server.cjs');

const CANONICAL_VIEWPORTS = [
	{ id: 'desktop-1440', width: 1440, height: 1000, theme: 'light', state: 'default' },
	{ id: 'desktop-1280', width: 1280, height: 900, theme: 'light', state: 'default' },
	{ id: 'tablet-991', width: 991, height: 900, theme: 'light', state: 'default' },
	{ id: 'tablet-768', width: 768, height: 900, theme: 'light', state: 'default' },
	{ id: 'mobile-478', width: 478, height: 900, theme: 'light', state: 'default' },
	{ id: 'mobile-375', width: 375, height: 812, theme: 'light', state: 'default' },
	{ id: 'mobile-320', width: 320, height: 720, theme: 'light', state: 'default' }
];

const COMPUTED_PROPERTIES = [
	'display', 'position', 'inset', 'top', 'right', 'bottom', 'left', 'z-index', 'overflow', 'overflow-x', 'overflow-y',
	'box-sizing', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height', 'aspect-ratio',
	'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis', 'justify-content', 'align-items', 'align-content', 'align-self',
	'order', 'gap', 'row-gap', 'column-gap', 'grid-template-columns', 'grid-template-rows', 'grid-auto-flow', 'grid-column', 'grid-row',
	'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
	'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'border-width', 'border-style', 'border-color', 'border-radius',
	'background', 'background-color', 'background-image', 'background-position', 'background-size', 'background-repeat',
	'color', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'line-height', 'letter-spacing',
	'text-align', 'text-decoration', 'text-transform', 'text-shadow', 'white-space', 'word-break',
	'opacity', 'visibility', 'transform', 'transform-origin', 'filter', 'backdrop-filter', 'box-shadow',
	'object-fit', 'object-position', 'cursor', 'pointer-events', 'list-style-type'
];

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function isHttp(value) {
	return /^https?:\/\//i.test(String(value));
}

function isPrivateHostname(hostname) {
	const host = hostname.toLowerCase().replace(/^\[|\]$/g, '');
	if (host === 'localhost' || host === '::1' || host.endsWith('.local')) return true;
	if (/^127\./.test(host) || /^10\./.test(host) || /^192\.168\./.test(host)) return true;
	const match = host.match(/^172\.(\d+)\./);
	return Boolean(match && Number(match[1]) >= 16 && Number(match[1]) <= 31);
}

function canonicalResourceUrl(value, localServer) {
	if (!localServer || !String(value).startsWith(`${localServer.origin}/`)) return value;
	const parsed = new URL(value);
	return decodeURIComponent(parsed.pathname).replace(/^\/+/, '').replace(/\\/g, '/');
}

function dataResource(url, kind) {
	const match = String(url).match(/^data:([^;,]*)(;base64)?,(.*)$/s);
	if (!match) return null;
	try {
		const bytes = match[2] ? Buffer.from(match[3], 'base64') : Buffer.from(decodeURIComponent(match[3]));
		return { url, kind, status: 200, mime: match[1] || 'text/plain', bytes: bytes.length, sha256: sha256(bytes), blocked: false };
	} catch {
		return { url, kind, status: null, mime: match[1] || null, bytes: null, sha256: null, blocked: false };
	}
}

function validateCapture(capture) {
	const result = validateContract('capture', capture);
	if (!result.ok) {
		const error = new Error(`Capture contract validation failed: ${result.errors.map((item) => item.message).join(' ')}`);
		error.findings = result.errors;
		throw error;
	}
}

async function collectPageEvidence(page, viewportId, options = {}) {
	return page.evaluate(({ viewportId: activeViewport, computedProperties, proofMode }) => {
		const excluded = new Set(['script', 'style', 'link', 'meta', 'noscript', 'template']);
		const simpleHash = (input) => {
			let hash = 2166136261;
			for (let index = 0; index < input.length; index += 1) {
				hash ^= input.charCodeAt(index);
				hash = Math.imul(hash, 16777619);
			}
			return (hash >>> 0).toString(36);
		};
		const domPath = (element) => {
			const segments = [];
			for (let current = element; current && current.nodeType === 1; current = current.parentElement) {
				const tag = current.localName;
				if (tag === 'html') { segments.unshift('html'); break; }
				let index = 1;
				for (let sibling = current.previousElementSibling; sibling; sibling = sibling.previousElementSibling) if (sibling.localName === tag) index += 1;
				segments.unshift(`${tag}:nth-of-type(${index})`);
			}
			return segments.join('>');
		};
		const directText = (element) => [...element.childNodes]
			.filter((node) => node.nodeType === Node.TEXT_NODE)
			.map((node) => node.textContent || '').join(' ').replace(/\s+/g, ' ').trim();
		const semanticRole = (element) => {
			if (element.getAttribute('role')) return element.getAttribute('role');
			const roles = { a: element.hasAttribute('href') ? 'link' : null, button: 'button', img: 'img', nav: 'navigation', main: 'main', header: 'banner', footer: 'contentinfo', form: 'form', ul: 'list', ol: 'list', li: 'listitem', input: 'textbox', textarea: 'textbox', select: 'combobox', table: 'table' };
			return roles[element.localName] || (/^h[1-6]$/.test(element.localName) ? 'heading' : null);
		};
		const accessibleName = (element) => {
			const labelledBy = element.getAttribute('aria-labelledby');
			if (labelledBy) {
				const value = labelledBy.split(/\s+/).map((id) => document.getElementById(id)?.textContent || '').join(' ').trim();
				if (value) return value.slice(0, 500);
			}
			return (element.getAttribute('aria-label') || element.getAttribute('alt') || element.getAttribute('title') || (['a', 'button'].includes(element.localName) ? element.textContent : '') || '').replace(/\s+/g, ' ').trim().slice(0, 500);
		};
		const declarations = (style) => {
			const result = {};
			for (const property of style) {
				const value = style.getPropertyValue(property).trim();
				if (!value) continue;
				result[property] = value + (style.getPropertyPriority(property) ? ' !important' : '');
			}
			for (const property of ['background', 'border', 'border-radius', 'font', 'margin', 'overflow', 'padding', 'text-decoration', 'transition']) {
				const value = style.getPropertyValue(property).trim();
				if (value) result[property] = value + (style.getPropertyPriority(property) ? ' !important' : '');
			}
			for (const property of computedProperties) {
				if (result[property]) continue;
				const value = style.getPropertyValue(property).trim();
				if (value && !['initial', 'unset', 'revert', 'revert-layer'].includes(value)) result[property] = value + (style.getPropertyPriority(property) ? ' !important' : '');
			}
			return result;
		};
		const selectorParts = (selector) => {
			const parts = [];
			let start = 0;
			let depth = 0;
			let quote = '';
			for (let index = 0; index < selector.length; index += 1) {
				const char = selector[index];
				if (quote) {
					if (char === quote && selector[index - 1] !== '\\') quote = '';
					continue;
				}
				if (char === '"' || char === "'") { quote = char; continue; }
				if (char === '(' || char === '[') depth += 1;
				else if (char === ')' || char === ']') depth = Math.max(0, depth - 1);
				else if (char === ',' && depth === 0) { parts.push(selector.slice(start, index).trim()); start = index + 1; }
			}
			parts.push(selector.slice(start).trim());
			return parts.filter(Boolean);
		};
		const selectorSpecificity = (element, selector) => {
			const score = (part) => {
				let text = part.replace(/:where\((?:[^()]|\([^()]*\))*\)/g, '');
				const ids = (text.match(/#[a-zA-Z0-9_-]+/g) || []).length;
				const classes = (text.match(/\.[a-zA-Z0-9_-]+|\[[^\]]+\]|:(?!:)[a-zA-Z0-9_-]+(?:\([^)]*\))?/g) || []).length;
				text = text.replace(/#[a-zA-Z0-9_-]+|\.[a-zA-Z0-9_-]+|\[[^\]]+\]|::?[a-zA-Z0-9_-]+(?:\([^)]*\))?|\*/g, ' ');
				const types = (text.match(/(?:^|[\s>+~|])([a-zA-Z][a-zA-Z0-9_-]*)/g) || []).length + (part.match(/::[a-zA-Z0-9_-]+/g) || []).length;
				return [ids, classes, types];
			};
			const matches = selectorParts(selector).filter((part) => {
				try { return element.matches(part); } catch { return false; }
			}).map(score);
			return matches.sort((left, right) => right[0] - left[0] || right[1] - left[1] || right[2] - left[2])[0] || [0, 0, 0];
		};
		const all = [document.body, ...document.body.querySelectorAll('*')].filter((element) => !excluded.has(element.localName) && !element.closest('script,style,noscript,template'));
		const capturedElements = new Set(all);
		const matchedRuleIndex = new Map(all.map((element) => [element, []]));
		let sourceOrder = 0;
		const indexRules = (rules, source, media = '') => {
			for (const rule of [...rules]) {
				sourceOrder += 1;
				if (rule.type === CSSRule.STYLE_RULE) {
					try {
						const ruleDeclarations = declarations(rule.style);
						for (const element of document.querySelectorAll(rule.selectorText)) {
							if (!capturedElements.has(element)) continue;
							const matches = matchedRuleIndex.get(element);
							if (matches.length >= 80) continue;
							matches.push({ selector: rule.selectorText, source, media, specificity: selectorSpecificity(element, rule.selectorText), order: sourceOrder, declarations: ruleDeclarations });
						}
					} catch { /* unsupported selector evidence is represented by computed style */ }
				} else if (rule.cssRules && (!rule.conditionText || matchMedia(rule.conditionText).matches)) {
					indexRules(rule.cssRules, source, rule.conditionText || media);
				}
			}
		};
		if (!proofMode) for (const sheet of [...document.styleSheets]) {
			try { indexRules(sheet.cssRules, sheet.href || 'inline', sheet.media?.mediaText || ''); } catch { /* cross-origin stylesheet */ }
		}
		const matchingRules = (element) => {
			if (proofMode) return [];
			const matches = [...(matchedRuleIndex.get(element) || [])];
			if (element.style?.length) matches.push({ selector: '<inline-style>', source: 'inline-attribute', media: '', specificity: [1, 0, 0], order: Number.MAX_SAFE_INTEGER, declarations: declarations(element.style) });
			return matches;
		};
		const pseudo = (element, name) => {
			const style = getComputedStyle(element, name);
			if (!style || ['none', 'normal', ''].includes(style.content)) return null;
			return { content: style.content, display: style.display, position: style.position, color: style.color, background: style.background, font: style.font, width: style.width, height: style.height };
		};
		const ids = new Map(all.map((element) => [element, `node-${simpleHash(domPath(element))}`]));
		const nodes = all.map((element) => {
			const style = getComputedStyle(element);
			const rect = element.getBoundingClientRect();
			const computed = Object.fromEntries(computedProperties.map((property) => [property, style.getPropertyValue(property).trim()]));
			const customProperties = {};
			if (!proofMode) for (const property of style) if (property.startsWith('--')) customProperties[property] = style.getPropertyValue(property).trim();
			const visible = style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) !== 0 && rect.width > 0 && rect.height > 0;
			const createsContext = style.position === 'fixed' || style.position === 'sticky' || (style.position !== 'static' && style.zIndex !== 'auto') || style.opacity !== '1' || style.transform !== 'none' || style.filter !== 'none' || style.isolation === 'isolate';
			return {
				id: ids.get(element), parentId: ids.get(element.parentElement) || null, tag: element.localName,
				role: semanticRole(element), accessibleName: accessibleName(element), text: directText(element),
				attributes: Object.fromEntries([...element.attributes].map((attribute) => [attribute.name, attribute.value])),
				observation: {
					viewportId: activeViewport, visible,
					box: { x: rect.x + scrollX, y: rect.y + scrollY, width: rect.width, height: rect.height }, computed, customProperties,
					scroll: { width: element.scrollWidth, height: element.scrollHeight, left: element.scrollLeft, top: element.scrollTop },
					stacking: { position: style.position, zIndex: style.zIndex, createsContext },
					pseudo: proofMode ? { before: null, after: null } : { before: pseudo(element, '::before'), after: pseudo(element, '::after') },
					matchedRules: matchingRules(element)
				},
				provenance: { selector: element.id ? `#${CSS.escape(element.id)}` : domPath(element), domPath: domPath(element) }
			};
		});
		const stylesheets = [...document.styleSheets].map((sheet, index) => {
			let ruleCount = 0;
			let accessible = true;
			try { ruleCount = sheet.cssRules.length; } catch { accessible = false; }
			return { id: `stylesheet-${index + 1}`, href: sheet.href || null, inline: !sheet.href, media: sheet.media?.mediaText || '', disabled: sheet.disabled, ruleCount, accessible };
		});
		const assetReferences = [];
		for (const element of all) {
			for (const name of ['src', 'poster', 'href']) {
				const value = element.getAttribute(name);
				if (value && (/^data:/i.test(value) || ['img', 'video', 'audio', 'source', 'link', 'script'].includes(element.localName))) assetReferences.push({ url: value, kind: element.localName === 'link' ? 'stylesheet' : element.localName });
			}
		}
		return { nodes, stylesheets, assetReferences };
	}, { viewportId, computedProperties: COMPUTED_PROPERTIES, proofMode: options.proofMode === true });
}

async function capturePage(options) {
	const input = options.input;
	if (!input) throw new Error('capturePage requires an input HTML file, bundle entry, or public URL.');
	const outputDirectory = path.resolve(options.outputDirectory || path.join(process.cwd(), 'seam-capture-output'));
	const viewports = (options.viewports?.length ? options.viewports : CANONICAL_VIEWPORTS).map((viewport) => ({ ...viewport }));
	const scriptsExecuted = options.scriptsExecuted !== false;
	const remoteAssetsAllowed = options.allowRemoteAssets === true;
	const deterministicClock = options.deterministicClock !== false;
	const publicInput = isHttp(input);
	let server = null;
	let entryUrl;
	let sourceKind;
	let sourceHash = null;
	let sourceEntry;
	if (publicInput) {
		const parsed = new URL(input);
		if (isPrivateHostname(parsed.hostname)) throw new Error('Private-network URL capture is disabled. Capture a local bundle path instead.');
		entryUrl = parsed.href;
		sourceEntry = parsed.href;
		sourceKind = 'url';
	} else {
		const entryFile = path.resolve(input);
		if (!fs.existsSync(entryFile) || !fs.statSync(entryFile).isFile()) throw new Error(`Capture input file does not exist: ${entryFile}`);
		const bundleRoot = path.resolve(options.bundleRoot || path.dirname(entryFile));
		server = await startLocalServer(bundleRoot, entryFile);
		entryUrl = server.url;
		sourceEntry = path.relative(bundleRoot, entryFile).replace(/\\/g, '/');
		sourceKind = path.extname(entryFile).toLowerCase() === '.html' || path.extname(entryFile).toLowerCase() === '.htm' ? 'html' : 'bundle';
		sourceHash = `sha256:${sha256(fs.readFileSync(entryFile))}`;
	}
	fs.mkdirSync(path.join(outputDirectory, 'screenshots'), { recursive: true });
	const browser = await chromium.launch({ executablePath: findBrowser(options.browserPath), headless: true, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
	const diagnostics = [];
	const nodes = new Map();
	const resources = new Map();
	let stylesheetEvidence = [];
	let engineVersion = browser.version();
	try {
		for (const viewport of viewports) {
			const context = await browser.newContext({
				viewport: { width: viewport.width, height: viewport.height }, colorScheme: viewport.theme === 'dark' ? 'dark' : 'light',
				serviceWorkers: 'block', javaScriptEnabled: scriptsExecuted, reducedMotion: 'reduce', locale: 'en-US', timezoneId: 'UTC'
			});
			if (deterministicClock && scriptsExecuted) await context.addInitScript(() => {
				const fixed = 1_735_689_600_000;
				Date.now = () => fixed;
				Math.random = () => 0.5;
			});
			const page = await context.newPage();
			const pendingResponses = new Set();
			page.on('console', (message) => {
				if (message.type() === 'error' || message.type() === 'warning') diagnostics.push(`${viewport.id} console ${message.type()}: ${message.text()}`);
			});
			page.on('pageerror', (error) => diagnostics.push(`${viewport.id} page error: ${error.message}`));
			await page.route('**/*', async (route) => {
				const request = route.request();
				const url = new URL(request.url());
				const allowedProtocol = ['data:', 'blob:', 'about:'].includes(url.protocol);
				const localAllowed = server && url.origin === server.origin;
				const publicSameOrigin = publicInput && url.origin === new URL(entryUrl).origin && !isPrivateHostname(url.hostname);
				const publicRemote = remoteAssetsAllowed && ['http:', 'https:'].includes(url.protocol) && !isPrivateHostname(url.hostname);
				if (allowedProtocol || localAllowed || publicSameOrigin || publicRemote) return route.continue();
				const canonicalUrl = canonicalResourceUrl(request.url(), server);
				resources.set(canonicalUrl, { url: canonicalUrl, kind: request.resourceType(), status: null, mime: null, bytes: null, sha256: null, blocked: true });
				diagnostics.push(`${viewport.id} blocked ${request.resourceType()}: ${request.url()}`);
				return route.abort('blockedbyclient');
			});
			page.on('response', (response) => {
				const evidencePromise = (async () => {
					try {
						const request = response.request();
						const body = await response.body();
						const mime = response.headers()['content-type']?.split(';')[0] || null;
						const canonicalUrl = canonicalResourceUrl(response.url(), server);
						const resource = { url: canonicalUrl, kind: request.resourceType(), status: response.status(), mime, bytes: body.length, sha256: sha256(body), blocked: false };
						if ((request.resourceType() === 'stylesheet' || mime === 'text/css') && body.length <= 1_000_000) resource.content = body.toString('utf8');
						resources.set(canonicalUrl, resource);
					} catch (error) {
						diagnostics.push(`${viewport.id} response evidence unavailable: ${canonicalResourceUrl(response.url(), server)} (${error.message})`);
					}
				})();
				pendingResponses.add(evidencePromise);
				evidencePromise.finally(() => pendingResponses.delete(evidencePromise));
			});
			const response = await page.goto(entryUrl, { waitUntil: 'load', timeout: options.navigationTimeout || 30_000 });
			if (!response) throw new Error(`Navigation produced no response for ${entryUrl}`);
			if (!sourceHash && viewport === viewports[0]) sourceHash = `sha256:${sha256(await response.body())}`;
			const settle = await page.evaluate(async ({ timeoutMs }) => {
				const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
				const lazyImages = [...document.images].map((image) => ({ image, loading: image.getAttribute('loading') }));
				const scrollBehavior = {
					html: document.documentElement.style.getPropertyValue('scroll-behavior'),
					htmlPriority: document.documentElement.style.getPropertyPriority('scroll-behavior'),
					body: document.body.style.getPropertyValue('scroll-behavior'),
					bodyPriority: document.body.style.getPropertyPriority('scroll-behavior')
				};
				document.documentElement.style.setProperty('scroll-behavior', 'auto', 'important');
				document.body.style.setProperty('scroll-behavior', 'auto', 'important');
				for (const item of lazyImages) item.image.loading = 'eager';
				const maximum = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
				for (let y = 0; y < maximum; y += Math.max(320, innerHeight)) {
					scrollTo(0, y);
					await delay(75);
				}
				scrollTo(0, 0);
				await delay(150);
				const imagesReady = Promise.all([...document.images].map((image) => image.complete ? image.decode?.().catch(() => {}) : new Promise((resolve) => {
					image.addEventListener('load', resolve, { once: true });
					image.addEventListener('error', resolve, { once: true });
				})));
				let timedOut = false;
				await Promise.race([
					Promise.all([document.fonts?.ready?.catch(() => {}) || Promise.resolve(), imagesReady]),
					delay(timeoutMs).then(() => { timedOut = true; })
				]);
				for (const item of lazyImages) {
					if (item.loading === null) item.image.removeAttribute('loading');
					else item.image.setAttribute('loading', item.loading);
				}
				if (scrollBehavior.html) document.documentElement.style.setProperty('scroll-behavior', scrollBehavior.html, scrollBehavior.htmlPriority);
				else document.documentElement.style.removeProperty('scroll-behavior');
				if (scrollBehavior.body) document.body.style.setProperty('scroll-behavior', scrollBehavior.body, scrollBehavior.bodyPriority);
				else document.body.style.removeProperty('scroll-behavior');
				return { timedOut, pendingImages: [...document.images].filter((image) => !image.complete).length };
			}, { timeoutMs: options.assetSettleTimeout || 10_000 });
			if (settle.timedOut) diagnostics.push(`${viewport.id} asset settle timeout: ${settle.pendingImages} image(s) remained pending.`);
			const evidence = await collectPageEvidence(page, viewport.id, { proofMode: options.proofMode === true });
			for (const captured of evidence.nodes) {
				const existing = nodes.get(captured.id);
				if (existing) existing.observations.push(captured.observation);
				else {
					const { observation, ...identity } = captured;
					nodes.set(captured.id, { ...identity, observations: [observation] });
				}
			}
			if (!stylesheetEvidence.length) stylesheetEvidence = evidence.stylesheets.map((sheet) => ({
				...sheet, href: sheet.href ? canonicalResourceUrl(sheet.href, server) : null
			}));
			for (const captured of evidence.nodes) for (const observation of captured.observations || [captured.observation]) {
				for (const rule of observation.matchedRules || []) rule.source = canonicalResourceUrl(rule.source, server);
			}
			for (const reference of evidence.assetReferences) {
				if (resources.has(reference.url)) continue;
				const data = dataResource(reference.url, reference.kind);
				if (data) resources.set(reference.url, data);
			}
			const screenshotFile = `screenshots/${viewport.id}.png`;
			const screenshot = await page.screenshot({ fullPage: true, animations: 'disabled' });
			fs.writeFileSync(path.join(outputDirectory, screenshotFile), screenshot);
			viewport.screenshot = { file: screenshotFile, bytes: screenshot.length, sha256: sha256(screenshot) };
			await Promise.allSettled([...pendingResponses]);
			await context.close();
		}
	} finally {
		await browser.close();
		if (server) await server.close();
	}
	const capture = {
		schema: 'seam.capture.v1', source: { kind: sourceKind, entry: sourceEntry, contentHash: sourceHash },
		viewports, nodes: [...nodes.values()], diagnostics: [...new Set(diagnostics)],
		capture: { engine: 'chromium', engineVersion, scriptsExecuted, remoteAssetsAllowed, deterministicClock },
		stylesheets: stylesheetEvidence.map((sheet) => ({ ...sheet, contentHash: sheet.href && resources.get(sheet.href)?.sha256 ? `sha256:${resources.get(sheet.href).sha256}` : null })),
		resources: [...resources.values()]
	};
	validateCapture(capture);
	return capture;
}

module.exports = { CANONICAL_VIEWPORTS, COMPUTED_PROPERTIES, canonicalResourceUrl, capturePage, isPrivateHostname, validateCapture };
