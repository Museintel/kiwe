import fs from 'node:fs';
import path from 'node:path';
import { validateFrameworkProfile } from './framework-profile-validator.js';

function walk(dir, output = []) {
  if (!fs.existsSync(dir)) return output;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'dist', 'qa-sections', 'accessibility'].includes(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, output);
    else output.push(full);
  }
  return output;
}

function object(value) {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function mergeDeep(base, overlay) {
  const output = structuredClone(base);
  for (const [key, value] of Object.entries(overlay || {})) {
    if (object(value) && object(output[key])) output[key] = mergeDeep(output[key], value);
    else output[key] = structuredClone(value);
  }
  return output;
}

function readJson(file) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_) { return null; }
}

function elements(template) {
  for (const key of ['content', 'header', 'footer']) if (Array.isArray(template?.[key])) return template[key];
  return [];
}

function collectVariables(value, pattern, output = new Set()) {
  if (typeof value === 'string') {
    for (const match of value.matchAll(pattern)) output.add(String(match[1]).toLowerCase());
  } else if (Array.isArray(value)) value.forEach((item) => collectVariables(item, pattern, output));
  else if (object(value)) Object.values(value).forEach((item) => collectVariables(item, pattern, output));
  return output;
}

function leafValues(value, prefix = '', output = new Map()) {
  if (!object(value)) { if (prefix) output.set(prefix, JSON.stringify(value)); return output; }
  for (const [key, item] of Object.entries(value)) {
    if (['_cssGlobalClasses', '_cssClasses', '_attributes'].includes(key)) continue;
    leafValues(item, prefix ? `${prefix}.${key}` : key, output);
  }
  return output;
}

const requiredTokens = ['color-brand', 'color-accent', 'color-surface', 'color-surface-raised', 'color-text', 'color-text-muted', 'color-border', 'font-display', 'font-body', 'type-h1', 'type-body', 'space-md', 'radius-lg', 'shadow-md'];

export function validateSeamFrameworkPackage(target) {
  const resolved = path.resolve(target || '.');
  const root = fs.existsSync(resolved) && fs.statSync(resolved).isFile() ? path.dirname(resolved) : resolved;
  const profileValidation = validateFrameworkProfile(root);
  const profilePath = path.join(root, 'framework', 'kiwe-framework-profile.json');
  const profile = readJson(profilePath);
  const findings = [];
  const add = (code, message, file = '', element = '') => findings.push({ code, message, file, ...(element ? { element } : {}) });
  if (!profileValidation.ok || !profile) {
    for (const error of profileValidation.errors || []) add(error.code || 'profile_error', error.message || 'Framework Profile validation failed.', error.path || 'framework/kiwe-framework-profile.json');
    return { ok: false, status: 'FAIL', schema: 'kiwe.seamframework-package-validation.v1', command: '/audit /seamframework', root, counts: { templates: 0, variables: 0, classes: 0, classReferences: 0, variableReferences: 0 }, findings, profile: profileValidation };
  }

  const tokens = profile.settings.tokens;
  const project = object(tokens.project) ? tokens.project : {};
  const variables = Array.isArray(project.variables) ? project.variables : [];
  const classes = Array.isArray(project.classes) ? project.classes : [];
  const variableNames = new Set(variables.map((item) => String(item?.name || '').toLowerCase()));
  const classById = new Map();
  const classNames = new Set();
  for (const item of classes) {
    const id = String(item?.id || '');
    const name = String(item?.name || '');
    if (!/^[a-z0-9][a-z0-9_-]{4,79}$/i.test(id)) add('invalid_project_class_id', `Project class ${name || '(unnamed)'} has no stable Bricks id.`, 'framework/kiwe-framework-profile.json');
    if (classById.has(id)) add('duplicate_project_class_id', `Project class id ${id} is duplicated.`, 'framework/kiwe-framework-profile.json');
    if (classNames.has(name)) add('duplicate_project_class_name', `Project class name ${name} is duplicated.`, 'framework/kiwe-framework-profile.json');
    classById.set(id, item);
    classNames.add(name);
  }
  const overrides = object(tokens.overrides) ? tokens.overrides : {};
  for (const name of requiredTokens) if (!(name in overrides)) add('missing_universal_token', `Required universal token ${name} is not covered.`, 'framework/kiwe-framework-profile.json');

  const files = walk(root).filter((file) => /\.json$/i.test(file));
  const templateFiles = files.filter((file) => {
    const json = readJson(file);
    return object(json) && json?.generator?.seamFramework === 'kiwe.framework-profile.v1' && elements(json).length > 0;
  });
  if (!templateFiles.length) add('missing_dependent_templates', 'No Framework-dependent Bricks templates were found.', '');
  const loadedTemplates = templateFiles.map((file) => readJson(file));
  const packageDefinitions = collectVariables([loadedTemplates, classes], /(--[a-z0-9_-]+)\s*:/gi);
  let classReferences = 0;
  let variableReferences = 0;
  for (const file of templateFiles) {
    const template = readJson(file);
    const relative = path.relative(root, file).replace(/\\/g, '/');
    if (Array.isArray(template?.generator?.frameworkSourceClasses)) add('source_ownership_leak', 'Compiler-only source class metadata leaked into the dependent template.', relative);
    const list = elements(template);
    const ids = new Set(list.map((item) => String(item?.id || '')));
    if (ids.size !== list.length) add('duplicate_element_id', 'Template contains duplicate Bricks element ids.', relative);
    for (const item of list) {
      const id = String(item?.id || '');
      if (item?.parent && !ids.has(String(item.parent))) add('orphan_parent', `Element points to missing parent ${item.parent}.`, relative, id);
      const refs = Array.isArray(item?.settings?._cssGlobalClasses) ? item.settings._cssGlobalClasses.map(String) : [];
      classReferences += refs.length;
      let mergedClassSettings = {};
      for (const ref of refs) {
        const classItem = classById.get(ref);
        if (!classItem) { add('uninstalled_project_class', `Element references class id ${ref}, but the profile does not install it.`, relative, id); continue; }
        mergedClassSettings = mergeDeep(mergedClassSettings, classItem.settings || {});
      }
      const owned = leafValues(mergedClassSettings);
      const local = leafValues(item.settings || {});
      const duplicate = Array.from(owned).filter(([key, value]) => local.get(key) === value).map(([key]) => key);
      if (duplicate.length) add('duplicate_style_ownership', `Framework class stack and element both own ${duplicate.slice(0, 8).join(', ')}.`, relative, id);
      const typography = object(item?.settings?._typography) ? item.settings._typography : {};
      if (String(item?.name || '') === 'heading' && /^h[1-6]$/i.test(String(item?.settings?.tag || '')) && /var\(--kiwe-type-h[1-6]/i.test(String(typography['font-size'] || ''))) add('semantic_heading_lock', 'Semantic heading carries a local universal heading-size lock.', relative, id);
    }
    for (const item of Array.isArray(template.global_classes) ? template.global_classes : []) if (object(item?.settings) && Object.keys(item.settings).length) add('styled_template_global_class', 'Dependent template embeds styled global classes instead of relying on the Framework Profile.', relative, String(item?.id || ''));
    const serialized = JSON.stringify(template);
    if (/var\(\s*--[a-z0-9_-]+\s*,/i.test(serialized)) add('css_variable_fallback', 'Dependent template contains CSS variable fallbacks; profile installation must fail closed.', relative);
    const refs = collectVariables(template, /var\(\s*(--[a-z0-9_-]+)/gi);
    const definitions = collectVariables(template, /(--[a-z0-9_-]+)\s*:/gi);
    variableReferences += refs.size;
    for (const ref of refs) {
      const official = ref.startsWith('--kiwe-') && requiredTokens.includes(ref.replace(/^--kiwe-/, ''));
      if (!official && !variableNames.has(ref) && !definitions.has(ref) && !packageDefinitions.has(ref)) add('undefined_css_variable', `Template consumes ${ref}, but neither the profile nor project runtime defines it.`, relative);
    }
  }

  const auditFile = path.join(root, 'framework', 'audit-seamframework.json');
  const compilerAudit = readJson(auditFile);
  if (!compilerAudit || compilerAudit.status !== 'PASS') add('missing_compiler_audit', 'Compiler /audit /seamframework PASS artifact is missing.', path.relative(root, auditFile).replace(/\\/g, '/'));
  else {
    if (Number(compilerAudit.integration) !== 100) add('incomplete_compiler_integration', `Compiler Framework integration is ${String(compilerAudit.integration)}%, not 100%.`, path.relative(root, auditFile).replace(/\\/g, '/'));
    const sourceParity = Array.isArray(compilerAudit.checks) && compilerAudit.checks.find((check) => check?.id === 'source-parity');
    if (!sourceParity || sourceParity.status !== 'PASS') add('missing_source_parity_proof', 'Compiler audit does not prove raw-to-Framework structure and content preservation.', path.relative(root, auditFile).replace(/\\/g, '/'));
  }
  const counts = { templates: templateFiles.length, variables: variables.length, classes: classes.length, classReferences, variableReferences };
  return { ok: findings.length === 0, status: findings.length ? 'FAIL' : 'PASS', schema: 'kiwe.seamframework-package-validation.v1', command: '/audit /seamframework', root, counts, findings, profile: profileValidation };
}
