# Content operations batch — 6 September 2026

**Subsequent delivery:** RC57 changes are included in the installed RC58 lab artifact. Native page-name checks passed in [RC58 acceptance](RC58-LAB-ACCEPTANCE-2026-09-06.md); product notification provider acceptance remains unverified. The local-only status below records the original RC57 batch.

The founder asked to group implementation and verify it in one pass, reducing repeated browser work and tool usage. This batch follows the completed RC56 Categories/Teams lab work and implements adjacent client and store workflows together.

## Implementation and state

Local source is now `8.0.0-rc.57`, still uncommitted on canonical main over `731a94ff48dd350bb7b168ec285fcc96afbdaa01`. The Hostinger lab remains on the independently verified RC56 artifact. RC57 is not installed, signed or pushed. Key and Seam are unchanged by this batch, and Seam's pinned snapshot is not synchronized to dirty source.

- **Page names:** the existing Page details workspace adds a dedicated title form. Client Administrators receive `kiwe_manage_page_titles` under the ownership policy; generic page/Bricks editing remains denied. The native write contains only the ID, sanitized title and existing slug. Content, author and publication status are not accepted from this form. Page-bound nonce, POST requirement, Unicode/length validation, stale-form detection and readback errors cover the mutation. A no-op avoids a write. Success receipts are short-lived, actor-bound and tied to the page.
- **Product review:** `admin_guest_product_submission` is a separate preference/topic for people with both `edit_products` and `edit_others_products`, and is unavailable without WooCommerce. Editorial permissions do not grant access to product proposals. The event requires a real pending Guest-marked record and the matching author; ordinary products and saved drafts do not generate a review alert. Links point to the native recipient review route even though the submitting Guest cannot obtain an edit link.
- **Contributor updates:** `guest_product_status` reports status changes for the author's Guest product proposals. Unchanged states, initial inserts, ordinary products and the author's own changes are silent. Published items use their permalink; unpublished Guest work links to the permitted Guest workspace. Article workflows retain their topics. Delivery always intersects current capability/application authority, explicit topic/channel choices and browser permission; new topics are not silently opted in.

The two new notification purposes are registered with the shared channel service's campaign-availability boundary. No new transport, table, background worker or frontend asset is added. The existing 100-recipient event limit remains a separately tracked defect; this batch does not claim large-audience delivery.

## Combined verification

One combined verification batch passed: 53 green-baseline gates, PHP syntax for all nine selected services, and relative links across the 13 maintained documents. Runtime was Node 22.23.1 / PHP 8.4.23. The baseline executes the native-page regressions and actual Kiwe event/preference services with synthetic WordPress I/O, both with and without Woo. Each recipe receipt confirmed unchanged source during its run. These source tests do not certify real WordPress persistence, PHP 8.2 execution, mobile page-name forms or provider delivery for RC57.

Commands: `node tools/maintenance/run-reserve-task.mjs baseline --out <evidence-directory>`, plus the `syntax` and `docs` recipes in the same batch. No further browser or live deployment pass was performed for RC57. The local 359-file manifest passed the package gate. This result paragraph was documented after the run; receipts retain the exact source snapshot tested.

The package version and generated token catalog move together; the package manifest is generated after runtime edits. Keep the installed RC56 archive intact. Batch the next real WordPress/browser acceptance across page rename, category/menu changes and product review/status once the next implementation group is ready; do not repeat RC56's completed checks without a new concern.

## Next build group

Design native menu-membership controls around explicitly selected WordPress menus, preserving custom labels, hierarchy and ordering. Category indexing needs an explicit adapter for the active SEO authority. Guest draft editing/resubmission and bounded notification background delivery remain independent changes. Backup/restore and media portability stay in the researched proposal rather than interrupting this sequence.
