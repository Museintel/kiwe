#!/usr/bin/env node
import {
  calculateFluidClamp,
  diagnoseCommand,
  getCommandManifest,
  getStartEntrypoint,
  listCommands,
  planFlow,
  routeCommand,
  validateAccessibility,
  validateBindings,
  validateBricksConversion,
  validateBricksThemeStyle,
  validateFrameworkProfile
} from '../lib/kiwe-core.js';

function print(value) {
  process.stdout.write(`${typeof value === 'string' ? value : JSON.stringify(value, null, 2)}\n`);
}

function option(args, name, fallback = '') {
  const index = args.indexOf(name);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
}

function commandInput(args) {
  return {
    command: option(args, '--command', args[0] && !args[0].startsWith('--') ? args[0] : ''),
    brief: option(args, '--brief'),
    artifactSummary: option(args, '--artifact-summary'),
    siteGraphSummary: option(args, '--site-graph-summary'),
    reportSummary: option(args, '--report-summary'),
    attachmentSummary: option(args, '--attachment-summary')
  };
}

function validationOptions(args) {
  return {
    optional: args.includes('--optional'),
    documented: args.includes('--documented'),
    siteGraphPath: option(args, '--site-graph')
  };
}

function target(args) {
  return args.find((value) => !value.startsWith('--') && value !== option(args, '--site-graph')) || '.';
}

function usage() {
  print(`SEAM CLI

  seam entry
  seam manifest
  seam commands
  seam diagnose --command "/convert /bricks" --artifact-summary source.html
  seam diagnose --command "/convert /bricks /dynamictags" --artifact-summary source.html --site-graph-summary site-graph.json
  seam route --command "/audit" --artifact-summary template.json
  seam plan --attachment-summary "index.html, styles.css and app.js"
  seam validate-bindings <path> [--site-graph <path>] [--optional]
  seam validate-bricks-conversion <path> [--site-graph <path>] [--optional] [--documented]
  seam validate-framework-profile <path> [--optional]
  seam validate-bricks-theme-style <path> [--optional]
  seam validate-accessibility <path> [--optional]
  seam fluid-clamp --min 220px --max 390px [--min-vw 478] [--max-vw 1440]`);
}

const [, , action, ...args] = process.argv;

try {
  if (!action || action === '--help' || action === '-h') usage();
  else if (action === 'entry') print(getStartEntrypoint());
  else if (action === 'manifest') print(getCommandManifest());
  else if (action === 'commands') print(listCommands());
  else if (action === 'diagnose') print(diagnoseCommand(commandInput(args)));
  else if (action === 'route') print(routeCommand(commandInput(args)));
  else if (action === 'plan') print(planFlow({ ...commandInput(args), desiredOutcome: option(args, '--desired-outcome') }));
  else if (action === 'validate-bindings') print(validateBindings(target(args), validationOptions(args)));
  else if (action === 'validate-bricks-conversion') print(validateBricksConversion(target(args), validationOptions(args)));
  else if (action === 'validate-framework-profile') print(validateFrameworkProfile(target(args), validationOptions(args)));
  else if (action === 'validate-bricks-theme-style') print(validateBricksThemeStyle(target(args), validationOptions(args)));
  else if (action === 'validate-accessibility') print(validateAccessibility(target(args), validationOptions(args)));
  else if (action === 'fluid-clamp') print(calculateFluidClamp({min: option(args, '--min', args[0]), max: option(args, '--max', args[1]), minViewport: Number(option(args, '--min-vw', 478)), maxViewport: Number(option(args, '--max-vw', 1440))}));
  else throw new Error(`Unknown command "${action}". Run with --help.`);
} catch (error) {
  process.stderr.write(`${error.message}\n`);
  process.exitCode = 1;
}
