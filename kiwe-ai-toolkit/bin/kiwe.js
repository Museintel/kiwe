#!/usr/bin/env node
import { createHandoff, getBricksConversionContext, getContext, getDynamicContext, getWorkflowContext, listClassVocabulary, listModes, prepareApplyPlan, routeCommand, startDynamicPass, startProject, validateBindings, validateBricksConversion, validateFrameworkProfile, validateHandoff } from '../lib/kiwe-core.js';

function print(value) {
  if (typeof value === 'string') {
    process.stdout.write(value.endsWith('\n') ? value : `${value}\n`);
  } else {
    process.stdout.write(`${JSON.stringify(value, null, 2)}\n`);
  }
}

function usage() {
  print(`Kiwe AI Toolkit

Commands:
  kiwe modes
  kiwe start [auto|website|theme|combined] --brief text [--name name]
  kiwe workflow
  kiwe route --command "/rebuild /seamframework" [--brief text] [--artifact-summary text] [--site-graph-summary text] [--use-companion]
  kiwe context <website|theme|combined>
  kiwe create <website|theme|combined> <output-dir> [--name name] [--brief text]
  kiwe validate <website|theme|combined> <output-dir>
  kiwe vocabulary
  kiwe dynamic-context
  kiwe bricks-conversion-context
  kiwe dynamic-pass --brief text [--site-graph-summary text] [--handoff-summary text]
  kiwe validate-framework-profile <profile-json-or-handoff-dir> [--optional]
  kiwe validate-bindings <handoff-or-bindings-dir-or-json> [--site-graph path/to/site-graph.json] [--optional]
  kiwe validate-bricks-conversion <handoff-or-conversion-json> [--site-graph path/to/site-graph.json] [--optional]
  kiwe prepare-apply <handoff-or-bindings-dir-or-json> --site-graph path/to/site-graph.json [--write]
`);
}

const [, , command, ...args] = process.argv;

try {
  if (!command || command === '--help' || command === '-h') {
    usage();
  } else if (command === 'modes') {
    print(listModes());
  } else if (command === 'start') {
    const mode = args[0] && !args[0].startsWith('--') ? args[0] : 'auto';
    const nameIndex = args.indexOf('--name');
    const briefIndex = args.indexOf('--brief');
    const name = nameIndex >= 0 ? args[nameIndex + 1] : '';
    const brief = briefIndex >= 0 ? args.slice(briefIndex + 1).join(' ') : '';
    print(startProject({ mode, name, brief }));
  } else if (command === 'workflow') {
    print(getWorkflowContext());
  } else if (command === 'route') {
    const commandIndex = args.indexOf('--command');
    const briefIndex = args.indexOf('--brief');
    const artifactIndex = args.indexOf('--artifact-summary');
    const graphIndex = args.indexOf('--site-graph-summary');
    const commandText = commandIndex >= 0 ? args[commandIndex + 1] : args[0] || '';
    const brief = briefIndex >= 0 ? args[briefIndex + 1] : '';
    const artifactSummary = artifactIndex >= 0 ? args[artifactIndex + 1] : '';
    const siteGraphSummary = graphIndex >= 0 ? args[graphIndex + 1] : '';
    print(routeCommand({ command: commandText, brief, artifactSummary, siteGraphSummary, useCompanion: args.includes('--use-companion') }));
  } else if (command === 'context') {
    print(getContext(args[0] || 'website'));
  } else if (command === 'vocabulary') {
    print(listClassVocabulary());
  } else if (command === 'dynamic-context') {
    print(getDynamicContext());
  } else if (command === 'bricks-conversion-context') {
    print(getBricksConversionContext());
  } else if (command === 'dynamic-pass') {
    const briefIndex = args.indexOf('--brief');
    const graphIndex = args.indexOf('--site-graph-summary');
    const handoffIndex = args.indexOf('--handoff-summary');
    const brief = briefIndex >= 0 ? args[briefIndex + 1] : '';
    const siteGraphSummary = graphIndex >= 0 ? args[graphIndex + 1] : '';
    const currentHandoffSummary = handoffIndex >= 0 ? args[handoffIndex + 1] : '';
    print(startDynamicPass({ brief, siteGraphSummary, currentHandoffSummary }));
  } else if (command === 'validate-bindings') {
    const siteGraphIndex = args.indexOf('--site-graph');
    const targetDir = args[0] && !args[0].startsWith('--') ? args[0] : '.';
    const siteGraphPath = siteGraphIndex >= 0 ? args[siteGraphIndex + 1] : '';
    const result = validateBindings(targetDir, { siteGraphPath, optional: args.includes('--optional') });
    print(result);
    process.exitCode = result.ok ? 0 : 1;
  } else if (command === 'validate-bricks-conversion') {
    const siteGraphIndex = args.indexOf('--site-graph');
    const targetDir = args[0] && !args[0].startsWith('--') ? args[0] : '.';
    const siteGraphPath = siteGraphIndex >= 0 ? args[siteGraphIndex + 1] : '';
    const result = validateBricksConversion(targetDir, { siteGraphPath, optional: args.includes('--optional') });
    print(result);
    process.exitCode = result.ok ? 0 : 1;
  } else if (command === 'validate-framework-profile') {
    const targetDir = args[0] && !args[0].startsWith('--') ? args[0] : '.';
    const result = validateFrameworkProfile(targetDir, { optional: args.includes('--optional') });
    print(result);
    process.exitCode = result.ok ? 0 : 1;
  } else if (command === 'prepare-apply') {
    const siteGraphIndex = args.indexOf('--site-graph');
    const targetDir = args[0] && !args[0].startsWith('--') ? args[0] : '.';
    const siteGraphPath = siteGraphIndex >= 0 ? args[siteGraphIndex + 1] : '';
    const result = prepareApplyPlan(targetDir, { siteGraphPath, write: args.includes('--write') });
    print(result);
    process.exitCode = result.ok ? 0 : 1;
  } else if (command === 'create') {
    const mode = args[0] || 'website';
    const outputDir = args[1] || '';
    const nameIndex = args.indexOf('--name');
    const briefIndex = args.indexOf('--brief');
    const name = nameIndex >= 0 ? args[nameIndex + 1] : '';
    const brief = briefIndex >= 0 ? args.slice(briefIndex + 1).join(' ') : '';
    print(createHandoff({ mode, outputDir, name, brief }));
  } else if (command === 'validate') {
    const result = validateHandoff(args[1] || '.', args[0] || 'website');
    print(result);
    process.exitCode = result.ok ? 0 : 1;
  } else {
    usage();
    process.exitCode = 1;
  }
} catch (error) {
  console.error(error && error.message ? error.message : String(error));
  process.exitCode = 1;
}
