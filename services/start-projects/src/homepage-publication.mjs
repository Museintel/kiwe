import { randomBytes } from 'node:crypto';
import { z } from 'zod';
import { bricksInput, nativeApproval, nativeIntent } from './bricks-import.mjs';
import { parse, StartError } from './projects.mjs';
import { createWordPressLink } from './wordpress-link.mjs';
import { siteUrl } from './site-network.mjs';

export const publicationReturn = '/projects/homepage-publish-return';
export const publicationInput = bricksInput.extend({ action: z.enum(['publish','restore']) });
const scopeFor = (target) => target && target !== 'home' ? 'mcp.bricks.document' : 'mcp.bricks.homepage';
const codePattern = /^kiwe_mcp_code_c_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/;
const expiresIn = 900_000;
const opaque = () => randomBytes(32).toString('base64url');
const error = () => new StartError(409, 'homepage_publication_review', 'WordPress could not confirm this publication. Check the assigned route and use its current owner review.');

export function createHomepagePublication(projects, startOrigin, wordpress = createWordPressLink(), now = Date.now) {
  const pending = new Map();
  const grants = new Map();
  const working = new Map();

  function target(accountId, { projectId, revision, draftId, target: documentId }) {
    const project = projects.getProject(accountId, { projectId });
    if (revision !== undefined && project.revision !== revision) throw error();
    const imported = projects.readHomepageImport(accountId, projectId, draftId, documentId);
    if (!imported || imported.status !== 'draft_ready' || !imported.receipt?.draftId) throw error();
    const draft = projects.getHomepage(accountId, projectId, draftId, documentId);
    const placement = draft.document?.item;
    return { project, imported, args: {
      job: imported.receipt.job, archiveSha256: imported.archive_sha, draftId: imported.receipt.draftId,
      ...(placement ? { target: placement.id, pageId: placement.pageId } : {}),
    } };
  }

  function checkedReceipt(raw, { args, project }) {
    let value;
    try { value = JSON.parse(raw?.resultJson); } catch { throw error(); }
    const expected = args.target ? 'kiwe.bricks-document-publication.v1' : 'kiwe.bricks-homepage-publication.v1';
    const publishedId = args.target ? value?.placementId : value?.pageId;
    if (value?.schema !== expected || Object.keys(args).some(key => value[key] !== args[key])
        || (!args.target && value.url !== project.site.url + '/')
        || !Number.isSafeInteger(publishedId) || publishedId < (value.status === 'published' ? 1 : 0)
        || !['draft_ready','published','restored','restore_in_progress','review_current_homepage','review_current_document'].includes(value.status)) throw error();
    let publicUrl = null;
    if (value.url !== null) {
      try { publicUrl = siteUrl(project.site.url, value.url).href; } catch { throw error(); }
    }
    let blogUrl;
    if (value.blogUrl !== undefined) {
      try { blogUrl = siteUrl(project.site.url, value.blogUrl).href; } catch { throw error(); }
    }
    return { pageId: publishedId, url: publicUrl, status: value.status, ...(blogUrl ? { blogUrl } : {}),
      ...(args.target ? { importCleanupPending: value.importCleanupPending === true } : {}) };
  }

  function currentGrant(id) {
    const grant = grants.get(id);
    if (grant && grant.expiresAt > now()) return grant;
    grants.delete(id);
    return null;
  }

  function view(accountId, details) {
    const { imported } = target(accountId, details);
    const publication = projects.getHomepagePublication(accountId, details);
    if (!publication) return null;
    const session = currentGrant(imported.id);
    return { ...publication, approvalUrl: session ? publication.approvalUrl : null, sessionActive: Boolean(session) };
  }

  async function finish(accountId, details, grant) {
    const site = target(accountId, details);
    const prior = projects.getHomepagePublication(accountId, details);
    if (prior?.status !== 'approval_required' || prior.action !== details.action || !prior.intent) throw error();
    const op = scopeFor(details.target) + '.' + details.action;
    const call = (name, args) => wordpress.call(grant.connection, grant.token, 'kiwe-mcp-' + name, args);
    const raw = await call('change-status', { intent: prior.intent });
    if (raw?.id === prior.intent && raw.operation === op && ['cancelled','needs-review','failed'].includes(raw.state)) {
      grants.delete(site.imported.id);
      await wordpress.revoke(grant.connection, grant.token);
      projects.saveHomepagePublication(accountId, details, { ...prior, status: 'review_again', approvalUrl: null });
      throw error();
    }
    let result = nativeIntent(raw, op, prior.intent);
    if (result.state === 'prepared') {
      if (!result.approved) return view(accountId, details);
      target(accountId, details);
      result = nativeIntent(await call('apply-change', { intent: prior.intent }), op, prior.intent);
    }
    const receipt = checkedReceipt(result.result, site);
    if (result.state !== 'complete' || receipt.status !== (details.action === 'publish' ? 'published' : 'restored')) throw error();
    projects.saveHomepagePublication(accountId, details, receipt);
    grants.delete(site.imported.id);
    await wordpress.revoke(grant.connection, grant.token);
    return view(accountId, details);
  }

  return {
    status(accountId, projectId, draftId, documentId) { return view(accountId, { projectId, draftId, target: documentId }); },

    async begin(accountId, input) {
      const details = parse(publicationInput, input);
      const { project, imported } = target(accountId, details);
      const saved = projects.getHomepagePublication(accountId, details);
      if (saved?.status === 'published' && details.action === 'publish') {
        return { authorizationUrl: null, publication: view(accountId, details) };
      }
      if (details.action === 'restore' && saved?.status !== 'published' && saved?.action !== 'restore') throw error();
      if (currentGrant(imported.id)) {
        if (saved?.action !== details.action) throw error();
        return { authorizationUrl: null, publication: view(accountId, details) };
      }
      for (const [state, flow] of pending) {
        if (flow.expiresAt <= now()) pending.delete(state);
        else if (flow.id === imported.id && flow.action === details.action) return { authorizationUrl: flow.connection.authorizationUrl };
      }
      const state = opaque();
      const connection = await wordpress.begin(project.domain, startOrigin + publicationReturn, state, opaque(), scopeFor(details.target), 'Kiwe Start publication');
      if (connection.origin !== project.site.url) throw error();
      target(accountId, details);
      pending.set(state, { ...details, accountId, id: imported.id, connection, expiresAt: now() + 600_000 });
      return { authorizationUrl: connection.authorizationUrl };
    },

    async complete(parameters) {
      const { state, iss, code, error: denial } = parameters;
      const flow = /^[A-Za-z0-9_-]{43}$/.test(state ?? '') ? pending.get(state) : null;
      if (!flow || flow.expiresAt <= now() || flow.connection.issuer !== iss) throw error();
      pending.delete(state);
      if (denial || !codePattern.test(code ?? '')) throw error();
      const site = target(flow.accountId, flow);
      if (site.imported.id !== flow.id || currentGrant(flow.id) || working.has(flow.id)) throw error();

      const prepare = async () => {
        const scope = scopeFor(flow.target);
        let authorization;
        let keep = false;
        try {
          authorization = await wordpress.exchange(flow.connection, code, scope);
          const ttl = Math.min(expiresIn, Number(authorization.expiresIn) * 1000);
          if (!Number.isFinite(ttl) || ttl <= 0) throw error();
          const { token } = authorization;
          const call = (name, args) => wordpress.call(flow.connection, token, 'kiwe-mcp-' + name, args);
          const read = checkedReceipt(await call('read', { operation: scope + '.status', arguments: site.args }), site);
          if (['review_current_homepage','review_current_document'].includes(read.status)) throw error();
          if ((flow.action === 'publish' && read.status === 'published')
              || (flow.action === 'restore' && read.status === 'restored')) {
            projects.saveHomepagePublication(flow.accountId, flow, read);
            return;
          }
          if (flow.action === 'restore' && !['published','restore_in_progress'].includes(read.status)) throw error();
          const operation = scope + '.' + flow.action;
          const intent = nativeIntent(await call('prepare-change', { operation, arguments: site.args, requestId: opaque() }), operation);
          if (intent.state !== 'prepared' || intent.approvalRequired !== true || intent.approved) throw error();
          const approvalUrl = nativeApproval(intent.approvalUrl, site.project.site.url, intent.id);
          projects.saveHomepagePublication(flow.accountId, flow, { ...read, action: flow.action,
            status: 'approval_required', intent: intent.id, approvalUrl });
          grants.set(flow.id, { token, connection: flow.connection, expiresAt: now() + ttl });
          keep = true;
        } catch (cause) {
          if (cause instanceof StartError) throw cause;
          throw error();
        } finally {
          if (authorization && !keep) await wordpress.revoke(flow.connection, authorization.token);
        }
      };

      const work = prepare();
      working.set(flow.id, work);
      try { await work; } finally { working.delete(flow.id); }
      return { accountId: flow.accountId, projectId: flow.projectId, target: flow.target };
    },

    async resume(accountId, input) {
      const details = parse(publicationInput, input);
      const { imported } = target(accountId, details);
      const current = view(accountId, details);
      if (current?.status !== 'approval_required') return current;
      if (current.action !== details.action) throw error();
      const grant = currentGrant(imported.id);
      if (!grant) return current;
      let work = working.get(imported.id);
      if (!work) {
        work = finish(accountId, details, grant).finally(() => working.delete(imported.id));
        working.set(imported.id, work);
      }
      return work;
    },
  };
}
