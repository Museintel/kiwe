import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateBindings as validateBindingsPlan } from './binding-validator.js';
import { validateBricksConversion as validateBricksConversionPlan } from './bricks-conversion-validator.js';
import { validateAccessibility as validateAccessibilityPlan } from './accessibility-validator.js';
import { validateFrameworkProfile as validateFrameworkProfilePlan } from './framework-profile-validator.js';
import { validateBricksThemeStyle as validateBricksThemeStylePlan } from './bricks-theme-style-validator.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const toolkitRoot = path.resolve(__dirname, '..');

const contextByCommand = new Map([
  ['/ideate', 'ideate.md'],
  ['/convert /bricks', 'convert-bricks.md'],
  ['/audit', 'audit.md'],
  ['/accessibility', 'accessibility.md'],
  ['/fix', 'accessibility.md'],
  ['/redo', 'audit.md']
]);

function readJson(relativePath) {
  return JSON.parse(fs.readFileSync(path.join(toolkitRoot, relativePath), 'utf8'));
}

function readText(relativePath) {
  return fs.readFileSync(path.join(toolkitRoot, relativePath), 'utf8');
}

function normalizeCommand(value) {
  return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
}

function parseCommand(value) {
  const requestedCommand = normalizeCommand(value);
  const convertMatch = requestedCommand.match(/^\/convert \/bricks(?: \/(dynamictags|queryloop))?$/);
  if (convertMatch) {
    const modifier = convertMatch[1] ? `/${convertMatch[1]}` : '';
    return {
      command: '/convert /bricks',
      requestedCommand,
      modifier,
      bindingMode: modifier === '/dynamictags' ? 'dynamicTagsOnly' : modifier === '/queryloop' ? 'queryLoopsOnly' : 'dynamicTagsAndQueryLoops'
    };
  }
  return { command: requestedCommand, requestedCommand, modifier: '', bindingMode: '' };
}

function hasInput(...values) {
  return values.some((value) => String(value || '').trim().length > 0);
}

function commandSpec(command) {
  return getCommandManifest().commands.find((item) => item.command === command) || null;
}

function requirementStatus(command, { brief = '', attachmentSummary = '', artifactSummary = '', siteGraphSummary = '', reportSummary = '' } = {}) {
  if (command === '/ideate') {
    return hasInput(brief, attachmentSummary, artifactSummary, siteGraphSummary) ? null : 'Provide a project brief, inspectable attachments, approved source material or SiteGraph context.';
  }
  if (command === '/convert /bricks' || command === '/audit' || command === '/accessibility') {
    return hasInput(artifactSummary) ? null : 'Provide or summarize the artifact to process.';
  }
  if (command === '/fix') {
    if (!hasInput(artifactSummary)) return 'Provide the artifact to correct.';
    return hasInput(reportSummary, brief) ? null : 'Provide the audit report or proven findings.';
  }
  if (command === '/redo') {
    if (!hasInput(artifactSummary)) return 'Provide the last accepted artifact and failed output.';
    return hasInput(reportSummary, brief) ? null : 'Provide failure evidence or the prior report.';
  }
  return null;
}

export function getCommandManifest() {
  return readJson('command-manifest.json');
}

export function getStartEntrypoint() {
  return {
    ...readJson('entry.json'),
    startText: fs.readFileSync(path.resolve(toolkitRoot, '..', 'KIWE-START.md'), 'utf8')
  };
}

export function listCommands() {
  return getCommandManifest().commands.map((item) => ({ ...item }));
}

export function getIdeationContext() {
  return readText('contexts/ideate.md');
}

export function getBricksConversionContext() {
  return readText('contexts/convert-bricks.md');
}

export function getAuditContext() {
  return readText('contexts/audit.md');
}

export function getAccessibilityContext() {
  return readText('contexts/accessibility.md');
}

export function diagnoseCommand(input = {}) {
  const parsed = parseCommand(input.command);
  if (!parsed.command) {
    return { ok: false, status: 'needs_command', contractVersion: getCommandManifest().contractVersion, allowed: listCommands().map((item) => item.command) };
  }
  const spec = commandSpec(parsed.command);
  if (!spec) {
    return { ok: false, status: 'rejected', command: parsed.requestedCommand, reason: 'Unknown command, alias or modifier.', allowed: listCommands().map((item) => item.command) };
  }
  const missing = requirementStatus(parsed.command, input);
  if (missing) {
    return { ok: false, status: 'needs_input', command: parsed.command, requestedCommand: parsed.requestedCommand, reason: missing, requires: spec.requires };
  }
  return { ok: true, status: 'ready', command: parsed.command, requestedCommand: parsed.requestedCommand, modifier: parsed.modifier || undefined, bindingMode: parsed.bindingMode || undefined, outputs: spec.outputs, boundary: spec.boundary };
}

export function routeCommand(input = {}) {
  const diagnosis = diagnoseCommand(input);
  if (!diagnosis.ok) return diagnosis;
  const spec = commandSpec(diagnosis.command);
  return {
    ...diagnosis,
    contractVersion: getCommandManifest().contractVersion,
    context: readText(`contexts/${contextByCommand.get(diagnosis.command)}`),
    authority: getCommandManifest().authority,
    input: {
      brief: String(input.brief || ''),
      attachmentSummary: String(input.attachmentSummary || ''),
      artifactSummary: String(input.artifactSummary || ''),
      siteGraphSummary: String(input.siteGraphSummary || ''),
      reportSummary: String(input.reportSummary || '')
    },
    options: diagnosis.command === '/convert /bricks' ? { bindingMode: diagnosis.bindingMode, modifier: diagnosis.modifier || null, emitsBricksJson: false, emitsUpdatedSource: false, sourcePolicy: 'immutable' } : undefined
  };
}

export function planFlow(input = {}) {
  if (hasInput(input.command)) return routeCommand(input);
  const summary = `${input.attachmentSummary || ''} ${input.artifactSummary || ''} ${input.desiredOutcome || ''}`.toLowerCase();
  let command = '/ideate';
  if (/failed|failure|regression|redo/.test(summary)) command = '/redo';
  else if (/audit report|findings|fix|correct/.test(summary)) command = '/fix';
  else if (/accessib|contrast|overflow|collision|dark mode|keyboard/.test(summary)) command = '/accessibility';
  else if (/bricks|template|json|generated artifact|compiled/.test(summary)) command = '/audit';
  if (/dynamic tag|query loop|binding intent|prepare.+bricks/.test(summary) && /\.html|\.css|\.js|raw project|source project|web project/.test(summary)) command = '/convert /bricks';

  return {
    schema: 'kiwe.flow-plan.v2',
    contractVersion: getCommandManifest().contractVersion,
    inferredCommand: command,
    execution: 'suggest-only',
    waitsForUserCommand: true,
    reason: commandSpec(command).boundary,
    next: commandSpec(command)
  };
}

function parseLength(value, label) {
  const match = String(value || '').trim().match(/^(-?(?:\d+|\d*\.\d+))(px|rem|em|ch|vw|vh|vmin|vmax)$/i);
  if (!match) throw new Error(`${label} must be a simple CSS length such as 220px, 2.5rem, or -7px.`);
  return { value: Number(match[1]), unit: match[2].toLowerCase() };
}

function cssNumber(value, precision) {
  const rounded = Number(value.toFixed(Math.max(0, Math.min(8, precision))));
  return String(Object.is(rounded, -0) ? 0 : rounded);
}

export function calculateFluidClamp(options = {}) {
  const min = parseLength(options.min ?? options.minValue, 'min');
  const max = parseLength(options.max ?? options.maxValue, 'max');
  const minViewport = Number(options.minViewport ?? options.minVw ?? 478);
  const maxViewport = Number(options.maxViewport ?? options.maxVw ?? 1440);
  const precision = Number.isInteger(options.precision) ? options.precision : 4;
  if (min.unit !== max.unit) throw new Error('min and max must use the same CSS unit.');
  if (!Number.isFinite(minViewport) || !Number.isFinite(maxViewport) || minViewport <= 0 || maxViewport <= 0 || minViewport === maxViewport) throw new Error('Viewport widths must be positive and different.');
  if (min.value === max.value) throw new Error('Use a stable token instead of clamp(v, v, v).');
  const slope = ((max.value - min.value) / (maxViewport - minViewport)) * 100;
  const intercept = min.value - (slope / 100) * minViewport;
  return {
    schema: 'kiwe.fluid-clamp.v1',
    css: `clamp(${cssNumber(Math.min(min.value, max.value), precision)}${min.unit}, calc(${cssNumber(intercept, precision)}${min.unit} + ${cssNumber(slope, precision)}vw), ${cssNumber(Math.max(min.value, max.value), precision)}${min.unit})`,
    slope: Number(cssNumber(slope, precision)),
    intercept: Number(cssNumber(intercept, precision))
  };
}

export function validateBindings(targetDir, options = {}) {
  return validateBindingsPlan(targetDir, options);
}

export function validateBricksConversion(targetDir, options = {}) {
  return validateBricksConversionPlan(targetDir, options);
}

export function validateAccessibility(targetDir, options = {}) {
  return validateAccessibilityPlan(targetDir, options);
}

export function validateFrameworkProfile(targetDir, options = {}) {
  return validateFrameworkProfilePlan(targetDir, options);
}

export function validateBricksThemeStyle(targetDir, options = {}) {
  return validateBricksThemeStylePlan(targetDir, options);
}
