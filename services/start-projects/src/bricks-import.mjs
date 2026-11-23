import { createHash, randomBytes } from 'node:crypto';
import { z } from 'zod';
import { claimInput, parse, siteLinkInput, StartError } from './projects.mjs';
import { requestSite, siteUrl } from './site-network.mjs';
import { createWordPressLink } from './wordpress-link.mjs';
import { seamSource } from './homepage-source.mjs';

export const bricksReturn = '/projects/bricks-return';
export const bricksInput = siteLinkInput.extend({ draftId: z.uuid() });
const scope = 'mcp.bricks.import';
const opaque = () => randomBytes(32).toString('base64url');
const intentPattern = /^i_[a-f0-9]{32}$/;
const approvalCode = /^kiwe_mcp_code_c_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/;
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const phases = ['media', 'styles', 'templates'];
const unavailable = () => new StartError(409, 'bricks_connection_required', 'Open WordPress authorization for this homepage draft.');
const failed = () => new StartError(409, 'bricks_review_required', 'WordPress could not finish this step. Open the saved native review or check the current import again.');

function uploadUrl(connection) {
  const url = new URL(connection.resource);
  const restRoute = '/kiwe-mcp/v1/mcp';
  if (url.searchParams.get('rest_route') === restRoute && url.searchParams.size === 1) {
    url.searchParams.set('rest_route', '/kiwe-mcp/v1/bricks/package');
  } else if (!url.search && url.pathname.endsWith(restRoute)) {
    url.pathname = url.pathname.slice(0, -3) + 'bricks/package';
  } else throw unavailable();
  return siteUrl(connection.origin, url.href);
}

export function createBricksTarget(wordpress = createWordPressLink(), send = requestSite) {
  return {
    async upload(connection, token, intent, bytes) {
      const boundary = 'kiwe-' + randomBytes(18).toString('hex');
      const body = Buffer.concat([
        Buffer.from('--' + boundary + '\r\nContent-Disposition: form-data; name="package"; filename="seam-framework.zip"\r\nContent-Type: application/zip\r\n\r\n'),
        bytes, Buffer.from('\r\n--' + boundary + '--\r\n'),
      ]);
      const response = await send(connection.origin, uploadUrl(connection).href, {
        method: 'POST', timeout: 60000, headers: {
          'Content-Type': 'multipart/form-data; boundary=' + boundary,
          'X-Kiwe-Intent': intent, Authorization: 'Bearer ' + token,
        }, body,
      });
      return JSON.parse(response.body);
    },
    call: (connection, token, name, args) => wordpress.call(connection, token, name, args),
    begin: (...args) => wordpress.begin(...args),
    exchange: (...args) => wordpress.exchange(...args),
    revoke: (...args) => wordpress.revoke(...args),
  };
}

function nativeIntent(value, operation, id) {
  if (!intentPattern.test(value?.id ?? '') || (id && value.id !== id) || value.operation !== operation
      || !['prepared','complete'].includes(value.state)) throw failed();
  return value;
}

function nativeJob(raw, origin, archive, jobId) {
  let receipt;
  try { receipt = JSON.parse(raw?.resultJson); } catch { throw failed(); }
  if (receipt?.schema !== 'kiwe.mcp-bricks-import.v1' || !/^[a-f0-9]{32}$/.test(receipt.job || '')
      || (jobId && receipt.job !== jobId) || receipt.archiveSha256 !== archive || receipt.publishesWebsite !== false
      || !Number.isSafeInteger(receipt.revision) || receipt.revision < 1 || receipt.templateTotal !== 1
      || !Number.isSafeInteger(receipt.templatesDone) || receipt.templatesDone < 0 || receipt.templatesDone > 1
      || !Number.isSafeInteger(receipt.blockerCount) || receipt.blockerCount < 0) throw failed();
  const url = new URL(receipt.nativeReviewUrl);
  if (url.username || url.password) throw failed();
  siteUrl(origin, url.origin + url.pathname + url.search);
  if (!url.pathname.endsWith('/wp-admin/admin.php') || url.searchParams.get('page') !== 'kiwe-framework'
      || url.searchParams.get('compiler-import') !== receipt.job) throw failed();
  const draftId = receipt.status === 'complete' && receipt.templatesDone === 1 && receipt.drafts?.length === 1
    ? receipt.drafts[0]?.id : null;
  if (receipt.status === 'complete' && (!Number.isSafeInteger(draftId) || draftId < 1)) throw failed();
  return {
    job: receipt.job, revision: receipt.revision, archiveSha256: archive,
    status: receipt.status, nativeReviewUrl: url.href, templatesDone: receipt.templatesDone,
    blockerCount: receipt.blockerCount, needsAttention: Boolean(receipt.needsAttention), draftId,
  };
}

function nativeApproval(value, origin, intent) {
  const url = siteUrl(origin, value);
  if (!url.pathname.endsWith('/wp-admin/admin-post.php') || url.searchParams.get('action') !== 'kiwe_mcp_change_review'
      || url.searchParams.get('intent') !== intent) throw failed();
  return url.href;
}

export function createBricksImport(projects, homepage, startOrigin, compiler, wordpress = createBricksTarget(), now = Date.now) {
  const flows = new Map();
  const grants = new Map();
  const running = new Map();
  const opening = new Map();
  const connecting = new Set();

  function view(project, row) {
    if (!row) return null;
    const sessionActive = Boolean(grantFor(row.id));
    return {
      status: !sessionActive && row.stage_intent && row.status !== 'draft_ready' ? 'native_review' : row.status,
      sha256: row.source_sha, archiveSha256: row.archive_sha,
      approvalUrl: sessionActive ? row.approval_url : null, reviewUrl: row.receipt?.nativeReviewUrl ?? null,
      builderUrl: row.receipt?.draftId ? project.site.url + '/wp-admin/post.php?post=' + row.receipt.draftId + '&action=edit' : null,
      sessionActive, recoveryRequired: Boolean(row.stage_intent && !row.receipt && !sessionActive),
      frameworkUrl: project.site.url + '/wp-admin/admin.php?page=kiwe-framework#kiwe-compiler-import',
    };
  }

  function grantFor(id) {
    const entry = grants.get(id);
    if (!entry || entry.expiresAt <= now()) {
      grants.delete(id);
      return null;
    }
    return entry;
  }

  function editable(accountId, details) {
    const project = projects.getProject(accountId, { projectId: details.projectId });
    if (details.revision !== undefined && project.revision !== details.revision) throw unavailable();
    const source = homepage.source(accountId, project.id, details.draftId, true);
    return { project, source };
  }

  async function advance(accountId, project, draftId, row, source) {
    const grant = grantFor(row.id);
    if (!grant || grant.connection.origin !== project.site.url) throw unavailable();
    const { connection, token } = grant;
    const save = changes => {
      row = projects.saveHomepageImport(accountId, project.id, draftId, row.id, changes);
    };
    const call = (name, args) => wordpress.call(connection, token, 'kiwe-mcp-' + name, args);
    const op = suffix => scope + '.' + suffix;
    const status = (suffix, id) => call('change-status', { intent: id }).then(r => nativeIntent(r, op(suffix), id));
    const prepare = (suffix, args, requestId) => call('prepare-change', {
      operation: op(suffix), arguments: args, requestId,
    }).then(r => nativeIntent(r, op(suffix)));
    const apply = (suffix, id) => {
      projects.readHomepageImport(accountId, project.id, draftId);
      return call('apply-change', { intent: id }).then(r => nativeIntent(r, op(suffix), id));
    };

    let receipt = row.receipt;
    if (!receipt) {
      const stage = row.stage_intent ? await status('stage', row.stage_intent) : null;
      let current = stage;
      if (current?.state !== 'complete') {
        const { bytes } = await compiler.compile({
          id: row.id, files: seamSource(source).files, archiveSha256: row.archive_sha,
        });
        const archive = digest(bytes);
        if (row.archive_sha && row.archive_sha !== archive) throw failed();
        save({ archive_sha: archive });
        if (!current) {
          current = await prepare('stage', { archiveSha256: archive, bytes: bytes.length }, row.id + '-stage');
          save({ stage_intent: current.id });
        }
        if (current.state === 'prepared') {
          projects.readHomepageImport(accountId, project.id, draftId);
          current = nativeIntent(await wordpress.upload(connection, token, current.id, bytes), op('stage'), current.id);
        }
      }
      if (current.state !== 'complete') throw failed();
      receipt = nativeJob(current.result, project.site.url, row.archive_sha);
      save({ receipt });
    }

    const key = { job: receipt.job, archiveSha256: row.archive_sha };
    const inspect = () => call('read', { operation: op('status'), arguments: key })
      .then(result => nativeJob(result, project.site.url, row.archive_sha, key.job));
    receipt = await inspect();
    if (receipt.blockerCount || receipt.needsAttention) {
      save({ receipt, status: 'native_review', approval_url: null });
      return row;
    }
    if (receipt.status === 'review') {
      const approval = row.begin_intent ? await status('begin', row.begin_intent)
        : await prepare('begin', { ...key, revision: receipt.revision }, row.id + '-begin');
      save({ receipt, begin_intent: approval.id });
      if (approval.state === 'prepared' && approval.approved !== true) {
        save({ status: 'approval_required', approval_url: nativeApproval(approval.approvalUrl, project.site.url, approval.id) });
        return row;
      }
      const started = approval.state === 'prepared' ? await apply('begin', approval.id) : approval;
      if (started.state !== 'complete') throw failed();
      receipt = nativeJob(started.result, project.site.url, row.archive_sha, key.job);
      save({ receipt, status: 'importing', approval_url: null });
    }
    if (phases.includes(receipt.status) && !row.begin_intent) {
      // A native operator can continue from the saved job on Framework.
      save({ receipt, status: 'native_review', approval_url: null });
      return row;
    }
    for (let index = 0; index < 6 && phases.includes(receipt.status) && !receipt.needsAttention; index++) {
      const change = await prepare('next', { ...key, beginIntent: row.begin_intent, revision: receipt.revision }, row.id + '-step-' + receipt.revision);
      const finished = change.state === 'prepared' ? await apply('next', change.id) : change;
      if (finished.state !== 'complete') throw failed();
      receipt = nativeJob(finished.result, project.site.url, row.archive_sha, key.job);
      save({ receipt, status: 'importing', approval_url: null });
    }
    const result = receipt.status === 'complete' ? 'draft_ready'
      : phases.includes(receipt.status) && !receipt.needsAttention ? 'importing' : 'native_review';
    save({ receipt, status: result, approval_url: null });
    if (result === 'draft_ready') {
      grants.delete(row.id);
      await wordpress.revoke(connection, token);
    }
    return row;
  }

  async function resume(accountId, details) {
    if (!compiler) throw new StartError(503, 'seam_unavailable', 'The site operator needs to configure Seam before conversion.');
    const { project, source } = editable(accountId, details);
    const row = projects.readHomepageImport(accountId, project.id, details.draftId);
    if (!row) throw unavailable();
    if (row.status === 'draft_ready') return view(project, row);
    if (!grantFor(row.id)) {
      if (row.stage_intent) return view(project, row);
      throw unavailable();
    }
    let work = running.get(row.id);
    if (!work) {
      work = advance(accountId, project, details.draftId, row, source).finally(() => running.delete(row.id));
      running.set(row.id, work);
    }
    try { return view(project, await work); }
    catch (error) {
      if (error instanceof StartError) throw error;
      throw failed();
    }
  }

  return {
    available: Boolean(compiler),

    status(accountId, projectId, draftId) {
      const project = projects.getProject(accountId, { projectId });
      if (!draftId) return null;
      return view(project, projects.readHomepageImport(accountId, projectId, draftId));
    },

    async begin(accountId, input) {
      if (!compiler) throw new StartError(503, 'seam_unavailable', 'The site operator needs to configure Seam before conversion.');
      const details = parse(bricksInput, input);
      const { project } = editable(accountId, details);
      const row = projects.reserveHomepageImport(accountId, project.id, details.draftId);
      if (grantFor(row.id) || row.stage_intent || row.status === 'draft_ready') {
        return { authorizationUrl: null, conversion: view(project, row) };
      }
      const reused = [...flows.values()].find(flow => flow.id === row.id && flow.expiresAt > now());
      if (reused) return { authorizationUrl: reused.connection.authorizationUrl, conversion: view(project, row) };
      if (opening.has(row.id)) return opening.get(row.id);
      const setup = (async () => {
        const state = opaque();
        const connection = await wordpress.begin(project.domain, startOrigin + bricksReturn, state, opaque(), scope, 'Kiwe Start Bricks draft');
        if (connection.origin !== project.site.url) throw unavailable();
        for (const [key, flow] of flows) if (flow.expiresAt <= now()) flows.delete(key);
        for (const key of grants.keys()) grantFor(key);
        flows.set(state, { ...details, id: row.id, accountId, connection, expiresAt: now() + 600000 });
        return { authorizationUrl: connection.authorizationUrl, conversion: view(project, row) };
      })();
      opening.set(row.id, setup);
      try { return await setup; } finally { opening.delete(row.id); }
    },

    async complete(parameters) {
      const { state, iss, code, error } = parameters;
      const flow = /^[A-Za-z0-9_-]{43}$/.test(state ?? '') ? flows.get(state) : undefined;
      if (!flow || flow.expiresAt <= now() || flow.connection.issuer !== iss) throw unavailable();
      flows.delete(state);
      if (error || !approvalCode.test(code ?? '')) throw unavailable();
      const { project } = editable(flow.accountId, flow);
      const row = projects.readHomepageImport(flow.accountId, project.id, flow.draftId);
      if (row?.id !== flow.id || row.stage_intent || grantFor(row.id) || connecting.has(row.id)) throw unavailable();
      connecting.add(row.id);
      try {
        let grant;
        try { grant = await wordpress.exchange(flow.connection, code, scope); }
        catch { throw unavailable(); }
        const ttl = Math.min(900, Number(grant.expiresIn));
        if (!Number.isFinite(ttl) || ttl <= 0) {
          await wordpress.revoke(flow.connection, grant.token);
          throw unavailable();
        }
        grants.set(row.id, { token: grant.token, connection: flow.connection, expiresAt: now() + ttl * 1000 });
      } finally { connecting.delete(row.id); }
      await resume(flow.accountId, flow);
      return { accountId: flow.accountId, projectId: project.id };
    },

    resume(accountId, input) { return resume(accountId, parse(bricksInput, input)); },
  };
}
