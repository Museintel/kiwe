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
  'lib/seam-framework-package-validator.js',
  'lib/bricks-theme-style-validator.js',
  'tools/validate-seamframework.cjs',
  'tools/validate-seamframework-package.cjs',
  'tools/compile-seamframework.cjs',
  'tools/validate-bricks-theme-style.cjs',
  'mcp/index.js',
  'tools/smoke-test.cjs'
].forEach((file) => runNode(['--check', file]));

(async () => {
  const m = await import(pathToFileURL(path.join(root, 'lib/kiwe-core.js')).href);
  const entry = m.getStartEntrypoint();
  const manifest = m.getCommandManifest();
  const compilerContract = JSON.parse(fs.readFileSync(path.join(root, 'contracts/seam-compiler-contract.json'), 'utf8'));
  const plan = m.planFlow({ artifactSummary: 'homepage-appsite-v3-main-only-preview.html raw html css js' });

  assert(compilerContract.version === '0.13.0', 'compiler contract mismatch');
  assert(compilerContract.stages.convert.frameworkNeutral === true, 'raw Convert must remain Framework-neutral');
  assert(compilerContract.stages.framework.profileInstallRequiredBeforeTemplateImport === true, 'Framework install order missing');
  assert(compilerContract.stages.framework.output.includes('/audit /seamframework'), 'Framework audit artifact contract missing');
  assert(compilerContract.stages.accessibility.currentCompletionGate === true, 'accessibility must be an explicit separate completion gate');
  assert(compilerContract.stages.accessibility.requiresExecutableProof === true, 'accessibility requires executable proof');
  assert(compilerContract.stages.accessibility.browserAiMayNotInventContrastRatios === true, 'browser AI must not invent accessibility evidence');

  assert(entry.schema === 'kiwe.start.v1', 'entry schema mismatch');
  assert(entry.productName === 'SeamFlow', 'entry product mismatch');
  assert(entry.contractVersion === '7.15', 'entry contract mismatch');
  assert(entry.noCommandInteraction.firstResponseShape.at(-1) === 'Commands: use /list for the compact command list', 'first response /list hint must be last');
  assert(entry.flows.executionCommands['/execute /stepbystep'], 'missing /execute /stepbystep in entry');
  assert(entry.flows.executionCommands['/ideate'], 'missing /ideate in entry');
  assert(entry.flows.commandGrammar.contextSources.includes('/usesitegraph') && entry.flows.commandGrammar.contextSources.includes('/usebrickscontext'), 'entry missing contextual dynamic evidence sources');
  assert(entry.flows.commandGrammar.phaseTargets.includes('/previewdata') && entry.flows.commandGrammar.phaseTargets.includes('/bricksbindings'), 'entry missing contextual dynamic targets');
  assert(entry.flows.commandGrammar.entityScopes.includes('/products') && entry.flows.commandGrammar.fieldScopes.includes('/images'), 'entry missing entity/field narrowing tokens');
  assert(entry.navigationTree.contexts.ideation.includes('ideate-lite.md'), 'entry missing ideation context route');
  assert(entry.flows.executionCommands['/execute /fullflow'], 'missing /execute /fullflow in entry');
  assert(entry.flows.executionCommands['/audit /eachstep'], 'missing /audit /eachstep in entry');
  assert(entry.flows.executionCommands['/audit /atend'], 'missing /audit /atend in entry');
  assert(entry.flows.secondPassCommands['/audit /allattached'], 'missing /audit /allattached in entry');
  assert(entry.flows.secondPassCommands['/fix /allflow'], 'missing /fix /allflow in entry');
  assert(entry.flows.secondPassCommands['/audit /allattached /allflow'], 'missing /audit /allattached /allflow in entry');
  assert(entry.flows.secondPassCommands['/fix /previousaudit'], 'missing /fix /previousaudit in entry');
  assert(entry.flows.secondPassCommands['/audit /previousoutput'], 'missing /audit /previousoutput in entry');
  assert(entry.flows.secondPassCommands['/fix /previousoutput'], 'missing /fix /previousoutput in entry');
  assert(entry.errorHandling.codes.KIWE_PREVIOUS_AUDIT_MISSING, 'entry missing previous audit error code');
  assert(entry.errorHandling.codes.KIWE_PREVIOUS_OUTPUT_MISSING, 'entry missing previous output error code');
  assert(entry.flows.auditClosure, 'entry missing audit closure law');
  assert(entry.flows.auditClosure.byStartPoint['bricks-template-upload'].includes('/audit /bricksconversion'), 'entry missing Bricks closure audit');
  assert(entry.flows.auditClosure.byStartPoint['raw-html-css-js'].includes('/audit /bricksconversion'), 'entry missing raw Bricks closure');
  assert(JSON.stringify(entry).includes('Do not use prior Kiwe validation material'), 'missing current-run evidence boundary in entry');
  assert(JSON.stringify(entry).includes('Full-flow means one final delivery, not one giant context load'), 'missing full-flow stepwise context boundary in entry');
  assert(JSON.stringify(entry).includes('Missing SiteGraph is not a blocker for static Bricks conversion or /usebrickscontext targets'), 'missing contextual SiteGraph non-blocking boundary in entry');
  assert(JSON.stringify(entry).includes('PASS requires executable validator proof'), 'missing validator proof boundary in entry');
  assert(JSON.stringify(entry).includes('self-contained fallback'), 'missing standalone Seam validator boundary in entry');
  assert(JSON.stringify(entry).includes('auto-detects Framework packages'), 'missing Framework package validator routing in entry');
  assert(JSON.stringify(entry).includes('compile-seamframework.cjs'), 'missing deterministic Seam compiler in entry');
  assert(entry.validatorAuthority.routeFallbackLadder.includes('validate-framework-profile') || entry.validatorAuthority.routeFallbackLadder.includes('official matching validator'), 'entry missing cross-phase REST-to-Git fallback ladder');
  assert(entry.pluginApi && entry.pluginApi.routes && entry.pluginApi.routes.execute.includes('/ai/seamflow/execute'), 'entry missing plugin SeamFlow execute route');
  assert(entry.pluginApi.auth.includes('seamflow'), 'entry missing plugin SeamFlow scope');
  assert(entry.pluginApi.askWhenMissing.includes('WordPress Admin'), 'entry missing plugin API key creation prompt');
  assert(entry.noCommandInteraction.firstResponseShape.some((line) => line.includes('Route A hosted SEAM Compiler')), 'entry missing compiler route options');
  assert(entry.firstResponse.ifFilesNoCommand.includes('/convert /bricks'), 'entry missing raw deterministic conversion default');
  assert(entry.noCommandInteraction.firstResponseShape.some((line) => line.includes('/convert /bricks')), 'entry first response missing raw conversion command');
  assert(entry.errorHandling.codes.KIWE_VALIDATOR_PROOF_MISSING, 'entry missing validator proof error code');

  assert(manifest.schema === 'kiwe.command-manifest.v1', 'manifest schema mismatch');
  assert(manifest.productName === 'SeamFlow', 'manifest product mismatch');
  assert(manifest.entry.contractVersion === '7.15', 'manifest contract mismatch');
  assert(manifest.flowPlanner.mcp === 'kiwe_seamflow_plan', 'manifest MCP planner mismatch');
  assert(manifest.commands['/execute /stepbystep'], 'manifest missing /execute /stepbystep');
  assert(manifest.commands['/ideate'], 'manifest missing /ideate');
  assert(manifest.commandGrammar.primaryActions.includes('/ideate'), 'manifest missing /ideate primary action');
  assert(manifest.commandGrammar.contextSources.includes('/usesitegraph') && manifest.commandGrammar.contextSources.includes('/usebrickscontext'), 'manifest missing contextual dynamic evidence sources');
  assert(manifest.commandGrammar.phaseTargets.includes('/dynamictags') && manifest.commandGrammar.phaseTargets.includes('/queryloops'), 'manifest missing narrow Bricks dynamic targets');
  assert(manifest.commandGrammar.entityScopes.includes('/products') && manifest.commandGrammar.fieldScopes.includes('/titles'), 'manifest missing dynamic scope tokens');
  assert(manifest.contexts.ideation === 'kiwe-ai-toolkit/contexts/ideate-lite.md', 'manifest missing ideation context');
  assert(manifest.commands['/execute /fullflow'], 'manifest missing /execute /fullflow');
  assert(manifest.commands['/audit /eachstep'], 'manifest missing /audit /eachstep');
  assert(manifest.commands['/audit /atend'], 'manifest missing /audit /atend');
  assert(manifest.commands['/audit /allattached'], 'manifest missing /audit /allattached');
  assert(manifest.commands['/fix /allflow'], 'manifest missing /fix /allflow');
  assert(manifest.commands['/audit /allattached /allflow'], 'manifest missing /audit /allattached /allflow');
  assert(manifest.commands['/fix /previousaudit'], 'manifest missing /fix /previousaudit');
  assert(manifest.commands['/audit /previousoutput'], 'manifest missing /audit /previousoutput');
  assert(manifest.commands['/fix /previousoutput'], 'manifest missing /fix /previousoutput');
  assert(manifest.errorCatalog.codes.KIWE_MANUAL_PASS_BLOCKED, 'manifest missing command-central error catalog');
  assert(manifest.errorCatalog.codes.KIWE_VALIDATOR_PROOF_MISSING, 'manifest missing validator proof error catalog');
  assert(manifest.globalRules.validatorAuthority.includes('SEAM Compiler'), 'manifest missing SEAM Compiler proof authority');
  assert(manifest.pluginApi && manifest.pluginApi.routes && manifest.pluginApi.routes.status.includes('/ai/seamflow/status'), 'manifest missing plugin SeamFlow status route');
  assert(manifest.pluginApi.scope.includes('seamflow'), 'manifest missing plugin SeamFlow scope');
  assert(manifest.pluginApi.askWhenMissing.includes('WordPress Admin'), 'manifest missing plugin API key creation prompt');
  assert(manifest.globalRules.firstInteractionRoutes.includes('Route D'), 'manifest missing first-interaction route menu');
  assert(manifest.globalRules.firstInteractionRoutes.includes('/convert /bricks'), 'manifest missing raw conversion default');
  assert(manifest.globalRules.routeFallbackLadder.includes('Route B'), 'manifest missing cross-phase REST-to-Git fallback ladder');
  assert(manifest.commands['/seamframework'], 'manifest missing optional Framework stage');
  assert(manifest.commands['/accessibility'], 'manifest missing independent bare /accessibility lane');
  assert(manifest.commands['/accessibility'].forbidden.includes('forcing Seam Framework'), 'bare accessibility must remain Framework-independent');
  assert(JSON.stringify(manifest.commands['/rebuild /seamframework']).includes('legacy'), 'manifest missing legacy normalization boundary');
  assert(manifest.globalRules.auditClosure.includes('SeamFlow closes only when'), 'manifest missing audit closure rule');
  assert(manifest.commands['/fix /accessibility'].preserve.includes('Seam classes'), 'accessibility preservation contract missing Seam classes');

  assert(plan.schema === 'kiwe.seamflow-plan.v1', 'plan schema mismatch');
  assert(plan.productName === 'SeamFlow', 'plan product mismatch');
  assert(plan.contractVersion === '7.15', 'plan contract mismatch');
  assert(plan.startResponse.mustReport === 'SeamFlow contract: 7.15', 'plan contract report mismatch');
  assert(plan.startResponse.order.at(-1) === 'Commands: use /list for the compact command list', 'plan first-response order should put /list last');
  assert(plan.routeOptions.pluginRest.includes('KIWE_REST_BASE'), 'plan missing plugin REST route option');
  assert(plan.routeOptions.apiPrompt.includes('WordPress Admin'), 'plan missing route API prompt');
  assert(plan.startResponse.rawDraftDefaultCommand === '/convert /bricks', 'plan missing raw conversion default');
  assert(plan.firstInteractionDefaults.rawHtmlCssJsDraft.recommendedCommand === '/convert /bricks', 'plan missing structured raw conversion default');
  assert(plan.capabilityCheck.routeFallbackLadder.includes('Route B'), 'plan missing cross-phase REST-to-Git fallback ladder');
  assert(plan.recommendedNextCommands.includes('/seamframework if Framework output is wanted'), 'plan missing optional Framework stage');
  assert(plan.executionOptions.stepByStep === '/execute /stepbystep', 'plan missing step-by-step command');
  assert(plan.executionOptions.fullFlow === '/execute /fullflow', 'plan missing full-flow command');
  assert(plan.auditClosure.requiredAudits.includes('/audit /bricksconversion'), 'plan missing bricks closure audit');

  const bad = m.diagnoseCommand({ command: '/buid /preview /brickstheme' });
  const ideate = m.diagnoseCommand({ command: '/ideate' });
  const ideateRoute = m.routeCommand({ command: '/ideate', brief: 'A distinct editorial portal' });
  const noop = m.diagnoseCommand({ command: '/create /preview /website', artifactSummary: 'website/bricks-paste.html exists' });
  const missingProfile = m.diagnoseCommand({ command: '/convert /bricks', artifactSummary: 'website/bricks-paste.html exists' });
  const ok = m.diagnoseCommand({ command: '/convert /bricks', artifactSummary: 'website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists' });
  const wrongVerb = m.diagnoseCommand({ command: '/create /bricks', artifactSummary: 'website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists' });
  const a11y = m.diagnoseCommand({ command: '/create /accessibility', artifactSummary: 'website/bricks-paste.html exists' });
  const bareA11y = m.diagnoseCommand({ command: '/accessibility', artifactSummary: 'current /ideate HTML/CSS/JS page exists' });
  const missing = m.diagnoseCommand({ command: '/audit /accessibility' });
  const auditCadence = m.diagnoseCommand({ command: '/audit /eachstep', artifactSummary: 'website/bricks-paste.html exists' });
  const executeMissing = m.diagnoseCommand({ command: '/execute /fullflow /audit /eachstep' });
  const executeOk = m.diagnoseCommand({ command: '/execute /fullflow /audit /eachstep', artifactSummary: 'homepage raw html css js' });
  const auditAllMissing = m.diagnoseCommand({ command: '/audit /allattached' });
  const auditAllOk = m.diagnoseCommand({ command: '/audit /allattached', artifactSummary: 'framework/kiwe-framework-profile.json exists; bricks-template/home-template-upload.json exists' });
  const fixAllMissing = m.diagnoseCommand({ command: '/fix /allflow' });
  const auditAllFlowOk = m.diagnoseCommand({ command: '/audit /allattached /allflow', artifactSummary: 'framework/kiwe-framework-profile.json exists; bricks-template/home-template-upload.json exists' });
  const previousAuditMissing = m.diagnoseCommand({ command: '/fix /previousaudit' });
  const previousPassNeedsAudit = m.diagnoseCommand({ command: '/fix /previouspass', artifactSummary: 'current artifact exists but no previous audit findings exist' });
  const oldAuditAliasRejected = m.diagnoseCommand({ command: '/audit' + 'ateachstep', artifactSummary: 'website/bricks-paste.html exists' });
  const previousOutputMissing = m.diagnoseCommand({ command: '/audit /previousoutput' });
  const previousOutputOk = m.diagnoseCommand({ command: '/audit /previousoutput', artifactSummary: 'immediate previous AI output files: framework/kiwe-framework-profile.json; bricks-template/home-template-upload.json' });
  const broadSiteGraph = m.diagnoseCommand({ command: '/usesitegraph', artifactSummary: 'current raw index.html', siteGraphSummary: 'kiwe.site-graph.v1 export' });
  const previewOnly = m.diagnoseCommand({ command: '/usesitegraph /for /previewdata /nonai', artifactSummary: 'current raw index.html', siteGraphSummary: 'kiwe.site-graph.v1 export with product records' });
  const genericDynamic = m.diagnoseCommand({ command: '/usebrickscontext /for /dynamictags', artifactSummary: 'current raw index.html' });
  const invalidPreviewContext = m.diagnoseCommand({ command: '/usebrickscontext /for /previewdata', artifactSummary: 'current raw index.html' });
  const invalidNonAi = m.diagnoseCommand({ command: '/usebrickscontext /for /dynamictags /nonai', artifactSummary: 'current raw index.html' });
  const previewRoute = m.routeCommand({ command: '/usesitegraph /for /previewdata /nonai', artifactSummary: 'current raw index.html', siteGraphSummary: 'kiwe.site-graph.v1 export with product records' });
  const scopedPreview = m.diagnoseCommand({ command: '/usesitegraph /for /previewdata /products /titles /images /nonai', artifactSummary: 'current raw index.html', siteGraphSummary: 'kiwe.site-graph.v1 export with product records' });
  const scopedPreviewRoute = m.routeCommand({ command: '/usesitegraph /for /previewdata /products /titles /images /nonai', artifactSummary: 'current raw index.html', siteGraphSummary: 'kiwe.site-graph.v1 export with product records' });
  const genericDynamicRoute = m.routeCommand({ command: '/usebrickscontext /for /dynamictags', artifactSummary: 'current raw index.html' });

  assert(bad.stop && bad.code === 'unknown_command_token', 'bad typo diagnostic failed');
  assert(!ideate.stop && ideate.kind === 'ideate', '/ideate diagnostic failed');
  assert(ideateRoute.includes('Ask no more than three short questions at a time.'), '/ideate route missing adaptive interview');
  assert(ideateRoute.includes('/ideate` output is always framework-neutral'), '/ideate route must be framework-neutral');
  assert(!ideateRoute.includes('Should this draft be (1) framework-neutral HTML/CSS/JS, or (2) Seam-ready HTML/CSS/JS?'), '/ideate route must not ask for a framework choice');
  assert(ideateRoute.includes('existing client website URL'), '/ideate route missing broader project-resource intake');
  assert(ideateRoute.includes('inspiration or moodboard material'), '/ideate route missing inspiration and moodboard intake');
  assert(ideateRoute.includes('reuse') && ideateRoute.includes('inspiration only'), '/ideate route must distinguish reusable assets from directional references');
  assert(!ideateRoute.includes('## Seam-ready branch'), '/ideate route must not contain a Seam-ready output branch');
  assert(ideateRoute.includes('Framework Profile JSON'), '/ideate route must reserve registered tokens for the later Framework Profile flow');
  assert(ideateRoute.includes('Responsive geometry ladder'), '/ideate route missing responsive geometry guidance');
  assert(ideateRoute.includes('ordinary conversation is the refinement interface'), '/ideate route missing conversational refinement');
  assert(noop.stop && noop.status === 'noop', 'preview noop diagnostic failed');
  assert(!missingProfile.stop, 'raw conversion must not require a Framework profile');
  assert(!ok.stop, 'valid bricks conversion diagnostic stopped');
  assert(wrongVerb.stop && wrongVerb.code === 'bricks_convert_requires_convert_verb', 'wrong verb diagnostic failed');
  assert(!a11y.stop, 'accessibility create diagnostic stopped unexpectedly');
  assert(!bareA11y.stop && bareA11y.kind === 'accessibility-create', 'bare /accessibility should route the current artifact without forcing another lane');
  assert(missing.stop && missing.code === 'accessibility_audit_missing_artifact', 'missing accessibility audit diagnostic failed');
  assert(auditCadence.stop && auditCadence.code === 'audit_cadence_requires_execute', 'audit cadence flag diagnostic failed');
  assert(executeMissing.stop && executeMissing.code === 'execute_missing_current_artifact', 'execute missing artifact diagnostic failed');
  assert(!executeOk.stop, 'execute with current artifact should not stop');
  assert(auditAllMissing.stop && auditAllMissing.code === 'audit_all_missing_artifacts', 'audit all missing artifacts diagnostic failed');
  assert(!auditAllOk.stop, 'audit all with current artifacts should not stop');
  assert(fixAllMissing.stop && fixAllMissing.code === 'audit_all_missing_artifacts', 'fix all missing artifacts diagnostic failed');
  assert(!auditAllFlowOk.stop, 'combined allattached allflow audit should not stop with current artifacts');
  assert(previousAuditMissing.stop && previousAuditMissing.code === 'previous_audit_missing', 'previous audit missing diagnostic failed');
  assert(previousPassNeedsAudit.stop && previousPassNeedsAudit.code === 'previous_audit_missing', 'previouspass should request previous audit evidence');
  assert(oldAuditAliasRejected.stop && oldAuditAliasRejected.code === 'unknown_command_token', 'old audit cadence alias should be rejected');
  assert(previousOutputMissing.stop && previousOutputMissing.code === 'previous_output_missing', 'previous output missing diagnostic failed');
  assert(!previousOutputOk.stop, 'previous output with immediate output summary should not stop');
  assert(broadSiteGraph.stop && broadSiteGraph.code === 'dynamic_target_missing', 'broad /usesitegraph must ask for an explicit /for target');
  assert(!previewOnly.stop, 'targeted preview-data SiteGraph command should pass with artifact and evidence');
  assert(!scopedPreview.stop, 'entity/field-scoped preview command should pass without prose');
  assert(!genericDynamic.stop, 'generic Bricks dynamic tags must not require SiteGraph');
  assert(invalidPreviewContext.stop && invalidPreviewContext.code === 'preview_data_requires_sitegraph', 'preview data must require SiteGraph evidence');
  assert(invalidNonAi.stop && invalidNonAi.code === 'nonai_requires_sitegraph', '/nonai must not leak into Bricks-context commands');
  assert(previewRoute.includes('does not add query loops, dynamic tags, launchers'), 'preview-only route must not silently create bindings');
  assert(!previewRoute.includes('Expected output when dynamic intent changes:'), 'preview-only route must not require a binding artifact');
  assert(scopedPreviewRoute.includes('Entity scope: change only `/products` regions.'), 'preview route must honor the product entity scope');
  assert(scopedPreviewRoute.includes('Field scope: change only `/titles`, `/images` values'), 'preview route must honor exact field scopes');
  assert(genericDynamicRoute.includes('SiteGraph is not required'), 'Bricks-context route must explicitly remain independent of SiteGraph');
  assert(genericDynamicRoute.includes('annotate only source-evidenced'), 'dynamic-tag target contract missing');

  const seamValidatorSource = fs.readFileSync(path.join(root, 'tools/validate-seamframework.cjs'), 'utf8');
  assert(seamValidatorSource.includes('self-contained-fallback'), 'Seam validator missing standalone fallback mode');
  assert(seamValidatorSource.includes('bareSeamSelectorDeclarations'), 'Seam validator missing bare Seam selector check');

  captureNode(['bin/kiwe.js', 'entry'], 'tmp/entry-smoke.json');
  captureNode(['bin/kiwe.js', 'command-manifest'], 'tmp/command-manifest-smoke.json');
  captureNode(['bin/kiwe.js', 'seamflow', '--artifact-summary', 'homepage-appsite-v3-main-only-preview.html raw html css js'], 'tmp/seamflow-smoke.json');
  captureNode(['bin/kiwe.js', 'plan-flow', '--artifact-summary', 'native Bricks template upload JSON with title templateType header and seam classes'], 'tmp/flow-plan-smoke.json');
  captureNode(['bin/kiwe.js', 'workflow'], 'tmp/workflow-smoke.md');
  captureNode(['bin/kiwe.js', 'ideate-context'], 'tmp/ideate-context-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/ideate', '--brief', 'A distinct editorial portal'], 'tmp/route-ideate-smoke.md');
  captureNode(['bin/kiwe.js', 'diagnose', '--command', '/create /preview /brickstheme'], 'tmp/diagnose-invalid-preview-smoke.json');
  captureNode(['bin/kiwe.js', 'route', '--command', '/rebuild /seamframework', '--brief', 'Smoke'], 'tmp/route-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /frameworkprofile', '--brief', 'Smoke'], 'tmp/route-framework-profile-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /brickstheme', '--brief', 'Smoke'], 'tmp/route-bricks-theme-style-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /preview /dsatheme', '--brief', 'Smoke'], 'tmp/route-theme-preview-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /preview /combined', '--brief', 'Smoke'], 'tmp/route-combined-preview-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/convert /bricks', '--brief', 'Smoke', '--artifact-summary', 'website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists'], 'tmp/route-bricks-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/create /accessibility', '--brief', 'Smoke', '--artifact-summary', 'website/bricks-paste.html exists'], 'tmp/route-accessibility-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/audit /allattached', '--brief', 'Smoke', '--artifact-summary', 'framework/kiwe-framework-profile.json exists; bricks-template/home-template-upload.json exists'], 'tmp/route-audit-allattached-smoke.md');
  captureNode(['bin/kiwe.js', 'route', '--command', '/fix /allflow', '--brief', 'Smoke', '--artifact-summary', 'website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists; bricks-template/home-template-upload.json exists'], 'tmp/route-fix-allflow-smoke.md');
  captureNode(['bin/kiwe.js', 'diagnose', '--command', '/fix /previousaudit'], 'tmp/diagnose-previousaudit-smoke.json');
  captureNode(['bin/kiwe.js', 'diagnose', '--command', '/audit /previousoutput'], 'tmp/diagnose-previousoutput-smoke.json');
  captureNode(['bin/kiwe.js', 'compile-seamframework', 'fixtures/seam-compile-raw/index.html', 'tmp/seam-compile-smoke'], 'tmp/compile-seamframework-smoke.json');
  const compileSmoke = JSON.parse(fs.readFileSync(path.join(root, 'tmp/compile-seamframework-smoke.json'), 'utf8'));
  assert(compileSmoke.ok, 'compile-seamframework smoke did not pass');
  assert(compileSmoke.validator.exitCode === 0, 'compiled Seam artifact validator did not pass');
  assert(fs.existsSync(path.join(root, 'tmp/seam-compile-smoke/website/bricks-paste.html')), 'compiled Seam artifact missing');

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
  const unsafeBricksTemplate = m.validateBricksConversion(path.join(root, 'fixtures/bricks-template-invalid-compiler-safe'));
  const unsafeBricksTemplateText = JSON.stringify(unsafeBricksTemplate);
  assert(!unsafeBricksTemplate.ok && unsafeBricksTemplateText.includes('includes the CSS custom-property prefix'), 'invalid Bricks template variable-name fixture did not fail');
  assert(!unsafeBricksTemplate.ok && unsafeBricksTemplateText.includes('not compiler-safe'), 'invalid Bricks unsafe-control fixture did not fail');
  assert(!unsafeBricksTemplate.ok && unsafeBricksTemplateText.includes('CSS-variable font stacks become invalid'), 'invalid Bricks font-family token fixture did not fail');
  assert(!unsafeBricksTemplate.ok && unsafeBricksTemplateText.includes('stores color as a plain string'), 'invalid Bricks color-control shape fixture did not fail');
  assert(!unsafeBricksTemplate.ok && unsafeBricksTemplateText.includes('inline fallback'), 'invalid Bricks CSS-variable fallback fixture did not fail');
  const multiOwnerDir = path.join(tmp, 'bricks-template-invalid-multi-owner');
  fs.mkdirSync(path.join(multiOwnerDir, 'bricks-template'), { recursive: true });
  const multiOwnerElements = Array.from({ length: 180 }, (_, index) => ({
    id: `mo${String(index).padStart(4, '0')}`,
    name: 'block',
    parent: index === 0 ? 0 : 'mo0000',
    children: [],
    settings: {
      _cssGlobalClasses: ['gdup1'],
      _display: 'flex',
      _background: { color: { raw: 'var(--kiwe-color-surface)' } }
    }
  }));
  multiOwnerElements[0].children = multiOwnerElements.slice(1).map((item) => item.id);
  fs.writeFileSync(path.join(multiOwnerDir, 'bricks-template/home-template-upload.json'), JSON.stringify({
    title: 'Home',
    templateType: 'content',
    version: '2.3.7',
    content: multiOwnerElements,
    global_classes: [
      {
        id: 'gdup1',
        name: 'nc-duplicate-owner',
        settings: {
          _background: { color: { raw: 'var(--kiwe-color-surface)' } },
          _border: {
            color: { raw: 'var(--kiwe-color-border)' },
            width: { top: 'var(--kiwe-border-width-hairline)', right: 'var(--kiwe-border-width-hairline)', bottom: 'var(--kiwe-border-width-hairline)', left: 'var(--kiwe-border-width-hairline)' },
            radius: { top: 'var(--kiwe-radius-lg)', right: 'var(--kiwe-radius-lg)', bottom: 'var(--kiwe-radius-lg)', left: 'var(--kiwe-radius-lg)' }
          }
        }
      }
    ],
    global_variables: []
  }));
  const multiOwner = m.validateBricksConversion(multiOwnerDir);
  assert(!multiOwner.ok && JSON.stringify(multiOwner).includes('ghost styling'), 'invalid Bricks multi-owner global class fixture did not fail');
  runNode(['tools/validate-accessibility.cjs', 'fixtures/accessibility-valid']);

  const contrast = m.validateAccessibility(path.join(root, 'fixtures/accessibility-invalid-contrast'));
  assert(!contrast.ok && JSON.stringify(contrast).includes('accessibility_low_contrast_literal_pair'), 'invalid contrast fixture did not fail');
  const overflow = m.validateAccessibility(path.join(root, 'fixtures/accessibility-invalid-overflow'));
  assert(!overflow.ok && JSON.stringify(overflow).includes('accessibility_text_clipping_risk'), 'invalid overflow fixture did not fail');

  runNode(['tools/prepare-apply-plan.cjs', 'fixtures/bindings-valid', '--site-graph', 'fixtures/bindings-valid/site-graph.json']);
  runNode(['tools/validate-framework-profile.cjs', 'fixtures/framework-profile-valid']);
  runNode(['tools/validate-framework-profile.cjs', 'fixtures/framework-profile-valid-project']);
  runNode(['tools/validate-bricks-theme-style.cjs', 'fixtures/bricks-theme-style-valid']);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
