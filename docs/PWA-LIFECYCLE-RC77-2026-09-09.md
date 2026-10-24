# RC77 — PWA cache isolation and offline continuity

9 September 2026. Local source/package and disposable Hostinger lab: **8.0.0-rc.79**. RC77–RC79 are candidates within one PWA batch. Ascendants stays RC55.2; this PWA batch was not deployed there. The later, separate [traffic incident](ASCENDANTS-TRAFFIC-2026-09-09.md) is deferred by the founder. Kiwe remains on main at the previous committed HEAD `731a94ff48dd350bb7b168ec285fcc96afbdaa01`, with accumulated uncommitted work preserved. No commit, push, signing or public release.

## Behavior

- REST and runtime failures cannot replay cached login/account/API results. Current explicit static assets use only this site's cache and only reusable CSS/JS responses.
- Public editorial image hints now work offline. Ordinary images are never opportunistically cached. The existing document/media count and size limits remain.
- Each native site home URL owns a distinct v2 cache prefix. Upgrade, offline-off and full worker retirement preserve unrelated buckets and registrations.
- Installation downloads no unused islands. A disabled endpoint delivers a valid retirement worker, avoiding a stale controller that survives an endpoint 404.
- Offline navigation shows a reconnect screen with an accessible retry link and returns 503. Actual upstream HTTP errors remain intact.
- A password-unlocked reader cannot turn protected article content into a public offline contract.

The existing PWA controls remain their sole authority. No schema, capability or persistent setting was added. New public `cachePrefix` configuration is derived from the native site URL. See the [architecture decision](decisions/2026-09-09-pwa-cache-isolation.md) for ownership and migration tradeoffs.

## Verification and deployment

The previous RC76 inline worker and frontend retirement function failed 20 of the 28 new synthetic checks when executed with the new harness configuration; this is regression reproduction, not a full recreation of RC76 PHP configuration. RC77 passes all 28. The first combined baseline caught stale generated release metadata and missing changelog version; after regeneration/documentation, **all 61 release gates passed**. Local PHP is 8.4.23; Hostinger native acceptance used PHP 8.2.33 and WordPress 7.1.

Installed at **12:54:15 UTC**. All **22 native checks** passed, including flag combinations, native site-derived scope, public/draft/unlocked-password article decisions, cleanup and original settings preservation. Deployment receipt completed at 12:54:20 UTC. No routine full backup; previous RC76 package retained outside public_html in `kiwe-private-rc77-20260909/previous`.

Artifact: 1,559,427 bytes, 384 archive entries, 381 package manifest files. SHA-256: `d7030f8de769ae8934a0829e6eb016bbbdb99f93ea0fa8158ea8c697801a6e01`.

RC77 browser acceptance reproduced a long-name overflow (477 px content on a 390 px viewport). RC78 adds narrow-width wrapping and correct reconnect sizing. RC78 installed at **13:11:16 UTC**, passed all 61 gates and repeated 22 native checks. Its artifact is 1,559,467 bytes, 384 archive entries, 381 manifest files; SHA-256 `153f5bf5fa216069b244da72098b1a94f5485a3c14134dbb6084717ba3edec55`. Previous RC77 is retained outside public_html in `kiwe-private-rc78-20260909/previous`.

The browser verified six article/reconnect layouts (390/820/1440), real offline public article and image replay, REST cache rejection, current static asset fallback, a real RC77→RC78 generation update preserving unrelated storage, and reconnect to native login. One browser wait overlapped the upgrade; it was repeated after confirming activation and actual cached entries. The final image check cleared normal HTTP cache and verified `fromServiceWorker=true`, avoiding a false pass from browser HTTP storage. The synthetic article/image were deleted and original settings restored. The first disable browser check found LiteSpeed serving the earlier active worker (`x-litespeed-cache: hit`) despite `no-store` headers; a fresh query returned disabled. RC79 explicitly sets `DONOTCACHEPAGE` and invokes `litespeed_control_set_nocache` for worker and manifest endpoints. RC79 installed at **13:23:17 UTC** and passed all 22 native checks. WordPress CLI PHP is 8.2.33; public HTTP responses advertise PHP 8.3.33. Repeated exact worker URLs now return LiteSpeed no-cache. After temporary hosting 503s cleared, the unchanged disabled worker URL completed retirement without a query change or cache purge: registration count zero, own caches empty, unrelated cache retained. The combined scoped browser record has **27 passed checks and six verified layouts**. Historical 503/failed checks remain in separate evidence files.

Evidence is outside canonical source in the task workspace `work/rc77-evidence/`. RC78 baseline: `baseline-1788959387823-18f6beb8-277c-4fb3-b1b8-b173a03f4766.json`; focused reserve receipt: `pwa-1788959515184-de5664a6-04ff-4489-88ad-94621ab138d1.json`. RC77 baseline receipt: `baseline-1788958317243-6accec65-e65f-4839-9ab8-fdac5d6dd55b.json`; the preceding failed receipt remains as historical evidence. Native reusable fixture: `tools/release/test-wordpress-pwa.php`; deterministic gate: `tools/pwa/worker-behavior.cjs`, which renders the actual PHP worker using `tools/pwa/render-worker.php`.

## Limits and next work

This batch does not complete physical Android/iOS installation, real push delivery, passkey/enrollment/recovery, logout notification ownership, or broader DSA dock/ad/popup journeys. The normal-login cookie warning from earlier acceptance remains unestablished and unresolved. Existing v1 caches are no longer consumed but are not globally deleted because their owner is ambiguous. Current public offline reading remains an opt-in pilot; Bricks content, personalized pages, transactions and query-bearing routes need the network.

Keep the launch estimate at **5–8 substantial remaining batches**, governed by first-launch gates rather than candidate count. RC77 closes specific PWA defects inside the wider DSA/Key/PWA work package. Next: supported authentication/session/provider journeys and the selected operational workflows, followed by Seam/Bricks, measured shared-host performance and release/recovery. Do not resume open-ended SEO feature development.

Business workspace, Design Context, role routing, Key control plane and Seam conversion contracts were not changed by this PWA batch. The subsequent traffic/hosting concern is recorded separately in the incident handoff and Founder Intent Register.

RC79 passes all 61 release gates, including **29 worker behavior checks** (new explicit WordPress/LiteSpeed endpoint-cache boundary). Baseline: `baseline-1788960037461-f6cb3d66-400c-4a8b-8fa2-c6da329b7f49.json`. Artifact: 1,559,546 bytes, 384 entries/381 manifest files; SHA-256 `e7af3e5b41a8a3014cc6aa481e7f35e723962b9c57f47816829576a18cd9c194`.


## Final restoration and continuation

Original PWA settings were restored (enabled=true, worker_enabled=false), their settings digest matches the original, and debugging remains off. Synthetic article/media cleanup had already passed. Temporary RC77–RC79 helper scripts and uploaded candidates were removed at 17:40:01 UTC; protective directory rules and sanitized receipts remain. The cleanup job was deleted. Previous package directories remain outside the public root for recovery. External final evidence: work/rc79-evidence/browser.json, retirement.json, restore.json and helpers-cleanup.json.

The interrupted retirement task is complete; do not recreate it. The local RC79 preview is a sample workspace, not production data. Resume supported authentication/session/provider journeys within the DSA/Key/PWA package; track high-traffic SecureTrack query/write budgets in the performance gate before production promotion. No further Ascendants polling is scheduled.
