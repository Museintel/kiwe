# RC65 — shared workspace Home and native page naming

8 September 2026. **Local source/package: 8.0.0-rc.65. Lab: RC64.** The full local baseline passed. Hostinger's upload-credential service returned HTTP 500 twice; the earlier credential returned 403. No RC65 lab package swap or cron job was started. Ascendants is unchanged. Source stays uncommitted/unpushed; this is not a signed release.

## Delivered scope

The client Home now uses a small projection of the existing DSA/Seam token registry for typography, surfaces, spacing and radii. It shows native business identity, task cards and a publishing overview only for actors allowed to inspect other authors. Authors and Guests do not request those site-wide counters. Technical provider/configuration status is removed from Home. The technical owner and a site with ownership policy disabled retain the native WordPress dashboard.

Phone navigation uses ordinary Home, Website/Products/Posts/Guest, Alerts and All tasks links, selected according to capability. It retains native deep links/history and requires no JavaScript router. Native WordPress navigation remains available. A menu-label defect was repaired: mutating a cast copy of the menu array did not reliably rename the actual Home entry.

Existing Page details is the single editing home for page names. A small script enhances its native POST form with unsaved, saving, saved and error feedback, undo, duplicate-submission prevention and an unsaved-navigation prompt. Requests have a 15-second client wait limit. A failed, malformed or interrupted response retains the entered text and does not report success. An uncertain outcome tells the user to reload and inspect the current name before retrying; no automatic retries or offline queue.

`POST /dsa/v1/workspace/pages/{id}/name` calls the existing Page_Workspace_Service native writer. WordPress cookie authentication and the REST nonce protect browser calls; the route has argument validation and a permission callback. The client role REST boundary admits only this specific POST operation. It grants no general page/Bricks editor capability. The writer accepts title and expected title, preserves the native slug, ignores forged content/layout fields, rejects a stale name and reads the result back. Responses are private/no-store. WordPress remains the record owner; there is no new table, option or parallel page inventory.

The pre-write expected-title check is not a database-wide atomic compare-and-swap. Truly simultaneous external edits can still race between checking and writing. The UI gives confirmed native readback and stale-form protection, not a promise of serializable multi-editor transactions.

## Source and architecture

- [Workspace design projection](../wp-content/mu-plugins/dsa/includes/Access/Workspace_Design_Service.php), [shared primitives](../wp-content/mu-plugins/dsa/assets/css/app-primitives.css), [Home/navigation](../wp-content/mu-plugins/dsa/includes/Access/Workspace_Admin_Service.php).
- [Native page task and REST adapter](../wp-content/mu-plugins/dsa/includes/Access/Page_Workspace_Service.php), [role boundary](../wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php), [page-name interaction](../wp-content/mu-plugins/dsa/assets/js/workspace-page-name.js).
- [Accepted app architecture](APP-EXPERIENCE-ARCHITECTURE.md) and [client task boundaries](CLIENT-WORKSPACE-TASKS.md).

The workspace uses neutral registry defaults for legibility; site-specific public DSA theme overrides are not automatically copied into administration. Public DSA components and all other task screens have not yet been visually converged. Shared tokens are the first step, not a completed universal component system. The Home shell adds no JavaScript; the page-name script loads only for authorized Page details edits. Neither adds visitor JavaScript or exposes private state to the visitor PWA.

## Verification and cost

| Check | Evidence |
| --- | --- |
| Complete local release baseline | 59 gates passed, Node 22.23.1 / PHP 8.4.23 |
| Home behavior | 13 checks: role-specific rendering, actual native-menu array mutation, bounded counters and conditional style loading |
| REST adapter | 18 checks in addition to existing policy/page tests: denial, narrow routing, native-field preservation, stale edit and readback failure |
| Actual page-name script | Confirmed response, undo, duplicate click, stale/server error, offline/malformed/wrong-object response, timeout and navigation protection |
| Changed PHP services and native fixture | Local lint passed |
| Browser layout | Synthetic Home rendered from actual service and styles at 320, 390, 820 and 1440 CSS pixels; phone overflow absent, dock targets 62px high |
| Native WordPress/PHP 8.2 acceptance | Prepared but not run for RC65 because upload service failed |

The preview uses synthetic WordPress I/O and simplified admin chrome, without WordPress's icon font. It verifies the new content layout, not native admin-menu collisions, restricted-role sessions or cookie-authenticated saves. Native browser keyboard/focus, reduced-motion, actual page-save and mobile chrome acceptance remain pending. RC64 evidence must not be relabeled as RC65 evidence.

Baseline receipt: `baseline-1788806152907-8d8dc043-fe7c-49d0-83b0-ea413e015580.json`; source hash `ae4a50225a9b03f82af7584d9f67b3d4fd8e504fa2956071918326b2d46c48cc`. Documentation updates after that receipt do not alter its verified runtime package.

Package: 375 manifest files; ZIP 1,544,766 bytes; SHA-256 `bda8901496dc98486ba27b898acac6d20838e38dab165995956007e567408011`. Asset sizes are file measurements, not observed HTTP compression: primitives 1,776 bytes / 669 gzip; Home stylesheet 11,342 / 2,439; Page details stylesheet 2,753 / 1,040; page-name script 3,050 / 1,201. Registry projection is inline. No AI or delivery call is needed for these tasks. Whole-request CPU/network/interaction budgets remain to be measured on the native lab.

## Repeatable acceptance and next work

Run the existing `workspace` reserve recipe only after relevant changes; it now includes [Home/REST/script behavior](../tools/release/workspace-app-contracts.cjs). The full baseline already includes it, so do not repeat every recipe after a green unchanged run.

The [focused native fixture](../tools/release/test-wordpress-workspace-app.php) requires an explicit CLI test-site/version wrapper, blocked outbound/scheduled work and the complete RC65 package. It dispatches through real WordPress REST, uses temporary synthetic users/page records and process-local ownership policy, and removes those records in finally. It deliberately leaves one clearly named draft for the existing lab administrator's browser acceptance; its ID is in `browserFixture`. Delete that exact draft after the browser run. A fixture receipt must parse passed=true and cleanup=true with all checks present and no unexpectedExit; process exit zero alone is insufficient.

The task's external work directory contains the RC65 ZIP and prepared stage/state/swap/rollback/receipt wrappers. Future deployment must first obtain valid Hostinger upload credentials, confirm the lab is still RC64, upload denial rules before helpers, lint the staged candidate on PHP 8.2, retain the previous package, inspect native receipts, exercise the page-name form in the browser, delete the named draft/helpers/cron, and compare settings/policy readback. No routine SQL/ZIP backup of this disposable lab is required.

Next: finish RC65 native acceptance when uploads recover, then extend the same small task-state pattern to native Navigation and Design library selection. Also address the long account-setup notice with accessible priority/details treatment, without removing security guidance. Full DSA/workspace component convergence and performance measurement remain active work. SEO additions stay deferred.
