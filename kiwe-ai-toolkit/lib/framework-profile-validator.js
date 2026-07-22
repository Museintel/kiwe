import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const toolkitRoot = path.resolve(__dirname, '..');
const repoRoot = path.resolve(toolkitRoot, '..');

function readMaybe(file) {
  try {
    return fs.readFileSync(file, 'utf8');
  } catch (_) {
    return '';
  }
}

function isPlainObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function resolveProfilePath(target) {
  const full = path.resolve(target || '.');
  if (fs.existsSync(full) && fs.statSync(full).isFile()) return full;

  const candidates = [
    path.join(full, 'framework', 'kiwe-framework-profile.json'),
    path.join(full, 'kiwe-framework-profile.json')
  ];
  return candidates.find((file) => fs.existsSync(file)) || candidates[0];
}

function officialTokenNames() {
  const names = new Set();
  const candidates = [
    path.join(repoRoot, 'wp-content', 'mu-plugins', 'dsa', 'includes', 'Design', 'Seam_Token_Service.php'),
    path.join(repoRoot, 'wp-content', 'mu-plugins', 'dsa', 'ui-system', 'token-map.css'),
    path.join(toolkitRoot, 'packs', 'website-builder', 'contracts', 'token-map.css'),
    path.join(toolkitRoot, 'packs', 'appshell-theme', 'token-map.css')
  ];

  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    const body = readMaybe(file);
    for (const match of body.matchAll(/['"]name['"]\s*=>\s*['"]([^'"]+)['"]/g)) {
      names.add(String(match[1]));
    }
    for (const match of body.matchAll(/self::token\(\s*['"]([^'"]+)['"]/g)) {
      names.add(String(match[1]));
    }
    for (const match of body.matchAll(/--kiwe-([a-z0-9-]+)/g)) {
      const name = String(match[1]);
      if (!name.startsWith('theme-')) names.add(name);
    }
  }

  return names;
}

function add(list, code, message, pathValue = '') {
  list.push({ code, message, path: pathValue });
}

export function validateFrameworkProfile(target, options = {}) {
  const profilePath = resolveProfilePath(target);
  const errors = [];
  const warnings = [];
  const optional = Boolean(options.optional);

  if (!fs.existsSync(profilePath)) {
    if (optional) {
      return {
        ok: true,
        optional: true,
        missing: true,
        path: profilePath,
        errors,
        warnings,
        summary: 'No Framework profile present.'
      };
    }
    add(errors, 'missing_profile', 'Framework profile not found. Expected framework/kiwe-framework-profile.json or a direct JSON file.', profilePath);
    return { ok: false, path: profilePath, errors, warnings };
  }

  let profile = null;
  try {
    profile = JSON.parse(readMaybe(profilePath));
  } catch (error) {
    add(errors, 'invalid_json', `Framework profile is not valid JSON: ${error.message}`, profilePath);
    return { ok: false, path: profilePath, errors, warnings };
  }

  if (!isPlainObject(profile)) {
    add(errors, 'invalid_root', 'Framework profile root must be an object.', '');
    return { ok: false, path: profilePath, errors, warnings };
  }

  const allowedRoot = new Set(['type', 'schema', 'schemaVersion', 'pluginVersion', 'exportedAt', 'source', 'settings']);
  for (const key of Object.keys(profile)) {
    if (!allowedRoot.has(key)) {
      add(errors, 'unknown_root_key', `Framework profile must not contain root ${key}.`, key);
    }
  }

  for (const forbidden of ['dock', 'style', 'screens', 'theme_screens', 'dsa_theme', 'visual_effects', 'commerce', 'bricks', 'css', 'html']) {
    if (Object.prototype.hasOwnProperty.call(profile, forbidden)) {
      add(errors, 'forbidden_root_key', `Framework profile must not contain root ${forbidden}. Put AppShell settings in theme-package.json and page content in website/bricks-paste.html.`, forbidden);
    }
  }

  if (profile.schema !== 'kiwe.framework-profile.v1') {
    add(errors, 'invalid_schema', 'Framework profile schema must be kiwe.framework-profile.v1.', 'schema');
  }
  if (Object.prototype.hasOwnProperty.call(profile, 'type') && profile.type !== 'kiwe-framework-profile') {
    add(errors, 'invalid_type', 'Framework profile type, when present, must be kiwe-framework-profile.', 'type');
  }
  if (Object.prototype.hasOwnProperty.call(profile, 'schemaVersion') && (!Number.isInteger(profile.schemaVersion) || profile.schemaVersion < 1)) {
    add(errors, 'invalid_schema_version', 'schemaVersion must be an integer >= 1 when present.', 'schemaVersion');
  }

  const settings = isPlainObject(profile.settings) ? profile.settings : null;
  if (!settings) {
    add(errors, 'missing_settings', 'Framework profile must contain settings.', 'settings');
  } else {
    for (const key of Object.keys(settings)) {
      if (key !== 'tokens') {
        add(errors, 'unknown_settings_key', `Framework profile settings must contain only tokens; found settings.${key}.`, `settings.${key}`);
      }
    }
  }

  const tokens = settings && isPlainObject(settings.tokens) ? settings.tokens : null;
  if (!tokens) {
    add(errors, 'missing_tokens', 'Framework profile must contain settings.tokens.', 'settings.tokens');
  }

  const tokenKeys = new Set(['enabled', 'profile_label', 'overrides', 'bricks_theme_style']);
  if (tokens) {
    for (const key of Object.keys(tokens)) {
      if (!tokenKeys.has(key)) {
        add(errors, 'unknown_tokens_key', `settings.tokens contains unsupported key ${key}.`, `settings.tokens.${key}`);
      }
    }
    if (Object.prototype.hasOwnProperty.call(tokens, 'enabled') && typeof tokens.enabled !== 'boolean') {
      add(errors, 'invalid_enabled', 'settings.tokens.enabled must be boolean.', 'settings.tokens.enabled');
    }
    if (Object.prototype.hasOwnProperty.call(tokens, 'profile_label')) {
      if (typeof tokens.profile_label !== 'string' || tokens.profile_label.trim() === '' || tokens.profile_label.length > 80) {
        add(errors, 'invalid_profile_label', 'settings.tokens.profile_label must be a non-empty string up to 80 characters.', 'settings.tokens.profile_label');
      }
    }
    if (Object.prototype.hasOwnProperty.call(tokens, 'overrides') && !isPlainObject(tokens.overrides)) {
      add(errors, 'invalid_overrides', 'settings.tokens.overrides must be an object.', 'settings.tokens.overrides');
    }
    if (Object.prototype.hasOwnProperty.call(tokens, 'bricks_theme_style') && !isPlainObject(tokens.bricks_theme_style)) {
      add(errors, 'invalid_bricks_theme_style', 'settings.tokens.bricks_theme_style must be an object.', 'settings.tokens.bricks_theme_style');
    }
  }

  const official = officialTokenNames();
  const overrides = tokens && isPlainObject(tokens.overrides) ? tokens.overrides : {};
  for (const [tokenName, value] of Object.entries(overrides)) {
    if (!/^[a-z0-9][a-z0-9-]{0,79}$/i.test(tokenName)) {
      add(errors, 'invalid_token_name', `Token override "${tokenName}" must use an official token name such as color-brand, not a CSS variable or private key.`, `settings.tokens.overrides.${tokenName}`);
      continue;
    }
    if (official.size && !official.has(tokenName)) {
      add(errors, 'unknown_token_name', `Token override "${tokenName}" is not in the known Kiwe universal token list.`, `settings.tokens.overrides.${tokenName}`);
    }
    if (!['string', 'number'].includes(typeof value)) {
      add(errors, 'invalid_token_value', `Token override "${tokenName}" must be a string or number.`, `settings.tokens.overrides.${tokenName}`);
    }
  }

  const style = tokens && isPlainObject(tokens.bricks_theme_style) ? tokens.bricks_theme_style : {};
  if (style) {
    const styleKeys = new Set(['enabled', 'id', 'label']);
    for (const key of Object.keys(style)) {
      if (!styleKeys.has(key)) {
        add(errors, 'unknown_bricks_theme_style_key', `settings.tokens.bricks_theme_style contains unsupported key ${key}.`, `settings.tokens.bricks_theme_style.${key}`);
      }
    }
    if (Object.prototype.hasOwnProperty.call(style, 'enabled') && typeof style.enabled !== 'boolean') {
      add(errors, 'invalid_style_enabled', 'settings.tokens.bricks_theme_style.enabled must be boolean.', 'settings.tokens.bricks_theme_style.enabled');
    }
    if (Object.prototype.hasOwnProperty.call(style, 'id') && !/^[a-z0-9][a-z0-9_-]{0,79}$/i.test(String(style.id))) {
      add(errors, 'invalid_style_id', 'settings.tokens.bricks_theme_style.id must be a safe id up to 80 characters.', 'settings.tokens.bricks_theme_style.id');
    }
    if (Object.prototype.hasOwnProperty.call(style, 'label') && (typeof style.label !== 'string' || style.label.trim() === '' || style.label.length > 100)) {
      add(errors, 'invalid_style_label', 'settings.tokens.bricks_theme_style.label must be a non-empty string up to 100 characters.', 'settings.tokens.bricks_theme_style.label');
    }
  }

  if (!Object.keys(overrides).length) {
    add(warnings, 'empty_overrides', 'Framework profile has no token overrides. That is valid, but it may not change the live visual system.', 'settings.tokens.overrides');
  }

  return {
    ok: errors.length === 0,
    schema: 'kiwe.framework-profile.validation-result.v1',
    path: profilePath,
    errors,
    warnings,
    counts: {
      overrides: Object.keys(overrides).length,
      officialTokensKnown: official.size
    }
  };
}
