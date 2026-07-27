import fs from 'node:fs';
import path from 'node:path';

const PLAN_SCHEMA = 'kiwe.accessibility-plan.v1';
const REPORT_SCHEMA = 'kiwe.accessibility-validation.v1';
const TEXT_EXTENSIONS = new Set(['.html', '.htm', '.css', '.json']);
const SKIP_DIRS = new Set(['.git', 'node_modules', 'vendor', 'dist', 'build', '.next']);
const MAX_FILE_BYTES = 700_000;
const DEFAULT_MIN_CONTRAST = 4.5;

const NAMED_COLORS = new Map([
  ['black', [0, 0, 0, 1]],
  ['white', [255, 255, 255, 1]],
  ['transparent', [0, 0, 0, 0]],
  ['red', [255, 0, 0, 1]],
  ['green', [0, 128, 0, 1]],
  ['blue', [0, 0, 255, 1]],
  ['currentcolor', null]
]);

const DARK_PROOF_RE = /\b(?:data-kiwe-theme\s*=\s*["']dark["']|data-theme\s*=\s*["']dark["']|data-kiwe-theme-toggle|prefers-color-scheme\s*:\s*dark|\[data-kiwe-theme=["']dark["']|\[data-theme=["']dark["'])/i;
const BRICKS_THEME_STYLE_RE = /\b(?:bricks_theme_style|themeStyle|themeStyles|colorPrimary|colorSecondary|colorLight|colorDark|siteBackground)\b/i;
const KIWE_TOKEN_RE = /var\(\s*--kiwe-(?:color|theme|font|type|space|radius|shadow)-[a-z0-9-]+/i;
const PRIVATE_PROJECT_COLOR_VAR_RE = /var\(\s*--(?!kiwe-|dsa-|wp--|bricks-)[a-z0-9_-]*(?:color|bg|surface|text|ink|muted|accent|brand)[a-z0-9_-]*/i;

function isPlainObject(value) {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function rel(root, file) {
  return path.relative(root, file).replace(/\\/g, '/');
}

function add(findings, level, code, message, file = '', selector = '') {
  findings.push({ level, code, message, file, selector });
}

function readTextIfExists(file) {
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return '';
  const stat = fs.statSync(file);
  if (stat.size > MAX_FILE_BYTES) return '';
  return fs.readFileSync(file, 'utf8');
}

function findPlanPath(target) {
  const resolved = path.resolve(target || '.');
  if (fs.existsSync(resolved) && fs.statSync(resolved).isFile()) {
    return path.basename(resolved).toLowerCase().includes('accessibility') ? resolved : '';
  }
  const candidates = [
    path.join(resolved, 'accessibility', 'kiwe-accessibility-plan.json'),
    path.join(resolved, 'kiwe-accessibility-plan.json')
  ];
  return candidates.find((candidate) => fs.existsSync(candidate)) || '';
}

function collectFiles(root) {
  const files = [];
  const resolved = path.resolve(root || '.');
  if (!fs.existsSync(resolved)) return files;
  if (fs.statSync(resolved).isFile()) return [resolved];

  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        if (!SKIP_DIRS.has(entry.name)) walk(path.join(dir, entry.name));
        continue;
      }
      if (!entry.isFile()) continue;
      const full = path.join(dir, entry.name);
      const ext = path.extname(entry.name).toLowerCase();
      if (!TEXT_EXTENSIONS.has(ext)) continue;
      if (fs.statSync(full).size > MAX_FILE_BYTES) continue;
      files.push(full);
    }
  };
  walk(resolved);
  return files;
}

function parseJson(file, findings) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    add(findings, 'fail', 'accessibility_plan_invalid_json', `Accessibility plan is not valid JSON: ${error && error.message ? error.message : String(error)}`, file);
    return null;
  }
}

function validatePlan(plan, planPath, findings) {
  if (!isPlainObject(plan)) {
    add(findings, 'fail', 'accessibility_plan_not_object', 'Accessibility plan must be a JSON object.', planPath);
    return;
  }
  if (plan.schema !== PLAN_SCHEMA) {
    add(findings, 'fail', 'accessibility_plan_invalid_schema', `Accessibility plan schema must be ${PLAN_SCHEMA}.`, planPath, '$.schema');
  }
  const modes = Array.isArray(plan.modes) ? plan.modes.map((mode) => String(mode).toLowerCase()) : [];
  if (!modes.includes('light') || !modes.includes('dark')) {
    add(findings, 'fail', 'accessibility_plan_missing_light_dark_modes', 'Accessibility plan must cover both light and dark modes.', planPath, '$.modes');
  }
  const tokenPairs = Array.isArray(plan.tokenPairs) ? plan.tokenPairs : [];
  if (!tokenPairs.length) {
    add(findings, 'fail', 'accessibility_plan_missing_token_pairs', 'Accessibility plan must include tokenPairs for page/theme contrast surfaces.', planPath, '$.tokenPairs');
  }
  for (const [index, pair] of tokenPairs.entries()) {
    if (!isPlainObject(pair)) {
      add(findings, 'fail', 'accessibility_plan_invalid_token_pair', `tokenPairs[${index}] must be an object.`, planPath, `$.tokenPairs[${index}]`);
      continue;
    }
    const fg = String(pair.foreground || pair.fg || '').trim();
    const bg = String(pair.background || pair.bg || '').trim();
    if (!fg || !bg) {
      add(findings, 'fail', 'accessibility_plan_pair_missing_foreground_background', `tokenPairs[${index}] must include foreground and background.`, planPath, `$.tokenPairs[${index}]`);
      continue;
    }
    if (!/var\(\s*--kiwe-|^#|^rgb|^hsl|^oklch|^color-mix/i.test(fg) || !/var\(\s*--kiwe-|^#|^rgb|^hsl|^oklch|^color-mix/i.test(bg)) {
      add(findings, 'warn', 'accessibility_plan_pair_not_tokenized', `tokenPairs[${index}] should use Kiwe/Seam token variables when possible.`, planPath, `$.tokenPairs[${index}]`);
    }
    const parsedFg = firstColor(fg);
    const parsedBg = firstColor(bg);
    if (parsedFg && parsedBg) {
      const ratio = contrastRatio(parsedFg, parsedBg);
      const min = Number(pair.minimumContrast || pair.minContrast || DEFAULT_MIN_CONTRAST);
      if (ratio < min) {
        add(findings, 'fail', 'accessibility_plan_low_contrast_pair', `tokenPairs[${index}] has contrast ${ratio.toFixed(2)} below ${min}.`, planPath, `$.tokenPairs[${index}]`);
      }
    }
  }
  if (!Array.isArray(plan.manualReview)) {
    add(findings, 'fail', 'accessibility_plan_missing_manual_review', 'Accessibility plan must include manualReview as an array, even when empty.', planPath, '$.manualReview');
  }
}

function extractCssBlocks(text, file) {
  const blocks = [];
  const ext = path.extname(file).toLowerCase();
  if (ext === '.css') {
    blocks.push({ selector: file, css: text });
  }
  for (const match of text.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)) {
    blocks.push({ selector: '<style>', css: match[1] || '' });
  }
  for (const match of text.matchAll(/\bstyle\s*=\s*["']([^"']+)["']/gi)) {
    blocks.push({ selector: '[inline-style]', css: `[inline-style]{${match[1] || ''}}` });
  }
  if (ext === '.json' && /(?:color|background|theme\.css|customCss|_cssCustom)/i.test(text)) {
    const strings = Array.from(text.matchAll(/"([^"\\]*(?:\\.[^"\\]*)*)"/g), (match) => {
      try {
        return JSON.parse(`"${match[1]}"`);
      } catch {
        return '';
      }
    }).filter((value) => /(?:color|background|--kiwe-|#(?:[0-9a-f]{3}|[0-9a-f]{6}))\b/i.test(value));
    if (strings.length) blocks.push({ selector: '[json-css-strings]', css: strings.join('\n') });
  }
  return blocks;
}

function parseDeclarations(body) {
  const declarations = {};
  for (const item of String(body || '').split(';')) {
    const index = item.indexOf(':');
    if (index <= 0) continue;
    const prop = item.slice(0, index).trim().toLowerCase();
    const value = item.slice(index + 1).trim();
    if (!prop || !value) continue;
    declarations[prop] = value;
  }
  return declarations;
}

function scanCss(css, file, findings) {
  const withoutComments = String(css || '').replace(/\/\*[\s\S]*?\*\//g, '');
  for (const match of withoutComments.matchAll(/([^{}]+)\{([^{}]+)\}/g)) {
    const selector = String(match[1] || '').trim();
    const body = String(match[2] || '');
    if (!selector || selector.startsWith('@')) continue;
    const declarations = parseDeclarations(body);
    const colorValue = declarations.color || declarations.fill;
    const bgValue = declarations['background-color'] || declarations.background;
    if (colorValue && bgValue) {
      reviewColorPair(colorValue, bgValue, file, selector, findings);
    }
    if (colorValue && /gradient|image-set|url\(/i.test(String(bgValue || ''))) {
      add(findings, 'warn', 'accessibility_background_requires_manual_review', 'Text over image/gradient background needs an explicit audited solid fallback token.', file, selector);
    }
    const combined = `${selector}{${body}}`;
    if (PRIVATE_PROJECT_COLOR_VAR_RE.test(combined) && !KIWE_TOKEN_RE.test(combined)) {
      add(findings, 'warn', 'accessibility_private_color_variable_without_kiwe_pair', 'Private project color variable appears without a nearby Kiwe token pair; map it through the accessibility plan or Framework profile.', file, selector);
    }
  }
}

function reviewColorPair(colorValue, bgValue, file, selector, findings) {
  const fgColors = colorsInValue(colorValue);
  const bgColors = colorsInValue(bgValue);
  if (!fgColors.length || !bgColors.length) {
    if (/var\(/i.test(colorValue) || /var\(/i.test(bgValue) || /color-mix|oklch|hsl/i.test(`${colorValue} ${bgValue}`)) {
      return;
    }
    add(findings, 'warn', 'accessibility_unparsed_color_pair', `Could not parse color/background pair "${colorValue}" on "${bgValue}".`, file, selector);
    return;
  }
  for (const fg of fgColors) {
    for (const bg of bgColors) {
      if (fg[3] < 0.85 || bg[3] < 0.85) {
        add(findings, 'warn', 'accessibility_alpha_contrast_needs_manual_review', 'Semi-transparent text/background contrast needs manual proof against the real composed surface.', file, selector);
        continue;
      }
      const ratio = contrastRatio(fg, bg);
      if (ratio < DEFAULT_MIN_CONTRAST) {
        add(findings, 'fail', 'accessibility_low_contrast_literal_pair', `Literal text/background contrast is ${ratio.toFixed(2)}:1, below ${DEFAULT_MIN_CONTRAST}:1. Use readable foreground/on-surface tokens for both light and dark modes.`, file, selector);
      }
    }
  }
}

function colorsInValue(value) {
  const raw = String(value || '').trim().toLowerCase();
  if (!raw || raw.includes('var(') || raw.includes('color-mix(') || raw.includes('oklch(') || raw.includes('hsl(')) return [];
  const colors = [];
  for (const match of raw.matchAll(/#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/gi)) {
    const parsed = parseHex(match[0]);
    if (parsed) colors.push(parsed);
  }
  for (const match of raw.matchAll(/rgba?\(([^)]+)\)/gi)) {
    const parsed = parseRgb(match[1]);
    if (parsed) colors.push(parsed);
  }
  if (NAMED_COLORS.has(raw)) {
    const color = NAMED_COLORS.get(raw);
    if (color) colors.push(color);
  }
  return colors;
}

function firstColor(value) {
  return colorsInValue(value)[0] || null;
}

function parseHex(value) {
  const hex = String(value || '').replace('#', '').trim();
  if (![3, 4, 6, 8].includes(hex.length)) return null;
  const expand = (char) => char + char;
  const r = hex.length <= 4 ? parseInt(expand(hex[0]), 16) : parseInt(hex.slice(0, 2), 16);
  const g = hex.length <= 4 ? parseInt(expand(hex[1]), 16) : parseInt(hex.slice(2, 4), 16);
  const b = hex.length <= 4 ? parseInt(expand(hex[2]), 16) : parseInt(hex.slice(4, 6), 16);
  let a = 1;
  if (hex.length === 4) a = parseInt(expand(hex[3]), 16) / 255;
  if (hex.length === 8) a = parseInt(hex.slice(6, 8), 16) / 255;
  return [r, g, b, a];
}

function parseRgb(inner) {
  const text = String(inner || '').replace(/\s*\/\s*/, ',');
  const parts = text.split(/[,\s]+/).filter(Boolean);
  if (parts.length < 3) return null;
  const nums = parts.slice(0, 4).map((part, index) => {
    if (part.endsWith('%') && index < 3) return Math.round(255 * parseFloat(part) / 100);
    return parseFloat(part);
  });
  if (nums.slice(0, 3).some((n) => Number.isNaN(n))) return null;
  return [
    Math.max(0, Math.min(255, nums[0])),
    Math.max(0, Math.min(255, nums[1])),
    Math.max(0, Math.min(255, nums[2])),
    Number.isFinite(nums[3]) ? Math.max(0, Math.min(1, nums[3])) : 1
  ];
}

function luminance(color) {
  const [r, g, b] = color.slice(0, 3).map((value) => {
    const channel = value / 255;
    return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrastRatio(fg, bg) {
  const l1 = luminance(fg);
  const l2 = luminance(bg);
  const lighter = Math.max(l1, l2);
  const darker = Math.min(l1, l2);
  return (lighter + 0.05) / (darker + 0.05);
}

function summarize(files, findings) {
  const counts = { fail: 0, warn: 0, info: 0 };
  for (const finding of findings) {
    const level = finding.level || 'info';
    if (Object.prototype.hasOwnProperty.call(counts, level)) counts[level] += 1;
  }
  return {
    filesChecked: files.length,
    fail: counts.fail,
    warn: counts.warn,
    info: counts.info
  };
}

export function validateAccessibility(targetDir, options = {}) {
  const root = path.resolve(targetDir || '.');
  const findings = [];
  const planPath = findPlanPath(root);
  const files = collectFiles(root);
  const textByFile = [];
  let plan = null;

  if (planPath) {
    plan = parseJson(planPath, findings);
    if (plan) validatePlan(plan, planPath, findings);
  } else if (!options.optional) {
    add(findings, 'fail', 'accessibility_plan_missing', 'Expected accessibility/kiwe-accessibility-plan.json for /create or /audit /accessibility.', root);
  }

  for (const file of files) {
    const text = readTextIfExists(file);
    if (!text) continue;
    textByFile.push({ file, text });
    for (const block of extractCssBlocks(text, file)) {
      scanCss(block.css, file, findings);
    }
  }

  const allText = textByFile.map((item) => item.text).join('\n');
  const planModes = isPlainObject(plan) && Array.isArray(plan.modes) ? plan.modes.map((mode) => String(mode).toLowerCase()) : [];
  const hasDarkProof = DARK_PROOF_RE.test(allText) || planModes.includes('dark');
  if (!hasDarkProof) {
    add(findings, 'fail', 'accessibility_missing_dark_mode_proof', 'Output must prove native dark-mode support through data-kiwe-theme/data-theme/prefers-color-scheme and token pairs. Do not ship light-only pages.', root);
  }
  if (!BRICKS_THEME_STYLE_RE.test(allText)) {
    add(findings, 'warn', 'accessibility_missing_bricks_theme_style_alignment', 'No Bricks theme-style/color-palette alignment was found. If this output targets Bricks, map Kiwe tokens to Bricks root color/background/link lanes.', root);
  }
  if (!KIWE_TOKEN_RE.test(allText)) {
    add(findings, 'warn', 'accessibility_missing_kiwe_color_tokens', 'No Kiwe/Seam token variables were found near color usage. Prefer official --kiwe-color-* tokens over isolated project color systems.', root);
  }

  const publicFindings = findings.map((finding) => ({
    ...finding,
    file: finding.file ? rel(root, finding.file) || finding.file : ''
  }));
  const summary = summarize(files, publicFindings);

  return {
    ok: summary.fail === 0,
    schema: REPORT_SCHEMA,
    target: root,
    planPath: planPath ? rel(root, planPath) : '',
    summary,
    findings: publicFindings
  };
}
