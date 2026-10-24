# Explicit SecureTrack schema maintenance

8 September 2026. Status: accepted local implementation; native deployment acceptance pending.

## Problem

SecureTrack's event and bootstrap paths could run dbDelta after failed runtime reads/writes. Formatting mismatches generated repeated ALTER statements on matching table metadata. A short non-atomic transient lock did not cover event-path repair and could expire while DDL was running. Hostinger reported repeated ALTER operations on Kiwe's events table during the Ascendants incident.

## Decision

The existing SecureTrack database service owns schema changes only during explicit, authorized maintenance. Normal requests never initiate or retry DDL. Use a connection-scoped advisory lock, verify ownership before DDL, persist a bounded attempt status/cooldown, stop on SQL errors and confirm readiness before recording schema completion. Retain native POST and an explicitly opted-in WP-CLI path. Expose status and initial setup in the existing SecureTrack settings area.

## Tradeoffs and compatibility

Fresh installs and upgrades that require schema changes now need an explicit setup step. This is preferable to unbounded self-repair or automatically moving the same expensive DDL into WP-Cron, which would still share the production database and hosting limits. Missing storage can reduce event recording; enforcement configuration is not silently disabled. An advisory lock serializes Kiwe maintenance only; unrelated plugins/external SQL can still operate on the database. Real database acceptance must establish hosting support for the lock.

stp_schema_maintenance stores only status, start time and version; no credentials, customer records or raw SQL errors. Existing content, security records and schema semantics remain intact. No new public endpoint or role. No automatic deletion of failed-attempt evidence or older indexes. A rollback to old code restores the risky automatic repair behavior, so prefer a forward correction. Partial DDL is not rolled back automatically.

## Verification and rollout

[RC69 evidence and rollout contract](../SECURETRACK-SCHEMA-RC69-2026-09-08.md) distinguishes synthetic coordinator tests, actual WordPress parser tests, live source reads and native database acceptance. Key, Seam and Design Context schemas are unaffected. Product Vision and founder capabilities are unchanged; the incident record captures the new operational evidence.


Native acceptance refinement (RC70): dbDelta intentionally DESCRIBEs nonexistent tables. Stop failed writes, permit discovery probes, and require final readiness; the previous blanket query-error guard prevented fresh setup. Actual PHP 8.2/MariaDB creation, zero-DDL repeats and independent connection contention passed. [Native and production record](../SECURETRACK-DEPLOYMENT-2026-09-08.md).
