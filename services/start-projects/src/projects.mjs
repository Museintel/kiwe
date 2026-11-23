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
const projectOutput = z.strictObject({
  id: z.uuid(),
  planId: id,
  accountType: z.enum(['client', 'agency']),
  hostingIncluded: z.boolean(),
  domain: z.string().nullable(),
  hosting: hostChoices.nullable(),
  revision: z.number().int().positive(),
  next: z.enum(['choose_domain', 'provision_site', 'connect_site']),
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
  return {
    id: row.id,
    planId: row.plan_id,
    accountType: row.account_type,
    hostingIncluded: Boolean(row.hosting_included),
    domain: row.domain,
    hosting: row.hosting,
    revision: row.revision,
    next: !row.domain ? 'choose_domain' : row.hosting === 'included' ? 'provision_site' : 'connect_site',
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
  `);
  const byEntitlement = db.prepare('SELECT * FROM projects WHERE entitlement_id = ?');
  const ownerOfProject = db.prepare('SELECT account_id FROM projects WHERE id = ?');
  const owned = db.prepare('SELECT * FROM projects WHERE id = ? AND account_id = ?');
  const list = db.prepare('SELECT * FROM projects WHERE account_id = ? ORDER BY rowid');
  const insert = db.prepare(
    'INSERT INTO projects(id, entitlement_id, purchase_id, account_id, account_type, plan_id, hosting_included) '
    + 'VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(entitlement_id) DO NOTHING',
  );
  const update = db.prepare(
    'UPDATE projects SET domain = ?, hosting = ?, revision = revision + 1 '
    + 'WHERE id = ? AND account_id = ? RETURNING *',
  );
  const addHandoff = db.prepare('INSERT INTO handoffs(digest, project_id, expires_at) VALUES (?, ?, ?)');
  const consumeHandoff = db.prepare('DELETE FROM handoffs WHERE digest = ? AND expires_at > ? RETURNING project_id');
  const addSession = db.prepare('INSERT INTO sessions(digest, account_id, expires_at) VALUES (?, ?, ?)');
  const checkSession = db.prepare('SELECT account_id FROM sessions WHERE digest = ? AND expires_at > ?');
  const endSession = db.prepare('DELETE FROM sessions WHERE digest = ?');
  const pruneHandoffs = db.prepare('DELETE FROM handoffs WHERE expires_at <= ?');
  const pruneSessions = db.prepare('DELETE FROM sessions WHERE expires_at <= ?');

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
        if (row.revision !== details.revision) {
          throw new StartError(409, 'project_changed', 'The project changed. Reload it before saving.');
        }
        return view(update.get(domain, details.hosting, row.id, accountId));
      });
    },

    close() {
      db.close();
    },
  };
}
