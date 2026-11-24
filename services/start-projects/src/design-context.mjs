import { randomBytes, randomUUID } from 'node:crypto';
import { z } from 'zod';
import { parse, siteLinkInput, StartError } from './projects.mjs';
import { createWordPressLink } from './wordpress-link.mjs';

export const contextReturnPath = '/projects/context-return';
const scope = 'mcp.start.context';
const operation = 'mcp.start.context.change';
const flowLifetime = 10 * 60_000;
const tokenPattern = /^[A-Za-z0-9_-]{43}$/;
const codePattern = /^kiwe_mcp_code_c_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/;
const expectedPattern = /^[a-f0-9]{64}$/;

export const contextArea = z.enum(['identity', 'story', 'contact']);
export const contextInput = siteLinkInput.extend({ area: contextArea });
export const contextEditInput = contextInput.extend({
  field: z.string().min(1).max(160),
  expected: z.string().regex(expectedPattern),
  value: z.string().max(2000),
});
export const contextReviewInput = contextEditInput.extend({ reviewId: z.uuid() });
const fieldOutput = z.strictObject({
  path: z.string(), label: z.string(), type: z.enum(['text', 'textarea', 'email', 'tel', 'select']),
  value: z.string(), maxLength: z.number().int().positive(),
  choices: z.array(z.strictObject({ value: z.string(), label: z.string() })),
});
const sectionOutput = z.strictObject({
  area: contextArea, expected: z.string().regex(expectedPattern), fields: z.array(fieldOutput).max(8),
});
export const contextOutput = z.strictObject({
  projectId: z.uuid(), website: z.url(), nativeUrl: z.url(),
  status: z.enum(['needs_authorization', 'ready']),
  section: sectionOutput.nullable(),
});
export const contextProposalOutput = z.strictObject({ reviewUrl: z.url(), expiresAt: z.string() });

const fieldsByArea = {
  identity: new Set(['siteName', 'tagline', 'industry', 'siteType']),
  story: new Set(['identity.description', 'audience.primary', 'seo.primaryGoal', 'about.usp']),
  contact: new Set(['contact.phone', 'contact.email']),
};

function contextError() {
  return new StartError(409, 'native_context_required', 'Open WordPress authorization for this website to continue.');
}

function changedContext() {
  return new StartError(409, 'context_changed', 'The website could not confirm this change. Review its current value before saving again.');
}

function reviewExpired() {
  return new StartError(410, 'context_review_expired', 'This review expired. Open Design Context and prepare a fresh change.');
}

function fieldProjection(area, item) {
  if (!fieldsByArea[area].has(item.path) || item.needsNativeRead) return null;
  const definition = JSON.parse(item.definitionJson);
  const value = JSON.parse(item.valueJson);
  const nativeChoices = definition.options;
  let choices = [];
  if (Array.isArray(nativeChoices)) {
    choices = nativeChoices.filter(option => typeof option === 'string').map(option => ({
      value: option, label: option ? option[0].toUpperCase() + option.slice(1) : 'Choose an option',
    }));
  } else if (nativeChoices && typeof nativeChoices === 'object') {
    choices = Object.entries(nativeChoices).filter(([, label]) => typeof label === 'string').map(([option, label]) => ({ value: option, label }));
  }
  const nativeType = definition.type === 'phone' ? 'tel' : definition.type;
  const type = choices.length ? 'select' : ['textarea', 'tel', 'email'].includes(nativeType) ? nativeType : 'text';
  return fieldOutput.parse({
    path: item.path,
    label: item.label,
    value: typeof value === 'string' ? value : '',
    type,
    maxLength: Math.min(2000, Number(definition.maxLength) > 0 ? Number(definition.maxLength) : 2000),
    choices,
  });
}

export function createDesignContext(projects, startOrigin, wordpress = createWordPressLink(), now = Date.now) {
  const flows = new Map();
  const grants = new Map();
  const reviews = new Map();
  const opening = new Map();
  const redirect = startOrigin + contextReturnPath;
  const nativeUrl = (website) => website + '/wp-admin/admin.php?page=kiwe-design-context';

  function target(accountId, details) {
    const { project, binding, ready } = projects.designContextAccess(accountId, details);
    if (project.revision !== details.revision) {
      throw new StartError(409, 'project_changed', 'Open the updated project before editing Design Context.');
    }
    if (details.binding && details.binding !== binding) throw contextError();
    if (!ready) {
      throw new StartError(409, 'context_setup_required', 'Connect the WordPress site and verify Key first.');
    }
    return { ...project, binding };
  }

  function remember(map, key, value) {
    for (const [id, record] of map) if (record.expiresAt <= now()) map.delete(id);
    map.set(key, value);
  }

  function activeGrant(project) {
    const grant = grants.get(project.binding);
    if (!grant || grant.expiresAt <= now() || grant.connection.origin !== project.site.url) {
      if (grant) grants.delete(project.binding);
      return null;
    }
    return grant;
  }

  function base(project) {
    return { projectId: project.id, website: project.site.url, nativeUrl: nativeUrl(project.site.url) };
  }

  async function section(grant, area) {
    try {
      const native = await wordpress.call(grant.connection, grant.token, 'kiwe-mcp-read', {
        operation: 'mcp.start.context.read', arguments: { area },
      });
      if (native?.schema !== 'kiwe.mcp-setup.v1' || native.area !== area || !Array.isArray(native.fields) || native.fields.length > 8) {
        throw contextError();
      }
      return sectionOutput.parse({
        area, expected: native.expected, fields: native.fields.map(item => fieldProjection(area, item)).filter(Boolean),
      });
    } catch {
      throw new StartError(409, 'native_context_unavailable', 'WordPress could not open these fields. Reopen authorization or use Design Context in WordPress.');
    }
  }

  async function editable(project, args) {
    const grant = activeGrant(project);
    if (!grant) throw contextError();
    const { area, field, expected, value } = args;
    const current = await section(grant, area);
    const rule = current.fields.find(item => item.path === field);
    if (current.expected !== expected || !rule) throw changedContext();
    if (value.length > rule.maxLength || (rule.choices.length && !rule.choices.some(choice => choice.value === value))) {
      throw new StartError(400, 'invalid_context_value', 'Use a value accepted by this Design Context field.');
    }
    return { grant, rule };
  }

  async function apply(accountId, project, args, requestId) {
    const { grant, rule } = await editable(project, args);
    const { area, field, expected, value } = args;
    if (rule.value === value) return;
    try {
      const access = { ...args, binding: project.binding };
      target(accountId, access);
      const prepared = await wordpress.call(grant.connection, grant.token, 'kiwe-mcp-prepare-change', {
        operation, requestId, arguments: { area, field, expected, value },
      });
      if (!/^i_[a-f0-9]{32}$/.test(prepared?.id ?? '') || prepared.operation !== operation || prepared.approvalRequired) throw changedContext();
      target(accountId, access);
      const saved = await wordpress.call(grant.connection, grant.token, 'kiwe-mcp-apply-change', { intent: prepared.id });
      if (saved?.id !== prepared.id || saved?.state !== 'complete' || saved.operation !== operation) throw changedContext();
    } catch {
      throw changedContext();
    }
  }

  function ownedReview(accountId, projectId, reviewId) {
    const review = reviews.get(reviewId);
    if (!review || review.accountId !== accountId || review.projectId !== projectId || review.expiresAt <= now()) throw reviewExpired();
    target(accountId, review);
    return review;
  }

  return {
    async status(accountId, input) {
      const details = parse(contextInput, input);
      const project = target(accountId, details);
      const grant = activeGrant(project);
      if (!grant) return { ...base(project), status: 'needs_authorization', section: null };
      const current = await section(grant, details.area);
      target(accountId, { ...details, binding: project.binding });
      return { ...base(project), status: 'ready', section: current };
    },

    async begin(accountId, input) {
      const details = parse(siteLinkInput, input);
      const project = target(accountId, details);
      const { binding } = project;
      const existing = [...flows.values()].find(flow => flow.binding === binding && flow.expiresAt > now());
      if (existing) return existing.connection.authorizationUrl;
      if (opening.has(binding)) return opening.get(binding);
      const pending = (async () => {
        const state = randomBytes(32).toString('base64url');
        const connection = await wordpress.begin(
          new URL(project.site.url).hostname, redirect, state, randomBytes(32).toString('base64url'), scope, 'Kiwe Start Design Context',
        );
        target(accountId, { ...details, binding });
        if (connection.origin !== project.site.url) throw contextError();
        remember(flows, state, { ...details, binding, accountId, connection, expiresAt: now() + flowLifetime });
        return connection.authorizationUrl;
      })();
      opening.set(binding, pending);
      try { return await pending; } finally { opening.delete(binding); }
    },

    async complete({ state, iss, code, error }) {
      const flow = tokenPattern.test(state ?? '') ? flows.get(state) : null;
      if (!flow || flow.expiresAt <= now() || flow.connection.issuer !== iss) throw contextError();
      flows.delete(state);
      if (error || !codePattern.test(code ?? '')) throw contextError();
      const project = target(flow.accountId, flow);
      const { connection } = flow;
      let grant;
      try {
        grant = await wordpress.exchange(connection, code, scope);
      } catch (error) {
        if (error instanceof StartError) throw error;
        throw contextError();
      }
      const ttl = Math.min(900, Number(grant.expiresIn));
      if (!Number.isFinite(ttl) || ttl <= 0) {
        await wordpress.revoke(connection, grant.token);
        throw contextError();
      }
      try {
        await section({ connection, token: grant.token }, 'identity');
        target(flow.accountId, flow);
      } catch (error) {
        await wordpress.revoke(connection, grant.token);
        throw error;
      }
      const previous = activeGrant(project);
      remember(grants, project.binding, { connection, token: grant.token, expiresAt: now() + ttl * 1000 });
      if (previous) await wordpress.revoke(previous.connection, previous.token);
      return { accountId: flow.accountId, projectId: project.id };
    },

    async save(accountId, input) {
      const details = parse(contextReviewInput, input);
      await apply(accountId, target(accountId, details), details, details.reviewId);
    },

    async propose(accountId, input) {
      const details = parse(contextEditInput, input);
      const project = target(accountId, details);
      const { rule } = await editable(project, details);
      target(accountId, { ...details, binding: project.binding });
      const reviewId = randomUUID();
      const expiresAt = now() + flowLifetime;
      remember(reviews, reviewId, { ...details, binding: project.binding, accountId, expiresAt, label: rule.label, before: rule.value });
      return { reviewUrl: startOrigin + '/projects/' + project.id + '/context/review/' + reviewId, expiresAt: new Date(expiresAt).toISOString() };
    },

    review(accountId, projectId, reviewId) {
      return ownedReview(accountId, projectId, reviewId);
    },

    async confirm(accountId, projectId, reviewId) {
      const review = ownedReview(accountId, projectId, reviewId);
      await apply(accountId, target(accountId, review), review, reviewId);
      reviews.delete(reviewId);
      return review.area;
    },

    async close(accountId, projectId) {
      const { project, binding } = projects.designContextAccess(accountId, { projectId });
      if (!project.site) return;
      const grant = activeGrant({ ...project, binding });
      grants.delete(binding);
      if (grant) await wordpress.revoke(grant.connection, grant.token);
    },
  };
}
