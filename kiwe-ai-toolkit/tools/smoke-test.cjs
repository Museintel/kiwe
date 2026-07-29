#!/usr/bin/env node

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const tmp = path.join(root, 'tmp');
fs.mkdirSync(tmp, { recursive: true });

function runNode(args, options = {}) {
  return execFileSync(process.execPath, args, {
    cwd: root,
    stdio: options.capture ? ['ignore', 'pipe', 'inherit'] : 'inherit',
    encoding: options.capture ? 'utf8' : undefined
  });
}

function captureNode(args, outputPath) {
  const output = runNode(args, { capture: true });
  fs.writeFileSync(path.join(root, outputPath), output);
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

[
  'bin/kiwe.js',
  'lib/kiwe-core.js',
  'lib/binding-validator.js',
  'lib/bricks-conversion-validator.js',
  'lib/accessibility-validator.js',
  'lib/apply-planner.js',
  'lib/framework-profile-validator.js',
  'lib/bricks-theme-style-validator.js',
  'tools/validate-bricks-theme-style.cjs',
  'mcp/index.js',
  'tools/smoke-test.cjs'
].forEach((file) => runNode(['--check', file]));

(async () => {
  const m = await import(pathToFileURL(path.join(root, 'lib/kiwe-core.js')).href);
  const entry = m.getStartEntrypoint();
  const manifest = m.getCommandManifest();
  const plan = m.planFlow({ artifactSummary: 'homepage-appsite-v3-main-only-preview.html raw html css js' });

  assert(entry.schema === 'kiwe.start.v1', 'entry schema mismatch');
  assert(entry.productName === 'SeamFlow', 'entry product mismatch');
  assert(entry.contractVersion === '6.68', 'entry contract mismatch');
  assert(entry.noCommandInteraction.firstResponseShape.at(-1) === 'Commands: use /list for the compact command list', 'first response /list hint must be last');
  assert(entry.flows.executionCommands['/execute /stepbystep'], 'missing /execute /stepbystep in entry');
  assert(entry.flows.executionCommands['/execute /fullflow'], 'missing /execute /fullflow in entry');
  assert(entry.flows.auditClosure, 'entry missing audit closure law');
  assert(entry.flows.auditClosure.byStartPoint['bricks-template-upload'].includes('/audit /bricksconversion'), 'entry missing Bricks closure audit');
  assert(entry.flows.auditClosure.byStartPoint['raw-html-css-js'].includes('/audit /accessibility'), 'entry missing raw flow accessibility closure');
  assert(JSON.stringify(entry).includes('Do not use prior Kiwe validation material'), 'missing current-run evidence boundary in entry');

  assert(manifest.schema === 'kiwe.command-manifest.v1', 'manifest schema mismatch');
  assert(manifest.productName === 'SeamFlow', 'manifest product mismatch');
  assert(manifest.entry.contractVersion === '6.68', 'manifest contract mismatch');
  assert(manifest.flowPlanner.mcp === 'kiwe_seamflow_plan', 'manifest MCP planner mismatch');
  assert(manifest.commands['/execute /stepbystep'], 'manifest missing /execute /stepbystep');
  assert(manifest.commands['/execute /fullflow'], 'manifest missing /execute /fullflow');
  assert(manifest.commands['/auditateachstep'], 'manifest missing /auditateachstep');
  assert(manifest.commands['/auditatend'], 'manifest missing /auditatend');
  assert(manifest.globalRules.auditClosure.includes('SeamFlow closes only when'), 'manifest missing audit closure rule');
  assert(manifest.commands['/fix /accessibility'].preserve.includes('Seam classes'), 'accessibility preservation contract missing Seam classes');

  assert(plan.schema === 'kiwe.seamflow-plan.v1', 'plan schema mismatch');
  assert(plan.productName === 'SeamFlow', 'plan product mismatch');
  assert(plan.contractVersion === '6.68', 'plan contract mismatch');
  assert(plan.startResponse.mustReport === 'SeamFlow contract: 6.68', 'plan contract report mismatch');
  assert(plan.startResponse.order.at(-1) === 'Commands: use /list for the compact command list', 'plan first-response order should put /list last');
  assert(plan.recommendedNextCommands.includes('/audit /accessibility'), 'plan missing accessibility audit');
  assert(plan.executionOptions.stepByStep === '/execute /stepbystep', 'plan missing step-by-step command');
  assert(plan.executionOptions.fullFlow === '/execute /fullflow', 'plan missing full-flow command');
  assert(plan.auditClosure.requiredAudits.includes('/audit /seamframework'), 'plan missing seam closure audit');
  assert(plan.auditClosure.requiredAudits.includes('/audit /bricksconversion'), 'plan missing bricks closure audit');
  assert(plan.auditClosure.requiredAudits.includes('/audit /accessibility'), 'plan missing accessibility closure audit');

  const bad = m.diagnoseCommand({ command: '/buid /preview /brickstheme' });
  const noop = m.diagnoseCommand({ command: '/create /preview /website', artifactSummary: 'website/bricks-paste.html exists' });
  const missingProfile = m.diagnoseCommand({ command: '/convert /bricks', artifactSummary: 'website/bricks-paste.html exists' });
  const ok = m.diagnoseCommand({ command: '/convert /bricks', artifactSummary: 'website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists' });
  const wrongVerb = m.diagnoseCommand({ command: '/create /bricks', artifactSummary: 'website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists' });
  const a11y = m.diagnoseCommand({ command: '/create /accessibility', artifactSummary: 'website/bricks-paste.html exists' });
  const missing = m.diagnoseCommand({ command: '/audit /accessibility' });
  const auditCadence = m.diagnoseCommand({ command: '/auditateachstep', artifactSummary: 'website/bricks-paste.html exists' });
  const executeMissing = m.diagnoseCommand({ command: '/execute /fullflow /auditateachstep' });
  const executeOk = m.diagnoseCommand({ command: '/execute /fullflow /auditateachstep', artifactSummary: 'homepage raw html css js' });

  assert(bad.stop && bad.code === 'unknown_command_token', 'bad typo diagnostic failed');
  assert(noop.stop && noop.status === 'noop', 'preview noop diagnostic failed');
  assert(missingProfile.stop && missingProfile.code === 'bricks_convert_missing_framework_profile', 'missing framework diagnostic failed');
  assert(!ok.stop, 'valid bricks conversion diagnostic stopped');
  assert(wrongVerb.stop && wrongVerb.code === 'bricks_convert_requires_convert_verb', 'wrong verb diagnostic failed');
  assert(!a11y.stop, 'accessibility create diagnostic stopped unexpectedly');
  assert(missing.stop && missing.code === 'accessibility_audit_missing_artifact', 'missing accessibility audit diagnostic failed');
  assert(auditCadence.stop && auditCadence.code === 'audit_cadence_requires_execute', 'audit cadence flag diagnostic failed');
  assert(executeMissing.stop && executeMissing.code === 'execute_missing_current_artifact', 'execute missing artifact diagnostic failed');
  assert(!executeOk.stop, 'execute with current artifact should not stop');

  captureNode(['bin/kiwe.js', 'entry'], 'tmp/entry-smoke.json');
  captureNode(['bin/kiwe.js', 'command-manifest'], 'tmp/command-manifest-smoke.json');
  captureNode(['bin/kiwe.js', 'seamflow', '--artifact-summary', 'homepage-appsite-v3-main-only-preview.html raw html css js'], 'tmp/seamflow-smoke.json');
  captureNode(['bin/kiwe.js', 'plan-flow', '--artifact-summary', 'native Bricks template upload JSON with title templateType header and seam classes'], 'tmp/flow-plan-smoke.json');
  captureNode(['bin/kiwe.js', 'workflow'], 'tmp/workflow-smoke.md');
  captureNode(['bin/kiwe.js', 'diagnose', '--command', '/create /preview /brickstheme'], 'tmp/diagnose-invalid-preview-smoke.json');
  captureNode(['bin/kiwe.js', 'route', '--command', '/rebuild /seamframework', '--brief', 'Smoke'], 'tmp/route-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /frameworkprofile', '--brief', 'Smoke'], 'tmp/route-framework-profile-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /brickstheme', '--brief', 'Smoke'], 'tmp/route-bricks-theme-style-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /preview /dsatheme', '--brief', 'Smoke'], 'tmp/route-theme-preview-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /preview /combined', '--brief', 'Smoke'], 'tmp/route-combined-preview-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/convert /bricks', '--brief', 'Smoke', '--artifact-summary', 'website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists'], 'tmp/route-bricks-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /accessibility', '--brief', 'Smoke', '--artifact-summary', 'website/bricks-paste.html exists'], 'tmp/route-accessibility-smoke.md');

  runNode(['tools/validate-output.cjs', '--help']);
  runNode(['tools/audit-output.cjs', '--help']);
  runNode(['tools/validate-bindings.cjs', '--help']);
  runNode(['tools/validate-bricks-conversion.cjs', '--help']);
  runNode(['tools/validate-accessibility.cjs', '--help']);
  runNode(['tools/prepare-apply-plan.cjs', '--help']);
  runNode(['tools/validate-framework-profile.cjs', '--help']);
  runNode(['tools/validate-bricks-theme-style.cjs', '--help']);
  runNode(['tools/validate-bindings.cjs', 'fixtures/bindings-valid', '--site-graph', 'fixtures/bindings-valid/site-graph.json']);
  runNode(['tools/validate-bricks-conversion.cjs', 'fixtures/bricks-conversion-valid', '--site-graph', 'fixtures/bricks-conversion-valid/site-graph.json']);
  runNode(['tools/validate-bricks-conversion.cjs', 'fixtures/bricks-template-valid']);
  runNode(['tools/validate-accessibility.cjs', 'fixtures/accessibility-valid']);

  const contrast = m.validateAccessibility('fixtures/accessibility-invalid-contrast');
  assert(!contrast.ok && JSON.stringify(contrast).includes('accessibility_low_contrast_literal_pair'), 'invalid contrast fixture did not fail');
  const overflow = m.validateAccessibility('fixtures/accessibility-invalid-overflow');
  assert(!overflow.ok && JSON.stringify(overflow).includes('accessibility_text_clipping_risk'), 'invalid overflow fixture did not fail');

  runNode(['tools/prepare-apply-plan.cjs', 'fixtures/bindings-valid', '--site-graph', 'fixtures/bindings-valid/site-graph.json']);
  runNode(['tools/validate-framework-profile.cjs', 'fixtures/framework-profile-valid']);
  runNode(['tools/validate-bricks-theme-style.cjs', 'fixtures/bricks-theme-style-valid']);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
