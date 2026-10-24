# Bounded SEO job state and leases

Date: 6 September 2026. Status: accepted for local RC62; native lab acceptance pending.

The earlier worker loaded every source ID and retained all proposals in one growing option. A request-local transient check was insufficient for concurrent work and expensive requests could be repeated after interruption. The desired outcome is resumable review on inexpensive shared hosting without accumulating a site's content in queue state.

Use a new non-autoloaded Kiwe JSON option with a bounded cursor, counters, twenty proposals and one 120-second lease. Discovery uses ordered ID pages of at most five against an initial maximum ID. Byte-exact CAS serializes store updates; a token fences late completions. No external work occurs inside a CAS callback. Uncertain expired work pauses for explicit recovery. Native WordPress remains the content authority; review writes and readback are not a distributed transaction with this queue.

Keeping the old serialized job would require loading potentially unbounded state before migration. It remains inert and preserved, with a notice; RC62 manages new jobs. A custom queue table or external service adds installation/operational complexity for the initial one-job-per-site flow and remains an option if durable histories or multiple concurrent scopes become a demonstrated requirement.

Permission, quota, cancellation, stale-proposal, partial-save, cron, rollback and validation consequences are maintained in the [RC62 contract](../SEO-JOBS-RC62-2026-09-06.md). Jobs do not introduce new role rights or a second content database. No changes to Key, Seam or published response schemas are required.
