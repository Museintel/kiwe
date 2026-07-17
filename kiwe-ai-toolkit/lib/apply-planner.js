import fs from 'node:fs';
import path from 'node:path';
import { validateBindings } from './binding-validator.js';

function isPlainObject(value) {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function readJson(file, findings, label) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    findings.push({
      level: 'fail',
      message: `${label} is not valid JSON: ${error && error.message ? error.message : String(error)}`,
      file
    });
    return null;
  }
}

function findBindingPath(target) {
  const resolved = path.resolve(target || '.');
  if (fs.existsSync(resolved) && fs.statSync(resolved).isFile()) {
    return {
      root: path.dirname(resolved),
      bindingPath: resolved
    };
  }

  const nested = path.join(resolved, 'bricks-bindings', 'kiwe-bindings.json');
  if (fs.existsSync(nested)) {
    return {
      root: resolved,
      bindingPath: nested
    };
  }

  const direct = path.join(resolved, 'kiwe-bindings.json');
  if (fs.existsSync(direct)) {
    return {
      root: resolved,
      bindingPath: direct
    };
  }

  return {
    root: resolved,
    bindingPath: ''
  };
}

function siteCapabilities(siteGraph) {
  const bricks = isPlainObject(siteGraph.bricks) ? siteGraph.bricks : {};
  const abilities = isPlainObject(bricks.abilities) ? bricks.abilities : {};
  const conversion = isPlainObject(bricks.conversion) ? bricks.conversion : {};

  return {
    wordpressAbilitiesApiPresent: Boolean(abilities.wpAbilitiesApiPresent),
    bricksAbilityManager: Boolean(abilities.bricksAbilityManager),
    bricksMcpLikelyAvailable: Boolean(abilities.mcpLikelyAvailable),
    htmlCssToBricksAvailable: Boolean(conversion.htmlCssToBricksAvailable),
    bricksActive: Boolean(bricks.active),
    bricksVersion: String(bricks.version || ''),
    preferredWorkflow: String(conversion.preferredWorkflow || 'manual Bricks builder review/import'),
    trustedAdapterLikelyAvailable: Boolean(abilities.mcpLikelyAvailable || (abilities.wpAbilitiesApiPresent && abilities.bricksAbilityManager)),
    manualBuilderFallback: true
  };
}

function applyStatus(capabilities, needsBricks = true) {
  if (needsBricks && !capabilities.bricksActive) return 'manual-review';
  if (capabilities.trustedAdapterLikelyAvailable) return 'ready-for-trusted-adapter-review';
  return 'ready-for-manual-bricks-review';
}

function bindingSummary(binding) {
  return {
    queries: asArray(binding.queries).length,
    dynamicFields: asArray(binding.dynamicFields).length,
    launchers: asArray(binding.launchers).length,
    menuContext: asArray(binding.menuContext).length,
    assumptions: asArray(binding.assumptions).length,
    requiresHumanReview: asArray(binding.requiresHumanReview).length
  };
}

function operationId(prefix, sourceId, index) {
  const clean = String(sourceId || `${prefix}-${index + 1}`).replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase();
  return `${prefix}:${clean || index + 1}`;
}

function queryOperation(query, index, capabilities) {
  const id = String(query.id || `query-${index + 1}`);
  return {
    id: operationId('query', id, index),
    type: 'bricks.query-loop',
    sourceId: id,
    selector: String(query.selector || ''),
    label: String(query.label || id),
    status: applyStatus(capabilities),
    authority: 'bricks-query-loop',
    description: 'Configure a Bricks query loop for the matching static preview region. Do not execute custom SQL/PHP from the binding plan.',
    bricks: isPlainObject(query.bricks) ? query.bricks : {},
    bindings: isPlainObject(query.bindings) ? query.bindings : {},
    adapterSteps: [
      'Locate the matching Bricks element/region after HTML/CSS import.',
      'Create or update a query loop using the provided Bricks settings.',
      'Map dynamic data tags to child elements inside the loop.',
      'Preview rendered output before save.'
    ],
    reviewRequired: []
  };
}

function dynamicFieldOperation(field, index, capabilities) {
  return {
    id: operationId('dynamic-field', `${field.selector || 'field'}-${field.field || index + 1}`, index),
    type: 'bricks.dynamic-field',
    selector: String(field.selector || ''),
    field: String(field.field || ''),
    tag: String(field.tag || ''),
    status: applyStatus(capabilities),
    authority: 'bricks-dynamic-data',
    description: 'Replace static preview content with a Bricks dynamic data tag after import.',
    adapterSteps: [
      'Locate the selected element in the imported Bricks tree.',
      'Apply the dynamic tag to the intended field/control.',
      'Preview the resolved value with Bricks dynamic data preview.'
    ],
    reviewRequired: []
  };
}

function launcherOperation(launcher, index) {
  return {
    id: operationId('launcher', `${launcher.value || 'module'}-${index + 1}`, index),
    type: 'kiwe.launcher-attribute',
    selector: String(launcher.selector || ''),
    attribute: 'data-dsa-open-module',
    value: String(launcher.value || ''),
    status: 'ready-existing-kiwe-runtime',
    authority: 'kiwe-appshell',
    description: 'Keep or apply the canonical Kiwe launcher attribute. Kiwe owns the AppShell open/close runtime.',
    adapterSteps: [
      'Ensure the page element has data-dsa-open-module with the declared value.',
      'Do not add custom JavaScript for opening Kiwe surfaces.',
      'Smoke-test click and keyboard activation after import.'
    ],
    reviewRequired: []
  };
}

function menuContextOperation(item, index) {
  return {
    id: operationId('menu-context', item.id || item.label || index + 1, index),
    type: 'kiwe.menu-context',
    label: String(item.label || ''),
    selector: String(item.selector || (item.id ? `#${item.id}` : '')),
    source: String(item.source || 'visible-section'),
    status: 'ready-existing-kiwe-runtime',
    authority: 'kiwe-menu-context',
    description: 'Expose a visible page section/heading/Seam semantic region to Kiwe Menu context. Do not create hidden duplicate anchors.',
    adapterSteps: [
      'Preserve visible section IDs/headings/semantic attributes during Bricks import.',
      'Confirm Kiwe Menu context scrolls to the live page section.',
      'If the label is not visible or semantic on the page, move it to human review.'
    ],
    reviewRequired: []
  };
}

function buildPreflight(capabilities, validation) {
  return [
    {
      id: 'validate-bindings',
      label: 'Binding plan validator',
      status: validation.ok ? 'passed' : 'blocked',
      required: true,
      details: 'Run validate-bindings against the target Site Graph before preparing any apply step.'
    },
    {
      id: 'capture-revision',
      label: 'Capture Bricks/WordPress revision',
      status: 'required-before-mutation',
      required: true,
      details: 'A future adapter must create or verify a rollback point before it saves any builder data.'
    },
    {
      id: 'html-css-to-bricks',
      label: 'HTML/CSS to Bricks conversion path',
      status: capabilities.htmlCssToBricksAvailable ? 'available' : 'manual-review',
      required: false,
      details: capabilities.preferredWorkflow
    },
    {
      id: 'trusted-abilities',
      label: 'Trusted Bricks/WP abilities path',
      status: capabilities.trustedAdapterLikelyAvailable ? 'available' : 'manual-builder-fallback',
      required: false,
      details: 'Use Bricks/WP abilities only through an admin-approved Kiwe adapter. Browser AI output alone cannot save builder state.'
    },
    {
      id: 'post-apply-audit',
      label: 'Post-apply visual and authority audit',
      status: 'required-after-mutation',
      required: true,
      details: 'After any future apply step, rerun Kiwe output/binding audit and a browser smoke test before publishing.'
    }
  ];
}

function manualReviewItems(binding, validation) {
  const review = asArray(binding.requiresHumanReview).map((item, index) => ({
    id: operationId('review', `binding-${index + 1}`, index),
    source: 'binding-plan',
    item
  }));

  for (const finding of asArray(validation.findings)) {
    if (finding.level === 'warn') {
      review.push({
        id: operationId('review', `validator-${review.length + 1}`, review.length),
        source: 'validate-bindings',
        item: finding.message,
        file: finding.file || ''
      });
    }
  }

  return review;
}

function buildPlan({ root, bindingPath, siteGraphPath, binding, siteGraph, validation }) {
  const capabilities = siteCapabilities(siteGraph);
  const operations = [
    ...asArray(binding.queries).map((query, index) => queryOperation(query, index, capabilities)),
    ...asArray(binding.dynamicFields).map((field, index) => dynamicFieldOperation(field, index, capabilities)),
    ...asArray(binding.launchers).map((launcher, index) => launcherOperation(launcher, index)),
    ...asArray(binding.menuContext).map((item, index) => menuContextOperation(item, index))
  ];

  return {
    schema: 'kiwe.bricks-apply-plan.v1',
    sourceBindingSchema: 'kiwe.bricks-bindings.v1',
    siteGraphSchema: 'kiwe.site-graph.v1',
    target: {
      builder: 'bricks',
      mode: 'dry-run-apply-plan',
      applyAuthority: 'admin-approved-kiwe-bricks-adapter',
      mutatesWordPress: false
    },
    handoff: {
      root,
      bindingPath,
      siteGraphPath
    },
    siteCapabilities: capabilities,
    safety: {
      dryRunOnly: true,
      mutatesWordPress: false,
      requiresAdminApprovalBeforeSave: true,
      requiresRevisionBeforeSave: true,
      forbidden: [
        'direct-ai-write',
        'publish-without-admin-approval',
        'custom-cart-runtime',
        'custom-checkout-runtime',
        'custom-auth-runtime',
        'custom-search-runtime',
        'payment-code',
        'service-worker-code'
      ]
    },
    bindingSummary: bindingSummary(binding),
    preflight: buildPreflight(capabilities, validation),
    operations,
    manualReview: manualReviewItems(binding, validation),
    applySequence: [
      'validate-bindings',
      'capture-revision',
      'import-or-convert-html-css-to-bricks',
      'apply-query-loop-settings',
      'apply-dynamic-tags',
      'preserve-kiwe-launchers-and-menu-context',
      'preview-rendered-output',
      'admin-approve-save',
      'post-apply-audit'
    ],
    limitations: [
      'This plan is not an executable mutation.',
      'A future trusted adapter must map selectors to real Bricks element IDs after import/conversion.',
      'Browser AI output must not claim WordPress, WooCommerce, Bricks, or Kiwe state was changed.'
    ]
  };
}

function writePlan(root, plan) {
  const outDir = path.join(root, 'bricks-apply');
  fs.mkdirSync(outDir, { recursive: true });
  const planPath = path.join(outDir, 'kiwe-apply-plan.json');
  const notesPath = path.join(outDir, 'APPLY-NOTES.md');
  fs.writeFileSync(planPath, `${JSON.stringify(plan, null, 2)}\n`, 'utf8');
  fs.writeFileSync(notesPath, `# Kiwe Bricks apply notes

This folder is a dry-run apply plan. It does not prove that WordPress, Bricks, WooCommerce, or Kiwe were mutated.

## Authority

- Plan schema: \`${plan.schema}\`
- Apply mode: \`${plan.target.mode}\`
- Mutates WordPress: \`${String(plan.target.mutatesWordPress)}\`
- Required authority: \`${plan.target.applyAuthority}\`

## Required sequence

${plan.applySequence.map((step, index) => `${index + 1}. ${step}`).join('\n')}

## Operations

${plan.operations.map((operation) => `- \`${operation.id}\` — ${operation.type} — ${operation.status}`).join('\n')}

## Manual review

${plan.manualReview.length ? plan.manualReview.map((item) => `- ${typeof item.item === 'string' ? item.item : JSON.stringify(item.item)}`).join('\n') : '- No manual-review items were reported by the binding plan or validator.'}
`, 'utf8');

  return { planPath, notesPath };
}

export function prepareApplyPlan(target = '.', options = {}) {
  const findings = [];
  const located = findBindingPath(target);
  const root = located.root;
  const siteGraphPath = options.siteGraphPath ? path.resolve(options.siteGraphPath) : '';

  if (!located.bindingPath) {
    findings.push({ level: 'fail', message: 'No bricks-bindings/kiwe-bindings.json file found.', file: '' });
  }
  if (!siteGraphPath) {
    findings.push({ level: 'fail', message: 'prepare-apply-plan requires --site-graph so the apply path is tied to a real target site.', file: '' });
  } else if (!fs.existsSync(siteGraphPath)) {
    findings.push({ level: 'fail', message: `Site Graph file not found: ${siteGraphPath}`, file: '' });
  }

  if (findings.some((finding) => finding.level === 'fail')) {
    return result(false, root, null, findings, null, null);
  }

  const validation = validateBindings(root, { siteGraphPath });
  const binding = readJson(located.bindingPath, findings, 'kiwe-bindings.json');
  const siteGraph = readJson(siteGraphPath, findings, 'Site Graph');

  if (!validation.ok) {
    findings.push({ level: 'fail', message: 'Binding validation failed; prepare-apply-plan cannot continue.', file: located.bindingPath });
  }
  if (siteGraph && siteGraph.schema !== 'kiwe.site-graph.v1') {
    findings.push({ level: 'fail', message: 'Site Graph schema must be kiwe.site-graph.v1.', file: siteGraphPath });
  }
  if (binding && binding.schema !== 'kiwe.bricks-bindings.v1') {
    findings.push({ level: 'fail', message: 'Binding schema must be kiwe.bricks-bindings.v1.', file: located.bindingPath });
  }

  if (findings.some((finding) => finding.level === 'fail') || !binding || !siteGraph) {
    return result(false, root, null, findings, validation, null);
  }

  const plan = buildPlan({
    root,
    bindingPath: located.bindingPath,
    siteGraphPath,
    binding,
    siteGraph,
    validation
  });

  let written = null;
  if (options.write) {
    written = writePlan(root, plan);
  }

  return result(true, root, plan, findings, validation, written);
}

function result(ok, root, plan, findings, bindingValidation, written) {
  const counts = findings.reduce((acc, finding) => {
    acc[finding.level] = (acc[finding.level] || 0) + 1;
    return acc;
  }, {});

  return {
    ok,
    root,
    plan,
    bindingValidation,
    written,
    counts,
    findings
  };
}
