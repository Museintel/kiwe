const fs = require('fs');
const path = require('path');

function loadPlaywright() {
  try {
    return require('playwright');
  } catch (error) {
    return require(path.join(process.env.USERPROFILE || '', '.cache', 'codex-runtimes', 'codex-primary-runtime', 'dependencies', 'node', 'node_modules', 'playwright'));
  }
}

const root = path.resolve(__dirname, '..', '..');
const css = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/css/workspace-shell.css'), 'utf8');
const chrome = process.env.KIWE_CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

(async () => {
  const { chromium } = loadPlaywright();
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  try {
    for (const width of [320, 390, 768]) {
      const page = await browser.newPage({ viewport: { width, height: 844 }, isMobile: width < 768, hasTouch: width < 768 });
      await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="kiwe-client-shell"><main id="kiwe-shell-main"><div class="kiwe-shell-tabs" role="tablist" aria-label="Workspace tasks"><button role="tab" aria-selected="true">Work</button><button role="tab" aria-selected="false">Daily</button><button role="tab" aria-selected="false">Setup</button><button role="tab" aria-selected="false">Organize</button><button role="tab" aria-selected="false">SEO</button><button role="tab" aria-selected="false">Analytics</button></div></main></body></html>');
      await page.addStyleTag({ content: css });
      const layout = await page.evaluate(() => {
        const tabs = [...document.querySelectorAll('.kiwe-shell-tabs button')].map((button) => {
          const rect = button.getBoundingClientRect();
          return { text: button.textContent, left: rect.left, right: rect.right, top: rect.top, height: rect.height };
        });
        return {
          viewport: window.innerWidth,
          scrollWidth: document.documentElement.scrollWidth,
          tabs,
          rows: new Set(tabs.map((tab) => Math.round(tab.top))).size,
        };
      });
      const fits = layout.scrollWidth <= layout.viewport
        && layout.tabs.length === 6
        && layout.tabs.every((tab) => tab.left >= 0 && tab.right <= layout.viewport && tab.height >= 44)
        && (width < 768 ? layout.rows > 1 : layout.rows === 1);
      if (!fits) throw Error(`Home tabs overflow or hide at ${width}px: ${JSON.stringify(layout)}`);
      process.stdout.write(`PASS Home tabs at ${width}px: ${layout.rows} row(s), scrollWidth ${layout.scrollWidth}px\n`);
      await page.close();
    }
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
