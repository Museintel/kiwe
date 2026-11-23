const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.KIWE_PLAYWRIGHT_PATH || 'playwright');

const assets = path.resolve(__dirname, '../../wp-content/mu-plugins/dsa/assets/js');
const early = fs.readFileSync(path.join(assets, 'early-launcher.js'), 'utf8');
const surface = fs.readFileSync(path.join(assets, 'surface.js'), 'utf8');
const fixture = '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1">' +
  '<script>' + early + '</script>' +
  '<nav data-dsa-surface><button id="search" type="button" data-dsa-dock-item data-dsa-module-mode="dock" data-dsa-module="search"><span>Search</span></button>' +
  '<button id="cart" type="button" data-dsa-dock-item data-dsa-module-mode="dock" data-dsa-module="cart">Cart</button></nav>' +
  '<a id="header" href="#" data-dsa-open-module="search">Header Search</a> ' +
  '<a id="fallback" href="#destination" data-dsa-open-module="search">Native Destination</a>' +
  '<p id="destination">Native destination</p>';

async function start(page) {
  // Model the real startup order with a user click before the deferred
  // listener exists, then install a normal listener and run the handoff.
  return page.evaluate(() => {
    const opened = [];
    document.addEventListener('click', event => {
      const trigger = event.target.closest('[data-dsa-open-module], [data-dsa-dock-item]');
      if (trigger) { event.preventDefault(); opened.push(trigger.id); }
    });
    window.DSA.earlyLauncher(true);
    return opened;
  });
}

async function main() {
  assert.match(surface, /bindFormMessageAccessibility\(\);\s*if \( window\.DSA\.earlyLauncher \) window\.DSA\.earlyLauncher\( Boolean\( overlayRoot \) \)/);
  const browser = await chromium.launch({ executablePath: process.env.KIWE_CHROME_PATH });
  const context = await browser.newContext({ isMobile: true, hasTouch: true, viewport: { width: 390, height: 844 } });
  try {
    const single = await context.newPage();
    await single.setContent(fixture);
    await single.locator('#search span').tap();
    assert.equal(await single.locator('#search').getAttribute('data-dsa-launch-pending'), '');
    assert.deepEqual(await start(single), ['search']);
    assert.equal(await single.locator('#search').getAttribute('data-dsa-launch-pending'), null);
    assert.equal(await single.evaluate(() => window.DSA.earlyLauncher), undefined);

    const changed = await context.newPage();
    await changed.setContent(fixture);
    await changed.locator('#search').tap();
    await changed.locator('#header').press('Enter');
    assert.equal(await changed.locator('#search').getAttribute('data-dsa-launch-pending'), null);
    assert.deepEqual(await start(changed), ['header']);

    const detached = await context.newPage();
    await detached.setContent(fixture);
    await detached.locator('#cart').tap();
    await detached.locator('#cart').evaluate(control => { control.hidden = true; });
    assert.deepEqual(await start(detached), []);

    const native = await context.newPage();
    await native.setContent(fixture);
    await native.locator('#fallback').tap();
    assert.equal(await native.evaluate(() => location.hash), '#destination');
    assert.equal(await native.locator('#fallback').getAttribute('data-dsa-launch-pending'), null);
    await native.locator('#search').tap();
    await native.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    assert.deepEqual(await start(native), []);
    console.log(JSON.stringify({ passed: true, checks: 11, scope: 'Mobile early intent, keyboard activation, current visibility, native fallback and navigation cleanup.' }));
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
