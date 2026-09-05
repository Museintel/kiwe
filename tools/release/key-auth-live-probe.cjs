const path = require('path');

function loadPlaywright() {
	try {
		return require('playwright');
	} catch (error) {
		return require(path.join(process.env.USERPROFILE || '', '.cache', 'codex-runtimes', 'codex-primary-runtime', 'dependencies', 'node', 'node_modules', 'playwright'));
	}
}

const target = process.argv[2] || 'https://ascendants.in/wp-login.php?action=lostpassword';
const chrome = process.env.KIWE_CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const viewports = [
	{ name: 'desktop', width: 1280, height: 800, isMobile: false },
	{ name: 'mobile', width: 390, height: 844, isMobile: true },
];

(async () => {
	const { chromium } = loadPlaywright();
	const browser = await chromium.launch({ headless: true, executablePath: chrome });
	const results = [];

	for (const viewport of viewports) {
		const context = await browser.newContext({
			viewport: { width: viewport.width, height: viewport.height },
			isMobile: viewport.isMobile,
			hasTouch: viewport.isMobile,
			userAgent: viewport.isMobile
				? 'Mozilla/5.0 (Linux; Android 15; Pixel 9 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36'
				: undefined,
		});
		const page = await context.newPage();
		const errors = [];
		page.on('pageerror', (error) => errors.push(String(error)));

		const url = new URL(target);
		url.searchParams.set('kiwe-auth-proof', `${Date.now()}-${viewport.name}`);
		await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.waitForTimeout(500);

		const result = await page.evaluate(() => {
			const form = document.querySelector('#lostpasswordform, #resetpassform, #loginform');
			const logo = document.querySelector('#login h1 a');
			const formRect = form?.getBoundingClientRect();
			const logoStyle = logo ? getComputedStyle(logo) : null;
			return {
				bodyClass: document.body.classList.contains('kiwe-key-auth'),
				keyBrand: /Key\.kiwe/i.test(document.body.innerText),
				customLogo: Boolean(logoStyle && logoStyle.backgroundImage && logoStyle.backgroundImage !== 'none'),
				formInViewport: Boolean(formRect
					&& formRect.left >= 0
					&& formRect.right <= innerWidth
					&& formRect.top >= 0
					&& formRect.bottom <= innerHeight),
				adScripts: document.querySelectorAll('script[src*="adsense"], script[src*="doubleclick"], ins.adsbygoogle').length,
			};
		});

		results.push({ name: viewport.name, ...result, pageErrors: errors.length });
		await context.close();
	}

	await browser.close();
	const passed = results.every((result) => result.bodyClass
		&& result.keyBrand
		&& result.customLogo
		&& result.formInViewport
		&& result.adScripts === 0
		&& result.pageErrors === 0);
	process.stdout.write(`${JSON.stringify({ passed, results })}\n`);
	if (!passed) process.exitCode = 1;
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
