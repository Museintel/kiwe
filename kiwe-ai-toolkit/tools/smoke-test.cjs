#!/usr/bin/env node
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const expected = ['/ideate', '/convert /bricks', '/audit', '/accessibility', '/fix', '/redo'];
let checks = 0;

function assert(condition, message) {
  checks += 1;
  if (!condition) throw new Error(message);
}

function cli(...args) {
  return JSON.parse(execFileSync(process.execPath, ['bin/kiwe.js', ...args], { cwd: root, encoding: 'utf8' }));
}

for (const file of ['bin/kiwe.js', 'lib/kiwe-core.js', 'lib/binding-validator.js', 'lib/seam-map-validator.js', 'lib/bricks-conversion-validator.js', 'lib/accessibility-validator.js', 'lib/framework-profile-validator.js', 'lib/bricks-theme-style-validator.js', 'mcp/index.js']) {
  execFileSync(process.execPath, ['--check', file], { cwd: root, stdio: 'inherit' });
}

(async () => {
  const core = await import(pathToFileURL(path.join(root, 'lib/kiwe-core.js')).href);
  const entry = core.getStartEntrypoint();
  const manifest = core.getCommandManifest();

  assert(entry.schema === 'kiwe.entry.v2', 'entry schema');
  assert(entry.productName === 'SEAM', 'entry product');
  assert(entry.contractVersion === '9.0', 'entry version');
  assert(JSON.stringify(entry.commands) === JSON.stringify(expected), 'entry command surface');
  assert(manifest.schema === 'kiwe.command-manifest.v2', 'manifest schema');
  assert(manifest.aliases.length === 0, 'manifest aliases must be empty');
  assert(JSON.stringify(manifest.commands.map((item) => item.command)) === JSON.stringify(expected), 'manifest command surface');
  assert(!JSON.stringify(manifest).includes('/usesitegraph'), 'SiteGraph must be input, not command');
  assert(!JSON.stringify(manifest).includes('/seamframework'), 'Framework must be option, not command');
  const ideateContext = fs.readFileSync(path.join(root, 'contexts', 'ideate.md'), 'utf8');
  const ideateSchema = JSON.parse(fs.readFileSync(path.join(root, 'schemas', 'ideate-discovery.schema.json'), 'utf8'));
  assert(ideateContext.includes('production-content readiness preflight'), 'ideate production-content readiness rule');
  assert(ideateContext.includes('contentReadiness.runtimeSmokeTest'), 'ideate runtime smoke-test rule');
  assert(ideateContext.includes('kiwe.seam-map.v1') && ideateContext.includes('data-seam-anchor'), 'ideate strict semantic Seam handoff');
  assert(ideateContext.includes('Unmarked content has one deterministic meaning: preserve it as static source content.'), 'strict unmarked-content policy');
  assert(ideateSchema.properties.schema.const === 'kiwe.ideate-discovery.v5', 'ideate discovery schema version');
  assert(ideateSchema.required.includes('contentReadiness'), 'ideate discovery content readiness');
  const productionGate = ideateSchema.properties.contentReadiness.allOf.find(rule => rule.if.properties.status.const === 'production-ready').then.properties;
  assert(productionGate.runtimeSmokeTest.const === 'passed', 'production-ready requires completed runtime proof');
  assert(productionGate.basis.enum.join(',') === 'sitegraph-grounded,self-contained-approved-source', 'production-ready requires grounded evidence');

  for (const command of expected) {
    const input = { command, brief: 'approved brief and report evidence', artifactSummary: 'approved source.html and output.json', reportSummary: 'audit findings' };
    const result = core.routeCommand(input);
    assert(result.ok && result.command === command && result.context.length > 100, `${command} route`);
  }

  assert(core.diagnoseCommand({ command: '/usesitegraph', artifactSummary: 'x' }).status === 'rejected', 'reject removed command');
  assert(core.diagnoseCommand({ command: '/convert /bricks /seamframework', artifactSummary: 'x' }).status === 'rejected', 'reject command-option alias');
  assert(core.diagnoseCommand({ command: '/convert /bricks /dynamictags', artifactSummary: 'x' }).bindingMode === 'dynamicTagsOnly', 'dynamic-tags modifier');
  assert(core.diagnoseCommand({ command: '/convert /bricks /queryloop', artifactSummary: 'x' }).bindingMode === 'queryLoopsOnly', 'query-loop modifier');
  assert(core.diagnoseCommand({ command: '/convert /bricks /unknown', artifactSummary: 'x' }).status === 'rejected', 'reject unsupported modifier');
  assert(core.diagnoseCommand({ command: '/fix', artifactSummary: 'x' }).status === 'needs_input', 'fix requires findings');
  assert(core.diagnoseCommand({ command: '/redo', artifactSummary: 'x' }).status === 'needs_input', 'redo requires evidence');
  assert(core.planFlow({ artifactSummary: 'existing raw project index.html styles.css app.js', desiredOutcome: 'enhance the design' }).inferredCommand === '/ideate', 'raw project enhancement plan');
  const attachmentPlan = core.planFlow({ attachmentSummary: 'attached index.html, styles.css, app.js and images' });
  assert(attachmentPlan.inferredCommand === '/ideate' && attachmentPlan.execution === 'suggest-only' && attachmentPlan.waitsForUserCommand === true, 'attachment-only command suggestion');
  assert(core.planFlow({ artifactSummary: 'accepted raw project index.html styles.css app.js', desiredOutcome: 'prepare dynamic tags and query loops for Bricks' }).inferredCommand === '/convert /bricks', 'binding preparation plan');
  assert(core.planFlow({ artifactSummary: 'compiled Bricks template JSON' }).inferredCommand === '/audit', 'generated plan');
  assert(core.planFlow({ artifactSummary: 'audit report with findings' }).inferredCommand === '/fix', 'findings plan');
  assert(core.planFlow({ desiredOutcome: 'keyboard, overflow and dark mode review' }).inferredCommand === '/accessibility', 'accessibility plan');

  assert(cli('manifest').contractVersion === '9.0', 'CLI manifest');
  assert(cli('diagnose', '--command', '/audit', '--artifact-summary', 'template.json').status === 'ready', 'CLI diagnose');
  const conversionRoute = cli('route', '--command', '/convert /bricks', '--artifact-summary', 'source.html');
  assert(conversionRoute.options.bindingMode === 'dynamicTagsAndQueryLoops' && conversionRoute.options.emitsBricksJson === false, 'CLI binding preparation boundary');
  assert(conversionRoute.options.emitsUpdatedSource === false && conversionRoute.options.sourcePolicy === 'immutable', 'immutable source boundary');
  assert(conversionRoute.outputs.length === 2 && !conversionRoute.outputs.some(output => /raw project/.test(output)), 'binding graph and report only');
  const { validateImmutableBindingContract } = await import(pathToFileURL(path.join(root, 'lib/binding-validator.js')).href);
  const binding = { target: { sourcePolicy: 'immutable' }, queries: [], launchers: [], dynamicFields: [{ template: 'home.html', selector: '#title', slot: 'text', field: 'brand', tag: '{site_title}', textRange: { path: [0], expectedText: 'Welcome Example', match: 'Example' } }] };
  assert(validateImmutableBindingContract(binding).length === 0, 'guarded partial text binding');
  for (const mutation of [field => { delete field.template; }, field => { delete field.slot; }, field => { field.tag = '{echo:danger}'; }, field => { delete field.textRange; }, field => { field.textRange.path = [-1]; }]) {
    const invalid = structuredClone(binding); mutation(invalid.dynamicFields[0]);
    assert(validateImmutableBindingContract(invalid).some(f => f.level === 'fail'), 'invalid binding refused');
  }
  const unsafeLoop = { ...binding, queries: [{ template: 'home.html', selector: '.card', bindings: { title: '{post_title}' }, bricks: { queryEditor: 'return []' } }] };
  assert(validateImmutableBindingContract(unsafeLoop).length >= 3, 'unguarded executable loop rejected');
  assert(cli('plan', '--artifact-summary', 'existing index.html styles.css', '--desired-outcome', 'enhance and redesign').inferredCommand === '/ideate', 'CLI enhancement discovery');
  assert(cli('plan', '--artifact-summary', 'accepted index.html styles.css', '--desired-outcome', 'prepare query loop bindings for Bricks').inferredCommand === '/convert /bricks', 'CLI binding discovery');

  const fixture = (...parts) => path.join(root, 'fixtures', ...parts);
  assert(core.validateSeamMap(fixture('seam-map-valid')).ok, 'strict SEAM Map fixture');
  assert(cli('validate-seam-map', fixture('seam-map-valid')).ok, 'CLI strict SEAM Map validator');
  assert(core.validateBindings(fixture('bindings-valid'), { siteGraphPath: fixture('bindings-valid', 'site-graph.json') }).ok, 'bindings fixture');
  assert(core.validateBricksConversion(fixture('bricks-conversion-valid'), { siteGraphPath: fixture('bricks-conversion-valid', 'site-graph.json') }).ok, 'conversion fixture');
  assert(!core.validateBricksConversion(fixture('bricks-conversion-invalid-flexdirection-responsive')).ok, 'invalid conversion fixture');
  assert(core.validateFrameworkProfile(fixture('framework-profile-valid')).ok, 'framework fixture');
  assert(!core.validateFrameworkProfile(fixture('framework-profile-invalid')).ok, 'invalid framework fixture');
  assert(core.validateBricksThemeStyle(fixture('bricks-theme-style-valid')).ok, 'theme style fixture');
  assert(core.validateAccessibility(fixture('accessibility-valid')).ok, 'accessibility fixture');
  assert(!core.validateAccessibility(fixture('accessibility-invalid-overflow')).ok, 'invalid accessibility fixture');
  assert(!core.validateAccessibility(fixture('accessibility-invalid-contrast')).ok, 'contrast fixture');

  const clamp = core.calculateFluidClamp({ min: '220px', max: '390px', minViewport: 478, maxViewport: 1440 });
  assert(clamp.css.startsWith('clamp(') && !clamp.css.includes('NaN'), 'fluid clamp');

  process.stdout.write(`SEAM command foundation: ${checks}/${checks} checks passed.\n`);
})().catch((error) => {
  process.stderr.write(`${error.stack || error.message}\n`);
  process.exitCode = 1;
});
