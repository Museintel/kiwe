**Later resolution, 8 September:** The targeted RC55.2 SecureTrack correction is deployed; mainline RC70 remains local. [Native acceptance, recovery and deployment](SECURETRACK-DEPLOYMENT-2026-09-08.md). Historical investigation and local-only status below remain dated evidence.

# RC69 — stop SecureTrack schema repair during normal requests

8 September 2026. Local candidate 8.0.0-rc.69, uncommitted/unpushed on canonical main at 731a94ff48dd350bb7b168ec285fcc96afbdaa01. No production or lab deployment. RC68's completed workspace work remains included. Security schema acceptance takes priority over the paused feature sequence.

## Incident evidence and responsibility

The founder reports restoring Ascendants from Hostinger's 6 September backup, with some posts from 7–8 September lost and the site serving again. On 8 September at 16:39:44 UTC, independent bounded public GET checks returned HTTP 200 and an Ascendants title for both the cached homepage and a fresh query with cache=miss. The restored loader was read as RC55. Hostinger's WordPress inventory still returned its prior invalid-installation result; this discrepancy is not proof of a current healthy authenticated admin session.

The founder relayed Hostinger's report: its server was down; only the Ascendants database was affected by automated protection; permissions appeared restored; repeated ALTER TABLE operations involved wp_stp_events. The provider could not identify the initiating plugin/process while the server was unavailable. Treat these as attributed provider findings, not our independent server-log analysis. No exact query digest, process trace, native table inventory or lost-post recovery was obtained.

wp_stp_events is Kiwe SecureTrack's prefixed security event table. The source schema, native table-name service, direct ALTER helpers and dbDelta calls establish ownership. The restored site's SecureTrack_Db_Service.php was read and still contains the old automatic repair paths. It is incorrect to rule Kiwe out as solely a hosting issue.

The old maybe_install runs at plugins_loaded. Version mismatch or missing/unreadable schema triggers a full install behind a get/set transient lock with a 60-second expiry. Event logging also invokes full repair when storage readiness fails and after a failed session insert. That repair bypasses the install lock, runs stp_create_tables and calls the migration a second time. The old create routine records a schema version before confirming success. These paths can amplify a database outage, SQL error or concurrent requests into repeated schema work.

A concrete idempotency defect was reproduced using the actual dbDelta function read from Ascendants' wp-admin/includes/upgrade.php. The aligned CREATE definitions have multiple spaces between column names and types; dbDelta's type parser expects the single separator used by WordPress schema declarations. Matching synthetic table metadata caused 19 CHANGE COLUMN statements for stp_events on each invocation, in both MariaDB 10.11 and MySQL 8 metadata scenarios. This is an executable code defect, not proof that these exact statements triggered Hostinger's restriction. The [WordPress dbDelta source](https://developer.wordpress.org/reference/functions/dbdelta/) documents its comparison and ALTER generation. Integer display-width normalization also matters on MariaDB.

## Resulting behavior

- Normal visitor, administrator, publishing, logging and cron requests do not create, alter or repair SecureTrack tables. The compatibility bootstrap function is inert. Missing storage skips the affected event write; failed session creation is attempted once without schema repair. Existing enforcement rules remain in place; unavailable storage can reduce event recording and other storage-dependent behavior.
- Existing schema creation/migration/helper functions require the active maintenance coordinator. The owner uses the existing SecureTrack settings area with a nonce-protected POST, or an operator runs WP-CLI with the explicit KIWE_SECURE_SCHEMA_MAINTENANCE=true constant. Plain CLI, GET, AJAX, nonces without permission and arbitrary event calls do not acquire this authority. Fresh MU installs now require this explicit setup step before storage-dependent tracking is ready.
- A per-database/prefix MySQL advisory lock is acquired with zero wait. It remains held by the connection throughout maintenance; it does not expire after 60 seconds. Each DDL statement checks connection ownership, and loss of the lock stops DDL. Failure to obtain the lock denies maintenance.
- A small non-autoloaded stp_schema_maintenance option records status, start timestamp and schema version. Every attempted run has a 15-minute cooldown. There is no scheduled retry or automatic latch clearing. Failed or interrupted runs need operator review; the next explicit attempt still checks the lock and cooldown. Status and storage availability appear in the existing settings area.
- Query errors stop subsequent maintenance queries. Schema readiness is refreshed before the version is recorded. Failures do not claim completion or advance the schema version. Advisory locks and request-local guards release in finally. MySQL DDL is not transactional: an interrupted run may have applied a subset of valid schema changes and must be inspected before retrying.
- CREATE field declarations use parser-compatible spacing/types and MariaDB-compatible integer display widths. Matching schemas produce zero alterations in the same WordPress parser proof. Equivalent canonical indexes satisfy the old migration aliases instead of adding redundant indexes. Existing redundant indexes are preserved; no index, table or event is dropped.
- Table metadata checks use escaped LIKE patterns and prefix-specific request caches. Missing metadata is briefly cached as unavailable. A requested refresh actually clears negative table-existence results.

This changes schema execution policy, not the stored event/content schema. It does not delete, rename or restore WordPress tables, articles, users, settings, security events or Key credentials. It does not recover articles lost through the user's backup restoration.

## Verification

The focused coordinator suite passed 24 behavior cases plus one explicit CLI case and affected-service syntax/entry-point checks. It executes the real schema service and event logger with synthetic WordPress/SQL boundaries: ordinary-request zero-DDL, unavailable storage, no session repair retry, GET/nonce/role/AJAX denial, explicit CLI entry, lock contention/loss, cooldown, SQL errors, exceptions, native readback and equivalent-index handling. An initial test-only typo (return1) was corrected; the affected tests then passed. The grouped baseline passed all 60 gates on its first RC69 run, with unchanged source during the run.

The parser proof executes the site's actual WordPress function against bounded synthetic metadata, not a real MariaDB/MySQL server. All 20 table/server cases passed after the fix: 245 unnecessary schema statements across the before cases became zero after; each stp_events case changed from 19 to zero. Metadata fixtures model MariaDB 10.11 and MySQL 8; this does not claim those database servers executed. Node 22.23.1 and PHP 8.4.23 executed locally. PHP 8.2, native database lock/reconnection behavior, concurrent migrations and the changed maintenance form in an authenticated browser remain unverified.

Baseline receipt: baseline-1788886480934-ebb0a94d-1c3b-4be7-bd1d-19d88ca3be03.json. Parser evidence: rc69-evidence/parser-before.json, parser-after.json and parser-comparison.json. The final maintained-document link check and package handoff accompany the exported review copies. No unchanged workspace browser tour or repeated full baseline was run.

Immutable unsigned local artifact: kiwe-rc69-lab.zip, 1,555,994 bytes, 384 entries and 381 manifest files. SHA-256: 199cfe6dbd4d07ad1e2c51c97753c0dcfcb34518cf832e9db56969f558a763cd. The archive builder verified every entry against source. No signing, commit, push, production backport or deployment occurred.

Commands from canonical source:

```text
node tools/release/securetrack-schema-contracts.cjs
php tools/release/test-securetrack-dbdelta.php <external>/ascendants-dbdelta-function.php <external>/rc69-evidence/securetrack-core-before.php
php tools/release/test-securetrack-dbdelta.php <external>/ascendants-dbdelta-function.php
node tools/maintenance/run-reserve-task.mjs baseline --out <external>/reserve-evidence
node tools/maintenance/run-reserve-task.mjs docs --out <external>/reserve-evidence
```

The first parser comparison is expected to fail and preserves the regression evidence. The WordPress function is held outside source; its SHA-256 is d159fa9847454f6f987417e8b7ff0a601f2b68cde736e8f2aa3be5ebf9e80c9c. No production database query was executed by these tests. Evidence folder: C:/Users/shariq/Documents/Codex/2026-09-05/im/work/rc69-evidence.

## Deployment and next action

RC69 is not installed. Ascendants still has the old RC55 repair behavior after its restore. Do not describe the local correction as live prevention. Before production rollout, run the corrected maintenance coordinator on isolated native WordPress/MariaDB fixtures, confirm repeated no-op schema checks, real connection-lock contention, error handling, PHP 8.2 and preserved site/security behavior. Use a current private recovery point and a new immutable package/version; never overwrite files under the existing RC55 release identity. A targeted RC55 backport may be preferable to deploying all pending dashboard work to a recently restored production site and needs its own package and acceptance record.

No table drop, rename or speculative database optimization is part of this fix. Do not click schema repair on the old installed build. Request the exact slow SQL statements from Hostinger if incident correlation is needed. No further support message was sent in this batch.

After native schema acceptance, resume the grouped RC65–RC68 workspace acceptance on the disposable lab, then business-form convergence. SEO additions remain deferred. [Incident timeline](ASCENDANTS-INSTALL-SCREEN-2026-09-08.md), [architecture decision](decisions/2026-09-08-explicit-securetrack-schema-maintenance.md), [maintenance contract](MAINTAINER-CONTRACT.md).
