# RC68 — Categories and Team task convergence

8 September 2026. Local source candidate 8.0.0-rc.68 on canonical main at 731a94ff48dd350bb7b168ec285fcc96afbdaa01. Existing work remains uncommitted/unpushed; lab RC64 and Ascendants RC55 remain installed. No hosting retry, deployment, debug change or production mutation belongs to this batch.

## Client tasks

Categories and Team now project the same neutral Seam tokens as Home, Navigation and Design library. Responsive cards, large controls, focused empty states and search/paging remain grounded in native WordPress records. Existing create, rename, move and team forms keep their ordinary POST/redirect workflows and actor-bound saved receipts.

A small optional native-form script adds unsaved-change feedback, Undo, a visible count of selected posts, validation of the existing 1–20 move limit and submitting feedback. Saving one form prompts before discarding unfinished work in another form on the page. Duplicate submissions are prevented while the native navigation proceeds. Inputs remain enabled for native serialization; no fetch, REST request, queue or automatic replay is added. Successful results are reported only by the existing server receipt on the next screen. Browser Back restores usable controls and preserves the current input. Abandoning an unsaved page uses the browser's normal warning. Native forms remain usable without JavaScript.

## Existing authority, stronger comparison

Category renaming still sends only name to wp_update_term. The displayed expected fingerprint covers term ID, name, slug, parent and description; a mismatch rejects an old form before writing. Native readback must match the requested name and preserved fields before the operation reports success. Meta and relationship handling remain native; category moves still add the destination, confirm it, then remove the source, retaining unrelated assignments and explicit per-post failure outcomes.

Team editing still uses the existing public membership/title/order user metadata and existing staff, owner, blog and per-user capability checks. Its expected fingerprint covers that person's ID and the three raw values. A stale form does not overwrite newer data. Existing per-field readback and membership-last ordering remain; a final comparison detects an earlier field changing during a later write. This is an optimistic check, not a cross-plugin transaction. A failed save can leave some native fields changed and tells the user to review them.

Both native handlers require expected state. An older cached form without it must reload. Existing internal callers of the service writers that omit expected retain their previous authorized behavior. No new option table, public/private schema, capability or endpoint is introduced. No new token catalogue or copied business records. Category/team native editors outside these two forms keep their own workflow.

## Implementation and recovery

Task_Workspace_UI owns the reusable native-form feedback and scoped asset registration. The two task services own their records, expected state, validation and receipt. workspace-native-forms.js is separate from the REST task script because it enhances a full-document native submission; it does not claim an in-place save. Shared primitive CSS and route-specific category/team CSS load only for permitted screens. No visitor bundle or AI call.

Rollback to the exact preceding package removes this presentation and its form comparison requirement. It does not undo deliberate native record changes. No data migration; earlier AI/SEO downgrade notes still apply. This extends existing native-task authority and the accepted shared app direction, so no new architecture decision is required. Key, Seam, SiteGraph/export schemas, Product Vision and maintainer policy are unaffected; no cosmetic edits to those contracts are needed.

## Verification and next gate

The single grouped baseline passed all 59 gates on the final runtime source, including the new PHP/native-form and actual JavaScript behavior regressions. The separate focused syntax recipe passed 34 services. Both receipts confirm unchanged source during their run. Native-task PHP behavior passed 19 cases, plus three actual screen renders (22 with rendering enabled). No failed RC68 gate required a repeated baseline.

Fresh headless Edge 152.0.4191.66 reviewed actual PHP-rendered Categories, category detail and Team screens with synthetic WordPress I/O at 320, 390, 820 and 1440 pixels: 12 layouts without horizontal overflow, with 48-pixel native submit controls. Five browser scenarios passed: Team Undo and complete native serialization; another card's unsaved-work confirmation with cancel sending no POST; usable controls after browser Back; post counts and empty-selection denial; native category creation with JavaScript disabled. Three synthetic POSTs reached only the loopback fixture server. No page errors; browser and server closed. Phone Team and desktop category screenshots were visually reviewed.

Commands executed from the repository, with evidence outside it:

```text
node tools/maintenance/run-reserve-task.mjs baseline --out <external>/reserve-evidence
node tools/maintenance/run-reserve-task.mjs syntax --out <external>/reserve-evidence
php tools/release/test-native-task-forms.php <external>/rc68-evidence
node <external>/rc68-browser-review.cjs
tools/release/build-folder-copy-archive.ps1 -OutputPath <external>/kiwe-rc68-lab.zip
```

Baseline receipt: baseline-1788869309287-b3e0e03e-a339-4fdd-96d4-d8743e02f628.json. Syntax receipt: syntax-1788869277279-ed8272fd-9f29-465c-bb19-5659747c070b.json. Browser receipt and screenshots: rc68-evidence. A final maintained-document link check follows these documentation edits; its independent receipt accompanies the exported handoff. Evidence lives under C:/Users/shariq/Documents/Codex/2026-09-05/im/work and review copies under that task's outputs/evidence.

Verified immutable candidate: kiwe-rc68-lab.zip, 1,554,453 bytes, 384 ZIP entries / 381 manifest files. SHA-256: 0164b1f8ed0161704b256bb0cab2b300a920e6507c9adcdf949067031861553f. The archive builder verified every entry against source. This is an unsigned local folder-copy candidate.

Node 22.23.1 and PHP 8.4.23 executed locally. PHP 8.2, authenticated WordPress sessions, actual database/hook interactions and shared-host performance remain unverified for this batch. Native acceptance must include category/team expected-state denial and readback on real records, full native POST/redirect receipts, actor permissions, browser Back, JavaScript-disabled forms and the earlier RC65–RC67 tasks. The historical RC56 workspace fixture is version-pinned and does not certify these new handlers; update or replace its scoped acceptance plan before use.

Next: combined RC65–RC68 native/session/browser acceptance once hosting access is stable, then business-form convergence and existing native control links in business context. Keep SEO additions deferred. No new founder requirement was added by the request to continue.
