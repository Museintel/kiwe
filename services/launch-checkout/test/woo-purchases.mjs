import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { clientPlan, launchCatalogue } from '../../start-projects/src/client-plan.mjs';
import { startForCheckout } from './billing-proof.mjs';

const fixture = await mkdtemp(path.join(tmpdir(), 'kiwe-woo-purchases-'));
const start = await startForCheckout('http://127.0.0.1:8250');
const php = process.env.KIWE_LAUNCH_PHP ?? 'php';
const args = process.env.KIWE_LAUNCH_PHP_CONFIG ? ['-c', process.env.KIWE_LAUNCH_PHP_CONFIG] : [];
const run = promisify(execFile);
try {
  await Promise.all([
    writeFile(path.join(fixture, 'client-plan.json'), JSON.stringify(clientPlan)),
    writeFile(path.join(fixture, 'launch-catalogue.json'), JSON.stringify(launchCatalogue)),
  ]);
  const harness = fileURLToPath(new URL('./woo-purchases.php', import.meta.url));
  const { stdout } = await run(php, [...args, harness, fixture, start.origin], {
    timeout: 25000, env: { ...process.env, KIWE_TEST_START_TOKEN: start.purchaseToken },
  });
  const receipt = JSON.parse(stdout);
  assert.equal(receipt.projects.length, 2);
  const projects = start.projects.listProjects(receipt.buyer);
  assert.equal(projects.length, 2);
  assert.deepEqual(projects.map(project => project.id).sort(), receipt.projects.sort());
  assert.deepEqual(projects.map(project => project.planId).sort(), ['kiwe-ecommerce-v1', 'kiwe-portfolio-v1']);
  assert.equal(projects.find(project => project.planId === 'kiwe-portfolio-v1').setup.mode, 'services');
  console.log('Native WooCommerce billing proof passed for two Start projects, isolated renewals and refunds, access and retries.');
} finally {
  await start.close();
  await rm(fixture, { recursive: true, force: true });
}
