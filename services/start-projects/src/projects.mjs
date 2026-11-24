import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { isIP } from 'node:net';
import { DatabaseSync } from 'node:sqlite';
import { domainToASCII } from 'node:url';
import { z } from 'zod';

export class StartError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

export const projectScope = 'start:projects';
const id = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._:@/-]{0,159}$/);
export const accountInput = id;
const hostChoices = z.enum(['included', 'existing']);
export const purchaseInput = z.strictObject({
  entitlementId: id,
  purchaseId: id,
  accountId: id,
  accountType: z.enum(['client', 'agency']),
  planId: id,
  hostingIncluded: z.boolean(),
});
export const handoffInput = z.strictObject({ entitlementId: id, accountId: id });
export const ticketInput = z.strictObject({ ticket: z.string().regex(/^[A-Za-z0-9_-]{43}$/) });
export const claimInput = z.strictObject({ projectId: z.uuid() });
const verifiedEmail = z.string().trim().toLowerCase().max(254).pipe(z.email());
export const clientInvitationInput = claimInput.extend({ email: verifiedEmail, revision: z.number().int().positive() });
const clientHandoffInput = z.union([
  z.strictObject({ accountId: id, inviteCode: ticketInput.shape.ticket, verifiedEmail }),
  z.strictObject({ accountId: id, projectId: z.uuid().optional() }),
]);
const agencyPlan = 'kiwe-agency-monthly';
const agencySiteLimit = 3;
const monthlyPlans = new Map([
  ['kiwe-client-monthly', { accountType: 'client', requiresHosting: true }],
  [agencyPlan, { accountType: 'agency', requiresHosting: false }],
]);

export const domainInput = claimInput.extend({
  domain: z.string().min(1).max(253),
  hosting: hostChoices,
  revision: z.number().int().positive(),
});
export const siteLinkInput = claimInput.extend({ revision: z.number().int().positive() });
export const agencyHomeInput = z.strictObject({
  name: z.string().trim().min(1).max(80).regex(/^[^\u0000-\u001f\u007f]+$/u),
  domain: z.string().min(1).max(253),
});
export const agencyVerificationInput = z.strictObject({ attemptId: z.uuid() });
export const agencySiteInput = z.strictObject({ domain: domainInput.shape.domain });
const workspace = z.strictObject({
  type: z.enum(['kiwe_launch', 'agency']),
  name: z.string(),
  website: z.url().nullable(),
});
export const agencySpaceOutput = z.strictObject({
  workspace,
  sites: z.strictObject({ used: z.number().int().nonnegative(), limit: z.literal(agencySiteLimit) }).optional(),
  pending: z.strictObject({
    attemptId: z.uuid(), name: z.string(), website: z.url(), proofUrl: z.url(),
    proofText: z.string(), expiresAt: z.string(),
  }).nullable(),
});
const connectedSite = z.strictObject({
  url: z.url(), coreVersion: z.string(), wordpressVersion: z.string(), verifiedAt: z.string(),
});
const managedSite = z.strictObject({
  status: z.enum(['queued', 'provisioning', 'ready', 'needs_attention']), url: z.url().nullable(),
});
export const keyState = z.strictObject({
  state: z.enum(['awaiting_approval', 'sender_ready', 'sender_offline', 'inactive']),
  sender: z.enum(['account_primary', 'dedicated']).nullable(),
  siteAuthenticated: z.boolean(),
  pairingPending: z.boolean(),
});
const projectOutput = z.strictObject({
  id: z.uuid(),
  planId: id,
  accountType: z.enum(['client', 'agency']),
  access: z.literal('client').optional(),
  workspace,
  hostingIncluded: z.boolean(),
  domain: z.string().nullable(),
  hosting: hostChoices.nullable(),
  revision: z.number().int().positive(),
  site: connectedSite.nullable(),
  managedSite: managedSite.nullable(),
  key: keyState.extend({ manageUrl: z.url().nullable() }).nullable(),
  next: z.enum(['choose_domain', 'provision_site', 'activate_kiwe', 'connect_site', 'connect_key', 'fill_design_context']),
});
export const singleProjectOutput = z.strictObject({ project: projectOutput });
export const projectsOutput = z.strictObject({ projects: z.array(projectOutput) });

export function parse(schema, input) {
  const result = schema.safeParse(input);
  if (!result.success) throw new StartError(400, 'invalid_details', 'Check the submitted details and try again.');
  return result.data;
}

function normalizeDomain(value) {
  const input = value.trim();
  if (/[:/@?#%\\\s]/u.test(input)) {
    throw new StartError(400, 'invalid_domain', 'Enter a domain such as example.com.');
  }
  const domain = domainToASCII(input).toLowerCase().replace(/\.$/, '');
  const labels = domain.split('.');
  if (labels.length < 2 || domain.length > 253 || isIP(domain)
      || labels.some((label) => !/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label))) {
    throw new StartError(400, 'invalid_domain', 'Enter a domain such as example.com.');
  }
  return domain;
}

const agencyProofPath = '/.well-known/kiwe-agency.txt';

function workspaceView(accountType, agency, launchWebsite) {
  if (accountType === 'client') return { type: 'kiwe_launch', name: 'Kiwe Launch', website: launchWebsite };
  return { type: 'agency', name: agency?.name ?? 'Your agency', website: agency?.website ?? null };
}

function view(row, launchWebsite) {
  const site = row.site_url ? {
    url: row.site_url, coreVersion: row.core_version, wordpressVersion: row.wordpress_version, verifiedAt: row.verified_at,
  } : null;
  const hosted = row.managed_status ? {
    status: row.managed_status, url: row.managed_status === 'ready' ? 'https://' + row.setup_domain : null,
  } : null;
  const key = row.key_state ? {
    state: row.key_state, sender: row.key_sender, siteAuthenticated: Boolean(row.key_site_authenticated), pairingPending: Boolean(row.key_pairing_pending),
    manageUrl: row.key_manage_url,
  } : null;
  let next = 'choose_domain';
  if (site) next = key?.siteAuthenticated && key.state === 'sender_ready' ? 'fill_design_context' : 'connect_key';
  else if (hosted?.status === 'ready') next = 'activate_kiwe';
  else if (row.hosting === 'included') next = 'provision_site';
  else if (row.domain) next = 'connect_site';
  return {
    id: row.id,
    planId: row.plan_id,
    accountType: row.account_type,
    workspace: workspaceView(row.account_type, { name: row.agency_name, website: row.agency_website }, launchWebsite),
    hostingIncluded: Boolean(row.hosting_included),
    domain: row.domain,
    hosting: row.hosting,
    revision: row.revision,
    site,
    managedSite: hosted,
    key,
    next,
  };
}

function missingProject() {
  return new StartError(404, 'project_unavailable', 'This project is unavailable.');
}

const secret = () => randomBytes(32).toString('base64url');
const digest = (value) => createHash('sha256').update(value, 'utf8').digest('hex');
const handoffLifetime = 5 * 60 * 1000;
export const browserLifetime = 8 * 60 * 60 * 1000;
export const pilotBrowserLifetime = 60 * 60 * 1000;
export const pilotMcpLifetime = 20 * 60 * 1000;

export function openProjects(filename, { now = Date.now, launchWebsite = 'https://kiwelaunch.com' } = {}) {
  const db = new DatabaseSync(filename, { enableDoubleQuotedStringLiterals: false });
  db.exec('PRAGMA busy_timeout = 3000; PRAGMA journal_mode = WAL;');
  db.exec(`
    CREATE TABLE IF NOT EXISTS projects (
      id TEXT PRIMARY KEY,
      entitlement_id TEXT NOT NULL UNIQUE,
      purchase_id TEXT NOT NULL,
      account_id TEXT NOT NULL,
      account_type TEXT NOT NULL CHECK(account_type IN ('client', 'agency')),
      plan_id TEXT NOT NULL,
      hosting_included INTEGER NOT NULL CHECK(hosting_included IN (0, 1)),
      domain TEXT,
      hosting TEXT CHECK(hosting IN ('included', 'existing')),
      revision INTEGER NOT NULL DEFAULT 1,
      CHECK((domain IS NULL) = (hosting IS NULL))
    ) STRICT;
    CREATE INDEX IF NOT EXISTS projects_account ON projects(account_id);
    CREATE TABLE IF NOT EXISTS handoffs (
      digest TEXT PRIMARY KEY,
      project_id TEXT NOT NULL REFERENCES projects(id),
      expires_at INTEGER NOT NULL
    ) STRICT;
    CREATE TABLE IF NOT EXISTS sessions (
      digest TEXT PRIMARY KEY,
      account_id TEXT NOT NULL,
      expires_at INTEGER NOT NULL
    ) STRICT;
    CREATE INDEX IF NOT EXISTS handoffs_expiry ON handoffs(expires_at);
    CREATE INDEX IF NOT EXISTS sessions_expiry ON sessions(expires_at);
    CREATE TABLE IF NOT EXISTS pilot_mcp_tokens (
      digest TEXT PRIMARY KEY,
      session_digest TEXT NOT NULL UNIQUE,
      expires_at INTEGER NOT NULL
    ) STRICT;
    CREATE TABLE IF NOT EXISTS browser_logins (
      digest TEXT PRIMARY KEY,
      browser_digest TEXT NOT NULL,
      pending TEXT NOT NULL,
      expires_at INTEGER NOT NULL
    ) STRICT;
    CREATE INDEX IF NOT EXISTS browser_logins_expiry ON browser_logins(expires_at);
    CREATE TABLE IF NOT EXISTS managed_sites (
      project_id TEXT PRIMARY KEY REFERENCES projects(id),
      status TEXT NOT NULL CHECK(status IN ('queued', 'provisioning', 'ready', 'needs_attention')),
      setup_domain TEXT UNIQUE,
      reserved_at INTEGER,
      hold_until INTEGER NOT NULL DEFAULT 0,
      CHECK(status = 'queued' OR setup_domain IS NOT NULL)
    ) STRICT;
    CREATE TABLE IF NOT EXISTS project_sites (
      project_id TEXT PRIMARY KEY REFERENCES projects(id),
      site_url TEXT NOT NULL,
      core_version TEXT NOT NULL,
      wordpress_version TEXT NOT NULL,
      verified_at TEXT NOT NULL
    ) STRICT;
    CREATE UNIQUE INDEX IF NOT EXISTS project_sites_url ON project_sites(site_url);

    CREATE TABLE IF NOT EXISTS project_client_invites (
      project_id TEXT PRIMARY KEY REFERENCES projects(id),
      digest TEXT NOT NULL UNIQUE,
      email TEXT NOT NULL,
      site_url TEXT NOT NULL,
      project_revision INTEGER NOT NULL,
      expires_at INTEGER NOT NULL
    ) STRICT;
    CREATE TABLE IF NOT EXISTS project_client_access (
      grant_id TEXT PRIMARY KEY,
      project_id TEXT NOT NULL UNIQUE REFERENCES projects(id),
      account_id TEXT NOT NULL,
      site_url TEXT NOT NULL,
      project_revision INTEGER NOT NULL
    ) STRICT;
    CREATE INDEX IF NOT EXISTS project_client_account ON project_client_access(account_id);
    CREATE TABLE IF NOT EXISTS project_client_handoffs (
      digest TEXT PRIMARY KEY,
      grant_id TEXT NOT NULL REFERENCES project_client_access(grant_id) ON DELETE CASCADE,
      project_id TEXT NOT NULL REFERENCES projects(id),
      account_id TEXT NOT NULL,
      expires_at INTEGER NOT NULL
    ) STRICT;
    CREATE INDEX IF NOT EXISTS project_client_handoffs_expiry ON project_client_handoffs(expires_at);
    CREATE TABLE IF NOT EXISTS key_workspaces (
      account_id TEXT PRIMARY KEY,
      workspace_id TEXT NOT NULL UNIQUE
    ) STRICT;
    CREATE TABLE IF NOT EXISTS agency_spaces (
      account_id TEXT PRIMARY KEY,
      name TEXT,
      website TEXT UNIQUE,
      verified_at INTEGER,
      attempt_id TEXT,
      attempt_name TEXT,
      attempt_website TEXT,
      proof_text TEXT,
      expires_at INTEGER
    ) STRICT;
    CREATE TABLE IF NOT EXISTS project_key_links (
      project_id TEXT PRIMARY KEY REFERENCES projects(id),
      state TEXT NOT NULL DEFAULT 'awaiting_approval',
      sender TEXT,
      site_authenticated INTEGER NOT NULL DEFAULT 0 CHECK(site_authenticated IN (0, 1)),
      manage_url TEXT,
      pairing_pending INTEGER NOT NULL DEFAULT 0
    ) STRICT;
    CREATE TABLE IF NOT EXISTS homepage_drafts (
      id TEXT PRIMARY KEY,
      project_id TEXT NOT NULL REFERENCES projects(id),
      project_revision INTEGER NOT NULL,
      version INTEGER NOT NULL,
      graph TEXT NOT NULL,
      feedback TEXT NOT NULL,
      design TEXT,
      source TEXT,
      approved_at INTEGER,
      UNIQUE (project_id, version)
    ) STRICT;
    CREATE TABLE IF NOT EXISTS homepage_publications (
      draft_id TEXT PRIMARY KEY REFERENCES homepage_drafts(id),
      record TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS homepage_imports (
      draft_id TEXT PRIMARY KEY REFERENCES homepage_drafts(id),
      id TEXT NOT NULL UNIQUE,
      source_sha TEXT NOT NULL,
      archive_sha TEXT,
      stage_intent TEXT,
      begin_intent TEXT,
      status TEXT NOT NULL DEFAULT 'authorization_required',
      approval_url TEXT,
      receipt TEXT
    ) STRICT;
    CREATE TABLE IF NOT EXISTS project_website_inventory (
      project_id TEXT PRIMARY KEY REFERENCES projects(id),
      project_revision INTEGER NOT NULL,
      website TEXT NOT NULL,
      snapshot TEXT NOT NULL,
      checked_at INTEGER NOT NULL
    ) STRICT;
    CREATE TABLE IF NOT EXISTS site_link_attempts (
      digest TEXT PRIMARY KEY,
      project_id TEXT NOT NULL UNIQUE REFERENCES projects(id),
      account_id TEXT NOT NULL,
      revision INTEGER NOT NULL,
      issuer TEXT NOT NULL,
      connection TEXT NOT NULL,
      expires_at INTEGER NOT NULL
    ) STRICT;
    CREATE INDEX IF NOT EXISTS site_link_expiry ON site_link_attempts(expires_at);
  `);
  const draftColumns = db.prepare('PRAGMA table_info(homepage_drafts)').all().map(column => column.name);
  if (!draftColumns.includes('target')) db.exec("ALTER TABLE homepage_drafts ADD COLUMN target TEXT NOT NULL DEFAULT 'home'");
  if (!draftColumns.includes('document')) db.exec('ALTER TABLE homepage_drafts ADD COLUMN document TEXT');
  db.exec('CREATE INDEX IF NOT EXISTS start_drafts_by_target ON homepage_drafts(project_id,target,version)');
  const byEntitlement = db.prepare('SELECT * FROM projects WHERE entitlement_id = ?');
  const pruneLogins = db.prepare('DELETE FROM browser_logins WHERE expires_at <= ?');
  const addLogin = db.prepare('INSERT INTO browser_logins(digest, browser_digest, pending, expires_at) VALUES (?, ?, ?, ?)');
  const consumeLogin = db.prepare('DELETE FROM browser_logins WHERE digest = ? AND browser_digest = ? AND expires_at > ? RETURNING pending');
  const ownerOfProject = db.prepare('SELECT account_id FROM projects WHERE id = ?');
  const projection = 'SELECT p.*, s.site_url, s.core_version, s.wordpress_version, s.verified_at, '
    + 'm.status AS managed_status, m.setup_domain, '
    + 'k.project_id AS key_project_id, k.state AS key_state, k.sender AS key_sender, k.site_authenticated AS key_site_authenticated, '
    + 'k.manage_url AS key_manage_url, k.pairing_pending AS key_pairing_pending, '
    + 'a.name AS agency_name, a.website AS agency_website FROM projects p '
    + 'LEFT JOIN project_sites s ON p.id = s.project_id LEFT JOIN managed_sites m ON p.id = m.project_id '
    + 'LEFT JOIN project_key_links k ON p.id = k.project_id LEFT JOIN agency_spaces a ON p.account_id = a.account_id';
  const owned = db.prepare(projection + ' WHERE p.id = ? AND p.account_id = ?');
  const list = db.prepare(projection + ' WHERE p.account_id = ? ORDER BY p.rowid');
  const clientProjection = projection.replace('SELECT p.*,', 'SELECT c.grant_id, p.*,')
    + ' JOIN project_client_access c ON c.project_id = p.id AND c.project_revision = p.revision AND c.site_url = s.site_url';
  const clientProject = db.prepare(clientProjection + ' WHERE p.id = ? AND c.account_id = ?');
  const clientProjects = db.prepare(clientProjection + ' WHERE c.account_id = ? ORDER BY p.rowid');
  const clientGrantForProject = db.prepare(clientProjection + ' WHERE p.id = ?');
  const clientAccessManager = db.prepare("SELECT 1 FROM projects p JOIN project_sites s ON p.id = s.project_id WHERE p.account_id = ? AND p.account_type = 'agency' LIMIT 1");
  const clearClientGrant = db.prepare('DELETE FROM project_client_access WHERE project_id = ?');
  const addClientGrant = db.prepare('INSERT INTO project_client_access(grant_id, project_id, account_id, site_url, project_revision) VALUES (?, ?, ?, ?, ?)');
  const addClientInvite = db.prepare(
    'INSERT INTO project_client_invites(project_id, digest, email, site_url, project_revision, expires_at) VALUES (?, ?, ?, ?, ?, ?) '
    + 'ON CONFLICT(project_id) DO UPDATE SET digest = excluded.digest, email = excluded.email, site_url = excluded.site_url, '
    + 'project_revision = excluded.project_revision, expires_at = excluded.expires_at',
  );
  const getClientInvite = db.prepare('SELECT * FROM project_client_invites WHERE digest = ? AND expires_at > ?');
  const pendingClientInvite = db.prepare('SELECT 1 FROM project_client_invites WHERE project_id = ? AND site_url = ? AND project_revision = ? AND expires_at > ?');
  const clearClientInvite = db.prepare('DELETE FROM project_client_invites WHERE project_id = ?');
  const pruneClientInvites = db.prepare('DELETE FROM project_client_invites WHERE expires_at <= ?');
  const addClientHandoff = db.prepare('INSERT INTO project_client_handoffs(digest, grant_id, project_id, account_id, expires_at) VALUES (?, ?, ?, ?, ?)');
  const consumeClientHandoff = db.prepare('DELETE FROM project_client_handoffs WHERE digest = ? AND expires_at > ? RETURNING *');
  const pruneClientHandoffs = db.prepare('DELETE FROM project_client_handoffs WHERE expires_at <= ?');
  const insert = db.prepare(
    'INSERT INTO projects(id, entitlement_id, purchase_id, account_id, account_type, plan_id, hosting_included) '
    + 'VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(entitlement_id) DO NOTHING',
  );
  const update = db.prepare(
    'UPDATE projects SET domain = ?, hosting = ?, revision = revision + 1 '
    + 'WHERE id = ? AND account_id = ?',
  );
  const addHandoff = db.prepare('INSERT INTO handoffs(digest, project_id, expires_at) VALUES (?, ?, ?)');
  const consumeHandoff = db.prepare('DELETE FROM handoffs WHERE digest = ? AND expires_at > ? RETURNING project_id');
  const addSession = db.prepare('INSERT INTO sessions(digest, account_id, expires_at) VALUES (?, ?, ?)');
  const checkSession = db.prepare('SELECT account_id FROM sessions WHERE digest = ? AND expires_at > ?');
  const putPilotToken = db.prepare(
    'INSERT INTO pilot_mcp_tokens(digest, session_digest, expires_at) VALUES (?, ?, ?) '
    + 'ON CONFLICT(session_digest) DO UPDATE SET digest = excluded.digest, expires_at = excluded.expires_at',
  );
  const checkPilotToken = db.prepare(
    'SELECT s.account_id FROM pilot_mcp_tokens t JOIN sessions s ON s.digest = t.session_digest '
    + 'WHERE t.digest = ? AND t.expires_at > ? AND s.expires_at > ?',
  );
  const prunePilotTokens = db.prepare('DELETE FROM pilot_mcp_tokens WHERE expires_at <= ?');
  const endSession = db.prepare('DELETE FROM sessions WHERE digest = ?');
  const pruneHandoffs = db.prepare('DELETE FROM handoffs WHERE expires_at <= ?');
  const pruneSessions = db.prepare('DELETE FROM sessions WHERE expires_at <= ?');

  const pendingLink = db.prepare('SELECT connection FROM site_link_attempts WHERE project_id = ? AND revision = ? AND expires_at > ?');
  const addLinkAttempt = db.prepare(
    'INSERT INTO site_link_attempts(digest, project_id, account_id, revision, issuer, connection, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
  );
  const takeLinkAttempt = db.prepare('DELETE FROM site_link_attempts WHERE digest = ? AND issuer = ? AND expires_at > ? RETURNING *');
  const dropLinkAttempt = db.prepare('DELETE FROM site_link_attempts WHERE project_id = ?');
  const pruneLinks = db.prepare('DELETE FROM site_link_attempts WHERE expires_at <= ?');
  const forgetSite = db.prepare('DELETE FROM project_sites WHERE project_id = ?');
  const attachedSite = db.prepare('SELECT project_id FROM project_sites WHERE site_url = ?');
  const confirmLink = db.prepare(
    'INSERT INTO project_sites(project_id, site_url, core_version, wordpress_version, verified_at) VALUES (?, ?, ?, ?, ?)',
  );

  function requireLinkTarget(accountId, { projectId, revision }) {
    const row = owned.get(projectId, accountId);
    if (!row) throw missingProject();
    if (row.revision !== revision) {
      throw new StartError(409, 'project_changed', 'The project changed. Reload it before connecting a site.');
    }
    const domain = row.hosting === 'existing' ? row.domain : row.managed_status === 'ready' ? row.setup_domain : null;
    if (!domain || row.site_url) {
      throw new StartError(409, 'site_link_choice', 'Choose an existing WordPress site or finish the managed WordPress setup first.');
    }
    return { row, domain };
  }

  const managedJob = db.prepare('SELECT status, setup_domain AS domain, reserved_at AS reservedAt FROM managed_sites WHERE project_id = ?');
  const addManaged = db.prepare('INSERT INTO managed_sites(project_id, status) VALUES (?, ?) ON CONFLICT(project_id) DO NOTHING');
  const reserveManaged = db.prepare(
    "UPDATE managed_sites SET setup_domain = ?, reserved_at = ? WHERE project_id = ? AND setup_domain IS NULL",
  );
  const claimManaged = db.prepare(
    "UPDATE managed_sites SET hold_until = ? WHERE project_id = ? AND status = 'queued' AND hold_until <= ?",
  );
  const updateManaged = db.prepare(
    "UPDATE managed_sites SET status = ? WHERE project_id = ? AND status NOT IN ('ready', 'needs_attention')",
  );

  function requireManagedTarget(accountId, { projectId, revision }) {
    const row = owned.get(projectId, accountId);
    if (!row) throw missingProject();
    if (row.revision !== revision) {
      throw new StartError(409, 'project_changed', 'The project changed. Reload it before setting up hosting.');
    }
    if (!row.domain || row.hosting !== 'included' || !row.hosting_included) {
      throw new StartError(409, 'hosting_unavailable', 'Choose the hosting included with your plan first.');
    }
    return row;
  }

  const ensureKeyWorkspace = db.prepare('INSERT INTO key_workspaces(account_id, workspace_id) VALUES (?, ?) ON CONFLICT(account_id) DO NOTHING');
  const keyWorkspace = db.prepare('SELECT workspace_id FROM key_workspaces WHERE account_id = ?');
  const addKeyLink = db.prepare('INSERT INTO project_key_links(project_id) VALUES (?) ON CONFLICT(project_id) DO NOTHING');
  const updateKeyLink = db.prepare('UPDATE project_key_links SET state = ?, sender = ?, site_authenticated = ?, pairing_pending = ?, manage_url = ? WHERE project_id = ?');

  const agencyMember = db.prepare("SELECT 1 FROM projects WHERE account_id = ? AND account_type = 'agency' LIMIT 1");
  const monthlyAgency = db.prepare("SELECT purchase_id FROM projects WHERE account_id = ? AND account_type = 'agency' AND plan_id = ? ORDER BY rowid LIMIT 1");
  const countAgencySites = db.prepare("SELECT COUNT(*) AS used FROM projects WHERE account_id = ? AND account_type = 'agency'");
  const agencyDomain = db.prepare("SELECT id FROM projects WHERE account_id = ? AND account_type = 'agency' AND domain = ? ORDER BY rowid LIMIT 1");
  const emptyAgencySlot = db.prepare("SELECT id FROM projects WHERE account_id = ? AND account_type = 'agency' AND plan_id = ? AND domain IS NULL AND hosting_included = 0 ORDER BY rowid LIMIT 1");
  const agencySpace = db.prepare('SELECT * FROM agency_spaces WHERE account_id = ?');
  const addAgency = db.prepare('INSERT INTO agency_spaces(account_id) VALUES (?) ON CONFLICT(account_id) DO NOTHING');
  const agencyClaim = db.prepare('SELECT 1 FROM agency_spaces WHERE website = ? AND account_id <> ?');
  const nativeAgencySite = db.prepare(
    "SELECT 1 FROM project_sites s JOIN projects p ON s.project_id = p.id WHERE s.site_url = ? AND p.account_id = ? AND p.account_type = 'agency'",
  );
  const addAgencyAttempt = db.prepare(
    'UPDATE agency_spaces SET attempt_id = ?, attempt_name = ?, attempt_website = ?, proof_text = ?, expires_at = ? WHERE account_id = ?',
  );
  const confirmAgency = db.prepare(
    'UPDATE agency_spaces SET name = ?, website = ?, verified_at = ?, attempt_id = NULL, attempt_name = NULL, '
    + 'attempt_website = NULL, proof_text = NULL, expires_at = NULL WHERE account_id = ?',
  );

  function agencySites(accountId) {
    return monthlyAgency.get(accountId, agencyPlan) ? { used: countAgencySites.get(accountId).used, limit: agencySiteLimit } : null;
  }

  function requireAgencySiteCapacity(used) {
    if (used >= agencySiteLimit) throw new StartError(409, 'agency_site_limit', 'The Agency plan includes up to three site projects.');
  }

  function agencyState(accountId) {
    if (!agencyMember.get(accountId)) throw new StartError(404, 'agency_unavailable', 'An agency plan opens its own workspace.');
    const row = agencySpace.get(accountId);
    const pending = row?.expires_at > now() ? {
      attemptId: row.attempt_id, name: row.attempt_name, website: row.attempt_website,
      proofUrl: row.attempt_website + agencyProofPath, proofText: row.proof_text,
      expiresAt: new Date(row.expires_at).toISOString(),
    } : null;
    const sites = agencySites(accountId);
    return { workspace: workspaceView('agency', row, launchWebsite), pending, ...(sites ? { sites } : {}) };
  }

  function saveAgencyHome(accountId, name, website) {
    if (agencyClaim.get(website, accountId)) throw new StartError(409, 'agency_unavailable', 'This agency website is already assigned.');
    confirmAgency.run(name, website, now(), accountId);
    return agencyState(accountId);
  }

  const projectView = (row) => view(row, launchWebsite);

  function requireKeyTarget(accountId, { projectId, revision }) {
    const row = owned.get(projectId, accountId);
    if (!row) throw missingProject();
    if (row.revision !== revision) {
      throw new StartError(409, 'project_changed', 'The project changed. Reload it before connecting Key.');
    }
    if (!row.site_url) {
      throw new StartError(409, 'website_required', 'Verify the WordPress site before connecting Key.');
    }
    return row;
  }

  const readPublication = db.prepare('SELECT record FROM homepage_publications WHERE draft_id = ?');
  const writePublication = db.prepare('INSERT INTO homepage_publications(draft_id,record) VALUES (?,?) '
    + 'ON CONFLICT(draft_id) DO UPDATE SET record=excluded.record');
  const importByDraft = db.prepare('SELECT * FROM homepage_imports WHERE draft_id = ?');
  const addImport = db.prepare(
    'INSERT INTO homepage_imports(draft_id,id,source_sha) VALUES (?,?,?) ON CONFLICT(draft_id) DO NOTHING',
  );
  const updateImport = db.prepare(
    'UPDATE homepage_imports SET archive_sha=?, stage_intent=?, begin_intent=?, status=?, approval_url=?, receipt=? WHERE id=?',
  );
  const inventoryByProject = db.prepare('SELECT * FROM project_website_inventory WHERE project_id = ?');
  const saveInventory = db.prepare(
    'INSERT INTO project_website_inventory(project_id,project_revision,website,snapshot,checked_at) VALUES (?,?,?,?,?) '
    + 'ON CONFLICT(project_id) DO UPDATE SET project_revision=excluded.project_revision, website=excluded.website, snapshot=excluded.snapshot, checked_at=excluded.checked_at',
  );
  const currentHome = db.prepare('SELECT * FROM homepage_drafts WHERE project_id = ? AND target = ? ORDER BY version DESC LIMIT 1');
  const maxDraftVersion = db.prepare('SELECT MAX(version) AS version FROM homepage_drafts WHERE project_id = ?');
  const approvedHomeDirection = db.prepare(
    "SELECT design FROM homepage_drafts WHERE project_id = ? AND project_revision = ? AND target = 'home' AND approved_at IS NOT NULL ORDER BY version DESC LIMIT 1",
  );
  const homeById = db.prepare('SELECT * FROM homepage_drafts WHERE project_id = ? AND target = ? AND id = ?');
  const lastHomeDesign = db.prepare(
    'SELECT design FROM homepage_drafts WHERE project_id = ? AND project_revision = ? AND target = ? AND version < ? AND design IS NOT NULL ORDER BY version DESC LIMIT 1',
  );
  const addHome = db.prepare(
    'INSERT INTO homepage_drafts(id, project_id, project_revision, version, graph, feedback, target, document) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
  );
  const attachHomeSource = db.prepare('UPDATE homepage_drafts SET design = ?, source = ? WHERE id = ? AND source IS NULL');
  const approveHome = db.prepare('UPDATE homepage_drafts SET approved_at = ? WHERE id = ? AND approved_at IS NULL');
  const readHome = (row) => row
    ? { ...row, design: row.design ? JSON.parse(row.design) : null, source: row.source ? JSON.parse(row.source) : null,
      document: row.document ? JSON.parse(row.document) : null }
    : null;

  function homepageTarget(accountId, projectId, revision) {
    const row = owned.get(projectId, accountId);
    if (!row) throw missingProject();
    if (revision !== undefined && row.revision !== revision) {
      throw new StartError(409, 'project_changed', 'Open the updated project before working on its homepage.');
    }
    if (!row.site_url || !row.key_site_authenticated || row.key_state === 'inactive') {
      throw new StartError(409, 'homepage_setup_required', 'Verify the WordPress site and Key before requesting a homepage.');
    }
    return row;
  }

  function latestHomepage(accountId, projectId, draftId, target = 'home') {
    const project = homepageTarget(accountId, projectId);
    const draft = currentHome.get(projectId, target);
    const latest = draft?.project_revision === project.revision ? draft : null;
    if (draftId && latest?.id !== draftId) {
      throw new StartError(409, 'homepage_changed', 'A newer homepage revision is active. Open the current draft.');
    }
    return readHome(latest);
  }

  function websiteTarget(accountId, projectId, revision) {
    const project = owned.get(projectId, accountId);
    if (!project) throw missingProject();
    if (revision !== undefined && project.revision !== revision) {
      throw new StartError(409, 'project_changed', 'Open the updated project before reading its website.');
    }
    if (!project.site_url) throw new StartError(409, 'website_required', 'Connect this WordPress website first.');
    return project;
  }

  function approvedImportTarget(accountId, projectId, draftId, target) {
    const draft = latestHomepage(accountId, projectId, draftId, target);
    if (!draft?.source || draft.approved_at === null) {
      throw new StartError(409, 'homepage_approval_required', 'Approve the current homepage preview before Convert to Bricks.');
    }
    return draft;
  }

  function importReceipt(row) {
    return row ? { ...row, receipt: row.receipt ? JSON.parse(row.receipt) : null } : null;
  }


  function agencyClientTarget(accountId, projectId) {
    const project = owned.get(projectId, accountId);
    if (!project || project.account_type !== 'agency') throw missingProject();
    if (!project.site_url) throw new StartError(409, 'website_required', 'Verify this website before inviting its client.');
    return project;
  }

  function clientView(row) {
    return {
      ...projectView(row), access: 'client', accountType: 'client', planId: 'agency-client',
      workspace: workspaceView('client', null, launchWebsite),
      managedSite: null, key: null, next: 'fill_design_context',
    };
  }

  function openSession(accountId, projectId, time, lifetime = browserLifetime) {
    const token = secret();
    pruneSessions.run(time);
    addSession.run(digest(token), accountId, time + lifetime);
    return { token, projectId };
  }

  function claimClientInvitation(accountId, email, invitationDigest, time) {
    const verified = verifiedEmail.safeParse(email);
    const invitation = getClientInvite.get(invitationDigest, time);
    const owner = invitation && ownerOfProject.get(invitation.project_id);
    const project = owner && owned.get(invitation.project_id, owner.account_id);
    if (!verified.success || !project || project.account_type !== 'agency' || owner.account_id === accountId
        || invitation.email !== verified.data || project.site_url !== invitation.site_url
        || project.revision !== invitation.project_revision || clientGrantForProject.get(project.id)) {
      throw new StartError(404, 'client_invite_unavailable', 'This client invitation is unavailable. Use the invited account and a current code.');
    }
    clearClientInvite.run(project.id);
    addClientGrant.run(randomUUID(), project.id, accountId, project.site_url, project.revision);
    return clientProject.get(project.id, accountId);
  }

  function transaction(work) {
    db.exec('BEGIN IMMEDIATE');
    try {
      const result = work();
      db.exec('COMMIT');
      return result;
    } catch (error) {
      db.exec('ROLLBACK');
      throw error;
    }
  }

  return {
    acceptPurchase(input) {
      const receipt = parse(purchaseInput, input);
      const plan = monthlyPlans.get(receipt.planId);
      if (plan && (plan.accountType !== receipt.accountType || (plan.requiresHosting && !receipt.hostingIncluded))) {
        throw new StartError(400, 'plan_details_mismatch', 'The purchase details do not match this Kiwe monthly plan.');
      }
      return transaction(() => {
        const row = byEntitlement.get(receipt.entitlementId);
        if (row) {
          if (row.purchase_id !== receipt.purchaseId || row.account_id !== receipt.accountId
              || row.account_type !== receipt.accountType || row.plan_id !== receipt.planId
              || row.hosting_included !== Number(receipt.hostingIncluded)) {
            throw new StartError(409, 'entitlement_conflict', 'This entitlement already has different purchase details.');
          }
          return { created: false, projectId: row.id };
        }
        if (receipt.accountType === 'agency'
            && (receipt.planId === agencyPlan || monthlyAgency.get(receipt.accountId, agencyPlan))) {
          requireAgencySiteCapacity(countAgencySites.get(receipt.accountId).used);
        }
        const projectId = randomUUID();
        insert.run(projectId, receipt.entitlementId, receipt.purchaseId, receipt.accountId,
          receipt.accountType, receipt.planId, Number(receipt.hostingIncluded));
        return { created: true, projectId };
      });
    },

    issueHandoff(input) {
      const { entitlementId, accountId } = parse(handoffInput, input);
      return transaction(() => {
        const row = byEntitlement.get(entitlementId);
        if (!row || row.account_id !== accountId) throw missingProject();
        const ticket = secret();
        const expiresAt = now() + handoffLifetime;
        pruneHandoffs.run(now());
        addHandoff.run(digest(ticket), row.id, expiresAt);
        return { ticket, expiresAt };
      });
    },

    redeemHandoff(input) {
      const { ticket } = parse(ticketInput, input);
      return transaction(() => {
        const time = now();
        const key = digest(ticket);
        const record = consumeHandoff.get(key, time);
        if (record) return openSession(ownerOfProject.get(record.project_id).account_id, record.project_id, time);
        const shared = consumeClientHandoff.get(key, time);
        const current = shared && clientProject.get(shared.project_id, shared.account_id);
        if (!current || current.grant_id !== shared.grant_id) {
          throw new StartError(410, 'handoff_expired', 'Open the project again from your Kiwe account.');
        }
        return openSession(shared.account_id, shared.project_id, time);
      });
    },

    saveBrowserLogin({ binding, state, pending }, lifetime) {
      transaction(() => {
        const time = now();
        const { inviteCode, ...login } = pending;
        if (inviteCode) login.invitationDigest = digest(inviteCode);
        pruneLogins.run(time);
        addLogin.run(digest(state), digest(binding), JSON.stringify(login), time + lifetime);
      });
    },

    takeBrowserLogin(binding, state) {
      const row = consumeLogin.get(digest(state), digest(binding), now());
      if (!row) throw new StartError(410, 'login_expired', 'Start a new sign-in on this device.');
      return JSON.parse(row.pending);
    },

    signIn({ accountId, verifiedEmail: email }, invitationDigest, previousSession, lifetime = browserLifetime) {
      return transaction(() => {
        const time = now();
        const client = invitationDigest ? claimClientInvitation(accountId, email, invitationDigest, time) : null;
        if (previousSession) endSession.run(digest(previousSession));
        return openSession(accountId, client?.id ?? null, time, lifetime);
      });
    },

    issuePilotMcp(session, pilotAccount) {
      if (!ticketInput.shape.ticket.safeParse(session).success) throw missingProject();
      return transaction(() => {
        const time = now();
        const binding = digest(session);
        if (checkSession.get(binding, time)?.account_id !== pilotAccount) throw missingProject();
        const token = 'kiwe_start_pilot_' + secret();
        prunePilotTokens.run(time);
        putPilotToken.run(digest(token), binding, time + pilotMcpLifetime);
        return token;
      });
    },

    accountForPilotMcp(token, pilotAccount) {
      if (!/^kiwe_start_pilot_[A-Za-z0-9_-]{43}$/.test(token)) return null;
      const time = now();
      return checkPilotToken.get(digest(token), time, time)?.account_id === pilotAccount ? pilotAccount : null;
    },

    accountForSession(token) {
      return token ? checkSession.get(digest(token), now())?.account_id ?? null : null;
    },

    endSession(token) {
      if (token) endSession.run(digest(token));
    },

    listProjects(accountId) {
      return [...list.all(accountId).map(projectView), ...clientProjects.all(accountId).map(clientView)];
    },

    getProject(accountId, input) {
      const { projectId } = parse(claimInput, input);
      const row = owned.get(projectId, accountId);
      if (!row) throw missingProject();
      return projectView(row);
    },


    readProject(accountId, input) {
      const { projectId } = parse(claimInput, input);
      const project = owned.get(projectId, accountId);
      if (project) return projectView(project);
      const client = clientProject.get(projectId, accountId);
      if (!client) throw missingProject();
      return clientView(client);
    },

    designContextAccess(accountId, { projectId }) {
      const row = owned.get(projectId, accountId) ?? clientProject.get(projectId, accountId);
      if (!row) throw missingProject();
      return {
        project: row.grant_id ? clientView(row) : projectView(row),
        binding: JSON.stringify([accountId, row.id, row.revision, row.grant_id ?? null]),
        ready: Boolean(row.site_url && row.key_site_authenticated && row.key_state !== 'inactive'),
      };
    },

    canManageClientAccess(accountId) {
      return Boolean(clientAccessManager.get(accountId));
    },

    clientAccess(accountId, input) {
      const { projectId } = parse(claimInput, input);
      const project = agencyClientTarget(accountId, projectId);
      if (clientGrantForProject.get(projectId)) return 'active';
      return pendingClientInvite.get(projectId, project.site_url, project.revision, now()) ? 'invited' : 'none';
    },

    inviteClient(accountId, input) {
      const { projectId, email, revision } = parse(clientInvitationInput, input);
      return transaction(() => {
        const project = agencyClientTarget(accountId, projectId);
        if (project.revision !== revision) throw new StartError(409, 'project_changed', 'Open the updated website before inviting its client.');
        if (clientGrantForProject.get(projectId)) {
          throw new StartError(409, 'client_already_connected', 'Revoke the current client access before inviting someone else.');
        }
        clearClientGrant.run(projectId);
        const time = now();
        const inviteCode = secret();
        const expiresAt = time + 7 * 24 * 60 * 60 * 1000;
        pruneClientInvites.run(time);
        addClientInvite.run(projectId, digest(inviteCode), email, project.site_url, project.revision, expiresAt);
        return { inviteCode, expiresAt };
      });
    },

    revokeClient(accountId, input) {
      const { projectId } = parse(claimInput, input);
      return transaction(() => {
        const project = owned.get(projectId, accountId);
        if (!project || project.account_type !== 'agency') throw missingProject();
        clearClientInvite.run(projectId);
        clearClientGrant.run(projectId);
        return { revoked: true };
      });
    },

    issueClientHandoff(input) {
      const request = parse(clientHandoffInput, input);
      const { accountId } = request;
      return transaction(() => {
        const time = now();
        const client = request.inviteCode
          ? claimClientInvitation(accountId, request.verifiedEmail, digest(request.inviteCode), time)
          : request.projectId ? clientProject.get(request.projectId, accountId) : clientProjects.all(accountId)[0];
        if (!client) throw missingProject();
        const ticket = secret();
        const expiresAt = time + handoffLifetime;
        pruneClientHandoffs.run(time);
        addClientHandoff.run(digest(ticket), client.grant_id, client.id, accountId, expiresAt);
        return { ticket, expiresAt, projectId: client.id };
      });
    },

    getAgencySpace(accountId) {
      return agencyState(accountId);
    },

    agencySites,

    addAgencySite(accountId, input) {
      const { domain: value } = parse(agencySiteInput, input);
      const domain = normalizeDomain(value);
      return transaction(() => {
        const plan = monthlyAgency.get(accountId, agencyPlan);
        if (!plan) throw new StartError(404, 'agency_unavailable', 'An Agency monthly plan is required to add a site.');
        const previous = agencyDomain.get(accountId, domain);
        if (previous) return projectView(owned.get(previous.id, accountId));
        let slot = emptyAgencySlot.get(accountId, agencyPlan);
        if (!slot) {
          requireAgencySiteCapacity(countAgencySites.get(accountId).used);
          const id = randomUUID();
          insert.run(id, 'agency-site/' + id, plan.purchase_id, accountId, 'agency', agencyPlan, 0);
          slot = { id };
        }
        update.run(domain, 'existing', slot.id, accountId);
        return projectView(owned.get(slot.id, accountId));
      });
    },

    prepareAgencyHome(accountId, input) {
      const { name, domain } = parse(agencyHomeInput, input);
      const website = 'https://' + normalizeDomain(domain);
      return transaction(() => {
        const current = agencyState(accountId);
        if (current.workspace.website === website && current.workspace.name === name) return current;
        addAgency.run(accountId);
        if (current.workspace.website === website || nativeAgencySite.get(website, accountId)) {
          return saveAgencyHome(accountId, name, website);
        }
        if (current.pending?.website === website && current.pending.name === name) return current;
        addAgencyAttempt.run(randomUUID(), name, website, 'kiwe-agency-' + secret(), now() + 24 * 60 * 60 * 1000, accountId);
        return agencyState(accountId);
      });
    },

    agencyAttempt(accountId, input) {
      const { attemptId } = parse(agencyVerificationInput, input);
      const { pending } = agencyState(accountId);
      if (pending?.attemptId !== attemptId) throw new StartError(410, 'agency_proof_expired', 'Create a fresh website check for this workspace.');
      return pending;
    },

    confirmAgencyHome(accountId, input) {
      return transaction(() => {
        const pending = this.agencyAttempt(accountId, input);
        return saveAgencyHome(accountId, pending.name, pending.website);
      });
    },

    setDomain(accountId, input) {
      const details = parse(domainInput, input);
      const domain = normalizeDomain(details.domain);
      return transaction(() => {
        const row = owned.get(details.projectId, accountId);
        if (!row) throw missingProject();
        if (details.hosting === 'included' && !row.hosting_included) {
          throw new StartError(409, 'hosting_unavailable', 'This purchase uses an existing site.');
        }
        if (row.domain === domain && row.hosting === details.hosting) return projectView(row);
        if (row.key_project_id) throw new StartError(409, 'key_setup_started', 'This website already has a Key request.');
        if (row.managed_status) throw new StartError(409, 'hosting_already_requested', 'This project already has a hosting request.');
        if (row.revision !== details.revision) {
          throw new StartError(409, 'project_changed', 'The project changed. Reload it before saving.');
        }
        if (row.account_type === 'agency' && monthlyAgency.get(accountId, agencyPlan)) {
          const existing = agencyDomain.get(accountId, domain);
          if (existing && existing.id !== row.id) {
            throw new StartError(409, 'agency_domain_used', 'This domain is already in your agency workspace.');
          }
        }
        forgetSite.run(row.id);
        dropLinkAttempt.run(row.id);
        update.run(domain, details.hosting, row.id, accountId);
        return projectView(owned.get(row.id, accountId));
      });
    },

    prepareManagedSite(accountId, details) {
      return transaction(() => {
        const row = requireManagedTarget(accountId, details);
        addManaged.run(row.id, 'queued');
        return managedJob.get(row.id);
      });
    },

    reserveManagedSite(accountId, details, domain) {
      return transaction(() => {
        const row = requireManagedTarget(accountId, details);
        reserveManaged.run(domain, now(), row.id);
        return managedJob.get(row.id);
      });
    },

    managedReservationExpired(accountId, details) {
      const row = requireManagedTarget(accountId, details);
      return now() - managedJob.get(row.id).reservedAt >= 24 * 60 * 60 * 1000;
    },

    claimManagedStart(accountId, details) {
      const row = requireManagedTarget(accountId, details);
      const time = now();
      return claimManaged.run(time + 90_000, row.id, time).changes === 1;
    },

    saveManagedStatus(accountId, details, status) {
      return transaction(() => {
        const row = requireManagedTarget(accountId, details);
        updateManaged.run(status, row.id);
        return projectView(owned.get(row.id, accountId));
      });
    },

    siteLinkTarget(accountId, input) {
      const { row, domain } = requireLinkTarget(accountId, parse(siteLinkInput, input));
      return { id: row.id, revision: row.revision, domain };
    },

    pendingSiteLink(projectId, revision) {
      const existing = pendingLink.get(projectId, revision, now());
      return existing ? JSON.parse(existing.connection) : null;
    },

    saveSiteLink(accountId, details, state, connection) {
      return transaction(() => {
        const { row, domain } = requireLinkTarget(accountId, details);
        if (connection.origin !== 'https://' + domain) {
          throw new StartError(409, 'site_domain_mismatch', 'The authorization must use the assigned website address.');
        }
        const previous = pendingLink.get(row.id, row.revision, now());
        if (previous) return JSON.parse(previous.connection);
        pruneLinks.run(now());
        dropLinkAttempt.run(row.id);
        addLinkAttempt.run(digest(state), row.id, accountId, row.revision, connection.issuer,
          JSON.stringify(connection), now() + handoffLifetime * 2);
        return connection;
      });
    },

    takeSiteLink(state, issuer) {
      const row = takeLinkAttempt.get(digest(state), issuer, now());
      if (!row) throw new StartError(410, 'site_link_expired', 'Open a new site authorization link in Kiwe Start.');
      return {
        projectId: row.project_id, accountId: row.account_id, revision: row.revision,
        connection: JSON.parse(row.connection),
      };
    },

    confirmSiteLink(flow, proof) {
      return transaction(() => {
        const { row, domain } = requireLinkTarget(flow.accountId, flow);
        if (proof.url !== 'https://' + domain) {
          throw new StartError(409, 'site_domain_mismatch', 'The verified website must use the assigned website address.');
        }
        if (attachedSite.get(proof.url)) {
          throw new StartError(409, 'site_already_linked', 'This website is already connected to a Kiwe project.');
        }
        confirmLink.run(row.id, proof.url, proof.coreVersion, proof.wordpressVersion, new Date(now()).toISOString());
        dropLinkAttempt.run(row.id);
        update.run(row.domain, row.hosting, row.id, row.account_id);
        return { ...projectView(owned.get(row.id, row.account_id)), accountId: row.account_id };
      });
    },

    prepareKeyRequest(accountId, details) {
      return transaction(() => {
        const row = requireKeyTarget(accountId, details);
        ensureKeyWorkspace.run(accountId, randomUUID());
        addKeyLink.run(row.id);
        return {
          projectId: row.id, workspaceId: keyWorkspace.get(accountId).workspace_id,
          origin: row.site_url, accountType: row.account_type,
        };
      });
    },

    saveKeyStatus(accountId, details, receipt) {
      return transaction(() => {
        const row = requireKeyTarget(accountId, details);
        const { state, sender, siteAuthenticated, pairingPending } = parse(keyState, {
          state: receipt.state, sender: receipt.sender, siteAuthenticated: receipt.siteAuthenticated,
          pairingPending: receipt.pairingPending,
        });
        updateKeyLink.run(state, sender, Number(siteAuthenticated), Number(pairingPending), receipt.approvalUrl, row.id);
        return projectView(owned.get(row.id, accountId));
      });
    },

    getWebsiteInventory(accountId, projectId) {
      const project = websiteTarget(accountId, projectId);
      const row = inventoryByProject.get(projectId);
      if (row?.project_revision !== project.revision || row.website !== project.site_url) return null;
      return { ...JSON.parse(row.snapshot), checkedAt: new Date(row.checked_at).toISOString() };
    },

    saveWebsiteInventory(accountId, { projectId, revision, snapshot }) {
      return transaction(() => {
        const project = websiteTarget(accountId, projectId, revision);
        if (snapshot.website !== project.site_url) {
          throw new StartError(409, 'website_changed', 'WordPress returned inventory for a different website.');
        }
        saveInventory.run(projectId, revision, snapshot.website, JSON.stringify(snapshot), now());
      });
    },

    getHomepagePublication(accountId, { projectId, draftId, target }) {
      const draft = approvedImportTarget(accountId, projectId, draftId, target);
      const row = readPublication.get(draft.id);
      return row ? JSON.parse(row.record) : null;
    },

    saveHomepagePublication(accountId, { projectId, draftId, target }, record) {
      return transaction(() => {
        const draft = approvedImportTarget(accountId, projectId, draftId, target);
        const imported = importByDraft.get(draft.id);
        if (imported?.status !== 'draft_ready') throw new StartError(409, 'bricks_draft_required', 'Prepare the current private Bricks draft first.');
        writePublication.run(draft.id, JSON.stringify(record));
      });
    },

    readHomepageImport(accountId, projectId, draftId, target) {
      const draft = approvedImportTarget(accountId, projectId, draftId, target);
      return importReceipt(importByDraft.get(draft.id));
    },

    reserveHomepageImport(accountId, projectId, draftId, target) {
      return transaction(() => {
        const draft = approvedImportTarget(accountId, projectId, draftId, target);
        addImport.run(draft.id, randomUUID().replaceAll('-', ''), draft.source.sha256);
        return importReceipt(importByDraft.get(draft.id));
      });
    },

    saveHomepageImport(accountId, projectId, draftId, id, changes, target) {
      return transaction(() => {
        const draft = approvedImportTarget(accountId, projectId, draftId, target);
        const row = importReceipt(importByDraft.get(draft.id));
        if (row?.id !== id || row.source_sha !== draft.source.sha256) {
          throw new StartError(409, 'bricks_run_changed', 'Reload this approved homepage before continuing its import.');
        }
        const next = { ...row, ...changes };
        updateImport.run(next.archive_sha, next.stage_intent, next.begin_intent, next.status, next.approval_url,
          next.receipt ? JSON.stringify(next.receipt) : null, id);
        return next;
      });
    },

    getHomepage(accountId, projectId, draftId, target) {
      return latestHomepage(accountId, projectId, draftId, target);
    },

    getApprovedHomepage(accountId, projectId, draftId, target = 'home') {
      const project = homepageTarget(accountId, projectId);
      const row = draftId ? homeById.get(projectId, target, draftId) : currentHome.get(projectId, target);
      return row?.project_revision === project.revision && row.approved_at !== null ? readHome(row) : null;
    },

    previousHomepageDesign(accountId, projectId, version, target = 'home') {
      const project = homepageTarget(accountId, projectId);
      const row = lastHomeDesign.get(projectId, project.revision, target, version);
      return row ? JSON.parse(row.design) : null;
    },

    getHomepageDirection(accountId, projectId) {
      const project = homepageTarget(accountId, projectId);
      const row = approvedHomeDirection.get(projectId, project.revision);
      return row ? JSON.parse(row.design) : null;
    },

    prepareHomepage(accountId, { projectId, revision, graph, feedback, fromDraft, target = 'home', document = null }) {
      return transaction(() => {
        const project = homepageTarget(accountId, projectId, revision);
        const previous = currentHome.get(projectId, target);
        if (fromDraft && (previous?.id !== fromDraft || previous.project_revision !== project.revision)) {
          throw new StartError(409, 'homepage_changed', 'A newer homepage revision is active. Open the current draft.');
        }
        const specification = document === null ? null : JSON.stringify(document);
        if (previous && previous.project_revision === project.revision && !previous.source
            && previous.graph === graph && previous.feedback === feedback && previous.document === specification) return readHome(previous);
        addHome.run(randomUUID(), projectId, revision, (maxDraftVersion.get(projectId).version ?? 0) + 1,
          graph, feedback, target, specification);
        return readHome(currentHome.get(projectId, target));
      });
    },

    saveHomepage(accountId, projectId, draftId, design, source, target) {
      return transaction(() => {
        const draft = latestHomepage(accountId, projectId, draftId, target);
        if (draft.source) {
          if (draft.source.sha256 === source.sha256) return draft;
          throw new StartError(409, 'homepage_saved', 'This draft was saved. Request a new revision for further changes.');
        }
        attachHomeSource.run(JSON.stringify(design), JSON.stringify(source), draftId);
        return readHome(currentHome.get(projectId, draft.target));
      });
    },

    approveHomepage(accountId, projectId, draftId, sha256, target) {
      return transaction(() => {
        const draft = latestHomepage(accountId, projectId, draftId, target);
        if (!draft.source || draft.source.sha256 !== sha256) {
          throw new StartError(409, 'homepage_changed', 'Approve the current preview and its exact source.');
        }
        approveHome.run(now(), draftId);
        return readHome(currentHome.get(projectId, draft.target));
      });
    },

    close() {
      db.close();
    },
  };
}
