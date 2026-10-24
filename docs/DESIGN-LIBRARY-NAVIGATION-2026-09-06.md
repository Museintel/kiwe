# Design library and Navigation — RC58 batch

The Administrator workspace manages the business. Design Context exports selected evidence from that workspace. Owning a file, user, comment or product review does not automatically authorize it as design evidence. This implements the founder's 6 September distinction and continues RC57 content operations in one grouped local build.

## Client experience

**Design library** has Media and Testimonials sections, each opening on its selected records. Switch to Browse source library to find more, search, and include individual records. Media supports every existing WordPress attachment MIME; uploading and editing file details use the original native screens and permissions. A selection is not copied into another catalogue. Removing it does not delete the file or comment. Cards show whether a selected item remains eligible for public export. Results use 16 records with one lookahead; a page can contain fewer visible records after permission filtering.

Testimonials come from explicitly chosen approved comments and WooCommerce reviews. The public author name, plain-text words, original link and genuine rating (when present) form the evidence. User accounts alone are not testimonials; a future user-linked/manual testimonial requires an actual quote and explicit selection. Do not derive endorsements from biographies, accounts, ratings without words, or private correspondence.

**Navigation** owns client menu mutations. Page details and Categories link to it with their native object as a suggested source. Clients select an existing named WordPress menu, add a public page or category at the end of its main level or an existing parent, change a custom label, restore the native label by leaving it blank, or remove a leaf link. Existing hierarchy, source URLs, link attributes and menu location assignments survive. No parent with children is removed. Designers retain menu creation, existing-link reordering/reparenting, custom URL editing and assignment to theme/Bricks/DSA locations. The first 100 named menus are offered; individual menus above 200 items need designer review rather than partial edits.

One scoped stylesheet provides cards, adaptive grids, 44px controls, readable form text and focus outlines. There is no new visitor JavaScript, polling, table, external request or runtime dependency. These are new task screens; this batch does not claim to restyle every existing WordPress screen or prove phone/tablet/desktop appearance without browser acceptance.

## Authority and public contract

| Concern | Owner / contract |
| --- | --- |
| Media membership | Native attachment post meta `_kiwe_design_context_media = 1`; absent means unselected |
| Testimonial membership | Native comment meta `_kiwe_design_context_testimonial = 1`; absent means unselected |
| Media selection permission | `kiwe_manage_context` and object `edit_post`; selecting requires an inherited attachment with no password and either no parent or a published, unprotected, public parent |
| Testimonial selection permission | `kiwe_manage_context`, `moderate_comments`, object `read_post`; selecting also requires approved ordinary comment/review and a public parent; Woo reviews require active Woo |
| Public projection | Rechecks current approval, parent visibility and Woo availability; no account email, IP, account ID, session or private operational comment fields |
| Navigation permission | Dedicated `kiwe_manage_navigation` for client Administrator and technical owner; ordinary unmanaged admins receive it through existing Kiwe capability composition |
| Menu writes | Fixed native menu, server-derived membership, optimistic fingerprint, POST and menu-bound nonce. Generic menu-item/theme/Bricks writes remain denied under ownership policy |
| Mutation outcomes | Readback and actor-bound, single-use transient receipts; failures never become success query flags |

The existing `kiwe.seam-design-context.v1` profile adds `testimonials: { source: "selected-native-comments", items: [...], limit: 20 }`. Item fields are `id` (native comment ID), `source` (`wordpress-comment` or `woocommerce-review`), `name`, `quote`, `isExcerpt`, `url`, and optional integer `rating` from 1 to 5. At most 20 selected candidate records are considered for this brief; visibility filtering may reduce that count. Quotes are capped at 2,000 characters and explicitly marked when excerpted. The library remains paginated for managing more selections. This is design evidence, not a new Bricks dynamic query binding.

Profile `resources.query` becomes `{ resource: "media", mimeType: "", contextOnly: true, page: 1 }`, with `authority: "explicitly-selected-native-public-media"`. The SiteGraph Design Context packet uses the same selected media query. Generic SiteGraph media queries remain a separate public catalogue; `contextOnly: true` narrows them through native metadata and intersects, rather than replaces, requested IDs and public visibility. The data schema advertises the new optional argument. Existing explicitly chosen logo, founder/team portraits, service/product images retain their original domain authority. No implicit selection or backfill of an old media library occurs.

Old saved `resources.items` are retained for recovery, but are not silently migrated or republished. Before accepting RC58 on an established site, select desired media in Design library and compare context exports. An empty selection deliberately yields no general media pool. Previously downloaded briefs and third-party caches do not retroactively change. Fresh projections recheck eligibility; generic public SiteGraph responses retain their existing short cache policy.

The sanitized SEAM capability snapshot contract is unchanged: it contains capabilities rather than business/media records. Key and Seam source do not change in this unit; no in-progress Kiwe source is copied into Seam's pinned snapshot. See [the accepted selection decision](decisions/2026-09-06-explicit-design-context-selection.md).

Native menu reads retain core positions by using `output: OBJECT`, and label-only updates avoid the menu helper's omitted-field defaults. These decisions follow the official [menu reader](https://developer.wordpress.org/reference/functions/wp_get_nav_menu_items/) and [menu writer](https://developer.wordpress.org/reference/functions/wp_update_nav_menu_item/) contracts. Single-record writes follow ordinary WordPress concurrency; the fingerprint rejects stale forms but does not claim a database transaction against simultaneous third-party writes.

## Validation and delivery state

The single combined local run passed: **54 baseline gates**, syntax for **16 PHP services**, and **71 relative links across 15 maintained documents**, using PHP **8.4.23** and Node **22.23.1** on Windows. Behavioral regressions cover explicit media/comment eligibility, all-MIME handling, moderation/visibility revocation, Woo-disabled behavior, narrow native writes, cross-menu and stale-form denial, database failures, scoped filter cleanup, bounded queries, capability/route boundaries and nonce/POST requirements. Synthetic WordPress I/O is not native WordPress integration proof. No unchanged test was repeated.

Commands were the `baseline`, `syntax` and `docs` recipes in `tools/maintenance/run-reserve-task.mjs`, with evidence outside the ecosystem source. Receipts: `baseline-1788662995250-4590ee05-95a2-4816-ac94-032d23d2cf69.json`, `syntax-1788662964923-c41f843b-cc04-4805-9428-3ad76e0a492f.json`, and `docs-1788662961622-bebd38e7-37d9-45b5-b279-8cdd78f28cb0.json`. Each recorded unchanged source during its run. Source snapshot hash: `faed16d8823fbb6d2d7426ace69f349a49ddf6bd705888a7b30a821f700f0d01`. This results paragraph was added afterward; runtime/package bytes are unchanged.

Local version: **8.0.0-rc.58**, on canonical Kiwe `main` at existing baseline `731a94ff48dd350bb7b168ec285fcc96afbdaa01`, with previous work preserved. Source is uncommitted/unpushed. The subsequently built exact RC58 ZIP is installed on the disposable lab; see [36 native checks and scoped browser acceptance](RC58-LAB-ACCEPTANCE-2026-09-06.md). No signed feed was published. Ascendants is unchanged. The generated version catalogue and **364-file package manifest** were regenerated before the passing run.

## Acceptance scope and remaining gaps

1. Completed: prepare one exact RC58 package, retaining only the previous package on this disposable lab; routine SQL/ZIP backups are waived. Preserve lab raw-convert and ownership-policy settings unless using explicitly controlled fixtures. The existing live CLI workspace fixture is RC56-pinned; adapt its version contract before reusing it.
2. Exercise RC57 page naming and Guest product notification routing plus RC58 media/comment selection and menus in native WordPress, including a restricted client actor. Use synthetic data only and clean it up.
3. Check card/forms at phone, tablet and desktop widths, keyboard focus, screen reading labels, large-library pagination, missing/Woo-disabled states and role-denied routes in one browser pass.
4. Confirm chosen menus in both DSA and theme/Bricks output after normal cache invalidation. Confirm unselected media and unapproved/private testimonials stay out of fresh context exports. Old rendered/exported content may require its own refresh.
5. Keep Seam compile/import/visual parity deferred as requested. Record exact evidence and remaining gaps; do not broaden this pass into unrelated provider testing.

Recovery: restore the previous exact plugin artifact through the existing lab recovery procedure. Selection metadata is additive and can remain inert; deselection uses the new workspace. Reverting code alone does not undo deliberate menu edits; use an appropriate native-state recovery record for production. The disposable lab has no fresh database snapshot under the founder's explicit waiver. No bulk migration, media deletion, comment publishing or source-layout rewrite occurs here.
