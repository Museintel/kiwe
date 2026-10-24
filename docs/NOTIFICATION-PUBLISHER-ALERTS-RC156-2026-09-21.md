# RC156 category-aware publisher alerts

**Accepted:** 21 September 2026 IST (20 September UTC)  
**Installed target:** disposable Hostinger WordPress/WooCommerce lab  
**Production boundary:** Ascendants remains on its recorded RC55.4 runtime

## Result

The saved **new post** visitor preference now causes one automatic alert when an ordinary post first becomes public. Publication itself performs no provider request. It records an audience-table ceiling and post categories, then schedules a bounded WordPress cron worker. Password-protected posts, unsupported post types, updates and repeat publications do not create another audience run.

Each pass scans at most 25 topic-index rows and addresses at most three eligible people. One global worker lock prevents concurrent fan-out. The worker rechecks current topic, category and channel consent immediately before every handoff. Progress is committed before external I/O; an uncertain handoff is retained as unknown and is never replayed automatically. A client Administrator can inspect aggregate channel enrollment and bounded job status or explicitly resume a stalled job. Contacts, WordPress user IDs, visitor hashes, message bodies, push endpoints and provider payloads do not enter that response or the retained 20-item summary history.

Registered accounts receive only the routes they selected for this topic: browser/PWA, WordPress email, configured WhatsApp or configured SMS. Anonymous visitors can receive only an exact active PWA subscription associated with their opaque visitor hash. Removing consent removes the row from indexed discovery before a later pass; category mismatches that reach the worker are counted as skipped. WooCommerce sites retain the publisher `newsletter` and `new_post` topics alongside commerce topics.

## Verification

- The complete local release baseline passed **86 gates**. Package verification passed for **425 plugin files**.
- Focused notification contracts passed **40 checks**: 11 audience, 16 manual-campaign queue and 13 automatic post-alert checks.
- The responsive synthetic React run passed **71 checks**, including automatic-alert aggregate status and resume behavior. This is deterministic UI interaction evidence, not a production provider receipt.
- The installed lab passed **29 native WordPress checks** covering package integrity, debug-off state, topic coexistence, aggregate-only role output, publication-without-send, bounded multi-pass delivery, category filtering, revocation before a later pass, exact anonymous PWA delivery, sanitized history, replay prevention and complete fixture cleanup.
- Email and HTTP transports were intercepted before external delivery. A real mailbox, WhatsApp, SMS or push receipt remains part of final provider/pilot acceptance.
- The public homepage returned HTTP 200 after activation. Both public acceptance/retirement helpers returned HTTP 404 after cleanup. Only the intentional Hostinger WordPress cron runner remains.

Two earlier fixture runs did not reject the candidate. One allowed the host cron to race the controlled worker; another expected an index-revoked audience row to reach the worker and increment `skipped`. The final helper prevents that race and asserts the actual indexed-consent behavior. No fixture data survived any run.

## Release and recovery

- Version: `8.0.0-rc.156`; previous lab version: RC155.
- Archive: 428 entries, 1,802,575 bytes; SHA-256 `ef05e4547ba4f0d64ce2e4fe102adadb8d513a565c958a4c9ce09773b808eab0`.
- Installed manifest: 425 plugin files; native verification covered 206 PHP files.
- Private recovery: `rc155-before-rc156-20260920-194201` on the lab host.
- No database schema was added. Active-job state uses bounded WordPress options and the existing WordPress cron system; consent remains in the existing notification preference authority.
- The upload archive, deployment receipt, acceptance helper, retirement helper and temporary cron jobs were removed from public hosting.
- No commit, Git push, signing or Ascendants deployment was performed.

## Remaining backend work

Automatic publisher alerts close the final notification package. One non-WooCommerce package remains: configured-provider AI/SEO delivery limits and supported SEO-metadata migration. Six to eight WooCommerce packages remain. The backend total is therefore **7–9 batches**. Visitor DSA, design delivery and final launch acceptance remain later phases.
