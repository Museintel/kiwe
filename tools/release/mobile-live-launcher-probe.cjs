const fs = require('fs');
const path = require('path');

function loadPlaywright() {
	try {
		return require('playwright');
	} catch (error) {
		return require(path.join(process.env.USERPROFILE || '', '.cache', 'codex-runtimes', 'codex-primary-runtime', 'dependencies', 'node', 'node_modules', 'playwright'));
	}
}

const target = process.argv[2] || 'https://ascendants.in/';
const chrome = process.env.KIWE_CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

(async () => {
	const { chromium } = loadPlaywright();
	const browser = await chromium.launch({ headless: true, executablePath: chrome });
	const context = await browser.newContext({
		viewport: { width: 390, height: 844 },
		isMobile: true,
		hasTouch: true,
		userAgent: 'Mozilla/5.0 (Linux; Android 15; Pixel 9 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36',
	});
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', (error) => errors.push(String(error)));

	const url = new URL(target);
	url.searchParams.set('kiwe-mobile-proof', String(Date.now()));
	await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 60000 });
	await page.waitForTimeout(2500);

	const result = {
		viewport: await page.evaluate(() => ({ width: innerWidth, height: innerHeight })),
		surface: await page.locator('[data-dsa-surface]').count(),
		runtime: await page.locator('script[src*="/dsa/assets/js/surface.js"]').count(),
		launchers: {},
		errors,
	};

	for (const id of ['search', 'profile', 'menu']) {
		const launcher = page.locator(`[data-dsa-open-module="${id}"]`).first();
		const record = { count: await launcher.count(), visible: false, opened: false, panel: '' };
		if (record.count) {
			record.visible = await launcher.isVisible();
			await launcher.click({ timeout: 10000 });
			await page.waitForTimeout(500);
			const dialog = page.locator('[data-dsa-overlay-root]:not([hidden]) [role="dialog"]').first();
			record.opened = (await dialog.count()) > 0;
			if (record.opened) record.panel = (await dialog.getAttribute('data-dsa-screen-name')) || '';
			await page.keyboard.press('Escape');
			await page.waitForTimeout(300);
		}
		result.launchers[id] = record;
	}

	await browser.close();
	process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
	const passed = result.surface === 1
		&& result.runtime === 1
		&& ['search', 'profile', 'menu'].every((id) => result.launchers[id]?.visible && result.launchers[id]?.opened);
	if (!passed) process.exitCode = 1;
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
