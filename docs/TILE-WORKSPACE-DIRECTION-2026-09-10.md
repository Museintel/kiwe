**Latest interpretation:** [The founder's supplied brief and corrected Metro workspace](METRO-WORKSPACE-CORRECTION-2026-09-10.md) require tiles as the working surface, not only Home shortcuts. Identity edits and existing-user team selection are demonstrated in the corrected sample. Earlier form previews below remain historical evidence.

**Later on 10 September:** The founder approved this look. The [whole-context map and expanded sample interactions](DESIGN-CONTEXT-WORKSPACE-COVERAGE.md) followed, then [RC83 native UI work](WORKSPACE-SHELL-RC83-2026-09-10.md). The 15-check prototype record below describes the initial pass, not current runtime deployment.

# Tile workspace direction and React prototype

10 September 2026. The founder reviewed the actual lab dashboard and clarified that colored, varied-size Windows 8/Lumia-style tiles must form the primary navigation. The existing sidebar plus shortcut cards does not satisfy that outcome. This is an explicit correction to the UI direction, not a claim that the RC82 role or native-save checks were false.

[Accepted architecture and migration gates](decisions/2026-09-10-tile-workspace-shell.md) define a dedicated same-origin React client application, its relationship to Bricks and DSA, and the native authority/performance boundaries. The technical owner keeps WordPress/Bricks access; clients should complete supported tasks inside the new shell without the permanent native sidebar/toolbar. Share design primitives with visitor DSA without shipping management code to public pages. Production Node hosting is not required.

## Built in this turn

[Canonical local prototype](../tools/workspace-prototype/README.md): React Home tiles, internal task navigation, role examples, business details, sample post/category and product editing, explicit design-media/team selection, sample menu selection, unsaved-edit protection and a related visitor shell composition. It runs at http://127.0.0.1:8774/ using sample data; all writes are in memory and reset on reload. The old localhost 8773 preview and real client lab session are separate. The new preview does not read or write either website. No inference, polling or external resource requests are used.

The sample post textarea is an interaction sketch, not Gutenberg. Products illustrate the requested client workflow but do not fix the RC82 client permissions. The role selector is a presentation demonstration, not server authorization. The visitor illustration does not establish Key, ordering or saved-item functionality.

## Verification

The final local browser pass completed **15 scoped checks**: tile navigation, no native sidebar, deferred task-code loading, unsaved-navigation protection, explicit local-save feedback, shared sample brand projection, sample draft continuity, role visibility/direct-route filtering, no horizontal overflow at 820/390/360 pixels and in the small-phone editor, loopback-only requests and no JavaScript exceptions. Desktop, phone, tablet and editor screenshots were captured; desktop and phone Home plus the phone editor were visually inspected. Initial acceptance revealed an imprecise category label; explicit labeling was added. Visual review led to a wrapping title field and a wider mobile Navigation tile. A test timing assumption was corrected to wait for the actual task row before checking it.

The final initial JS/CSS bundle totals 250,886 minified bytes and 77,737 bytes using gzip estimation; the task module is separate and deferred. The local server serves uncompressed assets, so estimated gzip size is not measured network transfer. This prototype uses an independent React bundle; no production WordPress dependency choice or real PHP/API speed is proven. Fifteen local checks are not a full accessibility audit, physical-device test, native write proof, concurrent-load test or launch certification.

Evidence is outside source at work/tile-workspace-evidence/browser.json, server.stdout.log and the named PNGs. Dependency versions are pinned in the prototype lockfile. No main runtime files or package manifests changed, so a new MU version and unchanged full runtime baseline were not required. Documentation links and diff formatting are checked after this update.

## Next connected batch

Build a server-gated shell and capability-derived task catalog, fix the confirmed Woo custom-owner compatibility and client Administrator product boundaries, then connect one complete native read/edit/save/readback task inside the shell. Keep denial, expired-session, stale-state and disabled-feature behavior in the same grouped acceptance pass. Native editor/task migrations follow the acceptance gates in the decision record. Do not replace the existing client routes until their required workflows are covered.

Full-bootstrap/security-event profiling remains part of the launch work and must measure the connected app. No additional SEO feature phase. The previous first-launch estimate must be reviewed once this reference slice establishes migration effort; a prototype is not completion of that work.

**Deployment:** canonical source/runtime and test site remain RC82; Ascendants remains verified RC55.4. This turn changes local design tooling and documentation only. No hosting settings, accounts, security factors, live packages, signing, commits or pushes were changed. [Prior real client-account proof](CLIENT-LAB-SETUP-2026-09-10.md) and [production backport](RETENTION-DEPLOYMENT-RC554-2026-09-10.md) retain their separate scope.
