const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.KIWE_PLAYWRIGHT_PATH || 'playwright');

const assets = path.resolve(__dirname, '../../wp-content/mu-plugins/dsa/assets');
const script = fs.readFileSync(path.join(assets, 'js/dock-reveal.js'), 'utf8');
const viewportScript = fs.readFileSync(path.join(assets, 'js/dock-viewport.js'), 'utf8');
const css = fs.readFileSync(path.join(assets, 'css/surface.css'), 'utf8');
const framework = fs.readFileSync(path.join(assets, 'css/seam.css'), 'utf8');
const cluster = '<div class="dsa-dock-cluster" data-dsa-dock-cluster><nav class="dsa-dock"><button type="button">Menu</button></nav></div>';
const dock = '[data-dsa-dock-cluster]';
const marker = 'data-dsa-dock-reveal';

async function showFixture(page, { enabled = true, height = 1600, footerReady = true, sections = '' } = {}) {
  const head = '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + framework + css +
    'body{margin:0}#nested{height:60px;overflow:auto}#nested div{height:140px}</style>' +
    '<script>window.reveals=0; document.addEventListener("animationstart",e=>{if(e.animationName==="dsa-dock-first-scroll") reveals++});</script>';
  const body = '<header' + (sections ? ' style="position:absolute"' : '') + '><button data-dsa-open-module="search" onclick="this.dataset.opened=1">Search</button></header>' +
    '<main style="min-height:' + height + 'px">' + (sections || '<div id="nested"><div>Scrollable content</div></div>') + '</main>' +
    '<div id="dsa-surface" class="dsa-surface">' +
    (footerReady ? cluster.replace('<nav', '<script>' + viewportScript +
      ';window.hiddenBeforeDock = document.documentElement.hasAttribute("data-dsa-dock-viewport-hidden");</script><nav') : '') + '</div>';
  await page.setContent(head + (enabled ? '<script>' + script + '</script>' : '') + body);
}

async function test() {
  const browser = await chromium.launch({ executablePath: process.env.KIWE_CHROME_PATH });
  const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  let checks = 0;

  async function checkState(page, state) {
    assert.equal(await page.locator('html').getAttribute(marker), state);
    checks++;
  }

  async function checkDock(page, visible) {
    assert.equal(await page.locator(dock).isVisible(), visible);
    checks++;
  }

  try {
    const page = await desktop.newPage();
    await showFixture(page);
    await checkState(page, 'waiting');
    await checkDock(page, false);
    await page.getByRole('button', { name: 'Search' }).click();
    assert.equal(await page.getByRole('button', { name: 'Search' }).getAttribute('data-opened'), '1');
    checks++;
    await page.locator('#nested').evaluate(el => { el.scrollTop = 30; });
    await checkState(page, 'waiting');
    await page.mouse.move(300, 400);
    await page.mouse.wheel(0, 190);
    await page.waitForFunction(() => scrollY > 0 && reveals === 1 && !document.documentElement.hasAttribute('data-dsa-dock-reveal'));
    await checkDock(page, true);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForFunction(() => scrollY === 0);
    await checkDock(page, true);
    assert.equal(await page.evaluate(() => reveals), 1);
    checks++;

    const delayed = await mobile.newPage();
    await showFixture(delayed, { footerReady: false });
    await delayed.evaluate(() => window.scrollTo(0, 180));
    await delayed.waitForFunction(() => document.documentElement.dataset.dsaDockReveal === 'revealing');
    await delayed.locator('#dsa-surface').evaluate((el, html) => el.insertAdjacentHTML('afterbegin', html), cluster);
    await delayed.waitForFunction(() => reveals === 1 && !document.documentElement.hasAttribute('data-dsa-dock-reveal'));
    await checkDock(delayed, true);

    const reduced = await desktop.newPage();
    await reduced.emulateMedia({ reducedMotion: 'reduce' });
    await showFixture(reduced);
    await checkDock(reduced, false);
    await reduced.keyboard.press('PageDown');
    await reduced.waitForFunction(() => scrollY > 0 && !document.documentElement.hasAttribute('data-dsa-dock-reveal'));
    await checkDock(reduced, true);
    assert.equal(await reduced.evaluate(() => reveals), 0);
    checks++;

    const short = await mobile.newPage();
    await showFixture(short, { height: 70 });
    await checkState(short, null);
    await checkDock(short, true);
    const ordinary = await desktop.newPage();
    await showFixture(ordinary, { enabled: false });
    await checkState(ordinary, null);
    await checkDock(ordinary, true);

    const cover = await mobile.newPage();
    await showFixture(cover, { sections: '<section id="hero" data-dsa-hide-dock="cover" style="height:110vh"></section><section style="height:850px"></section>' });
    assert.equal(await cover.evaluate(() => hiddenBeforeDock), true);
    checks++;
    await checkDock(cover, false);
    await cover.evaluate(() => scrollTo(0, 26));
    await cover.waitForFunction(() => scrollY >= 26);
    await checkDock(cover, false);
    await cover.evaluate(() => scrollTo(0, 160));
    await cover.locator(dock).waitFor({ state: 'visible' });
    assert.ok(await cover.locator('#hero').evaluate(element => element.getBoundingClientRect().bottom > 0));
    checks++;
    await cover.evaluate(() => scrollTo(0, 0));
    await cover.locator(dock).waitFor({ state: 'hidden' });
    await checkDock(cover, false);

    const visibleSection = await desktop.newPage();
    await showFixture(visibleSection, { enabled: false, sections: '<section id="first" data-dsa-hide-dock style="height:300px"></section><section style="height:1000px"></section><section id="later" style="height:400px"></section>' });
    assert.equal(await visibleSection.evaluate(() => hiddenBeforeDock), true);
    checks++;
    await checkDock(visibleSection, false);
    await visibleSection.evaluate(() => scrollTo(0, 150));
    await visibleSection.waitForFunction(() => scrollY === 150);
    await checkDock(visibleSection, false);
    await visibleSection.evaluate(() => scrollTo(0, 410));
    await visibleSection.locator(dock).waitFor({ state: 'visible' });
    await checkDock(visibleSection, true);
    await visibleSection.locator('#later').evaluate(element => element.setAttribute('data-dsa-hide-dock', ''));
    await visibleSection.evaluate(() => scrollTo(0, 710));
    await visibleSection.locator(dock).waitFor({ state: 'hidden' });
    await checkDock(visibleSection, false);
    await visibleSection.locator('#later').evaluate(element => element.removeAttribute('data-dsa-hide-dock'));
    await visibleSection.locator(dock).waitFor({ state: 'visible' });
    await checkDock(visibleSection, true);

    console.log(JSON.stringify({ passed: true, checks, scope: 'First scroll, pre-paint viewport hiding, covered heroes, dynamic markup, independent header actions, and reduced motion.' }));
  } finally {
    await browser.close();
  }
}

test().catch(error => { console.error(error); process.exitCode = 1; });
