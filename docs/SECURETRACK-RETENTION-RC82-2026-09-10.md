**Production follow-up:** The targeted RC55.4 backport is now installed and verified on Ascendants. [Deployment, recovery and lab dashboard setup](RETENTION-DEPLOYMENT-RC554-2026-09-10.md). The earlier production-pending status below is historical.

# RC82 — bounded SecureTrack retention

10 September 2026. Local and installed lab RC82 follow the [RC81 traffic correction](TRAFFIC-RELIABILITY-RC81-2026-09-10.md). Ascendants remains verified RC55.3 with visitor/page analytics off and SecureTrack/Key enabled. This retention change is not yet deployed or verified on production.

## Behavior and ownership

The existing daily stp_cron_cleanup callback remains the sole scheduled cleanup owner. It uses the original green/yellow retention settings, fixed 14-day green session age, 30-day completed queue age and newest-1,000 pending queue policy. There are no new client controls, shadow options, provider calls, schema migrations, automatic optimization, request-time cleanup hooks or rapid retry loops.

One callback obtains a database/site-specific GET_LOCK with zero timeout. A busy/unavailable lock skips the work; same-connection recursion is also rejected. Owned locks release on completion, early budget exit and SQL failure. The callback returns a small in-memory status/deleted count for verification, without persisting a diagnostic for every run.

| Work | Maximum deleted in one pass |
| --- | ---: |
| Green events past configured retention | 500 |
| Yellow events past configured retention | 500 |
| Pages for eligible green sessions | 1,000 |
| Pages for eligible yellow sessions | 1,000 |
| Eligible, child-free green/yellow sessions | 100 each |
| Old reviewed/error/local-only AI queue entries | 500 total |
| Pending AI entries older than the newest 1,000 | 500 |
| Expired rate-limit counters | 1,000 |

At most 5,200 deleted rows and fourteen SQL calls, including lock acquisition/release, occur in a complete pass after configuration is read. A two-second elapsed budget is checked before starting each retention query. It is not a query timeout: it cannot interrupt running SQL, limit rows examined, prevent host/database lock waits or guarantee concurrency capacity. Limits reduce mutation and transaction size; large-table query-plan and full-bootstrap profiling remain necessary.

Each session color selects at most 100 old session IDs. Page deletion is capped and rechecks eligibility. Parent deletion uses the same IDs, rechecks status/activity and requires NOT EXISTS any remaining page. A session with more than a page batch survives for a later run. This fixes the reproduced capped-child/unconditional-parent defect; it does not add foreign keys or promise transactional protection against every possible concurrent writer. Red sessions/events and active counters are excluded.

Backlogs wait for a later daily run. This deliberately reduces cleanup throughput; a large backlog can take several runs and retention ages are eligibility thresholds, not guaranteed deletion deadlines. Do not compensate by looping inside a public request. Existing orphan rows are not scanned or repaired by this release.

## Verification and deployment

The real callback against synthetic database/time boundaries passes 37 checks: row/query caps, lock contention/failure, query failure, page-delete failure, elapsed budget, same-connection recursion and disabled retention. Against RC81, the new contract failed and recursive entry did not complete. The new native fixture uses connection-local temporary tables from the installed candidate’s schema definitions and temporary options/cache; it seeds only synthetic data, exercises actual cleanup SQL and removes the temporary tables without changing normal site options.

All 64 local release gates passed after adding the missing RC82 changelog entry. The runtime package has not changed since that baseline. Native acceptance subsequently passed all 19 checks on PHP 8.2.33 / WordPress 7.1. The final lab version is RC82. Do not report a lab install from archive upload alone. Candidate archive: 1,560,736 bytes; 384 entries/381 manifest files; SHA-256 2d5e4f5ed28148486176a7b2110ade32126f1d8f3a344ed37391f0fbc9bad6b3.

Use the retention reserve recipe for the small repeatable local check, then require a parsed native passed=true and cleanup=true receipt before accepting the lab. Keep one previous package directory; the founder's disposable-lab exception means no repeated full backup. A future production backport needs its own immutable version and reviewed delta; RC55.3 remains frozen.

## SQL basis

The implementation uses single-table DELETE LIMIT; the limit is a maximum deleted-row count, not a maximum scanned-row count. See the [MySQL DELETE reference](https://dev.mysql.com/doc/refman/8.0/en/delete.html). Cooperative connection locks are explicitly released and use zero timeout; see [MariaDB GET_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock). These references support the chosen mechanisms, not an unmeasured site-performance claim.

Next after native acceptance: measure full bootstrap and enabled security-event cost, review remaining background work, and resume supported identity/provider and launch journeys. No extra SEO feature phase is implied.


The first native attempt failed during temporary-table setup, before cleanup executed. Settings/cache and temporary tables were restored (cleanup=true), and deployment automatically restored RC81. The exact failed clone was not identified by the first receipt. The fixture now derives each temporary table from the installed candidate’s schema definitions rather than depending on a provisioned table for a normally disabled lab module. This changes test setup only; it does not initialize or repair normal site tables.


## Final native result

At 20:11 UTC on 9 September (10 September IST), the corrected native fixture passed 19/19 checks with cleanup=true. Three explicit test invocations deleted 4,003, then 1,252, then zero synthetic rows; normal scheduling remains daily. The first pass retained 501 pages and their parent; the next removed the remaining children and retired that parent. The promotion/activity race fixture preserved both affected sessions and pages. All red/recent events, active counters, processing queue items and newest 1,000 pending items survived according to policy. No orphan page remained in the fixture.

All six temporary tables were removed; normal options, database prefix and request cache were restored. DSA settings digest, original security/identity settings and debug-off policy were preserved. The lab SecureTrack module remains normally disabled and Key enabled, as before the test; loading cleanup code inside CLI did not enable the live engine. A cache-miss public homepage returned 200 and expected site content without a database error. This is not a physical login or load-test result.

Evidence: task workspace work/rc82-evidence/ holds package, local, failed-first-fixture, app-result, after-result, job-result and public receipts. The baseline receipt is baseline-1788984395539-2eecca49-2f91-40fa-8f9e-1f382109c544.json. Production remains RC55.3; retain the pinned RC55.3 builder and prepare a new immutable backport version rather than reusing it with changed inputs.


Final helper cleanup passed at 20:13 UTC: 19 exact helper/archive files were removed across the two attempt directories, including the cleanup helper. Protected sanitized receipts and private previous-package directories remain. The temporary maintenance job was removed; no background monitor was created. Final maintained-document links and whitespace checks passed.
