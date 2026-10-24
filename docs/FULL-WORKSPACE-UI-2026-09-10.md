**Subsequent correction:** [Card controls and Notifications](WORKSPACE-CARD-CONTROLS-2026-09-10.md) adds the omitted service-category connections, page indexing/menu switches and visible Notifications. Current preview has fourteen task areas and 137 checks. The thirteen-area/100-check report below remains the earlier evidence; neither count establishes full native workflow completeness.

# Full client workspace UI pass

10 September 2026. The founder explicitly approved the corrected Metro/People design and requested the same treatment for Home and the other client areas. They also require WordPress/WooCommerce roles on user cards, team-title editing directly on the card, and a full permitted WordPress/WooCommerce/Kiwe profile when opening a user. Bricks, Rank Math and other technical plugin profile fields belong to the Super Admin surface.

This unit builds the thirteen core task areas in the local React application. It is a complete visual pass across that task catalog, **not a connected WordPress release or full WordPress/WooCommerce feature parity**. All records and saves remain synthetic and in memory. Current runtime candidate remains RC83 with its private shell guard off by default; lab RC82; Ascendants RC55.4. No live site was changed.

## Built in the local application

| Area | Working sample interaction |
| --- | --- |
| Home | Role-scoped tiles show drafts, low-stock items, open orders, selected people/media, menus and pending inbox items. Values derive from shared sample records and update after edits. No polling or invented traffic metrics. |
| Your website | The approved identity tiles remain. Name/tagline, main/dark logos, favicon, business classification, story/audience, contact/social/address and brand preferences have focused editors opened from their subject. All six current native social networks are represented. |
| Posts and pages | Tile lists, filters, search, new records, writing surface, drafts, sample publishing, categories, images, search appearance and page purpose/call-to-action. Homepage blog/bestseller intentions have their own tiles. Author sees their own sample posts. |
| Products | Tile collection, new/edit sample product, category, price, stock, SKU, weight, image and search appearance. Invalid negative amounts/non-integer stock are rejected in the sample editor. |
| Orders | Open/all/completed views, search, line-item snapshots, amount, customer note, sample payment/delivery information and status changes. No payment, inventory adjustment, refund or notification runs. |
| Categories | Add, rename, description, archive indexing/search appearance, website/DSA menu switches and moving selected assigned records to another category. Menu and content views resolve category names from the same record ID. |
| Media and Design library | Image/document tiles, explicit selection, metadata and alt text, filtered/searchable library and bounded local file selection. New media is unselected. Author sees their own sample media; global Design Context selection is Administrator-only. |
| People | Existing-user tiles show the role and its WordPress/WooCommerce origin. Team title is editable on the card and saves on Enter or leaving that field; Escape restores the previous title. Selection/undo, public-team preview, search by name/role/title and complete curated profile sections share the same user IDs. |
| Navigation | Original page/category names, independent website/DSA placement, menu-label overrides, label fallback to the original name and menu ordering. No second page/category inventory is written. |
| Services | Tile list, new/edit sample service, description/category/price/image and Design Context/navigation intentions. The actual native source binding remains a technical-owner concern. |
| Search and sharing | Site intent/description/indexing/sitemap/allowance choices, legal identity/proof fields, sample search preview and links to object-level editing homes. No AI call, SEO-plugin replacement or ranking guarantee. |
| Store setup | Currency/format/units, selling/shipping scope, tax switches, business registrations and display choices, store address, plus sample shipping-zone/method and tax-rate/class editors. Address edits share the Contact record. |
| Inbox | Permitted sample updates/comments/reviews, approval, read state and explicit testimonial selection from approved feedback. Non-selected or unapproved feedback is not a testimonial. |

POS, reservations, LMS, backup/restore, account security actions, provider journeys and other future modules remain outside this UI pass. The visitor composition remains illustrative. A textarea is not Gutenberg; the sample editor does not implement blocks, revisions, autosave, arbitrary Bricks content or the full Woo product/order model. No claim of general client launch readiness follows from this work.

## People fields and permissions

The full profile has **35 editable fields** across four visible sections, plus read-only account facts:

- WordPress: display name, first/last name, nickname, account email, website, biography and language. Username, registered date and role are facts rather than client role-changing controls.
- Kiwe: public team title, display order, founder title, approved LinkedIn/Facebook URLs and portrait. Selection and public fields must retain the existing native user metadata and projection. Editing the title in the profile updates the same value shown on the card.
- WooCommerce billing: first/last name, company, both address lines, city, postcode, country, state, phone and email.
- WooCommerce shipping: the matching address fields and phone. Copy billing copies only supported shipping keys; it does not create a shipping-email field.

These field families were checked against [WordPress's user schema](https://developer.wordpress.org/rest-api/reference/users/), [WooCommerce's core customer-profile fields](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/admin/class-wc-admin-profile.php) and the local Kiwe source. The Woo source checked was upstream trunk, not proof of every installed-version field or locale rule. Native country/state options, field validation and save hooks must come from the installed Woo version. Country-code/enum sample inputs need that metadata during integration.

The explicit list excludes arbitrary `user_contactmethods`, `show_user_profile`, plugin-generated forms, builder/SEO metadata, raw capabilities, keys, sessions, application passwords and verification factors. Do not fetch all user meta and merely hide unwanted fields in CSS. The role selector is a visual demonstration; its sample bootstrap contains synthetic records and **must not be copied as a real authorization/data-minimization implementation**.

The requested “whole user fields” means the client-permitted core business profile. Native wp-admin display preferences and protected password/session/verified-contact operations are not represented as ordinary writable metadata here. Preserve their native security flows and explicit object permissions when integrating them. No arbitrary verified flag, login-phone reassignment or credential mutation is authorized by a public-profile edit.

## Native owners to preserve during connection

| Task | Owner and required boundary |
| --- | --- |
| User read/edit | WordPress users and `edit_user`/listing/object authority; never expose the technical owner through client management. Use an explicit returned-field allowlist and private/no-store responses. |
| Public team | [Team_Workspace_Service](../wp-content/mu-plugins/dsa/includes/Access/Team_Workspace_Service.php), [User_Profile_Service](../wp-content/mu-plugins/dsa/includes/Onboarding/User_Profile_Service.php), existing profile metadata and Bricks projection. Current service listing is bounded eligible staff, not an unrestricted full account directory. |
| Public profile | WordPress name/bio/URL and existing Kiwe social/avatar keys. The existing DSA public-profile writer is self-edit only; do not reuse it to bypass checks for an Administrator editing another user. A narrow authorized adapter is required. |
| Billing/shipping | WooCommerce customer fields and supported writers/hooks, with both actor commerce authority and target-user checks. Keep these private and separate from public Design Context. |
| Business context | Existing [Design_Context_Profile_Service](../wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php) and native WordPress/Woo owners. UI groups may move, but saves must be narrow, stale-aware and verified through native readback. |
| Content/navigation/media | Original posts/pages/products/terms/attachments/menu items and their native APIs. UI name projections use source IDs; label overrides retain separate native semantics. Content/form samples do not authorize new record or capability grants. |
| Search/commerce operations | Existing Kiwe SEO authority and Woo transaction/configuration APIs. Preserve active SEO-plugin ownership, public cache invalidation, order snapshots, extension semantics and provider allowances. |

The prototype requires a nonempty public team title when saving a selected user's title. The current native team writer permits an empty title/fallback. Align this with the founder's requested title workflow in the connected adapter and its validation; do not claim the native writer already enforces this rule. Native expected-state/readback/denial behavior still needs acceptance. Title blur must not become per-keystroke network traffic or overwrite another administrator's newer edit.

## Structure, cost and field coverage

The source is in [workspace-prototype](../tools/workspace-prototype/README.md). `workspace-model.mjs` defines synthetic records, role demonstrations, field presentation and source-ID projections. `home.jsx`, `people.jsx` and `workspace-areas.jsx` provide the task views; `ui.jsx` and `field-tiles.jsx` share tile/dialog/field patterns. Tasks load on demand. The old form-oriented context files remain historical and unimported.

The static native context declaration inventory still has **76 declarations in 11 groups**, with no runtime declaration changes in this unit. It is an inventory, not proof that every plugin/runtime field has a connected editor. This pass adds the previously omitted audience/industry/commerce/regulatory/search fields to the UI, directs founder details to existing people and keeps source-post-type setup outside the client surface. The [whole-context coverage contract](DESIGN-CONTEXT-WORKSPACE-COVERAGE.md) remains the integration checklist.

All local preview data resets on reload. Local file selection is capped at ten files of 2 MB each; it is not the native upload pipeline or WordPress file-type authorization. Administrative UI code is development tooling outside the MU-plugin package and contributes no new bytes to live public pages. The loopback build reports compressed estimates, but the server sends uncompressed responses. Native PHP/database cost, concurrent administrators and public-site impact remain unmeasured for the connected application.

## Acceptance and continuation

The grouped local browser pass passed **100 checks**. It covers thirteen task tiles plus Home; direct identity/title/profile edits; title projection and undo without role changes; 35 curated profile fields and private address copying; category/menu/content read-through and selected moves; writing draft protection; product/order Home updates; media selection; approved testimonials; search description; shipping-rule creation; role-specific omissions including direct hashes; Author-owned content/media; no external requests; and no JavaScript errors. All task views and the profile sheet fit 1440/820/390/360 px without horizontal overflow. Desktop and phone Home, People, profile and category screenshots were visually reviewed.

The initial 99-check pass passed. The final source inventory found X and Pinterest missing from the six native social links; those controls and their check were added, and the grouped pass was repeated successfully. The earlier receipt is retained. No unchanged PHP/provider/production suite was rerun. [The reusable acceptance tool](../tools/workspace-prototype/check-full-ui.cjs) records exact source hashes and writes evidence outside the repository. `check-ui.cjs` remains a compatible entry point to the current check.

Evidence: external task `work/full-workspace-ui-evidence/` contains `browser.json`, `browser-before-social-completion.json`, `native-fields.json`, build output, screenshots and final handoff. Workspace verification reports the known dirty trees; origins, branches and upstreams remain correct. Documentation links and diff formatting are checked at handoff.

Next implementation is native connection, beginning with People: server-side permitted task/read model, explicit user/profile field ownership, the existing team writer, capability/nonce/stale/session/error handling and actual public projection readback. Then connect Identity and the remaining task adapters in grouped batches. The full UI does not close the narrow Woo custom-owner/client capability gaps, native editor integration or shared-host performance gates. Keep RC83's old bridge off by default until an accepted connected replacement is verified.

Canonical `main`, baseline `731a94ff48dd350bb7b168ec285fcc96afbdaa01`. No commit, push, signing, package/version change, deployment, schema/capability change, native user change or live setting change. Production SecureTrack/PhoneKey/passkey behavior is unaffected by this development-tooling unit.
