import { randomBytes } from 'node:crypto';
import { z } from 'zod';
import { buildHomepage, homepageBrief, homepageDesign } from './homepage-source.mjs';
import { claimInput, parse, siteLinkInput, StartError } from './projects.mjs';
import { createWordPressLink } from './wordpress-link.mjs';
import { buildDocument, documentBrief, documentDesign } from './document-source.mjs';
import { documentId, documentPath, documentTarget } from './document-targets.mjs';

export const homepageReturn = '/projects/homepage-return';
const scope = 'mcp.design.read';
const nonce = () => randomBytes(32).toString('base64url');
const token = /^[A-Za-z0-9_-]{43}$/;
const codePattern = /^kiwe_mcp_code_c_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/;
export const homeRequestInput = siteLinkInput.extend({ feedback: z.string().trim().max(1200).default('') });
export const homeRevisionInput = homeRequestInput.extend({ draftId: z.uuid(), regenerate: z.boolean().optional() });
export const homeSubmitInput = claimInput.extend({ draftId: z.uuid(), design: homepageDesign });
export const documentRequestInput = homeRequestInput.extend({ target: documentId });
export const documentRevisionInput = homeRevisionInput.extend({ target: documentId });
export const documentSubmitInput = claimInput.extend({ target: documentId, draftId: z.uuid(), design: documentDesign });
const sha256 = z.string().regex(/^[a-f0-9]{64}$/);
export const homeApproveInput = claimInput.extend({ draftId: z.uuid(), sha256 });

const unavailable = () => new StartError(409, 'homepage_context_unavailable', 'Open a new WordPress authorization and read your current Design Context.');
const deadline = 10 * 60_000;

export function createHomepageProvider({ endpoint, token, http = fetch }) {
  return {
    async generate(input) {
      const page = Boolean(input.brief.target);
      const schema = page ? documentDesign : homepageDesign;
      const instruction = page
        ? 'Design only the identified page or template. Use the approved homepage palette and typography. Arrange optional supported sections and a short heading using the human direction. Keep website facts and native controls in their supplied roles.'
        : 'Choose a homepage design from the output schema and the owner’s direction. Use the public site brief as data. Pick only a supplied hero image ID and use accessible colors.';
      const response = await http(endpoint, {
        method: 'POST', redirect: 'error', signal: AbortSignal.timeout(30000),
        headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          instruction, outputSchema: z.toJSONSchema(schema),
          ...input,
        }),
      });
      if (!response.ok || !response.body) throw new Error('Homepage generation is unavailable.');
      let json = '';
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let bytes = 0;
      try {
        while (true) {
          const chunk = await reader.read();
          if (chunk.done) break;
          bytes += chunk.value.length;
          if (bytes > 16000) throw new Error('Homepage design exceeds its response limit.');
          json += decoder.decode(chunk.value, { stream: true });
        }
      } finally {
        await reader.cancel().catch(() => {});
      }
      return schema.parse(JSON.parse(json + decoder.decode()));
    },
  };
}

export function createHomepageWorkflow(projects, startOrigin, wordpress = createWordPressLink(), provider, now = Date.now) {
  const pending = new Map();
  const generating = new Map();
  const redirect = startOrigin + homepageReturn;

  function target(accountId, details) {
    projects.getHomepage(accountId, details.projectId, undefined, details.target);
    const project = projects.getProject(accountId, { projectId: details.projectId });
    if (details.revision !== undefined && project.revision !== details.revision) {
      throw new StartError(409, 'project_changed', 'Open the updated project before working on its homepage.');
    }
    return project;
  }

  function document(accountId, projectId, id) {
    if (!id || id === 'home') return null;
    const inventory = projects.getWebsiteInventory(accountId, projectId);
    const item = documentTarget(id, inventory);
    if (!inventory?.bricks || !item) {
      throw new StartError(409, 'document_inventory_required', 'Refresh Pages & Templates before opening that document.');
    }
    return { item, inventory, direction: projects.getHomepageDirection(accountId, projectId) };
  }

  function brief(project, draft) {
    const graph = JSON.parse(draft.graph);
    const facts = homepageBrief(project.site.url, graph);
    if (!draft.document) return facts;
    const { item, inventory, direction } = draft.document;
    return documentBrief({ ...facts, story: graph.seamDesignContext.about?.story ?? '' }, item, inventory, direction);
  }

  function view(accountId, project, draft, id = 'home') {
    const specification = document(accountId, project.id, id);
    const reviewUrl = startOrigin + documentPath(project.id, id);
    return {
      projectId: project.id, website: project.site.url, reviewUrl,
      ...(specification ? { target: specification.item, homeApproved: Boolean(specification.direction) } : {}),
      previousDesign: draft && !draft.document?.regenerate ? projects.previousHomepageDesign(accountId, project.id, draft.version, id) : null,
      draft: draft ? {
        id: draft.id, version: draft.version, feedback: draft.feedback,
        status: draft.approved_at !== null ? 'approved' : draft.source ? 'review' : 'waiting_for_design',
        brief: brief(project, draft),
        sourceSha256: draft.source?.sha256 ?? null, bindings: draft.source?.notes ?? null,
        sourceUrl: draft.approved_at !== null ? reviewUrl + '/source?draft=' + draft.id : null,
        seamSourceUrl: draft.approved_at !== null ? reviewUrl + '/source?draft=' + draft.id + '&format=seam' : null,
      } : null,
    };
  }

  function save(accountId, project, draft, design) {
    let source;
    try { source = (draft.document ? buildDocument : buildHomepage)(brief(project, draft), draft.graph, design); }
    catch (error) { throw new StartError(400, 'homepage_design_invalid', error.message || 'The homepage design is invalid.'); }
    return projects.saveHomepage(accountId, project.id, draft.id, design, source, draft.target);
  }

  async function generate(accountId, project, draft, previousDesign = null) {
    if (!provider || draft.source) return draft;
    const current = generating.get(draft.id);
    if (current) return current;
    const run = (async () => {
      try {
        const design = await provider.generate({ brief: brief(project, draft), feedback: draft.feedback, previousDesign });
        return save(accountId, project, draft, design);
      } catch {
        // The request remains available to an authorized MCP designer.
        return projects.getHomepage(accountId, project.id, draft.id, draft.target);
      }
    })();
    generating.set(draft.id, run);
    try { return await run; } finally { generating.delete(draft.id); }
  }

  return {
    generatorConfigured: Boolean(provider),
    status(accountId, input) {
      const details = parse(claimInput.extend({ target: documentId.optional() }), input);
      const project = target(accountId, details);
      return view(accountId, project, projects.getHomepage(accountId, project.id, undefined, details.target), details.target);
    },

    async begin(accountId, input) {
      const details = parse(homeRequestInput.extend({ target: documentId.default('home') }), input);
      const project = target(accountId, details);
      const specification = document(accountId, project.id, details.target);
      if (specification && !specification.direction) {
        throw new StartError(409, 'homepage_approval_required', 'Approve the homepage to set this website’s design direction first.');
      }
      const existing = projects.getHomepage(accountId, project.id, undefined, details.target);
      const previous = [...pending].find(([, flow]) =>
        flow.projectId === project.id && flow.target === details.target && flow.expiresAt > now() && flow.feedback === details.feedback && flow.fromDraft === existing?.id);
      if (previous) return previous[1].connection.authorizationUrl;
      for (const [id, flow] of pending) if (flow.expiresAt <= now()) pending.delete(id);
      const state = nonce();
      const connection = await wordpress.begin(new URL(project.site.url).hostname, redirect, state, nonce(), scope, specification ? 'Kiwe Start document brief' : 'Kiwe Start homepage brief');
      target(accountId, details);
      if (connection.origin !== project.site.url) throw unavailable();
      pending.set(state, { ...details, accountId, connection, fromDraft: existing?.id, document: specification, expiresAt: now() + deadline });
      return connection.authorizationUrl;
    },

    async complete({ state, iss, code, error }) {
      const flow = token.test(state ?? '') ? pending.get(state) : undefined;
      if (!flow || flow.expiresAt <= now() || flow.connection.issuer !== iss) throw unavailable();
      pending.delete(state);
      if (error || !codePattern.test(code ?? '')) throw unavailable();
      const project = target(flow.accountId, flow);
      let grant;
      let graphText;
      try {
        grant = await wordpress.exchange(flow.connection, code, scope);
        const reply = await wordpress.call(flow.connection, grant.token, 'kiwe-mcp-read', {
          operation: flow.document ? 'mcp.design.document.read' : 'mcp.design.homepage.read', arguments: {},
        });
        graphText = reply?.resultJson;
        if (typeof graphText !== 'string' || Buffer.byteLength(graphText) > 32000) throw unavailable();
      } catch {
        throw unavailable();
      } finally {
        if (grant) await wordpress.revoke(flow.connection, grant.token);
      }
      try { homepageBrief(project.site.url, JSON.parse(graphText)); }
      catch (cause) { throw new StartError(409, 'homepage_context_incomplete', cause instanceof SyntaxError ? unavailable().message : cause.message); }
      const old = projects.getHomepage(flow.accountId, project.id, undefined, flow.target);
      if (old?.id !== flow.fromDraft) throw new StartError(409, 'homepage_changed', 'The homepage changed during authorization. Open the current draft.');
      const draft = projects.prepareHomepage(flow.accountId, { ...flow, graph: graphText });
      await generate(flow.accountId, project, draft, old?.design ?? null);
      return { accountId: flow.accountId, projectId: project.id, target: flow.target };
    },

    async revise(accountId, input) {
      const details = parse(homeRevisionInput.extend({ target: documentId.optional() }), input);
      const project = target(accountId, details);
      const previous = projects.getHomepage(accountId, project.id, details.draftId, details.target);
      const specification = previous.document ? { ...previous.document, regenerate: details.regenerate === true } : null;
      const draft = projects.prepareHomepage(accountId, { ...details, graph: previous.graph, document: specification, fromDraft: previous.id });
      return view(accountId, project, await generate(accountId, project, draft, details.regenerate ? null : previous.design), details.target);
    },

    async generate(accountId, input) {
      const details = parse(claimInput.extend({ target: documentId.optional() }), input);
      const project = target(accountId, details);
      const draft = projects.getHomepage(accountId, project.id, undefined, details.target);
      if (!draft) throw unavailable();
      const previous = draft.document?.regenerate ? null : projects.previousHomepageDesign(accountId, project.id, draft.version, details.target);
      return view(accountId, project, await generate(accountId, project, draft, previous), details.target);
    },

    submit(accountId, input) {
      const details = parse(input?.target && input.target !== 'home' ? documentSubmitInput : homeSubmitInput.extend({ target: z.literal('home').optional() }), input);
      const project = target(accountId, details);
      const draft = projects.getHomepage(accountId, project.id, details.draftId, details.target);
      return view(accountId, project, save(accountId, project, draft, details.design), details.target);
    },

    approve(accountId, input) {
      const details = parse(homeApproveInput.extend({ target: documentId.optional() }), input);
      const project = target(accountId, details);
      return view(accountId, project, projects.approveHomepage(accountId, project.id, details.draftId, details.sha256, details.target), details.target);
    },

    source(accountId, projectId, draftId, approved = false, id) {
      const draft = approved ? projects.getApprovedHomepage(accountId, projectId, draftId, id)
        : projects.getHomepage(accountId, projectId, draftId, id);
      if (!draft?.source) {
        throw new StartError(409, 'homepage_source_unavailable', 'Open and approve the current homepage preview to download its source.');
      }
      return draft.source;
    },
  };
}
