# Connected Services, categories and service plans — RC144

**Accepted 20 September 2026 on the disposable Hostinger lab.** Services now have one coherent client workflow in both supported storage modes. Connected Services continue to use native WordPress posts, thumbnails, registered scalar metadata and configured taxonomies. An unconnected Design Context plan now has its own create, edit and remove mutation, so saving one plan no longer rewrites its neighbours.

Every plan action requires the current per-plan fingerprint and exact allowlisted fields. The server rejects stale state, injected settings, invalid images, disabled Services and role violations, then reads the canonical Design Context section back before reporting success. Connected records keep their existing single-record native writer and per-record stale guard.

The overview remains text-only. Media is loaded and selected inside the opened editor, which preserves the light dashboard requirement. **Manage service categories** now opens a dedicated service-only taxonomy destination and appears only when the configured native service post type has a taxonomy the client can manage. Product and post groups do not leak into that task.

The immutable package is `8.0.0-rc.144`, archive SHA-256 `e5a84c135049a123a7fa64ee3daea61c0b55419718cb9466239af34b8b3490c6`, with 420 verified runtime files. All 83 local release gates pass. Focused React acceptance passed 18 checks at 1440, 820, 390 and 360 pixels, covering plan save/create/remove, connected media and metadata, dedicated category routing, dirty-close protection, mutation proof, horizontal fit and absence of external browser requests.

The WordPress/PHP 8.2.33 fixture passed 513 native checks. It proved neighbour preservation, stale and injected-field refusal, native service images, native taxonomy rename/readback, configured metadata, catalogs beyond 100 records, fixture cleanup and settings preservation. [The acceptance receipt](generated/rc144-lab-acceptance.json) records the final lab state.

The lab reports RC144 and retains RC143 for rollback. Debug logging is off, the published illustrated sample remains intact, both temporary helper locations are retired and no scheduled jobs remain. The public homepage and sample post returned HTTP 200; retired helpers returned HTTP 404. Ascendants remains recorded RC55.4 and was not changed. No commit, push or signing was performed.

This closes the named **Connected Services/category/media and unconnected-plan** package. The ledger now estimates **14–20 backend** and **22–34 overall** named batches, including **6–8 WooCommerce** batches. The next package is **Home, Design Context, navigation and client-setting coverage**.
