**21 October C103 source follow-up — not yet implemented:** C102 finds that `Template_Workspace_Service::template_caps()` omits delete primitives for Admin/Super Admin, and native page renames can return `invalid_page_template` after persisting a title when an obsolete Elementor template is saved. Qualify narrow manager template Trash/restore permissions through existing lifecycle owners without broadening Designer/Author/Guest access or changing linked-page protection. Handle obsolete builder page-template state in the existing rename path with before/readback/stale protection; preserve valid templates and content. Prepare source/lab acceptance before any runtime deployment. [Operational evidence](PAGE-TEMPLATE-CLEANUP-2026-10-21.md).

**Current checkpoint - RC89 lab acceptance:** Read [Batch 6](WORKSPACE-LAB-BATCH6-2026-09-11.md) before using older recipes. A suitable next bounded reserve task is a read-only Home destination inventory: map each native task URL to its registered screen and intended state, report older-form destinations and lost creation/review filters, and return source paths with no runtime edits or deployment. Lead reviews the findings before the next UI batch. Do not rerun unchanged native fixtures or create fresh live data merely to reproduce the completed acceptance.

**Batch 5 repeatable preflight:** Run the explicitly assigned PHP 8.2 runner described in [Batch 5](WORKSPACE-PREFLIGHT-BATCH5-2026-09-11.md) with an external output directory. Return receipt.json with passed, cleanup and unchanged source hashes; never sign, deploy, enable debugging or repair source as part of a verification-only reserve task. The runner rejects a different PHP minor version and uses disposable local files, not live website data.

**Batch 4 addition, 11 September:** For a narrowly assigned follow-up, run the workflow-app-contracts.cjs service gate and build-native-workflow.cjs --check artifact gate without editing or deploying; return exits and the exact source state for lead review. The green baseline now includes both. [Scope and native limitations](GUEST-NOTIFICATION-COMMERCE-BATCH4-2026-09-11.md).

**Current UI build, 10 September:** [One DSA Menu, Guest work and notification coverage](GUEST-MENU-NOTIFICATION-COVERAGE-2026-09-10.md) correct the invented two-menu UI, add applications/review/own Guest contributions, and expose all 19 built-in notification IDs conditionally with per-type sample channel choices. Administrator Home has 16 destinations; role/site-specific views differ. 180 browser checks and 22 sample boundary checks passed; a 22-area source/coverage ledger records missing journeys. The existing bounded server notification queue was found; the prior claim that only session history exists is corrected. Native role/read-history integration and per-type channel persistence remain pending. Runtime RC83 guard off; lab RC82; Ascendants RC55.4. No runtime/package change, signing, commit, push or deployment.

# Small tasks for a reserve session

**RC63 focused recheck:** node tools/maintenance/run-reserve-task.mjs score --out <absolute external evidence directory> runs changed-service lint, score behavior with Woo present/absent, existing job/onboarding/native-context checks and package verification. Only run after relevant changes or an explicit handback task. No provider calls, native deployment or browser claims. Next product phase is shared DSA/client-dashboard UX after one combined native acceptance pass.

**RC62 focused recheck:** node tools/maintenance/run-reserve-task.mjs seo --out <absolute external evidence directory> runs changed SEO service lints, onboarding/owner handoff contracts, bounded SEO behavior, AI permission/broker checks and package verification. Use only after relevant source changes or an explicit handback task; do not spend a session repeating unchanged gates. Current SEO/job and AI services are included in the syntax recipe. Lead reviews the receipt and any failed/changed cases; no provider calls or deployment are part of this recipe.

Give a reserve session this document. It works with any available model; it does not require a particular account reserve or an automatic model switch. The task boundary, evidence and subsequent review determine trust.

## Session instruction

Read `AGENTS.md`, `HANDOFF.md`, the current [RC71 lab checkpoint](WORKSPACE-LAB-RC71-2026-09-09.md), the historical [RC68 native task build](WORKSPACE-ORGANIZATION-RC68-2026-09-08.md), this file, the [RC66 task build](WORKSPACE-TASKS-RC66-2026-09-08.md), and the earlier [RC58 Design library and Navigation](DESIGN-LIBRARY-NAVIGATION-2026-09-06.md), the preceding [content operations batch](CONTENT-OPERATIONS-BATCH-2026-09-06.md), and [RC58 acceptance](RC58-LAB-ACCEPTANCE-2026-09-06.md) for current lab state. Preserve existing edits. Pick one READY task below, run exactly its named recipe, inspect the result and hand it back. Do not improvise a repair when verification fails. Do not commit, push, deploy, change credentials, enable policies, contact customers or spend money as part of these recipes. Those operations require a separate concrete task and its existing authorization.

These READY recipes are read-only with respect to product source. The runner records source hashes before/after, baseline revision, dirty paths, runtime, commands and exits in a unique JSON receipt. A source change during a run makes its result inconclusive. A pass means only the named checks passed, not release approval.

From the canonical Kiwe repository:

```powershell
node tools/maintenance/run-reserve-task.mjs --list
node tools/maintenance/run-reserve-task.mjs workspace --out C:\path\outside-repository\reserve-evidence
```

Use an existing authorized output directory outside all three repositories. Never paste credentials, raw customer records or private provider output into evidence.

| ID | State | Bounded work | Expected handback |
| --- | --- | --- | --- |
| `retention` | READY | Run 37 real-callback retention budget/failure checks, native-fixture syntax and package integrity | Local receipt only; no SQL on a website, cleanup deployment or automatic repair |
| `pwa` | READY | Execute the generated PHP worker and client-retirement regression harness, existing push/PWA contracts, native-fixture syntax and package integrity | Local source-bound receipt only; do not change settings, register workers on a customer browser, or claim native/provider/physical-device acceptance |
| `schema` | READY | Execute the explicit schema coordinator and event-failure regressions plus affected PHP syntax | Source-bound receipt; no native queries, deployment or automatic repair |
| `workspace` | READY | Execute category/team, native-form stale/readback and actual form script behavior, Home/role, setup notices, Key-disabled behavior and page REST/script behavior | Receipt; failing check names or passes; no automatic fix |
| `content` | READY | Execute page-title/native-page and product-notification behavior, including Woo-disabled mode | Receipt; capability, preference and native-field preservation evidence; no real delivery |
| `context` | READY | Execute selection/navigation native-writer and REST boundaries plus the shipped task script’s save, stale, timeout and reversal behavior | Source-bound receipt; synthetic WordPress I/O only; no browser or deployment |
| `features` | READY | Execute real broker/policy/role/handler/render/sanitizer with synthetic WordPress and transport boundaries | Source-bound permission, denial, native-write and zero-call evidence, broker contracts/lint and package verification; no native/browser certification |
| `tokens` | READY | Execute shared request/token allowance, permission and broker behavior plus package verification | Source-bound synthetic accounting and permission evidence; no provider/native/browser certification |
| `allowance` | READY | Execute shared request/token accounting, settlement, synthetic concurrency, provider failure and cooldown behavior | Source-bound receipt; no native MySQL/provider/browser certification |
| `syntax` | READY | Lint the changed workspace PHP services using the installed PHP runtime | Receipt with actual PHP version; do not infer PHP 8.2 compatibility from PHP 8.4 |
| `docs` | READY | Check relative links in the maintained vision, handoff and workspace documents | Receipt and broken-link paths; propose corrections for review |
| `baseline` | READY | Run the existing full Kiwe green baseline once on the present source snapshot | Receipt; distinguish package-manifest drift from a code regression; never regenerate automatically |

Check the latest batch record before executing: named recipes may already have passing evidence. Do not repeat unchanged checks just to occupy a reserve session, or run every row independently when the combined baseline already covers them. A READY recipe is available when needed, not a standing request to rerun it.

## Lead review after handback

1. Read the receipt; compare its revision and source hash with the current tree. A stale receipt stays historical.
2. Inspect any proposed file changes and their allowed scope. This queue authorizes evidence generation only.
3. Reproduce failures before fixing them. Review boundary and integration implications independently.
4. Run additional checks only for a new change, a failure or an unresolved concern. Do not repeatedly spend a session rerunning identical green evidence.
5. Record the decision and next task in the owning handoff/engineering record.

## Template for the next small implementation task

Prepare a concrete task before assigning implementation work: purpose; prerequisites; exact allowed files; maximum scope; inputs; one expected behavior; forbidden side effects; exact acceptance command; expected output; stop conditions; recovery; and handback path. Prefer deterministic scripts for repeated data extraction, validation and comparison. Never ask a smaller session to invent authentication, payment, role, migration or architectural decisions from a vague goal.

Handback format: task ID, state (passed/failed/inconclusive/blocked), baseline revision/source hash, changed files, commands and receipt path, what the evidence proves, what remains unverified, and one next action. The lead retains release and architecture review; completing a small task does not establish the whole feature's readiness.

## Publishing incident read-only handback

Only when a fresh public REST check is needed, run node tools/maintenance/check-wordpress-rest.mjs https://ascendants.in/ once and hand back its JSON result. This is two public GET/OPTIONS requests with no authentication or writes. Never infer that publishing is repaired from a pass, enable debug logging, change plugins or repeat the check continuously. The [incident record](ASCENDANTS-PUBLISHING-INCIDENT-2026-09-08.md) owns remaining evidence and access needs.



**RC76 bounded continuation:** Rerun local workspace entry/Home contracts only after relevant changes. Native staff proof lives in tools/release/test-wordpress-workspace-entry.php and requires a disposable WP/Woo CLI harness with parsed passed/cleanup receipts. Never run it on Ascendants. Browser checks must use normal staff login, not forged session cookies; preserve enrollment barriers. Finish wider DSA/PWA and operation acceptance from the first-launch plan. Do not claim simple-product acceptance covers variable products/orders, or that contributor login proves approved Guest submission.


## Optional telemetry and worker budget recipe — RC81

Run node tools/maintenance/run-reserve-task.mjs telemetry --out <absolute-outside-repository-directory>. It executes real callback/event code and the denial helper against synthetic boundaries, lints the three runtime PHP files and the native fixture, and verifies the package. Expected: 43 telemetry checks, 12 denial-worker checks, clean syntax and a matching 381-file manifest. No hosting requests, settings changes, load generation or engine disable. This local recipe does not prove PHP 8.2/native WordPress throughput or real passkey/provider success. Production is verified RC55.3 with only optional visitor/page tracking disabled; do not revive the withdrawn whole-engine off-switch task. Native receipts are in the RC81 report; this recipe generates no live traffic.


## Retention recipe — RC82

Run node tools/maintenance/run-reserve-task.mjs retention --out <absolute-outside-repository-directory>. It covers deletion/query budgets, unavailable locks, reentry, query errors, child-delete errors, elapsed time and disabled retention. It performs no live deletion. A baseline receipt already includes its 37 behavior checks; rerun only for a changed source or a failed check. Native data preservation requires the separate temporary-table fixture and the current RC82 report.


## Tile-shell continuation boundary

The prototype in tools/workspace-prototype is isolated sample tooling, not a replacement deployable package. After a relevant change, a bounded reserve task may install its locked dependencies, build it and report exact asset output or broken doc links. Never wire authentication, change live roles, copy prototype sample data into WordPress or declare native persistence from this preview. The lead owns the server-gated shell and Woo owner/client permission changes. [Decision and required gates](decisions/2026-09-10-tile-workspace-shell.md).


### RC83 bounded UI checks

Run the shell recipe only after a relevant source change: node tools/maintenance/run-reserve-task.mjs shell --out <absolute external evidence directory>. It checks actual shell/role behavior with synthetic WordPress I/O, PHP syntax and the current package; it does not enable a host feature or prove native media/login behavior. Stop and hand back failures without weakening checks. The lead reviews the source-bound receipt. The existing tools/workspace-prototype/audit-context.cjs inventories static native field declarations without reading client data; it is a minimum inventory, not a claim of complete workflow coverage.
