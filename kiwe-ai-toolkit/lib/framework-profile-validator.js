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

export function officialTokenNames() {
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
    for (const match of body.matchAll(/self::token\(\s*['"]([^'"]+)['"]/g)) {
      names.add(String(match[1]));
    }
    for (const match of body.matchAll(/--kiwe-([a-z0-9]+(?:-[a-z0-9]+)*)(?![-\w])/g)) {
      const name = String(match[1]);
      if (!name.startsWith('theme-')) names.add(name);
    }
  }

  return names;
}

export function officialFrameworkCssVariableNames() {
  const variables = new Set();

  officialTokenNames().forEach((name) => {
    if (name) variables.add(`--kiwe-${name}`);
  });

  const candidates = [
    path.join(repoRoot, 'wp-content', 'mu-plugins', 'dsa', 'includes', 'Design', 'Seam_Token_Service.php'),
    path.join(repoRoot, 'wp-content', 'mu-plugins', 'dsa', 'framework-system', 'runtime', 'seam.css'),
    path.join(repoRoot, 'wp-content', 'mu-plugins', 'dsa', 'assets', 'css', 'seam.css'),
    path.join(toolkitRoot, 'packs', 'website-builder', 'runtime', 'seam.css'),
    path.join(toolkitRoot, 'packs', 'website-builder', 'contracts', 'token-map.css'),
    path.join(toolkitRoot, 'packs', 'appshell-theme', 'token-map.css')
  ];

  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    const body = readMaybe(file);
    for (const match of body.matchAll(/--(?:kiwe|seam)-[a-z0-9_]+(?:-[a-z0-9_]+)*(?![-\w])/gi)) {
      const variable = String(match[0]).toLowerCase();
      if (!variable.startsWith('--kiwe-theme-')) variables.add(variable);
    }
  }

  return variables;
}

function add(list, code, message, pathValue = '') {
  list.push({ code, message, path: pathValue });
}

const BRICKS_THEME_STYLE_KEYS = new Set([
  'enabled',
  'id',
  'label',
  'siteBackground',
  'site_background',
  'background',
  'colorPrimary',
  'color_primary',
  'primary',
  'brand',
  'colorSecondary',
  'color_secondary',
  'secondary',
  'accent',
  'colorSurface',
  'color_surface',
  'surface',
  'colorSurfaceRaised',
  'color_surface_raised',
  'surfaceRaised',
  'colorLight',
  'color_light',
  'light',
  'colorDark',
  'color_dark',
  'dark',
  'colorMuted',
  'color_muted',
  'muted',
  'colorBorder',
  'color_border',
  'borderColor',
  'border_color',
  'linkColor',
  'link_color',
  'colorLink',
  'color_link',
  'linkHoverColor',
  'link_hover_color',
  'fontDisplay',
  'font_display',
  'displayFont',
  'display_font',
  'fontBody',
  'font_body',
  'bodyFont',
  'body_font',
  'typeH1',
  'type_h1',
  'typeH2',
  'type_h2',
  'typeBody',
  'type_body',
  'radiusLg',
  'radius_lg',
  'radiusLarge',
  'radius_large',
  'shadowMd',
  'shadow_md',
  'shadowMedium',
  'shadow_medium',
  'spaceMd',
  'space_md'
]);

const CORE_TOKEN_COVERAGE = [
  {
    token: 'color-brand',
    cssVar: '--kiwe-color-brand',
    styleKeys: ['colorPrimary', 'color_primary', 'primary', 'brand', 'linkColor', 'link_color', 'colorLink', 'color_link']
  },
  {
    token: 'color-accent',
    cssVar: '--kiwe-color-accent',
    styleKeys: ['colorSecondary', 'color_secondary', 'secondary', 'accent', 'linkHoverColor', 'link_hover_color']
  },
  {
    token: 'color-surface',
    cssVar: '--kiwe-color-surface',
    styleKeys: ['siteBackground', 'site_background', 'background', 'colorSurface', 'color_surface', 'surface', 'colorLight', 'color_light', 'light']
  },
  {
    token: 'color-surface-raised',
    cssVar: '--kiwe-color-surface-raised',
    styleKeys: ['colorSurfaceRaised', 'color_surface_raised', 'surfaceRaised']
  },
  {
    token: 'color-text',
    cssVar: '--kiwe-color-text',
    styleKeys: ['colorDark', 'color_dark', 'dark']
  },
  {
    token: 'color-text-muted',
    cssVar: '--kiwe-color-text-muted',
    styleKeys: ['colorMuted', 'color_muted', 'muted']
  },
  {
    token: 'color-border',
    cssVar: '--kiwe-color-border',
    styleKeys: ['colorBorder', 'color_border', 'borderColor', 'border_color']
  },
  {
    token: 'font-display',
    cssVar: '--kiwe-font-display',
    styleKeys: ['fontDisplay', 'font_display', 'displayFont', 'display_font']
  },
  {
    token: 'font-body',
    cssVar: '--kiwe-font-body',
    styleKeys: ['fontBody', 'font_body', 'bodyFont', 'body_font']
  },
  {
    token: 'type-h1',
    cssVar: '--kiwe-type-h1',
    styleKeys: ['typeH1', 'type_h1']
  },
  {
    token: 'type-body',
    cssVar: '--kiwe-type-body',
    styleKeys: ['typeBody', 'type_body']
  },
  {
    token: 'space-md',
    cssVar: '--kiwe-space-md',
    styleKeys: ['spaceMd', 'space_md']
  },
  {
    token: 'radius-lg',
    cssVar: '--kiwe-radius-lg',
    styleKeys: ['radiusLg', 'radius_lg', 'radiusLarge', 'radius_large']
  },
  {
    token: 'shadow-md',
    cssVar: '--kiwe-shadow-md',
    styleKeys: ['shadowMd', 'shadow_md', 'shadowMedium', 'shadow_medium']
  }
];

function hasMeaningfulValue(container, key) {
  if (!isPlainObject(container) || !Object.prototype.hasOwnProperty.call(container, key)) return false;
  const value = container[key];
  return ['string', 'number'].includes(typeof value) && String(value).trim() !== '';
}

function hasTokenCoverage(overrides, style, requirement) {
  if (hasMeaningfulValue(overrides, requirement.token)) return true;
  return requirement.styleKeys.some((key) => hasMeaningfulValue(style, key));
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

  const tokenKeys = new Set(['enabled', 'profile_label', 'overrides', 'bricks_theme_style', 'project']);
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
    if (Object.prototype.hasOwnProperty.call(tokens, 'project') && !isPlainObject(tokens.project)) {
      add(errors, 'invalid_project_extensions', 'settings.tokens.project must be an object when present.', 'settings.tokens.project');
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

  const style = tokens && isPlainObject(tokens.bricks_theme_style) ? tokens.bricks_theme_style : null;
  if (tokens && !style) {
    add(errors, 'missing_bricks_theme_style', 'Framework profile must include settings.tokens.bricks_theme_style so Kiwe > Framework can push the matching Bricks global theme style.', 'settings.tokens.bricks_theme_style');
  }
  if (style) {
    for (const key of Object.keys(style)) {
      if (!BRICKS_THEME_STYLE_KEYS.has(key)) {
        add(errors, 'unknown_bricks_theme_style_key', `settings.tokens.bricks_theme_style contains unsupported key ${key}.`, `settings.tokens.bricks_theme_style.${key}`);
      }
    }
    if (Object.keys(style).length === 0) {
      add(errors, 'empty_bricks_theme_style', 'settings.tokens.bricks_theme_style must not be empty. Include enabled, id, label, and safe global style slots where useful.', 'settings.tokens.bricks_theme_style');
    }
    if (!Object.prototype.hasOwnProperty.call(style, 'enabled')) {
      add(errors, 'missing_style_enabled', 'settings.tokens.bricks_theme_style.enabled must be true for a complete Kiwe > Framework profile.', 'settings.tokens.bricks_theme_style.enabled');
    } else if (typeof style.enabled !== 'boolean') {
      add(errors, 'invalid_style_enabled', 'settings.tokens.bricks_theme_style.enabled must be boolean.', 'settings.tokens.bricks_theme_style.enabled');
    } else if (style.enabled !== true) {
      add(errors, 'style_not_enabled', 'settings.tokens.bricks_theme_style.enabled must be true so the imported profile can create/update the Bricks theme style.', 'settings.tokens.bricks_theme_style.enabled');
    }
    if (!Object.prototype.hasOwnProperty.call(style, 'id')) {
      add(errors, 'missing_style_id', 'settings.tokens.bricks_theme_style.id is required and must be a safe Bricks theme-style id.', 'settings.tokens.bricks_theme_style.id');
    } else if (!/^[a-z0-9][a-z0-9_-]{0,79}$/i.test(String(style.id))) {
      add(errors, 'invalid_style_id', 'settings.tokens.bricks_theme_style.id must be a safe id up to 80 characters.', 'settings.tokens.bricks_theme_style.id');
    }
    if (!Object.prototype.hasOwnProperty.call(style, 'label')) {
      add(errors, 'missing_style_label', 'settings.tokens.bricks_theme_style.label is required so designers can find the generated Bricks theme style.', 'settings.tokens.bricks_theme_style.label');
    } else if (typeof style.label !== 'string' || style.label.trim() === '' || style.label.length > 100) {
      add(errors, 'invalid_style_label', 'settings.tokens.bricks_theme_style.label must be a non-empty string up to 100 characters.', 'settings.tokens.bricks_theme_style.label');
    }

    for (const requirement of CORE_TOKEN_COVERAGE) {
      if (!hasTokenCoverage(overrides, style, requirement)) {
        add(
          errors,
          'missing_core_token_coverage',
          `Framework profile must cover official token "${requirement.token}" (${requirement.cssVar}) through settings.tokens.overrides or a mapped bricks_theme_style global slot so Kiwe > Framework push does not leave live Seam/Bricks variables empty.`,
          `settings.tokens.overrides.${requirement.token}`
        );
      }
    }
  }

  if (!Object.keys(overrides).length) {
    add(warnings, 'empty_overrides', 'Framework profile has no token overrides. That is valid, but it may not change the live visual system.', 'settings.tokens.overrides');
  }

  const project = tokens && isPlainObject(tokens.project) ? tokens.project : null;
  let projectVariableCount = 0;
  let projectClassCount = 0;
  if (project) {
    const allowedProjectKeys = new Set(['enabled', 'id', 'label', 'name', 'variables', 'classes']);
    for (const key of Object.keys(project)) {
      if (!allowedProjectKeys.has(key)) {
        add(errors, 'unknown_project_key', `settings.tokens.project contains unsupported key ${key}.`, `settings.tokens.project.${key}`);
      }
    }
    if (Object.prototype.hasOwnProperty.call(project, 'enabled') && typeof project.enabled !== 'boolean') {
      add(errors, 'invalid_project_enabled', 'settings.tokens.project.enabled must be boolean.', 'settings.tokens.project.enabled');
    }
    if (Object.prototype.hasOwnProperty.call(project, 'id') && (typeof project.id !== 'string' || !/^[a-z0-9][a-z0-9_-]{0,59}$/i.test(project.id))) {
      add(errors, 'invalid_project_id', 'settings.tokens.project.id must be a safe project id up to 60 characters.', 'settings.tokens.project.id');
    }
    if (Object.prototype.hasOwnProperty.call(project, 'label') && (typeof project.label !== 'string' || project.label.trim() === '' || project.label.length > 80)) {
      add(errors, 'invalid_project_label', 'settings.tokens.project.label must be a non-empty string up to 80 characters.', 'settings.tokens.project.label');
    }

    const variables = Array.isArray(project.variables) ? project.variables : [];
    if (Object.prototype.hasOwnProperty.call(project, 'variables') && !Array.isArray(project.variables)) {
      add(errors, 'invalid_project_variables', 'settings.tokens.project.variables must be an array.', 'settings.tokens.project.variables');
    }
    const seenVariables = new Set();
    variables.forEach((variable, index) => {
      if (!isPlainObject(variable)) {
        add(errors, 'invalid_project_variable', 'Project variables must be objects.', `settings.tokens.project.variables[${index}]`);
        return;
      }
      const allowedVariableKeys = new Set(['name', 'variable', 'key', 'value', 'type', 'behavior', 'category', 'description']);
      for (const key of Object.keys(variable)) {
        if (!allowedVariableKeys.has(key)) {
          add(errors, 'unknown_project_variable_key', `Project variable contains unsupported key ${key}.`, `settings.tokens.project.variables[${index}].${key}`);
        }
      }
      const name = String(variable.name || variable.variable || variable.key || '').trim().toLowerCase();
      if (!/^--[a-z][a-z0-9]*-[a-z0-9][a-z0-9_-]{0,80}$/.test(name)) {
        add(errors, 'invalid_project_variable_name', 'Project variable names must be CSS custom properties such as --nc-card-radius or --bv-hero-gap.', `settings.tokens.project.variables[${index}].name`);
      } else if (/^--(?:kiwe|seam)-/.test(name)) {
        add(errors, 'reserved_project_variable_name', 'Project variables must not use reserved --kiwe-* or --seam-* names. Use settings.tokens.overrides for official tokens.', `settings.tokens.project.variables[${index}].name`);
      } else if (seenVariables.has(name)) {
        add(errors, 'duplicate_project_variable_name', `Duplicate project variable ${name}.`, `settings.tokens.project.variables[${index}].name`);
      } else {
        seenVariables.add(name);
      }
      if (!['string', 'number'].includes(typeof variable.value) || String(variable.value).trim() === '') {
        add(errors, 'invalid_project_variable_value', 'Project variable values must be non-empty strings or numbers.', `settings.tokens.project.variables[${index}].value`);
      }
    });
    projectVariableCount = seenVariables.size;

    const classes = Array.isArray(project.classes) ? project.classes : [];
    if (Object.prototype.hasOwnProperty.call(project, 'classes') && !Array.isArray(project.classes)) {
      add(errors, 'invalid_project_classes', 'settings.tokens.project.classes must be an array.', 'settings.tokens.project.classes');
    }
    const seenClasses = new Set();
    classes.forEach((classItem, index) => {
      if (!isPlainObject(classItem)) {
        add(errors, 'invalid_project_class', 'Project classes must be objects.', `settings.tokens.project.classes[${index}]`);
        return;
      }
      const allowedClassKeys = new Set(['id', 'name', 'settings', 'category', 'description']);
      for (const key of Object.keys(classItem)) {
        if (!allowedClassKeys.has(key)) {
          add(errors, 'unknown_project_class_key', `Project class contains unsupported key ${key}.`, `settings.tokens.project.classes[${index}].${key}`);
        }
      }
      const id = String(classItem.id || '').trim();
      if (id && !/^[a-z0-9][a-z0-9_-]{4,79}$/i.test(id)) {
        add(errors, 'invalid_project_class_id', 'Project class id must be a safe stable Bricks class id when supplied.', `settings.tokens.project.classes[${index}].id`);
      }
      const name = String(classItem.name || '').trim();
      if (!/^(?:[a-z][a-z0-9]{1,12})-[a-z0-9][a-z0-9_-]{1,80}$/i.test(name)) {
        add(errors, 'invalid_project_class_name', 'Project class names must be prefixed and collision-safe, for example nc-promo-card or bv-product-card.', `settings.tokens.project.classes[${index}].name`);
      } else if (/^(?:kiwe|seam)-/i.test(name)) {
        add(errors, 'reserved_project_class_name', 'Project classes must not use reserved kiwe-* or seam-* names. Use official Seam classes for universal vocabulary.', `settings.tokens.project.classes[${index}].name`);
      } else if (seenClasses.has(name)) {
        add(errors, 'duplicate_project_class_name', `Duplicate project class ${name}.`, `settings.tokens.project.classes[${index}].name`);
      } else {
        seenClasses.add(name);
      }
      if (Object.prototype.hasOwnProperty.call(classItem, 'settings') && !isPlainObject(classItem.settings)) {
        add(errors, 'invalid_project_class_settings', 'Project class settings must be an object when present.', `settings.tokens.project.classes[${index}].settings`);
      }
    });
    projectClassCount = seenClasses.size;

    if (project.enabled === true && projectVariableCount === 0 && projectClassCount === 0) {
      add(errors, 'empty_project_extensions', 'settings.tokens.project.enabled is true but no project variables or classes are declared.', 'settings.tokens.project');
    }
  }

  return {
    ok: errors.length === 0,
    schema: 'kiwe.framework-profile.validation-result.v1',
    path: profilePath,
    errors,
    warnings,
    counts: {
      overrides: Object.keys(overrides).length,
      projectVariables: projectVariableCount,
      projectClasses: projectClassCount,
      officialTokensKnown: official.size
    }
  };
}
