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
export const domainInput = claimInput.extend({
  domain: z.string().min(1).max(253),
  hosting: hostChoices,
  revision: z.number().int().positive(),
});
export const siteLinkInput = claimInput.extend({ revision: z.number().int().positive() });
const connectedSite = z.strictObject({
  url: z.url(), coreVersion: z.string(), wordpressVersion: z.string(), verifiedAt: z.string(),
});
const managedSite = z.strictObject({
  status: z.enum(['queued', 'provisioning', 'ready', 'needs_attention']), url: z.url().nullable(),
});
const projectOutput = z.strictObject({
  id: z.uuid(),
  planId: id,
  accountType: z.enum(['client', 'agency']),
  hostingIncluded: z.boolean(),
  domain: z.string().nullable(),
  hosting: hostChoices.nullable(),
  revision: z.number().int().positive(),
  site: connectedSite.nullable(),
  managedSite: managedSite.nullable(),
  next: z.enum(['choose_domain', 'provision_site', 'activate_kiwe', 'connect_site', 'connect_key']),
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

function view(row) {
  const site = row.site_url ? {
    url: row.site_url, coreVersion: row.core_version, wordpressVersion: row.wordpress_version, verifiedAt: row.verified_at,
  } : null;
  const hosted = row.managed_status ? {
    status: row.managed_status, url: row.managed_status === 'ready' ? 'https://' + row.setup_domain : null,
  } : null;
  let next = 'choose_domain';
  if (site) next = 'connect_key';
  else if (hosted?.status === 'ready') next = 'activate_kiwe';
  else if (row.hosting === 'included') next = 'provision_site';
  else if (row.domain) next = 'connect_site';
  return {
    id: row.id,
    planId: row.plan_id,
    accountType: row.account_type,
    hostingIncluded: Boolean(row.hosting_included),
    domain: row.domain,
    hosting: row.hosting,
    revision: row.revision,
    site,
    managedSite: hosted,
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

export function openProjects(filename, { now = Date.now } = {}) {
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
  const byEntitlement = db.prepare('SELECT * FROM projects WHERE entitlement_id = ?');
  const ownerOfProject = db.prepare('SELECT account_id FROM projects WHERE id = ?');
  const projection = 'SELECT p.*, s.site_url, s.core_version, s.wordpress_version, s.verified_at, '
    + 'm.status AS managed_status, m.setup_domain FROM projects p '
    + 'LEFT JOIN project_sites s ON p.id = s.project_id LEFT JOIN managed_sites m ON p.id = m.project_id';
  const owned = db.prepare(projection + ' WHERE p.id = ? AND p.account_id = ?');
  const list = db.prepare(projection + ' WHERE p.account_id = ? ORDER BY p.rowid');
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
    if (!row.domain || row.hosting !== 'existing' || row.site_url) {
      throw new StartError(409, 'site_link_choice', 'Choose an unconnected existing WordPress site first.');
    }
    return row;
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
      return transaction(() => {
        const inserted = insert.run(
          randomUUID(), receipt.entitlementId, receipt.purchaseId, receipt.accountId,
          receipt.accountType, receipt.planId, Number(receipt.hostingIncluded),
        );
        const row = byEntitlement.get(receipt.entitlementId);
        if (row.purchase_id !== receipt.purchaseId || row.account_id !== receipt.accountId
            || row.account_type !== receipt.accountType || row.plan_id !== receipt.planId
            || row.hosting_included !== Number(receipt.hostingIncluded)) {
          throw new StartError(409, 'entitlement_conflict', 'This entitlement already has different purchase details.');
        }
        return { created: inserted.changes === 1, projectId: row.id };
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
        const record = consumeHandoff.get(digest(ticket), time);
        if (!record) throw new StartError(410, 'handoff_expired', 'Open the project again from your Kiwe purchase.');
        const row = ownerOfProject.get(record.project_id);
        const token = secret();
        pruneSessions.run(time);
        addSession.run(digest(token), row.account_id, time + browserLifetime);
        return { token, projectId: record.project_id };
      });
    },

    accountForSession(token) {
      return token ? checkSession.get(digest(token), now())?.account_id ?? null : null;
    },

    endSession(token) {
      if (token) endSession.run(digest(token));
    },

    listProjects(accountId) {
      return list.all(accountId).map(view);
    },

    getProject(accountId, input) {
      const { projectId } = parse(claimInput, input);
      const row = owned.get(projectId, accountId);
      if (!row) throw missingProject();
      return view(row);
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
        if (row.domain === domain && row.hosting === details.hosting) return view(row);
        if (row.managed_status) throw new StartError(409, 'hosting_already_requested', 'This project already has a hosting request.');
        if (row.revision !== details.revision) {
          throw new StartError(409, 'project_changed', 'The project changed. Reload it before saving.');
        }
        forgetSite.run(row.id);
        dropLinkAttempt.run(row.id);
        update.run(domain, details.hosting, row.id, accountId);
        return view(owned.get(row.id, accountId));
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
        return view(owned.get(row.id, accountId));
      });
    },

    siteLinkTarget(accountId, input) {
      return view(requireLinkTarget(accountId, parse(siteLinkInput, input)));
    },

    pendingSiteLink(projectId, revision) {
      const existing = pendingLink.get(projectId, revision, now());
      return existing ? JSON.parse(existing.connection) : null;
    },

    saveSiteLink(accountId, details, state, connection) {
      return transaction(() => {
        const row = requireLinkTarget(accountId, details);
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
        const row = requireLinkTarget(flow.accountId, flow);
        if (proof.url !== 'https://' + row.domain) {
          throw new StartError(409, 'site_domain_mismatch', 'The verified website must use the selected domain.');
        }
        if (attachedSite.get(proof.url)) {
          throw new StartError(409, 'site_already_linked', 'This website is already connected to a Kiwe project.');
        }
        confirmLink.run(row.id, proof.url, proof.coreVersion, proof.wordpressVersion, new Date(now()).toISOString());
        dropLinkAttempt.run(row.id);
        update.run(row.domain, row.hosting, row.id, row.account_id);
        return { ...view(owned.get(row.id, row.account_id)), accountId: row.account_id };
      });
    },

    close() {
      db.close();
    },
  };
}
