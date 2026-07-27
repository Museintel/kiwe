import fs from 'node:fs';
import path from 'node:path';

const REPORT_SCHEMA = 'kiwe.bricks-theme-style-validation.v1';

function isPlainObject(value) {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function add(list, code, message, pathValue = '') {
  list.push({ code, message, path: pathValue });
}

function resolveStylePath(target) {
  const full = path.resolve(target || '.');
  if (fs.existsSync(full) && fs.statSync(full).isFile()) return full;

  const candidates = [
    path.join(full, 'bricks-theme-style.json'),
    path.join(full, 'bricks-theme', 'bricks-theme-style.json'),
    path.join(full, 'framework', 'bricks-theme-style.json')
  ];
  return candidates.find((file) => fs.existsSync(file)) || candidates[0];
}

function readMaybe(file) {
  try {
    return fs.readFileSync(file, 'utf8');
  } catch (_) {
    return '';
  }
}

function containsForbiddenRuntime(value) {
  return /\b(?:data-dsa-surface|data-dsa-dock|data-dsa-screen|theme-package|kiwe\.theme-package|kiwe\.framework-profile|woocommerce|checkout|phonekey|service-worker|bricks_template|templateType|global_classes|globalClasses)\b/i.test(JSON.stringify(value || {}));
}

function containsRawHardcodedCss(value) {
  return /\b(?:position\s*:|inset\s*:|z-index\s*:|100vw|100vh|#[0-9a-f]{3,8}\b|rgba?\(|oklch\(|hsla?\(|\d+(?:px|rem|em|vw|vh)\b)/i.test(JSON.stringify(value || {}));
}

export function validateBricksThemeStyle(target, options = {}) {
  const stylePath = resolveStylePath(target);
  const errors = [];
  const warnings = [];

  if (!fs.existsSync(stylePath)) {
    if (options.optional) {
      return {
        ok: true,
        optional: true,
        missing: true,
        schema: REPORT_SCHEMA,
        path: stylePath,
        errors,
        warnings
      };
    }
    add(errors, 'missing_theme_style', 'Bricks theme style not found. Expected bricks-theme-style.json.', stylePath);
    return { ok: false, schema: REPORT_SCHEMA, path: stylePath, errors, warnings };
  }

  let style = null;
  try {
    style = JSON.parse(readMaybe(stylePath));
  } catch (error) {
    add(errors, 'invalid_json', `Bricks theme style JSON could not be parsed: ${error.message}`, stylePath);
    return { ok: false, schema: REPORT_SCHEMA, path: stylePath, errors, warnings };
  }

  if (!isPlainObject(style)) {
    add(errors, 'invalid_root', 'Bricks theme style root must be an object.', '');
    return { ok: false, schema: REPORT_SCHEMA, path: stylePath, errors, warnings };
  }

  const allowedRoot = new Set(['id', 'label', 'settings']);
  for (const key of Object.keys(style)) {
    if (!allowedRoot.has(key)) {
      add(errors, 'unknown_root_key', `Bricks theme style must contain only optional id plus label and settings; found ${key}.`, key);
    }
  }

  if (style.id !== undefined && (typeof style.id !== 'string' || !/^[a-z0-9_-]{3,64}$/i.test(style.id))) {
    add(errors, 'invalid_id', 'Optional Bricks theme style id must be a safe string when present.', 'id');
  }

  if (typeof style.label !== 'string' || style.label.trim() === '' || style.label.length > 100) {
    add(errors, 'invalid_label', 'Bricks theme style label must be a non-empty string up to 100 characters.', 'label');
  }

  if (!isPlainObject(style.settings)) {
    add(errors, 'missing_settings', 'Bricks theme style must contain a settings object.', 'settings');
  }

  if (containsForbiddenRuntime(style)) {
    add(errors, 'forbidden_runtime_payload', 'Bricks theme style must not contain DSA/AppShell theme packages, Bricks template content, WooCommerce/runtime authority, or global-class/template upload payloads.');
  }

  const settings = isPlainObject(style.settings) ? style.settings : {};
  const allowedGroups = new Set(['_custom', 'conditions', 'general', 'colors', 'typography', 'links']);
  for (const key of Object.keys(settings)) {
    if (!allowedGroups.has(key)) {
      add(warnings, 'broad_theme_style_group', `Bricks theme style group "${key}" is outside the recommended lean global lane. Use only when the human explicitly requested this group.`, `settings.${key}`);
    }
  }

  const conditions = settings.conditions?.conditions;
  if (conditions !== undefined && !Array.isArray(conditions)) {
    add(errors, 'invalid_conditions', 'settings.conditions.conditions must be an array when present.', 'settings.conditions.conditions');
  }

  if (Array.isArray(conditions)) {
    for (const [index, condition] of conditions.entries()) {
      if (!isPlainObject(condition)) {
        add(errors, 'invalid_condition', 'Each Bricks theme-style condition must be an object.', `settings.conditions.conditions.${index}`);
        continue;
      }
      if (condition.main && condition.main !== 'any') {
        add(warnings, 'non_global_condition', 'For a sitewide Bricks theme style, Bricks uses main: "any". Non-global conditions are allowed only when the human requested a scoped style.', `settings.conditions.conditions.${index}.main`);
      }
    }
  }

  if (containsRawHardcodedCss(settings)) {
    add(warnings, 'raw_css_literal_or_value', 'Theme style contains raw CSS literals. Prefer Kiwe/Seam CSS variables such as var(--kiwe-color-brand), var(--kiwe-type-h1), var(--kiwe-space-md), and Bricks color refs where practical.', 'settings');
  }

  if (!JSON.stringify(settings).includes('--kiwe-')) {
    add(warnings, 'no_kiwe_tokens', 'Theme style does not appear to use Kiwe/Seam token variables. This may not sync with Kiwe > Framework.', 'settings');
  }

  return {
    ok: errors.length === 0,
    schema: REPORT_SCHEMA,
    path: stylePath,
    errors,
    warnings,
    counts: {
      settingGroups: Object.keys(settings).length
    }
  };
}
