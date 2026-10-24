# Site-scoped PWA caches and worker retirement

Accepted, 9 September 2026. Owner: Kiwe WordPress PWA service.

The previous worker searched all origin caches when live Kiwe requests failed, stored image hints without replaying them, and deleted Kiwe caches/registrations belonging to other site paths. A disabled endpoint returned 404, which could leave an installed worker controlling open clients.

Use a v2 cache prefix derived from the native site home URL. Version public asset, article and image caches under that prefix; retain the site's push renewal metadata across worker upgrades. Live REST/runtime responses have no cache fallback. Static assets must match the current explicit URL, expected content type and reusable response policy. Cache assets only as requested. Offline documents remain a server-approved public editorial projection; password-protected posts are excluded even for readers with an unlock cookie. Normal personalized WordPress HTML, accounts and commerce are not stored.

Worker and manifest endpoints explicitly signal WordPress and LiteSpeed to bypass page caching as well as setting no-store HTTP headers. A Hostinger acceptance failure demonstrated that headers alone were insufficient. Disabling the worker serves a valid, uncached retirement worker that claims clients, removes its v2 caches and unregisters itself. Frontend retirement targets the exact native site scope and worker endpoint. It never reloads an editor or grants access. Ordinary offline navigation offers a retry link and returns 503; genuine upstream HTTP failures retain their original response.

Legacy v1 caches were shared across paths and have no reliable owner. RC77 stops reading them and does not copy their renewal tokens or globally delete them. Existing clients may need to revisit online to refresh their subscription metadata and cached public reading. Browser origin storage remains a browser security boundary; a path prefix is ownership bookkeeping, not tenant isolation against same-origin JavaScript. Offline copies cannot be remotely revoked while disconnected; protected/membership content must never opt into public editorial caching.

Alternatives rejected: caching normal page HTML; deleting every Kiwe cache on the origin; returning 404 as the sole disable behavior; eagerly preloading unused modules. No new server table, capability, provider or required hosting service.

Verification: generated PHP worker executed under a deterministic JS event/cache harness, real WordPress content/flag checks and isolated Chromium worker acceptance. Recover by restoring the previous package; a version downgrade changes worker bytes and triggers an update, but deliberately does not migrate v2 cached data back to unscoped v1 storage. Production rollout remains a separately reviewed action.

Browser semantics were checked against [MDN registration and update options](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register), [unregister behavior](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerRegistration/unregister) and [CacheStorage lookup scope](https://developer.mozilla.org/en-US/docs/Web/API/CacheStorage/match). Batch evidence and remaining limitations: [RC77](../PWA-LIFECYCLE-RC77-2026-09-09.md).
