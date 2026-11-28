import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { cp, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { clientPlan, launchCatalogue } from '../../start-projects/src/client-plan.mjs';
import { startForCheckout } from './billing-proof.mjs';

const wp = path.resolve(process.env.KIWE_LAUNCH_WP_FIXTURE ?? '');
const lab = JSON.parse(await readFile(path.join(wp, '../lab.json'), 'utf8'));
const origin = 'http://127.0.0.1:' + lab.http;
const service = fileURLToPath(new URL('../', import.meta.url));
const root = await mkdtemp(path.join(tmpdir(), 'kiwe-launch-woo-native-'));
const start = await startForCheckout(origin);
const php = process.env.KIWE_LAUNCH_PHP ?? 'php';
const phpArgs = process.env.KIWE_LAUNCH_PHP_CONFIG ? ['-c', process.env.KIWE_LAUNCH_PHP_CONFIG] : [];
const token = [...randomBytes(8)].map(value => String.fromCharCode(65 + value % 26)).join('');
const catalogue = { ...launchCatalogue, websitePlans: launchCatalogue.websitePlans.map(plan => plan.id === 'kiwe-portfolio-v1'
  ? { ...plan, sku: 'KIWE-PROOF' + token + '-FIRST-12-MONTHS' } : plan) };

try {
  await mkdir(path.join(root, 'data/sessions'), { recursive: true });
  await Promise.all([
    ...['boot.php', 'registration.php', 'schema.sql', 'store.php', 'providers.php', 'billing.php',
      'billing-providers.php', 'billing-schema.sql', 'web.php', 'partners.php', 'partner-schema.sql']
      .map(name => cp(path.join(service, name), path.join(root, name))),
    ...['settings-fixture.php', 'billing-fixture.php'].map((name, index) =>
      cp(path.join(service, 'test', name), path.join(root, index ? name : 'settings.php'))),
    writeFile(path.join(root, 'data/rate-key'), randomBytes(32), { mode: 0o600 }),
    writeFile(path.join(root, 'client-plan.json'), JSON.stringify(clientPlan)),
    writeFile(path.join(root, 'launch-catalogue.json'), JSON.stringify(catalogue)),
  ]);
  const { stdout } = await promisify(execFile)(php, [...phpArgs, path.join(service, 'test/wordpress-native.php'), root, wp], {
    timeout: 60_000,
    env: { ...process.env, KIWE_CHECKOUT_TEST_ORIGIN: origin, KIWE_BILLING_TEST_START_ORIGIN: start.origin,
      KIWE_BILLING_TEST_START_TOKEN: start.purchaseToken },
  });
  const proof = JSON.parse(stdout);
  assert.equal(proof.passed, true);
  assert.equal(proof.cleanup, true);
  const projects = start.projects.listProjects(proof.buyer);
  assert.equal(projects.length, 2);
  assert.ok(projects.every(project => project.planId === 'kiwe-portfolio-v1'));
  const referred = start.projects.listProjects(proof.other);
  assert.equal(referred.length, 1);
  assert.equal(referred[0].planId, 'kiwe-portfolio-v1');
  console.log('Native WooCommerce proved direct, Pass and referred orders, account and domain checks, and tier-specific commissions.');
} finally {
  await start.close();
  await rm(root, { recursive: true, force: true });
}
