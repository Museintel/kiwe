# RC58 lab acceptance — 6 September 2026

RC58 was installed on the authorized disposable Hostinger testing site, plum-giraffe-539524.hostingersite.com, at 08:43:15 UTC. It includes RC57 page naming/product notification source plus RC58 Design library and Navigation. Ascendants, Key and Seam were not changed. Source remains uncommitted/unpushed on canonical main at baseline 731a94ff48dd350bb7b168ec285fcc96afbdaa01. No signed feed was published.

## Exact artifact and environment

The folder-copy archive was built once with the verified .NET builder: 1,514,699 bytes, 367 ZIP entries, 364 manifested package files, SHA256 e13563fe072f0fc27992308e7a252fb106294710b292d5644397d430692f53de. Both root MU loaders matched the nested RC58 package. Server staging checked strict ZIP consistency, path/symlink boundaries and every normalized manifest hash before replacement. All candidate PHP files passed Hostinger CLI lint without warnings/deprecations before swap.

Native checks ran on WordPress 7.0.4 / PHP CLI 8.2.33, with Bricks 2.3.10 and WooCommerce 11.0.1 installed; the web configuration uses PHP 8.3. The earlier local RC58 run passed 54 baseline gates and 16 service lints on PHP 8.4.23; those unchanged runtime gates were not repeated. The new standalone native fixture was separately linted and executed.

The initial orchestration attempt stopped before installation because PHP process execution is disabled on this host. It made no backup or package swap. The corrected one-use shell cron ran PHP staging, lint, swap and acceptance as separate processes. Hostinger PHP helpers must not rely on exec/shell_exec/proc_open.

## Native acceptance

The new [WordPress context/navigation fixture](../tools/release/test-wordpress-context-navigation.php) passed **36 checks**, including installed package integrity, restricted client capabilities, native page title and preserved URL/Bricks metadata, native menu entries and label readback, source rename/custom-label semantics, parent/leaf deletion, stale menu forms, selected PDF/audio attachments, current public-parent eligibility, comment moderation and Woo review ratings, original native WordPress/Woo value precedence, and visitor denial. The settings-precedence checks use process-local option filters to prove projection authority; they do not exercise client settings forms or change real shop settings.

Fixtures blocked outbound HTTP/mail/notification dispatch and scheduled work in their process. Three synthetic users and their posts/comments/terms/menu were removed in finally. No physical media files were created. Ownership policy was exercised through a process-local fixture and was not activated persistently on the site. Successful service calls in a CLI fixture are distinct from browser form acceptance.

## Browser and public checks

One existing authenticated administrator session exercised the source-library filter and the real media include/remove POST forms. Each showed its corresponding success receipt; the chosen attachment was returned to its original unselected state. The Testimonials tab and existing native Main Menu controls loaded. Design library was visually inspected at 390px phone width, Testimonials at 820px tablet width, and the populated Navigation form at 1280px desktop width. DOM checks found no horizontal document overflow at those widths; Navigation also measured 390/390 on phone. A full-page Navigation phone screenshot timed out, so it is not visual acceptance for every lower card. Temporary viewport overrides were reset.

This was an unrestricted existing session because persistent ownership policy remains off. Browser submission of menu mutations/page naming, restricted-role browser routes, physical devices, screen-reader behavior and the entire dashboard are not certified. Existing RC56 browser/native evidence remains historical and was not rerun. Guest product notification dispatch/provider delivery and rendered menu changes in DSA/Bricks remain deferred; no customer notifications were sent.

Observed UX follow-up: the existing account-setup notice occupies excessive phone space. Consolidate its presentation into a concise, actionable setup card as part of dashboard convergence, retaining its security requirement. This pass did not redesign or suppress it.

Hostinger cache was purged once after install. Anonymous home and the scoped task stylesheet returned HTTP 200; the temporary fixture URL returned 403. Raw Convert Test Mode remains true and intentionally suppresses visitor DSA. Accessibility preview remains false, ownership policy remains false, and the serialized dsa_settings digest matched before/after.

## Recovery, cleanup and next work

The founder explicitly waived routine full SQL/ZIP backups for this disposable lab. None were created for RC58. The previous RC56 package/loaders are retained outside the document root in kiwe-private-rc58b-20260906/previous; older pre-existing recovery artifacts remain untouched. This is a code recovery point, not a database snapshot, and the successful run did not rehearse rollback. Production recovery requirements remain in force.

The installation cron was deleted after successful acceptance. A separate bounded cleanup removes the temporary ZIP, PHP/shell scripts and locks after copying allowlisted evidence to private storage. Cleanup completed at 08:52:02 UTC: all temporary scripts, archive and locks removed; no synthetic users/posts/terms remained; browser-tested media returned to its original unselected state. The cleanup cron was then deleted. Routine backup suppression does not relax package integrity, evidence or cleanup.

Next product work remains consolidation of client tasks and their pending browser acceptance. The [AI affordability/SEO contract](AI-AFFORDABILITY-SEO.md) captures the latest founder direction and prioritizes shared per-site budget/consumer authority before new automated SEO entry points. Seam import/parity remains deferred. AI provider credentials, accounts and billing were not changed.

Evidence is kept outside canonical repositories in the current task's work/lab58-evidence and refreshed outputs/evidence. Native acceptance, before/after, stage, swap, job, browser, public HTTP and cleanup receipts describe distinct properties. The package digest identifies the runtime; later documentation/fixture changes do not imply a new installed package.

Final readback: the hosting account had zero cron jobs; the temporary web directory contained only its deny rule and sanitized cleanup receipt. The documentation gate passed once after this batch: PASS 87 relative links across 18 maintained documents. Receipt: docs-1788684881062-dcced556-eda0-4a92-bd4c-c27627baaecd.json. This result note was added afterward and does not alter runtime/package bytes.
