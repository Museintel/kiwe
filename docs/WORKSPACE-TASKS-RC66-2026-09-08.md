# RC66 — reactive Navigation and Design library

8 September 2026. Local candidate: 8.0.0-rc.66, on canonical Kiwe main at the existing 731a94f baseline, uncommitted/unpushed. The disposable lab remains RC64; Ascendants remains RC55. No hosting change or deployment is part of this build. The founder asked to resume development after suspecting a Hostinger outage. That cause remains unverified; the [publishing incident](ASCENDANTS-PUBLISHING-INCIDENT-2026-09-08.md) is paused, not declared repaired. WordPress debugging stays disabled on all nine sites.

## Client outcome

Navigation label edits and Design library selections now have explicit saving, saved, unsaved and error states without a full page reload. These existing task screens use the small shared Home/DSA token projection, neutral cards, responsive grids, large buttons and accessible live feedback. Ordinary links, history, source search, paging and specialized native editing remain available.

Renaming a menu link updates its displayed title and references to it as a parent after native readback. Clearing a custom override follows the source name. Text being edited in another card is preserved. Only one mutation per open menu runs at a time; all menu forms receive the confirmed new fingerprint together. If another menu field changes during the save, the adapter refuses to advance unseen state and requires a reload. The current writer still preserves source URL, slug, hierarchy, attributes and metadata. Add/remove links remain their native POST workflows; existing-link reordering and assignment to menu locations remain designer tasks.

The Design library includes or removes explicitly selected native attachments and approved comments/reviews. The card remains visible after removing a selection so the client can deliberately include it again, subject to current public eligibility. This also preserves keyboard context and pagination. The source record is never deleted. Existing logos/team portraits and generic media inventory keep their separate authority. No whole-library scan, automatic selection or content/schema export change is introduced.

## Boundaries and saved-state contract

| Operation | Endpoint / authority |
| --- | --- |
| Menu label | POST /dsa/v1/workspace/menus/{id}/items/{item}/label; kiwe_manage_navigation; existing Navigation_Workspace_Service::change with a forced label operation |
| Design selection | POST /dsa/v1/workspace/context/{media\|testimonial}/{id}/selection; kiwe_manage_context plus native object edit/read and testimonial moderation; existing Context_Selection_Service::select |
| Selection conflict | Optional expected boolean on the shared selection writer; both the enhanced and native form send the state they displayed. Existing internal three-argument callers retain their behavior. A form cached from an older release without expected must reload. |

WordPress owns cookie/REST nonce authentication and schema validation. Exact route and method exceptions are added to the existing role guard; no broad menu-item, builder or generic workspace mutation authority is granted. Path parameters own identity even if a body forges id/kind/item. Direct callback calls repeat authorization and input checks. Successful and domain-error responses are private, no-store JSON; responses include only the small task projection, never comment email/IP/account data or whole native records.

Fingerprint/expected-state checks are optimistic conflict detection, not a database transaction against simultaneous third-party writes. Menu readback verifies that only the submitted label changed before advancing every displayed form. The native menu writer remains the only mutation implementation. Selection writer permission and public-eligibility checks run before its expected-state check; current public visibility is rechecked in the response and export. Neither comparison detects every change-away-and-back sequence.

The shared task script loads only on these two permitted admin screens. It uses same-origin POST, a REST nonce, a 15-second deadline and explicit server readback. It preserves text on failures, prevents duplicate submissions, and does not queue or replay writes. Any failed/unconfirmed response blocks further writes to that menu or selection card until reload, with a visible review link. Reloading can invoke the normal unsaved-changes warning. Other independent selection cards remain usable. JavaScript absence, unavailable APIs and invalid enhancement URLs leave the native forms intact.

## Verification and acceptance

Executed on Windows with Node 22.23.1 and PHP 8.4.23. One full baseline ran after the final runtime edit: 58/59 gates passed, with only the missing RC66 changelog entry failing the release-contract gate. After documenting the release, the affected gate passed 22/22. The other 58 gates were not repeated. No subsequent runtime edit was made. Baseline receipt: baseline-1788866198497-41d9e3b8-eef2-4853-bddf-256867dd9af8.json. The 31-file focused PHP syntax recipe passed (syntax-1788866754864-88ecce84-4102-4463-9569-3a23270dee2c.json); the prepared native fixture was also linted after its cleanup adjustment. PHP 8.2 was not executed locally.

The expanded baseline context/navigation gate passed the previous 59 native-I/O checks, 40 new REST checks and the shipped JavaScript behavior scenarios. Rendering the actual PHP screens into synthetic fixtures passed three further assertions. Headless Edge then checked Navigation, Media and Testimonials at 320, 390, 820 and 1440 pixels: all twelve layouts had no horizontal overflow and enhanced action targets were 48 pixels high. Browser interactions confirmed label/heading/fingerprint updates, preservation of another dirty label, undo, selection reversal and blocked related writes after a simulated 503 HTML response. No page-script errors occurred. Phone Media, desktop Navigation and interrupted-save screenshots were visually reviewed.

Browser setup initially lacked the bundled Chromium executable, so the installed Edge was used. The first interaction run stopped on a mistaken test-only Undo selector; correcting the selector and resetting the ephemeral preview produced a pass. Runtime source did not change. These were actual shipped renderers/assets with synthetic WordPress I/O, API replies and page chrome, not an authenticated WordPress browser test. The loopback preview and browser were closed after review. Receipts and screenshots are in the external task work/rc66-ui-evidence directory and exported review evidence; no customer records or live provider requests were used.

Commands from the canonical repository: node tools/maintenance/run-reserve-task.mjs baseline --out <external evidence directory>; node tools/release/rc12-contracts.cjs; node tools/maintenance/run-reserve-task.mjs syntax --out <external evidence directory>; php -l tools/release/test-wordpress-reactive-tasks.php; php tools/release/test-workspace-task-rest.php <external render directory>. Documentation links are checked once after final documentation edits with the docs reserve recipe. Browser harness: task work/rc66-browser-review.cjs against the temporary loopback preview. Do not repeatedly retry Hostinger during the suspected outage.

The server regressions exercise actual service code against synthetic native I/O: permissions, URL/body identity, stale writes, concurrent changes to another menu item, native-field preservation, revoked public eligibility, failed writes and native fallback. The JavaScript regressions execute the shipped script and test visible-state readback, preservation of other unsaved text, group locking, reversal, malformed/expired/conflicting/offline replies, timeout and zero automatic replay.

Native fixture: tools/release/test-wordpress-reactive-tasks.php, to run alongside the existing RC65 workspace-app proof inside an explicit CLI lab wrapper. It requires RC66/test-site constants, blocks outbound mail/HTTP/schedules, uses synthetic records and removes them before returning a structured passed/cleanup receipt. These prepared checks are not evidence of native execution. Nonce/session/browser and real shared-host performance remain acceptance requirements.

## Deployment and recovery

A new immutable RC66 folder-copy archive was built with the regenerated token catalogue and manifest after the final runtime edit. The builder verified 376 manifest files and compared all 379 ZIP entries against source bytes. Archive: external task work/kiwe-rc66-lab.zip, 1,547,506 bytes, SHA-256 3d2df8d855c638dabee7d7285cd5c7fe34145255d45df3a76de1151283bbab6f. Unsigned, uncommitted, unpushed and not deployed. The new task script is 5,813 bytes raw / 2,038 gzip; scoped task CSS is 4,280 / 1,396 and shared primitives 1,776 / 669. Compression sizes are not shared-host or device performance measurements.

Retain the preceding lab package during the eventual grouped RC65–RC66 acceptance; routine full SQL/ZIP lab backups remain waived. The RC65 archive remains unchanged. Production receives no runtime change from this work unit.

No database migration, new option store, external service, client framework or visitor bundle is introduced. Restoring the preceding exact package removes these enhancements while leaving native menus and selection metadata readable. Deliberate native changes are not undone by a code rollback. Earlier RC61/RC62/RC63 state compatibility rules still apply to the eventual lab release.

Key and Seam repositories, SiteGraph/export schemas and the PWA/visitor runtime are unaffected. Shared app architecture, client task contract, changelog, handoff, roadmap and reserve entry are updated; no new architectural decision is needed because this extends the already accepted same-origin progressive task pattern. Product Vision and maintainer policy need no change: native authority, explicit selection, optional route-specific assets and grouped verification already define this work.

Next: one combined native/browser acceptance of RC65–RC66 when hosting access is stable, then account-notice priority and further DSA/workspace component convergence. SEO feature additions remain deferred.
